<?php

/**
 * Trait BatchAndAtomicTrait
 *
 * Manages bulk operations (insert/update/delete batches) and atomic/expression-based updates for concurrency safety.
 * This trait is optimized for high-volume and thread-safe mutations.
 */
trait BatchAndAtomicTrait {
    /**
     * High-performance bulk insert with automatic optimization and smart chunking
     * 
     * Advanced bulk insertion method with comprehensive optimization features:
     * - Automatic chunk size calculation based on record complexity and system resources
     * - Database-specific variable limit handling (SQLite 999-variable limit)
     * - Adaptive performance monitoring and learning
     * - Memory-aware processing to prevent system overload
     * - Transaction safety with automatic rollback on failure
     * 
     * Optimization features:
     * - Auto-detects optimal chunk sizes for your table structure
     * - Respects SQLite variable limits automatically
     * - Tracks and learns from performance patterns
     * - Enables database-specific bulk optimizations
     * 
     * @param string $table Target table name (validated for safety)
     * @param array $records Array of associative arrays (column => value pairs)
     * @param int|null $chunkSize Optional chunk size override (auto-calculated if null)
     * @return int Number of records successfully inserted
     * @throws InvalidArgumentException If table/column validation fails or records are empty
     * @throws RuntimeException If bulk operation fails
     * 
     * @example
     * // Bulk insert with auto-optimization
     * $inserted = $model->insert_batch('users', [
     *     ['name' => 'John', 'email' => 'john@example.com'],
     *     ['name' => 'Jane', 'email' => 'jane@example.com'],
     *     // ... thousands more records
     * ]);
     * 
     * @example
     * // With custom chunk size
     * $inserted = $model->insert_batch('products', $productData, 500);
     * 
     * @example
     * // Large dataset processing
     * $model->performance()->enablePerformanceMode(); // Enable all optimizations
     * $inserted = $model->insert_batch('analytics_events', $largeDataset);
     */
    public function insert_batch(string $table, array $records, int|null $chunkSize = null): int
    {
        if (empty($records)) {
            return 0;
        }

        $this->connect();
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $this->debugLog("Bulk insert started", DebugCategory::BULK, DebugLevel::BASIC, [
            'table' => $table,
            'record_count' => count($records),
            'requested_chunk_size' => $chunkSize,
            'start_memory' => $startMemory
        ]);

        // Simple bulk detection - no complex learning
        $this->performance()->detectBulkOperation(count($records));

        // Calculate optimal chunk size based on database limits
        if ($chunkSize === null) {
            $chunkSize = $this->performance()->calculateOptimalChunkSize($records[0]);

            $this->debugLog("Chunk size auto-calculated", DebugCategory::BULK, DebugLevel::DETAILED, [
                'calculated_chunk_size' => $chunkSize,
                'field_count' => count($records[0]),
                'record_complexity' => strlen(serialize($records[0]))
            ]);
        }

        $totalInserted = $this->ultraFastBulkInsert($table, $records, $chunkSize);

        $executionTime = microtime(true) - $startTime;
        $endMemory = memory_get_usage(true);
        $recordsPerSecond = $executionTime > 0 ? round(count($records) / $executionTime) : 0;

        // Comprehensive operation logging
        $this->debugLog("Bulk insert completed", DebugCategory::BULK, DebugLevel::BASIC, [
            'operation' => 'insert_batch',
            'table' => $table,
            'record_count' => count($records),
            'records_inserted' => $totalInserted,
            'chunk_size' => $chunkSize,
            'execution_time' => $executionTime,
            'execution_time_ms' => round($executionTime * 1000, 2),
            'records_per_second' => $recordsPerSecond,
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => memory_get_peak_usage(true),
            'bulk_mode_active' => $this->performance()->isBulkModeActive(),
            'database_type' => $this->dbType,
            'success_rate' => count($records) > 0 ? ($totalInserted / count($records)) * 100 : 0
        ]);

        // Advanced bulk operation analysis
        if ($this->debug) {
            $this->debug()->analyzeBulkOperation('insert_batch', count($records), [
                'table' => $table,
                'chunk_size' => $chunkSize,
                'execution_time' => $executionTime,
                'records_per_second' => $recordsPerSecond,
                'bulk_mode_active' => $this->performance()->isBulkModeActive(),
                'optimizations' => ModelDebugHelper::getAppliedOptimizations($this),
                'database_type' => $this->dbType,
                'memory_usage' => $endMemory - $startMemory,
                'field_count' => count($records[0])
            ]);
        }

        return $totalInserted;
    }  

    /**
     * Update multiple records in batch with intelligent strategy selection
     * 
     * High-performance batch update method that automatically selects the optimal
     * strategy based on dataset size and database type. Delegates complex logic
     * to BatchUpdateFactory for clean separation of concerns.
     * 
     * **CASE Statement Strategy** (Default for moderate datasets):
     * - Uses SQL CASE statements for updates up to 2,000 records
     * - Fast for moderate-sized batches with good memory usage
     * - Single query execution with parameter binding
     * 
     * **Temp Table Strategy** (Massive datasets):
     * - Uses temporary tables with JOIN/UPDATE for 2,000+ records
     * - Dramatically faster for massive datasets (10x-50x improvement)
     * - Optimized for PostgreSQL with synchronous_commit=off
     * - Automatic cleanup and transaction safety
     * 
     * @param string $table Table name to update (validated)
     * @param string $identifierField Column name for record identification (validated)
     * @param array $updates Array of updates: [['id' => 1, 'data' => ['name' => 'New Name']], ...]
     * @param int|null $chunkSize Optional chunk size override (auto-calculated if null)
     * @return int Total number of records successfully updated
     * @throws InvalidArgumentException If validation fails or updates is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Moderate batch update (uses CASE statements)
     * $updates = [
     *     ['id' => 1, 'data' => ['name' => 'John Doe', 'email' => 'john@example.com']],
     *     ['id' => 2, 'data' => ['name' => 'Jane Smith', 'email' => 'jane@example.com']]
     * ];
     * $updated = $model->update_batch('users', 'id', $updates);
     * 
     * @example
     * // Massive batch update (automatically uses temp table strategy)
     * $massiveUpdates = []; // 5,000+ records
     * foreach ($csvData as $row) {
     *     $massiveUpdates[] = ['id' => $row['id'], 'data' => ['price' => $row['new_price']]];
     * }
     * $model->update_batch('products', 'id', $massiveUpdates); // Uses temp table for performance
     */
    public function update_batch(string $table, string $identifierField, array $updates, int|null $chunkSize = null): int
    {
        if (empty($updates)) {
            $this->debugLog("Empty updates array provided", DebugCategory::BULK, DebugLevel::BASIC);
            return 0;
        }

        $this->connect();

        require_once dirname(__DIR__) . '/factories/BatchUpdate.php';

        // Create factory instance and delegate the complex logic
        $factory = new BatchUpdateFactory($this);

        // Execute batch update using intelligent strategy selection
        return $factory->executeBatchUpdate($table, $identifierField, $updates, $chunkSize);
    }

    /**
     * Delete multiple records in batch using IN clause with optimized chunking
     * 
     * High-performance batch deletion method using IN clauses with automatic
     * chunking for database compatibility. Supports large deletion sets while
     * respecting database variable limits and maintaining transaction safety.
     * 
     * @param string $table Table name to delete from (validated)
     * @param string $identifierField Column name for WHERE condition (validated)  
     * @param array $identifiers Array of values to match for deletion
     * @param int|null $chunkSize Optional chunk size override (auto-calculated if null)
     * @return int Total number of records successfully deleted
     * @throws InvalidArgumentException If validation fails or identifiers is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Delete multiple users by ID
     * $deletedCount = $model->delete_batch('users', 'id', [1, 2, 3, 4, 5]);
     * 
     * @example
     * // Delete products by category with custom chunk size
     * $categories = [10, 20, 30, 40, 50];
     * $deleted = $model->delete_batch('products', 'category_id', $categories, 100);
     * 
     * @example
     * // Delete sessions by status
     * $expiredSessions = ['expired', 'invalid', 'terminated'];
     * $model->delete_batch('sessions', 'status', $expiredSessions);
     */
    public function delete_batch(string $table, string $identifierField, array $identifiers, int|null $chunkSize = null): int
    {
        if (empty($identifiers)) {
            return 0;
        }

        $this->connect();
        $startTime = microtime(true);

        // Validate table and column names
        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($identifierField);

        // Auto-detect bulk operation for optimization
        $this->performance()->detectBulkOperation(count($identifiers));

        $this->debugLog("Batch delete started", DebugCategory::BULK, DebugLevel::BASIC, [
            'table' => $table,
            'identifier_field' => $identifierField,
            'identifier_count' => count($identifiers),
            'chunk_size' => $chunkSize,
            'bulk_mode_active' => $this->performance()->isBulkModeActive()
        ]);

        // Calculate optimal chunk size if not provided
        if ($chunkSize === null) {
            $chunkSize = match ($this->dbType) {
                'sqlite' => min(999, 500),
                'mysql' => 1000,
                'postgresql' => 1000,
                default => 500
            };

            $this->debugLog("Chunk size auto-calculated", DebugCategory::BULK, DebugLevel::DETAILED, [
                'calculated_chunk_size' => $chunkSize,
                'database_type' => $this->dbType,
                'identifier_count' => count($identifiers)
            ]);
        }

        try {
            $totalDeleted = 0;
            $chunks = array_chunk($identifiers, $chunkSize);

            $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
            $escapedField = QueryBuilder::escapeIdentifier($identifierField, $this->dbType);

            foreach ($chunks as $chunkIndex => $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sql = "DELETE FROM $escapedTable WHERE $escapedField IN ($placeholders)";

                $stmt = $this->getPreparedStatement($sql);

                foreach ($chunk as $index => $value) {
                    $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
                }

                $stmt->execute();
                $deletedInChunk = $stmt->rowCount();
                $totalDeleted += $deletedInChunk;

                if ($this->debug && ($chunkIndex % 10 === 0 || $chunkIndex === count($chunks) - 1)) {
                    $this->debugLog("Batch delete progress", DebugCategory::BULK, DebugLevel::DETAILED, [
                        'chunk' => $chunkIndex + 1,
                        'total_chunks' => count($chunks),
                        'chunk_size' => count($chunk),
                        'deleted_in_chunk' => $deletedInChunk,
                        'total_deleted' => $totalDeleted
                    ]);
                }
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Batch delete completed", DebugCategory::BULK, DebugLevel::BASIC, [
                    'table' => $table,
                    'identifier_field' => $identifierField,
                    'identifiers_requested' => count($identifiers),
                    'records_deleted' => $totalDeleted,
                    'chunks_processed' => count($chunks),
                    'chunk_size' => $chunkSize,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'deletions_per_second' => $executionTime > 0 ? round($totalDeleted / $executionTime) : 0,
                    'operation' => 'delete_batch'
                ]);

                // Bulk operation analysis
                $this->debug()->analyzeBulkOperation('delete_batch', count($identifiers), [
                    'table' => $table,
                    'chunk_size' => $chunkSize,
                    'execution_time' => $executionTime,
                    'records_per_second' => $executionTime > 0 ? round($totalDeleted / $executionTime) : 0,
                    'database_type' => $this->dbType,
                    'total_deleted' => $totalDeleted,
                    'success_rate' => count($identifiers) > 0 ? ($totalDeleted / count($identifiers)) * 100 : 0
                ]);
            }

            return $totalDeleted;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Batch delete failed", DebugCategory::BULK, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'identifiers_count' => count($identifiers)
            ]);
            throw new RuntimeException("Failed to execute delete_batch operation: " . $e->getMessage());
        }
    }

    /**
     * Update record with expression support for atomic operations
     * 
     * Enables atomic updates using SQL expressions instead of requiring
     * separate SELECT + UPDATE operations. Auto-bulk detection is disabled
     * for expression operations to maintain predictable behavior.
     * 
     * @param int $update_id Record ID to update
     * @param array $data Standard column => value pairs for parameter binding
     * @param array $expressions Column => expression pairs for literal SQL
     * @param string|null $target_table Target table name
     * @param array $allowed_columns Columns that can be referenced in expressions
     * @return bool Success status
     * 
     * @example
     * // Atomic counter increment
     * $model->update_with_expressions(123, [], ['counter' => 'counter + 1'], 'stats', ['counter']);
     * 
     * @example  
     * // Mixed parameter and expression update
     * $model->update_with_expressions(123, 
     *     ['name' => 'New Name'],                    // Parameter binding
     *     ['counter' => 'counter + 1'],              // Expression  
     *     'users',
     *     ['counter']                                // Allow counter in expressions
     * );
     */
    public function update_with_expressions(
        int $update_id,
        array $data = [],
        array $expressions = [],
        ?string $target_table = null,
        array $allowed_columns = []
    ): bool {
        if (empty($data) && empty($expressions)) {
            throw new InvalidArgumentException("Either data or expressions must be provided");
        }

        $this->connect();
        $startTime = microtime(true);

        // Disable auto-bulk detection for expression operations
        $this->performance()->disableAutoBulkForCurrentOperation();

        $table = $this->getTableName($target_table);

        // Build query using expression-enabled builder
        $sql = $this->getOptimizedSQL('update_with_expressions', [
            'table' => $table,
            'columns' => array_keys($data),
            'expressions' => $expressions,
            'allowed_columns' => $allowed_columns
        ]);

        $this->debugLog("Update with expressions started", DebugCategory::SQL, DebugLevel::BASIC, [
            'table' => $table,
            'update_id' => $update_id,
            'data_columns' => array_keys($data),
            'expression_columns' => array_keys($expressions),
            'auto_bulk_disabled' => true
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);

            // Bind regular parameters
            $params = array_merge($data, ['update_id' => $update_id]);
            $this->executeStatement($stmt, $params);

            $executionTime = microtime(true) - $startTime;
            $success = $stmt->rowCount() > 0;

            $this->debugLog("Update with expressions completed", DebugCategory::SQL, DebugLevel::BASIC, [
                'success' => $success,
                'rows_affected' => $stmt->rowCount(),
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);

            return $success;
        } catch (PDOException $e) {
            $executionTime = microtime(true) - $startTime;
            $this->lastError = $e->getMessage();
            $this->debugLog("Update with expressions failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);
            return false;
        } finally {
            // Reset the skip flag after operation
            $this->performance()->resetAutoBulkSkipFlag();
        }
    }

    /**
     * Insert record with expression support for computed values
     * 
     * Enables inserts with computed values using SQL expressions. Auto-bulk
     * detection is disabled for expression operations to maintain predictable behavior.
     * 
     * @param array $data Standard column => value pairs for parameter binding
     * @param array $expressions Column => expression pairs for literal SQL
     * @param string|null $target_table Target table name
     * @param array $allowed_columns Columns that can be referenced in expressions
     * @return int|false New record ID or false on failure
     * 
     * @example
     * // Insert with computed timestamp
     * $id = $model->insert_with_expressions(
     *     ['name' => 'Test User'],           // Parameter binding
     *     ['created_at' => 'NOW()'],         // Expression
     *     'users'
     * );
     */
    public function insert_with_expressions(
        array $data = [],
        array $expressions = [],
        ?string $target_table = null,
        array $allowed_columns = []
    ): int|false {
        if (empty($data) && empty($expressions)) {
            throw new InvalidArgumentException("Either data or expressions must be provided");
        }

        $this->connect();
        $startTime = microtime(true);

        // Disable auto-bulk detection for expression operations
        $this->performance()->disableAutoBulkForCurrentOperation();

        $table = $this->getTableName($target_table);

        // Build query using expression-enabled builder
        $sql = $this->getOptimizedSQL('insert_with_expressions', [
            'table' => $table,
            'columns' => array_keys($data),
            'expressions' => $expressions,
            'allowed_columns' => $allowed_columns
        ]);

        $this->debugLog("Insert with expressions started", DebugCategory::SQL, DebugLevel::BASIC, [
            'table' => $table,
            'data_columns' => array_keys($data),
            'expression_columns' => array_keys($expressions),
            'auto_bulk_disabled' => true
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $data);

            $executionTime = microtime(true) - $startTime;
            $insertId = (int) $this->dbh->lastInsertId();

            $this->debugLog("Insert with expressions completed", DebugCategory::SQL, DebugLevel::BASIC, [
                'insert_id' => $insertId,
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);

            return $insertId;
        } catch (PDOException $e) {
            $executionTime = microtime(true) - $startTime;
            $this->lastError = $e->getMessage();
            $this->debugLog("Insert with expressions failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);
            return false;
        } finally {
            // Reset the skip flag after operation
            $this->performance()->resetAutoBulkSkipFlag();
        }
    }

    /**
     * Update multiple records matching a condition with expression support
     * 
     * Updates all records where a column matches a value, with support for
     * both parameter binding and SQL expressions. Auto-bulk detection is disabled.
     * 
     * @param string $column Column name for WHERE condition
     * @param mixed $column_value Value to match in WHERE condition
     * @param array $data Standard column => value pairs for parameter binding
     * @param array $expressions Column => expression pairs for literal SQL
     * @param string|null $target_table Target table name
     * @param array $allowed_columns Columns that can be referenced in expressions
     * @return bool Success status
     * 
     * @example
     * // Update all products in a category with price increase
     * $model->update_where_with_expressions('category_id', 5, [], [
     *     'price' => 'price * 1.15',       // 15% price increase
     *     'last_updated' => 'NOW()'
     * ], 'products', ['price']);
     */
    public function update_where_with_expressions(
        string $column,
        $column_value,
        array $data = [],
        array $expressions = [],
        ?string $target_table = null,
        array $allowed_columns = []
    ): bool {
        if (empty($data) && empty($expressions)) {
            throw new InvalidArgumentException("Either data or expressions must be provided");
        }

        $this->connect();
        $startTime = microtime(true);

        // Disable auto-bulk detection for expression operations
        $this->performance()->disableAutoBulkForCurrentOperation();

        $table = $this->getTableName($target_table);

        // Build query using expression-enabled builder (WHERE column validated in QueryBuilder)
        $sql = $this->getOptimizedSQL('update_where_with_expressions', [
            'table' => $table,
            'where_column' => $column,
            'columns' => array_keys($data),
            'expressions' => $expressions,
            'allowed_columns' => $allowed_columns
        ]);

        $this->debugLog("Update WHERE with expressions started", DebugCategory::SQL, DebugLevel::BASIC, [
            'table' => $table,
            'where_column' => $column,
            'where_value' => $column_value,
            'data_columns' => array_keys($data),
            'expression_columns' => array_keys($expressions),
            'auto_bulk_disabled' => true
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);

            // Bind parameters (WHERE value + data values)
            $params = array_merge($data, ['where_value' => $column_value]);
            $this->executeStatement($stmt, $params);

            $executionTime = microtime(true) - $startTime;
            $rowsAffected = $stmt->rowCount();
            $success = $rowsAffected > 0;

            $this->debugLog("Update WHERE with expressions completed", DebugCategory::SQL, DebugLevel::BASIC, [
                'success' => $success,
                'rows_affected' => $rowsAffected,
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);

            return $success;
        } catch (PDOException $e) {
            $executionTime = microtime(true) - $startTime;
            $this->lastError = $e->getMessage();
            $this->debugLog("Update WHERE with expressions failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);
            return false;
        } finally {
            // Reset the skip flag after operation
            $this->performance()->resetAutoBulkSkipFlag();
        }
    }

    /**
     * Increment a numeric column atomically
     * 
     * Convenience method for the common pattern of incrementing counters,
     * view counts, etc. without race conditions.
     * 
     * @param int $id Record ID to update
     * @param string $column Column name to increment
     * @param int|float $amount Amount to increment by (default: 1)
     * @param string|null $target_table Target table name
     * @return bool Success status
     * 
     * @example
     * // Increment view count by 1
     * $model->increment_column(123, 'view_count', 1, 'posts');
     * 
     * @example
     * // Increase price by 5.50
     * $model->increment_column(456, 'price', 5.50, 'products');
     */
    public function increment_column(
        int $id,
        string $column,
        int|float $amount = 1,
        ?string $target_table = null
    ): bool {
        // Validate amount is numeric and positive
        if (!is_numeric($amount) || $amount <= 0) {
            throw new InvalidArgumentException("Increment amount must be a positive number");
        }

        return $this->update_with_expressions($id, [], [
            $column => "$column + $amount"
        ], $target_table, [$column]);
    }

    /**
     * Decrement a numeric column atomically
     * 
     * Convenience method for decreasing counters, quantities, etc.
     * 
     * @param int $id Record ID to update
     * @param string $column Column name to decrement
     * @param int|float $amount Amount to decrement by (default: 1)
     * @param string|null $target_table Target table name
     * @return bool Success status
     * 
     * @example
     * // Decrease stock quantity by 1
     * $model->decrement_column(123, 'stock_quantity', 1, 'products');
     */
    public function decrement_column(
        int $id,
        string $column,
        int|float $amount = 1,
        ?string $target_table = null
    ): bool {
        // Validate amount is numeric and positive
        if (!is_numeric($amount) || $amount <= 0) {
            throw new InvalidArgumentException("Decrement amount must be a positive number");
        }

        return $this->update_with_expressions($id, [], [
            $column => "$column - $amount"
        ], $target_table, [$column]);
    }

    /**
     * Update timestamp column to current database time
     * 
     * Convenience method for updating timestamp fields using database-specific
     * current time functions.
     * 
     * @param int $id Record ID to update
     * @param string $column Timestamp column name (default: 'updated_at')
     * @param string|null $target_table Target table name
     * @return bool Success status
     * 
     * @example
     * // Update last_login timestamp
     * $model->touch_timestamp(123, 'last_login', 'users');
     */
    public function touch_timestamp(
        int $id,
        string $column = 'updated_at',
        ?string $target_table = null
    ): bool {
        return $this->update_with_expressions($id, [], [
            $column => 'NOW()'  // Will be translated per database type
        ], $target_table);
    }

    /**
     * Toggle boolean column value (0 to 1, 1 to 0)
     * 
     * Atomic toggle operation for boolean fields using database-level calculation
     * to prevent race conditions. Commonly used for status flags, active/inactive
     * toggles, and feature switches.
     * 
     * @param int $id Record ID to update
     * @param string $column Boolean column name to toggle (validated)
     * @param string|null $target_table Target table name (defaults to current module)
     * @return bool True if toggle succeeded, false otherwise
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Toggle user active status
     * $model->toggle_column(123, 'is_active', 'users');
     * 
     * @example
     * // Toggle product featured status
     * $model->toggle_column(456, 'is_featured', 'products');
     * 
     * @example
     * // Toggle notification setting
     * $model->toggle_column(789, 'email_notifications', 'user_settings');
     */
    public function toggle_column(int $id, string $column, ?string $target_table = null): bool
    {
        $this->connect();
        $startTime = microtime(true);

        $this->debugLog("Toggle column operation initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'id' => $id,
            'column' => $column,
            'table' => $target_table ?: $this->current_module,
            'operation' => 'toggle_column'
        ]);

        try {
            // Use atomic expression update to toggle the boolean value
            $success = $this->update_with_expressions($id, [], [
                $column => "CASE WHEN $column = 1 THEN 0 ELSE 1 END"
            ], $target_table, [$column]);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Toggle column operation completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'id' => $id,
                    'column' => $column,
                    'success' => $success,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'toggle_column'
                ]);
            }

            return $success;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Toggle column operation failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'id' => $id,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to toggle column: " . $e->getMessage());
        }
    }
}
