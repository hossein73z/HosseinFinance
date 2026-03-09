<?php

// DON'T COMMIT!
// Code for testing on KSWEB
putenv('DB_HOST=localhost');
putenv('DB_PORT=3306');
putenv('DB_NAME=hossein_finance');
putenv('DB_USER=hossein');
putenv('DB_PASS=H457869z');

putenv('SHARED_SECRET=0iNaaYhna2ilf0fY');
putenv('BOT_ID=HosseinFinance_bot');
putenv('MAIN_BOT_TOKEN=1797658259:sGXRQN3Hwkj79PCWjDnCFt9W072q-2OljYo');
putenv('MAJID_API_TOKEN=1');

// Load core configuration and constants.
use JetBrains\PhpStorm\NoReturn;

// --- VERCEL CONFIGURATION ---
define('SHARED_SECRET', getenv('SHARED_SECRET'));
define('BOT_ID', getenv('BOT_ID'));
define('MAIN_BOT_TOKEN', getenv('MAIN_BOT_TOKEN'));
define('MAJID_API_TOKEN', getenv('MAJID_API_TOKEN'));

// Database Constants (Required for DatabaseManager)
define('DB_HOST', getenv('DB_HOST'));
define('DB_PORT', getenv('DB_PORT'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('DB_API_SECRET', getenv('DB_API_SECRET'));

// Base URL for Web Apps
define('BASE_URL', 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL'));
// ----------------------------

// Load necessary files
require_once 'Libraries/DatabaseManager.php';
require_once 'Functions/ExternalEndpointsFunctions.php';
require_once 'Functions/KeyboardFunctions.php';
require_once 'Functions/StringHelper.php'; // TODO: Create object for Jalali date
require_once 'Models/Button.php';
require_once 'Models/User.php';

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
    exit();
}

$update = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("[ERROR] Invalid JSON received: " . json_last_error_msg());
    exit();
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
    exit();
}

// --- MAIN UPDATE ROUTER ---

if (isset($update['message'])) {
    handleIncomingMessage($update['message'], $db);
} elseif (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query'], $db);
} else {
    error_log("[INFO] Unhandled update type received.");
}

DatabaseManager::closeConnection();
exit();


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
    $user = getOrCreateUser($message['chat'], $db);

    // Global Command Routing
    $text = $message['text'] ?? '';
    if ($text === '/holdings')
        level_1($user, $db);
    if ($text === '/loans')
        level_2($user, $db);
    if ($text === '/prices')
        level_5($user, $db);
    if ($text === '/ai')
        level_6($user, $db);

    $pressed_button = getPressedButton(text: $text, parent_btn_id: $user->getLastBtn(), admin: $user->isAdmin(), db: $db);

    choosePath(pressed_button: $pressed_button, message: $message, user: $user, db: $db);
}

/**
 * Handles inline button presses.
 */
function handleCallbackQuery(array $callback_query, DatabaseManager $db): void
{
    $message = $callback_query['message'];
    $user = $db->read(
        table: 'users',
        conditions: ['chat_id' => $message['chat']['id']],
        single: true);

    if ($user !== false) {
        choosePath(message: $message, user: User::fromDbRow($user), callback_query: $callback_query, db: $db);
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
function getOrCreateUser(array $chat, DatabaseManager $db): User
{
    $user = $db->read(
        table: 'users',
        conditions: ['chat_id' => $chat['id']],
        single: true);

    if (!$user) {
        $admins = $db->read(
            table: 'users',
            conditions: ['is_admin' => 1]);
        $new_user_id = $db->create(
            table: 'users',
            data: [
                'chat_id' => $chat['id'],
                'first_name' => $chat['first_name'] ?? 'N/A',
                'last_name' => $chat['last_name'] ?? null,
                'username' => $chat['username'] ?? null,
                'progress' => null,
                'is_admin' => ($admins) ? 0 : 1, // First user is admin
                'last_btn' => 0
            ]);

        if ($new_user_id) {
            $user = $db->read(
                table: 'users',
                conditions: ['chat_id' => $chat['id']],
                single: true
            );
        } else {
            error_log("[ERROR] Failed to create new user: " . $chat['id']);
            exit();
        }
    }
    return User::fromDbRow($user);
}

#[NoReturn]
function callbackHandler(User $user, array $callback_query, DatabaseManager $db): void
{
    // TODO: Callback queries move user to corresponding level instead of removing the message

    $message = $callback_query['message'];

    if ($user->getLastBtn() == 1) level_1(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getLastBtn() == 2) level_2(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getLastBtn() == 5) level_5(user: $user, db: $db, message: $message, callback_query: $callback_query);
    if ($user->getLastBtn() == 6) level_6(user: $user, db: $db, message: $message, callback_query: $callback_query);

    // Fallback if not handled
    sendToTelegram('editMessageText', [
        'text' => 'درخواست نامفهوم بود!',
        'message_id' => $message['message_id'],
        'chat_id' => $user->getChatId(),
    ]);

    exit();
}

#[NoReturn]
function specialButtonHandler(User $user, Button $pressed_button, DatabaseManager $db): void
{
    if ($pressed_button->getId() === "s0") backButton($user, $db);
    if ($pressed_button->getId() === "s1") cancelButton($user, $db);
    if ($pressed_button->getId() === "s2") sendFavorites($user, $db);
    exit();
}

#[NoReturn]
function normalButtonHandler(User $user, Button $pressed_button, DatabaseManager $db): void
{
    // Route the button to corresponding level
    if ($pressed_button->getId() == 1) level_1(user: $user, db: $db, pressed_button: $pressed_button);
    if ($pressed_button->getId() == 2) level_2(user: $user, db: $db, pressed_button: $pressed_button);
    if ($pressed_button->getId() == 5) level_5(user: $user, db: $db, pressed_button: $pressed_button);
    if ($pressed_button->getId() == 6) level_6(user: $user, db: $db);

    // Default Actions for normal button
    $response = sendToTelegram('sendMessage', [
        'text' => $pressed_button->getText(),
        'chat_id' => $user->getChatId(),
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

    exit();
}

#[NoReturn]
function nonButtonHandler(User $user, array $message, DatabaseManager $db): void
{
    // TODO: Misplaced deep links move user to the corresponding level instead of showing error

    if ($user->getLastBtn() == 1) level_1(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == 2) level_2(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == 5) level_5(user: $user, db: $db, message: $message);
    if ($user->getLastBtn() == 6) level_6(user: $user, db: $db, message: $message);

    // Fallback "Unrecognized" message
    sendToTelegram('sendMessage', [
        'text' => 'پیام نامفهوم است!',
        'chat_id' => $user->getChatId(),
        'reply_markup' => [
            'keyboard' => createKeyboardsArray($user->getLastBtn(), $user->isAdmin(), $db),
            'resize_keyboard' => true,
            'is_persistent' => true,
        ]
    ]);

    exit();
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

/**
 * Logic for 'Back' button.
 * IMPORTANT: Needs modifications in case of multistep progress
 */
#[NoReturn]
function backButton(User $user, DatabaseManager $db): void
{
    $progress = $user->getProgress() ? json_decode($user->getProgress(), true) : null;
    $current_level = $db->read(
        table: 'buttons',
        conditions: ['id' => $user->getLastBtn()],
        single: true
    );
    $current_btn = Button::fromDbRow($current_level);

    if ($progress) {
        $user->setProgress(null);
        normalButtonHandler(user: $user, pressed_button: $current_btn, db: $db);
    } else {
        $last_level = $db->read(
            table: 'buttons',
            conditions: ['id' => $current_level['belong_to']],
            single: true
        );

        $last_btn = Button::fromDbRow($last_level);
        $user->setProgress(null);
        normalButtonHandler(user: $user, pressed_button: $last_btn, db: $db);
    }
}

/**
 * Logic for 'Cancel' button.
 */
#[NoReturn]
function cancelButton(User $user, $db): void
{
    $user->setProgress(null);
    backButton($user, $db);
}


// ==========================================
//          LEVEL 1: HOLDINGS
// ==========================================

#[NoReturn]
function level_1(
    User            $user,
    DatabaseManager $db,
    ?Button         $pressed_button = null,
    ?array          $message = null,
    ?array          $callback_query = null): void
{

    // Initialize button object if null is given
    $current_button = $pressed_button ?? Button::fromDbRow($db->read('buttons', ['id' => 1], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $current_button->getId(), admin: $user->isAdmin(), db: $db);

    // Add '➕ افزودن دارایی جدید' button to the keyboard
//    array_unshift($keyboard, [createWebAppBtn('➕ افزودن دارایی جدید', '/assets/add_holding.html')]);

    $data = [
        'chat_id' => $user->getChatId(),
        'text' => $current_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $current_button->getText()
        ]
    ];

    if ($pressed_button) {
        $response = sendToTelegram('sendMessage', $data);
        if ($response) {
            $db->update(
                table: 'users',
                data: [
                    'last_btn' => $pressed_button->getId(),
                    'progress' => null,
                ],
                conditions: ['id' => $user->getId()]
            );
            sendAllHoldings($user, $db);
        }
        exit();
    }

    if ($callback_query) handleHoldingsCallback($user, $callback_query, $message);
    if ($message && isset($message['web_app_data'])) handleHoldingsWebAppData($user, $data, $message, $db);
    if ($message && !isset($message['web_app_data'])) handleHoldingsTextMessage($user, $data, $message, $db);
    exit();
}

#[NoReturn]
function handleHoldingsCallback(User $user, array $callback_query, array $message): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    // $query_data = json_decode($callback_query['data'], true);

    sendToTelegram('editMessageText', [
        'chat_id' => $user->getChatId(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ]);
    exit();
}

#[NoReturn]
function handleHoldingsWebAppData(User $user, array $data, array $message, DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);
    $action = $web_app_data['action'] ?? null;

    $correct_data = false;

    if ($action === 'add') {

        $new_holding = $web_app_data['holding'];
        try {
            $db->create(
                table: 'holdings',
                data: [
                    "user_id" => $user->getId(),
                    "asset_id" => $new_holding["asset_id"],
                    "amount" => $new_holding["amount"],
                    "avg_price" => $new_holding["avg_price"],
                    "date" => $new_holding["date"],
                    "time" => $new_holding["time"],
                    "note" => $new_holding["note"],
                ]
            );
            $data['text'] = '✅ دارایی جدید با موفقیت ثبت شد.';

        } catch (PDOException $e) {

            if ($e->errorInfo[1] == 1062) {
                /*
                 * Duplicate Entry.
                 *
                 * Sends the informative message, redirects user
                 * to the existing holding and breaks the process.
                 */

                $data['text'] = ' ' .
                    'شما از قبل این دارایی را در سیستم ثبت کرده اید.' . "\n" .
                    'درصورت تمایل برای ثبت تغییرات، دارایی ثبت شده را ویرایش کنید.';
                sendToTelegram('sendMessage', $data);

                $holding = getHoldingsWithAssetDetails(['h.asset_id' => $new_holding["asset_id"], 'h.user_id' => $user->getId()], $db, true);
                if ($holding) {
                    $db->update(
                        table: 'users',
                        data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                        conditions: ['id' => $user->getId()]
                    );
                    sendHoldingDetail($holding, $data);
                }
                exit();
            }

            error_log('Holding: ' . json_encode($new_holding) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->errorInfo[2];
        }
        $correct_data = true;
    }
    if ($action === 'edit') {

        try {
            $db->update(
                table: 'holdings',
                data: $web_app_data['updates'],
                conditions: ['id' => $web_app_data['id']]
            );
            $data['text'] = '✅ دارایی با موفقیت ویرایش ثبت شد.';

        } catch (PDOException $e) {
            error_log('Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->errorInfo[2];
        }
        $correct_data = true;
    }
    if ($action === 'delete') {

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
        $correct_data = true;
    }

    if ($correct_data) {
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
    exit();
}

#[NoReturn]
function handleHoldingsTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{

    // Check for supported deep-link(s)
    $matched = preg_match('/^\/start viewHolding_holdingId(\d+)(_mssgId(\d+))?$/m', $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        $holding_id = $matches[1];

        $holding = getHoldingsWithAssetDetails(['h.id' => $holding_id, 'h.user_id' => $user->getId()], $db, true);
        if ($holding) {

            // Delete received deep-link message
            sendToTelegram('deleteMessage', ['chat_id' => $user->getChatId(), 'message_id' => $message['message_id']]);
            // Delete holdings' message
            sendToTelegram('deleteMessage', ['chat_id' => $user->getChatId(), 'message_id' => $matches[3]]);

            sendHoldingDetail($holding, $data);
            $db->update(
                table: 'users',
                data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                conditions: ['id' => $user->getId()]
            );
            exit();

        } else $data['text'] = 'دارایی با این مشخصه یافت نشد!';
    } else $data['text'] = 'پیام نامفهوم است!';

    // Only irreverent texts and deep-links with wrong holding id reach here.
    $data = checkAndAddEditHoldingButton($data, $user, $db);
    sendToTelegram('sendMessage', $data);
    exit();
}

function sendAllHoldings(User $user, DatabaseManager $db): void
{
    $holdings = getHoldingsWithAssetDetails(['user_id' => $user->getId()], $db);
    if ($holdings) {
        $temp_mssg = sendLoadingMessage($user->getChatId(), 'در حال دریافت اطلاعات دارایی‌ها ...');
        if ($temp_mssg) {

            $text = "دارایی‌های ثبت شده‌ی شما:\n";
            $total_pro_los = 0;
            foreach ($holdings as $holding) {
                $total_pro_los += $holding['amount'] * ($holding['current_price'] - $holding['avg_price']) * $holding['base_rate'];
                $text .= "\n" . createHoldingDetailText($holding, 'MarkdownV2', ['space', 'org_amount', 'org_total_price', 'space', 'profit'], $temp_mssg['result']['message_id']);
            }

            $pro_los_string =
                ($total_pro_los == 0) ?
                    "🟤 جمع سود/زیان: ۰ ریال" : (
                ($total_pro_los > 0) ?
                    "🟢 جمع سود: " . beautifulNumber($total_pro_los) . " ریال" :
                    "🔴 جمع ضرر: " . beautifulNumber($total_pro_los) . " ریال"
                );
            $text .= "\n" . markdownScape($pro_los_string);

            sendToTelegram('editMessageText', [
                'chat_id' => $user->getChatId(),
                'message_id' => $temp_mssg['result']['message_id'],
                'text' => $text,
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $user->getChatId(), 'text' => 'شما هیچ دارایی‌ای ثبت نکرده‌اید.']);
    }
}

function sendHoldingDetail(array $holding, array $data): void
{
    $data['text'] = createHoldingDetailText($holding);
//    array_unshift($data['reply_markup']['keyboard'], [
//        createWebAppBtn('✏ ویرایش ' . beautifulNumber($holding['asset_name'], null), '/assets/add_holding.html', ['data' => base64_encode(json_encode($holding))])
//    ]);

    sendToTelegram('sendMessage', $data);
}

function checkAndAddEditHoldingButton(array $data, User $user, DatabaseManager $db): array
{
    $progress = json_decode($user->getProgress(), true);
    if ($progress && key($progress) === 'view_holding') {
        $holding = getHoldingsWithAssetDetails(['h.id' => $progress['view_holding']['holding_id'], 'h.user_id' => $user->getId()], $db, true);
        if ($holding) {
//            array_unshift($data['reply_markup']['keyboard'], [
//                createWebAppBtn(
//                    text: '✏ ویرایش ' . $holding['asset_name'],
//                    path: '/assets/add_holding.html',
//                    params: ['data' => base64_encode(json_encode($holding))])
//            ]);
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
    ?Button         $pressed_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void
{
    // Initialize button object if null is given
    $current_button = $pressed_button ?? Button::fromDbRow($db->read('buttons', ['id' => 2], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $current_button->getId(), admin: $user->isAdmin(), db: $db);

    // Add '➕ افزودن وام جدید' button to the keyboard
//    array_unshift($keyboard, [createWebAppBtn('➕ افزودن وام جدید', '/assets/add_loan.html')]);

    $data = [
        'chat_id' => $user->getChatId(),
        'text' => $current_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $current_button->getText()
        ]
    ];

    if ($pressed_button) {
        // Send initial message
        $response = sendToTelegram('sendMessage', $data);
        if ($response) {
            $db->update(
                table: 'users',
                data: [
                    'last_btn' => $current_button->getId(),
                    'progress' => null,
                ],
                conditions: ['id' => $user->getId()]
            );

            // Send informative message
            sendAllLoans($user, $db);
        }
        exit();
    }

    if ($callback_query)
        handleLoansCallback($user, $callback_query, $data, $message, $db);

    if ($message && isset($message['web_app_data']))
        handleLoansWebAppData($user, $data, $message, $db);
    if ($message && !isset($message['web_app_data']))
        handleLoansTextMessage($user, $data, $message, $db);
    exit();
}

#[NoReturn]
function handleLoansCallback(User $user, array $callback_query, array $data, array $message, $db): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

    $query_data = json_decode($callback_query['data'], true);
    if (!$query_data) exit();

    $query_key = array_key_first($query_data);
    $data['message_id'] = $message['message_id'];

    switch ($query_key) {
        case 'loan_list':
            sendToTelegram('deleteMessage', ['chat_id' => $user->getChatId(), 'message_id' => $message['message_id']]);

            sendToTelegram('sendMessage', $data);
            sendAllLoans($user, $db);
            break;
        default:

            sendToTelegram('editMessageText', [
                'chat_id' => $user->getChatId(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            break;
    }
    exit();
}

#[NoReturn]
function handleLoansWebAppData(User $user, array $data, array $message, DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);
    // Add new loan and installments
    if (isset($web_app_data['loans']) &&
        isset($web_app_data['installments'])) {

        $new_loan = $web_app_data['loans'];
        try {

            $loan_id = $db->create(
                table: 'loans',
                data: [
                    'user_id' => $user->getId(),
                    'name' => $new_loan['name'],
                    'total_amount' => $new_loan['total_amount'],
                    'received_date' => $new_loan['received_date'],
                    'alert_offset' => $new_loan['alert_offset'],
                ]);

            $count = 0;
            foreach ($web_app_data['installments'] as $inst) {
                try {
                    $db->create(
                        table: 'installments',
                        data: [
                            'loan_id' => $loan_id,
                            'amount' => $inst['amount'],
                            'due_date' => $inst['due_date'],
                            'is_paid' => $inst['is_paid'] ? 1 : 0
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
                'chat_id' => $user->getChatId()
            ]);
            error_log(
                'Loan: ' . json_encode($new_loan) . "\n" .
                'Error: ' . $e->getMessage());
        }
        sendAllLoans($user, $db);
        exit();

    }

    // Edit existing loan and related installments
    if (isset($web_app_data['id']) &&
        isset($web_app_data['updates'])) {

        $new_insts = $web_app_data['updates']['installments'] ?? null;
        unset($web_app_data['updates']['installments']);
        $data['text'] = "نتیجه ویرایش وام: ";

        try {
            // Update the loan
            $db->update(
                table: 'loans',
                data: $web_app_data['updates'],
                conditions: ['id' => $web_app_data['id'], 'user_id' => $user->getId()]);
            $data['text'] .= "\nویرایش اطلاعات وام: ✅";

        } catch (Exception $e) {
            $data['text'] .= "\nویرایش اطلاعات وام: ❌";
            error_log(
                'Loan Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . $e->getMessage()
            );
        }

        if ($new_insts) {

            // Add 'loan_id' to installments
            foreach ($new_insts as &$new_inst)
                $new_inst['loan_id'] = $web_app_data['id'];

            // Update the Existing installments, based on their dates
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
        exit();

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
        exit();
    }

    $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
    sendToTelegram('sendMessage', $data);

    sendAllLoans($user, $db);
    exit();

}

#[NoReturn]
function handleLoansTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{
    sendToTelegram('deleteMessage', ['chat_id' => $user->getChatId(), 'message_id' => $message['message_id']]);

    // Show loan detail
    $matched = preg_match("/^\/start showLoan_loanId(\d+?)_mssgId(\d+?)$/m", $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        $loan = getLoansWithInstallments(['l.id' => $matches[1], 'l.user_id' => $user->getId()], $db)[0];

        if ($loan) {

            // Delete loans message
            sendToTelegram('deleteMessage', ['chat_id' => $user->getChatId(), 'message_id' => $matches[2]]);

            sendLoanDetail($loan, $data);

            $db->update(
                table: 'users',
                data: ['progress' => json_encode(['view_loan' => ['loan_id' => $loan['id']]])],
                conditions: ['id' => $user->getId()]
            );
            exit();
        }
    }

    // Toggle Installment Payment
    $matched = preg_match("/^\/start toggleInstPayment_instId(\d+?)_mssgId(\d+?)$/m", $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        $installment = $db->read(
            table: 'installments i',
            conditions: ['i.id' => $matches[1], 'l.user_id' => $user->getId()],
            single: true,
            selectColumns: 'i.*, l.user_id',
            join: 'LEFT JOIN loans l ON i.loan_id = l.id'
        );

        if ($installment) {
            $db->update(
                table: 'installments',
                data: ['is_paid' => !$installment['is_paid']],
                conditions: ['id' => $installment['id']]
            );

            $loan = getLoansWithInstallments(['l.id' => $installment['loan_id'], 'l.user_id' => $user->getId()], $db)[0];

            if ($loan) {
                sendToTelegram('editMessageText', [
                    'chat_id' => $user->getChatId(),
                    'message_id' => $matches[2],
                    'text' => createLoanDetailText($loan, $matches[2]),
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => ['inline_keyboard' => [[['text' => 'برگشت به لیست وام‌ها', 'callback_data' => json_encode(['loan_list' => null])]]]]
                ]);
            }
            exit();

        }
    }


    // Add '✏ ویرایش' button to the keyboard if use is viewing a loan.
    // This works with irreverent texts and wrong loan or installment id.
    $progress = json_decode($user->getProgress(), true);
    if ($progress && key($progress) === 'view_loan') {
        $loan = getLoansWithInstallments(['l.id' => $progress['view_loan']['loan_id'], 'l.user_id' => $user->getId()], $db)[0];
        if ($loan) {
//            array_unshift($data['reply_markup']['keyboard'], [
//                createWebAppBtn(
//                    text: '✏ ویرایش وام «' . $loan['name'] . '»',
//                    path: '/assets/add_loan.html',
//                    params: ['data' => base64_encode(json_encode($loan))])
//            ]);
        }
    }

    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit();

}

function sendAllLoans(User $user, DatabaseManager $db): void
{
    $loans = getLoansWithInstallments(['l.user_id' => $user->getId()], $db);

    if ($loans) {

        $temp_mssg = sendLoadingMessage($user->getChatId(), 'در حال دریافت اطلاعات وام‌ها ...');
        if ($temp_mssg) {
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getChatId(),
                'message_id' => $temp_mssg['result']['message_id'],
                'text' => createLoansView($loans, $temp_mssg['result']['message_id']),
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $user->getChatId(), 'text' => 'هیچ وام یا قسطی برای شما ثبت نشده است!']);
    }

    $db->update('users', ['progress' => null], ['id' => $user->getId()]);
}

function sendLoanDetail(array $loan, array $data): void
{

    $data['text'] = 'جزئیات وام «' . $loan['name'] . '»';
//    array_unshift($data['reply_markup']['keyboard'], [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/add_loan.html', ['data' => base64_encode(json_encode($loan))])]);

    sendToTelegram('sendMessage', $data);

    $temp_mssg = sendLoadingMessage($data['chat_id'], 'در حال دریافت اطلاعات اقساط ...');
    if ($temp_mssg) {

        $data['message_id'] = $temp_mssg['result']['message_id'];
        $data['text'] = createLoanDetailText($loan, $temp_mssg['result']['message_id']);
        $data['parse_mode'] = 'MarkdownV2';
        $data['reply_markup'] = ['inline_keyboard' => [[['text' => 'برگشت به لیست وام‌ها', 'callback_data' => json_encode(['loan_list' => null])]]]];

        sendToTelegram('editMessageText', $data);
    }
}

// ==========================================
//          LEVEL 5: PRICES
// ==========================================

#[NoReturn]
function level_5(
    User            $user,
    DatabaseManager $db,
    ?Button         $pressed_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void
{
    // Initialize button object if null is given
    $current_button = $pressed_button ?? Button::fromDbRow($db->read('buttons', ['id' => 5], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $current_button->getId(), admin: $user->isAdmin(), db: $db);

    $data = [
        'chat_id' => $user->getChatId(),
        'text' => $current_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $current_button->getText()
        ]
    ];

    $asset_types = $db->read(
        table: 'assets',
        selectColumns: 'asset_type',
        distinct: true,
        orderBy: ['asset_type' => 'DESC']
    );
    if (!$asset_types) {

        $data['text'] = 'دسته‌بندی‌ای در سیستم یافت نشد!';
        sendToTelegram('sendMessage', $data);
        exit();

    } else $asset_types = array_column($asset_types, 'asset_type');

    // Add asset types to level 5 keyboard
    foreach ($asset_types as $asset_type) array_unshift($keyboard, [['text' => $asset_type]]);
    $data['reply_markup']['keyboard'] = $keyboard;

    if ($pressed_button) {

        // Update user's level and progress
        $db->update(
            table: 'users',
            data: [
                'last_btn' => $current_button->getId(),
                'progress' => null,
            ],
            conditions: ['id' => $user->getId()]
        );

        // Send initial message
        sendToTelegram('sendMessage', $data);

        // Send favorites message
        sendFavorites($user, $db);
    }

    if ($message) handlePricesTextMessage($data, $message, $asset_types, $db);
    if ($callback_query) handlePricesCallback($user, $callback_query, $message, $asset_types, $db);
    exit();
}

#[NoReturn]
function handlePricesCallback(User $user, array $callback_query, array $message, array $asset_types, DatabaseManager $db): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

    $data = [
        'chat_id' => $user->getChatId(),
        'message_id' => $message['message_id'],
    ];

    $query_data = json_decode($callback_query['data'], true);
    if (!$query_data)
        sendToTelegram('deleteMessage', $data);

    $data['text'] = '📢 خطای ناشناخته!';

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
                $data['reply_markup']['inline_keyboard'] = [
                    [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])]]
                ];

                foreach ($asset_types as $asset_type) {
                    $data['reply_markup']['inline_keyboard'] = array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => $asset_type, 'callback_data' => json_encode(['new_fav_type' => $asset_type])]]
                    );
                }
            }

            // Show list of favorites to choose for deletion
            if ($action === 'remove') {

                $favorites = $db->read(
                    table: 'favorites f',
                    conditions: ['f.user_id' => $user->getId()],
                    selectColumns: 'a.*, f.id as fav_id',
                    join: 'JOIN assets a ON a.name=f.asset_name',
                    orderBy: ['asset_type' => 'DESC', 'f.id' => 'ASC']
                );
                $data['reply_markup']['inline_keyboard'] = [
                    [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])]]
                ];
                if ($favorites) {

                    $data['text'] = 'کدام گزینه را می‌خواهید حذف کنید؟';

                    foreach ($favorites as $favorite) {
                        $data['reply_markup']['inline_keyboard'] = array_unshift(
                            $data['reply_markup']['inline_keyboard'],
                            [['text' => $favorite['asset_name'], 'callback_data' => json_encode(['del_fav' => $favorite['id']])]]
                        );
                    }
                } else $data['text'] = 'لیست علاقه‌مندی‌های شما خالی‌ست!'; // TODO: Answer the callback with a message instead of changing text
            }

            sendToTelegram('editMessageText', $data);
            exit();

        // Show list of assets in a specific type for user to add to their favorites
        case 'new_fav_type':

            $data['reply_markup']['inline_keyboard'] = [
                [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => 'add'])]]
            ];

            $assets = $db->read(
                table: 'assets',
                conditions: ['asset_type' => $query_data['new_fav_type']],
                orderBy: ['asset_type' => 'DESC']
            );

            if ($assets) {
                $data['text'] = 'گزینه‌ی مد نظر خود را از لیست زیر انتخاب کنید:';

                foreach ($assets as $asset)
                    $data['reply_markup']['inline_keyboard'] = array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => $asset['name'], 'callback_data' => json_encode(['new_fav_id' => $asset['id']])]]
                    );

            } else $data['text'] = 'دسته‌بندی مورد نظر خالی‌ست!';

            sendToTelegram('editMessageText', $data);
            exit();

        // Add new favorite to the table and send the favorites message to the user
        case 'new_fav_id':

            $asset_id = $query_data['new_fav_id'];
            try {
                $db->create(
                    table: 'favorites',
                    data: [
                        'user_id' => $user->getId(),
                        'asset_id' => $asset_id
                    ]
                );
                $data['text'] = '✅ علاقه‌مندی جدید افزوده شد!';
            } catch (Exception $e) {
                error_log('Error adding new favorite: ' . $e->getMessage());
                $data['text'] = '❌ خطای پایگاه داده!';
            }

            sendToTelegram('editMessageText', $data);
            sendFavorites($user, $db);
            exit();

        // Show confirmation message for deleting a favorite
        case 'del_fav':

            $favorite_id = $query_data['del_fav'];

            $data['text'] = 'آیا از حذف اطمینان دارید؟';
            $data['reply_markup']['inline_keyboard'] = [[
                ['text' => 'لغو', 'callback_data' => json_encode(['edit_fav' => 'remove'])],
                ['text' => 'تایید', 'callback_data' => json_encode(['conf_del_fav' => $favorite_id])],
            ]];
            sendToTelegram('editMessageText', $data);
            exit();

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
                error_log('Error adding new favorite: ' . $e->getMessage());
                $data['text'] = '❌ خطای پایگاه داده!';
            }

            sendToTelegram('editMessageText', $data);
            sendFavorites($user, $db);
            exit();

        // Start showing live price updates on the current message
        case 'set_live':
            deleteOldActiveLiveMessage($user, $message['message_id'], $db);
            setLiveMessage($user->getId(), $query_data['set_live'], $message['message_id'], $db);
            sendFavorites($user, $db, $message['message_id']);
            exit();

        // Logic for price alerts can be added here
        case 'price_alert':
            exit();

        // Acts as back button and shows the favorites message
        case 'show_favorites':
            sendFavorites($user, $db, $message['message_id']);
            exit();

        default:
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getChatId(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            exit();
    }
}

#[NoReturn]
function handlePricesTextMessage(array $data, array $message, array $asset_types, DatabaseManager $db): void
{
    if (in_array($message['text'], $asset_types)) {

        $data['reply_to_message_id'] = $message['message_id'];

        $assets = $db->read('assets', ['asset_type' => $message['text']]);
        if ($assets) $data['text'] = createPricesTextForSingleAssetType($assets);
        else $data['text'] = 'این دسته بندی خالی‌ست!';

        sendToTelegram('sendMessage', $data);
        exit();
    }

    // Send default message of this level
    $data['text'] = 'پیام نامفهوم است!' . "\n" . 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
    sendToTelegram('sendMessage', $data);
    exit();

}

/**
 * Activate/Inactivate current message in the database as `live_price`.
 *
 * @param int|string $user_id
 * @param bool $activate If `false` **only** works on existing record with the same `$message_id`
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

/**
 * Edits a message and adds favorites text and inline keyboard.
 * Doesn't send the initial message containing bottom keyboard.
 * Doesn't delete any message or set change live price updates.
 *
 * @param User $user
 * @param DatabaseManager $db
 * @param int|string|null $message_id ID of the message to be edited. If `null`, a new message is sent and immediately edited
 * @return void
 *
 * TODO: Add markdown
 */
function sendFavorites(User $user, DatabaseManager $db, int|string|null $message_id = null): void
{
    $message_id = ($message_id !== null) ?
        $message_id :
        sendLoadingMessage($user->getChatId(), 'در حال دریافت اطلاعات لیست علاقه‌مندی‌ها ...')['result']['message_id'];

    try {
        $favorites = $db->read(
            table: 'favorites f',
            conditions: ['f.user_id' => $user->getId()],
            selectColumns: 'a.*, f.id as fav_id',
            join: 'JOIN assets a ON a.name=f.asset_name',
            orderBy: ['asset_type' => 'DESC', 'f.id' => 'ASC']
        );
    } catch (Exception $e) {
        error_log('createFavoritesText: ' . $e->getMessage());
        $favorites = null;
    }

    sendToTelegram('editMessageText', [
        'chat_id' => $user->getChatId(),
        'message_id' => $message_id,
        'text' => createFavoritesText($favorites),
        'reply_markup' => [
            'inline_keyboard' => createFavoritesInlineKeyboard($user->getId(), $message_id, $db, boolval($favorites))
        ]
    ]);
}

/**
 * Creates an array of array of inline buttons for favorites' message.
 *
 * @param int|string $user_id
 * @param int $message_id The ID of current message to be checked for live update
 * @param DatabaseManager $db
 * @param bool|null $has_favorites If `null`, The function automatically checks the database for any registered favorites
 * @return array[]
 */

function createFavoritesInlineKeyboard(
    int|string      $user_id,
    int             $message_id,
    DatabaseManager $db,
    ?bool           $has_favorites = null): array
{
    $live_mssg = $db->read(
        table: 'special_messages',
        conditions: [
            'user_id' => $user_id,
            'type' => 'live_price',
            'is_active' => true,
            'message_id' => $message_id,
        ],
        single: true
    );

    $has_favorites = $has_favorites ?? boolval($db->read(
        table: 'favorites f',
        conditions: ['f.user_id' => $user_id],
        selectColumns: 'a.*, f.id as fav_id',
        join: 'JOIN assets a ON a.name=f.asset_name',
        orderBy: ['asset_type' => 'DESC', 'f.id' => 'ASC']
    ));

    if ($has_favorites) $inline_keyboard[] = ($live_mssg) ?
        [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]] :
        [['text' => 'نمایش زنده قیمت‌ها ▶', 'callback_data' => json_encode(['set_live' => true])]];

    $inline_keyboard[] = [['text' => 'هشدار قیمت', 'callback_data' => json_encode(['price_alert' => null])]];
    $inline_keyboard[] = [['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]];

    return $inline_keyboard;
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
            'chat_id' => $user->getChatId(),
            'message_id' => $message['message_id'],
            'text' => 'این پیام منقضی شده است.'
        ]);
    } else {
        sendToTelegram('sendMessage', [
            'chat_id' => $user->getChatId(),
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
    exit();
}


// ==========================================
//          DATA HANDLING & UI HELPERS
// ==========================================

function getHoldingsWithAssetDetails(array $conditions, DatabaseManager $db, $single = false): bool|array
{
    return $db->read(
        table: 'holdings h',
        conditions: $conditions,
        single: $single,
        selectColumns: '
                h.*,
                a.name as asset_name,
                a.price as current_price,
                a.base_currency,
                a.exchange_rate as base_rate',
        join: 'INNER JOIN assets a ON h.asset_id = a.id'
    );
}

function getLoansWithInstallments(array $conditions, DatabaseManager $db): bool|array
{
    $loans = $db->read(
        table: 'loans l',
        conditions: $conditions,
        selectColumns: '
            l.*,
            CAST(
                CONCAT(
                    "[", IFNULL(
                        GROUP_CONCAT(
                            JSON_OBJECT(
                                "id", i.id, 
                                "loan_id", i.loan_id, 
                                "amount", i.amount, 
                                "due_date", i.due_date, 
                                "is_paid", i.is_paid
                            ) ORDER BY i.due_date ASC
                        ), ""
                    ), "]"
                ) AS JSON
            ) as installments',
        join: 'JOIN installments i on i.loan_id = l.id',
        groupBy: 'l.id'
    );
    if ($loans)
        foreach ($loans as &$loan)
            $loan['installments'] = json_decode($loan['installments'], true);
    return $loans;
}

function createWebAppBtn(string $text, string $path, array $params = []): array
{
    $url = BASE_URL . $path;
    $params['api_url'] = BASE_URL . '/api/ExternalConnections/api.php';
    $params['api_key'] = DB_API_SECRET;

    return [
        'text' => $text,
        'web_app' => ['url' => $url . '?' . http_build_query($params)]
    ];
}

function sendLoadingMessage(string $chat_id, string $text): array|false
{
    return sendToTelegram('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => ['inline_keyboard' => [[['text' => '...', 'callback_data' => 'null']]]]
    ]);
}

/**
 * Finds user's **active** live message in the database with `$message_id`
 * different from the one provided, and sends delete request to telegram.
 **/
function deleteOldActiveLiveMessage(User $user, int|string $message_id, DatabaseManager $db): bool
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
        return sendToTelegram('deleteMessage', ['chat_id' => $user->getChatId(), 'message_id' => $live_mssg['message_id']]);
    else
        return false;
}

// ==========================================
//          TEXT FORMATTING HELPERS
// ==========================================

function createHoldingDetailText(
    array   $holding,
    ?string $markdown = null,
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
    ?string $mssg_id = null): string
{
    // Create tree view for each presented attribute
    $tree = '';
    foreach ($attributes as $attribute) {

        if ($attribute == 'space') {
            $tree .= "\n   │ " . "‏";
        }

        if ($attribute == 'date') {
            $date = dateStringToArray($holding['date']);
            $tree .=
                "\n   ┤── تاریخ خرید: " .
                beautifulNumber("$date[2] $date[1] $date[0]", null);
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
            $pro_los = calculateProLos($holding['avg_price'], $holding['current_price'], $holding['amount'], $holding['base_rate']);
            $pro_los_string =
                ($pro_los == 0) ?
                    "🟤 سود/زیان: ۰ ریال" : (
                ($pro_los > 0) ?
                    "🟢 سود: " . beautifulNumber($pro_los) . " ریال" :
                    "🔴 ضرر: " . beautifulNumber($pro_los) . " ریال"
                );

            $tree .= "\n   ┘── " . $pro_los_string;
        }
    }

    // Manage deep-link and markdown escaping
    if ($markdown === 'MarkdownV2') {

        $tree = markdownScape($tree);

        $asset_name = beautifulNumber(markdownScape($holding['asset_name']), null);
        $holding['asset_name'] = "[$asset_name](https://t.me/" . BOT_ID . "?start=viewHolding_holdingId{$holding['id']}" . ($mssg_id ? "_mssgId" . $mssg_id : '') . ")" . '‏';
    }

    return $holding['asset_name'] . $tree;
}

function createLoansView(array $loans, ?string $mssg_id = null): string
{
    $text = 'وام‌های ثبت شده‌ی شما: ' . "\n";
    foreach ($loans as $loan) {
        $installments_per_year = [];
        $next_payment = getJalaliDate();

        foreach ($loan['installments'] as $inst) {
            $year = explode('/', $inst['due_date'])[0];
            if ($inst['is_paid'] == 1) {
                $installments_per_year[$year][] = "🟢";
            } else {
                if ($next_payment <= getJalaliDate()) $next_payment = $inst['due_date'];
                $installments_per_year[$year][] = ($inst['due_date'] < getJalaliDate()) ? "🔴" : "⚪";
            }
        }

        $daysRemaining = 0;
        $parts = explode('/', $next_payment);
        if (count($parts) == 3) {
            try {
                $gregorianDueDate = new DateTime(jalaliToGregorian($parts[0], $parts[1], $parts[2]) . ' 00:00:00');
                $today = new DateTime('now');
                $today->setTime(0, 0);
                $daysRemaining = (int)$today->diff($gregorianDueDate)->format('%r%a');
            } catch (Exception $e) {
                $daysRemaining = 'خطا در محاسبه!';
                error_log('Error creating `DateTime` object from Jalali calendar: ' . $e->getMessage());
            }
        }

        $link = "https://t.me/" . BOT_ID . "?start=showLoan_loanId{$loan['id']}" . ($mssg_id ? "_mssgId" . $mssg_id : '');
        $loan_name = "\n‏\-* [" . markdownScape(beautifulNumber($loan['name'], null)) . "]($link)*";

        $detail = "\n‏      │  \n‏      ┤─ مبلغ وام\: " . markdownScape(beautifulNumber($loan['total_amount'])) .
            "\n‏      ┤─ تاریخ دریافت\: " . markdownScape(beautifulNumber($loan['received_date'], null));
        if ($daysRemaining) $detail .= "\n‏      ┤─ قسط بعدی\: " . beautifulNumber($daysRemaining) . ' روز دیگر';

        $detail .= "\n‏      ┘─ وضعیت اقساط\: ";
        foreach ($installments_per_year as $year => $insts) {
            $prefix = (array_key_last($installments_per_year) != $year) ? "\n‏          ┤─ " : "\n‏          ┘─ ";
            $detail .= $prefix . beautifulNumber($year, null) . '\: ' . implode('', $insts);
        }

        $text .= $loan_name . $detail . "\n";
    }
    return $text;
}

function createLoanDetailText(array $loan, string $mssg_id): string
{
    $installments = $loan['installments'];
    $paid_count = $overdue_count = $remaining_count = 0;
    $paid_sum = $overdue_sum = $remaining_sum = 0;

    foreach ($installments as &$inst) {
        if ($inst['is_paid'] == 1) {
            $inst['is_paid'] = "🟢";
            $paid_count++;
            $paid_sum += $inst['amount'];
        } elseif ($inst['due_date'] < getJalaliDate()) {
            $inst['is_paid'] = "🔴";
            $overdue_count++;
            $overdue_sum += $inst['amount'];
        } else {
            $inst['is_paid'] = "⚪";
            $remaining_count++;
            $remaining_sum += $inst['amount'];
        }
    }
    unset($inst);

    $text = "‏*" . markdownScape($loan['name']) . "*:\n" .
        "\n مبلغ وام\: " . markdownScape(beautifulNumber($loan['total_amount'])) .
        "\n تاریخ دریافت\: " . markdownScape(beautifulNumber($loan['received_date'], null)) .
        "\n کل بازپرداخت\: " . markdownScape(beautifulNumber(array_sum(array_column($installments, 'amount')))) .
        "\n " . markdownScape(beautifulNumber($paid_count) . " قسط پرداخت‌شده، معادل " . beautifulNumber($paid_sum)) .
        "\n " . markdownScape(beautifulNumber($remaining_count) . " قسط باقی‌مانده، معادل " . beautifulNumber($remaining_sum)) .
        "\n " . markdownScape(beautifulNumber($overdue_count) . " قسط معوقه، معادل " . beautifulNumber($overdue_sum)) .
        "\n جزئیات اقساط\: ";

    foreach ($installments as $index => $inst) {
        $num = beautifulNumber(intval($index) + 1, null);
        $date = beautifulNumber($inst['due_date'], null);
        $amt = beautifulNumber($inst['amount']);
        $link = "https://t.me/" . BOT_ID . "?start=toggleInstPayment_instId{$inst['id']}_mssgId$mssg_id";

        $text .= "\n‏    $num\) {$inst['is_paid']}  $date:  $amt    [تغییر وضعیت پرداخت]($link)";
    }
    return $text;
}

function dateStringToArray(string $date, string $delimiter = '-'): array
{
    // TODO: This function needs to be a method of Jalali object

    $date = preg_split("/$delimiter/u", $date);
    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $date[1] = str_replace(['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'], $months, $date[1]);

    return $date;
}

function calculateProLos(float $p1, float $p2, float $amount = 1, float $conversion_rate = 1): float
{
    $total_price_def = $amount * ($p2 - $p1);
    return $total_price_def * $conversion_rate;
}

/**
 * Creates a well-structured text for favorites' message.
 * If `$assets` is `null`, both `$user_id` and `$db` are required.
 *
 * @param array|null $assets Array of assets, must be ordered by `asset_type`
 * @param int|string|null $user_id Used to fetch favorites **only** if `$assets` is `null`
 * @param DatabaseManager|null $db Used to fetch favorites **only** if `$assets` is `null`
 * @return string|null `null` on wrong inputs
 */
function createFavoritesText(?array $assets, int|string|null $user_id = null, ?DatabaseManager $db = null): string|null
{
    if ($assets === null) {
        if ($db && $user_id) {
            try {
                $assets = $db->read(
                    table: 'favorites f',
                    conditions: ['f.user_id' => $user_id],
                    selectColumns: 'a.*, f.id as fav_id',
                    join: 'JOIN assets a ON a.name=f.asset_name',
                    orderBy: ['asset_type' => 'DESC', 'f.id' => 'ASC']
                );
            } catch (Exception $e) {
                error_log('createFavoritesText: ' . $e->getMessage());
                exit();
            }
        } else {
            error_log('createFavoritesText: Required inputs are wrong`');
            exit();
        }
    }

    if ($assets) {
        $text = '';
        $asset_type = '';
        foreach ($assets as $asset) {
            if ($asset['asset_type'] != $asset_type) {
                $asset_type = $asset['asset_type'];
                $date = preg_split('/-/u', $asset['date']);
                $date[1] = str_replace(
                    ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
                    ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
                    $date[1]);
                $text .= beautifulNumber("\nآخرین قیمت های «$asset[asset_type]» در " . "$date[2] $date[1] $date[0]" . " ساعت " . $asset['time'] . "\n", null);
            }
            $text .= "   -- " . beautifulNumber($asset['name'], null) . ': ' . beautifulNumber($asset['price']) . "\n";
        }
    } else $text = 'لیست علاقه‌مندی‌های شما خالیست!';

    return $text;
}

/**
 * @param array $assets Array of assets
 * @return string
 */
function createPricesTextForSingleAssetType(array $assets): string
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
        $asset['price'] = beautifulNumber($asset['price']);
        $asset['name'] = beautifulNumber($asset['name'], null);
        $asset['base_currency'] = beautifulNumber($asset['base_currency'], null);
        $text .= "\n$asset[name]: $asset[price] $asset[base_currency]";
    }
    return $text;
}
