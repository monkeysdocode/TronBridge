<?php

require_once dirname(__DIR__) . '/core/DatabaseConfig.php';
require_once dirname(__DIR__) . '/migration/DatabaseMigrator.php';
//require_once dirname(__DIR__) . '/migration/SchemaExtractor.php';
require_once dirname(__DIR__, 2) . '/engine/schema/core/SchemaExtractor.php';
require_once dirname(__DIR__) . '/migration/MigrationValidator.php';
require_once dirname(__DIR__) . '/migration/DataMigrator.php';

/**
 * Database Migration Factory - Factory for Enhanced Model migration system
 * 
 * Provides factory methods for creating migration components with proper
 * configuration and integration with the Enhanced Model system.
 * 
 * @package Database\Factories
 * @author Enhanced Model System
 * @version 1.0.0
 */
class DatabaseMigrationFactory
{
    /**
     * Model instance for factory integration
     */
    private $model = null;

    /**
     * Debug callback for Enhanced Model integration
     */
    private $debugCallback = null;

    /**
     * Factory configuration
     */
    private array $config = [];

    /**
     * Initialize factory
     */
    public function __construct($model = null, array $config = [])
    {
        $this->model = $model;
        $this->config = array_merge([
            'default_chunk_size' => 1000,
            'default_memory_limit' => '256M',
            'enable_progress_tracking' => true,
            'enable_rollback' => true,
            'validate_by_default' => true,
            'log_migrations' => true,
            'migration_log_path' => null
        ], $config);
    }

    /**
     * Set debug callback for Enhanced Model integration
     */
    public function setDebugCallback(?callable $callback): void
    {
        $this->debugCallback = $callback;
    }

    /**
     * Create database migrator
     */
    public function createMigrator($sourceModel, $targetModel, array $options = []): DatabaseMigrator
    {
        $migrationOptions = array_merge([
            'chunk_size' => $this->config['default_chunk_size'],
            'memory_limit' => $this->config['default_memory_limit'],
            'create_rollback_point' => $this->config['enable_rollback'],
            'validate_before_migration' => $this->config['validate_by_default'],
            'validate_after_migration' => $this->config['validate_by_default']
        ], $options);
        
        $migrator = new DatabaseMigrator($sourceModel, $targetModel, $migrationOptions);
        
        if ($this->debugCallback) {
            $migrator->setDebugCallback($this->debugCallback);
        }
        
        if ($this->config['enable_progress_tracking']) {
            $migrator->setProgressCallback($this->createProgressCallback());
        }
        
        return $migrator;
    }

    /**
     * Create schema extractor
     */
    public function createSchemaExtractor(): SchemaExtractor
    {
        $extractor = new SchemaExtractor();
        
        if ($this->debugCallback) {
            $extractor->setDebugCallback($this->debugCallback);
        }
        
        return $extractor;
    }

    /**
     * Create migration validator
     */
    public function createValidator($sourceModel, $targetModel): MigrationValidator
    {
        $validator = new MigrationValidator($sourceModel, $targetModel);
        
        if ($this->debugCallback) {
            $validator->setDebugCallback($this->debugCallback);
        }
        
        return $validator;
    }

    /**
     * Create data migrator
     */
    public function createDataMigrator($sourceModel, $targetModel): DataMigrator
    {
        $dataMigrator = new DataMigrator($sourceModel, $targetModel);
        
        if ($this->debugCallback) {
            $dataMigrator->setDebugCallback($this->debugCallback);
        }
        
        if ($this->config['enable_progress_tracking']) {
            $dataMigrator->setProgressCallback($this->createProgressCallback());
        }
        
        return $dataMigrator;
    }

    /**
     * Quick migration - simplified interface for common scenarios
     */
    public function quickMigrate($sourceModel, $targetModel, array $options = []): array
    {
        $migrator = $this->createMigrator($sourceModel, $targetModel, $options);
        
        $this->debug("Starting quick migration", [
            'source' => $this->getModelInfo($sourceModel),
            'target' => $this->getModelInfo($targetModel)
        ]);
        
        return $migrator->migrateDatabase();
    }

    /**
     * Schema-only migration
     */
    public function migrateSchemaOnly($sourceModel, $targetModel, array $options = []): array
    {
        $options['include_data'] = false;
        $migrator = $this->createMigrator($sourceModel, $targetModel, $options);
        
        $this->debug("Starting schema-only migration");
        
        return $migrator->migrateSchema();
    }

    /**
     * Data-only migration (assumes schema already exists)
     */
    public function migrateDataOnly($sourceModel, $targetModel, array $options = []): array
    {
        $dataMigrator = $this->createDataMigrator($sourceModel, $targetModel);
        
        // Extract schema to get table list
        $extractor = $this->createSchemaExtractor();
        $sourceSchema = $extractor->extractFullSchema($sourceModel);
        
        $this->debug("Starting data-only migration", [
            'tables_count' => count($sourceSchema)
        ]);
        
        return $dataMigrator->migrateAllTables($sourceSchema, $options);
    }

    /**
     * Validate migration compatibility
     */
    public function validateCompatibility($sourceModel, $targetModel): array
    {
        $validator = $this->createValidator($sourceModel, $targetModel);
        
        $this->debug("Validating migration compatibility");
        
        return $validator->validateCompatibility();
    }

    /**
     * Create cross-database backup (using existing backup system)
     */
    public function createCrossDatabaseBackup($sourceModel, string $targetDatabase, string $outputPath, array $options = []): array
    {
        $this->debug("Creating cross-database backup", [
            'target_database' => $targetDatabase,
            'output_path' => $outputPath
        ]);
        
        // Use existing backup system with cross-database compatibility
        $backupOptions = array_merge([
            'cross_database_compatible' => true,
            'target_database' => $targetDatabase,
            'format_sql' => true,
            'include_drop_statements' => true,
            'add_if_not_exists' => true
        ], $options);
        
        return $sourceModel->backup()->createBackup($outputPath, $backupOptions);
    }

    /**
     * Restore cross-database backup
     */
    public function restoreCrossDatabaseBackup($targetModel, string $backupPath, array $options = []): array
    {
        $this->debug("Restoring cross-database backup", [
            'backup_path' => $backupPath
        ]);
        
        $restoreOptions = array_merge([
            'validate_before_restore' => true,
            'use_transaction' => true,
            'debug_parsing' => true
        ], $options);
        
        return $targetModel->backup()->restoreBackup($backupPath, $restoreOptions);
    }

    /**
     * Create migration report
     */
    public function createMigrationReport($sourceModel, $targetModel): array
    {
        $this->debug("Creating migration report");
        
        $extractor = $this->createSchemaExtractor();
        $validator = $this->createValidator($sourceModel, $targetModel);
        
        // Extract schemas
        $sourceSchema = $extractor->extractFullSchema($sourceModel);
        $targetSchema = $extractor->extractFullSchema($targetModel);
        
        // Validate compatibility
        $compatibility = $validator->validateCompatibility();
        
        // Generate report
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'source_info' => $this->getModelInfo($sourceModel),
            'target_info' => $this->getModelInfo($targetModel),
            'source_schema' => $this->summarizeSchema($sourceSchema),
            'target_schema' => $this->summarizeSchema($targetSchema),
            'compatibility' => $compatibility,
            'recommendations' => $this->generateMigrationRecommendations($sourceSchema, $compatibility)
        ];
        
        return $report;
    }

    /**
     * Batch migrate multiple databases
     */
    public function batchMigrate(array $migrations, array $globalOptions = []): array
    {
        $this->debug("Starting batch migration", [
            'migrations_count' => count($migrations)
        ]);
        
        $results = [];
        $overallSuccess = true;
        
        foreach ($migrations as $name => $migration) {
            $this->debug("Processing migration: $name");
            
            $options = array_merge($globalOptions, $migration['options'] ?? []);
            
            try {
                $result = $this->quickMigrate(
                    $migration['source'],
                    $migration['target'],
                    $options
                );
                
                $results[$name] = $result;
                
                if (!$result['success']) {
                    $overallSuccess = false;
                    if ($globalOptions['stop_on_error'] ?? true) {
                        break;
                    }
                }
                
            } catch (Exception $e) {
                $results[$name] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $overallSuccess = false;
                
                if ($globalOptions['stop_on_error'] ?? true) {
                    break;
                }
            }
        }
        
        return [
            'success' => $overallSuccess,
            'migrations' => $results,
            'total_count' => count($migrations),
            'successful_count' => count(array_filter($results, fn($r) => $r['success'] ?? false)),
            'failed_count' => count(array_filter($results, fn($r) => !($r['success'] ?? false)))
        ];
    }

    /**
     * Create progress callback
     */
    private function createProgressCallback(): callable
    {
        return function($progress) {
            $this->debug("Migration progress", $progress);
            
            // Log to file if configured
            if ($this->config['log_migrations'] && $this->config['migration_log_path']) {
                $logEntry = date('Y-m-d H:i:s') . " - Progress: " . json_encode($progress) . "\n";
                file_put_contents($this->config['migration_log_path'], $logEntry, FILE_APPEND | LOCK_EX);
            }
        };
    }

    /**
     * Get model information
     */
    private function getModelInfo($model): array
    {
        try {
            $pdo = $model->getPDO();
            $databaseType = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            
            // Try to get more connection info
            $connectionInfo = [];
            switch ($databaseType) {
                case 'mysql':
                    $stmt = $pdo->query("SELECT DATABASE() as db_name, VERSION() as version");
                    $info = $stmt->fetch(PDO::FETCH_ASSOC);
                    $connectionInfo = [
                        'database_name' => $info['db_name'],
                        'version' => $info['version']
                    ];
                    break;
                    
                case 'postgresql':
                    $stmt = $pdo->query("SELECT current_database() as db_name, version() as version");
                    $info = $stmt->fetch(PDO::FETCH_ASSOC);
                    $connectionInfo = [
                        'database_name' => $info['db_name'],
                        'version' => $info['version']
                    ];
                    break;
                    
                case 'sqlite':
                    $stmt = $pdo->query("PRAGMA database_list");
                    $databases = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $connectionInfo = [
                        'file_path' => $databases[0]['file'] ?? 'Unknown',
                        'version' => $pdo->query("SELECT sqlite_version()")->fetchColumn()
                    ];
                    break;
            }
            
            return array_merge([
                'type' => $databaseType,
                'model_class' => get_class($model)
            ], $connectionInfo);
            
        } catch (Exception $e) {
            return [
                'type' => 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Summarize schema for reporting
     */
    private function summarizeSchema(array $schema): array
    {
        $summary = [
            'tables_count' => count($schema),
            'total_columns' => 0,
            'total_indexes' => 0,
            'total_constraints' => 0,
            'total_records' => 0,
            'tables' => []
        ];
        
        foreach ($schema as $tableName => $tableSchema) {
            $tableInfo = [
                'name' => $tableName,
                'columns_count' => count($tableSchema['columns'] ?? []),
                'indexes_count' => count($tableSchema['indexes'] ?? []),
                'constraints_count' => count($tableSchema['constraints'] ?? []),
                'row_count' => $tableSchema['row_count'] ?? 0
            ];
            
            $summary['total_columns'] += $tableInfo['columns_count'];
            $summary['total_indexes'] += $tableInfo['indexes_count'];
            $summary['total_constraints'] += $tableInfo['constraints_count'];
            $summary['total_records'] += $tableInfo['row_count'];
            $summary['tables'][] = $tableInfo;
        }
        
        return $summary;
    }

    /**
     * Generate migration recommendations
     */
    private function generateMigrationRecommendations(array $sourceSchema, array $compatibility): array
    {
        $recommendations = [];
        
        // Based on schema complexity
        $totalTables = count($sourceSchema);
        $totalRecords = array_sum(array_column($sourceSchema, 'row_count'));
        
        if ($totalTables > 50) {
            $recommendations[] = "Large schema detected ($totalTables tables). Consider batch migration or schema-first approach.";
        }
        
        if ($totalRecords > 1000000) {
            $recommendations[] = "Large dataset detected (" . number_format($totalRecords) . " records). Use larger chunk sizes and consider off-peak migration.";
        }
        
        // Based on compatibility issues
        if (!empty($compatibility['warnings'])) {
            $recommendations[] = "Address " . count($compatibility['warnings']) . " compatibility warnings before migration.";
        }
        
        if (!$compatibility['compatible']) {
            $recommendations[] = "Migration blocked by " . count($compatibility['errors']) . " compatibility errors. Review and resolve before proceeding.";
        }
        
        // Performance recommendations
        if ($totalRecords > 100000) {
            $recommendations[] = "Consider creating database indexes after migration for better performance.";
            $recommendations[] = "Disable foreign key checks during migration and re-enable afterwards.";
        }
        
        return $recommendations;
    }

    /**
     * Debug logging
     */
    private function debug(string $message, array $context = []): void
    {
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, "[Migration Factory] $message", $context);
        }
    }
}
