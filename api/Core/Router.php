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
            'button' => json_encode(getStructuredButton(0, !$admins, $db)),
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

    // Global Command Routing
    $text = $message['text'] ?? '';

    // Levels' Main Commands
    if ($text === '/holdings') /*******/ level_1(user: $user, db: $db, level_button: getStructuredButton(1, $user->isAdmin(), $db));
    if ($text === '/loans') /**********/ level_2(user: $user, db: $db, level_button: getStructuredButton(2, $user->isAdmin(), $db));
    if ($text === '/prices') /*********/ level_5(user: $user, db: $db, level_button: getStructuredButton(5, $user->isAdmin(), $db));
    if ($text === '/alerts') /*********/ level_8(user: $user, db: $db, level_button: getStructuredButton(8, $user->isAdmin(), $db));
    if ($text === '/accounts') /*******/ level_9(user: $user, db: $db, level_button: getStructuredButton(9, $user->isAdmin(), $db));
    if ($text === '/favorites') /******/ sendAllFavorites($user, $db);
    if ($text === '/base_currency') /**/ sendSelectBaseCurrencyMessage($user, $db);

    // Levels' Sub Commands
    $matched = preg_match('/\/(.+?)_(\d+?)$/u', $text, $matches);
    if ($matched && $matches[1] == 'holding') level_1(user: $user, db: $db, command_data: $matches[2]);
    if ($matched && $matches[1] == 'loan') level_2(user: $user, db: $db, command_data: $matches[2]);

    $pressed_button = getPressedButton($text, $user, $db);

    choosePath(pressed_button: $pressed_button, message: $message, user: $user, db: $db);
}

/**
 * Handles inline and rich button callback data.
 */
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

        $query_data = &$callback_query['data'];
        if ($query_data === null) {
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);
            exit;
        }

        $query_data = json_decode(html_entity_decode($callback_query['data'], ENT_QUOTES, 'UTF-8'), true);
        $query_key = $query_data ? array_key_first($query_data) : $query_data;

        switch ($query_key) {

            case 'set_base_currency':
                setBaseCurrency($user, $callback_query, $message, $db);
                break;

            case 'cron_inst_paid':
                payInstallmentFromCronJob($user, $callback_query, $message, $db);
                break;

            case 'inplace_inst_pay_toggle':
                inplaceInstallmentPaymentToggle($user, $callback_query, $message, $db);
                break;

            case 'insts_for_n_days':
                $has_insts = sendInstallmentsForNextNDays($user, $db, mssg_id_to_edit: $message['message_id']);
                if (!$has_insts)
                    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => 'هیچ قسطی در ۳۰ روز آینده ندارید!']);
                break;

            case 'edit_fav':
            case 'mng_fav_del':
            case 'mng_fav_add':
            case 'mng_fav_type':
            case 'del_fav':
            case 'conf_del_fav':
            case 'set_live':
            case 'show_favorites':
                level_5($user, $db, null, $message, $callback_query);
                break;

            case 'fav_alert':
            case 'mng_alerts':
            case 'new_alert_type':
            case 'new_alert_asset_id':
            case 'new_asset_alert':
            case 'edit_asset_alert':
            case 'edit_alert_price':
            case 'del_alert':
            case 'del_asset_alert':
            case 'conf_del_alert':
            case 'conf_del_asset_alert':
            case 'show_asset_alerts':
            case 'show_all_alerts':
                managePriceAlerts($user, $callback_query, $message, $db);
                break;

            case 'add_mssg_transaction':
                addTransactionFromMessage($user, $callback_query, $message, $db);
                break;

            default:
                choosePath(message: $message, user: $user, callback_query: $callback_query, db: $db);
                break;
        }
    } else {
        sendToTelegram('editMessageText', [
            'text' => 'برای استفاده از این رباط ابتدا دستور /start را ارسال کنید.',
            'message_id' => $message['message_id'],
            'chat_id' => $message['chat']['id'],
        ]);
    }
}

/**
 * Routes the flow based on user input or state.
 */
function choosePath(
    ?Button          $pressed_button = null,
    ?array           $message = null,
    ?User            $user = null,
    ?array           $callback_query = null,
    ?DatabaseManager $db = null): void
{
    if ($callback_query)
        callbackHandler($user, $callback_query, $db);
    if ($pressed_button)
        if (str_starts_with($pressed_button->getId(), "s"))
            specialButtonHandler(user: $user, pressed_button: $pressed_button, db: $db);
        else normalButtonHandler(user: $user, pressed_button: $pressed_button, db: $db);
    nonButtonHandler(user: $user, message: $message, db: $db);
}

function callbackHandler(User $user, array $callback_query, DatabaseManager $db): void
{
    $message = $callback_query['message'];

    if ($user->getButtonId() == 1) /***/ level_1(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getButtonId() == 2) /***/ level_2(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getButtonId() == 5) /***/ level_5(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getButtonId() == 8) /***/ level_8(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getButtonId() == 11) /**/ level_11(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getButtonId() == 12) /**/ level_12(user: $user, db: $db, message: $message, callback_query: $callback_query);

    // Fallback if not handled
    sendToTelegram('editMessageText', [
        'text' => 'درخواست نامفهوم بود!',
        'message_id' => $message['message_id'],
        'chat_id' => $user->getid(),
    ]);

    exit;
}

function specialButtonHandler(User $user, Button $pressed_button, DatabaseManager $db): void
{
    if ($pressed_button->getId() === "s0") backButton($user, $db);
    if ($pressed_button->getId() === "s1") cancelButton($user, $db);
    if ($pressed_button->getId() === "s2") sendAllFavorites($user, $db);
    if ($pressed_button->getId() === "s4") sendSelectBaseCurrencyMessage($user, $db);
    if ($pressed_button->getId() === "s5") sendDBInformation($user);
    if ($pressed_button->getId() === "s6") sendHostInformation($user);

    exit;
}

function normalButtonHandler(User $user, Button $pressed_button, DatabaseManager $db): void
{
    // Route the button to corresponding level
    if ($pressed_button->getId() == 1) level_1(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 2) level_2(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 5) level_5(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 8) level_8(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 9) level_9(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 10) level_10(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 11) level_11(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 12) level_12(user: $user, db: $db, level_button: $pressed_button);

    $new_button = getStructuredButton($pressed_button->getId(), $user->isAdmin(), $db);
    $keyboard = refineKeyboardForTelegram($new_button->getKeyboard());

    // Default Actions for normal button
    $response = sendToTelegram('sendMessage', [
        'text' => $pressed_button->getText(),
        'chat_id' => $user->getid(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => false,
            'input_field_placeholder' => $pressed_button->getText(),
        ]
    ]);
    if ($response)
        // Update user with new button and cleared progress
        $db->update('users', ['button' => json_encode($new_button), 'progress' => null], ['id' => $user->getId()]);

    exit;
}

function nonButtonHandler(User $user, array $message, DatabaseManager $db): void
{
    // Priority: progress-based free-text flows
    if (isAlertPriceProgress($user->getProgress())) {
        handleAlertPriceInput($user, $message, $db);
        return;
    }

    if ($user->getButtonId() == '1') /***/ level_1(user: $user, db: $db, message: $message);
    if ($user->getButtonId() == '2') /***/ level_2(user: $user, db: $db, message: $message);
    if ($user->getButtonId() == '5') /***/ level_5(user: $user, db: $db, message: $message);
    if ($user->getButtonId() == '8') /***/ level_8(user: $user, db: $db, message: $message);
    if ($user->getButtonId() == '10') /**/ level_10(user: $user, db: $db, message: $message);
    if ($user->getButtonId() == '11') /**/ level_11(user: $user, db: $db, message: $message);
    if ($user->getButtonId() == '12') /**/ level_12(user: $user, db: $db, message: $message);

    // Fallback "Unrecognized" message
    sendToTelegram('sendMessage', [
        'text' => 'پیام نامفهوم است!',
        'chat_id' => $user->getid(),
        'reply_markup' => [
            'keyboard' => $user->getKeyboard(),
            'resize_keyboard' => true,
            'is_persistent' => false,
        ]
    ]);

    exit;
}
