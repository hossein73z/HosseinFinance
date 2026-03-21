<?php

use JetBrains\PhpStorm\NoReturn;

require_once __DIR__ . '/../Libraries/DatabaseManager.php';
require_once __DIR__ . '/../Functions/ExternalEndpointsFunctions.php';
require_once __DIR__ . '/../Functions/StringHelper.php';
require_once __DIR__ . '/../Functions/MessageFunctions.php';
require_once __DIR__ . '/../Models/User.php';

// --- CONFIGURATION ---
define('PRICE_BOT_TOKEN', getenv('PRICE_BOT_TOKEN'));
define('SHARED_SECRET', getenv('SHARED_SECRET'));

// Read the raw POST data from the incoming webhook request body.
$input = file_get_contents('php://input');

// --- Security Check ---
$header_secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
$query_secret = $_GET['secret'] ?? null;

// Validate against the SHARED_SECRET constant defined above via env vars
if (($header_secret !== SHARED_SECRET) && ($query_secret !== SHARED_SECRET)) {
    http_response_code(403);
    error_log("SECURITY ALERT: Access denied. Invalid secret. Input size: " . strlen($input));
    die();
}

// Attempt to decode the JSON input into a PHP associative array.
$message = json_decode($input, true)['message'];

// Check for JSON decoding errors.
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    // Return a structured JSON error response.
    error_log(json_encode(['status' => 'error', 'message' => 'Invalid JSON received. Error: ' . json_last_error_msg() . '. Input: ' . $input]));
    die();
}

http_response_code(200);

// --- Message Data Extraction (Date and Time) ---
if (preg_match_all('/\|[  ].*? ((\d\d?) (.*?) (\d\d\d\d)) -[  ](\d\d:\d\d)/ums', $message['text'], $date_time_matches)) {

    $time = $date_time_matches[5][0];
    // Year
    $date = $date_time_matches[4][0] . "-";
    // Month conversion from Persian month names to numerical months
    $date .= str_replace(
            ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند', ' '],
            ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '-'],
            $date_time_matches[3][0]) . "-";
    // Day
    $date .= $date_time_matches[2][0];

    $asset_type = null;
    // --- Price Category 1: Precious Metals (فلزات گرانبها) ---
    if (preg_match('/^⭕️ قیمت فلزات گرانبها /mu', $message['text'])) {

        $pattern = "قیمت (.*?)‏.*?\nقیمت لحظه ای : (.*?) (.*?)\n";
        $matched = preg_match_all(pattern: "/$pattern/um", subject: $message['text'], matches: $matches);
        if ($matched) {

            $asset_type = 'فلزات گرانبها';

            $new_assets['names']/************/ = $matches[1];
            $new_assets['prices']/***********/ = $matches[2];
            $new_assets['base_currencies']/**/ = $matches[3];
        }
    }
    // --- Price Category 2: Gold and Melted Gold (طلا و آبشده) ---
    if (preg_match('/^⭕️ قیمت طلا و آبشده /mu', $message['text'])) {

        $pattern = " قیمت(.*?) ?\s\.*?\s+قیمت لحظه ای : (.*?) (.*?)\s";
        $matched = preg_match_all(pattern: "/$pattern/um", subject: $message['text'], matches: $matches);
        if ($matched) {

            $asset_type = 'طلا و آبشده';

            $new_assets['names']/************/ = $matches[1];
            $new_assets['prices']/***********/ = $matches[2];
            $new_assets['base_currencies']/**/ = $matches[3];
        }
    }
    // --- Price Category 3: Free Currencies (ارزهای آزاد) ---
    if (preg_match('/^⭕️ قیمت ارزهای آزاد \|/mu', $message['text'])) {

        $pattern = "^(.*?)[  ](.*?) :[  ](.*?) (.*)$";
        $matched = preg_match_all(pattern: "/$pattern/um", subject: $message['text'], matches: $matches);
        if ($matched) {

            $asset_type = 'ارزهای آزاد';

            $new_assets['emojis']/***********/ = $matches[1];
            $new_assets['names']/************/ = $matches[2];
            $new_assets['prices']/***********/ = $matches[3];
            $new_assets['base_currencies']/**/ = $matches[4];
        }
    }
    // --- Price Category 4: Coins (سکه) ---
    if (preg_match('/^⭕️ قیمت سکه \|/mu', $message['text'])) {

        // First match to find the correct block of "Coin" prices.
        $pattern = "⭕️ قیمت دیگر سکه ها \.*\s(.*?)#";
        $matched = preg_match_all(pattern: "/$pattern/usm", subject: $message['text'], matches: $price_block_matches);
        if ($matched) {

            // Extract Coin prices like other categories
            $pattern = "(.*?) : (.*?) (.*?)\s";
            $matched = preg_match_all(pattern: "/$pattern/um", subject: $price_block_matches[1][0], matches: $matches);
            if ($matched) {

                $asset_type = 'سکه';

                $new_assets['names']/************/ = $matches[1];
                $new_assets['prices']/***********/ = $matches[2];
                $new_assets['base_currencies']/**/ = $matches[3];
            }
        }
    }
    // --- Price Category 5: Cryptocurrencies (ارزهای دیجیتال) ---
    if (preg_match('/^⭕️ گزارش قیمت ارزهای دیجیتال/mu', $message['text'])) {

        $pattern = "◽️ (.*?) : (.*?) (.*?)\R\R";
        $matched = preg_match_all(pattern: "/$pattern/um", subject: $message['text'], matches: $matches);
        if ($matched) {

            $asset_type = 'ارزهای دیجیتال';

            $new_assets['names']/************/ = $matches[1];
            $new_assets['prices']/***********/ = $matches[2];
            $new_assets['base_currencies']/**/ = $matches[3];
        }
    }

    if ($asset_type && isset($new_assets)) {
        // Successful extraction: Save to database and respond.
        try {
            addPriceToDatabase($new_assets, $asset_type, $date, $time);
        } catch (Exception $e) {
            error_log($e->getMessage());
        }
        error_log("Received message for (($asset_type))");
    }
    exit();
}


/**
 * Processes the extracted price data and saves it to the 'assets' and 'prices' tables.
 *
 * @param array $new_assets Object of arrays of new assets' information,
 * expected to have `names`, `prices`, `base_currencies` and `emojis` (Optional) keys.
 * @param string $asset_type The category of the assets (e.g., 'فلزات گرانبها').
 * @param string $date The extracted date in 'YYYY-MM-DD' format.
 * @param string $time The extracted time in 'HH:MM' format.
 * @return void
 * @throws Exception
 */
#[NoReturn]
function addPriceToDatabase(array $new_assets, string $asset_type, string $date, string $time): void
{
    $db = DatabaseManager::getInstance(
        host: getenv('DB_HOST'),
        db: getenv('DB_NAME'),
        user: getenv('DB_USER'),
        pass: getenv('DB_PASS'),
        port: getenv('DB_PORT') ?: '3306',
    );

    $assets = [];
    foreach ($new_assets['names'] as $i => $name) {

        $assets[] = [
            'name' => trim($name),
            'emoji' => (isset($new_assets['emojis']) && $new_assets['emojis']) ? trim($new_assets['emojis'][$i]) : null,
            'asset_type' => $asset_type,
            'price' => floatval(str_replace(",", "", $new_assets['prices'][$i])),
            'base_currency' => trim($new_assets['base_currencies'][$i]),
            'date' => preg_match("/^\d\d\d\d-\d\d-\d$/m", $date) ? substr_replace($date, "0", 8, 0) : $date,
            'time' => $time,
        ];

        // Change Tether information to match dollar
        if ($name == 'تتر') {
            $dollar = $db->read('assets', ['name' => 'دلار'], true);
            $assets[$i]['price'] = $dollar['price'];
            $assets[$i]['base_currency'] = $dollar['base_currency'];
            $assets[$i]['date'] = $dollar['date'];
            $assets[$i]['time'] = $dollar['time'];
        }
    }

    sendPriceAlerts($assets, $db);

    $db->upsertBatch('assets', $assets);

    updateLiveMessages($assets, $db);
}

/**
 * Finds related, active alerts.
 * Returns an array of alerts, each with these keys:
 *     id, user_id, asset_name, target_price, trigger_type,
 *     asset_type, current_price, base_currency, new_price
 */
function getRelatedAlerts(array $assets, DatabaseManager $db): array|bool
{
    $asset_names = array_column($assets, 'name');

    $alerts = $db->query("
        select
            alerts.id,
            alerts.user_id,
            alerts.asset_name,
            alerts.target_price,
            alerts.trigger_type,
            alerts.is_active,
            assets.asset_type,
            assets.price as current_price,
            assets.base_currency
        from alerts
            join assets on alerts.asset_name=assets.name
        where
            alerts.asset_name in ('" . implode("','", $asset_names) . "') and
            alerts.is_active is true
    ;")->fetchAll();

    return filterTriggeredAlerts($alerts, $assets);
}

/**
 * Removes not-triggered alert based on old and new prices
 * and adds 'new_price' key-value to each alert.
 */
function filterTriggeredAlerts(array $alerts, array $assets): array|bool
{
    $asset_names = array_column($assets, 'name');

    if ($alerts) foreach ($alerts as $i => &$alert) {

        $asset_index = array_search($alert['asset_name'], $asset_names);
        if ($asset_index !== false) {

            $new_price = $assets[$asset_index]['price'];
            $old_price = $alert['current_price'];

            // Unset alert if target price is not between new and old prices
            if (max($old_price, $new_price) <= $alert['target_price'] ||
                min($old_price, $new_price) >= $alert['target_price']) {
                unset($alerts[$i]);
                continue;
            }

            $alert['new_price'] = $new_price;
        }
    }
    return $alerts;
}

/**
 * Finds triggered alerts and sends notifications to users
 */
function sendPriceAlerts(array $assets, DatabaseManager $db): void
{
    if ($assets) {

        $alerts = getRelatedAlerts($assets, $db);
        foreach ($alerts as $alert) {
            $data = [
                'chat_id' => $alert['user_id'],
                'text' => 'هشدار قیمت برای «' . beautifulNumber($alert['asset_name'], null) . '» فعال شد.' .
                    "\n" . 'قیمت هشدار: ' . beautifulNumber($alert['target_price']) .
                    "\n" . 'قیمت کنونی: ' . beautifulNumber($alert['new_price']),
            ];
            sendToTelegram('sendMessage', $data);
        }
    }
}

#[NoReturn]
function updateLiveMessages(array $new_assets, DatabaseManager $db): void
{
    $users = getUsersWithLiveMessage($new_assets, $db);
    if ($users) foreach ($users as &$user) {
        if (!$user['fav_assets']) continue;
        $user['fav_assets'] = json_decode($user['fav_assets'], true);
        sendFavorites(User::fromDbRow($user), $db, $user['live_message_id']);
        /*
         * TODO: With telegram, test with deleted message and deleted user
         * On deleted message, inactivate or delete the live message.
         * On deleted chat, remove the user from the database completely.
         */

    }
}

/** Finds users with both these conditions:
 *     - At least one of the asset names in their favorites.
 *     - Active live favorite message.
 */
function getUsersWithLiveMessage(array $assets, DatabaseManager $db): array
{
    $asset_names = array_column($assets, 'name');

    return $db->query("
        select
            u.*,
            sp.message_id as live_message_id,
            concat('[',
                group_concat(distinct json_object(
                    'fav_id', f2.id,
                    'id', a.id,
                    'name', a.name,
                    'emoji', a.emoji,
                    'asset_type', a.asset_type,
                    'price', a.price,
                    'base_currency', a.base_currency,
                    'date', a.date,
                    'time', a.time
                )),
            ']') as fav_assets
        from users u
            join favorites f1 on u.id = f1.user_id
            join favorites f2 on u.id = f2.user_id
            join special_messages sp on u.id = sp.user_id
            join assets a on f2.asset_name = a.name
        where sp.is_active = 1 and f1.asset_name in ('" . implode("','", $asset_names) . "')
        group by f2.user_id, sp.message_id;
        ")->fetchAll();
}