<?php

/**
 * SQLite Native Backup Strategy - SQLite3::backup() API Implementation
 * 
 * Implements SQLite database backup using PHP's native SQLite3::backup() API.
 * This is the preferred method for SQLite backups as it provides atomic,
 * concurrent-safe backup operations that work correctly with WAL mode.
 * 
 * 
 * Key Features:
 * - Uses SQLite3::backup() API for atomic backup operations
 * - Handles concurrent access safely (works with WAL mode)
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
     * Uses SQLite3::backup() for atomic, concurrent-safe backup. Progress is simulated (0% and 100%).
     *
     * @param string $outputPath Path where backup file should be created
     * @param array $options Backup configuration options (e.g., 'progress_callback', 'retry_attempts')
     * @return array Backup result with success status and metadata
     * @throws RuntimeException If backup fails
     */
    public function createBackup(string $outputPath, array $options = []): array {
        $startTime = microtime(true);
        $this->validateBackupPath($outputPath);

        // Normalize options with defaults
        $options = array_merge([
            'progress_callback' => null,
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
            $this->validateSourceDatabase();
            $this->prepareBackupDirectory($outputPath);

            if ($options['vacuum_source']) {
                $this->vacuumSourceDatabase();
            }

            $backupResult = $this->performNativeBackup($outputPath, $options);

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
     * Perform the actual native backup operation
     *
     * @param string $outputPath Backup file path
     * @param array $options Backup options
     * @return array Backup operation results
     * @throws RuntimeException If backup fails after retries
     */
    private function performNativeBackup(string $outputPath, array $options): array {
        $pagesCopied = 0;
        $totalPages = 0;
        $attempt = 0;
        $maxAttempts = $options['retry_attempts'];

        while ($attempt < $maxAttempts) {
            $attempt++;
            $this->debugLog("Starting backup attempt", DebugLevel::DETAILED, [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts
            ]);

            $sourceDb = null;
            $destDb = null;

            try {
                $sourceDb = new SQLite3($this->databasePath, SQLITE3_OPEN_READONLY);
                $destDb = new SQLite3($outputPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

                // Perform backup and get results
                $result = $this->executeBackup($sourceDb, $destDb, $options);
                $pagesCopied = $result['pages_copied'];
                $totalPages = $result['total_pages'];

                $this->debugLog("Backup attempt successful", DebugLevel::DETAILED, [
                    'attempt' => $attempt,
                    'pages_copied' => $pagesCopied,
                    'total_pages' => $totalPages
                ]);

                break; // Success
            } catch (RuntimeException $e) {
                $errorCode = $sourceDb ? $sourceDb->lastErrorCode() : 0;
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

                // Retry on busy/locked errors
                if (in_array($errorCode, [SQLITE3_BUSY, SQLITE3_LOCKED])) {
                    if ($options['retry_delay_ms'] > 0) {
                        usleep($options['retry_delay_ms'] * 1000);
                    }
                }
            } finally {
                if ($sourceDb) $sourceDb->close();
                if ($destDb) $destDb->close();
                if (file_exists($outputPath) && $pagesCopied === 0) unlink($outputPath); // Cleanup incomplete backups
            }
        }

        return [
            'pages_copied' => $pagesCopied,
            'total_pages' => $totalPages,
            'attempts_used' => $attempt
        ];
    }


    /**
     * Execute the backup operation (atomic, as per PHP SQLite3 API)
     *
     * @param SQLite3 $sourceDb Source database connection
     * @param SQLite3 $destDb Destination database connection
     * @param array $options Backup options
     * @return array Backup execution results
     * @throws RuntimeException If backup fails
     */
    private function executeBackup(SQLite3 $sourceDb, SQLite3 $destDb, array $options): array {
        $startTime = microtime(true);
        $this->debugLog("Starting backup execution (atomic)", DebugLevel::VERBOSE);

        // Simulate 0% progress
        if (isset($options['progress_callback']) && is_callable($options['progress_callback'])) {
            call_user_func($options['progress_callback'], [
                'pages_copied' => 0,
                'total_pages' => 0,
                'progress_percent' => 0,
                'duration_seconds' => 0
            ]);
        }

        // Perform atomic backup
        if (!$sourceDb->backup($destDb)) {
            throw new RuntimeException("Backup failed: " . $sourceDb->lastErrorMsg());
        }

        // Estimate pages (using 4KB page size)
        $backupSize = $this->estimateBackupSize();
        $totalPages = $backupSize > 0 ? ceil($backupSize / 4096) : 0;
        $pagesCopied = $totalPages;

        // Simulate 100% progress
        if (isset($options['progress_callback']) && is_callable($options['progress_callback'])) {
            call_user_func($options['progress_callback'], [
                'pages_copied' => $pagesCopied,
                'total_pages' => $totalPages,
                'progress_percent' => 100,
                'duration_seconds' => microtime(true) - $startTime
            ]);
        }

        $this->debugLog("Backup execution completed", DebugLevel::DETAILED, [
            'pages_copied' => $pagesCopied,
            'duration_seconds' => round(microtime(true) - $startTime, 3)
        ]);

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
    public function testCapabilities(): array {
        $results = [
            'strategy_type' => $this->getStrategyType(),
            'sqlite3_extension' => extension_loaded('sqlite3'),
            'sqlite3_version' => SQLite3::version()['versionString'] ?? null,
            'source_database_accessible' => file_exists($this->databasePath) && is_readable($this->databasePath),
            'temp_directory_writable' => is_writable(sys_get_temp_dir()),
            'backup_api_functional' => $this->testBackupAPI(),
            'overall_status' => 'unknown'
        ];
        $results['overall_status'] = $this->evaluateOverallStatus($results);
        return $results;
    }


    /**
     * Test backup API functionality with minimal operation
     * 
     * @return bool True if backup API is functional
     */
    private function testBackupAPI(): bool {
        // [Simplified: Test boolean return, no object checks]
        $testBackupPath = sys_get_temp_dir() . '/SQLITE3_backup_test_' . uniqid() . '.db';
        try {
            $sourceDb = new SQLite3($this->databasePath, SQLITE3_OPEN_READONLY);
            $destDb = new SQLite3($testBackupPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $success = $sourceDb->backup($destDb);
            $sourceDb->close();
            $destDb->close();
            if (file_exists($testBackupPath)) unlink($testBackupPath);
            return $success;
        } catch (Exception $e) {
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

    public function getSelectionCriteria(): array {
        return [
            'priority' => 1,
            'requirements' => ['sqlite3_extension'],
            'advantages' => [
                'Atomic operation',
                'Concurrent-safe',
                'Works with WAL mode'
            ],
            'limitations' => [
                'Requires SQLite3 extension',
                'SQLite databases only',
                'No incremental progress tracking'
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
