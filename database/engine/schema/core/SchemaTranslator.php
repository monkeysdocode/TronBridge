<?php

require_once __DIR__ . '/SchemaDependencySorter.php';
require_once __DIR__ . '/SchemaRenderer.php';
require_once __DIR__ . '/SchemaTransformer.php';
require_once __DIR__ . '/SchemaDumpExtractor.php';

require_once dirname(__DIR__) . '/platforms/MySQLPlatform.php';
require_once dirname(__DIR__) . '/platforms/PostgreSQLPlatform.php';
require_once dirname(__DIR__) . '/platforms/SQLitePlatform.php';


/**
 * Translates raw SQL schema definitions from one database dialect to another.
 *
 * The SchemaTranslator class is the primary engine for converting a complete
 * SQL script from a source database type (e.g., MySQL) to a target database
 * type (e.g., PostgreSQL). It manages the entire translation workflow:
 * parsing, transforming, sorting, and rendering.
 *
 * It orchestrates the high-level process by delegating specific tasks to
 * other specialized classes:
 * - **Parsing**: Uses `SchemaDumpExtractor` to parse the raw SQL string into
 *   language-agnostic schema objects.
 * - **Transforming**: Uses `SchemaTransformer` to convert the schema objects
 *   to the target platform's conventions.
 * - **Sorting**: Uses `SchemaDependencySorter` to sort tables based on
 *   foreign key relationships.
 * - **Rendering**: Uses `SchemaRenderer` to generate the final SQL script for
 *   the target platform.
 *
 * Key Features:
 * - End-to-end translation of raw SQL.
 * - Automatic dependency sorting for safe table creation order.
 * - A modular architecture that delegates tasks to specialized components.
 *
 * @package Database\Schema\Core
 * @author Enhanced Model System
 * @version 2.0.0
 */
class SchemaTranslator
{
    private array $platforms = [];
    private array $warnings = [];
    private array $postTransformActions = [];

    private int $tablesProcessed = 0;
    private int $indexesProcessed = 0;
    private int $constraintsProcessed = 0;
    private int $insertsProcessed = 0;
    private int $rowsProcessed = 0;

    private array $options = [];
    private $debugCallback = null;
    private ?SchemaDependencySorter $dependencySorter = null;
    private SchemaDumpExtractor $dumpExtractor;

    /**
     * Initialize translator with default parsers and platforms
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            // Core translation options
            'strict' => false, 
            'preserve_indexes' => true,
            'preserve_constraints' => true,
            'handle_unsupported' => 'warn', // 'warn', 'skip', 'error'
            'enum_conversion' => 'text_with_check', // 'text', 'text_with_check'
            'auto_increment_conversion' => 'native', // 'native', 'sequence'
            'add_statistics' => true,

            // Dependency sorting options
            'dependency_sort' => true,  // Enable/disable dependency sorting
            'sort_for_create' => true,  // Sort for CREATE order (parents first)
            'detect_cycles' => true,    // Detect circular dependencies
            'cycle_handling' => 'warn', // 'warn', 'error', 'ignore'

            // Output formatting options
            'add_header_comments' => true,
            'add_dependency_comments' => false, // Add comments showing dependency order
            'group_by_dependencies' => false,   // Group tables by dependency level

            // INSERT processing options
            'process_insert_statements' => true,
            'insert_conflict_handling' => 'error', // 'error', 'update', 'skip'  
            'insert_batch_size' => 1000,
            'include_column_names' => true,
            'validate_insert_data' => true,
            'normalize_insert_data' => true,
            'separate_data_section' => true, // Whether to separate DDL and DML
            'data_section_header' => true    // Add header comment for data section
        ], $options);

        // Initialize the dump extractor which now manages parsers
        $this->dumpExtractor = new SchemaDumpExtractor($this->options);

        // Register default platforms
        $this->registerPlatform('mysql', new MySQLPlatform());
        $this->registerPlatform('postgresql', new PostgreSQLPlatform());
        $this->registerPlatform('sqlite', new SQLitePlatform());

        // Initialize dependency sorter if sorting is enabled
        if ($this->options['dependency_sort']) {
            $this->dependencySorter = new SchemaDependencySorter();
        }
    }

    /**
     * Reset processing statistics
     */
    private function resetStatistics(): void
    {
        $this->tablesProcessed = 0;
        $this->indexesProcessed = 0;
        $this->constraintsProcessed = 0;
        $this->insertsProcessed = 0;
        $this->rowsProcessed = 0;
    }
    

    /**
     * Set debug callback for Enhanced Model integration
     */
    public function setDebugCallback(?callable $callback): void
    {
        $this->debugCallback = $callback;
        $this->dumpExtractor->setDebugCallback($callback); // Pass down to extractor
    }

    /**
     * Register a platform
     */
    public function registerPlatform(string $database, AbstractPlatform $platform): void
    {
        $this->platforms[strtolower($database)] = $platform;
    }

    /**
     * Translate SQL from source to target database with automatic dependency sorting
     * 
     * @param string $sql Source SQL
     * @param string $sourceDB Source database type
     * @param string $targetDB Target database type
     * @return string Translated SQL with proper dependency ordering
     * @throws TranslationException
     */
    public function translateSQL(string $sql, string $sourceDB, string $targetDB): string
    {
        $this->warnings = [];
        $this->postTransformActions = [];

        $sourceDB = strtolower($sourceDB);
        $targetDB = strtolower($targetDB);

        $this->debug("Starting SQL translation with dependency sorting", [
            'source_db' => $sourceDB,
            'target_db' => $targetDB,
            'sql_length' => strlen($sql),
            'dependency_sort_enabled' => $this->options['dependency_sort']
        ]);

        // If source and target are the same, no translation needed
        if ($sourceDB === $targetDB) {
            $this->debug("Source and target databases are the same, no translation needed");
            return $sql;
        }

        // Use SchemaDumpExtractor to parse SQL into schema objects
        try {
            $tables = $this->dumpExtractor->convertStatementsToSchema($sql, $sourceDB);
        } catch (\Exception $e) {
            throw new TranslationException(
                "Failed to parse SQL: " . $e->getMessage(),
                0,
                $e
            );
        }

        if (empty($tables)) {
            $this->debug("No tables found in SQL");
            return $sql;
        }

        $this->debug("Parsed tables from SQL", [
            'table_count' => count($tables),
            'table_names' => array_keys($tables)
        ]);

        // Transform schema objects for target database
        $transformedTables = $this->transformSchema($tables, $sourceDB, $targetDB);

        // Apply dependency sorting if enabled
        if ($this->options['dependency_sort'] && $this->dependencySorter) {
            $transformedTables = $this->sortTablesByDependencies($transformedTables);
        }

        // Render SQL for target database
        $targetSQL = $this->renderSQL($transformedTables, $targetDB);

        // Append post-transformation actions
        $targetSQL .= $this->renderPostTransformActions();

        $this->debug("Translation completed", [
            'tables_count' => count($tables),
            'warnings_count' => count($this->warnings),
            'post_actions_count' => count($this->postTransformActions),
            'output_length' => strlen($targetSQL),
            'dependency_sorted' => $this->options['dependency_sort'],
            'processing_stats' => [
                'tables_processed' => $this->tablesProcessed,
                'indexes_processed' => $this->indexesProcessed,
                'constraints_processed' => $this->constraintsProcessed,
                'inserts_processed' => $this->insertsProcessed,
                'rows_processed' => $this->rowsProcessed
            ]
        ]);

        return $targetSQL;
    }

    /**
     * Transform schema objects for target database
     */
    public function transformSchema(array $tables, string $sourceDB, string $targetDB): array
    {
        $transformer = new SchemaTransformer($this->options);

        // Only set debug callback if we have one
        if ($this->debugCallback !== null) {
            $transformer->setDebugCallback($this->debugCallback);
        }

        $transformed = [];

        foreach ($tables as $tableName => $table) {
            $this->debug("Transforming table: $tableName");

            try {
                $transformedTable = $transformer->transformTable($table, $sourceDB, $targetDB);

                $this->tablesProcessed++;

                if (method_exists($table, 'getIndexes')) {
                    $this->indexesProcessed += count($table->getIndexes());
                } elseif (method_exists($table, 'getIndices')) {
                    $this->indexesProcessed += count($table->getIndices());
                }
                
                // Count constraints for this table
                if (method_exists($table, 'getConstraints')) {
                    $this->constraintsProcessed += count($table->getConstraints());
                }
                
                // Count data rows if present
                if (method_exists($table, 'hasData') && $table->hasData()) {
                    if (method_exists($table, 'getDataRowCount')) {
                        $this->rowsProcessed += $table->getDataRowCount();
                    }
                    $this->insertsProcessed++;
                }

                // Collect transformer warnings
                $tableWarnings = $transformer->getWarnings();
                foreach ($tableWarnings as $warning) {
                    $this->addWarning("Table '$tableName': $warning");
                }

                // Collect post-transform actions
                $postActions = $transformer->getPostTransformActions();
                foreach ($postActions as $action) {
                    $action['table'] = $tableName; // Add table context
                    $this->postTransformActions[] = $action;
                }

                // Clear transformer state for next table
                $transformer->clearPostTransformActions();

                $transformed[$tableName] = $transformedTable;
            } catch (UnsupportedFeatureException $e) {
                if ($this->options['handle_unsupported'] === 'error') {
                    throw $e;
                } elseif ($this->options['handle_unsupported'] === 'warn') {
                    $this->addWarning("Table '$tableName': " . $e->getMessage());
                }
                // If 'skip', we just don't include the table
            }
        }

        return $transformed;
    }

    /**
     * Sort tables by dependencies using SchemaDependencySorter
     * 
     * @param array $tables Array of Table objects keyed by table name
     * @return array Dependency-sorted array of Table objects
     */
    private function sortTablesByDependencies(array $tables): array
    {
        if (!$this->dependencySorter || empty($tables)) {
            return $tables;
        }

        try {
            $this->debug("Starting dependency sorting", [
                'table_count' => count($tables),
                'sort_for_create' => $this->options['sort_for_create']
            ]);

            // Convert associative array to indexed array of Table objects
            $tableObjects = array_values($tables);

            // Sort tables based on option (CREATE or DROP order)
            if ($this->options['sort_for_create']) {
                $sortedTables = $this->dependencySorter->sortForCreate($tableObjects);
            } else {
                $sortedTables = $this->dependencySorter->sortForDrop($tableObjects);
            }

            // Convert back to associative array keyed by table name
            $sortedAssoc = [];
            foreach ($sortedTables as $table) {
                $sortedAssoc[$table->getName()] = $table;
            }

            $this->debug("Dependency sorting completed", [
                'original_order' => array_keys($tables),
                'sorted_order' => array_keys($sortedAssoc),
                'order_changed' => array_keys($tables) !== array_keys($sortedAssoc)
            ]);

            return $sortedAssoc;
        } catch (Exception $e) {
            $message = "Dependency sorting failed: " . $e->getMessage();

            if ($this->options['cycle_handling'] === 'error') {
                throw new TranslationException($message, 0, $e);
            } elseif ($this->options['cycle_handling'] === 'warn') {
                $this->addWarning($message);
                $this->debug("Using original table order due to sorting failure");
            }
            // If 'ignore', silently use original order

            return $tables; // Return original order
        }
    }

    /**
     * Render schema objects as SQL with dependency-aware formatting
     */
    public function renderSQL(array $tables, string $database): string
    {
        $database = strtolower($database);

        if (!isset($this->platforms[$database])) {
            throw new TranslationException("No platform available for $database");
        }

        $platform = $this->platforms[$database];
        $renderer = new SchemaRenderer($platform);

        // Only set debug callback if we have one
        if ($this->debugCallback !== null) {
            $renderer->setDebugCallback($this->debugCallback);
        }

        $sql = [];

        // Add header comment if enabled
        if ($this->options['add_header_comments']) {
            $sql[] = $this->generateHeader($database);
        }

        // Add dependency sorting comments if enabled
        if ($this->options['add_dependency_comments'] && $this->options['dependency_sort']) {
            $sql[] = $this->generateDependencyComments($tables);
        }

        // Add database-specific setup statements
        $sql[] = $this->generateSetupStatements($database);

        // Render schema (DDL)
        $schemaSQL = $this->renderSchemaSQL($tables, $renderer);
        $sql = array_merge($sql, $schemaSQL);

        // NEW: Render data (INSERT statements) if enabled and tables have data
        if ($this->options['process_insert_statements'] && $this->hasTablesWithData($tables)) {
            $dataSQL = $this->renderDataSQL($tables, $renderer);
            
            if (!empty($dataSQL)) {
                if ($this->options['separate_data_section']) {
                    $sql[] = ""; // Empty line separator
                }
                
                if ($this->options['data_section_header']) {
                    $sql[] = $this->generateDataSectionHeader($tables);
                }
                
                $sql = array_merge($sql, $dataSQL);
            }
        }

        return implode("\n", $sql);
    }

    /**
     * NEW: Render schema SQL (DDL statements)
     */
    private function renderSchemaSQL(array $tables, SchemaRenderer $renderer): array
    {
        $sql = [];

        // Render tables (now in dependency order if sorting was enabled)
        if ($this->options['group_by_dependencies']) {
            $this->renderTablesByDependencyGroups($tables, $renderer, $sql);
        } else {
            $this->renderTablesSequentially($tables, $renderer, $sql);
        }

        return $sql;
    }

    /**
     * Render data SQL (INSERT statements)
     */
    private function renderDataSQL(array $tables, SchemaRenderer $renderer): array
    {
        $sql = [];
        $insertOptions = $this->getInsertOptions();

        foreach ($tables as $table) {
            if ($table->hasData()) {
                $this->debug("Rendering INSERT statements for table: " . $table->getName(), [
                    'row_count' => $table->getDataRowCount(),
                    'conflict_handling' => $insertOptions['conflict_handling']
                ]);

                $tableInserts = $renderer->renderInserts($table, $table->getData(), $insertOptions);
                
                if (!empty($tableInserts)) {
                    $sql[] = "-- Data for table: " . $table->getName();
                    $sql = array_merge($sql, $tableInserts);
                    $sql[] = ""; // Add spacing between tables
                }
            }
        }

        return $sql;
    }

    /**
     * NEW: Get INSERT rendering options from translator options
     */
    private function getInsertOptions(): array
    {
        return [
            'conflict_handling' => $this->options['insert_conflict_handling'],
            'batch_size' => $this->options['insert_batch_size'],
            'include_column_names' => $this->options['include_column_names'],
            'chunk_large_data' => true
        ];
    }

    /**
     * NEW: Check if any tables have data
     */
    private function hasTablesWithData(array $tables): bool
    {
        foreach ($tables as $table) {
            if ($table->hasData()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render tables sequentially (standard approach)
     */
    private function renderTablesSequentially(array $tables, SchemaRenderer $renderer, array &$sql): void
    {
        foreach ($tables as $table) {
            $this->debug("Rendering table: " . $table->getName());

            $sql[] = '-- Table structure for table: ' . $table->getName();
            $tableSQL = $renderer->renderTable($table);
            $sql[] = $tableSQL;

            // Render separate CREATE INDEX statements
            $indexSQL = $renderer->renderIndexes($table);
            if (!empty($indexSQL)) {
                $sql[] = "";
                $sql[] = '-- Indexes for table: ' . $table->getName();
                $sql = array_merge($sql, $indexSQL);
            }



            // Render triggers (e.g., for ON UPDATE CURRENT_TIMESTAMP)
            if (method_exists($renderer, 'renderTriggers')) {
                $triggers = $renderer->renderTriggers($table);
                if (!empty($triggers)) {
                    $sql[] = "";
                    $sql[] = '-- Triggers for table: ' . $table->getName();
                    $sql = array_merge($sql, $triggers);
                }
            }

            $sql[] = ""; // Add spacing between tables
        }

        // Second pass: Render all foreign key constraints as ALTER TABLE statements
        $this->renderForeignKeyConstraints($tables, $renderer, $sql);
    }

    /**
     * Render tables grouped by dependency levels (advanced feature)
     */
    private function renderTablesByDependencyGroups(array $tables, SchemaRenderer $renderer, array &$sql): void
    {
        // Group tables by dependency level for clearer output
        $dependencyLevels = $this->analyzeDependencyLevels($tables);

        foreach ($dependencyLevels as $level => $levelTables) {
            if ($this->options['add_dependency_comments']) {
                $sql[] = "-- Dependency Level $level (" . count($levelTables) . " tables)";
            }

            foreach ($levelTables as $table) {
                $this->debug("Rendering table: " . $table->getName() . " (level $level)");

                $sql[] = '-- Table structure for table: ' . $table->getName();
                $tableSQL = $renderer->renderTable($table);
                $sql[] = $tableSQL;

                $indexSQL = $renderer->renderIndexes($table);
                if (!empty($indexSQL)) {
                    $sql[] = "";
                    $sql[] = '-- Indexes for table: ' . $table->getName();
                    $sql = array_merge($sql, $indexSQL);
                }

                // Render triggers (e.g., for ON UPDATE CURRENT_TIMESTAMP)
                if (method_exists($renderer, 'renderTriggers')) {
                    $triggers = $renderer->renderTriggers($table);
                    if (!empty($triggers)) {
                        $sql[] = "";
                        $sql[] = '-- Indexes for table: ' . $table->getName();
                        $sql = array_merge($sql, $triggers);
                    }
                }
            }

            $sql[] = ""; // Add spacing between dependency levels
        }

        // Second pass: Render all foreign key constraints as ALTER TABLE statements
        $this->renderForeignKeyConstraints($tables, $renderer, $sql);
    }

    /**
     * Render foreign key constraints after all tables are created
     * 
     * This method collects all foreign key constraints from all tables and renders them
     * as separate ALTER TABLE ADD CONSTRAINT statements. This ensures that all 
     * referenced tables exist before foreign key constraints are created.
     */
    private function renderForeignKeyConstraints(array $tables, SchemaRenderer $renderer, array &$sql): void
    {
        $foreignKeyConstraints = [];
        $tableNames = []; // For debug output

        // Collect all foreign key constraints from all tables
        foreach ($tables as $table) {
            $tableNames[] = $table->getName();

            foreach ($table->getConstraints() as $constraint) {
                // Only collect foreign key constraints that should be rendered separately
                if ($constraint->isForeignKey() && !$renderer->shouldRenderConstraintInline($constraint, $renderer)) {
                    $foreignKeyConstraints[] = [
                        'table' => $table,
                        'constraint' => $constraint
                    ];

                    $this->debug("Collected foreign key constraint: " . $constraint->getName() .
                        " on table: " . $table->getName() .
                        " -> " . $constraint->getReferencedTable());
                }
            }
        }

        // Add section comment if we have foreign key constraints to render
        if (!empty($foreignKeyConstraints)) {
            if ($this->options['add_dependency_comments']) {
                $sql[] = '';
                $sql[] = '-- ============================================================================';
                $sql[] = '-- FOREIGN KEY CONSTRAINTS';
                $sql[] = '-- ============================================================================';
                $sql[] = '-- Adding foreign key constraints after all tables have been created';
                $sql[] = '-- Total constraints: ' . count($foreignKeyConstraints);
                $sql[] = '-- Tables involved: ' . implode(', ', $tableNames);
                $sql[] = '';
            } else {
                $sql[] = '';
                $sql[] = '-- Foreign Key Constraints';
                $sql[] = '';
            }

            $this->debug("Rendering " . count($foreignKeyConstraints) . " foreign key constraints");

            // Render each foreign key constraint as ALTER TABLE statement
            foreach ($foreignKeyConstraints as $fkData) {
                $table = $fkData['table'];
                $constraint = $fkData['constraint'];

                // Use the existing renderConstraints method which handles the ALTER TABLE generation
                $constraintSQL = $renderer->renderConstraints($table);

                // Filter to only get this specific constraint
                foreach ($table->getConstraints() as $tableConstraint) {
                    if ($tableConstraint === $constraint) {
                        $alterTableSQL = $renderer->renderAlterTableConstraint($table, $constraint);
                        if ($alterTableSQL) {
                            $sql[] = rtrim($alterTableSQL, ';') . ';';
                            $this->debug("Rendered foreign key: " . $constraint->getName());
                        }
                        break;
                    }
                }
            }

            $sql[] = ""; // Add spacing after foreign key section
        } else {
            $this->debug("No foreign key constraints found to render separately");
        }
    }

    /**
     * Render post-transformation actions as SQL
     */
    private function renderPostTransformActions(): string
    {
        if (empty($this->postTransformActions)) {
            return '';
        }

        $sql = "\n\n-- ========================================\n";
        $sql .= "-- POST-TRANSFORMATION ACTIONS\n";
        $sql .= "-- ========================================\n\n";

        // Group actions by type for better organization
        $actionGroups = [];
        foreach ($this->postTransformActions as $action) {
            $actionGroups[$action['type']][] = $action;
        }

        // Render PostgreSQL actions
        if (isset($actionGroups['postgresql_generated_column'])) {
            $sql .= "-- PostgreSQL Generated Columns for Full-Text Search\n";
            foreach ($actionGroups['postgresql_generated_column'] as $action) {
                $sql .= "-- {$action['description']}\n";
                $sql .= $action['sql'] . "\n\n";
            }
        }

        if (isset($actionGroups['postgresql_gin_index'])) {
            $sql .= "-- PostgreSQL GIN Indexes for Full-Text Search\n";
            foreach ($actionGroups['postgresql_gin_index'] as $action) {
                $sql .= "-- {$action['description']}\n";
                $sql .= $action['sql'] . "\n\n";
            }
        }

        // Render SQLite actions
        if (isset($actionGroups['sqlite_fts_table'])) {
            $sql .= "-- SQLite FTS Virtual Tables\n";
            foreach ($actionGroups['sqlite_fts_table'] as $action) {
                $sql .= "-- {$action['description']}\n";
                $sql .= $action['sql'] . "\n\n";
            }
        }

        if (isset($actionGroups['sqlite_fts_populate'])) {
            $sql .= "-- Populate SQLite FTS Tables\n";
            foreach ($actionGroups['sqlite_fts_populate'] as $action) {
                $sql .= "-- {$action['description']}\n";
                $sql .= $action['sql'] . "\n\n";
            }
        }

        if (isset($actionGroups['sqlite_fts_triggers'])) {
            $sql .= "-- SQLite FTS Synchronization Triggers\n";
            foreach ($actionGroups['sqlite_fts_triggers'] as $action) {
                $sql .= "-- {$action['description']}\n";
                $sql .= $action['sql'] . "\n\n";
            }
        }

        return $sql;
    }

    /**
     * Get post-transformation actions for external processing
     */
    public function getPostTransformActions(): array
    {
        return $this->postTransformActions;
    }

    /**
     * Analyze dependency levels of tables
     */
    private function analyzeDependencyLevels(array $tables): array
    {
        $levels = [];
        $level = 0;

        // Simple implementation - group tables that don't reference others at level 0,
        // tables that only reference level 0 at level 1, etc.
        foreach ($tables as $table) {
            $maxDependencyLevel = 0;

            foreach ($table->getConstraints() as $constraint) {
                if ($constraint->isForeignKey()) {
                    $referencedTable = $constraint->getReferencedTable();
                    // In a real implementation, you'd track the level of referenced tables
                    // For now, just use a simple heuristic
                }
            }

            $levels[$maxDependencyLevel][] = $table;
        }

        return $levels;
    }

    /**
     * Generate header comment with dependency information
     */
    private function generateHeader(string $targetDB): string
    {
        $date = new DateTimeImmutable();

        $header = "-- Schema Translation Output\n";
        $header .= "-- Generated by Enhanced Model Schema Translator v2.0\n";
        $header .= "-- Target Database: " . strtoupper($targetDB) . "\n";
        $header .= "-- Generation Time: " . $date->format(DateTimeInterface::RFC2822) . "\n";
        $header .= "-- PHP Version: " . PHP_VERSION . "\n";

        if ($this->options['dependency_sort']) {
            $sortType = $this->options['sort_for_create'] ? 'CREATE' : 'DROP';
            $header .= "-- Dependency Sorting: Enabled ($sortType order)\n";
        } else {
            $header .= "-- Dependency Sorting: Disabled\n";
        }

        $header .= "-- =============================================================================\n";

        return $header;
    }

    /**
     * NEW: Generate data section header
     */
    private function generateDataSectionHeader(array $tables): string
    {
        $totalRows = 0;
        $tablesWithData = 0;
        
        foreach ($tables as $table) {
            if ($table->hasData()) {
                $totalRows += $table->getDataRowCount();
                $tablesWithData++;
            }
        }

        $header = "-- ========================================\n";
        $header .= "-- DATA INSERTION\n";
        $header .= "-- Tables with data: $tablesWithData\n";
        $header .= "-- Total rows: $totalRows\n";
        $header .= "-- Conflict handling: " . strtoupper($this->options['insert_conflict_handling']) . "\n";
        $header .= "-- ========================================";
        
        return $header;
    }

    /**
     * Generate dependency comments
     */
    private function generateDependencyComments(array $tables): string
    {
        $comment = "-- Table Creation Order (dependency-sorted):\n";
        $order = 1;
        foreach ($tables as $table) {
            $comment .= "-- " . sprintf("%2d", $order) . ". " . $table->getName() . "\n";
            $order++;
        }
        $comment .= "--\n";
        return $comment;
    }

    /**
     * Generate database-specific setup statements
     */
    private function generateSetupStatements(string $database): string
    {
        switch ($database) {
            case 'sqlite':
                return "-- Enable foreign key constraints for dependency enforcement\nPRAGMA foreign_keys = ON;\n";
            case 'postgresql':
                return "-- PostgreSQL setup\n-- (Tables will be created in dependency order)\n";
            case 'mysql':
                return "-- MySQL setup\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
            default:
                return "";
        }
    }

    /**
     * Get conversion warnings
     */
    public function getConversionWarnings(): array
    {
        return array_unique($this->warnings);
    }

    /**
     * Add warning
     */
    private function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
        $this->debug("Warning: $warning", ['level' => 'warning']);
    }

    /**
     * Debug logging
     */
    private function debug(string $message, array $context = []): void
    {
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, $message, $context);
        }
    }

    /**
     * Check if dependency sorting is enabled
     */
    public function isDependencySortingEnabled(): bool
    {
        return $this->options['dependency_sort'] && $this->dependencySorter !== null;
    }

    /**
     * Enable/disable dependency sorting at runtime
     */
    public function setDependencySorting(bool $enabled): void
    {
        $this->options['dependency_sort'] = $enabled;

        if ($enabled && !$this->dependencySorter) {
            $this->dependencySorter = new SchemaDependencySorter();
        }
    }

    /**
     * Get current options (for debugging/inspection)
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get translation statistics including INSERT info
     */
    public function getStatistics(): array
    {
        return [
            'warnings_count' => count($this->warnings),
            'dependency_sort_enabled' => $this->options['dependency_sort'],
            'insert_processing_enabled' => $this->options['process_insert_statements'],
            'insert_conflict_handling' => $this->options['insert_conflict_handling'],
            'insert_batch_size' => $this->options['insert_batch_size'],
            'tables_processed' => $this->tablesProcessed,
            'indexes_processed' => $this->indexesProcessed,
            'constraints_processed' => $this->constraintsProcessed,
            'inserts_processed' => $this->insertsProcessed,
            'rows_processed' => $this->rowsProcessed
        ];
    }
}