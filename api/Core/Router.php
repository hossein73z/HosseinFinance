<?php

use JetBrains\PhpStorm\NoReturn;

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
#[NoReturn]
function handleIncomingMessage(array $message, DatabaseManager $db): void
{
    $user = getOrCreateUser($message['from'], $db);

    $text = $message['text'] ?? '';

    // Levels' Main Commands
    if ($text === '/start') mainMenu($user, $db);
    if ($text === '/holdings') holdings_menu($user, $db);
    if ($text === '/loans') loans_menu($user, $db);
    if ($text === '/prices') prices_menu($user, $db);
    if ($text === '/alerts') alerts_menu($user, $db);

    levelHandler($user, $db, $message);
}

function handleCallbackQuery(array $callback_query, DatabaseManager $db): void
{

    $message = &$callback_query['message'];
    $callback_query['data'] = json_decode(html_entity_decode($callback_query['data'], ENT_QUOTES, 'UTF-8'), true);

    $user = $db->read(
        table: 'users',
        conditions: ['id' => $callback_query['from']['id']],
        single: true
    );

    if ($user) {
        $user = User::fromDbRow($user);

        // Non-level-related callbacks
        switch (array_key_first($callback_query['data'])) {
            case'view_holding':
            case'edit_holding':
            case'edit_holding_name':
            case'edit_holding_price':
            case'edit_holding_amount':
            case'show_all_holdings':
                holdings_menu($user, $db, $message, $callback_query);

            case 'mng_alerts':
            case 'fav_alert':
            case 'new_alert_type':
            case 'new_alert_asset_id':
            case 'edit_alert_price':
            case 'new_asset_alert':
            case 'edit_asset_alert':
            case 'del_alert':
            case 'del_asset_alert':
            case 'conf_del_alert':
            case 'conf_del_asset_alert':
            case 'show_asset_alerts':
            case 'show_all_alerts':
                alerts_menu($user, $db, $message, $callback_query);

            case 'edit_fav':
            case 'mng_fav_type':
            case 'mng_fav_add':
            case 'mng_fav_del':
            case 'new_fav_name':
            case 'set_live':
            case 'show_favorites':
                prices_menu($user, $db, $message, $callback_query);

            default:
                levelHandler($user, $db, $message, $callback_query);
        }

    } else {
        sendToTelegram('deleteMessage', [
            'message_id' => $message['message_id'],
            'chat_id' => $message['chat']['id'],
        ]);
    }
}

#[NoReturn]
function levelHandler(
    ?User           $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?array          $callback_query = null,
    ?string         $button_id = null): void
{

    $button_id = $button_id ?? $user->getButtonId();

    // Route to corresponding level
    switch ($button_id) {
        case null:
        case 'main_menu':
            mainMenu(user: $user, db: $db, message: $message, callback_query: $callback_query);
        case 'holdings':
            holdings_menu(user: $user, db: $db, message: $message, callback_query: $callback_query);
        case 'loans':
            loans_menu(user: $user, db: $db, message: $message, callback_query: $callback_query);
        case 'add_new_holding':
            add_holding_menu(user: $user, db: $db, message: $message, callback_query: $callback_query);
        case 'prices':
            prices_menu(user: $user, db: $db, message: $message, callback_query: $callback_query);
        case 'favorites':
            sendAllFavorites($user, $db);
        case 'alerts':
            alerts_menu(user: $user, db: $db, message: $message, callback_query: $callback_query);
        case 'administration':
            adminMenu(user: $user, db: $db, message: $message, callback_query: $callback_query);
        case'host_info':
            sendHostInformation($user);
        case'database_info':
            sendDBInformation($user);
        default:
            exit('Unhandled ' . ($message && isset($message['text']) ? "message: text=\"$message[text]\"" : "button: id=\"$button_id\""));
    }
}
