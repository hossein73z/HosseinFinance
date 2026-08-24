<?php

// See and manage transactions
function level_11(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 11], true));

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

    if ($callback_query) handleTransactionsCallback($user, $message);
    if ($message) handleTransactionsTextMessage($user, $data, $message, $db);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        // Send Informative message
        sendAllTransactions($user, $db);
    }

    exit;
}

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
    $transactions = $db->read(
        table: 'transactions t',
        conditions: ['t.user_id' => $user->getId()],
        selectColumns: 't.*, a.name as account_name, a.type as account_type',
        join: 'join accounts a on a.id = t.account_id',
        orderBy: ['t.date' => 'ASC', 't.time' => 'ASC'],
        limit: 10,
    );
    if ($transactions) {
        $data['text'] = 'لیست تراکنش‌های شما:';
        $data['chat_id'] = $user->getId();

        foreach ($transactions as $transaction) {
            $data['text'] .= "\n" . ($transaction['type'] == 'outward' ? '📤 برداشت از: ' : '📥 واریز به: ') .
                beautifulNumber($transaction['account_name'], null) . ' (' . beautifulNumber($transaction['account_type'], null) . ')';
            $data['text'] .= "\n" . 'مبلغ: ' . beautifulNumber($transaction['amount']);
            $data['text'] .= "\n" . 'زمان: ' . beautifulNumber(JalaliDate::fromGregorianString($transaction['date'])->format(), null) . ' ' . beautifulNumber($transaction['time'], null);
        }
    } else {
        $data['text'] = 'شما هنوز تراکنشی ثبت نکرده‌اید!';
    }
    sendToTelegram('sendMessage', $data);
    exit;
}

// Create new transaction
function level_12(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 12], true));

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

    if ($callback_query) handleAddTransactionCallback($user, $callback_query, $message);

    addTransactionProgress($user, $data, $message, $db);
}

function handleAddTransactionCallback(User $user, array $callback_query, array $message): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ];

    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    sendToTelegram('editMessageText', $data);
    exit;
}

function addTransactionProgress(User $user, array $data, ?array $message, DatabaseManager $db): void
{
    /**
     * Required fields for new transaction:
     *  - type
     *  - account_id
     *  - amount
     *  - category
     *  - date
     *  - time
     *
     * If any of these values are not presented, asks for it,
     * otherwise adds the transaction to the database.
     */

    $progress = $user->getProgress();
    if (!$progress || !isset($progress['add_transaction'])) {
        // Start adding transaction process
        $progress = ['add_transaction' => ['type' => null]];
        $db->update('users', ['last_btn' => 12, 'progress' => json_encode($progress)], ['id' => $user->getId()]);
        askForTransactionType($user->setProgress($progress), $data, $db);
    } else {

        // HACK: Lazy work

        /*
         * Each `if` works with this principle:
         *  $message == null -> Requests the information.
         *  $message != null -> Saves the received information.
         */

        // Type
        if (!isset($progress['add_transaction']['type'])) {
            if (!$message) askForTransactionType($user, $data, $db);
            $type = '';
            if ($message['text'] == 'واریز') $type = 'inward';
            elseif ($message['text'] == 'برداشت') $type = 'outward';
            else askForTransactionType($user->setProgress($progress), $data, $db, 'پیام نامفهوم بود. لطفاً نوع تراکنش (برداشت/واریز) را از دکمه‌های زیر انتخاب کنید!');
            $progress['add_transaction']['type'] = $type;
            addTransactionProgress($user->setProgress($progress), $data, null, $db);
        }
        // Account Name
        if (!isset($progress['add_transaction']['account_id'])) {
            if (!$message) askForTransactionAccount($user, $progress['add_transaction']['type'], $data, $db);
            $account = $db->read('accounts', ['user_id' => $user->getId(), 'name' => $message['text']], true);
            if ($account) $progress['add_transaction']['account_id'] = $account['id'];
            else askForTransactionAccount($user, $progress['add_transaction']['type'], $data, $db, 'حساب مورد نظر در سیستم یافت نشد!');
            addTransactionProgress($user->setProgress($progress), $data, null, $db);
        }
        // Amount
        if (!isset($progress['add_transaction']['amount'])) {
            if (!$message) askForTransactionAmount($user, $data, $db);
            $amount = cleanAndValidateNumber($message['text']);
            if ($amount === null) askForTransactionAmount($user, $data, $db, 'پیام نامفهوم بود. لطفاً مبلغ را تنها با استفاده از ارقام وارد کنید!');
            $progress['add_transaction']['amount'] = $amount;
            addTransactionProgress($user->setProgress($progress), $data, null, $db);
        }
        if (!isset($progress['add_transaction']['category'])) {
            if (!$message) askForTransactionCategory($user, $data, $db);
            $category = trim($message['text'] ?? '');
            if ($category === '') {
                askForTransactionCategory($user, $data, $db, 'دسته‌بندی تراکنش نمی‌تواند خالی باشد. لطفاً یک دسته‌بندی وارد کنید.');
            }
            $progress['add_transaction']['category'] = $category;
            addTransactionProgress($user->setProgress($progress), $data, null, $db);
        }
        // Date
        if (!isset($progress['add_transaction']['date'])) {
            if (!$message) askForTransactionDate($user, $data, $db);
            if ($message['text'] == 'امروز') {
                $progress['add_transaction']['date'] = (new DateTime())->format('Y-m-d');
            } elseif ($message['text'] == 'دیروز') {
                $progress['add_transaction']['date'] = (new DateTime())->modify('-1 days')->format('Y-m-d');
            } elseif ($message['text'] == '۲ روز پیش') {
                $progress['add_transaction']['date'] = (new DateTime())->modify('-2 days')->format('Y-m-d');
            } else {
                $date_text = toEnglishDigits(trim($message['text']));
                if (!preg_match('/^(\d{4})[\/.\-](\d{1,2})[\/.\-](\d{1,2})$/u', $date_text, $date_matches)) {
                    askForTransactionDate($user, $data, $db, 'فرمت تاریخ صحیح نیست. لطفاً به صورت yyyy/mm/dd یا yyyy-mm-dd وارد کنید.');
                }
                $date_j = JalaliDate::fromString($date_text);
                $normalized_date = JalaliDate::fromGregorianObject($date_j->toGregorian());
                $expected = sprintf('%04d-%02d-%02d', intval($date_matches[1]), intval($date_matches[2]), intval($date_matches[3]));
                if ($normalized_date->format('-') !== $expected) {
                    askForTransactionDate($user, $data, $db, 'تاریخ وارد شده نامعتبر است. دوباره تلاش کنید.');
                }
                $progress['add_transaction']['date'] = $date_j->toGregorian()->format('Y-m-d');
            }
            addTransactionProgress($user->setProgress($progress), $data, null, $db);
        }
        // Time
        if (!isset($progress['add_transaction']['time'])) {
            if (!$message) askForTransactionTime($user, $data, $db);
            if ($message['text'] == 'اکنون') {
                $progress['add_transaction']['time'] = (new DateTime())->format('H:i');
            } else {
                $time_text = toEnglishDigits(trim($message['text']));
                if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/u', $time_text, $time_matches)) {
                    askForTransactionTime($user, $data, $db, 'فرمت زمان صحیح نیست. لطفاً به صورت HH:MM وارد کنید.');
                }
                $progress['add_transaction']['time'] = sprintf('%02d:%02d', intval($time_matches[1]), intval($time_matches[2]));
            }
            addTransactionProgress($user->setProgress($progress), $data, null, $db);
        }
    }

    // Add the transaction if all the required values are presented
    $transaction['user_id'] = $user->getId();
    $transaction['account_id'] = $progress['add_transaction']['account_id'];
    $transaction['amount'] = $progress['add_transaction']['amount'];
    if (isset($progress['add_transaction']['category'])) $transaction['category'] = $progress['add_transaction']['category'];
    $transaction['type'] = $progress['add_transaction']['type'];
    $transaction['date'] = $progress['add_transaction']['date'];
    $transaction['time'] = $progress['add_transaction']['time'];

    addTransaction($user, $transaction, $data, $db);
}

function askForTransactionType(User $user, array $data, DatabaseManager $db, ?string $text = null): void
{
    $data['text'] = $text ?? 'نوع تراکنش را از دکمه‌های زیر انتخاب کنید:';
    array_unshift($data['reply_markup']['keyboard'], [['text' => 'واریز'], ['text' => 'برداشت']]);
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = ['add_transaction' => ['type' => null]];
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]
        );
    }
    exit;
}

function askForTransactionAccount(
    User            $user,
    string          $type,
    array           $data,
    DatabaseManager $db,
    ?string         $text = null): void
{
    $type_text = $type == 'inward' ? 'مقصد' : 'مبدأ';
    $data['text'] = $text ?? 'حساب ' . $type_text . ' را از دکمه‌های زیر انتخاب کنید:';
    $accounts = $db->read('accounts', ['user_id' => $user->getId()]);
    foreach ($accounts as $account)
        array_unshift($data['reply_markup']['keyboard'], [['text' => $account['name']]]);
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_transaction']['account_id'] = null;
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]
        );
    }
    exit;
}

function askForTransactionAmount(
    User            $user,
    array           $data,
    DatabaseManager $db,
    ?string         $text = null): void
{
    $data['text'] = $text ?? 'مبلغ تراکنش را بع عدد ارسال کنید.';
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_transaction']['amount'] = null;
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]
        );
    }
    exit;
}

function askForTransactionCategory(
    User            $user,
    array           $data,
    DatabaseManager $db,
    ?string         $text = null): void
{
    $data['text'] = $text ?? 'دسته‌بندی تراکنش را وارد کنید' . "\n" . 'مثال: خوراک، حمل‌ونقل، حقوق، تفریح';
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_transaction']['category'] = null;
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]
        );
    }
    exit;
}

function askForTransactionDate(
    User            $user,
    array           $data,
    DatabaseManager $db,
    ?string         $text = null): void
{
    $data['text'] = $text ?? 'تاریخ تراکنش را با فرمت مثال زده شده ارسال کنید یا از دکمه‌های زیر استفاده کنید. مثال:' . "\n" . JalaliDate::fromGregorian()->format('-');
    array_unshift(
        $data['reply_markup']['keyboard'],
        [['text' => 'امروز'], ['text' => 'دیروز'], ['text' => '۲ روز پیش']]
    );
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_transaction']['date'] = null;
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]
        );
    }
    exit;
}

function askForTransactionTime(
    User            $user,
    array           $data,
    DatabaseManager $db,
    ?string         $text = null): void
{
    $data['text'] = $text ?? 'زمان تراکنش را با فرمت مثال زده شده ارسال کنید یا از دکمه‌ی زیر برای ساعت کنونی استفاده کنید. مثال:' . "\n" . (new DateTime())->format('H:i');
    array_unshift($data['reply_markup']['keyboard'], [['text' => 'اکنون']]);
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_transaction']['time'] = null;
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]
        );
    }
    exit;
}

function addTransaction(User $user, array $transaction, array $data, DatabaseManager $db): void
{
    try {
        $account = $db->read('accounts', ['id' => $transaction['account_id'], 'user_id' => $user->getId()], true);
        if (!$account) {
            $data['text'] = '❌ حساب انتخاب شده پیدا نشد.';
            sendToTelegram('sendMessage', $data);
            level_11($user, $db);
        }

        $new_balance = $account['current_balance'] + ($transaction['type'] === 'inward' ? $transaction['amount'] : -$transaction['amount']);
        $transaction['new_balance'] = $new_balance;

        $db->create('transactions', $transaction);
        $db->update('accounts', ['current_balance' => $new_balance], ['id' => $account['id']]);
        $data['text'] = '✅ تراکنش جدید با موفقیت ثبت شد.';
    } catch (PDOException $e) {
        error_log('Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));
        $data['text'] = '❌ خطای پایگاه داده در ثبت تراکنش: ' . $e->errorInfo[2];
    }

    // Send success/failure message
    sendToTelegram('sendMessage', $data);

    // Redirect user to view all transactions
    level_11($user, $db);
}
