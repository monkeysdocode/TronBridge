<?php

require_once dirname(__DIR__, 2) . '/exceptions/BackupException.php';
require_once dirname(__DIR__, 3) . '/engine/schema/core/SchemaDependencySorter.php';
require_once dirname(__DIR__, 3) . '/engine/schema/core/SchemaRenderer.php';
require_once dirname(__DIR__, 3) . '/engine/schema/platforms/SQLitePlatform.php';
require_once dirname(__DIR__, 3) . '/engine/schema/parsers/SQLiteParser.php';
require_once dirname(__DIR__, 3) . '/engine/core/DatabaseSQLParser.php';
require_once dirname(__DIR__, 1) . '/interfaces/BackupStrategyInterface.php';
require_once dirname(__DIR__, 1) . '/interfaces/RestoreStrategyInterface.php';

/**
 * SQLite SQL Dump Strategy - Clean Modernized Implementation
 * 
 * FIXED: Removed all duplicate/conflicting methods and cleaned up for proper
 * dependency-sorted schema export using SchemaDependencySorter
 */
class SQLiteSQLDumpStrategy implements BackupStrategyInterface, RestoreStrategyInterface
{
    use DebugLoggingTrait;
    use ConfigSanitizationTrait;
    use PathValidationTrait;

    private Model $model;
    private PDO $pdo;
    private array $connectionConfig;
    private string $databasePath;

    // Schema system components
    private ?SQLiteParser $sqliteParser = null;
    private ?SQLitePlatform $sqlitePlatform = null;
    private ?SchemaRenderer $schemaRenderer = null;

    // SQLite-specific configuration
    private ?string $sqliteVersion = null;
    private bool $supportsForeignKeys = false;
    private bool $supportsStrictTables = false;

    public function __construct(Model $model, array $connectionConfig)
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->connectionConfig = $connectionConfig;
        $this->databasePath = $this->extractDatabasePath();

        $this->initializeSchemaSystem();
        $this->detectSQLiteCapabilities();

        $this->debugLog("SQLite strategy initialized with schema system", DebugLevel::BASIC, [
            'database_path' => $this->databasePath,
            'sqlite_version' => $this->sqliteVersion,
            'schema_system_enabled' => $this->schemaRenderer !== null,
            'foreign_keys_supported' => $this->supportsForeignKeys,
            'strict_tables_supported' => $this->supportsStrictTables
        ]);
    }

    private function initializeSchemaSystem(): void
    {
        try {
            $this->sqliteParser = new SQLiteParser();
            $this->sqlitePlatform = new SQLitePlatform();
            $this->schemaRenderer = new SchemaRenderer($this->sqlitePlatform);

            if (method_exists($this->schemaRenderer, 'setDebugCallback')) {
                $this->schemaRenderer->setDebugCallback(function ($message, $context = []) {
                    $this->debugLog("Schema System: " . $message, DebugLevel::VERBOSE, $context);
                });
            }

            $this->debugLog("SQLite schema system initialized successfully", DebugLevel::VERBOSE);
        } catch (Exception $e) {
            $this->debugLog("SQLite schema system initialization failed", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function detectSQLiteCapabilities(): void
    {
        try {
            $stmt = $this->pdo->query("SELECT sqlite_version()");
            $this->sqliteVersion = $stmt->fetchColumn();

            $stmt = $this->pdo->query("PRAGMA foreign_keys");
            $this->supportsForeignKeys = $stmt !== false;

            if (version_compare($this->sqliteVersion, '3.37.0', '>=')) {
                $this->supportsStrictTables = true;
            }
        } catch (PDOException $e) {
            $this->debugLog("Could not detect SQLite capabilities", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function extractDatabasePath(): string
    {
        if (isset($this->connectionConfig['database'])) {
            return $this->connectionConfig['database'];
        }

        try {
            $stmt = $this->pdo->query("PRAGMA database_list");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['file'] ?? ':memory:';
        } catch (PDOException $e) {
            return 'unknown';
        }
    }

    // =============================================================================
    // MAIN BACKUP METHOD (CLEAN VERSION)
    // =============================================================================

    public function createBackup(string $outputPath, array $options = []): array
    {
        $startTime = microtime(true);
        $this->validateBackupPath($outputPath);

        $backupOptions = array_merge([
            'include_schema' => true,
            'include_data' => true,
            'include_indexes' => true,
            'include_constraints' => true,
            'include_triggers' => true,
            'include_views' => true,
            'include_pragma_settings' => true,
            'include_drop_statements' => true,
            'single_transaction' => true,
            'chunk_size' => 1000,
            'validate_backup' => true,
            'progress_callback' => null
        ], $options);

        $this->debugLog("Starting SQLite backup with schema system", DebugLevel::BASIC, [
            'output_path' => $outputPath,
            'schema_system_enabled' => $this->schemaRenderer !== null
        ]);

        try {
            if ($backupOptions['single_transaction']) {
                $this->pdo->beginTransaction();
            }

            $tables = $this->getSQLiteTables();
            $backupResult = $this->generateCleanSchemaBackup($outputPath, $tables, $backupOptions);

            if ($backupOptions['single_transaction'] && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            $duration = microtime(true) - $startTime;
            $validationResult = null;
            if ($backupOptions['validate_backup']) {
                $validationResult = $this->validateBackupFileInternal($outputPath);
            }

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
            if ($backupOptions['single_transaction'] && $this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }

            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_seconds' => microtime(true) - $startTime,
                'strategy_used' => $this->getStrategyType()
            ];
        }
    }

    // =============================================================================
    // CLEAN SCHEMA BACKUP GENERATION (DEPENDENCY-SORTED)
    // =============================================================================

    private function generateCleanSchemaBackup(string $outputPath, array $tables, array $options): array
    {
        $statistics = [
            'tables_backed_up' => 0,
            'rows_backed_up' => 0,
            'indexes_backed_up' => 0,
            'constraints_backed_up' => 0,
            'triggers_backed_up' => 0,
            'views_backed_up' => 0
        ];

        $handle = fopen($outputPath, 'w');
        if (!$handle) {
            throw new BackupException("Cannot open output file for writing: $outputPath");
        }

        try {
            $this->writeSQLHeader($handle, $options);

            $this->debugLog("Creating Table objects and sorting dependencies", DebugLevel::VERBOSE);

            $tableObjects = [];
            foreach ($tables as $tableInfo) {
                $tableName = $tableInfo['name'];
                $tableStructure = $this->getTableStructure($tableName);
                $tableObjects[] = $this->createTableObject($tableName, $tableStructure);
            }

            // Use SchemaDependencySorter for proper ordering
            $dependencySorter = new SchemaDependencySorter();
            $sortedTables = $dependencySorter->sortForCreate($tableObjects);

            $this->debugLog("Tables sorted by dependencies", DebugLevel::VERBOSE, [
                'sorted_order' => array_map(fn($t) => $t->getName(), $sortedTables)
            ]);

            // STEP 1: Write DROP statements (reverse dependency order)
            if ($options['include_drop_statements'] ?? true) {
                fwrite($handle, "\n-- ===============================================\n");
                fwrite($handle, "-- Phase 0: Drop Existing Objects\n");
                fwrite($handle, "-- ===============================================\n\n");

                $this->writeDropStatements($handle, $sortedTables, $options);
            }

            // STEP 2: Export table structures (Phase 1)
            fwrite($handle, "\n-- ===============================================\n");
            fwrite($handle, "-- Phase 1: Table Structures\n");
            fwrite($handle, "-- ===============================================\n\n");

            foreach ($sortedTables as $table) {
                $this->exportSingleTableStructure($handle, $table);
                $statistics['tables_backed_up']++;
            }

            // STEP 3: Export table data (Phase 2)
            if ($options['include_data']) {
                fwrite($handle, "\n-- ===============================================\n");
                fwrite($handle, "-- Phase 2: Table Data\n");
                fwrite($handle, "-- ===============================================\n\n");

                foreach ($sortedTables as $table) {
                    $rowCount = $this->exportSingleTableData($handle, $table->getName(), $options);
                    $statistics['rows_backed_up'] += $rowCount;
                }
            }

            // STEP 4: Export indexes (Phase 3)
            if ($options['include_indexes']) {
                fwrite($handle, "\n-- ===============================================\n");
                fwrite($handle, "-- Phase 3: Indexes\n");
                fwrite($handle, "-- ===============================================\n\n");

                foreach ($sortedTables as $table) {
                    $indexCount = $this->exportTableIndexes($handle, $table->getName());
                    $statistics['indexes_backed_up'] += $indexCount;
                }
            }

            // STEP 5: Export triggers (Phase 4)
            if ($options['include_triggers']) {
                fwrite($handle, "\n-- ===============================================\n");
                fwrite($handle, "-- Phase 4: Triggers\n");
                fwrite($handle, "-- ===============================================\n\n");

                $statistics['triggers_backed_up'] = $this->exportAllTriggers($handle);
            }

            // STEP 6: Export views (Phase 5)
            if ($options['include_views']) {
                fwrite($handle, "\n-- ===============================================\n");
                fwrite($handle, "-- Phase 5: Views\n");
                fwrite($handle, "-- ===============================================\n\n");

                $statistics['views_backed_up'] = $this->exportAllViews($handle);
            }

            $this->writeSQLFooter($handle, $options);
            return ['statistics' => $statistics];
        } finally {
            fclose($handle);
        }
    }

    private function exportSingleTableStructure($handle, Table $table): void
    {
        $tableName = $table->getName();

        try {
            // Create clean table with only inline constraints
            $cleanTable = $this->createTableForInlineExport($table);

            // Generate SQL - prefer schema renderer
            if ($this->schemaRenderer) {
                $sql = $this->schemaRenderer->renderTable($cleanTable);
            } else {
                $tableStructure = $this->getTableStructure($tableName);
                $sql = $this->generateFallbackTableSQL($tableName, $tableStructure);
            }

            fwrite($handle, "-- Table: $tableName\n");
            fwrite($handle, $sql . "\n\n");
        } catch (Exception $e) {
            $this->debugLog("Failed to export table structure", DebugLevel::BASIC, [
                'table' => $tableName,
                'error' => $e->getMessage()
            ]);
            fwrite($handle, "-- ERROR: Could not export table $tableName: " . $e->getMessage() . "\n\n");
        }
    }

    private function createTableForInlineExport(Table $originalTable): Table
    {
        $cleanTable = new Table($originalTable->getName());

        // Copy all columns
        foreach ($originalTable->getColumns() as $column) {
            $cleanTable->addColumn(clone $column);
        }

        // Only include PRIMARY KEY if it's NOT handled inline by AUTOINCREMENT
        foreach ($originalTable->getIndexes() as $index) {
            if ($index->getType() === Index::TYPE_PRIMARY) {
                $autoIncrementCol = $originalTable->getAutoIncrementColumn();
                $indexColumns = array_keys($index->getColumns());

                // Skip single-column AUTOINCREMENT primary keys (handled inline)
                if (
                    $autoIncrementCol && count($indexColumns) === 1 &&
                    $indexColumns[0] === $autoIncrementCol->getName()
                ) {
                    continue;
                }

                // Add composite or non-AUTOINCREMENT primary keys as constraints
                $cleanTable->addIndex(clone $index);
            }
        }

        // Add inline CHECK constraints only (no foreign keys)
        foreach ($originalTable->getConstraints() as $constraint) {
            if ($constraint->getType() !== Constraint::TYPE_FOREIGN_KEY) {
                $cleanTable->addConstraint(clone $constraint);
            }
        }

        // Copy table options
        foreach ($originalTable->getOptions() as $option => $value) {
            $cleanTable->setOption($option, $value);
        }

        return $cleanTable;
    }

    private function exportTableIndexes($handle, string $tableName): int
    {
        $exported = 0;
        $indexes = $this->getSQLiteIndexes($tableName);

        foreach ($indexes as $indexInfo) {
            // Skip auto-created indexes
            if (strpos($indexInfo['name'], 'sqlite_autoindex_') === 0) {
                continue;
            }

            $sql = $this->generateCreateIndexSQL($tableName, $indexInfo);
            if ($sql) {
                fwrite($handle, $sql . ";\n");
                $exported++;
            }
        }

        return $exported;
    }

    private function exportAllTriggers($handle): int
    {
        $stmt = $this->pdo->query("
            SELECT sql FROM sqlite_master 
            WHERE type = 'trigger' AND sql IS NOT NULL
            ORDER BY name
        ");

        $exported = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fwrite($handle, $row['sql'] . ";\n\n");
            $exported++;
        }

        return $exported;
    }

    private function exportAllViews($handle): int
    {
        $stmt = $this->pdo->query("
            SELECT sql FROM sqlite_master 
            WHERE type = 'view' AND sql IS NOT NULL
            ORDER BY name
        ");

        $exported = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fwrite($handle, $row['sql'] . ";\n\n");
            $exported++;
        }

        return $exported;
    }

    // =============================================================================
    // SQLITE METADATA EXTRACTION
    // =============================================================================

    private function getSQLiteTables(): array
    {
        $stmt = $this->pdo->query("
            SELECT name, type, sql 
            FROM sqlite_master 
            WHERE type = 'table' AND name NOT LIKE 'sqlite_%' 
            ORDER BY name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTableStructure(string $tableName): array
    {
        return [
            'columns' => $this->getSQLiteTableInfo($tableName),
            'indexes' => $this->getSQLiteIndexes($tableName),
            'constraints' => $this->getSQLiteConstraints($tableName),
            'triggers' => $this->getSQLiteTriggers($tableName),
            'options' => $this->getSQLiteTableOptions($tableName)
        ];
    }

    private function getSQLiteTableInfo(string $tableName): array
    {
        $stmt = $this->pdo->prepare("PRAGMA table_info(" . $this->quoteName($tableName) . ")");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getSQLiteIndexes(string $tableName): array
    {
        $indexes = [];

        $stmt = $this->pdo->prepare("PRAGMA index_list(" . $this->quoteName($tableName) . ")");
        $stmt->execute();
        $indexList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($indexList as $index) {
            $stmt2 = $this->pdo->prepare("PRAGMA index_info(" . $this->quoteName($index['name']) . ")");
            $stmt2->execute();
            $columns = $stmt2->fetchAll(PDO::FETCH_COLUMN, 2);

            // Get WHERE clause for partial indexes
            $whereClause = null;
            $stmt3 = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?");
            $stmt3->execute([$index['name']]);
            $indexSQL = $stmt3->fetchColumn();

            if ($indexSQL && preg_match('/WHERE\s+(.+)$/i', $indexSQL, $matches)) {
                $whereClause = $matches[1];
            }

            $indexes[] = [
                'name' => $index['name'],
                'unique' => $index['unique'] == 1,
                'columns' => $columns,
                'where' => $whereClause
            ];
        }

        return $indexes;
    }

    private function getSQLiteConstraints(string $tableName): array
    {
        $constraints = [];

        $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$tableName]);
        $createSQL = $stmt->fetchColumn();

        if ($createSQL && $this->sqliteParser) {
            try {
                $table = $this->sqliteParser->parseCreateTable($createSQL);

                foreach ($table->getConstraints() as $constraint) {
                    $constraints[] = [
                        'name' => $constraint->getName(),
                        'type' => $constraint->getType(),
                        'columns' => $constraint->getColumns(),
                        'referenced_table' => $constraint->getReferencedTable(),
                        'referenced_columns' => $constraint->getReferencedColumns(),
                        'on_delete' => $constraint->getOnDelete(),
                        'on_update' => $constraint->getOnUpdate(),
                        'expression' => $constraint->getExpression()
                    ];
                }
            } catch (Exception $e) {
                $this->debugLog("Could not parse constraints for table $tableName", DebugLevel::VERBOSE, [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $constraints;
    }

    private function getSQLiteTriggers(string $tableName): array
    {
        $stmt = $this->pdo->prepare("
            SELECT name, sql FROM sqlite_master 
            WHERE type = 'trigger' AND tbl_name = ? 
            ORDER BY name
        ");
        $stmt->execute([$tableName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getSQLiteTableOptions(string $tableName): array
    {
        $options = [];

        $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$tableName]);
        $createSQL = $stmt->fetchColumn();

        if ($createSQL) {
            if (preg_match('/\bWITHOUT\s+ROWID\b/i', $createSQL)) {
                $options['without_rowid'] = true;
            }

            if ($this->supportsStrictTables && preg_match('/\bSTRICT\b/i', $createSQL)) {
                $options['strict'] = true;
            }
        }

        return $options;
    }

    // =============================================================================
    // TABLE/SCHEMA OBJECT CREATION
    // =============================================================================

    private function createTableObject(string $tableName, array $tableInfo): Table
    {
        $table = new Table($tableName);

        // Add columns
        foreach ($tableInfo['columns'] as $columnInfo) {
            $column = $this->createColumnFromInfo($columnInfo);
            $table->addColumn($column);
        }

        // Handle primary key correctly
        $primaryKeyColumns = [];
        $hasAutoIncrementPK = false;

        foreach ($tableInfo['columns'] as $columnInfo) {
            if ($columnInfo['pk'] ?? false) {
                $primaryKeyColumns[] = $columnInfo['name'];

                if (
                    strtolower($columnInfo['type']) === 'integer' &&
                    $this->isAutoIncrementColumn($columnInfo['name'])
                ) {
                    $hasAutoIncrementPK = true; 

                }
            }
        }

        // Only create separate PRIMARY KEY constraint if NOT single AUTOINCREMENT
        if (!empty($primaryKeyColumns)) {
            $isSingleAutoIncrement = (count($primaryKeyColumns) === 1 && $hasAutoIncrementPK);

            //if (!$isSingleAutoIncrement) {
                $primaryIndex = new Index('PRIMARY', Index::TYPE_PRIMARY);
                foreach ($primaryKeyColumns as $pkColumn) {
                    $primaryIndex->addColumn($pkColumn);
                }
                $table->addIndex($primaryIndex);
            //}
        }

        // Add other indexes
        foreach ($tableInfo['indexes'] as $indexInfo) {
            $index = $this->createIndexFromInfo($indexInfo);
            if ($index && $index->getName() !== 'PRIMARY') {
                $table->addIndex($index);
            }
        }

        // Add constraints
        foreach ($tableInfo['constraints'] as $constraintInfo) {
            $constraint = $this->createConstraintFromInfo($constraintInfo);
            if ($constraint) {
                $table->addConstraint($constraint);
            }
        }

        // Set table options
        foreach ($tableInfo['options'] as $option => $value) {
            $table->setOption($option, $value);
        }

        return $table;
    }

    /**
     * Fix getSQLiteTableInfo to clean up default values
     * 
     * Add this method to SQLiteSQLDumpStrategy.php or update existing one
     */
    private function cleanSQLiteDefaultValue(?string $defaultValue): ?string
    {
        if ($defaultValue === null) {
            return null;
        }

        $cleaned = trim($defaultValue);

        // Map SQLite datetime functions to standard SQL constants
        $mappings = [
            // Basic datetime functions
            '/^datetime\s*\(\s*[\'"]now[\'"]\s*\)$/i' => 'CURRENT_TIMESTAMP',
            '/^date\s*\(\s*[\'"]now[\'"]\s*\)$/i' => 'CURRENT_DATE',
            '/^time\s*\(\s*[\'"]now[\'"]\s*\)$/i' => 'CURRENT_TIME',

            // With parentheses around the whole expression
            '/^\(\s*datetime\s*\(\s*[\'"]now[\'"]\s*\)\s*\)$/i' => 'CURRENT_TIMESTAMP',
            '/^\(\s*date\s*\(\s*[\'"]now[\'"]\s*\)\s*\)$/i' => 'CURRENT_DATE',
            '/^\(\s*time\s*\(\s*[\'"]now[\'"]\s*\)\s*\)$/i' => 'CURRENT_TIME',

            // strftime equivalents
            '/^strftime\s*\(\s*[\'"]%Y-%m-%d %H:%M:%S[\'"],\s*[\'"]now[\'"]\s*\)$/i' => 'CURRENT_TIMESTAMP',
            '/^strftime\s*\(\s*[\'"]%Y-%m-%d[\'"],\s*[\'"]now[\'"]\s*\)$/i' => 'CURRENT_DATE',

            // julianday and other SQLite-specific functions to ignore (keep as-is)
            '/^julianday\s*\(/i' => null, // Keep original
            '/^unixepoch\s*\(/i' => null, // Keep original
        ];

        // Apply regex mappings
        foreach ($mappings as $pattern => $replacement) {
            if (preg_match($pattern, $cleaned)) {
                if ($replacement === null) {
                    break; // Keep original for functions we don't want to map
                }

                $this->debugLog("Mapped SQLite function via regex", DebugLevel::VERBOSE, [
                    'pattern' => $pattern,
                    'replacement' => $replacement
                ]);
                return $replacement;
            }
        }

        // Remove SQLite's automatic quoting artifacts
        // SQLite sometimes returns defaults like '''active''' or 'active'

        // Pattern 1: Triple quotes '''value'''
        if (preg_match('/^\'\'\'(.*)\'\'\'$/', $cleaned, $matches)) {
            return $matches[1]; // Return unquoted value
        }

        // Pattern 2: Single quotes 'value'  
        if (preg_match('/^\'(.*)\'$/', $cleaned, $matches)) {
            return $matches[1]; // Return unquoted value
        }

        // Pattern 3: Double quotes "value"
        if (preg_match('/^"(.*)"$/', $cleaned, $matches)) {
            return $matches[1]; // Return unquoted value
        }

        // Return as-is for functions, numbers, etc.
        return $cleaned;
    }

    private function createColumnFromInfo(array $columnInfo): Column
    {
        $column = new Column($columnInfo['name'], $columnInfo['type'] ?? 'TEXT');

        // Handle AUTOINCREMENT detection first
        $isPrimaryKey = $columnInfo['pk'] ?? false;
        $isIntegerType = strtolower($columnInfo['type']) === 'integer';
        $isAutoIncrement = $isPrimaryKey && $isIntegerType && $this->isAutoIncrementColumn($columnInfo['name']);

        if ($isAutoIncrement) {
            $column->setAutoIncrement(true);
            // DON'T set nullable for autoincrement - let SQLitePlatform handle it properly
            $this->debugLog("Detected AUTOINCREMENT column", DebugLevel::VERBOSE, [
                'column' => $columnInfo['name'],
                'type' => $columnInfo['type'],
                'is_primary_key' => $isPrimaryKey
            ]);
        } else {
            // Only set nullable for non-autoincrement columns
            $column->setNullable(!($columnInfo['notnull'] ?? false));
        }

        // Handle default values
        if (isset($columnInfo['dflt_value']) && $columnInfo['dflt_value'] !== null) {
            $cleanedDefault = $this->cleanSQLiteDefaultValue($columnInfo['dflt_value']);
            $column->setDefault($cleanedDefault);

            $this->debugLog("Setting column default", DebugLevel::VERBOSE, [
                'column' => $columnInfo['name'],
                'raw_default' => $columnInfo['dflt_value'],
                'cleaned_default' => $cleanedDefault
            ]);
        }

        return $column;
    }

    private function createIndexFromInfo(array $indexInfo): ?Index
    {
        if (strpos($indexInfo['name'], 'sqlite_autoindex_') === 0) {
            return null;
        }

        $index = new Index(
            $indexInfo['name'],
            $indexInfo['unique'] ? Index::TYPE_UNIQUE : Index::TYPE_INDEX
        );

        foreach ($indexInfo['columns'] as $columnName) {
            $index->addColumn($columnName);
        }

        return $index;
    }

    private function createConstraintFromInfo(array $constraintInfo): ?Constraint
    {
        switch ($constraintInfo['type']) {
            case 'FOREIGN KEY':
                $constraint = new Constraint(
                    $constraintInfo['name'] ?? 'fk_' . uniqid(),
                    Constraint::TYPE_FOREIGN_KEY
                );
                $constraint->setColumns($constraintInfo['columns']);
                $constraint->setReferencedTable($constraintInfo['referenced_table']);
                $constraint->setReferencedColumns($constraintInfo['referenced_columns']);

                if (isset($constraintInfo['on_delete'])) {
                    $constraint->setOnDelete($constraintInfo['on_delete']);
                }
                if (isset($constraintInfo['on_update'])) {
                    $constraint->setOnUpdate($constraintInfo['on_update']);
                }

                return $constraint;

            case 'CHECK':
                $constraint = new Constraint(
                    $constraintInfo['name'] ?? 'check_' . uniqid(),
                    Constraint::TYPE_CHECK
                );
                $constraint->setExpression($constraintInfo['expression']);
                return $constraint;

            default:
                return null;
        }
    }

    private function isAutoIncrementColumn(string $columnName): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'");
            $stmt->execute();

            if ($stmt->fetchColumn() > 0) {
                $tables = $this->getSQLiteTables();

                foreach ($tables as $table) {
                    if (!empty($table['sql'])) {
                        if (preg_match('/\b' . preg_quote($columnName, '/') . '\s+INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i', $table['sql'])) {
                            return true;
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $this->debugLog("Could not determine AUTOINCREMENT status", DebugLevel::VERBOSE, [
                'column' => $columnName,
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    // =============================================================================
    // DATA EXPORT (CLEAN)
    // =============================================================================

    private function exportSingleTableData($handle, string $tableName, array $options): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM " . $this->quoteName($tableName));
        $stmt->execute();
        $totalRows = $stmt->fetchColumn();

        if ($totalRows === 0) {
            fwrite($handle, "-- No data for table $tableName\n\n");
            return 0;
        }

        fwrite($handle, "-- Data for table $tableName ($totalRows rows)\n");

        $stmt = $this->pdo->prepare("PRAGMA table_info(" . $this->quoteName($tableName) . ")");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);

        $chunkSize = $options['chunk_size'] ?? 1000;
        $offset = 0;
        $rowsExported = 0;

        while ($offset < $totalRows) {
            $stmt = $this->pdo->prepare("SELECT * FROM " . $this->quoteName($tableName) . " LIMIT ? OFFSET ?");
            $stmt->execute([$chunkSize, $offset]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    $values[] = $this->quoteSQLiteValue($value);
                }

                fwrite($handle, "INSERT INTO " . $this->quoteName($tableName) .
                    " (" . implode(', ', array_map([$this, 'quoteName'], $columns)) . ")" .
                    " VALUES (" . implode(', ', $values) . ");\n");

                $rowsExported++;
            }

            $offset += $chunkSize;
        }

        fwrite($handle, "\n");
        return $rowsExported;
    }

    // =============================================================================
    // UTILITY METHODS (CLEAN)
    // =============================================================================

    private function generateCreateIndexSQL(string $tableName, array $indexInfo): string
    {
        if (strpos($indexInfo['name'], 'sqlite_autoindex_') === 0) {
            return '';
        }

        $sql = 'CREATE';

        if ($indexInfo['unique']) {
            $sql .= ' UNIQUE';
        }

        $sql .= ' INDEX ' . $this->quoteName($indexInfo['name']);
        $sql .= ' ON ' . $this->quoteName($tableName);
        $sql .= ' (' . implode(', ', array_map([$this, 'quoteName'], $indexInfo['columns'])) . ')';

        if (!empty($indexInfo['where'])) {
            $sql .= ' WHERE ' . $indexInfo['where'];
        }

        return $sql;
    }

    private function generateFallbackTableSQL(string $tableName, array $tableStructure): string
    {
        $sql = "CREATE TABLE " . $this->quoteName($tableName) . " (\n";
        $columnSQLs = [];
        $constraints = [];
        $primaryKeyColumns = [];
        $hasAutoIncrementPK = false;

        foreach ($tableStructure['columns'] as $column) {
            $columnSQL = "  " . $this->quoteName($column['name']) . " " . strtoupper($column['type']);

            $isPK = $column['pk'] ?? false;
            $isInteger = strtolower($column['type']) === 'integer';
            $isAutoIncrement = $isPK && $isInteger && $this->isAutoIncrementColumn($column['name']);

            if ($column['notnull']) {
                $columnSQL .= " NOT NULL";
            }

            if (isset($column['dflt_value']) && $column['dflt_value'] !== null) {
                $columnSQL .= " DEFAULT " . $this->quoteSQLiteValue($column['dflt_value']);
            }

            if ($isAutoIncrement) {
                $columnSQL .= " PRIMARY KEY AUTOINCREMENT";
                $hasAutoIncrementPK = true;
            } elseif ($isPK) {
                $primaryKeyColumns[] = $column['name'];
            }

            $columnSQLs[] = $columnSQL;
        }

        // Add separate PRIMARY KEY constraint if needed
        if (!empty($primaryKeyColumns) && !$hasAutoIncrementPK) {
            $pkCols = array_map([$this, 'quoteName'], $primaryKeyColumns);
            $constraints[] = "  PRIMARY KEY (" . implode(", ", $pkCols) . ")";
        }

        $allParts = array_merge($columnSQLs, $constraints);
        $sql .= implode(",\n", $allParts);
        $sql .= "\n)";

        // Add table options
        if (!empty($tableStructure['options']['without_rowid'])) {
            $sql .= " WITHOUT ROWID";
        }
        if (!empty($tableStructure['options']['strict'])) {
            $sql .= " STRICT";
        }

        return $sql;
    }

    private function writeSQLHeader($handle, array $options): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $sqliteVersion = $this->sqliteVersion ?? 'Unknown';

        fwrite($handle, "-- SQLite Database Backup\n");
        fwrite($handle, "-- Generated by Enhanced Model SQLite Strategy v2.0.0\n");
        fwrite($handle, "-- Database: " . basename($this->databasePath) . "\n");
        fwrite($handle, "-- Generation Time: $timestamp\n");
        fwrite($handle, "-- SQLite Version: $sqliteVersion\n\n");

        if ($options['include_pragma_settings']) {
            // IMPROVED: Better pragma sequence for restore
            fwrite($handle, "-- Disable constraints and optimize for restore\n");
            fwrite($handle, "PRAGMA foreign_keys = OFF;\n");
            fwrite($handle, "PRAGMA ignore_check_constraints = ON;\n");  
            fwrite($handle, "PRAGMA synchronous = OFF;\n");
            fwrite($handle, "PRAGMA journal_mode = MEMORY;\n");
            fwrite($handle, "PRAGMA temp_store = MEMORY;\n");  
            fwrite($handle, "\n");
        }

        if ($options['single_transaction']) {
            fwrite($handle, "BEGIN TRANSACTION;\n\n");
        }
    }

    private function writeSQLFooter($handle, array $options): void
    {
        if ($options['single_transaction']) {
            fwrite($handle, "\nCOMMIT;\n\n");
        }

        if ($options['include_pragma_settings']) {
            fwrite($handle, "-- Restore normal SQLite settings\n");
            fwrite($handle, "PRAGMA ignore_check_constraints = OFF;\n"); 
            fwrite($handle, "PRAGMA foreign_keys = ON;\n");
            fwrite($handle, "PRAGMA synchronous = NORMAL;\n");
            fwrite($handle, "PRAGMA journal_mode = DELETE;\n");
            fwrite($handle, "PRAGMA temp_store = DEFAULT;\n"); 
            fwrite($handle, "\n");
        }

        fwrite($handle, "-- End of SQLite SQL dump\n");
    }

    /**
     * Write DROP statements for clean restore (reverse dependency order)
     * 
     */
    private function writeDropStatements($handle, array $sortedTables, array $options): void
    {
        // Reverse the dependency-sorted tables for dropping
        $reversedTables = array_reverse($sortedTables);

        foreach ($reversedTables as $table) {
            $tableName = $table->getName();
            $quotedName = $this->quoteName($tableName);
            fwrite($handle, "DROP TABLE IF EXISTS $quotedName;\n");
        }

        // Also drop views and triggers that might exist
        fwrite($handle, "\n-- Drop existing views and triggers\n");

        // Drop views
        try {
            $stmt = $this->pdo->query("
            SELECT name FROM sqlite_master 
            WHERE type = 'view' AND name LIKE 'test_%'
            ORDER BY name
        ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $quotedName = $this->quoteName($row['name']);
                fwrite($handle, "DROP VIEW IF EXISTS $quotedName;\n");
            }
        } catch (Exception $e) {
            $this->debugLog("Could not retrieve views for dropping", DebugLevel::VERBOSE, [
                'error' => $e->getMessage()
            ]);
        }

        // Drop triggers  
        try {
            $stmt = $this->pdo->query("
            SELECT name FROM sqlite_master 
            WHERE type = 'trigger'
            ORDER BY name
        ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $quotedName = $this->quoteName($row['name']);
                fwrite($handle, "DROP TRIGGER IF EXISTS $quotedName;\n");
            }
        } catch (Exception $e) {
            $this->debugLog("Could not retrieve triggers for dropping", DebugLevel::VERBOSE, [
                'error' => $e->getMessage()
            ]);
        }

        fwrite($handle, "\n");
    }

    private function quoteName(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function quoteSQLiteValue($value): string
    {
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_numeric($value)) return (string) $value;
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    // =============================================================================
    // RESTORE METHODS (CLEAN)
    // =============================================================================

    public function restoreBackup(string $backupPath, array $options = []): array
    {
        $startTime = microtime(true);
        $this->validateBackupPath($backupPath);

        if (!file_exists($backupPath)) {
            throw new BackupException("Backup file not found: $backupPath");
        }

        $restoreOptions = array_merge([
            'continue_on_error' => true,
            'enable_foreign_keys' => true,
            'single_transaction' => true,
            'validate_restore' => true,
            'progress_callback' => null
        ], $options);

        $this->debugLog("Starting SQLite restore", DebugLevel::BASIC, [
            'backup_path' => $backupPath,
            'backup_size_bytes' => filesize($backupPath)
        ]);

        try {
            $sqlParser = new DatabaseSQLParser();
            $statements = $sqlParser->parseStatements(file_get_contents($backupPath));

            $restoreResult = $this->executeRestoreStatements($statements, $restoreOptions);
            $duration = microtime(true) - $startTime;

            $validationResult = null;
            if ($restoreOptions['validate_restore']) {
                $validationResult = $this->validateRestoreIntegrity();
            }

            return [
                'success' => true,
                'backup_path' => $backupPath,
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType(),
                'statements_executed' => $restoreResult['executed'],
                'statements_failed' => $restoreResult['failed'],
                'errors' => $restoreResult['errors'],
                'validation' => $validationResult
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_seconds' => microtime(true) - $startTime,
                'strategy_used' => $this->getStrategyType()
            ];
        }
    }

    private function executeRestoreStatements(array $statements, array $options): array
    {
        $executed = 0;
        $failed = 0;
        $errors = [];

        if ($options['enable_foreign_keys']) {
            $this->pdo->exec("PRAGMA foreign_keys = ON");
        } else {
            $this->pdo->exec("PRAGMA foreign_keys = OFF");
        }

        $this->pdo->exec("PRAGMA synchronous = OFF");
        $this->pdo->exec("PRAGMA journal_mode = MEMORY");

        if ($options['single_transaction']) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($statements as $index => $statement) {
                $statement = trim($statement);

                if (empty($statement) || strpos($statement, '--') === 0) {
                    continue;
                }

                try {
                    $this->pdo->exec($statement);
                    $executed++;

                    if ($options['progress_callback'] && is_callable($options['progress_callback'])) {
                        $progress = [
                            'progress_percent' => (($index + 1) / count($statements)) * 100,
                            'current_operation' => 'Executing statement ' . ($index + 1),
                            'statements_executed' => $executed,
                            'total_statements' => count($statements)
                        ];
                        call_user_func($options['progress_callback'], $progress);
                    }
                } catch (PDOException $e) {
                    $failed++;
                    $errors[] = [
                        'statement_index' => $index,
                        'statement' => substr($statement, 0, 200) . (strlen($statement) > 200 ? '...' : ''),
                        'error' => $e->getMessage()
                    ];
                    echo "Error: $statement\n";

                    if (!$options['continue_on_error']) {
                        throw $e;
                    }
                }
            }

            if ($options['single_transaction'] && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            $this->pdo->exec("PRAGMA synchronous = NORMAL");
            $this->pdo->exec("PRAGMA journal_mode = DELETE");
        } catch (Exception $e) {
            if ($options['single_transaction'] && $this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }
            throw $e;
        }

        return [
            'executed' => $executed,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    // =============================================================================
    // INTERFACE IMPLEMENTATION (CLEAN)
    // =============================================================================

    private function validateBackupFileInternal(string $backupPath): array
    {
        try {
            $content = file_get_contents($backupPath, false, null, 0, 1000);
            $valid = strpos($content, 'SQLite Database Backup') !== false;

            return [
                'valid' => $valid,
                'file_size' => filesize($backupPath),
                'readable' => is_readable($backupPath)
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function validateRestoreIntegrity(): array
    {
        try {
            $stmt = $this->pdo->query("PRAGMA integrity_check");
            $result = $stmt->fetchColumn();

            return [
                'valid' => $result === 'ok',
                'integrity_check' => $result
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getStrategyType(): string
    {
        return 'sqlite_sql_dump';
    }

    public function testCapabilities(): array
    {
        $capabilities = [
            'success' => true,
            'capabilities' => [],
            'warnings' => [],
            'errors' => []
        ];

        try {
            $this->pdo->query("SELECT 1");
            $capabilities['capabilities'][] = 'Database connection working';

            $version = $this->sqliteVersion ?? 'Unknown';
            $capabilities['capabilities'][] = "SQLite version: $version";

            if ($this->schemaRenderer && $this->sqliteParser && $this->sqlitePlatform) {
                $capabilities['capabilities'][] = 'Schema system fully available';
            } else {
                $capabilities['warnings'][] = 'Schema system partially unavailable - using fallback methods';
            }
        } catch (Exception $e) {
            $capabilities['success'] = false;
            $capabilities['errors'][] = $e->getMessage();
        }

        return $capabilities;
    }

    public function estimateBackupSize(): int
    {
        try {
            $fileSize = file_exists($this->databasePath) ? filesize($this->databasePath) : 0;
            $estimatedSize = $fileSize * 3;
            $estimatedSize += 50000;
            return max($estimatedSize, 1024);
        } catch (Exception $e) {
            return 1024 * 1024;
        }
    }

    public function estimateBackupTime(): int
    {
        try {
            $totalRows = 0;
            $tables = $this->getSQLiteTables();

            foreach ($tables as $table) {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM " . $this->quoteName($table['name']));
                $stmt->execute();
                $totalRows += $stmt->fetchColumn();
            }

            $dataTime = ceil($totalRows / 1000);
            $schemaTime = count($tables);

            return max($dataTime + $schemaTime, 1);
        } catch (Exception $e) {
            return 30;
        }
    }

    public function getDescription(): string
    {
        return 'SQLite SQL Dump Strategy - Creates human-readable SQL files with complete schema and data';
    }

    public function getSelectionCriteria(): array
    {
        return [
            'database_type' => 'sqlite',
            'backup_format' => 'sql',
            'human_readable' => true,
            'version_control_friendly' => true,
            'cross_platform' => true,
            'requires_shell_access' => false,
            'supports_partial_restore' => true,
            'supports_compression' => false
        ];
    }

    public function supportsCompression(): bool
    {
        return false;
    }

    public function detectBackupFormat(string $backupPath): string
    {
        if (!file_exists($backupPath) || !is_readable($backupPath)) {
            return 'unknown';
        }

        try {
            $handle = fopen($backupPath, 'r');
            $header = '';
            for ($i = 0; $i < 10 && !feof($handle); $i++) {
                $header .= fgets($handle);
            }
            fclose($handle);

            if (
                strpos($header, 'SQLite Database Backup') !== false ||
                strpos($header, 'PRAGMA foreign_keys') !== false
            ) {
                return 'sqlite_sql_dump';
            }

            if (preg_match('/CREATE\s+TABLE/i', $header)) {
                return 'sql_dump';
            }

            return 'unknown';
        } catch (Exception $e) {
            return 'unknown';
        }
    }

    public function validateBackupFile(string $backupPath): array
    {
        try {
            $result = [
                'valid' => false,
                'format' => 'unknown',
                'file_size' => 0,
                'readable' => false,
                'issues' => [],
                'warnings' => []
            ];

            if (!file_exists($backupPath)) {
                $result['issues'][] = 'Backup file does not exist';
                return $result;
            }

            if (!is_readable($backupPath)) {
                $result['issues'][] = 'Backup file is not readable';
                return $result;
            }

            $result['file_size'] = filesize($backupPath);
            $result['readable'] = true;
            $result['format'] = $this->detectBackupFormat($backupPath);

            if ($result['format'] === 'sqlite_sql_dump') {
                $content = file_get_contents($backupPath, false, null, 0, 2000);

                if (strpos($content, 'CREATE TABLE') !== false) {
                    $result['valid'] = true;
                } else {
                    $result['warnings'][] = 'No CREATE TABLE statements found';
                }
            } elseif ($result['format'] === 'sql_dump') {
                $result['valid'] = true;
                $result['warnings'][] = 'Generic SQL dump detected - may need format conversion';
            } else {
                $result['issues'][] = 'Unsupported backup format: ' . $result['format'];
            }

            return $result;
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'issues' => ['Validation failed: ' . $e->getMessage()]
            ];
        }
    }

    public function getRestoreOptions(string $backupPath): array
    {
        $validation = $this->validateBackupFile($backupPath);

        if (!$validation['valid']) {
            return [
                'available_options' => [],
                'recommended_options' => [],
                'warnings' => ['Backup file validation failed']
            ];
        }

        return [
            'available_options' => [
                'continue_on_error' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Continue restore even if individual statements fail'
                ],
                'enable_foreign_keys' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Enable foreign key constraints during restore'
                ],
                'single_transaction' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Wrap entire restore in a single transaction'
                ],
                'validate_restore' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Run integrity checks after restore'
                ]
            ],
            'recommended_options' => [
                'continue_on_error' => true,
                'enable_foreign_keys' => $this->supportsForeignKeys,
                'single_transaction' => true,
                'validate_restore' => true
            ]
        ];
    }

    public function partialRestore(string $backupPath, array $targets, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            $sqlParser = new DatabaseSQLParser();
            $allStatements = $sqlParser->parseStatements(file_get_contents($backupPath));

            $filteredStatements = $this->filterStatementsForTargets($allStatements, $targets);

            $restoreOptions = array_merge([
                'continue_on_error' => true,
                'enable_foreign_keys' => false,
                'single_transaction' => true
            ], $options);

            $restoreResult = $this->executeRestoreStatements($filteredStatements, $restoreOptions);
            $duration = microtime(true) - $startTime;

            return [
                'success' => true,
                'backup_path' => $backupPath,
                'targets_restored' => $targets,
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType(),
                'statements_executed' => $restoreResult['executed'],
                'statements_failed' => $restoreResult['failed'],
                'errors' => $restoreResult['errors']
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_seconds' => microtime(true) - $startTime,
                'strategy_used' => $this->getStrategyType()
            ];
        }
    }

    private function filterStatementsForTargets(array $statements, array $targets): array
    {
        $filtered = [];
        $targetPattern = '/(?:CREATE\s+TABLE|INSERT\s+INTO|CREATE\s+INDEX|CREATE\s+TRIGGER)\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?(' .
            implode('|', array_map('preg_quote', $targets)) . ')["`]?/i';

        foreach ($statements as $statement) {
            if (preg_match($targetPattern, $statement)) {
                $filtered[] = $statement;
            }
        }

        return $filtered;
    }

    public function getSupportedOptions(): array
    {
        return [
            'include_schema',
            'include_data',
            'include_indexes',
            'include_constraints',
            'include_triggers',
            'include_views',
            'include_pragma_settings',
            'single_transaction',
            'chunk_size',
            'validate_backup',
            'validate_restore',
            'continue_on_error',
            'enable_foreign_keys',
            'progress_callback'
        ];
    }

    // Clean debugging method (kept for compatibility)
    public function testTableSQLGeneration(string $tableName): array
    {
        try {
            $tableStructure = $this->getTableStructure($tableName);
            $table = $this->createTableObject($tableName, $tableStructure);

            $schemaSQL = null;
            $fallbackSQL = null;

            if ($this->schemaRenderer) {
                try {
                    $schemaSQL = $this->schemaRenderer->renderTable($table);
                } catch (Exception $e) {
                    $schemaSQL = "ERROR: " . $e->getMessage();
                }
            }

            try {
                $fallbackSQL = $this->generateFallbackTableSQL($tableName, $tableStructure);
            } catch (Exception $e) {
                $fallbackSQL = "ERROR: " . $e->getMessage();
            }

            return [
                'table' => $tableName,
                'schema_sql' => $schemaSQL,
                'fallback_sql' => $fallbackSQL,
                'table_info' => $tableStructure,
                'table_object_debug' => [
                    'columns' => array_keys($table->getColumns()),
                    'indexes' => array_map(function ($idx) {
                        return ['name' => $idx->getName(), 'type' => $idx->getType()];
                    }, $table->getIndexes()),
                    'constraints' => array_map(function ($constraint) {
                        return ['name' => $constraint->getName(), 'type' => $constraint->getType()];
                    }, $table->getConstraints())
                ]
            ];
        } catch (Exception $e) {
            return [
                'table' => $tableName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }
}
