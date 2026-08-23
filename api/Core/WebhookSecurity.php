<?php

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
