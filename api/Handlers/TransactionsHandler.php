<?php

// See and manage transactions
use JetBrains\PhpStorm\NoReturn;

#[NoReturn]
function transaction_menu(
    User            $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?array          $callback_query = null): void
{
    if ($callback_query) {
        handleTransactionsCallback($user, $message);
    } elseif (!$message) {
        $user->setProgress(null);
        $user->setButton(new Button(
            id: 'transactions',
            attrs: ['text' => '🔃 تراکنش‌ها'],
            adminKey: false,
            belongTo: 'main_menu',
            keyboard: [
                [['id' => 'add_transaction', 'text' => 'ثبت تراکنش جدید دارایی', 'style' => 'success', 'admin_key' => 0],],
                [['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],],
            ]
        ));
    } else {
        if ($pressed_button_id = getPressedButtonID($message['text'], $user))
            switch ($pressed_button_id) {
                case 'add_transaction':
                    addTransactionProgress($user, null, $db);
                    break;

                case 'back':
                    $progress = $user->getProgress();
                    unset($progress['add_transaction'][array_key_first($progress['add_transaction'])]); // TODO: Clean this
                    $user->setProgress($progress);
                    addTransactionProgress($user, null, $db);
                    break;

                default:
                    levelHandler($user, $db, button_id: $pressed_button_id);
            }
        else {
            addTransactionProgress($user, $message, $db);
            $data['text'] = 'پیام نامفهوم است. لطفاً یکی از دکمه‌های زیر را انتخاب کنید.';
        }
    }

    sendInitialLevelMessage($user, $db, $data ?? null);
    sendAllTransactions($user, $db);
    exit;
}

#[NoReturn]
function handleTransactionsCallback(User $user, array $message): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ];
    sendToTelegram('editMessageText', $data);
    exit;
}

#[NoReturn]
function handleTransactionsTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{

    $transaction = extractTransactionFromText($message['text']);
    if ($transaction) {

        $accounts = $db->read('accounts', ['user_id' => $user->getId()]);
        if ($accounts) {
            $data['text'] = "در متن ارسالی یک تراکنش پیدا شد. در صورت تمایل می‌توانید با انتخاب حساب مبدا/مقصد از دکمه‌های زیر، این تراکنش را برای حساب منتخب ذخیره کنید.\n";

            $data['text'] .= "\n" . 'بانک: ' . beautifulNumber($transaction['bank'], null);
            $data['text'] .= "\n" . 'مبلغ: ' . beautifulNumber($transaction['amount']);
            $data['text'] .= "\n" . 'نوع: ' . beautifulNumber($transaction['type'] == 'inward' ? 'واریز' : 'برداشت', null);
            $data['text'] .= "\n" . 'موجودی فعلی: ' . beautifulNumber($transaction['balance']);
            $data['text'] .= "\n" . 'تاریخ: ' . beautifulNumber($transaction['date']->format(), null);
            $data['text'] .= "\n" . 'ساعت: ' . beautifulNumber($transaction['time'], null);

            $inline_keyboard = [];
            foreach ($accounts as $account) {
                $button_text = '(' . beautifulNumber($account['type'], null) . ') ' . beautifulNumber($account['name'], null);
                $inline_keyboard[] = [
                    ['text' => $button_text, 'callback_data' => json_encode(['add_mssg_transaction' => $account['id']])]
                ];
            }

            $data['reply_markup'] = ['inline_keyboard' => $inline_keyboard];
        } else $data['text'] = 'پیام نامفهوم است!';
    } else $data['text'] = 'پیام نامفهوم است!';

    // Send default message of this level
    sendToTelegram('sendMessage', $data);
    exit;
}

#[NoReturn]
function addTransactionFromMessage(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    if ($message) {
        //        preg_match('/^بانک: (.+?)$/um', $message['text'], $bank);
        preg_match('/^مبلغ: (.+?)$/um', $message['text'], $amount);
        preg_match('/^نوع: (.+?)$/um', $message['text'], $type);
        preg_match('/^موجودی فعلی: (.+?)$/um', $message['text'], $balance);
        preg_match('/^تاریخ: (.+?)$/um', $message['text'], $date);
        preg_match('/^ساعت: (.+?)$/um', $message['text'], $time);

        $transaction = [
            //            'bank' => $bank[1],
            'amount' => cleanAndValidateNumber(str_replace(',', '', $amount[1])),
            'type' => $type[1] == 'واریز' ? 'inward' : 'outward',
            'balance' => cleanAndValidateNumber(str_replace(',', '', $balance[1])),
            'date' => JalaliDate::fromString(toEnglishDigits($date[1]))->toGregorian()->format('Y-m-d'),
            'time' => toEnglishDigits($time[1]),
        ];

        $result = $db->create('transactions', [
            'user_id' => $user->getId(),
            'account_id' => $callback_query['data']['add_mssg_transaction'],
            'type' => $transaction['type'],
            'date' => $transaction['date'],
            'time' => $transaction['time'],
            'amount' => $transaction['amount'],
        ]);

        if ($result) {
            $db->update('accounts', ['current_balance' => $transaction['balance']], ['id' => $callback_query['data']['add_mssg_transaction']]);
            $text = '✅ تراکنش جدید با موفقیت ثبت شد!';
        } else
            $text = '❌ خطای پایگاه داده در ثبت تراکنش جدید!';

        sendToTelegram('editMessageText', ['chat_id' => $user->getId(), 'message_id' => $message['message_id'], 'text' => $text]);
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    }
    exit;
}

function sendAllTransactions(User $user, DatabaseManager $db): void
{
    $transactions = $db->read('transactions', ['user_id' => $user->getId()], orderBy: ['date' => 'DESC', 'time' => 'DESC']);
    if ($transactions) {
        $html = "<h3>" . "لیست تراکنش‌های شما" . "<br></h3>";

        $html .= "<ul>";
        foreach ($transactions as $tx) {
            $asset_name = beautifulNumber($tx['asset_name'], null);
            $amount = beautifulNumber($tx['amount']);
            $price = beautifulNumber($tx['price']);
            if ($tx['type'] == 'transfer') $type_emoji = '🔄';
            elseif ($tx['type'] == 'inward') $type_emoji = '🔻';
            elseif ($tx['type'] == 'outward') $type_emoji = '🔺';
            else $type_emoji = '⚠';

            $html .= "<li>$type_emoji $amount × ($asset_name) --> $price</li>";
        }
        $html .= "</ul>";
    } else {
        $html = 'شما هنوز تراکنشی ثبت نکرده‌اید!';
    }

    $data = [
        'chat_id' => $user->getId(),
        'rich_message' => [
            'is_rtl' => true,
            'html' => $html,
        ]
    ];
    sendToTelegram('sendRichMessage', $data);
}

function addTransactionProgress(User $user, ?array $message, DatabaseManager $db): null
{
    /**
     * Required fields for new transaction:
     *  - type (outward / inward / transfer)
     *  - asset_type
     *  - asset_name
     *  - amount
     *  - price
     *  - TODO: category
     *  - TODO: note
     *  - TODO: date
     *  - TODO: time
     *
     * If any of these values are not presented, asks for
     * it, otherwise adds the transaction to the database.
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
    if (!$progress || !in_array(array_key_first($progress), ['add_transaction', 'edit_transaction']))
        $progress = $user->setProgress(['add_transaction' => null])->getProgress();

    $progress_key = array_key_first($progress);

    // Type (outward / inward / transfer)
    if (!isset($progress[$progress_key]['type'])) {
        if (!$message) askForTransactionType($user, $data, $db);
        $allowed = ['outward' => 'فروش', 'inward' => 'خرید'/*, 'transfer' => 'تبدیل'*/];
        $selected = array_search($message['text'], $allowed, true);
        if ($selected !== false) $progress[$progress_key]['type'] = $selected;
        else askForTransactionType($user, $data, $db,
            'پیام نامفهوم بود. لطفاً نوع تراکنش را از دکمه‌های زیر انتخاب کنید.');
        addTransactionProgress($user->setProgress($progress), null, $db);
    }

    // Asset Type
    if (!isset($progress[$progress_key]['asset_type'])) {
        if (!$message) askForTransactionAssetType($user, $data, $db);
        $asset = $db->read('assets', ['asset_type' => $message['text']], true);
        if ($asset) $progress[$progress_key]['asset_type'] = $asset['asset_type'];
        else askForTransactionAssetType($user, $data, $db,
            'پیام نامفهوم بود. لطفاً دسته‌بندی مد نظر را از دکمه‌های زیر انتخاب کنید.');
        addTransactionProgress($user->setProgress($progress), null, $db);
    }

    // Asset Name
    if (!isset($progress[$progress_key]['asset_name'])) {
        if (!$message) askForTransactionAssetName($user, $data, $progress[$progress_key]['asset_type'], $db);
        $asset = $db->read('assets', ['name' => $message['text']], true);
        if ($asset) $progress[$progress_key]['asset_name'] = $asset['name'];
        else askForTransactionAssetName($user, $data, $progress[$progress_key]['asset_type'], $db,
            'پیام نامفهوم بود. لطفاً دارایی مد نظر را از دکمه‌های زیر انتخاب کنید.');
        addTransactionProgress($user->setProgress($progress), null, $db);
    }

    // Amount
    if (!isset($progress[$progress_key]['amount'])) {
        if (!$message) askForTransactionAmount($user, $data, $db);
        $valid_number = cleanAndValidateNumber($message['text']);
        if ($valid_number) $progress[$progress_key]['amount'] = $valid_number;
        else askForTransactionAmount($user, $data, $db,
            'پیام نامفهوم بود. لطفاً مقدار را به عدد وارد کنید.');
        addTransactionProgress($user->setProgress($progress), null, $db);
    }

    // Price
    if (!isset($progress[$progress_key]['price'])) {
        if (!$message) askForTransactionPrice($user, $data, $progress[$progress_key]['asset_name'], $db);
        $valid_number = cleanAndValidateNumber($message['text']);
        if ($valid_number) $progress[$progress_key]['price'] = $valid_number;
        else askForTransactionPrice($user, $data, $progress[$progress_key]['asset_name'], $db,
            'پیام نامفهوم بود. لطفاً قیمت را به عدد وارد کنید.');
        addTransactionProgress($user->setProgress($progress), null, $db);
    }

    // Add the transaction if all the required values are presented
    $new_tx = [
        "id" => $progress[$progress_key]["transaction_id"] ?? null,
        "user_id" => $user->getId(),
        "asset_name" => $progress[$progress_key]["asset_name"],
        "amount" => beautifulNumber($progress[$progress_key]["amount"], null, false),
        "price" => beautifulNumber($progress[$progress_key]["price"], null, false),
        "type" => $progress[$progress_key]["type"],
        "category" => $progress[$progress_key]["category"] ?? 'دسته‌بندی نشده',
        "date" => $progress[$progress_key]["date"] ?? new DateTime()->format('Y-m-d'),
        "time" => $progress[$progress_key]["time"] ?? new DateTime()->format('H:i:s'),
        "note" => $progress[$progress_key]["note"] ?? null,
    ];

    try {
        if (isset($new_tx["id"])) {
            $db->update('transactions', $new_tx, ['id' => $new_tx['id']]);
            $data['text'] = '✅ تراکنش با موفقیت به‌روزرسانی شد.';
        } else {
            $db->create('transactions', $new_tx);
            $data['text'] = '✅ تراکنش جدید با موفقیت ثبت شد.';
        }
    } catch (PDOException $e) {
        error_log('Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));
        $data['text'] = '❌ خطای پایگاه داده: ' . $e->errorInfo[2];
    }

    $user->setKeyboard([
        [['id' => 'add_transaction', 'text' => 'ثبت تراکنش جدید', 'style' => 'success', 'admin_key' => 0]],
        [['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0]],
    ])->setProgress(null);

    $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
    $data['reply_markup']['keyboard'] = $user->getKeyboard();
    sendToTelegram('sendMessage', $data);

    sendAllTransactions($user, $db);

    updateUserHoldings($new_tx, $db);

    exit('Add transaction: ' . json_encode($new_tx, JSON_UNESCAPED_UNICODE));
}

#[NoReturn]
function askForTransactionType(User $user, array $data, DatabaseManager $db, ?string $text = null): void
{
    $keyboard = [
        [['text' => 'فروش']/*, ['text' => 'تبدیل']*/, ['text' => 'خرید']],
        [['id' => 'transactions', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]]
    ];

    $data['text'] = $text ?? 'نوع تراکنش را انتخاب کنید:';
    $data['reply_markup']['keyboard'] = $keyboard;
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $user->setKeyboard($keyboard);
        $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
    }
    exit();
}

#[NoReturn]
function askForTransactionAssetType(User $user, array $data, DatabaseManager $db, ?string $text = null): void
{
    $asset_types = $db->read(
        table: 'assets',
        selectColumns: 'asset_type',
        distinct: true,
        orderBy: ['asset_type' => 'DESC']
    );

    if ($asset_types) {
        $asset_types = array_column($asset_types, 'asset_type');
        $keyboard = [];
        foreach ($asset_types as $asset_type) $keyboard[] = [['text' => $asset_type]];
        $keyboard[] = [
            array_key_first($user->getProgress()) != 'edit_transaction' ?
                ['id' => 'back', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0] : [],
            ['id' => 'transactions', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]
        ];
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
function askForTransactionAssetName(User $user, array $data, string $asset_type, DatabaseManager $db, ?string $text = null): void
{
    $assets = $db->read('assets', ['asset_type' => $asset_type]);
    if ($assets) {

        $keyboard = [];
        foreach ($assets as $asset) $keyboard[] = [['text' => $asset['name']]];
        $keyboard[] = [
            array_key_first($user->getProgress()) != 'edit_transaction' ?
                ['id' => 'back', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0] : [],
            ['id' => 'transactions', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0],
        ];

        $data['text'] = $text ?? 'گزینه‌ی مورد نظر را از دکمه‌های زیر انتخاب کنید:';
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
function askForTransactionAmount(User $user, array $data, DatabaseManager $db, ?string $text = null): void
{
    $keyboard = [[
        array_key_first($user->getProgress()) != 'edit_transaction' ?
            ['id' => 'back', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0] : [],
        ['id' => 'transactions', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0],
    ]];

    $data['text'] = $text ?? 'مقدار تراکنش را به عدد وارد کنید:';
    $data['reply_markup']['keyboard'] = $keyboard;
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $user->setKeyboard($keyboard);
        $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
    }
    exit();
}

#[NoReturn]
function askForTransactionPrice(User $user, array $data, string $asset_name, DatabaseManager $db, ?string $html = null): void
{
    $asset = $db->read('assets', ['name' => $asset_name], true);
    if ($asset) {
        $name = beautifulNumber($asset['name'], null);
        $price = beautifulNumber($asset['price'], delimiter: '،');
        $base = beautifulNumber($asset['base_currency'], null);

        $data['rich_message']['html'] = $html ??
            ("قیمت تراکنش را به عدد وارد کنید:<br>قیمت کنونی «" . "{$name}»: <b>$price</b> $base");

        $keyboard = [[
            array_key_first($user->getProgress()) != 'edit_transaction' ?
                ['id' => 'back', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0] : [],
            ['id' => 'transactions', 'text' => '❌ لغو ❌', 'style' => 'danger', 'admin_key' => 0]
        ]];
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

function updateUserHoldings(array $transaction, DatabaseManager $db): void
{
    $holding_txs = $db->read(
        table: 'transactions',
        conditions: ['user_id' => $transaction['user_id'], 'asset_name' => $transaction['asset_name']],
        orderBy: ['date' => 'ASC', 'time' => 'ASC'],
    ) ?? [];

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

    $total_amount = $buy_amount - $sel_amount;
    $avg_buy_price = $total_cost / $buy_amount;

    try {
        if ($total_amount > 0) {
            $db->upsert(
                table: 'holdings',
                data: [
                    'user_id' => $transaction['user_id'],
                    'asset_name' => $transaction['asset_name'],
                    'amount' => $total_amount,
                    'avg_price' => $avg_buy_price,
                ]);
        } else
            $db->delete('holdings', ['user_id' => $transaction['user_id'], 'asse_name' => $transaction['asset_name'],]);
    } catch (PDOException $e) {
        error_log('Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));
    }
}

