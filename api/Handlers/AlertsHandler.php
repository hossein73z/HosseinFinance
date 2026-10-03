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
        handleAlertsCallback($user, $callback_query, $message, $db);
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
function sendAllAlerts(User $user, DatabaseManager $db, int|string|null $message_id = null, ?int $alert_id = null): void
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

    $html = '';
    $data = [
        'rich_message' => ['is_rtl' => true, 'html' => &$html],
        'chat_id' => $user->getid(),
        'reply_markup' => ['inline_keyboard' => [
            [['text' => 'افزودن هشدار جدید', 'callback_data' => json_encode(['add_alert' => null])]]
        ]]
    ];

    if ($alerts) {

        $alerts_html = '<ul>';
        foreach ($alerts as $alert) {
            $status_emoji = '⚠';
            switch ($alert['status']) {
                case 'active':
                    $status_emoji = '🔄';
                    break;
                case 'triggered':
                    $status_emoji = '✅';
                    break;
                case 'inactive':
                    $status_emoji = '🚫';
                    break;
            }

            $asset_name = beautifulNumber($alert['asset_name'], null);
            $alert_price = beautifulNumber($alert['target_price']);
            $current_price = beautifulNumber($alert['current_price']);
            $base_currency = beautifulNumber($alert['base_currency'], null);

            $toggle_callback = json_encode(['toggle_alert_activation' => $alert['id']]);
            $toggle_button = "<tg-button type='callback_data' style='link' data='$toggle_callback'>$status_emoji</tg-button>";

            $price_callback = json_encode(['edit_alert' => $alert['id']]);
            $price_button = "<tg-button type='callback_data' style='link' data='$price_callback'>$alert_price</tg-button>";

            // Create delete/confirmation button for the alert
            if ($alert_id && $alert['id'] == $alert_id) {
                $delete_conf_callback = json_encode(['conf_del_alert' => $alert['id']]);
                $delete_deny_callback = json_encode(['show_all_alerts' => null]);
                $delete_button =
                    "<tg-button style='primary' type='disabled'>" . "حذف" . "</tg-button>" .
                    "<tg-button style='danger' type='callback_data' data='$delete_conf_callback'>" . "تأیید حذف" . "</tg-button>" .
                    "<tg-button style='success' type='callback_data' data='$delete_deny_callback'>" . "لغو" . "</tg-button>";
            } else {
                $delete_callback = json_encode(['del_alert' => $alert['id']]);
                $delete_button = "<tg-button type='callback_data' style='primary' data='$delete_callback'>" . "حذف" . "</tg-button>";
            }
            $alert_line_html = "<li>$toggle_button $price_button $base_currency $delete_button</li>";

            $asset_title_html = "هشدارهای " . "<b><u>$asset_name</u></b> ($current_price $base_currency)";

            if (!isset($prev_asset_name)) {
                $prev_asset_name = $asset_name;
                $alerts_html .= "<li>$asset_title_html<ul>$alert_line_html";
            } elseif ($prev_asset_name != $asset_name) {
                $prev_asset_name = $asset_name;
                $alerts_html .= "</ul></li><li>$asset_title_html<ul>$alert_line_html";
            } else {
                $alerts_html .= $alert_line_html;
            }
        }
        $alerts_html .= "</ul></li></ul>";

        $footer = "برای فعال/غیرفعال‌سازی هشدار و یا ویرایش قیمت، به ترتیب ایموجی وضعیت و یا قیمت آن را لمس کنید.";
        $html = "<h3>هشدارهای شما:</h3>$alerts_html<hr>$footer";

    } else $html = 'شما هشداری ثبت نکرده‌اید!';

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
            [['text' => 'افزودن هشدار جدید', 'callback_data' => json_encode(['fav_new_alert' => $asset_id])]],
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
                    $status_emoji = '🔄';
                    break;
                case 'triggered':
                    $status_emoji = '✅';
                    break;
                case 'inactive':
                    $status_emoji = '🚫';
                    break;
            }

            $alert_price = beautifulNumber($alert['target_price']);
            $base_currency = beautifulNumber($alert['base_currency'], null);

            $edit_callback = json_encode(['fav_edit_alert' => $alert['id']]);
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
    if (!in_array($progress_key, ['new_alert', 'edit_alert', 'fav_new_alert', 'fav_edit_alert'], true))
        return null;

    // Resolve the related asset (and `alert_id` when editing)
    $alert_id = null;
    if ($progress_key === 'new_alert') {
        $asset_id = $progress[$progress_key]['asset_id'];
        $asset = $db->read('assets', ['id' => $asset_id], true);
    } elseif ($progress_key === 'edit_alert') {
        $alert_id = $progress[$progress_key]['alert_id'];
        $asset = $db->query("
            SELECT assets.*
            FROM assets JOIN alerts ON alerts.asset_name = assets.name
            WHERE alerts.user_id = '{$user->getId()}' AND alerts.id = '$alert_id'")->fetch();
    } elseif ($progress_key === 'fav_new_alert') {
        $asset_id = $progress[$progress_key]['asset_id'];
        $asset = $db->read('assets', ['id' => $asset_id], true);
    } else { // fav_edit_alert
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

    if ($progress_key == 'new_alert') levelHandler($user, $db, button_id: 'alerts');
    if ($progress_key == 'edit_alert') levelHandler($user, $db, button_id: 'alerts');
    if ($progress_key == 'fav_new_alert') levelHandler($user, $db, button_id: 'prices');
    if ($progress_key == 'fav_edit_alert') levelHandler($user, $db, button_id: 'prices');
    exit();
}

#[NoReturn]
function handleAlertsCallback(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ];

    $query_data = $callback_query['data'];
    $query_key = array_key_first($query_data);

    switch ($query_key) {

        // Ask for new alert's type (from alerts list)
        case 'add_alert':
            $asset_types = $db->read('assets', selectColumns: 'asset_type', distinct: true);

            if ($asset_types) {

                $asset_types = array_column($asset_types, 'asset_type');
                $inline_keyboard = [];
                foreach ($asset_types as $asset_type)
                    $inline_keyboard[] = [[
                        'text' => beautifulNumber($asset_type, null),
                        'callback_data' => json_encode(['alert_type' => $asset_type], JSON_UNESCAPED_UNICODE)
                    ]];
                $inline_keyboard[] = [[
                    'text' => '🔙 برگشت 🔙',
                    "style" => "primary",
                    'callback_data' => json_encode(['show_all_alerts' => null])
                ]];

                $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
                $data['reply_markup']['inline_keyboard'] = $inline_keyboard;
                sendToTelegram('editMessageText', $data);
            }
            break;

        // Show list of assets of specific type to select for new alert
        case 'alert_type': # ── Request from alert list
        case 'fav_alert_type': # ─────── Request from favorites message

            if ($query_key == 'fav_alert_type') {
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
                    ['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['add_alert' => null])],
                    ['text' => '❌ لغو ❌', "style" => "danger", 'callback_data' => json_encode(['show_all_alerts' => null])]
                ]];
                $assets = $db->read('assets', ['asset_type' => $query_data[$query_key]]);
            }

            if ($assets) {
                $data['text'] = 'گزینه‌ی مد نظر خود را از لیست زیر انتخاب کنید:';

                foreach ($assets as $asset) array_unshift(
                    $data['reply_markup']['inline_keyboard'],
                    [['text' => beautifulNumber($asset['name'], null), 'callback_data' => json_encode(['new_alert' => $asset['id']], JSON_UNESCAPED_UNICODE)]]
                );
            } else $data['text'] = 'دسته‌بندی مورد نظر خالی‌ست!';

            sendToTelegram('editMessageText', $data);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            exit;

        // Ask for alert price via ForceReply (no empty level / s3)
        case 'new_alert': # ─────── Add price for new alert, from main alerts menu
        case 'edit_alert': # ────── Edit price of an alert, from main alerts menu
        case 'fav_new_alert': # ─── Add price for new alert, from favorites' alert menu
        case 'fav_edit_alert': # ── Edit price of an alert, from favorites' alert menu

            $item_id = $query_data[$query_key];
            if ($query_key == 'new_alert') {
                $user->setKeyboard([[['id' => 'alerts', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]]);
                $progress = ['new_alert' => ['asset_id' => $item_id]];
                $asset = $db->read('assets', ['id' => $item_id], true);

            } elseif ($query_key == 'edit_alert') {
                $user->setKeyboard([[['id' => 'alerts', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]]);
                $progress = ['edit_alert' => ['alert_id' => $item_id]];
                $asset = $db->query("
                    SELECT assets.*
                    FROM assets JOIN alerts ON alerts.asset_name = assets.name
                    WHERE alerts.user_id = '{$user->getId()}' AND alerts.id = '$item_id'")->fetch();

            } elseif ($query_key == 'fav_new_alert') {
                $user->setKeyboard([[['id' => 'prices', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]]);
                $progress = ['fav_new_alert' => ['asset_id' => $item_id]];
                $asset = $db->read('assets', ['id' => $item_id], true);

            } else {
                $user->setKeyboard([[['id' => 'prices', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]]);
                $progress = ['fav_edit_alert' => ['alert_id' => $item_id]];
                $asset = $db->query("
                    SELECT assets.*
                    FROM assets JOIN alerts ON alerts.asset_name = assets.name
                    WHERE alerts.user_id = '{$user->getId()}' AND alerts.id = '$item_id'")->fetch();

            }

            if (!$asset) {
                sendToTelegram('answerCallbackQuery', [
                    'callback_query_id' => $callback_query['id'],
                    'text' => '❌ دارایی مورد نظر یافت نشد.',
                ]);
                exit;
            }

            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);
            $user->setProgress($progress);
            $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);

            askForAlertPrice($user, $asset);
            exit;

        // Toggle alert's activation status
        case 'toggle_alert_activation':
            $alert_id = $query_data[$query_key];
            $db->query("UPDATE alerts SET status = IF(status = 'active', 'inactive', 'active') WHERE id = $alert_id;")->fetch();
            sendAllAlerts($user, $db, $message['message_id']);

        // Ask user to confirm deleting alert (from alerts list)
        case 'del_alert':
            $alert_id = $query_data[$query_key];
            sendAllAlerts($user, $db, $message['message_id'], $alert_id);

        // Ask user to confirm deleting alert (from favorites message)
        case 'del_asset_alert':

            // Query structure due to length limitation: [del_asset_alert => [alert_id => asset_id]]
            $alert_id = array_key_first($query_data[$query_key]);
            $asset_id = $query_data[$query_key][$alert_id];

            $data['text'] = 'آیا از حذف اطمینان دارید؟';
            $data['reply_markup']['inline_keyboard'] = [[
                ['text' => 'تایید', "style" => "danger", 'callback_data' => json_encode(['conf_del_asset_alert' => [$alert_id => $asset_id]])],
                ['text' => 'لغو', "style" => "success", 'callback_data' => json_encode(['show_asset_alerts' => $asset_id])],
            ]];

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        // Delete alert (from alerts list)
        case 'conf_del_alert':
            $alert_id = $query_data[$query_key];
            $db->delete('alerts', ['id' => $alert_id, 'user_id' => $user->getId()]);
            sendAllAlerts($user, $db, $message['message_id']);

        // Delete alert and send alerts' message to the user (from favorites message)
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

            sendToTelegram('editMessageText', $data);
            sendAssetAlerts($user, $db, $asset_id);

        // Show list of alerts for specific asset
        // Called from favorites menu
        case 'show_asset_alerts':
            $asset_id = $query_data[$query_key];
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            $db->update('special_messages', ['status' => 'paused'], ['user_id' => $user->getId(), 'type' => 'live_price', 'status' => 'active', 'message_id' => $message['message_id']]);
            sendAssetAlerts($user, $db, $asset_id, $message['message_id']);

        // Show main list of all alerts
        case 'show_all_alerts':
            sendAllAlerts($user, $db, $message['message_id']);
    }
    exit();
}
