<?php

use JetBrains\PhpStorm\NoReturn;

#[NoReturn]
function holdings_menu(
    User            $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?array          $callback_query = null): void
{

    if ($callback_query) {
        handleHoldingsCallback($user, $callback_query, $message, $db);
    } elseif (!$message) { // Just entered the Level
        $user->setProgress(null);
        $user->setButton(new Button(
            id: 'holdings',
            attrs: ['text' => '💼 دارایی‌ها'],
            adminKey: false,
            belongTo: 'main_menu',
            keyboard: [
                [['id' => 'buy_new_holding', 'text' => 'ثبت خرید جدید دارایی', 'style' => 'success', 'admin_key' => 0],],
                [['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],],
            ]
        ));
    } else {
        if ($pressed_button_id = getPressedButtonID($message['text'], $user))
            switch ($pressed_button_id) {
                case 'buy_new_holding':
                    addHoldingProgress($user, null, $db);
                    break;

                case 'back':
                    $progress = $user->getProgress();
                    $progress_key = array_key_first($progress);
                    unset($progress[$progress_key][array_key_first($progress[$progress_key])]); // TODO: Clean this
                    $user->setProgress($progress);
                    addHoldingProgress($user, null, $db);
                    break;

                default:
                    levelHandler($user, $db, button_id: $pressed_button_id);
            }
        else {
            addHoldingProgress($user, $message, $db);
            $data['text'] = 'پیام نامفهوم است. لطفاً یکی از دکمه‌های زیر را انتخاب کنید.';
        }
    }

    sendInitialLevelMessage($user, $db, $data ?? null);
    if (!$message) sendAllHoldings($user, $db);
    exit($user->getButton()->getText());
}

#[NoReturn]
function handleHoldingsCallback(User $user, array $callback_query, array $message, DatabaseManager $db): void
{

    $query_data = $callback_query['data'];
    $query_key = array_key_first($query_data);

    switch ($query_key) {

        case 'view_holding':
        case 'edit_holding':
            $holding_id = $query_data[$query_key];
            $holding = getHoldingsWithAssetDetails(['h.id' => $holding_id, 'h.user_id' => $user->getId()], $db, true);
            if ($holding) {
                sendHoldingDetail($user, $holding, $message['message_id'], is_editing: $query_key === 'edit_holding');
                sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
                exit(($query_key == 'view_holding' ? 'View' : 'Edit') . " holding: id=$holding_id asset_name=\"$holding[asset_name]\"");
            }
            break;

        case 'edit_holding_name':
        case 'edit_holding_price':
        case 'edit_holding_amount':
            $holding_id = $query_data[$query_key];
            $holding = getHoldingsWithAssetDetails(['h.id' => $holding_id, 'h.user_id' => $user->getId()], $db, true);
            if ($holding) {

                $progress['edit_holding'] = [];
                if ($query_key != 'edit_holding_name') $progress['edit_holding']['asset_type'] = $holding['asset_type'];
                if ($query_key != 'edit_holding_name') $progress['edit_holding']['asset_name'] = $holding['asset_name'];
                if ($query_key != 'edit_holding_price') $progress['edit_holding']['avg_price'] = $holding['avg_price'];
                if ($query_key != 'edit_holding_amount') $progress['edit_holding']['amount'] = $holding['amount'];
                $progress['edit_holding']['holding_id'] = $holding['id'];

                sendToTelegram('deleteMessage', ['chat_id' => $user->getId(), 'message_id' => $message['message_id']]);
                addHoldingProgress($user->setProgress($progress), null, $db);
            }
            break;

        case 'delete_holding':
            $holding_id = $query_data[$query_key];
            $holding = getHoldingsWithAssetDetails(['h.id' => $holding_id, 'h.user_id' => $user->getId()], $db, true);
            if ($holding)
                sendHoldingDetail($user, $holding, $message['message_id'], is_deleting: true);
            break;

        case 'delete_holding_conf':
            $holding_id = $query_data[$query_key];

            $result = $db->delete('holdings', ['id' => $holding_id, 'user_id' => $user->getId()]);

            if ($result) $data['text'] = '✔ دارایی مورد نظر با موفقیت حذف شد!';
            else $data['text'] = '❌ خطای پایگاه داده در حذف دارایی!';

            $data['chat_id'] = $user->getId();
            $data['reply_markup'] = [
                'keyboard' => $user->getKeyboard(),
                'resize_keyboard' => true,
                'is_persistent' => false,
                'input_field_placeholder' => $user->getButton()->getText()
            ];
            sendToTelegram('sendMessage', $data);
            sendAllHoldings($user, $db);
            sendToTelegram('deleteMessage', ['chat_id' => $user->getId(), 'message_id' => $message['message_id']]);
            break;

        case 'show_all_holdings':
            sendAllHoldings($user, $db, $message['message_id']);
            exit;

        default:
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            exit;
    }

    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    exit;
}

function addHoldingProgress(User $user, ?array $message, DatabaseManager $db): null
{
    /**
     * Required fields for new holding:
     *  - asset_type
     *  - asset_name
     *  - amount
     *  - avg_price
     *  - TODO: note
     *  - TODO: date
     *  - TODO: time
     *
     * If any of these values are not presented, asks for
     * it, otherwise adds the holding to the database.
     */

    $data = [
        'chat_id' => $user->getid(),
        'reply_markup' => [
            'keyboard' => $user->getKeyboard(),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'force_reply' => false,
            'input_field_placeholder' => $user->getButton()->getText() // TODO: Write a different text for each level
        ]
    ];

    $progress = $user->getProgress();
    if (!$progress && $message) return null;
    if (!$progress || !in_array(array_key_first($progress), ['add_holding', 'edit_holding']))
        $progress = $user->setProgress(['add_holding' => null])->getProgress();

    $progress_key = array_key_first($progress);

    // Asset Type
    if (!isset($progress[$progress_key]['asset_type'])) {
        if (!$message) askForHoldingAssetType($user, $data, $db);
        $asset = $db->read('assets', ['asset_type' => $message['text']], true);
        if ($asset) $progress[$progress_key]['asset_type'] = $asset['asset_type'];
        else askForHoldingAssetType($user, $data, $db, 'پیام نامفهوم بود. لطفاً دسته‌بندی مد نظر را از دکمه‌های زیر انتخاب کنید.');
        addHoldingProgress($user->setProgress($progress), null, $db);
    }

    // Asset Name
    if (!isset($progress[$progress_key]['asset_name'])) {
        if (!$message) askForHoldingAssetName($user, $data, $progress[$progress_key]['asset_type'], $db);
        $asset = $db->read('assets', ['name' => $message['text']], true);
        if ($asset) $progress[$progress_key]['asset_name'] = $asset['name'];
        else askForHoldingAssetName($user, $data, $progress[$progress_key]['asset_type'], $db, 'پیام نامفهوم بود. لطفاً دارایی مد نظر را از دکمه‌های زیر انتخاب کنید.');
        addHoldingProgress($user->setProgress($progress), null, $db);
    }

    // Amount
    if (!isset($progress[$progress_key]['amount'])) {
        if (!$message) askForHoldingAmount($user, $data, $db);
        $valid_number = cleanAndValidateNumber($message['text']);
        if ($valid_number) $progress[$progress_key]['amount'] = $valid_number;
        else askForHoldingAmount($user, $data, $db, 'پیام نامفهوم بود. لطفاً مقدار دارایی را به عدد وارد کنید.');
        addHoldingProgress($user->setProgress($progress), null, $db);
    }

    // Average Price
    if (!isset($progress[$progress_key]['avg_price'])) {
        if (!$message) askForHoldingPrice($user, $data, $progress[$progress_key]['asset_name'], $db);
        $valid_number = cleanAndValidateNumber($message['text']);
        if ($valid_number) $progress[$progress_key]['avg_price'] = $valid_number;
        else askForHoldingPrice($user, $data, $progress[$progress_key]['asset_name'], $db, 'پیام نامفهوم بود. لطفاً قیمت را به عدد وارد کنید.');
        addHoldingProgress($user->setProgress($progress), null, $db);
    }

    // Add the holding if all the required values are presented
    $new_holding["id"] = $progress[$progress_key]["holding_id"] ?? null;
    $new_holding["user_id"] = $user->getId();
    $new_holding["asset_name"] = $progress[$progress_key]["asset_name"];
    $new_holding["amount"] = beautifulNumber($progress[$progress_key]["amount"], null, false);
    $new_holding["avg_price"] = beautifulNumber($progress[$progress_key]["avg_price"], null, false);
    $new_holding["date"] = new DateTime()->format('Y-m-d');
    $new_holding["time"] = new DateTime()->format('h:i');

    $existing_holding = null;
    if ($progress_key == 'add_holding') {
        /**
         * If adding new holding, check holding's existence
         * by `asset_name` since there is no `id` provided
         */

        $existing_holding = getHoldingsWithAssetDetails(["user_id" => $user->getId(), "asset_name" => $new_holding["asset_name"]], $db, true);
        if ($existing_holding) {
            /** If holding already exists, calculate new amount and average price */

            // New received data
            $price = $new_holding["avg_price"];
            $amount = (float)$new_holding["amount"];

            // Data from old existing holding
            $old_avg_price = $existing_holding["avg_price"];
            $old_amount = (float)$existing_holding["amount"];

            // New merged data
            $new_amount = $amount + $old_amount;
            $new_avg_price = (($price * $amount) + ($old_avg_price * $old_amount)) / $new_amount;

            $new_holding["amount"] = beautifulNumber($new_amount, null, false);
            $new_holding["avg_price"] = beautifulNumber($new_avg_price, null, false);
        }
    }
    if ($progress_key == 'edit_holding') {
        /**
         * If editing a holding, find existing holding by
         * `id`, since the `asset_name` might be edited
         */
        $existing_holding = getHoldingsWithAssetDetails(["h.user_id" => $user->getId(), "h.id" => $new_holding["id"]], $db, true);
    }

    if ($existing_holding) {
        try {
            $db->update('holdings', $new_holding, ['id' => $existing_holding['id']]);
            $data['text'] = '✅ دارایی با موفقیت به‌روزرسانی شد.';
        } catch (PDOException $e) {
            error_log('Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));
            $data['text'] = '❌ خطای پایگاه داده: ' . $e->errorInfo[2];
        }
    } else {
        try {
            $db->create('holdings', $new_holding);
            $data['text'] = '✅ دارایی جدید با موفقیت ثبت شد.';
        } catch (PDOException $e) {
            error_log('Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));
            $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی: ' . $e->errorInfo[2];
        }
    }

    $user->setKeyboard([
        [['id' => 'buy_new_holding', 'text' => 'ثبت خرید جدید دارایی', 'style' => 'success', 'admin_key' => 0],],
        [['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],],
    ])->setProgress(null);
    $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
    $data['reply_markup']['keyboard'] = $user->getKeyboard();
    sendToTelegram('sendMessage', $data);

    $holding = getHoldingsWithAssetDetails(["user_id" => $user->getId(), "asset_name" => $new_holding["asset_name"]], $db, true);

    if ($existing_holding) sendHoldingDetail($user, $holding);
    else sendAllHoldings($user, $db);
    exit('Add holding: ' . json_encode($new_holding, JSON_UNESCAPED_UNICODE));
}

#[NoReturn]
function askForHoldingAssetType(User $user, array $data, DatabaseManager $db, ?string $text = null): void
{
    $asset_types = $db->read(
        table: 'assets',
        selectColumns: 'asset_type',
        distinct: true,
        orderBy: ['asset_type' => 'DESC']
    );

    if ($asset_types) {

        $asset_types = array_column($asset_types, 'asset_type');
        $keyboard = [[['id' => 'holdings', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]];
        foreach ($asset_types as $asset_type) array_unshift($keyboard, [['text' => $asset_type]]);

        $data['text'] = $text ?? 'دسته‌بندی دارایی مورد نظر را از دکمه‌های زیر انتخاب کنید:';
        $data['reply_markup']['keyboard'] = $keyboard;
        $response = sendToTelegram('sendMessage', $data);
        if ($response) {
            $user->setKeyboard($keyboard);
            $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
        }
    } else {
        $data['text'] = 'دسته‌بندی‌ای در سیستم یافت نشد!';
        sendToTelegram('sendMessage', $data);
    }
    exit();
}

#[NoReturn]
function askForHoldingAssetName(User $user, array $data, string $asset_type, DatabaseManager $db, ?string $text = null): void
{
    $assets = $db->read('assets', ['asset_type' => $asset_type]);
    if ($assets) {

        $keyboard = [
            array_key_first($user->getProgress()) == 'edit_holding' ?
                [['id' => 'holdings', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]] :
                [['id' => 'back', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],
                    ['id' => 'holdings', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0],]
        ];
        foreach ($assets as $asset) array_unshift($keyboard, [['text' => $asset['name']]]);

        $data['text'] = $text ?? 'دارایی مورد نظر را از دکمه‌های زیر انتخاب کنید:';
        $data['reply_markup']['keyboard'] = $keyboard;
        $response = sendToTelegram('sendMessage', $data);
        if ($response) {
            $user->setKeyboard($keyboard);
            $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
        }
    } else {
        $data['text'] = 'این دسته‌بندی خالی‌ست!';
        sendToTelegram('sendMessage', $data);
    }
    exit();
}

#[NoReturn]
function askForHoldingAmount(User $user, array $data, DatabaseManager $db, ?string $text = null): void
{
    $keyboard = [
        array_key_first($user->getProgress()) == 'edit_holding' ?
            [['id' => 'holdings', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]] :
            [['id' => 'back', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],
                ['id' => 'holdings', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0],]
    ];

    $data['text'] = $text ?? 'مقدار خرید دارایی را به عدد وارد کنید:';
    $data['reply_markup']['keyboard'] = $keyboard;
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $user->setKeyboard($keyboard);
        $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
    }
    exit();
}

#[NoReturn]
function askForHoldingPrice(User $user, array $data, string $asset_name, DatabaseManager $db, ?string $html = null): void
{
    $asset = $db->read('assets', ['name' => $asset_name], true);
    if ($asset) {

        $name = beautifulNumber($asset['name'], null);
        $price = beautifulNumber($asset['price'], delimiter: '،');
        $base = beautifulNumber($asset['base_currency'], null);

        $data['rich_message']['html'] = $html ??
            ('قیمت خرید را به عدد وارد کنید:' . "<br>" .
                'قیمت کنونی ' . "«{$name}»: <b>$price</b> $base");
        $data['reply_markup']['resize_keyboard'] = true;
        $keyboard = [
            [
                ['text' => $price]
            ],
            array_key_first($user->getProgress()) == 'edit_holding' ?
                [['id' => 'holdings', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]] :
                [
                    ['id' => 'back', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],
                    ['id' => 'holdings', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]
                ]
        ];
        $data['reply_markup']['keyboard'] = $keyboard;
        $response = sendToTelegram('sendRichMessage', $data);
        if ($response) {
            $user->setKeyboard($keyboard);
            $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
        }
    } else {
        $data['text'] = 'این گزینه در دیتابیس وجود ندارد!';
        sendToTelegram('sendMessage', $data);
    }
    exit();
}

function calculateHodlingAmountAndAveragePrice(array $holding_txs): ?array
{
    $buy_amount = 0.0;
    $sel_amount = 0.0;
    $total_cost = 0.0;
    foreach ($holding_txs as $tx) {
        $amount = (float)$tx['amount'];
        $price = (float)$tx['price'];

        if ($tx['type'] == 'inward') {
            $total_cost += $price;
            $buy_amount += $amount;
        } elseif ($tx['type'] == 'outward') {
            $sel_amount += $amount;
        }
    }

    $total_amount = ($buy_amount - $sel_amount) ?? 0;
    $avg_buy_price = ($total_cost / $buy_amount) ?? 0;

    if ($total_amount > 0) {
        return [$total_amount, $avg_buy_price];
    } else
        return [0, $avg_buy_price];
}
