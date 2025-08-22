#!/usr/bin/env php
<?php

/**
 * Database Backup CLI Tool
 * 
 * Command-line interface for creating database backups using the Enhanced Model
 * backup system with intelligent strategy selection and cross-database support.
 * 
 * Features:
 * - Multi-database support (MySQL, SQLite, PostgreSQL)
 * - Intelligent backup strategy selection with fallback
 * - Progress tracking for large operations
 * - Comprehensive validation and testing
 * - Detailed capability analysis
 * - Enhanced debug integration
 * 
 * Usage:
 *   php backup-cli.php backup --source="sqlite:/path/to/db.sqlite" --output="/backups/backup.sql"
 *   php backup-cli.php restore --target="mysql:host=localhost;dbname=test;user=root;pass=secret" --backup="/backups/backup.sql"
 *   php backup-cli.php test --source="postgresql:host=localhost;dbname=test;user=postgres;pass=secret"
 *   php backup-cli.php validate --backup="/backups/backup.sql"
 * 
 * @package Database\Scripts
 * @author Enhanced Model System
 * @version 1.0.0 - Initial Implementation
 */

// Prevent running from web interface
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Load Enhanced Model and backup system
require_once dirname(__DIR__, 2) . '/engine/Model.php';
require_once dirname(__DIR__) . '/engine/factories/DatabaseBackup.php';

/**
 * Database Backup CLI Handler
 */
class BackupCLI
{
    private array $options = [
        'source' => null,
        'target' => null,
        'output' => null,
        'backup' => null,
        'strategy' => null,
        'timeout' => 3600,
        'chunk-size' => null,
        'verbose' => false,
        'debug' => false,
        'quiet' => false,
        'help' => false,
        'version' => false,
        'validate-only' => false,
        'test-only' => false,
        'compress' => false,
        'format-sql' => false,
        'include-schema' => true,
        'include-data' => true,

        'progress' => true,
        'validate-backup' => true,
        'no-validate' => false,
        'force-strategy' => null,
        'list-strategies' => false
    ];

    private array $stats = [];
    private float $startTime;
    private ?Model $currentModel = null;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Main entry point
     */
    public function run(array $argv): int
    {
        try {
            $command = $argv[1] ?? 'help';
            $this->parseArguments(array_slice($argv, 1));

            if ($this->options['help']) {
                $this->showHelp();
                return 0;
            }

            if ($this->options['version']) {
                $this->showVersion();
                return 0;
            }

            switch ($command) {
                case 'backup':
                    return $this->commandBackup();
                    
                case 'restore':
                    return $this->commandRestore();
                    
                case 'validate':
                    return $this->commandValidate();
                    
                case 'test':
                case 'capabilities':
                    return $this->commandTest();
                    
                case 'estimate':
                    return $this->commandEstimate();
                    
                case 'strategies':
                case 'list-strategies':
                    return $this->commandListStrategies();
                    

                    
                case 'help':
                default:
                    $this->showHelp();
                    return 0;
            }
            
        } catch (Exception $e) {
            $this->error("Error: " . $e->getMessage());
            if ($this->options['debug']) {
                $this->error("Stack trace:\n" . $e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Create database backup
     */
    private function commandBackup(): int
    {
        $this->validateRequiredOption('source', 'Source database connection required for backup');
        $this->validateRequiredOption('output', 'Output path required for backup');

        $this->info("Creating database backup...");
        
        try {
            $model = $this->createModel($this->options['source']);
            $this->configureDebug($model);
            
            $backupOptions = $this->buildBackupOptions();
            
            // Show backup configuration
            if (!$this->options['quiet']) {
                $this->displayBackupConfiguration($model, $backupOptions);
            }
            
            $result = $model->backup()->createBackup($this->options['output'], $backupOptions);
            
            if ($result['success']) {
                $this->displayBackupResults($result);
                
                // Show debug output if enabled
                if ($this->options['debug'] || $this->options['verbose']) {
                    $this->info("\n" . str_repeat("=", 60));
                    $this->info("Debug Output:");
                    echo $model->getDebugOutput();
                }
                
                return 0;
            } else {
                $this->error("Backup failed: " . ($result['error'] ?? 'Unknown error'));
                return 1;
            }
            
        } catch (Exception $e) {
            $this->error("Backup failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Restore database from backup
     */
    private function commandRestore(): int
    {
        $this->validateRequiredOption('target', 'Target database connection required for restore');
        $this->validateRequiredOption('backup', 'Backup file path required for restore');

        $this->info("Restoring database from backup...");
        
        try {
            $model = $this->createModel($this->options['target']);
            $this->configureDebug($model);
            
            $restoreOptions = $this->buildRestoreOptions();
            
            // Show restore configuration
            if (!$this->options['quiet']) {
                $this->displayRestoreConfiguration($model, $restoreOptions);
            }
            
            $result = $model->backup()->restoreBackup($this->options['backup'], $restoreOptions);
            
            if ($result['success']) {
                $this->displayRestoreResults($result);
                
                // Show debug output if enabled
                if ($this->options['debug'] || $this->options['verbose']) {
                    $this->info("\n" . str_repeat("=", 60));
                    $this->info("Debug Output:");
                    echo $model->getDebugOutput();
                }
                
                return 0;
            } else {
                $this->error("Restore failed: " . ($result['error'] ?? 'Unknown error'));
                return 1;
            }
            
        } catch (Exception $e) {
            $this->error("Restore failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Validate backup file
     */
    private function commandValidate(): int
    {
        $this->validateRequiredOption('backup', 'Backup file path required for validation');

        $this->info("Validating backup file...");
        
        try {
            // First, try to detect the database type from the backup file itself
            $detectedType = $this->detectBackupDatabaseType($this->options['backup']);
            
            $model = null;
            
            if ($detectedType) {
                // Try to create a model matching the detected database type
                try {
                    $model = $this->createValidationModel($detectedType);
                    $this->info("Using $detectedType validation model for backup file analysis");
                } catch (Exception $e) {
                    $this->warning("Could not create $detectedType model: " . $e->getMessage());
                    $model = null;
                }
            }
            
            // Fallback to in-memory SQLite if detection failed or model creation failed
            if ($model === null) {
                $model = $this->createValidationModel('sqlite', ':memory:');
                $this->info("Using SQLite in-memory model for backup file validation");
            }
            
            $this->configureDebug($model);
            
            $result = $model->backup()->validateBackupFile($this->options['backup']);
            
            $this->displayValidationResults($result);
            
            return $result['valid'] ? 0 : 1;
            
        } catch (Exception $e) {
            $this->error("Validation failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Test database capabilities
     */
    private function commandTest(): int
    {
        $this->validateRequiredOption('source', 'Source database connection required for capability testing');

        $this->info("Testing database backup capabilities...");
        
        try {
            echo "Creating model...\n";
            $model = $this->createModel($this->options['source']);
            $this->configureDebug($model);
            
            $capabilities = $model->backup()->getCapabilities();
            $this->displayCapabilities($capabilities);

            $testResults = $model->backup()->testCapabilities();
            $this->displayTestResults($testResults);
            
            // Show debug output if enabled
            if ($this->options['debug'] || $this->options['verbose']) {
                $this->info("\n" . str_repeat("=", 60));
                $this->info("Debug Output:");
                echo $model->getDebugOutput();
            }
            
            // Return error if no strategies are available
            $hasWorkingStrategy = false;
            foreach ($testResults as $result) {
                if ($result['success']) {
                    $hasWorkingStrategy = true;
                    break;
                }
            }
            
            return $hasWorkingStrategy ? 0 : 1;
            
        } catch (Exception $e) {
            $this->error("Capability test failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Estimate backup size and duration
     */
    private function commandEstimate(): int
    {
        $this->validateRequiredOption('source', 'Source database connection required for estimation');

        $this->info("Estimating backup size and duration...");
        
        try {
            $model = $this->createModel($this->options['source']);
            $this->configureDebug($model);
            
            $estimate = $model->backup()->estimateBackup();
            
            $this->displayEstimateResults($estimate);
            
            return 0;
            
        } catch (Exception $e) {
            $this->error("Estimation failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * List available backup strategies
     */
    private function commandListStrategies(): int
    {
        $databaseType = $this->options['source'] ? $this->extractDatabaseType($this->options['source']) : null;
        
        if ($databaseType) {
            $this->info("Listing backup strategies for $databaseType databases...");
            
            try {
                $model = $this->createModel($this->options['source']);
                $strategies = $model->backup()->getAvailableStrategies();
                $capabilities = $model->backup()->getCapabilities();
                
                $this->displayStrategies($strategies, $capabilities);
                
                return 0;
                
            } catch (Exception $e) {
                $this->error("Failed to list strategies: " . $e->getMessage());
                return 1;
            }
        } else {
            $this->info("Listing all supported backup strategies...");
            $this->displayAllStrategies();
            return 0;
        }
    }

    // =============================================================================
    // ARGUMENT PARSING
    // =============================================================================

    /**
     * Parse command line arguments
     */
    private function parseArguments(array $args): void
    {
        $i = 1; // Skip command
        while ($i < count($args)) {
            $arg = $args[$i];
            
            if (strpos($arg, '--') === 0) {
                // Long option
                if (strpos($arg, '=') !== false) {
                    [$key, $value] = explode('=', substr($arg, 2), 2);
                    $this->options[str_replace('_', '-', $key)] = $this->parseValue($value);
                } else {
                    $key = substr($arg, 2);
                    if (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
                        $this->options[str_replace('_', '-', $key)] = $this->parseValue($args[$i + 1]);
                        $i++;
                    } else {
                        $this->options[str_replace('_', '-', $key)] = true;
                    }
                }
            } elseif (strpos($arg, '-') === 0 && strlen($arg) > 1) {
                // Short option
                $key = substr($arg, 1);
                $mapping = [
                    'v' => 'verbose',
                    'q' => 'quiet',
                    'h' => 'help',
                    'd' => 'debug',
                    'c' => 'compress',
                    'f' => 'format-sql'
                ];
                
                if (isset($mapping[$key])) {
                    $this->options[$mapping[$key]] = true;
                } else {
                    if (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
                        $this->options[$key] = $this->parseValue($args[$i + 1]);
                        $i++;
                    } else {
                        $this->options[$key] = true;
                    }
                }
            }
            
            $i++;
        }
    }

    /**
     * Parse option value with type conversion
     */
    private function parseValue(string $value): mixed
    {
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if (is_numeric($value)) return is_float($value) ? (float)$value : (int)$value;
        return $value;
    }

    /**
     * Validate required option
     */
    private function validateRequiredOption(string $option, string $message): void
    {
        if (empty($this->options[$option])) {
            throw new InvalidArgumentException($message);
        }
    }

    // =============================================================================
    // MODEL AND CONFIGURATION
    // =============================================================================

    /**
     * Create Model instance from connection string
     */
    private function createModel(string $connectionString): Model
    {
        return new Model(null, $connectionString);
    }

    /**
     * Detect database type from backup file content
     * 
     * @param string $backupPath Path to backup file
     * @return string|null Detected database type or null if unknown
     */
    private function detectBackupDatabaseType(string $backupPath): ?string
    {
        if (!file_exists($backupPath) || !is_readable($backupPath)) {
            return null;
        }

        try {
            // Read first few KB to analyze content
            $handle = fopen($backupPath, 'rb');
            if (!$handle) {
                return null;
            }

            $header = fread($handle, 100);
            fclose($handle);

            // Check for binary SQLite signature
            if (substr($header, 0, 16) === 'SQLite format 3' . "\000") {
                return 'sqlite';
            }

            // Read text content for SQL analysis
            $handle = fopen($backupPath, 'r');
            if (!$handle) {
                return null;
            }

            $content = '';
            for ($i = 0; $i < 40 && !feof($handle); $i++) {
                $content .= fgets($handle);
            }
            fclose($handle);

            $contentLower = strtolower($content);

            // Look for database-specific patterns
            if (strpos($contentLower, 'mysql') !== false || 
                strpos($contentLower, 'innodb') !== false ||
                strpos($contentLower, 'auto_increment') !== false) {
                return 'mysql';
            }

            if (strpos($contentLower, 'postgresql') !== false ||
                strpos($contentLower, 'postgres') !== false ||
                strpos($contentLower, 'serial') !== false ||
                strpos($contentLower, 'nextval') !== false) {
                return 'postgresql';
            }

            if (strpos($contentLower, 'sqlite') !== false ||
                strpos($contentLower, 'autoincrement') !== false ||
                strpos($contentLower, 'pragma') !== false) {
                return 'sqlite';
            }

            // Default to SQLite for generic SQL dumps
            if (strpos($contentLower, 'create table') !== false) {
                return 'sqlite';
            }

            return null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create validation model for specific database type
     * 
     * @param string $dbType Database type (mysql, sqlite, postgresql)
     * @param string|null $params Optional connection parameters
     * @return Model Validation model instance
     * @throws InvalidArgumentException If model creation fails
     */
    private function createValidationModel(string $dbType, ?string $params = null): Model
    {
        switch ($dbType) {
            case 'sqlite':
                $connectionString = 'sqlite:' . ($params ?: ':memory:');
                break;
                
            case 'mysql':
                // For validation, we don't need a real MySQL connection
                // We'll fall back to SQLite in-memory for cross-database validation
                throw new InvalidArgumentException("MySQL validation requires actual database connection - falling back to SQLite");
                
            case 'postgresql':
                // For validation, we don't need a real PostgreSQL connection
                // We'll fall back to SQLite in-memory for cross-database validation
                throw new InvalidArgumentException("PostgreSQL validation requires actual database connection - falling back to SQLite");
                
            default:
                throw new InvalidArgumentException("Unsupported database type for validation: $dbType");
        }
        
        return new Model('validation_table', $connectionString);
    }

    /**
     * Parse database connection string
     */
    private function parseConnectionString(string $connectionString): array
    {
        if (strpos($connectionString, '://') !== false) {
            [$type, $params] = explode('://', $connectionString, 2);
            return ['type' => $type, 'params' => $params];
        } elseif (strpos($connectionString, ':') !== false) {
            [$type, $params] = explode(':', $connectionString, 2);
            return ['type' => $type, 'params' => $params];
        }
        
        throw new InvalidArgumentException("Invalid connection string format: $connectionString");
    }

    /**
     * Extract database type from connection string
     */
    private function extractDatabaseType(string $connectionString): string
    {
        $parsed = $this->parseConnectionString($connectionString);
        return $parsed['type'];
    }

    /**
     * Configure debug output for model
     */
    private function configureDebug(Model $model): void
    {
        if ($this->options['debug']) {
            $model->setDebugPreset('cli');
        } elseif ($this->options['verbose']) {
            $model->setDebugPreset('developer');
        } elseif (!$this->options['quiet']) {
            $model->setDebugPreset('basic');
        }
    }

    // =============================================================================
    // OPTION BUILDERS
    // =============================================================================

    /**
     * Build backup options array
     */
    private function buildBackupOptions(): array
    {
        $options = [
            'include_schema' => $this->options['include-schema'],
            'include_data' => $this->options['include-data'],
            'timeout' => $this->options['timeout'],
            'validate_backup' => $this->options['validate-backup'] && !$this->options['no-validate']
        ];

        if ($this->options['compress']) {
            $options['compress_output'] = true;
        }

        if ($this->options['format-sql']) {
            $options['format_sql'] = true;
            $options['add_comments'] = true;
        }

        if ($this->options['force-strategy']) {
            $options['force_strategy'] = $this->options['force-strategy'];
        }

        if ($this->options['chunk-size']) {
            $options['chunk_size'] = (int)$this->options['chunk-size'];
        }

        if ($this->options['progress'] && !$this->options['quiet']) {
            $options['progress_callback'] = [$this, 'progressCallback'];
        }

        return $options;
    }

    /**
     * Build restore options array
     */
    private function buildRestoreOptions(): array
    {
        $options = [
            'timeout' => $this->options['timeout'],
            'validate_before_restore' => !$this->options['no-validate'],
            'use_transaction' => true,
            'stop_on_error' => false
        ];

        if ($this->options['progress'] && !$this->options['quiet']) {
            $options['progress_callback'] = [$this, 'progressCallback'];
        }

        return $options;
    }



    // =============================================================================
    // PROGRESS AND DISPLAY
    // =============================================================================

    /**
     * Progress callback for backup operations
     */
    public function progressCallback(array $progress): void
    {
        if ($this->options['quiet']) return;

        $percent = round($progress['progress_percent'] ?? 0, 1);
        $operation = $progress['current_operation'] ?? 'Processing';
        
        $this->info("[$operation] Progress: {$percent}%");
    }

    /**
     * Display backup configuration
     */
    private function displayBackupConfiguration(Model $model, array $options): void
    {
        $capabilities = $model->backup()->getCapabilities();
        
        $this->info("Backup Configuration:");
        $this->info("• Database Type: " . $capabilities['database_type']);
        $this->info("• Include Schema: " . ($options['include_schema'] ? 'Yes' : 'No'));
        $this->info("• Include Data: " . ($options['include_data'] ? 'Yes' : 'No'));
        $this->info("• Output Path: " . $this->options['output']);
        
        if (isset($options['compress_output']) && $options['compress_output']) {
            $this->info("• Compression: Enabled");
        }
        
        if (isset($options['cross_database_compatible']) && $options['cross_database_compatible']) {
            $this->info("• Cross-Database: " . $options['target_database']);
        }
        
        if (isset($options['force_strategy'])) {
            $this->info("• Forced Strategy: " . $options['force_strategy']);
        }
        
        $this->info("");
    }

    /**
     * Display restore configuration
     */
    private function displayRestoreConfiguration(Model $model, array $options): void
    {
        $capabilities = $model->backup()->getCapabilities();
        
        $this->info("Restore Configuration:");
        $this->info("• Database Type: " . $capabilities['database_type']);
        $this->info("• Backup File: " . $this->options['backup']);
        $this->info("• Validate Before Restore: " . ($options['validate_before_restore'] ? 'Yes' : 'No'));
        $this->info("• Use Transaction: " . ($options['use_transaction'] ? 'Yes' : 'No'));
        $this->info("");
    }

    /**
     * Display backup results
     */
    private function displayBackupResults(array $result): void
    {
        $this->success("✅ Backup completed successfully!");
        $this->info("• Output File: " . $result['output_path']);
        $this->info("• File Size: " . $this->formatBytes($result['backup_size_bytes'] ?? 0));
        $this->info("• Duration: " . round($result['duration_seconds'] ?? 0, 2) . " seconds");
        $this->info("• Strategy Used: " . ($result['strategy_used'] ?? 'Unknown'));
        
        if (isset($result['records_exported'])) {
            $this->info("• Records Exported: " . number_format($result['records_exported']));
        }
        
        if (isset($result['tables_exported'])) {
            $this->info("• Tables Exported: " . $result['tables_exported']);
        }
        
        if (isset($result['compression_ratio'])) {
            $this->info("• Compression Ratio: " . round($result['compression_ratio'] * 100, 1) . "%");
        }
    }

    /**
     * Display restore results
     */
    private function displayRestoreResults(array $result): void
    {
        $this->success("✅ Restore completed successfully!");
        $this->info("• Statements Executed: " . ($result['statements_executed'] ?? 0));
        $this->info("• Duration: " . round($result['duration_seconds'] ?? 0, 2) . " seconds");
        
        if (isset($result['statements_failed']) && $result['statements_failed'] > 0) {
            $this->warning("• Statements Failed: " . $result['statements_failed']);
        }
    }

    /**
     * Display validation results
     */
    private function displayValidationResults(array $result): void
    {
        if ($result['valid']) {
            $this->success("✅ Backup file is valid!");
            $this->info("• Format: " . ($result['format'] ?? 'Unknown'));
            $this->info("• Database Type: " . ($result['database_type'] ?? 'Unknown'));
            $this->info("• File Size: " . $this->formatBytes($result['size_bytes'] ?? 0));
            
            if (isset($result['tables_detected'])) {
                $this->info("• Tables Detected: " . implode(', ', $result['tables_detected']));
            }
            
            if (isset($result['records_estimated'])) {
                $this->info("• Estimated Records: " . number_format($result['records_estimated']));
            }
        } else {
            $this->error("❌ Backup file validation failed!");
            $this->error("• Error: " . ($result['error'] ?? 'Unknown validation error'));
        }
    }

    /**
     * Display database capabilities
     */
    private function displayCapabilities(array $capabilities): void
    {
        $this->info("Database Capabilities:");
        $this->info("• Database Type: " . $capabilities['database_type']);
        $this->info("• Shell Access (proc_open): " . ($capabilities['capabilities']['proc_open'] ? '✅' : '❌'));
        
        if ($capabilities['database_type'] === 'sqlite') {
            $this->info("• SQLite3 Extension: " . ($capabilities['capabilities']['sqlite3_extension'] ? '✅' : '❌'));
        } elseif ($capabilities['database_type'] === 'mysql') {
            $this->info("• mysqldump Available: " . ($capabilities['capabilities']['mysqldump_available'] ? '✅' : '❌'));
        } elseif ($capabilities['database_type'] === 'postgresql') {
            $this->info("• pg_dump Available: " . ($capabilities['capabilities']['pg_dump_available'] ? '✅' : '❌'));
        }
        
        $this->info("• Gzip Support: " . ($capabilities['capabilities']['gzip_available'] ? '✅' : '❌'));
        $this->info("");
    }

    /**
     * Display strategy test results
     */
    private function displayTestResults(array $testResults): void
    {
        $this->info("Strategy Test Results:");
        
        foreach ($testResults as $strategy => $result) {
            $status = $result['success'] ? '✅' : '❌';
            $strategyName = basename($strategy, '.php');
            $this->info("$status $strategyName");
            
            if (!$result['success'] && isset($result['error'])) {
                $this->warning("    Error: " . $result['error']);
            } elseif ($result['success'] && isset($result['description'])) {
                $this->info("    " . $result['description']);
            }
        }
        $this->info("");
    }

    /**
     * Display backup estimation results
     */
    private function displayEstimateResults(array $estimate): void
    {
        $this->info("Backup Estimation:");
        $this->info("• Estimated Size: " . $this->formatBytes($estimate['estimated_size_bytes'] ?? 0));
        $this->info("• Estimated Duration: " . round($estimate['estimated_duration_seconds'] ?? 0, 1) . " seconds");
        
        if (isset($estimate['table_count'])) {
            $this->info("• Tables: " . $estimate['table_count']);
        }
        
        if (isset($estimate['total_records'])) {
            $this->info("• Total Records: " . number_format($estimate['total_records']));
        }
    }

    /**
     * Display available strategies for current database
     */
    private function displayStrategies(array $strategies, array $capabilities): void
    {
        $this->info("Available Backup Strategies:");
        
        foreach ($strategies as $index => $strategy) {
            $priority = $index + 1;
            $strategyName = get_class($strategy);
            $strategyType = method_exists($strategy, 'getStrategyType') ? $strategy->getStrategyType() : 'Unknown';
            
            $this->info("$priority. $strategyName");
            $this->info("   Type: $strategyType");
            
            if (method_exists($strategy, 'getDescription')) {
                $this->info("   Description: " . $strategy->getDescription());
            }
        }
        $this->info("");
    }

    /**
     * Display all supported strategies
     */
    private function displayAllStrategies(): void
    {
        $this->info("Supported Database Types and Strategies:");
        
        $this->info("\nSQLite Strategies:");
        $this->info("• SQLiteNativeBackupStrategy - Binary backup via SQLite3::backup()");
        $this->info("• SQLiteVacuumBackupStrategy - Binary backup via VACUUM INTO");
        $this->info("• SQLiteSQLDumpStrategy - SQL dump (cross-database compatible)");
        $this->info("• SQLiteFileCopyBackupStrategy - File copy (last resort)");
        
        $this->info("\nMySQL Strategies:");
        $this->info("• MySQLShellBackupStrategy - Shell backup via mysqldump");
        $this->info("• MySQLPHPBackupStrategy - PHP-based SQL generation");
        
        $this->info("\nPostgreSQL Strategies:");
        $this->info("• PostgreSQLShellBackupStrategy - Shell backup via pg_dump");
        $this->info("• PostgreSQLPHPBackupStrategy - PHP-based SQL generation");
    }

    // =============================================================================
    // UTILITY METHODS
    // =============================================================================

    /**
     * Format bytes into human-readable format
     */
    private function formatBytes(int $size): string
    {
        if ($size === 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($size, 1024));
        $power = min($power, count($units) - 1);
        
        return round($size / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Output colored info message
     */
    private function info(string $message): void
    {
        if (!$this->options['quiet']) {
            echo "\033[36m$message\033[0m\n";
        }
    }

    /**
     * Output colored success message
     */
    private function success(string $message): void
    {
        if (!$this->options['quiet']) {
            echo "\033[32m$message\033[0m\n";
        }
    }

    /**
     * Output colored warning message
     */
    private function warning(string $message): void
    {
        echo "\033[33m$message\033[0m\n";
    }

    /**
     * Output colored error message
     */
    private function error(string $message): void
    {
        echo "\033[31m$message\033[0m\n";
    }

    /**
     * Show help information
     */
    private function showHelp(): void
    {
        echo <<<HELP
Enhanced Model Database Backup CLI Tool

USAGE:
    php backup-cli.php <command> [options]

COMMANDS:
    backup          Create database backup
    restore         Restore database from backup
    validate        Validate backup file
    test            Test database backup capabilities
    estimate        Estimate backup size and duration
    strategies      List available backup strategies
    help            Show this help message

BACKUP OPTIONS:
    --source=<connection>       Source database connection string
    --output=<path>             Output backup file path
    --strategy=<name>           Force specific backup strategy
    --timeout=<seconds>         Operation timeout (default: 3600)
    --chunk-size=<number>       Records per chunk for large operations
    --compress                  Enable output compression
    --format-sql                Format SQL for readability
    --no-schema                 Exclude database schema
    --no-data                   Exclude table data
    --no-validate               Skip backup validation

RESTORE OPTIONS:
    --target=<connection>       Target database connection string
    --backup=<path>             Backup file path to restore
    --timeout=<seconds>         Operation timeout (default: 3600)
    --no-validate               Skip pre-restore validation

GENERAL OPTIONS:
    --verbose, -v               Verbose output with detailed information
    --debug, -d                 Enable debug mode with full diagnostics
    --quiet, -q                 Suppress non-essential output
    --no-progress               Disable progress indicators
    --help, -h                  Show this help message
    --version                   Show version information

CONNECTION STRINGS:
    SQLite:      sqlite:/path/to/database.db
    MySQL:       mysql:host=localhost;dbname=mydb;user=root;pass=secret
    PostgreSQL:  postgresql:host=localhost;dbname=mydb;user=postgres;pass=secret

EXAMPLES:

    # Basic SQLite backup
    php backup-cli.php backup --source="sqlite:/var/www/data/app.sqlite" --output="/backups/app_backup.sql"

    # MySQL backup with compression
    php backup-cli.php backup --source="mysql:host=localhost;dbname=ecommerce;user=backup;pass=secret" --output="/backups/mysql_backup.sql.gz" --compress

    # Test PostgreSQL capabilities
    php backup-cli.php test --source="postgresql:host=db.example.com;dbname=production;user=readonly;pass=secret"

    # Validate backup file
    php backup-cli.php validate --backup="/backups/critical_backup.sql"

    # Restore with verbose output
    php backup-cli.php restore --target="mysql:host=localhost;dbname=restored;user=admin;pass=secret" --backup="/backups/backup.sql" --verbose

    # List available strategies for SQLite
    php backup-cli.php strategies --source="sqlite:/tmp/test.db"

    # Estimate backup size
    php backup-cli.php estimate --source="postgresql:host=localhost;dbname=analytics;user=postgres;pass=secret"

    # Force specific backup strategy
    php backup-cli.php backup --source="sqlite:/data/app.db" --output="/backup/forced.sql" --force-strategy="sqlite_sql_dump"

BACKUP STRATEGIES:

    SQLite (Priority Order):
    1. sqlite_native     - Binary backup via SQLite3::backup() (fastest)
    2. sqlite_vacuum     - Binary backup via VACUUM INTO (compressed)
    3. sqlite_sql_dump   - SQL dump (cross-database compatible)
    4. sqlite_file_copy  - File copy (last resort)

    MySQL:
    1. mysql_shell       - Shell backup via mysqldump (preferred)
    2. mysql_php         - PHP-based SQL generation (fallback)

    PostgreSQL:
    1. postgresql_shell  - Shell backup via pg_dump (preferred)
    2. postgresql_php    - PHP-based SQL generation (fallback)

CROSS-DATABASE MIGRATION:

    For cross-database migrations, use a two-step process:
    
    Step 1: Create SQL dump with backup-cli.php
    php backup-cli.php backup --source="sqlite:/old/app.db" --output="/tmp/source.sql" --format-sql
    
    Step 2: Translate SQL dump with sql-dump-translator.php
    php sql-dump-translator.php /tmp/source.sql sqlite mysql /migration/mysql_compatible.sql
    
    This approach provides:
    • Full control over translation parameters
    • Ability to review intermediate SQL files
    • Separation of backup and translation concerns
    • Access to all sql-dump-translator.php features

NOTES:

    • Shell-based strategies (mysqldump, pg_dump) require the respective tools to be installed
    • Large database operations benefit from chunked processing and progress tracking
    • Use --debug for detailed operation logs and troubleshooting information
    • For cross-database migration, use sql-dump-translator.php after creating SQL dumps

HELP;
    }

    /**
     * Show version information
     */
    private function showVersion(): void
    {
        echo "Enhanced Model Database Backup CLI v1.0.0\n";
        echo "Multi-database backup system with intelligent strategy selection\n";
        echo "Supports: MySQL, SQLite, PostgreSQL with cross-database migration\n";
    }
}

// Execute if run directly
if (isset($argv) && realpath($argv[0]) === __FILE__) {
    $cli = new BackupCLI();
    exit($cli->run($argv));
}
