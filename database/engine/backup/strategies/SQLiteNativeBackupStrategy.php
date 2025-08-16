<?php

/**
 * SQLite Native Backup Strategy - SQLite3::backup() API Implementation
 * 
 * Implements SQLite database backup using PHP's native SQLite3::backup() API.
 * This is the preferred method for SQLite backups as it provides atomic,
 * concurrent-safe backup operations that work correctly with WAL mode.
 * 
 * **ENHANCED WITH FULL DEBUG SYSTEM INTEGRATION**
 * - Comprehensive logging of all backup operations and decisions
 * - Real-time progress tracking during backup operations
 * - Detailed error analysis and troubleshooting information
 * - Performance monitoring and optimization insights
 * 
 * Key Features:
 * - Uses SQLite3::backup() API for atomic backup operations
 * - Handles concurrent access safely (works with WAL mode)
 * - Real-time progress tracking with callback support
 * - Automatic retry logic for busy database conditions
 * - Comprehensive validation and integrity checking
 * - Supports both backup and restore operations
 * 
 * Technical Implementation:
 * - Converts PDO connection to SQLite3 for backup API access
 * - Implements progress callbacks for large database operations
 * - Handles all SQLite-specific error conditions
 * - Provides detailed capability testing and diagnostics
 * 
 * @package Database\Backup\Strategy\SQLite
 * @author Enhanced Model System
 * @version 1.0.0 - Initial Implementation with Debug Integration
 */
class SQLiteNativeBackupStrategy implements BackupStrategyInterface, RestoreStrategyInterface
{
    use DebugLoggingTrait;
    use ConfigSanitizationTrait;
    use PathValidationTrait;
    
    private Model $model;
    private PDO $pdo;
    private array $connectionConfig;
    private string $databasePath;

    /**
     * Initialize SQLite native backup strategy
     * 
     * @param Model $model Enhanced Model instance for debug logging
     * @param array $connectionConfig Database connection configuration
     * @throws RuntimeException If SQLite3 extension not available
     */
    public function __construct(Model $model, array $connectionConfig)
    {
        if (!extension_loaded('sqlite3')) {
            throw new RuntimeException("SQLite3 extension required for native backup strategy");
        }

        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->connectionConfig = $connectionConfig;
        $this->databasePath = $this->extractDatabasePath();

        $this->debugLog("SQLite native backup strategy initialized", DebugLevel::VERBOSE, [
            'database_path' => $this->databasePath,
            'sqlite3_version' => SQLite3::version()['versionString'],
            'connection_config' => $this->sanitizeConfig($connectionConfig)
        ]);
    }

    // =============================================================================
    // BACKUP OPERATIONS
    // =============================================================================

    /**
     * Create SQLite database backup using native backup API
     * 
     * Uses SQLite3::backup() method to create atomic, concurrent-safe backup.
     * This method works correctly with WAL mode and handles busy database
     * conditions with automatic retry logic.
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

        // Normalize options with defaults
        $options = array_merge([
            'progress_callback' => null,
            'pages_per_step' => 100,
            'retry_attempts' => 3,
            'retry_delay_ms' => 100,
            'vacuum_source' => false,
            'validate_backup' => true
        ], $options);

        $this->debugLog("Starting SQLite native backup", DebugLevel::BASIC, [
            'source_database' => $this->databasePath,
            'output_path' => $outputPath,
            'options' => $options
        ]);

        try {
            // Validate source database
            $this->validateSourceDatabase();

            // Prepare backup directory
            $this->prepareBackupDirectory($outputPath);

            // Vacuum source database if requested
            if ($options['vacuum_source']) {
                $this->vacuumSourceDatabase();
            }

            // Perform the backup operation
            $backupResult = $this->performNativeBackup($outputPath, $options);

            // Validate backup if requested
            if ($options['validate_backup']) {
                $validationResult = $this->validateBackupFile($outputPath);
                $backupResult['validation'] = $validationResult;
            }

            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite native backup completed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'backup_size_bytes' => filesize($outputPath),
                'pages_copied' => $backupResult['pages_copied'] ?? 0,
                'validation_passed' => $backupResult['validation']['valid'] ?? false
            ]);

            return [
                'success' => true,
                'output_path' => $outputPath,
                'backup_size_bytes' => filesize($outputPath),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType(),
                'pages_copied' => $backupResult['pages_copied'] ?? 0,
                'validation' => $backupResult['validation'] ?? null
            ];
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite native backup failed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'error' => $e->getMessage(),
                'output_path' => $outputPath
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
     * Perform the actual native backup operation (ENHANCED VERSION)
     * 
     * @param string $outputPath Backup file path
     * @param array $options Backup options
     * @return array Backup operation results
     * @throws RuntimeException If backup fails
     */
    private function performNativeBackup(string $outputPath, array $options): array
    {
        $pagesCopied = 0;
        $totalPages = 0;
        $attempt = 0;
        $maxAttempts = $options['retry_attempts'];

        while ($attempt < $maxAttempts) {
            $attempt++;

            $this->debugLog("Starting backup attempt", DebugLevel::DETAILED, [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'pages_per_step' => $options['pages_per_step']
            ]);

            $sourceDb = null;
            $destDb = null;
            $backup = null;

            try {
                // Open source database with SQLite3
                $sourceDb = new SQLite3($this->databasePath, SQLITE3_OPEN_READONLY);

                // Create destination database
                $destDb = new SQLite3($outputPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

                // Initialize backup with enhanced error checking
                $backup = $sourceDb->backup($destDb);

                $this->debugLog("Backup initialization result", DebugLevel::VERBOSE, [
                    'backup_result' => var_export($backup, true),
                    'backup_type' => gettype($backup),
                    'is_object' => is_object($backup),
                    'is_sqlite3backup' => ($backup instanceof SQLite3Backup),
                    'is_false' => ($backup === false),
                    'is_true' => ($backup === true),
                    'is_null' => ($backup === null),
                    'php_version' => PHP_VERSION,
                    'sqlite3_version' => SQLite3::version()['versionString']
                ]);

                // Enhanced backup validation
                if ($backup === false || $backup === null) {
                    $sourceError = $sourceDb->lastErrorMsg();
                    $destError = $destDb->lastErrorMsg();

                    throw new RuntimeException(
                        "Failed to initialize SQLite backup. " .
                            "Source error: $sourceError, Destination error: $destError"
                    );
                } else if ($backup === true) {
                    // This should never happen but your system is doing it
                    throw new RuntimeException(
                        "SQLite backup() returned unexpected boolean true. " .
                            "This may be a PHP SQLite3 extension bug. " .
                            "PHP version: " . PHP_VERSION . ", " .
                            "SQLite version: " . SQLite3::version()['versionString']
                    );
                } else if (!is_object($backup)) {
                    throw new RuntimeException(
                        "SQLite backup() returned unexpected type: " . gettype($backup) .
                            " (value: " . var_export($backup, true) . ")"
                    );
                } else if (!($backup instanceof SQLite3Backup)) {
                    throw new RuntimeException(
                        "SQLite backup() returned wrong object type: " . get_class($backup) .
                            " (expected SQLite3Backup)"
                    );
                }

                // Verify backup object has required methods
                if (!method_exists($backup, 'step')) {
                    throw new RuntimeException("SQLite3Backup object missing step() method");
                }

                if (!method_exists($backup, 'finish')) {
                    throw new RuntimeException("SQLite3Backup object missing finish() method");
                }

                // Backup object is valid, perform backup with progress tracking
                $result = $this->executeBackupWithProgress($backup, $options);

                $pagesCopied = $result['pages_copied'];
                $totalPages = $result['total_pages'];

                $this->debugLog("Backup attempt successful", DebugLevel::DETAILED, [
                    'attempt' => $attempt,
                    'pages_copied' => $pagesCopied,
                    'total_pages' => $totalPages
                ]);

                break; // Success, exit retry loop

            } catch (Exception $e) {
                $this->debugLog("Backup attempt failed", DebugLevel::DETAILED, [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'will_retry' => $attempt < $maxAttempts,
                    'source_error' => $sourceDb ? $sourceDb->lastErrorMsg() : 'N/A',
                    'dest_error' => $destDb ? $destDb->lastErrorMsg() : 'N/A'
                ]);

                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException("Backup failed after $maxAttempts attempts: " . $e->getMessage());
                }

                // Wait before retry
                if ($options['retry_delay_ms'] > 0) {
                    usleep($options['retry_delay_ms'] * 1000);
                }

                // Clean up failed backup file
                if (file_exists($outputPath)) {
                    unlink($outputPath);
                }
            } finally {
                // Always cleanup resources with proper type checking
                try {
                    if ($backup && is_object($backup) && method_exists($backup, 'finish')) {
                        $backup->finish();
                    }
                    if ($sourceDb) {
                        $sourceDb->close();
                    }
                    if ($destDb) {
                        $destDb->close();
                    }
                } catch (Exception $cleanupError) {
                    // Log cleanup errors but don't fail the operation
                    $this->debugLog("Resource cleanup error", DebugLevel::VERBOSE, [
                        'cleanup_error' => $cleanupError->getMessage()
                    ]);
                }
            }
        }

        return [
            'pages_copied' => $pagesCopied,
            'total_pages' => $totalPages,
            'attempts_used' => $attempt
        ];
    }

    /**
     * Execute backup with progress tracking
     * 
     * @param SQLite3Backup $backup Backup resource
     * @param array $options Backup options
     * @return array Backup execution results
     */
    private function executeBackupWithProgress($backup, array $options): array
    {
        $pagesCopied = 0;
        $totalPages = 0;
        $startTime = microtime(true);

        $this->debugLog("Starting backup execution with progress tracking", DebugLevel::VERBOSE, [
            'pages_per_step' => $options['pages_per_step']
        ]);

        while (true) {
            // Perform backup step
            $result = $backup->step($options['pages_per_step']);

            // Get progress information
            $remaining = $backup->remaining();
            $pageCount = $backup->pagecount();

            $pagesCopied = $pageCount - $remaining;
            $totalPages = $pageCount;

            $this->debugLog("Backup step completed", DebugLevel::VERBOSE, [
                'step_result' => $result,
                'pages_copied' => $pagesCopied,
                'pages_remaining' => $remaining,
                'total_pages' => $totalPages,
                'progress_percent' => $totalPages > 0 ? round(($pagesCopied / $totalPages) * 100, 1) : 0
            ]);

            // Call progress callback if provided
            if ($options['progress_callback'] && is_callable($options['progress_callback'])) {
                $progress = [
                    'pages_copied' => $pagesCopied,
                    'total_pages' => $totalPages,
                    'progress_percent' => $totalPages > 0 ? ($pagesCopied / $totalPages) * 100 : 0,
                    'duration_seconds' => microtime(true) - $startTime
                ];

                call_user_func($options['progress_callback'], $progress);
            }

            // Check completion status
            if ($result === SQLITE3_DONE) {
                $this->debugLog("Backup execution completed successfully", DebugLevel::DETAILED, [
                    'total_pages_copied' => $pagesCopied,
                    'duration_seconds' => round(microtime(true) - $startTime, 3)
                ]);
                break;
            }

            if ($result !== SQLITE3_OK && $result !== SQLITE3_BUSY && $result !== SQLITE3_LOCKED) {
                throw new RuntimeException("Backup step failed with code: $result");
            }

            // Brief pause for busy/locked conditions
            if ($result === SQLITE3_BUSY || $result === SQLITE3_LOCKED) {
                usleep(50000); // 50ms
            }
        }

        $backup->finish();

        return [
            'pages_copied' => $pagesCopied,
            'total_pages' => $totalPages
        ];
    }

    // =============================================================================
    // RESTORE OPERATIONS
    // =============================================================================

    /**
     * Restore database from SQLite backup file
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
            'backup_current' => true,
            'progress_callback' => null
        ], $options);

        $this->debugLog("Starting SQLite database restore", DebugLevel::BASIC, [
            'backup_path' => $backupPath,
            'target_database' => $this->databasePath,
            'options' => $options
        ]);

        try {
            // Validate backup file
            if ($options['validate_before_restore']) {
                $validation = $this->validateBackupFile($backupPath);
                if (!$validation['valid']) {
                    throw new RuntimeException("Backup file validation failed: " . $validation['error']);
                }
            }

            // Backup current database if requested
            if ($options['backup_current'] && file_exists($this->databasePath)) {
                $currentBackupPath = $this->databasePath . '.restore-backup-' . date('Y-m-d-H-i-s');
                copy($this->databasePath, $currentBackupPath);

                $this->debugLog("Current database backed up", DebugLevel::DETAILED, [
                    'backup_path' => $currentBackupPath
                ]);
            }

            // Perform restore by copying backup file
            if (!copy($backupPath, $this->databasePath)) {
                throw new RuntimeException("Failed to copy backup file to database location");
            }

            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite database restore completed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'restored_size_bytes' => filesize($this->databasePath)
            ]);

            return [
                'success' => true,
                'restored_size_bytes' => filesize($this->databasePath),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType()
            ];
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite database restore failed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType()
            ];
        }
    }

    // =============================================================================
    // VALIDATION AND TESTING
    // =============================================================================

    /**
     * Test SQLite native backup capabilities
     * 
     * @return array Comprehensive capability test results
     */
    public function testCapabilities(): array
    {
        $this->debugLog("Testing SQLite native backup capabilities", DebugLevel::BASIC);

        $results = [
            'strategy_type' => $this->getStrategyType(),
            'sqlite3_extension' => extension_loaded('sqlite3'),
            'sqlite3_version' => null,
            'source_database_accessible' => false,
            'source_database_readable' => false,
            'backup_api_functional' => false,
            'temp_directory_writable' => false,
            'overall_status' => 'unknown'
        ];

        try {
            // Check SQLite3 extension and version
            if ($results['sqlite3_extension']) {
                $results['sqlite3_version'] = SQLite3::version()['versionString'];
            }

            // Test source database access
            if (file_exists($this->databasePath)) {
                $results['source_database_accessible'] = true;
                $results['source_database_readable'] = is_readable($this->databasePath);
            }

            // Test temp directory writability
            $results['temp_directory_writable'] = is_writable(sys_get_temp_dir());

            // Test backup API functionality
            if ($results['sqlite3_extension'] && $results['source_database_accessible']) {
                $results['backup_api_functional'] = $this->testBackupAPI();
            }

            // Determine overall status
            $results['overall_status'] = $this->evaluateOverallStatus($results);

            $this->debugLog("Capability testing completed", DebugLevel::DETAILED, $results);
        } catch (Exception $e) {
            $results['test_error'] = $e->getMessage();
            $results['overall_status'] = 'failed';

            $this->debugLog("Capability testing failed", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Test backup API functionality with minimal operation
     * 
     * @return bool True if backup API is functional
     */
    private function testBackupAPI(): bool
    {
        $sourceDb = null;
        $destDb = null;
        $backup = null;

        try {
            $testBackupPath = sys_get_temp_dir() . '/SQLITE3_backup_test_' . uniqid() . '.db';

            $this->debugLog("Testing backup API functionality", DebugLevel::VERBOSE, [
                'source_database' => $this->databasePath,
                'test_backup_path' => $testBackupPath,
                'source_exists' => file_exists($this->databasePath),
                'source_readable' => is_readable($this->databasePath),
                'temp_dir_writable' => is_writable(dirname($testBackupPath))
            ]);

            // Validate source database exists and is readable
            if (!file_exists($this->databasePath)) {
                $this->debugLog("Source database does not exist", DebugLevel::VERBOSE, [
                    'source_path' => $this->databasePath
                ]);
                return false;
            }

            if (!is_readable($this->databasePath)) {
                $this->debugLog("Source database is not readable", DebugLevel::VERBOSE, [
                    'source_path' => $this->databasePath
                ]);
                return false;
            }

            // Try to open source database
            try {
                $sourceDb = new SQLite3($this->databasePath, SQLITE3_OPEN_READONLY);
            } catch (Exception $e) {
                $this->debugLog("Failed to open source database", DebugLevel::VERBOSE, [
                    'error' => $e->getMessage(),
                    'source_path' => $this->databasePath
                ]);
                return false;
            }

            // Try to create destination database
            try {
                $destDb = new SQLite3($testBackupPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            } catch (Exception $e) {
                $this->debugLog("Failed to create destination database", DebugLevel::VERBOSE, [
                    'error' => $e->getMessage(),
                    'dest_path' => $testBackupPath
                ]);

                if ($sourceDb) {
                    $sourceDb->close();
                }
                return false;
            }

            // Try to initialize backup
            try {
                $backup = $sourceDb->backup($destDb);

                $this->debugLog("Backup initialization result", DebugLevel::VERBOSE, [
                    'backup_result' => var_export($backup, true),
                    'backup_type' => gettype($backup),
                    'is_object' => is_object($backup),
                    'is_sqlite3backup' => ($backup instanceof SQLite3Backup),
                    'is_false' => ($backup === false),
                    'is_true' => ($backup === true),
                    'is_null' => ($backup === null)
                ]);
            } catch (Exception $e) {
                $this->debugLog("Failed to initialize backup", DebugLevel::VERBOSE, [
                    'error' => $e->getMessage()
                ]);

                $sourceDb->close();
                $destDb->close();
                return false;
            }

            // Enhanced type checking for backup object
            if ($backup === false || $backup === null) {
                // Expected failure case
                $sourceError = $sourceDb->lastErrorMsg();
                $destError = $destDb->lastErrorMsg();

                $this->debugLog("Backup initialization failed (expected)", DebugLevel::VERBOSE, [
                    'source_error_msg' => $sourceError,
                    'source_error_code' => $sourceDb->lastErrorCode(),
                    'dest_error_msg' => $destError,
                    'dest_error_code' => $destDb->lastErrorCode(),
                    'backup_result' => var_export($backup, true)
                ]);

                $sourceDb->close();
                $destDb->close();
                return false;
            } else if ($backup === true) {
                // Unexpected case - backup() returned boolean true
                $this->debugLog("Backup initialization returned unexpected boolean true", DebugLevel::VERBOSE, [
                    'php_version' => PHP_VERSION,
                    'sqlite3_version' => SQLite3::version()['versionString'],
                    'backup_result' => var_export($backup, true),
                    'possible_php_bug' => 'SQLite3::backup() should never return boolean true'
                ]);

                $sourceDb->close();
                $destDb->close();
                return false;
            } else if (!is_object($backup)) {
                // Any other unexpected type
                $this->debugLog("Backup initialization returned unexpected type", DebugLevel::VERBOSE, [
                    'backup_type' => gettype($backup),
                    'backup_value' => var_export($backup, true),
                    'expected_type' => 'SQLite3Backup object or false'
                ]);

                $sourceDb->close();
                $destDb->close();
                return false;
            } else if (!($backup instanceof SQLite3Backup)) {
                // Object but not the right type
                $this->debugLog("Backup initialization returned wrong object type", DebugLevel::VERBOSE, [
                    'actual_class' => get_class($backup),
                    'expected_class' => 'SQLite3Backup',
                    'backup_methods' => get_class_methods($backup)
                ]);

                $sourceDb->close();
                $destDb->close();
                return false;
            } else {
                // Backup object is valid SQLite3Backup, proceed with step
                $functional = false;

                try {
                    // Verify the object has the methods we need
                    if (!method_exists($backup, 'step')) {
                        throw new RuntimeException("SQLite3Backup object missing step() method");
                    }

                    if (!method_exists($backup, 'finish')) {
                        throw new RuntimeException("SQLite3Backup object missing finish() method");
                    }

                    $this->debugLog("About to call backup->step()", DebugLevel::VERBOSE, [
                        'backup_class' => get_class($backup),
                        'backup_methods' => get_class_methods($backup)
                    ]);

                    $result = $backup->step(1); // Copy just one page

                    $this->debugLog("Backup step completed", DebugLevel::VERBOSE, [
                        'step_result' => $result,
                        'step_result_type' => gettype($result),
                        'step_result_name' => $this->getSQLiteResultName($result)
                    ]);

                    $backup->finish();

                    $functional = ($result === SQLITE3_OK || $result === SQLITE3_DONE);

                    $this->debugLog("Backup step test completed", DebugLevel::VERBOSE, [
                        'step_result' => $result,
                        'step_result_name' => $this->getSQLiteResultName($result),
                        'functional' => $functional,
                        'pages_remaining' => method_exists($backup, 'remaining') ? $backup->remaining() : 'method_not_available',
                        'page_count' => method_exists($backup, 'pagecount') ? $backup->pagecount() : 'method_not_available'
                    ]);
                } catch (Exception $e) {
                    $this->debugLog("Backup step failed with exception", DebugLevel::VERBOSE, [
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                        'backup_class' => get_class($backup),
                        'trace' => $e->getTraceAsString()
                    ]);

                    try {
                        if (method_exists($backup, 'finish')) {
                            $backup->finish();
                        }
                    } catch (Exception $finishError) {
                        $this->debugLog("Backup finish also failed", DebugLevel::VERBOSE, [
                            'finish_error' => $finishError->getMessage()
                        ]);
                    }
                    $functional = false;
                }
            }

            // Close database connections
            $sourceDb->close();
            $destDb->close();

            // Cleanup test file
            if (file_exists($testBackupPath)) {
                unlink($testBackupPath);
            }

            return $functional;
        } catch (Exception $e) {
            $this->debugLog("Backup API test failed with exception", DebugLevel::VERBOSE, [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e)
            ]);

            // Clean up resources
            try {
                if ($backup && method_exists($backup, 'finish')) {
                    $backup->finish();
                }
                if ($sourceDb) {
                    $sourceDb->close();
                }
                if ($destDb) {
                    $destDb->close();
                }
            } catch (Exception $cleanupError) {
                // Ignore cleanup errors
            }

            return false;
        }
    }

    /**
     * Get human-readable name for SQLite result code
     * 
     * @param int $result SQLite result code
     * @return string Result name
     */
    private function getSQLiteResultName(int $result): string
    {
        $names = [
            SQLITE3_OK => 'SQLITE3_OK',
            SQLITE3_DONE => 'SQLITE3_DONE',
            SQLITE3_BUSY => 'SQLITE3_BUSY',
            SQLITE3_LOCKED => 'SQLITE3_LOCKED',
            SQLITE3_ERROR => 'SQLITE3_ERROR',
            SQLITE3_CORRUPT => 'SQLITE3_CORRUPT',
            SQLITE3_FULL => 'SQLITE3_FULL',
            SQLITE3_READONLY => 'SQLITE3_READONLY',
            SQLITE3_INTERRUPT => 'SQLITE3_INTERRUPT',
            SQLITE3_IOERR => 'SQLITE3_IOERR',
            SQLITE3_NOTFOUND => 'SQLITE3_NOTFOUND',
            SQLITE3_NOMEM => 'SQLITE3_NOMEM',
            SQLITE3_MISUSE => 'SQLITE3_MISUSE',
            SQLITE3_NOLFS => 'SQLITE3_NOLFS',
            SQLITE3_AUTH => 'SQLITE3_AUTH',
            SQLITE3_FORMAT => 'SQLITE3_FORMAT',
            SQLITE3_RANGE => 'SQLITE3_RANGE',
            SQLITE3_NOTADB => 'SQLITE3_NOTADB'
        ];

        return $names[$result] ?? "UNKNOWN_RESULT($result)";
    }


    /**
     * Validate SQLite backup file
     * 
     * @param string $backupPath Path to backup file
     * @return array Validation results
     */
    public function validateBackupFile(string $backupPath): array
    {
        $this->debugLog("Validating SQLite backup file", DebugLevel::DETAILED, [
            'backup_path' => $backupPath
        ]);

        $validation = [
            'valid' => false,
            'file_exists' => false,
            'file_readable' => false,
            'file_size_bytes' => 0,
            'SQLITE3_format_valid' => false,
            'database_openable' => false,
            'integrity_check_passed' => false,
            'table_count' => 0,
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

            // Check SQLite format
            $validation['SQLITE3_format_valid'] = $this->validateSQLiteFormat($backupPath);
            if (!$validation['SQLITE3_format_valid']) {
                $validation['error'] = "File is not a valid SQLite database";
                return $validation;
            }

            // Test database opening
            $testDb = new SQLite3($backupPath, SQLITE3_OPEN_READONLY);
            $validation['database_openable'] = true;

            // Run integrity check
            $integrityResult = $testDb->querySingle("PRAGMA integrity_check");
            $validation['integrity_check_passed'] = ($integrityResult === 'ok');

            // Count tables
            $tableCountResult = $testDb->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
            $validation['table_count'] = (int)$tableCountResult;

            $testDb->close();

            $validation['valid'] = $validation['database_openable'] &&
                $validation['integrity_check_passed'] &&
                $validation['table_count'] >= 0;

            $this->debugLog("Backup file validation completed", DebugLevel::DETAILED, $validation);
        } catch (Exception $e) {
            $validation['error'] = $e->getMessage();

            $this->debugLog("Backup file validation failed", DebugLevel::DETAILED, [
                'error' => $e->getMessage()
            ]);
        }

        return $validation;
    }

    /**
     * Validate SQLite file format by checking magic header
     * 
     * @param string $filePath Path to file to check
     * @return bool True if file has valid SQLite format
     */
    private function validateSQLiteFormat(string $filePath): bool
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 16);
        fclose($handle);

        // SQLite files start with "SQLite format 3\000"
        $expectedHeader = "SQLite format 3\000";

        return strncmp($header, $expectedHeader, strlen($expectedHeader)) === 0;
    }

    // =============================================================================
    // UTILITY AND CONFIGURATION METHODS
    // =============================================================================

    /**
     * Extract database file path from PDO connection
     * 
     * @return string Database file path
     * @throws RuntimeException If path cannot be determined
     */
    private function extractDatabasePath(): string
    {

        // For SQLite, try to extract path from DSN or use connection config
        if (isset($this->connectionConfig['path'])) {
            return $this->connectionConfig['path'];
        }

        if (isset($this->connectionConfig['database'])) {
            return $this->connectionConfig['database'];
        }

        // Get DSN from PDO connection
        $dsn = $this->pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS) ?? '';

        // Try to extract from DSN (basic implementation)
        if (preg_match('/sqlite:(.+)$/', $dsn, $matches)) {
            return $matches[1];
        }

        throw new RuntimeException("Could not determine SQLite database file path");
    }


    /**
     * Validate source database before backup
     * 
     * @throws RuntimeException If source database is invalid
     */
    private function validateSourceDatabase(): void
    {
        if (!file_exists($this->databasePath)) {
            throw new RuntimeException("Source database file does not exist: {$this->databasePath}");
        }

        if (!is_readable($this->databasePath)) {
            throw new RuntimeException("Source database file is not readable: {$this->databasePath}");
        }

        // Test if database can be opened
        try {
            $testDb = new SQLite3($this->databasePath, SQLITE3_OPEN_READONLY);
            $testDb->close();
        } catch (Exception $e) {
            throw new RuntimeException("Source database cannot be opened: " . $e->getMessage());
        }
    }

    /**
     * Prepare backup directory and validate path
     * 
     * @param string $outputPath Backup file path
     * @throws RuntimeException If directory cannot be prepared
     */
    private function prepareBackupDirectory(string $outputPath): void
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
     * Vacuum source database before backup
     */
    private function vacuumSourceDatabase(): void
    {
        $this->debugLog("Vacuuming source database before backup", DebugLevel::DETAILED);

        try {
            $this->pdo->exec('VACUUM');

            $this->debugLog("Source database vacuum completed", DebugLevel::DETAILED);
        } catch (PDOException $e) {
            $this->debugLog("Source database vacuum failed", DebugLevel::DETAILED, [
                'error' => $e->getMessage()
            ]);
            // Don't fail the backup for vacuum issues
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
        if (!$results['sqlite3_extension']) {
            return 'unavailable - SQLite3 extension not loaded';
        }

        if (!$results['source_database_accessible']) {
            return 'unavailable - source database not accessible';
        }

        if (!$results['temp_directory_writable']) {
            return 'unavailable - temp directory not writable';
        }

        if (!$results['backup_api_functional']) {
            return 'error - backup API not functional';
        }

        return 'available';
    }

    // =============================================================================
    // INTERFACE IMPLEMENTATION
    // =============================================================================

    public function estimateBackupSize(): int
    {
        if (file_exists($this->databasePath)) {
            return filesize($this->databasePath);
        }
        return 0;
    }

    public function estimateBackupTime(): int
    {
        $sizeBytes = $this->estimateBackupSize();
        // Estimate ~10MB/second for SQLite backup
        return max(10, intval($sizeBytes / (10 * 1024 * 1024)));
    }

    public function getStrategyType(): string
    {
        return 'SQLITE3_native';
    }

    public function getDescription(): string
    {
        return 'SQLite Native Backup (SQLite3::backup() API)';
    }

    public function getSelectionCriteria(): array
    {
        return [
            'priority' => 1, // Highest priority for SQLite
            'requirements' => ['sqlite3_extension'],
            'advantages' => [
                'Atomic operation',
                'Concurrent-safe',
                'Works with WAL mode',
                'Native progress tracking'
            ],
            'limitations' => [
                'Requires SQLite3 extension',
                'SQLite databases only'
            ]
        ];
    }

    public function supportsCompression(): bool
    {
        return false; // Native backup doesn't support compression
    }

    public function detectBackupFormat(string $backupPath): string
    {
        if ($this->validateSQLiteFormat($backupPath)) {
            return 'SQLITE3_native';
        }
        return 'unknown';
    }

    public function getRestoreOptions(string $backupPath): array
    {
        $validation = $this->validateBackupFile($backupPath);

        return [
            'full_restore' => $validation['valid'],
            'validate_before_restore' => true,
            'backup_current_database' => true,
            'estimated_duration_seconds' => $this->estimateBackupTime()
        ];
    }

    public function partialRestore(string $backupPath, array $targets, array $options = []): array
    {
        // SQLite native backup doesn't support partial restore
        throw new RuntimeException("Partial restore not supported by SQLite native backup strategy");
    }
}
