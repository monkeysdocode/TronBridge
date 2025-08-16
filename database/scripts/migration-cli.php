<?php

/**
 * Database Migration CLI Tool
 * 
 * Command-line interface for running database migrations with the
 * Enhanced Model migration system.
 * 
 * Usage:
 *   php migration-cli.php migrate --source="sqlite:/path/to/source.db" --target="mysql:host=localhost;dbname=target;user=root;pass=secret"
 *   php migration-cli.php validate --source="..." --target="..."
 *   php migration-cli.php schema-only --source="..." --target="..."
 *   php migration-cli.php report --source="..." --target="..."
 */

require_once dirname(__DIR__, 2) . '/engine/Model.php';
require_once dirname(__DIR__) . '/engine/factories/DatabaseMigration.php';

class MigrationCLI
{
    private array $config = [];
    private DatabaseMigrationFactory $factory;
    private bool $verbose = false;
    private string $logFile = '';

    public function __construct()
    {
        $this->logFile = 'migration_' . date('Y-m-d_H-i-s') . '.log';
        $this->factory = new DatabaseMigrationFactory(null, [
            'log_migrations' => true,
            'migration_log_path' => $this->logFile
        ]);
        
        $this->factory->setDebugCallback([$this, 'debugCallback']);
    }

    public function run(array $argv): int
    {
        try {
            $command = $argv[1] ?? 'help';
            $options = $this->parseOptions(array_slice($argv, 2));
            
            $this->verbose = isset($options['verbose']) || isset($options['v']);
            
            if (isset($options['log-file'])) {
                $this->logFile = $options['log-file'];
            }
            
            switch ($command) {
                case 'migrate':
                    return $this->commandMigrate($options);
                    
                case 'validate':
                    return $this->commandValidate($options);
                    
                case 'schema-only':
                    return $this->commandSchemaOnly($options);
                    
                case 'data-only':
                    return $this->commandDataOnly($options);
                    
                case 'report':
                    return $this->commandReport($options);
                    
                case 'backup':
                    return $this->commandBackup($options);
                    
                case 'restore':
                    return $this->commandRestore($options);
                    
                case 'batch':
                    return $this->commandBatch($options);
                    
                case 'help':
                default:
                    $this->showHelp();
                    return 0;
            }
            
        } catch (Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }

    private function commandMigrate(array $options): int
    {
        $this->info("Starting database migration...");
        
        $sourceModel = $this->createModel($options['source'] ?? '');
        $targetModel = $this->createModel($options['target'] ?? '');
        
        $migrationOptions = [
            'chunk_size' => (int) ($options['chunk-size'] ?? 1000),
            'memory_limit' => $options['memory-limit'] ?? '256M',
            'include_data' => !isset($options['schema-only']),
            'validate_before_migration' => !isset($options['no-validate']),
            'validate_after_migration' => !isset($options['no-validate']),
            'create_rollback_point' => !isset($options['no-rollback']),
            'stop_on_error' => !isset($options['continue-on-error'])
        ];
        
        if (isset($options['exclude-tables'])) {
            $migrationOptions['exclude_tables'] = explode(',', $options['exclude-tables']);
        }
        
        if (isset($options['include-tables'])) {
            $migrationOptions['include_tables'] = explode(',', $options['include-tables']);
        }
        
        $result = $this->factory->quickMigrate($sourceModel, $targetModel, $migrationOptions);
        
        $this->displayMigrationResults($result);
        
        return $result['success'] ? 0 : 1;
    }

    private function commandValidate(array $options): int
    {
        $this->info("Validating migration compatibility...");
        
        $sourceModel = $this->createModel($options['source'] ?? '');
        $targetModel = $this->createModel($options['target'] ?? '');
        
        $result = $this->factory->validateCompatibility($sourceModel, $targetModel);
        
        $this->displayValidationResults($result);
        
        return $result['compatible'] ? 0 : 1;
    }

    private function commandSchemaOnly(array $options): int
    {
        $this->info("Starting schema-only migration...");
        
        $sourceModel = $this->createModel($options['source'] ?? '');
        $targetModel = $this->createModel($options['target'] ?? '');
        
        $migrationOptions = [
            'include_indexes' => !isset($options['no-indexes']),
            'include_constraints' => !isset($options['no-constraints']),
            'validate_before_migration' => !isset($options['no-validate'])
        ];
        
        $result = $this->factory->migrateSchemaOnly($sourceModel, $targetModel, $migrationOptions);
        
        $this->displaySchemaResults($result);
        
        return $result['success'] ? 0 : 1;
    }

    private function commandDataOnly(array $options): int
    {
        $this->info("Starting data-only migration...");
        
        $sourceModel = $this->createModel($options['source'] ?? '');
        $targetModel = $this->createModel($options['target'] ?? '');
        
        $migrationOptions = [
            'chunk_size' => (int) ($options['chunk-size'] ?? 1000),
            'handle_conflicts' => $options['handle-conflicts'] ?? 'update',
            'validate_data_types' => !isset($options['no-validate-types'])
        ];
        
        $result = $this->factory->migrateDataOnly($sourceModel, $targetModel, $migrationOptions);
        
        $this->displayDataResults($result);
        
        return $result['success'] ? 0 : 1;
    }

    private function commandReport(array $options): int
    {
        $this->info("Generating migration report...");
        
        $sourceModel = $this->createModel($options['source'] ?? '');
        $targetModel = $this->createModel($options['target'] ?? '');
        
        $report = $this->factory->createMigrationReport($sourceModel, $targetModel);
        
        $this->displayReport($report);
        
        // Save report to file if requested
        if (isset($options['output'])) {
            $reportJson = json_encode($report, JSON_PRETTY_PRINT);
            file_put_contents($options['output'], $reportJson);
            $this->success("Report saved to: " . $options['output']);
        }
        
        return 0;
    }

    private function commandBackup(array $options): int
    {
        $this->info("Creating cross-database backup...");
        
        $sourceModel = $this->createModel($options['source'] ?? '');
        $targetDatabase = $options['target-type'] ?? 'mysql';
        $outputPath = $options['output'] ?? 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        $backupOptions = [
            'format_sql' => true,
            'include_drop_statements' => isset($options['include-drops']),
            'add_if_not_exists' => isset($options['if-not-exists'])
        ];
        
        $result = $this->factory->createCrossDatabaseBackup($sourceModel, $targetDatabase, $outputPath, $backupOptions);
        
        if ($result['success']) {
            $this->success("Backup created successfully:");
            $this->info("  File: " . $result['output_path']);
            $this->info("  Size: " . $this->formatBytes($result['backup_size_bytes']));
            return 0;
        } else {
            $this->error("Backup failed: " . $result['error']);
            return 1;
        }
    }

    private function commandRestore(array $options): int
    {
        $this->info("Restoring cross-database backup...");
        
        $targetModel = $this->createModel($options['target'] ?? '');
        $backupPath = $options['backup'] ?? '';
        
        if (!file_exists($backupPath)) {
            $this->error("Backup file not found: $backupPath");
            return 1;
        }
        
        $restoreOptions = [
            'validate_before_restore' => !isset($options['no-validate']),
            'use_transaction' => !isset($options['no-transaction'])
        ];
        
        $result = $this->factory->restoreCrossDatabaseBackup($targetModel, $backupPath, $restoreOptions);
        
        if ($result['success']) {
            $this->success("Restore completed successfully:");
            $this->info("  Statements executed: " . ($result['statements_executed'] ?? 0));
            return 0;
        } else {
            $this->error("Restore failed: " . $result['error']);
            return 1;
        }
    }

    private function commandBatch(array $options): int
    {
        $configFile = $options['config'] ?? 'batch-migration.json';
        
        if (!file_exists($configFile)) {
            $this->error("Batch configuration file not found: $configFile");
            return 1;
        }
        
        $this->info("Starting batch migration from: $configFile");
        
        $config = json_decode(file_get_contents($configFile), true);
        if (!$config) {
            $this->error("Invalid JSON in configuration file");
            return 1;
        }
        
        // Convert config to migrations array
        $migrations = [];
        foreach ($config['migrations'] ?? [] as $name => $migration) {
            $migrations[$name] = [
                'source' => $this->createModel($migration['source']),
                'target' => $this->createModel($migration['target']),
                'options' => $migration['options'] ?? []
            ];
        }
        
        $globalOptions = array_merge([
            'stop_on_error' => true
        ], $config['global_options'] ?? []);
        
        $result = $this->factory->batchMigrate($migrations, $globalOptions);
        
        $this->displayBatchResults($result);
        
        return $result['success'] ? 0 : 1;
    }

    private function createModel(string $connectionString): object
    {
        if (empty($connectionString)) {
            throw new Exception("Connection string is required");
        }
        
        // Parse connection string format: "type:connection_params"
        $parts = explode(':', $connectionString, 2);
        if (count($parts) !== 2) {
            throw new Exception("Invalid connection string format. Use: type:connection_params");
        }
        
        [$type, $params] = $parts;
        
        switch (strtolower($type)) {
            case 'sqlite':
                return new Model('migration', $connectionString);
                
            case 'mysql':
            case 'postgresql':
                return new Model('migration', $connectionString);
                
            default:
                throw new Exception("Unsupported database type: $type");
        }
    }

    private function parseOptions(array $args): array
    {
        $options = [];
        
        foreach ($args as $arg) {
            if (strpos($arg, '--') === 0) {
                $arg = substr($arg, 2);
                if (strpos($arg, '=') !== false) {
                    [$key, $value] = explode('=', $arg, 2);
                    $options[$key] = $value;
                } else {
                    $options[$arg] = true;
                }
            } elseif (strpos($arg, '-') === 0) {
                $options[substr($arg, 1)] = true;
            }
        }
        
        return $options;
    }

    private function displayMigrationResults(array $result): void
    {
        if ($result['success']) {
            $this->success("Migration completed successfully!");
            $this->info("Tables migrated: " . count($result['tables_migrated']));
            $this->info("Records migrated: " . number_format($result['records_migrated']));
            $this->info("Execution time: " . round($result['execution_time'], 2) . " seconds");
            
            if (isset($result['performance_stats'])) {
                $stats = $result['performance_stats'];
                $this->info("Average speed: " . number_format($stats['records_per_second']) . " records/sec");
                $this->info("Peak memory: " . $stats['memory_peak']);
            }
        } else {
            $this->error("Migration failed!");
            if (isset($result['error'])) {
                $this->error("Error: " . $result['error']);
            }
        }
        
        if (!empty($result['warnings'])) {
            $this->warning("Warnings (" . count($result['warnings']) . "):");
            foreach ($result['warnings'] as $warning) {
                $this->warning("  - $warning");
            }
        }
        
        if (!empty($result['tables_failed'])) {
            $this->error("Failed tables:");
            foreach ($result['tables_failed'] as $table) {
                $this->error("  - $table");
            }
        }
    }

    private function displayValidationResults(array $result): void
    {
        if ($result['compatible']) {
            $this->success("Migration is compatible!");
        } else {
            $this->error("Migration is not compatible!");
        }
        
        $this->info("Source database: " . $result['source_database']);
        $this->info("Target database: " . $result['target_database']);
        $this->info("Tables to migrate: " . $result['tables_count']);
        
        if (!empty($result['warnings'])) {
            $this->warning("Warnings (" . count($result['warnings']) . "):");
            foreach ($result['warnings'] as $warning) {
                $this->warning("  - $warning");
            }
        }
        
        if (!empty($result['errors'])) {
            $this->error("Errors (" . count($result['errors']) . "):");
            foreach ($result['errors'] as $error) {
                $this->error("  - $error");
            }
        }
        
        if (!empty($result['recommendations'])) {
            $this->info("Recommendations:");
            foreach ($result['recommendations'] as $rec) {
                $this->info("  - $rec");
            }
        }
    }

    private function displaySchemaResults(array $result): void
    {
        if ($result['success']) {
            $this->success("Schema migration completed!");
            $this->info("Tables migrated: " . implode(', ', $result['tables_migrated']));
        } else {
            $this->error("Schema migration failed!");
            $this->error("Error: " . $result['error']);
        }
    }

    private function displayDataResults(array $result): void
    {
        if ($result['success']) {
            $this->success("Data migration completed!");
            $this->info("Tables migrated: " . count($result['tables_migrated']));
            $this->info("Records migrated: " . number_format($result['records_migrated']));
        } else {
            $this->error("Data migration failed!");
        }
    }

    private function displayReport(array $report): void
    {
        $this->info("=== Migration Report ===");
        $this->info("Generated: " . $report['timestamp']);
        
        $this->info("\nSource Database:");
        $this->info("  Type: " . $report['source_info']['type']);
        $this->info("  Tables: " . $report['source_schema']['tables_count']);
        $this->info("  Total Records: " . number_format($report['source_schema']['total_records']));
        
        $this->info("\nTarget Database:");
        $this->info("  Type: " . $report['target_info']['type']);
        
        $compatibility = $report['compatibility'];
        if ($compatibility['compatible']) {
            $this->success("\nCompatibility: COMPATIBLE");
        } else {
            $this->error("\nCompatibility: NOT COMPATIBLE");
        }
        
        if (!empty($compatibility['warnings'])) {
            $this->warning("Warnings: " . count($compatibility['warnings']));
        }
        
        if (!empty($compatibility['errors'])) {
            $this->error("Errors: " . count($compatibility['errors']));
        }
        
        if (!empty($report['recommendations'])) {
            $this->info("\nRecommendations:");
            foreach ($report['recommendations'] as $rec) {
                $this->info("  - $rec");
            }
        }
    }

    private function displayBatchResults(array $result): void
    {
        $this->info("=== Batch Migration Results ===");
        $this->info("Total migrations: " . $result['total_count']);
        $this->success("Successful: " . $result['successful_count']);
        $this->error("Failed: " . $result['failed_count']);
        
        foreach ($result['migrations'] as $name => $migrationResult) {
            $status = $migrationResult['success'] ? '✓' : '✗';
            $this->info("$status $name");
            
            if (!$migrationResult['success'] && isset($migrationResult['error'])) {
                $this->error("    Error: " . $migrationResult['error']);
            }
        }
    }

    private function showHelp(): void
    {
        echo <<<HELP
Database Migration CLI Tool

USAGE:
    php migration-cli.php <command> [options]

COMMANDS:
    migrate       Full database migration (schema + data)
    validate      Validate migration compatibility
    schema-only   Migrate schema only (no data)
    data-only     Migrate data only (assumes schema exists)
    report        Generate migration compatibility report
    backup        Create cross-database backup
    restore       Restore cross-database backup
    batch         Run batch migrations from config file
    help          Show this help message

COMMON OPTIONS:
    --source=<connection>     Source database connection string
    --target=<connection>     Target database connection string
    --chunk-size=<number>     Records per chunk (default: 1000)
    --memory-limit=<size>     Memory limit (default: 256M)
    --log-file=<path>         Custom log file path
    --verbose, -v             Verbose output
    --no-validate             Skip validation steps
    --no-rollback             Skip rollback point creation
    --continue-on-error       Continue on table errors

MIGRATION OPTIONS:
    --schema-only             Schema only (no data)
    --exclude-tables=<list>   Comma-separated list of tables to exclude
    --include-tables=<list>   Comma-separated list of tables to include
    --handle-conflicts=<mode> How to handle conflicts: skip, update, error

CONNECTION STRINGS:
    SQLite:      sqlite:/path/to/database.db
    MySQL:       mysql:host=localhost;dbname=mydb;user=root;pass=secret
    PostgreSQL:  postgresql:host=localhost;dbname=mydb;user=postgres;pass=secret

EXAMPLES:
    # Full migration
    php migration-cli.php migrate --source="sqlite:/tmp/old.db" --target="mysql:host=localhost;dbname=new;user=root;pass=secret"
    
    # Validate compatibility
    php migration-cli.php validate --source="sqlite:/tmp/old.db" --target="mysql:host=localhost;dbname=new;user=root;pass=secret"
    
    # Schema only with custom chunk size
    php migration-cli.php schema-only --source="..." --target="..." --chunk-size=5000
    
    # Generate report
    php migration-cli.php report --source="..." --target="..." --output=migration-report.json
    
    # Create cross-database backup
    php migration-cli.php backup --source="sqlite:/tmp/old.db" --target-type=mysql --output=backup.sql
    
    # Batch migration
    php migration-cli.php batch --config=batch-config.json

BATCH CONFIG FORMAT (JSON):
    {
        "global_options": {
            "stop_on_error": true,
            "chunk_size": 1000
        },
        "migrations": {
            "migration1": {
                "source": "sqlite:/path/to/db1.db",
                "target": "mysql:host=localhost;dbname=db1;user=root;pass=secret",
                "options": {}
            }
        }
    }

HELP;
    }

    public function debugCallback(string $message, array $context = []): void
    {
        $logEntry = date('Y-m-d H:i:s') . " - $message";
        if (!empty($context)) {
            $logEntry .= " - " . json_encode($context);
        }
        
        // Log to file
        file_put_contents($this->logFile, $logEntry . "\n", FILE_APPEND | LOCK_EX);
        
        // Display if verbose
        if ($this->verbose) {
            echo "[DEBUG] $message\n";
        }
    }

    private function info(string $message): void
    {
        echo "\033[36m$message\033[0m\n";
    }

    private function success(string $message): void
    {
        echo "\033[32m$message\033[0m\n";
    }

    private function warning(string $message): void
    {
        echo "\033[33m$message\033[0m\n";
    }

    private function error(string $message): void
    {
        echo "\033[31m$message\033[0m\n";
    }

    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;
        return round($size / pow(1024, $power), 2) . ' ' . $units[$power];
    }
}

// Run CLI if called directly
if (php_sapi_name() === 'cli') {
    $cli = new MigrationCLI();
    exit($cli->run($argv));
}
