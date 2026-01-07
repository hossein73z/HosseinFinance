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
// ----------------------------

// Load the DatabaseManager class for DB operations.
require_once 'Libraries/DatabaseManager.php';
// Load functions for external APIs (Telegram, MajidAPI, etc.).
require_once 'Functions/ExternalEndpointsFunctions.php';
// Load functions for generating Telegram keyboards.
require_once 'Functions/KeyboardFunctions.php';
// Load helper functions for number formatting and validation.
require_once 'Functions/StringHelper.php';

/**
 * Registers a shutdown function to catch fatal errors.
 * Logs to Vercel/Server stderr logs instead of a local file.
 */
register_shutdown_function(function () {
    $error = error_get_last();
    // Check if the script stopped due to a fatal error.
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR])) {
        $log_message = "CRITICAL SCRIPT CRASH: Type: {$error['type']} | Message: {$error['message']} | File: {$error['file']} | Line: {$error['line']}";
        error_log($log_message); // Writes to Vercel Runtime Logs
    }
});

// --- INITIAL WEBHOOK PROCESSING ---

// Set the response content type to JSON.
header('Content-Type: application/json');

// Get the raw data from the request body.
$input = file_get_contents('php://input');

// Check for the Telegram Secret Token Header (Best Practice)
// OR the 'secret' query parameter (For manual dev/testing).
$header_secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
$query_secret = $_GET['secret'] ?? null;

// Validate against the SHARED_SECRET constant defined above via env vars
if (($header_secret !== SHARED_SECRET) && ($query_secret !== SHARED_SECRET)) {
    error_log("SECURITY ALERT: Access denied. Invalid secret. Input size: " . strlen($input));
    http_response_code(403);
    die(json_encode(['status' => 'unauthorized', 'message' => 'Invalid Secret Token']));
}

// Send a 200 OK response immediately so Telegram knows we received the update.
http_response_code(200);

// Exit if no input data was received.
if (empty($input)) {
    error_log("[WARN] No input data received via Webhook.");
    exit();
}

// Decode the JSON Telegram update.
$update = json_decode($input, true);

// Log error if JSON decoding failed.
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("[ERROR] Invalid JSON received: " . json_last_error_msg());
    exit();
}

// Get Database Instance.
try {
    $db = DatabaseManager::getInstance(
        host: getenv('DB_HOST'),
        db: getenv('DB_NAME'),
        user: getenv('DB_USER'),
        pass: getenv('DB_PASS'),
        port: getenv('DB_PORT') ?: '3306',
    );
} catch (Exception $e) {
    error_log($e->getMessage());
    exit();
}

// --- MAIN UPDATE ROUTER ---

// Process 'message' updates (Text, Commands, Buttons).
if (isset($update['message'])) {

    $message = $update['message'];
    $chat = $message['chat'];

    // Check/Register User
    $person = $db->read('persons', ['chat_id' => $chat['id']], true);

    if (!$person) {
        // --- New User Registration ---
        $admins = $db->read('persons', ['is_admin' => 1]);

        $new_user_id = $db->create(
            'persons',
            [
                'chat_id' => $chat['id'],
                'first_name' => $chat['first_name'] ?? 'N/A',
                'last_name' => $chat['last_name'] ?? null,
                'username' => $chat['username'] ?? null,
                'progress' => null,
                // First user becomes admin automatically
                'is_admin' => ($admins) ? 0 : 1,
                'last_btn' => 0
            ]
        );

        if ($new_user_id) $person = $db->read('persons', ['chat_id' => $chat['id']], true);
        else {
            error_log("[ERROR] Failed to create new user: " . $chat['id']);
            return;
        }
    }

    // ------------------------------
    // ----- The core bot logic -----
    // ------------------------------

    // Global Command Routing (Overrides everything)
    if ($message['text'] == '/holdings') level_1($person);
    if ($message['text'] == '/loans') level_2($person);
    if ($message['text'] == '/prices') level_5($person);
    if ($message['text'] == '/ai') level_6($person);

    // Check if the received text is a button
    $pressed_button = getPressedButton(
        text: $message['text'] ?? '',
        parent_btn_id: $person['last_btn'],
        admin: $person['is_admin'],
        db: $db
    );

    // Default Routing
    choosePath(
        pressed_button: $pressed_button,
        message: $message,
        person: $person
    );

} elseif (isset($update['callback_query'])) {
    // Process 'Inline' button presses.

    $callback_query = $update['callback_query'];
    $message = $callback_query['message'];
    $chat = $message['chat'];

    $person = $db->read('persons', ['chat_id' => $chat['id']], true);

    if ($person !== false) {

        choosePath(
            message: $message,
            person: $person,
            callback_query: $callback_query
        );
    }
    exit();
} else {
    // Log unhandled update types (e.g., edited_message, channel_post).
    error_log("[INFO] Unhandled update type received.");
    exit();
}

// Close DB connection.
DatabaseManager::closeConnection();


// --- CORE ROUTING FUNCTIONS ---

/**
 * Routes the message to the appropriate handler.
 */
function choosePath(
    array|null|false $pressed_button = null,
    array|null|false $message = null,
    array|null       $person = null,
    array|null       $callback_query = null
): void
{
    global $db;

    if ($callback_query) { // Handle Callback Queries

        if ($person['last_btn'] == 1) level_1(person: $person, message: $message, callback_query: $callback_query); // Holdings
        if ($person['last_btn'] == 2) level_2(person: $person, message: $message, callback_query: $callback_query); // Loans
        if ($person['last_btn'] == 5) level_5(person: $person, message: $message, callback_query: $callback_query); // prices
        if ($person['last_btn'] == 6) level_6(person: $person, message: $message, callback_query: $callback_query); // AI

        // Send default "Unrecognized" message
        $data = [
            'text' => 'درخواست نامفهوم بود!',
            'message_id' => $message['message_id'],
            'chat_id' => $person['chat_id'],
        ];
        sendToTelegram('editMessageText', $data);

    } else { // Handle Text Messages

        if (!$pressed_button) { // Received text is not a button

            // Route to active level handler (Input Step)
            if ($person['last_btn'] == "1") level_1($person, $message); // Holdings
            if ($person['last_btn'] == "2") level_2($person, $message); // Loans
            if ($person['last_btn'] == "5") level_5($person, $message); // Prices
            if ($person['last_btn'] == "6") level_6($person, $message); // AI

            // Send default "Unrecognized" message (With level keyboard)
            $data = [
                'text' => 'پیام نامفهوم است!',
                'chat_id' => $person['chat_id'],
                'reply_markup' => [
                    'keyboard' => createKeyboardsArray($person['last_btn'], $person['is_admin'], $db),
                    'resize_keyboard' => true,
                    'is_persistent' => true,
                ]];
            sendToTelegram('sendMessage', $data);

        } else { // Received text is a button

            if (str_starts_with($pressed_button['id'], "s")) { // Pressed button is a special buttons

                if ($pressed_button['id'] === "s0") backButton(person: $person);
                if ($pressed_button['id'] === "s1") cancelButton(person: $person);

            } else { // Pressed button is a normal buttons

                switch ($pressed_button['id']) {
                    case "1":
                        level_1($person);
                    case "2":
                        level_2($person);
                    case "5":
                        level_5($person);
                    case "6":
                        level_6($person);
                    default:
                        // Send button's text as message and update user's `last_btn`
                        $response = sendToTelegram('sendMessage', [
                            'text' => json_decode($pressed_button['attrs'], true)['text'],
                            'chat_id' => $person['chat_id'],
                            'reply_markup' => [
                                'keyboard' => createKeyboardsArray($pressed_button['id'], $person['is_admin'], $db),
                                'resize_keyboard' => true,
                                'is_persistent' => true,
                                'input_field_placeholder' => json_decode($pressed_button['attrs'], true)['text'],
                            ]
                        ]);
                        if ($response) $db->update('persons', ['last_btn' => $pressed_button['id'], 'progress' => null], ['id' => $person['id']]);
                        break;
                }
            }
        }
    }
}

/**
 * Logic for 'Back' button. Handles multi-step form rollback or menu navigation.
 */
function backButton(array $person): void
{
    global $db;

    if ($person['progress']) $progress = json_decode($person['progress'], true);
    else $progress = null;

    $current_level = $db->read('buttons', ['id' => $person['last_btn']], true);

    if ($progress) {

        // Reset progress, stay on level
        $person['progress'] = null;
        choosePath(pressed_button: $current_level, message: false, person: $person);

    } else {

        // Go to parent menu
        $last_level = $db->read('buttons', ['id' => $current_level['belong_to']], true);

        $person['progress'] = null;
        choosePath(pressed_button: $last_level, person: $person);

    }
}

/**
 * Clears progress and goes back.
 */
function cancelButton(array $person): void
{
    $person['progress'] = null;
    backButton($person);
}


// --- LEVEL HANDLERS ---

/**
 * Level 1: My Holdings
 */
#[NoReturn]
function level_1(array $person, array|null $message = null, array|null $callback_query = null): void
{
    global $db;

    $telegram_method = 'sendMessage';
    $data = [
        'chat_id' => $person['chat_id'],
        'text' => 'دارایی‌ها',
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(1, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => 'دارایی‌ها',
        ]
    ];

    // Add web_app button(s)
    $progress = json_decode($person['progress'], true);
    if ($progress && array_key_first($progress) == 'view_holding') { // User is viewing a holding

        // Read holding to send to the edit page
        $holding = $db->read(
            table: 'holdings',
            conditions: [
                'id' => $progress['view_holding']['holding_id'],
                'person_id' => $person['id']],
            single: true
        );

        // Add 'Edit Holding' Button
        if ($holding) {
            // Edit holding button
            $data['reply_markup']['keyboard'] = array_merge([[[
                'text' => '✏ ویرایش',
                'web_app' => [
                    'url' => 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_holding.html' . '?' .
                        'api_url=' . 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/api/ExternalConnections/api.php' . '&' .
                        'api_key=' . getenv('DB_API_SECRET') . '&' .
                        'data=' . base64_encode(json_encode($holding))
                ]
            ]]], $data['reply_markup']['keyboard']);
        }

    }

    // Add 'Add Holding' button
    $data['reply_markup']['keyboard'] = array_merge([[[
        'text' => '➕ افزودن دارایی جدید',
        'web_app' => [
            'url' => 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_holding.html?' .
                'api_url=' . 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/api/ExternalConnections/api.php&' .
                'api_key=' . getenv('DB_API_SECRET')
        ]
    ]]], $data['reply_markup']['keyboard']);

    // Retrieve user holdings
    $holdings = $db->read(
        table: 'holdings h',
        conditions: ['person_id' => $person['id']],
        selectColumns: '
            h.*,
            a.name as asset_name,
            a.price as current_price,
            a.base_currency,
            a.exchange_rate as base_rate',
        join: 'INNER JOIN assets a ON h.asset_id = a.id');

    // Received request is a callback query
    if ($callback_query) {

        // Answer the query
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

        $query_data = json_decode($callback_query['data'], true);
        if ($query_data == 'null') exit();

        // Delete the message generating the callback
        $telegram_method = 'editMessageText';
        unset($data['reply_markup']);
        $data['text'] = 'این پیام منقضی شده است.';
        $data['message_id'] = $message['message_id'];

    }

    // User has just the entered
    if (!$callback_query && !$message) {

        if ($holdings) { // User has registered holdings

            // Send a message just to show the keyboards
            $response = sendToTelegram('sendMessage', $data);
            if (!$response) exit();

            $data['text'] = 'در حال دریافت اطلاعات دارایی‌ها ...';
            $data['reply_markup'] = ['inline_keyboard' => [
                [['text' => '...', 'callback_data' => 'null']]
            ]];
            $temp_mssg = sendToTelegram('sendMessage', $data);

            if ($temp_mssg) {
                try {
                    $data['text'] = "دارایی‌های ثبت شده‌ی شما:\n";
                    $total_profit = 0;
                    foreach ($holdings as $holding) {
                        $total_profit += $holding['amount'] * ($holding['current_price'] - $holding['avg_price']) * $holding['base_rate'];
                        $data['text'] .= "\n" . createHoldingDetailText(
                                holding: $holding,
                                markdown: 'MarkdownV2',
                                attributes: ['org_amount', 'org_total_price', 'profit'],
                                mssg_id: $temp_mssg['result']['message_id']
                            );
                    }
                    $total_profit = ($total_profit >= 0) ?
                        "🟢 کل سود: " . beautifulNumber($total_profit) . ' ریال' :
                        "🔴 کل ضرر: " . beautifulNumber($total_profit) . ' ریال';
                    $total_profit = markdownScape($total_profit);
                    $data['text'] .= "\n" . $total_profit;
                    $data['parse_mode'] = "MarkdownV2";
                    $data['message_id'] = $temp_mssg['result']['message_id'];
                    unset($data['reply_markup']);
                    $telegram_method = 'editMessageText';
                } catch (Exception $e) {
                    error_log($e->getMessage());
                }
            }
        } else $data['text'] = 'شما هیچ دارایی‌ای ثبت نکرده‌اید.';

    }

    // Message received in the level 1
    if ((!$callback_query && $message)) {

        // Received message is web app data
        // This part stops the function by recalling it
        if (isset($message['web_app_data'])) {

            $web_app_data = json_decode($message['web_app_data']['data'], true);

            if ($web_app_data['action'] == 'add') {

                $holding = $web_app_data['holding'];
                $result = $db->create('holdings', [
                    "person_id" => $person['id'],
                    "asset_id" => $holding["asset_id"],
                    "amount" => $holding["amount"],
                    "avg_price" => $holding["avg_price"],
                    "date" => $holding["date"],
                    "time" => $holding["time"],
                    "note" => $holding["note"],
                ]);

                if ($result) $data['text'] = '✅ دارایی جدید با موفقیت ثبت شد.';
                else $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی جدید.';
            }
            if ($web_app_data['action'] == 'edit') {

                $result = $db->update('holdings', $web_app_data['updates'], ['id' => $web_app_data['id']]);

                if ($result) $data['text'] = '✅ دارایی با موفقیت ویرایش ثبت شد.';
                else $data['text'] = '❌ خطای پایگاه داده در ویرایش دارایی.';
            }
            if ($web_app_data['action'] == 'delete') {

                $result = $db->delete('holdings', ['id' => $web_app_data['id']], true);

                if ($result) $data['text'] = '✅ دارایی با موفقیت حذف شد.';
                else $data['text'] = '❌ خطای پایگاه داده درحذف دارایی.';

            }

            sendToTelegram($telegram_method, $data); // Send success/failure message to the user
            backButton($person);
        }

        // Check deep-link for showing holding detail
        $matched = preg_match('/^\/start viewHolding_holdingId(\d+)(_mssgId(\d+))$/m', $message['text'], $matches);
        if ($matched) {

            if ($matches[1]) {

                $index = array_search($matches[1], array_column($holdings, 'id'));
                $holding = ($index !== false) ? $holdings[$index] : null;

                if ($holding) {

                    if ($matches[3]) {
                        sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $message['message_id']]);
                        sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $matches[3]]);
                    }

                    $data['text'] = createHoldingDetailText(holding: $holding);

                    // Add web_app button to edit the holding
                    $data['reply_markup']['keyboard'][2] = $data['reply_markup']['keyboard'][1];
                    $data['reply_markup']['keyboard'][1] = [[
                        'text' => '✏ ویرایش',
                        'web_app' => [
                            'url' => 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_holding.html' . '?' .
                                'api_url=' . 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/api/ExternalConnections/api.php' . '&' .
                                'api_key=' . getenv('DB_API_SECRET') . '&' .
                                'data=' . base64_encode(json_encode($holding))
                        ]
                    ]];

                    // Set user progress
                    $db->update('persons',
                        ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                        ['id' => $person['id']]);

                } else  $data['text'] = 'دارایی با این مشخصه یافت نشد!';
            } else  $data['text'] = 'الگوی پیام اشتباه است!';
        } else $data['text'] = 'پیام نامفهوم است!';
    }

    $response = sendToTelegram($telegram_method, $data);
    if ($response && !$message && !$callback_query) $db->update('persons', ['last_btn' => 1, 'progress' => null], ['id' => $person['id']]);
    exit();
}

/**
 * Level 2: Loans And Installments
 */
#[NoReturn]
function level_2(array $person, array|null $message = null, array|null $callback_query = null): void
{
    global $db;

    $telegram_method = 'sendMessage';
    $data = [
        'chat_id' => $person['chat_id'],
        'text' => '🏦 وام و اقساط',
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(2, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => '🏦 وام و اقساط',
        ]
    ];
    // Check if user is in the middle of a progress
    if (isset($person['progress'])) {

        $progress = json_decode($person['progress'], true);
        if ($progress) {

            if (array_key_first($progress) == 'viewing_loan') {

                $loan = $db->read('loans', ['id' => $progress['viewing_loan']['person_id'], 'person_id' => $person['id']], true);
                if ($loan) {
                    array_unshift($data['reply_markup']['keyboard'], [[
                        'text' => '✏ ویرایش وام «' . $loan['name'] . '»',
                        'web_app' => [
                            'url' =>
                                'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_loan.html' .
                                '?data=' . base64_encode(json_encode($loan))]
                    ]]);
                }
            }
        }
    }
    // Add a button for creating new loan
    array_unshift($data['reply_markup']['keyboard'], [[
        'text' => '➕ افزودن وام جدید',
        'web_app' => [
            'url' =>
                'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_loan.html']
    ]]);

    if ($callback_query) {

        // Delete the message generating the callback
        unset($data['reply_markup']);
        $data['text'] = 'این پیام منقضی شده است.';
        $telegram_method = 'editMessageText';
        $data['message_id'] = $message['message_id'];

        // Answer the query
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        $query_data = json_decode($callback_query['data'], true);
        if ($query_data == 'null') exit();
        if ($query_data) {
            if (array_key_first($query_data) == 'loan_list') {
                $telegram_method = 'deleteMessage';
                sendToTelegram($telegram_method, $data);
                $person['progress'] = null;
                level_2($person);
            }
        }
    }
    if (!$callback_query) {
        if (!$message) {

            $loans = $db->read(
                table: 'loans l',
                conditions: ['l.person_id' => $person['id']],
                selectColumns: '
                    l.*,
                    JSON_ARRAYAGG(JSON_OBJECT(
                        "id", i.id,
                        "loan_id", i.loan_id,
                        "amount", i.amount,
                        "due_date", i.due_date,
                        "is_paid", i.is_paid
                    )) as installments',
                join: 'JOIN installments i on i.loan_id = l.id',
                groupBy: 'l.id'
            );

            if ($loans) {
                foreach ($loans as $index => $loan)
                    $loans[$index]['installments'] = json_decode($loan['installments'], true);

                // Send a message just to show the keyboards
                $response = sendToTelegram('sendMessage', $data);
                if (!$response) exit();

                $data['text'] = 'در حال دریافت اطلاعات وام‌ها ...';
                $data['reply_markup'] = ['inline_keyboard' => [
                    [['text' => '...', 'callback_data' => 'null']]
                ]];
                $temp_mssg = sendToTelegram('sendMessage', $data);

                if ($temp_mssg) {
                    try {
                        $data['text'] = createLoansView($loans, $temp_mssg['result']['message_id']);
                        $data['parse_mode'] = "MarkdownV2";
                        $data['message_id'] = $temp_mssg['result']['message_id'];
                        unset($data['reply_markup']);
                        $telegram_method = 'editMessageText';
                    } catch (Exception $e) {
                        error_log($e->getMessage());
                    }
                }

            } else $data['text'] = 'هیچ وام یا قسطی برای شما ثبت نشده است!';

        }
        if ($message) {
            $data['text'] = "پیام نامفهوم است!";

            if (isset($message['web_app_data'])) {
                $web_app_data = json_decode($message['web_app_data']['data'], true);

                if ($web_app_data && isset($web_app_data['loans']) & isset($web_app_data['installments'])) {

                    $loanData = $web_app_data['loans'];
                    $loan_insert_data = [
                        'person_id' => $person['id'],
                        'name' => $loanData['name'],
                        'total_amount' => $loanData['total_amount'],
                        'received_date' => $loanData['received_date'],
                        'alert_offset' => $loanData['alert_offset'],
                    ];

                    $loan_id = $db->create('loans', $loan_insert_data);

                    if ($loan_id) {
                        $new_insts = $web_app_data['installments'];
                        $count = 0;

                        foreach ($new_insts as $inst) {
                            $inst_insert_data = [
                                'person_id' => $loan_id,
                                'amount' => $inst['amount'],
                                'due_date' => $inst['due_date'],
                                'is_paid' => $inst['is_paid'] ? 1 : 0
                            ];
                            $db->create('installments', $inst_insert_data);
                            $count++;
                        }

                        $data['text'] = "✅ وام «{$loanData['name']}» با موفقیت ثبت شد.\n📊 تعداد اقساط: " . beautifulNumber($count);
                        sendToTelegram($telegram_method, $data);
                        level_2($person);

                    }
                }
                if ($web_app_data && isset($web_app_data['id']) && isset($web_app_data['updates'])) {
                    $new_insts = $web_app_data['updates']['installments'] ?? null;
                    unset($web_app_data['updates']['installments']);

                    $data['text'] = "نتیجه ویرایش وام: ";

                    if (sizeof($web_app_data['updates']) > 0) {
                        $result = $db->update('loans', $web_app_data['updates'], ['id' => $web_app_data['id'], 'person_id' => $person['id']]);
                        $data['text'] .= $result ? "\nویرایش اطلاعات وام: ✅" : "\nویرایش اطلاعات وام: ❌";
                    }

                    if ($new_insts) {
                        foreach ($new_insts as $index => $new_inst) $new_insts[$index]['person_id'] = $web_app_data['id'];

                        $result = $db->upsertBatch('installments', $new_insts);

                        $data['text'] .= $result ?
                            "\nویرایش اطلاعات اقساط: " . "✅" :
                            "\nویرایش اطلاعات اقساط: " . "❌";

                        $deleted_rows = $db->delete('installments', ['person_id' => $web_app_data['id'], '!due_date' => array_column($new_insts, 'due_date')]);
                        if ($deleted_rows) $data['text'] .= "\nتعداد قسط حذف شده: " . beautifulNumber($deleted_rows);
                    }
                    sendToTelegram($telegram_method, $data);
                    $person['progress'] = null;
                    level_2($person);

                }
                if ($web_app_data && isset($web_app_data['id']) && isset($web_app_data['delete'])) {
                    if ($web_app_data['delete']) {
                        $result = $db->delete('loans', ['id' => $web_app_data['id']]);
                        $data['text'] = $result ? '✅ حذف وام با موفقیت انجام شد!' : '❌ خطای پایگاه داده در حذف وام!';
                        sendToTelegram($telegram_method, $data);
                        level_2($person);
                    }
                }

                error_log('Web App Data: ' . json_encode($web_app_data, JSON_PRETTY_PRINT));
            } elseif (isset($message['text'])) {
                if (preg_match("/^\/start (\w*?)_/m", $message['text'])) {

                    // Show loan detail
                    if (preg_match("/^\/start showLoan_loanId(\d+?)_mssgId(\d+?)$/m", $message['text'], $matches)) {

                        sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $message['message_id']]);
                        sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $matches[2]]);

                        $loan = $db->read(
                            table: 'loans l',
                            conditions: ['l.id' => $matches[1], 'l.person_id' => $person['id']],
                            single: true,
                            selectColumns: '
                                    l.*, JSON_ARRAYAGG(JSON_OBJECT(
                                        "id", i.id,
                                        "loan_id", i.loan_id,
                                        "amount", i.amount,
                                        "due_date", i.due_date,
                                        "is_paid", i.is_paid
                                    )) as installments',
                            join: 'JOIN installments i on i.loan_id = l.id',
                            groupBy: 'l.id'
                        );
                        if ($loan) {
                            $loan['installments'] = json_decode($loan['installments'], true);

                            // Send a message just to show the bottom keyboard
                            $data['text'] = 'جزئیات وام «' . $loan['name'] . '»';
                            array_unshift($data['reply_markup']['keyboard'], [[
                                'text' => '✏ ویرایش وام «' . $loan['name'] . '»',
                                'web_app' => [
                                    'url' =>
                                        'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_loan.html' .
                                        '?data=' . base64_encode(json_encode($loan))]
                            ]]);

                            $response = sendToTelegram('sendMessage', $data);
                            if (!$response) exit();

                            // Send a message just to show the inline keyboard and get message ID
                            $data['text'] = 'در حال دریافت اطلاعات اقساط ...';
                            $data['reply_markup'] = ['inline_keyboard' => [
                                [['text' => '...', 'callback_data' => 'null']]
                            ]];
                            $temp_mssg = sendToTelegram('sendMessage', $data);

                            if ($temp_mssg) {
                                $db->update('persons', ['progress' => json_encode(['viewing_loan' => ['loan_id' => $loan['id']]], JSON_PRETTY_PRINT)], ['id' => $person['id']]);
                                $telegram_method = 'editMessageText';
                                $data['text'] = createLoanDetailView($loan, $temp_mssg['result']['message_id']);
                                $data['parse_mode'] = "MarkdownV2";
                                $data['message_id'] = $temp_mssg['result']['message_id'];
                                $data['reply_markup'] = ['inline_keyboard' => [
                                    [['text' => 'برگشت به لیست وام‌ها', 'callback_data' => json_encode(['loan_list' => null])]]
                                ]];
                            }
                        }
                    }
                    // Toggle installment payment in loan's detail message
                    if (preg_match("/^\/start toggleInstPayment_instId(\d+?)_mssgId(\d+?)$/m", $message['text'], $matches)) {

                        sendToTelegram('deleteMessage', ['chat_id' => $person['chat_id'], 'message_id' => $message['message_id']]);

                        $installment = $db->read(
                            table: 'installments i',
                            conditions: ['i.id' => $matches[1], 'l.person_id' => $person['id']],
                            single: true,
                            selectColumns: 'i.*, l.person_id',
                            join: 'LEFT JOIN loans l ON i.loan_id = l.id');

                        if ($installment) {

                            $db->update('installments', ['is_paid' => !$installment['is_paid']], ['id' => $installment['id']]);

                            $loan = $db->read(
                                table: 'loans l',
                                conditions: ['l.id' => $installment['loan_id'], 'l.person_id' => $person['id']],
                                single: true,
                                selectColumns: '
                                    l.*, JSON_ARRAYAGG(JSON_OBJECT(
                                        "id", i.id,
                                        "loan_id", i.loan_id,
                                        "amount", i.amount,
                                        "due_date", i.due_date,
                                        "is_paid", i.is_paid
                                    )) as installments',
                                join: 'JOIN installments i on i.loan_id = l.id',
                                groupBy: 'l.id'
                            );

                            if ($loan) {
                                $loan['installments'] = json_decode($loan['installments'], true);
                                $data['text'] = createLoanDetailView($loan, $matches[2]);
                                $telegram_method = 'editMessageText';
                                $data['reply_markup'] = ['inline_keyboard' => [
                                    [['text' => 'برگشت به لیست وام‌ها', 'callback_data' => json_encode(['loan_list' => null])]]
                                ]];

                                $data['parse_mode'] = "MarkdownV2";
                                $data['message_id'] = $matches[2];
                            }

                        } else $data['text'] = 'قسطی با این مشخصه یافت نشد!';
                    }
                }
            }
        }
    }

    $response = sendToTelegram($telegram_method, $data);
    if ($response && !$message && !$callback_query) $db->update('persons', ['last_btn' => 2, 'progress' => null], ['id' => $person['id']]);
    exit();

}

/**
 * Level 5: View Prices
 */
#[NoReturn]
function level_5(array $person, array|null $message = null, array|null $callback_query = null): void
{
    global $db;

    // Initialize default data to be sent
    $telegram_method = 'sendMessage';
    $data = [
        'chat_id' => $person['chat_id'],
        'text' => "پیام نامفهموم بود!\nلطفاً یکی از دسته‌بندی‌های زیر را انتخاب کنید:",
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(5, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'input_field_placeholder' => 'قیمت‌ها',
        ]
    ];

    // Since this level works on assets, close everything if there are no registered asset types.
    $asset_types = $db->read('assets', selectColumns: 'asset_type', distinct: true, orderBy: ['asset_type' => 'DESC']);
    if ($asset_types) {

        // Received data is a callback query
        if ($callback_query) {

            // Answer the query
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            $query_data = json_decode($callback_query['data'], true) ?? null;

            // Default configurations for handling unhandled callback data
            unset($data['reply_markup']);
            $data['text'] = 'این پیام منقضی شده است.';
            $telegram_method = 'editMessageText';
            $data['message_id'] = $message['message_id'];

            if ($query_data) {
                $query_key = array_key_first($query_data);

                // Edit Favorite
                if ($query_key == 'edit_fav') {

                    // Show "Add" and "Delete" buttons
                    if ($query_data[$query_key] == null) {

                        $data['text'] = 'عملیات مورد نظر را انتخاب کنید:';
                        $data['reply_markup']['inline_keyboard'] = [
                            [['text' => 'افزودن', 'callback_data' => json_encode(['edit_fav' => 'add'])]],
                            [['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['back' => 'favorites_list'])]],
                        ];

                        // Only add 'Delete' button if user has a favorite asset
                        $favorites = $db->read('favorites', ['person_id' => $person['id']]);
                        if ($favorites) {
                            $data['reply_markup']['inline_keyboard'][0][] = ['text' => 'حذف', 'callback_data' => json_encode(['edit_fav' => 'remove'])];
                        }

                        // Deactivate live message if this is a live message
                        $live_mssg = $db->read('special_messages', [
                            'person_id' => $person['id'],
                            'type' => 'live_price',
                            'is_active' => true
                        ], true);
                        if ($live_mssg && $live_mssg['message_id'] == $message['message_id']) {
                            $db->update('special_messages', ['is_active' => false], ['id' => $live_mssg['id']]);
                        }

                    }
                    // Show list of assets' types to add new favorite
                    if ($query_data[$query_key] == 'add') {

                        $data['text'] = 'یکی از دسته‌بندی‌های زیر را انتخاب کنید:';
                        $data['reply_markup']['inline_keyboard'] = [
                            [
                                ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])]
                            ]
                        ];

                        $asset_types = array_column($asset_types, 'asset_type');
                        foreach ($asset_types as $index => $asset_type)
                            $data['reply_markup']['inline_keyboard'][] = [
                                ['text' => $asset_type, 'callback_data' => json_encode(['add_fav' => ['asset_type' => $index]])],
                            ];
                    }
                    // Show list of favorites to select one for delete
                    if ($query_data[$query_key] == 'remove') {

                        $favorites = $db->read(
                            table: 'favorites f',
                            conditions: ['person_id' => $person['id']],
                            selectColumns: 'f.*, a.name as asset_name',
                            join: 'JOIN assets a ON a.id = f.asset_id',
                            orderBy: ['asset_type' => 'DESC', 'id' => 'ASC']);
                        if ($favorites) {
                            $data['text'] = 'کدام گزینه را می‌خواهید خذف کنید؟';
                            $data['reply_markup']['inline_keyboard'] = [
                                [
                                    ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => null])]
                                ]
                            ];

                            foreach ($favorites as $favorite)
                                $data['reply_markup']['inline_keyboard'][] = [
                                    ['text' => $favorite['asset_name'], 'callback_data' => json_encode(['del_fav' => ['fav_id' => $favorite['id']]])],
                                ];

                        }
                    }

                }
                // Add Favorite
                if ($query_key == 'add_fav') {

                    // Show list of assets with the selected type for adding to favorites
                    if (array_key_first($query_data[$query_key]) == 'asset_type') {

                        $asset_type = array_column($asset_types, 'asset_type')[$query_data[$query_key]['asset_type']];
                        $assets = $db->read('assets', conditions: ['asset_type' => $asset_type], orderBy: ['asset_type' => 'DESC']);

                        $data['text'] = 'گزینه‌ی مد نظر خود را از لیست زیر انتخاب کنید:';
                        $data['reply_markup']['inline_keyboard'] = [
                            [
                                ['text' => '🔙 برگشت 🔙', 'callback_data' => json_encode(['edit_fav' => 'add'])]
                            ]
                        ];

                        foreach ($assets as $asset)
                            $data['reply_markup']['inline_keyboard'][] = [
                                ['text' => $asset['name'], 'callback_data' => json_encode(['add_fav' => ['asset' => $asset['id']]])],
                            ];
                    }
                    // Show success/failure message for adding new favorite
                    if (array_key_first($query_data[$query_key]) == 'asset') {
                        $result = $db->create('favorites', ['person_id' => $person['id'], 'asset_id' => $query_data[$query_key]['asset']]);
                        $data['text'] = $result ? '✅ علاقه‌مندی جدید افزوده شد!' : '❌ خطای پایگاه داده!';
                        sendToTelegram('editMessageText', $data);
                        $favorites = $db->read(
                            table: 'favorites f',
                            conditions: ['person_id' => $person['id']],
                            selectColumns: 'a.*',
                            join: 'JOIN assets a ON a.id=f.asset_id',
                            orderBy: ['asset_type' => 'DESC', 'id' => 'ASC']);

                        $data['text'] = createFavoritesText($favorites);
                        $data['reply_markup'] = ['inline_keyboard' => [[['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]]]];

                        $telegram_method = 'sendMessage';
                    }

                }
                // Delete Favorite
                if ($query_key == 'del_fav') {

                    // Show confirmation for deleting a favorite item
                    if (array_key_first($query_data[$query_key]) == 'fav_id') {
                        $data['text'] = 'آیا از حذف اطمینان دارید؟';
                        $data['reply_markup']['inline_keyboard'] = [
                            [
                                ['text' => 'لغو', 'callback_data' => json_encode(['edit_fav' => 'remove'])],
                                ['text' => 'تایید', 'callback_data' => json_encode(['del_fav' => ['conf' => $query_data[$query_key]['fav_id']]])],
                            ]
                        ];
                    }
                    // Show success/failure message for deleting the favorite
                    if (array_key_first($query_data[$query_key]) == 'conf') {
                        $result = $db->delete(table: 'favorites', conditions: ['id' => $query_data[$query_key]['conf']], resetAutoIncrement: true);
                        $data['text'] = $result ? '✅ حذف موفقیت آمیز بود!' : '❌ خطای پایگاه داده!';
                        sendToTelegram('editMessageText', $data);
                        $favorites = $db->read(
                            table: 'favorites f',
                            conditions: ['person_id' => $person['id']],
                            selectColumns: 'a.*',
                            join: 'JOIN assets a ON a.id=f.asset_id',
                            orderBy: ['asset_type' => 'DESC', 'id' => 'ASC']);

                        $data['text'] = createFavoritesText($favorites);
                        $data['reply_markup'] = ['inline_keyboard' => [[['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]]]];

                        $telegram_method = 'sendMessage';
                    }

                }
                // Control live price message
                if ($query_key == 'set_live') {

                    // Default false result for database operation
                    $result = false;

                    // Set live price message
                    if ($query_data[$query_key] === true) {

                        // Read live message from database =
                        $live_mssg = $db->read('special_messages', [
                            'person_id' => $person['id'],
                            'type' => 'live_price',
                        ], true);

                        // Delete previous live message if exists
                        if ($live_mssg) sendToTelegram('deleteMessage', ['message_id' => $live_mssg['message_id'], 'chat_id' => $person['chat_id']]);

                        // Create/Update the live message in the database
                        $result = $db->upsert('special_messages', [
                            'person_id' => $person['id'],
                            'type' => 'live_price',
                            'is_active' => true,
                            'message_id' => $message['message_id'],
                        ]);
                    }

                    // Unset live price message
                    if ($query_data[$query_key] === false) {

                        // Delete the live message in the database
                        $result = $db->delete('special_messages', [
                            'person_id' => $person['id'],
                            'type' => 'live_price'
                        ], true);
                    }

                    // Database operation success
                    if ($result) {

                        // Read person's favorites
                        $favorites = $db->read(
                            table: 'favorites f',
                            conditions: ['person_id' => $person['id']],
                            selectColumns: 'a.*',
                            join: 'JOIN assets a ON a.id=f.asset_id',
                            orderBy: ['asset_type' => 'DESC', 'id' => 'ASC']);

                        // Create data array to send to telegram
                        $data['text'] = createFavoritesText($favorites);
                        $data['reply_markup'] = ['inline_keyboard' => [
                            $query_data[$query_key] === true ?
                                [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]] :
                                [['text' => 'نمایش زنده قیمت‌ها ▶', 'callback_data' => json_encode(['set_live' => true])]],
                            [['text' => 'هشدار قیمت', 'callback_data' => json_encode(['price_alert' => null])]],
                            [['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]],
                        ]];

                    } else $data['text'] = 'خطای پایگاه داده';
                }
                // Price alerts
                if ($query_key == 'price_alert') {

                    // Show main alert setting menu and disable live message
                    if ($query_data[$query_key] == null) {

                    }
                }
                // Back
                if ($query_key == 'back') {
                    // show main list of favorites
                    if ($query_data['back'] == 'favorites_list') {
                        $favorites = $db->read(
                            table: 'favorites f',
                            conditions: ['person_id' => $person['id']],
                            selectColumns: 'a.*',
                            join: 'JOIN assets a ON a.id=f.asset_id',
                            orderBy: ['asset_type' => 'DESC', 'id' => 'ASC']);

                        // Check if user has live message configured
                        $result = $db->read('special_messages', ['person_id' => $person['id'], 'type' => 'live_price'], true);
                        if ($result) $db->update('special_messages', ['is_active' => true], ['id' => $result['id']]);

                        $data['text'] = createFavoritesText($favorites);
                        $data['reply_markup'] = ['inline_keyboard' => [
                            $result && $result['message_id'] == $message['message_id'] ?
                                [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]] :
                                [['text' => 'نمایش زنده قیمت‌ها ▶', 'callback_data' => json_encode(['set_live' => true])]],
                            [['text' => 'هشدار قیمت', 'callback_data' => json_encode(['price_alert' => null])]],
                            [['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]],
                        ]];
                    }
                }
            }

        }

        // Received data is not a callback query
        if (!$callback_query) {

            // Create the keyboard for assets types
            $asset_types = array_column($asset_types, 'asset_type');
            foreach ($asset_types as $asset_type) array_unshift($data['reply_markup']['keyboard'], [['text' => $asset_type]]);
            // Add 'Favorites' button to the keyboard
            array_unshift($data['reply_markup']['keyboard'], [['text' => '❤ علاقه‌مندی‌ها ❤']]);

            // User has just entered the level
            if (!$message) $data['text'] = "دسته‌بندی مورد نظر را انتخاب کنید:";

            // Received message
            if ($message) {

                // Prices for a specific asset type is requested
                if (in_array($message['text'], $asset_types)) {

                    $assets = $db->read('assets', ['asset_type' => $message['text']]);
                    if ($assets) {

                        $date = preg_split('/-/u', $assets[0]['date']);
                        $date[1] = str_replace(
                            ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
                            ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
                            $date[1]);

                        $text = "آخرین قیمت ها در " . "$date[2] $date[1] $date[0]" . " ساعت " . $assets[0]['time'] . "\n";
                        $text = beautifulNumber($text, null);

                        foreach ($assets as $asset) {
                            $asset['price'] = beautifulNumber($asset['price']);
                            $asset_text = "$asset[name] : $asset[price] $asset[base_currency]";

                            $text = $text . "\n" . $asset_text;
                        }

                        $data['text'] = $text;
                        $data['reply_to_message_id'] = $message['message_id'];

                    } else $data['text'] = 'این دسته بندی خالی‌ست!';

                }
                if ($message['text'] == '❤ علاقه‌مندی‌ها ❤') {

                    $favorites = $db->read(
                        table: 'favorites f',
                        conditions: ['person_id' => $person['id']],
                        selectColumns: 'a.*',
                        join: 'JOIN assets a ON a.id=f.asset_id',
                        orderBy: ['asset_type' => 'DESC', 'id' => 'ASC']);

                    // Check if user has live message configured
                    $live_mssg = $db->read('special_messages', ['person_id' => $person['id'], 'type' => 'live_price'], true);

                    // Prepare message to be sent to user
                    $data['text'] = createFavoritesText($favorites);
                    $data['reply_markup'] = ['inline_keyboard' => [
                        $live_mssg ?
                            [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]] :
                            [['text' => 'نمایش زنده قیمت‌ها ▶', 'callback_data' => json_encode(['set_live' => true])]],
                        [['text' => 'هشدار قیمت', 'callback_data' => json_encode(['price_alert' => null])]],
                        [['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]],
                    ]];

                    // Different workflow if user has registered live message
                    if ($live_mssg) {

                        // Send favorite list to user
                        $response = sendToTelegram($telegram_method, $data);

                        if ($response) {

                            // Update message ID in the database
                            $update_live_mssg = $db->update(
                                table: 'special_messages',
                                data: ['message_id' => $response['result']['message_id'], 'is_active' => true],
                                conditions: ['person_id' => $person['id'], 'type' => 'live_price',]);

                            // Delete previous live message and exit
                            if ($update_live_mssg) {
                                $telegram_method = 'deleteMessage';
                                $data = ['message_id' => $live_mssg['message_id'], 'chat_id' => $person['chat_id']];
                            }
                        }
                    }
                }
            }
        }
    }
    if (!$asset_types) $data['text'] = "دسته‌بندی‌ای در سیستم یافت نشد!";

    $response = sendToTelegram($telegram_method, $data);
    if ($response && !$message && !$callback_query) $db->update('persons', ['last_btn' => 5, 'progress' => null], ['id' => $person['id']]);
    exit();

}

/**
 * Level 6: Artificial Intelligence
 */
#[NoReturn]
function level_6(array $person, array|null $message = null, array|null $callback_query = null): void
{
    global $db;

    // Initialize default data to be sent
    $telegram_method = 'sendMessage';
    $data = [
        'chat_id' => $person['chat_id'],
        'text' => 'پیام نامفهوم است!',
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(6, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'input_field_placeholder' => 'هوش مصنوعی',
        ]
    ];

    if ($callback_query) { // Received data is a callback query

        // Answer the query
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        // $query_data = json_decode($callback_query['data'], true) ?? null;

        // Default configurations for handling unhandled callback data
        unset($data['reply_markup']);
        $data['text'] = 'این پیام منقضی شده است.';
        $telegram_method = 'editMessageText';
        $data['message_id'] = $message['message_id'];

    }

    // Received data is a message
    if (!$callback_query && !$message) {

    }
    if ($message) {

    }

    $response = sendToTelegram($telegram_method, $data);
    if ($response && !$message && !$callback_query) $db->update('persons', ['last_btn' => 6, 'progress' => null], ['id' => $person['id']]);
    exit();
}

function createHoldingDetailText(
    array       $holding,
    string|null $markdown = null,
    array       $attributes = [
        'date',
        'org_amount',
        'org_price',
        'new_price',
        'org_total_price',
        'new_total_price',
        'profit',
    ],
    int|string  $mssg_id = null): string
{
    $text = '';

    $date = preg_split('/-/u', $holding['date']);
    $date[1] = str_replace(
        ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
        ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
        $date[1]);

    if ($markdown === 'MarkdownV2') {
        $asset_name = beautifulNumber(markdownScape($holding['asset_name']), null);
        $holding['asset_name'] = "[$asset_name](https://t.me/" . BOT_ID . "?start=viewHolding_holdingId$holding[id]" . ($mssg_id ? "_mssgId" . $mssg_id : '') . ")" . '‏';
    }

    $price_def = $holding['current_price'] - $holding['avg_price'];
    $profit = ($price_def >= 0) ?
        "🟢 سود: " . beautifulNumber(($price_def * $holding['amount']) * floatval($holding['base_rate'])) :
        "🔴 ضرر: " . beautifulNumber(($price_def * $holding['amount']) * floatval($holding['base_rate']));

    $price_tree = "\n   │ " . "‏";
    $price_tree .= (in_array('date', $attributes)) ? ("\n   ┤── " . "تاریخ خرید: " . beautifulNumber("$date[2] $date[1] $date[0]", null)) : '';
    $price_tree .= (in_array('org_amount', $attributes)) ? ("\n   ┤── " . "مقدار / تعداد: " . beautifulNumber(floatval($holding['amount']))) : '';
    $price_tree .= (in_array('org_price', $attributes)) ? ("\n   ┤── " . "قیمت خرید هر واحد: " . beautifulNumber(floatval($holding['avg_price'])) . " " . $holding['base_currency']) : '';
    $price_tree .= (in_array('new_price', $attributes)) ? ("\n   ┤── " . "قیمت لحظه‌ای هر واحد: " . beautifulNumber($holding['current_price']) . " " . $holding['base_currency']) : '';
    $price_tree .= (in_array('org_total_price', $attributes)) ? ("\n   ┤── " . "قیمت خرید کل: " . beautifulNumber($holding['avg_price'] * $holding['amount']) . " " . $holding['base_currency']) : '';
    $price_tree .= (in_array('new_total_price', $attributes)) ? ("\n   ┤── " . "قیمت لحظه‌ای کل دارایی: " . beautifulNumber($holding['current_price'] * $holding['amount']) . " " . $holding['base_currency']) : '';
    $price_tree .= "\n   │ " . "‏";
    $price_tree .= (in_array('profit', $attributes)) ? ("\n   ┘── " . $profit . " ریال") : '';
    $price_tree .= "\n";
    if ($markdown === 'MarkdownV2') $price_tree = markdownScape($price_tree);

    return $text . $holding['asset_name'] . $price_tree;
}

/**
 * @throws Exception
 */
function createLoansView(array $loans, int|string|null $mssg_id = null): string
{
    $text = 'وام‌های ثبت شده‌ی شما: ' . "\n";
    foreach ($loans as $loan) {

        // Initialize counters
        $installments_per_year = [];
        $installments_string = "\n       ┤─ ";
        $next_payment = getJalaliDate();

        // Loop through installments to calculate counts
        foreach ($loan['installments'] as $inst) {

            if ($inst['is_paid'] == 1)
                $installments_per_year[explode('/', $inst['due_date'])[0]][] = "🟢";
            else {
                if ($next_payment <= getJalaliDate()) $next_payment = $inst['due_date'];
                if ($inst['due_date'] < getJalaliDate())
                    $installments_per_year[explode('/', $inst['due_date'])[0]][] = "🔴";
                else $installments_per_year[explode('/', $inst['due_date'])[0]][] = "⚪";
            }

            if (strlen($installments_string) % 12 == 0) $installments_string .= "\n‏       ┤─ ";
        }

        // Get remaining days to payment
        $daysRemaining = 0;
        $parts = explode('/', $next_payment);
        if (count($parts) == 3) {

            $gregorianDueDate = new DateTime(jalaliToGregorian($parts[0], $parts[1], $parts[2]) . ' 00:00:00');
            $today = new DateTime('now');
            $today->setTime(0, 0); // Normalize today to midnight for accurate day calc

            $interval = $today->diff($gregorianDueDate);
            $daysRemaining = (int)$interval->format('%r%a'); // %r gives sign (-/+), %a gives total days
        }

        $loan_name = "\n‏\-* " . "[" . markdownScape(beautifulNumber($loan['name'], null)) . "](https://t.me/" . BOT_ID . "?start=showLoan_loanId" . $loan['id'];
        if ($mssg_id) $loan_name .= "_mssgId" . $mssg_id;
        $loan_name .= ")*";
        $loan_detail = "\n‏      │  ";
        $loan_detail .= "\n‏      ┤─ " . "مبلغ وام\: " . markdownScape(beautifulNumber($loan['total_amount']));
        $loan_detail .= "\n‏      ┤─ " . "تاریخ دریافت\: " . markdownScape(beautifulNumber($loan['received_date'], null));

        if ($daysRemaining) $loan_detail .= "\n‏      ┤─ " . "قسط بعدی\: " . beautifulNumber($daysRemaining) . ' روز دیگر';

        $loan_detail .= "\n‏      ┘─ " . "وضعیت اقساط\: ";
        foreach ($installments_per_year as $year => $inst) {
            if (array_key_last($installments_per_year) != $year) $loan_detail .= "\n‏          ┤─ ";
            else $loan_detail .= "\n‏          ┘─ ";

            $loan_detail .= beautifulNumber($year, null) . '\: ' . implode('', $inst);
        }

        $text .= $loan_name . $loan_detail;
        $text .= "\n";
    }
    return $text;
}

function createLoanDetailView(array $loan, int|string $mssg_id): string
{
    $installments = $loan['installments'];
    $paid_count = 0;
    $overdue_count = 0;
    $remaining_count = 0;
    $paid_sum = 0;
    $overdue_sum = 0;
    $remaining_sum = 0;

    // Loop through installments to calculate counts
    foreach ($installments as $index => $inst) {
        if ($inst['is_paid'] == 1) {
            $installments[$index]['is_paid'] = "🟢";
            $paid_count++;
            $paid_sum += $inst['amount'];
        } else
            if ($inst['due_date'] < getJalaliDate()) {
                $installments[$index]['is_paid'] = "🔴";
                $overdue_count++;
                $overdue_sum += $inst['amount'];
            } else {
                $installments[$index]['is_paid'] = "⚪";
                $remaining_count++;
                $remaining_sum += $inst['amount'];
            }
    }

    $text = "‏*" . markdownScape($loan['name']) . "*:\n";
    $text .= "\n مبلغ وام\: " . markdownScape(beautifulNumber($loan['total_amount']));
    $text .= "\n تاریخ دریافت\: " . markdownScape(beautifulNumber($loan['received_date'], null));
    $text .= "\n کل بازپرداخت\: " . markdownScape(beautifulNumber(array_sum(array_column($installments, 'amount'))));
    $text .= "\n " . markdownScape(beautifulNumber($paid_count) . " قسط پرداخت‌شده، معادل " . beautifulNumber($paid_sum));
    $text .= "\n " . markdownScape(beautifulNumber($remaining_count) . " قسط باقی‌مانده، معادل " . beautifulNumber($remaining_sum));
    $text .= "\n " . markdownScape(beautifulNumber($overdue_count) . " قسط معوقه، معادل " . beautifulNumber($overdue_sum));
    $text .= "\n جزئیات اقساط\: ";

    foreach ($installments as $index => $inst) {
        $text .= "\n‏    " . beautifulNumber(intval($index) + 1, null) . "\) " . $inst['is_paid'] . "  " . beautifulNumber($inst['due_date'], null) . ":  " . beautifulNumber($inst['amount']);
        $text .= "    [تغییر وضعیت پرداخت](https://t.me/" . BOT_ID . "?start=toggleInstPayment_instId$inst[id]_mssgId$mssg_id)";
    }
    return $text;
}