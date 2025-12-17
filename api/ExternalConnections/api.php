<?php
/**
 * api.php
 *
 * Remote Database Endpoint for Vercel.
 * Dynamic / Future-proof version.
 */

// -------------------------------------------------------------------------
// CONFIGURATION VIA ENVIRONMENT VARIABLES
// -------------------------------------------------------------------------

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST'));
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME'));
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER'));
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS'));

$apiSecret = getenv('DB_API_SECRET');
$allowedTablesEnv = getenv('DB_ALLOWED_TABLES');
$allowedTables = $allowedTablesEnv ? array_map('trim', explode(',', $allowedTablesEnv)) : [];

// -------------------------------------------------------------------------
// LOAD LIBRARIES
// -------------------------------------------------------------------------

$dbManagerPath = __DIR__ . '/../Libraries/DatabaseManager.php';

if (file_exists($dbManagerPath)) {
    require_once $dbManagerPath;
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server Error: DatabaseManager library not found."]);
    exit;
}

// -------------------------------------------------------------------------
// HEADERS
// -------------------------------------------------------------------------

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-Api-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -------------------------------------------------------------------------
// AUTHENTICATION & INPUT
// -------------------------------------------------------------------------

$requestKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($requestKey !== $apiSecret) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized: Invalid API Key"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON payload"]);
    exit;
}

// Extract generic parameters
$operation = $input['operation'] ?? '';
$table = $input['table'] ?? '';

// Validate Allowlist
if (!in_array($table, $allowedTables)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access to table '$table' is denied."]);
    exit;
}

// -------------------------------------------------------------------------
// DYNAMIC PROCESS EXECUTION
// -------------------------------------------------------------------------

try {
    $db = DatabaseManager::getInstance();

    // 1. Check if the requested function exists in DatabaseManager
    if (!method_exists($db, $operation)) {
        throw new Exception("Invalid operation: '$operation'");
    }

    // 2. Prepare Arguments
    // We take the entire input array as arguments
    $args = $input;

    // Remove keys that are meant for API logic, not Database logic
    unset($args['operation']);
    unset($args['x-api-key']);

    // --- KEY MAPPING (Aliases) ---
    // This bridges the gap between JSON keys and PHP Function Variable names.
    // If your JSON sends "select" but PHP needs "$selectColumns":
    if (isset($args['select'])) {
        $args['selectColumns'] = $args['select'];
        unset($args['select']);
    }

    // 3. Execute Dynamically using Named Arguments
    // PHP 8.0+ allows call_user_func_array to accept an associative array.
    // Keys in $args will act as parameter names (e.g., 'orderBy' => [])
    // This makes the order of arguments irrelevant.
    try {
        $result = call_user_func_array([$db, $operation], $args);
    } catch (ArgumentCountError $e) {
        throw new Exception("Missing required arguments for '$operation'. Details: " . $e->getMessage());
    } catch (TypeError $e) {
        throw new Exception("Invalid argument types for '$operation'. Details: " . $e->getMessage());
    }

    // 4. Handle Result Logic
    // If result is strict FALSE, we assume failure (based on your class logic)
    if ($result === false) {
        throw new Exception("Database operation '$operation' failed.");
    }

    // Generate a generic success message
    $count = is_array($result) ? count($result) : ($result === true ? 1 : $result);
    $msg = "Operation '$operation' successful.";

    echo json_encode([
        "success" => true,
        "message" => $msg,
        "data" => $result
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}