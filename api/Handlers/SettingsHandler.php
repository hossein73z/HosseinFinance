<?php

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
        foreach ($base_currencies as $base_currency)
            if ($base_currency != $user->getBaseCurrency())
                $keyboard[] = [['text' => $base_currency, 'callback_data' => json_encode(['set_base_currency' => $base_currency])]];

        $data = [
            'reply_markup' => ['inline_keyboard' => $keyboard],
            'text' => 'ارز پایه کنونی شما: ' . $user->getBaseCurrency() . "\n" . 'شما می‌توانید از طریق دکمه‌های شیشه‌ای زیرو ارز پایه‌ی خود را تغییر دهید.',
            'chat_id' => $user->getid()
        ];

        sendToTelegram('sendMessage', $data);
    }
    exit;
}
