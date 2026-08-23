<?php


function level_5(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 5], true));

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

    $asset_types = $db->read(
        table: 'assets',
        selectColumns: 'asset_type',
        distinct: true,
        orderBy: ['asset_type' => 'DESC']
    );

    $asset_types = array_column($asset_types, 'asset_type');

    // Add asset types to level 5 keyboard
    foreach ($asset_types as $asset_type) array_unshift($keyboard, [['text' => $asset_type]]);
    $data['reply_markup']['keyboard'] = $keyboard;

    if ($callback_query) handlePricesCallback($user, $callback_query, $message, $asset_types, $db);
    if ($message) handlePricesTextMessage($data, $message, $asset_types, $user->getBaseCurrency(), $db);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        // Send Informative message
        sendAllFavorites($user, $db);
    }

    exit;
}

function handlePricesCallback(
    User            $user,
    array           $callback_query,
    array           $message,
    array           $asset_types,
    DatabaseManager $db): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => '📢 خطای ناشناخته!'
    ];

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    switch ($query_key) {

        // Show menu to add/remove a favorite asset
        case 'edit_fav':

            $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
            $data['reply_markup']['inline_keyboard'] = [[
                ['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['show_favorites' => null])]
            ]];

            foreach ($asset_types as $asset_type) {
                array_unshift(
                    $data['reply_markup']['inline_keyboard'],
                    [['text' => beautifulNumber($asset_type, null), 'callback_data' => json_encode(['mng_fav_type' => $asset_type], JSON_UNESCAPED_UNICODE)]]
                );
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            $db->update('special_messages', ['status' => 'paused'], ['user_id' => $user->getId(), 'type' => 'live_price', 'status' => 'active', 'message_id' => $message['message_id']]);
            exit;

        /**
         * Show a message to manage favorites under with a specific
         * type. All the three cases below show the same message.
         */
        case 'mng_fav_type': // --- Just show list of assets
        case 'mng_fav_add': // ---- Show list of assets and add a favorite
        case 'mng_fav_del': // ---- Show list of assets and delete a favorite

            $data['reply_markup']['inline_keyboard'] = [[
                ['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['edit_fav' => null])],
                ['text' => '🔚 پایان 🔚', "style" => "success", 'callback_data' => json_encode(['show_favorites' => null])]
            ]];

            if ($query_key != 'mng_fav_type') {
                try {
                    $asset_name = $query_data[$query_key];
                    $asset_type = $db->read('assets', ['name' => $asset_name], true, 'asset_type', true)['asset_type'];
                    if ($query_key == 'mng_fav_add') $db->create('favorites', ['user_id' => $user->getId(), 'asset_name' => $asset_name]);
                    if ($query_key == 'mng_fav_del') $db->delete('favorites', ['user_id' => $user->getId(), 'asset_name' => $asset_name], true);
                } catch (Exception $e) {
                    error_log('Error adding new favorite: ' . $e->getMessage());
                    exit;
                }
            } else $asset_type = $query_data['mng_fav_type'];

            // Read assets under $asset_type with added `in_favorites` property
            $assets = $db->query(
                "
                select
                    a.*, CASE WHEN f.user_id IS NULL THEN 0 ELSE 1 END AS in_favorites
                from assets a 
                left join favorites f
                    on f.asset_name = a.name
                    AND f.user_id = " . $user->getId() . "
                where
                    a.asset_type = '$asset_type'"
            )->fetchAll();

            if ($assets) {

                $data['text'] = 'گزینه‌ی مد نظر خود را از لیست زیر انتخاب کنید:';

                // Create inline buttons for read assets
                foreach ($assets as $asset) {
                    $asset_name = ($asset['in_favorites'] ? '🔳 ' : '⬜ ') . beautifulNumber($asset['name'], null);
                    $callback_data = json_encode([($asset['in_favorites'] ? 'mng_fav_del' : 'mng_fav_add') => $asset['name']], JSON_UNESCAPED_UNICODE);
                    array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => $asset_name, 'callback_data' => $callback_data]]
                    );
                }
            } else $data['text'] = 'دسته‌بندی مورد نظر خالی‌ست!';

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            $db->update('special_messages', ['status' => 'paused'], ['user_id' => $user->getId(), 'type' => 'live_price', 'status' => 'active', 'message_id' => $message['message_id']]);
            exit;

        // Add new favorite to the table and send the favorites message to the user
        case 'new_fav_name': # Old Approach, not used anymore.

            $asset_name = $query_data['new_fav_name'];
            try {
                $db->create(
                    table: 'favorites',
                    data: [
                        'user_id' => $user->getId(),
                        'asset_name' => $asset_name
                    ]
                );
                $data['text'] = '✅ «' . beautifulNumber($asset_name, null) . '» به لیست علاقه‌مندی‌های شما افزوده شد!';
            } catch (Exception $e) {
                error_log('Error adding new favorite: ' . $e->getMessage());
                $data['text'] = '❌ خطای پایگاه داده!';
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            sendAllFavorites($user, $db);

        // Start showing live price updates on the current message
        case 'set_live':
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            deleteOldActiveLiveMessage($user, $message['message_id'], $db);
            setLiveMessage($user->getId(), $query_data['set_live'], $message['message_id'], $db);
            sendAllFavorites($user, $db, $message['message_id']);

        // Show the main favorites' message
        case 'show_favorites':
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            $db->update('special_messages', ['status' => 'active'], ['user_id' => $user->getId(), 'type' => 'live_price', 'status' => 'paused', 'message_id' => $message['message_id']]);
            sendAllFavorites($user, $db, $message['message_id']);

        default:
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            exit;
    }
}

function handlePricesTextMessage(
    array           $data,
    array           $message,
    array           $asset_types,
    string          $base_currency,
    DatabaseManager $db): void
{
    if (in_array($message['text'], $asset_types)) {

        // Retrieve all related assets
        $assets = $db->read('assets', ['asset_type' => $message['text']]);

        $base_prices = CreateNamePricePairs(
            array_merge(array_unique(array_column($assets, 'base_currency')), [$base_currency]),
            $db
        );

        if ($assets) $data['text'] = createPricesTextForSingleAssetType($assets, $base_prices, $base_currency);
        else $data['text'] = 'این دسته بندی خالی‌ست!';

        $data['reply_to_message_id'] = $message['message_id'];
        sendToTelegram('sendMessage', $data);
        exit;
    }

    // Send default message of this level
    $data['text'] = 'پیام نامفهوم است!' . "\n" . 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
    sendToTelegram('sendMessage', $data);
    exit;
}

/**
 * Activate/Inactivate current message in the database as `live_price`.
 *
 * @param int|string $user_id
 * @param bool $activate On false only works on existing record with the same `$message_id`
 * @param int|string $message_id The ID of the message to set as live price message
 * @param DatabaseManager $db
 * @return bool|null Activation state on success or `null` on database error
 */
function setLiveMessage(int|string $user_id, bool $activate, int|string $message_id, DatabaseManager $db): bool|null
{
    $db_result = false;
    try {
        if ($activate === true)
            $db_result = $db->upsert(
                table: 'special_messages',
                data: [
                    'user_id' => $user_id,
                    'type' => 'live_price',
                    'status' => 'active',
                    'message_id' => $message_id,
                ]
            );

        if ($activate === false)
            $db_result = $db->update(
                table: 'special_messages',
                data: [
                    'status' => 'inactive',
                ],
                conditions: [
                    'user_id' => $user_id,
                    'type' => 'live_price',
                    'message_id' => $message_id
                ]
            );
    } catch (Exception $e) {
        error_log('changeLiveMessageState: ' . $e->getMessage());
    }

    if ($db_result) return $activate;
    else return null;
}
