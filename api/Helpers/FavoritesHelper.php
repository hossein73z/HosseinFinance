<?php

require_once __DIR__ . '/../Functions/MessageFunctions.php';

function sendAllFavorites(User $user, DatabaseManager $db, int|string|null $message_id = null): void
{

    $favorites = getFavoriteWithExchangeRateAndAlerts($user->getId(), $db);

    // Check if user already has an active or paused live-price message
    $live_message = $db->read(
        table: 'special_messages',
        conditions: [
            'user_id' => $user->getId(),
            'type' => 'live_price',
            'status' => ['active', 'paused']],
        single: true
    );

    if ($message_id == null) {
        /** Sending new favorites' message */

        $result = sendToTelegram('sendRichMessage', [
            'chat_id' => $user->getid(),
            'reply_markup' => createFavoritesInlineMarkup(is_live: (bool)$live_message, has_favorites: (bool)$favorites),
            'rich_message' => createFavoritesRichMessage($favorites, $user->getBaseCurrency()),
        ]);

        if ($live_message) {

            // Set new message as active live-price in the database
            $db->upsert(
                table: 'special_messages',
                data: [
                    'user_id' => $user->getId(),
                    'type' => 'live_price',
                    'status' => 'active',
                    'message_id' => $result['result']['message_id']
                ]
            );

            // Disable or delete last live-price message if it was not paused
            if ($live_message['status'] == 'active') {

                // Try to stop last active live-price message
                $response = sendToTelegram('editMessageReplyMarkup', [
                    'chat_id' => $user->getid(),
                    'message_id' => $live_message['message_id'],
                    'reply_markup' => createFavoritesInlineMarkup(is_live: false, has_favorites: true)
                ]);

                // Delete last live-price message if it couldn't be stopped
                if (!$response) sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $live_message['message_id']]);
            }
        }

    } else {
        /** Editing existing favorites' message */

        $is_live = $live_message['message_id'] == $message_id;
        sendToTelegram('editMessageText', [
            'chat_id' => $user->getid(),
            'message_id' => $message_id,
            'reply_markup' => createFavoritesInlineMarkup(is_live: $is_live, has_favorites: (bool)$favorites),
            'rich_message' => createFavoritesRichMessage($favorites, $user->getBaseCurrency()),
        ]);
    }
    exit();
}

function getFavoriteWithExchangeRateAndAlerts(string|int $user_id, DatabaseManager $db): bool|array
{
    try {
        $favorites = $db->query("
            SELECT
                a.*,
                f.id as fav_id,
                (select price from assets where assets.name = a.base_currency)
                    / (select price from assets where assets.name = ifnull(json_unquote(json_extract(u.settings, '$.base_currency')), 'ریال')) as exchange_rate,
                CONCAT('[',
                    GROUP_CONCAT(
                        JSON_OBJECT(
                            'id', al.id,
                            'user_id', al.user_id,
                            'asset_name', al.asset_name,
                            'target_price', al.target_price,
                            'trigger_type', al.trigger_type,
                            'status', al.status,
                            'created_date', al.created_date,
                            'created_time', al.created_time,
                            'triggered_date', al.triggered_date,
                            'triggered_time', al.triggered_time,
                            'note', al.note
                        )
                    ),
                ']') AS alerts
            FROM favorites f 
                LEFT JOIN assets a ON f.asset_name = a.name
                LEFT JOIN users u ON f.user_id = u.id
                LEFT JOIN alerts al ON f.asset_name = al.asset_name
            WHERE f.user_id = $user_id
            GROUP BY f.id, a.id, asset_type
            ORDER BY asset_type DESC, f.id;"
        )->fetchAll();

    } catch (Exception $e) {
        error_log('createFavoritesText: ' . $e->getMessage());
        $favorites = null;
    }
    return $favorites;
}

function createFavoritesInlineMarkup(
    bool $is_live,
    bool $has_favorites): array
{

    return ['inline_keyboard' => ($has_favorites)
        ? [
            ($is_live) ?
                [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]] :
                [['text' => 'نمایش زنده قیمت‌ها ▶', 'callback_data' => json_encode(['set_live' => true])]],
            [['text' => 'افزودن هشدار قیمت', 'callback_data' => json_encode(['fav_alert' => null])]],
            [['text' => 'حذف / اضافه', 'callback_data' => json_encode(['edit_fav' => null])]]
        ] : [
            [['text' => 'حذف / اضافه', 'callback_data' => json_encode(['edit_fav' => null])]]
        ]];
}

function createFavoritesRichMessage(
    array  $assets,
    string $base_currency): array
{

    $rich_message = ['is_rtl' => true, 'html' => ""];
    if ($assets) {

        $asset_type = '';
        foreach ($assets as $asset) {

            // Create new header if iterated to new asset type
            if ($asset['asset_type'] != $asset_type) {

                // Close previous list tag and add a divider, only if not the first asset type
                if ($asset_type) $rich_message['html'] .= "</ul><hr/>";

                $asset_type = $asset['asset_type'];
                $date = preg_split('/-/u', $asset['date']);
                $date[1] = str_replace(
                    ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
                    ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
                    $date[1]);
                $date_string = "$date[2] $date[1] $date[0]";

                // Add asset type header
                $rich_message['html'] .= "<h4>" . beautifulNumber("آخرین قیمت‌های " . "«{$asset_type}»" . " در " . $date_string . " ساعت " . $asset['time'], null) . "</h4><ul>";
            }

            // Create asset detail list item text
            $asset_name = beautifulNumber($asset['name'], null);
            $asset_price = beautifulNumber($asset['price']);
            $asset_base = beautifulNumber($asset['base_currency'], null);
            $asset_line = $asset_name . ': ' . $asset_price . ' ' . $asset_base;

            // Calculate and add asset price based on user's base currency
            if ($asset['base_currency'] != $base_currency) {
                $based_price = beautifulNumber($asset['price'] * $asset['exchange_rate']);
                $based_price_text = ' --> ' . $based_price . ' ' . $base_currency;
                $asset_line .= $based_price_text;
            }

            $asset_alerts = json_decode($asset['alerts'], true);
            if ($asset_alerts[0]['id'] != null) {
                $callback_data = json_encode(['show_asset_alerts' => $asset['id']]);
                $alerts_count = beautifulNumber(sizeof($asset_alerts));
                $alert_button_string = "<tg-button type='callback_data' data='$callback_data'>🔔 $alerts_count</tg-button>";
            } else $alert_button_string = '';

            // Add asset detail list item
            $rich_message['html'] .= "<li>$asset_line $alert_button_string</li>";
        }
    } else $rich_message['html'] = '<p>لیست علاقه‌مندی‌های شما خالیست!</p>';

    return $rich_message;
}
