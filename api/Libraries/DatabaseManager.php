<?php

/**
 * DatabaseManager.php
 *
 * A standalone, reusable PHP class for simplified **MySQL CRUD and UPSERT**
 * operations using **PDO** and the **Singleton pattern**.
 *
 * Key Features:
 * - Enforces a single database connection instance (`Singleton`).
 * - Uses prepared statements for all queries to prevent SQL injection.
 * - Supports standard **CREATE, READ, UPDATE, DELETE** operations.
 * - Includes efficient **Batch Create** and **Batch Upsert** methods.
 * - Provides **Upsert (Insert or Update)** functionality for single records.
 * - Simplifies WHERE clause creation, supporting both equality (`=`) and `IN` array conditions.
 * - Normalizes PHP boolean values to MySQL integer (1 or 0).
 * - The `read` method supports joins, ordering, distinct selection, and result chunking.
 *
 * NOTE: Define the constants DB_HOST, DB_NAME, DB_USER, and DB_PASS
 * outside of this class file (e.g., in a configuration file) to avoid
 * hardcoding sensitive credentials.
 */
class DatabaseManager
{
    private PDO $pdo;
    private static ?DatabaseManager $instance = null;

    /**
     * Private constructor to enforce the Singleton pattern.
     * Establishes the PDO database connection upon first instantiation.
     */
    private function __construct()
    {
        // 1. Retrieve credentials from Vercel Environment Variables
        // Note: We use getenv() which works with Vercel's secret injection
        $host = getenv('DB_HOST');
        $port = getenv('DB_PORT') ?: '3306';
        $db = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');

        if (!$host || !$db || !$user) {
            // If running locally without .env loaded or secrets missing in Vercel
            throw new Exception("Database configuration missing. Please check Vercel Environment Variables.");
        }

        // 2. Data Source Name (DSN)
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

        // 3. PDO connection options
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,      // Throw exceptions on error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Default fetch mode is associative array
            PDO::ATTR_EMULATE_PREPARES => false,              // Use real prepared statements
        ];

        // 4. TiDB Cloud / SSL Configuration
        // TiDB Cloud (Port 4000) requires a secure connection.
        if ($port == 4000 || $host !== 'localhost') {

            // Disable strict certificate verification to avoid "caching_sha2_password" or "SSL certificate" errors
            // inside the Vercel serverless environment which might lack specific CA bundles.
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;

            // Force SSL Mode.
            if (defined('PDO::MYSQL_ATTR_SSL_MODE')) {
                $options[PDO::MYSQL_ATTR_SSL_MODE] = PDO::MYSQL_ATTR_SSL_MODE_REQUIRED;
            } else {
                $options[PDO::MYSQL_ATTR_SSL_CA] = ''; // Fallback to trigger SSL negotiation
            }
        }

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Log error internally (Vercel logs) and throw generic error to prevent leaking creds
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed. Check server logs.");
        }
    }

    /**
     * Gets the singleton instance of the DatabaseManager.
     * If no instance exists, it creates one and establishes the connection.
     *
     * @return DatabaseManager The single instance of the class.
     */
    public static function getInstance(): DatabaseManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Prevents cloning of the instance.
     */
    private function __clone()
    {
    }

    /**
     * Prevents unserialization.
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize a singleton.");
    }

    // ------------------------------------------------------------------------
    // --- Connection Management ----------------------------------------------
    // ------------------------------------------------------------------------

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    public static function closeConnection(): void
    {
        self::$instance = null;
    }

    // ------------------------------------------------------------------------
    // --- Internal Utility ---------------------------------------------------
    // ------------------------------------------------------------------------

    private function buildWhere(array $conditions, string $prefix = 'where'): array
    {
        if (empty($conditions)) {
            return ['clause' => '', 'params' => []];
        }

        $whereParts = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            // 1. Determine SQL syntax:
            // If it contains '->' (JSON) or '.' (Table.Column), do not wrap in backticks.
            // Otherwise, wrap in backticks to be safe.
            if (str_contains($column, '->') || str_contains($column, '.')) {
                $columnSql = $column;
            } else {
                $columnSql = "`$column`";
            }

            // 2. Create a safe PDO placeholder name:
            // Remove ANY character that is not A-Z, 0-9, or underscore.
            // This turns 'attrs->>"$.text"' into 'attrstext'
            $sanitizedColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

            if (is_array($value)) {
                if (empty($value)) {
                    $whereParts[] = '1 = 0';
                    continue;
                }
                $placeholders = [];
                foreach ($value as $index => $item) {
                    $placeholder = ":{$prefix}_{$sanitizedColumn}_{$index}";
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = $item;
                }
                $whereParts[] = "$columnSql IN (" . implode(', ', $placeholders) . ")";
            } else {
                $placeholder = ":{$prefix}_$sanitizedColumn";
                $whereParts[] = "$columnSql = $placeholder";
                $params[$placeholder] = $value;
            }
        }

        if (empty($whereParts)) {
            return ['clause' => '', 'params' => []];
        }

        return [
            'clause' => implode(' AND ', $whereParts),
            'params' => $params
        ];
    }

    private function normalizeDataForDb(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (is_bool($value)) {
                $value = (int)$value;
            }
        }
        return $data;
    }


    // ------------------------------------------------------------------------
    // --- CRUD Operations ----------------------------------------------------
    // ------------------------------------------------------------------------

    public function create(string $table, array $data): int|bool
    {
        if (empty($data)) {
            return false;
        }

        $normalizedData = $this->normalizeDataForDb($data);
        $fields = implode(', ', array_keys($normalizedData));
        $placeholders = ':' . implode(', :', array_keys($normalizedData));

        $sql = "INSERT INTO `$table` ($fields) VALUES ($placeholders)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($normalizedData);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("CREATE operation failed for table $table: " . $e->getMessage());
            return false;
        }
    }

    public function createBatch(string $table, array $dataRows): int|bool
    {
        if (empty($dataRows) || empty($dataRows[0])) {
            return false;
        }

        $normalizedDataRows = array_map([$this, 'normalizeDataForDb'], $dataRows);
        $fields = array_keys($normalizedDataRows[0]);
        $fieldNames = implode(', ', $fields);
        $fieldCount = count($fields);
        $placeholderTemplate = '(' . implode(', ', array_fill(0, $fieldCount, '?')) . ')';
        $valueSets = array_fill(0, count($normalizedDataRows), $placeholderTemplate);

        $sql = "INSERT INTO `$table` ($fieldNames) VALUES " . implode(', ', $valueSets);

        $params = [];
        foreach ($normalizedDataRows as $row) {
            $params = array_merge($params, array_values(array_intersect_key($row, array_flip($fields))));
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("CREATE BATCH operation failed for table $table: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @param string|array $join Can be a raw string or an array of join definitions
     * @param string|array $groupBy Field(s) to group by (Required for aggregation/JSON queries)
     */
    public function read(
        string       $table,
        array        $conditions = [],
        bool         $single = false,
        string       $selectColumns = '*',
        bool         $distinct = false,
        string|array $join = [],
        string|array $groupBy = [],
        array        $orderBy = [],
        ?int         $limit = null,
        int          $offset = 0,
        ?int         $chunkSize = null
    ): array|bool
    {
        $where = $this->buildWhere($conditions);
        $distinctClause = $distinct ? 'DISTINCT ' : '';

        // --- 1. Handle Table Name & Alias ---
        $tableClause = (!str_contains($table, ' ')) ? "`$table`" : $table;

        // --- 2. Handle Joins ---
        $joinClause = '';
        if (is_array($join) && !empty($join)) {
            foreach ($join as $j) {
                $type = isset($j['type']) ? strtoupper($j['type']) : 'LEFT';
                $joinTable = $j['table'];
                $onCondition = $j['on'];
                $joinClause .= " $type JOIN $joinTable ON $onCondition";
            }
        } elseif (is_string($join)) {
            $joinClause = " $join";
        }

        // --- 3. Handle Group By (NEW) ---
        // SQL Order: WHERE -> GROUP BY -> HAVING -> ORDER BY
        $groupByClause = '';
        if (!empty($groupBy)) {
            $groupStr = is_array($groupBy) ? implode(', ', $groupBy) : $groupBy;
            $groupByClause = " GROUP BY $groupStr";
        }

        // --- 4. Handle Ordering ---
        $orderParts = [];
        if (!empty($orderBy)) {
            foreach ($orderBy as $column => $direction) {
                $sanitizedDirection = (strtoupper($direction) === 'DESC') ? 'DESC' : 'ASC';
                $orderParts[] = "$column " . $sanitizedDirection;
            }
        }
        $orderByClause = !empty($orderParts) ? " ORDER BY " . implode(', ', $orderParts) : "";

        // --- Construct Main Query Part ---
        // Added $groupByClause between WHERE and ORDER BY
        $baseSql = "SELECT $distinctClause $selectColumns FROM $tableClause $joinClause";

        if (!empty($where['clause'])) {
            $baseSql .= " WHERE " . $where['clause'];
        }

        $baseSql .= $groupByClause; // <--- Inserted Here
        $baseSql .= $orderByClause;

        // --- Handle Chunking ---
        if ($chunkSize !== null && $chunkSize > 0) {
            $sql = $baseSql;

            if ($limit !== null && $limit > 0) {
                $sql .= ($offset > 0) ? " LIMIT $offset, $limit" : " LIMIT $limit";
            } elseif ($offset > 0) {
                $sql .= " LIMIT $offset, 18446744073709551615";
            }

            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($where['params']);
                $allData = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($allData)) return [];

                $chunks = [];
                for ($i = 0; $i < count($allData); $i += $chunkSize) {
                    $chunks[] = array_slice($allData, $i, $chunkSize);
                }
                return $chunks;

            } catch (PDOException $e) {
                error_log("READ (CHUNK) operation failed: " . $e->getMessage());
                return false;
            }
        }

        // --- Handle Standard Query ---
        $sql = $baseSql;

        if ($single) {
            $sql .= " LIMIT 1";
        } elseif ($limit !== null && $limit > 0) {
            $sql .= ($offset > 0) ? " LIMIT $offset, $limit" : " LIMIT $limit";
        } elseif ($offset > 0) {
            $sql .= " LIMIT $offset, 18446744073709551615";
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($where['params']);
            return $single ? $stmt->fetch(PDO::FETCH_ASSOC) : $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("READ operation failed: " . $e->getMessage());
            return false;
        }
    }

    public function update(string $table, array $data, array $conditions): int|bool
    {
        if (empty($data) || empty($conditions)) return false;

        $normalizedData = $this->normalizeDataForDb($data);
        $setParts = [];
        $setParams = [];
        foreach ($normalizedData as $key => $value) {
            $setPlaceholder = ":set_$key";
            $setParts[] = "`$key` = $setPlaceholder";
            $setParams[$setPlaceholder] = $value;
        }
        $setFields = implode(', ', $setParts);

        $where = $this->buildWhere($conditions, 'where_upd');
        if (empty($where['clause'])) {
            error_log("UPDATE operation failed for table $table: Missing WHERE conditions.");
            return false;
        }

        $sql = "UPDATE `$table` SET $setFields WHERE " . $where['clause'];
        $executeParams = array_merge($setParams, $where['params']);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($executeParams);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("UPDATE operation failed for table $table: " . $e->getMessage());
            return false;
        }
    }

    public function upsert(string $table, array $data): int|bool
    {
        if (empty($data)) return false;

        $normalizedData = $this->normalizeDataForDb($data);
        $fields = array_keys($normalizedData);
        $fieldNames = implode(', ', $fields);
        $placeholders = ':' . implode(', :', $fields);

        $updateParts = [];
        foreach ($fields as $field) {
            $updateParts[] = "`$field` = VALUES(`$field`)";
        }
        $updateFields = implode(', ', $updateParts);

        $sql = "INSERT INTO `$table` ($fieldNames) VALUES ($placeholders)
                ON DUPLICATE KEY UPDATE $updateFields";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($normalizedData);
            $lastId = $this->pdo->lastInsertId();
            return ($lastId > 0) ? (int)$lastId : true;
        } catch (PDOException $e) {
            error_log("UPSERT operation failed for table $table: " . $e->getMessage());
            return false;
        }
    }

    public function upsertBatch(string $table, array $dataRows): int|bool
    {
        if (empty($dataRows) || empty($dataRows[0])) return false;

        $normalizedDataRows = array_map([$this, 'normalizeDataForDb'], $dataRows);
        $fields = array_keys($normalizedDataRows[0]);
        $fieldNames = implode(', ', $fields);
        $fieldCount = count($fields);

        $placeholderTemplate = '(' . implode(', ', array_fill(0, $fieldCount, '?')) . ')';
        $valueSets = array_fill(0, count($normalizedDataRows), $placeholderTemplate);
        $valuesClause = implode(', ', $valueSets);

        $updateParts = [];
        foreach ($fields as $field) {
            $updateParts[] = "`$field` = VALUES(`$field`)";
        }
        $updateFields = implode(', ', $updateParts);

        $sql = "INSERT INTO `$table` ($fieldNames) VALUES $valuesClause
                ON DUPLICATE KEY UPDATE $updateFields";

        $params = [];
        foreach ($normalizedDataRows as $row) {
            $params = array_merge($params, array_values(array_intersect_key($row, array_flip($fields))));
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("UPSERT BATCH operation failed for table $table: " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $table, array $conditions, bool $resetAutoIncrement = false): int|bool
    {
        if (empty($conditions)) {
            error_log("DELETE operation failed for table $table: Missing WHERE conditions.");
            return false;
        }

        $where = $this->buildWhere($conditions);
        if (empty($where['clause'])) {
            return false;
        }

        $sql = "DELETE FROM `$table` WHERE " . $where['clause'];

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($where['params']);
            $rowCount = $stmt->rowCount();

            if ($rowCount > 0 && $resetAutoIncrement) {
                try {
                    $resetSql = "ALTER TABLE `$table` AUTO_INCREMENT = 1";
                    $this->pdo->exec($resetSql);
                } catch (PDOException $e) {
                    error_log("AUTO_INCREMENT reset failed: " . $e->getMessage());
                }
            }
            return $rowCount;
        } catch (PDOException $e) {
            error_log("DELETE operation failed for table $table: " . $e->getMessage());
            return false;
        }
    }
}