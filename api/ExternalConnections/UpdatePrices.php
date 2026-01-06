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
/*
* This regex attempts to find the standard date/time header pattern from a Telegram message.
* Pattern: | (SPACE) ... (SPACE) (DAY) (MONTH_NAME) (YEAR) - (SPACE) (HH:MM)
* Group 1: Full date part (e.g., 20 خرداد 1402)
* Group 2: Day (d or dd)
* Group 3: Month name (e.g., خرداد)
* Group 4: Year (yyyy)
* Group 5: Time (HH:MM)
*/
if (preg_match_all('/\|[  ].*? ((\d\d?) (.*?) (\d\d\d\d)) -[  ](\d\d:\d\d)/ums', $message['text'], $matches)) {

    $time = $matches[5][0];
    // Reconstruct the date into 'YYYY-MM-DD' format.
    $date =
        $matches[4][0] . "-" . // Year
        str_replace( // Month conversion from Persian month names to numerical months
            ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند', ' '],
            ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '-'],
            $matches[3][0]) . "-" .
        $matches[2][0]; // Day

    $asset_type = null;
    // --- Price Category 1: Precious Metals (فلزات گرانبها) ---
    if (preg_match('/^⭕️ قیمت فلزات گرانبها /mu', $message['text'])) {

        $asset_type = 'فلزات گرانبها';

        /*
        * Find "Ounces" prices using a pattern to match the asset name, price, and currency.
        * Pattern: قیمت (ASSET_NAME) ‏... \n قیمت لحظه ای : (PRICE) (CURRENCY)\n
        * Group 1: Asset Name (e.g., اونس طلا)
        * Group 2: Price value
        * Group 3: Base Currency
        */
        $pattern = "قیمت (.*?)‏.*?\nقیمت لحظه ای : (.*?) (.*?)\n";
        $matched = preg_match_all(pattern: "/$pattern/um", subject: $message['text'], matches: $matches);
        if ($matched) {

            // Check if the expected number of items (4) was extracted.
            if (sizeof($matches[2]) != 4) {
                // Log and send a warning to the chat if the count is unexpected.
                error_log("MetaItems: " . json_encode($matches));
                sendToTelegram('sendMessage', [
                    'chat_id' => $message['chat']['id'],
                    'reply_to_message_id' => $message['message_id'],
                    'text' => 'Warning: Expected 4 precious metal prices, got ' . sizeof($matches[2])
                ], PRICE_BOT_TOKEN);
            }
        }
    }
    // --- Price Category 2: Gold and Melted Gold (طلا و آبشده) ---
    if (preg_match('/^⭕️ قیمت طلا و آبشده /mu', $message['text'])) {

        $asset_type = 'طلا و آبشده';

        /*
        * Find "Gold" prices.
        * Pattern:  قیمت(ASSET_NAME) ? \s... \s+ قیمت لحظه ای : (PRICE) (CURRENCY)\s
        * Group 1: Asset Name (e.g., طلای ۱۸ عیار)
        * Group 2: Price value
        * Group 3: Base Currency
        */
        $pattern = " قیمت(.*?) ?\s\.*?\s+قیمت لحظه ای : (.*?) (.*?)\s";
        $matched = preg_match_all(pattern: "/$pattern/um", subject: $message['text'], matches: $matches);
        if ($matched) {

            // Check if the expected number of items (6) was extracted.
            if (sizeof($matches[2]) != 6) {
                // Log and send a warning.
                error_log("GoldItems:" . json_encode($matches));
                sendToTelegram('sendMessage', [
                    'chat_id' => $message['chat']['id'],
                    'reply_to_message_id' => $message['message_id'],
                    'text' => 'Warning: Expected 6 gold prices, got ' . sizeof($matches[2])
                ], PRICE_BOT_TOKEN);
            }
        }
    }
    // --- Price Category 3: Free Currencies (ارزهای آزاد) ---
    if (preg_match('/^⭕️ قیمت ارزهای آزاد \|/mu', $message['text'])) {

        $asset_type = 'ارزهای آزاد';

        /*
        * Find "Currency" prices. The pattern is complex due to various spaces/non-breaking spaces.
        * Pattern: ^(ASSET_PART_1)( | )(ASSET_PART_2) :( | )(PRICE) (CURRENCY)$
        * Group 1: Part 1 of Asset Name
        * Group 3: Part 2 of Asset Name
        * Group 5: Price value
        * Group 6: Base Currency
        */
        $pattern = "^(.*?)[  ](.*?) :[  ](.*?) (.*)$";
        $matched = preg_match_all(pattern: "/$pattern/um", subject: $message['text'], matches: $matches);
        if ($matched) {

            // Re-structure the $matches array to fit the `addPriceToDatabase` function's expected format.

            // Combine the two parts of the asset name (Flag icon and name).
            foreach ($matches[1] as $i => $match) $matches[1][$i] = trim($matches[1][$i]) . " " . trim($matches[2][$i]);

            // Sorting the indexes
            unset($matches[2]);
            $matches = array_values($matches);

            // Check if the expected number of items (39) was extracted.
            if (sizeof($matches[2]) != 39) {
                // Log and send a warning.
                error_log("CurrencyItems: " . json_encode($matches));
                sendToTelegram('sendMessage', [
                    'chat_id' => $message['chat']['id'],
                    'reply_to_message_id' => $message['message_id'],
                    'text' => 'Warning: Expected 39 currency prices, got ' . sizeof($matches[2])
                ], PRICE_BOT_TOKEN);
            }
        }
    }
    // --- Price Category 4: Coins (سکه) ---
    if (preg_match('/^⭕️ قیمت سکه \|/mu', $message['text'])) {

        $asset_type = 'سکه';

        /*
        * First, find the block of "Coin" prices, which is delimited by the '⭕️ قیمت دیگر سکه ها' header and a trailing '#'.
        * Pattern: ⭕️ قیمت دیگر سکه ها ... (PRICE_BLOCK)#
        * Group 1: The entire block of specific coin prices.
        */
        $pattern = "⭕️ قیمت دیگر سکه ها \.*\s(.*?)#";
        $matched = preg_match_all(pattern: "/$pattern/usm", subject: $message['text'], matches: $matches);
        if ($matched) {

            /*
            * Second, extract individual coin prices from the found block (Group 1 of the previous match).
            * Pattern: (ASSET_NAME) : (PRICE) (CURRENCY)\s
            * Group 1: Asset Name
            * Group 2: Price value
            * Group 3: Base Currency
            */
            $pattern = "(.*?) : (.*?) (.*?)\s";
            $matched = preg_match_all(pattern: "/$pattern/um", subject: $matches[1][0], matches: $matches);
            if ($matched) {

                // Check if the expected number of items (13) was extracted.
                if (sizeof($matches[2]) != 13) {
                    // Log and send a warning.
                    error_log("CoinItems: " . json_encode($matches));
                    sendToTelegram('sendMessage', [
                        'chat_id' => $message['chat']['id'],
                        'reply_to_message_id' => $message['message_id'],
                        'text' => 'Warning: Expected 13 coin prices, got ' . sizeof($matches[2])
                    ], PRICE_BOT_TOKEN);
                }
            }
        }
    }
    // --- Price Category 5: Cryptocurrencies (ارزهای دیجیتال) ---
    if (preg_match('/^⭕️ گزارش قیمت ارزهای دیجیتال/mu', $message['text'])) {

        $asset_type = 'ارزهای دیجیتال';
        /*
        * Find "Cryptocurrencies" prices using a pattern to match the asset name, price, and currency.
        * Pattern: ◽️ (ASSET_NAME) : (PRICE) (CURRENCY)\R\R
        * Group 1: Asset Name (e.g., بیت کوین)
        * Group 2: Price value
        * Group 3: Base Currency
        */
        $pattern = "◽️ (.*?) : (.*?) (.*?)\R\R";
        $matched = preg_match_all(pattern: "/$pattern/um", subject: $message['text'], matches: $matches);

        if ($matched) {

            // Check if the expected number of items (36) was extracted.
            if (sizeof($matches[2]) != 36) {
                // Log and send a warning to the chat if the count is unexpected.
                error_log("MetaItems: " . json_encode($matches));
                sendToTelegram('sendMessage', [
                    'chat_id' => $message['chat']['id'],
                    'reply_to_message_id' => $message['message_id'],
                    'text' => 'Warning: Expected 36 cryptocurrencies prices, got ' . sizeof($matches[2])
                ], PRICE_BOT_TOKEN);
            }

        }
    }

    if ($asset_type) {
        // Successful extraction: Save to database and respond.
        try {
            addPriceToDatabase($matches, $asset_type, $date, $time);
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
 * @param array $matches The result of preg_match_all, expected to have keys 1 (asset name),
 * 2 (price value), and 3 (base currency).
 * @param string $asset_type The category of the assets (e.g., 'فلزات گرانبها').
 * @param string $date The extracted date in 'YYYY-MM-DD' format.
 * @param string $time The extracted time in 'HH:MM' format.
 * @return void
 * @throws Exception
 */
function addPriceToDatabase(array $matches, string $asset_type, string $date, string $time): void
{
    $db = DatabaseManager::getInstance(
        host: getenv('DB_HOST'),
        db: getenv('DB_NAME'),
        user: getenv('DB_USER'),
        pass: getenv('DB_PASS'),
        port: getenv('DB_PORT') ?: '3306',
    );
    $assets = [];
    // Format extracted data into a structured array for batch processing.
    foreach ($matches[1] as $index => $match) {
        $asset_price = str_replace(",", "", $matches[2][$index]); // Remove thousands separators
        $assets[] = [
            'name' => trim($match),
            'asset_type' => $asset_type,
            'price' => floatval($asset_price),
            'base_currency' => trim($matches[3][$index]),
            // Fix a potential single-digit day issue in the reconstructed date string.
            'date' => preg_match("/^\d\d\d\d-\d\d-\d$/m", $date) ? substr_replace($date, "0", 8, 0) : $date,
            'time' => $time,
        ];

        // Update all the related exchange rates.
        if (trim($match) == '🇺🇸 دلار') {
            $dollars = $db->read('assets', ['base_currency' => ['دلار', 'تتر']]);
            if ($dollars) {
                foreach ($dollars as $i => $dollar) $dollars[$i]['exchange_rate'] = floatval($asset_price);
                $db->upsertBatch('assets', $dollars);
            }
        }

        //Check for and send alerts
        $alerts = $db->read(
            table: 'alerts',
            conditions: ['asset_name' => trim($match), 'is_active' => true],
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

            $person = $db->read('persons', ['id' => $alert['person_id']], true);

            $alert_icon = '';
            if ($alert['trigger_type'] == 'up') $alert_icon = '⏫ ';
            if ($alert['trigger_type'] == 'down') $alert_icon = '⏬ ';
            if ($alert['trigger_type'] == 'both') $alert_icon = '↕️ ';
            $response = sendToTelegram('sendMessage', [
                'chat_id' => $person['chat_id'],
                'text' =>
                    "هشدار قیمت برای " . "«" . beautifulNumber(trim($match), null) . "»" . " فعال شد." . "\n" .
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
        join: 'JOIN persons p ON p.id = sm.person_id'
    );

    if ($live_mssgs) foreach ($live_mssgs as $live_mssg) {

        $live_mssg_data = json_decode($live_mssg['data'], true);

        if ($live_mssg['is_active']) {
            $favorites = $db->read(
                table: 'favorites f',
                conditions: ['person_id' => $live_mssg['person_id']],
                selectColumns: 'a.*',
                join: 'JOIN assets a ON a.id=f.asset_id',
                orderBy: ['asset_type' => 'DESC', 'id' => 'ASC']);

            $data['chat_id'] = $live_mssg['chat_id'];
            $data['message_id'] = $live_mssg_data['mssg_id'];
            $data['text'] = createFavoritesText($favorites);
            $data['reply_markup'] = ['inline_keyboard' => [
                [['text' => 'توقف نمایش زنده ⏸', 'callback_data' => json_encode(['set_live' => false])]],
                [['text' => 'هشدار قیمت', 'callback_data' => json_encode(['price_alert' => null])]],
                [['text' => 'ویرایش لیست', 'callback_data' => json_encode(['edit_fav' => null])]],
            ]];

            sendToTelegram('editMessageText', $data);
        }
    }

}