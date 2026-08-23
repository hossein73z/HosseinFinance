<?php

// ----- LOAD NECESSARY FILES ------ //

// Configuration
require_once __DIR__ . '/config/config.php';

// Core
require_once __DIR__ . '/Core/Router.php';
require_once __DIR__ . '/Core/WebhookSecurity.php';
require_once __DIR__ . '/Core/Navigation.php';

// Libraries
require_once __DIR__ . '/Libraries/DatabaseManager.php';

// Handlers
require_once __DIR__ . '/Handlers/MainMenuHandler.php';
require_once __DIR__ . '/Handlers/HoldingsHandler.php';
require_once __DIR__ . '/Handlers/LoansHandler.php';
require_once __DIR__ . '/Handlers/PricesHandler.php';
require_once __DIR__ . '/Handlers/AIHandler.php';
require_once __DIR__ . '/Handlers/AlertsHandler.php';
require_once __DIR__ . '/Handlers/AccountsHandler.php';
require_once __DIR__ . '/Handlers/TransactionsHandler.php';
require_once __DIR__ . '/Handlers/SettingsHandler.php';

// Functions
require_once __DIR__ . '/Functions/ExternalEndpointsFunctions.php';
require_once __DIR__ . '/Functions/KeyboardFunctions.php';
require_once __DIR__ . '/Functions/MessageFunctions.php';
require_once __DIR__ . '/Functions/StringHelper.php';
require_once __DIR__ . '/Functions/AdminFunctions.php';

// Helpers
require_once __DIR__ . '/Helpers/HoldingsHelper.php';
require_once __DIR__ . '/Helpers/LoansHelper.php';
require_once __DIR__ . '/Helpers/PricesHelper.php';
require_once __DIR__ . '/Helpers/TransactionsHelper.php';
require_once __DIR__ . '/Helpers/TelegramUIHelper.php';

// Models
require_once __DIR__ . '/Models/Button.php';
require_once __DIR__ . '/Models/User.php';
require_once __DIR__ . '/Models/JalaliDate.php';

// --- SHUTDOWN AND INITIALIZATION --- //

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR])) {
        error_log("CRITICAL SCRIPT CRASH: Type: {$error['type']} | Message: {$error['message']} | File: {$error['file']} | Line: {$error['line']}");
    }
});

// Initialize Database connection (singleton).
// Callers should obtain it via DatabaseManager::getInstance() after bootstrap.
try {
    $db = DatabaseManager::getInstance(
        host: DB_HOST,
        db: DB_NAME,
        user: DB_USER,
        pass: DB_PASS,
        port: DB_PORT ?: '3306'
    );
    $db->query("SET SESSION group_concat_max_len = 10000000;");
} catch (Exception $e) {
    error_log($e->getMessage());
    exit;
}
