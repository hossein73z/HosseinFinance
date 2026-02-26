<?php
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
require_once 'Functions/StringHelper.php';

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
function handleIncomingMessage(array $message, DatabaseManager $db): void
{
    $person = getOrCreateUser($message['chat'], $db);

    // Global Command Routing
    $text = $message['text'] ?? '';
    if ($text === '/holdings')
        level_1($person, $db);
    if ($text === '/loans')
        level_2($person, $db);
    if ($text === '/prices')
        level_5($person, $db);
    if ($text === '/ai')
        level_6($person, $db);

    $pressed_button = getPressedButton(text: $text, parent_btn_id: $person['last_btn'], admin: $person['is_admin'], db: $db);

    choosePath(pressed_button: $pressed_button, message: $message, person: $person, db: $db);
}

/**
 * Handles inline button presses.
 */
function handleCallbackQuery(array $callback_query, DatabaseManager $db): void
{
    $message = $callback_query['message'];
    $person = $db->read(
        table: 'persons',
        conditions: ['chat_id' => $message['chat']['id']],
        single: true);

    if ($person !== false) {
        choosePath(message: $message, person: $person, callback_query: $callback_query, db: $db);
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
function getOrCreateUser(array $chat, DatabaseManager $db): array
{
    $person = $db->read(
        table: 'persons',
        conditions: ['chat_id' => $chat['id']],
        single: true);

    if (!$person) {
        $admins = $db->read(
            table: 'persons',
            conditions: ['is_admin' => 1]);
        $new_user_id = $db->create(
            table: 'persons',
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
            return $db->read(
                table: 'persons',
                conditions: ['chat_id' => $chat['id']],
                single: true
            );
        } else {
            error_log("[ERROR] Failed to create new user: " . $chat['id']);
            exit();
        }
    }
    return $person;
}

#[NoReturn]
function callbackHandler(array $person, array $callback_query, DatabaseManager $db): void
{
    $message = $callback_query['message'];

    if ($person['last_btn'] == 1) level_1(person: $person, db: $db, message: $message, callback_query: $callback_query);
    if ($person['last_btn'] == 2) level_2(person: $person, db: $db, message: $message, callback_query: $callback_query);
    if ($person['last_btn'] == 5) level_5(person: $person, db: $db, message: $message, callback_query: $callback_query);
    if ($person['last_btn'] == 6) level_6(person: $person, db: $db, message: $message, callback_query: $callback_query);

    // Fallback if not handled
    sendToTelegram('editMessageText', [
        'text' => 'درخواست نامفهوم بود!',
        'message_id' => $message['message_id'],
        'chat_id' => $person['chat_id'],
    ]);

    exit();
}

#[NoReturn]
function specialButtonHandler(array $person, array $pressed_button, DatabaseManager $db): void
{
    if ($pressed_button['id'] === "s0") backButton($person, $db);
    if ($pressed_button['id'] === "s1") cancelButton($person, $db);
    exit();
}

#[NoReturn]
function normalButtonHandler(array $person, array $pressed_button, DatabaseManager $db): void
{
    if ($pressed_button['id'] == 1) level_1(person: $person, db: $db);
    if ($pressed_button['id'] == 2) level_2(person: $person, db: $db);
    if ($pressed_button['id'] == 5) level_5(person: $person, db: $db);
    if ($pressed_button['id'] == 6) level_6(person: $person, db: $db);

    // Default Actions for normal button
    $btnAttrs = json_decode($pressed_button['attrs'], true);
    $response = sendToTelegram('sendMessage', [
        'text' => $btnAttrs['text'],
        'chat_id' => $person['chat_id'],
        'reply_markup' => [
            'keyboard' => createKeyboardsArray($pressed_button['id'], $person['is_admin'], $db),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $btnAttrs['text'],
        ]
    ]);

    // Only update user's last_btn if the message is sent successfully
    if ($response) {
        $db->update(
            table: 'persons',
            data: ['last_btn' => $pressed_button['id'], 'progress' => null],
            conditions: ['id' => $person['id']]
        );
    }
    exit();
}

#[NoReturn]
function nonButtonHandler(array $person, array $message, DatabaseManager $db): void
{
    if ($person['last_btn'] == 1) level_1(person: $person, db: $db, message: $message);
    if ($person['last_btn'] == 2) level_2(person: $person, db: $db, message: $message);
    if ($person['last_btn'] == 5) level_5(person: $person, db: $db, message: $message);
    if ($person['last_btn'] == 6) level_6(person: $person, db: $db, message: $message);

    // Fallback "Unrecognized" message
    sendToTelegram('sendMessage', [
        'text' => 'پیام نامفهوم است!',
        'chat_id' => $person['chat_id'],
        'reply_markup' => [
            'keyboard' => createKeyboardsArray($person['last_btn'], $person['is_admin'], $db),
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
    ?array          $pressed_button = null,
    ?array          $message = null,
    ?array          $person = null,
    ?array          $callback_query = null,
    DatabaseManager $db = null): void
{
    if ($callback_query)
        callbackHandler($person, $callback_query, $db);
    if ($pressed_button)
        if (str_starts_with($pressed_button['id'], "s"))
            specialButtonHandler(person: $person, pressed_button: $pressed_button, db: $db);
        else normalButtonHandler(person: $person, pressed_button: $pressed_button, db: $db);
    nonButtonHandler(person: $person, message: $message, db: $db);
}

/**
 * Logic for 'Back' button.
 * IMPORTANT: Needs modifications in case of multistep progress
 */
#[NoReturn]
function backButton(array $person, DatabaseManager $db): void
{
    $progress = $person['progress'] ? json_decode($person['progress'], true) : null;
    $current_level = $db->read(
        table: 'buttons',
        conditions: ['id' => $person['last_btn']],
        single: true
    );

    if ($progress) {
        $person['progress'] = null;
        normalButtonHandler(person: $person, pressed_button: $current_level, db: $db);
    } else {
        $last_level = $db->read(
            table: 'buttons',
            conditions: ['id' => $current_level['belong_to']],
            single: true
        );
        $person['progress'] = null;
        normalButtonHandler(person: $person, pressed_button: $last_level, db: $db);
    }
}

/**
 * Logic for 'Cancel' button.
 */
#[NoReturn]
function cancelButton(array $person, $db): void
{
    $person['progress'] = null;
    backButton($person, $db);
}


// ==========================================
//          LEVEL 1: HOLDINGS
// ==========================================

#[NoReturn]
function level_1(array $person, $db, ?array $message = null, ?array $callback_query = null): void
{
    if ($callback_query) {
        handleHoldingsCallback($person, $callback_query, $message, $db);
    } elseif ($message) {
        if (isset($message['web_app_data'])) {
            handleHoldingsWebAppData($person, $message, $db);
        } else {
            handleHoldingsDeepLink($person, $message, $db);
        }
    } else {
        renderHoldingsMainView($person, $db);
    }
    exit();
}

function handleHoldingsCallback(array $person, array $callback_query, array $message): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    $query_data = json_decode($callback_query['data'], true);

    if ($query_data !== 'null') {
        sendToTelegram('editMessageText', [
            'chat_id' => $person['chat_id'],
            'message_id' => $message['message_id'],
            'text' => 'این پیام منقضی شده است.'
        ]);
    }
}

function handleHoldingsWebAppData(array $person, array $message, DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);
    $action = $web_app_data['action'] ?? null;
    $text = 'پیام نامفهوم است.';

    if ($action === 'add') {
        $holding = $web_app_data['holding'];
        $result = $db->create(
            table: 'holdings',
            data: [
                "person_id" => $person['id'],
                "asset_id" => $holding["asset_id"],
                "amount" => $holding["amount"],
                "avg_price" => $holding["avg_price"],
                "date" => $holding["date"],
                "time" => $holding["time"],
                "note" => $holding["note"],
            ]);
        $text = $result ? '✅ دارایی جدید با موفقیت ثبت شد.' : '❌ خطای پایگاه داده در ثبت دارایی جدید.';
    } elseif ($action === 'edit') {
        $result = $db->update(
            table: 'holdings',
            data: $web_app_data['updates'],
            conditions: ['id' => $web_app_data['id']]);
        $text = $result ? '✅ دارایی با موفقیت ویرایش ثبت شد.' : '❌ خطای پایگاه داده در ویرایش دارایی.';
    } elseif ($action === 'delete') {
        $result = $db->delete(
            table: 'holdings',
            conditions: ['id' => $web_app_data['id']],
            resetAutoIncrement: true);
        $text = $result ? '✅ دارایی با موفقیت حذف شد.' : '❌ خطای پایگاه داده درحذف دارایی.';
    }

    sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => $text]);

    // Clear progress and return to main view
    $db->update(
        table: 'persons',
        data: ['progress' => null],
        conditions: ['id' => $person['id']]);
    $person['progress'] = null;
    renderHoldingsMainView($person, $db);
}

function handleHoldingsDeepLink(array $person, array $message, DatabaseManager $db): void
{
    $matched = preg_match('/^\/start viewHolding_holdingId(\d+)(_mssgId(\d+))?$/m', $message['text'], $matches);
    $text = 'پیام نامفهوم است!';

    if ($matched && !empty($matches[1])) {
        $holding_id = $matches[1];
        $mssg_id_to_delete = $matches[3] ?? null;

        $holding = $db->read(
            table: 'holdings h',
            conditions: [
                'h.id' => $holding_id,
                'h.person_id' => $person['id']
            ],
            single: true,
            selectColumns: '
                h.*,
                a.name as asset_name,
                a.price as current_price,
                a.base_currency,
                a.exchange_rate as base_rate',
            join: 'INNER JOIN assets a ON h.asset_id = a.id'
        );

        if ($holding) {
            if ($mssg_id_to_delete) {
                sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $message['message_id']]);
                sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $mssg_id_to_delete]);
            }

            $keyboard = createKeyboardsArray(1, $person['is_admin'], $db);
            array_unshift($keyboard, [
                createWebAppBtn('✏ ویرایش', '/assets/add_holding.html', ['data' => base64_encode(json_encode($holding))])
            ]);

            sendToTelegram('sendMessage', [
                'chat_id' => $person['chat_id'],
                'text' => createHoldingDetailText($holding),
                'reply_markup' => ['keyboard' => $keyboard, 'resize_keyboard' => true, 'is_persistent' => true]
            ]);

            $db->update(
                table: 'persons',
                data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                conditions: ['id' => $person['id']]);
            return;
        } else {
            $text = 'دارایی با این مشخصه یافت نشد!';
        }
    }
    sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => $text]);
}

function renderHoldingsMainView(array $person, DatabaseManager $db): void
{
    $keyboard = createKeyboardsArray(1, $person['is_admin'], $db);

    // Add App Buttons
    $progress = json_decode($person['progress'], true);
    if ($progress && key($progress) === 'view_holding') {
        $holding = $db->read(
            table: 'holdings',
            conditions: ['id' => $progress['view_holding']['holding_id'], 'person_id' => $person['id']],
            single: true);
        if ($holding) {
            array_unshift($keyboard, [
                createWebAppBtn('✏ ویرایش', '/assets/add_holding.html', ['data' => base64_encode(json_encode($holding))])
            ]);
        }
    }
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن دارایی جدید', '/assets/add_holding.html')]);

    sendToTelegram('sendMessage', [
        'chat_id' => $person['chat_id'],
        'text' => 'دارایی‌ها',
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => 'دارایی‌ها'
        ]
    ]);

    $holdings = $db->read(
        table: 'holdings h',
        conditions: [
            'person_id' => $person['id']
        ],
        selectColumns: '
            h.*,
            a.name as asset_name,
            a.price as current_price,
            a.base_currency,
            a.exchange_rate as base_rate',
        join: 'INNER JOIN assets a ON h.asset_id = a.id');

    if ($holdings) {
        $temp_mssg = sendLoadingMessage($person['chat_id'], 'در حال دریافت اطلاعات دارایی‌ها ...');
        if ($temp_mssg) {
            $text = "دارایی‌های ثبت شده‌ی شما:\n";
            $total_profit = 0;

            foreach ($holdings as $holding) {
                $total_profit += $holding['amount'] * ($holding['current_price'] - $holding['avg_price']) * $holding['base_rate'];
                $text .= "\n" . createHoldingDetailText($holding, 'MarkdownV2', ['org_amount', 'org_total_price', 'profit'], $temp_mssg['result']['message_id']);
            }

            $profit_text = ($total_profit >= 0) ? "🟢 کل سود: " . beautifulNumber($total_profit) . ' ریال' : "🔴 کل ضرر: " . beautifulNumber($total_profit) . ' ریال';
            $text .= "\n" . markdownScape($profit_text);

            sendToTelegram('editMessageText', [
                'chat_id' => $person['chat_id'],
                'message_id' => $temp_mssg['result']['message_id'],
                'text' => $text,
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => 'شما هیچ دارایی‌ای ثبت نکرده‌اید.']);
    }

    $db->update(
        table: 'persons',
        data: ['last_btn' => 1, 'progress' => null],
        conditions: ['id' => $person['id']]);
}


// ==========================================
//          LEVEL 2: LOANS & INSTALLMENTS
// ==========================================

#[NoReturn]
function level_2(array $person, $db, ?array $message = null, ?array $callback_query = null): void
{
    if ($callback_query) {
        handleLoansCallback($person, $callback_query, $message, $db);
    } elseif ($message) {
        if (isset($message['web_app_data'])) {
            handleLoansWebAppData($person, $message, $db);
        } else {
            handleLoansDeepLink($person, $message, $db);
        }
    } else {
        renderLoansMainView($person, $db);
    }
    exit();
}

function handleLoansCallback(array $person, array $callback_query, array $message, $db): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    $query_data = json_decode($callback_query['data'], true);

    if ($query_data && key($query_data) === 'loan_list') {
        sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $message['message_id']]);
        $person['progress'] = null;
        renderLoansMainView($person, $db);
    } elseif ($query_data !== 'null') {
        sendToTelegram('editMessageText', [
            'chat_id' => $person['chat_id'],
            'message_id' => $message['message_id'],
            'text' => 'این پیام منقضی شده است.'
        ]);
    }
}

function handleLoansWebAppData(array $person, array $message, DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);
    $text = "پیام نامفهوم است!";

    if (isset($web_app_data['loans']) && isset($web_app_data['installments'])) {
        // Create new loan
        $loanData = $web_app_data['loans'];
        $loan_id = $db->create(
            table: 'loans',
            data: [
                'person_id' => $person['id'],
                'name' => $loanData['name'],
                'total_amount' => $loanData['total_amount'],
                'received_date' => $loanData['received_date'],
                'alert_offset' => $loanData['alert_offset'],
            ]);

        if ($loan_id) {
            $count = 0;
            foreach ($web_app_data['installments'] as $inst) {
                $db->create(
                    table: 'installments',
                    data: [
                        'loan_id' => $loan_id, // Fixed: Original code used person_id instead of loan_id
                        'amount' => $inst['amount'],
                        'due_date' => $inst['due_date'],
                        'is_paid' => $inst['is_paid'] ? 1 : 0
                    ]);
                $count++;
            }
            $text = "✅ وام «{$loanData['name']}» با موفقیت ثبت شد.\n📊 تعداد اقساط: " . beautifulNumber($count);
        }
    } elseif (isset($web_app_data['id']) && isset($web_app_data['updates'])) {
        // Update existing loan
        $new_insts = $web_app_data['updates']['installments'] ?? null;
        unset($web_app_data['updates']['installments']);
        $text = "نتیجه ویرایش وام: ";

        if (!empty($web_app_data['updates'])) {
            $result = $db->update(
                table: 'loans',
                data: $web_app_data['updates'],
                conditions: ['id' => $web_app_data['id'], 'person_id' => $person['id']]);
            $text .= $result ? "\nویرایش اطلاعات وام: ✅" : "\nویرایش اطلاعات وام: ❌";
        }

        if ($new_insts) {
            foreach ($new_insts as &$new_inst) {
                $new_inst['loan_id'] = $web_app_data['id']; // Fixed: was person_id
            }
            $result = $db->upsertBatch(
                table: 'installments',
                dataRows: $new_insts
            );
            $text .= $result ? "\nویرایش اطلاعات اقساط: ✅" : "\nویرایش اطلاعات اقساط: ❌";

            $deleted_rows = $db->delete(
                table: 'installments',
                conditions: ['loan_id' => $web_app_data['id'], '!due_date' => array_column($new_insts, 'due_date')]
            );
            if ($deleted_rows) $text .= "\nتعداد قسط حذف شده: " . beautifulNumber($deleted_rows);
        }
    } elseif (isset($web_app_data['delete']) && $web_app_data['delete']) {
        // Delete loan
        $result = $db->delete(
            table: 'loans',
            conditions: ['id' => $web_app_data['id']]
        );
        $text = $result ? '✅ حذف وام با موفقیت انجام شد!' : '❌ خطای پایگاه داده در حذف وام!';
    }

    sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => $text]);

    $person['progress'] = null;
    $db->update(
        table: 'persons',
        data: ['progress' => null],
        conditions: ['id' => $person['id']]
    );
    renderLoansMainView($person, $db);
}

function handleLoansDeepLink(array $person, array $message, DatabaseManager $db): void
{
    $text = $message['text'];

    // Show loan detail
    if (preg_match("/^\/start showLoan_loanId(\d+?)_mssgId(\d+?)$/m", $text, $matches)) {
        sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $message['message_id']]);
        sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $matches[2]]);

        $loan = getLoanWithInstallments($matches[1], $person['id'], $db);

        if ($loan) {
            $keyboard = createKeyboardsArray(2, $person['is_admin'], $db);
            array_unshift($keyboard, [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/add_loan.html', ['data' => base64_encode(json_encode($loan))])]);
            array_unshift($keyboard, [createWebAppBtn('➕ افزودن وام جدید', '/assets/add_loan.html')]);

            sendToTelegram('sendMessage', [
                'chat_id' => $person['chat_id'],
                'text' => 'جزئیات وام «' . $loan['name'] . '»',
                'reply_markup' => ['keyboard' => $keyboard, 'resize_keyboard' => true, 'is_persistent' => true]
            ]);

            $temp_mssg = sendLoadingMessage($person['chat_id'], 'در حال دریافت اطلاعات اقساط ...');
            if ($temp_mssg) {
                $db->update(
                    table: 'persons',
                    data: ['progress' => json_encode(['viewing_loan' => ['loan_id' => $loan['id']]])],
                    conditions: ['id' => $person['id']]
                );

                sendToTelegram('editMessageText', [
                    'chat_id' => $person['chat_id'],
                    'message_id' => $temp_mssg['result']['message_id'],
                    'text' => createLoanDetailView($loan, $temp_mssg['result']['message_id']),
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => ['inline_keyboard' => [[['text' => 'برگشت به لیست وام‌ها', 'callback_data' => json_encode(['loan_list' => null])]]]]
                ]);
            }
        }
    } // Toggle Installment Payment
    elseif (preg_match("/^\/start toggleInstPayment_instId(\d+?)_mssgId(\d+?)$/m", $text, $matches)) {
        sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $message['message_id']]);

        $installment = $db->read(
            table: 'installments i',
            conditions: ['i.id' => $matches[1]],
            single: true,
            selectColumns: 'i.*, l.person_id',
            join: 'LEFT JOIN loans l ON i.loan_id = l.id'
        );

        if ($installment && $installment['person_id'] == $person['id']) {
            $db->update(
                table: 'installments',
                data: ['is_paid' => !$installment['is_paid']],
                conditions: ['id' => $installment['id']]
            );
            $loan = getLoanWithInstallments($installment['loan_id'], $person['id'], $db);

            if ($loan) {
                sendToTelegram('editMessageText', [
                    'chat_id' => $person['chat_id'],
                    'message_id' => $matches[2],
                    'text' => createLoanDetailView($loan, $matches[2]),
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => ['inline_keyboard' => [[['text' => 'برگشت به لیست وام‌ها', 'callback_data' => json_encode(['loan_list' => null])]]]]
                ]);
            }
        } else {
            sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => 'قسطی با این مشخصه یافت نشد!']);
        }
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => 'پیام نامفهوم است!']);
    }
}

function renderLoansMainView(array $person, DatabaseManager $db): void
{
    $keyboard = createKeyboardsArray(2, $person['is_admin'], $db);

    // Add App Buttons
    $progress = json_decode($person['progress'], true);
    if ($progress && key($progress) === 'viewing_loan') {
        $loan = $db->read(
            table: 'loans',
            conditions: ['id' => $progress['viewing_loan']['loan_id'], 'person_id' => $person['id']],
            single: true
        );
        if ($loan) {
            array_unshift($keyboard, [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/add_loan.html', ['data' => base64_encode(json_encode($loan))])]);
        }
    }
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن وام جدید', '/assets/add_loan.html')]);

    sendToTelegram('sendMessage', [
        'chat_id' => $person['chat_id'],
        'text' => '🏦 وام و اقساط',
        'reply_markup' => ['keyboard' => $keyboard, 'resize_keyboard' => true, 'is_persistent' => true, 'input_field_placeholder' => '🏦 وام و اقساط']
    ]);

    $loans = $db->read(
        table: 'loans l',
        conditions: ['l.person_id' => $person['id']],
        selectColumns: '
            l.*,
            JSON_ARRAYAGG(
                JSON_OBJECT(
                    "id", i.id,
                    "loan_id", i.loan_id,
                    "amount", i.amount,
                    "due_date", i.due_date,
                    "is_paid", i.is_paid
                )
            ) as installments',
        join: 'JOIN installments i on i.loan_id = l.id', groupBy: 'l.id'
    );

    if ($loans) {
        foreach ($loans as &$loan) {
            $loan['installments'] = json_decode($loan['installments'], true);
        }

        $temp_mssg = sendLoadingMessage($person['chat_id'], 'در حال دریافت اطلاعات وام‌ها ...');
        if ($temp_mssg) {
            sendToTelegram('editMessageText', [
                'chat_id' => $person['chat_id'],
                'message_id' => $temp_mssg['result']['message_id'],
                'text' => createLoansView($loans, $temp_mssg['result']['message_id']),
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => 'هیچ وام یا قسطی برای شما ثبت نشده است!']);
    }

    $db->update(
        table: 'persons',
        data: ['last_btn' => 2, 'progress' => null],
        conditions: ['id' => $person['id']]);
}


// ==========================================
//          LEVEL 5: PRICES / FAVORITES
// ==========================================

#[NoReturn]
function level_5(array $person, DatabaseManager $db, ?array $message = null, ?array $callback_query = null): void
{
    $asset_types = $db->read(
        table: 'assets',
        selectColumns: 'asset_type',
        distinct: true,
        orderBy: ['asset_type' => 'DESC']
    );
    if (!$asset_types) {
        sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => 'دسته‌بندی‌ای در سیستم یافت نشد!']);
        exit();
    }

    if ($callback_query) {
        handlePricesCallback($person, $callback_query, $message, $asset_types, $db);
    } elseif ($message) {
        handlePricesMessage($person, $message, $asset_types, $db);
    } else {
        renderPricesMainView($person, $asset_types, $db);
    }
    exit();
}

function handlePricesCallback(array $person, array $callback_query, array $message, array $asset_types, DatabaseManager $db): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    $query_data = json_decode($callback_query['data'], true);
    if (!$query_data) return;

    $query_key = array_key_first($query_data);
    $data = ['chat_id' => $person['chat_id'], 'message_id' => $message['message_id']];

    switch ($query_key) {
        case 'edit_fav':
            handleEditFavoriteCallback($person, $query_data, $asset_types, $data, $db);
            break;
        case 'add_fav':
            handleAddFavoriteCallback($person, $query_data, $asset_types, $data, $db);
            break;
        case 'del_fav':
            handleDeleteFavoriteCallback($person, $query_data, $data, $db);
            break;
        case 'set_live':
            handleSetLiveCallback($person, $query_data, $data, $db);
            break;
        case 'price_alert':
            // Logic for price alerts can be added here
            break;
        case 'back':
            if ($query_data['back'] === 'favorites_list') {
                renderFavoritesList($person, $data['message_id'], true, $db);
            }
            break;
        default:
            $data['text'] = 'این پیام منقضی شده است.';
            sendToTelegram('editMessageText', $data);
            break;
    }
}

function handleEditFavoriteCallback(array $person, array $query_data, array $asset_types, array $data, $db): void
{
    $value = $query_data['edit_fav'];

    if ($value === null) {
        $data['text'] = 'عملیات مورد نظر را انتخاب کنید:';
        $data['reply_markup']['inline_keyboard'] = [
            [['text' => 'افزودن', 'callback_data' => json_encode(['edit_fav' => 'add'])]],
            [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['back' => 'favorites_list'])]],
        ];

        $favorites = $db->read(
            table: 'favorites',
            conditions: ['person_id' => $person['id']]
        );
        if ($favorites) {
            $data['reply_markup']['inline_keyboard'][0][] = ['text' => 'حذف', 'callback_data' => json_encode(['edit_fav' => 'remove'])];
        }

        disableLivePriceMessage($person, $data['message_id'], $db);

    } elseif ($value === 'add') {
        $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
        $data['reply_markup']['inline_keyboard'] = [[['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])]]];

        $types = array_column($asset_types, 'asset_type');
        foreach ($types as $index => $type) {
            $data['reply_markup']['inline_keyboard'][] = [['text' => $type, 'callback_data' => json_encode(['add_fav' => ['asset_type' => $index]])]];
        }
    } elseif ($value === 'remove') {
        $favorites = getFavoritesList($person['id'], $db);
        if ($favorites) {
            $data['text'] = 'کدام گزینه را می‌خواهید حذف کنید؟';
            $data['reply_markup']['inline_keyboard'] = [[['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])]]];

            foreach ($favorites as $fav) {
                $data['reply_markup']['inline_keyboard'][] = [['text' => $fav['asset_name'] ?? $fav['name'], 'callback_data' => json_encode(['del_fav' => ['fav_id' => $fav['id']]])]];
            }
        }
    }

    sendToTelegram('editMessageText', $data);
}

function handleAddFavoriteCallback(array $person, array $query_data, array $asset_types, array $data, DatabaseManager $db): void
{
    $inner_key = array_key_first($query_data['add_fav']);
    $inner_val = $query_data['add_fav'][$inner_key];

    if ($inner_key === 'asset_type') {
        $type = array_column($asset_types, 'asset_type')[$inner_val];
        $assets = $db->read(
            table: 'assets',
            conditions: ['asset_type' => $type],
            orderBy: ['asset_type' => 'DESC']
        );

        $data['text'] = 'گزینه‌ی مد نظر خود را از لیست زیر انتخاب کنید:';
        $data['reply_markup']['inline_keyboard'] = [[['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => 'add'])]]];

        foreach ($assets as $asset) {
            $data['reply_markup']['inline_keyboard'][] = [['text' => $asset['name'], 'callback_data' => json_encode(['add_fav' => ['asset' => $asset['id']]])]];
        }
        sendToTelegram('editMessageText', $data);

    } elseif ($inner_key === 'asset') {
        $result = $db->create(
            table: 'favorites',
            data: ['person_id' => $person['id'], 'asset_id' => $inner_val]
        );
        sendToTelegram('editMessageText', [
            'chat_id' => $person['chat_id'],
            'message_id' => $data['message_id'],
            'text' => $result ? '✅ علاقه‌مندی جدید افزوده شد!' : '❌ خطای پایگاه داده!'
        ]);

        renderFavoritesList($person, null, false, $db); // Send as new message
    }
}

function handleDeleteFavoriteCallback(array $person, array $query_data, array $data, DatabaseManager $db): void
{
    $inner_key = array_key_first($query_data['del_fav']);
    $inner_val = $query_data['del_fav'][$inner_key];

    if ($inner_key === 'fav_id') {
        $data['text'] = 'آیا از حذف اطمینان دارید؟';
        $data['reply_markup']['inline_keyboard'] = [[
            ['text' => 'لغو', 'callback_data' => json_encode(['edit_fav' => 'remove'])],
            ['text' => 'تایید', 'callback_data' => json_encode(['del_fav' => ['conf' => $inner_val]])],
        ]];
        sendToTelegram('editMessageText', $data);

    } elseif ($inner_key === 'conf') {
        $result = $db->delete(
            table: 'favorites',
            conditions: ['id' => $inner_val],
            resetAutoIncrement: true
        );
        sendToTelegram('editMessageText', [
            'chat_id' => $person['chat_id'],
            'message_id' => $data['message_id'],
            'text' => $result ? '✅ حذف موفقیت آمیز بود!' : '❌ خطای پایگاه داده!'
        ]);

        renderFavoritesList($person, null, false, $db);
    }
}

function handleSetLiveCallback(array $person, array $query_data, array $data, DatabaseManager $db): void
{
    $is_active = $query_data['set_live'];
    $result = false;

    if ($is_active === true) {
        $live_mssg = $db->read(
            table: 'special_messages',
            conditions: ['person_id' => $person['id'], 'type' => 'live_price'],
            single: true
        );
        if ($live_mssg) sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $live_mssg['message_id']]);

        $result = $db->upsert(
            table: 'special_messages',
            data: [
                'person_id' => $person['id'],
                'type' => 'live_price',
                'is_active' => true,
                'message_id' => $data['message_id'],
            ]
        );
    } else {
        $result = $db->delete(
            table: 'special_messages',
            conditions: ['person_id' => $person['id'], 'type' => 'live_price'],
            resetAutoIncrement: true
        );
    }

    if ($result) {
        renderFavoritesList($person, $data['message_id'], true, $db);
    } else {
        $data['text'] = 'خطای پایگاه داده';
        sendToTelegram('editMessageText', $data);
    }
}

function handlePricesMessage(array $person, array $message, array $asset_types, $db): void
{
    $text = $message['text'];
    $types_array = array_column($asset_types, 'asset_type');

    if (in_array($text, $types_array)) {
        $assets = $db->read(
            table: 'assets',
            conditions: ['asset_type' => $text]
        );
        if ($assets) {
            $date = preg_split('/-/u', $assets[0]['date']);
            $date[1] = str_replace(
                ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
                ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
                $date[1]
            );

            $reply = "آخرین قیمت ها در $date[2] $date[1] $date[0] ساعت " . $assets[0]['time'] . "\n";
            $reply = beautifulNumber($reply, null);

            foreach ($assets as $asset) {
                $price = beautifulNumber($asset['price']);
                $reply .= "\n{$asset['name']} : {$price} {$asset['base_currency']}";
            }

            sendToTelegram('sendMessage', [
                'chat_id' => $person['chat_id'],
                'text' => $reply,
                'reply_to_message_id' => $message['message_id']
            ]);
        } else {
            sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => 'این دسته بندی خالی‌ست!']);
        }
    } elseif ($text === '❤ علاقه‌مندی‌ها ❤') {
        renderFavoritesList($person, null, false, $db);
    } else {
        renderPricesMainView($person, $asset_types, $db);
    }
}

function renderPricesMainView(array $person, array $asset_types, $db): void
{
    $keyboard = createKeyboardsArray(5, $person['is_admin'], $db);
    $types = array_column($asset_types, 'asset_type');

    foreach ($types as $type) {
        array_unshift($keyboard, [['text' => $type]]);
    }
    array_unshift($keyboard, [['text' => '❤ علاقه‌مندی‌ها ❤']]);

    sendToTelegram('sendMessage', [
        'chat_id' => $person['chat_id'],
        'text' => "دسته‌بندی مورد نظر را انتخاب کنید:",
        'reply_markup' => ['keyboard' => $keyboard, 'resize_keyboard' => true, 'input_field_placeholder' => 'قیمت‌ها']
    ]);

    $db->update(
        table: 'persons',
        data: ['last_btn' => 5, 'progress' => null],
        conditions: ['id' => $person['id']]);
}

function renderFavoritesList(array $person, ?int $message_id_to_edit, bool $is_edit, DatabaseManager $db): void
{
    $favorites = getFavoritesList($person['id'], $db);
    $live_mssg = $db->read(
        table: 'special_messages',
        conditions: [
            'person_id' => $person['id'],
            'type' => 'live_price'
        ],
        single: true
    );

    if ($is_edit && $live_mssg) {
        $db->update(
            table: 'special_messages',
            data: ['is_active' => true],
            conditions: ['id' => $live_mssg['id']]
        );
    }

    $inline_keyboard = [
        ($live_mssg && ($is_edit || $live_mssg['message_id'] == $message_id_to_edit))
            ? [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]]
            : [['text' => 'نمایش زنده قیمت‌ها ▶', 'callback_data' => json_encode(['set_live' => true])]],
        [['text' => 'هشدار قیمت', 'callback_data' => json_encode(['price_alert' => null])]],
        [['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]],
    ];

    $data = [
        'chat_id' => $person['chat_id'],
        'text' => createFavoritesText($favorites),
        'reply_markup' => ['inline_keyboard' => $inline_keyboard]
    ];

    if ($is_edit && $message_id_to_edit) {
        $data['message_id'] = $message_id_to_edit;
        sendToTelegram('editMessageText', $data);
    } else {
        $response = sendToTelegram('sendMessage', $data);
        if ($response && $live_mssg) {
            $db->update(
                table: 'special_messages',
                data: [
                    'message_id' => $response['result']['message_id'],
                    'is_active' => true
                ],
                conditions: ['id' => $live_mssg['id']]
            );
            sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $live_mssg['message_id']]);
        }
    }
}

function getFavoritesList(int $person_id, DatabaseManager $db): array|false
{
    return $db->read(
        table: 'favorites f',
        conditions: ['person_id' => $person_id],
        selectColumns: 'a.*, f.id as fav_id',
        join: 'JOIN assets a ON a.id=f.asset_id',
        orderBy: ['asset_type' => 'DESC', 'f.id' => 'ASC']
    );
}

function disableLivePriceMessage(array $person, int $message_id, DatabaseManager $db): void
{
    $live_mssg = $db->read(
        table: 'special_messages',
        conditions: [
            'person_id' => $person['id'],
            'type' => 'live_price',
            'is_active' => true
        ],
        single: true
    );
    if ($live_mssg && $live_mssg['message_id'] == $message_id) {
        $db->update(
            table: 'special_messages',
            data: ['is_active' => false],
            conditions: ['id' => $live_mssg['id']]);
    }
}


// ==========================================
//          LEVEL 6: ARTIFICIAL INTELLIGENCE
// ==========================================

#[NoReturn]
function level_6(array $person, DatabaseManager $db, ?array $message = null, ?array $callback_query = null): void
{
    if ($callback_query) {
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        sendToTelegram('editMessageText', [
            'chat_id' => $person['chat_id'],
            'message_id' => $message['message_id'],
            'text' => 'این پیام منقضی شده است.'
        ]);
    } else {
        sendToTelegram('sendMessage', [
            'chat_id' => $person['chat_id'],
            'text' => 'در حال توسعه...',
            'reply_markup' => [
                'keyboard' => createKeyboardsArray(6, $person['is_admin'], $db),
                'resize_keyboard' => true,
                'input_field_placeholder' => 'هوش مصنوعی',
            ]
        ]);
        $db->update(
            table: 'persons',
            data: ['last_btn' => 6, 'progress' => null],
            conditions: ['id' => $person['id']]
        );
    }
    exit();
}


// ==========================================
//          DATA FETCHING & UI HELPERS
// ==========================================

function getLoanWithInstallments($loan_id, $person_id, DatabaseManager $db)
{
    $loan = $db->read(
        table: 'loans l',
        conditions: [
            'l.id' => $loan_id,
            'l.person_id' => $person_id
        ],
        single: true,
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
    if ($loan) $loan['installments'] = json_decode($loan['installments'], true);
    return $loan;
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


// ==========================================
//          TEXT FORMATTING HELPERS
// ==========================================

function createHoldingDetailText(array $holding, ?string $markdown = null, array $attributes = ['date', 'org_amount', 'org_price', 'new_price', 'org_total_price', 'new_total_price', 'profit'], ?string $mssg_id = null): string
{
    $date = preg_split('/-/u', $holding['date']);
    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $date[1] = str_replace(['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'], $months, $date[1]);

    if ($markdown === 'MarkdownV2') {
        $asset_name = beautifulNumber(markdownScape($holding['asset_name']), null);
        $holding['asset_name'] = "[$asset_name](https://t.me/" . BOT_ID . "?start=viewHolding_holdingId{$holding['id']}" . ($mssg_id ? "_mssgId" . $mssg_id : '') . ")" . '‏';
    }

    $price_def = $holding['current_price'] - $holding['avg_price'];
    $profit_val = ($price_def * $holding['amount']) * floatval($holding['base_rate']);
    $profit = ($price_def >= 0) ? "🟢 سود: " . beautifulNumber($profit_val) : "🔴 ضرر: " . beautifulNumber($profit_val);

    $tree = "\n   │ " . "‏";
    if (in_array('date', $attributes)) $tree .= "\n   ┤── تاریخ خرید: " . beautifulNumber("$date[2] $date[1] $date[0]", null);
    if (in_array('org_amount', $attributes)) $tree .= "\n   ┤── مقدار / تعداد: " . beautifulNumber(floatval($holding['amount']));
    if (in_array('org_price', $attributes)) $tree .= "\n   ┤── قیمت خرید هر واحد: " . beautifulNumber(floatval($holding['avg_price'])) . " " . $holding['base_currency'];
    if (in_array('new_price', $attributes)) $tree .= "\n   ┤── قیمت لحظه‌ای هر واحد: " . beautifulNumber($holding['current_price']) . " " . $holding['base_currency'];
    if (in_array('org_total_price', $attributes)) $tree .= "\n   ┤── قیمت خرید کل: " . beautifulNumber($holding['avg_price'] * $holding['amount']) . " " . $holding['base_currency'];
    if (in_array('new_total_price', $attributes)) $tree .= "\n   ┤── قیمت لحظه‌ای کل دارایی: " . beautifulNumber($holding['current_price'] * $holding['amount']) . " " . $holding['base_currency'];
    $tree .= "\n   │ " . "‏";
    if (in_array('profit', $attributes)) $tree .= "\n   ┘── " . $profit . " ریال";
    $tree .= "\n";

    if ($markdown === 'MarkdownV2') $tree = markdownScape($tree);

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
            $gregorianDueDate = new DateTime(jalaliToGregorian($parts[0], $parts[1], $parts[2]) . ' 00:00:00');
            $today = new DateTime('now');
            $today->setTime(0, 0);
            $daysRemaining = (int)$today->diff($gregorianDueDate)->format('%r%a');
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

function createLoanDetailView(array $loan, string $mssg_id): string
{
    $installments = $loan['installments'];
    $paid_count = $overdue_count = $remaining_count = 0;
    $paid_sum = $overdue_sum = $remaining_sum = 0;

    foreach ($installments as $index => &$inst) {
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
        $link = "https://t.me/" . BOT_ID . "?start=toggleInstPayment_instId{$inst['id']}_mssgId{$mssg_id}";

        $text .= "\n‏    $num\) {$inst['is_paid']}  $date:  $amt    [تغییر وضعیت پرداخت]($link)";
    }
    return $text;
}