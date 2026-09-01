<?php

function sendLoadingMessage(string $chat_id, string $text): array|false
{
    return sendToTelegram('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => ['inline_keyboard' => [[['text' => '...', 'disabled' => ['DisabledButton' => []]]]]]
    ]);
}
