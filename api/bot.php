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

    $global_message = $update['message'];
    $user = $global_message['chat']; // Sender information

    // Check/Register User
    $global_person = $db->read('persons', ['chat_id' => $user['id']], true);

    if ($global_person === false) {
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
            $global_person = $db->read('persons', ['chat_id' => $user['id']], true);
        } else {
            error_log("[ERROR] Failed to create new user: " . $user['id']);
            return;
        }
    }

    // Handle Web App Data (e.g. Loans)
    if (isset($global_message['web_app_data'])) {
        processLoanData($global_person, $global_message['web_app_data']['data']);
        exit();
    }

    // ------------------------------
    // ----- The core bot logic -----
    // ------------------------------

    $global_pressed_button = getPressedButton(
        text: $global_message['text'],
        parent_btn_id: $global_person['last_btn'],
        admin: $global_person['is_admin'],
        db: $db
    );

    // Global Command Routing
    if ($global_message['text'] == '/holdings') {
        level_1($global_person);
    } elseif ($global_message['text'] == '/prices') {
        level_4($global_person);
    } else {
        // Route based on button/state
        choosePath(
            pressed_button: $global_pressed_button,
            message: $global_message,
            person: $global_person
        );
    }

} elseif (isset($update['callback_query'])) {
    // Process 'Inline' button presses.

    $callback_query = $update['callback_query'];

    // Acknowledge callback to stop the loading animation.
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

    $message = $callback_query['message'];
    $user = $callback_query['from'];

    $person = $db->read('persons', ['chat_id' => $user['id']], true);

    if ($person !== false) {
        // Normalize JSON quotes and decode
        $callback_data = json_decode(str_replace("'", '"', $callback_query['data']), true);

        choosePath(
            pressed_button: false,
            message: $message,
            person: $person,
            query_data: $callback_data
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
    array|null|false $query_data = null
): void
{
    global $global_pressed_button;
    global $global_message;
    global $global_person;
    global $db;

    if ($pressed_button === null) $pressed_button = $global_pressed_button;
    if ($message === null) $message = $global_message;
    if ($person === null) $person = $global_person;

    if ($query_data) {
        // Handle Callback Queries
        $data = [
            'text' => 'درخواست نامفهوم بود!',
            'message_id' => $message['message_id'],
            'chat_id' => $person['chat_id'],
        ];

        $text = null;
        if ($person['last_btn'] == 1) $text = level_1(person: $person, message: $message, query_data: $query_data);
        if ($person['last_btn'] == 4) $text = level_4(person: $person, message: $message, query_data: $query_data);

        if ($text) $data['text'] = $text;
        sendToTelegram('editMessageText', $data);

    } else {
        // Handle Text Messages
        if (!$pressed_button) {
            // No button matched: Handle as free text input or error
            $data = [
                'text' => $custom_text ?? 'پیام نامفهوم است!',
                'chat_id' => $person['chat_id'],
                'reply_markup' => [
                    'keyboard' => createKeyboardsArray($person['last_btn'], $person['is_admin'], $db),
                    'resize_keyboard' => true,
                ]];

            // Route to active level handler (Input Step)
            if ($person['last_btn'] == "1") level_1($person, $message); // View Holdings
            if ($person['last_btn'] == "2") level_2($person, $message); // Add Holding
            if ($person['last_btn'] == "4") level_4($person, $message); // View Prices

            $response = sendToTelegram('sendMessage', $data);
            if ($response) exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

        } else {
            // Button matched
            if (str_starts_with($pressed_button['id'], "s")) {
                // Special system buttons
                if ($pressed_button['id'] === "s0") backButton(person: $person);
                if ($pressed_button['id'] === "s1") cancelButton(person: $person);

            } else {
                // Menu Navigation
                if ($pressed_button['id'] == "1") level_1($person);
                if ($pressed_button['id'] == "2") level_2($person);
                if ($pressed_button['id'] == "4") level_4($person);

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
function level_1(array $person, array|null $message = null, array|null $query_data = null): string|null
{
    global $db;
    $data = [
        'chat_id' => $person['chat_id'],
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(1, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'input_field_placeholder' => 'دارایی‌ها',
        ]
    ];

    $holdings = $db->read(
        table: 'holdings',
        conditions: ['person_id' => $person['id']],
        selectColumns: '
        holdings.*,
        a.name as asset_name,
        a.price as current_price,
        a.base_currency,
        a.exchange_rate as base_rate',
        join: 'INNER JOIN assets a ON holdings.asset_id = a.id');

    if ($query_data) {

        if (array_key_first($query_data) === 'show_chart') {
            sendToTelegram('deleteMessage', [
                'chat_id' => $person['chat_id'],
                'message_id' => $message['message_id']
            ]);

            $price_chunks = $db->read(
                table: 'prices',
                conditions: ['asset_id' => $query_data['show_chart']['asset_id']],
                selectColumns: 'prices.*, a.name as asset_name',
                join: 'INNER JOIN assets a ON prices.asset_id = a.id',
                orderBy: ['prices.date' => "DESC"],
                limit: 99,
                chunkSize: 9
            );

            if ($price_chunks) {
                $chart_config = pricesToChartConfig($price_chunks);
                try {
                    $url = generateQuickChartUrl($chart_config, height: 700);
                    $data['chat_id'] = $person['chat_id'];
                    $data['photo'] = $url;
                    $data['caption'] = 'قیمت «' . $price_chunks[0][0]['asset_name'] . '»';

                    $response = sendToTelegram('sendPhoto', $data);
                    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

                } catch (Exception $e) {
                    error_log("[ERROR] Chart generation failed: " . $e->getMessage());
                }
            }
            exit();
        }
        if (array_key_first($query_data) === 'edit_holding') {

            $index = array_search($query_data['edit_holding']['holding_id'], array_column($holdings, 'id'));
            $holding = ($index !== false) ? $holdings[$index] : null;
            if ($holding) {
                $data['text'] = createHoldingDetailText(holding: $holding);
                $data['text'] = $data['text'] . "\n\n" . '*کدام مشخصه‌ی این دارایی را می‌خواهید ویرایش کنید؟*';
                $data['text'] = str_replace(["(", ")", ".", "-"], ["\(", "\)", "\.", "\-"], $data['text']);
                $data['parse_mode'] = "MarkdownV2";
                $data['message_id'] = $message['message_id'];
                $data['reply_markup'] = [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '🏷️ نوع دارایی',
                                'callback_data' => str_replace('"', "'", json_encode([
                                    'edit_asset_id' => [
                                        'holding_id' => $query_data['edit_holding']['holding_id']
                                    ]]))
                            ],
                            [
                                'text' => '💲 قیمت خرید',
                                'callback_data' => str_replace('"', "'", json_encode([
                                    'edit_price' => [
                                        'holding_id' => $query_data['edit_holding']['holding_id']
                                    ]]))
                            ]
                        ], [
                            [
                                'text' => '💰 مقدار خرید',
                                'callback_data' => str_replace('"', "'", json_encode([
                                    'edit_amount' => [
                                        'holding_id' => $query_data['edit_holding']['holding_id']
                                    ]]))
                            ],
                            [
                                'text' => '📅 تاریخ و زمان خرید',
                                'callback_data' => str_replace('"', "'", json_encode([
                                    'edit_date' => [
                                        'holding_id' => $query_data['edit_holding']['holding_id']
                                    ]]))
                            ]
                        ], [
                            [
                                'text' => '🗑️ حذف دارایی',
                                'callback_data' => str_replace('"', "'", json_encode([
                                    'delete_holding' => ['holding_id' => $query_data['edit_holding']['holding_id']]
                                ]))
                            ]
                        ], [
                            [
                                'text' => '🔙 برگشت 🔙',
                                'callback_data' => str_replace('"', "'", json_encode([
                                    'view_holding' => ['holding_id' => $query_data['edit_holding']['holding_id']]
                                ]))
                            ]
                        ]
                    ]
                ];
            } else return null;

            $response = sendToTelegram('editMessageText', $data);
            exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));
        }
        {
            if (array_key_first($query_data) === 'delete_holding') {

                if (!key_exists('confirmed', $query_data['delete_holding'])) {

                    $index = array_search($query_data['delete_holding']['holding_id'], array_column($holdings, 'id'));
                    $holding = ($index !== false) ? $holdings[$index] : null;
                    if ($holding) {

                        $data['text'] = createHoldingDetailText(holding: $holding);
                        $data['text'] = $data['text'] . "\n\n" . "*آیا از حذف این دارایی اطمینان دارید؟*";
                        $data['text'] = str_replace(["(", ")", ".", "-"], ["\(", "\)", "\.", "\-"], $data['text']);
                        $data['parse_mode'] = "MarkdownV2";
                        $data['message_id'] = $message['message_id'];
                        $data['reply_markup'] = [
                            'inline_keyboard' => [
                                [
                                    [
                                        'text' => '✅ بله',
                                        'callback_data' => str_replace('"', "'", json_encode([
                                            'delete_holding' => [
                                                'holding_id' => $query_data['delete_holding']['holding_id'],
                                                'confirmed' => true
                                            ]
                                        ]))
                                    ],
                                    [
                                        'text' => '❌ لغو',
                                        'callback_data' => str_replace('"', "'", json_encode([
                                            'edit_holding' => [
                                                'holding_id' => $query_data['delete_holding']['holding_id']
                                            ]]))
                                    ]
                                ]
                            ]
                        ];

                    } else return 'دارایی با این مشخصه یافت نشد!';

                } else {
                    $result = $db->delete('holdings', ['id' => $query_data['delete_holding']['holding_id']], resetAutoIncrement: true);

                    if ($result) {
                        sendToTelegram('editMessageText', [
                            'chat_id' => $message['chat']['id'],
                            'text' => '✅ دارایی با موفقیت حذف شد!',
                            'message_id' => $message['message_id'],
                        ]);
                        level_1($person);
                    } else $data['text'] = 'خطا!';
                }

                $response = sendToTelegram('editMessageText', $data);
                exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

            }
            if (array_key_first($query_data) === 'edit_asset_id') {

                $asset_types = $db->read('assets', selectColumns: 'asset_type', distinct: true);
                if ($asset_types) {

                    $index = array_search($query_data['edit_asset_id']['holding_id'], array_column($holdings, 'id'));
                    $holding = ($index !== false) ? $holdings[$index] : null;
                    if ($holding) {

                        $data['text'] = '*دسته‌بندی جدید را برای این دارایی انتخاب کنید:*';
                        $data['text'] = str_replace(["(", ")", ".", "-"], ["\(", "\)", "\.", "\-"], $data['text']);
                        $data['parse_mode'] = "MarkdownV2";
                        $data['message_id'] = $message['message_id'];
                        $data['reply_markup'] = ['inline_keyboard' => []];
                        $asset_types = array_reverse(array_column($asset_types, 'asset_type'));
                        foreach ($asset_types as $index => $asset_type) array_unshift($data['reply_markup']['inline_keyboard'],
                            [
                                [
                                    'text' => $asset_type,
                                    'callback_data' => str_replace('"', "'", json_encode([
                                            'edit_asset_type' => [
                                                'holding_id' => $query_data['edit_asset_id']['holding_id'],
                                                'type_index' => $index,
                                            ]
                                        ]
                                    ))
                                ]
                            ]
                        );
                        $data['reply_markup']['inline_keyboard'][] = [
                            [
                                'text' => '🔙 برگشت 🔙',
                                'callback_data' => str_replace('"', "'", json_encode(['edit_holding' => ['holding_id' => $query_data['edit_asset_id']['holding_id']]]))
                            ],
                        ];
                        $response = sendToTelegram('editMessageText', $data);
                        exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

                    } else return 'دارایی با این مشخصه یافت نشد!';
                } else return 'دسته‌بندی‌ای در دیتابیس یافت نشد!';

            }
            {
                if (array_key_first($query_data) === 'edit_asset_type') {

                    $asset_types = $db->read('assets', selectColumns: 'asset_type', distinct: true);
                    if ($asset_types) {

                        $assets = $db->read('assets', [
                            'asset_type' => array_reverse($asset_types)[$query_data['edit_asset_type']['type_index']]['asset_type']]);
                        if ($assets) {

                            $data['text'] = '*دارایی را انتخاب کنید:*';
                            $data['text'] = str_replace(["(", ")", ".", "-"], ["\(", "\)", "\.", "\-"], $data['text']);
                            $data['parse_mode'] = "MarkdownV2";
                            $data['message_id'] = $message['message_id'];
                            $data['reply_markup'] = ['inline_keyboard' => []];
                            $assets = array_reverse($assets);
                            foreach ($assets as $asset) array_unshift($data['reply_markup']['inline_keyboard'],
                                [
                                    [
                                        'text' => $asset['name'],
                                        'callback_data' => str_replace('"', "'", json_encode([
                                                'edit_asset' => [
                                                    'holding_id' => $query_data['edit_asset_type']['holding_id'],
                                                    'asset_id' => $asset['id'],
                                                ]
                                            ]
                                        ))
                                    ]
                                ]
                            );
                            $data['reply_markup']['inline_keyboard'][] = [
                                [
                                    'text' => '🔙 برگشت 🔙',
                                    'callback_data' => str_replace('"', "'", json_encode([
                                        'edit_asset_id' => [
                                            'holding_id' => $query_data['edit_asset_type']['holding_id']
                                        ]]))
                                ],
                            ];

                            $response = sendToTelegram('editMessageText', $data);
                            exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

                        } else return 'دارایی‌ای برای این دسته یافت نشد!';
                    } else return 'دسته‌بندی‌ای در دیتابیس یافت نشد!';

                }
                if (array_key_first($query_data) === 'edit_asset') {

                    $result = $db->update('holdings', ['asset_id' => $query_data['edit_asset']['asset_id']], ['id' => $query_data['edit_asset']['holding_id']]);
                    if ($result) {
                        sendToTelegram('editMessageText', [
                            'chat_id' => $message['chat']['id'],
                            'text' => '✅ دارایی با موفقیت ویرایش شد!',
                            'message_id' => $message['message_id'],
                        ]);
                        level_1($person);
                    } else return 'خطا در ویرایش دارایی!';

                }
            }
            if (array_key_first($query_data) === 'edit_price') {

                $index = array_search($query_data['edit_price']['holding_id'], array_column($holdings, 'id'));
                $holding = ($index !== false) ? $holdings[$index] : null;
                if ($holding) {

                    $data['text'] = createHoldingDetailText(holding: $holding);
                    $data['text'] = $data['text'] . "\n\n" . '*قیمت جدید را برای خرید این دارایی وارد کنید:*';
                    $data['text'] = str_replace(["(", ")", ".", "-"], ["\(", "\)", "\.", "\-"], $data['text']);
                    $data['parse_mode'] = "MarkdownV2";
                    $data['message_id'] = $message['message_id'];
                    $data['reply_markup'] = ['inline_keyboard' => [
                        [
                            [
                                'text' => '🔙 برگشت 🔙',
                                'callback_data' => str_replace('"', "'", json_encode(['edit_holding' => ['holding_id' => $query_data['edit_price']['holding_id']]]))
                            ]
                        ],
                    ]];

                    $response = sendToTelegram('editMessageText', $data);
                    if ($response) {
                        $progress = ['edit_holding_price' => ['holding_id' => $query_data['edit_price']['holding_id']]];
                        $db->update('persons', ['progress' => json_encode($progress)], ['id' => $person['id']]);
                    }
                    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

                } else return 'دارایی با این مشخصه یافت نشد!';
            }
            if (array_key_first($query_data) === 'edit_amount') {

                $index = array_search($query_data['edit_amount']['holding_id'], array_column($holdings, 'id'));
                $holding = ($index !== false) ? $holdings[$index] : null;
                if ($holding) {

                    $data['text'] = createHoldingDetailText(holding: $holding);
                    $data['text'] = $data['text'] . "\n\n" . '*مقدار جدید این دارایی وارد کنید:*';
                    $data['text'] = str_replace(["(", ")", ".", "-"], ["\(", "\)", "\.", "\-"], $data['text']);
                    $data['parse_mode'] = "MarkdownV2";
                    $data['message_id'] = $message['message_id'];
                    $data['reply_markup'] = ['inline_keyboard' => [
                        [
                            [
                                'text' => '🔙 برگشت 🔙',
                                'callback_data' => str_replace('"', "'", json_encode(['edit_holding' => ['holding_id' => $query_data['edit_amount']['holding_id']]]))
                            ]
                        ],
                    ]];

                    $response = sendToTelegram('editMessageText', $data);
                    if ($response) {
                        $progress = ['edit_holding_amount' => ['holding_id' => $query_data['edit_amount']['holding_id']]];
                        $db->update('persons', ['progress' => json_encode($progress)], ['id' => $person['id']]);
                    }
                    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

                } else return 'دارایی با این مشخصه یافت نشد!';
            }
            if (array_key_first($query_data) === 'edit_date') {

                $index = array_search($query_data['edit_date']['holding_id'], array_column($holdings, 'id'));
                $holding = ($index !== false) ? $holdings[$index] : null;
                if ($holding) {

                    $data['text'] = createHoldingDetailText(holding: $holding);
                    $data['text'] = $data['text'] . "\n\n" . '*تاریخ خرید را وارد کنید. سعی کنید به طور واضح تاریخ و ساعت انجام خرید این دارایی را بنویسید. این پیام توسط هوش مصنوعی پردازش می‌شود.*';
                    $data['text'] = str_replace(["(", ")", ".", "-"], ["\(", "\)", "\.", "\-"], $data['text']);
                    $data['parse_mode'] = "MarkdownV2";
                    $data['message_id'] = $message['message_id'];
                    $data['reply_markup'] = ['inline_keyboard' => [
                        [
                            [
                                'text' => '🔙 برگشت 🔙',
                                'callback_data' => str_replace('"', "'", json_encode(['edit_holding' => ['holding_id' => $query_data['edit_date']['holding_id']]]))
                            ]
                        ],
                    ]];

                    $response = sendToTelegram('editMessageText', $data);
                    if ($response) {
                        $progress = ['edit_holding_date' => ['holding_id' => $query_data['edit_date']['holding_id']]];
                        $db->update('persons', ['progress' => json_encode($progress)], ['id' => $person['id']]);
                    }
                    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

                } else return 'دارایی با این مشخصه یافت نشد!';
            }
            if (array_key_first($query_data) === 'view_holding') {

                $index = array_search($query_data['view_holding']['holding_id'], array_column($holdings, 'id'));
                $holding = ($index !== false) ? $holdings[$index] : null;

                if ($holding) {

                    $data['chat_id'] = $message['chat']['id'];
                    $data['text'] = createHoldingDetailText(holding: $holding);
                    $data['message_id'] = $message['message_id'];
                    $data['reply_markup'] = [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📊 نمایش نمودار',
                                    'callback_data' => str_replace('"', "'", json_encode(['show_chart' => ['asset_id' => $holding['asset_id']]]))
                                ],
                            ], [
                                [
                                    'text' => '✏ ویرایش',
                                    'callback_data' => str_replace('"', "'", json_encode(['edit_holding' => ['holding_id' => $holding['id']]]))
                                ],
                            ]
                        ]
                    ];
                    $response = sendToTelegram('editMessageText', $data);
                    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

                } else return 'دارایی با این مشخصه یافت نشد!';

            }
        }

        return null;

    } elseif ($message) {

        // Check deep-link: /start <holding_id>
        $matched = preg_match("/^\/start (\d*)$/m", $message['text'], $matches);
        if ($matched) {

            if ($matches[1]) {

                $index = array_search($matches[1], array_column($holdings, 'id'));
                $holding = ($index !== false) ? $holdings[$index] : null;

                if ($holding) {
                    $data['text'] = createHoldingDetailText(holding: $holding);
                    $data['reply_markup'] = [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📊 نمایش نمودار',
                                    'callback_data' => str_replace('"', "'", json_encode(['show_chart' => ['asset_id' => $holding['asset_id']]]))
                                ],
                            ], [
                                [
                                    'text' => '✏ ویرایش',
                                    'callback_data' => str_replace('"', "'", json_encode(['edit_holding' => ['holding_id' => $holding['id']]]))
                                ],
                            ]
                        ]
                    ];
                } else $data['text'] = 'دارایی با این مشخصه یافت نشد!';

            } else $data['text'] = 'الگوی پیام اشتباه است!';

        } else {
            $progress = json_decode($person['progress'], true);

            if ($progress) {
                if (array_key_last($progress) == 'edit_holding_price') {

                    $cleaned_number = cleanAndValidateNumber($message['text']);
                    if ($cleaned_number) {
                        $result = $db->update('holdings', ['avg_price' => $cleaned_number], ['id' => $progress["edit_holding_price"]["holding_id"]]);
                        if ($result) {
                            sendToTelegram('sendMessage', [
                                'chat_id' => $message['chat']['id'],
                                'text' => '✅ دارایی با موفقیت ویرایش شد!',
                            ]);
                            $db->update('persons', ['progress' => null], ['id' => $person['id']]);
                            level_1($person);
                        }
                    } else $data['text'] = 'لطفاً قیمت را تنها با استفاده از اعداد ارسال کنید!';
                }
                if (array_key_last($progress) == 'edit_holding_amount') {

                    $cleaned_number = cleanAndValidateNumber($message['text']);
                    if ($cleaned_number) {
                        $result = $db->update('holdings', ['amount' => $cleaned_number], ['id' => $progress["edit_holding_amount"]["holding_id"]]);
                        if ($result) {
                            sendToTelegram('sendMessage', [
                                'chat_id' => $message['chat']['id'],
                                'text' => '✅ دارایی با موفقیت ویرایش شد!',
                            ]);
                            $db->update('persons', ['progress' => null], ['id' => $person['id']]);
                            level_1($person);
                        }
                    } else $data['text'] = 'لطفاً مقدار دارایی را تنها با استفاده از اعداد ارسال کنید!';
                }
                if (array_key_last($progress) == 'edit_holding_date') {

                    $waiting_response = sendToTelegram('sendMessage', [
                        'text' => '🧠 در حال پردازش پیام توسط هوش مصنوعی ...',
                        'chat_id' => $person['chat_id'],
                    ]);

                    $jalali = majidAPI_date_time()['result']['jalali'];
                    $current_datetime = "$jalali[date] $jalali[time]";

                    $user_query = "
                    TASK:
                        I want a JSONized string to use in my application.
                        Read the user request bellow and extract the exact time and date the user is mentioning in the request.
                        The date is going to be in Hijri Jalali. So it probably will be in range of year 1300 to 1500.
                        Pay extra attention if the date or time the user is mentioning is relative to current date or time.
                        Current date and time are: '$current_datetime'
                    USER REQUEST:
                        ```
                            $message[text]
                        ```
                    OUTPUT:
                        Return the result STRICTLY as a JSON object following this schema:
                            ```json {\"status\": \"Success\", \"result\": {\"date\": \"yyy-mm-dd\", \"time\": \"hh:mm\"}}```
                        Or this schema if there is no date nor time specified in the user request:
                            ```json {\"status\": \"Error\", \"result\": \"PROPER ERROR\"}```";


                    try {
                        $majidAPI_response = majidAPI_ai($user_query);

                        sendToTelegram('deleteMessage', [
                            'chat_id' => $waiting_response['result']['chat']['id'],
                            'message_id' => $waiting_response['result']['message_id']
                        ]);

                        if (!$majidAPI_response) error_log("[ERROR] MajidAPI failed to return a response.");
                        elseif ($majidAPI_response['status'] === 200) {
                            preg_match_all("/^```json\s(.*?)\s```$/m", $majidAPI_response['result'], $matches);
                            $ai_response = json_decode($matches[1][0], true);

                            if ($ai_response['status'] === 'Success') {

                                $result = $db->update(
                                    'holdings',
                                    [
                                        'date' => $ai_response['result']['date'],
                                        'time' => $ai_response['result']['time']
                                    ],
                                    ['id' => $progress["edit_holding_date"]["holding_id"]]);

                                if ($result) {
                                    $data['text'] = "✅ دارایی جدید با موفقیت ثبت شد!";
                                } else {
                                    $data['text'] = "❌ خطا در ثبت دارایی.";
                                    error_log("[ERROR] Error updating holding date.");
                                }

                                sendToTelegram('sendMessage', $data);
                                level_1($person);

                            } elseif ($ai_response['status'] === 'Error') sendToTelegram('sendMessage', ['chat_id' => $person['chat_id'], 'text' => 'هوش مصنوعی قادر به استخراج اطلاعات تاریخ و زمان از پیام شما نبود. لطفاً دوباره تلاش کنید.']);
                            else error_log("[ERROR] AI No date found: " . json_encode($majidAPI_response));

                        } else error_log("[ERROR] AI Unknown Error: " . json_encode($majidAPI_response));

                        $db->update('persons', ['progress' => null], ['id' => $person['id']]);
                        exit();

                    } catch (Exception $e) {
                        error_log("[EXCEPTION] " . $e->getMessage());
                        exit("❌ An exception occurred: " . $e->getMessage());
                    }
                }
            }
        }

    } else {

        if ($holdings) {

            $data['text'] = "دارایی‌های ثبت شده‌ی شما:\n";
            $total_profit = 0;
            foreach ($holdings as $holding) {
                $total_profit += $holding['amount'] * ($holding['current_price'] - $holding['avg_price']) * $holding['base_rate'];
                $data['text'] = $data['text'] . "\n" . createHoldingDetailText(holding: $holding, markdown: 'MarkdownV2');
            }
            $total_profit = ($total_profit >= 0) ?
                "🟢 کل سود: " . beautifulNumber($total_profit) . ' ریال' :
                "🔴 کل ضرر: " . beautifulNumber($total_profit) . ' ریال';
            $total_profit = str_replace(["."], ["\."], $total_profit);
            $data['text'] .= "\n" . $total_profit;

            $data['parse_mode'] = "MarkdownV2";

        } else $data['text'] = 'شما هیچ دارایی‌ای ثبت نکرده‌اید.';

    }

    $person['last_btn'] = 1;
    $db->update('persons', $person, ['id' => $person['id']]);

    $response = sendToTelegram('sendMessage', $data);
    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));
}

/**
 * Level 2: Add New Holding (Multi-step form)
 */
#[NoReturn]
function level_2(array $person, array|null|bool $message = null): string|null
{
    global $db;
    $data = [
        'chat_id' => $person['chat_id'],
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(2, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'input_field_placeholder' => 'دارایی‌ها',
        ]
    ];

    if ($message === null) {

        $asset_types = $db->read('assets', selectColumns: 'asset_type', distinct: true);
        if ($asset_types) {

            $asset_types = array_column($asset_types, 'asset_type');
            foreach ($asset_types as $asset_type) $data['reply_markup']['keyboard'][] = [['text' => $asset_type]];

            $data['text'] = "دسته بندی دارایی جدید را از دکمه‌های زیر انتخاب کنید:";
            $progress = ["asset_type" => null];

            $person['last_btn'] = 2;
            $person['progress'] = json_encode($progress);
            $db->update('persons', $person, ['id' => $person['id']]);

        } else $data['text'] = "دسته‌بندی‌ای در دیتابیس یافت نشد!";

    } else {

        if ($person['progress']) $progress = json_decode($person['progress'], true);
        else $progress = null;

        // Step 1: Asset Type
        if ($progress && array_key_last($progress) == 'asset_type') {

            if (is_bool($message) && $message === false) $message = ['text' => $progress['asset_type']];

            $assets = $db->read('assets', ['asset_type' => $message['text']], selectColumns: 'name', distinct: true);
            if ($assets) {

                $assets = array_column($assets, 'name');
                foreach ($assets as $asset) $data['reply_markup']['keyboard'][] = [['text' => $asset]];

                $data['text'] = "دارایی جدید را از دکمه‌های زیر انتخاب کنید:";
                $progress["asset_type"] = $message['text'];
                $progress["asset"] = null;

                $response = sendToTelegram('sendMessage', $data);
                if ($response) $db->update('persons', ['progress' => json_encode($progress)], ['id' => $person['id']]);

                exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

            } else {
                $person['progress'] = null;
                level_2($person);
            }
        }

        // Step 2: Asset Name
        if ($progress && array_key_last($progress) == 'asset') {

            if (is_bool($message) && $message === false) $message = ['text' => $progress['asset']['name']];

            $asset = $db->read('assets', ['name' => $message['text']], true);
            if ($asset) {

                $data['text'] = "میزان دارایی را به شکل عددی ارسال کنید.";
                $progress["asset"] = $asset;
                $progress["amount"] = null;

                $response = sendToTelegram('sendMessage', $data);
                if ($response) $db->update('persons', ['progress' => json_encode($progress)], ['id' => $person['id']]);

                exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

            } else {
                array_pop($progress);
                $person['progress'] = json_encode($progress);
                level_2($person, false);
            }

        }

        // Step 3: Amount
        if ($progress && array_key_last($progress) == 'amount') {

            if (is_bool($message) && $message === false) $message = ['text' => $progress['amount']];

            $cleaned_number = cleanAndValidateNumber($message['text']);
            if ($cleaned_number) {

                $asset = $progress['asset'];
                $data['text'] = 'میانگین قیمت خرید هر واحد را به ' . $asset['base_currency'] . ' وارد کنید:' . "\n\n" .
                    'قیمت کنونی ' . $asset['name'] . ': ' . beautifulNumber($asset['price']) . ' ' . $asset['base_currency'] . ' است.';
                $progress["amount"] = $cleaned_number;
                $progress["price"] = null;

                $response = sendToTelegram('sendMessage', $data);
                if ($response) $db->update('persons', ['progress' => json_encode($progress)], ['id' => $person['id']]);

                exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

            } else {
                array_pop($progress);
                $person['progress'] = json_encode($progress);
                level_2($person, false);
            }

        }

        // Step 4: Price
        if ($progress && array_key_last($progress) == 'price') {

            if (is_bool($message) && $message === false) $message = ['text' => $progress['price']];

            $cleaned_number = cleanAndValidateNumber($message['text']);
            if ($cleaned_number) {

                $data['text'] = 'تاریخ خرید را وارد کنید. سعی کنید به طور واضح تاریخ و ساعت انجام خرید این دارایی را بنویسید. این پیام توسط هوش مصنوعی پردازش می‌شود.';

                $progress["price"] = $cleaned_number;
                $progress["date"] = null;

                $response = sendToTelegram('sendMessage', $data);
                if ($response) $db->update('persons', ['progress' => json_encode($progress)], ['id' => $person['id']]);

                exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

            } else {
                array_pop($progress);
                $person['progress'] = json_encode($progress);
                level_2($person, false);
            }

        }

        // Step 4.5: Exchange Rate (Ignored in logic flow but preserved)
        if ($progress && array_key_last($progress) == 'exchange_rate') {

            if (is_bool($message) && $message === false) $message = ['text' => $progress['exchange_rate']];

            $cleaned_number = cleanAndValidateNumber($message['text']);
            if ($cleaned_number) {

                $data['text'] = 'تاریخ خرید را وارد کنید. سعی کنید به طور واضح تاریخ و ساعت انجام خرید این دارایی را بنویسید. این پیام توسط هوش مصنوعی پردازش می‌شود.';
                $progress["exchange_rate"] = $cleaned_number;
                $progress["date"] = null;

                $response = sendToTelegram('sendMessage', $data);
                if ($response) $db->update('persons', ['progress' => json_encode($progress)], ['id' => $person['id']]);

                exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));

            } else {
                array_pop($progress);
                $person['progress'] = json_encode($progress);
                level_2($person, false);
            }

        }

        // Step 5: Date/Time (AI)
        if ($progress && array_key_last($progress) == 'date') {

            if (is_bool($message) && $message === false) $message = ['text' => $progress['price']];

            $waiting_response = sendToTelegram('sendMessage', [
                'text' => '🧠 در حال پردازش پیام توسط هوش مصنوعی ...',
                'chat_id' => $person['chat_id'],
            ]);

            $jalali = majidAPI_date_time()['result']['jalali'];
            $current_datetime = "$jalali[date] $jalali[time]";

            $user_query = "
                    TASK:
                        I want a JSONized string to use in my application.
                        Read the user request bellow and extract the exact time and date the user is mentioning in the request.
                        The date is going to be in Hijri Jalali. So it probably will be in range of year 1300 to 1500.
                        Pay extra attention if the date or time the user is mentioning is relative to current date or time.
                        Current date and time are: '$current_datetime'
                    USER REQUEST:
                        ```
                            " . $message['text'] . "
                        ```
                    OUTPUT:
                        Return the result STRICTLY as a JSON object following this schema:
                            ```json {\"status\": \"Success\", \"result\": {\"date\": \"yyy-mm-dd\", \"time\": \"hh:mm\"}}```
                        Or this schema if there is no date nor time specified in the user request:
                            ```json {\"status\": \"Error\", \"result\": \"PROPER ERROR\"}```";


            try {
                $majidAPI_response = majidAPI_ai($user_query);

                sendToTelegram('deleteMessage', [
                    'chat_id' => $waiting_response['result']['chat']['id'],
                    'message_id' => $waiting_response['result']['message_id']
                ]);

                if (!$majidAPI_response) {

                    $data['text'] = 'مشکل در برقراری ارتباط با سرور هوش مصنوعی. لطفاً دوباره امتحان کنید.';
                    error_log("[ERROR] MajidAPI failed.");

                } elseif ($majidAPI_response['status'] === 200) {
                    preg_match_all("/^```json\s(.*?)\s```$/m", $majidAPI_response['result'], $matches);
                    $ai_response = json_decode($matches[1][0], true);

                    if ($ai_response['status'] === 'Success') {

                        $holding['person_id'] = $person['id'];
                        $holding['asset_id'] = $progress['asset']['id'];
                        $holding['amount'] = $progress['amount'];
                        $holding['avg_price'] = $progress['price'];
                        $holding['exchange_rate'] = $progress['asset']['exchange_rate'];
                        $holding['date'] = $ai_response['result']['date'];
                        $holding['time'] = $ai_response['result']['time'];

                        $add_result = $db->create('holdings', $holding);

                        if ($add_result) {
                            $data['text'] = "✅ دارایی جدید با موفقیت ثبت شد!";
                        } else {
                            $data['text'] = "❌ خطا در ثبت دارایی.";
                            error_log("[ERROR] Error adding new holding: " . json_encode($holding));
                        }

                        $db->update('persons', ['progress' => null], ['id' => $person['id']]);

                    } elseif ($ai_response['status'] === 'Error') {
                        array_pop($progress);
                        $person['progress'] = json_encode($progress);
                        level_2($person, false);

                    } else {
                        $data['text'] = 'خطا در پردازش پاسخ از سرور هوش مصنوعی. لطفاً دوباره امتحان کنید.';
                        error_log("[ERROR] AI No date found: " . json_encode($majidAPI_response));
                    }

                } else {
                    $data['text'] = 'خطای ناشناخته. لطفاً دوباره امتحان کنید.';
                    error_log("[ERROR] AI Unexpected Response: " . json_encode($majidAPI_response));
                }

            } catch (Exception $e) {
                $data['text'] = "❌ An exception occurred: " . $e->getMessage();
            }

            sendToTelegram('sendMessage', $data);
            if (isset($add_result)) cancelButton($person);
            exit(json_encode(['status' => 'OK', 'Progress' => $progress]));
        }

        $data['text'] = "Fatal Error!";
        error_log("[CRITICAL] No progress in Level 2. User: " . $person['id']);
    }

    $response = sendToTelegram('sendMessage', $data);
    exit(json_encode(['status' => 'OK', 'telegram_response' => $response]));
}

/**
 * Level 4: View Prices
 */
#[NoReturn]
function level_4(array $person, array|null $message = null, array|null $query_data = null): null|string
{
    global $db;
    $data = [
        'chat_id' => $person['chat_id'],
        'reply_markup' => [
            'keyboard' => createKeyboardsArray(4, $person['is_admin'], $db),
            'resize_keyboard' => true,
            'input_field_placeholder' => 'دارایی‌ها',
        ]
    ];

    if ($query_data) {

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
                                'callback_data' => str_replace('"', "'", json_encode(['show_chart' => ['id' => $asset['id']]]))
                            ],
                        ], [
                            [
                                'text' => '🔔 هشدار قیمت',
                                'callback_data' => str_replace('"', "'", json_encode(['price_alert' => ['id' => $asset['id']]]))
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
                            'callback_data' => str_replace('"', "'", json_encode(['new_alert' => ['id' => $asset_id]]))
                        ]
                    ], [
                        [
                            'text' => '🔙 برگشت 🔙',
                            'callback_data' => str_replace('"', "'", json_encode(['view_asset' => ['id' => $asset_id]]))
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
                                            'callback_data' => str_replace('"', "'", json_encode(['show_chart' => ['id' => $asset['id']]]))
                                        ],
                                    ], [
                                        [
                                            'text' => '🔔 هشدار قیمت',
                                            'callback_data' => str_replace('"', "'", json_encode(['price_alert' => ['id' => $asset['id']]]))
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
        $holding['asset_name'] = "[" . $holding['asset_name'] . "](https://t.me/" . BOT_ID . "?start=" . $holding['id'] . ")" . '‏';
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

/**
 * Handles Web App Data (Loans)
 */
function processLoanData(array $person, string $json_data): void
{
    global $db;

    $data = json_decode($json_data, true);

    if (!$data || !isset($data['loans']) || !isset($data['installments'])) {
        sendToTelegram('sendMessage', [
            'chat_id' => $person['chat_id'],
            'text' => '❌ خط در دریافت اطلاعات. داده‌ها معتبر نیستند.'
        ]);
        return;
    }

    $loanData = $data['loans'];
    $loan_insert_data = [
        'person_id' => $person['id'],
        'name' => $loanData['name'],
        'total_amount' => $loanData['total_amount'],
        'received_date' => $loanData['received_date'],
        'total_installments' => $loanData['total_installments']
    ];

    $loan_id = $db->create('loans', $loan_insert_data);

    if ($loan_id) {
        $installments = $data['installments'];
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

        sendToTelegram('sendMessage', [
            'chat_id' => $person['chat_id'],
            'text' => "✅ وام «{$loanData['name']}» با موفقیت ثبت شد.\n📊 تعداد اقساط: $count"
        ]);

        $person['last_btn'] = 5;
        $db->update('persons', $person, ['id' => $person['id']]);

    } else {
        sendToTelegram('sendMessage', [
            'chat_id' => $person['chat_id'],
            'text' => '❌ خطای پایگاه داده در ثبت وام.'
        ]);
    }
}