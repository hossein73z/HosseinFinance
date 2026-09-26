<?php

use JetBrains\PhpStorm\NoReturn;

#[NoReturn]
function prices_menu(
    User            $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?array          $callback_query = null): void
{

    if ($callback_query) {
        handlePricesCallback($user, $callback_query, $message, $db);
    } else {
        $assets = $db->read(table: 'assets', orderBy: ['asset_type' => 'DESC']);
        $asset_types = array_values(array_unique(array_column($assets, 'asset_type')));

        if (!$message) {

            $keyboard = [
                [['id' => 'favorites', 'text' => '❤ علاقه‌مندی‌ها ❤', 'style' => 'success', 'admin_key' => 0]],
                [['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0]],
            ];
            foreach ($asset_types as $asset_type) array_unshift($keyboard, [['text' => $asset_type]]);

            $user->setProgress(null);
            $user->setButton(new Button(
                id: 'prices',
                attrs: ['text' => '💰 قیمت‌ها'],
                adminKey: false,
                belongTo: 'main_menu',
                keyboard: $keyboard
            ));
        } elseif ($pressed_button_id = getPressedButtonID($message['text'], $user)) {
            levelHandler($user, $db, button_id: $pressed_button_id);
        } else {
            if (in_array($message['text'], $asset_types)) {

                $base_prices = CreateNamePricePairs(array_merge($asset_types, [$user->getBaseCurrency()]), $db);

                if ($assets) $data['text'] = createPricesTextForSingleAssetType($assets, $base_prices, $user->getBaseCurrency());
                else $data['text'] = 'این دسته بندی خالی‌ست!';

            } else $data['text'] = 'پیام نامفهوم است!' . "\n" . 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
        }
    }

    $data = [
        'chat_id' => $user->getid(),
        'text' => $data['text'] ?? $user->getButton()->getText(),
        'reply_markup' => [
            'keyboard' => $data['reply_markup']['keyboard'] ?? $user->getKeyboard(),
            'resize_keyboard' => true,
            'is_persistent' => false,
            'input_field_placeholder' => $user->getButton()->getText()
        ]
    ];

    $response = sendToTelegram('sendMessage', $data);
    if ($response)
        $db->update('users', ['button' => json_encode($user->getButton()), 'progress' => null], ['id' => $user->getId()]);
    if (!$message) sendAllFavorites($user, $db);
    exit($user->getButton()->getText());
}

function handlePricesCallback(
    User            $user,
    array           $callback_query,
    array           $message,
    DatabaseManager $db): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => '📢 خطای ناشناخته!'
    ];

    $query_data = $callback_query['data'];

    try {
        $query_key = array_key_first($query_data);
    } catch (TypeError $e) {
        exit('Wrong query data: ' . $e->getMessage());
    }
    switch ($query_key) {

        // Show menu to add/remove a favorite asset
        case 'edit_fav':

            $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
            $data['reply_markup']['inline_keyboard'] = [[
                ['text' => '🔙 برگشت 🔙', "style" => "primary", 'callback_data' => json_encode(['show_favorites' => null])]
            ]];

            $asset_types = $db->read(
                table: 'assets',
                selectColumns: 'asset_type',
                distinct: true,
                orderBy: ['asset_type' => 'DESC']);
            $asset_types = array_column($asset_types, 'asset_type');

            foreach ($asset_types as $asset_type) {
                array_unshift(
                    $data['reply_markup']['inline_keyboard'],
                    [['text' => beautifulNumber($asset_type, null), 'callback_data' => json_encode(['mng_fav_type' => $asset_type], JSON_UNESCAPED_UNICODE)]]
                );
            }

            sendToTelegram('editMessageText', $data);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            $db->update('special_messages', ['status' => 'paused'], ['user_id' => $user->getId(), 'type' => 'live_price', 'status' => 'active', 'message_id' => $message['message_id']]);
            exit;

        /**
         * Show a message to manage favorites under with a specific
         * type. All the three cases below show the same message.
         */
        case 'mng_fav_type': // ── Just show list of assets
        case 'mng_fav_add': // ─── Show list of assets and add a favorite
        case 'mng_fav_del': // ─── Show list of assets and delete a favorite

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
                SELECT
                    a.*, IF(f.user_id IS NULL, 0, 1) AS in_favorites
                FROM assets a 
                LEFT JOIN favorites f
                    ON f.asset_name = a.name
                    AND f.user_id = " . $user->getId() . "
                WHERE
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

            sendToTelegram('editMessageText', $data);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
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

            sendToTelegram('editMessageText', $data);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendAllFavorites($user, $db);

        // Start showing live price updates on the current message
        case 'set_live':
            deleteOldActiveLiveMessage($user, $message['message_id'], $db);
            setLiveMessage($user->getId(), $query_data['set_live'], $message['message_id'], $db);
            sendAllFavorites($user, $db, $message['message_id']);

        // Show the main favorites' message
        case 'show_favorites':
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            $db->update('special_messages', ['status' => 'active'], ['user_id' => $user->getId(), 'type' => 'live_price', 'status' => 'paused', 'message_id' => $message['message_id']]);
            sendAllFavorites($user, $db, $message['message_id']);

        default:
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            exit;
    }
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
