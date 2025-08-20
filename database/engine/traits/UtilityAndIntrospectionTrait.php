<?php

/**
 * Trait UtilityAndIntrospectionTrait
 *
 * Offers utility functions, debugging tools, performance management, factories, raw SQL execution, and database introspection.
 * This trait provides supportive meta-operations for development, maintenance, and advanced database interactions.
 */
trait UtilityAndIntrospectionTrait {
    /**
     * Execute custom SQL query with optional result type specification
     * 
     * Direct SQL execution for complex queries that don't fit standard CRUD patterns.
     * Supports multiple return formats and includes comprehensive error handling.
     * Use with caution - bypasses validation and optimization layers.
     * 
     * @param string $sql Raw SQL query to execute (user responsibility for safety)
     * @param string|null $return_type Result format: 'object', 'array', or null for no results
     * @return mixed Query results in specified format, or null for non-SELECT queries
     * @throws RuntimeException If query execution fails
     * 
     * @example
     * // Complex reporting query
     * $results = $model->query("
     *     SELECT u.name, COUNT(o.id) as order_count 
     *     FROM users u 
     *     LEFT JOIN orders o ON u.id = o.user_id 
     *     GROUP BY u.id
     * ", 'array');
     * 
     * @example
     * // DDL operation
     * $model->query("CREATE INDEX idx_users_email ON users (email)");
     */
    public function query(string $sql, ?string $return_type = null): mixed
    {
        $this->connect();
        $startTime = microtime(true);

        $this->debugLog("Raw query execution", DebugCategory::SQL, DebugLevel::DETAILED, [
            'sql' => $sql,
            'return_type' => $return_type,
            'sql_length' => strlen($sql)
        ]);

        try {
            $this->stmt = $this->dbh->prepare($sql);
            $this->stmt->execute();

            $results = null;
            if ($return_type === 'object') {
                $results = $this->stmt->fetchAll(PDO::FETCH_OBJ);
            } elseif ($return_type === 'array') {
                $results = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Raw query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'sql' => $sql,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'return_type' => $return_type,
                    'result_count' => is_array($results) ? count($results) : 0,
                    'operation' => 'raw_query'
                ]);

                // Basic query analysis (limited since it's raw SQL)
                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'operation' => 'raw_query',
                    'return_type' => $return_type,
                    'result_count' => is_array($results) ? count($results) : 0,
                    'sql_length' => strlen($sql),
                    'query_type' => ModelDebugHelper::detectQueryType($sql)
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Raw query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'sql' => $sql
            ]);
            throw new RuntimeException("Query execution failed: " . $e->getMessage());
        }
    }

    /**
     * Execute parameterized SQL query with secure parameter binding
     * 
     * Safe SQL execution with PDO parameter binding to prevent SQL injection.
     * Supports named parameters and multiple result formats. Preferred method
     * for custom queries requiring user input.
     * 
     * @param string $sql SQL query with named placeholders (:param_name)
     * @param array $data Associative array of parameter values to bind
     * @param string|null $return_type Result format: 'object', 'array', or null for no results
     * @return array|object|null Query results in specified format, or null for non-SELECT queries
     * @throws RuntimeException If query execution fails
     * 
     * @example
     * // Safe parameterized query
     * $users = $model->query_bind(
     *     "SELECT * FROM users WHERE status = :status AND created_at > :date",
     *     ['status' => 'active', 'date' => '2024-01-01'],
     *     'object'
     * );
     * 
     * @example
     * // Update with parameters
     * $model->query_bind(
     *     "UPDATE users SET last_login = :now WHERE id = :user_id",
     *     ['now' => date('Y-m-d H:i:s'), 'user_id' => 123]
     * );
     */
    public function query_bind(string $sql, array $data, ?string $return_type = null): array|object|null
    {
        $this->connect();
        $startTime = microtime(true);

        $this->debugLog("Raw query bind execution", DebugCategory::SQL, DebugLevel::DETAILED, [
            'sql' => $sql,
            'data_keys' => array_keys($data),
            'return_type' => $return_type,
            'sql_length' => strlen($sql)
        ]);

        try {
            $this->stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($this->stmt, $data);

            $result = null;
            if ($return_type === 'object') {
                $result = $this->stmt->fetchAll(PDO::FETCH_OBJ);
            } elseif ($return_type === 'array') {
                $result = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Raw query bind executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'sql' => $sql,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'return_type' => $return_type,
                    'result_count' => is_array($result) ? count($result) : 0,
                    'operation' => 'raw_query'
                ]);

                // Basic query analysis (limited since it's raw SQL)
                $this->debug()->analyzeQuery($sql, $data, $executionTime, [
                    'operation' => 'raw_query',
                    'return_type' => $return_type,
                    'result_count' => is_array($result) ? count($result) : 0,
                    'sql_length' => strlen($sql),
                    'query_type' => ModelDebugHelper::detectQueryType($sql)
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Raw query bind failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'sql' => $sql
            ]);
            throw new RuntimeException("Query execution failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Execute SQL statement for development/administrative purposes only
     * 
     * Direct SQL execution method restricted to development environments for
     * database schema operations, data migration, and administrative tasks.
     * Provides safety controls to prevent misuse in production.
     * 
     * ⚠️ **SECURITY WARNING**: This method executes raw SQL without validation.
     * Use only in controlled environments with trusted input.
     * 
     * @param string $sql SQL statement to execute
     * @return mixed Query results if applicable, null for non-SELECT operations
     * @throws Exception If not in development environment
     * @throws RuntimeException If SQL execution fails
     * 
     * @example
     * // Schema operations (development only)
     * if (ENV === 'dev') {
     *     $model->exec("CREATE INDEX idx_users_email ON users (email)");
     *     $model->exec("ALTER TABLE products ADD COLUMN category_id INT");
     * }
     * 
     * @example
     * // Data migration (development only)
     * $model->exec("UPDATE users SET created_at = NOW() WHERE created_at IS NULL");
     */
    public function exec(string $sql): mixed
    {
        if (!defined('ENV') || ENV !== 'dev') {
            throw new Exception("This feature is disabled...");
        }

        $this->connect();
        $startTime = microtime(true);  // Add timing

        $this->debugLog("Raw SQL execution started", DebugCategory::SQL, DebugLevel::DETAILED, [
            'sql' => $sql,
            'sql_length' => strlen($sql),
            'environment' => 'development'
        ]);

        try {
            $this->stmt = $this->dbh->prepare($sql);
            $this->stmt->execute();

            $result = null;
            if (stripos(trim($sql), 'SELECT') === 0) {
                $result = $this->stmt->fetchAll(PDO::FETCH_OBJ);
            }

            // Add success logging
            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Raw SQL execution completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'sql_type' => ModelDebugHelper::detectQueryType($sql),
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'result_count' => is_array($result) ? count($result) : 0,
                    'operation' => 'exec'
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();

            // Add error logging
            $this->debugLog("Raw SQL execution failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'sql' => substr($sql, 0, 200) // Truncate for safety
            ]);

            throw new RuntimeException("Failed to execute SQL statement: " . $e->getMessage());
        }
    }

    /**
     * Check if a table exists in the database (cross-database compatible)
     * 
     * Database-agnostic table existence check that works across MySQL, SQLite,
     * and PostgreSQL. Uses appropriate system catalog queries for each database
     * type while maintaining consistent API and validation.
     * 
     * @param string $table_name Name of table to check for existence (validated)
     * @return bool True if table exists, false otherwise
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Check if users table exists before creating
     * if (!$model->table_exists('users')) {
     *     $model->query("CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(255))");
     * }
     * 
     * @example
     * // Conditional table operations
     * if ($model->table_exists('temp_data')) {
     *     $model->query("DROP TABLE temp_data");
     * }
     */
    public function table_exists(string $table_name): bool
    {
        $this->connect();
        QueryBuilder::validateTableName($table_name);

        try {
            switch ($this->dbType) {
                case 'mysql':
                    // FIXED: Use direct query with escaped table name
                    $escapedTableName = $this->dbh->quote($table_name);
                    $sql = "SHOW TABLES LIKE $escapedTableName";
                    $stmt = $this->dbh->query($sql);
                    return $stmt !== false && $stmt->fetch() !== false;

                case 'sqlite':
                    $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name = :table_name";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt, ['table_name' => $table_name]);
                    return $stmt->fetch() !== false;

                case 'postgresql':
                    $sql = "SELECT tablename FROM pg_tables WHERE tablename = :table_name";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt, ['table_name' => $table_name]);
                    return $stmt->fetch() !== false;

                default:
                    throw new RuntimeException("Unsupported database type: {$this->dbType}");
            }
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to check table existence: " . $e->getMessage());
        }
    }

    /**
     * Get all table names from the database (cross-database compatible)
     * 
     * Retrieves a list of all user tables in the database, excluding system
     * tables. Works consistently across MySQL, SQLite, and PostgreSQL with
     * appropriate filtering for each database type.
     * 
     * @return array Array of table names in the database
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // List all tables for backup
     * $tables = $model->get_all_tables();
     * foreach ($tables as $table) {
     *     echo "Backing up table: $table\n";
     * }
     * 
     * @example
     * // Check if specific tables exist
     * $tables = $model->get_all_tables();
     * $requiredTables = ['users', 'products', 'orders'];
     * $missing = array_diff($requiredTables, $tables);
     */
    public function get_all_tables(): array
    {
        $this->connect();

        try {
            switch ($this->dbType) {
                case 'mysql':
                    $sql = "SHOW TABLES";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt);
                    return $stmt->fetchAll(PDO::FETCH_COLUMN);

                case 'sqlite':
                    $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt);
                    return $stmt->fetchAll(PDO::FETCH_COLUMN);

                case 'postgresql':
                    $sql = "SELECT tablename FROM pg_tables WHERE schemaname = 'public'";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt);
                    return $stmt->fetchAll(PDO::FETCH_COLUMN);

                default:
                    throw new RuntimeException("Unsupported database type for get_all_tables: {$this->dbType}");
            }
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to retrieve table list: " . $e->getMessage());
        }
    }

    /**
     * Describe table structure with cross-database compatibility
     * 
     * Retrieves detailed information about table columns including names, types,
     * nullability, and default values. Provides consistent output format across
     * different database systems with optional column-names-only mode.
     * 
     * @param string $table Table name to describe (validated)
     * @param bool $column_names_only If true, return only column names array
     * @return array|false Column details array or column names, false on failure
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get full table structure
     * $structure = $model->describe_table('users');
     * foreach ($structure as $column) {
     *     echo "Column: {$column['Field']}, Type: {$column['Type']}\n";
     * }
     * 
     * @example
     * // Get just column names
     * $columns = $model->describe_table('products', true);
     * // Returns: ['id', 'name', 'price', 'category_id', 'created_at']
     */
    /**
     * Describe table structure with cross-database compatibility
     * 
     * Retrieves detailed information about table columns including names, types,
     * nullability, and default values. Provides consistent output format across
     * different database systems with optional column-names-only mode.
     * 
     * @param string $table Table name to describe (validated)
     * @param bool $column_names_only If true, return only column names array
     * @return array|false Column details array or column names, false on failure
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If database operation fails
     */
    public function describe_table(string $table, bool $column_names_only = false): array|false
    {
        $this->connect();
        QueryBuilder::validateTableName($table);

        // Use the fixed table_exists method
        if (!$this->table_exists($table)) {
            return false;
        }

        try {
            switch ($this->dbType) {
                case 'mysql':
                    // FIXED: Use direct query for MySQL DESCRIBE
                    $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
                    $sql = "DESCRIBE $escapedTable";
                    $stmt = $this->dbh->query($sql);

                    if ($stmt === false) {
                        return false;
                    }

                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if ($column_names_only) {
                        return array_column($columns, 'Field');
                    }
                    return $columns;

                case 'sqlite':
                    $sql = "PRAGMA table_info(" . QueryBuilder::escapeIdentifier($table, $this->dbType) . ")";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt);
                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // FIXED: Double-check that we got results (shouldn't happen now with table_exists check)
                    if (empty($columns)) {
                        return false;
                    }

                    if ($column_names_only) {
                        return array_column($columns, 'name');
                    }

                    // Convert SQLite format to MySQL-like format for consistency
                    $formatted = [];
                    foreach ($columns as $col) {
                        $formatted[] = [
                            'Field' => $col['name'],
                            'Type' => $col['type'],
                            'Null' => $col['notnull'] ? 'NO' : 'YES',
                            'Key' => $col['pk'] ? 'PRI' : '',
                            'Default' => $col['dflt_value'],
                            'Extra' => $col['pk'] ? 'auto_increment' : ''
                        ];
                    }
                    return $formatted;

                case 'postgresql':
                    $sql = "SELECT column_name, data_type, is_nullable, column_default 
                            FROM information_schema.columns 
                            WHERE table_name = :table_name 
                            ORDER BY ordinal_position";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt, ['table_name' => $table]);
                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // FIXED: Check for empty results
                    if (empty($columns)) {
                        return false;
                    }

                    if ($column_names_only) {
                        return array_column($columns, 'column_name');
                    }

                    // Convert PostgreSQL format to MySQL-like format for consistency
                    $formatted = [];
                    foreach ($columns as $col) {
                        $formatted[] = [
                            'Field' => $col['column_name'],
                            'Type' => $col['data_type'],
                            'Null' => $col['is_nullable'],
                            'Key' => '',
                            'Default' => $col['column_default'],
                            'Extra' => ''
                        ];
                    }
                    return $formatted;

                default:
                    throw new RuntimeException("Unsupported database type for describe_table: {$this->dbType}");
            }
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Detect the primary key column for a table
     * 
     * @param string $table Table name to inspect
     * @return string Primary key column name (defaults to 'id' if not found)
     */
    protected function getPrimaryKey(string $table): string
    {
        try {
            $dbType = $this->getDbType();

            switch ($dbType) {
                case 'mysql':
                    $sql = "SHOW KEYS FROM $table WHERE Key_name = 'PRIMARY'";
                    $result = $this->query($sql, 'object');
                    return $result ? $result->Column_name : 'id';

                case 'postgresql':
                case 'postgres':
                case 'pgsql':
                    $sql = "SELECT a.attname
                        FROM pg_index i
                        JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                        WHERE i.indrelid = '$table'::regclass AND i.indisprimary";
                    $result = $this->query($sql, 'object');
                    return $result ? $result->attname : 'id';

                case 'sqlite':
                    $sql = "PRAGMA table_info($table)";
                    $result = $this->query($sql, 'object');
                    foreach ($result as $row) {
                        if ($row->pk == 1) {
                            return $row->name;
                        }
                    }
                    return 'id';

                default:
                    return 'id';
            }
        } catch (Exception $e) {
            $this->debugLog("Failed to detect primary key, defaulting to 'id'", DebugCategory::SQL, DebugLevel::DETAILED, [
                'table' => $table,
                'error' => $e->getMessage(),
                'database_type' => $this->getDbType()
            ]);
            return 'id';
        }
    }

    /**
     * Resequence table IDs with cross-database compatibility and safety checks
     * 
     * Safely resequences auto-increment IDs in a table starting from 1, handling
     * all major database types with appropriate auto-increment reset procedures.
     * Includes comprehensive transaction safety and conflict resolution.
     * 
     * ⚠️ **WARNING**: This operation modifies primary keys and may affect referential
     * integrity. Use with extreme caution and always backup data first.
     * 
     * @param string $table_name Table to resequence (validated)
     * @return bool True if resequencing succeeded, false otherwise
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If operation fails or database is unsupported
     * 
     * @example
     * // Resequence user IDs after cleanup
     * $model->beginTransaction();
     * try {
     *     $success = $model->resequence_ids('users');
     *     if ($success) {
     *         $model->commit();
     *         echo "User IDs resequenced successfully";
     *     } else {
     *         $model->rollback();
     *     }
     * } catch (Exception $e) {
     *     $model->rollback();
     *     error_log("Resequencing failed: " . $e->getMessage());
     * }
     */
    public function resequence_ids(string $table_name): bool
    {
        $this->connect();

        // Validate table name using QueryBuilder
        QueryBuilder::validateTableName($table_name);

        // Check if table is empty first
        $count = $this->count($table_name);
        if ($count === 0) {
            // Reset auto-increment for empty table
            return $this->resetAutoIncrement($table_name);
        }

        try {
            // Begin transaction for safety
            $wasInTransaction = $this->dbh->inTransaction();
            if (!$wasInTransaction) {
                $this->dbh->beginTransaction();
            }

            // Get all rows ordered by current ID
            $escapedTable = QueryBuilder::escapeIdentifier($table_name, $this->dbType);
            $sql = "SELECT * FROM $escapedTable ORDER BY id";
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                if (!$wasInTransaction) {
                    $this->dbh->commit();
                }
                return $this->resetAutoIncrement($table_name);
            }

            // First pass: assign temporary negative IDs to avoid conflicts
            $counter = 1;
            foreach ($rows as $row) {
                $currentId = $row['id'];
                $tempId = -$counter;

                $updateSql = "UPDATE $escapedTable SET id = :temp_id WHERE id = :current_id";
                $updateStmt = $this->getPreparedStatement($updateSql);
                $this->executeStatement($updateStmt, [
                    'temp_id' => $tempId,
                    'current_id' => $currentId
                ]);
                $counter++;
            }

            // Second pass: assign new sequential IDs
            $counter = 1;
            foreach ($rows as $row) {
                $tempId = -$counter;
                $newId = $counter;

                $updateSql = "UPDATE $escapedTable SET id = :new_id WHERE id = :temp_id";
                $updateStmt = $this->getPreparedStatement($updateSql);
                $this->executeStatement($updateStmt, [
                    'new_id' => $newId,
                    'temp_id' => $tempId
                ]);
                $counter++;
            }

            // Reset auto-increment counter
            $this->resetAutoIncrement($table_name);

            // Commit transaction if we started it
            if (!$wasInTransaction) {
                $this->dbh->commit();
            }

            return true;
        } catch (Exception $e) {
            // Rollback if we started the transaction
            if (!$wasInTransaction && $this->dbh->inTransaction()) {
                $this->dbh->rollback();
            }

            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to resequence IDs for table '$table_name': " . $e->getMessage());
        }
    }

    /**
     * Reset auto-increment counter for table (database-specific implementation)
     * 
     * @param string $table_name Table name to reset auto-increment
     * @return bool Success status
     */
    private function resetAutoIncrement(string $table_name): bool
    {
        try {
            $escapedTable = QueryBuilder::escapeIdentifier($table_name, $this->dbType);

            switch ($this->dbType) {
                case 'mysql':
                    $sql = "ALTER TABLE $escapedTable AUTO_INCREMENT = 1";
                    break;

                case 'sqlite':
                    $sql = "UPDATE sqlite_sequence SET seq = 0 WHERE name = :table_name";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt, ['table_name' => $table_name]);
                    return true;

                case 'postgresql':
                    // PostgreSQL uses sequences, need to find the sequence name
                    $seqSql = "SELECT pg_get_serial_sequence(:table_name, 'id') as seq_name";
                    $seqStmt = $this->getPreparedStatement($seqSql);
                    $this->executeStatement($seqStmt, ['table_name' => $table_name]);
                    $seqResult = $seqStmt->fetch(PDO::FETCH_ASSOC);

                    if ($seqResult && $seqResult['seq_name']) {
                        $sql = "SELECT setval('{$seqResult['seq_name']}', 1, false)";
                    } else {
                        return true; // No sequence found, nothing to reset
                    }
                    break;

                default:
                    throw new RuntimeException("Unsupported database type for auto-increment reset: {$this->dbType}");
            }

            if (isset($sql)) {
                $stmt = $this->getPreparedStatement($sql);
                $this->executeStatement($stmt);
            }

            return true;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Access database backup operations through unified factory interface
     * 
     * Provides comprehensive backup and restore operations across MySQL, SQLite, and PostgreSQL
     * databases with intelligent strategy selection, secure shell command execution, and 
     * comprehensive fallback mechanisms. Features automatic capability detection and 
     * enhanced debug integration.
     * 
     * @return DatabaseBackupFactory Backup operations interface
     * 
     * @example
     * // Basic database backup
     * $result = $model->backup()->createBackup('/path/to/backup.sql');
     * 
     * @example
     * // Advanced backup with options
     * $result = $model->backup()->createBackup('/path/to/backup.sql', [
     *     'compress' => true,
     *     'include_schema' => true,
     *     'timeout' => 1800
     * ]);
     * 
     * @example
     * // Test backup capabilities
     * $capabilities = $model->backup()->testCapabilities();
     * 
     * @example
     * // Restore from backup
     * $result = $model->backup()->restoreBackup('/path/to/backup.sql');
     * 
     * @example
     * // Get backup estimates
     * $estimate = $model->backup()->estimateBackup();
     * echo "Estimated backup size: " . $estimate['estimated_size_bytes'] . " bytes";
     * echo "Estimated duration: " . $estimate['estimated_duration_seconds'] . " seconds";
     */
    public function backup(): DatabaseBackupFactory
    {
        if ($this->backupHelper === null) {
            require_once dirname(__DIR__) . '/factories/DatabaseBackup.php';
            $this->backupHelper = new DatabaseBackupFactory($this);
        }
        return $this->backupHelper;
    }

    /**
     * Access database migration operations.
     *
     * @return DatabaseMigrationFactory
     */
    public function migration(): DatabaseMigrationFactory
    {
        if ($this->migrationFactory === null) {
            require_once dirname(__FILE__) . '/factories/DatabaseMigration.php';

            $this->migrationFactory = new DatabaseMigrationFactory($this, [
                'default_chunk_size' => 1000,
                'default_memory_limit' => '256M',
                'enable_progress_tracking' => true,
                'enable_rollback' => true,
                'validate_by_default' => true,
                'log_migrations' => true
            ]);

            // Set debug callback if debug is enabled
            if ($this->debug) {
                $this->migrationFactory->setDebugCallback([$this, 'debugCallback']);
            }
        }

        return $this->migrationFactory;
    } 

    /**
     * Access database performance operations through unified interface
     * 
     * Provides comprehensive performance monitoring, optimization, and adaptive learning
     * for bulk operations. Features include auto-bulk detection, memory-aware chunking,
     * and database-specific optimization strategies.
     * 
     * @return DatabasePerformance Performance management interface
     * 
     * @example
     * // Enable performance optimizations
     * $model->performance()->enablePerformanceMode();
     * 
     * @example
     * // Get performance statistics
     * $stats = $model->performance()->getPerformanceStats();
     * 
     * @example
     * // Configure adaptive thresholds
     * $perf = $model->performance();
     * $perf->setBulkThresholds(100, 20);
     * $perf->setAdaptiveMode(true);
     */
    public function performance(): DatabasePerformance
    {
        if ($this->performanceHelper === null) {
            require_once dirname(__DIR__) . '/factories/DatabasePerformance.php';

            // Pass simple options only
            $performanceOptions = [
                'debug' => $this->debug,
                'bulkThreshold' => $this->options['bulkThreshold'] ?? 50,
                'autoOptimize' => $this->options['autoOptimize'] ?? true
            ];

            $this->performanceHelper = new DatabasePerformance($this, $performanceOptions);
        }
        return $this->performanceHelper;
    }

    /**
     * Access database maintenance operations through unified interface
     * 
     * Provides cross-database maintenance operations including vacuum, analyze,
     * reindex, and integrity checking. Each operation is automatically adapted
     * for the current database type (MySQL, SQLite, PostgreSQL).
     * 
     * @return DatabaseMaintenance Maintenance operations interface
     * 
     * @example
     * // Basic maintenance operations
     * $model->maintenance()->vacuum();           // Reclaim space
     * $model->maintenance()->analyze();          // Update statistics
     * $model->maintenance()->checkIntegrity();   // Verify integrity
     * 
     * @example
     * // Comprehensive optimization
     * $model->maintenance()->optimize();         // Full maintenance
     * 
     * @example
     * // Multiple operations with stored reference
     * $maintenance = $model->maintenance();
     * $maintenance->analyze();
     * $maintenance->vacuum();
     * $maintenance->reindex();
     */
    public function maintenance(): DatabaseMaintenance
    {
        if ($this->maintenanceHelper === null) {
            require_once dirname(__DIR__) . '/factories/DatabaseMaintenance.php';
            $this->maintenanceHelper = new DatabaseMaintenance($this);
        }
        return $this->maintenanceHelper;
    }

    /**
     * Enhanced Model Integration
     * 
     * Add this method to your Enhanced Model class:
     */
    public function sqlDumpTranslator(array $options = []): SQLDumpTranslator
    {
        if ($this->sqlTranslator === null) {
            require_once dirname(__FILE__, 2) . '/factories/SQLDumpTranslator.php';
            $this->sqlTranslator = new SQLDumpTranslator($this, $options);
        }
        return $this->sqlTranslator;
    }

    /**
     * Factory method for debug collector (lazy loading)
     * 
     * @return DebugCollector Debug collector instance
     */
    private function debug(): DebugCollector
    {
        if ($this->debugCollector === null) {
            $this->debugCollector = DebugFactory::createDebugCollector($this);
        }
        return $this->debugCollector;
    }

    /**
     * Ultra-lightweight debug logging (zero overhead when disabled)
     * 
     * @param string $message Debug message
     * @param int $category Debug category (DebugCategory constants)
     * @param int $level Debug level (DebugLevel constants)  
     * @param array $context Additional context data
     * @return void
     */
    public function debugLog(string $message, int $category = DebugCategory::SQL, int $level = DebugLevel::BASIC, array $context = []): void
    {
        // Zero overhead when debugging disabled - no objects created, no processing
        if (!$this->debug) return;

        // Lazy load debug collector only when needed
        $this->debug()->log($message, $category, $level, $context);
    }

    /**
     * Enhanced setDebug method with new capabilities
     * 
     * @param DebugLevel|bool $level Debug level or boolean for backwards compatibility
     * @param int|null $categories Debug categories bitmask
     * @param string $format Output format ('html', 'text', 'json', 'ansi')
     * @return void
     */
    public function setDebug(DebugLevel|bool $level = false, int|null $categories = null, string $format = 'html'): void
    {
        $this->debug = (bool)$level;

        if ($this->debug) {
            require_once dirname(__DIR__) . '/factories/Debug.php';
            $this->debug()->configure($level, $categories, $format);
        }
    }

    /**
     * Set debug mode using predefined presets
     * 
     * Available presets:
     * - 'off': Disable debugging completely
     * - 'basic': Basic SQL logging only
     * - 'developer': Detailed debugging with HTML output (recommended for development)
     * - 'performance': Performance monitoring and bulk operation analysis
     * - 'cli': CLI-friendly debugging with ANSI colors
     * - 'production': Production-safe performance monitoring with JSON output
     * - 'verbose': Maximum debugging with all categories and verbose output
     * 
     * @param string $preset Debug preset name
     * @return void
     * @throws InvalidArgumentException If preset name is invalid
     * 
     * @example
     * // Enable developer-friendly debugging
     * $model->setDebugPreset('developer');
     * 
     * @example
     * // Enable CLI debugging for scripts
     * $model->setDebugPreset('cli');
     * 
     * @example
     * // Production monitoring
     * $model->setDebugPreset('production');
     */
    public function setDebugPreset(string $preset): void
    {
        require_once dirname(__DIR__) . '/factories/Debug.php';
        DebugPresets::apply($this, $preset);
    }

    /**
     * Enable or disable automatic debug output for real-time feedback
     * 
     * When enabled, debug messages are output immediately as they occur.
     * When disabled, debug messages are collected for later retrieval.
     * 
     * @param bool $enabled True to enable real-time output
     * @return void
     * 
     * @example
     * // Enable real-time debug output for CLI scripts
     * $model->setDebugPreset('cli');
     * $model->setDebugAutoOutput(true);
     */
    public function setDebugAutoOutput(bool $enabled): void
    {
        if ($this->debug && $this->debugCollector !== null) {
            $this->debug()->setAutoOutput($enabled);
        }
    }

    /**
     * Get formatted debug output
     * 
     * @return string Formatted debug output
     */
    public function getDebugOutput(): string
    {
        if (!$this->debug || $this->debugCollector === null) {
            return 'Debug mode not enabled';
        }

        return $this->debug()->getOutput();
    }

    /**
     * Get raw debug data for programmatic access
     * 
     * @return array Raw debug data
     */
    public function getDebugData(): array
    {
        if (!$this->debug || $this->debugCollector === null) {
            return [];
        }

        return $this->debug()->getDebugData();
    }

    /**
     * Export debug session for external analysis
     * 
     * @param string $format Export format ('json', 'csv', 'xml', 'html')
     * @return string Exported debug data
     */
    public function exportDebugSession(string $format = 'json'): string
    {
        return DebugFactory::exportDebugSession($this->debugCollector, $format);
    }

    /**
     * Clear debug messages and reset counters
     * 
     * @return void
     */
    public function clearDebugMessages(): void
    {
        if ($this->debugCollector !== null) {
            $this->debugCollector->clear();
        }
    }

    /**
     * Enable performance mode with database optimizations
     * 
     * @return void
     */
    public function enablePerformanceMode(): void
    {
        $this->performance()->enablePerformanceMode();
    }

    /**
     * Disable performance mode and restore normal settings
     * 
     * @return void
     */
    public function disablePerformanceMode(): void
    {
        $this->performance()->disablePerformanceMode();
    }

    /**
     * Check if debug mode is currently enabled
     * 
     * Returns the current state of debug mode. This method is used by
     * factory classes and backup strategies to determine whether to
     * enable debug callbacks and detailed logging.
     * 
     * @return bool True if debug mode is enabled, false otherwise
     * 
     * @example
     * // Check debug status before setting up callbacks
     * if ($model->isDebugEnabled()) {
     *     $strategy->setDebugCallback(function($msg, $context) {
     *         $this->debugLog($msg, DebugLevel::DETAILED, $context);
     *     });
     * }
     * 
     * @example
     * // Conditional debug setup in factory classes
     * $verboseLogging = $model->isDebugEnabled();
     * $helper = new RobustRestoreHelper($pdo, $dbType, [
     *     'debug_parsing' => $verboseLogging
     * ]);
     */
    public function isDebugEnabled(): bool
    {
        return $this->debug;
    }

    /**
     * Get last database error message for debugging and logging
     * 
     * Retrieves the most recent error message from failed database operations.
     * Useful for error handling, logging, and debugging failed queries.
     * Returns null if no errors have occurred.
     * 
     * @return string|null Last error message, or null if no errors
     * 
     * @example
     * $success = $model->insert($invalidData);
     * if (!$success) {
     *     $error = $model->getLastError();
     *     error_log("Database insert failed: " . $error);
     * }
     * 
     * @example
     * // Check for errors after bulk operation
     * try {
     *     $model->insert_batch('table', $data);
     * } catch (RuntimeException $e) {
     *     $dbError = $model->getLastError();
     *     // Log both exception and database error details
     * }
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Format bytes to human-readable string
     * 
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g., '1.5 GB', '512 MB')
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get memory limit in bytes
     */
    public function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int)substr($memoryLimit, 0, -1);

        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value
        };
    }

    /**
     * Get database type for maintenance operations
     * 
     * @return string Database type identifier
     */
    public function getDbType(): string
    {
        return $this->dbType;
    }
}
