<?php

require_once dirname(__DIR__) . '/backup/interfaces/BackupStrategyInterface.php';
require_once dirname(__DIR__) . '/backup/interfaces/RestoreStrategyInterface.php';

require_once dirname(__DIR__) . '/backup/traits/ConfigSanitizationTrait.php';
require_once dirname(__DIR__) . '/backup/traits/DebugLoggingTrait.php';
require_once dirname(__DIR__) . '/backup/traits/PathValidationTrait.php';



/**
 * Database Backup Factory - Comprehensive Multi-Database Backup System
 * 
 * Provides unified backup operations across MySQL, SQLite, and PostgreSQL databases
 * with intelligent strategy selection, secure shell command execution, and comprehensive
 * fallback mechanisms. Features automatic capability detection and enhanced debug integration.
 * 
 * **ENHANCED WITH FULL DEBUG SYSTEM INTEGRATION**
 * - All operations logged through Model's debug system using DebugCategory::MAINTENANCE
 * - Supports all debug levels (BASIC, DETAILED, VERBOSE) with contextual information
 * - Zero overhead when debugging disabled
 * - Progress tracking and detailed capability analysis
 * 
 * Key Features:
 * - Cross-database backup operations with unified API
 * - Secure proc_open() implementation with credential protection
 * - Multiple backup strategies per database with automatic selection
 * - Large database handling with progress tracking and timeout protection
 * - Comprehensive error handling and cleanup
 * - Database-specific optimizations and format support
 * - Enhanced debug logging and performance monitoring
 * 
 * Backup Strategies:
 * - **SQLite**: Native SQLite3::backup() → VACUUM INTO → File copy
 * - **MySQL**: mysqldump via proc_open() → PHP SQL generation
 * - **PostgreSQL**: pg_dump via proc_open() → PHP SQL generation
 * 
 * @package Database\Backup
 * @author Enhanced Model System
 * @version 1.0.0 - Initial Implementation with Debug Integration
 */
class DatabaseBackupFactory
{
    use DebugLoggingTrait;

    private Model $model;
    private PDO $pdo;
    private string $dbType;
    private array $capabilities;
    private array $connectionConfig;
    private array $loadedStrategies = [];

    /**
     * Initialize backup factory with Enhanced Model instance
     * 
     * Automatically inherits debug configuration from parent Model instance.
     * All backup operations will be logged through the Model's debug system.
     * 
     * @param Model $model Enhanced Model instance with active database connection
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->dbType = $model->getDbType();

        // Detect system capabilities for strategy selection
        $this->capabilities = $this->detectCapabilities();
        $this->connectionConfig = $this->extractConnectionConfig();

        $this->debugLog("Database backup factory initialized", DebugLevel::VERBOSE, [
            'database_type' => $this->dbType,
            'capabilities' => $this->capabilities,
            'available_strategies' => $this->getAvailableStrategies()
        ]);
    }


    // =============================================================================
    // MAIN BACKUP INTERFACE
    // =============================================================================

    /**
     * Create database backup with intelligent strategy selection and automatic fallback
     * 
     * @param string $outputPath Path where backup file should be created
     * @param array $options Backup configuration options
     * @return array Backup result with success status, metadata, and diagnostics
     * @throws RuntimeException If backup fails and no fallback strategies available
     */
    public function createBackup(string $outputPath, array $options = []): array
    {
        $startTime = microtime(true);

        // Validate and normalize options
        $options = $this->normalizeOptions($options);

        $this->debugLog("Starting database backup with intelligent strategy selection", DebugLevel::BASIC, [
            'output_path' => $outputPath,
            'options' => $options,
            'database_type' => $this->dbType
        ]);

        try {
            // FIXED: Select best strategy based on options and capabilities
            // This now passes $options to selectBestStrategy() so it can check for forcing options
            $selectedStrategy = $this->selectBestStrategy($options);

            $this->debugLog("Selected backup strategy", DebugLevel::BASIC, [
                'selected_strategy' => get_class($selectedStrategy),
                'strategy_type' => $selectedStrategy->getStrategyType()
            ]);

            // Perform backup with selected strategy
            $result = $selectedStrategy->createBackup($outputPath, $options);

            if ($result['success']) {
                $duration = microtime(true) - $startTime;

                $this->debugLog("Backup completed successfully", DebugLevel::BASIC, [
                    'strategy_used' => get_class($selectedStrategy),
                    'output_path' => $result['output_path'],
                    'backup_size_bytes' => $result['backup_size_bytes'] ?? 0,
                    'duration_seconds' => round($duration, 3)
                ]);

                // Add factory metadata to result
                $result['strategy_used'] = $selectedStrategy->getStrategyType();
                $result['duration_seconds'] = round($duration, 3);

                return $result;
            } else {
                // Strategy failed, but we still have a result to return
                $duration = microtime(true) - $startTime;
                $result['duration_seconds'] = round($duration, 3);
                $result['strategy_used'] = $selectedStrategy->getStrategyType();

                $this->debugLog("Backup failed with selected strategy", DebugLevel::BASIC, [
                    'strategy_used' => get_class($selectedStrategy),
                    'error' => $result['error'] ?? 'Unknown error',
                    'duration_seconds' => $result['duration_seconds']
                ]);

                return $result;
            }
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("Backup failed with exception", DebugLevel::BASIC, [
                'exception' => $e->getMessage(),
                'duration_seconds' => round($duration, 3)
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => 'none'
            ];
        }
    }

    /**
     * Restore database from backup file
     * 
     * Restores database from a previously created backup file using appropriate
     * restoration strategy for the database type and backup format.
     * 
     * @param string $backupPath Path to backup file
     * @param array $options Restore configuration options
     * @return array Restore result with success status and metadata
     */
    public function restoreBackup(string $backupPath, array $options = []): array
    {
        $startTime = microtime(true);

        if (!file_exists($backupPath)) {
            throw new InvalidArgumentException("Backup file not found: $backupPath");
        }

        $this->debugLog("Starting database restore", DebugLevel::BASIC, [
            'backup_path' => $backupPath,
            'backup_size_bytes' => filesize($backupPath),
            'options' => $options
        ]);

        try {
            $strategy = $this->selectRestoreStrategy($backupPath, $options);

            $this->debugLog("Selected restore strategy", DebugLevel::DETAILED, [
                'strategy_class' => get_class($strategy),
                'backup_format_detected' => $strategy->detectBackupFormat($backupPath)
            ]);

            $result = $strategy->restoreBackup($backupPath, $options);

            $duration = microtime(true) - $startTime;

            $this->debugLog("Database restore completed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'success' => $result['success'],
                'strategy_used' => get_class($strategy)
            ]);

            $result['metadata'] = [
                'duration_seconds' => round($duration, 3),
                'strategy_used' => get_class($strategy),
                'backup_size_bytes' => filesize($backupPath)
            ];

            return $result;
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("Database restore failed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'exception' => $e->getMessage()
            ]);

            throw new RuntimeException("Database restore failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate backup file for restoration
     * 
     * Performs comprehensive validation of backup file including format verification,
     * integrity checks, and compatibility analysis using the appropriate backup strategy
     * for the database type and backup format.
     * 
     * @param string $backupPath Path to backup file to validate
     * @return array Validation results with success status and detailed analysis
     * @throws InvalidArgumentException If backup file not found
     * @throws RuntimeException If no suitable strategy available for validation
     * 
     * @example
     * // Validate backup file before restore
     * $validation = $model->backup()->validateBackupFile('/path/to/backup.sql');
     * if ($validation['valid']) {
     *     echo "✅ Backup is valid for restore";
     *     echo "Format: " . $validation['format'];
     *     echo "Database type: " . $validation['database_type'];
     * } else {
     *     echo "❌ Backup validation failed: " . $validation['error'];
     * }
     */
    public function validateBackupFile(string $backupPath): array
    {
        $startTime = microtime(true);

        $this->debugLog("Starting backup file validation", DebugLevel::BASIC, [
            'backup_path' => $backupPath,
            'database_type' => $this->dbType
        ]);

        try {
            // Select appropriate validation strategy (includes SQLite format detection)
            $validationStrategy = $this->selectValidationStrategy($backupPath);

            // Perform validation using selected strategy
            $result = $validationStrategy->validateBackupFile($backupPath);

            // Add format detection information for SQLite
            if ($this->dbType === 'sqlite') {
                $detectedFormat = $this->detectSQLiteFileFormat($backupPath);
                $result['detected_format'] = $detectedFormat;
                $result['validation_strategy'] = get_class($validationStrategy);
            }

            $duration = microtime(true) - $startTime;

            $this->debugLog("Backup file validation completed", DebugLevel::BASIC, [
                'validation_result' => $result['valid'] ?? false,
                'validation_strategy' => get_class($validationStrategy),
                'duration_seconds' => round($duration, 3),
                'detected_format' => $result['detected_format'] ?? 'not_applicable'
            ]);

            return $result;
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("Backup file validation failed", DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 3)
            ]);

            return [
                'valid' => false,
                'error' => 'Validation failed: ' . $e->getMessage(),
                'backup_path' => $backupPath,
                'database_type' => $this->dbType
            ];
        }
    }

    /**
     * Get restore options for backup file
     * 
     * Analyzes backup file and returns available restore options based on the backup
     * content, format, and database compatibility. Provides detailed information about
     * restoration capabilities and requirements.
     * 
     * @param string $backupPath Path to backup file to analyze
     * @return array Available restore options with detailed analysis
     * @throws InvalidArgumentException If backup file not found
     * @throws RuntimeException If no suitable strategy available for analysis
     * 
     * @example
     * // Get restore options for backup file
     * $options = $model->backup()->getRestoreOptions('/path/to/backup.sql');
     * echo "Full restore supported: " . ($options['full_restore'] ? 'Yes' : 'No');
     * echo "Partial restore supported: " . ($options['partial_restore'] ? 'Yes' : 'No');
     * echo "Estimated restore time: " . $options['estimated_duration_seconds'] . " seconds";
     * 
     * if (!empty($options['available_tables'])) {
     *     echo "Tables in backup: " . implode(', ', $options['available_tables']);
     * }
     */
    public function getRestoreOptions(string $backupPath): array
    {
        $startTime = microtime(true);

        if (!file_exists($backupPath)) {
            throw new InvalidArgumentException("Backup file not found: $backupPath");
        }

        $this->debugLog("Analyzing backup file for restore options", DebugLevel::BASIC, [
            'backup_path' => $backupPath,
            'backup_size_bytes' => filesize($backupPath),
            'database_type' => $this->dbType
        ]);

        try {
            // Select appropriate strategy for analysis
            $strategy = $this->selectRestoreOptionsStrategy($backupPath);

            $this->debugLog("Selected restore analysis strategy", DebugLevel::DETAILED, [
                'strategy_class' => get_class($strategy),
                'strategy_type' => $strategy->getStrategyType(),
                'detected_format' => method_exists($strategy, 'detectBackupFormat') ?
                    $strategy->detectBackupFormat($backupPath) : 'unknown'
            ]);

            // Get restore options using selected strategy
            $result = $strategy->getRestoreOptions($backupPath);

            $duration = microtime(true) - $startTime;

            $this->debugLog("Restore options analysis completed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'analysis_strategy' => get_class($strategy),
                'full_restore_available' => $result['full_restore'] ?? false,
                'partial_restore_available' => $result['partial_restore'] ?? false,
                'tables_count' => count($result['available_tables'] ?? [])
            ]);

            // Add metadata to result
            $result['metadata'] = [
                'duration_seconds' => round($duration, 3),
                'strategy_used' => get_class($strategy),
                'backup_size_bytes' => filesize($backupPath),
                'analysis_timestamp' => time(),
                'compatible_database_type' => $this->dbType
            ];

            return $result;
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("Restore options analysis failed with exception", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e)
            ]);

            // Return basic options with error information
            return [
                'full_restore' => false,
                'partial_restore' => false,
                'error' => "Analysis failed: " . $e->getMessage(),
                'available_tables' => [],
                'estimated_duration_seconds' => 0,
                'metadata' => [
                    'duration_seconds' => round($duration, 3),
                    'exception_occurred' => true,
                    'backup_size_bytes' => filesize($backupPath)
                ]
            ];
        }
    }

    // =============================================================================
    // CAPABILITY DETECTION AND STRATEGY SELECTION
    // =============================================================================

    /**
     * Detect system capabilities for backup strategy selection
     * 
     * Performs comprehensive analysis of available backup methods, shell access,
     * external tools, and system constraints to determine optimal backup strategies.
     * 
     * @return array Comprehensive capability analysis
     */
    private function detectCapabilities(): array
    {
        $this->debugLog("Detecting system capabilities", DebugLevel::VERBOSE);

        $capabilities = [
            // PHP function availability
            'proc_open' => function_exists('proc_open'),
            'shell_exec' => function_exists('shell_exec'),
            'exec' => function_exists('exec'),
            'file_operations' => is_writable(sys_get_temp_dir()),

            // PHP extensions
            'sqlite3_extension' => extension_loaded('sqlite3'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),

            // External tools
            'mysqldump_available' => false,
            'pg_dump_available' => false,
            'gzip_available' => false,

            // System constraints
            'disabled_functions' => array_map('trim', explode(',', ini_get('disable_functions'))),
            'safe_mode' => ini_get('safe_mode'),
            'open_basedir' => ini_get('open_basedir'),
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit')
        ];

        // Check for external tools if shell access is available
        if ($capabilities['proc_open'] || $capabilities['shell_exec']) {
            $capabilities['mysqldump_available'] = $this->commandExists('mysqldump');
            $capabilities['pg_dump_available'] = $this->commandExists('pg_dump');
            $capabilities['gzip_available'] = $this->commandExists('gzip');
        }

        $this->debugLog("System capabilities detected", DebugLevel::VERBOSE, [
            'shell_access' => $capabilities['proc_open'] || $capabilities['shell_exec'],
            'external_tools' => [
                'mysqldump' => $capabilities['mysqldump_available'],
                'pg_dump' => $capabilities['pg_dump_available'],
                'gzip' => $capabilities['gzip_available']
            ],
            'php_extensions' => [
                'sqlite3' => $capabilities['sqlite3_extension'],
                'pdo_mysql' => $capabilities['pdo_mysql'],
                'pdo_pgsql' => $capabilities['pdo_pgsql']
            ]
        ]);

        return $capabilities;
    }

    /**
     * Check if external command exists and is executable
     * 
     * @param string $command Command name to check
     * @return bool True if command is available
     */
    private function commandExists(string $command): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        // Use 'which' on Unix-like systems, 'where' on Windows
        $checkCommand = DIRECTORY_SEPARATOR === '\\' ? "where $command" : "which $command";

        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($checkCommand, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        return $returnCode === 0;
    }

    /**
     * Select best backup strategy based on database type and capabilities with fallback support
     * 
     * @param array $options Backup options that may influence strategy selection
     * @return BackupStrategyInterface Selected backup strategy
     * @throws RuntimeException If no working strategies available
     */
    private function selectBestStrategy(array $options): BackupStrategyInterface
    {
        $strategies = $this->getAvailableStrategies();

        if (empty($strategies)) {
            throw new RuntimeException("No backup strategies available for database type: {$this->dbType}");
        }

        $this->debugLog("Testing strategies for best selection", DebugLevel::DETAILED, [
            'available_strategies' => count($strategies),
            'database_type' => $this->dbType,
            'options' => $options
        ]);

        // FIRST: Check for explicit strategy forcing/preference in options
        $preferredStrategy = $this->findPreferredStrategy($strategies, $options);
        if ($preferredStrategy !== null) {
            $this->debugLog("Using explicitly preferred strategy", DebugLevel::DETAILED, [
                'selected_strategy' => get_class($preferredStrategy),
                'strategy_type' => $preferredStrategy->getStrategyType(),
                'selection_reason' => 'explicitly_preferred'
            ]);
            return $preferredStrategy;
        }

        // SECOND: Test each strategy in priority order until we find one that works
        $lastError = '';
        foreach ($strategies as $index => $strategy) {
            try {
                $this->debugLog("Testing strategy capabilities", DebugLevel::DETAILED, [
                    'strategy_index' => $index + 1,
                    'strategy_class' => get_class($strategy),
                    'strategy_type' => $strategy->getStrategyType()
                ]);

                // Test if strategy can actually work
                $capabilities = $strategy->testCapabilities();

                if ($capabilities['overall_status'] === 'available') {
                    $this->debugLog("Strategy selected successfully", DebugLevel::DETAILED, [
                        'selected_strategy' => get_class($strategy),
                        'strategy_index' => $index + 1,
                        'total_tested' => $index + 1,
                        'selection_criteria' => $strategy->getSelectionCriteria(),
                        'selection_reason' => 'priority_order'
                    ]);

                    return $strategy;
                } else {
                    $error = $capabilities['test_error'] ?? $capabilities['overall_status'];
                    $lastError = $error;

                    $this->debugLog("Strategy failed capability test", DebugLevel::DETAILED, [
                        'strategy_class' => get_class($strategy),
                        'strategy_index' => $index + 1,
                        'error' => $error,
                        'will_try_next' => ($index + 1) < count($strategies)
                    ]);
                }
            } catch (Exception $e) {
                $lastError = $e->getMessage();

                $this->debugLog("Strategy threw exception during testing", DebugLevel::DETAILED, [
                    'strategy_class' => get_class($strategy),
                    'strategy_index' => $index + 1,
                    'exception' => $e->getMessage(),
                    'will_try_next' => ($index + 1) < count($strategies)
                ]);
            }
        }

        // If we get here, no strategies worked
        throw new RuntimeException("No working backup strategies found for database type: {$this->dbType}. Last error: $lastError");
    }

    /**
     * Find preferred strategy based on options
     * 
     * @param array $strategies Available strategies
     * @param array $options Backup options
     * @return BackupStrategyInterface|null Preferred strategy or null if none specified/found
     */
    private function findPreferredStrategy(array $strategies, array $options): ?BackupStrategyInterface
    {
        // Check for force_strategy option (highest priority)
        if (!empty($options['force_strategy'])) {
            $forcedStrategyName = $options['force_strategy'];

            foreach ($strategies as $strategy) {
                if ($strategy->getStrategyType() === $forcedStrategyName) {
                    $this->debugLog("Found preferred strategy by type", DebugLevel::DETAILED, [
                        'forced_strategy' => $forcedStrategyName,
                        'matched_class' => get_class($strategy),
                        'strategy_type' => $strategy->getStrategyType()
                    ]);

                    return $strategy;
                }
            }

            $this->debugLog("Forced strategy not found", DebugLevel::DETAILED, [
                'forced_strategy' => $forcedStrategyName,
                'available_strategies' => array_map(fn($s) => get_class($s), $strategies)
            ]);
        }

        // Check for preferred_strategy option
        if (!empty($options['preferred_strategy'])) {
            $preferredType = $options['preferred_strategy'];

            foreach ($strategies as $strategy) {
                if ($strategy->getStrategyType() === $preferredType) {
                    $this->debugLog("Found preferred strategy by type", DebugLevel::DETAILED, [
                        'preferred_type' => $preferredType,
                        'matched_class' => get_class($strategy)
                    ]);

                    return $strategy;
                }
            }

            $this->debugLog("Preferred strategy type not found", DebugLevel::DETAILED, [
                'preferred_type' => $preferredType,
                'available_types' => array_map(fn($s) => $s->getStrategyType(), $strategies)
            ]);
        }

        // Check for strategy_type option
        if (!empty($options['strategy_type'])) {
            $strategyType = $options['strategy_type'];

            foreach ($strategies as $strategy) {
                if ($strategy->getStrategyType() === $strategyType) {
                    $this->debugLog("Found strategy by strategy_type option", DebugLevel::DETAILED, [
                        'strategy_type' => $strategyType,
                        'matched_class' => get_class($strategy)
                    ]);

                    return $strategy;
                }
            }

            $this->debugLog("Strategy type not found", DebugLevel::DETAILED, [
                'strategy_type' => $strategyType,
                'available_types' => array_map(fn($s) => $s->getStrategyType(), $strategies)
            ]);
        }

        return null; // No preference found
    }

    /**
     * Get all available backup strategies for current database type
     * 
     * @return array Array of available backup strategy instances, ordered by preference
     */
    public function getAvailableStrategies(): array
    {
        return match ($this->dbType) {
            'mysql' => $this->getMySQLStrategies(),
            'sqlite' => $this->getSQLiteStrategies(),
            'postgresql' => $this->getPostgreSQLStrategies(),
            default => []
        };
    }

    /**
     * Get MySQL backup strategies in order of preference
     * 
     * @return array MySQL backup strategy instances
     */
    private function getMySQLStrategies(): array
    {
        $strategies = [];

        // 1. mysqldump via proc_open (preferred)
        if ($this->capabilities['proc_open'] && $this->capabilities['mysqldump_available']) {
            require_once dirname(__DIR__) . '/backup/strategies/MySQLShellBackupStrategy.php';
            $strategies[] = new MySQLShellBackupStrategy($this->model, $this->connectionConfig);
        }

        // 2. PHP-based SQL generation (fallback)
        require_once dirname(__DIR__) . '/backup/strategies/MySQLPHPBackupStrategy.php';
        $strategies[] = new MySQLPHPBackupStrategy($this->model, $this->connectionConfig);

        return $strategies;
    }

    /**
     * Get SQLite backup strategies in order of preference
     * 
     * Provides comprehensive SQLite backup options with optimal prioritization:
     * - Binary backups prioritized for performance and file size
     * - SQL dumps available for cross-database compatibility when needed
     * 
     * Strategy Priority Order for Backup Creation:
     * 1. Native SQLite3::backup() - Binary backup (fastest, most reliable)
     * 2. VACUUM INTO - Binary backup with compression (fast, smaller files)
     * 3. SQL Dump Strategy - Human-readable SQL (cross-database compatible, larger files)
     * 4. File Copy - Direct file copy (last resort, requires exclusive access)
     * 
     * For Restore Operations: Format detection automatically selects appropriate strategy
     * 
     * @return array SQLite backup strategy instances
     */
    private function getSQLiteStrategies(): array
    {
        $strategies = [];

        // 1. Native SQLite3::backup() API (preferred for SQLite-to-SQLite)
        if ($this->capabilities['sqlite3_extension']) {
            require_once dirname(__DIR__) . '/backup/strategies/SQLiteNativeBackupStrategy.php';
            $strategies[] = new SQLiteNativeBackupStrategy($this->model, $this->connectionConfig);
        }

        // 2. VACUUM INTO (preferred binary backup when native API unavailable)
        require_once dirname(__DIR__) . '/backup/strategies/SQLiteVacuumBackupStrategy.php';
        $strategies[] = new SQLiteVacuumBackupStrategy($this->model, $this->connectionConfig);

        // 3. SQL Dump Strategy (for cross-database compatibility and version control)
        require_once dirname(__DIR__) . '/backup/strategies/SQLiteSQLDumpStrategy.php';
        $strategies[] = new SQLiteSQLDumpStrategy($this->model, $this->connectionConfig);

        // 4. File copy (last resort)
        require_once dirname(__DIR__) . '/backup/strategies/SQLiteFileCopyBackupStrategy.php';
        $strategies[] = new SQLiteFileCopyBackupStrategy($this->model, $this->connectionConfig);

        return $strategies;
    }

    /**
     * Get PostgreSQL backup strategies in order of preference
     * 
     * @return array PostgreSQL backup strategy instances
     */
    private function getPostgreSQLStrategies(): array
    {
        $strategies = [];

        // 1. pg_dump via proc_open (preferred)
        if ($this->capabilities['proc_open'] && $this->capabilities['pg_dump_available']) {
            require_once dirname(__DIR__) . '/backup/strategies/PostgreSQLShellBackupStrategy.php';
            $strategies[] = new PostgreSQLShellBackupStrategy($this->model, $this->connectionConfig);
        }

        // 2. PHP-based SQL generation (fallback)
        require_once dirname(__DIR__) . '/backup/strategies/PostgreSQLPHPBackupStrategy.php';
        $strategies[] = new PostgreSQLPHPBackupStrategy($this->model, $this->connectionConfig);

        return $strategies;
    }

    /**
     * Get a specific backup strategy instance for testing or advanced usage
     * 
     * This method allows direct access to individual backup strategy instances,
     * enabling testing of strategy-specific methods like testCapabilities(),
     * validateBackupFile(), getSelectionCriteria(), etc.
     * 
     * @param string $strategyIdentifier Strategy identifier (e.g., 'sqlite_sql_dump', 'mysql_php')
     * @return BackupStrategyInterface|null Strategy instance or null if not found/available
     * @throws InvalidArgumentException If strategy identifier is invalid
     * 
     * @example
     * // Get SQLite SQL dump strategy for testing
     * $strategy = $model->backup()->getStrategy('sqlite_sql_dump');
     * $capabilities = $strategy->testCapabilities();
     * $validation = $strategy->validateBackupFile('/path/to/backup.sql');
     * 
     * @example  
     * // Get MySQL PHP strategy for advanced operations
     * $strategy = $model->backup()->getStrategy('mysql_php');
     * $description = $strategy->getDescription();
     * $criteria = $strategy->getSelectionCriteria();
     */
    public function getStrategy(string $strategyIdentifier): ?BackupStrategyInterface
    {
        // Validate strategy identifier
        if (empty($strategyIdentifier)) {
            throw new InvalidArgumentException("Strategy identifier cannot be empty");
        }

        // Normalize strategy identifier
        $strategyIdentifier = strtolower(trim($strategyIdentifier));

        // Check if strategy is already loaded
        if (isset($this->loadedStrategies[$strategyIdentifier])) {
            return $this->loadedStrategies[$strategyIdentifier];
        }

        // Map strategy identifiers to class names and file paths
        $strategyMap = $this->getStrategyMap();

        if (!isset($strategyMap[$strategyIdentifier])) {
            $this->debugLog("Unknown strategy identifier: $strategyIdentifier", DebugLevel::BASIC, [
                'available_strategies' => array_keys($strategyMap)
            ]);
            return null;
        }

        $strategyInfo = $strategyMap[$strategyIdentifier];

        try {
            // Load strategy file if not already loaded
            if (!class_exists($strategyInfo['class'], false)) {
                if (!file_exists($strategyInfo['file'])) {
                    $this->debugLog("Strategy file not found", DebugLevel::BASIC, [
                        'strategy' => $strategyIdentifier,
                        'file' => $strategyInfo['file']
                    ]);
                    return null;
                }

                require_once $strategyInfo['file'];

                if (!class_exists($strategyInfo['class'])) {
                    $this->debugLog("Strategy class not found after loading file", DebugLevel::BASIC, [
                        'strategy' => $strategyIdentifier,
                        'class' => $strategyInfo['class'],
                        'file' => $strategyInfo['file']
                    ]);
                    return null;
                }
            }

            // Instantiate strategy
            $strategy = new $strategyInfo['class']($this->model, $this->getConnectionConfig());

            // Test if strategy is available/working
            if (method_exists($strategy, 'testCapabilities')) {
                $capabilities = $strategy->testCapabilities();
                if (isset($capabilities['success']) && !$capabilities['success']) {
                    $this->debugLog("Strategy not available", DebugLevel::BASIC, [
                        'strategy' => $strategyIdentifier,
                        'reason' => $capabilities['error'] ?? 'Unknown'
                    ]);
                    return null;
                }
            }

            // Cache the loaded strategy
            $this->loadedStrategies[$strategyIdentifier] = $strategy;

            $this->debugLog("Strategy loaded successfully", DebugLevel::VERBOSE, [
                'strategy' => $strategyIdentifier,
                'class' => $strategyInfo['class']
            ]);

            return $strategy;
        } catch (Exception $e) {
            $this->debugLog("Failed to load strategy", DebugLevel::BASIC, [
                'strategy' => $strategyIdentifier,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get available strategy identifiers
     * 
     * Returns a list of all strategy identifiers that can be used with getStrategy().
     * 
     * @return array Array of strategy identifiers
     */
    public function getAvailableStrategyIdentifiers(): array
    {
        return array_keys($this->getStrategyMap());
    }

    /**
     * Get strategy map with identifiers, class names, and file paths
     * 
     * @return array Strategy mapping configuration
     */
    private function getStrategyMap(): array
    {
        // Define the base path for strategies
        $basePath = dirname(__DIR__) . '/backup/strategies/';

        return [
            // MySQL Strategies
            'mysql_shell' => [
                'class' => 'MySQLShellBackupStrategy',
                'file' => $basePath . 'MySQLShellBackupStrategy.php',
                'description' => 'MySQL Shell (mysqldump)',
                'database_type' => 'mysql'
            ],
            'mysql_php' => [
                'class' => 'MySQLPHPBackupStrategy',
                'file' => $basePath . 'MySQLPHPBackupStrategy.php',
                'description' => 'MySQL PHP (Pure PHP)',
                'database_type' => 'mysql'
            ],

            // PostgreSQL Strategies
            'postgresql_shell' => [
                'class' => 'PostgreSQLShellBackupStrategy',
                'file' => $basePath . 'PostgreSQLShellBackupStrategy.php',
                'description' => 'PostgreSQL Shell (pg_dump)',
                'database_type' => 'postgresql'
            ],
            'postgresql_php' => [
                'class' => 'PostgreSQLPHPBackupStrategy',
                'file' => $basePath . 'PostgreSQLPHPBackupStrategy.php',
                'description' => 'PostgreSQL PHP (Pure PHP)',
                'database_type' => 'postgresql'
            ],

            // SQLite Strategies
            'sqlite_native' => [
                'class' => 'SQLiteNativeBackupStrategy',
                'file' => $basePath . 'SQLiteNativeBackupStrategy.php',
                'description' => 'SQLite Native (SQLite3::backup())',
                'database_type' => 'sqlite'
            ],
            'sqlite_vacuum' => [
                'class' => 'SQLiteVacuumBackupStrategy',
                'file' => $basePath . 'SQLiteVacuumBackupStrategy.php',
                'description' => 'SQLite Vacuum (VACUUM INTO)',
                'database_type' => 'sqlite'
            ],
            'sqlite_sql_dump' => [
                'class' => 'SQLiteSQLDumpStrategy',
                'file' => $basePath . 'SQLiteSQLDumpStrategy.php',
                'description' => 'SQLite SQL Dump (Human-readable SQL)',
                'database_type' => 'sqlite'
            ],
            'sqlite_file_copy' => [
                'class' => 'SQLiteFileCopyBackupStrategy',
                'file' => $basePath . 'SQLiteFileCopyBackupStrategy.php',
                'description' => 'SQLite File Copy (Direct file copy)',
                'database_type' => 'sqlite'
            ]
        ];
    }

    /**
     * Get connection configuration for strategy instantiation
     * 
     * @return array Database connection configuration
     */
    private function getConnectionConfig(): array
    {
        try {
            // Get the Model's DatabaseConfig instance
            $databaseConfig = $this->model->getConfig();

            // Build connection configuration array from DatabaseConfig
            $config = [
                'type' => $databaseConfig->getType(),
                'pdo' => $this->model->getPDO() // Always include PDO instance
            ];

            // Add database-specific configuration
            switch ($databaseConfig->getType()) {
                case 'mysql':
                    $config = array_merge($config, [
                        'host' => $databaseConfig->getHost(),
                        'port' => $databaseConfig->getPort(),
                        'user' => $databaseConfig->getUser(),
                        'password' => $databaseConfig->getPass(),
                        'database' => $databaseConfig->getDbname(),
                        'charset' => $databaseConfig->getCharset()
                    ]);
                    break;

                case 'postgresql':
                    $config = array_merge($config, [
                        'host' => $databaseConfig->getHost(),
                        'port' => $databaseConfig->getPort(),
                        'user' => $databaseConfig->getUser(),
                        'password' => $databaseConfig->getPass(),
                        'database' => $databaseConfig->getDbname()
                    ]);
                    break;

                case 'sqlite':
                    $config = array_merge($config, [
                        'database' => $databaseConfig->getDbfile(),
                        'dbfile' => $databaseConfig->getDbfile()
                    ]);
                    break;
            }

            return $config;
        } catch (Exception $e) {
            $this->debugLog("Failed to get connection config from DatabaseConfig", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);

            // Fallback configuration with just PDO
            return [
                'type' => 'unknown',
                'pdo' => $this->model->getPDO()
            ];
        }
    }



    /**
     * Select restore strategy based on backup file format and database type
     * 
     * Intelligently selects the most appropriate restore strategy by analyzing
     * the backup file format and matching it to compatible strategies.
     * 
     * @param string $backupPath Path to backup file
     * @param array $options Restore options
     * @return RestoreStrategyInterface Selected restore strategy
     * @throws RuntimeException If no compatible strategies available
     */
    private function selectRestoreStrategy(string $backupPath, array $options): RestoreStrategyInterface
    {
        $strategies = $this->getAvailableStrategies();

        if (empty($strategies)) {
            throw new RuntimeException("No restore strategies available for database type: {$this->dbType}");
        }

        $this->debugLog("Starting intelligent restore strategy selection", DebugLevel::DETAILED, [
            'backup_path' => $backupPath,
            'backup_size_bytes' => filesize($backupPath),
            'available_strategies' => count($strategies),
            'database_type' => $this->dbType
        ]);

        // Check for explicit strategy preference in options
        if (isset($options['preferred_strategy'])) {
            $preferredType = $options['preferred_strategy'];
            foreach ($strategies as $strategy) {
                if ($strategy->getStrategyType() === $preferredType) {
                    $this->debugLog("Using explicitly preferred strategy", DebugLevel::DETAILED, [
                        'preferred_strategy' => $preferredType,
                        'selected_strategy' => get_class($strategy)
                    ]);
                    return $strategy;
                }
            }
        }

        // Try to detect the backup format and find a matching strategy
        $bestStrategy = null;
        $formatMatches = [];
        $lastError = '';

        foreach ($strategies as $index => $strategy) {
            try {
                $this->debugLog("Testing strategy format compatibility", DebugLevel::DETAILED, [
                    'strategy_index' => $index + 1,
                    'strategy_class' => get_class($strategy),
                    'strategy_type' => $strategy->getStrategyType()
                ]);

                // Check if strategy can detect/handle this backup format
                $detectedFormat = $strategy->detectBackupFormat($backupPath);

                $this->debugLog("Format detection result", DebugLevel::VERBOSE, [
                    'strategy_type' => $strategy->getStrategyType(),
                    'detected_format' => $detectedFormat
                ]);

                // If strategy recognizes the format, it's a good candidate
                if ($detectedFormat !== 'unknown') {
                    $confidence = $this->calculateFormatConfidence($strategy, $detectedFormat, $backupPath);

                    $formatMatches[] = [
                        'strategy' => $strategy,
                        'format' => $detectedFormat,
                        'confidence' => $confidence
                    ];

                    $this->debugLog("Strategy format match found", DebugLevel::DETAILED, [
                        'strategy_type' => $strategy->getStrategyType(),
                        'detected_format' => $detectedFormat,
                        'confidence' => $confidence
                    ]);
                }
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                $this->debugLog("Strategy format detection failed", DebugLevel::DETAILED, [
                    'strategy_type' => $strategy->getStrategyType(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        // If we found format matches, select the best one
        if (!empty($formatMatches)) {
            // Sort by confidence (highest first)
            usort($formatMatches, function ($a, $b) {
                return $b['confidence'] <=> $a['confidence'];
            });

            $bestMatch = $formatMatches[0];
            $bestStrategy = $bestMatch['strategy'];

            $this->debugLog("Format-based strategy selection successful", DebugLevel::DETAILED, [
                'selected_strategy' => get_class($bestStrategy),
                'detected_format' => $bestMatch['format'],
                'confidence' => $bestMatch['confidence'],
                'total_matches' => count($formatMatches)
            ]);
        }

        // If no format matches found, fall back to testing each strategy's capabilities
        if ($bestStrategy === null) {
            $this->debugLog("No format matches found, testing strategy capabilities", DebugLevel::DETAILED, [
                'backup_path' => $backupPath
            ]);

            foreach ($strategies as $index => $strategy) {
                try {
                    // Test if strategy can validate this backup file
                    if ($strategy instanceof RestoreStrategyInterface) {
                        $validation = $strategy->validateBackupFile($backupPath);

                        if ($validation['valid'] ?? false) {
                            $bestStrategy = $strategy;

                            $this->debugLog("Capability-based strategy selection successful", DebugLevel::DETAILED, [
                                'selected_strategy' => get_class($bestStrategy),
                                'validation_passed' => true,
                                'strategy_index' => $index + 1
                            ]);
                            break;
                        } else {
                            $this->debugLog("Strategy validation failed", DebugLevel::VERBOSE, [
                                'strategy_type' => $strategy->getStrategyType(),
                                'validation_error' => $validation['error'] ?? 'Unknown validation error'
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    $lastError = $e->getMessage();
                    $this->debugLog("Strategy capability test failed", DebugLevel::DETAILED, [
                        'strategy_type' => $strategy->getStrategyType(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // If still no strategy found, use the first available as last resort
        if ($bestStrategy === null) {
            $bestStrategy = $strategies[0];

            $this->debugLog("Using first available strategy as fallback", DebugLevel::DETAILED, [
                'selected_strategy' => get_class($bestStrategy),
                'reason' => 'No format or capability matches found',
                'last_error' => $lastError
            ]);
        }

        $this->debugLog("Restore strategy selection completed", DebugLevel::BASIC, [
            'selected_strategy' => get_class($bestStrategy),
            'strategy_type' => $bestStrategy->getStrategyType()
        ]);

        return $bestStrategy;
    }

    /**
     * Calculate confidence score for format detection
     * 
     * @param BackupStrategyInterface $strategy Strategy that detected the format
     * @param string $detectedFormat Detected format
     * @param string $backupPath Path to backup file for additional analysis
     * @return float Confidence score (0.0 to 1.0)
     */
    private function calculateFormatConfidence(BackupStrategyInterface $strategy, string $detectedFormat, string $backupPath): float
    {
        $strategyType = $strategy->getStrategyType();
        $fileExtension = strtolower(pathinfo($backupPath, PATHINFO_EXTENSION));

        // Very high confidence if strategy type exactly matches detected format
        if ($detectedFormat === $strategyType) {
            return 1.0;
        }

        // High confidence for specific format matches
        if (stripos($detectedFormat, 'sql_dump') !== false && stripos($strategyType, 'sql_dump') !== false) {
            return 0.9;
        }

        if (stripos($detectedFormat, 'native') !== false && stripos($strategyType, 'native') !== false) {
            return 0.9;
        }

        if (stripos($detectedFormat, 'vacuum') !== false && stripos($strategyType, 'vacuum') !== false) {
            return 0.9;
        }

        // Medium-high confidence for SQL formats with SQL strategies
        if (stripos($detectedFormat, 'sql') !== false && stripos($strategyType, 'sql') !== false) {
            return 0.8;
        }

        // Medium confidence for file extension matches
        if ($fileExtension === 'sql' && stripos($strategyType, 'sql') !== false) {
            return 0.7;
        }

        if ($fileExtension === 'db' && (stripos($strategyType, 'native') !== false ||
            stripos($strategyType, 'vacuum') !== false ||
            stripos($strategyType, 'file_copy') !== false)) {
            return 0.7;
        }

        // Lower confidence for generic matches
        if ($detectedFormat !== 'unknown') {
            return 0.5;
        }

        return 0.0;
    }

    // =============================================================================
    // STRATEGY SELECTION FOR VALIDATION AND ANALYSIS
    // =============================================================================

    /**
     * Select appropriate strategy for backup file validation with SQLite format detection
     * 
     * For SQLite databases, determines whether backup file is binary SQLite or SQL dump
     * and forces appropriate strategy for validation.
     * 
     * @param string $backupPath Path to backup file
     * @return RestoreStrategyInterface Strategy capable of validation
     * @throws RuntimeException If no suitable validation strategy available
     */
    private function selectValidationStrategy(string $backupPath): RestoreStrategyInterface
    {
        $strategies = $this->getAvailableStrategies();

        if (empty($strategies)) {
            throw new RuntimeException("No validation strategies available for database type: {$this->dbType}");
        }

        $this->debugLog("Starting validation strategy selection", DebugLevel::DETAILED, [
            'database_type' => $this->dbType,
            'backup_path' => $backupPath,
            'file_size' => file_exists($backupPath) ? filesize($backupPath) : 0,
            'available_strategies' => count($strategies)
        ]);

        // For SQLite, we need to detect file format and force appropriate strategy
        if ($this->dbType === 'sqlite') {
            return $this->selectSQLiteValidationStrategy($backupPath, $strategies);
        }

        // For other database types, use first available strategy with validation capability
        foreach ($strategies as $strategy) {
            if ($strategy instanceof RestoreStrategyInterface) {
                $this->debugLog("Validation strategy selected", DebugLevel::DETAILED, [
                    'selected_strategy' => get_class($strategy),
                    'strategy_type' => $strategy->getStrategyType()
                ]);
                return $strategy;
            }
        }

        throw new RuntimeException("No strategies with validation capabilities available for database type: {$this->dbType}");
    }

    /**
     * Select SQLite validation strategy based on file format detection
     * 
     * Detects whether backup file is binary SQLite database or SQL dump text file
     * and returns appropriate validation strategy.
     * 
     * @param string $backupPath Path to SQLite backup file
     * @param array $strategies Available backup strategies
     * @return RestoreStrategyInterface Appropriate validation strategy
     * @throws RuntimeException If file format cannot be determined or no suitable strategy found
     */
    private function selectSQLiteValidationStrategy(string $backupPath, array $strategies): RestoreStrategyInterface
    {
        // Detect file format
        $fileFormat = $this->detectSQLiteFileFormat($backupPath);

        $this->debugLog("SQLite file format detected", DebugLevel::DETAILED, [
            'backup_path' => $backupPath,
            'detected_format' => $fileFormat,
            'file_extension' => pathinfo($backupPath, PATHINFO_EXTENSION)
        ]);

        // Find appropriate strategy based on format
        $selectedStrategy = null;
        $preferredStrategyTypes = [];

        switch ($fileFormat) {
            case 'binary_sqlite':
                // Binary SQLite file - prefer native, vacuum, or file copy strategies
                $preferredStrategyTypes = ['native', 'vacuum', 'file_copy'];
                break;

            case 'sql_dump':
                // SQL dump file - force SQL dump strategy
                $preferredStrategyTypes = ['sql_dump'];
                break;

            case 'unknown':
            default:
                // Unknown format - try SQL dump first (more forgiving), then binary
                $preferredStrategyTypes = ['sql_dump', 'native', 'vacuum', 'file_copy'];
                $this->debugLog("Unknown SQLite file format, trying fallback strategies", DebugLevel::BASIC, [
                    'backup_path' => $backupPath,
                    'fallback_order' => $preferredStrategyTypes
                ]);
                break;
        }

        // Find strategy matching preferred types
        foreach ($preferredStrategyTypes as $preferredType) {
            foreach ($strategies as $strategy) {
                if ($strategy instanceof RestoreStrategyInterface) {
                    $strategyType = strtolower($strategy->getStrategyType());

                    if (strpos($strategyType, $preferredType) !== false) {
                        $selectedStrategy = $strategy;
                        break 2;
                    }
                }
            }
        }

        // Fallback to first available strategy if no preferred match
        if ($selectedStrategy === null) {
            foreach ($strategies as $strategy) {
                if ($strategy instanceof RestoreStrategyInterface) {
                    $selectedStrategy = $strategy;
                    break;
                }
            }
        }

        if ($selectedStrategy === null) {
            throw new RuntimeException("No SQLite validation strategies available");
        }

        $this->debugLog("SQLite validation strategy selected", DebugLevel::BASIC, [
            'selected_strategy' => get_class($selectedStrategy),
            'strategy_type' => $selectedStrategy->getStrategyType(),
            'file_format' => $fileFormat,
            'selection_reason' => $fileFormat === 'unknown' ? 'fallback' : 'format_match'
        ]);

        return $selectedStrategy;
    }

    /**
     * Detect SQLite backup file format
     * 
     * Determines whether file is binary SQLite database or SQL dump text file
     * by examining file headers and content patterns.
     * 
     * @param string $backupPath Path to backup file
     * @return string Format: 'binary_sqlite', 'sql_dump', or 'unknown'
     */
    private function detectSQLiteFileFormat(string $backupPath): string
    {
        if (!file_exists($backupPath) || !is_readable($backupPath)) {
            return 'unknown';
        }

        try {
            // Read first 100 bytes to check file header
            $handle = fopen($backupPath, 'rb');
            if (!$handle) {
                return 'unknown';
            }

            $header = fread($handle, 100);
            fclose($handle);

            // Check for SQLite database file signature
            if (substr($header, 0, 16) === 'SQLite format 3' . "\000") {
                $this->debugLog("Binary SQLite database detected", DebugLevel::VERBOSE, [
                    'detection_method' => 'sqlite_header_signature',
                    'header_bytes' => bin2hex(substr($header, 0, 16))
                ]);
                return 'binary_sqlite';
            }

            // Check for SQL dump patterns in first few hundred bytes
            $handle = fopen($backupPath, 'r');
            if (!$handle) {
                return 'unknown';
            }

            $content = '';
            for ($i = 0; $i < 10 && !feof($handle); $i++) {
                $content .= fgets($handle);
            }
            fclose($handle);

            // Convert to lowercase for pattern matching
            $contentLower = strtolower($content);

            // Look for SQL dump indicators
            $sqlIndicators = [
                'create table',
                'insert into',
                'pragma',
                'begin transaction',
                'sqlite database backup',
                'drop table',
                '-- sqli'  // Common SQL comment prefix
            ];

            foreach ($sqlIndicators as $indicator) {
                if (strpos($contentLower, $indicator) !== false) {
                    $this->debugLog("SQL dump file detected", DebugLevel::VERBOSE, [
                        'detection_method' => 'sql_pattern_match',
                        'matched_pattern' => $indicator
                    ]);
                    return 'sql_dump';
                }
            }

            // Check file extension as secondary indicator
            $extension = strtolower(pathinfo($backupPath, PATHINFO_EXTENSION));
            if ($extension === 'sql') {
                $this->debugLog("SQL dump inferred from file extension", DebugLevel::VERBOSE, [
                    'detection_method' => 'file_extension',
                    'extension' => $extension
                ]);
                return 'sql_dump';
            }

            if (in_array($extension, ['db', 'sqlite', 'sqlite3', 'database'])) {
                $this->debugLog("Binary SQLite inferred from file extension", DebugLevel::VERBOSE, [
                    'detection_method' => 'file_extension',
                    'extension' => $extension
                ]);
                return 'binary_sqlite';
            }

            // Final check: try to detect if file is mostly text or binary
            $handle = fopen($backupPath, 'rb');
            if ($handle) {
                $sample = fread($handle, 1024);
                fclose($handle);

                $binaryBytes = 0;
                for ($i = 0; $i < strlen($sample); $i++) {
                    $byte = ord($sample[$i]);
                    // Count non-printable characters (excluding common whitespace)
                    if ($byte < 32 && !in_array($byte, [9, 10, 13])) { // tab, LF, CR
                        $binaryBytes++;
                    }
                }

                // If more than 10% non-printable bytes, likely binary
                $binaryRatio = $binaryBytes / strlen($sample);
                if ($binaryRatio > 0.1) {
                    $this->debugLog("Binary file detected by byte analysis", DebugLevel::VERBOSE, [
                        'detection_method' => 'binary_byte_analysis',
                        'binary_ratio' => round($binaryRatio, 3),
                        'sample_size' => strlen($sample)
                    ]);
                    return 'binary_sqlite';
                }
            }

            $this->debugLog("File format could not be determined", DebugLevel::BASIC, [
                'backup_path' => $backupPath,
                'file_size' => filesize($backupPath),
                'extension' => $extension ?? 'none'
            ]);

            return 'unknown';
        } catch (Exception $e) {
            $this->debugLog("Error during file format detection", DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'backup_path' => $backupPath
            ]);
            return 'unknown';
        }
    }

    /**
     * Select appropriate strategy for restore options analysis
     * 
     * Chooses the best strategy for analyzing restore options from a backup file.
     * Considers file format, database compatibility, and strategy capabilities.
     * 
     * @param string $backupPath Path to backup file
     * @return RestoreStrategyInterface Strategy capable of restore analysis
     * @throws RuntimeException If no suitable analysis strategy available
     */
    private function selectRestoreOptionsStrategy(string $backupPath): RestoreStrategyInterface
    {
        $strategies = $this->getAvailableStrategies();

        if (empty($strategies)) {
            throw new RuntimeException("No restore analysis strategies available for database type: {$this->dbType}");
        }

        // For restore options analysis, we can use the same logic as validation
        // but we might want to prefer strategies that can provide more detailed analysis
        $selectedStrategy = null;

        foreach ($strategies as $strategy) {
            if ($strategy instanceof RestoreStrategyInterface) {
                $selectedStrategy = $strategy;
                break;
            }
        }

        if ($selectedStrategy === null) {
            throw new RuntimeException("No strategies with restore analysis capabilities available for database type: {$this->dbType}");
        }

        $this->debugLog("Restore options strategy selection completed", DebugLevel::DETAILED, [
            'selected_strategy' => get_class($selectedStrategy),
            'total_available' => count($strategies),
            'backup_file_size' => filesize($backupPath)
        ]);

        return $selectedStrategy;
    }

    // =============================================================================
    // CONFIGURATION AND VALIDATION
    // =============================================================================

    /**
     * Extract database connection configuration from Model's DatabaseConfig
     * 
     * Leverages the Enhanced Model's existing DatabaseConfig class to get
     * accurate connection details instead of parsing DSN or using defaults.
     * 
     * @return array Database connection configuration
     */
    private function extractConnectionConfig(): array
    {
        // Use Model's existing DatabaseConfig for accurate connection details
        $config = $this->model->getConfig();

        // Build configuration array based on database type
        $connectionConfig = [
            'type' => $config->getType(),
        ];

        // Add type-specific configuration
        switch ($config->getType()) {
            case 'mysql':
                $connectionConfig = array_merge($connectionConfig, [
                    'host' => $config->getHost() ?? 'localhost',
                    'port' => (int)($config->getPort() ?? 3306),
                    'database' => $config->getDbname() ?? '',
                    'user' => $config->getUser() ?? '',
                    'password' => $config->getPass() ?? '',
                    'charset' => $config->getCharset() ?? 'utf8mb4'
                ]);
                break;

            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                $connectionConfig = array_merge($connectionConfig, [
                    'host' => $config->getHost() ?? 'localhost',
                    'port' => (int)($config->getPort() ?? 5432),
                    'database' => $config->getDbname() ?? '',
                    'user' => $config->getUser() ?? '',
                    'password' => $config->getPass() ?? ''
                ]);
                break;

            case 'sqlite':
                $connectionConfig = array_merge($connectionConfig, [
                    'path' => $config->getDbfile() ?? '',
                    'database' => $config->getDbfile() ?? '' // For compatibility
                ]);
                break;

            default:
                // Fallback to basic configuration
                $connectionConfig = array_merge($connectionConfig, [
                    'host' => 'localhost',
                    'database' => 'database',
                    'user' => 'user',
                    'password' => 'password'
                ]);
                break;
        }

        $this->debugLog("Connection configuration extracted from DatabaseConfig", DebugLevel::VERBOSE, [
            'database_type' => $connectionConfig['type'],
            'connection_string' => $config->getConnectionString(), // Safe connection string without credentials
            'config_complete' => $config->validate()
        ]);

        return $connectionConfig;
    }

    /**
     * Normalize and validate backup options
     * 
     * @param array $options Raw options array
     * @return array Normalized options with defaults
     */
    private function normalizeOptions(array $options): array
    {
        $defaults = [
            'include_schema' => true,
            'include_data' => true,
            'compress' => false,
            'timeout' => 1800, // 30 minutes
            'chunk_size' => 1000,
            'memory_limit' => null,
            'progress_callback' => null
        ];

        $normalized = array_merge($defaults, $options);

        // Validate timeout
        if ($normalized['timeout'] < 30) {
            $normalized['timeout'] = 30; // Minimum 30 seconds
        }

        // Validate chunk size
        if ($normalized['chunk_size'] < 100) {
            $normalized['chunk_size'] = 100; // Minimum 100 records
        }

        $this->debugLog("Backup options normalized", DebugLevel::VERBOSE, [
            'original_options' => $options,
            'normalized_options' => $normalized
        ]);

        return $normalized;
    }



    // =============================================================================
    // PUBLIC UTILITY METHODS
    // =============================================================================

    /**
     * Get system backup capabilities analysis
     * 
     * @return array Detailed capability analysis for troubleshooting
     */
    public function getCapabilities(): array
    {
        return [
            'database_type' => $this->dbType,
            'capabilities' => $this->capabilities,
            'available_strategies' => array_map(
                fn($strategy) => [
                    'class' => get_class($strategy),
                    'type' => $strategy->getStrategyType(),
                    'description' => $strategy->getDescription()
                ],
                $this->getAvailableStrategies()
            )
        ];
    }

    /**
     * Test backup capabilities without creating actual backup
     * 
     * @return array Test results for each available strategy
     */
    public function testCapabilities(): array
    {
        $this->debugLog("Testing backup capabilities", DebugLevel::BASIC);

        $results = [];
        $strategies = $this->getAvailableStrategies();

        foreach ($strategies as $strategy) {
            $strategyClass = get_class($strategy);

            try {
                $testResult = $strategy->testCapabilities();
                $results[$strategyClass] = [
                    'success' => true,
                    'details' => $testResult
                ];

                $this->debugLog("Strategy test successful", DebugLevel::DETAILED, [
                    'strategy' => $strategyClass,
                    'test_result' => $testResult
                ]);
            } catch (Exception $e) {
                $results[$strategyClass] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];

                $this->debugLog("Strategy test failed", DebugLevel::DETAILED, [
                    'strategy' => $strategyClass,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Estimate backup size and duration
     * 
     * @param array $options Backup options
     * @return array Size and time estimates
     */
    public function estimateBackup(array $options = []): array
    {
        $this->debugLog("Estimating backup requirements", DebugLevel::DETAILED);

        try {
            $strategy = $this->selectBestStrategy($this->normalizeOptions($options));

            $estimate = [
                'estimated_size_bytes' => $strategy->estimateBackupSize(),
                'estimated_duration_seconds' => $strategy->estimateBackupTime(),
                'strategy_to_be_used' => get_class($strategy),
                'compression_available' => $strategy->supportsCompression()
            ];

            $this->debugLog("Backup estimation completed", DebugLevel::DETAILED, $estimate);

            return $estimate;
        } catch (Exception $e) {
            $this->debugLog("Backup estimation failed", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);

            return [
                'error' => $e->getMessage(),
                'estimated_size_bytes' => 0,
                'estimated_duration_seconds' => 0
            ];
        }
    }
}
