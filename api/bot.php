<?php
// Load core configuration and constants.
use JetBrains\PhpStorm\NoReturn;

date_default_timezone_set('Asia/Tehran');

// --- VERCEL CONFIGURATION ---
define('SHARED_SECRET', getenv('SHARED_SECRET'));
define('BOT_ID', getenv('BOT_ID'));
define('BOT_TOKEN', getenv('BOT_TOKEN'));

// Database Constants (Required for DatabaseManager)
define('DB_HOST', getenv('DB_HOST'));
define('DB_PORT', getenv('DB_PORT'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('DB_API_SECRET', getenv('DB_API_SECRET'));

// Base URL for Web Apps
define('BASE_URL', getenv('BASE_URL'));
// ----------------------------

// Load necessary files
require_once 'Libraries/DatabaseManager.php';
require_once 'Functions/ExternalEndpointsFunctions.php';
require_once 'Functions/KeyboardFunctions.php';
require_once 'Functions/MessageFunctions.php';
require_once 'Functions/StringHelper.php';
require_once 'Models/Button.php';
require_once 'Models/User.php';
require_once 'Models/JalaliDate.php';

// --- INITIALIZATION & SHUTDOWN ---

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR])) {
        error_log("CRITICAL SCRIPT CRASH: Type: {$error['type']} | Message: {$error['message']} | File: {$error['file']} | Line: {$error['line']}");
    }
});

// Setup Webhook Response
header('Content-Type: application/json');
$input = file_get_contents('php://input');

validateWebhookSecurity($input);
http_response_code(200);

if (empty($input)) {
    error_log("[WARN] No input data received via Webhook.");
    exit;
}

$update = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("[ERROR] Invalid JSON received: " . json_last_error_msg());
    exit;
}

// Initialize Database connection.
try {
    $db = DatabaseManager::getInstance(
        host: DB_HOST,
        db: DB_NAME,
        user: DB_USER,
        pass: DB_PASS,
        port: DB_PORT ?: '3306'
    );
    $db->query("SET SESSION group_concat_max_len = 10000000;");
} catch (Exception $e) {
    error_log($e->getMessage());
    exit;
}

// --- MAIN UPDATE ROUTER ---

if (isset($update['message'])) handleIncomingMessage($update['message'], $db);
elseif (isset($update['callback_query'])) handleCallbackQuery($update['callback_query'], $db);
else error_log("[INFO] Unhandled update type received.");

DatabaseManager::closeConnection();
exit;


// ==========================================
//          CORE ROUTING FUNCTIONS
// ==========================================

/**
 * Validates the request against the shared secret.
 */
function validateWebhookSecurity(string $input): void
{
    $header_secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
    $query_secret = $_GET['secret'] ?? null;

    if (($header_secret !== SHARED_SECRET) && ($query_secret !== SHARED_SECRET)) {
        error_log("SECURITY ALERT: Access denied. Invalid secret. Input size: " . strlen($input));
        http_response_code(403);
        die(json_encode(['status' => 'unauthorized', 'message' => 'Invalid Secret Token']));
    }
}

/**
 * Handles normal text messages, commands, and web app data.
 */
#[NoReturn]
function handleIncomingMessage(array $message, DatabaseManager $db): void
{
    $user = getOrCreateUser($message['from'], $db);

    // Global Command Routing
    $text = $message['text'] ?? '';

    // Levels' Main Commands
    if ($text === '/start') /**********/ level_0(user: $user, db: $db);
    if ($text === '/holdings') /*******/ level_1(user: $user, db: $db);
    if ($text === '/loans') /**********/ level_2(user: $user, db: $db);
    if ($text === '/prices') /*********/ level_5(user: $user, db: $db);
    if ($text === '/ai') /*************/ level_6(user: $user, db: $db);
    if ($text === '/accounts') /*******/ level_9(user: $user, db: $db);
    if ($text === '/favorites') /******/ sendAllFavorites($user, $db);
    if ($text === '/base_currency') /**/ sendSelectBaseCurrencyMessage($user, $db);

    // Levels' Sub Commands
    $matched = preg_match('/\/(.+?)_(\d+?)$/u', $text, $matches);
    if ($matched && $matches[1] == 'holding') level_1(user: $user, db: $db, command_data: $matches[2]);
    if ($matched && $matches[1] == 'loan') level_2(user: $user, db: $db, command_data: $matches[2]);

    $pressed_button = getPressedButton(text: $text, parent_btn_id: $user->getLastBtn(), admin: $user->isAdmin(), db: $db);

    choosePath(pressed_button: $pressed_button, message: $message, user: $user, db: $db);
}

/**
 * Handles inline button presses.
 */
function handleCallbackQuery(array $callback_query, DatabaseManager $db): void
{
    $message = &$callback_query['message'];

    $user = $db->read(
        table: 'users',
        conditions: ['id' => $callback_query['from']['id']],
        single: true);

    if ($user) {
        $user = User::fromDbRow($user);

        $query_data = &$callback_query['data'];
        if ($query_data === null) {
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);
            exit;
        }

        $query_data = json_decode($callback_query['data'], true);
        $query_key = $query_data ? array_key_first($query_data) : $query_data;

        switch ($query_key) {

            case 'set_base_currency':
                setBaseCurrency($user, $callback_query, $message, $db);

            case 'cron_inst_paid':
                payInstallmentFromCronJob($user, $callback_query, $message, $db);

            case'inplace_inst_pay_toggle':
                inplaceInstallmentPaymentToggle($user, $callback_query, $message, $db);

            case 'edit_fav':
            case 'new_fav_type':
            case 'new_fav_name':
            case 'del_fav':
            case 'conf_del_fav':
            case 'set_live':
            case 'show_favorites':
                level_5($user, $db, null, $message, $callback_query);

            case 'fav_alert':
            case 'mng_alerts':
            case 'new_alert_type':
            case 'new_alert_name':
            case 'del_alert':
            case 'conf_del_alert':
            case 'show_alerts':
                managePriceAlerts($user, $callback_query, $message, $db);

            case 'add_mssg_transaction':
                addTransaction($user, $callback_query, $message, $db);

            default:
                choosePath(message: $message, user: $user, callback_query: $callback_query, db: $db);
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
 * Retrieves an existing user or registers a new one.
 */
function getOrCreateUser(array $from, DatabaseManager $db): User
{
    $user = $db->read(
        table: 'users',
        conditions: ['id' => $from['id']],
        single: true);

    if (!$user) {
        $admins = $db->read(
            table: 'users',
            conditions: ['is_admin' => 1]);
        $new_user_id = $db->create(
            table: 'users',
            data: [
                'id' => $from['id'],
                'first_name' => $from['first_name'] ?? 'N/A',
                'last_name' => $from['last_name'] ?? null,
                'username' => $from['username'] ?? null,
                'settings' => json_encode(['base_currency' => 'ریال']),
                'progress' => null,
                'is_admin' => ($admins) ? 0 : 1, // First user is admin
                'last_btn' => 0
            ]);

        if ($new_user_id) {
            $user = $db->read(
                table: 'users',
                conditions: ['id' => $from['id']],
                single: true
            );
        } else {
            error_log("[ERROR] Failed to create new user: " . $from['id']);
            exit;
        }
    }
    return User::fromDbRow($user);
}

#[NoReturn]
function callbackHandler(User $user, array $callback_query, DatabaseManager $db): void
{
    $message = $callback_query['message'];

    if ($user->getLastBtn() == 0) /***/ level_0(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getLastBtn() == 1) /***/ level_1(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getLastBtn() == 2) /***/ level_2(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getLastBtn() == 5) /***/ level_5(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getLastBtn() == 6) /***/ level_6(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getLastBtn() == 8) /***/ level_8(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getLastBtn() == 11) /**/ level_11(user: $user, db: $db, message: $message, callback_query: $callback_query);

    // Fallback if not handled
    sendToTelegram('editMessageText', [
        'text' => 'درخواست نامفهوم بود!',
        'message_id' => $message['message_id'],
        'chat_id' => $user->getid(),
    ]);

    exit;
}

#[NoReturn]
function specialButtonHandler(User $user, Button $pressed_button, DatabaseManager $db): void
{
    if ($pressed_button->getId() === "s0") backButton($user, $db);
    if ($pressed_button->getId() === "s1") cancelButton($user, $db);
    if ($pressed_button->getId() === "s2") sendAllFavorites($user, $db);
    if ($pressed_button->getId() === "s4") sendSelectBaseCurrencyMessage($user, $db);

    exit;
}

#[NoReturn]
function normalButtonHandler(User $user, Button $pressed_button, DatabaseManager $db): void
{
    // Route the button to corresponding level
    if ($pressed_button->getId() == 0) level_0(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 1) level_1(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 2) level_2(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 5) level_5(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 6) level_6(user: $user, db: $db);
    if ($pressed_button->getId() == 8) level_8(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 9) level_9(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 10) level_10(user: $user, db: $db, level_button: $pressed_button);
    if ($pressed_button->getId() == 11) level_11(user: $user, db: $db, level_button: $pressed_button);

    // Default Actions for normal button
    $response = sendToTelegram('sendMessage', [
        'text' => $pressed_button->getText(),
        'chat_id' => $user->getid(),
        'reply_markup' => [
            'keyboard' => createKeyboardsArray($pressed_button->getId(), $user->isAdmin(), $db),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $pressed_button->getText(),
        ]
    ]);

    if ($response) $db->update(
        table: 'users',
        data: ['last_btn' => $pressed_button->getId()],
        conditions: ['id' => $user->getId()]
    );

    exit;
}

#[NoReturn]
function nonButtonHandler(User $user, array $message, DatabaseManager $db): void
{
    if ($user->getLastBtn() == '0') /***/ level_0(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == '1') /***/ level_1(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == '2') /***/ level_2(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == '5') /***/ level_5(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == '6') /***/ level_6(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == '8') /***/ level_8(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == '10') /**/ level_10(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == '11') /**/ level_11(user: $user, db: $db, message: $message);

    if ($user->getLastBtn() == 's3') /**/ empty_level(user: $user, db: $db, message: $message);

    // Fallback "Unrecognized" message
    sendToTelegram('sendMessage', [
        'text' => 'پیام نامفهوم است!',
        'chat_id' => $user->getid(),
        'reply_markup' => [
            'keyboard' => createKeyboardsArray($user->getLastBtn(), $user->isAdmin(), $db),
            'resize_keyboard' => true,
            'is_persistent' => true,
        ]
    ]);

    exit;
}

/**
 * Routes the flow based on user input or state.
 */
#[NoReturn]
function choosePath(
    ?Button         $pressed_button = null,
    ?array          $message = null,
    ?User           $user = null,
    ?array          $callback_query = null,
    DatabaseManager $db = null): void
{
    if ($callback_query)
        callbackHandler($user, $callback_query, $db);
    if ($pressed_button)
        if (str_starts_with($pressed_button->getId(), "s"))
            specialButtonHandler(user: $user, pressed_button: $pressed_button, db: $db);
        else normalButtonHandler(user: $user, pressed_button: $pressed_button, db: $db);
    nonButtonHandler(user: $user, message: $message, db: $db);
}

#[NoReturn]
function backButton(User $user, DatabaseManager $db, int|string|null $parent_btn_id = null): void
{
    $progress = $user->getProgress();
    $current_level = $db->read(
        table: 'buttons',
        conditions: ['id' => $parent_btn_id ?? $user->getLastBtn()],
        single: true
    );
    $current_btn = Button::fromDbRow($current_level);

    if ($progress && sizeof($progress[array_key_first($progress)]) > 1) {
        $current_progress = &$progress[array_key_first($progress)];
        // Delete the last level
        array_pop($current_progress);
        // Clear the current last level
        $current_progress[array_key_last($current_progress)] = null;
        normalButtonHandler($user->setProgress($progress), $current_btn, $db);
    } else {
        // If user is at level 1 of a progress or has no
        // progress at all Redirect them back to the parent level.
        $parent_level = $db->read(
            table: 'buttons',
            conditions: ['id' => $current_btn->getBelongTo()],
            single: true
        );

        $last_btn = Button::fromDbRow($parent_level);
        normalButtonHandler(user: $user->setProgress(null), pressed_button: $last_btn, db: $db);
    }
}

#[NoReturn]
function cancelButton(User $user, DatabaseManager $db, int|string|null $parent_btn_id = null): void
{
    backButton($user->setProgress(null), $db, $parent_btn_id);
}


// ==========================================
//          LEVEL 0: Main Menu
// ==========================================

#[NoReturn]
function level_0(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null): void
{

    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 0], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    if ($callback_query) handleMainMenuCallBack($user, $callback_query, $message);
    if ($message) handleMainMenuTextMessage($data);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);
    }

    exit;
}

#[NoReturn]
function handleMainMenuCallBack(User $user, array $callback_query, array $message): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    // $query_data = $callback_query['data'];

    sendToTelegram('editMessageText', [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ]);
    exit;
}

#[NoReturn]
function handleMainMenuTextMessage(array $data): void
{
    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit;

}

// ==========================================
//          LEVEL 1: HOLDINGS
// ==========================================

#[NoReturn]
function level_1(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null,
    ?string         $command_data = null): void
{

    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 1], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    // Add '➕ افزودن دارایی جدید' button to the keyboard
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن دارایی جدید', '/assets/holding.html', add_api: true)]);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    if ($callback_query) handleHoldingsCallback($user, $callback_query, $data, $message, $db);
    if ($message && isset($message['web_app_data'])) handleHoldingsWebAppData($user, $data, $message, $db);
    if ($message && !isset($message['web_app_data'])) handleHoldingsTextMessage($user, $data, $message, $db);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        if ($command_data) {
            $holding = getHoldingsWithAssetDetails(['h.id' => $command_data, 'h.user_id' => $user->getId()], $db, true);
            if ($holding) sendHoldingDetail($holding, $data, $user->getBaseCurrency());
            else sendAllHoldings($user, $db, $response['result']['message_id']);

        } else sendAllHoldings($user, $db, $response['result']['message_id']);
    }

    exit;
}

#[NoReturn]
function handleHoldingsCallback(User $user, array $callback_query, array $data, array $message, $db): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    $data['message_id'] = $message['message_id'];

    switch ($query_key) {
        case 'holdings_list':
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);
            $response = sendToTelegram('sendMessage', $data);
            sendAllHoldings($user, $db, $response['result']['message_id']);
            break;

        default:
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            break;
    }
    exit;
}

#[NoReturn]
function handleHoldingsWebAppData(User $user, array $data, array $message, DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);

    $action = $web_app_data['action'] ?? null;

    $expected_data = false;

    if ($action == 'add') {

        $new_holding = $web_app_data['holding'];
        try {
            $db->create(
                table: 'holdings',
                data: [
                    "user_id" => $user->getId(),
                    "asset_id" => $new_holding["asset_id"],
                    "amount" => $new_holding["amount"],
                    "avg_price" => $new_holding["avg_price"],
                    "date" => JalaliDate::fromString($new_holding["date"])->toGregorian()->format('Y-m-d'),
                    "time" => $new_holding["time"],
                    "note" => $new_holding["note"],
                ]
            );
            $data['text'] = '✅ دارایی جدید با موفقیت ثبت شد.';

        } catch (PDOException $e) {

            if ($e->errorInfo[1] == 1062) {
                /**
                 * Duplicate Entry.
                 *
                 * Informs user of existing holding, redirects
                 * them to the holding and breaks the process.
                 */

                $data['text'] = 'شما از قبل این دارایی را در سیستم ثبت کرده اید.' . "\n" .
                    'درصورت تمایل برای ثبت تغییرات، دارایی ثبت شده را ویرایش کنید.';

                sendToTelegram('sendMessage', $data);

                $holding = getHoldingsWithAssetDetails(['h.asset_id' => $new_holding["asset_id"], 'h.user_id' => $user->getId()], $db, true);
                if ($holding) {
                    $db->update(
                        table: 'users',
                        data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                        conditions: ['id' => $user->getId()]
                    );
                    sendHoldingDetail($holding, $data, $user->getBaseCurrency());
                }
                exit;
            }

            error_log('Holding: ' . json_encode($new_holding) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->errorInfo[2];
        }
        $expected_data = true;
    }
    if ($action == 'edit') {

        try {
            $updates = $web_app_data['updates'];
            if (isset($updates['date'])) $updates['date'] = JalaliDate::fromString($updates['date'])->toGregorian()->format('Y-m-d');
            $db->update(
                table: 'holdings',
                data: $updates,
                conditions: ['id' => $web_app_data['id']]
            );
            $data['text'] = '✅ دارایی با موفقیت ویرایش شد.';

        } catch (PDOException $e) {
            error_log('Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->errorInfo[2];
        }
        $expected_data = true;
    }
    if ($action == 'delete') {

        try {
            $db->delete(
                table: 'holdings',
                conditions: ['id' => $web_app_data['id']],
                resetAutoIncrement: true
            );
            $data['text'] = '✅ دارایی با موفقیت حذف شد.';

        } catch (PDOException $e) {
            error_log('Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده درحذف دارایی: ' . $e->errorInfo[2];
        }
        $expected_data = true;
    }

    if ($expected_data) {
        // Send success/failure message
        sendToTelegram('sendMessage', $data);

        // Clear user progress and show all holdings
        $db->update('users', ['progress' => null], ['id' => $user->getId()]);
        sendAllHoldings($user, $db);
    } else {
        $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
        $data = checkAndAddEditHoldingButton($data, $user, $db);
        sendToTelegram('sendMessage', $data);
    }
    exit;
}

#[NoReturn]
function handleHoldingsTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{

    // Show holding detail
    $matched = preg_match('/^\/start viewHolding_holdingId(\d+)(_holdingsMssgId(\d+))?(_initMssgId(\d+))?$/m', $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        $holding_id = $matches[1];

        $holding = getHoldingsWithAssetDetails(['h.id' => $holding_id, 'h.user_id' => $user->getId()], $db, true);
        if ($holding) {

            // Delete redundant messages
            if (isset($matches[5]))
                sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $matches[5]]); ######## Initial
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $matches[3]]); ############ Holdings
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]); # Deep-Link

            sendHoldingDetail($holding, $data, $user->getBaseCurrency());
            $db->update(
                table: 'users',
                data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                conditions: ['id' => $user->getId()]
            );
            exit;

        } else $data['text'] = 'دارایی با این مشخصه یافت نشد!';
    } else $data['text'] = 'پیام نامفهوم است!';

    // Only irreverent texts and deep-links with wrong holding id reach here.
    $data = checkAndAddEditHoldingButton($data, $user, $db);
    sendToTelegram('sendMessage', $data);
    exit;
}

function sendAllHoldings(User $user, DatabaseManager $db, int|string $initial_mssg_id = null): void
{
    $holdings = getHoldingsWithAssetDetails(['user_id' => $user->getId()], $db);
    if ($holdings) {
        $temp_mssg = sendLoadingMessage($user->getid(), 'در حال دریافت اطلاعات دارایی‌ها ...');
        if ($temp_mssg) {

            $text = "دارایی‌های ثبت شده‌ی شما:\n";
            $total_pro_los = 0;
            foreach ($holdings as $holding) {
                $total_pro_los += $holding['amount'] * ($holding['current_price'] - $holding['avg_price']) * $holding['exchange_rate'];
                $text .= "\n";
                $text .= createHoldingDetailText(
                    holding: $holding,
                    markdown: 'MarkdownV2',
                    user_base_currency: $user->getBaseCurrency(),
                    attributes: ['org_amount', 'org_total_price', 'profit'],
                    holding_mssg_id: $temp_mssg['result']['message_id'],
                    initial_mssg_id: $initial_mssg_id
                );
            }

            $pro_los_string =
                ($total_pro_los == 0) ?
                    "🟤 جمع سود/زیان: ۰ " . $user->getBaseCurrency() : (
                ($total_pro_los > 0) ?
                    "🟢 جمع سود: " . beautifulNumber($total_pro_los) . ' ' . $user->getBaseCurrency() :
                    "🔴 جمع ضرر: " . beautifulNumber($total_pro_los) . ' ' . $user->getBaseCurrency()
                );
            $text .= "\n" . markdownScape($pro_los_string);

            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $temp_mssg['result']['message_id'],
                'text' => $text,
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $user->getid(), 'text' => 'شما هیچ دارایی‌ای ثبت نکرده‌اید.']);
    }
}

/**
 * Automatically adds edit button to the message.
 *
 * @param array $holding
 * @param array $data
 * @param string $user_base_currency
 * @return void
 */
function sendHoldingDetail(array $holding, array $data, string $user_base_currency = 'ریال'): void
{
    $data['text'] = "/holding_$holding[id]\n";
    $data['text'] .= 'جزئیات دارایی «' . $holding['asset_name'] . '»';

    array_unshift($data['reply_markup']['keyboard'], [
        createWebAppBtn(
            text: '✏ ویرایش ' . beautifulNumber($holding['asset_name'], null),
            path: '/assets/holding.html',
            params: ['holding' => base64_encode(json_encode($holding))],
            add_api: true)
    ]);

    sendToTelegram('sendMessage', $data);

    $temp_mssg = sendLoadingMessage($data['chat_id'], 'در حال دریافت اطلاعات به دارایی ' . $holding['asset_name'] . ' ...');
    if ($temp_mssg) {

        $data['message_id'] = $temp_mssg['result']['message_id'];
        $data['text'] = createHoldingDetailText($holding, user_base_currency: $user_base_currency);
        $data['parse_mode'] = 'MarkdownV2';
        $data['reply_markup'] = ['inline_keyboard' => [[['text' => 'برگشت به لیست دارایی‌ها', 'callback_data' => json_encode(['holdings_list' => null])]]]];

        sendToTelegram('editMessageText', $data);
    }

}

function checkAndAddEditHoldingButton(array $data, User $user, DatabaseManager $db): array
{
    $progress = $user->getProgress();
    if ($progress && key($progress) === 'view_holding') {
        $holding = getHoldingsWithAssetDetails(['h.id' => $progress['view_holding']['holding_id'], 'h.user_id' => $user->getId()], $db, true);

        if ($holding) {
            array_unshift($data['reply_markup']['keyboard'], [
                createWebAppBtn(
                    text: '✏ ویرایش ' . $holding['asset_name'],
                    path: '/assets/holding.html',
                    params: ['holding' => base64_encode(json_encode($holding))],
                    add_api: true)
            ]);
        }
    }

    return $data;
}


// ==========================================
//          LEVEL 2: LOANS & INSTALLMENTS
// ==========================================

#[NoReturn]
function level_2(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null,
    ?string         $command_data = null): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 2], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    // Add '➕ افزودن وام جدید' button to the keyboard
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن وام جدید', '/assets/loan.html')]);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    if ($callback_query) handleLoansCallback($user, $callback_query, $data, $message, $db);
    if ($message && isset($message['web_app_data'])) handleLoansWebAppData($user, $data, $message, $db);
    if ($message && !isset($message['web_app_data'])) handleLoansTextMessage($user, $data, $message, $db);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);
        if ($command_data) {
            $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $command_data);
            if ($loan) sendLoanDetail($loan, $data);
            else sendAllLoans($user, $db, $response['result']['message_id']);

        } else sendAllLoans($user, $db, $response['result']['message_id']);
    }

    exit;
}

#[NoReturn]
function handleLoansCallback(User $user, array $callback_query, array $data, array $message, $db): void
{
    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    $data['message_id'] = $message['message_id'];

    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

    switch ($query_key) {
        case 'loans_list':
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);
            $response = sendToTelegram('sendMessage', $data);
            if ($response) sendAllLoans($user, $db, $response['result']['message_id']);
            break;

        case 'detailed_loans':
            sendAllLoans($user, $db, null, $message['message_id'], !$query_data[$query_key]);
            break;

        default:
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            break;
    }
    exit;
}

#[NoReturn]
function handleLoansWebAppData(User $user, array $data, array $message, DatabaseManager $db): void
{
    /*
     * TODO: Add Triggers to automatically calculate `alert_date` column:
     *  `after insert` trigger for installments and
     *  `after update` trigger for loans table
     */

    $web_app_data = json_decode($message['web_app_data']['data'], true);
    // Add new loan and installments
    if (isset($web_app_data['loan']) &&
        isset($web_app_data['installments'])) {

        $new_loan = $web_app_data['loan'];
        try {

            $received_date = JalaliDate::fromString($new_loan['received_date'], '-')->toGregorian();
            $loan_id = $db->create(
                table: 'loans',
                data: [
                    'user_id' => $user->getId(),
                    'name' => $new_loan['name'],
                    'total_amount' => $new_loan['total_amount'],
                    'received_date' => $received_date->format('Y-m-d'),
                    'alert_offset' => $new_loan['alert_offset'],
                ]);

            $count = 0;
            foreach ($web_app_data['installments'] as $inst) {
                try {

                    $due_date = JalaliDate::fromString($inst['due_date'])->toGregorian();
                    $alert_date = JalaliDate::fromString($inst['alert_date'])->toGregorian();
                    $db->create(
                        table: 'installments',
                        data: [
                            'loan_id' => $loan_id,
                            'amount' => $inst['amount'],
                            'due_date' => $due_date->format('Y-m-d'),
                            'alert_date' => $alert_date->format('Y-m-d'),
                            'is_paid' => $inst['is_paid']
                        ]);
                    $count++;
                } catch (Exception $e) {
                    error_log(
                        'Installment: ' . json_encode($inst) . "\n" .
                        'Error: ' . $e->getMessage());
                }
            }
            $data['text'] = "✅ وام «{$new_loan['name']}» با موفقیت ثبت شد.\n📊 تعداد اقساط: " . beautifulNumber($count);
            sendToTelegram('sendMessage', $data);

        } catch (Exception $e) {
            sendToTelegram('sendMessage', [
                'text' => '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->getMessage(),
                'chat_id' => $user->getid()
            ]);
            error_log(
                'Loan: ' . json_encode($new_loan) . "\n" .
                'Error: ' . $e->getMessage());
        }
        sendAllLoans($user, $db);
        exit;

    }

    // Edit existing loan and related installments
    if (isset($web_app_data['id']) &&
        isset($web_app_data['updates'])) {

        $new_insts = $web_app_data['updates']['installments'] ?? null;
        unset($web_app_data['updates']['installments']);

        $loan_modified = false;
        $data['text'] = "نتیجه ویرایش وام: ";
        if ($web_app_data['updates']) {
            try {
                $db->update(
                    table: 'loans',
                    data: $web_app_data['updates'],
                    conditions: ['id' => $web_app_data['id'], 'user_id' => $user->getId()]);
                $loan_modified = true;

            } catch (Exception $e) {
                error_log(
                    'Loan Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                    'Error: ' . $e->getMessage()
                );
            }
        }
        $data['text'] .= $loan_modified ? "\nویرایش اطلاعات وام: ✅" : "\nویرایش اطلاعات وام: ❌";

        if ($new_insts) {

            foreach ($new_insts as &$new_inst) {
                $new_inst['loan_id'] = $web_app_data['id'];
                $new_inst['due_date'] = JalaliDate::fromString($new_inst['due_date'])->toGregorian()->format('Y-m-d');
                $new_inst['alert_date'] = JalaliDate::fromString($new_inst['alert_date'])->toGregorian()->format('Y-m-d');
            }

            // Update the Existing installments, based on their IDs or dates
            try {
                $db->upsertBatch(
                    table: 'installments',
                    dataRows: $new_insts
                );
                $data['text'] .= "\nویرایش اطلاعات اقساط: ✅";
            } catch (Exception $e) {
                $data['text'] .= "\nویرایش اطلاعات اقساط: ❌";
                error_log(
                    'New Installments: ' . json_encode($new_insts) . "\n" .
                    'Error: ' . $e->getMessage()
                );
            }

            // Delete redundant installments
            try {
                $deleted_rows = $db->delete(
                    table: 'installments',
                    conditions: ['loan_id' => $web_app_data['id'], '!due_date' => array_column($new_insts, 'due_date')]
                );
                $data['text'] .= "\nتعداد قسط حذف شده: " . beautifulNumber($deleted_rows);
            } catch (Exception $e) {
                $data['text'] .= "\nحذف اقساط با خطا مواجه شد! ";
                error_log(
                    'delete Installment: ' . json_encode(array_column($new_insts, 'due_date')) . "\n" .
                    'Error: ' . $e->getMessage()
                );
            }
        }

        sendToTelegram('sendMessage', $data);
        sendAllLoans($user, $db);
        exit;

    }

    // Delete existing loan and related installments
    if (isset($web_app_data['delete']) && $web_app_data['delete']) {

        try {
            $db->delete(
                table: 'loans',
                conditions: ['id' => $web_app_data['id']]
            );
            $data['text'] = '✅ حذف وام با موفقیت انجام شد!';
        } catch (Exception $e) {
            $data['text'] = '❌ خطای پایگاه داده در حذف وام!';
            error_log(
                'Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . $e->getMessage());

        }

        sendToTelegram('sendMessage', $data);
        sendAllLoans($user, $db);
        exit;
    }

    error_log('Unprocessable WebApp Data Received: ' . "\n" . json_encode($web_app_data));

    $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
    sendToTelegram('sendMessage', $data);

    sendAllLoans($user, $db);
    exit;

}

#[NoReturn]
function handleLoansTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{

    // Show loan detail
    $matched = preg_match("/^\/start showLoan_loanId(\d+?)(_loansMssgId(\d+?))?(_initMssgId(\d+?))?$/m", $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $matches[1]);

        if ($loan) {
            /** else: Send default Irrelevance message */

            // Delete redundant messages
            if (isset($matches[5]))
                sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $matches[5]]); ######## Initial
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $matches[3]]); ############ Loans
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]); # Deep-Link

            sendLoanDetail($loan, $data);

            $db->update(
                table: 'users',
                data: ['progress' => json_encode(['view_loan' => ['loan_id' => $loan['id']]])],
                conditions: ['id' => $user->getId()]
            );
            exit;
        }
    }

    // Toggle Installment Payment
    $matched = preg_match("/^\/start toggleInstPayment_instId(\d+?)_mssgId(\d+?)$/m", $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        // Delete deep-link message
        sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);

        $installment = $db->read(
            table: 'installments i',
            conditions: ['i.id' => $matches[1], 'l.user_id' => $user->getId()],
            single: true,
            selectColumns: 'i.*, l.user_id',
            join: 'LEFT JOIN loans l ON i.loan_id = l.id'
        );

        if ($installment) {
            /** else: Default Irrelevance message will be sent */

            $db->update(
                table: 'installments',
                data: ['is_paid' => !$installment['is_paid']],
                conditions: ['id' => $installment['id']]
            );

            $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $installment['loan_id']);

            if ($loan) {
                sendToTelegram('editMessageText', [
                    'chat_id' => $user->getid(),
                    'message_id' => $matches[2],
                    'text' => createLoanDetailText($loan, 'MarkdownV2', $matches[2]),
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => ['inline_keyboard' => createLoanDetailKeyboard($loan)]
                ]);
            }
            exit;

        }
    }

    /**
     * Add '✏ ویرایش' button to the keyboard if usee is viewing a loan.
     * This works with irreverent texts and wrong loan or installment id.
     */
    $progress = $user->getProgress();
    if ($progress && key($progress) === 'view_loan') {
        $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $progress['view_loan']['loan_id']);
        if ($loan) {
            array_unshift($data['reply_markup']['keyboard'], [
                createWebAppBtn(
                    text: '✏ ویرایش وام «' . $loan['name'] . '»',
                    path: '/assets/loan.html',
                    params: ['data' => base64_encode(json_encode($loan))])
            ]);
        }
    }

    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit;

}

function sendAllLoans(User $user, DatabaseManager $db, ?string $initial_mssg_id = null, ?string $mssg_id_to_edit = null, bool $summerized = true): void
{
    $loans = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true);

    if ($loans) {
        if (!$mssg_id_to_edit) { // TODO: I'm not satisfied with this approach
            $temp_mssg = sendLoadingMessage($user->getid(), 'در حال دریافت اطلاعات وام‌ها ...');
            if ($temp_mssg) $mssg_id_to_edit = $temp_mssg['result']['message_id'];
        }

        if ($mssg_id_to_edit)
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $mssg_id_to_edit,
                'text' => createLoansView($loans, $mssg_id_to_edit, $initial_mssg_id, $summerized),
                'parse_mode' => 'MarkdownV2',
                'reply_markup' => ['inline_keyboard' => [[
                    [
                        'text' => $summerized ? 'نمایش جزئیات وام‌ها' : 'پنهان کردن جزئیات',
                        'callback_data' => json_encode(['detailed_loans' => $summerized])
                    ]]]]
            ]);
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $user->getid(), 'text' => 'هیچ وام یا قسطی برای شما ثبت نشده است!']);
    }

    $db->update('users', ['progress' => null], ['id' => $user->getId()]);
}

function sendLoanDetail(array $loan, array $data): void
{

    $data['text'] = "/loan_$loan[id]\n";
    $data['text'] .= 'جزئیات وام «' . $loan['name'] . '»';

    array_unshift($data['reply_markup']['keyboard'], [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/loan.html', ['data' => base64_encode(json_encode($loan))])]);

    sendToTelegram('sendMessage', $data);

    $temp_mssg = sendLoadingMessage($data['chat_id'], 'در حال دریافت اطلاعات اقساط ...');
    if ($temp_mssg) {

        $data['message_id'] = $temp_mssg['result']['message_id'];
        $data['text'] = createLoanDetailText($loan, 'MarkdownV2', $temp_mssg['result']['message_id']);
        $data['parse_mode'] = 'MarkdownV2';
        $data['reply_markup'] = ['inline_keyboard' => createLoanDetailKeyboard($loan)];

        sendToTelegram('editMessageText', $data);
    }
}

#[NoReturn]
function payInstallmentFromCronJob(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    $installment_id = $callback_query['data']['cron_inst_paid'];
    $user_id = $user->getId();
    try {
        $db->query("
        UPDATE installments i
        JOIN loans l ON i.loan_id = l.id
        SET i.is_paid = true
        WHERE i.id = $installment_id
        AND l.user_id = $user_id;
    ");
        $text = $message['text'] . "\n\n" . "✅ پرداخت قسط ثبت شد.";
    } catch (Exception $e) {
        error_log('Error adding new favorite: ' . $e->getMessage());
        $text = $message['text'] . "\n\n" . "❌ خطای پایگاه داده!";
    }

    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    sendToTelegram('editMessageText', ['chat_id' => $user->getId(), 'text' => $text, 'message_id' => $message['message_id']]);
    exit();
}


#[NoReturn]
function inplaceInstallmentPaymentToggle(User $user, array $callback_query, array $message, DatabaseManager $db): void
{

    $installment_id = $callback_query['data']['inplace_inst_pay_toggle'];

    // FIXME: Duplicate code fragment
    $db->query("update installments set is_paid = !is_paid where id = $installment_id")->fetch();

    $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, installment_id: $installment_id);

    if ($loan) {
        sendToTelegram('editMessageText', [
            'chat_id' => $user->getid(),
            'message_id' => $message['message_id'],
            'text' => createLoanDetailText($loan, 'MarkdownV2', $message['message_id']),
            'parse_mode' => 'MarkdownV2',
            'reply_markup' => ['inline_keyboard' => createLoanDetailKeyboard($loan)]
        ]);
    }
    exit();
}

// ==========================================
//          LEVEL 5: PRICES
// ==========================================

#[NoReturn]
function level_5(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 5], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    $asset_types = $db->read(
        table: 'assets',
        selectColumns: 'asset_type',
        distinct: true,
        orderBy: ['asset_type' => 'DESC']
    );

    $asset_types = array_column($asset_types, 'asset_type');

    // Add asset types to level 5 keyboard
    foreach ($asset_types as $asset_type) array_unshift($keyboard, [['text' => $asset_type]]);
    $data['reply_markup']['keyboard'] = $keyboard;

    if ($callback_query) handlePricesCallback($user, $callback_query, $message, $asset_types, $db);
    if ($message) handlePricesTextMessage($data, $message, $asset_types, $user->getBaseCurrency(), $db);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        // Send Informative message
        sendAllFavorites($user, $db);
    }

    exit;
}

#[NoReturn]
function handlePricesCallback(User $user, array $callback_query, array $message, array $asset_types, DatabaseManager $db): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => '📢 خطای ناشناخته!'
    ];

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    switch ($query_key) {

        // Show menu to add/remove a favorite asset
        case 'edit_fav':

            $action = $query_data['edit_fav'];

            // Show main menu for editing favorites
            if ($action === null) {

                $data['text'] = 'عملیات مورد نظر را انتخاب کنید:';
                $data['reply_markup']['inline_keyboard'] = [
                    [['text' => 'حذف', 'callback_data' => json_encode(['edit_fav' => 'remove'])]],
                    [['text' => 'افزودن', 'callback_data' => json_encode(['edit_fav' => 'add'])]],
                    [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['show_favorites' => null])]],
                ];
                setLiveMessage($user->getId(), false, $message['message_id'], $db);
            }

            // Show list of asset types for adding new asset
            if ($action === 'add') {

                $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
                $data['reply_markup']['inline_keyboard'] = [[
                    ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])],
                    ['text' => '❌ لغو ❌', 'callback_data' => json_encode(['show_favorites' => null])]
                ]];

                foreach ($asset_types as $asset_type) {
                    array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => beautifulNumber($asset_type, null), 'callback_data' => json_encode(['new_fav_type' => $asset_type])]]
                    );
                }
            }

            // Show list of favorites to choose for deletion
            if ($action === 'remove') {

                $favorites = $db->read(
                    table: 'favorites f',
                    conditions: ['f.user_id' => $user->getId()],
                    selectColumns: 'a.*, f.id as fav_id',
                    join: 'JOIN assets a ON a.name=f.asset_name', // Join is required for sorting the based on asset type
                    orderBy: ['asset_type' => 'ASC']
                );
                $data['reply_markup']['inline_keyboard'] = [[
                    ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])],
                    ['text' => '❌ لغو ❌', 'callback_data' => json_encode(['show_favorites' => null])]
                ]];
                if ($favorites) {

                    $data['text'] = 'کدام گزینه را می‌خواهید حذف کنید؟';

                    foreach ($favorites as $favorite) {
                        array_unshift(
                            $data['reply_markup']['inline_keyboard'],
                            [['text' => beautifulNumber($favorite['name'], null), 'callback_data' => json_encode(['del_fav' => $favorite['fav_id']])]]
                        );
                    }
                } else {
                    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => 'لیست علاقه‌مندی‌های شما خالی‌ست!']);
                    exit;
                }
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        // Show list of assets in a specific type for user to add to their favorites
        case 'new_fav_type':

            $data['reply_markup']['inline_keyboard'] = [[
                ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => 'add'])],
                ['text' => '❌ لغو ❌', 'callback_data' => json_encode(['show_favorites' => null])]
            ]];

            // Read assets excluding the ones already in user's list of favorites
            $assets = $db->query(
                "select a.* from assets a " .
                "left join favorites f on f.asset_name = a.name where " . // Join favorites to filter out existing ones
                "a.asset_type = '$query_data[new_fav_type]' and" . ///////// Get assets with the received type
                "(f.user_id is null or" . /////////////////////////////////// Include assets which are not in favorites table
                " f.user_id!=" . $user->getId() . ")" ////////////////////// Exclude assets which are already in user's list
            )->fetchAll();

            if ($assets) {
                $data['text'] = 'گزینه‌ی مد نظر خود را از لیست زیر انتخاب کنید:';

                foreach ($assets as $asset)
                    array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => beautifulNumber($asset['name'], null), 'callback_data' => json_encode(['new_fav_name' => $asset['name']])]]
                    );

            } else $data['text'] = 'دسته‌بندی مورد نظر خالی‌ست!';

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        // Add new favorite to the table and send the favorites message to the user
        case 'new_fav_name':

            $asset_name = $query_data['new_fav_name'];
            try {
                $db->create(
                    table: 'favorites',
                    data: [
                        'user_id' => $user->getId(),
                        'asset_name' => $asset_name
                    ]
                );
                $data['text'] = '✅ «' . beautifulNumber($asset_name, null) . '» به لیست علاقه‌مندی‌های شما افزوده شد!';
            } catch (Exception $e) {
                error_log('Error adding new favorite: ' . $e->getMessage());
                $data['text'] = '❌ خطای پایگاه داده!';
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            sendAllFavorites($user, $db);

        // Show confirmation message for deleting a favorite
        case 'del_fav':

            $favorite_id = $query_data['del_fav'];

            $data['text'] = 'آیا از حذف اطمینان دارید؟';
            $data['reply_markup']['inline_keyboard'] = [[
                ['text' => 'لغو', 'callback_data' => json_encode(['show_favorites' => null])],
                ['text' => 'تایید', 'callback_data' => json_encode(['conf_del_fav' => $favorite_id])],
            ]];

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        // Delete favorite from the database and send the favorites message to the user
        case 'conf_del_fav':

            $favorite_id = $query_data['conf_del_fav'];
            try {
                $db->delete(
                    table: 'favorites',
                    conditions: ['id' => $favorite_id],
                    resetAutoIncrement: true
                );
                $data['text'] = '✅ حذف موفقیت آمیز بود!';
            } catch (Exception $e) {
                error_log('Error deleting a favorite: ' . $e->getMessage());
                $data['text'] = '❌ خطای پایگاه داده!';
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            sendAllFavorites($user, $db);

        // Start showing live price updates on the current message
        case 'set_live':
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            deleteOldActiveLiveMessage($user, $message['message_id'], $db);
            setLiveMessage($user->getId(), $query_data['set_live'], $message['message_id'], $db);
            sendAllFavorites($user, $db, $message['message_id']);

        // Show the main favorites' message
        case 'show_favorites':
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendAllFavorites($user, $db, $message['message_id']);

        default:
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            exit;
    }
}

#[NoReturn]
function handlePricesTextMessage(array $data, array $message, array $asset_types, string $base_currency, DatabaseManager $db): void
{
    if (in_array($message['text'], $asset_types)) {

        // Retrieve all related assets
        $assets = $db->read('assets', ['asset_type' => $message['text']]);

        $base_prices = CreateNamePricePairs(
            array_merge(array_unique(array_column($assets, 'base_currency')), [$base_currency]),
            $db
        );

        if ($assets) $data['text'] = createPricesTextForSingleAssetType($assets, $base_prices, $base_currency);
        else $data['text'] = 'این دسته بندی خالی‌ست!';

        $data['reply_to_message_id'] = $message['message_id'];
        sendToTelegram('sendMessage', $data);
        exit;
    }

    // Send default message of this level
    $data['text'] = 'پیام نامفهوم است!' . "\n" . 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
    sendToTelegram('sendMessage', $data);
    exit;

}

/**
 * Activate/Inactivate current message in the database as `live_price`.
 *
 * @param int|string $user_id
 * @param bool $activate On false only works on existing record with the same `$message_id`
 * @param int|string $message_id The ID of the message to set as live price message
 * @param DatabaseManager $db
 * @return bool|null Activation state on success or `null` on database error
 */
function setLiveMessage(int|string $user_id, bool $activate, int|string $message_id, DatabaseManager $db): bool|null
{
    $db_result = false;
    try {
        if ($activate === true)
            $db_result = $db->upsert(
                table: 'special_messages',
                data: [
                    'user_id' => $user_id,
                    'type' => 'live_price',
                    'is_active' => true,
                    'message_id' => $message_id,
                ]
            );

        if ($activate === false)
            $db_result = $db->update(
                table: 'special_messages',
                data: [
                    'is_active' => false,
                ],
                conditions: [
                    'user_id' => $user_id,
                    'type' => 'live_price',
                    'message_id' => $message_id
                ]
            );

    } catch (Exception $e) {
        error_log('changeLiveMessageState: ' . $e->getMessage());
    }

    if ($db_result) return $activate;
    else return null;

}

// ==========================================
//          LEVEL 6: ARTIFICIAL INTELLIGENCE
// ==========================================

#[NoReturn]
function level_6(
    User            $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?array          $callback_query = null): void
{
    if ($callback_query) {
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        sendToTelegram('editMessageText', [
            'chat_id' => $user->getid(),
            'message_id' => $message['message_id'],
            'text' => 'این پیام منقضی شده است.'
        ]);
    } else {
        sendToTelegram('sendMessage', [
            'chat_id' => $user->getid(),
            'text' => 'در حال توسعه...',
            'reply_markup' => [
                'keyboard' => createKeyboardsArray(6, $user->isAdmin(), $db),
                'resize_keyboard' => true,
                'input_field_placeholder' => 'هوش مصنوعی',
            ]
        ]);
        $db->update(
            table: 'users',
            data: ['last_btn' => 6, 'progress' => null],
            conditions: ['id' => $user->getId()]
        );
    }
    exit;
}

// ==========================================
//          Base Currency
// ==========================================

#[NoReturn]
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

#[NoReturn]
function setBaseCurrency(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'];

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


// ==========================================
//          LEVEL 8: Alerts
// ==========================================

#[NoReturn]
function level_8(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 8], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    if ($callback_query) handleAlertsCallback($user, $message);
    if ($message) handleAlertsTextMessage($data);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        // Send Informative message
        sendAllAlerts($user, $db);
    }

    exit;
}

#[NoReturn]
function handleAlertsCallback(User $user, array $message): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'];

    sendToTelegram('editMessageText', $data);
    exit;
}

#[NoReturn]
function handleAlertsTextMessage(array $data): void
{
    // Send default message of this level
    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit;

}

function sendAllAlerts(User $user, DatabaseManager $db, int|string|null $message_id = null): void
{
    $message_id = ($message_id !== null) ?
        $message_id :
        sendLoadingMessage($user->getid(), 'در حال دریافت اطلاعات لیست علاقه‌مندی‌ها ...')['result']['message_id'];

    $alerts = $db->read(
        table: 'alerts',
        conditions: ['user_id' => $user->getId()],
        selectColumns: '
            alerts.*,
            assets.emoji,
            assets.asset_type,
            assets.price as current_price,
            assets.base_currency,
            assets.date as update_date,
            assets.time as update_time',
        join: 'join assets on assets.name = alerts.asset_name',
        orderBy: ['assets.asset_type' => 'ASC', 'alerts.asset_name' => 'ASC', 'alerts.target_price' => 'ASC']
    );

    $data = [
        'text' => &$text,
        'chat_id' => $user->getid(),
        'message_id' => $message_id,
        'reply_markup' => ['inline_keyboard' => [
            [['text' => 'مدیریت هشدارها', 'callback_data' => json_encode(['mng_alerts' => null])]]
        ]]
    ];

    if ($alerts) {
        $text = 'هشدارهای شما:' . "\n";
        foreach ($alerts as $alert) {
            $text .= "\n  - " . beautifulNumber($alert['asset_name'], null) . ': ' . beautifulNumber($alert['target_price']);
        }
    } else $text = 'شما هشداری ثبت نکرده‌اید!';

    sendToTelegram('editMessageText', $data);
}

#[NoReturn]
function managePriceAlerts(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'];

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    switch ($query_key) {

        // Add or remove alerts
        case 'mng_alerts':

            $action = $query_data[$query_key];

            // Show alerts' management menu
            if ($action == null) {
                $data['text'] = 'عملیات مورد نظر را انتخاب کنید:';
                $data['reply_markup'] = ['inline_keyboard' => [
                    [['text' => 'افزودن هشدار', 'callback_data' => json_encode(['mng_alerts' => 'add_alert'])]],
                    [['text' => 'حذف هشدار', 'callback_data' => json_encode(['mng_alerts' => 'remove_alert'])]],
                    [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['show_alerts' => null])]],
                ]];
            }

            // Show list of asset types to select for new alert
            if ($action == 'add_alert') {
                $asset_types = $db->read('assets', selectColumns: 'asset_type', distinct: true);

                if ($asset_types) {
                    $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
                    $data['reply_markup']['inline_keyboard'] = [[
                        ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['mng_alerts' => null])],
                        ['text' => '❌ لغو ❌', 'callback_data' => json_encode(['show_alerts' => null])]
                    ]];

                    $asset_types = array_column($asset_types, 'asset_type');
                    foreach ($asset_types as $asset_type) array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => beautifulNumber($asset_type, null), 'callback_data' => json_encode(['new_alert_type' => $asset_type])]]
                    );
                } else {
                    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => 'دسته‌بندی‌ای در سیستم یافت نشد!']);
                    exit;
                }
            }

            // Show list of alerts to delete
            if ($action == 'remove_alert') {
                $alerts = $db->read(
                    table: 'alerts',
                    conditions: ['user_id' => $user->getId()],
                    selectColumns: '
                        alerts.*,
                        assets.emoji,
                        assets.asset_type,
                        assets.price as current_price,
                        assets.base_currency,
                        assets.date as update_date,
                        assets.time as update_time',
                    join: 'join assets on assets.name = alerts.asset_name'
                );

                if ($alerts) {
                    $data['text'] = 'کدام مورد را می‌خواهید حذف کنید؟';

                    $data['reply_markup']['inline_keyboard'] = [[
                        ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['mng_alerts' => null])],
                        ['text' => '❌ لغو ❌', 'callback_data' => json_encode(['show_alerts' => null])]
                    ]];

                    foreach ($alerts as $alert) array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => beautifulNumber($alert['asset_name'], null) . ': ' . beautifulNumber($alert['target_price']), 'callback_data' => json_encode(['del_alert' => $alert['id']])]]
                    );

                } else {
                    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => 'شما هشداری ثبت نکرده‌اید!']);
                    exit;
                }
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        // Show list of asset to select for new alert
        case 'fav_alert': // A request from favorites message
        case 'new_alert_type': // A request from alert manager message

            if ($query_key == 'fav_alert') {
                $data['reply_markup']['inline_keyboard'] = [[
                    ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['show_favorites' => null])]
                ]];

                $assets = $db->read(
                    table: 'favorites f',
                    conditions: ['f.user_id' => $user->getId()],
                    selectColumns: 'a.*, f.id as fav_id',
                    join: 'JOIN assets a ON a.name=f.asset_name',
                    orderBy: ['asset_type' => 'ASC']
                );

            } else {
                $data['reply_markup']['inline_keyboard'] = [[
                    ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['mng_alerts' => 'add_alert'])],
                    ['text' => '❌ لغو ❌', 'callback_data' => json_encode(['show_alerts' => null])]
                ]];
                $assets = $db->read('assets', ['asset_type' => $query_data[$query_key]]);
            }

            if ($assets) {
                $data['text'] = 'گزینه‌ی مد نظر خود را از لیست زیر انتخاب کنید:';

                foreach ($assets as $asset) array_unshift(
                    $data['reply_markup']['inline_keyboard'],
                    [['text' => beautifulNumber($asset['name'], null), 'callback_data' => json_encode(['new_alert_name' => $asset['name']])]]
                );

            } else $data['text'] = 'دسته‌بندی مورد نظر خالی‌ست!';

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        case 'new_alert_name':

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);

            $user = $user->setProgress(['parent_btn' => $user->getLastBtn(), 'data' => ['set_alert' => ['asset_name' => $query_data['new_alert_name']]]]);
            empty_level($user, $db, $user->getLastBtn());

        // Ask user to confirm deleting alert
        case 'del_alert':
            $alert_id = $query_data[$query_key];

            $data['text'] = 'آیا از حذف اطمینان دارید؟';
            $data['reply_markup']['inline_keyboard'] = [[
                ['text' => 'لغو', 'callback_data' => json_encode(['show_alerts' => null])],
                ['text' => 'تایید', 'callback_data' => json_encode(['conf_del_alert' => $alert_id])],
            ]];

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            exit;

        // Delete alert and send alerts' message to the user
        case 'conf_del_alert':

            $alert_id = $query_data[$query_key];
            try {
                $db->delete(
                    table: 'alerts',
                    conditions: ['id' => $alert_id],
                    resetAutoIncrement: true
                );
                $data['text'] = '✅ حذف موفقیت آمیز بود!';
            } catch (Exception $e) {
                error_log('Error deleting a favorite: ' . $e->getMessage());
                $data['text'] = '❌ خطای پایگاه داده!';
            }

            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendToTelegram('editMessageText', $data);
            sendAllAlerts($user, $db);
            exit;

        // Show main list of alerts
        case 'show_alerts':
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            sendAllAlerts($user, $db, $message['message_id']);
            exit;
    }

    sendToTelegram('editMessageText', $data);
    exit;

}

// ==========================================
//          LEVEL 9: Accounts
// ==========================================

#[NoReturn]
function level_9(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 9], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    if ($callback_query) handleAccountsCallback($user, $message);
    if ($message) handleAccountsTextMessage($data);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        // Send Informative message
        sendAllAccounts($user, $db);
    }

    exit;
}

#[NoReturn]
function handleAccountsCallback(User $user, array $message): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'];

    sendToTelegram('editMessageText', $data);
    exit;
}

#[NoReturn]
function handleAccountsTextMessage(array $data): void
{
    // Send default message of this level
    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit;

}

function sendAllAccounts(User $user, DatabaseManager $db, int|string|null $message_id = null): void
{
    $message_id = ($message_id !== null) ?
        $message_id :
        sendLoadingMessage($user->getid(), 'در حال دریافت لیست حساب‌ها ...')['result']['message_id'];

    $accounts = $db->read('accounts', ['user_id' => $user->getId()]);

    $data = [
        'text' => &$text,
        'chat_id' => $user->getid(),
        'message_id' => $message_id,
//        'reply_markup' => ['inline_keyboard' => [
//            [['text' => 'مدیریت حساب‌ها', 'callback_data' => json_encode(['mng_accounts' => null])]]
//        ]]
    ];

    if ($accounts) {
        $text = 'حساب‌های شما:' . "\n";
        foreach ($accounts as $account) {
            $text .= "\n  - " . "‏" . beautifulNumber($account['name'], null) . "‏" . " (" . beautifulNumber($account['type'], null) . "): " . beautifulNumber($account['current_balance']);
        }
    } else $text = 'شما حسابی ثبت نکرده‌اید!';

    sendToTelegram('editMessageText', $data);
}


// ==========================================
//          LEVEL 10: Add New Account
// ==========================================

#[NoReturn]
function level_10(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 10], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    if ($callback_query) handleAddAccountsCallback($user, $message);

    addAccountProgress($user, $data, $message, $db);
}

#[NoReturn]
function handleAddAccountsCallback(User $user, array $message): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'];

    sendToTelegram('editMessageText', $data);
    exit;
}

#[NoReturn]
function addAccountProgress(User $user, array $data, ?array $message, DatabaseManager $db): void
{
    /**
     * Required fields for new account: user_id, type and name.
     *
     * If any of the three values are not presented, asks for it,
     * Otherwise adds the account to the database.
     */

    $progress = $user->getProgress();
    if (!$progress || !isset($progress['add_account'])) {
        // Starting adding account process
        $progress = ['add_account' => ['type' => null]];
        $db->update('users', ['last_btn' => 10, 'progress' => json_encode($progress)], ['id' => $user->getId()]);
        askForAccountType($user->setProgress($progress), $data, $db);

    } else {
        /*
         * Each `if works with this principle:
         *  $message == null -> asks for certain information.
         *  $message != null -> saves the received information
         */
        if (!isset($progress['add_account']['type'])) {
            if (!$message) askForAccountType($user, $data, $db);
            $progress['add_account']['type'] = $message['text'];
            addAccountProgress($user->setProgress($progress), $data, null, $db);
        }
        if (!isset($progress['add_account']['name'])) {
            if (!$message) askForAccountName($user, $data, $db);
            $progress['add_account']['name'] = $message['text'];
            addAccountProgress($user->setProgress($progress), $data, null, $db);
        }
        if (!isset($progress['add_account']['starting_balance'])) {
            if (!$message) askForAccountStartingBalance($user, $data, $db);
            $amount = cleanAndValidateNumber($message['text']);
            if ($amount === null)
                askForAccountStartingBalance($user, $data, $db, 'پیام نامفهوم بود. لطفاً موجودی را تنها با استفاده از ارقام وارد کنید!');
            $progress['add_account']['starting_balance'] = $amount;
            addAccountProgress($user->setProgress($progress), $data, null, $db);
        }
    }

    // Add the account if all the required values are presented
    addAccount($user, [
        'user_id' => $user->getId(),
        'type' => $progress['add_account']['type'],
        'name' => $progress['add_account']['name'],
        'starting_balance' => $progress['add_account']['starting_balance'],
        'current_balance' => $progress['add_account']['starting_balance']
    ], $data, $db);
}

#[NoReturn]
function askForAccountType(User $user, array $data, DatabaseManager $db): void
{
    $data['text'] = 'نوع حساب را وارد کنید' . "\n" . 'مثال: بانک، نقد، شخص';
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = ['add_account' => ['type' => null]];
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]);
    }
    exit;
}

#[NoReturn]
function askForAccountName(User $user, array $data, DatabaseManager $db): void
{
    $data['text'] = 'نام حساب را وارد کنید' . "\n" . 'مثال: سپه، ملی، کیف‌پول، علی‌رضا';
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_account']['name'] = null;
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]);
    }
    exit;
}

#[NoReturn]
function askForAccountStartingBalance(User $user, array $data, DatabaseManager $db, ?string $text = null): void
{
    $data['text'] = $text ?? 'موجودی کنونی حساب را وارد کنید';
    $response = sendToTelegram('sendMessage', $data);
    if ($response) {
        $progress = $user->getProgress();
        $progress['add_account']['starting_balance'] = null;
        $db->update(
            'users',
            ['progress' => json_encode($progress)],
            ['id' => $user->getId()]);
    }
    exit;
}

#[NoReturn]
function addAccount(User $user, array $account, array $data, DatabaseManager $db): void
{
    try {
        $db->create('accounts', $account);
        $data['text'] = '✅ حساب جدید با موفقیت ثبت شد.';

    } catch (PDOException $e) {
        error_log('Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));
        $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی: ' . $e->errorInfo[2];
    }

    // Send success/failure message
    sendToTelegram('sendMessage', $data);

    // Redirect user to view all accounts
    level_9($user, $db);
}

// ==========================================
//          LEVEL 11: Transactions
// ==========================================

#[NoReturn]
function level_11(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 9], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    if ($callback_query) handleTransactionsCallback($user, $message);
    if ($message) handleTransactionsTextMessage($user, $data, $message, $db);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        // Send Informative message
        sendAllTransactions($user, $db);
    }

    exit;
}

#[NoReturn]
function handleTransactionsCallback(User $user, array $message): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'];

    sendToTelegram('editMessageText', $data);
    exit;
}

#[NoReturn]
function handleTransactionsTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{

    $transaction = extractTransactionFromText($message['text']);
    if ($transaction) {

        $accounts = $db->read('accounts', ['user_id' => $user->getId()]);
        if ($accounts) {
            $data['text'] = "در متن ارسالی یک تراکنش پیدا شد. در صورت تمایل می‌توانید با انتخاب حساب مبدا/مقصد از دکمه‌های زیر، این تراکنش را برای حساب منتخب ذخیره کنید.\n";

            $data['text'] .= "\n" . 'بانک: ' . beautifulNumber($transaction['bank'], null);
            $data['text'] .= "\n" . 'مبلغ: ' . beautifulNumber($transaction['amount']);
            $data['text'] .= "\n" . 'نوع: ' . beautifulNumber($transaction['type'] == 'inward' ? 'واریز' : 'برداشت', null);
            $data['text'] .= "\n" . 'موجودی فعلی: ' . beautifulNumber($transaction['balance']);
            $data['text'] .= "\n" . 'تاریخ: ' . beautifulNumber($transaction['date']->format(), null);
            $data['text'] .= "\n" . 'ساعت: ' . beautifulNumber($transaction['time'], null);

            $inline_keyboard = [];
            foreach ($accounts as $account) {
                $button_text = '(' . beautifulNumber($account['type'], null) . ') ' . beautifulNumber($account['name'], null);
                $inline_keyboard[] = [
                    ['text' => $button_text, 'callback_data' => json_encode(['add_mssg_transaction' => $account['id']])]
                ];
            }

            $data['reply_markup'] = ['inline_keyboard' => $inline_keyboard];
        } else $data['text'] = 'پیام نامفهوم است!';

    } else $data['text'] = 'پیام نامفهوم است!';

    // Send default message of this level
    sendToTelegram('sendMessage', $data);
    exit;
}

function extractTransactionFromText(string $text): ?array
{
    // --- Bank Name ---
    preg_match('/بلو/u', $text, $bank); // Blu
    if ($bank) $bank = $bank[0];

    $transaction = [];
    if ($bank == 'بلو') {
        $transaction['bank'] = $bank;

        // --- Amount ---
        preg_match('/ (.+?) ریال به حساب شما نشست\./um', $text, $amount);
        if ($amount) {
            $amount = cleanAndValidateNumber(preg_replace('/\D+/', '', $amount[1]));
            $transaction['amount'] = $amount;
            $transaction['type'] = 'inward';
        } else {
            preg_match('/ (.+?) ریال از حساب شما پرید\./um', $text, $amount);
            if ($amount) {
                $amount = cleanAndValidateNumber(preg_replace('/\D+/', '', $amount[1]));
                $transaction['amount'] = $amount;
                $transaction['type'] = 'outward';
            } else return null;
        }

        // --- Balance ---
        preg_match('/موجودی: (.+?) ریال/um', $text, $balance);
        if ($balance) {
            $balance = cleanAndValidateNumber(preg_replace('/\D+/', '', $balance[1]));
            $transaction['balance'] = $balance;
        } else return null;

        // --- Date ---
        preg_match('/^(....)\.(..)\.(..)$/um', $text, $date);

        if ($date) {
            $year = cleanAndValidateNumber($date[1]);
            $month = cleanAndValidateNumber($date[2]);
            $day = cleanAndValidateNumber($date[3]);

            $date = $year . '/' . $month . '/' . $day;
            $date = JalaliDate::fromString($date);
            $transaction['date'] = $date;
        } else $transaction['date'] = JalaliDate::fromGregorian();

        // --- Time ---
        preg_match('/^(..):(..)$/um', $text, $time);
        if ($time) {
            $time = cleanAndValidateNumber($time[1]) . ':' . cleanAndValidateNumber($time[2]);
            $transaction['time'] = $time;
        } else $transaction['time'] = (new DateTime())->format('H:i');
    }
    return $transaction;
}

#[NoReturn]
function addTransaction(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    if ($message) {
//        preg_match('/^بانک: (.+?)$/um', $message['text'], $bank);
        preg_match('/^مبلغ: (.+?)$/um', $message['text'], $amount);
        preg_match('/^نوع: (.+?)$/um', $message['text'], $type);
        preg_match('/^موجودی فعلی: (.+?)$/um', $message['text'], $balance);
        preg_match('/^تاریخ: (.+?)$/um', $message['text'], $date);
        preg_match('/^ساعت: (.+?)$/um', $message['text'], $time);

        $transaction = [
//            'bank' => $bank[1],
            'amount' => cleanAndValidateNumber(str_replace(',', '', $amount[1])),
            'type' => $type[1] == 'واریز' ? 'inward' : 'outward',
            'balance' => cleanAndValidateNumber(str_replace(',', '', $balance[1])),
            'date' => JalaliDate::fromString(toEnglishDigits($date[1]))->toGregorian()->format('Y-m-d'),
            'time' => toEnglishDigits($time[1]),
        ];

        $result = $db->create('transactions', [
            'user_id' => $user->getId(),
            'account_id' => $callback_query['data']['add_mssg_transaction'],
            'type' => $transaction['type'],
            'date' => $transaction['date'],
            'time' => $transaction['time'],
            'amount' => $transaction['amount'],
        ]);

        if ($result) {
            $db->update('accounts', ['current_balance' => $transaction['balance']], ['id' => $callback_query['data']['add_mssg_transaction']]);
            $text = '✅ تراکنش جدید با موفقیت ثبت شد!';
        } else
            $text = '❌ خطای پایگاه داده در ثبت تراکنش جدید!';

        sendToTelegram('editMessageText', ['chat_id' => $user->getId(), 'message_id' => $message['message_id'], 'text' => $text]);
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    }
    exit;
}

#[NoReturn]
function sendAllTransactions(User $user, DatabaseManager $db): void
{
    $transactions = $db->read(
        table: 'transactions t',
        conditions: ['t.user_id' => $user->getId()],
        selectColumns: 't.*, a.name as account_name, a.type as account_type',
        join: 'join accounts a on a.id = t.account_id',
        orderBy: ['t.date' => 'ASC', 't.time' => 'ASC'],
        limit: 10,
    );
    if ($transactions) {
        $data['text'] = 'لیست تراکنش‌های شما:';
        $data['chat_id'] = $user->getId();

        foreach ($transactions as $transaction) {
            $data['text'] .= "\n" . ($transaction['type'] == 'outward' ? '📤 برداشت از: ' : '📥 واریز به: ') .
                beautifulNumber($transaction['account_name'], null) . ' (' . beautifulNumber($transaction['account_type'], null) . ')';
            $data['text'] .= "\n" . 'مبلغ: ' . beautifulNumber($transaction['amount']);
            $data['text'] .= "\n" . 'زمان: ' . beautifulNumber(JalaliDate::fromGregorianString($transaction['date'])->format(), null) . ' ' . beautifulNumber($transaction['time'], null);
        }

    } else {
        $data['text'] = 'شما هنوز تراکنشی ثبت نکرده‌اید!';
    }
    sendToTelegram('sendMessage', $data);
    exit;
}

// ==========================================
//          LEVEL S3: EMPTY LEVEL
// ==========================================

#[NoReturn]
function empty_level(
    User            $user,
    DatabaseManager $db,
    string|int      $parent_btn_id = 0, // Seems to be redundant but is required to avoid `null` progress bug
    ?array          $message = null,
): void
{
    $progress = $user->getProgress();

    if (!$progress) backButton($user, $db, $parent_btn_id);

    // Note: Text and keyboard must be initialized within progress handler
    $data = [
        'chat_id' => $user->getid(),
        'text' => &$text,
        'reply_markup' => [
            'keyboard' => [&$keyboard],
            'resize_keyboard' => true,
            'is_persistent' => true
        ]
    ];

    $parent_level = $progress['parent_btn'];
    $progress_data = $progress['data'];

    ##########################
    #    Progress handler    #
    ##########################

    if (array_key_first($progress_data) == 'set_alert') {

        // Create bottom keyboard with just cancel button
        $button = $db->read('buttons', ['id' => ['s1']], true);
        $keyboard[] = json_decode($button['attrs'], true);

        $asset_name = $progress_data['set_alert']['asset_name'];

        // Just entered the level
        // Ask user to give alert's target price
        if (!$message) {

            $asset = $db->read('assets', ['name' => $asset_name], true);

            $text = 'قیمتی که می‌خواهید برای آن هشدار تنظیم کنید را نوشته و ارسال کتید.';
            $text .= "\n";
            $text .= '*قیمت کنونی «' . markdownScape(beautifulNumber($asset_name, null)) . '»*: ';
            $text .= markdownScape(beautifulNumber($asset['price'])) . ' ' . markdownScape(beautifulNumber($asset['base_currency'], null));

            // Update user last button to current level (s3)
            $db->update('users', $user->setLastBtn('s3')->toDbArray(), ['id' => $user->getId()]);

        }

        // Received message (Supposed to be alert's target price)
        if ($message) {

            // Check if Received text is cancel button
            $pressed_button = $db->read('buttons', ['id' => 's1', 'attrs->>"$.text"' => $message['text']]);
            if ($pressed_button) backButton($user, $db, $parent_level);

            // Check if received text is a valid button
            $target_price = cleanAndValidateNumber($message['text']);
            if ($target_price) {

                // Read asset from database for price comparison
                $asset = $db->read('assets', ['name' => $asset_name], true);
                $price_diff = floatval($target_price) - floatval($asset['price']);
                $diff_percent = intval(($price_diff / floatval($asset['price'])) * 100);

                // Check if received price different from current price
                if ($price_diff != 0) {

                    date_default_timezone_set('Asia/Tehran');
                    $result = $db->upsert('alerts', [
                        'user_id' => $user->getId(),
                        'asset_name' => $asset_name,
                        'target_price' => $target_price,
                        'is_active' => true,
                        'created_date' => JalaliDate::fromGregorian()->format(),
                        'created_time' => date('H:i')
                    ]);
                    if ($result) {

                        $text = '✅ هشدار قیمت برای «' . beautifulNumber($asset['name'], null) . '» با موفقیت ثبت شد!';
                        $text .= "\n" . 'قیمت کنونی: ' . beautifulNumber($asset['price']);
                        $text .= "\n" . 'قیمت هشدار: ' . beautifulNumber($target_price);
                        $text .= "\n" . 'اختلاف قیمت: ' . ($price_diff > 0 ? '➕' : '➖');
                        $text .= ' ' . beautifulNumber(abs($price_diff));
                        $text .= ' (' . beautifulNumber($diff_percent) . '%)';

                    } else $text = '❌ خطای پایگاه داده!';

                    // Send success/failure message and go back to parent level
                    sendToTelegram('sendMessage', $data);
                    backButton($user, $db, $parent_level);

                } else // Send warning: Received number is the same as current price
                    $text = "قیمت هشدار نمی‌تواند با قیمت کنونی برابر باشد." . "\n" .
                        "قیمت دیگری بنویسید یا در صورت انصراف از دکمه لغو استفاده کنید.";

            } else // Send warning: Received text does not contain a valid number
                $text = "پیام نامفهوم بود." . "\n" .
                    "قیمت را به عدد بنویسید یا در صورت انصراف از دکمه لغو استفاده کنید.";
        }

        // Send default progress related text and bottom keyboard
        // Note: Entering level or Wrong number format reach here
        sendToTelegram('sendMessage', $data);
        exit;
    }
    exit;
}


// ==========================================
//          DATA HANDLING & UI HELPERS
// ==========================================

/**
 * Return a list of holdings (Or just one, if `Single == true`) containing `asset_name`,
 * `current_price`, `base_currency` and `exchange_rate` (Based on user's base currency).
 * 'date' column is also converted to Jalali string in 'yyyy/mm/dd' format.
 */
function getHoldingsWithAssetDetails(array $conditions, DatabaseManager $db, bool $single = false): bool|array
{
    $select_price = "select price from assets where assets.name";

    $asset_base = "a.base_currency";
    $asset_base_price = "$select_price = $asset_base";

    $user_base = "ifnull(json_unquote(json_extract(u.settings, '$.base_currency')), 'ریال')";
    $user_base_price = "$select_price = $user_base";

    $holdings = $db->read(
        table: 'holdings h',
        conditions: $conditions,
        single: $single,
        selectColumns: "
            h.*,
            a.name                                   as asset_name,
            a.price                                  as current_price,
            a.base_currency                          as base_currency,
            ($asset_base_price) / ($user_base_price) as exchange_rate",
        join: '
            LEFT JOIN assets a ON h.asset_id = a.id
            LEFT JOIN users u ON h.user_id = u.id'
    );

    if ($single) $holdings['date'] = JalaliDate::fromGregorianString($holdings['date'])->format();
    else foreach ($holdings as $holding) {
        $holding['date'] = JalaliDate::fromGregorianString($holding['date'])->format();
    }

    return $holdings;
}

/**
 * // TODO: Rewrite this for the new parameters
 * - Returns a list of all user's loans with related installments under `installments` key,
 *      sorted by remaining days to the next payment.
 * - All loans and installments dates are returned as string (Gregorian or Jalali).
 * - Each returned installment has an `is_due` boolean key.
 * - Each loan has a `next_payment` key, storing due date of the next installment in
 *      DateTime object or null if all the installments are due.
 * - Each loan as an `insts_summary` key storing the count and summation of
 *      paid, overdue and remaining installments for that loan.
 */
function getLoanWithInstallments(int|string $user_id, DatabaseManager $db, bool $jalali = false, int|string|null $loan_id = null, int|string|null $installment_id = null): bool|array
{
    if ($loan_id) $loan_select = "l.id = $loan_id and";
    elseif ($installment_id) $loan_select = "l.id = (select loan_id from installments where id = $installment_id) and";
    else $loan_select = '';

    $query = $db->query("
            select
                l.*,
                CONCAT('[',
                    GROUP_CONCAT(
                        JSON_OBJECT(
                            'id', i.id,
                            'loan_id', i.loan_id,
                            'amount', i.amount,
                            'due_date', i.due_date,
                            'alert_date', i.alert_date,
                            'is_paid', i.is_paid
                        ) ORDER BY due_date ASC
                    ),
                ']') AS installments
            from loans l
            LEFT JOIN installments i on i.loan_id = l.id
            where
                $loan_select
                l.user_id = $user_id
            group by l.id
            ");

    function prepareLoan(array $loan, bool $jalali): array
    {
        // Decode installments JSON into an array of installments
        $loan['installments'] = json_decode($loan['installments'], true);
        if ($loan['installments'][0]['id'] == null) $loan['installments'] = null;

        // Convert received date to Jalali
        if ($jalali) $loan['received_date'] = JalaliDate::fromGregorianString($loan['received_date'])->format();

        if ($loan['installments']) {
            $loan['next_payment'] = null;
            $loan['insts_summary']['paid_count'] = 0;
            $loan['insts_summary']['overdue_count'] = 0;
            $loan['insts_summary']['remaining_count'] = 0;
            $loan['insts_summary']['paid_sum'] = 0;
            $loan['insts_summary']['overdue_sum'] = 0;
            $loan['insts_summary']['remaining_sum'] = 0;
            foreach ($loan['installments'] as &$installment) {

                // Create `due_date` object just for calculations
                $due_date = DateTime::createFromFormat('Y-m-d', $installment['due_date']);

                // Create `is_due` and `is_paid` boolean values
                $is_due = boolval((new DateTime())->modify('-1 seconds')->diff($due_date)->invert);
                $is_paid = boolval($installment['is_paid']);

                // Calculate and add remaining days to next payment
                if ($loan['next_payment'] === null && !$is_due && !$is_paid)
                    $loan['next_payment'] = $due_date;

                // Initialize installments' summary
                if ($is_paid) $summary_key_word = 'paid';
                elseif ($is_due) $summary_key_word = 'overdue';
                else $summary_key_word = 'remaining';

                // Add installments' summary to loan object
                $loan['insts_summary'][$summary_key_word . '_count'] += 1;
                $loan['insts_summary'][$summary_key_word . '_sum'] += $installment['amount'];

                // Add `is_due` and `is_paid` to the installment
                $installment['is_due'] = $is_due;
                $installment['is_paid'] = $is_paid;

                // Change dates to Jalali string
                if ($jalali) {
                    $installment['due_date'] = JalaliDate::fromGregorianString($installment['due_date'])->format();
                    $installment['alert_date'] = JalaliDate::fromGregorianString($installment['alert_date'])->format();
                }
            }
        }
        return $loan;
    }

    if ($loan_id || $installment_id) {
        $loan = $query->fetch();
        if ($loan) $loan = prepareLoan($loan, $jalali);
        return $loan;
    } else {
        $loans = $query->fetchAll();
        if ($loans) foreach ($loans as &$loan) $loan = prepareLoan($loan, $jalali);
        usort($loans, function ($a, $b) {
            if ($a['next_payment'] == null) return 1;
            elseif ($b['next_payment'] == null) return -1;
            else return $a['next_payment']->diff(new DateTime())->days <=> $b['next_payment']->diff(new DateTime())->days;
        });
        return $loans;
    }
}

function createWebAppBtn(string $text, string $path, array $params = [], bool $add_api = false): array
{
    $url = BASE_URL . $path;
    if ($add_api) {
        $params['api_url'] = BASE_URL . '/api/ExternalConnections/api.php';
        $params['api_key'] = DB_API_SECRET;
    }

    return [
        'text' => $text,
        'web_app' => ['url' => $url . '?' . http_build_query($params)]
    ];
}

/**
 * Finds user's **active** live message in the database with `$message_id`
 * different from the one provided, and sends delete request to telegram.
 **/
function deleteOldActiveLiveMessage(User $user, int|string $message_id, DatabaseManager $db): bool|array
{
    $live_mssg = $db->read(
        table: 'special_messages',
        conditions: [
            'user_id' => $user->getId(),
            'type' => 'live_price',
            'is_active' => true,
            '!message_id' => $message_id,
        ],
        single: true
    );
    if ($live_mssg)
        return sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $live_mssg['message_id']]);
    else
        return false;
}

// ==========================================
//  TEXT FORMATTING AND MATHEMATICAL HELPERS
// ==========================================

function createHoldingDetailText(
    array   $holding,
    ?string $markdown = null,
    string  $user_base_currency = 'ریال',
    array   $attributes = [
        'space',
        'date',
        'org_amount',
        'org_price',
        'new_price',
        'org_total_price',
        'new_total_price',
        'space',
        'profit'
    ],
    ?string $holding_mssg_id = null,
    ?string $initial_mssg_id = null): string
{
    // Create tree view for each presented attribute
    $tree = '';
    foreach ($attributes as $attribute) {

        if ($attribute == 'space') {
            $tree .= "\n   │ " . "‏";
        }

        if ($attribute == 'date' && isset($holding['date'])) {
            $date = JalaliDate::fromString($holding['date'])->toPersianMonths();
            $tree .=
                "\n   ┤── تاریخ خرید: " .
                beautifulNumber("$date[day] $date[month] $date[year]", null);
        }

        if ($attribute == 'org_amount') {
            $tree .=
                "\n   ┤── مقدار / تعداد: " .
                beautifulNumber(floatval($holding['amount']));
        }

        if ($attribute == 'org_price') {
            $tree .=
                "\n   ┤── قیمت خرید هر واحد: " .
                beautifulNumber(floatval($holding['avg_price'])) . " " . $holding['base_currency'];
        }

        if ($attribute == 'new_price') {
            $tree .=
                "\n   ┤── قیمت لحظه‌ای هر واحد: " .
                beautifulNumber($holding['current_price']) . " " . $holding['base_currency'];
        }

        if ($attribute == 'org_total_price') {
            $tree .=
                "\n   ┤── قیمت خرید کل دارایی: " .
                beautifulNumber($holding['avg_price'] * $holding['amount']) . " " . $holding['base_currency'];
        }

        if ($attribute == 'new_total_price') {
            $tree .=
                "\n   ┤── قیمت لحظه‌ای کل دارایی: " .
                beautifulNumber($holding['current_price'] * $holding['amount']) . " " . $holding['base_currency'];
        }

        if ($attribute == 'profit') {

            // Calculate and create profit string
            $pro_los = calculateProLos($holding['avg_price'], $holding['current_price'], $holding['amount'], $holding['exchange_rate']);
            $pro_los_string =
                ($pro_los == 0) ?
                    "🟤 سود/زیان: ۰ " . $user_base_currency : (
                ($pro_los > 0) ?
                    "🟢 سود: " . beautifulNumber($pro_los) . ' ' . $user_base_currency :
                    "🔴 ضرر: " . beautifulNumber($pro_los) . ' ' . $user_base_currency
                );

            $tree .= "\n   ┘── " . $pro_los_string;
        }
    }

    // Manage deep-link and Markdown escaping
    if ($markdown === 'MarkdownV2') {

        $tree = markdownScape($tree);

        $asset_name = beautifulNumber(markdownScape($holding['asset_name']), null);
        $holding['asset_name'] = "[$asset_name](https://ble.ir/" . BOT_ID . "?start=viewHolding_holdingId{$holding['id']}" . ($holding_mssg_id ? "_holdingsMssgId" . $holding_mssg_id : '') . ($initial_mssg_id ? "_initMssgId" . $initial_mssg_id : '') . ")" . '‏';
    }

    return $holding['asset_name'] . $tree . "\n";
}

/**
 * Considerations for `$loans` array:
 *  -- Each loan must have all related installments
 *     under `installments` column.
 *  -- All dates (loans' received date and installments' due
 *     and alert date) must be in Jalali object.
 *  -- Installments must be sorted ascending by their due date.
 *  -- Installments must have 'is_due' bool value.
 */
function createLoansView(array $loans, ?string $loans_mssg_id = null, ?string $initial_mssg_id = null, bool $summerized = true): string
{
    $text = 'وام‌های ثبت شده‌ی شما: ' . "\n";
    foreach ($loans as $loan) {

        $installments = &$loan['installments'];
        if ($installments) {

            // Create payment status icon for the installment
            $insts_per_year = [];
            $summerized_insts_text = '‏';
            foreach ($installments as $installment) {

                if ($summerized && $installment['is_paid']) $summerized_insts_text .= "🟢";
                if ($summerized && !$installment['is_paid']) $summerized_insts_text .= $installment['is_due'] ? "🔴" : "⚪";

                $due_year = JalaliDate::fromString($installment['due_date'])->jy;
                if (!$summerized && $installment['is_paid']) $insts_per_year[$due_year][] = "🟢";
                if (!$summerized && !$installment['is_paid']) $insts_per_year[$due_year][] = $installment['is_due'] ? "🔴" : "⚪";
            }

            $last_year = array_key_last($insts_per_year);
            $installments_detail = "\n‏      ┘─ وضعیت اقساط\: ";
            foreach ($insts_per_year as $year => $year_installments) {
                $prefix = ($year != $last_year) ?
                    "\n‏          ┤─ " :
                    "\n‏          ┘─ ";
                $installments_detail .= $prefix . beautifulNumber($year, null) . '\: ' . implode('', $year_installments);
            }

        } else {
            $installments_detail = '';
            $summerized_insts_text = '';
        }

        $deep_link = "https://ble.ir/" . BOT_ID . "?start=showLoan_loanId{$loan['id']}" . ($loans_mssg_id ? "_loansMssgId" . $loans_mssg_id : '') . ($initial_mssg_id ? "_initMssgId" . $initial_mssg_id : '');
        $loan_name = "\n‏" . "\-* [" . beautifulNumber($loan['name'], null) . "]($deep_link)*";

        if (isset($loan['next_payment'])) {
            $remaining_days = $loan['next_payment']->diff((new DateTime())->modify('-2 seconds'))->days;
            $next_payment_text = beautifulNumber($remaining_days . ' روز دیگر در ' . JalaliDate::fromGregorianObject($loan['next_payment'])->format(), null);

        } else $next_payment_text = 'پایان یافته';

        if (!$summerized) {
            $detail =
                "\n‏      │  " .
                "\n‏      ┤─ " . 'مبلغ وام: ' . beautifulNumber($loan['total_amount']) .
                "\n‏      ┤─ " . 'تاریخ دریافت: ' . beautifulNumber($loan['received_date'], null) .
                "\n‏      ┤─ " . 'قسط بعدی: ' . $next_payment_text;

            $detail .= $installments_detail . "\n";
        } else
            $detail = ': ' . $next_payment_text . "\n" . $summerized_insts_text . "\n";

        $text .= $loan_name . markdownScape($detail);
    }
    return $text;
}

/**
 * Considerations for `$loan` array:
 *  -- It must have all related installments
 *     under `installments` key.
 *  -- All dates (loan's received date and installments' due
 *     and alert date) must be in Jalali string.
 *  -- Installments must be sorted ascending by their due date.
 *  -- Installments must have 'is_due' bool value.
 * TODO: Rewrite for the new parameters
 */
function createLoanDetailText(array $loan, ?string $markdown = null, ?string $mssg_id = null): string
{
    $installments = &$loan['installments'];
    if ($installments) {

        $installments_text = '';
        foreach ($installments as $i => $installment) {

            // Create payment status emoji
            if ($installment['is_paid']) $payment_emoji = "🟢";
            elseif ($installment['is_due']) $payment_emoji = "🔴";
            else $payment_emoji = "⚪";

            // Create installment text
            $inst_num = beautifulNumber(intval($i) + 1, null);
            $date = beautifulNumber($installment['due_date'], null);
            $amount = beautifulNumber($installment['amount']);

            if ($markdown) {
                $link = "https://ble.ir/" . BOT_ID . "?start=toggleInstPayment_instId$installment[id]_mssgId$mssg_id";
                $installments_text .= "\n" . '‏' . '    ' . markdownScape($inst_num) . "\) [$payment_emoji]($link)  " . markdownScape($date) . ':  ' . markdownScape($amount);
            } else
                $installments_text .= "\n" . '‏' . "    $inst_num) $payment_emoji  $date:  $amount";

        }

        if ($markdown)
            $text = "‏*" . markdownScape($loan['name']) . "*:\n" .
                "\n مبلغ وام\: " . markdownScape(beautifulNumber($loan['total_amount'])) .
                "\n تاریخ دریافت\: " . markdownScape(beautifulNumber($loan['received_date'], null)) .
                "\n کل بازپرداخت\: " . markdownScape(beautifulNumber(array_sum(array_column($installments, 'amount')))) .
                "\n " . markdownScape(beautifulNumber($loan['insts_summary']['paid_count']) . " قسط پرداخت‌شده، معادل " . beautifulNumber($loan['insts_summary']['paid_sum'])) .
                "\n " . markdownScape(beautifulNumber($loan['insts_summary']['remaining_count']) . " قسط باقی مانده، معادل " . beautifulNumber($loan['insts_summary']['remaining_sum'])) .
                "\n " . markdownScape(beautifulNumber($loan['insts_summary']['overdue_count']) . " قسط معوقه، معادل " . beautifulNumber($loan['insts_summary']['overdue_sum'])) .
                "\n جزئیات اقساط\: ";
        else
            $text = "‏*" . $loan['name'] . "*:\n" .
                "\n مبلغ وام\: " . beautifulNumber($loan['total_amount']) .
                "\n تاریخ دریافت\: " . beautifulNumber($loan['received_date'], null) .
                "\n کل بازپرداخت\: " . beautifulNumber(array_sum(array_column($installments, 'amount'))) .
                "\n " . beautifulNumber($loan['insts_summary']['paid_count']) . " قسط پرداخت‌شده، معادل " . beautifulNumber($loan['insts_summary']['paid_sum']) .
                "\n " . beautifulNumber($loan['insts_summary']['remaining_count']) . " قسط باقی مانده، معادل " . beautifulNumber($loan['insts_summary']['remaining_sum']) .
                "\n " . beautifulNumber($loan['insts_summary']['overdue_count']) . " قسط معوقه، معادل " . beautifulNumber($loan['insts_summary']['overdue_sum']) .
                "\n جزئیات اقساط\: ";

        $text .= $installments_text;

    } else $text = 'هیچ قسطی برای این وام ثبت نشده است!';

    return $text;
}

function createLoanDetailKeyboard(array $loan): array
{
    $keyboard = [];
    $keyboard_row = [];
    $btn_in_row = 2;
    foreach ($loan['installments'] as $installment) {
        $due_date = JalaliDate::fromString($installment['due_date'])->format();
        if ($installment['is_paid']) $payment_icon = '🟢';
        elseif ($installment['is_due']) $payment_icon = '🔴';
        else $payment_icon = '⚪';
        $keyboard_row[] = [
            'text' => $payment_icon . ' ' . beautifulNumber($due_date, null),
            'callback_data' => json_encode(['inplace_inst_pay_toggle' => $installment['id']])
        ];

        if (sizeof($keyboard_row) >= $btn_in_row) {
            $keyboard[] = $keyboard_row;
            $keyboard_row = [];
        }
    }

    if ($keyboard_row) $keyboard[] = $keyboard_row;
    $keyboard[] = [['text' => 'برگشت به لیست وام‌ها', 'callback_data' => json_encode(['loans_list' => null])]];

    return $keyboard;

}

function calculateProLos(float $p1, float $p2, float $amount = 1, float $conversion_rate = 1): float
{
    $total_price_def = $amount * ($p2 - $p1);
    return $total_price_def * $conversion_rate;
}

/**
 * @param array $asset_names
 * @param DatabaseManager $db
 * @return array
 */
function CreateNamePricePairs(array $asset_names, DatabaseManager $db): array
{
    // Read prices for all base currencies
    $base_prices = $db->read('assets', ['name' => $asset_names]);
    // Create an array of [$name => $price] pairs
    return array_combine(
        array_column($base_prices, 'name'),
        array_map('floatval', array_column($base_prices, 'price'))
    );
}

/**
 * @param array $assets Array of assets
 * @param array $base_prices
 * @param string $user_base_currency
 * @return string
 */
function createPricesTextForSingleAssetType(array $assets, array $base_prices, string $user_base_currency): string
{
    $date = preg_split('/-/u', $assets[0]['date']);
    $date[1] = str_replace(
        ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
        ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
        $date[1]
    );

    $text = "آخرین قیمت ها در $date[2] $date[1] $date[0] ساعت " . $assets[0]['time'] . "\n";
    $text = beautifulNumber($text, null);

    // Create price texts and add them to the text
    foreach ($assets as $asset) {
        $asset_price = beautifulNumber($asset['price']);
        $asset_name = beautifulNumber($asset['name'], null);
        $asset_base_currency = beautifulNumber($asset['base_currency'], null);
        $text .= "\n$asset_name: $asset_price $asset_base_currency";

        if (
            $asset['base_currency'] != $user_base_currency &&
            $base_prices[$user_base_currency]
        ) {
            $exchange_rate = $base_prices[$asset['base_currency']] / $base_prices[$user_base_currency];
            $based_price = $asset['price'] * $exchange_rate;
            $text .= ' --> ' . beautifulNumber($based_price) . ' ' . $user_base_currency;
        }
    }
    return $text;
}
