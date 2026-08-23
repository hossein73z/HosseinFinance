<?php

// View and mange accounts
function level_9(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void {
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 9], true));

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

    if ($callback_query) handleAccountsCallback($user, $message);
    if ($message) handleAccountsTextMessage($data);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        // Send Informative message
        sendAllAccounts($user, $db);
    }

    exit;
}

function handleAccountsCallback(User $user, array $message): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ];

    sendToTelegram('editMessageText', $data);
    exit;
}

function handleAccountsTextMessage(array $data): void
{
    // Send default message of this level
    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit;
}

function sendAllAccounts(User $user, DatabaseManager $db, int|string|null $message_id = null): void
{
    $message_id = ($message_id !== null) ?
        $message_id :
        sendLoadingMessage($user->getid(), 'در حال دریافت لیست حساب‌ها ...')['result']['message_id'];

    $accounts = $db->read('accounts', ['user_id' => $user->getId()]);

    $text = '';
    $data = [
        'text' => &$text,
        'chat_id' => $user->getid(),
        'message_id' => $message_id,
        //        'reply_markup' => ['inline_keyboard' => [
        //            [['text' => 'مدیریت حساب‌ها', 'callback_data' => json_encode(['mng_accounts' => null])]]
        //        ]]
    ];

    if ($accounts) {
        $text = 'حساب‌های شما:' . "\n";
        foreach ($accounts as $account) {
            $text .= "\n  - " . "‏" . beautifulNumber($account['name'], null) . "‏" . " (" . beautifulNumber($account['type'], null) . "): " . beautifulNumber($account['current_balance']);
        }
    } else $text = 'شما حسابی ثبت نکرده‌اید!';

    sendToTelegram('editMessageText', $data);
}

// Adding new account
function level_10(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void {
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 10], true));

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

    if ($callback_query) handleAddAccountsCallback($user, $message);

    addAccountProgress($user, $data, $message, $db);
}

function handleAddAccountsCallback(User $user, array $message): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ];

    sendToTelegram('editMessageText', $data);
    exit;
}

function addAccountProgress(User $user, array $data, ?array $message, DatabaseManager $db): void
{
    /**
     * Required fields for new account:
     *  - name
     *  - type
     *
     * If any of these values are not presented, asks for it,
     * otherwise adds the account to the database.
     */

    $progress = $user->getProgress();
    if (!$progress || !isset($progress['add_account'])) {
        // Start adding account process
        $progress = ['add_account' => ['type' => null]];
        $db->update('users', ['last_btn' => 10, 'progress' => json_encode($progress)], ['id' => $user->getId()]);
        askForAccountType($user->setProgress($progress), $data, $db);
    } else {
        /*
         * Each `if` works with this principle:
         *  $message == null -> asks for the information.
         *  $message != null -> saves the received information
         */
        if (!isset($progress['add_account']['type'])) {
            if (!$message) askForAccountType($user, $data, $db);
            $progress['add_account']['type'] = $message['text'];
            addAccountProgress($user->setProgress($progress), $data, null, $db);
        }
        if (!isset($progress['add_account']['name'])) {
            if (!$message) askForAccountName($user, $data, $db);
            $progress['add_account']['name'] = $message['text'];
            addAccountProgress($user->setProgress($progress), $data, null, $db);
        }
        if (!isset($progress['add_account']['starting_balance'])) {
            if (!$message) askForAccountStartingBalance($user, $data, $db);
            $amount = cleanAndValidateNumber($message['text']);
            if ($amount === null)
                askForAccountStartingBalance($user, $data, $db, 'پیام نامفهوم بود. لطفاً موجودی را تنها با استفاده از ارقام وارد کنید!');
            $progress['add_account']['starting_balance'] = $amount;
            addAccountProgress($user->setProgress($progress), $data, null, $db);
        }
    }

    // Add the account if all the required values are presented
    addAccount($user, [
        'user_id' => $user->getId(),
        'type' => $progress['add_account']['type'],
        'name' => $progress['add_account']['name'],
        'starting_balance' => $progress['add_account']['starting_balance'],
        'current_balance' => $progress['add_account']['starting_balance']
    ], $data, $db);
}

function askForAccountType(User $user, array $data, DatabaseManager $db): void
{
    $data['text'] = 'نوع حساب را وارد کنید' . "\n" . 'مثال: بانک، نقد، شخص';
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = ['add_account' => ['type' => null]];
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]
        );
    }
    exit;
}

function askForAccountName(User $user, array $data, DatabaseManager $db): void
{
    $data['text'] = 'نام حساب را وارد کنید' . "\n" . 'مثال: سپه، ملی، کیف‌پول، علی‌رضا';
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_account']['name'] = null;
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]
        );
    }
    exit;
}

function askForAccountStartingBalance(User $user, array $data, DatabaseManager $db, ?string $text = null): void
{
    $data['text'] = $text ?? 'موجودی کنونی حساب را وارد کنید';
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_account']['starting_balance'] = null;
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]
        );
    }
    exit;
}

function addAccount(User $user, array $account, array $data, DatabaseManager $db): void
{
    try {
        $db->create('accounts', $account);
        $data['text'] = '✅ حساب جدید با موفقیت ثبت شد.';
    } catch (PDOException $e) {
        error_log('Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));
        $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی: ' . $e->errorInfo[2];
    }

    // Send success/failure message
    sendToTelegram('sendMessage', $data);

    // Redirect user to view all accounts
    level_9($user, $db);
}
