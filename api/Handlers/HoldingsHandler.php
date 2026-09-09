<?php

function level_1(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null,
    ?string         $command_data = null): void
{
    // Create keyboards
    $level_button = $level_button ?: $user->getButton();
    $keyboard = refineKeyboardForTelegram($level_button->getKeyboard());

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => &$keyboard,
            'resize_keyboard' => true,
            'is_persistent' => false,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    // Add '➕ افزودن دارایی جدید' button to the keyboard
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن دارایی جدید', '/assets/holding.html', add_api: true)]);

    if ($callback_query) handleHoldingsCallback($user, $callback_query, $data, $message, $db);
    if ($message && isset($message['web_app_data'])) handleHoldingsWebAppData($user, $data, $message, $db);
    if ($message && !isset($message['web_app_data'])) handleHoldingsTextMessage($user, $data, $message, $db);

    // Update user's level and progress
    $db->update('users', ['button' => json_encode($level_button), 'progress' => null], ['id' => $user->getId()]);

    if ($command_data) {
        $holding = getHoldingsWithAssetDetails(['h.id' => $command_data, 'h.user_id' => $user->getId()], $db, true);
        if ($holding) sendHoldingDetail($holding, $data, $user->getBaseCurrency());
    }
    if (!$command_data) {
        sendAllHoldings($user, $db, $data);
    }

    exit;
}

function handleHoldingsCallback(User $user, array $callback_query, array $data, array $message, DatabaseManager $db): void
{

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    $data['message_id'] = $message['message_id'];

    switch ($query_key) {

        case 'add_holding':
            addHoldingProgress($user, $data, null, $db);
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
            sendAllHoldings($user, $db, $data, $message['message_id']);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            exit;

        case 'holdings_list':
            $db->update('users', ['progress' => null], ['id' => $user->getId()]);
            sendAllHoldings($user, $db, $data);
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

function handleHoldingsWebAppData(User $user, array $data, array $message, DatabaseManager $db): void
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

    if ($expected_data) {
        // Send success/failure message
        sendToTelegram('sendMessage', $data);

        // Clear user progress and show all holdings
        $db->update('users', ['progress' => null], ['id' => $user->getId()]);
        sendAllHoldings($user, $db, $data);
    } else {
        $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
        $data = checkAndAddEditHoldingButton($data, $user, $db);
        sendToTelegram('sendMessage', $data);
    }
    exit;
}

function handleHoldingsTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{
    if (isAddHoldingProgress($user->getProgress())) addHoldingProgress($user, $data, $message, $db);

    $data['text'] = 'پیام نامفهوم است!';
    $data['reply_markup']['keyboard'] = $user->getKeyboard();
    sendToTelegram('sendMessage', $data);
    exit;
}

/**
 * Returns true when the user's progress indicates they are in the middle of
 * adding a new holding via the chat step-by-step flow.
 *
 * Supports the flat shape used by the existing starter step:
 *   ['add_holding' => ['asset_type' => null, ...]]
 * and a nested shape if you later adopt parent_btn/data style:
 *   ['data' => ['add_holding' => [...]]]
 */
function isAddHoldingProgress(?array $progress): bool
{
    if (!$progress || !is_array($progress)) {
        return false;
    }

    if (array_key_exists('add_holding', $progress)) {
        return true;
    }

    if (isset($progress['data']) && is_array($progress['data']) && array_key_exists('add_holding', $progress['data'])) {
        return true;
    }

    return false;
}

function addHoldingProgress(User $user, array $data, ?array $message, DatabaseManager $db): void
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

    $progress = $user->getProgress();
    if (!$progress || !isset($progress['add_holding'])) {
        askForHoldingAssetType($user, $db);
    } else {

        // Asset Type
        // TODO: use inline buttons for this part
        if (!isset($progress['add_holding']['asset_type'])) {
            if (!$message) askForHoldingAssetType($user, $db);
            if ($message['text'] == 'برگشت به لیست دارایی‌ها') level_1($user, $db);
            $assets = $db->read('assets', ['asset_type' => $message['text']]);
            if ($assets) $progress['add_holding']['asset_type'] = $message['text'];
            else askForHoldingAssetType($user, $db, 'پیام نامفهوم بود. لطفاً دسته‌بندی مد نظر را از دکمه‌های زیر انتخاب کنید.');
            addHoldingProgress($user->setProgress($progress), $data, null, $db);
        }

        // Asset Name
        // TODO: use inline buttons for this part
        if (!isset($progress['add_holding']['asset_name'])) {
            if (!$message) askForHoldingAssetName($user, $progress['add_holding']['asset_type'], $db);
            if ($message['text'] == 'لغو') level_1($user, $db);
            if ($message['text'] == 'برگشت') askForHoldingAssetType($user, $db);
            $asset = $db->read('assets', ['name' => $message['text']], true);
            if ($asset) $progress['add_holding']['asset_name'] = $message['text'];
            else askForHoldingAssetName($user, $progress['add_holding']['asset_type'], $db, 'پیام نامفهوم بود. لطفاً دارایی مد نظر را از دکمه‌های زیر انتخاب کنید.');
            addHoldingProgress($user->setProgress($progress), $data, null, $db);
        }

        // Amount
        if (!isset($progress['add_holding']['amount'])) {
            if (!$message) askForHoldingAmount($user, $db);
            if ($message['text'] == 'لغو') level_1($user, $db);
            if ($message['text'] == 'برگشت') askForHoldingAssetName($user, $progress['add_holding']['asset_type'], $db);
            $is_number = cleanAndValidateNumber($message['text']);
            if ($is_number) $progress['add_holding']['asset_name'] = $message['text'];
            else askForHoldingAmount($user, $db, 'پیام نامفهوم بود. لطفاً مقدار دارایی را به عدد وارد کنید.');
            addHoldingProgress($user->setProgress($progress), $data, null, $db);
        }

        // Average Price
        if (!isset($progress['add_holding']['avg_price'])) {
            if (!$message) askForHoldingPrice($user, $progress['add_holding']['asset_name'], $db);
            if ($message['text'] == 'لغو') level_1($user, $db);
            if ($message['text'] == 'برگشت') askForHoldingAmount($user, $db);
            $is_number = cleanAndValidateNumber($message['text']);
            if ($is_number) $progress['add_holding']['avg_price'] = $message['text'];
            else askForHoldingPrice($user, $progress['add_holding']['asset_name'], $db, 'پیام نامفهوم بود. لطفاً قیمت را به عدد وارد کنید.');
            addHoldingProgress($user->setProgress($progress), $data, null, $db);
        }
    }
}

function askForHoldingAssetType(User            $user,
                                DatabaseManager $db,
                                ?string         $text = null): void
{
    $data['chat_id'] = $user->getId();

    $asset_types = $db->read(
        table: 'assets',
        selectColumns: 'asset_type',
        distinct: true,
        orderBy: ['asset_type' => 'DESC']
    );
    if ($asset_types) {

        $asset_types = array_column($asset_types, 'asset_type');
        $keyboard[] = [['text' => 'برگشت به لیست دارایی‌ها', 'style' => 'primary']];
        foreach ($asset_types as $asset_type) array_unshift($keyboard, [['text' => $asset_type]]);

        $data['text'] = $text ?? 'دسته‌بندی دارایی مورد نظر را از دکمه‌های زیر انتخاب کنید:';
        $data['reply_markup']['keyboard'] = $keyboard;
        $response = sendToTelegram('sendMessage', $data);
        if ($response) {
            $progress = ['add_holding' => ['asset_type' => null]];
            $db->update('users', ['progress' => json_encode($progress)], ['id' => $user->getId()]);
        }
    } else {
        $data['text'] = 'دسته‌بندی‌ای در سیستم یافت نشد!';
        sendToTelegram('sendMessage', $data);
    }
    exit();
}

function askForHoldingAssetName(User            $user,
                                string          $asset_type,
                                DatabaseManager $db,
                                ?string         $text = null): void
{
    $data['chat_id'] = $user->getId();

    $assets = $db->read('assets', ['asset_type' => $asset_type]);
    if ($assets) {

        $keyboard[] = [
            ['text' => 'برگشت', 'style' => 'primary'],
            ['text' => 'لغو', 'style' => 'danger'],
        ];
        foreach ($assets as $asset) array_unshift($keyboard, [['text' => $asset['name']]]);

        $data['text'] = $text ?? 'دارایی مورد نظر را از دکمه‌های زیر انتخاب کنید:';
        $data['reply_markup']['keyboard'] = $keyboard;
        $response = sendToTelegram('sendMessage', $data);
        if ($response) {
            $progress = $user->getProgress();
            $progress['add_holding']['asset_name'] = null;
            $db->update('users', ['progress' => json_encode($progress)], ['id' => $user->getId()]);
        }
    } else {
        $data['text'] = 'این دسته‌بندی خالی‌ست!';
        sendToTelegram('sendMessage', $data);
    }
    exit();
}

function askForHoldingAmount(User            $user,
                             DatabaseManager $db,
                             ?string         $text = null): void
{
    $data['chat_id'] = $user->getId();
    $data['text'] = $text ?? 'مقدار دارایی را به عدد وارد کنید:';
    $data['reply_markup']['resize_keyboard'] = true;
    $data['reply_markup']['keyboard'] = [[
        ['text' => 'برگشت', 'style' => 'primary'],
        ['text' => 'لغو', 'style' => 'danger'],
    ]];
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_holding']['amount'] = null;
        $db->update('users', ['progress' => json_encode($progress)], ['id' => $user->getId()]);
    }
    exit();
}

function askForHoldingPrice(User            $user,
                            string          $asset_name,
                            DatabaseManager $db,
                            ?string         $text = null): void
{
    $data['chat_id'] = $user->getId();

    $asset = $db->read('assets', ['name' => $asset_name], true);
    if ($asset) {

        $name = beautifulNumber($asset['name'], null);
        $price = beautifulNumber($asset['price']);

        $data['text'] = $text ?? 'میانگین قیمت خرید دارایی را به عدد وارد کنید:' . "\n" .
        'قیمت کنونی «' . $name . '»: ' . $price;
        $data['reply_markup']['resize_keyboard'] = true;
        $data['reply_markup']['keyboard'] = [
            [
                ['text' => $price]
            ], [
                ['text' => 'برگشت', 'style' => 'primary'],
                ['text' => 'لغو', 'style' => 'danger'],
            ]];
        $response = sendToTelegram('sendMessage', $data);
        if ($response) {
            $progress = $user->getProgress();
            $progress['add_holding']['avg_price'] = null;
            $db->update('users', ['progress' => json_encode($progress)], ['id' => $user->getId()]);
        }
    } else {
        $data['text'] = 'این گزینه در دیتابیس وجود ندارد!';
        sendToTelegram('sendMessage', $data);
    }
    exit();
}
