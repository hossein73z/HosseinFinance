<?php

/**
 * Retrieves an existing user or registers a new one.
 */
function getOrCreateUser(array $from, DatabaseManager $db): User
{
    $user = $db->read('users', ['id' => $from['id']], true);

    if (!$user) {
        $admins = $db->read('users', ['is_admin' => 1]);
        $new_user_id = $db->create('users', [
            'id' => $from['id'],
            'first_name' => $from['first_name'] ?? 'N/A',
            'last_name' => $from['last_name'] ?? null,
            'username' => $from['username'] ?? null,
            'settings' => json_encode(['base_currency' => 'ریال']),
            'progress' => null,
            'button' => '{}',
            'is_admin' => !$admins, // First user is admin
        ]);

        if ($new_user_id || $new_user_id == 0) {
            $user = $db->read('users', ['id' => $from['id']], true);
        } else {
            error_log("[ERROR] Failed to create new user: " . $from['id']);
            exit;
        }
    }
    return User::fromDbRow($user);
}

/**
 * Handles normal text messages, commands, and web app data.
 */
function handleIncomingMessage(array $message, DatabaseManager $db): void
{
    $user = getOrCreateUser($message['from'], $db);

    $text = $message['text'] ?? '';

    // Levels' Main Commands
    if ($text === '/start') mainMenu($user, $db);
    if ($text === '/holdings') holdings_menu($user, $db);

    levelHandler($user, $db, $message);
}

function handleCallbackQuery(array $callback_query, DatabaseManager $db): void
{

    $message = &$callback_query['message'];

    $user = $db->read(
        table: 'users',
        conditions: ['id' => $callback_query['from']['id']],
        single: true
    );

    if ($user) {
        $user = User::fromDbRow($user);
        levelHandler($user, $db, $message, $callback_query);

    } else {
        sendToTelegram('deleteMessage', [
            'message_id' => $message['message_id'],
            'chat_id' => $message['chat']['id'],
        ]);
    }
}

function levelHandler(
    ?User           $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?array          $callback_query = null,
    ?string         $button_id = null): void
{

    $button_id = $button_id ?? $user->getButtonId();

    // Route to corresponding level
    if (!$button_id) mainMenu(user: $user, db: $db, message: $message);

    if ($button_id == 'main_menu') mainMenu(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($button_id == 'holdings') holdings_menu(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($button_id == 'add_new_holding') add_holding_menu(user: $user, db: $db, message: $message, callback_query: $callback_query);

    exit('Unhandled message. ' . ($message && isset($message['text']) ? "message_text=\"$message[text]\"" : "button_id=\"$button_id\""));
}
