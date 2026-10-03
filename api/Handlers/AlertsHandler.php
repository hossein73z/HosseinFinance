<?php

use JetBrains\PhpStorm\NoReturn;

#[NoReturn]
function alerts_menu(
    User            $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?array          $callback_query = null): void
{

    if ($callback_query) {
        managePriceAlerts($user, $callback_query, $message, $db);
    } elseif (!$message) {
        $user->setProgress(null);
        $user->setButton(new Button(
            id: 'alerts',
            attrs: ['text' => '🔔 هشدارها'],
            adminKey: false,
            belongTo: 'main_menu',
            keyboard: [[['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0]]]
        ));
    } else {

        // Received message is a button
        if ($pressed_button_id = getPressedButtonID($message['text'], $user))
            levelHandler($user, $db, button_id: $pressed_button_id);

        else {
            // Received text is an awaited alert price
            handleAlertPriceInput($user, $message, $db);

            // Received message is not recognizable
            $data['text'] = 'پیام نامفهوم است.';
        }
    }

    sendInitialLevelMessage($user, $db, $data ?? null);
    if (!$message) sendAllAlerts($user, $db);
    exit($user->getButton()->getText());
}

#[NoReturn]
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

            $toggle_callback = json_encode(['toggle_alert_activation' => $alert['id']]);
            $toggle_button = "<tg-button type='callback_data' style='link' data='$toggle_callback'>$status_emoji</tg-button>";

            $price_callback = json_encode(['edit_alert_price' => $alert['id']]);
            $price_button = "<tg-button type='callback_data' style='link' data='$price_callback'>$alert_price</tg-button>";

            $delete_callback = json_encode(['del_alert' => [$alert['id'] => $alert['asset_id']]]);
            $delete_button = "<tg-button type='callback_data' style='danger' data='$delete_callback'>" . "حذف" . "</tg-button>";

            $rich_text .= "<li>$toggle_button $asset_name: $price_button $base_currency $delete_button</li>";
        }

        $footer = "برای فعال/غیرفعال‌سازی هشدار و یا ویرایش قیمت، به ترتیب ایموجی وضعیت هشدار یا قیمت آن را لمس کنید.";
        $rich_text .= "</ul><hr>$footer";

    } else $rich_text = 'شما هشداری ثبت نکرده‌اید!';

    if (!$message_id) {
        sendToTelegram('sendRichMessage', $data);
    } else {
        $data['message_id'] = $message_id;
        sendToTelegram('editMessageText', $data);
    }
    exit();
}

#[NoReturn]
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
            [['text' => 'افزودن هشدار جدید', 'callback_data' => json_encode(['new_asset_alert' => $asset_id])]],
            [['text' => 'برگشت به لیست علاقه‌مندی‌ها', "style" => "primary", 'callback_data' => json_encode(['show_favorites' => null])]],
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

            $edit_callback = json_encode(['edit_asset_alert' => $alert['id']]);
            $edit_button = "<tg-button type='callback_data' style='link' data='$edit_callback'>" . "ویرایش" . "</tg-button>";

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

/**
 * Ask the user for the alert target price using ForceReply (keeps current reply keyboard).
 */
function askForAlertPrice(User $user, array $asset): void
{
    $text = 'قیمتی که می‌خواهید برای آن هشدار تنظیم کنید را نوشته و ارسال کنید.';
    $text .= "\n";
    $text .= '*قیمت کنونی «' . beautifulNumber($asset['name'], null) . '»*: ';
    $text .= beautifulNumber($asset['price']) . ' ' . beautifulNumber($asset['base_currency'], null);
    $text = markdownScape($text);

    sendToTelegram('sendMessage', [
        'chat_id' => $user->getId(),
        'text' => $text,
        'parse_mode' => 'MarkdownV2',
        'reply_markup' => [
            'keyboard' => $user->getKeyboard(),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'force_reply' => true,
            'input_field_placeholder' => 'قیمت هشدار را وارد کنید' # HACK
        ]
    ]);
}

/**
 * Process a text message that is expected to be an alert target price.
 * Called from nonButtonHandler when isAlertPriceProgress() is true.
 */
function handleAlertPriceInput(User $user, array $message, DatabaseManager $db): null
{
    $progress = $user->getProgress();
    if (!$progress) return null;

    $progress_key = array_key_first($progress);
    if (!in_array($progress_key, ['new_alert_price', 'edit_alert_price', 'new_asset_alert', 'edit_asset_alert'], true))
        return null;

    // Resolve the related asset (and `alert_id` when editing)
    $alert_id = null;
    if ($progress_key === 'new_alert_price') {
        $asset_id = $progress[$progress_key]['asset_id'];
        $asset = $db->read('assets', ['id' => $asset_id], true);
    } elseif ($progress_key === 'edit_alert_price') {
        $alert_id = $progress[$progress_key]['alert_id'];
        $asset = $db->query("
            SELECT assets.*
            FROM assets JOIN alerts ON alerts.asset_name = assets.name
            WHERE alerts.user_id = '{$user->getId()}' AND alerts.id = '$alert_id'")->fetch();
    } elseif ($progress_key === 'new_asset_alert') {
        $asset_id = $progress[$progress_key]['asset_id'];
        $asset = $db->read('assets', ['id' => $asset_id], true);
    } else { // edit_asset_alert
        $alert_id = $progress[$progress_key]['alert_id'];
        $asset = $db->query("
            SELECT assets.*
            FROM assets JOIN alerts ON alerts.asset_name = assets.name
            WHERE alerts.user_id = '{$user->getId()}' AND alerts.id = '$alert_id'")->fetch();
    }

    if (!$asset) {
        sendToTelegram('sendMessage', [
            'chat_id' => $user->getId(),
            'text' => '❌ دارایی مورد نظر یافت نشد.',
        ]);
        levelHandler($user, $db, button_id: 'main_menu'); # HACK
    }

    $target_price = cleanAndValidateNumber($message['text'] ?? '');

    if ($target_price === null) {
        sendToTelegram('sendMessage', [
            'chat_id' => $user->getId(),
            'text' => "پیام نامفهوم بود.\nقیمت را به عدد بنویسید یا در صورت انصراف از دکمه لغو استفاده کنید.",
            'reply_markup' => [
                'keyboard' => $user->getKeyboard(),
                'resize_keyboard' => true,
                'is_persistent' => true,
                'force_reply' => true,
                'input_field_placeholder' => 'قیمت هشدار را وارد کنید', # HACK
            ]
        ]);
        exit;
    }

    $price_diff = $target_price - (float)$asset['price'];
    $diff_percent = intval(($price_diff / floatval($asset['price'])) * 100);

    if ($price_diff == 0) {
        sendToTelegram('sendMessage', [
            'chat_id' => $user->getId(),
            'text' => "قیمت هشدار نمی‌تواند با قیمت کنونی برابر باشد.\nقیمت دیگری بنویسید یا در صورت انصراف از دکمه لغو استفاده کنید.",
            'reply_markup' => [
                'keyboard' => $user->getKeyboard(),
                'resize_keyboard' => true,
                'is_persistent' => true,
                'force_reply' => true,
                'input_field_placeholder' => 'قیمت هشدار را وارد کنید', # HACK
            ]
        ]);
        exit;
    }

    $new_alert = [
        'id' => $alert_id,
        'user_id' => $user->getId(),
        'asset_name' => $asset['name'],
        'target_price' => $target_price,
        'status' => 'active',
        'created_date' => JalaliDate::fromGregorian()->format(),
        'created_time' => date('H:i'),
    ];

    $result = $db->upsert('alerts', $new_alert);

    if ($result) {
        $text = '✅ هشدار قیمت برای «' . beautifulNumber($asset['name'], null) . '» با موفقیت ثبت شد!';
        $text .= "\n" . 'قیمت کنونی: ' . beautifulNumber($asset['price']);
        $text .= "\n" . 'قیمت هشدار: ' . beautifulNumber($target_price);
        $text .= "\n" . 'اختلاف قیمت: ' . ($price_diff > 0 ? '➕' : '➖');
        $text .= ' ' . beautifulNumber(abs($price_diff));
        $text .= ' (' . beautifulNumber($diff_percent) . '%)';
    } else {
        $text = '❌ خطای پایگاه داده!';
    }

    sendToTelegram('sendMessage', ['chat_id' => $user->getId(), 'text' => $text, 'reply_markup' => ['remove_keyboard' => true]]);

    if ($progress_key == 'new_alert_price') levelHandler($user, $db, button_id: 'alerts');
    if ($progress_key == 'edit_alert_price') levelHandler($user, $db, button_id: 'alerts');
    if ($progress_key == 'new_asset_alert') levelHandler($user, $db, button_id: 'prices');
    if ($progress_key == 'edit_asset_alert') levelHandler($user, $db, button_id: 'prices');
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
                    [['text' => 'برگشت به لیست هشدارها', "style" => "primary", 'callback_data' => json_encode(['show_all_alerts' => null])]],
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

            sendToTelegram('editMessageText', $data);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            exit;

        // Show list of asset to select for new alert
        case 'fav_alert': # ─────── Request from favorites message
        case 'new_alert_type': # ── Request from alert manager message

            if ($query_key == 'fav_alert') {
                $data['reply_markup']['inline_keyboard'] = [[
                    ['text' => 'برگشت به لیست علاقه‌مندی‌ها', "style" => "primary", 'callback_data' => json_encode(['show_favorites' => null])]
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

            sendToTelegram('editMessageText', $data);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            exit;

        // Ask for alert price via ForceReply (no empty level / s3)
        case 'new_alert_asset_id': # ── Add price for new alert, ── from main alerts menu and favorites' message
        case 'edit_alert_price': # ──── Edit price of an alert, ─── from main alerts menu
        case 'new_asset_alert': # ───── Add price for new alert, ── from favorites' alert menu
        case 'edit_asset_alert': # ──── Edit price of an alert, ─── from favorites' alert menu

            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);

            $item_id = $query_data[$query_key];
            if ($query_key == 'new_alert_asset_id') {
                $user->setKeyboard([[['id' => 'alerts', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]]);
                $progress = ['new_alert_price' => ['asset_id' => $item_id]];
                $asset = $db->read('assets', ['id' => $item_id], true);

            } elseif ($query_key == 'edit_alert_price') {
                $user->setKeyboard([[['id' => 'alerts', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]]);
                $progress = ['edit_alert_price' => ['alert_id' => $item_id]];
                $asset = $db->query("
                    SELECT assets.*
                    FROM assets JOIN alerts ON alerts.asset_name = assets.name
                    WHERE alerts.user_id = '{$user->getId()}' AND alerts.id = '$item_id'")->fetch();

            } elseif ($query_key == 'new_asset_alert') {
                $user->setKeyboard([[['id' => 'prices', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]]);
                $progress = ['new_asset_alert' => ['asset_id' => $item_id]];
                $asset = $db->read('assets', ['id' => $item_id], true);

            } else {
                $user->setKeyboard([[['id' => 'prices', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]]);
                $progress = ['edit_asset_alert' => ['alert_id' => $item_id]];
                $asset = $db->query("
                    SELECT assets.*
                    FROM assets JOIN alerts ON alerts.asset_name = assets.name
                    WHERE alerts.user_id = '{$user->getId()}' AND alerts.id = '$item_id'")->fetch();

            }

            if (!$asset) {
                sendToTelegram('sendMessage', [
                    'chat_id' => $user->getId(),
                    'text' => '❌ دارایی مورد نظر یافت نشد.',
                ]);
                exit;
            }

            $user->setProgress($progress);
            $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);

            askForAlertPrice($user, $asset);
            exit;

        // Toggle alert's activation status
        case 'toggle_alert_activation':
            $alert_id = $query_data[$query_key];
            $db->query("UPDATE alerts SET status = IF(status = 'active', 'inactive', 'active') WHERE id = $alert_id;")->fetch();
            sendAllAlerts($user, $db, $message['message_id']);

        // Ask user to confirm deleting alert
        case 'del_alert': # ──────── Request from main alerts' message
        case 'del_asset_alert': # ── Request from favorites message

            // Query structure due to length limitation: [query_key => [alert_id => asset_id]]
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

            // Query structure due to length limitation: [query_key => [alert_id => asset_id]]
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

        // Show list of alerts for specific asset
        // Called from favorites menu
        case 'show_asset_alerts':
            $asset_id = $query_data[$query_key];
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            $db->update('special_messages', ['status' => 'paused'], ['user_id' => $user->getId(), 'type' => 'live_price', 'status' => 'active', 'message_id' => $message['message_id']]);
            sendAssetAlerts($user, $db, $asset_id, $message['message_id']);

        // Show main list of all alerts
        case 'show_all_alerts':
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendAllAlerts($user, $db, $message['message_id']);
    }

    sendToTelegram('editMessageText', $data);
    exit;
}
