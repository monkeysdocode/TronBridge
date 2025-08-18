<?php

/**
 * Trait BasicCrudTrait
 *
 * Provides fundamental CRUD methods for single-record operations, including basic inserts, updates, deletes, and gets.
 * This trait forms the core of database interactions, handling essential read and write operations with validation and optimization.
 */
trait BasicCrudTrait {
    /**
     * Get all records from table with optional ordering and pagination
     * 
     * High-performance record retrieval with QueryBuilder validation, intelligent
     * SQL caching, and comprehensive result control. Supports flexible ordering
     * and pagination for large datasets.
     * 
     * @param string|null $order_by Column name and direction for ORDER BY (e.g., 'id desc', 'name asc')
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @param int|null $limit Maximum number of records to return
     * @param int $offset Number of records to skip for pagination (default: 0)
     * @return array Array of database records as objects
     * @throws InvalidArgumentException If table name or ORDER BY validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get all users ordered by ID descending
     * $users = $model->get('id desc', 'users');
     * 
     * @example
     * // Get latest 10 posts with pagination
     * $posts = $model->get('created_at desc', 'posts', 10, 20);
     * 
     * @example
     * // Multiple column ordering
     * $products = $model->get('category_id asc, price desc', 'products');
     */
    public function get(?string $order_by = null, ?string $target_tbl = null, ?int $limit = null, int $offset = 0): array
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_tbl);

        if ($order_by) {
            QueryBuilder::validateOrderBy($order_by);
        }

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'order_by' => $order_by,
            'limit' => $limit,
            'offset' => $offset
        ]);

        $this->debugLog("SQL query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql,
            'order_by' => $order_by,
            'limit' => $limit,
            'offset' => $offset
        ]);

        $stmt = $this->getPreparedStatement($sql);
        $this->executeStatement($stmt);
        $results = $stmt->fetchAll();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            // Basic operation logging
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

            // Advanced query analysis
            $this->debug()->analyzeQuery($sql, [], $executionTime, [
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
     * Retrieve single record by ID with optimized prepared statement caching
     * 
     * Ultra-fast record lookup by primary key using QueryBuilder validation
     * and intelligent SQL caching. Optimized for high-frequency access patterns
     * common in web applications.
     * 
     * @param int $id Primary key value to search for
     * @param string|null $target_table Target table name (defaults to current module)
     * @return object|false Database record as object, or false if not found
     * @throws InvalidArgumentException If table name fails validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get user by ID
     * $user = $model->get_where(123);
     * if ($user) {
     *     echo "Found user: " . $user->name;
     * }
     * 
     * @example
     * // From specific table
     * $product = $model->get_where(456, 'products');
     */
    public function get_where(int $id, ?string $target_table = null): object|false
    {
        $this->connect();

        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'where_id' => $id
        ]);


        $this->debugLog("SQL query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql
        ]);

        $stmt = $this->getPreparedStatement($sql);
        $this->executeStatement($stmt, ['id' => $id]);

        $results = $stmt->fetch() ?: false;

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("SELECT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                'table' => $table,
                'sql' => $sql,
                'params' => [],
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2),
                'result_count' => count($results),
                'operation' => 'select'
            ]);

            $this->debug()->analyzeQuery($sql, [], $executionTime, [
                'table' => $table,
                'operation' => 'select',
                'result_count' => count($results),
                'has_order_by' => false,
                'has_limit' => false,
                'large_result_set' => count($results) > 1000
            ]);
        }

        return $results;
    }

    /**
     * Insert single record with auto-bulk detection and performance optimization
     * 
     * High-performance INSERT operation with automatic bulk operation detection
     * for pattern recognition and adaptive optimization. Triggers bulk mode
     * when multiple inserts are detected within a session.
     * 
     * Features:
     * - Automatic bulk operation detection for performance optimization
     * - QueryBuilder validation for all identifiers
     * - Intelligent prepared statement caching
     * - Returns auto-increment ID for inserted records
     * 
     * @param array $data Associative array of column => value pairs to insert
     * @param string|null $target_table Target table name (defaults to current module)
     * @return int|false Auto-increment ID of inserted record, or false on failure
     * @throws InvalidArgumentException If table/column names fail validation or data is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Insert new user
     * $userId = $model->insert([
     *     'name' => 'Jane Doe',
     *     'email' => 'jane@example.com',
     *     'created_at' => date('Y-m-d H:i:s')
     * ]);
     * 
     * @example
     * // Insert product
     * $productId = $model->insert([
     *     'name' => 'Widget',
     *     'price' => 29.99,
     *     'category_id' => 5
     * ], 'products');
     */
    public function insert(array $data, ?string $target_table = null): int|false
    {
        if (empty($data)) {
            return false;
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $target_table ?? $this->current_module ?? 'default_table';

        // Trigger bulk detection for pattern recognition
        $this->performance()->detectBulkOperation(1);

        $result = $this->fastSingleInsert($table, $data);

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("Single insert completed", DebugCategory::SQL, DebugLevel::BASIC, [
                'table' => $table,
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2),
                'insert_id' => $result,
                'success' => $result !== false,
                'operation' => 'insert'
            ]);

            // Advanced insert analysis
            $this->debug()->analyzeQuery("INSERT INTO $table", $data, $executionTime, [
                'table' => $table,
                'operation' => 'insert',
                'result_count' => $result ? 1 : 0,
                'column_count' => count($data),
                'bulk_detection_active' => $this->performance()->isBulkModeActive()
            ]);
        }

        return $result;
    }

    /**
     * Update existing record by ID with validation and error handling
     * 
     * High-performance UPDATE operation with QueryBuilder validation, automatic
     * column name verification, and intelligent prepared statement caching.
     * Supports partial record updates and maintains data integrity.
     * 
     * @param int $update_id Primary key of record to update
     * @param array $data Associative array of column => value pairs to update
     * @param string|null $target_table Target table name (defaults to current module)
     * @return bool True on successful update, false on failure
     * @throws InvalidArgumentException If table/column names fail validation or data is empty
     * @throws RuntimeException If database operation fails catastrophically
     * 
     * @example
     * // Update user profile
     * $success = $model->update(123, [
     *     'name' => 'John Doe',
     *     'email' => 'john@example.com',
     *     'updated_at' => date('Y-m-d H:i:s')
     * ]);
     * 
     * @example
     * // Update product price
     * $updated = $model->update(456, ['price' => 99.99], 'products');
     */
    public function update(int $update_id, array $data, ?string $target_table = null): bool
    {
        if (empty($data)) {
            return false;
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);
        $columns = array_keys($data);

        // Validate using QueryBuilder bulk validation
        QueryBuilder::validateIdentifiersBulk($columns, 'column');

        $sql = $this->getOptimizedSQL('simple_update', [
            'table' => $table,
            'columns' => $columns
        ]);

        $data['update_id'] = $update_id;

        $this->debugLog("UPDATE query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'update_id' => $update_id,
            'columns' => $columns,
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $result = $this->executeStatement($stmt, $data);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("UPDATE query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'update_id' => $update_id,
                    'columns' => $columns,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'success' => $result,
                    'operation' => 'update'
                ]);

                // Check for indexed columns being updated
                $indexedColumnsUpdated = ModelDebugHelper::getIndexedColumnsFromUpdate($table, $columns);

                $this->debug()->analyzeQuery($sql, $data, $executionTime, [
                    'table' => $table,
                    'operation' => 'update',
                    'result_count' => $result ? 1 : 0,
                    'columns_updated' => $columns,
                    'indexed_columns_updated' => $indexedColumnsUpdated,
                    'update_id' => $update_id
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("UPDATE query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'update_id' => $update_id
            ]);
            throw new RuntimeException("Failed to execute update query: " . $e->getMessage());
        }
    }

    /**
     * Update records based on custom column condition
     * 
     * Flexible update method that modifies all records matching a specific column
     * condition. Includes comprehensive validation, bulk update detection, and
     * database-agnostic SQL generation for cross-platform compatibility.
     * 
     * @param string $column Column name for WHERE condition (validated)
     * @param mixed $column_value Value that must match for update targeting
     * @param array $data Associative array of column => value pairs to update
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @return bool True if update succeeded, false on failure
     * @throws InvalidArgumentException If validation fails or data is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Update all users in a city
     * $updated = $model->update_where('city', 'Toronto', [
     *     'timezone' => 'America/Toronto',
     *     'updated_at' => date('Y-m-d H:i:s')
     * ], 'users');
     * 
     * @example
     * // Deactivate products in category
     * $model->update_where('category_id', 5, ['status' => 'inactive'], 'products');
     */
    public function update_where(string $column, $column_value, array $data, ?string $target_tbl = null): bool
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Update data array cannot be empty');
        }

        $this->connect();
        $startTime = microtime(true);

        $table = $this->getTableName($target_tbl);

        // Validate column names using QueryBuilder
        QueryBuilder::validateColumnName($column);
        $updateColumns = array_keys($data);
        QueryBuilder::validateIdentifiersBulk($updateColumns, 'column');

        // Build UPDATE SQL with proper escaping
        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedWhereColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $setClauses = [];
        foreach ($updateColumns as $updateColumn) {
            $escaped = QueryBuilder::escapeIdentifier($updateColumn, $this->dbType);
            $setClauses[] = "$escaped = :$updateColumn";
        }

        $setClause = implode(', ', $setClauses);
        $sql = "UPDATE $escapedTable SET $setClause WHERE $escapedWhereColumn = :where_value";

        $this->debugLog("UPDATE query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $escapedWhereColumn,
            'sql' => $sql
        ]);

        // Prepare parameter array
        $params = $data;
        $params['where_value'] = $column_value;

        /*
        if ($this->debug) {
            $this->debugQuery($sql, $params);
        }
            */

        try {
            $stmt = $this->getPreparedStatement($sql);
            $result = $this->executeStatement($stmt, $params);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("UPDATE query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $escapedWhereColumn,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'success' => $result,
                    'operation' => 'update'
                ]);

                // Check for indexed columns being updated
                $indexedColumnsUpdated = ModelDebugHelper::getIndexedColumnsFromUpdate($table, [$escapedWhereColumn]);

                $this->debug()->analyzeQuery($sql, $data, $executionTime, [
                    'table' => $table,
                    'operation' => 'update',
                    'result_count' => $result ? 1 : 0,
                    'columns_updated' => $escapedWhereColumn,
                    'indexed_columns_updated' => $indexedColumnsUpdated
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("UPDATE query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute update_where query: " . $e->getMessage());
        }
    }

    /**
     * Delete record by ID with safety validation and error handling
     * 
     * Secure DELETE operation targeting specific record by primary key.
     * Includes QueryBuilder validation and comprehensive error handling
     * to prevent accidental data loss.
     * 
     * @param int $id Primary key of record to delete
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @return bool True on successful deletion, false on failure
     * @throws InvalidArgumentException If table name fails validation
     * @throws RuntimeException If database operation fails catastrophically
     * 
     * @example
     * // Delete user account
     * $deleted = $model->delete(123);
     * if ($deleted) {
     *     echo "User account successfully removed";
     * }
     * 
     * @example
     * // Delete from specific table
     * $model->delete(456, 'products');
     */
    public function delete(int $id, ?string $target_tbl = null): bool
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_tbl);

        $sql = $this->getOptimizedSQL('simple_delete', [
            'table' => $table
        ]);

        $this->debugLog("DELETE query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'delete_id' => $id,
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $result = $this->executeStatement($stmt, ['id' => $id]);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("DELETE query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'delete_id' => $id,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'success' => $result,
                    'operation' => 'delete'
                ]);

                // Safety analysis
                $this->debug()->analyzeQuery($sql, ['id' => $id], $executionTime, [
                    'table' => $table,
                    'operation' => 'delete',
                    'result_count' => $result ? 1 : 0,
                    'has_where_clause' => true,
                    'delete_by_id' => true,
                    'safety_level' => 'safe'
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("DELETE query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'delete_id' => $id
            ]);
            throw new RuntimeException("Failed to execute delete query: " . $e->getMessage());
        }
    }

    /**
     * Insert or update record based on unique column (UPSERT operation)
     * 
     * High-performance UPSERT implementation using database-specific syntax for optimal
     * performance. Automatically determines whether to INSERT or UPDATE based on the
     * existence of a record with the specified unique column value.
     * 
     * @param array $data Associative array of column => value pairs
     * @param string $unique_column Column name to check for uniqueness (validated)
     * @param string|null $target_table Target table name (defaults to current module)
     * @return int ID of inserted or updated record
     * @throws InvalidArgumentException If data validation fails or unique_column is invalid
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Insert new user or update existing by email
     * $userId = $model->upsert([
     *     'email' => 'user@example.com',
     *     'name' => 'John Doe',
     *     'updated_at' => date('Y-m-d H:i:s')
     * ], 'email', 'users');
     * 
     * @example
     * // Product catalog sync with SKU as unique identifier
     * $productId = $model->upsert([
     *     'sku' => 'ABC-123',
     *     'name' => 'Widget Pro',
     *     'price' => 29.99
     * ], 'sku', 'products');
     */
    public function upsert(array $data, string $unique_column, ?string $target_table = null): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Data array cannot be empty");
        }

        if (!isset($data[$unique_column])) {
            throw new InvalidArgumentException("Data must contain the unique column: $unique_column");
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($unique_column);

        $unique_value = $data[$unique_column];

        $this->debugLog("UPSERT operation initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'unique_column' => $unique_column,
            'data_fields' => array_keys($data),
            'operation' => 'upsert'
        ]);

        try {
            // Check if record exists
            $existingRecord = $this->get_one_where($unique_column, $unique_value, $table);

            if ($existingRecord) {
                // Update existing record
                $this->debugLog("UPSERT: Updating existing record", DebugCategory::SQL, DebugLevel::DETAILED, [
                    'existing_id' => $existingRecord->id,
                    'unique_column' => $unique_column,
                    'unique_value' => $unique_value
                ]);

                $success = $this->update($existingRecord->id, $data, $table);
                $recordId = $success ? $existingRecord->id : 0;
            } else {
                // Insert new record
                $this->debugLog("UPSERT: Inserting new record", DebugCategory::SQL, DebugLevel::DETAILED, [
                    'unique_column' => $unique_column,
                    'unique_value' => $unique_value
                ]);

                $recordId = $this->insert($data, $table);
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("UPSERT operation completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'unique_column' => $unique_column,
                    'record_id' => $recordId,
                    'operation_type' => $existingRecord ? 'update' : 'insert',
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'upsert'
                ]);

                $this->debug()->analyzeBulkOperation('upsert', 1, [
                    'table' => $table,
                    'operation_type' => $existingRecord ? 'update' : 'insert',
                    'execution_time' => $executionTime,
                    'unique_column' => $unique_column
                ]);
            }

            return $recordId;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("UPSERT operation failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'unique_column' => $unique_column
            ]);
            throw new RuntimeException("Failed to execute upsert operation: " . $e->getMessage());
        }
    }
}
