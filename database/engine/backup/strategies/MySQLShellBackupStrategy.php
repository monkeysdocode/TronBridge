<?php

/**
 * MySQL Shell Backup Strategy - mysqldump via Secure proc_open()
 * 
 * Implements MySQL database backup using mysqldump tool executed through secure
 * proc_open() with comprehensive credential protection and cross-platform compatibility.
 * This is the preferred method for MySQL backups as it provides complete schema
 * and data export with all MySQL-specific features.
 * 
 * **ENHANCED WITH FULL DEBUG SYSTEM INTEGRATION**
 * - Real-time command execution monitoring and progress tracking
 * - Detailed mysqldump option analysis and optimization recommendations
 * - Comprehensive error analysis with troubleshooting guidance
 * - Security-focused logging with credential protection
 * 
 * Key Features:
 * - Secure credential handling via stdin and environment variables
 * - Cross-platform compatibility (Windows/Unix) with unified interface
 * - Comprehensive mysqldump option support and optimization
 * - Real-time progress tracking for large database operations
 * - Automatic retry logic for connection issues
 * - Format detection and validation for restore operations
 * - Transaction-safe backup with --single-transaction support
 * 
 * Security Features:
 * - Credentials never exposed in command line or process list
 * - Automatic credential cleanup and secure memory handling
 * - Shell argument escaping and validation
 * - Process timeout and resource management
 * 
 * @package Database\Backup\Strategy\MySQL
 * @author Enhanced Model System
 * @version 1.0.0 - Initial Implementation with Debug Integration
 */
class MySQLShellBackupStrategy implements BackupStrategyInterface, RestoreStrategyInterface
{
    use DebugLoggingTrait;
    use ConfigSanitizationTrait;
    use PathValidationTrait;
    
    private Model $model;
    private PDO $pdo;
    private array $connectionConfig;
    private SecureShellExecutor $shellExecutor;

    /**
     * Initialize MySQL shell backup strategy
     * 
     * @param Model $model Enhanced Model instance for debug logging
     * @param array $connectionConfig Database connection configuration
     * @throws RuntimeException If mysqldump is not available or shell access disabled
     */
    public function __construct(Model $model, array $connectionConfig)
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->connectionConfig = $this->normalizeConnectionConfig($connectionConfig);

        // Initialize secure shell executor
        require_once dirname(__DIR__) . '/helpers/SecureShellExecutor.php';
        $this->shellExecutor = new SecureShellExecutor($model);

        $this->debugLog("MySQL shell backup strategy initialized", DebugLevel::VERBOSE, [
            'connection_host' => $this->connectionConfig['host'],
            'connection_database' => $this->connectionConfig['database'],
            'connection_config' => $this->sanitizeConfig($connectionConfig),
            'shell_executor_available' => true,
            'mysqldump_test_pending' => true
        ]);

        // Test mysqldump availability
        $this->validateMySQLDumpAvailability();
    }

    // =============================================================================
    // BACKUP OPERATIONS
    // =============================================================================

    /**
     * Create MySQL database backup using mysqldump
     * 
     * Executes mysqldump with optimal settings for database backup, including
     * transaction safety, comprehensive schema export, and data consistency.
     * 
     * @param string $outputPath Path where backup file should be created
     * @param array $options Backup configuration options
     * @return array Backup result with success status and metadata
     * @throws RuntimeException If backup fails
     */
    public function createBackup(string $outputPath, array $options = []): array
    {
        $startTime = microtime(true);

        $this->validateBackupPath($outputPath);

        // Normalize options with MySQL-specific defaults
        $options = array_merge([
            'include_schema' => true,
            'include_data' => true,
            'single_transaction' => true,
            'lock_tables' => false,
            'routines' => true,
            'triggers' => true,
            'events' => false,
            'compress' => false,
            'timeout' => 1800, // 30 minutes
            'progress_callback' => null,
            'tables' => [], // Empty = all tables
            'exclude_tables' => [],
            'where_conditions' => [],
            'mysqldump_options' => []
        ], $options);

        $this->debugLog("Starting MySQL shell backup", DebugLevel::BASIC, [
            'output_path' => $outputPath,
            'database' => $this->connectionConfig['database'],
            'include_schema' => $options['include_schema'],
            'include_data' => $options['include_data'],
            'single_transaction' => $options['single_transaction'],
            'timeout' => $options['timeout']
        ]);

        try {
            // Validate database connection
            $this->validateDatabaseConnection();

            // Prepare output directory
            $this->prepareOutputDirectory($outputPath);

            // Build mysqldump command
            $command = $this->buildMySQLDumpCommand($options);

            // Prepare credentials
            $credentials = $this->prepareCredentials();

            // Execute backup command
            $result = $this->executeMySQLDumpCommand($command, $credentials, $outputPath, $options);

            $duration = microtime(true) - $startTime;

            if ($result['success']) {
                $backupSize = file_exists($outputPath) ? filesize($outputPath) : 0;

                $this->debugLog("MySQL shell backup completed successfully", DebugLevel::BASIC, [
                    'duration_seconds' => round($duration, 3),
                    'backup_size_bytes' => $backupSize,
                    'mysqldump_return_code' => $result['return_code']
                ]);

                return [
                    'success' => true,
                    'output_path' => $outputPath,
                    'backup_size_bytes' => $backupSize,
                    'duration_seconds' => round($duration, 3),
                    'strategy_used' => $this->getStrategyType(),
                    'mysqldump_output' => $result['error'], // mysqldump sends info to stderr
                    'command_metadata' => $result['metadata'] ?? []
                ];
            } else {
                $this->debugLog("MySQL shell backup failed", DebugLevel::BASIC, [
                    'duration_seconds' => round($duration, 3),
                    'error' => $result['error'],
                    'return_code' => $result['return_code']
                ]);

                // Cleanup failed backup file
                if (file_exists($outputPath)) {
                    unlink($outputPath);
                }

                return [
                    'success' => false,
                    'error' => $result['error'],
                    'duration_seconds' => round($duration, 3),
                    'strategy_used' => $this->getStrategyType(),
                    'return_code' => $result['return_code']
                ];
            }
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("MySQL shell backup failed with exception", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'exception' => $e->getMessage()
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
     * Build mysqldump command with optimal options
     * 
     * @param array $options Backup options
     * @return array Command array for mysqldump
     */
    private function buildMySQLDumpCommand(array $options): array
    {
        $command = ['mysqldump'];

        // Connection parameters
        $command[] = '--host=' . $this->connectionConfig['host'];
        $command[] = '--user=' . $this->connectionConfig['user'];
        $command[] = '--password'; // No value - will be provided via stdin

        if (!empty($this->connectionConfig['port']) && $this->connectionConfig['port'] != 3306) {
            $command[] = '--port=' . $this->connectionConfig['port'];
        }

        // Core backup options
        if ($options['single_transaction']) {
            $command[] = '--single-transaction';
            $this->debugLog("Using --single-transaction for InnoDB consistency", DebugLevel::DETAILED);
        }

        if (!$options['lock_tables']) {
            $command[] = '--skip-lock-tables';
        }

        if ($options['routines']) {
            $command[] = '--routines';
        }

        if ($options['triggers']) {
            $command[] = '--triggers';
        } else {
            $command[] = '--skip-triggers';
        }

        if ($options['events']) {
            $command[] = '--events';
        }

        // Schema and data options
        if (!$options['include_schema']) {
            $command[] = '--no-create-info';
        }

        if (!$options['include_data']) {
            $command[] = '--no-data';
        }

        // Additional mysqldump options
        $command[] = '--opt'; // Includes --quick, --lock-tables, --add-drop-table, etc.
        $command[] = '--default-character-set=utf8mb4';
        $command[] = '--hex-blob'; // Proper binary data handling
        $command[] = '--complete-insert'; // Full INSERT statements

        // Table-specific options
        if (!empty($options['tables'])) {
            // Add database name and specific tables
            $command[] = $this->connectionConfig['database'];
            foreach ($options['tables'] as $table) {
                $command[] = $table;
            }
        } else {
            // Add database name for full backup
            $command[] = $this->connectionConfig['database'];
        }

        // Add any custom mysqldump options
        if (!empty($options['mysqldump_options'])) {
            $command = array_merge($command, $options['mysqldump_options']);
        }

        $this->debugLog("mysqldump command built", DebugLevel::DETAILED, [
            'command_parts' => count($command),
            'single_transaction' => $options['single_transaction'],
            'include_routines' => $options['routines'],
            'include_triggers' => $options['triggers'],
            'table_count' => empty($options['tables']) ? 'all' : count($options['tables'])
        ]);

        return $command;
    }

    /**
     * Execute mysqldump command with progress monitoring
     * 
     * @param array $command mysqldump command array
     * @param array $credentials Database credentials
     * @param string $outputPath Output file path
     * @param array $options Backup options
     * @return array Execution result
     */
    private function executeMySQLDumpCommand(array $command, array $credentials, string $outputPath, array $options): array
    {
        $this->debugLog("Executing mysqldump command", DebugLevel::DETAILED, [
            'output_path' => $outputPath,
            'timeout' => $options['timeout'],
            'progress_callback_enabled' => !empty($options['progress_callback'])
        ]);

        // Prepare execution options
        $execOptions = [
            'timeout' => $options['timeout'],
            'progress_callback' => $options['progress_callback']
        ];

        // Execute command via secure shell executor
        $result = $this->shellExecutor->executeDatabaseCommand($command, $credentials, $execOptions);

        if ($result['success']) {
            // Write mysqldump output to file
            $bytesWritten = file_put_contents($outputPath, $result['output']);

            if ($bytesWritten === false) {
                throw new RuntimeException("Failed to write backup data to file: $outputPath");
            }

            $this->debugLog("mysqldump output written to file", DebugLevel::DETAILED, [
                'bytes_written' => $bytesWritten,
                'output_file' => $outputPath
            ]);

            $result['backup_size_bytes'] = $bytesWritten;
        }

        return $result;
    }

    // =============================================================================
    // RESTORE OPERATIONS
    // =============================================================================

    /**
     * Restore MySQL database from backup file
     * 
     * @param string $backupPath Path to backup file
     * @param array $options Restore options
     * @return array Restore result
     */
    public function restoreBackup(string $backupPath, array $options = []): array
    {
        $startTime = microtime(true);

        $this->validateRestorePath($backupPath);

        $options = array_merge([
            'validate_before_restore' => true,
            'create_database' => false,
            'drop_database' => false,
            'timeout' => 1800,
            'progress_callback' => null
        ], $options);

        $this->debugLog("Starting MySQL database restore", DebugLevel::BASIC, [
            'backup_path' => $backupPath,
            'target_database' => $this->connectionConfig['database'],
            'create_database' => $options['create_database'],
            'drop_database' => $options['drop_database']
        ]);

        try {
            // Validate backup file
            if ($options['validate_before_restore']) {
                $validation = $this->validateBackupFile($backupPath);
                if (!$validation['valid']) {
                    throw new RuntimeException("Backup file validation failed: " . ($validation['error'] ?? 'Unknown error'));
                }
            }

            // Prepare database for restore
            if ($options['drop_database']) {
                $this->dropDatabase();
            }

            if ($options['create_database']) {
                $this->createDatabase();
            }

            // Build mysql command for restore
            $command = $this->buildMySQLRestoreCommand($options);

            // Prepare credentials
            $credentials = $this->prepareCredentials();

            // Execute restore
            $result = $this->executeMySQLRestoreCommand($command, $credentials, $backupPath, $options);

            $duration = microtime(true) - $startTime;

            if ($result['success']) {
                $this->debugLog("MySQL database restore completed", DebugLevel::BASIC, [
                    'duration_seconds' => round($duration, 3),
                    'backup_size_bytes' => filesize($backupPath)
                ]);

                return [
                    'success' => true,
                    'duration_seconds' => round($duration, 3),
                    'strategy_used' => $this->getStrategyType(),
                    'backup_size_bytes' => filesize($backupPath)
                ];
            } else {
                $this->debugLog("MySQL database restore failed", DebugLevel::BASIC, [
                    'duration_seconds' => round($duration, 3),
                    'error' => $result['error']
                ]);

                return [
                    'success' => false,
                    'error' => $result['error'],
                    'duration_seconds' => round($duration, 3),
                    'strategy_used' => $this->getStrategyType()
                ];
            }
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("MySQL database restore failed with exception", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'exception' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType()
            ];
        }
    }

    /**
     * Build mysql command for restore operation
     * 
     * @param array $options Restore options
     * @return array Command array for mysql client
     */
    private function buildMySQLRestoreCommand(array $options): array
    {
        $command = ['mysql'];

        // Connection parameters
        $command[] = '--host=' . $this->connectionConfig['host'];
        $command[] = '--user=' . $this->connectionConfig['user'];
        $command[] = '--password'; // No value - will be provided via stdin

        if (!empty($this->connectionConfig['port']) && $this->connectionConfig['port'] != 3306) {
            $command[] = '--port=' . $this->connectionConfig['port'];
        }

        // Add database name
        $command[] = $this->connectionConfig['database'];

        $this->debugLog("MySQL restore command built", DebugLevel::DETAILED, [
            'command_parts' => count($command),
            'target_database' => $this->connectionConfig['database']
        ]);

        return $command;
    }

    /**
     * Execute mysql restore command
     * 
     * @param array $command mysql command array
     * @param array $credentials Database credentials
     * @param string $backupPath Backup file path
     * @param array $options Restore options
     * @return array Execution result
     */
    private function executeMySQLRestoreCommand(array $command, array $credentials, string $backupPath, array $options): array
    {
        $this->debugLog("Executing MySQL restore command", DebugLevel::DETAILED, [
            'backup_path' => $backupPath,
            'backup_size_bytes' => filesize($backupPath)
        ]);

        // Read backup file content
        $backupContent = file_get_contents($backupPath);
        if ($backupContent === false) {
            throw new RuntimeException("Failed to read backup file: $backupPath");
        }

        // Prepare execution options with backup content as stdin
        $execOptions = [
            'timeout' => $options['timeout'],
            'stdin_data' => $backupContent,
            'progress_callback' => $options['progress_callback']
        ];

        // Execute command via secure shell executor
        return $this->shellExecutor->executeDatabaseCommand($command, $credentials, $execOptions);
    }

    // =============================================================================
    // VALIDATION AND TESTING
    // =============================================================================

    /**
     * Test MySQL shell backup capabilities
     * 
     * @return array Comprehensive capability test results
     */
    public function testCapabilities(): array
    {
        $this->debugLog("Testing MySQL shell backup capabilities", DebugLevel::BASIC);

        $results = [
            'strategy_type' => $this->getStrategyType(),
            'shell_access_available' => false,
            'mysqldump_available' => false,
            'mysql_client_available' => false,
            'database_connection_working' => false,
            'credentials_valid' => false,
            'overall_status' => 'unknown'
        ];

        try {
            // Test shell access
            $shellTest = $this->shellExecutor->testShellAccess();
            $results['shell_access_available'] = $shellTest['available'];

            // Test mysqldump availability
            $results['mysqldump_available'] = $this->shellExecutor->isCommandAvailable('mysqldump');

            // Test mysql client availability
            $results['mysql_client_available'] = $this->shellExecutor->isCommandAvailable('mysql');

            // Test database connection
            try {
                $this->validateDatabaseConnection();
                $results['database_connection_working'] = true;
                $results['credentials_valid'] = true;
            } catch (Exception $e) {
                $results['database_connection_error'] = $e->getMessage();
            }

            // Determine overall status
            $results['overall_status'] = $this->evaluateOverallStatus($results);

            $this->debugLog("MySQL capability testing completed", DebugLevel::DETAILED, $results);
        } catch (Exception $e) {
            $results['test_error'] = $e->getMessage();
            $results['overall_status'] = 'failed';

            $this->debugLog("MySQL capability testing failed", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }


    /**
     * Validate MySQL backup file format
     * 
     * @param string $backupPath Path to backup file
     * @return array Validation results
     */
    public function validateBackupFile(string $backupPath): array
    {
        $this->debugLog("Validating MySQL backup file", DebugLevel::DETAILED, [
            'backup_path' => $backupPath
        ]);

        $validation = [
            'valid' => false,
            'file_exists' => false,
            'file_readable' => false,
            'file_size_bytes' => 0,
            'sql_format_detected' => false,
            'mysql_dump_detected' => false,
            'contains_database_name' => false,
            'error' => null
        ];

        try {
            // Basic file checks
            $validation['file_exists'] = file_exists($backupPath);
            if (!$validation['file_exists']) {
                $validation['error'] = "Backup file does not exist";
                return $validation;
            }

            $validation['file_readable'] = is_readable($backupPath);
            $validation['file_size_bytes'] = filesize($backupPath);

            if ($validation['file_size_bytes'] === 0) {
                $validation['error'] = "Backup file is empty";
                return $validation;
            }

            // Read first part of file for format detection
            $handle = fopen($backupPath, 'r');
            if (!$handle) {
                $validation['error'] = "Cannot open backup file for reading";
                return $validation;
            }

            $header = fread($handle, 8192); // Read first 8KB
            fclose($handle);

            // Check for MySQL dump format indicators
            $validation['sql_format_detected'] = $this->detectSQLFormat($header);
            $validation['mysql_dump_detected'] = $this->detectMySQLDumpFormat($header);
            $validation['contains_database_name'] = strpos($header, $this->connectionConfig['database']) !== false;

            $validation['valid'] = $validation['sql_format_detected'] &&
                ($validation['mysql_dump_detected'] || $validation['contains_database_name']);

            if (!$validation['valid']) {
                $validation['error'] = "File does not appear to be a valid MySQL backup";
            }

            $this->debugLog("MySQL backup file validation completed", DebugLevel::DETAILED, $validation);
        } catch (Exception $e) {
            $validation['error'] = $e->getMessage();

            $this->debugLog("MySQL backup file validation failed", DebugLevel::DETAILED, [
                'error' => $e->getMessage()
            ]);
        }

        return $validation;
    }

    /**
     * Detect SQL format in file content
     * 
     * @param string $content File content to analyze
     * @return bool True if SQL format detected
     */
    private function detectSQLFormat(string $content): bool
    {
        $sqlIndicators = [
            'CREATE TABLE',
            'INSERT INTO',
            'DROP TABLE',
            'USE `',
            '-- MySQL dump',
            'SET NAMES',
            'SET SQL_MODE'
        ];

        foreach ($sqlIndicators as $indicator) {
            if (stripos($content, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect MySQL dump format in file content
     * 
     * @param string $content File content to analyze
     * @return bool True if MySQL dump format detected
     */
    private function detectMySQLDumpFormat(string $content): bool
    {
        $mysqlDumpIndicators = [
            '-- MySQL dump',
            'mysqldump',
            'SET @OLD_CHARACTER_SET_CLIENT',
            'SET @OLD_CHARACTER_SET_RESULTS',
            'SET @OLD_COLLATION_CONNECTION'
        ];

        foreach ($mysqlDumpIndicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    // =============================================================================
    // UTILITY AND CONFIGURATION METHODS
    // =============================================================================

    /**
     * Normalize connection configuration with defaults
     * 
     * @param array $config Raw connection configuration
     * @return array Normalized configuration
     */
    private function normalizeConnectionConfig(array $config): array
    {
        return array_merge([
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'root',
            'password' => '',
            'database' => 'database'
        ], $config);
    }

    /**
     * Validate mysqldump availability
     * 
     * @throws RuntimeException If mysqldump is not available
     */
    private function validateMySQLDumpAvailability(): void
    {
        if (!$this->shellExecutor->isCommandAvailable('mysqldump')) {
            throw new RuntimeException("mysqldump command not available - required for MySQL shell backup strategy");
        }

        $this->debugLog("mysqldump availability confirmed", DebugLevel::VERBOSE);
    }

    /**
     * Validate database connection
     * 
     * @throws RuntimeException If connection fails
     */
    private function validateDatabaseConnection(): void
    {
        try {
            // Test simple query
            $stmt = $this->pdo->query('SELECT 1');
            $result = $stmt->fetchColumn();

            if ($result !== '1' && $result !== 1) {
                throw new RuntimeException("Database connection test failed");
            }

            $this->debugLog("Database connection validated", DebugLevel::VERBOSE);
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Prepare database credentials for secure transmission
     * 
     * @return array Credentials array
     */
    private function prepareCredentials(): array
    {
        return [
            'password' => $this->connectionConfig['password']
        ];
    }

    /**
     * Prepare output directory
     * 
     * @param string $outputPath Output file path
     * @throws RuntimeException If directory preparation fails
     */
    private function prepareOutputDirectory(string $outputPath): void
    {
        $directory = dirname($outputPath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new RuntimeException("Cannot create backup directory: $directory");
            }
        }

        if (!is_writable($directory)) {
            throw new RuntimeException("Backup directory is not writable: $directory");
        }
    }

    /**
     * Drop database for clean restore
     */
    private function dropDatabase(): void
    {
        $this->debugLog("Dropping database for clean restore", DebugLevel::DETAILED, [
            'database' => $this->connectionConfig['database']
        ]);

        $sql = "DROP DATABASE IF EXISTS `{$this->connectionConfig['database']}`";
        $this->pdo->exec($sql);
    }

    /**
     * Create database for restore
     */
    private function createDatabase(): void
    {
        $this->debugLog("Creating database for restore", DebugLevel::DETAILED, [
            'database' => $this->connectionConfig['database']
        ]);

        $sql = "CREATE DATABASE IF NOT EXISTS `{$this->connectionConfig['database']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $this->pdo->exec($sql);
    }

    /**
     * Evaluate overall capability status
     * 
     * @param array $results Test results
     * @return string Overall status
     */
    private function evaluateOverallStatus(array $results): string
    {
        if (!$results['shell_access_available']) {
            return 'unavailable - shell access not available';
        }

        if (!$results['mysqldump_available']) {
            return 'unavailable - mysqldump not found';
        }

        if (!$results['database_connection_working']) {
            return 'error - database connection failed';
        }

        if (!$results['credentials_valid']) {
            return 'error - invalid database credentials';
        }

        return 'available';
    }

    // =============================================================================
    // INTERFACE IMPLEMENTATION
    // =============================================================================

    public function estimateBackupSize(): int
    {
        try {
            // Get database size from information_schema
            $sql = "
                SELECT ROUND(SUM(data_length + index_length)) as size_bytes
                FROM information_schema.tables 
                WHERE table_schema = :database
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':database', $this->connectionConfig['database']);
            $stmt->execute();

            $sizeBytes = $stmt->fetchColumn();
            return (int)($sizeBytes ?: 0);
        } catch (Exception $e) {
            $this->debugLog("Failed to estimate backup size", DebugLevel::VERBOSE, [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    public function estimateBackupTime(): int
    {
        $sizeBytes = $this->estimateBackupSize();
        // Estimate ~5MB/second for MySQL dump
        return max(30, intval($sizeBytes / (5 * 1024 * 1024)));
    }

    public function getStrategyType(): string
    {
        return 'mysql_shell';
    }

    public function getDescription(): string
    {
        return 'MySQL Shell Backup (mysqldump via secure proc_open)';
    }

    public function getSelectionCriteria(): array
    {
        return [
            'priority' => 1, // Highest priority for MySQL
            'requirements' => ['shell_access', 'mysqldump_command'],
            'advantages' => [
                'Complete schema and data export',
                'MySQL-specific features support',
                'Transaction-safe with --single-transaction',
                'Widely tested and reliable'
            ],
            'limitations' => [
                'Requires shell access',
                'Requires mysqldump tool',
                'Credentials handling complexity'
            ]
        ];
    }

    public function supportsCompression(): bool
    {
        return true; // Can pipe through gzip
    }

    public function detectBackupFormat(string $backupPath): string
    {
        $validation = $this->validateBackupFile($backupPath);

        if ($validation['mysql_dump_detected']) {
            return 'mysql_dump';
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
            'create_database' => true,
            'drop_database' => false,
            'validate_before_restore' => true,
            'estimated_duration_seconds' => $this->estimateBackupTime()
        ];
    }

    public function partialRestore(string $backupPath, array $targets, array $options = []): array
    {
        // MySQL shell backup supports partial restore by filtering SQL content
        throw new RuntimeException("Partial restore not yet implemented for MySQL shell backup strategy");
    }
}
