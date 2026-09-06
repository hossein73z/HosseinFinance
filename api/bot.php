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
