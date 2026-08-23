<?php

// Load necessary files
require_once 'config/config.php';

require_once 'Core/Router.php';
require_once 'Core/WebhookSecurity.php';
require_once 'Core/Navigation.php';

require_once 'Libraries/DatabaseManager.php';

require_once 'Functions/ExternalEndpointsFunctions.php';
require_once 'Functions/KeyboardFunctions.php';
require_once 'Functions/MessageFunctions.php';
require_once 'Functions/StringHelper.php';

require_once 'Models/Button.php';
require_once 'Models/User.php';
require_once 'Models/JalaliDate.php';

// --- INITIALIZATION & SHUTDOWN ---

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR])) {
        error_log("CRITICAL SCRIPT CRASH: Type: {$error['type']} | Message: {$error['message']} | File: {$error['file']} | Line: {$error['line']}");
    }
});

// Initialize Database connection.
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
