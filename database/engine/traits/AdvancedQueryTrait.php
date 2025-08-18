<?php

/**
 * Trait AdvancedQueryTrait
 *
 * Handles complex queries, specialized retrieval patterns, pagination, collections, and time-based filters.
 * This trait provides advanced SELECT operations with filtering, ordering, and limiting for sophisticated data access.
 */
trait AdvancedQueryTrait {
    /**
     * Advanced WHERE clause queries with operator support and result limiting
     * 
     * Flexible query method supporting various comparison operators, automatic
     * LIKE pattern handling, and comprehensive result control. Includes
     * QueryBuilder validation and intelligent operator normalization.
     * 
     * Supported operators:
     * - Equality: '=', '!=', '<>'
     * - Comparison: '<', '>', '<=', '>='
     * - Pattern matching: 'LIKE', 'NOT LIKE' (auto-wraps with %)
     * 
     * @param string $column Column name for WHERE condition (validated)
     * @param mixed $value Value to compare against (auto-escaped)
     * @param string $operator Comparison operator (default: '=')
     * @param string $order_by Column name for ORDER BY (default: 'id')
     * @param string|null $target_table Target table name (defaults to current module)
     * @param int|null $limit Maximum number of records to return
     * @param int|null $offset Number of records to skip for pagination
     * @return array Array of matching database records as objects
     * @throws InvalidArgumentException If column names or operator fail validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Find active users
     * $activeUsers = $model->get_where_custom('status', 'active');
     * 
     * @example
     * // Pattern matching with pagination
     * $results = $model->get_where_custom('name', 'John', 'LIKE', 'created_at', 'users', 10, 0);
     * 
     * @example
     * // Numeric comparison
     * $expensiveProducts = $model->get_where_custom('price', 1000, '>', 'price', 'products');
     */
    public function get_where_custom(string $column, $value, string $operator = '=', string $order_by = 'id', ?string $target_table = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->connect();

        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateColumnName($column);
        QueryBuilder::validateColumnName($order_by);

        // Validate operator (cached)
        static $validOperators = ['=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE', 'NOT LIKE'];
        if (!in_array($operator, $validOperators)) {
            throw new InvalidArgumentException("Invalid operator: $operator");
        }

        // Handle LIKE operators
        if (in_array($operator, ['LIKE', 'NOT LIKE'])) {
            $value = "%$value%";
        }

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'where_column' => $column,
            'where_operator' => $operator,
            'order_by' => $order_by,
            'limit' => $limit,
            'offset' => $offset
        ]);

        $this->debugLog("SQL query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql
        ]);

        $stmt = $this->getPreparedStatement($sql);
        $this->executeStatement($stmt, ['value' => $value]);

        $results = $stmt->fetchAll();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("SELECT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                'table' => $table,
                'sql' => $sql,
                'params' => [],
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2),
                'result_count' => count($results),
                'order_by' => $order_by,
                'limit' => $limit,
                'offset' => $offset,
                'operation' => 'select'
            ]);

            $this->debug()->analyzeQuery($sql, ['value' => $value], $executionTime, [
                'table' => $table,
                'operation' => 'select',
                'result_count' => count($results),
                'has_order_by' => $order_by !== null,
                'has_limit' => $limit !== null,
                'large_result_set' => count($results) > 1000
            ]);
        }

        return $results;
    }

    /**
     * Fetch single record matching column condition with validation and optimization
     * 
     * High-performance single record retrieval with QueryBuilder validation, automatic
     * column name verification, and intelligent prepared statement caching. Designed
     * for scenarios where you expect exactly one matching record.
     * 
     * @param string $column Column name for WHERE condition (validated)
     * @param mixed $value Value to match against specified column (auto-escaped)
     * @param string|null $target_table Target table name (defaults to current module)
     * @return object|false Database record as object, or false if not found
     * @throws InvalidArgumentException If column name fails validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get user by email
     * $user = $model->get_one_where('email', 'john@example.com', 'users');
     * if ($user) {
     *     echo "Found user: " . $user->name;
     * }
     * 
     * @example
     * // Get product by SKU
     * $product = $model->get_one_where('sku', 'ABC-123');
     * 
     * @example
     * // Get setting by key
     * $setting = $model->get_one_where('setting_key', 'site_title', 'settings');
     */
    public function get_one_where(string $column, $value, ?string $target_table = null): object|false
    {
        $this->connect();
        $startTime = microtime(true);

        $table = $this->getTableName($target_table);

        QueryBuilder::validateColumnName($column);

        $this->debugLog("Single record query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'value_type' => gettype($value),
            'value_length' => is_string($value) ? strlen($value) : null,
            'operation' => 'get_one_where'
        ]);

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'where_column' => $column,
            'where_operator' => '=',
            'limit' => 1
        ]);

        $params = ['value' => $value];

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);

            $result = $stmt->fetch(PDO::FETCH_OBJ);
            $found = $result !== false;

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Single record query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'sql' => $sql,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'record_found' => $found,
                    'result_count' => $found ? 1 : 0,
                    'operation' => 'get_one_where'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'get_one_where',
                    'where_column' => $column,
                    'where_operator' => '=',
                    'result_count' => $found ? 1 : 0,
                    'single_record_query' => true,
                    'expected_single_result' => true,
                    'has_limit' => true,
                    'limit_value' => 1
                ]);
            }

            return $found ? $result : false;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Single record query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column,
                'sql' => $sql ?? 'SQL generation failed'
            ]);
            throw new RuntimeException("Failed to execute get_one_where query: " . $e->getMessage());
        }
    }

    /**
     * Retrieve multiple records from database table based on single column condition
     * 
     * Enhanced version of get_many_where with QueryBuilder integration, comprehensive
     * validation, and database-agnostic SQL generation. Provides simple interface
     * for retrieving all records matching a specific column value.
     * 
     * @param string $column Column name to filter by (validated for safety)
     * @param mixed $value Value to match against the specified column
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of database records as objects matching the condition
     * @throws InvalidArgumentException If column name fails validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get all active users
     * $activeUsers = $model->get_many_where('status', 'active', 'users');
     * 
     * @example
     * // Get all products in a category
     * $products = $model->get_many_where('category_id', 5, 'products');
     */
    public function get_many_where(string $column, $value, ?string $target_table = null): array
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateColumnName($column);

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'where_column' => $column,
            'where_operator' => '='
        ]);

        $this->debugLog("SQL query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql,
            'column' => $column
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, ['value' => $value]);
            $results = $stmt->fetchAll();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("SELECT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'sql' => $sql,
                    'params' => ['value' => $value],
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'result_count' => count($results),
                    'operation' => 'select'
                ]);

                $this->debug()->analyzeQuery($sql, ['value' => $value], $executionTime, [
                    'table' => $table,
                    'operation' => 'select',
                    'result_count' => count($results),
                    'where_column' => $column,
                    'large_result_set' => count($results) > 1000
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Query execution failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'sql' => $sql,
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute get_many_where query: " . $e->getMessage());
        }
    }

    /**
     * Retrieve records where column value exists in specified array of values
     * 
     * High-performance IN clause query with automatic parameter binding, validation,
     * and support for large value arrays. Handles database-specific limits and
     * provides flexible result formatting options.
     * 
     * @param string $column Column name to filter by (validated for safety)
     * @param array $values Array of values to match against (must not be empty)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string $return_type Result format: 'object' (default) or 'array'
     * @return array Array of matching database records in specified format
     * @throws InvalidArgumentException If column validation fails or values array is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get users by multiple IDs
     * $users = $model->get_where_in('id', [1, 2, 3, 4, 5], 'users');
     * 
     * @example
     * // Get products in multiple categories, return as arrays
     * $products = $model->get_where_in('category_id', [10, 20, 30], 'products', 'array');
     */
    public function get_where_in(string $column, array $values, ?string $target_table = null, string $return_type = 'object'): array
    {
        if (empty($values)) {
            throw new InvalidArgumentException('The values array must not be empty.');
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateColumnName($column);

        // Build IN clause with proper parameter binding
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);
        $sql = "SELECT * FROM $escapedTable WHERE $escapedColumn IN ($placeholders)";

        $this->debugLog("WHERE IN query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'values_count' => count($values),
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);

            // Bind values as positional parameters
            foreach ($values as $index => $value) {
                $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
            }

            $stmt->execute();
            $results = ($return_type === 'array') ?
                $stmt->fetchAll(PDO::FETCH_ASSOC) :
                $stmt->fetchAll(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("WHERE IN query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'values_count' => count($values),
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'result_count' => count($results),
                    'return_type' => $return_type,
                    'operation' => 'get_where_in'
                ]);

                // Advanced analysis for large IN clauses
                $this->debug()->analyzeQuery($sql, array_slice($values, 0, 10), $executionTime, [
                    'table' => $table,
                    'operation' => 'get_where_in',
                    'result_count' => count($results),
                    'where_column' => $column,
                    'in_clause_size' => count($values),
                    'large_in_clause' => count($values) > 100,
                    'return_type' => $return_type
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("WHERE IN query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'values_count' => count($values)
            ]);
            throw new RuntimeException("Failed to execute get_where_in query: " . $e->getMessage());
        }
    }

    /**
     * Get first record from table with optional ordering
     * 
     * Retrieves the first record based on specified ordering criteria. Optimized
     * with LIMIT 1 for performance and includes comprehensive validation and
     * debug integration. Commonly used for getting oldest, newest, or alphabetically first records.
     * 
     * @param string|null $order_by Column and direction for ordering (e.g., 'created_at', 'id desc')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return object|false First record as object, or false if table is empty
     * @throws InvalidArgumentException If table name or order validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get first user by creation date
     * $firstUser = $model->get_first('created_at', 'users');
     * 
     * @example
     * // Get alphabetically first product
     * $firstProduct = $model->get_first('name', 'products');
     * 
     * @example
     * // Get oldest order (default id ordering)
     * $oldestOrder = $model->get_first(null, 'orders');
     */
    public function get_first(?string $order_by = 'id', ?string $target_table = null): object|false
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $sql = "SELECT * FROM $escapedTable";

        if ($order_by) {
            $orderClause = DatabaseSecurity::validateOrderBy($order_by, $this->dbType);
            $sql .= " ORDER BY $orderClause";
        }

        $sql .= " LIMIT 1";

        $this->debugLog("Get first record query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'order_by' => $order_by,
            'operation' => 'get_first'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $result = $stmt->fetch(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Get first record completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'order_by' => $order_by,
                    'record_found' => $result !== false,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_first'
                ]);

                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'table' => $table,
                    'operation' => 'get_first',
                    'has_order_by' => $order_by !== null,
                    'has_limit' => true,
                    'limit_value' => 1,
                    'result_count' => $result !== false ? 1 : 0
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Get first record failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute get_first query: " . $e->getMessage());
        }
    }

    /**
     * Get last record from table with optional ordering
     * 
     * Retrieves the last record based on specified ordering criteria. Automatically
     * reverses the order direction to get the last record efficiently. Commonly used
     * for getting newest, latest, or alphabetically last records.
     * 
     * @param string|null $order_by Column and direction for ordering (e.g., 'created_at', 'id desc')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return object|false Last record as object, or false if table is empty
     * @throws InvalidArgumentException If table name or order validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get latest user registration
     * $latestUser = $model->get_last('created_at', 'users');
     * 
     * @example
     * // Get highest ID order
     * $latestOrder = $model->get_last('id', 'orders');
     * 
     * @example
     * // Get most recent blog post
     * $recentPost = $model->get_last('published_at', 'posts');
     */
    public function get_last(?string $order_by = 'id', ?string $target_table = null): object|false
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $sql = "SELECT * FROM $escapedTable";

        if ($order_by) {
            // Reverse the order direction to get the last record
            $reversedOrder = $this->reverseOrderDirection($order_by);

            // Use existing ORDER BY validation and escaping
            QueryBuilder::validateOrderBy($reversedOrder);
            $orderClause = DatabaseSecurity::validateOrderBy($reversedOrder, $this->dbType);
            $sql .= " ORDER BY $orderClause";
        } else {
            // Default to DESC order for last record
            $sql .= " ORDER BY id DESC";
        }

        $sql .= " LIMIT 1";

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $result = $stmt->fetch(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;
                $this->debugLog("Get last record completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'order_by' => $order_by,
                    'record_found' => $result !== false,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_last'
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to execute get_last query: " . $e->getMessage());
        }
    }

    /**
     * Get random records from table with cross-database compatibility
     * 
     * Retrieves random records using database-specific RANDOM functions with automatic
     * translation. Provides true randomness across MySQL (RAND()), SQLite (RANDOM()),
     * and PostgreSQL (RANDOM()) with consistent API.
     * 
     * @param int $limit Number of random records to retrieve (default: 1)
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of random records as objects
     * @throws InvalidArgumentException If table validation fails or limit is invalid
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get single random product
     * $randomProduct = $model->get_random(1, 'products');
     * 
     * @example
     * // Get 5 random featured articles
     * $randomArticles = $model->get_random(5, 'featured_articles');
     * 
     * @example
     * // Get random user for spotlight
     * $spotlightUser = $model->get_random(1, 'users');
     */
    public function get_random(int $limit = 1, ?string $target_table = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("Limit must be at least 1");
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);

        // Database-specific random function
        $randomFunction = match ($this->dbType) {
            'mysql' => 'RAND()',
            'sqlite' => 'RANDOM()',
            'postgresql' => 'RANDOM()',
            default => 'RANDOM()'
        };

        $sql = "SELECT * FROM $escapedTable ORDER BY $randomFunction LIMIT :limit";

        $this->debugLog("Get random records query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'limit' => $limit,
            'random_function' => $randomFunction,
            'operation' => 'get_random'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, ['limit' => $limit]);
            $results = $stmt->fetchAll(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Get random records completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'requested_limit' => $limit,
                    'records_found' => count($results),
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_random'
                ]);

                $this->debug()->analyzeQuery($sql, ['limit' => $limit], $executionTime, [
                    'table' => $table,
                    'operation' => 'get_random',
                    'has_order_by' => true,
                    'order_by_type' => 'random',
                    'has_limit' => true,
                    'limit_value' => $limit,
                    'result_count' => count($results)
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Get random records failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'limit' => $limit
            ]);
            throw new RuntimeException("Failed to execute get_random query: " . $e->getMessage());
        }
    }

    /**
     * Extract single column values as array with optional key column
     * 
     * Powerful collection helper that extracts a single column as an array, optionally
     * using another column as array keys. Extremely useful for creating lookup arrays,
     * option lists, and data transformations.
     * 
     * @param string $column Column name to extract values from (validated)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $key_column Optional column to use as array keys (validated)
     * @return array Array of column values, optionally keyed by key_column
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get array of user emails
     * $emails = $model->pluck('email', 'users');
     * // Returns: ['user1@example.com', 'user2@example.com', ...]
     * 
     * @example
     * // Get user emails keyed by ID
     * $emailsById = $model->pluck('email', 'users', 'id');
     * // Returns: [1 => 'user1@example.com', 2 => 'user2@example.com', ...]
     * 
     * @example
     * // Get product names keyed by SKU
     * $productNames = $model->pluck('name', 'products', 'sku');
     * // Returns: ['ABC-123' => 'Widget A', 'DEF-456' => 'Widget B', ...]
     */
    public function pluck(string $column, ?string $target_table = null, ?string $key_column = null): array
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($key_column !== null) {
            QueryBuilder::validateColumnName($key_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT $escapedColumn";
        if ($key_column !== null) {
            $escapedKeyColumn = QueryBuilder::escapeIdentifier($key_column, $this->dbType);
            $sql .= ", $escapedKeyColumn";
        }
        $sql .= " FROM $escapedTable";

        $this->debugLog("Pluck operation initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'key_column' => $key_column,
            'has_key_column' => $key_column !== null,
            'operation' => 'pluck'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);

            $result = [];
            if ($key_column !== null) {
                // Use key column for array keys
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $result[$row[$key_column]] = $row[$column];
                }
            } else {
                // Simple indexed array
                $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Pluck operation completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'key_column' => $key_column,
                    'values_extracted' => count($result),
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'pluck'
                ]);

                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'table' => $table,
                    'operation' => 'pluck',
                    'columns_selected' => $key_column !== null ? 2 : 1,
                    'result_count' => count($result),
                    'collection_transformation' => true
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Pluck operation failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute pluck operation: " . $e->getMessage());
        }
    }

    /**
     * Get distinct values from column
     * 
     * Retrieves unique values from a column, commonly used for populating
     * filter dropdowns, category lists, and data analysis. Includes automatic
     * NULL filtering and optional ordering.
     * 
     * @param string $column Column name to get distinct values from (validated)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $order_by Optional ordering for results
     * @return array Array of distinct values from the column
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get all product categories
     * $categories = $model->get_distinct('category', 'products');
     * 
     * @example
     * // Get all countries with ordering
     * $countries = $model->get_distinct('country', 'users', 'country ASC');
     * 
     * @example
     * // Get all order statuses
     * $statuses = $model->get_distinct('status', 'orders');
     */
    public function get_distinct(string $column, ?string $target_table = null, ?string $order_by = null): array
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT DISTINCT $escapedColumn FROM $escapedTable WHERE $escapedColumn IS NOT NULL";

        if ($order_by) {
            // Use existing ORDER BY validation and escaping
            QueryBuilder::validateOrderBy($order_by);
            $orderClause = DatabaseSecurity::validateOrderBy($order_by, $this->dbType);
            $sql .= " ORDER BY $orderClause";
        }

        $this->debugLog("Get distinct values query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'order_by' => $order_by,
            'operation' => 'get_distinct'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Get distinct values completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'distinct_values' => count($results),
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_distinct'
                ]);

                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'table' => $table,
                    'operation' => 'get_distinct',
                    'aggregation_function' => 'DISTINCT',
                    'has_order_by' => $order_by !== null,
                    'result_count' => count($results)
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Get distinct values failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute get_distinct query: " . $e->getMessage());
        }
    }

    /**
     * Built-in pagination with comprehensive metadata
     * 
     * High-performance pagination implementation with intelligent total count caching
     * and comprehensive result metadata. Provides all information needed for
     * pagination UI components and navigation.
     * 
     * @param int $page Current page number (1-based)
     * @param int $per_page Records per page (default: 20)
     * @param string|null $order_by Optional ordering clause
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Pagination result with data and metadata
     * @throws InvalidArgumentException If page or per_page parameters are invalid
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Basic pagination
     * $result = $model->paginate(2, 15, 'created_at desc', 'posts');
     * // Returns: [
     * //   'data' => [...],           // Array of records
     * //   'total' => 150,            // Total record count
     * //   'page' => 2,               // Current page
     * //   'per_page' => 15,          // Records per page
     * //   'total_pages' => 10,       // Total pages
     * //   'has_previous' => true,    // Has previous page
     * //   'has_next' => true,        // Has next page
     * //   'previous_page' => 1,      // Previous page number
     * //   'next_page' => 3           // Next page number
     * // ]
     * 
     * @example
     * // Simple pagination without ordering
     * $users = $model->paginate(1, 10, null, 'users');
     */
    public function paginate(int $page, int $per_page = 20, ?string $order_by = null, ?string $target_table = null): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException("Page must be at least 1");
        }

        if ($per_page < 1 || $per_page > 1000) {
            throw new InvalidArgumentException("Per page must be between 1 and 1000");
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);

        $this->debugLog("Pagination query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'page' => $page,
            'per_page' => $per_page,
            'order_by' => $order_by,
            'operation' => 'paginate'
        ]);

        try {
            // Get total count for pagination metadata
            $total = $this->count($table);
            $total_pages = ceil($total / $per_page);

            // Calculate offset
            $offset = ($page - 1) * $per_page;

            // Get paginated data
            $data = $this->get($order_by, $table, $per_page, $offset);

            // Build pagination metadata
            $result = [
                'data' => $data,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => $total_pages,
                'has_previous' => $page > 1,
                'has_next' => $page < $total_pages,
                'previous_page' => $page > 1 ? $page - 1 : null,
                'next_page' => $page < $total_pages ? $page + 1 : null,
                'from' => $offset + 1,
                'to' => min($offset + $per_page, $total)
            ];

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Pagination query completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_records' => $total,
                    'records_returned' => count($data),
                    'total_pages' => $total_pages,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'paginate'
                ]);

                $this->debug()->analyzeBulkOperation('pagination', count($data), [
                    'table' => $table,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_records' => $total,
                    'execution_time' => $executionTime
                ]);
            }

            return $result;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Pagination query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'page' => $page
            ]);
            throw new RuntimeException("Failed to execute pagination query: " . $e->getMessage());
        }
    }

    /**
     * Get maximum ID value from specified table (convenience wrapper)
     * 
     * Convenience method for the common pattern of getting the highest ID value.
     * This method is now implemented as a wrapper around the more general max()
     * method, ensuring consistency and reducing code duplication.
     * 
     * @param string|null $target_table Target table name (defaults to current module)
     * @return int Maximum ID value, or 0 if table is empty
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get highest user ID
     * $maxUserId = $model->get_max('users');
     * 
     * @example
     * // Check latest order ID
     * $latestOrderId = $model->get_max('orders');
     * 
     * @example
     * // Get max ID from current module table
     * $maxId = $model->get_max();
     */
    public function get_max(?string $target_table = null): int
    {
        // Delegate to the general max() method for ID column
        $result = $this->max('id', $target_table);

        // Convert to int and handle null case (empty table)
        return $result !== null ? (int)$result : 0;
    }

    /**
     * Get records within date range
     * 
     * Efficient date range filtering with automatic date validation and timezone
     * handling. Commonly used for reporting, analytics, and time-based data retrieval.
     * 
     * @param string $start_date Start date (YYYY-MM-DD format)
     * @param string $end_date End date (YYYY-MM-DD format)
     * @param string $date_column Column name containing date values (default: 'created_at')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of records within the date range
     * @throws InvalidArgumentException If date format is invalid or column validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get orders from January 2024
     * $orders = $model->get_by_date_range('2024-01-01', '2024-01-31', 'order_date', 'orders');
     * 
     * @example
     * // Get recent user registrations
     * $recentUsers = $model->get_by_date_range('2024-01-01', '2024-12-31', 'created_at', 'users');
     * 
     * @example
     * // Get posts published this year
     * $posts = $model->get_by_date_range('2024-01-01', '2024-12-31', 'published_at', 'posts');
     */
    public function get_by_date_range(string $start_date, string $end_date, string $date_column = 'created_at', ?string $target_table = null): array
    {
        // Validate date formats
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            throw new InvalidArgumentException("Start date must be in YYYY-MM-DD format");
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            throw new InvalidArgumentException("End date must be in YYYY-MM-DD format");
        }

        if ($start_date > $end_date) {
            throw new InvalidArgumentException("Start date must be before or equal to end date");
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($date_column);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedDateColumn = QueryBuilder::escapeIdentifier($date_column, $this->dbType);

        $sql = "SELECT * FROM $escapedTable WHERE $escapedDateColumn >= :start_date AND $escapedDateColumn <= :end_date";

        $params = [
            'start_date' => $start_date,
            'end_date' => $end_date
        ];

        $this->debugLog("Date range query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'date_column' => $date_column,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'operation' => 'get_by_date_range'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $results = $stmt->fetchAll(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Date range query completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'date_column' => $date_column,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'records_found' => count($results),
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_by_date_range'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'date_range_filter',
                    'where_column' => $date_column,
                    'date_range_query' => true,
                    'result_count' => count($results)
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Date range query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'date_column' => $date_column
            ]);
            throw new RuntimeException("Failed to execute date range query: " . $e->getMessage());
        }
    }

    /**
     * Get records created today
     * 
     * Convenience method for retrieving today's records using database-specific
     * date functions. Automatically handles timezone considerations and provides
     * cross-database compatibility.
     * 
     * @param string $date_column Column containing date values (default: 'created_at')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of records created today
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get today's orders
     * $todaysOrders = $model->get_today('order_date', 'orders');
     * 
     * @example
     * // Get today's user registrations
     * $newUsers = $model->get_today('created_at', 'users');
     * 
     * @example
     * // Get today's blog posts
     * $todaysPosts = $model->get_today('published_at', 'posts');
     */
    public function get_today(string $date_column = 'created_at', ?string $target_table = null): array
    {
        $today = date('Y-m-d');
        return $this->get_by_date_range($today, $today, $date_column, $target_table);
    }

    /**
     * Get records from the past N days
     * 
     * Retrieves records from a specified number of days ago up to today.
     * Commonly used for recent activity feeds, analytics dashboards, and
     * time-based reporting.
     * 
     * @param int $days Number of days to look back (default: 7)
     * @param string $date_column Column containing date values (default: 'created_at')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of records from the specified time period
     * @throws InvalidArgumentException If days parameter or validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get orders from the past week
     * $recentOrders = $model->get_recent(7, 'order_date', 'orders');
     * 
     * @example
     * // Get posts from the past month
     * $recentPosts = $model->get_recent(30, 'published_at', 'posts');
     * 
     * @example
     * // Get user activity from past 3 days
     * $recentActivity = $model->get_recent(3, 'last_login', 'users');
     */
    public function get_recent(int $days = 7, string $date_column = 'created_at', ?string $target_table = null): array
    {
        if ($days < 1) {
            throw new InvalidArgumentException("Days must be at least 1");
        }

        $start_date = date('Y-m-d', strtotime("-$days days"));
        $end_date = date('Y-m-d');

        return $this->get_by_date_range($start_date, $end_date, $date_column, $target_table);
    }

    /**
     * Reverse order direction for get_last() method
     * 
     * @param string $order_by Original order clause
     * @return string Reversed order clause
     */
    private function reverseOrderDirection(string $order_by): string
    {
        // Simple reversal - if contains DESC, remove it; if no direction or ASC, add DESC
        if (stripos($order_by, ' desc') !== false) {
            return str_ireplace(' desc', '', $order_by);
        } elseif (stripos($order_by, ' asc') !== false) {
            return str_ireplace(' asc', ' desc', $order_by);
        } else {
            return $order_by . ' desc';
        }
    }
}
