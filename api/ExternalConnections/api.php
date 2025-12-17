<?php
/**
 * api.php
 *
 * Remote Database Endpoint.
 * Utilizes DatabaseManager.php and settings from config.php.
 */

// Load Configuration first
require_once '../config.php';

// Load Database Manager
require_once '../Libraries/DatabaseManager.php';

// Standard API Headers
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-Api-Key");

// -------------------------------------------------------------------------
// AUTHENTICATION & INPUT HANDLING
// -------------------------------------------------------------------------

// 1. Validate API Key from Headers
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$apiKey = $headers['x-api-key'] ?? '';

if ($apiKey !== DB_API_SECRET) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized: Invalid API Key"]);
    exit;
}

// 2. Decode JSON Input
$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON payload"]);
    exit;
}

// 3. Extract and Validate Parameters
$operation = $input['operation'] ?? '';
$table = $input['table'] ?? '';

// Check against the Allowlist defined in config.php
if (!in_array($table, DB_ALLOWED_TABLES)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access to table '$table' is denied or table does not exist in allowlist."]);
    exit;
}

// -------------------------------------------------------------------------
// PROCESS OPERATIONS
// -------------------------------------------------------------------------

try {
    // DatabaseManager uses DB_HOST, DB_NAME, etc., from config.php automatically
    $db = DatabaseManager::getInstance();

    $result = null;
    $message = "Operation successful";

    switch ($operation) {

        case 'create':
            if (empty($input['data'])) throw new Exception("Missing 'data' array");
            $result = $db->create($table, $input['data']);
            if (!$result) throw new Exception("Create operation failed");
            $message = "Record created with ID: " . $result;
            break;

        case 'createBatch':
            if (empty($input['dataRows'])) throw new Exception("Missing 'dataRows' array");
            $result = $db->createBatch($table, $input['dataRows']);
            if ($result === false) throw new Exception("Batch create failed");
            $message = "$result rows created";
            break;

        case 'read':
            $conditions = $input['conditions'] ?? [];
            $single = $input['single'] ?? false;
            $select = $input['select'] ?? '*';
            $distinct = $input['distinct'] ?? false;
            $join = $input['join'] ?? '';
            $orderBy = $input['orderBy'] ?? [];
            $limit = $input['limit'] ?? null;
            $offset = $input['offset'] ?? 0;
            $chunkSize = $input['chunkSize'] ?? null;

            $result = $db->read(
                $table, $conditions, $single, $select,
                $distinct, $join, $orderBy, $limit, $offset, $chunkSize
            );

            if ($result === false) $result = [];
            break;

        case 'update':
            if (empty($input['data']) || empty($input['conditions'])) {
                throw new Exception("Missing 'data' or 'conditions'");
            }
            $result = $db->update($table, $input['data'], $input['conditions']);
            if ($result === false) throw new Exception("Update failed");
            $message = "$result rows updated";
            break;

        case 'upsert':
            if (empty($input['data'])) throw new Exception("Missing 'data'");
            $result = $db->upsert($table, $input['data']);
            if ($result === false) throw new Exception("Upsert failed");
            break;

        case 'upsertBatch':
            if (empty($input['dataRows'])) throw new Exception("Missing 'dataRows'");
            $result = $db->upsertBatch($table, $input['dataRows']);
            if ($result === false) throw new Exception("Batch upsert failed");
            break;

        case 'delete':
            if (empty($input['conditions'])) throw new Exception("Missing 'conditions'");
            $resetAI = $input['resetAutoIncrement'] ?? false;
            $result = $db->delete($table, $input['conditions'], $resetAI);
            if ($result === false) throw new Exception("Delete failed");
            $message = "$result rows deleted";
            break;

        default:
            throw new Exception("Invalid operation provided: '$operation'");
    }

    echo json_encode([
        "success" => true,
        "message" => $message,
        "data" => $result
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}