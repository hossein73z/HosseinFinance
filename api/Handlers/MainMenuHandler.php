<?php

function mainMenu(
    User            $user,
    DatabaseManager $db,
    ?array          $message = null,
): void
{
    // Create keyboards
    $user->setButton(new Button(
        id: 'main_menu',
        attrs: ['text' => '🏠 صفحه اصلی'],
        adminKey: false,
        belongTo: null,
        keyboard: [
            [
                ['id' => 'holdings', 'text' => '💼 دارایی‌ها', 'admin_key' => 0],
                ['id' => 'loans', 'text' => '🏦 وام و اقساط', 'admin_key' => 0]
            ], [
                ['id' => 'accounts', 'text' => '🧾 حساب‌ها', 'admin_key' => 0],
                ['id' => 'transactions', 'text' => '🔃 تراکنش‌ها', 'admin_key' => 0]
            ], [
                ['id' => 'tools', 'text' => '🛠 ابزارها', 'admin_key' => 0]
            ], [
                ['id' => 'administration', 'text' => '👑 بخش مدیریت', 'style' => 'danger', 'admin_key' => 1],
                ['id' => 'settings', 'text' => '⚙ تنظیمات', 'style' => 'primary', 'admin_key' => 0]
            ]
        ]
    ));

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

    if ($message)
        if ($pressed_button_id = getPressedButtonID($message['text'], $user))
            levelHandler($user, $db, button_id: $pressed_button_id);
        else
            $data['text'] = 'پیام نامفهوم است. لطفاً یکی از دکمه‌های زیر را انتخاب کنید.';

    $db->update('users', ['button' => json_encode($user->getButton()), 'progress' => null], ['id' => $user->getId()]);

    sendToTelegram('sendMessage', $data);
    exit($user->getButton()->getText());
}
