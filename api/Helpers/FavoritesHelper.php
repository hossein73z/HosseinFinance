<?php

require_once __DIR__ . '/../Functions/MessageFunctions.php';

function sendAllFavorites(User $user, DatabaseManager $db, int|string|null $message_id = null): void
{

    $favorites = getFavoriteWithExchangeRate($user->getId(), $db);

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
            'reply_markup' => createFavoritesInlineMarkup($user->getId(), is_live: (bool)$live_message, has_favorites: (bool)$favorites),
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
                    'reply_markup' => createFavoritesInlineMarkup($user->getId(), is_live: false, has_favorites: true)
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
            'reply_markup' => createFavoritesInlineMarkup($user->getId(), is_live: $is_live, has_favorites: (bool)$favorites),
            'rich_message' => createFavoritesRichMessage($favorites, $user->getBaseCurrency()),
        ]);
    }
    exit();
}

function getFavoriteWithExchangeRate(string|int $user_id, DatabaseManager $db): bool|array
{
    try {
        $select_price = "select price from assets where assets.name";

        $asset_base = "a.base_currency";
        $asset_base_price = "$select_price = $asset_base";

        $user_base = "ifnull(json_unquote(json_extract(u.settings, '$.base_currency')), 'ریال')";
        $user_base_price = "$select_price = $user_base";

        $favorites = $db->read(
            table: 'favorites f',
            conditions: ['f.user_id' => $user_id],
            selectColumns: "
                a.*,
                f.id                                     as fav_id,
                ($asset_base_price) / ($user_base_price) as exchange_rate",
            join: '
                LEFT JOIN assets a ON f.asset_name = a.name
                LEFT join users u ON f.user_id = u.id',
            orderBy: ['asset_type' => 'DESC', 'f.id' => 'ASC']
        );

    } catch (Exception $e) {
        error_log('createFavoritesText: ' . $e->getMessage());
        $favorites = null;
    }
    return $favorites;
}

function createFavoritesInlineMarkup(
    int|string       $user_id,
    ?int             $message_id = null,
    ?DatabaseManager $db = null,
    ?bool            $is_live = null,
    ?bool            $has_favorites = null): array
{
    $is_live = $is_live ?? (bool)$db->read(
        table: 'special_messages',
        conditions: [
            'user_id' => $user_id,
            'type' => 'live_price',
            'status' => 'active',
            'message_id' => $message_id,
        ],
        single: true
    );

    $has_favorites = $has_favorites ?? (bool)($db->read('favorites', ['favorites.user_id' => $user_id]));

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

    $rich_message = ['is_rtl' => true, 'blocks' => []];
    if ($assets) {

        $asset_type = '';
        foreach ($assets as $asset) {

            // Create asset line text
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

            $list_block['type'] = 'list';
            $list_block['items'][] = ['blocks' => [['type' => 'paragraph', 'text' => $asset_line]]];

            // Create new header if iterated to new asset type
            if ($asset['asset_type'] != $asset_type) {

                // Add a divider before all headers except the first one
                if ($asset_type) $rich_message['blocks'][] = ['type' => 'divider'];

                $asset_type = $asset['asset_type'];
                $date = preg_split('/-/u', $asset['date']);
                $date[1] = str_replace(
                    ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
                    ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
                    $date[1]);
                $date_string = "$date[2] $date[1] $date[0]";

                // Add header to the return value
                $rich_message['blocks'][] = [
                    'type' => 'heading',
                    'size' => 4,
                    'text' => beautifulNumber("آخرین قیمت‌های " . "«{$asset_type}»" . " در " . $date_string . " ساعت " . $asset['time'], null)
                ];

                if ($asset_type) {
                    $rich_message['blocks'][] = $list_block;
                    $list_block = [];
                }
            }
        }
    } else $rich_message['blocks'] = [['type' => 'paragraph', 'text' => 'لیست علاقه‌مندی‌های شما خالیست!']];

    return $rich_message;
}
