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
require_once 'Models/Button.php';
require_once 'Models/Person.php';

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

    $pressed_button = getPressedButton(text: $text, parent_btn_id: $person->getLastBtn(), admin: $person->isAdmin(), db: $db);

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
        choosePath(message: $message, person: Person::fromDbRow($person), callback_query: $callback_query, db: $db);
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
function getOrCreateUser(array $chat, DatabaseManager $db): Person
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
            $person = $db->read(
                table: 'persons',
                conditions: ['chat_id' => $chat['id']],
                single: true
            );
        } else {
            error_log("[ERROR] Failed to create new user: " . $chat['id']);
            exit();
        }
    }
    return Person::fromDbRow($person);
}

#[NoReturn]
function callbackHandler(Person $person, array $callback_query, DatabaseManager $db): void
{
    // TODO: Callback queries move user to corresponding level instead of removing the message

    $message = $callback_query['message'];

    if ($person->getLastBtn() == 1) level_1(person: $person, db: $db, message: $message, callback_query: $callback_query);
    if ($person->getLastBtn() == 2) level_2(person: $person, db: $db, message: $message, callback_query: $callback_query);
    if ($person->getLastBtn() == 5) level_5(person: $person, db: $db, message: $message, callback_query: $callback_query);
    if ($person->getLastBtn() == 6) level_6(person: $person, db: $db, message: $message, callback_query: $callback_query);

    // Fallback if not handled
    sendToTelegram('editMessageText', [
        'text' => 'درخواست نامفهوم بود!',
        'message_id' => $message['message_id'],
        'chat_id' => $person->getChatId(),
    ]);

    exit();
}

#[NoReturn]
function specialButtonHandler(Person $person, Button $pressed_button, DatabaseManager $db): void
{
    if ($pressed_button->getId() === "s0") backButton($person, $db);
    if ($pressed_button->getId() === "s1") cancelButton($person, $db);
    if ($pressed_button->getId() === "s2") sendFavorites($person, $db);
    exit();
}

#[NoReturn]
function normalButtonHandler(Person $person, Button $pressed_button, DatabaseManager $db): void
{
    // Route the button to corresponding level
    if ($pressed_button->getId() == 1) level_1(person: $person, db: $db, pressed_button: $pressed_button);
    if ($pressed_button->getId() == 2) level_2(person: $person, db: $db, pressed_button: $pressed_button);
    if ($pressed_button->getId() == 5) level_5(person: $person, db: $db, pressed_button: $pressed_button);
    if ($pressed_button->getId() == 6) level_6(person: $person, db: $db);

    // Default Actions for normal button
    $response = sendToTelegram('sendMessage', [
        'text' => $pressed_button->getText(),
        'chat_id' => $person->getChatId(),
        'reply_markup' => [
            'keyboard' => createKeyboardsArray($pressed_button->getId(), $person->isAdmin(), $db),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $pressed_button->getText(),
        ]
    ]);

    if ($response) $db->update(
        table: 'persons',
        data: ['last_btn' => $pressed_button->getId()],
        conditions: ['id' => $person->getId()]
    );

    exit();
}

#[NoReturn]
function nonButtonHandler(Person $person, array $message, DatabaseManager $db): void
{
    // TODO: Misplaced deep links move user to the corresponding level instead of showing error

    if ($person->getLastBtn() == 1) level_1(person: $person, db: $db, message: $message);
    if ($person->getLastBtn() == 2) level_2(person: $person, db: $db, message: $message);
    if ($person->getLastBtn() == 5) level_5(person: $person, db: $db, message: $message);
    if ($person->getLastBtn() == 6) level_6(person: $person, db: $db, message: $message);

    // Fallback "Unrecognized" message
    sendToTelegram('sendMessage', [
        'text' => 'پیام نامفهوم است!',
        'chat_id' => $person->getChatId(),
        'reply_markup' => [
            'keyboard' => createKeyboardsArray($person->getLastBtn(), $person->isAdmin(), $db),
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
    ?Person         $person = null,
    ?array          $callback_query = null,
    DatabaseManager $db = null): void
{
    if ($callback_query)
        callbackHandler($person, $callback_query, $db);
    if ($pressed_button)
        if (str_starts_with($pressed_button->getId(), "s"))
            specialButtonHandler(person: $person, pressed_button: $pressed_button, db: $db);
        else normalButtonHandler(person: $person, pressed_button: $pressed_button, db: $db);
    nonButtonHandler(person: $person, message: $message, db: $db);
}

/**
 * Logic for 'Back' button.
 * IMPORTANT: Needs modifications in case of multistep progress
 */
#[NoReturn]
function backButton(Person $person, DatabaseManager $db): void
{
    $progress = $person->getProgress() ? json_decode($person->getProgress(), true) : null;
    $current_level = $db->read(
        table: 'buttons',
        conditions: ['id' => $person->getLastBtn()],
        single: true
    );
    $current_btn = Button::fromDbRow($current_level);

    if ($progress) {
        $person->setProgress(null);
        normalButtonHandler(person: $person, pressed_button: $current_btn, db: $db);
    } else {
        $last_level = $db->read(
            table: 'buttons',
            conditions: ['id' => $current_level['belong_to']],
            single: true
        );

        $last_btn = Button::fromDbRow($last_level);
        $person->setProgress(null);
        normalButtonHandler(person: $person, pressed_button: $last_btn, db: $db);
    }
}

/**
 * Logic for 'Cancel' button.
 */
#[NoReturn]
function cancelButton(Person $person, $db): void
{
    $person->setProgress(null);
    backButton($person, $db);
}


// ==========================================
//          LEVEL 1: HOLDINGS
// ==========================================

#[NoReturn]
function level_1(
    Person          $person,
    DatabaseManager $db,
    ?Button         $pressed_button = null,
    ?array          $message = null,
    ?array          $callback_query = null): void
{

    // Initialize button object if null is given
    $current_button = $pressed_button ?? Button::fromDbRow($db->read('buttons', ['id' => 1], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $current_button->getId(), admin: $person->isAdmin(), db: $db);

    // Add '➕ افزودن دارایی جدید' button to the keyboard
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن دارایی جدید', '/assets/add_holding.html')]);

    $data = [
        'chat_id' => $person->getChatId(),
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
                table: 'persons',
                data: [
                    'last_btn' => $pressed_button->getId(),
                    'progress' => null,
                ],
                conditions: ['id' => $person->getId()]
            );
            sendAllHoldings($person, $db);
        }
        exit();
    }

    if ($callback_query)
        handleHoldingsCallback($person, $callback_query, $message);

    if ($message && isset($message['web_app_data']))
        handleHoldingsWebAppData($person, $data, $message, $db);
    if ($message && !isset($message['web_app_data']))
        handleHoldingsTextMessage($person, $data, $message, $db);
}

#[NoReturn]
function handleHoldingsCallback(Person $person, array $callback_query, array $message): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    // $query_data = json_decode($callback_query['data'], true);

    sendToTelegram('editMessageText', [
        'chat_id' => $person->getChatId(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ]);
    exit();
}

#[NoReturn]
function handleHoldingsWebAppData(Person $person, array $data, array $message, DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);
    $action = $web_app_data['action'] ?? null;

    if ($action === 'add') {

        $new_holding = $web_app_data['holding'];
        try {
            $db->create(
                table: 'holdings',
                data: [
                    "person_id" => $person->getId(),
                    "asset_id" => $new_holding["asset_id"],
                    "amount" => $new_holding["amount"],
                    "avg_price" => $new_holding["avg_price"],
                    "date" => $new_holding["date"],
                    "time" => $new_holding["time"],
                    "note" => $new_holding["note"],
                ]);

            $data['text'] = '✅ دارایی جدید با موفقیت ثبت شد.';
            sendToTelegram('sendMessage', $data);

        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {

                $data['text'] = ' ' .
                    'شما از قبل این دارایی را در سیستم ثبت کرده اید.' . "\n" .
                    'درصورت تمایل برای ثبت تغییرات، دارایی ثبت شده را ویرایش کنید.';
                sendToTelegram('sendMessage', $data);

                $holding = $db->read(
                    table: 'holdings h',
                    conditions: [
                        'h.asset_id' => $new_holding["asset_id"],
                        'h.person_id' => $person->getId()
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

                    // Update user's progress to 'view_holding'
                    $db->update(
                        table: 'persons',
                        data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                        conditions: ['id' => $person->getId()]
                    );

                    // Send holding's detail to telegram
                    sendHoldingDetail($holding, $data);
                }

                exit();

            } else {
                sendToTelegram('sendMessage', [
                    'text' => '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->errorInfo[2],
                    'chat_id' => $person->getChatId()
                ]);
                error_log(
                    'Holding: ' . json_encode($new_holding) . "\n" .
                    'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));

            }
        }
        sendAllHoldings($person, $db);
        exit();
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
            $data['text'] = '❌ خطای پایگاه داده در ویرایش دارایی: ' . $e->errorInfo[2];
            error_log(
                'Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT));

        }

        sendToTelegram('sendMessage', $data);
        sendAllHoldings($person, $db);
        exit();
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
            $data['text'] = '❌ خطای پایگاه داده درحذف دارایی: ' . $e->errorInfo[2];
            error_log(
                'Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );

        }

        sendToTelegram('sendMessage', $data);
        sendAllHoldings($person, $db);
        exit();
    }

    $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
    sendToTelegram('sendMessage', $data);

    sendAllHoldings($person, $db);
    exit();
}

#[NoReturn]
function handleHoldingsTextMessage(Person $person, array $data, array $message, DatabaseManager $db): void
{
    sendToTelegram('deleteMessage', ['chat_id' => $person->getChatId(), 'message_id' => $message['message_id']]);

    $matched = preg_match('/^\/start viewHolding_holdingId(\d+)(_mssgId(\d+))?$/m', $message['text'], $matches);
    if ($matched && !empty($matches[1])) {
        $holding_id = $matches[1];

        $holding = $db->read(
            table: 'holdings h',
            conditions: [
                'h.id' => $holding_id,
                'h.person_id' => $person->getId()
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

            // Delete holdings message
            sendToTelegram('deleteMessage', ['chat_id' => $person->getChatId(), 'message_id' => $matches[3]]);

            // Send holding's detail to telegram
            sendHoldingDetail($holding, $data);

            // Update user's progress to 'view_holding'
            $db->update(
                table: 'persons',
                data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                conditions: ['id' => $person->getId()]
            );
            exit();

        } else $data['text'] = 'دارایی با این مشخصه یافت نشد!';
    } else $data['text'] = 'پیام نامفهوم است!';

    // Add '✏ ویرایش' button to the keyboard if use is viewing a holding.
    // This works with irreverent texts and wrong holding id.
    $progress = json_decode($person->getProgress(), true);
    if ($progress && key($progress) === 'view_holding') {
        $holding = $db->read(
            table: 'holdings h',
            conditions: [
                'h.id' => $progress['view_holding']['holding_id'],
                'h.person_id' => $person->getId()
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
            array_unshift($data['reply_markup']['keyboard'], [
                createWebAppBtn(
                    text: '✏ ویرایش ' . $holding['asset_name'],
                    path: '/assets/add_holding.html',
                    params: ['data' => base64_encode(json_encode($holding))])
            ]);
        }
    }

    sendToTelegram('sendMessage', $data);
    exit();
}

function sendAllHoldings(Person $person, DatabaseManager $db): void
{
    $holdings = $db->read(
        table: 'holdings h',
        conditions: ['person_id' => $person->getId()],
        selectColumns: '
            h.*,
            a.name as asset_name,
            a.price as current_price,
            a.base_currency,
            a.exchange_rate as base_rate',
        join: 'INNER JOIN assets a ON h.asset_id = a.id');

    if ($holdings) {
        $temp_mssg = sendLoadingMessage($person->getChatId(), 'در حال دریافت اطلاعات دارایی‌ها ...');
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
                'chat_id' => $person->getChatId(),
                'message_id' => $temp_mssg['result']['message_id'],
                'text' => $text,
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $person->getChatId(), 'text' => 'شما هیچ دارایی‌ای ثبت نکرده‌اید.']);
    }

    $db->update('persons', ['progress' => null], ['id' => $person->getId()]);
}

/**
 * Automatically adds edit button
 */
function sendHoldingDetail(array $holding, array $data): void
{
    $data['text'] = createHoldingDetailText($holding);
    array_unshift($data['reply_markup']['keyboard'], [
        createWebAppBtn('✏ ویرایش ' . beautifulNumber($holding['asset_name'], null), '/assets/add_holding.html', ['data' => base64_encode(json_encode($holding))])
    ]);

    sendToTelegram('sendMessage', $data);
}


// ==========================================
//          LEVEL 2: LOANS & INSTALLMENTS
// ==========================================

#[NoReturn]
function level_2(
    Person          $person,
    DatabaseManager $db,
    ?Button         $pressed_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void
{
    // Initialize button object if null is given
    $current_button = $pressed_button ?? Button::fromDbRow($db->read('buttons', ['id' => 2], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $current_button->getId(), admin: $person->isAdmin(), db: $db);

    // Add '➕ افزودن وام جدید' button to the keyboard
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن وام جدید', '/assets/add_loan.html')]);

    $data = [
        'chat_id' => $person->getChatId(),
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
                table: 'persons',
                data: [
                    'last_btn' => $current_button->getId(),
                    'progress' => null,
                ],
                conditions: ['id' => $person->getId()]
            );

            // Send informative message
            sendAllLoans($person, $db);
        }
        exit();
    }

    if ($callback_query)
        handleLoansCallback($person, $callback_query, $data, $message, $db);

    if ($message && isset($message['web_app_data']))
        handleLoansWebAppData($person, $data, $message, $db);
    if ($message && !isset($message['web_app_data']))
        handleLoansTextMessage($person, $data, $message, $db);
}

#[NoReturn]
function handleLoansCallback(Person $person, array $callback_query, array $data, array $message, $db): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

    $query_data = json_decode($callback_query['data'], true);
    if (!$query_data) exit();

    $query_key = array_key_first($query_data);
    $data['message_id'] = $message['message_id'];

    switch ($query_key) {
        case 'loan_list':
            sendToTelegram('deleteMessage', ['chat_id' => $person->getChatId(), 'message_id' => $message['message_id']]);

            sendToTelegram('sendMessage', $data);
            sendAllLoans($person, $db);
            break;
        default:

            sendToTelegram('editMessageText', [
                'chat_id' => $person->getChatId(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            break;
    }
    exit();
}

#[NoReturn]
function handleLoansWebAppData(Person $person, array $data, array $message, DatabaseManager $db): void
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
                    'person_id' => $person->getId(),
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
                'chat_id' => $person->getChatId()
            ]);
            error_log(
                'Loan: ' . json_encode($new_loan) . "\n" .
                'Error: ' . $e->getMessage());
        }
        sendAllLoans($person, $db);
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
                conditions: ['id' => $web_app_data['id'], 'person_id' => $person->getId()]);
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
        sendAllLoans($person, $db);
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
        sendAllLoans($person, $db);
        exit();
    }

    $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
    sendToTelegram('sendMessage', $data);

    sendAllLoans($person, $db);
    exit();

}

#[NoReturn]
function handleLoansTextMessage(Person $person, array $data, array $message, DatabaseManager $db): void
{
    sendToTelegram('deleteMessage', ['chat_id' => $person->getChatId(), 'message_id' => $message['message_id']]);

    // Show loan detail
    $matched = preg_match("/^\/start showLoan_loanId(\d+?)_mssgId(\d+?)$/m", $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        $loan = getLoansWithInstallments(['l.id' => $matches[1], 'l.person_id' => $person->getId()], $db)[0];

        if ($loan) {

            // Delete loans message
            sendToTelegram('deleteMessage', ['chat_id' => $person->getChatId(), 'message_id' => $matches[2]]);

            sendLoanDetail($loan, $data);

            $db->update(
                table: 'persons',
                data: ['progress' => json_encode(['view_loan' => ['loan_id' => $loan['id']]])],
                conditions: ['id' => $person->getId()]
            );
            exit();
        }
    }

    // Toggle Installment Payment
    $matched = preg_match("/^\/start toggleInstPayment_instId(\d+?)_mssgId(\d+?)$/m", $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        $installment = $db->read(
            table: 'installments i',
            conditions: ['i.id' => $matches[1], 'l.person_id' => $person->getId()],
            single: true,
            selectColumns: 'i.*, l.person_id',
            join: 'LEFT JOIN loans l ON i.loan_id = l.id'
        );

        if ($installment) {
            $db->update(
                table: 'installments',
                data: ['is_paid' => !$installment['is_paid']],
                conditions: ['id' => $installment['id']]
            );

            $loan = getLoansWithInstallments(['l.id' => $installment['loan_id'], 'l.person_id' => $person->getId()], $db)[0];

            if ($loan) {
                sendToTelegram('editMessageText', [
                    'chat_id' => $person->getChatId(),
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
    $progress = json_decode($person->getProgress(), true);
    if ($progress && key($progress) === 'view_loan') {
        $loan = getLoansWithInstallments(['l.id' => $progress['view_loan']['loan_id'], 'l.person_id' => $person->getId()], $db)[0];
        if ($loan) {
            array_unshift($data['reply_markup']['keyboard'], [
                createWebAppBtn(
                    text: '✏ ویرایش وام «' . $loan['name'] . '»',
                    path: '/assets/add_loan.html',
                    params: ['data' => base64_encode(json_encode($loan))])
            ]);
        }
    }

    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit();

}

function sendAllLoans(Person $person, DatabaseManager $db): void
{
    $loans = getLoansWithInstallments(['l.person_id' => $person->getId()], $db);

    if ($loans) {

        $temp_mssg = sendLoadingMessage($person->getChatId(), 'در حال دریافت اطلاعات وام‌ها ...');
        if ($temp_mssg) {
            sendToTelegram('editMessageText', [
                'chat_id' => $person->getChatId(),
                'message_id' => $temp_mssg['result']['message_id'],
                'text' => createLoansView($loans, $temp_mssg['result']['message_id']),
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $person->getChatId(), 'text' => 'هیچ وام یا قسطی برای شما ثبت نشده است!']);
    }

    $db->update('persons', ['progress' => null], ['id' => $person->getId()]);
}

function sendLoanDetail(array $loan, array $data): void
{

    $data['text'] = 'جزئیات وام «' . $loan['name'] . '»';
    array_unshift($data['reply_markup']['keyboard'], [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/add_loan.html', ['data' => base64_encode(json_encode($loan))])]);

    sendToTelegram('sendMessage', $data);

    $temp_mssg = sendLoadingMessage($data['chat_id'], 'در حال دریافت اطلاعات اقساط ...');
    if ($temp_mssg) {

        $data['message_id'] = $temp_mssg['result']['message_id'];
        $data['text'] = createLoanDetailText($loan, $temp_mssg['result']['message_id']);
        $data['parse_mode'] = 'MarkdownV2';
        $data['reply_markup'] = ['inline_keyboard' => [[['text' => 'برگشت به لیست وام‌ها', 'callback_data' => json_encode(['loan_list' => null])]]]];;

        sendToTelegram('editMessageText', $data);
    }
}

// ==========================================
//          LEVEL 5: PRICES
// ==========================================

#[NoReturn]
function level_5(
    Person          $person,
    DatabaseManager $db,
    ?Button         $pressed_button = null,
    ?array          $message = null,
    ?array          $callback_query = null
): void
{
    // Initialize button object if null is given
    $current_button = $pressed_button ?? Button::fromDbRow($db->read('buttons', ['id' => 5], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $current_button->getId(), admin: $person->isAdmin(), db: $db);

    $data = [
        'chat_id' => $person->getChatId(),
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
            table: 'persons',
            data: [
                'last_btn' => $current_button->getId(),
                'progress' => null,
            ],
            conditions: ['id' => $person->getId()]
        );

        // Send initial message
        sendToTelegram('sendMessage', $data);

        // Send favorites message
        sendFavorites($person, $db);
    }

    if ($message) handlePricesTextMessage($data, $message, $asset_types, $db);
    if ($callback_query) handlePricesCallback($person, $callback_query, $message, $asset_types, $db);
}

#[NoReturn]
function handlePricesCallback(Person $person, array $callback_query, array $message, array $asset_types, DatabaseManager $db): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

    $data = [
        'chat_id' => $person->getChatId(),
        'message_id' => $message['message_id'],
    ];

    $query_data = json_decode($callback_query['data'], true);
    if (!$query_data)
        sendToTelegram('deleteMessage', $data);

    $query_key = array_key_first($query_data);
    switch ($query_key) {
        case 'edit_fav':

            $action = $query_data['edit_fav'];

            // Show main menu for editing favorites
            if ($action === null) {

                $data['text'] = 'عملیات مورد نظر را انتخاب کنید:';
                $data['reply_markup']['inline_keyboard'] = [
                    [['text' => 'حذف', 'callback_data' => json_encode(['edit_fav' => 'remove'])]], // TODO: Think of something for when user has no favorites
                    [['text' => 'افزودن', 'callback_data' => json_encode(['edit_fav' => 'add'])]],
                    [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['show_favorites' => null])]],
                ];
                sendToTelegram('editMessageText', $data);
                setLiveMessage($person->getId(), false, $message['message_id'], $db);
                exit();

            }

            // Show list of asset types for adding new asset
            if ($action === 'add') {

                $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
                $data['reply_markup']['inline_keyboard'] = [
                    [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])]]
                ];

                foreach ($asset_types as $index => $asset_type) {
                    $data['reply_markup']['inline_keyboard'] = array_unshift(
                        $data['reply_markup']['inline_keyboard'],
                        [['text' => $asset_type, 'callback_data' => json_encode(['add_fav' => ['asset_type' => $index]])]] //TODO: Test if can handle the length of type name
                    );
                }

                sendToTelegram('editMessageText', $data);
                setLiveMessage($person->getId(), false, $message['message_id'], $db);
                exit();

            }

            // Show list of favorites to choose for deletion
            if ($action === 'remove') {

                $favorites = $db->read(
                    table: 'favorites f',
                    conditions: ['f.person_id' => $person->getId()],
                    selectColumns: 'a.*, f.id as fav_id',
                    join: 'JOIN assets a ON a.name=f.asset_name',
                    orderBy: ['asset_type' => 'DESC', 'f.id' => 'ASC']
                );
                $data['reply_markup']['inline_keyboard'] = [
                    [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])]]
                ];
                if ($favorites) {

                    $data['text'] = 'کدام گزینه را می‌خواهید حذف کنید؟';

                    // Create inline buttons for favorites
                    foreach ($favorites as $favorite) {
                        $data['reply_markup']['inline_keyboard'] = array_unshift(
                            $data['reply_markup']['inline_keyboard'],
                            [['text' => $favorite['asset_name'], 'callback_data' => json_encode(['del_fav' => ['fav_id' => $favorite['id']]])]]
                        );
                    }
                } else $data['text'] = 'لیست علاقه‌مندی‌های شما خالی‌ست!';

                sendToTelegram('editMessageText', $data);
                setLiveMessage($person->getId(), false, $message['message_id'], $db);
                exit();

            }

            break;
        case 'add_fav':
//            handleAddFavoriteCallback($person, $query_data, $asset_types, $data, $db);
            break;
        case 'del_fav':
//            handleDeleteFavoriteCallback($person, $query_data, $data, $db);
            break;
        case 'set_live':
            deleteOldLiveMessage($person, $message['message_id'], $db);
            setLiveMessage($person->getId(), $query_data['set_live'], $message['message_id'], $db);
            sendFavorites($person, $db, $message['message_id']);
            break;

        case 'price_alert':
            // Logic for price alerts can be added here
            break;
        case 'show_favorites':
            sendFavorites($person, $db, $message['message_id']);
            break;
        default:

            sendToTelegram('editMessageText', [
                'chat_id' => $person->getChatId(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            break;
    }
    exit();
}

function handleAddFavoriteCallback(Person $person, array $query_data, array $asset_types, array $data, DatabaseManager $db): void
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
            data: ['person_id' => $person->getId(), 'asset_id' => $inner_val]
        );
        sendToTelegram('editMessageText', [
            'chat_id' => $person->getChatId(),
            'message_id' => $data['message_id'],
            'text' => $result ? '✅ علاقه‌مندی جدید افزوده شد!' : '❌ خطای پایگاه داده!'
        ]);

        renderFavoritesList($person, null, false, $db); // Send as new message
    }
}

function handleDeleteFavoriteCallback(Person $person, array $query_data, array $data, DatabaseManager $db): void
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
            'chat_id' => $person->getChatId(),
            'message_id' => $data['message_id'],
            'text' => $result ? '✅ حذف موفقیت آمیز بود!' : '❌ خطای پایگاه داده!'
        ]);

        renderFavoritesList($person, null, false, $db);
    }
}

/**
 * Activate/Inactivate current message in the database as `live_price`.
 *
 * @param int|string $person_id
 * @param bool $activate On false only works on existing record with the same `$message_id`
 * @param int|string $message_id The ID of the message to set as live price message
 * @param DatabaseManager $db
 * @return bool|null Activation state on success or `null` on database error
 */
function setLiveMessage(int|string $person_id, bool $activate, int|string $message_id, DatabaseManager $db): bool|null
{
    $db_result = false;
    try {
        if ($activate === true)
            $db_result = $db->upsert(
                table: 'special_messages',
                data: [
                    'person_id' => $person_id,
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
                    'person_id' => $person_id,
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

function renderFavoritesList(Person $person, ?int $message_id_to_edit, bool $is_edit, DatabaseManager $db): void
{
    $favorites = $db->read(
        table: 'favorites f',
        conditions: ['f.person_id' => $person->getId()],
        selectColumns: 'a.*, f.id as fav_id',
        join: 'JOIN assets a ON a.name=f.asset_name',
        orderBy: ['asset_type' => 'DESC', 'f.id' => 'ASC']
    );
    $live_mssg = $db->read(
        table: 'special_messages',
        conditions: [
            'person_id' => $person->getId(),
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
        'chat_id' => $person->getChatId(),
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
            sendToTelegram('deleteMessage', ['chat_id' => $person->getChatId(), 'message_id' => $live_mssg['message_id']]);
        }
    }
}

function disableLivePriceMessage(Person $person, int $message_id, DatabaseManager $db): void
{
    $live_mssg = $db->read(
        table: 'special_messages',
        conditions: [
            'person_id' => $person->getId(),
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

/**
 * Edits a message and adds favorites text and inline keyboard.
 * Doesn't send the initial message containing bottom keyboard.
 * Doesn't delete any message or set change live price updates.
 *
 * @param Person $person
 * @param DatabaseManager $db
 * @param int|string|null $message_id ID of the message to be edited. If `null`, a new message is sent and immediately edited
 * @return void
 *
 * TODO: Add markdown
 */
function sendFavorites(Person $person, DatabaseManager $db, int|string|null $message_id = null): void
{
    $message_id = ($message_id !== null)
        ? $message_id
        : sendLoadingMessage($person->getChatId(), 'در حال دریافت اطلاعات لیست علاقه‌مندی‌ها ...')['result']['message_id'];

    sendToTelegram('editMessageText', [
        'chat_id' => $person->getChatId(),
        'message_id' => $message_id,
        'text' => createFavoritesText(null, $person->getId(), $db),
        'reply_markup' => ['inline_keyboard' => createFavoritesInlineKeyboard($person, $message_id, $db)]
    ]);
}

// ==========================================
//          LEVEL 6: ARTIFICIAL INTELLIGENCE
// ==========================================

#[NoReturn]
function level_6(Person $person, DatabaseManager $db, ?array $message = null, ?array $callback_query = null): void
{
    if ($callback_query) {
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        sendToTelegram('editMessageText', [
            'chat_id' => $person->getChatId(),
            'message_id' => $message['message_id'],
            'text' => 'این پیام منقضی شده است.'
        ]);
    } else {
        sendToTelegram('sendMessage', [
            'chat_id' => $person->getChatId(),
            'text' => 'در حال توسعه...',
            'reply_markup' => [
                'keyboard' => createKeyboardsArray(6, $person->isAdmin(), $db),
                'resize_keyboard' => true,
                'input_field_placeholder' => 'هوش مصنوعی',
            ]
        ]);
        $db->update(
            table: 'persons',
            data: ['last_btn' => 6, 'progress' => null],
            conditions: ['id' => $person->getId()]
        );
    }
    exit();
}


// ==========================================
//          DATA HANDLING & UI HELPERS
// ==========================================

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

function deleteOldLiveMessage(Person $person, int|string $message_id, DatabaseManager $db): bool
{
    $live_mssg = $db->read(
        table: 'special_messages',
        conditions: [
            'person_id' => $person->getId(),
            'type' => 'live_price',
            'is_active' => true,
            '!message_id' => $message_id,
        ],
        single: true
    );
    if ($live_mssg) sendToTelegram('deleteMessage', ['chat_id' => $person->getChatId(), 'message_id' => $live_mssg['message_id']]);

    return boolval($live_mssg);
}

/**
 * Checks the database for current message
 * to see if it's registered for live message
 *
 * @param Person $person
 * @param int $message_id The ID of current message to be checked for live update
 * @param DatabaseManager $db
 * @return array[] Array of array of inline buttons for favorites message
 *
 * TODO: manage inline buttons for no registered favorite
 */
function createFavoritesInlineKeyboard(Person $person, int $message_id, DatabaseManager $db): array
{
    $live_mssg = $db->read(
        table: 'special_messages',
        conditions: [
            'person_id' => $person->getId(),
            'type' => 'live_price',
            'is_active' => true,
            'message_id' => $message_id,
        ],
        single: true
    );

    return [
        ($live_mssg)
            ? [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]]
            : [['text' => 'نمایش زنده قیمت‌ها ▶', 'callback_data' => json_encode(['set_live' => true])]],
//        [['text' => 'هشدار قیمت', 'callback_data' => json_encode(['price_alert' => null])]],
        [['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]],
    ];
}


// ==========================================
//          TEXT FORMATTING HELPERS
// ==========================================

function createHoldingDetailText(
    array   $holding,
    ?string $markdown = null,
    array   $attributes = [
        'date',
        'org_amount',
        'org_price',
        'new_price',
        'org_total_price',
        'new_total_price',
        'profit'
    ],
    ?string $mssg_id = null): string
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
        $link = "https://t.me/" . BOT_ID . "?start=toggleInstPayment_instId{$inst['id']}_mssgId{$mssg_id}";

        $text .= "\n‏    $num\) {$inst['is_paid']}  $date:  $amt    [تغییر وضعیت پرداخت]($link)";
    }
    return $text;
}

/**
 * @param array|null $assets Array of assets, must be ordered by `asset_type`
 * @param int|string|null $person_id Used to fetch favorites if `$assets` is null
 * @param DatabaseManager|null $db Used to fetch favorites if `$assets` is null
 * @return string Well-structured text for favorites message
 */
function createFavoritesText(?array $assets, int|string|null $person_id = null, ?DatabaseManager $db = null): string
{
    try {
        $assets = $assets ?? $db->read(
            table: 'favorites f',
            conditions: ['f.person_id' => $person_id],
            selectColumns: 'a.*, f.id as fav_id',
            join: 'JOIN assets a ON a.name=f.asset_name',
            orderBy: ['asset_type' => 'DESC', 'f.id' => 'ASC']
        );
    } catch (Exception $e) {
        error_log('createFavoritesText: ' . $e->getMessage());
        exit();
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
