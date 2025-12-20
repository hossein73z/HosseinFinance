<?php

/**
 * Sends a request to the Telegram Bot API.
 * @param string $method The Telegram API method (e.g., 'sendMessage').
 * @param array $data The method parameters.
 * @param string $token The bot token to use.
 * @return bool|array The Telegram API response on success, or false on failure.
 */
function sendToTelegram(string $method, array $data = [], string $token = ''): bool|array
{
    // Allow token to be passed, or fallback to constant/env
    if (empty($token)) {
        $token = defined('MAIN_BOT_TOKEN') ? MAIN_BOT_TOKEN : getenv('MAIN_BOT_TOKEN');
    }

    $url = "https://api.telegram.org/bot" . $token . "/$method";

    // Handle Proxy Settings (Optional on Vercel as it is usually not blocked)
    $proxy = defined('PROXY_SETTINGS') ? PROXY_SETTINGS : null;

    //$response = stream_request($url, "POST", $data, proxy: $proxy);
    $response = stream_request($url, "POST", $data);
    $result = json_decode($response, true);

    // 1. JSON Parsing Error
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Vercel Log: Appears in "Runtime Logs"
        error_log("Telegram JSON Error: " . json_last_error_msg());
        error_log("Raw Response: " . $response);
        return false;
    }

    // 2. Telegram API Error
    if (isset($result['ok']) && $result['ok'] === true) {
        return $result;
    } else {
        $errorCode = $result['error_code'] ?? 'N/A';
        $description = $result['description'] ?? 'No description provided';

        // Vercel Log: Appears in "Runtime Logs"
        error_log("Telegram API Error [$errorCode]: $description");
        error_log("Sent request: " . json_encode($data, JSON_PRETTY_PRINT));
        return false;
    }
}

/**
 * Performs an HTTP request using PHP stream contexts, supporting proxy and custom headers.
 *
 * @param string $url The URL to send the request to.
 * @param string $method The HTTP method (e.g., 'GET', 'POST').
 * @param array|string|null $data The request body data.
 * @param string|null $proxy Optional proxy string.
 * @param array $headers Associative array of custom headers.
 * @param int $timeout Request timeout in seconds.
 * @return string|false The response body content or an error string/false on failure.
 */
function stream_request(string       $url,
                        string       $method = 'GET',
                        array|string $data = null,
                        ?string      $proxy = null,
                        array        $headers = [],
                        int          $timeout = 10): string|false
{
    $method = strtoupper($method);
    $content = '';
    $additional_headers = '';

    if (!empty($data)) {
        if (is_array($data)) {
            $content = json_encode($data);
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/json';
            }
        } else {
            $content = $data;
        }
    }

    foreach ($headers as $name => $value) {
        $additional_headers .= "$name: $value\r\n";
    }

    if ($content && !isset($headers['Content-Length'])) {
        $additional_headers .= 'Content-Length: ' . strlen($content) . "\r\n";
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => $additional_headers,
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
    ];

    if ($proxy) {
        $options['http']['proxy'] = $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    return $result === false ? false : $result;
}