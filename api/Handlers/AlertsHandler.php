<?php

function level_8(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null): void
{
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
    $alerts = $db->query("
        SELECT
            alerts.*,
            assets.id as asset_id,
            assets.emoji,
            assets.asset_type,
            assets.price as current_price,
            assets.base_currency,
            assets.date as update_date,
            assets.time as update_time
        FROM alerts JOIN assets ON assets.name = alerts.asset_name
        WHERE alerts.user_id = '{$user->getId()}'
        ORDER BY assets.asset_type, alerts.asset_name, alerts.target_price")->fetchAll();

    $rich_text = '';
    $data = [
        'rich_message' => ['is_rtl' => true, 'html' => &$rich_text],
        'chat_id' => $user->getid(),
        'reply_markup' => ['inline_keyboard' => [
            [['text' => 'مدیریت هشدارها', 'callback_data' => json_encode(['mng_alerts' => null])]]
        ]]
    ];

    if ($alerts) {
        $rich_text = '<h4>هشدارهای شما:</h4>';
        $rich_text .= '<ul>';
        foreach ($alerts as $alert) {
            $status_emoji = '⚠';
            switch ($alert['status']) {
                case 'active':
                    $status_emoji = '🔁';
                    break;
                case 'triggered':
                    $status_emoji = '✅';
                    break;
                case 'inactive':
                    $status_emoji = '❌';
                    break;
            }

            $asset_name = beautifulNumber($alert['asset_name'], null);
            $alert_price = beautifulNumber($alert['target_price']);
            $base_currency = beautifulNumber($alert['base_currency'], null);

            $edit_callback = json_encode(['edit_alert_price' => $alert['id']]);
            $edit_button = "<tg-button type='callback_data' style='link' data='$edit_callback'>" . "ویرایش" . "</tg-button>";

            $delete_callback = json_encode(['del_alert' => [$alert['id'] => $alert['asset_id']]]);
            $delete_button = "<tg-button type='callback_data' style='danger' data='$delete_callback'>" . "حذف" . "</tg-button>";

            $rich_text .= "<li>$status_emoji $asset_name: $alert_price $base_currency $edit_button $delete_button</li>";
        }
        $rich_text .= '</ul>';
    } else $rich_text = 'شما هشداری ثبت نکرده‌اید!';

    if (!$message_id) {
        sendToTelegram('sendRichMessage', $data);
    } else {
        $data['message_id'] = $message_id;
        sendToTelegram('editMessageText', $data);
    }
    exit();
}

function sendAssetAlerts(User $user, DatabaseManager $db, string|int $asset_id, int|string|null $message_id = null): void
{
    $alerts = $db->query("
        SELECT 
            alerts.*,
            assets.id as asset_id,
            assets.emoji,
            assets.asset_type,
            assets.price as current_price,
            assets.base_currency,
            assets.date as update_date,
            assets.time as update_time
        FROM alerts JOIN assets ON assets.name = alerts.asset_name
        WHERE alerts.user_id = '{$user->getId()}' AND assets.id = '$asset_id'
        ORDER BY alerts.target_price")->fetchAll();

    $rich_text = '';
    $data = [
        'rich_message' => ['is_rtl' => true, 'html' => &$rich_text],
        'chat_id' => $user->getid(),
        'reply_markup' => ['inline_keyboard' => [
            [['text' => '➕ افزودن هشدار جدید ➕', 'callback_data' => json_encode(['new_asset_alert' => $asset_id])]],
            [['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['show_favorites' => null])]],
        ]]
    ];

    if ($alerts) {
        $rich_text = '<p>هشدارهای <b>' . beautifulNumber($alerts[0]['asset_name'], null) . '</b></p>';
        $rich_text .= '<ul>';
        foreach ($alerts as $alert) {
            $status_emoji = '⚠';
            switch ($alert['status']) {
                case 'active':
                    $status_emoji = '🔁';
                    break;
                case 'triggered':
                    $status_emoji = '✅';
                    break;
                case 'inactive':
                    $status_emoji = '❌';
                    break;
            }

            $alert_price = beautifulNumber($alert['target_price']);
            $base_currency = beautifulNumber($alert['base_currency'], null);

            $edit_callback = json_encode(['edit_alert_price' => $alert['id']]);
            $edit_button = "<tg-button type='disabled' style='link' data='$edit_callback'>" . "ویرایش" . "</tg-button>";

            $delete_callback = json_encode(['del_asset_alert' => [$alert['id'] => $alert['asset_id']]]);
            $delete_button = "<tg-button type='callback_data' style='danger' data='$delete_callback'>" . "حذف" . "</tg-button>";

            $rich_text .= "<li>$status_emoji $alert_price $base_currency $edit_button $delete_button</li>";
        }
        $rich_text .= '</ul>';
    } else $rich_text = 'شما هشداری برای این آیتم ثبت نکرده‌اید!';

    if (!$message_id) {
        sendToTelegram('sendRichMessage', $data);
    } else {
        $data['message_id'] = $message_id;
        sendToTelegram('editMessageText', $data);
    }
    exit();
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

        // Add or remove alerts
        case 'mng_alerts':

            $action = $query_data[$query_key];

            // Show alerts' management menu
            if ($action == null) {
                $data['text'] = 'عملیات مورد نظر را انتخاب کنید:';
                $data['reply_markup'] = ['inline_keyboard' => [
                    [['text' => 'افزودن هشدار', 'callback_data' => json_encode(['mng_alerts' => 'add_alert'])]],
                    [['text' => 'حذف هشدار', 'callback_data' => json_encode(['mng_alerts' => 'remove_alert'])]],
                    [['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['show_all_alerts' => null])]],
                ]];
            }

            // Show list of asset types to select for new alert
            if ($action == 'add_alert') {
                $asset_types = $db->read('assets', selectColumns: 'asset_type', distinct: true);

                if ($asset_types) {
                    $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
                    $data['reply_markup']['inline_keyboard'] = [[
                        ['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['mng_alerts' => null])],
                        ['text' => '❌ لغو ❌', "style" => "danger", 'callback_data' => json_encode(['show_all_alerts' => null])]
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
                        assets.id as asset_id,
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
                        ['text' => '❌ لغو ❌', "style" => "danger", 'callback_data' => json_encode(['show_all_alerts' => null])]
                    ]];

                    foreach ($alerts as $alert) array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => beautifulNumber($alert['asset_name'], null) . ': ' . beautifulNumber($alert['target_price']), 'callback_data' => json_encode(['del_alert' => [$alert['id'] => $alert['asset_id']]])]]
                    );
                } else {
                    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => 'شما هشداری ثبت نکرده‌اید!']);
                    exit;
                }
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        // Show list of asset to select for new alert
        case 'fav_alert': // ------- Request from favorites message
        case 'new_alert_type': // -- Request from alert manager message

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
                    ['text' => '❌ لغو ❌', "style" => "danger", 'callback_data' => json_encode(['show_all_alerts' => null])]
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

        // Redirect user to empty level to input alert price
        case 'new_alert_asset_id': // -- Add price for new alert, -- from main alerts manu and favorites' message
        case 'edit_alert_price': // ---- Edit price of an alert, --- from main alerts menu
        case 'new_asset_alert': // ----- Add price for new alert, -- from favorites' alert menu

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);

            $item_id = $query_data[$query_key];
            if ($query_key == 'new_alert_asset_id') $progress_data = ['new_alert_price' => ['asset_id' => $item_id]];
            elseif ($query_key == 'edit_alert_price') $progress_data = ['edit_alert_price' => ['alert_id' => $item_id]];
            else $progress_data = ['new_asset_alert' => ['asset_id' => $item_id]];

            $user->setProgress(['parent_btn' => $user->getLastBtn(), 'data' => $progress_data]);
            empty_level($user, $db, $user->getLastBtn());
            break;

        // Ask user to confirm deleting alert
        case 'del_alert': // -------- Request from main alerts' message
        case 'del_asset_alert': // -- Request from favorites message

            // Query structure doo to length limitation: [query_key => [alert_id => asset_id]]
            $alert_id = array_key_first($query_data[$query_key]);
            $asset_id = $query_data[$query_key][$alert_id];

            $data['text'] = 'آیا از حذف اطمینان دارید؟';
            $data['reply_markup']['inline_keyboard'] = [
                $query_key == 'del_alert' ? [
                    ['text' => 'تایید', "style" => "danger", 'callback_data' => json_encode(['conf_del_alert' => [$alert_id => $asset_id]])],
                    ['text' => 'لغو', "style" => "success", 'callback_data' => json_encode(['show_all_alerts' => null])],
                ] : [
                    ['text' => 'تایید', "style" => "danger", 'callback_data' => json_encode(['conf_del_asset_alert' => [$alert_id => $asset_id]])],
                    ['text' => 'لغو', "style" => "success", 'callback_data' => json_encode(['show_asset_alerts' => $asset_id])],
                ]
            ];

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        // Delete alert and send alerts' message to the user
        case 'conf_del_alert':
        case 'conf_del_asset_alert':

            // Query structure doo to length limitation: [query_key => [alert_id => asset_id]]
            $alert_id = array_key_first($query_data[$query_key]);
            $asset_id = $query_data[$query_key][$alert_id];

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
            if ($query_key == 'conf_del_alert') sendAllAlerts($user, $db);
            else sendAssetAlerts($user, $db, $asset_id);
            break;

        // Show list of alerts for specific asset
        // Called from favorites menu
        case 'show_asset_alerts':
            $asset_id = $query_data[$query_key];
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            $db->update('special_messages', ['status' => 'paused'], ['user_id' => $user->getId(), 'type' => 'live_price', 'status' => 'active', 'message_id' => $message['message_id']]);
            sendAssetAlerts($user, $db, $asset_id, $message['message_id']);
            break;

        // Show main list of all alerts
        case 'show_all_alerts':
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendAllAlerts($user, $db, $message['message_id']);
            exit;
    }

    sendToTelegram('editMessageText', $data);
    exit;
}
