<?php
require_once __DIR__ . '/../Libraries/DatabaseManager.php';
require_once __DIR__ . '/../Functions/ExternalEndpointsFunctions.php';
require_once __DIR__ . '/../Functions/StringHelper.php';

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

            // Check if the expected number of items (4) was extracted.
            if (sizeof($new_assets['names']) != 4) {
                // Log and send a warning to the chat if the count is unexpected.
                error_log("MetaItems: " . json_encode($new_assets));
                sendToTelegram('sendMessage', [
                    'chat_id' => $message['chat']['id'],
                    'reply_to_message_id' => $message['message_id'],
                    'text' => 'Warning: Expected 4 precious metal prices, got ' . sizeof($new_assets[1])
                ], PRICE_BOT_TOKEN);
            }
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

            // Check if the expected number of items (6) was extracted.
            if (sizeof($new_assets['names']) != 6) {
                // Log and send a warning.
                error_log("GoldItems:" . json_encode($new_assets));
                sendToTelegram('sendMessage', [
                    'chat_id' => $message['chat']['id'],
                    'reply_to_message_id' => $message['message_id'],
                    'text' => 'Warning: Expected 6 gold prices, got ' . sizeof($new_assets[1])
                ], PRICE_BOT_TOKEN);
            }
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

            // Check if the expected number of items (39) was extracted.
            if (sizeof($new_assets['names']) != 39) {
                // Log and send a warning.
                error_log("CurrencyItems: " . json_encode($new_assets));
                sendToTelegram('sendMessage', [
                    'chat_id' => $message['chat']['id'],
                    'reply_to_message_id' => $message['message_id'],
                    'text' => 'Warning: Expected 39 currency prices, got ' . sizeof($new_assets[2])
                ], PRICE_BOT_TOKEN);
            }
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

                // Check if the expected number of items (13) was extracted.
                if (sizeof($new_assets['names']) != 13) {
                    // Log and send a warning.
                    error_log("CoinItems: " . json_encode($new_assets));
                    sendToTelegram('sendMessage', [
                        'chat_id' => $message['chat']['id'],
                        'reply_to_message_id' => $message['message_id'],
                        'text' => 'Warning: Expected 13 coin prices, got ' . sizeof($new_assets[1])
                    ], PRICE_BOT_TOKEN);
                }
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

            // Check if the expected number of items (36) was extracted.
            if (sizeof($new_assets['names']) != 36) {
                // Log and send a warning to the chat if the count is unexpected.
                error_log("MetaItems: " . json_encode($new_assets));
                sendToTelegram('sendMessage', [
                    'chat_id' => $message['chat']['id'],
                    'reply_to_message_id' => $message['message_id'],
                    'text' => 'Warning: Expected 36 cryptocurrencies prices, got ' . sizeof($new_assets[1])
                ], PRICE_BOT_TOKEN);
            }

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
    foreach ($new_assets['names'] as $index => $name) {
        $asset_price = str_replace(",", "", $new_assets['prices'][$index]);
        $assets[] = [
            'name' => trim($name),
            'emoji' => (isset($new_assets['emojis']) && $new_assets['emojis']) ? trim($new_assets['emojis'][$index]) : null,
            'asset_type' => $asset_type,
            'price' => floatval($asset_price),
            'base_currency' => trim($new_assets['base_currencies'][$index]),
            'date' => preg_match("/^\d\d\d\d-\d\d-\d$/m", $date) ? substr_replace($date, "0", 8, 0) : $date,
            'time' => $time,
        ];

        // Change Tether information to match dollar
        if ($name == 'تتر') {
            $dollar = $db->read('assets', ['name' => 'دلار'], true);
            $assets[$index]['price'] = $dollar['price'];
            $assets[$index]['base_currency'] = $dollar['base_currency'];
            $assets[$index]['date'] = $dollar['date'];
            $assets[$index]['time'] = $dollar['time'];
        }

        //Check for and send alerts
        $alerts = $db->read(
            table: 'alerts',
            conditions: ['asset_name' => trim($name), 'is_active' => true],
            selectColumns: 'alerts.*, assets.price',
            join: 'JOIN assets ON assets.name = alerts.asset_name'
        );
        foreach ($alerts as $alert) {
            error_log("Alert Price: " . $alert['price']);
            if ($alert['trigger_type'] == 'up' &&
                floatval($asset_price) <= floatval($alert['target_price'])) continue;
            elseif ($alert['trigger_type'] == 'down' &&
                floatval($asset_price) >= floatval($alert['target_price'])) continue;
            elseif ($alert['trigger_type'] == 'both') {
                if (floatval($alert['target_price']) > max(floatval($asset_price), floatval($alert['price'])) ||
                    floatval($alert['target_price']) < min(floatval($asset_price), floatval($alert['price']))) continue;
            }

            $user = $db->read('users', ['id' => $alert['user_id']], true);

            $alert_icon = '';
            if ($alert['trigger_type'] == 'up') $alert_icon = '⏫ ';
            if ($alert['trigger_type'] == 'down') $alert_icon = '⏬ ';
            if ($alert['trigger_type'] == 'both') $alert_icon = '↕️ ';
            $response = sendToTelegram('sendMessage', [
                'chat_id' => $user['chat_id'],
                'text' =>
                    "هشدار قیمت برای " . "«" . beautifulNumber(trim($name), null) . "»" . " فعال شد." . "\n" .
                    "قیمت هشدار: " . $alert_icon . beautifulNumber($alert['target_price']) . "\n" .
                    "قیمت کنونی: " . beautifulNumber(floatval($asset_price))
            ]);
            if ($response) $db->update('alerts', ['is_active' => false], ['id' => $alert['id']]);

        }
    }
    // 1. Insert/Update assets (upsert ensures the asset exists in the table).
    $db->upsertBatch('assets', $assets);


    // Update live messages
    $live_mssgs = $db->read(
        table: 'special_messages sm',
        conditions: ['sm.type' => 'live_price'],
        selectColumns: 'sm.*, p.chat_id',
        join: 'JOIN users p ON p.id = sm.user_id'
    );

    if ($live_mssgs) foreach ($live_mssgs as $live_mssg) {

        if ($live_mssg['is_active']) {
            $favorites = $db->read(
                table: 'favorites f',
                conditions: ['user_id' => $live_mssg['user_id']],
                selectColumns: 'a.*',
                join: 'JOIN assets a ON a.name=f.asset_name',
                orderBy: ['asset_type' => 'DESC', 'id' => 'ASC']);

            if ($favorites) {
                $data['chat_id'] = $live_mssg['chat_id'];
                $data['message_id'] = $live_mssg['message_id'];
                $data['text'] = 'createFavoritesText($favorites)'; // TODO: Fix
                $data['reply_markup'] = ['inline_keyboard' => [
                    [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]],
                    [['text' => 'هشدار قیمت', 'callback_data' => json_encode(['price_alert' => null])]],
                    [['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]],
                ]];

                sendToTelegram('editMessageText', $data);
            }
        }
    }

}