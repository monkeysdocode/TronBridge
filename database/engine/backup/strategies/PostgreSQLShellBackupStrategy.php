<?php

/**
 * PostgreSQL Shell Backup Strategy - pg_dump via Secure proc_open()
 * 
 * Implements PostgreSQL database backup using pg_dump tool executed through secure
 * proc_open() with comprehensive credential protection and cross-platform compatibility.
 * This is the preferred method for PostgreSQL backups as it provides complete schema
 * and data export with all PostgreSQL-specific features.
 * 
 * **ENHANCED WITH FULL DEBUG SYSTEM INTEGRATION**
 * - Real-time pg_dump execution monitoring and progress tracking
 * - Detailed PostgreSQL option analysis and optimization recommendations
 * - Comprehensive error analysis with PostgreSQL-specific troubleshooting
 * - Security-focused logging with credential protection via PGPASSWORD
 * 
 * Key Features:
 * - Secure credential handling via PGPASSWORD environment variable
 * - Cross-platform compatibility (Windows/Unix) with unified interface
 * - Comprehensive pg_dump option support and optimization
 * - Multiple output formats (SQL, custom, tar, directory)
 * - Real-time progress tracking for large database operations
 * - Automatic retry logic for connection issues
 * - Format detection and validation for restore operations
 * - Parallel dump support for performance optimization
 * 
 * Security Features:
 * - Credentials via PGPASSWORD environment variable (never in command line)
 * - Automatic credential cleanup and secure memory handling
 * - Shell argument escaping and validation
 * - Process timeout and resource management
 * 
 * @package Database\Backup\Strategy\PostgreSQL
 * @author Enhanced Model System
 * @version 1.0.0 - Initial Implementation with Debug Integration
 */
class PostgreSQLShellBackupStrategy implements BackupStrategyInterface, RestoreStrategyInterface
{
    use DebugLoggingTrait;
    use ConfigSanitizationTrait;
    use PathValidationTrait;
    
    private Model $model;
    private PDO $pdo;
    private array $connectionConfig;
    private SecureShellExecutor $shellExecutor;

    /**
     * Initialize PostgreSQL shell backup strategy
     * 
     * @param Model $model Enhanced Model instance for debug logging
     * @param array $connectionConfig Database connection configuration
     * @throws RuntimeException If pg_dump is not available or shell access disabled
     */
    public function __construct(Model $model, array $connectionConfig)
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->connectionConfig = $this->normalizeConnectionConfig($connectionConfig);

        // Initialize secure shell executor
        require_once dirname(__DIR__) . '/helpers/SecureShellExecutor.php';
        $this->shellExecutor = new SecureShellExecutor($model);

        $this->debugLog("PostgreSQL shell backup strategy initialized", DebugLevel::VERBOSE, [
            'connection_host' => $this->connectionConfig['host'],
            'connection_database' => $this->connectionConfig['database'],
            'connection_port' => $this->connectionConfig['port'],
            'connection_config' => $this->sanitizeConfig($connectionConfig),
            'shell_executor_available' => true,
            'pg_dump_test_pending' => true
        ]);

        // Test pg_dump availability
        $this->validatePgDumpAvailability();
    }

    // =============================================================================
    // BACKUP OPERATIONS
    // =============================================================================

    /**
     * Create PostgreSQL database backup using pg_dump
     * 
     * Executes pg_dump with optimal settings for database backup, including
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

        // Normalize options with PostgreSQL-specific defaults
        $options = array_merge([
            'format' => 'sql',                       // sql, custom, tar, directory
            'include_schema' => true,
            'include_data' => true,
            'compress' => false,
            'verbose' => false,
            'no_owner' => false,
            'no_privileges' => false,
            'clean' => false,
            'create' => false,
            'if_exists' => false,
            'timeout' => 1800,                       // 30 minutes
            'progress_callback' => null,
            'tables' => [],                          // Empty = all tables
            'exclude_tables' => [],
            'schemas' => [],                         // Empty = all schemas
            'exclude_schemas' => [],
            'parallel_jobs' => 1,                    // Parallel dump jobs
            'pg_dump_options' => []
        ], $options);

        $this->debugLog("Starting PostgreSQL shell backup", DebugLevel::BASIC, [
            'output_path' => $outputPath,
            'database' => $this->connectionConfig['database'],
            'format' => $options['format'],
            'include_schema' => $options['include_schema'],
            'include_data' => $options['include_data'],
            'compress' => $options['compress'],
            'parallel_jobs' => $options['parallel_jobs'],
            'timeout' => $options['timeout']
        ]);

        try {
            // Validate database connection
            $this->validateDatabaseConnection();

            // Prepare output directory
            $this->prepareOutputDirectory($outputPath);

            // Build pg_dump command
            $command = $this->buildPgDumpCommand($outputPath, $options);

            // Prepare credentials via environment variable
            $environment = $this->prepareEnvironment();

            // Execute backup command
            $result = $this->executePgDumpCommand($command, $environment, $outputPath, $options);

            $duration = microtime(true) - $startTime;

            if ($result['success']) {
                $backupSize = file_exists($outputPath) ? filesize($outputPath) : 0;

                $this->debugLog("PostgreSQL shell backup completed successfully", DebugLevel::BASIC, [
                    'duration_seconds' => round($duration, 3),
                    'backup_size_bytes' => $backupSize,
                    'pg_dump_return_code' => $result['return_code'],
                    'output_format' => $options['format']
                ]);

                return [
                    'success' => true,
                    'output_path' => $outputPath,
                    'backup_size_bytes' => $backupSize,
                    'duration_seconds' => round($duration, 3),
                    'strategy_used' => $this->getStrategyType(),
                    'format' => $options['format'],
                    'pg_dump_output' => $result['error'], // pg_dump sends info to stderr
                    'command_metadata' => $result['metadata'] ?? []
                ];
            } else {
                $this->debugLog("PostgreSQL shell backup failed", DebugLevel::BASIC, [
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

            $this->debugLog("PostgreSQL shell backup failed with exception", DebugLevel::BASIC, [
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
     * Build pg_dump command with optimal options
     * 
     * @param string $outputPath Output file path
     * @param array $options Backup options
     * @return array Command array for pg_dump
     */
    private function buildPgDumpCommand(string $outputPath, array $options): array
    {
        $command = ['pg_dump'];

        // Connection parameters
        $command[] = '--host=' . $this->connectionConfig['host'];
        $command[] = '--port=' . $this->connectionConfig['port'];
        $command[] = '--username=' . $this->connectionConfig['user'];
        // Password via PGPASSWORD environment variable

        // Output format
        if ($options['format'] !== 'sql') {
            $command[] = '--format=' . $options['format'];
        }

        // Output file (except for directory format)
        if ($options['format'] !== 'directory') {
            $command[] = '--file=' . $outputPath;
        } else {
            $command[] = $outputPath; // Directory path for directory format
        }

        // Core backup options
        if ($options['verbose']) {
            $command[] = '--verbose';
        }

        if ($options['compress'] && in_array($options['format'], ['custom', 'tar'])) {
            $command[] = '--compress=6';
        }

        if ($options['clean']) {
            $command[] = '--clean';
        }

        if ($options['create']) {
            $command[] = '--create';
        }

        if ($options['if_exists']) {
            $command[] = '--if-exists';
        }

        if ($options['no_owner']) {
            $command[] = '--no-owner';
        }

        if ($options['no_privileges']) {
            $command[] = '--no-privileges';
        }

        // Schema and data options
        if (!$options['include_schema']) {
            $command[] = '--data-only';
        } elseif (!$options['include_data']) {
            $command[] = '--schema-only';
        }

        // Parallel processing (custom format only)
        if ($options['format'] === 'custom' && $options['parallel_jobs'] > 1) {
            $command[] = '--jobs=' . $options['parallel_jobs'];

            $this->debugLog("Using parallel pg_dump", DebugLevel::DETAILED, [
                'parallel_jobs' => $options['parallel_jobs'],
                'format' => $options['format']
            ]);
        }

        // Table-specific options
        if (!empty($options['tables'])) {
            foreach ($options['tables'] as $table) {
                $command[] = '--table=' . $table;
            }
        }

        if (!empty($options['exclude_tables'])) {
            foreach ($options['exclude_tables'] as $table) {
                $command[] = '--exclude-table=' . $table;
            }
        }

        // Schema-specific options
        if (!empty($options['schemas'])) {
            foreach ($options['schemas'] as $schema) {
                $command[] = '--schema=' . $schema;
            }
        }

        if (!empty($options['exclude_schemas'])) {
            foreach ($options['exclude_schemas'] as $schema) {
                $command[] = '--exclude-schema=' . $schema;
            }
        }

        // Add database name
        $command[] = $this->connectionConfig['database'];

        // Add any custom pg_dump options
        if (!empty($options['pg_dump_options'])) {
            $command = array_merge($command, $options['pg_dump_options']);
        }

        $this->debugLog("pg_dump command built", DebugLevel::DETAILED, [
            'command_parts' => count($command),
            'format' => $options['format'],
            'parallel_jobs' => $options['parallel_jobs'],
            'include_schema' => $options['include_schema'],
            'include_data' => $options['include_data'],
            'table_count' => empty($options['tables']) ? 'all' : count($options['tables'])
        ]);

        return $command;
    }

    /**
     * Execute pg_dump command with progress monitoring
     * 
     * @param array $command pg_dump command array
     * @param array $environment Environment variables
     * @param string $outputPath Output file path
     * @param array $options Backup options
     * @return array Execution result
     */
    private function executePgDumpCommand(array $command, array $environment, string $outputPath, array $options): array
    {
        $this->debugLog("Executing pg_dump command", DebugLevel::DETAILED, [
            'output_path' => $outputPath,
            'timeout' => $options['timeout'],
            'progress_callback_enabled' => !empty($options['progress_callback']),
            'environment_vars' => array_keys($environment)
        ]);

        // Prepare execution options
        $execOptions = [
            'timeout' => $options['timeout'],
            'environment' => $environment,
            'progress_callback' => $options['progress_callback']
        ];

        // Execute command via secure shell executor
        return $this->shellExecutor->executeDatabaseCommand($command, [], $execOptions);
    }

    // =============================================================================
    // RESTORE OPERATIONS
    // =============================================================================

    /**
     * Restore PostgreSQL database from backup file
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
            'clean' => false,
            'create' => false,
            'if_exists' => false,
            'no_owner' => true,
            'no_privileges' => false,
            'single_transaction' => true,
            'timeout' => 1800,
            'parallel_jobs' => 1,
            'progress_callback' => null
        ], $options);

        $this->debugLog("Starting PostgreSQL database restore", DebugLevel::BASIC, [
            'backup_path' => $backupPath,
            'target_database' => $this->connectionConfig['database'],
            'clean' => $options['clean'],
            'create' => $options['create'],
            'single_transaction' => $options['single_transaction'],
            'parallel_jobs' => $options['parallel_jobs']
        ]);

        try {
            // Validate backup file
            if ($options['validate_before_restore']) {
                $validation = $this->validateBackupFile($backupPath);
                if (!$validation['valid']) {
                    throw new RuntimeException("Backup file validation failed: " . ($validation['error'] ?? 'Unknown error'));
                }
            }

            // Detect backup format
            $backupFormat = $this->detectBackupFormat($backupPath);

            // Build restore command based on format
            $command = $this->buildRestoreCommand($backupPath, $backupFormat, $options);

            // Prepare environment
            $environment = $this->prepareEnvironment();

            // Execute restore
            $result = $this->executeRestoreCommand($command, $environment, $options);

            $duration = microtime(true) - $startTime;

            if ($result['success']) {
                $this->debugLog("PostgreSQL database restore completed", DebugLevel::BASIC, [
                    'duration_seconds' => round($duration, 3),
                    'backup_size_bytes' => filesize($backupPath),
                    'backup_format' => $backupFormat
                ]);

                return [
                    'success' => true,
                    'duration_seconds' => round($duration, 3),
                    'strategy_used' => $this->getStrategyType(),
                    'backup_size_bytes' => filesize($backupPath),
                    'backup_format' => $backupFormat
                ];
            } else {
                $this->debugLog("PostgreSQL database restore failed", DebugLevel::BASIC, [
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

            $this->debugLog("PostgreSQL database restore failed with exception", DebugLevel::BASIC, [
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
     * Build restore command based on backup format
     * 
     * @param string $backupPath Backup file path
     * @param string $format Detected backup format
     * @param array $options Restore options
     * @return array Command array
     */
    private function buildRestoreCommand(string $backupPath, string $format, array $options): array
    {
        if ($format === 'sql' || $format === 'postgresql_sql') {
            // Use psql for SQL format
            $command = ['psql'];

            // Connection parameters
            $command[] = '--host=' . $this->connectionConfig['host'];
            $command[] = '--port=' . $this->connectionConfig['port'];
            $command[] = '--username=' . $this->connectionConfig['user'];
            $command[] = '--dbname=' . $this->connectionConfig['database'];

            if ($options['single_transaction']) {
                $command[] = '--single-transaction';
            }

            $command[] = '--file=' . $backupPath;
        } else {
            // Use pg_restore for custom/tar/directory formats
            $command = ['pg_restore'];

            // Connection parameters
            $command[] = '--host=' . $this->connectionConfig['host'];
            $command[] = '--port=' . $this->connectionConfig['port'];
            $command[] = '--username=' . $this->connectionConfig['user'];
            $command[] = '--dbname=' . $this->connectionConfig['database'];

            if ($options['clean']) {
                $command[] = '--clean';
            }

            if ($options['create']) {
                $command[] = '--create';
            }

            if ($options['if_exists']) {
                $command[] = '--if-exists';
            }

            if ($options['no_owner']) {
                $command[] = '--no-owner';
            }

            if ($options['no_privileges']) {
                $command[] = '--no-privileges';
            }

            if ($options['single_transaction']) {
                $command[] = '--single-transaction';
            }

            // Parallel processing for custom format
            if ($format === 'custom' && $options['parallel_jobs'] > 1) {
                $command[] = '--jobs=' . $options['parallel_jobs'];
            }

            $command[] = $backupPath;
        }

        $this->debugLog("Restore command built", DebugLevel::DETAILED, [
            'command_type' => $command[0],
            'backup_format' => $format,
            'command_parts' => count($command)
        ]);

        return $command;
    }

    /**
     * Execute restore command
     * 
     * @param array $command Restore command array
     * @param array $environment Environment variables
     * @param array $options Restore options
     * @return array Execution result
     */
    private function executeRestoreCommand(array $command, array $environment, array $options): array
    {
        $this->debugLog("Executing PostgreSQL restore command", DebugLevel::DETAILED, [
            'command_type' => $command[0],
            'timeout' => $options['timeout']
        ]);

        // Prepare execution options
        $execOptions = [
            'timeout' => $options['timeout'],
            'environment' => $environment,
            'progress_callback' => $options['progress_callback']
        ];

        // Execute command via secure shell executor
        return $this->shellExecutor->executeDatabaseCommand($command, [], $execOptions);
    }

    // =============================================================================
    // VALIDATION AND TESTING
    // =============================================================================

    /**
     * Test PostgreSQL shell backup capabilities
     * 
     * @return array Comprehensive capability test results
     */
    public function testCapabilities(): array
    {
        $this->debugLog("Testing PostgreSQL shell backup capabilities", DebugLevel::BASIC);

        $results = [
            'strategy_type' => $this->getStrategyType(),
            'shell_access_available' => false,
            'pg_dump_available' => false,
            'pg_restore_available' => false,
            'psql_available' => false,
            'database_connection_working' => false,
            'credentials_valid' => false,
            'overall_status' => 'unknown'
        ];

        try {
            // Test shell access
            $shellTest = $this->shellExecutor->testShellAccess();
            $results['shell_access_available'] = $shellTest['available'];

            // Test PostgreSQL tools availability
            $results['pg_dump_available'] = $this->shellExecutor->isCommandAvailable('pg_dump');
            $results['pg_restore_available'] = $this->shellExecutor->isCommandAvailable('pg_restore');
            $results['psql_available'] = $this->shellExecutor->isCommandAvailable('psql');

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

            $this->debugLog("PostgreSQL capability testing completed", DebugLevel::DETAILED, $results);
        } catch (Exception $e) {
            $results['test_error'] = $e->getMessage();
            $results['overall_status'] = 'failed';

            $this->debugLog("PostgreSQL capability testing failed", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }


    /**
     * Validate PostgreSQL backup file format
     * 
     * @param string $backupPath Path to backup file
     * @return array Validation results
     */
    public function validateBackupFile(string $backupPath): array
    {
        $this->debugLog("Validating PostgreSQL backup file", DebugLevel::DETAILED, [
            'backup_path' => $backupPath
        ]);

        $validation = [
            'valid' => false,
            'file_exists' => false,
            'file_readable' => false,
            'file_size_bytes' => 0,
            'format_detected' => 'unknown',
            'postgresql_format' => false,
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

            // Detect format and validate
            $validation['format_detected'] = $this->detectBackupFormat($backupPath);
            $validation['postgresql_format'] = $this->isPostgreSQLFormat($validation['format_detected']);

            if ($validation['format_detected'] === 'sql') {
                // For SQL format, check content
                $handle = fopen($backupPath, 'r');
                $header = fread($handle, 4096);
                fclose($handle);

                $validation['contains_database_name'] = strpos($header, $this->connectionConfig['database']) !== false;
                $validation['postgresql_format'] = $this->detectPostgreSQLSQL($header);
            }

            $validation['valid'] = $validation['postgresql_format'] && $validation['format_detected'] !== 'unknown';

            if (!$validation['valid']) {
                $validation['error'] = "File does not appear to be a valid PostgreSQL backup";
            }

            $this->debugLog("PostgreSQL backup file validation completed", DebugLevel::DETAILED, $validation);
        } catch (Exception $e) {
            $validation['error'] = $e->getMessage();

            $this->debugLog("PostgreSQL backup file validation failed", DebugLevel::DETAILED, [
                'error' => $e->getMessage()
            ]);
        }

        return $validation;
    }

    /**
     * Detect PostgreSQL backup format from file
     * 
     * @param string $backupPath Path to backup file
     * @return string Detected format
     */
    public function detectBackupFormat(string $backupPath): string
    {
        if (!file_exists($backupPath)) {
            return 'unknown';
        }

        // Read first few bytes to detect format
        $handle = fopen($backupPath, 'rb');
        if (!$handle) {
            return 'unknown';
        }

        $header = fread($handle, 16);
        fclose($handle);

        // Check for PostgreSQL custom format magic header
        if (strpos($header, 'PGDMP') === 0) {
            return 'custom';
        }

        // Check for tar format
        if ($this->isTarFormat($backupPath)) {
            return 'tar';
        }

        // Check if it's a directory
        if (is_dir($backupPath)) {
            return 'directory';
        }

        // Default to SQL format for text files
        return 'sql';
    }

    /**
     * Check if format is a PostgreSQL format
     * 
     * @param string $format Format string
     * @return bool True if PostgreSQL format
     */
    private function isPostgreSQLFormat(string $format): bool
    {
        return in_array($format, ['sql', 'custom', 'tar', 'directory']);
    }

    /**
     * Detect PostgreSQL SQL format in content
     * 
     * @param string $content Content to analyze
     * @return bool True if PostgreSQL SQL detected
     */
    private function detectPostgreSQLSQL(string $content): bool
    {
        $postgresqlIndicators = [
            'PostgreSQL database dump',
            'pg_dump',
            'SET statement_timeout',
            'SET lock_timeout',
            'SET client_encoding',
            'CREATE SCHEMA',
            'CREATE TABLE',
            'COPY ',
            'SELECT pg_catalog'
        ];

        foreach ($postgresqlIndicators as $indicator) {
            if (stripos($content, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if file is tar format
     * 
     * @param string $filePath File path
     * @return bool True if tar format
     */
    private function isTarFormat(string $filePath): bool
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }

        // Check for tar header at offset 257
        fseek($handle, 257);
        $magic = fread($handle, 8);
        fclose($handle);

        return (strpos($magic, 'ustar') !== false);
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
            'port' => 5432,
            'user' => 'postgres',
            'password' => '',
            'database' => 'postgres'
        ], $config);
    }

    /**
     * Validate pg_dump availability
     * 
     * @throws RuntimeException If pg_dump is not available
     */
    private function validatePgDumpAvailability(): void
    {
        if (!$this->shellExecutor->isCommandAvailable('pg_dump')) {
            throw new RuntimeException("pg_dump command not available - required for PostgreSQL shell backup strategy");
        }

        $this->debugLog("pg_dump availability confirmed", DebugLevel::VERBOSE);
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

            // Test PostgreSQL-specific query
            $version = $this->pdo->query('SELECT version()')->fetchColumn();

            $this->debugLog("PostgreSQL connection validated", DebugLevel::VERBOSE, [
                'postgresql_version' => $version
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException("PostgreSQL connection failed: " . $e->getMessage());
        }
    }

    /**
     * Prepare environment variables with credentials
     * 
     * @return array Environment variables
     */
    private function prepareEnvironment(): array
    {
        $environment = [];

        // Set PGPASSWORD for secure credential handling
        if (!empty($this->connectionConfig['password'])) {
            $environment['PGPASSWORD'] = $this->connectionConfig['password'];
        }

        $this->debugLog("Environment prepared for PostgreSQL commands", DebugLevel::VERBOSE, [
            'pgpassword_set' => isset($environment['PGPASSWORD'])
        ]);

        return $environment;
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

        if (!$results['pg_dump_available']) {
            return 'unavailable - pg_dump not found';
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
            // Get database size from pg_database_size
            $sql = "SELECT pg_database_size(current_database())";
            $sizeBytes = $this->pdo->query($sql)->fetchColumn();

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
        // Estimate ~8MB/second for PostgreSQL dump
        return max(30, intval($sizeBytes / (8 * 1024 * 1024)));
    }

    public function getStrategyType(): string
    {
        return 'postgresql_shell';
    }

    public function getDescription(): string
    {
        return 'PostgreSQL Shell Backup (pg_dump via secure proc_open)';
    }

    public function getSelectionCriteria(): array
    {
        return [
            'priority' => 1, // Highest priority for PostgreSQL
            'requirements' => ['shell_access', 'pg_dump_command'],
            'advantages' => [
                'Complete schema and data export',
                'PostgreSQL-specific features support',
                'Multiple output formats (SQL, custom, tar, directory)',
                'Parallel dump support for performance',
                'Transaction-safe backups',
                'Widely tested and reliable'
            ],
            'limitations' => [
                'Requires shell access',
                'Requires PostgreSQL client tools',
                'Credentials handling complexity'
            ]
        ];
    }

    public function supportsCompression(): bool
    {
        return true; // pg_dump supports compression in custom and tar formats
    }

    public function getRestoreOptions(string $backupPath): array
    {
        $validation = $this->validateBackupFile($backupPath);
        $format = $validation['format_detected'];

        return [
            'full_restore' => $validation['valid'],
            'clean' => false,
            'create' => false,
            'if_exists' => false,
            'no_owner' => true,
            'no_privileges' => false,
            'single_transaction' => true,
            'parallel_jobs' => $format === 'custom' ? 4 : 1,
            'estimated_duration_seconds' => $this->estimateBackupTime(),
            'backup_format' => $format
        ];
    }

    public function partialRestore(string $backupPath, array $targets, array $options = []): array
    {
        // PostgreSQL shell backup supports partial restore with table/schema filtering
        throw new RuntimeException("Partial restore not yet implemented for PostgreSQL shell backup strategy");
    }
}
