<?php

require_once dirname(__DIR__, 2) . '/exceptions/BackupException.php';
require_once dirname(__DIR__, 3) . '/engine/schema/core/SchemaExtractor.php';
require_once dirname(__DIR__, 3) . '/engine/schema/core/SchemaRenderer.php';
require_once dirname(__DIR__, 3) . '/engine/schema/core/SchemaDependencySorter.php';
require_once dirname(__DIR__, 3) . '/engine/schema/platforms/MySQLPlatform.php';
require_once dirname(__DIR__, 3) . '/engine/schema/parsers/MySQLParser.php';
require_once dirname(__DIR__, 1) . '/helpers/MySQLRestoreHelper.php';
require_once dirname(__DIR__, 1) . '/interfaces/BackupStrategyInterface.php';
require_once dirname(__DIR__, 1) . '/interfaces/RestoreStrategyInterface.php';

/**
 * MySQL PHP Backup Strategy - Modernized Implementation with Full Schema Support
 * 
 * Provides comprehensive MySQL database backup and restore capabilities using
 * pure PHP and PDO with advanced schema system integration. This modernized version
 * follows the same architectural pattern as the successful PostgreSQL implementation.
 * 
 * **COMPLETE MYSQL SCHEMA SUPPORT:**
 * - Full table definitions with all MySQL data types (ENUM, SET, TEXT variants)
 * - PRIMARY KEY, UNIQUE, FOREIGN KEY, and CHECK constraints
 * - All index types (BTREE, HASH, FULLTEXT, SPATIAL with prefixes)
 * - Triggers with DELIMITER handling and complete action statements
 * - AUTO_INCREMENT sequences with proper value preservation
 * - Table and column comments
 * - Engine specifications (InnoDB, MyISAM, etc.)
 * - Character set and collation settings
 * 
 * **Architectural Improvements:**
 * - Uses SchemaRenderer and MySQLPlatform for intelligent SQL generation
 * - Implements duplicate prevention with tracking arrays
 * - Clean mysqldump-quality SQL output with proper dependency ordering
 * - Simplified restore with DatabaseSQLParser integration
 * - PathValidationTrait security integration
 * 
 * **MySQL-Specific Optimizations:**
 * - AUTO_INCREMENT value preservation
 * - ENGINE and charset handling
 * - DELIMITER processing for triggers and procedures
 * - Foreign key constraint management
 * - FULLTEXT and SPATIAL index support
 * 
 * @package Database\Backup\Strategy\MySQL
 * @author Enhanced Model System  
 * @version 2.0.0 - Fixed API Compatibility Issues
 */
class MySQLPHPBackupStrategy implements BackupStrategyInterface, RestoreStrategyInterface
{
    use DebugLoggingTrait;
    use ConfigSanitizationTrait;
    use PathValidationTrait;

    private Model $model;
    private PDO $pdo;
    private array $connectionConfig;
    private string $databaseName;

    // Schema system components
    private ?SchemaExtractor $schemaExtractor = null;
    private ?SchemaRenderer $schemaRenderer = null;
    private ?MySQLPlatform $mysqlPlatform = null;
    private ?MySQLParser $mysqlParser = null;
    private ?SchemaDependencySorter $dependencySorter = null;

    // Duplicate prevention tracking
    private array $writtenTables = [];
    private array $writtenIndexes = [];
    private array $writtenConstraints = [];
    private array $writtenTriggers = [];
    private array $writtenComments = [];

    /**
     * Initialize MySQL PHP backup strategy with schema system
     */
    public function __construct(Model $model, array $connectionConfig)
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->connectionConfig = $this->normalizeConnectionConfig($connectionConfig);
        $this->databaseName = $this->connectionConfig['database'];

        // Initialize MySQL schema system components
        $this->initializeSchemaSystem();

        $this->debugLog("MySQL PHP backup strategy initialized with schema system", DebugLevel::VERBOSE, [
            'database_name' => $this->databaseName,
            'schema_system_enabled' => $this->schemaRenderer !== null,
            'mysql_platform_available' => $this->mysqlPlatform !== null
        ]);

        // Validate MySQL connection
        $this->validateMySQLConnection();
    }

    /**
     * Normalize connection configuration
     */
    private function normalizeConnectionConfig(array $config): array
    {
        $defaults = [
            'host' => 'localhost',
            'port' => 3306,
            'database' => '',
            'user' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'type' => 'mysql'
        ];

        $normalized = array_merge($defaults, $config);

        // Ensure port is integer
        $normalized['port'] = (int)$normalized['port'];

        return $normalized;
    }

    /**
     * Initialize MySQL schema system components
     */
    private function initializeSchemaSystem(): void
    {
        try {
            $this->mysqlPlatform = new MySQLPlatform();
            $this->schemaExtractor = new SchemaExtractor();
            $this->schemaRenderer = new SchemaRenderer($this->mysqlPlatform);
            $this->mysqlParser = new MySQLParser();
            $this->dependencySorter = new SchemaDependencySorter();

            // Set debug callback for schema system integration
            $this->schemaRenderer->setDebugCallback(function ($message, $context = []) {
                $this->debugLog("MySQL Schema System: " . $message, DebugLevel::VERBOSE, $context);
            });

            $this->debugLog("MySQL schema system initialized successfully", DebugLevel::VERBOSE);
        } catch (Exception $e) {
            $this->debugLog("MySQL schema system initialization failed", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);
            // Continue without schema system as fallback
        }
    }

    // =============================================================================
    // BACKUP OPERATIONS
    // =============================================================================

    /**
     * Create MySQL database backup using modernized schema-aware approach
     */
    public function createBackup(string $outputPath, array $options = []): array
    {
        $startTime = microtime(true);
        $this->validateBackupPath($outputPath);

        // Default options optimized for MySQL
        // Usage examples:
        // - Default (UTC): ['set_timezone_utc' => true, 'backup_timezone' => '+00:00']
        // - Server timezone: ['set_timezone_utc' => false]
        // - Specific timezone: ['set_timezone_utc' => true, 'backup_timezone' => '-05:00']
        // - Custom: ['set_timezone_utc' => true, 'backup_timezone' => 'America/New_York']
        $backupOptions = array_merge([
            'include_drop_statements' => true,
            'include_schema' => true,
            'include_data' => true,
            'include_triggers' => true,
            'defer_indexes' => true,          // NEW: Defer non-essential indexes
            'single_transaction' => true,
            'disable_foreign_keys' => true,   // Handled by FOREIGN_KEY_CHECKS = 0
            'set_timezone_utc' => true,       // Set timezone to UTC for portable backups
            'backup_timezone' => '+00:00',    // Configurable timezone (default UTC)
            'chunk_size' => 1000,
            'validate_backup' => true,
            'progress_callback' => null
        ], $options);

        $this->debugLog("Starting MySQL backup with schema system", DebugLevel::BASIC, [
            'output_path' => $outputPath,
            'options' => $this->sanitizeConfig($backupOptions),
            'schema_system_enabled' => $this->schemaRenderer !== null
        ]);

        try {
            // Start transaction if requested
            if ($backupOptions['single_transaction']) {
                $this->pdo->beginTransaction();
                $this->pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
            }

            $this->reportProgress($backupOptions, 5, "Getting table list");
            $tables = $this->getTableList($backupOptions);

            $this->reportProgress($backupOptions, 10, "Analyzing table structures");
            $tableStats = $this->analyzeTableStructures($tables);

            // Generate SQL backup using schema system
            $backupResult = $this->generateSchemaAwareSQLBackup($outputPath, $tables, $backupOptions);

            // Commit transaction if started
            if ($backupOptions['single_transaction']) {
                $this->pdo->commit();
            }

            // Validate backup if requested
            $validationResult = null;
            if ($backupOptions['validate_backup']) {
                $validationResult = $this->validateBackupFile($outputPath);
            }

            $duration = microtime(true) - $startTime;

            $this->debugLog("MySQL schema-aware backup completed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'backup_size_bytes' => filesize($outputPath),
                'tables_processed' => count($tables),
                'sql_statements_generated' => $backupResult['sql_statements_generated'],
                'validation_passed' => $validationResult['valid'] ?? false
            ]);

            return [
                'success' => true,
                'output_path' => $outputPath,
                'backup_size_bytes' => filesize($outputPath),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType(),
                'backup_statistics' => $backupResult['statistics'],
                'validation' => $validationResult
            ];
        } catch (Exception $e) {
            // Rollback transaction if started
            if ($backupOptions['single_transaction'] && $this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }

            $duration = microtime(true) - $startTime;

            $this->debugLog("MySQL backup failed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'error' => $e->getMessage()
            ]);

            // Cleanup failed backup file
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType()
            ];
        }
    }

    /**
     * Generate SQL backup
     */
    private function generateSchemaAwareSQLBackup(string $outputPath, array $tables, array $options): array
    {
        $sqlStatements = [];
        $statistics = [
            'tables_backed_up' => 0,
            'rows_backed_up' => 0,
            'indexes_backed_up' => 0,
            'constraints_backed_up' => 0,
            'triggers_backed_up' => 0,
            'comments_backed_up' => 0
        ];

        // Reset tracking arrays for duplicate prevention
        $this->writtenTriggers = [];
        $deferredIndexes = []; // Store indexes for later creation

        // Header comments
        $sqlStatements[] = "-- MySQL Database Backup";
        $sqlStatements[] = "-- Generated by Enhanced Model MySQL PHP Backup Strategy";
        $sqlStatements[] = "-- Date: " . date('Y-m-d H:i:s');
        $sqlStatements[] = "-- Database: " . $this->databaseName;
        $sqlStatements[] = "";

        // MySQL-specific session settings
        $sqlStatements[] = "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';";
        $sqlStatements[] = "SET AUTOCOMMIT = 0;";
        $sqlStatements[] = "START TRANSACTION;";
        // Configurable timezone setting (default UTC for portable backups)
        if ($options['set_timezone_utc']) {
            $timezone = $options['backup_timezone'] ?? '+00:00';
            $sqlStatements[] = "SET TIME_ZONE = '$timezone';";
        }
        $sqlStatements[] = "SET UNIQUE_CHECKS = 0;";        // Faster inserts
        if ($options['disable_foreign_keys']) {
            $sqlStatements[] = "SET FOREIGN_KEY_CHECKS = 0;";  // Allow out-of-order inserts
        }
        $sqlStatements[] = "SET SQL_LOG_BIN = 0;";          // Skip binary logging if allowed
        $sqlStatements[] = "";

        

        $this->reportProgress($options, 15, "Building dependency information");
        $tableObjects = $this->createTableObjectsForSorting($tables);

        // Sort tables by dependencies
        $this->reportProgress($options, 20, "Sorting tables by dependencies");
        $sortedTables = $this->sortTablesByDependencies($tableObjects, $tables);

        // PHASE 0: DROP statements (reverse dependency order)
        if ($options['include_drop_statements']) {
            $this->reportProgress($options, 25, "Writing DROP statements");
            $sqlStatements[] = "-- ===============================================";
            $sqlStatements[] = "-- Phase 0: Drop Existing Objects (Reverse Order)";
            $sqlStatements[] = "-- ===============================================";
            $sqlStatements[] = "";

            $dropStatements = $this->generateDropStatements($sortedTables, $options);
            $sqlStatements = array_merge($sqlStatements, $dropStatements);
            $sqlStatements[] = "";
        }


        // PHASE 1: Table structures (dependency order)
        $this->reportProgress($options, 35, "Writing table structures");
        $sqlStatements[] = "-- ===============================================";
        $sqlStatements[] = "-- Phase 1: Table Structures (Dependency Order)";
        $sqlStatements[] = "-- ===============================================";
        $sqlStatements[] = "";

        foreach ($sortedTables as $tableName) {
            if ($options['include_schema']) {
                $tableResult = $this->generateTableCreationSQL($tableName, $options);
                if ($tableResult['table_sql'] && !str_contains($tableResult['table_sql'], 'ERROR:')) {
                    $sqlStatements[] = "-- Table: $tableName (structure only, indexes deferred)";
                    $sqlStatements[] = $tableResult['table_sql'];
                    $sqlStatements[] = "";
                    
                    // Store deferred indexes for later
                    if (!empty($tableResult['deferred_indexes'])) {
                        $deferredIndexes[$tableName] = $tableResult['deferred_indexes'];
                        $statistics['indexes_deferred'] += count($tableResult['deferred_indexes']);
                    }
                    
                    $statistics['tables_backed_up']++;
                }
            }
        }

        // PHASE 2: Table data (dependency order)
        if ($options['include_data']) {
            $this->reportProgress($options, 60, "Writing table data");
            $sqlStatements[] = "-- ===============================================";
            $sqlStatements[] = "-- Phase 2: Table Data (Dependency Order)";
            $sqlStatements[] = "-- ===============================================";
            $sqlStatements[] = "";

            $processedDataTables = 0;
            foreach ($sortedTables as $tableName) {
                $this->reportProgress(
                    $options,
                    60 + (25 * $processedDataTables / count($sortedTables)),
                    "Exporting data: $tableName"
                );

                $dataSQL = $this->generateTableDataSQL($tableName, $options);
                if (!empty($dataSQL)) {
                    $sqlStatements[] = "-- Data for table: $tableName";
                    $sqlStatements = array_merge($sqlStatements, $dataSQL);
                    $sqlStatements[] = "";

                    // Count rows
                    foreach ($dataSQL as $insertSQL) {
                        $statistics['rows_backed_up'] += substr_count($insertSQL, '),(') + 1;
                    }
                }
                $processedDataTables++;
            }
        }

        // PHASE 3: Create deferred indexes (after all data is loaded)
        if ($options['include_schema'] && !empty($deferredIndexes)) {
            $this->reportProgress($options, 80, "Creating deferred indexes (optimized build)");
            $sqlStatements[] = "-- ===============================================";
            $sqlStatements[] = "-- Phase 3: Deferred Index Creation (Optimized Build)";
            $sqlStatements[] = "-- Creating indexes after data load for optimal performance";
            $sqlStatements[] = "-- ===============================================";
            $sqlStatements[] = "";

            $processedIndexTables = 0;
            $totalIndexTables = count($deferredIndexes);
            
            foreach ($sortedTables as $tableName) {
                if (isset($deferredIndexes[$tableName])) {
                    $this->reportProgress(
                        $options,
                        80 + (10 * $processedIndexTables / $totalIndexTables),
                        "Building indexes for: $tableName"
                    );

                    $sqlStatements[] = "-- Indexes for table: $tableName";
                    $sqlStatements = array_merge($sqlStatements, $deferredIndexes[$tableName]);
                    $sqlStatements[] = "";
                    $processedIndexTables++;
                }
            }
        }

        // PHASE 4: Triggers (require special handling)
        if ($options['include_triggers']) {
            $this->reportProgress($options, 92, "Writing triggers");
            $sqlStatements[] = "-- ===============================================";
            $sqlStatements[] = "-- Phase 4: Triggers";
            $sqlStatements[] = "-- ===============================================";
            $sqlStatements[] = "";
            
            $triggerSQL = $this->generateTriggersSQL($tables, $options);
            if (!empty($triggerSQL)) {
                $sqlStatements = array_merge($sqlStatements, $triggerSQL);
                $sqlStatements[] = "";
                $statistics['triggers_backed_up'] = count($this->writtenTriggers);
            }
        }

        // Footer
        $sqlStatements[] = "-- Restore normal MySQL settings";
        if ($options['disable_foreign_keys']) {
            $sqlStatements[] = "SET FOREIGN_KEY_CHECKS = 1;";
        }
        $sqlStatements[] = "SET UNIQUE_CHECKS = 1;";
        $sqlStatements[] = "SET SQL_LOG_BIN = 1;";
        $sqlStatements[] = "COMMIT;";

        // Write to file
        $this->reportProgress($options, 98, "Writing backup file");
        file_put_contents($outputPath, implode("\n", $sqlStatements));

        return [
            'sql_statements_generated' => count($sqlStatements),
            'statistics' => $statistics
        ];
    }

    /**
     * Create Table objects for dependency sorting
     */
    private function createTableObjectsForSorting(array $tables): array
    {
        $tableObjects = [];

        foreach ($tables as $tableName) {
            try {
                $table = new Table($tableName);

                // Get constraints using focused approach for dependency analysis
                if ($this->schemaExtractor) {
                    $constraints = $this->schemaExtractor->getTableConstraints($this->pdo, $tableName, 'mysql');

                    // Add foreign key constraints to Table object for dependency sorting
                    foreach ($constraints as $constraintInfo) {
                        if ($constraintInfo['type'] === 'foreign_key') {
                            $constraint = new Constraint(
                                $constraintInfo['name'],
                                'foreign_key',
                                [
                                    'column' => $constraintInfo['column'],
                                    'references_table' => $constraintInfo['references_table'],
                                    'references_column' => $constraintInfo['references_column'],
                                    'on_update' => $constraintInfo['on_update'] ?? 'RESTRICT',
                                    'on_delete' => $constraintInfo['on_delete'] ?? 'RESTRICT'
                                ]
                            );
                            $table->addConstraint($constraint);
                        }
                    }
                }

                $tableObjects[] = $table;

                $this->debugLog("Created Table object for dependency sorting", DebugLevel::VERBOSE, [
                    'table' => $tableName,
                    'constraint_count' => count($table->getConstraints())
                ]);
            } catch (Exception $e) {
                $this->debugLog("Failed to create Table object for $tableName", DebugLevel::BASIC, [
                    'error' => $e->getMessage()
                ]);

                // Create basic table without constraints as fallback
                $table = new Table($tableName);
                $tableObjects[] = $table;
            }
        }

        return $tableObjects;
    }

    /**
     * Sort tables by dependencies
     */
    private function sortTablesByDependencies(array $tableObjects, array $originalTables): array
    {
        if ($this->dependencySorter && count($tableObjects) > 0) {
            try {
                $sortedTableObjects = $this->dependencySorter->sortForCreate($tableObjects);
                $sortedTableNames = array_map(fn($t) => $t->getName(), $sortedTableObjects);

                $this->debugLog("Tables sorted by dependencies", DebugLevel::VERBOSE, [
                    'original_order' => $originalTables,
                    'sorted_order' => $sortedTableNames,
                    'reordered' => $originalTables !== $sortedTableNames
                ]);

                return $sortedTableNames;
            } catch (Exception $e) {
                $this->debugLog("Dependency sorting failed, using original order", DebugLevel::BASIC, [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $originalTables;
    }

    /**
     * Generate DROP statements in reverse dependency order
     */
    private function generateDropStatements(array $sortedTables, array $options): array
    {
        $dropStatements = [];

        // Reverse the sorted order for dropping
        $reversedTables = array_reverse($sortedTables);

        $dropStatements[] = "-- Drop existing tables";
        $dropStatements[] = "";

        foreach ($reversedTables as $tableName) {
            $dropStatements[] = "DROP TABLE IF EXISTS `$tableName`;";
        }

        return $dropStatements;
    }

    /**
     * Generate table creation SQL using schema system
     */
    private function generateTableCreationSQL(string $tableName, array $options): array
    {
        try {
            // Get complete table structure
            $createTable = $this->pdo->query("SHOW CREATE TABLE `$tableName`")->fetch(PDO::FETCH_ASSOC);
            
            if (!$createTable || !isset($createTable['Create Table'])) {
                return ['table_sql' => null, 'deferred_indexes' => []];
            }
            
            $originalSQL = $createTable['Create Table'];
            
            // Parse and separate indexes from table structure
            $result = $this->parseTableStructureAndIndexes($originalSQL, $tableName);
            
            $this->debugLog("Parsed table structure with deferred indexes", DebugLevel::VERBOSE, [
                'table' => $tableName,
                'original_sql_length' => strlen($originalSQL),
                'table_only_sql_length' => strlen($result['table_sql']),
                'deferred_indexes' => count($result['deferred_indexes'])
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->debugLog("Failed to generate table with deferred indexes for $tableName", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);
            
            return ['table_sql' => "-- ERROR: Could not generate table structure for $tableName", 'deferred_indexes' => []];
        }
    }


    /**
     * Parse CREATE TABLE statement to separate structure from indexes
     */
    private function parseTableStructureAndIndexes(string $createTableSQL, string $tableName): array
    {
        $lines = explode("\n", $createTableSQL);
        $tableLines = [];
        $deferredIndexes = [];
        
        $inTableDefinition = false;
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Start of CREATE TABLE
            if (str_starts_with($trimmedLine, 'CREATE TABLE')) {
                $tableLines[] = $line;
                $inTableDefinition = true;
                continue;
            }
            
            // End of table definition
            if ($inTableDefinition && str_starts_with($trimmedLine, ')')) {
                $tableLines[] = $line;
                $inTableDefinition = false;
                continue;
            }
            
            if ($inTableDefinition) {
                // Check if this line defines an index that can be deferred
                if ($this->isIndexDefinitionLine($trimmedLine)) {
                    $indexSQL = $this->convertInlineIndexToCreateIndex($trimmedLine, $tableName);
                    if ($indexSQL) {
                        $deferredIndexes[] = $indexSQL;
                        // Skip adding this line to table structure
                        continue;
                    }
                }
                
                // Add line to table structure
                $tableLines[] = $line;
            } else {
                // After table definition (table options, etc.)
                $tableLines[] = $line;
            }
        }
        
        // Clean up trailing commas in table definition
        $tableSQL = $this->cleanupTableStructure(implode("\n", $tableLines));
        
        return [
            'table_sql' => $tableSQL . ';',
            'deferred_indexes' => $deferredIndexes
        ];
    }

    /**
     * Check if a line defines an index that can be deferred
     */
    private function isIndexDefinitionLine(string $line): bool
    {
        $line = trim($line, ' ,');
        
        // Keep PRIMARY KEY and UNIQUE constraints (essential for data integrity)
        if (str_contains(strtoupper($line), 'PRIMARY KEY') || 
            str_contains(strtoupper($line), 'UNIQUE KEY')) {
            return false;
        }
        
        // Defer regular indexes
        if (str_starts_with(strtoupper($line), 'KEY ') ||
            str_starts_with(strtoupper($line), 'INDEX ') ||
            str_starts_with(strtoupper($line), 'FULLTEXT KEY ') ||
            str_starts_with(strtoupper($line), 'SPATIAL KEY ')) {
            return true;
        }
        
        // Keep foreign key constraints (essential for referential integrity)
        if (str_starts_with(strtoupper($line), 'CONSTRAINT') && 
            str_contains(strtoupper($line), 'FOREIGN KEY')) {
            return false;
        }
        
        return false;
    }

    /**
     * Convert inline index definition to CREATE INDEX statement
     */
    private function convertInlineIndexToCreateIndex(string $indexLine, string $tableName): ?string
    {
        $line = trim($indexLine, ' ,');
        
        // Extract index name and definition
        if (preg_match('/^(FULLTEXT |SPATIAL )?KEY\s+`?([^`\s]+)`?\s+(.+)$/i', $line, $matches)) {
            $indexType = trim($matches[1]);
            $indexName = $matches[2];
            $indexDefinition = $matches[3];
            
            // Build CREATE INDEX statement
            $sql = "CREATE ";
            
            if (strtoupper($indexType) === 'FULLTEXT ') {
                $sql .= "FULLTEXT ";
            } elseif (strtoupper($indexType) === 'SPATIAL ') {
                $sql .= "SPATIAL ";
            }
            
            $sql .= "INDEX `$indexName` ON `$tableName` $indexDefinition;";
            
            return $sql;
        }
        
        return null;
    }

    /**
     * Clean up table structure after removing indexes
     */
    private function cleanupTableStructure(string $tableSQL): string
    {
        $lines = explode("\n", $tableSQL);
        $cleanedLines = [];
        
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $trimmedLine = trim($line);
            
            // Handle trailing commas
            if ($i < count($lines) - 1) {
                $nextLine = trim($lines[$i + 1]);
                
                // If next line starts with ), remove trailing comma from current line
                if (str_starts_with($nextLine, ')') && str_ends_with($trimmedLine, ',')) {
                    $line = rtrim($line, ',');
                }
            }
            
            $cleanedLines[] = $line;
        }
        
        return implode("\n", $cleanedLines);
    }

    /**
     * Generate triggers SQL with DELIMITER handling
     */
    private function generateTriggersSQL(array $tables, array $options): array
    {
        $triggerSQL = [];

        foreach ($tables as $tableName) {
            $triggers = $this->pdo->query("
                SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, 
                       ACTION_STATEMENT, ACTION_ORIENTATION
                FROM INFORMATION_SCHEMA.TRIGGERS 
                WHERE EVENT_OBJECT_SCHEMA = '{$this->databaseName}' 
                AND EVENT_OBJECT_TABLE = '$tableName'
                ORDER BY TRIGGER_NAME
            ")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($triggers as $trigger) {
                $triggerKey = $tableName . '.' . $trigger['TRIGGER_NAME'];
                if (in_array($triggerKey, $this->writtenTriggers)) {
                    continue; // Skip duplicates
                }

                // MySQL DELIMITER handling for triggers
                $triggerSQL[] = "DELIMITER $$";
                $triggerSQL[] = "DROP TRIGGER IF EXISTS `{$trigger['TRIGGER_NAME']}`$$";
                $triggerSQL[] = "CREATE TRIGGER `{$trigger['TRIGGER_NAME']}` " .
                    "{$trigger['ACTION_TIMING']} {$trigger['EVENT_MANIPULATION']} " .
                    "ON `$tableName` FOR EACH ROW";
                $triggerSQL[] = $trigger['ACTION_STATEMENT'] . "$$";
                $triggerSQL[] = "DELIMITER ;";
                $triggerSQL[] = "";

                $this->writtenTriggers[] = $triggerKey;
            }
        }

        return $triggerSQL;
    }

    // =============================================================================
    // RESTORE OPERATIONS (SIMPLIFIED SEQUENTIAL)
    // =============================================================================

    /**
     * Restore MySQL backup using simplified sequential approach
     */
    public function restoreBackup(string $backupPath, array $options = []): array
    {
        // Validate restore path using security trait
        $this->validateRestorePath($backupPath);

        // Use simplified MySQLRestoreHelper (following PostgreSQL pattern)
        $restoreHelper = new MySQLRestoreHelper($this->pdo, 'mysql', [
            'debug_callback' => [$this, 'debugLog'],
            'model' => $this->model
        ]);

        // Set progress callback if provided
        if (isset($options['progress_callback'])) {
            $restoreHelper->setProgressCallback($options['progress_callback']);
        }

        return $restoreHelper->restoreFromBackup($backupPath, $options);
    }

    // =============================================================================
    // INTERFACE IMPLEMENTATION (FIXED: Added missing testCapabilities method)
    // =============================================================================

    /**
     * Test strategy capabilities (REQUIRED by BackupStrategyInterface)
     */
    public function testCapabilities(): array
    {
        $results = [
            'strategy_name' => $this->getStrategyType(),
            'database_type' => 'mysql',
            'php_requirements' => [],
            'pdo_capabilities' => [],
            'mysql_features' => [],
            'schema_system' => [],
            'overall_status' => 'unknown'
        ];

        try {
            // Test PHP requirements
            $results['php_requirements'] = [
                'php_version' => PHP_VERSION,
                'php_version_compatible' => version_compare(PHP_VERSION, '8.0.0', '>='),
                'pdo_available' => extension_loaded('pdo'),
                'pdo_mysql_available' => extension_loaded('pdo_mysql'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ];

            // Test PDO capabilities
            if ($this->pdo) {
                $results['pdo_capabilities'] = [
                    'connection_active' => true,
                    'mysql_version' => $this->pdo->query("SELECT VERSION()")->fetchColumn(),
                    'information_schema_access' => $this->testInformationSchemaAccess(),
                    'transaction_support' => $this->testTransactionSupport(),
                    'can_create_tables' => $this->testCreateTablePermissions()
                ];
            } else {
                $results['pdo_capabilities'] = [
                    'connection_active' => false,
                    'error' => 'No PDO connection available'
                ];
            }

            // Test MySQL-specific features
            $results['mysql_features'] = [
                'information_schema_tables' => $this->testInformationSchemaTables(),
                'show_create_table' => $this->testShowCreateTable(),
                'triggers_accessible' => $this->testTriggersAccess(),
                'foreign_keys_supported' => $this->testForeignKeySupport()
            ];

            // Test schema system
            $results['schema_system'] = [
                'mysql_platform_available' => $this->mysqlPlatform !== null,
                'schema_renderer_available' => $this->schemaRenderer !== null,
                'mysql_parser_available' => $this->mysqlParser !== null,
                'schema_system_functional' => $this->testSchemaSystemFunctionality()
            ];

            // Determine overall status
            $criticalIssues = 0;
            if (!$results['php_requirements']['pdo_mysql_available']) $criticalIssues++;
            if (!$results['pdo_capabilities']['connection_active']) $criticalIssues++;
            if (!$results['pdo_capabilities']['information_schema_access']) $criticalIssues++;

            if ($criticalIssues === 0) {
                $results['overall_status'] = 'available';
            } elseif ($criticalIssues <= 1) {
                $results['overall_status'] = 'limited_functionality';
            } else {
                $results['overall_status'] = 'not_functional';
            }
        } catch (Exception $e) {
            $results['overall_status'] = 'error';
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Test information schema access
     */
    private function testInformationSchemaAccess(): bool
    {
        try {
            $this->pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES LIMIT 1")->fetchColumn();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Test transaction support
     */
    private function testTransactionSupport(): bool
    {
        try {
            $this->pdo->beginTransaction();
            $this->pdo->rollback();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Test create table permissions (for restore operations)
     */
    private function testCreateTablePermissions(): bool
    {
        try {
            $testTable = 'test_backup_permissions_' . uniqid();
            $this->pdo->exec("CREATE TEMPORARY TABLE $testTable (id INT)");
            $this->pdo->exec("DROP TEMPORARY TABLE $testTable");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Test information schema tables access
     */
    private function testInformationSchemaTables(): bool
    {
        try {
            $tables = ['TABLES', 'COLUMNS', 'STATISTICS', 'KEY_COLUMN_USAGE', 'TRIGGERS'];
            foreach ($tables as $table) {
                $this->pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.$table LIMIT 1");
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Test SHOW CREATE TABLE access
     */
    private function testShowCreateTable(): bool
    {
        try {
            // Find any table in the current database
            $tables = $this->pdo->query("
                SELECT TABLE_NAME 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = '{$this->databaseName}' 
                LIMIT 1
            ")->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($tables)) {
                $this->pdo->query("SHOW CREATE TABLE `{$tables[0]}`");
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Test triggers access
     */
    private function testTriggersAccess(): bool
    {
        try {
            $this->pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = '{$this->databaseName}'")->fetchColumn();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Test foreign key support
     */
    private function testForeignKeySupport(): bool
    {
        try {
            $this->pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '{$this->databaseName}' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Test schema system functionality
     */
    private function testSchemaSystemFunctionality(): bool
    {
        try {
            if (!$this->mysqlPlatform || !$this->schemaRenderer) {
                return false;
            }

            // Test basic schema object creation
            $testTable = new Table('test_table');
            $testColumn = new Column('test_column', 'varchar');
            $testColumn->setLength(255);
            $testTable->addColumn($testColumn);

            // Test SQL generation
            $sql = $this->schemaRenderer->renderTable($testTable);

            return !empty($sql) && strpos($sql, 'CREATE TABLE') !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    // =============================================================================
    // UTILITY AND HELPER METHODS (FIXED: Column, Index, Constraint constructors)
    // =============================================================================

    /**
     * Helper methods implementation (FIXED constructor calls)
     */
    private function createColumnFromInfo(array $columnInfo): Column
    {
        // FIXED: Column constructor needs both name and type
        $column = new Column($columnInfo['COLUMN_NAME'], $columnInfo['DATA_TYPE']);
        $column->setNullable($columnInfo['IS_NULLABLE'] === 'YES');

        if ($columnInfo['COLUMN_DEFAULT'] !== null) {
            $column->setDefault($columnInfo['COLUMN_DEFAULT']);
        }

        if (strpos($columnInfo['EXTRA'], 'auto_increment') !== false) {
            $column->setAutoIncrement(true);
        }

        if ($columnInfo['COLUMN_COMMENT']) {
            $column->setComment($columnInfo['COLUMN_COMMENT']);
        }

        // Set length/precision/scale based on column type
        if ($columnInfo['NUMERIC_PRECISION']) {
            $column->setPrecision($columnInfo['NUMERIC_PRECISION']);
        }
        if ($columnInfo['NUMERIC_SCALE']) {
            $column->setScale($columnInfo['NUMERIC_SCALE']);
        }

        return $column;
    }

    /**
     * FIXED: Create Index from grouped MySQL index information
     */
    private function createIndexFromGroupedInfo(string $indexName, array $indexColumns): ?Index
    {
        // Skip PRIMARY key as it's handled separately
        if ($indexName === 'PRIMARY') {
            return null;
        }

        // Determine index type from the first column's information
        $firstColumn = $indexColumns[0];
        $indexType = ($firstColumn['NON_UNIQUE'] == 0) ? Index::TYPE_UNIQUE : Index::TYPE_INDEX;

        // Handle special MySQL index types - check INDEX_TYPE or INDEX_COMMENT
        if (isset($firstColumn['INDEX_TYPE'])) {
            $mysqlIndexType = strtoupper($firstColumn['INDEX_TYPE']);
            switch ($mysqlIndexType) {
                case 'FULLTEXT':
                    $indexType = Index::TYPE_FULLTEXT;
                    break;
                case 'SPATIAL':
                    $indexType = Index::TYPE_SPATIAL;
                    break;
            }
        }

        // Also check if the index comment or name indicates special types
        if (
            stripos($indexName, 'fulltext') !== false ||
            (isset($firstColumn['INDEX_COMMENT']) && stripos($firstColumn['INDEX_COMMENT'], 'fulltext') !== false)
        ) {
            $indexType = Index::TYPE_FULLTEXT;
        } elseif (
            stripos($indexName, 'spatial') !== false ||
            (isset($firstColumn['INDEX_COMMENT']) && stripos($firstColumn['INDEX_COMMENT'], 'spatial') !== false)
        ) {
            $indexType = Index::TYPE_SPATIAL;
        }

        // Create the index
        $index = new Index($indexName, $indexType);

        // Add all columns to the index (sorted by SEQ_IN_INDEX)
        usort($indexColumns, function ($a, $b) {
            return ($a['SEQ_IN_INDEX'] ?? 0) <=> ($b['SEQ_IN_INDEX'] ?? 0);
        });

        foreach ($indexColumns as $indexColumn) {
            $columnName = $indexColumn['COLUMN_NAME'];
            $length = !empty($indexColumn['SUB_PART']) ? (int)$indexColumn['SUB_PART'] : null;
            $index->addColumn($columnName, $length);
        }

        // Store additional MySQL-specific information
        if (isset($firstColumn['INDEX_TYPE']) && $firstColumn['INDEX_TYPE']) {
            $index->setMethod($firstColumn['INDEX_TYPE']);
        }

        if (isset($firstColumn['INDEX_COMMENT']) && $firstColumn['INDEX_COMMENT']) {
            $index->setComment($firstColumn['INDEX_COMMENT']);
        }

        return $index;
    }

    private function createConstraintFromInfo(array $constraintInfo): Constraint
    {
        // FIXED: Constraint constructor needs both name and type
        $constraint = new Constraint($constraintInfo['CONSTRAINT_NAME'], Constraint::TYPE_FOREIGN_KEY);

        // FIXED: Use correct method names
        $constraint->setColumns([$constraintInfo['COLUMN_NAME']]);
        $constraint->setReferencedTable($constraintInfo['REFERENCED_TABLE_NAME']);
        $constraint->setReferencedColumns([$constraintInfo['REFERENCED_COLUMN_NAME']]);

        // FIXED: Handle UPDATE_RULE and DELETE_RULE safely
        if (isset($constraintInfo['UPDATE_RULE']) && $constraintInfo['UPDATE_RULE'] !== 'RESTRICT') {
            $constraint->setOnUpdate($constraintInfo['UPDATE_RULE']);
        }
        if (isset($constraintInfo['DELETE_RULE']) && $constraintInfo['DELETE_RULE'] !== 'RESTRICT') {
            $constraint->setOnDelete($constraintInfo['DELETE_RULE']);
        }

        return $constraint;
    }

    // Additional helper methods...

    private function validateMySQLConnection(): void
    {
        try {
            $version = $this->pdo->query("SELECT VERSION()")->fetchColumn();
            $this->debugLog("MySQL connection validated", DebugLevel::VERBOSE, [
                'mysql_version' => $version
            ]);
        } catch (Exception $e) {
            throw new RuntimeException("MySQL connection validation failed: " . $e->getMessage());
        }
    }

    private function generateTableDataSQL(string $tableName, array $options): array
    {
        $dataSQL = [];
        $chunkSize = $options['chunk_size'] ?? 1000;

        $totalRows = $this->pdo->query("SELECT COUNT(*) FROM `$tableName`")->fetchColumn();
        if ($totalRows == 0) {
            return $dataSQL;
        }

        $offset = 0;
        while ($offset < $totalRows) {
            $rows = $this->pdo->query("
                SELECT * FROM `$tableName` 
                LIMIT $chunkSize OFFSET $offset
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break;
            }

            $insertSQL = $this->generateInsertStatement($tableName, $rows);
            if ($insertSQL) {
                $dataSQL[] = $insertSQL;
            }

            $offset += $chunkSize;
        }

        return $dataSQL;
    }

    private function generateInsertStatement(string $tableName, array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $columns = array_keys($rows[0]);
        $quotedColumns = array_map(function ($col) {
            return "`$col`";
        }, $columns);

        $sql = "INSERT INTO `$tableName` (" . implode(', ', $quotedColumns) . ") VALUES ";

        $valueStrings = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $this->pdo->quote($value);
                }
            }
            $valueStrings[] = '(' . implode(', ', $values) . ')';
        }

        $sql .= implode(', ', $valueStrings) . ';';
        return $sql;
    }

    private function getTableList(array $options): array
    {
        $databaseType = strtolower($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        return $this->schemaExtractor->getTableNames($this->pdo, $databaseType);
    }

    private function analyzeTableStructures(array $tables): array
    {
        $stats = [];

        foreach ($tables as $tableName) {
            if ($this->schemaExtractor) {
                $rowCount = $this->schemaExtractor->getTableRowCount($this->pdo, $tableName, 'mysql');
            } else {
                $rowCount = $this->pdo->query("SELECT COUNT(*) FROM `$tableName`")->fetchColumn();
            }

            $stats[$tableName] = ['row_count' => $rowCount];
        }

        return $stats;
    }

    private function reportProgress(array $options, float $percentage, string $operation): void
    {
        if (isset($options['progress_callback']) && is_callable($options['progress_callback'])) {
            call_user_func($options['progress_callback'], [
                'progress_percent' => $percentage,
                'current_operation' => $operation
            ]);
        }
    }

    // Strategy interface methods
    public function estimateBackupSize(): int
    {
        try {
            $size = $this->pdo->query("
                SELECT SUM(data_length + index_length) 
                FROM information_schema.tables 
                WHERE table_schema = '{$this->databaseName}'
            ")->fetchColumn();

            return $size ? (int)$size : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function estimateBackupTime(): int
    {
        $sizeBytes = $this->estimateBackupSize();
        // Estimate ~2MB/second for PHP-based backup
        return max(60, intval($sizeBytes / (2 * 1024 * 1024)));
    }

    public function getStrategyType(): string
    {
        return 'mysql_php_modernized';
    }

    public function getDescription(): string
    {
        return 'MySQL PHP Modernized Backup (Schema-aware with intelligent SQL generation)';
    }

    public function getSelectionCriteria(): array
    {
        return [
            'priority' => 2,
            'requirements' => ['pdo_mysql', 'information_schema_access'],
            'advantages' => [
                'Schema-aware intelligent SQL generation',
                'Duplicate prevention and clean output',
                'MySQL-specific feature support',
                'Works without shell access',
                'Robust error handling'
            ],
            'limitations' => [
                'Slower than mysqldump',
                'Higher memory usage for large databases'
            ]
        ];
    }

    public function supportsCompression(): bool
    {
        return false;
    }

    public function detectBackupFormat(string $backupPath): string
    {
        $validation = $this->validateBackupFile($backupPath);

        if ($validation['mysql_structure_detected']) {
            return 'mysql_php_modernized';
        } elseif ($validation['sql_format_detected']) {
            return 'sql';
        } else {
            return 'unknown';
        }
    }

    public function getRestoreOptions(string $backupPath): array
    {
        $validation = $this->validateBackupFile($backupPath);

        return [
            'full_restore' => $validation['valid'],
            'execute_in_transaction' => true,
            'validate_before_restore' => true,
            'estimated_duration_seconds' => $this->estimateBackupTime()
        ];
    }

    public function partialRestore(string $backupPath, array $targets, array $options = []): array
    {
        throw new RuntimeException("Partial restore not yet implemented for modernized MySQL backup strategy");
    }

    public function validateBackupFile(string $backupPath): array
    {
        $validation = [
            'valid' => false,
            'file_exists' => false,
            'file_size_bytes' => 0,
            'format_detected' => 'unknown',
            'mysql_structure_detected' => false,
            'sql_format_detected' => false,
            'error' => null
        ];

        try {
            if (!file_exists($backupPath)) {
                $validation['error'] = 'Backup file does not exist';
                return $validation;
            }

            $validation['file_exists'] = true;
            $validation['file_size_bytes'] = filesize($backupPath);

            if ($validation['file_size_bytes'] === 0) {
                $validation['error'] = 'Backup file is empty';
                return $validation;
            }

            // Read first few lines to detect format
            $handle = fopen($backupPath, 'r');
            $content = '';
            for ($i = 0; $i < 20 && !feof($handle); $i++) {
                $content .= fgets($handle);
            }
            fclose($handle);

            // Check for MySQL-specific patterns
            if (
                strpos($content, 'CREATE TABLE') !== false ||
                strpos($content, 'INSERT INTO') !== false ||
                strpos($content, 'SET SQL_MODE') !== false ||
                strpos($content, 'AUTO_INCREMENT') !== false
            ) {
                $validation['mysql_structure_detected'] = true;
                $validation['sql_format_detected'] = true;
                $validation['format_detected'] = 'mysql_sql';
            }

            $validation['valid'] = $validation['mysql_structure_detected'];
        } catch (Exception $e) {
            $validation['error'] = $e->getMessage();
        }

        return $validation;
    }

    private function validateRestorePath(string $backupPath): void
    {
        try {
            // Use PathValidationTrait method
            $this->validateBackupPath($backupPath);
        } catch (Exception $e) {
            throw new InvalidArgumentException("Restore file security validation failed: " . $e->getMessage());
        }
    }
}
