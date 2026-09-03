<?php

require_once __DIR__ . '/bootstrap.php';

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

// Database was initialized in bootstrap.php; resolve via singleton explicitly.
try {
    $db = DatabaseManager::getInstance();
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
//          LEVEL S3: EMPTY LEVEL
// ==========================================

function empty_level(
    User            $user,
    DatabaseManager $db,
    string|int      $parent_btn_id = 0, // Required to avoid `null` progress bug
    ?array          $message = null,
): void
{
    $progress = $user->getProgress();

    if (!$progress) backButton($user, $db, $parent_btn_id);

    // NOTE: Text and keyboard must be initialized within progress handler
    $text = '';
    $keyboard = [];
    $data = [
        'chat_id' => $user->getid(),
        'text' => &$text,
        'reply_markup' => [
            'keyboard' => [&$keyboard],
            'resize_keyboard' => true,
            'is_persistent' => false
        ]
    ];

    $parent_level = $progress['parent_btn'];
    $progress_data = $progress['data'];

    ##########################
    #### Progress Handler ####
    ##########################

    # --- Set alert price ---
    if (
        array_key_first($progress_data) == 'new_alert_price' ||
        array_key_first($progress_data) == 'edit_alert_price' ||
        array_key_first($progress_data) == 'new_asset_alert'
    ) {

        // Check if Received text is cancel button
        if ($message) {
            $pressed_button = $db->read('buttons', ['id' => 's1', 'attrs->>"$.text"' => $message['text']]);
            if ($pressed_button) cancelButton($user, $db, $parent_level);
        }

        // Create bottom keyboard with just cancel button
        $button = $db->read('buttons', ['id' => ['s1']], true);
        $keyboard[] = json_decode($button['attrs'], true);

        // Read asset from database
        if (array_key_first($progress_data) == 'new_alert_price') {
            $asset_id = $progress_data['new_alert_price']['asset_id'];
            $asset = $db->read('assets', ['id' => $asset_id], true);
        } elseif (array_key_first($progress_data) == 'edit_alert_price') {
            $alert_id = $progress_data['edit_alert_price']['alert_id'];
            $asset = $db->query("
                SELECT assets.*
                FROM assets JOIN alerts ON alerts.asset_name = assets.name
                WHERE alerts.user_id = '{$user->getId()}' AND alerts.id = '$alert_id'")->fetch();
        } else {
            $asset_id = $progress_data['new_asset_alert']['asset_id'];
            $asset = $db->read('assets', ['id' => $asset_id], true);
        }

        // Just entered the level
        // Ask user to give alert's target price
        if (!$message) {

            $text = 'قیمتی که می‌خواهید برای آن هشدار تنظیم کنید را نوشته و ارسال کتید.';
            $text .= "\n";
            $text .= '*قیمت کنونی «' . beautifulNumber($asset['name'], null) . '»*: ';
            $text .= beautifulNumber($asset['price']) . ' ' . beautifulNumber($asset['base_currency'], null);
            $text = markdownScape($text);

            $data['parse_mode'] = 'MarkdownV2';

            // Update user last button to current level (s3)
            $db->update('users', $user->setLastBtn('s3')->toDbArray(), ['id' => $user->getId()]);
        }

        // Received message (Supposed to be alert's target price)
        if ($message) {

            // Check if received text is a valid number
            $target_price = cleanAndValidateNumber($message['text']);
            if ($target_price) {

                // Read asset from database for price comparison
                $price_diff = $target_price - (float)$asset['price'];
                $diff_percent = intval(($price_diff / floatval($asset['price'])) * 100);

                // Check if received price different from current price
                if ($price_diff != 0) {

                    $new_alert = [
                        'user_id' => $user->getId(),
                        'asset_name' => $asset['name'],
                        'target_price' => $target_price,
                        'status' => 'active',
                        'created_date' => JalaliDate::fromGregorian()->format(),
                        'created_time' => date('H:i')
                    ];
                    if (isset($alert_id)) $new_alert['id'] = $alert_id;

                    $result = $db->upsert('alerts', $new_alert);
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
                    cancelButton($user, $db, $parent_level);
                } else // Send warning: Received number is the same as current price
                    $text = "قیمت هشدار نمی‌تواند با قیمت کنونی برابر باشد." . "\n" .
                        "قیمت دیگری بنویسید یا در صورت انصراف از دکمه لغو استفاده کنید.";
            } else // Send warning: Received text does not contain a valid number
                $text = "پیام نامفهوم بود." . "\n" .
                    "قیمت را به عدد بنویسید یا در صورت انصراف از دکمه لغو استفاده کنید.";
        }

        // Send default progress related text and bottom keyboard
        // NOTE: Entering level or Wrong number format reaches here
        sendToTelegram('sendMessage', $data);
        exit;
    }
    exit;
}
