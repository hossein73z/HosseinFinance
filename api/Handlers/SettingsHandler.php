<?php


use JetBrains\PhpStorm\NoReturn;

#[NoReturn]
function settingsMenu(
    User            $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?array          $callback_query = null): void
{

    if ($callback_query) {
        sendToTelegram('editMessageText', [
            'chat_id' => $user->getid(),
            'message_id' => $message['message_id'],
            'text' => 'این پیام منقضی شده است.'
        ]);
        exit('Expired callback query.');
    } elseif (!$message) { // Just entered the Level
        $user->setProgress(null);
        $user->setButton(new Button(
            id: 'settings',
            attrs: ['text' => '⚙ تنظیمات'],
            adminKey: false,
            belongTo: 'main_menu',
            keyboard: [
                [
                    ['id' => 'select_base_currency', 'text' => '💲 ارز پایه', 'admin_key' => false],
                ], [
                    ['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => false]
                ],
            ]
        ));

    } else { // Received a message in the level

        // Received message is a button
        if ($pressed_button_id = getPressedButtonID($message['text'], $user))
            levelHandler($user, $db, button_id: $pressed_button_id);

        // Received message is not recognizable
        else $data['text'] = 'پیام نامفهوم است. لطفاً یکی از دکمه‌های زیر را انتخاب کنید.';
    }

    sendInitialLevelMessage($user, $db, $data ?? null);
    exit($user->getButton()->getText());
}


#[NoReturn]
function setBaseCurrency(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ];

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    if ($query_key == 'set_base_currency') {

        $user->setBaseCurrency($query_data['set_base_currency']);
        try {
            $db->update(
                table: 'users',
                data: ['settings' => json_encode($user->getSettings())],
                conditions: ['id' => $user->getId()],
            );
            $data['text'] = '✅ ارز پایه با موفقیت به «' . $query_data['set_base_currency'] . '» تغییر کرد';
        } catch (Exception $e) {
            error_log('Error changing base currency: ' . $e->getMessage());
            $data['text'] = '❌ خطای پایگاه داده!';
        }

        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        sendToTelegram('editMessageText', $data);
        exit;
    }

    sendToTelegram('editMessageText', $data);
    exit;
}

#[NoReturn]
function sendSelectBaseCurrencyMessage(User $user, DatabaseManager $db): void
{
    $base_currencies = $db->read(
        table: 'assets',
        conditions: ['asset_type' => 'ارزهای آزاد'],
        selectColumns: 'name',
    );

    if ($base_currencies) {

        $base_currencies = array_column($base_currencies, 'name');

        $keyboard = [];
        $button_array = [];
        foreach ($base_currencies as $base_currency) {
            if ($base_currency != $user->getBaseCurrency())
                $button_array[] = ['text' => $base_currency, 'callback_data' => json_encode(['set_base_currency' => $base_currency], JSON_UNESCAPED_UNICODE)];
            if (sizeof($button_array) >= 4) {
                $keyboard[] = $button_array;
                $button_array = [];
            }
        }
        $disabled_button = ['text' => '─────', 'disabled' => true];
        if (sizeof($button_array) != 0) $button_array = array_pad($button_array, 4, $disabled_button);

        $keyboard[] = $button_array;

        $data = [
            'text' => 'ارز پایه کنونی شما: ' . $user->getBaseCurrency() . "\n" . 'شما می‌توانید از طریق دکمه‌های شیشه‌ای زیر، ارز پایه‌ی خود را تغییر دهید.',
            'chat_id' => $user->getid(),
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];

        sendToTelegram('sendMessage', $data);
    }
    exit;
}
