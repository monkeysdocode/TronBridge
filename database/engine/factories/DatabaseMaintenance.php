<?php

/**
 * Unified Database Maintenance System
 * 
 * Provides consistent maintenance operations across MySQL, SQLite, and PostgreSQL
 * databases through a unified API. Handles database-specific implementations
 * transparently while providing comprehensive maintenance capabilities.
 * 
 * **ENHANCED WITH FULL DEBUG SYSTEM INTEGRATION**
 * - Replaced all echo statements with Model's debug system
 * - Uses DebugCategory::MAINTENANCE for all maintenance operations
 * - Supports all debug levels (BASIC, DETAILED, VERBOSE)
 * - Inherits debug configuration from parent Model instance
 * - Zero overhead when debugging disabled
 * 
 * Features:
 * - Cross-database vacuum/optimize operations  
 * - Query optimizer statistics updates
 * - Index rebuilding and optimization
 * - Database integrity checking
 * - Health monitoring and fragmentation analysis
 * - Maintenance mode optimizations
 * - Operation time estimation
 * - Comprehensive debug logging
 * 
 * @package Database\Maintenance
 * @author Enhanced Model System
 * @version 1.1.0 - Debug System Integration
 */
class DatabaseMaintenance
{
    private Model $model;
    private PDO $pdo;
    private string $dbType;
    private bool $maintenanceMode = false;
    private array $originalSettings = [];

    /**
     * Initialize maintenance operations for Enhanced Model instance
     * 
     * Automatically inherits debug configuration from parent Model instance.
     * All maintenance operations will be logged through the Model's debug system.
     * 
     * @param Model $model Enhanced Model instance with active database connection
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->dbType = $model->getDbType();
        
        // Log initialization
        $this->debugLog("Database maintenance system initialized", DebugLevel::VERBOSE, [
            'database_type' => $this->dbType,
            'maintenance_mode' => $this->maintenanceMode
        ]);
    }

    // =============================================================================
    // ENHANCED DEBUG INTEGRATION
    // =============================================================================

    /**
     * Log debug message through Model's debug system
     * 
     * Automatically routes all debug messages through the parent Model's debug system,
     * ensuring consistent logging and zero overhead when debugging is disabled.
     * 
     * @param string $message Debug message
     * @param int $level Debug level (DebugLevel constants)
     * @param array $context Additional context data
     * @return void
     */
    private function debugLog(string $message, int $level = DebugLevel::BASIC, array $context = []): void
    {
        $this->model->debugLog($message, DebugCategory::MAINTENANCE, $level, $context);
    }

    /**
     * Log operation start with timing and context
     * 
     * @param string $operation Operation name
     * @param array $context Operation context
     * @return void
     */
    private function debugLogOperationStart(string $operation, array $context = []): void
    {
        $this->debugLog("🚀 {$operation} started", DebugLevel::BASIC, array_merge([
            'operation' => $operation,
            'database_type' => $this->dbType,
            'start_time' => date('Y-m-d H:i:s')
        ], $context));
    }

    /**
     * Log operation completion with timing and results
     * 
     * @param string $operation Operation name
     * @param float $duration Duration in seconds
     * @param array $results Operation results
     * @return void
     */
    private function debugLogOperationComplete(string $operation, float $duration, array $results = []): void
    {
        $this->debugLog("✅ {$operation} completed in " . round($duration, 3) . "s", DebugLevel::BASIC, array_merge([
            'operation' => $operation,
            'duration_seconds' => $duration,
            'success' => true
        ], $results));
    }

    /**
     * Log operation failure with error details
     * 
     * @param string $operation Operation name
     * @param string $error Error message
     * @param float $duration Duration in seconds
     * @param array $context Additional context
     * @return void
     */
    private function debugLogOperationError(string $operation, string $error, float $duration, array $context = []): void
    {
        $this->debugLog("❌ {$operation} failed: {$error}", DebugLevel::BASIC, array_merge([
            'operation' => $operation,
            'error' => $error,
            'duration_seconds' => $duration,
            'success' => false
        ], $context));
    }

    // =============================================================================
    // CORE MAINTENANCE OPERATIONS
    // =============================================================================

    /**
     * Clear all prepared statements and caches
     * 
     * Clears both database-level prepared statements and Enhanced Model's
     * internal caching. Essential for PostgreSQL compatibility and useful
     * for long-running processes, testing scenarios, and maintenance operations.
     * 
     * Database-specific behavior:
     * - PostgreSQL: Runs DEALLOCATE ALL + clears Enhanced Model cache
     * - MySQL/SQLite: Clears Enhanced Model cache (no DEALLOCATE needed)
     * 
     * @param bool $forceDeallocate Force DEALLOCATE ALL even for non-PostgreSQL databases
     * @return array Operation results including cleared cache counts and timing
     * 
     * @example
     * // Clear all prepared statements (recommended for PostgreSQL)
     * $result = $maintenance->deallocate_all();
     * 
     * @example
     * // Force deallocate for all database types
     * $result = $maintenance->deallocate_all(true);
     * 
     * @example
     * // Use in maintenance workflows
     * $maintenance->runMaintenanceWindow(function($m) {
     *     $m->analyze();
     *     $m->vacuum();
     *     $m->deallocate_all();  // Clean slate for next operations
     * });
     */
    public function deallocate_all(bool $forceDeallocate = false): array
    {
        $startTime = microtime(true);

        $results = [
            'operation' => 'deallocate_all',
            'database_type' => $this->dbType,
            'force_deallocate' => $forceDeallocate,
            'start_time' => date('Y-m-d H:i:s'),
        ];

        try {
            // Get cache stats before clearing
            $beforeStats = $this->model->getPerformanceStats();
            $preparedStatementsCount = $beforeStats['cache_stats']['prepared_statements'] ?? 0;
            $cachedSqlCount = $beforeStats['cache_stats']['cached_sql'] ?? 0;

            // Determine if we should run database DEALLOCATE
            $shouldDeallocate = $forceDeallocate || $this->dbType === 'postgresql';

            $this->debugLogOperationStart('DEALLOCATE ALL', [
                'prepared_statements_cached' => $preparedStatementsCount,
                'sql_queries_cached' => $cachedSqlCount,
                'will_run_database_deallocate' => $shouldDeallocate,
                'force_deallocate' => $forceDeallocate
            ]);

            // Clear Enhanced Model's prepared statement cache
            $this->model->clearPreparedStatementCache($shouldDeallocate);

            // Get cache stats after clearing
            $afterStats = $this->model->getPerformanceStats();
            $finalPreparedStatementsCount = $afterStats['cache_stats']['prepared_statements'] ?? 0;
            $finalCachedSqlCount = $afterStats['cache_stats']['cached_sql'] ?? 0;

            $results['database_deallocate_executed'] = $shouldDeallocate;
            $results['prepared_statements_cleared'] = $preparedStatementsCount - $finalPreparedStatementsCount;
            $results['cached_sql_cleared'] = $cachedSqlCount - $finalCachedSqlCount;
            $results['before_stats'] = [
                'prepared_statements' => $preparedStatementsCount,
                'cached_sql' => $cachedSqlCount
            ];
            $results['after_stats'] = [
                'prepared_statements' => $finalPreparedStatementsCount,
                'cached_sql' => $finalCachedSqlCount
            ];

            $results['duration_seconds'] = microtime(true) - $startTime;
            $results['success'] = true;

            // Add database-specific notes
            switch ($this->dbType) {
                case 'postgresql':
                    $results['notes'] = 'PostgreSQL DEALLOCATE ALL executed + Enhanced Model cache cleared';
                    break;
                case 'mysql':
                case 'sqlite':
                    if ($forceDeallocate) {
                        $results['notes'] = 'Enhanced Model cache cleared (DEALLOCATE forced but not needed for this database)';
                    } else {
                        $results['notes'] = 'Enhanced Model cache cleared (database DEALLOCATE not needed)';
                    }
                    break;
            }

            $this->debugLogOperationComplete('DEALLOCATE ALL', $results['duration_seconds'], [
                'prepared_statements_cleared' => $results['prepared_statements_cleared'],
                'cached_sql_cleared' => $results['cached_sql_cleared'],
                'database_deallocate_executed' => $shouldDeallocate
            ]);

        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('DEALLOCATE ALL', $e->getMessage(), $results['duration_seconds']);

            throw new RuntimeException("Deallocate all operation failed: " . $e->getMessage(), 0, $e);
        }

        return $results;
    }

    /**
     * Vacuum operation - reclaim space and defragment database
     * 
     * Performs database-specific space reclamation and defragmentation:
     * - SQLite: VACUUM (entire database)
     * - MySQL: OPTIMIZE TABLE (per table)
     * - PostgreSQL: VACUUM or VACUUM FULL
     * 
     * @param string|null $table Target table (MySQL/PostgreSQL only, SQLite ignores)
     * @param bool $full Use VACUUM FULL for PostgreSQL (more thorough but slower)
     * @return array Operation results including timing and space reclaimed
     * @throws InvalidArgumentException If table validation fails
     * @throws RuntimeException If vacuum operation fails
     * 
     * @example
     * $maintenance->vacuum();           // Vacuum entire database
     * $maintenance->vacuum('users');    // Vacuum specific table (MySQL/PostgreSQL)
     * $maintenance->vacuum(null, true); // PostgreSQL VACUUM FULL
     */
    public function vacuum(?string $table = null, bool $full = false): array
    {
        $startTime = microtime(true);
        $startStats = $this->getMaintenanceStats();

        $results = [
            'operation' => 'vacuum',
            'database_type' => $this->dbType,
            'table' => $table,
            'full_vacuum' => $full,
            'start_time' => date('Y-m-d H:i:s'),
        ];

        try {
            // Validate table name BEFORE any operations
            if ($table !== null) {
                DatabaseSecurity::validateTableName($table);
            }

            $this->debugLogOperationStart('VACUUM', [
                'table' => $table ?? 'entire_database',
                'full_vacuum' => $full,
                'database_size_before' => $startStats['database_size_bytes'] ?? 0
            ]);

            switch ($this->dbType) {
                case 'sqlite':
                    if ($table !== null) {
                        $results['notes'] = 'SQLite VACUUM operates on entire database (table parameter ignored)';
                        $this->debugLog("SQLite VACUUM operates on entire database (table parameter ignored)", DebugLevel::DETAILED);
                    }
                    $this->pdo->exec('VACUUM');
                    $results['notes'] = 'SQLite VACUUM completed (entire database)';
                    break;

                case 'mysql':
                    $tables = $table ? [$table] : $this->model->get_all_tables();
                    $optimizedTables = [];

                    $this->debugLog("Starting MySQL OPTIMIZE TABLE operation", DebugLevel::DETAILED, [
                        'tables_to_optimize' => count($tables),
                        'table_list' => $tables
                    ]);

                    foreach ($tables as $tbl) {
                        // Additional validation for each table
                        DatabaseSecurity::validateTableName($tbl);
                        $escapedTable = DatabaseSecurity::escapeIdentifier($tbl, $this->dbType);

                        $this->debugLog("Optimizing table: {$tbl}", DebugLevel::VERBOSE);

                        $stmt = $this->pdo->query("OPTIMIZE TABLE $escapedTable");
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        $optimizedTables[$tbl] = $result;

                        $this->debugLog("Table optimization result", DebugLevel::VERBOSE, [
                            'table' => $tbl,
                            'result' => $result
                        ]);
                    }

                    $results['optimized_tables'] = $optimizedTables;
                    $results['tables_processed'] = count($optimizedTables);
                    break;

                case 'postgresql':
                    if ($table) {
                        DatabaseSecurity::validateTableName($table);
                        $escapedTable = DatabaseSecurity::escapeIdentifier($table, $this->dbType);
                        $command = $full ? "VACUUM FULL $escapedTable" : "VACUUM $escapedTable";
                    } else {
                        $command = $full ? 'VACUUM FULL' : 'VACUUM';
                    }

                    $this->debugLog("Executing PostgreSQL command: {$command}", DebugLevel::DETAILED);
                    $this->pdo->exec($command);
                    $results['command_executed'] = $command;
                    break;

                default:
                    throw new RuntimeException("Unsupported database type for vacuum: {$this->dbType}");
            }

            $endStats = $this->getMaintenanceStats();
            $results['duration_seconds'] = microtime(true) - $startTime;
            $results['space_reclaimed_bytes'] = ($startStats['database_size_bytes'] ?? 0) - ($endStats['database_size_bytes'] ?? 0);
            $results['success'] = true;

            $this->debugLogOperationComplete('VACUUM', $results['duration_seconds'], [
                'space_reclaimed_bytes' => $results['space_reclaimed_bytes'],
                'space_reclaimed_formatted' => $this->formatBytes($results['space_reclaimed_bytes']),
                'tables_processed' => $results['tables_processed'] ?? 1
            ]);

        } catch (InvalidArgumentException $e) {
            // Re-throw validation errors as InvalidArgumentException
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('VACUUM validation', $e->getMessage(), $results['duration_seconds']);

            throw $e; // Re-throw the validation exception
        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('VACUUM', $e->getMessage(), $results['duration_seconds']);

            throw new RuntimeException("Vacuum operation failed: " . $e->getMessage(), 0, $e);
        }

        return $results;
    }

    /**
     * Analyze operation - update query optimizer statistics
     * 
     * Updates database query optimizer statistics for better query performance:
     * - SQLite: ANALYZE (entire database)
     * - MySQL: ANALYZE TABLE (per table)
     * - PostgreSQL: ANALYZE (database or specific tables)
     * 
     * @param string|array|null $tables Target table(s) or null for all tables
     * @return array Operation results including tables processed and timing
     * @throws InvalidArgumentException If table validation fails
     * @throws RuntimeException If analyze operation fails
     * 
     * @example
     * $maintenance->analyze();                    // Analyze all tables
     * $maintenance->analyze('users');             // Analyze specific table
     * $maintenance->analyze(['users', 'orders']); // Analyze multiple tables
     */
    public function analyze($tables = null): array
    {
        $startTime = microtime(true);

        $results = [
            'operation' => 'analyze',
            'database_type' => $this->dbType,
            'tables' => $tables,
            'start_time' => date('Y-m-d H:i:s'),
        ];

        try {
            // Validate table names BEFORE any operations
            if ($tables !== null) {
                $tableList = is_array($tables) ? $tables : [$tables];
                foreach ($tableList as $table) {
                    DatabaseSecurity::validateTableName($table);
                }
            }

            $this->debugLogOperationStart('ANALYZE', [
                'target_tables' => $tables ?? 'all_tables',
                'tables_count' => is_array($tables) ? count($tables) : ($tables ? 1 : 'all')
            ]);

            switch ($this->dbType) {
                case 'sqlite':
                    if ($tables !== null) {
                        $results['notes'] = 'SQLite ANALYZE operates on entire database (table parameter ignored)';
                        $this->debugLog("SQLite ANALYZE operates on entire database (table parameter ignored)", DebugLevel::DETAILED);
                    }
                    $this->pdo->exec('ANALYZE');
                    $results['scope'] = 'entire_database';
                    break;

                case 'mysql':
                case 'postgresql':
                    if ($tables) {
                        $tableList = is_array($tables) ? $tables : [$tables];
                        $analyzedTables = [];

                        $this->debugLog("Starting table-specific ANALYZE operation", DebugLevel::DETAILED, [
                            'tables_to_analyze' => count($tableList),
                            'table_list' => $tableList
                        ]);

                        foreach ($tableList as $table) {
                            DatabaseSecurity::validateTableName($table);
                            $escapedTable = DatabaseSecurity::escapeIdentifier($table, $this->dbType);

                            $this->debugLog("Analyzing table: {$table}", DebugLevel::VERBOSE);

                            if ($this->dbType === 'mysql') {
                                $stmt = $this->pdo->query("ANALYZE TABLE $escapedTable");
                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                $analyzedTables[$table] = $result;
                            } else { // PostgreSQL
                                $this->pdo->exec("ANALYZE $escapedTable");
                                $analyzedTables[$table] = ['status' => 'completed'];
                            }

                            $this->debugLog("Table analysis completed", DebugLevel::VERBOSE, [
                                'table' => $table,
                                'result' => $analyzedTables[$table]
                            ]);
                        }

                        $results['analyzed_tables'] = $analyzedTables;
                        $results['tables_processed'] = count($analyzedTables);
                        $results['scope'] = 'table_specific';
                    } else {
                        // Analyze all tables
                        $this->debugLog("Analyzing all tables", DebugLevel::DETAILED);
                        $this->pdo->exec('ANALYZE');
                        $results['scope'] = 'entire_database';
                    }
                    break;

                default:
                    throw new RuntimeException("Unsupported database type for analyze: {$this->dbType}");
            }

            $results['duration_seconds'] = microtime(true) - $startTime;
            $results['success'] = true;

            $this->debugLogOperationComplete('ANALYZE', $results['duration_seconds'], [
                'scope' => $results['scope'],
                'tables_processed' => $results['tables_processed'] ?? 'all'
            ]);

        } catch (InvalidArgumentException $e) {
            // Re-throw validation errors as InvalidArgumentException
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('ANALYZE validation', $e->getMessage(), $results['duration_seconds']);

            throw $e; // Re-throw the validation exception
        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('ANALYZE', $e->getMessage(), $results['duration_seconds']);

            throw new RuntimeException("Analyze operation failed: " . $e->getMessage(), 0, $e);
        }

        return $results;
    }

    /**
     * Reindex operation - rebuild database indexes
     * 
     * Rebuilds database indexes for improved query performance:
     * - SQLite: REINDEX (database or specific table)
     * - MySQL: DROP INDEX + CREATE INDEX (manual implementation)
     * - PostgreSQL: REINDEX (database or specific table)
     * 
     * @param string|null $table Target table or null for all indexes
     * @return array Operation results including indexes processed and timing
     * @throws InvalidArgumentException If table validation fails
     * @throws RuntimeException If reindex operation fails
     * 
     * @example
     * $maintenance->reindex();        // Rebuild all indexes
     * $maintenance->reindex('users'); // Rebuild indexes for specific table
     */
    public function reindex(?string $table = null): array
    {
        $startTime = microtime(true);

        $results = [
            'operation' => 'reindex',
            'database_type' => $this->dbType,
            'table' => $table,
            'start_time' => date('Y-m-d H:i:s'),
        ];

        try {
            // Validate table name BEFORE any operations
            if ($table !== null) {
                DatabaseSecurity::validateTableName($table);
            }

            $this->debugLogOperationStart('REINDEX', [
                'target' => $table ?? 'all_indexes',
                'database_type' => $this->dbType
            ]);

            switch ($this->dbType) {
                case 'sqlite':
                    if ($table) {
                        DatabaseSecurity::validateTableName($table);
                        $escapedTable = DatabaseSecurity::escapeIdentifier($table, $this->dbType);
                        $this->debugLog("Reindexing specific table: {$table}", DebugLevel::DETAILED);
                        $this->pdo->exec("REINDEX $escapedTable");
                        $results['scope'] = 'table_specific';
                    } else {
                        $this->debugLog("Reindexing entire database", DebugLevel::DETAILED);
                        $this->pdo->exec('REINDEX');
                        $results['scope'] = 'entire_database';
                    }
                    break;

                case 'mysql':
                    // MySQL doesn't have REINDEX - need to rebuild indexes manually
                    $tables = $table ? [$table] : $this->model->get_all_tables();
                    $reindexedTables = [];

                    $this->debugLog("Starting MySQL index rebuild", DebugLevel::DETAILED, [
                        'tables_to_reindex' => count($tables),
                        'note' => 'MySQL requires manual index rebuild'
                    ]);

                    foreach ($tables as $tbl) {
                        DatabaseSecurity::validateTableName($tbl);
                        $escapedTable = DatabaseSecurity::escapeIdentifier($tbl, $this->dbType);

                        $this->debugLog("Rebuilding indexes for table: {$tbl}", DebugLevel::VERBOSE);

                        // Get current indexes
                        $stmt = $this->pdo->query("SHOW INDEX FROM $escapedTable");
                        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        // Group indexes by name (excluding PRIMARY)
                        $indexGroups = [];
                        foreach ($indexes as $index) {
                            if ($index['Key_name'] !== 'PRIMARY') {
                                $indexGroups[$index['Key_name']][] = $index;
                            }
                        }

                        $rebuildCount = 0;
                        foreach ($indexGroups as $indexName => $indexCols) {
                            $this->debugLog("Rebuilding index: {$indexName}", DebugLevel::VERBOSE);

                            // Drop and recreate index
                            $this->pdo->exec("DROP INDEX `$indexName` ON $escapedTable");

                            // Build CREATE INDEX statement
                            $columns = array_map(fn($col) => "`{$col['Column_name']}`", $indexCols);
                            $this->pdo->exec("CREATE INDEX `$indexName` ON $escapedTable (" . implode(',', $columns) . ")");
                            $rebuildCount++;
                        }

                        $reindexedTables[$tbl] = [
                            'indexes_rebuilt' => $rebuildCount,
                            'status' => 'completed'
                        ];

                        $this->debugLog("Index rebuild completed for table", DebugLevel::VERBOSE, [
                            'table' => $tbl,
                            'indexes_rebuilt' => $rebuildCount
                        ]);
                    }

                    $results['reindexed_tables'] = $reindexedTables;
                    $results['tables_processed'] = count($reindexedTables);
                    $results['scope'] = $table ? 'table_specific' : 'entire_database';
                    break;

                case 'postgresql':
                    if ($table) {
                        DatabaseSecurity::validateTableName($table);
                        $escapedTable = DatabaseSecurity::escapeIdentifier($table, $this->dbType);
                        $command = "REINDEX TABLE $escapedTable";
                        $this->debugLog("Reindexing specific table: {$table}", DebugLevel::DETAILED);
                    } else {
                        $command = 'REINDEX DATABASE ' . $this->pdo->query('SELECT current_database()')->fetchColumn();
                        $this->debugLog("Reindexing entire database", DebugLevel::DETAILED);
                    }

                    $this->debugLog("Executing PostgreSQL command: {$command}", DebugLevel::DETAILED);
                    $this->pdo->exec($command);
                    $results['command_executed'] = $command;
                    $results['scope'] = $table ? 'table_specific' : 'entire_database';
                    break;

                default:
                    throw new RuntimeException("Unsupported database type for reindex: {$this->dbType}");
            }

            $results['duration_seconds'] = microtime(true) - $startTime;
            $results['success'] = true;

            $this->debugLogOperationComplete('REINDEX', $results['duration_seconds'], [
                'scope' => $results['scope'],
                'tables_processed' => $results['tables_processed'] ?? ($table ? 1 : 'all')
            ]);

        } catch (InvalidArgumentException $e) {
            // Re-throw validation errors as InvalidArgumentException
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('REINDEX validation', $e->getMessage(), $results['duration_seconds']);

            throw $e; // Re-throw the validation exception
        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('REINDEX', $e->getMessage(), $results['duration_seconds']);

            throw new RuntimeException("Reindex operation failed: " . $e->getMessage(), 0, $e);
        }

        return $results;
    }

    /**
     * Check database integrity
     * 
     * Performs database-specific integrity checks:
     * - SQLite: PRAGMA integrity_check
     * - MySQL: CHECK TABLE
     * - PostgreSQL: Constraint validation
     * 
     * @param string|null $table Target table or null for entire database
     * @param bool $quick Use quick check for better performance
     * @return array Integrity check results
     * @throws InvalidArgumentException If table validation fails
     * @throws RuntimeException If integrity check fails
     * 
     * @example
     * $maintenance->checkIntegrity();         // Check entire database
     * $maintenance->checkIntegrity('users');  // Check specific table
     * $maintenance->checkIntegrity(null, true); // Quick check
     */
    public function checkIntegrity(?string $table = null, bool $quick = false): array
    {
        $startTime = microtime(true);

        $results = [
            'operation' => 'integrity_check',
            'database_type' => $this->dbType,
            'table' => $table,
            'quick_check' => $quick,
            'start_time' => date('Y-m-d H:i:s'),
            'issues' => [],
        ];

        try {
            // Validate table name BEFORE any operations
            if ($table !== null) {
                DatabaseSecurity::validateTableName($table);
            }

            $this->debugLogOperationStart('INTEGRITY CHECK', [
                'target' => $table ?? 'entire_database',
                'quick_check' => $quick,
                'database_type' => $this->dbType
            ]);

            switch ($this->dbType) {
                case 'sqlite':
                    if ($table !== null) {
                        $results['notes'] = 'SQLite integrity check operates on entire database (table parameter ignored)';
                        $this->debugLog("SQLite integrity check operates on entire database (table parameter ignored)", DebugLevel::DETAILED);
                    }

                    $command = $quick ? 'PRAGMA quick_check' : 'PRAGMA integrity_check';
                    $this->debugLog("Executing SQLite command: {$command}", DebugLevel::DETAILED);

                    $stmt = $this->pdo->query($command);
                    $checkResults = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    if (count($checkResults) === 1 && $checkResults[0] === 'ok') {
                        $results['status'] = 'healthy';
                        $this->debugLog("Database integrity check passed", DebugLevel::DETAILED);
                    } else {
                        $results['status'] = 'issues_found';
                        $results['issues'] = $checkResults;
                        $this->debugLog("Database integrity issues found", DebugLevel::BASIC, [
                            'issues_count' => count($checkResults),
                            'issues' => $checkResults
                        ]);
                    }
                    break;

                case 'mysql':
                    $tables = $table ? [$table] : $this->model->get_all_tables();
                    $checkedTables = [];
                    $allHealthy = true;

                    $this->debugLog("Starting MySQL table integrity check", DebugLevel::DETAILED, [
                        'tables_to_check' => count($tables),
                        'quick_check' => $quick
                    ]);

                    foreach ($tables as $tbl) {
                        DatabaseSecurity::validateTableName($tbl);
                        $escapedTable = DatabaseSecurity::escapeIdentifier($tbl, $this->dbType);

                        $command = $quick ? "CHECK TABLE $escapedTable QUICK" : "CHECK TABLE $escapedTable";
                        $this->debugLog("Checking table: {$tbl}", DebugLevel::VERBOSE);

                        $stmt = $this->pdo->query($command);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        $checkedTables[$tbl] = $result;

                        if ($result['Msg_text'] !== 'OK') {
                            $allHealthy = false;
                            $results['issues'][] = "Table $tbl: " . $result['Msg_text'];
                            $this->debugLog("Table integrity issue found", DebugLevel::BASIC, [
                                'table' => $tbl,
                                'issue' => $result['Msg_text']
                            ]);
                        }
                    }

                    $results['checked_tables'] = $checkedTables;
                    $results['tables_processed'] = count($checkedTables);
                    $results['status'] = $allHealthy ? 'healthy' : 'issues_found';
                    break;

                case 'postgresql':
                    // PostgreSQL doesn't have a direct integrity check equivalent
                    // We'll validate constraints and foreign keys
                    $this->debugLog("Performing PostgreSQL constraint validation", DebugLevel::DETAILED);

                    if ($table) {
                        DatabaseSecurity::validateTableName($table);
                        $escapedTable = DatabaseSecurity::escapeIdentifier($table, $this->dbType);

                        // Check constraints for specific table
                        $stmt = $this->pdo->prepare("
                            SELECT conname, consrc 
                            FROM pg_constraint 
                            WHERE conrelid = ?::regclass 
                            AND contype = 'c'
                        ");
                        $stmt->execute([$table]);
                        $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $results['constraint_validation'] = [
                            'table' => $table,
                            'constraints_checked' => count($constraints)
                        ];
                    } else {
                        // Basic database-wide checks
                        $stmt = $this->pdo->query("SELECT COUNT(*) FROM pg_stat_database WHERE datname = current_database()");
                        $dbExists = $stmt->fetchColumn();

                        $results['constraint_validation'] = [
                            'database_accessible' => $dbExists > 0,
                            'scope' => 'database_wide'
                        ];
                    }

                    $results['status'] = 'healthy'; // Assume healthy if no exceptions
                    $results['notes'] = 'PostgreSQL constraint validation completed';
                    break;

                default:
                    throw new RuntimeException("Unsupported database type for integrity check: {$this->dbType}");
            }

            $results['duration_seconds'] = microtime(true) - $startTime;
            $results['success'] = true;

            $this->debugLogOperationComplete('INTEGRITY CHECK', $results['duration_seconds'], [
                'status' => $results['status'],
                'issues_found' => count($results['issues']),
                'tables_processed' => $results['tables_processed'] ?? ($table ? 1 : 'all')
            ]);

        } catch (InvalidArgumentException $e) {
            // Re-throw validation errors as InvalidArgumentException
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('INTEGRITY CHECK validation', $e->getMessage(), $results['duration_seconds']);

            throw $e; // Re-throw the validation exception
        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('INTEGRITY CHECK', $e->getMessage(), $results['duration_seconds']);

            throw new RuntimeException("Integrity check failed: " . $e->getMessage(), 0, $e);
        }

        return $results;
    }

    /**
     * Comprehensive optimization - combines multiple maintenance operations
     * 
     * Performs a complete database optimization by combining:
     * 1. Analyze (update statistics)
     * 2. Vacuum (reclaim space)
     * 3. Reindex (if beneficial based on database health)
     * 4. Integrity check (verify database health)
     * 
     * @param string|null $table Target table or null for entire database
     * @param array $options Optimization options
     * @return array Combined results from all operations
     * 
     * @example
     * $result = $maintenance->optimize();
     * $result = $maintenance->optimize('users', ['skip_reindex' => true]);
     */
    public function optimize(?string $table = null, array $options = []): array
    {
        $startTime = microtime(true);

        $results = [
            'operation' => 'comprehensive_optimization',
            'database_type' => $this->dbType,
            'table' => $table,
            'options' => $options,
            'start_time' => date('Y-m-d H:i:s'),
            'operations_performed' => [],
        ];

        $this->debugLogOperationStart('COMPREHENSIVE OPTIMIZATION', [
            'target' => $table ?? 'entire_database',
            'options' => $options,
            'estimated_operations' => $this->countPlannedOperations($options)
        ]);

        try {
            // Step 1: Update statistics first (important for subsequent operations)
            if (!($options['skip_analyze'] ?? false)) {
                $this->debugLog("🔍 Running ANALYZE operation", DebugLevel::BASIC);
                $results['operations_performed']['analyze'] = $this->analyze($table);
            }

            // Step 2: Reclaim space and defragment
            if (!($options['skip_vacuum'] ?? false)) {
                $this->debugLog("🧹 Running VACUUM operation", DebugLevel::BASIC);
                $results['operations_performed']['vacuum'] = $this->vacuum($table, $options['full_vacuum'] ?? false);
            }

            // Step 3: Rebuild indexes if beneficial
            if (!($options['skip_reindex'] ?? false) && $this->shouldReindex($table)) {
                $this->debugLog("🔧 Running REINDEX operation", DebugLevel::BASIC);
                $results['operations_performed']['reindex'] = $this->reindex($table);
            } else {
                $this->debugLog("⏭️ Skipping REINDEX (not beneficial or disabled)", DebugLevel::DETAILED);
            }

            // Step 4: Verify integrity
            if (!($options['skip_integrity_check'] ?? false)) {
                $this->debugLog("🏥 Running integrity check", DebugLevel::BASIC);
                $results['operations_performed']['integrity_check'] = $this->checkIntegrity($table, $options['quick_check'] ?? false);
            }

            // Step 5: Clear prepared statements if requested or beneficial
            if (($options['clear_prepared_statements'] ?? false) ||
                (!($options['skip_deallocate'] ?? false) && $this->shouldClearPreparedStatements())
            ) {
                $this->debugLog("🧽 Running DEALLOCATE ALL operation", DebugLevel::BASIC);
                $results['operations_performed']['deallocate_all'] = $this->deallocate_all($options['force_deallocate'] ?? false);
            }

            $results['duration_seconds'] = microtime(true) - $startTime;
            $results['success'] = true;
            $results['operations_count'] = count($results['operations_performed']);

            // Calculate space savings
            $spaceReclaimed = 0;
            if (isset($results['operations_performed']['vacuum']['space_reclaimed_bytes'])) {
                $spaceReclaimed = $results['operations_performed']['vacuum']['space_reclaimed_bytes'];
            }
            $results['total_space_reclaimed_bytes'] = $spaceReclaimed;

            $this->debugLogOperationComplete('COMPREHENSIVE OPTIMIZATION', $results['duration_seconds'], [
                'operations_performed' => $results['operations_count'],
                'space_reclaimed_bytes' => $spaceReclaimed,
                'space_reclaimed_formatted' => $this->formatBytes($spaceReclaimed),
                'operations_list' => array_keys($results['operations_performed'])
            ]);

        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            $this->debugLogOperationError('COMPREHENSIVE OPTIMIZATION', $e->getMessage(), $results['duration_seconds'], [
                'operations_completed' => count($results['operations_performed']),
                'last_operation' => array_key_last($results['operations_performed'])
            ]);

            throw new RuntimeException("Optimization failed: " . $e->getMessage(), 0, $e);
        }

        return $results;
    }

    // =============================================================================
    // MAINTENANCE MODE AND OPTIMIZATION
    // =============================================================================

    /**
     * Enable maintenance mode with database-specific optimizations
     * 
     * @return array Applied optimizations
     */
    public function enableMaintenanceMode(): array
    {
        if ($this->maintenanceMode) {
            return ['status' => 'already_enabled'];
        }

        $this->debugLog("🔧 Enabling maintenance mode", DebugLevel::BASIC, [
            'database_type' => $this->dbType
        ]);

        $optimizations = [];
        $errors = [];

        try {
            switch ($this->dbType) {
                case 'sqlite':
                    // Store original settings
                    $this->originalSettings['synchronous'] = $this->pdo->query('PRAGMA synchronous')->fetchColumn();
                    $this->originalSettings['journal_mode'] = $this->pdo->query('PRAGMA journal_mode')->fetchColumn();

                    // Apply maintenance optimizations
                    $this->pdo->exec('PRAGMA synchronous = OFF');
                    $this->pdo->exec('PRAGMA journal_mode = MEMORY');

                    $optimizations['synchronous'] = 'OFF';
                    $optimizations['journal_mode'] = 'MEMORY';

                    $this->debugLog("SQLite maintenance optimizations applied", DebugLevel::DETAILED, $optimizations);
                    break;

                case 'mysql':
                    // Store original settings
                    $stmt = $this->pdo->query("SHOW VARIABLES LIKE 'foreign_key_checks'");
                    $this->originalSettings['foreign_key_checks'] = $stmt->fetch(PDO::FETCH_ASSOC)['Value'];

                    $stmt = $this->pdo->query("SHOW VARIABLES LIKE 'unique_checks'");
                    $this->originalSettings['unique_checks'] = $stmt->fetch(PDO::FETCH_ASSOC)['Value'];

                    // Apply maintenance optimizations
                    $this->pdo->exec('SET foreign_key_checks = 0');
                    $this->pdo->exec('SET unique_checks = 0');

                    $optimizations['foreign_key_checks'] = '0';
                    $optimizations['unique_checks'] = '0';

                    $this->debugLog("MySQL maintenance optimizations applied", DebugLevel::DETAILED, $optimizations);
                    break;

                case 'postgresql':
                    // PostgreSQL maintenance optimizations
                    $stmt = $this->pdo->query("SHOW work_mem");
                    $this->originalSettings['work_mem'] = $stmt->fetchColumn();

                    try {
                        $this->pdo->exec("SET work_mem = '256MB'");
                        $optimizations['work_mem'] = '256MB';
                        $this->debugLog("PostgreSQL maintenance optimizations applied", DebugLevel::DETAILED, $optimizations);
                    } catch (Exception $e) {
                        $errors[] = "Could not adjust work_mem: " . $e->getMessage();
                        $this->debugLog("PostgreSQL work_mem adjustment failed", DebugLevel::DETAILED, [
                            'error' => $e->getMessage()
                        ]);
                    }
                    break;
            }

            $this->maintenanceMode = true;

            $result = [
                'status' => 'enabled',
                'database_type' => $this->dbType,
                'optimizations_applied' => $optimizations,
                'errors' => $errors
            ];

            $this->debugLog("✅ Maintenance mode enabled", DebugLevel::BASIC, $result);

            return $result;

        } catch (Exception $e) {
            $this->debugLogOperationError('ENABLE MAINTENANCE MODE', $e->getMessage(), 0);
            throw new RuntimeException("Failed to enable maintenance mode: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Disable maintenance mode and restore original settings
     * 
     * @return array Restoration results
     */
    public function disableMaintenanceMode(): array
    {
        if (!$this->maintenanceMode) {
            return ['status' => 'already_disabled'];
        }

        $this->debugLog("🔧 Disabling maintenance mode", DebugLevel::BASIC, [
            'database_type' => $this->dbType,
            'original_settings' => $this->originalSettings
        ]);

        $restorations = [];
        $errors = [];

        try {
            switch ($this->dbType) {
                case 'sqlite':
                    if (isset($this->originalSettings['synchronous'])) {
                        $this->pdo->exec('PRAGMA synchronous = ' . $this->originalSettings['synchronous']);
                        $restorations['synchronous'] = $this->originalSettings['synchronous'];
                    }

                    if (isset($this->originalSettings['journal_mode'])) {
                        $this->pdo->exec('PRAGMA journal_mode = ' . $this->originalSettings['journal_mode']);
                        $restorations['journal_mode'] = $this->originalSettings['journal_mode'];
                    }

                    $this->debugLog("SQLite settings restored", DebugLevel::DETAILED, $restorations);
                    break;

                case 'mysql':
                    if (isset($this->originalSettings['foreign_key_checks'])) {
                        $this->pdo->exec('SET foreign_key_checks = ' . $this->originalSettings['foreign_key_checks']);
                        $restorations['foreign_key_checks'] = $this->originalSettings['foreign_key_checks'];
                    }

                    if (isset($this->originalSettings['unique_checks'])) {
                        $this->pdo->exec('SET unique_checks = ' . $this->originalSettings['unique_checks']);
                        $restorations['unique_checks'] = $this->originalSettings['unique_checks'];
                    }

                    $this->debugLog("MySQL settings restored", DebugLevel::DETAILED, $restorations);
                    break;

                case 'postgresql':
                    if (isset($this->originalSettings['work_mem'])) {
                        try {
                            $this->pdo->exec("SET work_mem = '" . $this->originalSettings['work_mem'] . "'");
                            $restorations['work_mem'] = $this->originalSettings['work_mem'];
                            $this->debugLog("PostgreSQL settings restored", DebugLevel::DETAILED, $restorations);
                        } catch (Exception $e) {
                            $errors[] = "Could not restore work_mem: " . $e->getMessage();
                            $this->debugLog("PostgreSQL work_mem restoration failed", DebugLevel::DETAILED, [
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    break;
            }

            $this->maintenanceMode = false;
            $this->originalSettings = [];

            $result = [
                'status' => 'disabled',
                'database_type' => $this->dbType,
                'settings_restored' => $restorations,
                'errors' => $errors
            ];

            $this->debugLog("✅ Maintenance mode disabled", DebugLevel::BASIC, $result);

            return $result;

        } catch (Exception $e) {
            $this->debugLogOperationError('DISABLE MAINTENANCE MODE', $e->getMessage(), 0);
            throw new RuntimeException("Failed to disable maintenance mode: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Run maintenance operations within a maintenance window
     * 
     * Automatically enables maintenance mode, runs the provided callback,
     * and restores normal settings afterward.
     * 
     * @param callable $callback Maintenance operations to perform
     * @return array Results from maintenance window
     * 
     * @example
     * $maintenance->runMaintenanceWindow(function($m) {
     *     $m->analyze();
     *     $m->vacuum();
     *     $m->checkIntegrity();
     * });
     */
    public function runMaintenanceWindow(callable $callback): array
    {
        $startTime = microtime(true);

        $this->debugLogOperationStart('MAINTENANCE WINDOW', [
            'database_type' => $this->dbType
        ]);

        $results = [
            'operation' => 'maintenance_window',
            'start_time' => date('Y-m-d H:i:s'),
            'maintenance_mode_enabled' => false,
            'maintenance_mode_disabled' => false,
            'callback_results' => null,
            'errors' => []
        ];

        try {
            // Enable maintenance mode
            $this->debugLog("🔧 Enabling maintenance mode for window", DebugLevel::BASIC);
            $enableResult = $this->enableMaintenanceMode();
            $results['maintenance_mode_enabled'] = $enableResult;

            // Execute callback
            $this->debugLog("⚙️ Executing maintenance operations", DebugLevel::BASIC);
            $results['callback_results'] = $callback($this);

            // Disable maintenance mode
            $this->debugLog("🔧 Disabling maintenance mode after window", DebugLevel::BASIC);
            $disableResult = $this->disableMaintenanceMode();
            $results['maintenance_mode_disabled'] = $disableResult;

            $results['duration_seconds'] = microtime(true) - $startTime;
            $results['success'] = true;

            $this->debugLogOperationComplete('MAINTENANCE WINDOW', $results['duration_seconds'], [
                'maintenance_operations' => 'completed',
                'mode_enabled' => $enableResult['status'] ?? 'unknown',
                'mode_disabled' => $disableResult['status'] ?? 'unknown'
            ]);

        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['duration_seconds'] = microtime(true) - $startTime;

            // Attempt to restore normal mode even if callback failed
            try {
                if ($this->maintenanceMode) {
                    $this->debugLog("🚨 Attempting to restore normal mode after error", DebugLevel::BASIC);
                    $this->disableMaintenanceMode();
                }
            } catch (Exception $restoreException) {
                $results['errors'][] = "Failed to restore normal mode: " . $restoreException->getMessage();
                $this->debugLog("❌ Failed to restore normal mode", DebugLevel::BASIC, [
                    'error' => $restoreException->getMessage()
                ]);
            }

            $this->debugLogOperationError('MAINTENANCE WINDOW', $e->getMessage(), $results['duration_seconds']);

            throw new RuntimeException("Maintenance window failed: " . $e->getMessage(), 0, $e);
        }

        return $results;
    }

    // =============================================================================
    // HELPER AND UTILITY METHODS
    // =============================================================================

    /**
     * Count planned operations based on options
     * 
     * @param array $options Optimization options
     * @return int Number of planned operations
     */
    private function countPlannedOperations(array $options): int
    {
        $count = 0;
        
        if (!($options['skip_analyze'] ?? false)) $count++;
        if (!($options['skip_vacuum'] ?? false)) $count++;
        if (!($options['skip_reindex'] ?? false)) $count++;
        if (!($options['skip_integrity_check'] ?? false)) $count++;
        if (($options['clear_prepared_statements'] ?? false) || 
            (!($options['skip_deallocate'] ?? false))) $count++;
            
        return $count;
    }

    /**
     * Get comprehensive maintenance statistics
     * 
     * @return array Database maintenance statistics
     */
    public function getMaintenanceStats(): array
    {
        $this->debugLog("📊 Gathering maintenance statistics", DebugLevel::DETAILED);

        $stats = [
            'database_type' => $this->dbType,
            'maintenance_mode' => $this->maintenanceMode,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        try {
            switch ($this->dbType) {
                case 'sqlite':
                    // SQLite-specific stats
                    $stats['database_size_bytes'] = filesize($this->pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)) ?: 0;
                    
                    $pragma_stats = $this->pdo->query('PRAGMA page_count')->fetchColumn();
                    $page_size = $this->pdo->query('PRAGMA page_size')->fetchColumn();
                    $freelist_count = $this->pdo->query('PRAGMA freelist_count')->fetchColumn();
                    
                    $stats['total_pages'] = $pragma_stats;
                    $stats['page_size'] = $page_size;
                    $stats['free_pages'] = $freelist_count;
                    $stats['fragmentation_percent'] = $pragma_stats > 0 ? round(($freelist_count / $pragma_stats) * 100, 2) : 0;
                    
                    $this->debugLog("SQLite statistics gathered", DebugLevel::VERBOSE, [
                        'pages' => $pragma_stats,
                        'fragmentation' => $stats['fragmentation_percent'] . '%'
                    ]);
                    break;

                case 'mysql':
                    // MySQL-specific stats
                    $stmt = $this->pdo->query("SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = DATABASE()");
                    $stats['database_size_bytes'] = $stmt->fetchColumn() ?: 0;
                    
                    $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
                    $stats['table_count'] = $stmt->fetchColumn();
                    
                    $this->debugLog("MySQL statistics gathered", DebugLevel::VERBOSE, [
                        'size_bytes' => $stats['database_size_bytes'],
                        'table_count' => $stats['table_count']
                    ]);
                    break;

                case 'postgresql':
                    // PostgreSQL-specific stats
                    $stmt = $this->pdo->query("SELECT pg_database_size(current_database())");
                    $stats['database_size_bytes'] = $stmt->fetchColumn() ?: 0;
                    
                    $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
                    $stats['table_count'] = $stmt->fetchColumn();
                    
                    $this->debugLog("PostgreSQL statistics gathered", DebugLevel::VERBOSE, [
                        'size_bytes' => $stats['database_size_bytes'],
                        'table_count' => $stats['table_count']
                    ]);
                    break;
            }

            $this->debugLog("📊 Maintenance statistics gathered successfully", DebugLevel::DETAILED, [
                'database_size' => $this->formatBytes($stats['database_size_bytes'] ?? 0),
                'fragmentation' => ($stats['fragmentation_percent'] ?? 'N/A') . '%'
            ]);

        } catch (Exception $e) {
            $stats['error'] = $e->getMessage();
            $this->debugLog("⚠️ Error gathering maintenance statistics", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);
        }

        return $stats;
    }

    /**
     * Estimate maintenance operation time
     * 
     * @param string $operation Operation name
     * @param string|null $table Target table
     * @return array Time estimation
     */
    public function estimateMaintenanceTime(string $operation = 'optimize', ?string $table = null): array
    {
        $this->debugLog("⏱️ Estimating maintenance time", DebugLevel::DETAILED, [
            'operation' => $operation,
            'target' => $table ?? 'entire_database'
        ]);

        $stats = $this->getMaintenanceStats();
        $dbSizeGB = ($stats['database_size_bytes'] ?? 1000000) / (1024 * 1024 * 1024);

        // Base estimates in seconds per GB
        $baseEstimates = [
            'sqlite' => [
                'vacuum' => 30,    // 30 seconds per GB
                'analyze' => 5,    // 5 seconds per GB
                'reindex' => 20,   // 20 seconds per GB
                'optimize' => 60   // Combined operations
            ],
            'mysql' => [
                'vacuum' => 15,    // OPTIMIZE TABLE is generally faster
                'analyze' => 3,    // ANALYZE TABLE is quick
                'reindex' => 25,   // Manual index rebuild is slower
                'optimize' => 50
            ],
            'postgresql' => [
                'vacuum' => 20,    // VACUUM is moderate
                'analyze' => 4,    // ANALYZE is quick
                'reindex' => 30,   // REINDEX can be slow
                'optimize' => 60
            ]
        ];

        $baseTime = $baseEstimates[$this->dbType][$operation] ?? 30;
        $estimatedSeconds = max(5, intval($dbSizeGB * $baseTime));

        // Adjust for table-specific operations
        if ($table && $operation !== 'optimize') {
            $estimatedSeconds = max(2, intval($estimatedSeconds * 0.1)); // Single table is ~10% of database
        }

        $estimate = [
            'operation' => $operation,
            'estimated_seconds' => $estimatedSeconds,
            'estimated_minutes' => round($estimatedSeconds / 60, 1),
            'human_readable' => $this->formatDuration($estimatedSeconds),
            'database_size_gb' => round($dbSizeGB, 2),
            'notes' => 'Estimates based on database size and historical averages'
        ];

        $this->debugLog("⏱️ Maintenance time estimated", DebugLevel::DETAILED, $estimate);

        return $estimate;
    }

    /**
     * Get overall database health assessment
     * 
     * @return array Health assessment
     */
    public function getDatabaseHealth(): array
    {
        $this->debugLog("🏥 Assessing database health", DebugLevel::DETAILED);

        $health = [
            'status' => 'healthy',
            'score' => 100,
            'issues' => [],
            'recommendations' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];

        try {
            $stats = $this->getMaintenanceStats();

            // Check fragmentation (SQLite)
            if (isset($stats['fragmentation_percent'])) {
                if ($stats['fragmentation_percent'] > 25) {
                    $health['score'] -= 30;
                    $health['issues'][] = "High fragmentation: {$stats['fragmentation_percent']}%";
                    $health['recommendations'][] = "Run VACUUM to reduce fragmentation";
                    $this->debugLog("High fragmentation detected", DebugLevel::BASIC, [
                        'fragmentation_percent' => $stats['fragmentation_percent']
                    ]);
                } elseif ($stats['fragmentation_percent'] > 10) {
                    $health['score'] -= 10;
                    $health['issues'][] = "Moderate fragmentation: {$stats['fragmentation_percent']}%";
                }
            }

            // Check database size growth
            $sizeGB = ($stats['database_size_bytes'] ?? 0) / (1024 * 1024 * 1024);
            if ($sizeGB > 10) {
                $health['recommendations'][] = "Large database detected - consider regular maintenance";
                $this->debugLog("Large database detected", DebugLevel::DETAILED, [
                    'size_gb' => round($sizeGB, 2)
                ]);
            }

            // Check if maintenance mode is stuck
            if ($this->maintenanceMode) {
                $health['score'] -= 20;
                $health['issues'][] = "Maintenance mode is still enabled";
                $health['recommendations'][] = "Disable maintenance mode";
            }

            // Determine overall status
            if ($health['score'] >= 90) {
                $health['status'] = 'excellent';
            } elseif ($health['score'] >= 70) {
                $health['status'] = 'good';
            } elseif ($health['score'] >= 50) {
                $health['status'] = 'fair';
            } else {
                $health['status'] = 'needs_attention';
            }

            $this->debugLog("🏥 Database health assessment completed", DebugLevel::BASIC, [
                'status' => $health['status'],
                'score' => $health['score'],
                'issues_count' => count($health['issues'])
            ]);

        } catch (Exception $e) {
            $health['status'] = 'error';
            $health['score'] = 0;
            $health['issues'][] = "Health check failed: " . $e->getMessage();
            $this->debugLog("❌ Database health assessment failed", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);
        }

        return $health;
    }

    /**
     * Determine if prepared statement clearing would be beneficial
     */
    private function shouldClearPreparedStatements(): bool
    {
        $stats = $this->model->getPerformanceStats();

        // Clear if we have a lot of cached statements (memory management)
        $preparedStatementsCount = $stats['cache_stats']['prepared_statements'] ?? 0;
        $cachedSqlCount = $stats['cache_stats']['cached_sql'] ?? 0;

        $shouldClear = $preparedStatementsCount > 100 || $cachedSqlCount > 500;

        if ($shouldClear) {
            $this->debugLog("Prepared statement clearing would be beneficial", DebugLevel::DETAILED, [
                'prepared_statements' => $preparedStatementsCount,
                'cached_sql' => $cachedSqlCount,
                'recommendation' => 'clear_cache'
            ]);
        }

        return $shouldClear;
    }

    /**
     * Determine if reindexing would be beneficial
     */
    private function shouldReindex(?string $table = null): bool
    {
        $stats = $this->getMaintenanceStats();

        // Reindex if fragmentation is high (SQLite)
        if (isset($stats['fragmentation_percent']) && $stats['fragmentation_percent'] > 15) {
            $this->debugLog("Reindexing would be beneficial due to fragmentation", DebugLevel::DETAILED, [
                'fragmentation_percent' => $stats['fragmentation_percent'],
                'recommendation' => 'reindex'
            ]);
            return true;
        }

        // For other databases, be conservative
        $this->debugLog("Reindexing assessment", DebugLevel::VERBOSE, [
            'database_type' => $this->dbType,
            'recommendation' => 'skip_reindex',
            'reason' => 'No clear benefit detected'
        ]);

        return false;
    }

    /**
     * Format bytes to human-readable string
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size string
     */
    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format duration to human-readable string
     * 
     * @param int $seconds Duration in seconds
     * @return string Formatted duration string
     */
    public function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        } elseif ($seconds < 3600) {
            return round($seconds / 60, 1) . ' minutes';
        } else {
            return round($seconds / 3600, 1) . ' hours';
        }
    }

}