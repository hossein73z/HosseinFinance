<?php

/**
 * Generates a QuickChart URL from a Chart.js configuration array and optional visual parameters.
 *
 * This function allows for full manipulation and adjustment of the chart by accepting
 * the entire Chart.js configuration as a PHP associative array, which is then
 * securely serialized to JSON. This gives you direct access to all Chart.js/QuickChart
 * capabilities (chart type, data, options, colors, etc.).
 *
 * @param array $chartConfig The full Chart.js configuration array (e.g., ['type' => 'bar', 'data' => [...], 'options' => [...]]).
 * @param int $width The width of the chart image in pixels (default: 500).
 * @param int $height The height of the chart image in pixels (default: 300).
 * @param float $devicePixelRatio The scaling factor for the chart image (default: 2.0 for higher quality).
 * @param string $backgroundColor The background color (e.g., 'transparent', '#ffffff', 'red').
 * @return string The complete URL to the generated chart image.
 * @throws Exception If the provided chart configuration is not a valid array or is empty.
 */
function generateQuickChartUrl(
    array  $chartConfig,
    int    $width = 500,
    int    $height = 300,
    float  $devicePixelRatio = 2.0,
    string $backgroundColor = 'transparent'
): string
{
    if (empty($chartConfig)) {
        throw new Exception("Chart configuration array cannot be empty.");
    }

    // Initialize the QuickChart object
    $chart = new QuickChart();
    $chart->setWidth($width);
    $chart->setHeight($height);
    $chart->setDevicePixelRatio($devicePixelRatio);
    $chart->setBackgroundColor($backgroundColor);
    $chart->setConfig($chartConfig);

    return $chart->getUrl();
}

/**
 * A factory function to generate a standard Chart.js configuration array for use
 * with generateQuickChartUrl().
 *
 * @param string $type The chart type ('line', 'bar', 'pie', 'doughnut', etc.).
 * @param array $labels An array of labels for the x-axis (e.g., ['Jan', 'Feb', 'Mar']).
 * @param array $datasets An array of dataset arrays. Each must contain 'label' (string) and 'data' (array of numbers).
 * @param string $title An optional title for the chart.
 * @param bool $yBeginAtZero An optional boolean for the Y-axis. If set to true, the axis will start at 0.
 * @return array The complete Chart.js configuration array.
 */
function buildQuickChartConfig(
    string $type,
    array  $labels,
    array  $datasets,
    string $title = '',
    bool   $yBeginAtZero = false
): array
{
    $options = [
        'responsive' => true,
        'plugins' => [
            'legend' => ['position' => 'top'],
            'title' => ['display' => !empty($title), 'text' => $title],
        ],
    ];

    $scales['yAxis'] = ['ticks' => ['beginAtZero' => $yBeginAtZero]];

    if (!empty($scales)) {
        $options['scales'] = $scales;
    }

    return [
        'type' => $type,
        'data' => ['labels' => $labels, 'datasets' => $datasets],
        'options' => $options,
    ];
}

/**
 * Fetches date and time information from the MajidAPI service.
 * Requires the global constant MAJID_API_TOKEN to be defined.
 *
 * @return array|false The decoded JSON response array, or false on error.
 */
function majidAPI_date_time(): array|false
{
    // On Vercel, it is best practice to use getenv() or $_ENV
    $token = defined('MAJID_API_TOKEN') ? MAJID_API_TOKEN : getenv('MAJID_API_TOKEN');

    if (!$token) {
        error_log('MAJID_API_TOKEN is not defined in Environment Variables.');
        return false;
    }

    $url = 'https://api.majidapi.ir/tools/datetime?token=' . $token;
    return json_decode(stream_request($url), true);
}

function majidAPI_ai($query, string $model = 'copilot'): array|false|null
{
    $token = defined('MAJID_API_TOKEN') ? MAJID_API_TOKEN : getenv('MAJID_API_TOKEN');

    if (!$token) {
        error_log('MAJID_API_TOKEN is not defined in Environment Variables.');
        return false;
    }

    $models = [
        'deepseek' => 'ai/deepseek',
        'copilot' => 'ai/copilot',
        'gpt3' => 'gpt/3',
        'gpt3.5t' => 'gpt/35',
    ];

    // Safety check for invalid model
    $endpoint = $models[$model] ?? $models['copilot'];

    $url = 'https://api.majidapi.ir/' . $endpoint . '?token=' . $token . '&q=' . urlencode($query);
    return json_decode(stream_request($url), true);
}

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