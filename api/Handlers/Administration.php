<?php

use JetBrains\PhpStorm\NoReturn;

#[NoReturn]
function adminMenu(
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
            id: 'administration',
            attrs: ['text' => '👑 بخش مدیریت'],
            adminKey: true,
            belongTo: 'main_menu',
            keyboard: [
                [
                    ['id' => 'host_info', 'text' => 'مشخصات هاست', 'admin_key' => true],
                    ['id' => 'database_info', 'text' => 'مشخصات دیتابیس', 'admin_key' => true]
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
function sendDBInformation(User $user): void
{
    $data = [
        'chat_id' => $user->getid(),
        'rich_message' => [
            'is_rtl' => false,
            'html' =>
                "<ul>" .
                "<li><b>HOST: </b>" . DB_HOST . "</li>" .
                "<li><b>NAME: </b>" . DB_NAME . "</li>" .
                "<li><b>USER: </b>" . DB_USER . "</li>" .
                "<li><b>PORT: </b>" . DB_PORT . "</li>" .
                "</ul>"
        ]
    ];

    sendToTelegram('sendRichMessage', $data);
    exit;
}

#[NoReturn]
function sendHostInformation(User $user): void
{
    $data = [
        "chat_id" => $user->getid(),
        "rich_message" => [
            "is_rtl" => false,
            "html" =>
                "<ul>" .
                "<li><b>Host Name:</b> " . gethostname() . "</li>" .
                "<li><b>IP:</b> " . $_SERVER['SERVER_ADDR'] . "</li>" .
                "<li><b>PHP Version:</b> " . phpinfo() . "</li>" .
                "<li><b>OS Info:</b><ul>" .
                "    <li><b>OS Name:</b> " . php_uname('s') . "</li>" .
                "    <li><b>Host Name:</b> " . php_uname('n') . "</li>" .
                "    <li><b>Kernel Release:</b> " . php_uname('r') . "</li>" .
                "    <li><b>OS Version:</b> " . php_uname('v') . "</li>" .
                "    <li><b>Architecture:</b> " . php_uname('m') . "</li></li>" .
                "</ul></ul>"
        ]
    ];

    sendToTelegram('sendRichMessage', $data);
    exit;
}
