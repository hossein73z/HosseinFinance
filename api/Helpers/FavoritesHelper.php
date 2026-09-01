<?php

require_once __DIR__ . '/../Functions/MessageFunctions.php';

/**
 * Edits a message (or send new one if `$message_id` is null) with
 * the prices of favorites item and corresponding inline keyboard.
 * Also, updates the live price message in the database only if
 * `$message_id` is null.
 *
 * @param User $user
 * @param DatabaseManager $db
 * @param int|string|null $message_id ID of the message to be edited.
 * @return void
 */
function sendAllFavorites(User $user, DatabaseManager $db, int|string|null $message_id = null): void
{

    if ($message_id == null) {
        $message_id = sendLoadingMessage($user->getid(), 'در حال دریافت اطلاعات لیست علاقه‌مندی‌ها ...')['result']['message_id'];
        updateLivePriceMessageInDatabase($user, $message_id, $db);
    }

    $favorites = getFavoriteWithExchangeRate($user->getId(), $db);

    sendToTelegram('editMessageText', [
        'chat_id' => $user->getid(),
        'message_id' => $message_id,
        'text' => createFavoritesText(
            assets: $favorites,
            base_currency: $user->getBaseCurrency(),
            markdown: 'MarkdownV2'),
        'parse_mode' => 'MarkdownV2',
        'reply_markup' => [
            'inline_keyboard' => createFavoritesInlineKeyboard($user->getId(), $message_id, $db, null, boolval($favorites))
        ]
    ]);
    exit();
}

/**
 * If user has active or paused live price message registered
 * in the database, deletes it and updates its `message_id`
 * to the new `$message_id`.
 */
function updateLivePriceMessageInDatabase(User $user, string|int $message_id, DatabaseManager $db): void
{
    // Check if user has active or paused live price message
    $live_message = $db->read(
        table: 'special_messages',
        conditions: [
            'user_id' => $user->getId(),
            'type' => 'live_price',
            'status' => ['active', 'paused']],
        single: true
    );
    if ($live_message) {

        // Stop or delete previous live-price message only if it was active
        if ($live_message['status'] == 'active') {

            // Try to change last live-price message's keyboard to stop status
            $response = sendToTelegram('editMessageReplyMarkup', [
                'chat_id' => $user->getid(),
                'message_id' => $live_message['message_id'],
                'reply_markup' => [
                    'inline_keyboard' => createFavoritesInlineKeyboard($user->getId(), $live_message['message_id'], $db, false, true)
                ]]);

            // Delete last live-price message if it couldn't be stopped
            if (!$response) sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $live_message['message_id']]);
        }

        // Replace previous life price message in the database
        $db->upsert('special_messages', ['user_id' => $user->getId(), 'type' => 'live_price', 'status' => 'active', 'message_id' => $message_id]);
    }

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

/**
 * Creates an array of array of inline buttons for favorites' message.
 * The `$message_id` and `$db` are only required if `$is_live` is null,
 * so the function can check the database to see if the message is live.
 *
 * @param int|string $user_id
 * @param int|null $message_id The ID of current message to be checked for live update.
 * @param DatabaseManager|null $db
 * @param bool|null $is_live Is this message an active life message.
 * @param bool|null $has_favorites If `null`, The function automatically checks the database for any registered favorites
 * @return array[]
 */

function createFavoritesInlineKeyboard(
    int|string       $user_id,
    ?int             $message_id,
    ?DatabaseManager $db,
    ?bool            $is_live,
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

    $has_favorites = $has_favorites ?? boolval($db->read('favorites', ['favorites.user_id' => $user_id]));

    return ($has_favorites)
        ? [
            ($is_live) ?
                [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]] :
                [['text' => 'نمایش زنده قیمت‌ها ▶', 'callback_data' => json_encode(['set_live' => true])]],
            [['text' => 'افزودن هشدار قیمت', 'callback_data' => json_encode(['fav_alert' => null])]],
            [['text' => 'حذف / اضافه', 'callback_data' => json_encode(['edit_fav' => null])]]
        ] : [
            [['text' => 'حذف / اضافه', 'callback_data' => json_encode(['edit_fav' => null])]]
        ];
}

/**
 * Creates a well-structured text for favorites' message.
 *
 * @param array $assets Array of assets, must be ordered by `asset_type`
 * @param string $base_currency Users' base currency
 * @param string|null $markdown Supports 'MarkdownV2'
 * @return string
 */
function createFavoritesText(
    array   $assets,
    string  $base_currency,
    ?string $markdown = null): string
{
    if ($assets) {
        $text = '';
        $asset_type = '';
        $latest_updated_type = ['name' => null, 'date' => '', 'time' => ''];
        foreach ($assets as $asset) {

            // Find and store latest price update
            if ($markdown) {
                if ($asset['date'] > $latest_updated_type['date'] || !$latest_updated_type['date'])
                    $latest_updated_type = ['name' => $asset['asset_type'], 'date' => $asset['date'], 'time' => $asset['time'],];
                elseif ($asset['date'] == $latest_updated_type['date'] && $asset['time'] > $latest_updated_type['time'])
                    $latest_updated_type = ['name' => $asset['asset_type'], 'date' => $asset['date'], 'time' => $asset['time'],];
            }

            // Create and add asset type header text to `$text`
            if ($asset['asset_type'] != $asset_type) {
                $asset_type = $asset['asset_type'];
                $date = preg_split('/-/u', $asset['date']);
                $date[1] = str_replace(
                    ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
                    ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
                    $date[1]);
                $type_header_line = beautifulNumber("\nآخرین قیمت‌های «$asset[asset_type]» در " . "$date[2] $date[1] $date[0]" . " ساعت " . $asset['time'], null);
                $text .= $markdown ? "\n" . markdownScape($type_header_line) : "\n" . $type_header_line;
            }

            // Create asset detail line
            $asset_name = beautifulNumber($asset['name'], null);
            $asset_price = beautifulNumber($asset['price']);
            $asset_base = beautifulNumber($asset['base_currency'], null);

            // Add asset detail line to `$text`
            $asset_line = "\n   -- " . $asset_name . ': ' . $asset_price . ' ' . $asset_base;
            $text .= $markdown ? markdownScape($asset_line) : $asset_line;

            if ($asset['base_currency'] != $base_currency) {
                $based_price = beautifulNumber($asset['price'] * $asset['exchange_rate']);
                $based_price_text = ' --> ' . $based_price . ' ' . $base_currency;
                $text .= $markdown ? markdownScape($based_price_text) : $based_price_text;
            }

        }
        if ($markdown == 'MarkdownV2') {
            $pattern = "آخرین قیمت‌های «" . markdownScape($latest_updated_type['name']) . "» در .. .+? .... ساعت ..:.." . "\n";
            $pattern .= "( {3}\\\-\\\- .+?: .+?\n?)+?((\n\n)|$)";
            $text = preg_replace("/$pattern/u", ' *\\0* ', $text);
        }
        /**
         * Patterns to extract text from markdown message:
         *  Asset line (Specific asset): `"/^\*? {3}\\\-\\\- ".markdownScape($asset_name).": .+?\*?$/um"`
         *  Asset line (not-Specific asset): `"( {3}\\\-\\\- .+?: .+?\n?)+?((\n\n)|$)"`
         *  Type line: `"آخرین قیمت‌های «" . markdownScape($latest_updated_type['name']) . "» در .. .+? .... ساعت ..:.." . "\n"`
         */
    } else $text = markdownScape('لیست علاقه‌مندی‌های شما خالیست!');

    return trim($text);
}