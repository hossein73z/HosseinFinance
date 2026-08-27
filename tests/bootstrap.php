<?php

// --- Models ---
require_once __DIR__ . '/../api/Models/JalaliDate.php';
require_once __DIR__ . '/../api/Models/User.php';
require_once __DIR__ . '/../api/Models/Button.php';

// --- Library ---
require_once __DIR__ . '/../api/Libraries/DatabaseManager.php';

// --- Helpers ---
require_once __DIR__ . '/../api/Helpers/TransactionsHelper.php';
require_once __DIR__ . '/../api/Helpers/HoldingsHelper.php';
require_once __DIR__ . '/../api/Helpers/TelegramUIHelper.php';
require_once __DIR__ . '/../api/Helpers/PricesHelper.php';
require_once __DIR__ . '/../api/Helpers/LoansHelper.php';
require_once __DIR__ . '/../api/Helpers/FavoritesHelper.php';

// --- Functions ---
require_once __DIR__ . '/../api/Functions/StringHelper.php';
require_once __DIR__ . '/../api/Functions/KeyboardFunctions.php';
require_once __DIR__ . '/../api/Functions/MessageFunctions.php';
require_once __DIR__ . '/../api/Functions/ExternalEndpointsFunctions.php';

// --- Constants for unit tests ---
if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('BASE_URL') ?: 'https://example.com');
}
if (!defined('DB_API_SECRET')) {
    define('DB_API_SECRET', getenv('DB_API_SECRET') ?: 'test-secret');
}
if (!defined('BOT_ID')) {
    define('BOT_ID', getenv('BOT_ID') ?: 'TestBot');
}
if (!defined('SHARED_SECRET')) {
    define('SHARED_SECRET', getenv('SHARED_SECRET') ?: 'test-shared-secret');
}

// Load local env for CLI / PHPUnit
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\"'");
        // Do not override variables already set in the real environment
        if (getenv($name) === false) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// --- DB constants for integration tests (from environment) ---
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'test');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
}
if (!defined('TEST_CHAT_ID')) {
    define('TEST_CHAT_ID', getenv('TEST_CHAT_ID') !== false ? getenv('TEST_CHAT_ID') : '');
}