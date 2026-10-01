<?php

function sendLoadingMessage(string $chat_id, string $text): array|false
{
    return sendToTelegram('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => ['inline_keyboard' => [[['text' => '...', 'disabled' => ['DisabledButton' => []]]]]]
    ]);
}

function sendInitialLevelMessage(User $user, DatabaseManager $db, ?array $data = null): void
{
    $data = [
        'chat_id' => $user->getid(),
        'text' => $data['text'] ?? $user->getButton()->getText(),
        'reply_markup' => [
            'keyboard' => $data['reply_markup']['keyboard'] ?? $user->getKeyboard(),
            'resize_keyboard' => true,
            'is_persistent' => false,
            'input_field_placeholder' => $user->getButton()->getText()
        ]
    ];

    $response = sendToTelegram('sendMessage', $data);
    if ($response)
        $db->update('users', ['button' => json_encode($user->getButton()), 'progress' => null], ['id' => $user->getId()]);
}
