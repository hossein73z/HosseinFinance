<?php

use JetBrains\PhpStorm\NoReturn;

#[NoReturn]
function mainMenu(
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
            id: 'main_menu',
            attrs: ['text' => '🏠 صفحه اصلی'],
            adminKey: false,
            belongTo: null,
            keyboard: [
                [
                    ['id' => 'holdings', 'text' => '💼 دارایی‌ها', 'admin_key' => 0],
                    ['id' => 'loans', 'text' => '🏦 وام و اقساط', 'admin_key' => 0]
                ], [
                    ['id' => 'prices', 'text' => '💰 قیمت‌ها', 'admin_key' => 0],
                    ['id' => 'alerts', 'text' => '🔔 هشدارها', 'admin_key' => 0],
                ], [
                    ['id' => 'accounts', 'text' => '🧾 حساب‌ها', 'admin_key' => 0],
                    ['id' => 'transactions', 'text' => '🔃 تراکنش‌ها', 'admin_key' => 0]
                ], [
                    ['id' => 'administration', 'text' => '👑 بخش مدیریت', 'style' => 'danger', 'admin_key' => 1],
                    ['id' => 'settings', 'text' => '⚙ تنظیمات', 'style' => 'primary', 'admin_key' => 0]
                ]
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
