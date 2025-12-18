<?php
// Load core configuration and constants.
use JetBrains\PhpStorm\NoReturn;

// --- VERCEL CONFIGURATION ---
// Instead of config.php, we map Environment Variables to Constants
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
// Load the QuickChart library for generating charts.
require_once 'Libraries/QuickChart.php';
// Load functions for external APIs (Telegram, MajidAPI, etc.).
require_once 'Functions/ExternalEndpointsFunctions.php';
// Load functions for generating Telegram keyboards.
require_once 'Functions/KeyboardFunctions.php';
// Load helper functions for number formatting and validation.
require_once 'Functions/NumberHelper.php';

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

// --- 1. Security Check ---
// We check for the Telegram Secret Token Header (Best Practice)
// OR the 'secret' query parameter (For manual dev/testing).
$header_secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
$query_secret = $_GET['secret'] ?? null;

// Validate against the SHARED_SECRET constant defined above via env vars
if (($header_secret !== SHARED_SECRET) && ($query_secret !== SHARED_SECRET)) {
    error_log("SECURITY ALERT: Access denied. Invalid secret. Input size: " . strlen($input));
    http_response_code(403);
    die(json_encode(['status' => 'unauthorized', 'message' => 'Invalid Secret Token']));
}

// --- 2. Immediate Acknowledgment ---
// Send a 200 OK response immediately so Telegram knows we received the update.
http_response_code(200);

// Exit if no input data was received.
if (empty($input)) {
    error_log("[WARN] No input data received via Webhook.");
    exit(json_encode(['status' => 'ok', 'message' => 'No input']));
}

// Decode the JSON Telegram update.
$update = json_decode($input, true);

// Log error if JSON decoding failed.
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("[ERROR] Invalid JSON received: " . json_last_error_msg());
    exit();
}

// Get Database Instance (Ensure your config.php points to a remote DB, not a local SQLite file).
$db = DatabaseManager::getInstance();

// --- MAIN UPDATE ROUTER ---

// Process 'message' updates (Text, Commands, Buttons).
if (isset($update['message'])) {

    $message = $update['message'];
    $user = $message['chat']; // Sender information

    // Check/Register User
    $person = $db->read('persons', ['chat_id' => $user['id']], true);

    if (!$person) {
        // --- New User Registration ---
        $admins = $db->read('persons', ['is_admin' => 1]);

        $new_user_id = $db->create(
            'persons',
            [
                'chat_id' => $user['id'],
                'first_name' => $user['first_name'] ?? 'N/A',
                'last_name' => $user['last_name'] ?? null,
                'username' => $user['username'] ?? null,
                'progress' => null,
                // First user becomes admin automatically
                'is_admin' => ($admins) ? 0 : 1,
                'last_btn' => 0
            ]
        );

        if ($new_user_id) {
            $person = $db->read('persons', ['chat_id' => $user['id']], true);
        } else {
            error_log("[ERROR] Failed to create new user: " . $user['id']);
            return;
        }
    }

    // ------------------------------
    // ----- The core bot logic -----
    // ------------------------------

    $pressed_button = getPressedButton(
        text: $message['text'] ?? '',
        parent_btn_id: $person['last_btn'],
        admin: $person['is_admin'],
        db: $db
    );

    // Global Command Routing
    if ($message['text'] == '/holdings') {
        level_1($person);
    } elseif ($message['text'] == '/prices') {
        level_4($person);
    } elseif ($message['text'] == '/loans') {
        level_5($person);
    } else {
        // Route based on button/state
        choosePath(
            pressed_button: $pressed_button,
            message: $message,
            person: $person
        );
    }
} elseif (isset($update['callback_query'])) {
    // Process 'Inline' button presses.

    $callback_query = $update['callback_query'];

    $message = $callback_query['message'];
    $user = $callback_query['from'];

    $person = $db->read('persons', ['chat_id' => $user['id']], true);

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

    if ($callback_query) {
        // Handle Callback Queries
        $data = [
            'text' => 'درخواست نامفهوم بود!',
            'message_id' => $message['message_id'],
            'chat_id' => $person['chat_id'],
        ];

        $text = null;
        if ($person['last_btn'] == 1) level_1(person: $person, message: $message, callback_query: $callback_query);
        if ($person['last_btn'] == 4) $text = level_4(person: $person, message: $message, callback_query: $callback_query);

        if ($text) $data['text'] = $text;
        sendToTelegram('editMessageText', $data);

    } else {
        // Handle Text Messages
        if (!$pressed_button) {
            // No button matched: Handle as free text input or error
            $data = [
                'text' => 'پیام نامفهوم است!',
                'chat_id' => $person['chat_id'],
                'reply_markup' => [
                    'keyboard' => createKeyboardsArray($person['last_btn'], $person['is_admin'], $db),
                    'resize_keyboard' => true,
                ]];

            $text = null;
            // Route to active level handler (Input Step)
            if ($person['last_btn'] == "1") level_1($person, $message); // View Holdings
            if ($person['last_btn'] == "4") $text = level_4($person, $message); // View Prices
            if ($person['last_btn'] == "5") level_5($person, $message); // Add Loan

            if ($text) $data['text'] = $text;
            sendToTelegram('sendMessage', $data);

        } else {
            // Button matched
            if (str_starts_with($pressed_button['id'], "s")) {
                // Special system buttons
                if ($pressed_button['id'] === "s0") backButton(person: $person);
                if ($pressed_button['id'] === "s1") cancelButton(person: $person);

            } else {
                // Menu Navigation
                if ($pressed_button['id'] == "1") level_1($person);
                if ($pressed_button['id'] == "4") level_4($person);
                if ($pressed_button['id'] == "5") level_5($person);

                $data = [
                    'text' => json_decode($pressed_button['attrs'], true)['text'],
                    'chat_id' => $person['chat_id'],
                    'reply_markup' => [
                        'keyboard' => createKeyboardsArray($pressed_button['id'], $person['is_admin'], $db),
                        'resize_keyboard' => true,
                        'input_field_placeholder' => json_decode($pressed_button['attrs'], true)['text'],
                    ]
                ];

                $response = sendToTelegram('sendMessage', $data);
                if ($response) {
                    $person['last_btn'] = $pressed_button['id'];
                    $db->update('persons', $person, ['id' => $person['id']]);
                    echo json_encode(['status' => 'OK', 'telegram_response' => $response]);
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

    if ($progress && sizeof($progress) > 2) {
        // Step back in a multi-step form
        array_pop($progress);
        array_pop($progress);
        $person['progress'] = json_encode($progress);
        choosePath(pressed_button: false, message: false, person: $person);

    } elseif ($progress && sizeof($progress) > 1) {
        // Reset progress, stay on level
        $person['progress'] = null;
        choosePath(pressed_button: $current_level, message: false, person: $person);
    } else {
        // Go to parent menu
        $last_level = $db->read('buttons', ['id' => $current_level['belong_to']], true);

        $last_button['id'] = $last_level['id'];
        $last_button['attrs'] = $last_level['attrs'];
        $last_button['adminKey'] = $last_level['admin_key'];
        $last_button['messages'] = $last_level['messages'];
        $last_button['belongsTo'] = $last_level['belongs_to'];
        $last_button['keyboards'] = $last_level['keyboards'];

        $person['progress'] = null;
        choosePath(pressed_button: $last_button, person: $person);
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
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(1, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'input_field_placeholder' => 'دارایی‌ها',
        ]
    ];

    // Add web_app button(s)
    $progress = json_decode($person['progress'], true);
    if ($progress && array_key_first($progress) == 'view_holding') { // User is viewing a holding
        // Edit holding button
        $data['reply_markup']['keyboard'] = array_merge([
            [
                [
                    'text' => '✏ ویرایش',
                    'web_app' => [
                        'url' => 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_holding.html?k=' . getenv('DB_API_SECRET') . '&edit_holding_id=' . $progress['view_holding']['holding_id']]
                ]
            ]
        ], $data['reply_markup']['keyboard']);
    } else { // User has just entered level 1
        // Add holding button
        $data['reply_markup']['keyboard'] = array_merge([
            [
                [
                    'text' => '➕ افزودن دارایی جدید',
                    'web_app' => [
                        'url' => 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_holding.html?k=' . getenv('DB_API_SECRET')]
                ]
            ]
        ], $data['reply_markup']['keyboard']);
    }

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

    if ($callback_query) {

        // Answer the query
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        // $query_data = json_decode($callback_query['data'], true);

        // Delete the message generating the callback
        $telegram_method = 'editMessageText';
        unset($data['reply_markup']);
        $data['text'] = 'این پیام منقضی شده است.';
        $data['message_id'] = $message['message_id'];

    } elseif (!$message) { // User has just entered the level 1

        if ($holdings) { // User has registered holdings

            $data['text'] = "دارایی‌های ثبت شده‌ی شما:\n";
            $total_profit = 0;
            foreach ($holdings as $holding) {
                $total_profit += $holding['amount'] * ($holding['current_price'] - $holding['avg_price']) * $holding['base_rate'];
                $data['text'] = $data['text'] . "\n" . createHoldingDetailText(holding: $holding, markdown: 'MarkdownV2', attributes: ['org_amount', 'org_total_price', 'profit']);
            }
            $total_profit = ($total_profit >= 0) ?
                "🟢 کل سود: " . beautifulNumber($total_profit) . ' ریال' :
                "🔴 کل ضرر: " . beautifulNumber($total_profit) . ' ریال';
            $total_profit = str_replace(["."], ["\."], $total_profit);
            $data['text'] .= "\n" . $total_profit;

            $data['parse_mode'] = "MarkdownV2";

        } else $data['text'] = 'شما هیچ دارایی‌ای ثبت نکرده‌اید.';

        $db->update('persons', ['last_btn' => 1], ['id' => $person['id']]);

    } else { // Message received in the level 1

        if (isset($message['web_app_data'])) {

            $web_app_data = json_decode($message['web_app_data']['data'], true);
            $action = array_key_first($web_app_data);

            if (in_array($action, ['add', 'edit'])) {

                $holding = $web_app_data[$action];
                $result = $db->upsert('holdings', [
                    "person_id" => $person['id'],
                    "asset_id" => $holding["asset_id"],
                    "amount" => $holding["amount"],
                    "avg_price" => $holding["avg_price"],
                    "date" => $holding["date"],
                    "time" => $holding["time"],
                    "note" => $holding["note"],
                ]);

                if ($result) $data['text'] = $action == 'edit' ? '✅ دارایی با موفقیت ویرایش شد.' : '✅ دارایی جدید با موفقیت ثبت شد.';
                else $data['text'] = $action == 'edit' ? '❌ خطای پایگاه داده در ویرایش دارایی.' : '❌ خطای پایگاه داده در ثبت دارایی جدید.';

            } elseif ($action == 'delete') {

                $holding_id = $web_app_data[$action]['id'];
                $result = $db->delete('holdings', ['id' => $holding_id], true);

                if ($result) $data['text'] = '✅ دارایی با موفقیت حذف شد.';
                else $data['text'] = '❌ خطای پایگاه داده درحذف دارایی.';

            }

            sendToTelegram($telegram_method, $data); // Send success/failure message to the user
            level_1($person); // Call the level to send user the new list of their holdings

        } else {
            // Check deep-link: /start <holding_id>
            $matched = preg_match("/^\/start holding_(\d*)$/m", $message['text'], $matches);
            if ($matched) {

                if ($matches[1]) {

                    $index = array_search($matches[1], array_column($holdings, 'id'));
                    $holding = ($index !== false) ? $holdings[$index] : null;

                    if ($holding) {

                        $data['text'] = createHoldingDetailText(holding: $holding);

                        // Add web_app button to edit the holding
                        unset($data['reply_markup']['keyboard'][0]);
                        $data['reply_markup']['keyboard'] = array_merge([
                            [
                                [
                                    'text' => '✏ ویرایش',
                                    'web_app' => [
                                        'url' => 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_holding.html?k=' . getenv('DB_API_SECRET') . '&edit_holding_id=' . $holding['id']]
                                ]
                            ]
                        ], $data['reply_markup']['keyboard']);

                        // Set user progress
                        $db->update('persons',
                            ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                            ['id' => $person['id']]);

                    } else  $data['text'] = 'دارایی با این مشخصه یافت نشد!';
                } else  $data['text'] = 'الگوی پیام اشتباه است!';
            } else $data['text'] = 'پیام نامفهوم است!';
        }
    }

    $response = sendToTelegram($telegram_method, $data);
    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));
}

/**
 * Level 4: View Prices
 */
#[NoReturn]
function level_4(array $person, array|null $message = null, array|null $callback_query = null): null|string
{
    global $db;
    $data = [
        'chat_id' => $person['chat_id'],
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(4, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'input_field_placeholder' => 'قیمت‌ها',
        ]
    ];

    if ($callback_query) {

        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        $query_data = json_decode($callback_query['data'], true);

        $query_key = array_key_first($query_data);
        if ($query_key == 'view_asset') {
            $asset_id = $query_data[$query_key]['id'];
            $asset = $db->read('assets', ['id' => $asset_id], single: true);

            if ($asset) {
                $data['text'] = $asset['name'] . ': ' . beautifulNumber($asset['price']);
                $data['message_id'] = $message['message_id'];
                $data['reply_markup'] = [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '📊 نمایش نمودار',
                                'callback_data' => json_encode(['show_chart' => ['id' => $asset['id']]])
                            ],
                        ], [
                            [
                                'text' => '🔔 هشدار قیمت',
                                'callback_data' => json_encode(['price_alert' => ['id' => $asset['id']]])
                            ],
                        ]
                    ]
                ];
                $db->update('persons', ['progress' => null], ['id' => $person['id']]);
            } else $data['text'] = 'دارایی‌ای با این مشخصه یافت نشد!';

            $response = sendToTelegram('editMessageText', $data);
            exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

        }
        if ($query_key == 'show_chart') {

            sendToTelegram('deleteMessage', [
                'chat_id' => $person['chat_id'],
                'message_id' => $message['message_id']
            ]);

            $asset_id = $query_data[$query_key]['id'];

            $price_chunks = $db->read(
                table: 'prices',
                conditions: ['asset_id' => $asset_id],
                selectColumns: 'prices.*, a.name as asset_name',
                join: 'INNER JOIN assets a ON prices.asset_id = a.id',
                orderBy: ['prices.date' => "DESC"],
                limit: 100,
                chunkSize: 5);

            if ($price_chunks) {

                $chart_config = pricesToChartConfig($price_chunks);
                try {
                    $url = generateQuickChartUrl($chart_config, height: 700);
                    $data['photo'] = $url;
                    $data['caption'] = 'قیمت «' . $price_chunks[0][0]['asset_name'] . '»';
                    $response = sendToTelegram('sendPhoto', $data);
                    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

                } catch (Exception $e) {
                    error_log("[ERROR] Chart Gen Error: " . $e->getMessage());
                    exit(json_encode(['status' => 'OK']));
                }

            } else return 'داده‌ی قیمتی یافت نشد!';

        }
        if ($query_key == 'price_alert') {

            $asset_id = $query_data[$query_key]['id'];

            $data['reply_markup'] = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => 'ثبت هشدار قیمت جدید',
                            'callback_data' => json_encode(['new_alert' => ['id' => $asset_id]])
                        ]
                    ], [
                        [
                            'text' => '🔙 برگشت 🔙',
                            'callback_data' => json_encode(['view_asset' => ['id' => $asset_id]])
                        ]

                    ]
                ]
            ];

            $data['text'] = 'شما هیچ هشدار ثبت‌شده‌ای ندارید!';
            $data['message_id'] = $message['message_id'];

            $alerts = $db->read(
                table: 'alerts',
                conditions: ['alerts.person_id' => $person['id'], 'alerts.asset_id' => $asset_id],
                selectColumns: 'alerts.*, assets.name as asset_name, assets.base_currency',
                join: 'JOIN assets ON alerts.asset_id=assets.id'
            );

            if ($alerts) {
                $data['text'] = "هشدارهای ثبت شده‌ی شما برای «" . $alerts[0]['asset_name'] . "»:\n";
                foreach ($alerts as $alert) {
                    $alert_icon = '↕';
                    if ($alert['trigger_type'] == 'up') $alert_icon = '⬆';
                    if ($alert['trigger_type'] == 'down') $alert_icon = '⬇';

                    $status_icon = '⚪';
                    if (!$alert['is_active']) $status_icon = ($alert['triggered_date']) ? '🟢' : '🟤';

                    $data['text'] .= "\n" . $status_icon . "    " . beautifulNumber($alert['target_price']) . ' ' . $alert['base_currency'] . ' ' . $alert_icon;
                }
            }

            $response = sendToTelegram('editMessageText', $data);
            exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));
        }
        if ($query_key == 'new_alert') {
            $asset_id = $query_data[$query_key]['id'];

            $asset = $db->read('assets', ['id' => $asset_id], single: true);
            if ($asset) {

                $data['message_id'] = $message['message_id'];
                unset($data['reply_markup']);
                $data['text'] = 'قیمت مورد نظر برای ثبت این هشدار را وارد کنید.' .
                    "\n\n" . 'قیمت کنونی ' . beautifulNumber($asset['name'], null) . ': ' . beautifulNumber($asset['price']);

                $progress = ['new_alert' => ['asset_id' => $asset_id]];

                $response = sendToTelegram('editMessageText', $data);
                if ($response) $db->update('persons', ['progress' => json_encode($progress)], ['id' => $person['id']]);
                else exit();
            } else {
                error_log("[ERROR] New Alert Error. Asset ID: " . $asset_id);
                return 'خطا!';
            }
        }

        return null;

    } else {
        $asset_types = $db->read('assets', selectColumns: 'asset_type', distinct: true);
        if ($asset_types) {

            $asset_types = array_reverse(array_column($asset_types, 'asset_type'));
            foreach ($asset_types as $asset_type) array_unshift($data['reply_markup']['keyboard'], [['text' => $asset_type]]);
            array_unshift($data['reply_markup']['keyboard'], [['text' => 'علاقه‌مندی‌ها']]);

            if (!$message) {

                $data['text'] = "دسته‌بندی مورد نظر را انتخاب کنید:";
                $person['last_btn'] = 4;
                $person['progress'] = null;
                $db->update('persons', $person, ['id' => $person['id']]);

            } else {

                $matched = preg_match("/^\/start (\d*)$/m", $message['text'], $matches);

                if ($matched) {
                    if (!empty($matches[1])) {

                        $asset = $db->read('assets', ['id' => $matches[1]], single: true);

                        if ($asset) {
                            $data['text'] = $asset['name'] . ': ' . beautifulNumber($asset['price']);
                            $data['reply_markup'] = [
                                'inline_keyboard' => [
                                    [
                                        [
                                            'text' => '📊 نمایش نمودار',
                                            'callback_data' => json_encode(['show_chart' => ['id' => $asset['id']]])
                                        ],
                                    ], [
                                        [
                                            'text' => '🔔 هشدار قیمت',
                                            'callback_data' => json_encode(['price_alert' => ['id' => $asset['id']]])
                                        ],
                                    ]
                                ]
                            ];
                            $db->update('persons', ['progress' => null], ['id' => $person['id']]);
                        } else $data['text'] = 'دارایی‌ای با این مشخصه یافت نشد!';

                    }
                } elseif (in_array($message['text'], $asset_types)) {

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
                            $asset = str_replace(['.', "(", ")", "="], ['\.', "\(", "\)", "\="], $asset);
                            $asset_text = "[$asset[name]](https://t.me/" . BOT_ID . "?start=$asset[id]) : " . $asset['price'] . " $asset[base_currency]";

                            $text = $text . "\n" . $asset_text;
                        }

                        $data['text'] = $text;
                        $data['reply_to_message_id'] = $message['message_id'];
                        $data['parse_mode'] = "MarkdownV2";

                        $db->update('persons', ['progress' => null], ['id' => $person['id']]);

                    } else $data['text'] = 'قیمتی یافت نشد!';
                } elseif ($message['text'] == 'علاقه‌مندی‌ها') {
                    $favorites = $db->read(
                        table: 'favorites',
                        conditions: ['person_id' => $person['id']],
                        selectColumns: 'favorites.id, ' .
                        'assets.name AS asset_name, assets.asset_type, assets.price, assets.date, assets.time, ' .
                        'CONCAT(persons.first_name, \' \', COALESCE(persons.last_name, \' \')) AS person_name ',
                        join: 'JOIN assets ON assets.id=favorites.asset_id ' .
                        'JOIN persons ON persons.id=favorites.person_id ',
                        orderBy: ['asset_type' => 'DESC', 'id' => 'ASC']);

                    if ($favorites) {
                        $data['text'] = '';
                        foreach ($favorites as $favorite) {
                            $data['text'] .= beautifulNumber($favorite['asset_name'], null) . ': ' . beautifulNumber($favorite['price']) . "\n";
                        }
                    }

                } elseif ($person['progress']) {
                    $progress = json_decode($person['progress'], true);

                    if ($progress) {
                        $asset_id = $progress['new_alert']['asset_id'];

                        $cleaned_number = cleanAndValidateNumber($message['text']);
                        if ($cleaned_number) {

                            $date_time = majidAPI_date_time();
                            if ($date_time) {
                                $result = $db->upsert('alerts', [
                                    'person_id' => $person['id'],
                                    'asset_id' => $asset_id,
                                    'target_price' => $message['text'],
                                    'is_active' => true,
                                    'created_date' => $date_time['result']['jalali']['date'],
                                    'created_time' => $date_time['result']['jalali']['time'],
                                ]);

                                if ($result) $data['text'] = 'هشدار جدید با موفقیت ثبت شد!';
                                else $data['text'] = 'خطا در ثبت هشدار جدید!';
                            } else $data['text'] = 'خطا در دریافت تاریخ و زمان کنونی. دوباره امتحان کنید!';

                        } else $data['text'] = "پیام نامفهوم بود.\nلطفاً قیمت را تنها با استفاده از اعداد وارد کنید!";

                    } else $data['text'] = "پیام نامفهموم بود!\nلطفاً یکی از دسته‌بندی‌های زیر را انتخاب کنید:";
                } else $data['text'] = "پیام نامفهموم بود!\nلطفاً یکی از دسته‌بندی‌های زیر را انتخاب کنید:";
            }

        } else {
            $data['text'] = "دسته‌بندی‌ای در سیستم یافت نشد!";
        }

        $response = sendToTelegram('sendMessage', $data);
        exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));
    }

}

/**
 * Level 7: Add New Loan
 */
#[NoReturn]
function level_5(array $person, array|null $message = null, array|null $callback_query = null): void
{
    global $db;
    $telegram_method = 'sendMessage';
    $data = [
        'chat_id' => $person['chat_id'],
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(5, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'input_field_placeholder' => '➕ ثبت وام جدید',
        ]
    ];

    // Add web_app button(s)
    $data['reply_markup']['keyboard'] = array_merge([
        [
            [
                'text' => '➕ ثبت وام جدید', 'web_app' => ['url' => 'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/add_loan.html']
            ]
        ], [
            [
                'text' => '📋 لیست وام‌های من', 'web_app' => [
                'url' =>
                    'https://' . getenv('VERCEL_PROJECT_PRODUCTION_URL') . '/assets/list_loan.html?' .
                    'k=' . getenv('DB_API_SECRET') . '&' .
                    'id=' . $person['id']]
            ]
        ]
    ], $data['reply_markup']['keyboard']);

    if ($callback_query) {

        // Answer the query
        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        // $query_data = json_decode($callback_query['data'], true);

        // Delete the message generating the callback
        $telegram_method = 'editMessageText';
        unset($data['reply_markup']);
        $data['text'] = 'این پیام منقضی شده است.';
        $data['message_id'] = $message['message_id'];

    } elseif (!$message) {
        $loans = $db->read(
            'loans l',
            conditions: ['person_id' => $person['id']],
            selectColumns: "
                l.*, 
                JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'id', i.id,
                        'amount', i.amount,
                        'due_date', i.due_date,
                        'is_paid', i.is_paid
                    )
                ) as installments
                ",
            join: "LEFT JOIN installments i ON l.id=i.loan_id",
            groupBy: 'l.id',
        );

        if ($loans) {
            $data['text'] = 'وام‌های ثبت شده‌ی شما:';
            foreach ($loans as $loan) {
                $data['text'] .= "\n‏    " . $loan['name'] . ":";

                $installments = json_decode($loan['installments'], true);
                foreach ($installments as $installment) {
                    $paid_icon = ($installment['is_paid']) ? '✔' : '❌';
                    $data['text'] .= "\n‏        " . beautifulNumber($installment['due_date'], null) . ": " . beautifulNumber($installment['amount']) . $paid_icon;
                }
                $data['text'] .= "\n";
            }
        } else $data['text'] = 'هیچ وام ثبت شده‌ای ندارید!';

        $db->update('persons', ['last_btn' => 5], ['id' => $person['id']]);

    } else {

        if (isset($message['web_app_data'])) {
            $web_app_data = json_decode($message['web_app_data']['data'], true);

            if ($web_app_data && isset($web_app_data['loans']) & isset($web_app_data['installments'])) {

                $loanData = $web_app_data['loans'];
                $loan_insert_data = [
                    'person_id' => $person['id'],
                    'name' => $loanData['name'],
                    'total_amount' => $loanData['total_amount'],
                    'received_date' => $loanData['received_date'],
                    'total_installments' => $loanData['total_installments']
                ];

                $loan_id = $db->create('loans', $loan_insert_data);

                if ($loan_id) {
                    $installments = $web_app_data['installments'];
                    $count = 0;

                    foreach ($installments as $inst) {
                        $inst_insert_data = [
                            'loan_id' => $loan_id,
                            'amount' => $inst['amount'],
                            'due_date' => $inst['due_date'],
                            'is_paid' => $inst['is_paid'] ? 1 : 0
                        ];
                        $db->create('installments', $inst_insert_data);
                        $count++;
                    }

                    $data['text'] = "✅ وام «{$loanData['name']}» با موفقیت ثبت شد.\n📊 تعداد اقساط: $count";

                    sendToTelegram($telegram_method, $data); // Send success message to the user
                    level_5($person); // Call the level to send user the new list of their holdings

                } else $data['text'] = '❌ خطای پایگاه داده در ثبت وام.';
            } else $data['text'] = '❌ خط در دریافت اطلاعات. داده‌ها معتبر نیستند.';
        } else $data['text'] = 'پیام نامفهوم است!';
    }

    $response = sendToTelegram($telegram_method, $data);
    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));
}

/**
 * Helper to process price chunks for QuickChart.
 */
function pricesToChartConfig(array $price_chunks): array
{

    $averages = [];
    foreach ($price_chunks as $price_chunk) {
        $price_sum = 0;
        foreach ($price_chunk as $price) {
            $price_sum = $price_sum + floatval($price['price']);
        }

        $middle_index = (int)floor(count($price_chunk) / 2);
        $middle_price = $price_chunk[$middle_index];

        $averages[$middle_price['date']] = $price_sum / sizeof($price_chunk);
    }

    $labels[] = [];
    $datasets[0]['label'] = "قیمت " . $price_chunks[0][0]['asset_name'];
    $datasets[0]['borderColor'] = 'rgb(75, 192, 192)';

    $averages = array_reverse($averages);
    foreach ($averages as $key => $average) {
        $labels[] = $key; // Date
        $datasets[0]['data'][] = $average; // Average Price
    }

    return buildQuickChartConfig(
        type: 'line',
        labels: $labels,
        datasets: $datasets
    );
}

function createHoldingDetailText(array $holding, string|null $markdown = null, array $attributes = [
    'date',
    'org_amount',
    'org_price',
    'new_price',
    'org_total_price',
    'new_total_price',
    'profit',
]): string
{
    $text = '';

    $date = preg_split('/-/u', $holding['date']);
    $date[1] = str_replace(
        ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
        ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
        $date[1]);

    if ($markdown === 'MarkdownV2') {
        $holding['asset_name'] = beautifulNumber(str_replace(["(", ")"], ["\(", "\)"], $holding['asset_name']), null) . '‏';
        $holding['asset_name'] = "[" . $holding['asset_name'] . "](https://t.me/" . BOT_ID . "?start=holding_" . $holding['id'] . ")" . '‏';
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
    if ($markdown === 'MarkdownV2') $price_tree = str_replace(["(", ")", ".", "-"], ["\(", "\)", "\.", "\-"], $price_tree);

    return $text . $holding['asset_name'] . $price_tree;
}
