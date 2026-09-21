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
    if ($text === '/holdings') holdings($user, $db);

    levelHandler($user, $db, $message);
}

function levelHandler(
    ?User           $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?string         $button_id = null,
    ?array          $callback_query = null): void
{

    $button_id = $button_id ?? $user->getButtonId();

    // Route to corresponding level
    if (!$button_id) mainMenu(user: $user, db: $db, message: $message);

    if ($button_id == 'main_menu') mainMenu(user: $user, db: $db, message: $message);
    if ($button_id == 'holdings') holdings(user: $user, db: $db, message: $message);

    exit('Unhandled message.');
}
