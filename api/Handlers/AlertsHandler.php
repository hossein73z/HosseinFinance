<?php

function level_8(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void {
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 8], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => false,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    if ($callback_query) handleAlertsCallback($user, $message);
    if ($message) handleAlertsTextMessage($data);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        // Send Informative message
        sendAllAlerts($user, $db);
    }

    exit;
}

function handleAlertsCallback(User $user, array $message): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ];

    sendToTelegram('editMessageText', $data);
    exit;
}

function handleAlertsTextMessage(array $data): void
{
    // Send default message of this level
    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit;
}

function sendAllAlerts(User $user, DatabaseManager $db, int|string|null $message_id = null): void
{
    $message_id = ($message_id !== null) ?
        $message_id :
        sendLoadingMessage($user->getid(), 'در حال دریافت لیست هشدارها ...')['result']['message_id'];

    $alerts = $db->read(
        table: 'alerts',
        conditions: ['user_id' => $user->getId()],
        selectColumns: '
            alerts.*,
            assets.emoji,
            assets.asset_type,
            assets.price as current_price,
            assets.base_currency,
            assets.date as update_date,
            assets.time as update_time',
        join: 'join assets on assets.name = alerts.asset_name',
        orderBy: ['assets.asset_type' => 'ASC', 'alerts.asset_name' => 'ASC', 'alerts.target_price' => 'ASC']
    );

    $text = '';
    $data = [
        'text' => &$text,
        'chat_id' => $user->getid(),
        'message_id' => $message_id,
        'reply_markup' => ['inline_keyboard' => [
            [['text' => 'مدیریت هشدارها', 'callback_data' => json_encode(['mng_alerts' => null])]]
        ]]
    ];

    if ($alerts) {
        $text = 'هشدارهای شما:' . "\n";
        foreach ($alerts as $alert) {
            $text .= "\n  - " . beautifulNumber($alert['asset_name'], null) . ': ' . beautifulNumber($alert['target_price']);
        }
    } else $text = 'شما هشداری ثبت نکرده‌اید!';

    sendToTelegram('editMessageText', $data);
}

function managePriceAlerts(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ];

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    switch ($query_key) {

        /** Add or remove alerts */
        case 'mng_alerts':

            $action = $query_data[$query_key];

            // Show alerts' management menu
            if ($action == null) {
                $data['text'] = 'عملیات مورد نظر را انتخاب کنید:';
                $data['reply_markup'] = ['inline_keyboard' => [
                    [['text' => 'افزودن هشدار', 'callback_data' => json_encode(['mng_alerts' => 'add_alert'])]],
                    [['text' => 'حذف هشدار', 'callback_data' => json_encode(['mng_alerts' => 'remove_alert'])]],
                    [['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['show_alerts' => null])]],
                ]];
            }

            // Show list of asset types to select for new alert
            if ($action == 'add_alert') {
                $asset_types = $db->read('assets', selectColumns: 'asset_type', distinct: true);

                if ($asset_types) {
                    $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
                    $data['reply_markup']['inline_keyboard'] = [[
                        ['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['mng_alerts' => null])],
                        ['text' => '❌ لغو ❌', "style" => "danger", 'callback_data' => json_encode(['show_alerts' => null])]
                    ]];

                    $asset_types = array_column($asset_types, 'asset_type');
                    foreach ($asset_types as $asset_type) array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => beautifulNumber($asset_type, null), 'callback_data' => json_encode(['new_alert_type' => $asset_type], JSON_UNESCAPED_UNICODE)]]
                    );
                } else {
                    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => 'دسته‌بندی‌ای در سیستم یافت نشد!']);
                    exit;
                }
            }

            // Show list of alerts to delete
            if ($action == 'remove_alert') {
                $alerts = $db->read(
                    table: 'alerts',
                    conditions: ['user_id' => $user->getId()],
                    selectColumns: '
                        alerts.*,
                        assets.emoji,
                        assets.asset_type,
                        assets.price as current_price,
                        assets.base_currency,
                        assets.date as update_date,
                        assets.time as update_time',
                    join: 'join assets on assets.name = alerts.asset_name'
                );

                if ($alerts) {
                    $data['text'] = 'کدام مورد را می‌خواهید حذف کنید؟';

                    $data['reply_markup']['inline_keyboard'] = [[
                        ['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['mng_alerts' => null])],
                        ['text' => '❌ لغو ❌', "style" => "danger", 'callback_data' => json_encode(['show_alerts' => null])]
                    ]];

                    foreach ($alerts as $alert) array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => beautifulNumber($alert['asset_name'], null) . ': ' . beautifulNumber($alert['target_price']), 'callback_data' => json_encode(['del_alert' => $alert['id']])]]
                    );
                } else {
                    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => 'شما هشداری ثبت نکرده‌اید!']);
                    exit;
                }
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        /** Show list of asset to select for new alert */
        case 'fav_alert': // -------- Request from favorites message
        case 'new_alert_type': // --- Request from alert manager message

            if ($query_key == 'fav_alert') {
                $data['reply_markup']['inline_keyboard'] = [[
                    ['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['show_favorites' => null])]
                ]];

                $assets = $db->read(
                    table: 'favorites f',
                    conditions: ['f.user_id' => $user->getId()],
                    selectColumns: 'a.*, f.id as fav_id',
                    join: 'JOIN assets a ON a.name=f.asset_name',
                    orderBy: ['asset_type' => 'ASC']
                );
            } else {
                $data['reply_markup']['inline_keyboard'] = [[
                    ['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['mng_alerts' => 'add_alert'])],
                    ['text' => '❌ لغو ❌', "style" => "danger", 'callback_data' => json_encode(['show_alerts' => null])]
                ]];
                $assets = $db->read('assets', ['asset_type' => $query_data[$query_key]]);
            }

            if ($assets) {
                $data['text'] = 'گزینه‌ی مد نظر خود را از لیست زیر انتخاب کنید:';

                foreach ($assets as $asset) array_unshift(
                    $data['reply_markup']['inline_keyboard'],
                    [['text' => beautifulNumber($asset['name'], null), 'callback_data' => json_encode(['new_alert_asset_id' => $asset['id']], JSON_UNESCAPED_UNICODE)]]
                );
            } else $data['text'] = 'دسته‌بندی مورد نظر خالی‌ست!';

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        case 'new_alert_asset_id':

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);

            $user = $user->setProgress(['parent_btn' => $user->getLastBtn(), 'data' => ['set_alert' => ['asset_id' => $query_data['new_alert_asset_id']]]]);
            empty_level($user, $db, $user->getLastBtn());

        /** Ask user to confirm deleting alert */
        case 'del_alert':
            $alert_id = $query_data[$query_key];

            $data['text'] = 'آیا از حذف اطمینان دارید؟';
            $data['reply_markup']['inline_keyboard'] = [[
                ['text' => 'تایید', "style" => "danger", 'callback_data' => json_encode(['conf_del_alert' => $alert_id])],
                ['text' => 'لغو', "style" => "success", 'callback_data' => json_encode(['show_alerts' => null])],
            ]];

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        /** Delete alert and send alerts' message to the user */
        case 'conf_del_alert':

            $alert_id = $query_data[$query_key];
            try {
                $db->delete(
                    table: 'alerts',
                    conditions: ['id' => $alert_id],
                    resetAutoIncrement: true
                );
                $data['text'] = '✅ حذف موفقیت آمیز بود!';
            } catch (Exception $e) {
                error_log('Error deleting a favorite: ' . $e->getMessage());
                $data['text'] = '❌ خطای پایگاه داده!';
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            sendAllAlerts($user, $db);
            exit;

        /** Show main list of alerts */
        case 'show_alerts':
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendAllAlerts($user, $db, $message['message_id']);
            exit;
    }

    sendToTelegram('editMessageText', $data);
    exit;
}
