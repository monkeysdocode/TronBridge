<?php

/**
 * Trait AggregateAndValidationTrait
 *
 * Provides aggregate functions for data summarization (sum, avg, etc.) and validation methods like existence and uniqueness checks.
 * This trait focuses on analytical queries and pre-CRUD validations for data integrity.
 */
trait AggregateAndValidationTrait {
    /**
     * Calculate sum of numeric column with optional WHERE condition
     * 
     * High-performance aggregate calculation with QueryBuilder validation, optional
     * filtering, and cross-database compatibility. Handles NULL values appropriately
     * and provides comprehensive debug integration for performance analysis.
     * 
     * @param string $column Column name to sum (validated for safety)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $where_column Optional WHERE condition column
     * @param mixed $where_value Optional WHERE condition value
     * @return float Sum of column values, 0.0 if no matching records
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Calculate total revenue
     * $totalRevenue = $model->sum('amount', 'orders');
     * 
     * @example
     * // Calculate completed orders revenue
     * $completedRevenue = $model->sum('amount', 'orders', 'status', 'completed');
     * 
     * @example
     * // Calculate user's total spending
     * $userSpending = $model->sum('total', 'orders', 'user_id', 123);
     */
    public function sum(string $column, ?string $target_table = null, ?string $where_column = null, $where_value = null): float
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        // Validate column and table names
        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($where_column !== null) {
            QueryBuilder::validateColumnName($where_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT COALESCE(SUM($escapedColumn), 0) FROM $escapedTable";
        $params = [];

        if ($where_column !== null && $where_value !== null) {
            $escapedWhereColumn = QueryBuilder::escapeIdentifier($where_column, $this->dbType);
            $sql .= " WHERE $escapedWhereColumn = :where_value";
            $params['where_value'] = $where_value;
        }

        $this->debugLog("SUM aggregate query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'where_column' => $where_column,
            'has_where_condition' => $where_column !== null,
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $result = (float)$stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("SUM aggregate query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'sum_result' => $result,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'sum'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'sum',
                    'aggregation_function' => 'SUM',
                    'where_column' => $where_column,
                    'result_count' => 1
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("SUM aggregate query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute sum query: " . $e->getMessage());
        }
    }

    /**
     * Calculate average of numeric column with optional WHERE condition
     * 
     * High-performance aggregate calculation with proper NULL handling and
     * cross-database compatibility. Returns precise decimal average with
     * comprehensive error handling and debug integration.
     * 
     * @param string $column Column name to average (validated for safety)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $where_column Optional WHERE condition column
     * @param mixed $where_value Optional WHERE condition value
     * @return float Average of column values, 0.0 if no matching records
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Calculate average product rating
     * $avgRating = $model->avg('rating', 'reviews');
     * 
     * @example
     * // Calculate average price for category
     * $avgPrice = $model->avg('price', 'products', 'category_id', 5);
     */
    public function avg(string $column, ?string $target_table = null, ?string $where_column = null, $where_value = null): float
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($where_column !== null) {
            QueryBuilder::validateColumnName($where_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT COALESCE(AVG($escapedColumn), 0) FROM $escapedTable";
        $params = [];

        if ($where_column !== null && $where_value !== null) {
            $escapedWhereColumn = QueryBuilder::escapeIdentifier($where_column, $this->dbType);
            $sql .= " WHERE $escapedWhereColumn = :where_value";
            $params['where_value'] = $where_value;
        }

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $result = (float)$stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;
                $this->debugLog("AVG aggregate query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'avg_result' => $result,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'avg'
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to execute average query: " . $e->getMessage());
        }
    }

    /**
     * Find minimum value in column with optional WHERE condition
     * 
     * Cross-database compatible minimum value calculation supporting numeric,
     * date, and text columns. Handles NULL values appropriately and provides
     * type-appropriate return values.
     * 
     * @param string $column Column name to find minimum (validated for safety)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $where_column Optional WHERE condition column
     * @param mixed $where_value Optional WHERE condition value
     * @return mixed Minimum value from column, null if no matching records
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Find oldest user registration
     * $oldestUser = $model->min('created_at', 'users');
     * 
     * @example
     * // Find lowest price in category
     * $lowestPrice = $model->min('price', 'products', 'category_id', 3);
     */
    public function min(string $column, ?string $target_table = null, ?string $where_column = null, $where_value = null): mixed
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($where_column !== null) {
            QueryBuilder::validateColumnName($where_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT MIN($escapedColumn) FROM $escapedTable";
        $params = [];

        if ($where_column !== null && $where_value !== null) {
            $escapedWhereColumn = QueryBuilder::escapeIdentifier($where_column, $this->dbType);
            $sql .= " WHERE $escapedWhereColumn = :where_value";
            $params['where_value'] = $where_value;
        }

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $result = $stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;
                $this->debugLog("MIN aggregate query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'min_result' => $result,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'min'
                ]);
            }

            return $result === false ? null : $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to execute minimum query: " . $e->getMessage());
        }
    }

    /**
     * Find maximum value in column with optional WHERE condition
     * 
     * Cross-database compatible maximum value calculation supporting numeric,
     * date, and text columns. General-purpose aggregate method that serves
     * as the foundation for specialized methods like get_max().
     * 
     * @param string $column Column name to find maximum (validated for safety)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $where_column Optional WHERE condition column
     * @param mixed $where_value Optional WHERE condition value
     * @return mixed Maximum value from column, null if no matching records
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Find highest price
     * $highestPrice = $model->max('price', 'products');
     * 
     * @example
     * // Find latest order date for user
     * $latestOrder = $model->max('order_date', 'orders', 'user_id', 123);
     * 
     * @example
     * // Find highest rating in category
     * $topRating = $model->max('rating', 'reviews', 'category', 'electronics');
     * 
     * @example
     * // Find maximum ID (though get_max() is more convenient for this)
     * $maxId = $model->max('id', 'users');
     */
    public function max(string $column, ?string $target_table = null, ?string $where_column = null, $where_value = null): mixed
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($where_column !== null) {
            QueryBuilder::validateColumnName($where_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT MAX($escapedColumn) FROM $escapedTable";
        $params = [];

        if ($where_column !== null && $where_value !== null) {
            $escapedWhereColumn = QueryBuilder::escapeIdentifier($where_column, $this->dbType);
            $sql .= " WHERE $escapedWhereColumn = :where_value";
            $params['where_value'] = $where_value;
        }

        $this->debugLog("MAX aggregate query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'where_column' => $where_column,
            'has_where_condition' => $where_column !== null,
            'operation' => 'max'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $result = $stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("MAX aggregate query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'max_result' => $result,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'max'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'max',
                    'aggregation_function' => 'MAX',
                    'where_column' => $where_column,
                    'result_count' => 1
                ]);
            }

            return $result === false ? null : $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("MAX aggregate query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute maximum query: " . $e->getMessage());
        }
    }

    /**
     * Count total records in table with efficient COUNT(*) query
     * 
     * Optimized record counting using QueryBuilder identifier escaping
     * and prepared statement caching for repeated count operations.
     * 
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @return int Total number of records in the table
     * @throws InvalidArgumentException If table name fails validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Count all users
     * $userCount = $model->count('users');
     * echo "Total users: $userCount";
     * 
     * @example
     * // Count current module records
     * $recordCount = $model->count();
     */
    public function count(?string $target_tbl = null): int
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_tbl);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $sql = "SELECT COUNT(*) FROM $escapedTable";

        $this->debugLog("COUNT query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $result = (int)$stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("COUNT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'sql' => $sql,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'count_result' => $result,
                    'operation' => 'count'
                ]);

                // Performance analysis for large tables
                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'table' => $table,
                    'operation' => 'count',
                    'result_count' => 1,
                    'aggregation_function' => 'COUNT',
                    'large_table' => $result > 100000
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("COUNT query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute count query: " . $e->getMessage());
        }
    }

    /**
     * Count records matching column condition with flexible operator support
     * 
     * Enhanced counting method with comprehensive operator validation, LIKE pattern
     * handling, and QueryBuilder integration. Supports all standard comparison
     * operators and provides optimized COUNT queries for performance.
     * 
     * @param string $column Column name for WHERE condition (validated)
     * @param mixed $value Value to compare against (auto-escaped)
     * @param string $operator Comparison operator (default: '=')
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @return int Number of records matching the specified condition
     * @throws InvalidArgumentException If column name or operator validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Count active users
     * $activeCount = $model->count_where('status', 'active');
     * 
     * @example
     * // Count expensive products
     * $expensiveCount = $model->count_where('price', 1000, '>', 'products');
     * 
     * @example
     * // Count users with name pattern
     * $johnCount = $model->count_where('name', 'John', 'LIKE', 'users');
     */
    public function count_where(string $column, $value, string $operator = '=', ?string $target_tbl = null): int
    {
        $this->connect();
        $startTime = microtime(true);

        $table = $this->getTableName($target_tbl);

        // Validate column name using QueryBuilder
        QueryBuilder::validateColumnName($column);

        // Validate operator (cached for performance)
        static $validOperators = ['=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE', 'NOT LIKE'];
        if (!in_array($operator, $validOperators)) {
            throw new InvalidArgumentException("Invalid operator: $operator");
        }

        // Handle LIKE operators
        if (in_array($operator, ['LIKE', 'NOT LIKE'])) {
            $value = "%$value%";
        }

        $sql = $this->getOptimizedSQL('count_query', [
            'table' => $table,
            'where_column' => $column,
            'where_operator' => $operator
        ]);

        $this->debugLog("COUNT query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql
        ]);

        /*
        if ($this->debug) {
            $this->debugQuery($sql, ['value' => $value]);
        }
            */

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, ['value' => $value]);
            $result =  (int)$stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("COUNT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'operator' => $operator,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'count_result' => $result,
                    'operation' => 'count_where'
                ]);

                // Add this missing analysis
                $this->debug()->analyzeQuery($sql, ['value' => $value], $executionTime, [
                    'table' => $table,
                    'operation' => 'count_where',
                    'aggregation_function' => 'COUNT',
                    'where_column' => $column,
                    'where_operator' => $operator,
                    'result_count' => 1
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("COUNT query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute count_where query: " . $e->getMessage());
        }
    }

    /**
     * Count records matching single column equality condition
     * 
     * Simplified counting method for basic equality matching. Optimized for
     * high-frequency counting operations with minimal overhead and automatic
     * validation through QueryBuilder integration.
     * 
     * @param string $column Column name for equality condition (validated)
     * @param mixed $value Value that must match exactly
     * @param string|null $target_table Target table name (defaults to current module)
     * @return int Number of records with matching column value
     * @throws InvalidArgumentException If column name validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Count users in specific city
     * $cityCount = $model->count_rows('city', 'Toronto', 'users');
     * 
     * @example
     * // Count active sessions
     * $activeSessionCount = $model->count_rows('status', 'active', 'sessions');
     */
    public function count_rows(string $column, $value, ?string $target_table = null): int
    {
        // Delegate to count_where with equality operator for consistency
        return $this->count_where($column, $value, '=', $target_table);
    }

    /**
     * Check if record exists with specified column value (optimized for large tables)
     * 
     * High-performance existence check using optimized LIMIT 1 query instead of COUNT.
     * Significantly faster than count() > 0 for large tables since it stops at first match.
     * Includes comprehensive validation and debug integration.
     * 
     * @param string $column Column name for condition (validated for safety)
     * @param mixed $value Value to check for existence
     * @param string|null $target_table Target table name (defaults to current module)
     * @return bool True if record exists, false otherwise
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Check if email exists (faster than count for large tables)
     * if ($model->exists('email', 'user@example.com', 'users')) {
     *     echo "Email already registered";
     * }
     * 
     * @example
     * // Check if product SKU exists
     * if ($model->exists('sku', 'ABC-123', 'products')) {
     *     echo "SKU already in use";
     * }
     * 
     * @example
     * // Check if order exists for user
     * if ($model->exists('user_id', 123, 'orders')) {
     *     echo "User has orders";
     * }
     */
    public function exists(string $column, $value, ?string $target_table = null): bool
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT 1 FROM $escapedTable WHERE $escapedColumn = :value LIMIT 1";

        $this->debugLog("Existence check query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'value_type' => gettype($value),
            'operation' => 'exists'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, ['value' => $value]);
            $exists = $stmt->fetch() !== false;

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Existence check completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'exists' => $exists,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'exists'
                ]);

                $this->debug()->analyzeQuery($sql, ['value' => $value], $executionTime, [
                    'table' => $table,
                    'operation' => 'exists',
                    'where_column' => $column,
                    'optimized_existence_check' => true,
                    'result_count' => 1
                ]);
            }

            return $exists;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Existence check failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute existence check: " . $e->getMessage());
        }
    }

    /**
     * Check if column value is unique (excluding specified record)
     * 
     * Essential validation helper for update operations where you need to ensure
     * uniqueness while excluding the current record. Commonly used for email,
     * username, and SKU validation during updates.
     * 
     * @param string $column Column name to check for uniqueness (validated)
     * @param mixed $value Value to check for uniqueness
     * @param string|null $target_table Target table name (defaults to current module)
     * @param int|null $exclude_id Optional ID to exclude from uniqueness check
     * @return bool True if value is unique, false if duplicate exists
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Check username uniqueness during user update
     * if (!$model->is_unique('username', 'john_doe', 'users', $currentUserId)) {
     *     echo "Username already taken";
     * }
     * 
     * @example
     * // Check email uniqueness for new registration
     * if (!$model->is_unique('email', 'user@example.com', 'users')) {
     *     echo "Email already registered";
     * }
     * 
     * @example
     * // Check product SKU uniqueness during update
     * if (!$model->is_unique('sku', 'ABC-123', 'products', $productId)) {
     *     echo "SKU already exists";
     * }
     */
    public function is_unique(string $column, $value, ?string $target_table = null, ?int $exclude_id = null): bool
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT 1 FROM $escapedTable WHERE $escapedColumn = :value";
        $params = ['value' => $value];

        if ($exclude_id !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $exclude_id;
        }

        $sql .= " LIMIT 1";

        $this->debugLog("Uniqueness check query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'exclude_id' => $exclude_id,
            'has_exclusion' => $exclude_id !== null,
            'operation' => 'is_unique'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $duplicate_exists = $stmt->fetch() !== false;
            $is_unique = !$duplicate_exists;

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Uniqueness check completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'is_unique' => $is_unique,
                    'exclude_id' => $exclude_id,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'is_unique'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'uniqueness_check',
                    'where_column' => $column,
                    'has_exclusion' => $exclude_id !== null,
                    'result_count' => 1
                ]);
            }

            return $is_unique;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Uniqueness check failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute uniqueness check: " . $e->getMessage());
        }
    }
}
