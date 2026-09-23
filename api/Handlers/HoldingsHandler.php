<?php

function holdings(
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
                [['id' => 'add_new_holding', 'text' => 'افزودن دارایی جدید', 'style' => 'success', 'admin_key' => 0],],
                [['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],],
            ]
        ));
    } else { // Received a message in the level

        // Received message contains web_app data
        if (isset($message['web_app_data']))
            handleHoldingsWebAppData($user, $message, $db);

        // Received message is a button
        elseif ($pressed_button_id = getPressedButtonID($message['text'], $user))
            levelHandler($user, $db, button_id: $pressed_button_id);

        // Received message is not recognizable
        else $data['text'] = 'پیام نامفهوم است. لطفاً یکی از دکمه‌های زیر را انتخاب کنید.';
    }

    $data = [
        'chat_id' => $user->getid(),
        'text' => $data['text'] ?? $user->getButton()->getText(),
        'reply_markup' => [
            'keyboard' => $data['reply_markup']['keyboard'] ?? $user->getKeyboard(),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $user->getButton()->getText()
        ]
    ];

    $response = sendToTelegram('sendMessage', $data);
    if ($response)
        $db->update('users', ['button' => json_encode($user->getButton()), 'progress' => null], ['id' => $user->getId()]);
    if (!$message) sendAllHoldings($user, $db);
    exit($user->getButton()->getText());
}

function add_holding(
    User            $user,
    DatabaseManager $db,
    ?array          $message = null): void
{
    // Create keyboards
    $user->setButton(new Button(
        id: 'add_new_holding',
        attrs: ['text' => 'افزودن دارایی جدید'],
        adminKey: false,
        belongTo: 'main_menu',
        keyboard: [[['id' => 'holdings', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]]
    ));

    // Handle cancel button
    if ($message && $pressed_button_id = getPressedButtonID($message['text'], $user))
        levelHandler($user, $db, button_id: $pressed_button_id);

    addHoldingProgress($user, $message, $db);

}

function handleHoldingsCallback(User $user, array $callback_query, array $message, DatabaseManager $db): void
{

    $query_data = $callback_query['data'];
    $query_key = array_key_first($query_data);

    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
    ];

    switch ($query_key) {

        case 'add_holding':
            addHoldingProgress($user, null, $db);
            break;

        case 'show_holding':
            $holding_id = $query_data[$query_key];
            $holding = getHoldingsWithAssetDetails(['h.id' => $holding_id], $db, true);
            if ($holding) {
                sendHoldingDetail($holding, $data, $user->getBaseCurrency());
                $db->update(
                    table: 'users',
                    data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                    conditions: ['id' => $user->getId()]
                );
                sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
                exit;
            }
            break;

        case 'show_all_holdings':
            $db->update('users', ['progress' => null], ['id' => $user->getId()]);
            sendAllHoldings($user, $db, $message['message_id']);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            exit;

        case 'holdings_list':
            $db->update('users', ['progress' => null], ['id' => $user->getId()]);
            sendAllHoldings($user, $db);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            exit;

        default:
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => 'این پیام منقضی شده است!']);
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);
            exit;
    }

    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    exit;
}

function handleHoldingsWebAppData(User $user, array $message, DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);

    $action = $web_app_data['action'] ?? null;

    $expected_data = false;

    if ($action == 'add') {

        $new_holding = $web_app_data['holding'];
        try {
            $db->create(
                table: 'holdings',
                data: [
                    "user_id" => $user->getId(),
                    "asset_id" => $new_holding["asset_id"],
                    "amount" => $new_holding["amount"],
                    "avg_price" => $new_holding["avg_price"],
                    "date" => JalaliDate::fromString($new_holding["date"])->toGregorian()->format('Y-m-d'),
                    "time" => $new_holding["time"],
                    "note" => $new_holding["note"],
                ]
            );
            $data['text'] = '✅ دارایی جدید با موفقیت ثبت شد.';
        } catch (PDOException $e) {

            if ($e->errorInfo[1] == 1062) {
                /**
                 * Duplicate Entry.
                 *
                 * Informs user of existing holding, redirects
                 * them to the holding and breaks the process.
                 */

                $data['text'] = 'شما از قبل این دارایی را در سیستم ثبت کرده اید.' . "\n" .
                    'درصورت تمایل برای ثبت تغییرات، دارایی ثبت شده را ویرایش کنید.';

                sendToTelegram('sendMessage', $data);

                $holding = getHoldingsWithAssetDetails(['h.asset_id' => $new_holding["asset_id"], 'h.user_id' => $user->getId()], $db, true);
                if ($holding) {
                    $db->update(
                        table: 'users',
                        data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                        conditions: ['id' => $user->getId()]
                    );
                    sendHoldingDetail($holding, $data, $user->getBaseCurrency());
                }
                exit;
            }

            error_log(
                'Holding: ' . json_encode($new_holding) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->errorInfo[2];
        }
        $expected_data = true;
    }
    if ($action == 'edit') {

        try {
            $updates = $web_app_data['updates'];
            if (isset($updates['date'])) $updates['date'] = JalaliDate::fromString($updates['date'])->toGregorian()->format('Y-m-d');
            $db->update(
                table: 'holdings',
                data: $updates,
                conditions: ['id' => $web_app_data['id']]
            );
            $data['text'] = '✅ دارایی با موفقیت ویرایش شد.';
        } catch (PDOException $e) {
            error_log(
                'Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->errorInfo[2];
        }
        $expected_data = true;
    }
    if ($action == 'delete') {

        try {
            $db->delete(
                table: 'holdings',
                conditions: ['id' => $web_app_data['id']],
                resetAutoIncrement: true
            );
            $data['text'] = '✅ دارایی با موفقیت حذف شد.';
        } catch (PDOException $e) {
            error_log(
                'Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده درحذف دارایی: ' . $e->errorInfo[2];
        }
        $expected_data = true;
    }

    $data = [
        'chat_id' => $user->getid(),
        'text' => $user->getButton()->getText(),
        'reply_markup' => [
            'keyboard' => $user->getKeyboard(),
            'resize_keyboard' => true,
            'is_persistent' => false,
            'input_field_placeholder' => $user->getButton()->getText()
        ]
    ];

    if ($expected_data) {
        // Send success/failure message
        sendToTelegram('sendMessage', $data);

        // Clear user progress and show all holdings
        $db->update('users', ['progress' => null], ['id' => $user->getId()]);
        sendAllHoldings($user, $db);
    } else {
        $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
        $data = checkAndAddEditHoldingButton($data, $user, $db);
        sendToTelegram('sendMessage', $data);
    }
    exit;
}

function addHoldingProgress(User $user, ?array $message, DatabaseManager $db): void
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
            'force_reply' => true,
            'input_field_placeholder' => $user->getButton()->getText()
        ]
    ];

    $progress = $user->getProgress();
    if (!$progress || !isset($progress['add_holding'])) {
        askForHoldingAssetType($user, $data, $db);
    } else {

        // Asset Type
        // TODO: Skip this level if asset name is already specified
        if (!isset($progress['add_holding']['asset_type'])) {
            if (!$message) askForHoldingAssetType($user, $data, $db);
            if ($message['text'] == 'برگشت به لیست دارایی‌ها') holdings($user, $db);
            $assets = $db->read('assets', ['asset_type' => $message['text']]);
            if ($assets) $progress['add_holding']['asset_type'] = $assets[0]['asset_type'];
            else askForHoldingAssetType($user, $data, $db, 'پیام نامفهوم بود. لطفاً دسته‌بندی مد نظر را از دکمه‌های زیر انتخاب کنید.');
            addHoldingProgress($user->setProgress($progress), null, $db);
        }

        // Asset Name
        if (!isset($progress['add_holding']['asset_name'])) {
            if (!$message) askForHoldingAssetName($user, $data, $progress['add_holding']['asset_type'], $db);
            if ($message['text'] == 'لغو') holdings($user, $db);
            if ($message['text'] == 'برگشت') askForHoldingAssetType($user, $data, $db);
            $asset = $db->read('assets', ['name' => $message['text']], true);
            if ($asset) $progress['add_holding']['asset_name'] = $asset['name'];
            else askForHoldingAssetName($user, $data, $progress['add_holding']['asset_type'], $db, 'پیام نامفهوم بود. لطفاً دارایی مد نظر را از دکمه‌های زیر انتخاب کنید.');
            addHoldingProgress($user->setProgress($progress), null, $db);
        }

        // Amount
        if (!isset($progress['add_holding']['amount'])) {
            if (!$message) askForHoldingAmount($user, $data, $db);
            if ($message['text'] == 'لغو') holdings($user, $db);
            if ($message['text'] == 'برگشت') askForHoldingAssetName($user, $data, $progress['add_holding']['asset_type'], $db);
            $valid_number = cleanAndValidateNumber($message['text']);
            if ($valid_number) $progress['add_holding']['amount'] = $valid_number;
            else askForHoldingAmount($user, $data, $db, 'پیام نامفهوم بود. لطفاً مقدار دارایی را به عدد وارد کنید.');
            addHoldingProgress($user->setProgress($progress), null, $db);
        }

        // Average Price
        if (!isset($progress['add_holding']['avg_price'])) {
            if (!$message) askForHoldingPrice($user, $data, $progress['add_holding']['asset_name'], $db);
            if ($message['text'] == 'لغو') holdings($user, $db);
            if ($message['text'] == 'برگشت') askForHoldingAmount($user, $data, $db);
            $valid_number = cleanAndValidateNumber($message['text']);
            if ($valid_number) $progress['add_holding']['avg_price'] = $valid_number;
            else askForHoldingPrice($user, $data, $progress['add_holding']['asset_name'], $db, 'پیام نامفهوم بود. لطفاً قیمت را به عدد وارد کنید.');
            addHoldingProgress($user->setProgress($progress), null, $db);
        }

        // Add the holding if all the required values are presented
        $holding['user_id'] = $user->getId();
        $holding['asset_id'] = $db->read('assets', ['name' => $progress['add_holding']['asset_name']], true)['id'];
        $holding['amount'] = beautifulNumber($progress['add_holding']['amount'], null, false);
        $holding['avg_price'] = beautifulNumber($progress['add_holding']['avg_price'], null, false);
        $holding['date'] = new DateTime()->format('Y-m-d');
        $holding['time'] = new DateTime()->format('h:i');

        addHolding($user, $holding, $data, $db);

    }
}

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
        $keyboard = $user->getKeyboard();
        foreach ($asset_types as $asset_type) array_unshift($keyboard, [['text' => $asset_type]]);

        $data['text'] = $text ?? 'دسته‌بندی دارایی مورد نظر را از دکمه‌های زیر انتخاب کنید:';
        $data['reply_markup']['keyboard'] = $keyboard;
        $response = sendToTelegram('sendMessage', $data);
        if ($response) {
            $user->setProgress(['add_holding' => ['asset_type' => null]]);
            $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
        }
    } else {
        $data['text'] = 'دسته‌بندی‌ای در سیستم یافت نشد!';
        sendToTelegram('sendMessage', $data);
    }
    exit();
}

function askForHoldingAssetName(User $user, array $data, string $asset_type, DatabaseManager $db, ?string $text = null): void
{
    $assets = $db->read('assets', ['asset_type' => $asset_type]);
    if ($assets) {

        $keyboard[] = [
            ['text' => 'برگشت', 'style' => 'primary'],
            ['text' => 'لغو', 'style' => 'danger']
        ];
        foreach ($assets as $asset) array_unshift($keyboard, [['text' => $asset['name']]]);

        $data['text'] = $text ?? 'دارایی مورد نظر را از دکمه‌های زیر انتخاب کنید:';
        $data['reply_markup']['keyboard'] = $keyboard;
        $response = sendToTelegram('sendMessage', $data);
        if ($response) {
            $progress = $user->getProgress();
            $progress['add_holding']['asset_name'] = null;
            $db->update('users', ['progress' => json_encode($progress, JSON_PRETTY_PRINT)], ['id' => $user->getId()]);
        }
    } else {
        $data['text'] = 'این دسته‌بندی خالی‌ست!';
        sendToTelegram('sendMessage', $data);
    }
    exit();
}

function askForHoldingAmount(User $user, array $data, DatabaseManager $db, ?string $text = null): void
{
    $data['text'] = $text ?? 'مقدار دارایی را به عدد وارد کنید:';
    $data['reply_markup']['keyboard'] = [[
        ['text' => 'برگشت', 'style' => 'primary'],
        ['text' => 'لغو', 'style' => 'danger'],
    ]];
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_holding']['amount'] = null;
        $db->update('users', ['progress' => json_encode($progress, JSON_PRETTY_PRINT)], ['id' => $user->getId()]);
    }
    exit();
}

function askForHoldingPrice(User $user, array $data, string $asset_name, DatabaseManager $db, ?string $html = null): void
{
    $asset = $db->read('assets', ['name' => $asset_name], true);
    if ($asset) {

        $name = beautifulNumber($asset['name'], null);
        $price = beautifulNumber($asset['price'], delimiter: '،');
        $base = beautifulNumber($asset['base_currency'], null);

        $data['rich_message']['html'] = $html ??
            ('میانگین قیمت خرید دارایی را به عدد وارد کنید:' . "<br>" .
                'قیمت کنونی ' . "«{$name}»: <b>$price</b> $base");
        $data['reply_markup']['resize_keyboard'] = true;
        $data['reply_markup']['keyboard'] = [
            [
                ['text' => $price]
            ], [
                ['text' => 'برگشت', 'style' => 'primary'],
                ['text' => 'لغو', 'style' => 'danger'],
            ]];
        $response = sendToTelegram('sendRichMessage', $data);
        if ($response) {
            $progress = $user->getProgress();
            $progress['add_holding']['avg_price'] = null;
            $db->update('users', ['progress' => json_encode($progress, JSON_PRETTY_PRINT)], ['id' => $user->getId()]);
        }
    } else {
        $data['text'] = 'این گزینه در دیتابیس وجود ندارد!';
        sendToTelegram('sendMessage', $data);
    }
    exit();
}

function addHolding(User $user, array $holding, array $data, DatabaseManager $db): void
{
    try {
        $asset = $db->read('assets', ['id' => $holding['asset_id']], true);
        if (!$asset) {
            $data['text'] = '❌ دارایی انتخاب شده پیدا نشد.';
            sendToTelegram('sendMessage', $data);
            holdings($user, $db);
        }

        $db->create('holdings', $holding);
        $data['text'] = '✅ دارایی جدید با موفقیت ثبت شد.';
    } catch (PDOException $e) {
        error_log('Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));
        $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی: ' . $e->errorInfo[2];
    }

    // Send success/failure message
    sendToTelegram('sendMessage', $data);

    // Redirect user to view all holdings
    holdings($user, $db);
}
