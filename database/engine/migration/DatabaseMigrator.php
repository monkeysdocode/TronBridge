<?php

require_once dirname(__DIR__, 2) . '/engine/schema/core/SchemaTranslator.php';
require_once dirname(__DIR__, 2) . '/engine/schema/core/SchemaExtractor.php';
require_once dirname(__DIR__, 2) . '/engine/schema/core/SchemaDependencySorter.php';
require_once dirname(__DIR__, 2) . '/engine/schema/core/SchemaTransformer.php';
require_once dirname(__DIR__, 2) . '/engine/schema/core/SchemaRenderer.php';
require_once dirname(__DIR__, 2) . '/engine/schema/platforms/MySQLPlatform.php';
require_once dirname(__DIR__, 2) . '/engine/schema/platforms/PostgreSQLPlatform.php';
require_once dirname(__DIR__, 2) . '/engine/schema/platforms/SQLitePlatform.php';
require_once dirname(__DIR__, 2) . '/engine/schema/schema/Table.php';
require_once dirname(__DIR__, 2) . '/engine/schema/schema/Column.php';
require_once dirname(__DIR__, 2) . '/engine/schema/schema/Index.php';
require_once dirname(__DIR__, 2) . '/engine/schema/schema/Constraint.php';
require_once __DIR__ . '/MigrationValidator.php';
require_once __DIR__ . '/DataMigrator.php';

/**
 * Database Migrator - Complete database migration system
 * 
 * Orchestrates the entire migration process from schema extraction through
 * data migration with validation, rollback support, and progress tracking.
 * 
 * @package Database\Migration
 * @author Enhanced Model System
 * @version 2.0.0
 */
class DatabaseMigrator
{
    private $sourceModel;
    private $targetModel;
    private $options;
    private $warnings = [];
    private $rollbackPoints = [];
    private $debugCallback = null;

    // Components
    private $schemaExtractor;
    private $schemaTranslator;
    private $dataMigrator;
    private $validator;
    private $dependencySorter;
    private $schemaTransformer;

    // Platform components
    private $platforms = [];
    private $renderers = [];

    // Progress tracking
    private $progressCallback = null;
    private $currentPhase = '';
    private $totalPhases = 0;
    private $currentPhaseProgress = 0;

    /**
     * Post-transformation actions collected during migration
     */
    private array $postTransformActions = [];

    /**
     * Fulltext conversion statistics
     */
    private array $fulltextConversionStats = [
        'indexes_converted' => 0,
        'postgresql_gin_indexes' => 0,
        'postgresql_generated_columns' => 0,
        'sqlite_fts_tables' => 0,
        'conversion_warnings' => []
    ];

    /**
     * Initialize migrator with source and target models
     */
    public function __construct($sourceModel, $targetModel, array $options = [])
    {
        $this->sourceModel = $sourceModel;
        $this->targetModel = $targetModel;

        $this->options = array_merge([
            // Migration scope
            'include_data' => true,
            'include_indexes' => true,
            'include_constraints' => true,
            'include_triggers' => false,
            'include_drop_statements' => false,

            // Data handling
            'chunk_size' => 1000,
            'preserve_relationships' => true,
            'handle_conflicts' => 'update', // 'skip', 'update', 'error'
            'validate_data_types' => true,

            // Performance
            'memory_limit' => '256M',
            'max_execution_time' => 0,
            'parallel_tables' => false,

            // Safety
            'create_rollback_point' => true,
            'validate_before_migration' => true,
            'validate_after_migration' => true,
            'stop_on_error' => true,

            // Customization
            'transformation_rules' => [],
            'table_mapping' => [], // source_table => target_table
            'column_mapping' => [], // table => [source_col => target_col]
            'exclude_tables' => [],
            'include_tables' => [], // if empty, include all

            // ===== FULLTEXT CONVERSION OPTIONS =====
            'fulltext_strategy' => 'convert',           // 'convert' or 'remove'
            'postgresql_language' => 'english',         // Language for PostgreSQL FTS
            'postgresql_weights' => ['A', 'B', 'C', 'D'], // Column weights for multi-column indexes
            'sqlite_fts_version' => 'fts5',            // 'fts5', 'fts4', or 'fts3'
            'execute_post_transform_actions' => true,   // Execute GIN indexes, FTS tables, etc.
            'report_fulltext_conversions' => true,      // Include fulltext stats in results

            // Advanced fulltext options
            'postgresql_gin_index_suffix' => '_gin',    // Suffix for GIN index names
            'sqlite_fts_table_suffix' => '_fts',        // Suffix for FTS table names  
            'generated_column_suffix' => '_search_vector', // Suffix for generated search columns
        ], $options);

        // Initialize components
        $this->initializeComponents();
    }

    /**
     * Initialize migration components
     */
    private function initializeComponents(): void
    {
        // Initialize schema extractor
        $this->schemaExtractor = new SchemaExtractor();
        if ($this->debugCallback) {
            $this->schemaExtractor->setDebugCallback($this->debugCallback);
        }

        // Initialize schema translator
        $this->schemaTranslator = new SchemaTranslator();
        if ($this->debugCallback) {
            $this->schemaTranslator->setDebugCallback($this->debugCallback);
        }

        $transformerOptions = [
            'fulltext_strategy' => $this->options['fulltext_strategy'],
            'postgresql_language' => $this->options['postgresql_language'],
            'postgresql_weights' => $this->options['postgresql_weights'],
            'sqlite_fts_version' => $this->options['sqlite_fts_version'],
            'postgresql_gin_index_suffix' => $this->options['postgresql_gin_index_suffix'],
            'sqlite_fts_table_suffix' => $this->options['sqlite_fts_table_suffix'],
            'generated_column_suffix' => $this->options['generated_column_suffix'],

            // Pass through other existing options
            'enum_conversion' => $this->options['enum_conversion'] ?? 'text_with_check',
            'dependency_sort' => $this->options['dependency_sort'] ?? true,
            'handle_unsupported' => $this->options['handle_unsupported'] ?? 'warn'
        ];

        // Initialize schema transformer
        $this->schemaTransformer = new SchemaTransformer($transformerOptions);
        if ($this->debugCallback) {
            $this->schemaTransformer->setDebugCallback($this->debugCallback);
        }

        // Initialize dependency sorter
        $this->dependencySorter = new SchemaDependencySorter();

        // Initialize platform components
        $this->initializePlatforms();

        // Initialize data migrator
        $this->dataMigrator = new DataMigrator($this->sourceModel, $this->targetModel);
        if ($this->debugCallback) {
            $this->dataMigrator->setDebugCallback($this->debugCallback);
        }

        // Initialize validator
        $this->validator = new MigrationValidator($this->sourceModel, $this->targetModel);
        if ($this->debugCallback) {
            $this->validator->setDebugCallback($this->debugCallback);
        }

        // Set memory limit
        if ($this->options['memory_limit']) {
            ini_set('memory_limit', $this->options['memory_limit']);
        }
    }

    /**
     * Initialize platform-specific components
     */
    private function initializePlatforms(): void
    {
        // MySQL platform
        $this->platforms['mysql'] = new MySQLPlatform();
        $this->renderers['mysql'] = new SchemaRenderer($this->platforms['mysql']);

        // PostgreSQL platform
        $this->platforms['postgresql'] = new PostgreSQLPlatform();
        $this->renderers['postgresql'] = new SchemaRenderer($this->platforms['postgresql']);

        // SQLite platform
        $this->platforms['sqlite'] = new SQLitePlatform();
        $this->renderers['sqlite'] = new SchemaRenderer($this->platforms['sqlite']);

        // Set debug callbacks
        if ($this->debugCallback) {
            foreach ($this->renderers as $renderer) {
                $renderer->setDebugCallback($this->debugCallback);
            }
        }
    }

    /**
     * Convert schema array to schema objects
     */
    private function convertSchemaArrayToObjects(array $tableSchema, string $sourceDB): Table
    {
        $this->debug("Converting schema array to objects for table: {$tableSchema['name']}");

        // Create table object
        $table = new Table($tableSchema['name']);

        // Set table options
        if (isset($tableSchema['engine'])) {
            $table->setEngine($tableSchema['engine']);
        }
        if (isset($tableSchema['charset'])) {
            $table->setCharset($tableSchema['charset']);
        }
        if (isset($tableSchema['collation'])) {
            $table->setCollation($tableSchema['collation']);
        }
        if (isset($tableSchema['comment'])) {
            $table->setComment($tableSchema['comment']);
        }

        // Add columns
        if (isset($tableSchema['columns'])) {
            foreach ($tableSchema['columns'] as $columnData) {
                $column = $this->createColumnObject($columnData);
                $table->addColumn($column);
            }
        }

        // Add indexes
        if (isset($tableSchema['indexes']) && $this->options['include_indexes']) {
            foreach ($tableSchema['indexes'] as $indexData) {
                $index = $this->createIndexObject($indexData);
                $table->addIndex($index);
            }
        }

        // Add constraints
        if (isset($tableSchema['constraints']) && $this->options['include_constraints']) {
            foreach ($tableSchema['constraints'] as $constraintData) {
                $constraint = $this->createConstraintObject($constraintData);
                $table->addConstraint($constraint);
            }
        }

        return $table;
    }

    /**
     * Create column object from array data
     */
    private function createColumnObject(array $columnData): Column
    {
        $column = new Column($columnData['name'], $columnData['type']);

        // Set basic properties
        if (isset($columnData['nullable'])) {
            $column->setNullable($columnData['nullable']);
        }
        if (isset($columnData['default'])) {
            $column->setDefault($columnData['default']);
        }
        if (isset($columnData['length'])) {
            $column->setLength($columnData['length']);
        }
        if (isset($columnData['precision'])) {
            $column->setPrecision($columnData['precision']);
        }
        if (isset($columnData['scale'])) {
            $column->setScale($columnData['scale']);
        }
        if (isset($columnData['unsigned'])) {
            $column->setUnsigned($columnData['unsigned']);
        }
        if (isset($columnData['auto_increment'])) {
            $column->setAutoIncrement($columnData['auto_increment']);
        }
        if (isset($columnData['comment'])) {
            $column->setComment($columnData['comment']);
        }

        // Set custom options
        /*
        if (isset($columnData['values'])) {
            $column->setValues($columnData['values']);
        }
            */
        if (isset($columnData['on_update'])) {
            $column->setCustomOption('on_update', $columnData['on_update']);
        }

        if (isset($columnData['extra']) && !empty($columnData['extra'])) {
        $extra = strtolower(trim($columnData['extra']));
        
        // Check for ON UPDATE clause in extra field
        if (str_contains($extra, 'on update')) {
            if (str_contains($extra, 'current_timestamp')) {
                $column->setCustomOption('on_update', 'CURRENT_TIMESTAMP');
                
                $this->debug("Detected ON UPDATE in extra field", [
                    'column' => $columnData['name'],
                    'extra' => $columnData['extra']
                ]);
            }
        }
        
        // Handle other extra field content if needed
        if (str_contains($extra, 'auto_increment')) {
            $column->setAutoIncrement(true);
        }
    }

        return $column;
    }

    /**
     * Create index object from array data
     */
    private function createIndexObject(array $indexData): Index
    {
        $index = new Index($indexData['name'], $indexData['type'] ?? 'btree');

        // Add columns with flexible handling for different formats
        if (isset($indexData['columns'])) {
            foreach ($indexData['columns'] as $key => $value) {
                if (is_string($value)) {
                    // Simple string column name
                    $index->addColumn($value);
                } elseif (is_array($value)) {
                    if (is_string($key)) {
                        // Associative array: key is column name, value is options array
                        $index->addColumn($key, $value['length'] ?? null, $value['direction'] ?? null);
                    } else {
                        // Numeric array of arrays: each value must contain column name
                        if (!isset($value['column']) && !isset($value['name'])) {
                            throw new Exception("Column data array must contain 'column' or 'name' key");
                        }
                        $columnName = $value['column'] ?? $value['name'];
                        $index->addColumn($columnName, $value['length'] ?? null, $value['direction'] ?? null);
                    }
                } else {
                    throw new Exception("Invalid column data type in index: " . gettype($value));
                }
            }
        }

        // Set properties
        if (isset($indexData['unique'])) {
            $index->setUnique($indexData['unique']);
        }
        if (isset($indexData['method'])) {
            $index->setMethod($indexData['method']);
        }
        if (isset($indexData['where'])) {
            $index->setWhere($indexData['where']);
        }

        return $index;
    }


    /**
     * Create constraint object from array data
     */
    private function createConstraintObject(array $constraintData): Constraint
    {
        $constraint = new Constraint($constraintData['name'], $constraintData['type']);

        // Set columns
        if (isset($constraintData['columns'])) {
            $constraint->setColumns($constraintData['columns']);
        }

        // Foreign key specific
        if ($constraintData['type'] === 'foreign_key') {
            if (isset($constraintData['referenced_table'])) {
                $constraint->setReferencedTable($constraintData['referenced_table']);
            }
            if (isset($constraintData['referenced_columns'])) {
                $constraint->setReferencedColumns($constraintData['referenced_columns']);
            }
            if (isset($constraintData['on_delete'])) {
                $constraint->setOnDelete($constraintData['on_delete']);
            }
            if (isset($constraintData['on_update'])) {
                $constraint->setOnUpdate($constraintData['on_update']);
            }
        }

        // Check constraint specific
        if ($constraintData['type'] === 'check' && isset($constraintData['expression'])) {
            $constraint->setExpression($constraintData['expression']);
        }

        return $constraint;
    }

    /**
     * Transform schema objects for target database
     */
    private function transformSchemaObjects(Table $table, string $sourceDB, string $targetDB): Table
    {
        $this->debug("Transforming schema objects from $sourceDB to $targetDB");

        // Use SchemaTransformer to transform the table
        $transformedTable = $this->schemaTransformer->transformTable($table, $sourceDB, $targetDB);

        // Handle SQLite-specific index naming to prevent conflicts
        if ($targetDB === 'sqlite') {
            $indexes = $transformedTable->getIndexes();
            foreach ($indexes as $index) {
                $originalName = str_replace('idx_', '', strtolower($index->getName()));
                // Create a unique name by prefixing with table name and hashing if needed
                $newName = "idx_" . $transformedTable->getName() . "_" . $originalName;
                // Limit length and ensure uniqueness (SQLite index names must be unique database-wide)
                if (strlen($newName) > 64) {
                    $newName = "idx_" . $transformedTable->getName() . "_" . substr(hash('xxh3', $originalName), 0, 8);
                }
                $index->setName($newName);
                $this->debug("Renamed index '$originalName' to '$newName' for SQLite compatibility");
            }
        }

        // Collect transformation warnings
        $warnings = $this->schemaTransformer->getWarnings();
        foreach ($warnings as $warning) {
            $this->warnings[] = "Table '{$table->getName()}': $warning";
        }

        return $transformedTable;
    }

    /**
     * Render schema objects to SQL
     */
    private function renderSchemaObjectsToSQL(Table $table, string $targetDB): array
    {
        $this->debug("Rendering SQL for {$targetDB}");

        $sql = [];

        // Get the appropriate renderer
        $renderer = $this->renderers[$targetDB] ?? $this->renderers['mysql'];

        // Render CREATE TABLE statement
        $createTableSQL = $renderer->renderTable($table);
        if ($createTableSQL) {
            $sql[] = $createTableSQL;
        }

        // Render indexes (separate statements)
        $indexStatements = $renderer->renderIndexes($table);
        foreach ($indexStatements as $indexSQL) {
            $sql[] = $indexSQL;
        }

        // Render constraints (separate statements)
        $constraintStatements = $renderer->renderConstraints($table);
        foreach ($constraintStatements as $constraintSQL) {
            $sql[] = $constraintSQL;
        }

        $triggerStatements = $renderer->renderTriggers($table);
        foreach ($triggerStatements as $triggerSQL) {
            $sql[] = $triggerSQL;
        }

        if (!empty($triggerStatements)) {
            $this->debug("Rendered triggers for table: {$table->getName()}", [
                'trigger_count' => count($triggerStatements),
                'target_db' => $targetDB
            ]);
        }


        return $sql;
    }

    /**
     * Generate DROP TABLE SQL
     */
    private function generateDropTableSQL(string $tableName, string $targetDB): string
    {
        $platform = $this->platforms[$targetDB] ?? $this->platforms['mysql'];
        $quotedName = $platform->quoteIdentifier($tableName);

        switch ($targetDB) {
            case 'mysql':
                return "DROP TABLE IF EXISTS $quotedName";
            case 'postgresql':
                return "DROP TABLE IF EXISTS $quotedName CASCADE";
            case 'sqlite':
                return "DROP TABLE IF EXISTS $quotedName";
            default:
                return "DROP TABLE IF EXISTS $quotedName";
        }
    }

    /**
     * Get schema transformation warnings
     */
    private function getSchemaTransformationWarnings(): array
    {
        return $this->schemaTransformer->getWarnings();
    }

    /**
     * Sort tables by dependencies
     */
    private function sortTablesByDependencies(array $tables): array
    {
        $this->debug("Sorting tables by dependencies");

        // Convert array tables to Table objects if needed
        $tableObjects = [];
        foreach ($tables as $tableName => $tableData) {
            if ($tableData instanceof Table) {
                $tableObjects[$tableName] = $tableData;
            } else {
                // Assume it's an array that needs conversion
                $sourceDB = $this->detectDatabaseType($this->sourceModel);
                $tableObjects[$tableName] = $this->convertSchemaArrayToObjects($tableData, $sourceDB);
            }
        }

        // Use SchemaDependencySorter to sort tables
        $sortedTables = $this->dependencySorter->sortForCreate($tableObjects);

        // Convert back to the original format
        $result = [];
        foreach ($sortedTables as $table) {
            $tableName = $table->getName();
            if (isset($tables[$tableName])) {
                $result[$tableName] = $tables[$tableName];
            }
        }

        return $result;
    }

    /**
     * Perform memory cleanup
     */
    private function performMemoryCleanup(): void
    {
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // Check memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryPercentage = ($memoryUsage / $memoryLimit) * 100;

        $this->debug("Memory cleanup performed", [
            'current_usage' => $this->formatBytes($memoryUsage),
            'memory_limit' => $this->formatBytes($memoryLimit),
            'usage_percentage' => round($memoryPercentage, 1) . '%'
        ]);

        // Warn if memory usage is high
        if ($memoryPercentage > 80) {
            $this->warnings[] = "High memory usage detected: " . round($memoryPercentage, 1) . "%";
        }
    }

    /**
     * Parse memory limit from ini setting
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Calculate average processing speed
     */
    private function calculateAverageSpeed(int $recordsProcessed, float $timeElapsed): float
    {
        if ($timeElapsed <= 0) {
            return 0;
        }

        return round($recordsProcessed / $timeElapsed, 2);
    }

    /**
     * Format bytes for human reading
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        if ($bytes <= 0) {
            return '0 B';
        }

        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $bytes /= pow(1024, $power);

        return round($bytes, 2) . ' ' . $units[$power];
    }

    /**
     * Migrate table schema
     */
    private function migrateTableSchema($tableSchema, array $options): array
    {
        $tableName = $tableSchema['name'];
        $startTime = microtime(true);
        try {
            $this->debug("Migrating table schema: $tableName");

            // Get database types
            $sourceDB = $this->detectDatabaseType($this->sourceModel);
            $targetDB = $this->detectDatabaseType($this->targetModel);

            // Convert array schema to schema objects
            $tableObject = $this->convertSchemaArrayToObjects($tableSchema, $sourceDB);

            // Transform schema objects for target database
            $transformedTable = $this->transformSchemaObjects($tableObject, $sourceDB, $targetDB);

            // Collect post-transformation actions for this table
            $tablePostActions = $this->schemaTransformer->getPostTransformActions();

            // Update fulltext conversion statistics
            $this->updateFulltextStats($tablePostActions, $tableName);

            // Render SQL using existing SchemaRenderer + Platform
            $targetSQL = $this->renderSchemaObjectsToSQL($transformedTable, $targetDB);

            // ===== EXECUTE POST-TRANSFORMATION ACTIONS =====
            if ($this->options['execute_post_transform_actions'] && !empty($tablePostActions)) {
                $postActionSQL = $this->renderPostTransformActionsToSQL($tablePostActions, $tableName);
                $targetSQL = array_merge($targetSQL, $postActionSQL);

                $this->debug("Added post-transformation actions for $tableName", [
                    'actions_count' => count($tablePostActions),
                    'sql_statements' => count($postActionSQL)
                ]);
            }

            // Store post-actions for reporting
            $this->postTransformActions[$tableName] = $tablePostActions;

            // Clear transformer state for next table
            $this->schemaTransformer->clearPostTransformActions();

            // Deploy schema to target database
            $targetPdo = $this->targetModel->getPDO();
            $targetPdo->beginTransaction();
            try {
                // Drop table if exists (if requested)
                if ($options['include_drop_statements'] ?? false) {
                    $dropSQL = $this->generateDropTableSQL($tableName, $targetDB);
                    $targetPdo->exec($dropSQL);
                    $this->debug("Dropped existing table: $tableName");
                }

                // Execute the generated SQL statements
                foreach ($targetSQL as $sql) {
                    if (!empty(trim($sql))) {
                        $targetPdo->exec($sql);
                    }
                }

                $this->debug("Created table schema: $tableName");
                $targetPdo->commit();

                return [
                    'success' => true,
                    'table' => $tableName,
                    'execution_time' => microtime(true) - $startTime,
                    'sql_statements' => count($targetSQL),
                    'transformedTable' => $transformedTable,
                    'post_transform_actions' => $tablePostActions, // Include post-actions in result
                    'fulltext_conversions' => $this->getTableFulltextConversions($tablePostActions), // Fulltext-specific info
                    'warnings' => $this->getSchemaTransformationWarnings()
                ];
            } catch (Exception $e) {
                $targetPdo->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            $this->debug("Schema migration failed for table: $tableName", [
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'table' => $tableName,
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $startTime
            ];
        }
    }

    /**
     * Migrate all table data
     */
    private function migrateAllTableData(array $sourceSchema, array $options): array
    {
        $startTime = microtime(true);
        $results = [
            'tables_migrated' => [],
            'tables_failed' => [],
            'records_migrated' => 0,
            'performance_stats' => []
        ];

        try {
            $this->debug("Starting data migration for all tables", [
                'tables_count' => count($sourceSchema),
                'chunk_size' => $options['chunk_size'] ?? 1000
            ]);

            // Use DataMigrator for actual data transfer
            $dataMigrator = $this->dataMigrator;

            // Set up progress callback for data migration
            if ($this->progressCallback) {
                $dataMigrator->setProgressCallback($this->progressCallback);
            }

            // Apply transformation rules and column mappings from options
            if (!empty($options['transformation_rules'])) {
                foreach ($options['transformation_rules'] as $table => $rule) {
                    $dataMigrator->addTransformationRule($table, $rule);
                }
            }

            if (!empty($options['column_mapping'])) {
                foreach ($options['column_mapping'] as $table => $mapping) {
                    $dataMigrator->addColumnMapping($table, $mapping);
                }
            }

            // Filter tables based on options
            $tablesToMigrate = $this->filterTables($sourceSchema, $options);

            // Sort tables by dependencies if preserving relationships
            if ($options['preserve_relationships'] ?? true) {
                $tablesToMigrate = $this->sortTablesByDependencies($tablesToMigrate);
            }

            // Migrate data for each table
            $totalTables = count($tablesToMigrate);
            $currentTable = 0;

            foreach ($tablesToMigrate as $tableName => $tableSchema) {
                $currentTable++;

                $this->debug("Migrating data for table: $tableName", [
                    'progress' => "$currentTable/$totalTables",
                    'rows' => $tableSchema['row_count'] ?? 0
                ]);

                // Update overall progress
                $this->updateProgress("Migrating data: $tableName", $currentTable, $totalTables);

                try {
                    // Migrate single table data
                    $tableResult = $dataMigrator->migrateTable($tableName, [
                        'chunk_size' => $options['chunk_size'] ?? 1000,
                        'handle_conflicts' => $options['handle_conflicts'] ?? 'update',
                        'validate_data_types' => $options['validate_data_types'] ?? true,
                        'use_transaction' => $options['use_transaction'] ?? true
                    ]);

                    if ($tableResult['success']) {
                        $results['tables_migrated'][] = $tableName;
                        $results['records_migrated'] += $tableResult['records_migrated'];

                        $this->debug("Table data migration completed", [
                            'table' => $tableName,
                            'records' => $tableResult['records_migrated'],
                            'time' => round($tableResult['execution_time'], 2) . 's'
                        ]);
                    } else {
                        $results['tables_failed'][] = $tableName;
                        $this->warnings[] = "Data migration failed for table '$tableName': " . $tableResult['error'];

                        if ($options['stop_on_error'] ?? true) {
                            throw new Exception("Data migration failed for table '$tableName': " . $tableResult['error']);
                        }
                    }
                } catch (Exception $e) {
                    $results['tables_failed'][] = $tableName;
                    $this->warnings[] = "Data migration failed for table '$tableName': " . $e->getMessage();

                    if ($options['stop_on_error'] ?? true) {
                        throw $e;
                    }
                }

                // Memory cleanup every 10 tables
                if ($currentTable % 10 === 0) {
                    $this->performMemoryCleanup();
                }
            }

            // Get performance statistics
            $results['performance_stats'] = [
                'total_execution_time' => microtime(true) - $startTime,
                'tables_processed' => count($results['tables_migrated']) + count($results['tables_failed']),
                'average_records_per_second' => $this->calculateAverageSpeed($results['records_migrated'], microtime(true) - $startTime),
                'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
                'memory_current' => $this->formatBytes(memory_get_usage(true))
            ];

            $this->debug("Data migration completed", [
                'tables_migrated' => count($results['tables_migrated']),
                'tables_failed' => count($results['tables_failed']),
                'records_migrated' => $results['records_migrated'],
                'execution_time' => round($results['performance_stats']['total_execution_time'], 2) . 's'
            ]);

            return $results;
        } catch (Exception $e) {
            $results['error'] = $e->getMessage();
            $results['performance_stats'] = [
                'total_execution_time' => microtime(true) - $startTime,
                'memory_peak' => $this->formatBytes(memory_get_peak_usage(true))
            ];

            throw $e;
        }
    }

    /**
     * Set debug callback
     */
    public function setDebugCallback(?callable $callback): void
    {
        $this->debugCallback = $callback;

        // Propagate to components
        if ($this->schemaExtractor) {
            $this->schemaExtractor->setDebugCallback($callback);
        }
        if ($this->schemaTranslator) {
            $this->schemaTranslator->setDebugCallback($callback);
        }
        if ($this->dataMigrator) {
            $this->dataMigrator->setDebugCallback($callback);
        }
        if ($this->validator) {
            $this->validator->setDebugCallback($callback);
        }
    }

    /**
     * Set progress callback
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;

        // Propagate to data migrator
        if ($this->dataMigrator) {
            $this->dataMigrator->setProgressCallback($callback);
        }
    }

    /**
     * Debug logging helper
     */
    private function debug(string $message, array $context = []): void
    {
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, "[DatabaseMigrator] $message", $context);
        }
    }

    /**
     * Update progress
     */
    private function updateProgress(string $phase, int $current, int $total): void
    {
        $this->currentPhase = $phase;
        $this->currentPhaseProgress = $current;
        $this->totalPhases = $total;

        if ($this->progressCallback) {
            call_user_func($this->progressCallback, [
                'phase' => $phase,
                'current' => $current,
                'total' => $total,
                'percentage' => $total > 0 ? round(($current / $total) * 100, 1) : 0
            ]);
        }
    }

    /**
     * Detect database type from model
     */
    private function detectDatabaseType($model): string
    {
        $pdo = $model->getPDO();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        switch ($driver) {
            case 'mysql':
                return 'mysql';
            case 'pgsql':
                return 'postgresql';
            case 'sqlite':
                return 'sqlite';
            default:
                return 'mysql'; // Default fallback
        }
    }

    /**
     * Filter tables based on options
     */
    private function filterTables(array $tables, array $options): array
    {
        $filtered = [];

        foreach ($tables as $tableName => $tableData) {
            // Skip if in exclude list
            if (in_array($tableName, $options['exclude_tables'])) {
                continue;
            }

            // Skip if include list is specified and table is not in it
            if (!empty($options['include_tables']) && !in_array($tableName, $options['include_tables'])) {
                continue;
            }

            $filtered[$tableName] = $tableData;
        }

        return $filtered;
    }

    /**
     * Extract source schema
     */
    private function extractSourceSchema(): array
    {
        return $this->schemaExtractor->extractFullSchema($this->sourceModel);
    }

    /**
     * Migrate complete database
     */
    public function migrateDatabase(array $options = []): array
    {
        $options = array_merge($this->options, $options);
        $startTime = microtime(true);

        // Reset fulltext stats for this migration
        $this->postTransformActions = [];
        $this->fulltextConversionStats = [
            'indexes_converted' => 0,
            'postgresql_gin_indexes' => 0,
            'postgresql_generated_columns' => 0,
            'sqlite_fts_tables' => 0,
            'conversion_warnings' => []
        ];

        $result = [
            'success' => false,
            'migration_id' => uniqid('migration_'),
            'start_time' => date('Y-m-d H:i:s'),
            'source_db' => $this->detectDatabaseType($this->sourceModel),
            'target_db' => $this->detectDatabaseType($this->targetModel),
            'options' => $options,
            'schema_migration' => [],
            'data_migration' => [],
            'validation_results' => [],
            'rollback_point' => null,
            'warnings' => [],
            'execution_time' => 0,

            // ===== ENHANCED: FULLTEXT CONVERSION REPORTING =====
            'fulltext_conversions' => [
                'enabled' => $options['fulltext_strategy'] === 'convert',
                'strategy' => $options['fulltext_strategy'],
                'statistics' => [],
                'post_transform_actions' => [],
                'converted_tables' => []
            ]
        ];

        try {

            $this->debug("Starting database migration", [
                'migration_id' => $result['migration_id'],
                'source_db' => $result['source_db'],
                'target_db' => $result['target_db'],
                'fulltext_strategy' => $options['fulltext_strategy']
            ]);

            // Phase 1: Validation
            if ($options['validate_before_migration']) {
                $this->updateProgress('Validating migration', 1, 6);
                $validation = $this->validator->validateMigration($options);
                if (!$validation['is_valid']) {
                    throw new Exception("Migration validation failed: " . implode(', ', $validation['errors']));
                }
                $this->warnings = array_merge($this->warnings, $validation['warnings']);
            }

            // Phase 2: Create rollback point
            if ($options['create_rollback_point']) {
                $this->updateProgress('Creating rollback point', 2, 6);
                $result['rollback_point'] = $this->createRollbackPoint();
            }

            // Phase 3: Extract source schema
            $this->updateProgress('Extracting source schema', 3, 6);
            $sourceSchema = $this->extractSourceSchema();

            // Phase 4: Migrate schema
            $this->updateProgress('Migrating schema', 4, 6);
            $schemaResult = $this->migrateSchema($sourceSchema, $options);
            if (!$schemaResult['success']) {
                throw new Exception("Schema migration failed: " . $schemaResult['error']);
            }
            $result['schema_migrated'] = true;
            $result['tables_migrated'] = $schemaResult['tables_migrated'];

            // Phase 5: Migrate data
            if ($options['include_data']) {
                $this->updateProgress('Migrating data', 5, 6);
                $dataResult = $this->migrateAllTableData($sourceSchema, $options);
                $result['data_migrated'] = true;
                $result['records_migrated'] = $dataResult['records_migrated'];
            }

            // Phase 6: Post-migration validation
            if ($options['validate_after_migration']) {
                $this->updateProgress('Validating results', 6, 6);
                $postValidation = $this->validator->validateMigration();
                if (!$postValidation['is_valid']) {
                    $this->warnings = array_merge($this->warnings, $postValidation['errors']);
                }
            }

            // FULLTEXT CONVERSION RESULTS
            if ($options['report_fulltext_conversions']) {
                $result['fulltext_conversions'] = $this->compileFulltextConversionResults();
            }

            $result['success'] = true;
            $result['warnings'] = $this->warnings;
            $result['execution_time'] = microtime(true) - $startTime;

            $this->debug("Migration completed successfully", [
                'tables' => count($result['tables_migrated']),
                'records' => $result['records_migrated'],
                'time' => round($result['execution_time'], 2) . 's'
            ]);

            return $result;
        } catch (Exception $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            $result['warnings'] = $this->warnings;
            $result['execution_time'] = microtime(true) - $startTime;

            // Include partial fulltext conversion results even on failure
            if ($options['report_fulltext_conversions']) {
                $result['fulltext_conversions'] = $this->compileFulltextConversionResults();
            }

            $this->debug("Migration failed", [
                'error' => $e->getMessage(),
                'partial_fulltext_conversions' => count($this->postTransformActions)
            ]);

            // Attempt rollback if rollback point exists
            if ($result['rollback_point'] && $options['create_rollback_point']) {
                $this->debug("Attempting automatic rollback");
                try {
                    $this->rollback($result['rollback_point']['checkpoint_id']);
                    $result['rollback_attempted'] = true;
                    $result['rollback_success'] = true;
                } catch (Exception $rollbackError) {
                    $result['rollback_attempted'] = true;
                    $result['rollback_success'] = false;
                    $result['rollback_error'] = $rollbackError->getMessage();
                }
            }

            return $result;
        }
    }

    /**
     * Migrate schema only (no data)
     */
    public function migrateSchema(?array $sourceSchema = null, array $options = []): array
    {
        if (!$sourceSchema) {
            $sourceSchema = $this->extractSourceSchema();
        }

        $options = array_merge($this->options, $options);
        try {
            // Get database types
            $sourceDB = $this->detectDatabaseType($this->sourceModel);
            $targetDB = $this->detectDatabaseType($this->targetModel);
            $this->debug("Migrating schema", [
                'source_db' => $sourceDB,
                'target_db' => $targetDB,
                'tables_count' => count($sourceSchema)
            ]);

            // Filter tables based on options
            $tablesToMigrate = $this->filterTables($sourceSchema, $options);

            // Sort tables by dependencies for proper creation order
            $tablesToMigrate = $this->sortTablesByDependencies($tablesToMigrate);

            $migratedTables = [];
            $transformedSchemas = []; // New array to collect transformed tables

            foreach ($tablesToMigrate as $tableName => $tableSchema) {
                $result = $this->migrateTableSchema($tableSchema, $options);
                if ($result['success']) {
                    $migratedTables[] = $tableName;

                    // Collect transformed table (assuming migrateTableSchema returns it, or modify to do so)
                    // Note: You'll need to update migrateTableSchema to return the transformedTable as well
                    $transformedSchemas[$tableName] = $result['transformedTable'] ?? null;
                } else {
                    if ($options['stop_on_error']) {
                        throw new Exception("Schema migration failed for table '$tableName': " . $result['error']);
                    }
                    $this->warnings[] = "Failed to migrate schema for table '$tableName': " . $result['error'];
                }
            }

            return [
                'success' => true,
                'tables_migrated' => $migratedTables,
                'transformedSchemas' => $transformedSchemas, // Add this to the return
                'warnings' => $this->warnings
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'warnings' => $this->warnings
            ];
        }
    }

    /**
     * Render post-transformation actions to SQL statements, with trigger handling
     */
    private function renderPostTransformActionsToSQL(array $postActions, string $tableName): array
    {
        $sql = [];

        foreach ($postActions as $action) {
            if (!empty($action['sql'])) {
                // Add descriptive comment
                $sql[] = "-- {$action['description']} for table: $tableName";

                // Handle multi-statement SQL (like SQLite FTS triggers)
                if ($action['type'] === 'sqlite_fts_triggers' && str_contains($action['sql'], 'END;')) {
                    // Split complex trigger SQL into individual statements
                    $triggerStatements = $this->splitTriggerStatements($action['sql']);
                    foreach ($triggerStatements as $statement) {
                        $sql[] = trim($statement);
                    }
                } else {
                    // Single statement
                    $sql[] = $action['sql'];
                }

                $sql[] = ''; // Add spacing between actions
            }
        }

        return $sql;
    }

    private function splitTriggerStatements(string $triggerSQL): array
    {
        $statements = [];

        // Split on CREATE TRIGGER boundaries while preserving complete trigger definitions
        $parts = preg_split('/(?=CREATE TRIGGER)/i', $triggerSQL, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($parts as $part) {
            $trimmed = trim($part);
            if (!empty($trimmed)) {
                // Ensure each trigger statement ends properly
                if (!str_ends_with($trimmed, ';')) {
                    $trimmed .= ';';
                }
                $statements[] = $trimmed;
            }
        }

        return $statements;
    }

    /**
     * Update fulltext conversion statistics
     */
    private function updateFulltextStats(array $postActions, string $tableName): void
    {
        foreach ($postActions as $action) {
            switch ($action['type']) {
                case 'postgresql_gin_index':
                    $this->fulltextConversionStats['postgresql_gin_indexes']++;
                    break;
                case 'postgresql_generated_column':
                    $this->fulltextConversionStats['postgresql_generated_columns']++;
                    break;
                case 'sqlite_fts_table':
                case 'sqlite_fts_populate':
                case 'sqlite_fts_triggers':
                    $this->fulltextConversionStats['sqlite_fts_tables']++;
                    break;
            }
        }

        if (!empty($postActions)) {
            $this->fulltextConversionStats['indexes_converted']++;
        }
    }

    /**
     * Get fulltext conversion info for a specific table
     */
    private function getTableFulltextConversions(array $postActions): array
    {
        $conversions = [
            'has_conversions' => !empty($postActions),
            'postgresql_features' => [],
            'sqlite_features' => [],
            'action_count' => count($postActions)
        ];

        foreach ($postActions as $action) {
            switch ($action['type']) {
                case 'postgresql_gin_index':
                    $conversions['postgresql_features'][] = 'GIN Index';
                    break;
                case 'postgresql_generated_column':
                    $conversions['postgresql_features'][] = 'Generated Search Vector';
                    break;
                case 'sqlite_fts_table':
                    $conversions['sqlite_features'][] = 'FTS Virtual Table';
                    break;
                case 'sqlite_fts_populate':
                    $conversions['sqlite_features'][] = 'FTS Data Population';
                    break;
                case 'sqlite_fts_triggers':
                    $conversions['sqlite_features'][] = 'FTS Sync Triggers';
                    break;
            }
        }

        return $conversions;
    }

    /**
     * Compile comprehensive fulltext conversion results
     */
    private function compileFulltextConversionResults(): array
    {
        $results = [
            'strategy' => $this->options['fulltext_strategy'],
            'target_database' => $this->detectDatabaseType($this->targetModel),
            'configuration' => [
                'postgresql_language' => $this->options['postgresql_language'],
                'sqlite_fts_version' => $this->options['sqlite_fts_version'],
                'execute_post_actions' => $this->options['execute_post_transform_actions']
            ],
            'statistics' => $this->fulltextConversionStats,
            'converted_tables' => [],
            'post_transform_actions' => []
        ];

        // Compile per-table conversion details
        foreach ($this->postTransformActions as $tableName => $actions) {
            if (!empty($actions)) {
                $results['converted_tables'][$tableName] = $this->getTableFulltextConversions($actions);
                $results['post_transform_actions'][$tableName] = $actions;
            }
        }

        return $results;
    }

    /**
     * Create rollback point
     */
    public function createRollbackPoint(): array
    {
        $checkpointId = uniqid('rollback_', true);
        $timestamp = date('Y-m-d H:i:s');

        try {
            // Create backup of target database
            $backupPath = sys_get_temp_dir() . "/migration_rollback_{$checkpointId}.sql";

            // Use DatabaseBackup factory to create backup
            if (class_exists('DatabaseBackupFactory')) {
                $backupFactory = new DatabaseBackupFactory($this->targetModel);
                $backupResult = $backupFactory->createBackup($backupPath, [
                    'include_schema' => true,
                    'include_data' => true
                ]);

                if (!$backupResult['success']) {
                    throw new Exception("Failed to create rollback point: " . $backupResult['error']);
                }
            }

            $this->rollbackPoints[$checkpointId] = [
                'checkpoint_id' => $checkpointId,
                'timestamp' => $timestamp,
                'backup_path' => $backupPath
            ];

            $this->debug("Rollback point created", [
                'checkpoint_id' => $checkpointId,
                'backup_path' => $backupPath
            ]);

            return [
                'success' => true,
                'checkpoint_id' => $checkpointId,
                'timestamp' => $timestamp
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Rollback to checkpoint
     */
    public function rollback(string $checkpointId): array
    {
        if (!isset($this->rollbackPoints[$checkpointId])) {
            return [
                'success' => false,
                'error' => "Rollback point '$checkpointId' not found"
            ];
        }

        $rollbackPoint = $this->rollbackPoints[$checkpointId];

        try {
            // Use DatabaseBackup factory to restore
            if (class_exists('DatabaseBackupFactory')) {
                $backupFactory = new DatabaseBackupFactory($this->targetModel);
                $restoreResult = $backupFactory->restoreBackup($rollbackPoint['backup_path']);

                if (!$restoreResult['success']) {
                    throw new Exception("Rollback failed: " . $restoreResult['error']);
                }
            }

            return [
                'success' => true,
                'checkpoint_id' => $checkpointId,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'checkpoint_id' => $checkpointId,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get migration warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Get migration summary with fulltext conversion information
     */
    public function getMigrationSummary(): array
    {
        return [
            'total_tables_processed' => count($this->postTransformActions),
            'tables_with_fulltext_conversions' => count(array_filter($this->postTransformActions, fn($actions) => !empty($actions))),
            'fulltext_statistics' => $this->fulltextConversionStats,
            'configuration' => [
                'fulltext_strategy' => $this->options['fulltext_strategy'],
                'target_database' => $this->detectDatabaseType($this->targetModel),
                'postgresql_language' => $this->options['postgresql_language'],
                'sqlite_fts_version' => $this->options['sqlite_fts_version']
            ]
        ];
    }
}
