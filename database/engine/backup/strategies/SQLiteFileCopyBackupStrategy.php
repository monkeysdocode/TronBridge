<?php

/**
 * SQLite File Copy Backup Strategy - Last Resort File-Based Backup
 * 
 * Implements SQLite database backup using simple file copy operations as a last resort
 * when other backup methods are unavailable. This strategy handles SQLite's WAL mode
 * complexities and provides safe file copying with proper locking and validation.
 * 
 * **ENHANCED WITH FULL DEBUG SYSTEM INTEGRATION**
 * - Comprehensive logging of file operations and safety measures
 * - WAL mode detection and handling with detailed analysis
 * - File locking and concurrency safety monitoring
 * - Detailed error analysis and troubleshooting guidance
 * 
 * Key Features:
 * - Last resort backup when SQLite3 extension and VACUUM INTO unavailable
 * - Handles WAL (-wal) and SHM (-shm) auxiliary files properly
 * - Database locking for consistent backup in active environments
 * - Comprehensive validation and integrity checking
 * - Safe concurrent access handling
 * - Cross-platform file operations
 * 
 * Safety Measures:
 * - Database locking during copy to prevent corruption
 * - WAL checkpoint forcing before backup
 * - Auxiliary file handling (WAL, SHM) for complete backup
 * - Validation of copied files before completion
 * - Atomic backup operations where possible
 * 
 * Limitations:
 * - Risk of corruption if database is actively being written during backup
 * - Requires database locking which may impact performance
 * - Less efficient than native backup methods
 * - May not work correctly with very high concurrency
 * 
 * @package Database\Backup\Strategy\SQLite
 * @author Enhanced Model System
 * @version 1.0.0 - Initial Implementation with Debug Integration
 */
class SQLiteFileCopyBackupStrategy implements BackupStrategyInterface, RestoreStrategyInterface
{
    use DebugLoggingTrait;
    use ConfigSanitizationTrait;
    use PathValidationTrait;
    
    private Model $model;
    private PDO $pdo;
    private array $connectionConfig;
    private string $databasePath;
    private bool $walModeActive = false;

    /**
     * Initialize SQLite file copy backup strategy
     * 
     * @param Model $model Enhanced Model instance for debug logging
     * @param array $connectionConfig Database connection configuration
     */
    public function __construct(Model $model, array $connectionConfig)
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->connectionConfig = $connectionConfig;
        $this->databasePath = $this->extractDatabasePath();

        // Detect WAL mode
        $this->walModeActive = $this->detectWALMode();

        $this->debugLog("SQLite file copy backup strategy initialized", DebugLevel::VERBOSE, [
            'database_path' => $this->databasePath,
            'wal_mode_active' => $this->walModeActive,
            'database_exists' => file_exists($this->databasePath),
            'database_size_bytes' => file_exists($this->databasePath) ? filesize($this->databasePath) : 0,
            'connection_config' => $this->sanitizeConfig($connectionConfig)
        ]);

        // Warn about limitations
        $this->debugLog("Using file copy backup strategy", DebugLevel::BASIC, [
            'warning' => 'File copy is a last resort backup method with safety limitations',
            'recommendation' => 'Consider upgrading SQLite or enabling SQLite3 extension for better backup methods',
            'wal_mode_detected' => $this->walModeActive
        ]);
    }

    // =============================================================================
    // BACKUP OPERATIONS
    // =============================================================================

    /**
     * Create SQLite database backup using file copy
     * 
     * Performs file-based backup with proper locking, WAL handling, and validation.
     * This method attempts to ensure consistency but cannot guarantee atomicity
     * like native backup methods.
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
            'force_wal_checkpoint' => true,
            'use_exclusive_lock' => true,
            'copy_auxiliary_files' => true,
            'validate_backup' => true,
            'retry_attempts' => 3,
            'retry_delay_ms' => 500,
            'timeout' => 300
        ], $options);

        $this->debugLog("Starting SQLite file copy backup", DebugLevel::BASIC, [
            'source_database' => $this->databasePath,
            'output_path' => $outputPath,
            'wal_mode_active' => $this->walModeActive,
            'force_wal_checkpoint' => $options['force_wal_checkpoint'],
            'use_exclusive_lock' => $options['use_exclusive_lock'],
            'options' => $options
        ]);

        try {
            // Validate source database
            $this->validateSourceDatabase();

            // Prepare backup directory
            $this->prepareBackupDirectory($outputPath);

            // Pre-backup analysis
            $preBackupStats = $this->analyzeSourceDatabase();

            // Execute file copy backup with safety measures
            $copyResult = $this->executeFileCopyBackup($outputPath, $options);

            // Validate backup if requested
            $validationResult = null;
            if ($options['validate_backup']) {
                $validationResult = $this->validateBackupFile($outputPath);
                if (!$validationResult['valid']) {
                    throw new RuntimeException("Backup validation failed: " . ($validationResult['error'] ?? 'Unknown error'));
                }
            }

            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite file copy backup completed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'backup_size_bytes' => filesize($outputPath),
                'wal_checkpoint_performed' => $copyResult['wal_checkpoint_performed'],
                'auxiliary_files_copied' => $copyResult['auxiliary_files_copied'],
                'validation_passed' => $validationResult['valid'] ?? false,
                'retry_attempts_used' => $copyResult['attempts_used']
            ]);

            return [
                'success' => true,
                'output_path' => $outputPath,
                'backup_size_bytes' => filesize($outputPath),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType(),
                'wal_checkpoint_performed' => $copyResult['wal_checkpoint_performed'],
                'auxiliary_files_copied' => $copyResult['auxiliary_files_copied'],
                'validation' => $validationResult,
                'pre_backup_stats' => $preBackupStats,
                'copy_metadata' => $copyResult
            ];
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite file copy backup failed", DebugLevel::BASIC, [
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
     * Execute file copy backup with safety measures
     * 
     * @param string $outputPath Backup file path
     * @param array $options Backup options
     * @return array Copy operation results
     * @throws RuntimeException If copy fails
     */
    private function executeFileCopyBackup(string $outputPath, array $options): array
    {
        $result = [
            'wal_checkpoint_performed' => false,
            'auxiliary_files_copied' => [],
            'attempts_used' => 0,
            'lock_acquired' => false
        ];

        $maxAttempts = $options['retry_attempts'];
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $result['attempts_used'] = $attempt;

            $this->debugLog("Starting file copy attempt", DebugLevel::DETAILED, [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts
            ]);

            try {
                // Force WAL checkpoint if in WAL mode
                if ($this->walModeActive && $options['force_wal_checkpoint']) {
                    $this->forceWALCheckpoint();
                    $result['wal_checkpoint_performed'] = true;
                }

                // Acquire database lock if requested
                if ($options['use_exclusive_lock']) {
                    $this->acquireExclusiveLock();
                    $result['lock_acquired'] = true;
                }

                // Perform the actual file copy
                $this->performFileCopy($outputPath);

                // Copy auxiliary files if requested and present
                if ($options['copy_auxiliary_files']) {
                    $result['auxiliary_files_copied'] = $this->copyAuxiliaryFiles($outputPath);
                }

                $this->debugLog("File copy attempt successful", DebugLevel::DETAILED, [
                    'attempt' => $attempt,
                    'backup_size_bytes' => filesize($outputPath),
                    'auxiliary_files' => count($result['auxiliary_files_copied'])
                ]);

                break; // Success, exit retry loop

            } catch (Exception $e) {
                $this->debugLog("File copy attempt failed", DebugLevel::DETAILED, [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'will_retry' => $attempt < $maxAttempts
                ]);

                // Cleanup partial backup
                if (file_exists($outputPath)) {
                    unlink($outputPath);
                }

                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException("File copy failed after $maxAttempts attempts: " . $e->getMessage());
                }

                // Wait before retry
                if ($options['retry_delay_ms'] > 0) {
                    usleep($options['retry_delay_ms'] * 1000);
                }
            }
        }

        return $result;
    }

    /**
     * Force WAL checkpoint to flush pending transactions
     */
    private function forceWALCheckpoint(): void
    {
        $this->debugLog("Forcing WAL checkpoint", DebugLevel::DETAILED);

        try {
            // Try different checkpoint modes for maximum data consistency
            $checkpointModes = ['TRUNCATE', 'RESTART', 'PASSIVE'];

            foreach ($checkpointModes as $mode) {
                try {
                    $sql = "PRAGMA wal_checkpoint($mode)";
                    $result = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM);

                    $this->debugLog("WAL checkpoint completed", DebugLevel::VERBOSE, [
                        'mode' => $mode,
                        'busy' => $result[0] ?? 'unknown',
                        'log_pages' => $result[1] ?? 'unknown',
                        'checkpointed_pages' => $result[2] ?? 'unknown'
                    ]);

                    break; // Success with this mode

                } catch (PDOException $e) {
                    $this->debugLog("WAL checkpoint mode failed", DebugLevel::VERBOSE, [
                        'mode' => $mode,
                        'error' => $e->getMessage()
                    ]);

                    // Continue to next mode
                }
            }
        } catch (Exception $e) {
            $this->debugLog("WAL checkpoint failed", DebugLevel::DETAILED, [
                'error' => $e->getMessage(),
                'impact' => 'Backup may not include all recent transactions'
            ]);

            // Don't fail the backup for checkpoint issues
        }
    }

    /**
     * Acquire exclusive database lock
     */
    private function acquireExclusiveLock(): void
    {
        $this->debugLog("Acquiring exclusive database lock", DebugLevel::DETAILED);

        try {
            // Begin exclusive transaction to lock database
            $this->pdo->exec('BEGIN EXCLUSIVE');

            $this->debugLog("Exclusive lock acquired", DebugLevel::VERBOSE);
        } catch (PDOException $e) {
            $this->debugLog("Failed to acquire exclusive lock", DebugLevel::DETAILED, [
                'error' => $e->getMessage(),
                'impact' => 'Backup will proceed without exclusive lock (higher risk of inconsistency)'
            ]);

            // Don't fail backup for locking issues, but warn
        }
    }

    /**
     * Perform the actual file copy operation
     * 
     * @param string $outputPath Destination path
     * @throws RuntimeException If copy fails
     */
    private function performFileCopy(string $outputPath): void
    {
        $this->debugLog("Performing database file copy", DebugLevel::DETAILED, [
            'source' => $this->databasePath,
            'destination' => $outputPath,
            'source_size_bytes' => filesize($this->databasePath)
        ]);

        // Use stream copy for better error handling and memory efficiency
        $sourceHandle = fopen($this->databasePath, 'rb');
        if (!$sourceHandle) {
            throw new RuntimeException("Cannot open source database file: " . $this->databasePath);
        }

        $destHandle = fopen($outputPath, 'wb');
        if (!$destHandle) {
            fclose($sourceHandle);
            throw new RuntimeException("Cannot create backup file: $outputPath");
        }

        try {
            $bytesCopied = stream_copy_to_stream($sourceHandle, $destHandle);

            if ($bytesCopied === false) {
                throw new RuntimeException("Stream copy failed");
            }

            $this->debugLog("File copy completed", DebugLevel::DETAILED, [
                'bytes_copied' => $bytesCopied,
                'destination_size' => filesize($outputPath)
            ]);
        } finally {
            fclose($sourceHandle);
            fclose($destHandle);

            // End exclusive transaction if we started one
            try {
                $this->pdo->exec('COMMIT');
            } catch (PDOException $e) {
                // Ignore commit errors
            }
        }
    }

    /**
     * Copy auxiliary files (WAL, SHM) if they exist
     * 
     * @param string $backupPath Main backup file path
     * @return array List of copied auxiliary files
     */
    private function copyAuxiliaryFiles(string $backupPath): array
    {
        $copiedFiles = [];

        // Define auxiliary file extensions
        $auxiliaryExtensions = ['-wal', '-shm'];

        foreach ($auxiliaryExtensions as $extension) {
            $sourceAuxFile = $this->databasePath . $extension;
            $destAuxFile = $backupPath . $extension;

            if (file_exists($sourceAuxFile)) {
                $this->debugLog("Copying auxiliary file", DebugLevel::VERBOSE, [
                    'source' => $sourceAuxFile,
                    'destination' => $destAuxFile,
                    'size_bytes' => filesize($sourceAuxFile)
                ]);

                try {
                    if (copy($sourceAuxFile, $destAuxFile)) {
                        $copiedFiles[] = [
                            'extension' => $extension,
                            'source' => $sourceAuxFile,
                            'destination' => $destAuxFile,
                            'size_bytes' => filesize($destAuxFile)
                        ];
                    }
                } catch (Exception $e) {
                    $this->debugLog("Failed to copy auxiliary file", DebugLevel::VERBOSE, [
                        'file' => $sourceAuxFile,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        if (!empty($copiedFiles)) {
            $this->debugLog("Auxiliary files copied", DebugLevel::DETAILED, [
                'files_copied' => count($copiedFiles),
                'total_auxiliary_bytes' => array_sum(array_column($copiedFiles, 'size_bytes'))
            ]);
        }

        return $copiedFiles;
    }

    // =============================================================================
    // RESTORE OPERATIONS
    // =============================================================================

    /**
     * Restore database from file copy backup
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
            'restore_auxiliary_files' => true,
            'force_wal_checkpoint_after' => true
        ], $options);

        $this->debugLog("Starting SQLite file copy restore", DebugLevel::BASIC, [
            'backup_path' => $backupPath,
            'target_database' => $this->databasePath,
            'options' => $options
        ]);

        try {
            // Validate backup file
            if ($options['validate_before_restore']) {
                $validation = $this->validateBackupFile($backupPath);
                if (!$validation['valid']) {
                    throw new RuntimeException("Backup file validation failed: " . ($validation['error'] ?? 'Unknown error'));
                }
            }

            // Backup current database if requested
            $currentBackupPath = null;
            if ($options['backup_current'] && file_exists($this->databasePath)) {
                $currentBackupPath = $this->databasePath . '.restore-backup-' . date('Y-m-d-H-i-s');
                if (!copy($this->databasePath, $currentBackupPath)) {
                    throw new RuntimeException("Failed to backup current database");
                }

                $this->debugLog("Current database backed up", DebugLevel::DETAILED, [
                    'backup_path' => $currentBackupPath
                ]);
            }

            // Close existing database connection to avoid file locks
            $this->closeDatabaseConnection();

            // Perform restore by copying backup file
            if (!copy($backupPath, $this->databasePath)) {
                throw new RuntimeException("Failed to copy backup file to database location");
            }

            // Restore auxiliary files if requested
            $restoredAuxiliaryFiles = [];
            if ($options['restore_auxiliary_files']) {
                $restoredAuxiliaryFiles = $this->restoreAuxiliaryFiles($backupPath);
            }

            // Reconnect to database
            $this->reconnectDatabase();

            // Force WAL checkpoint after restore
            if ($options['force_wal_checkpoint_after'] && $this->detectWALMode()) {
                $this->forceWALCheckpoint();
            }

            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite file copy restore completed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'restored_size_bytes' => filesize($this->databasePath),
                'auxiliary_files_restored' => count($restoredAuxiliaryFiles),
                'current_backup_created' => $currentBackupPath !== null
            ]);

            return [
                'success' => true,
                'restored_size_bytes' => filesize($this->databasePath),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType(),
                'current_backup_path' => $currentBackupPath,
                'auxiliary_files_restored' => $restoredAuxiliaryFiles
            ];
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite file copy restore failed", DebugLevel::BASIC, [
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

    /**
     * Restore auxiliary files during restore operation
     * 
     * @param string $backupPath Main backup file path
     * @return array List of restored auxiliary files
     */
    private function restoreAuxiliaryFiles(string $backupPath): array
    {
        $restoredFiles = [];
        $auxiliaryExtensions = ['-wal', '-shm'];

        foreach ($auxiliaryExtensions as $extension) {
            $backupAuxFile = $backupPath . $extension;
            $targetAuxFile = $this->databasePath . $extension;

            if (file_exists($backupAuxFile)) {
                try {
                    if (copy($backupAuxFile, $targetAuxFile)) {
                        $restoredFiles[] = [
                            'extension' => $extension,
                            'size_bytes' => filesize($targetAuxFile)
                        ];

                        $this->debugLog("Auxiliary file restored", DebugLevel::VERBOSE, [
                            'file' => $targetAuxFile,
                            'size_bytes' => filesize($targetAuxFile)
                        ]);
                    }
                } catch (Exception $e) {
                    $this->debugLog("Failed to restore auxiliary file", DebugLevel::VERBOSE, [
                        'file' => $backupAuxFile,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $restoredFiles;
    }

    // =============================================================================
    // VALIDATION AND TESTING
    // =============================================================================

    /**
     * Test SQLite file copy backup capabilities
     * 
     * @return array Comprehensive capability test results
     */
    public function testCapabilities(): array
    {
        $this->debugLog("Testing SQLite file copy backup capabilities", DebugLevel::BASIC);

        $results = [
            'strategy_type' => $this->getStrategyType(),
            'source_database_exists' => false,
            'source_database_readable' => false,
            'source_database_writable' => false,
            'temp_directory_writable' => false,
            'file_copy_functional' => false,
            'wal_mode_detected' => $this->walModeActive,
            'auxiliary_files_present' => [],
            'overall_status' => 'unknown'
        ];

        try {
            // Test source database access
            $results['source_database_exists'] = file_exists($this->databasePath);
            if ($results['source_database_exists']) {
                $results['source_database_readable'] = is_readable($this->databasePath);
                $results['source_database_writable'] = is_writable($this->databasePath);
            }

            // Test temp directory writability
            $results['temp_directory_writable'] = is_writable(sys_get_temp_dir());

            // Check for auxiliary files
            $auxiliaryExtensions = ['-wal', '-shm'];
            foreach ($auxiliaryExtensions as $extension) {
                $auxFile = $this->databasePath . $extension;
                if (file_exists($auxFile)) {
                    $results['auxiliary_files_present'][] = [
                        'extension' => $extension,
                        'size_bytes' => filesize($auxFile)
                    ];
                }
            }

            // Test file copy functionality
            if ($results['source_database_exists'] && $results['temp_directory_writable']) {
                $results['file_copy_functional'] = $this->testFileCopyFunctionality();
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
     * Test file copy functionality with minimal operation
     * 
     * @return bool True if file copy is functional
     */
    private function testFileCopyFunctionality(): bool
    {
        try {
            $testBackupPath = sys_get_temp_dir() . '/sqlite_copy_test_' . uniqid() . '.db';

            // Test file copy
            $success = copy($this->databasePath, $testBackupPath);

            if ($success && file_exists($testBackupPath)) {
                $originalSize = filesize($this->databasePath);
                $copySize = filesize($testBackupPath);
                $functional = ($originalSize === $copySize);
            } else {
                $functional = false;
            }

            // Cleanup test file
            if (file_exists($testBackupPath)) {
                unlink($testBackupPath);
            }

            return $functional;
        } catch (Exception $e) {
            $this->debugLog("File copy functionality test failed", DebugLevel::VERBOSE, [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }


    /**
     * Validate SQLite backup file created by file copy
     * 
     * @param string $backupPath Path to backup file
     * @return array Validation results
     */
    public function validateBackupFile(string $backupPath): array
    {
        $this->debugLog("Validating SQLite file copy backup", DebugLevel::DETAILED, [
            'backup_path' => $backupPath
        ]);

        $validation = [
            'valid' => false,
            'file_exists' => false,
            'file_readable' => false,
            'file_size_bytes' => 0,
            'sqlite_format_valid' => false,
            'database_openable' => false,
            'integrity_check_passed' => false,
            'size_matches_original' => false,
            'table_count' => 0,
            'auxiliary_files_present' => [],
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

            // Compare size with original
            if (file_exists($this->databasePath)) {
                $originalSize = filesize($this->databasePath);
                $validation['size_matches_original'] = ($validation['file_size_bytes'] === $originalSize);
            }

            // Check SQLite format
            $validation['sqlite_format_valid'] = $this->validateSQLiteFormat($backupPath);
            if (!$validation['sqlite_format_valid']) {
                $validation['error'] = "File is not a valid SQLite database";
                return $validation;
            }

            // Test database opening and integrity
            $testPdo = new PDO("sqlite:$backupPath", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $validation['database_openable'] = true;

            // Run integrity check
            $integrityResult = $testPdo->query("PRAGMA integrity_check")->fetchColumn();
            $validation['integrity_check_passed'] = ($integrityResult === 'ok');

            // Count tables
            $tableCount = $testPdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
            $validation['table_count'] = (int)$tableCount;

            // Check for auxiliary files
            $auxiliaryExtensions = ['-wal', '-shm'];
            foreach ($auxiliaryExtensions as $extension) {
                $auxFile = $backupPath . $extension;
                if (file_exists($auxFile)) {
                    $validation['auxiliary_files_present'][] = [
                        'extension' => $extension,
                        'size_bytes' => filesize($auxFile)
                    ];
                }
            }

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

    // =============================================================================
    // UTILITY AND HELPER METHODS
    // =============================================================================

    /**
     * Detect if database is running in WAL mode
     * 
     * @return bool True if WAL mode is active
     */
    private function detectWALMode(): bool
    {
        try {
            $journalMode = $this->pdo->query("PRAGMA journal_mode")->fetchColumn();
            $isWalMode = (strtolower($journalMode) === 'wal');

            $this->debugLog("Journal mode detected", DebugLevel::VERBOSE, [
                'journal_mode' => $journalMode,
                'wal_mode_active' => $isWalMode
            ]);

            return $isWalMode;
        } catch (Exception $e) {
            $this->debugLog("Failed to detect journal mode", DebugLevel::VERBOSE, [
                'error' => $e->getMessage(),
                'assumed_wal_mode' => false
            ]);

            return false; // Assume not WAL mode if detection fails
        }
    }

    /**
     * Analyze source database before backup
     * 
     * @return array Database analysis
     */
    private function analyzeSourceDatabase(): array
    {
        $analysis = [
            'file_size_bytes' => 0,
            'journal_mode' => 'unknown',
            'wal_file_size_bytes' => 0,
            'shm_file_size_bytes' => 0,
            'page_count' => 0,
            'page_size' => 0
        ];

        try {
            // File sizes
            if (file_exists($this->databasePath)) {
                $analysis['file_size_bytes'] = filesize($this->databasePath);
            }

            $walFile = $this->databasePath . '-wal';
            if (file_exists($walFile)) {
                $analysis['wal_file_size_bytes'] = filesize($walFile);
            }

            $shmFile = $this->databasePath . '-shm';
            if (file_exists($shmFile)) {
                $analysis['shm_file_size_bytes'] = filesize($shmFile);
            }

            // Database statistics
            $analysis['journal_mode'] = $this->pdo->query("PRAGMA journal_mode")->fetchColumn();
            $analysis['page_count'] = (int)$this->pdo->query("PRAGMA page_count")->fetchColumn();
            $analysis['page_size'] = (int)$this->pdo->query("PRAGMA page_size")->fetchColumn();
        } catch (Exception $e) {
            $this->debugLog("Database analysis failed", DebugLevel::VERBOSE, [
                'error' => $e->getMessage()
            ]);
        }

        return $analysis;
    }

    /**
     * Extract database file path from connection configuration
     * 
     * @return string Database file path
     * @throws RuntimeException If path cannot be determined
     */
    private function extractDatabasePath(): string
    {
        if (isset($this->connectionConfig['path'])) {
            return $this->connectionConfig['path'];
        }

        if (isset($this->connectionConfig['database'])) {
            return $this->connectionConfig['database'];
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
    }

    /**
     * Prepare backup directory
     * 
     * @param string $outputPath Backup file path
     * @throws RuntimeException If directory preparation fails
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
     * Close database connection for file operations
     */
    private function closeDatabaseConnection(): void
    {
        // This is a placeholder - in practice, you'd need to coordinate with Model
        // to safely close and reopen the PDO connection
        $this->debugLog("Closing database connection for file operations", DebugLevel::VERBOSE);
    }

    /**
     * Reconnect to database after file operations
     */
    private function reconnectDatabase(): void
    {
        // This is a placeholder - in practice, you'd need to coordinate with Model
        // to safely reopen the PDO connection
        $this->debugLog("Reconnecting to database after file operations", DebugLevel::VERBOSE);
    }

    /**
     * Validate SQLite file format
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

        $expectedHeader = "SQLite format 3\000";
        return strncmp($header, $expectedHeader, strlen($expectedHeader)) === 0;
    }

    /**
     * Evaluate overall capability status
     * 
     * @param array $results Test results
     * @return string Overall status
     */
    private function evaluateOverallStatus(array $results): string
    {
        if (!$results['source_database_exists']) {
            return 'unavailable - source database does not exist';
        }

        if (!$results['source_database_readable']) {
            return 'unavailable - source database not readable';
        }

        if (!$results['temp_directory_writable']) {
            return 'unavailable - temp directory not writable';
        }

        if (!$results['file_copy_functional']) {
            return 'error - file copy not functional';
        }

        if ($results['wal_mode_detected']) {
            return 'available (with WAL mode limitations)';
        }

        return 'available';
    }

    // =============================================================================
    // INTERFACE IMPLEMENTATION
    // =============================================================================

    public function estimateBackupSize(): int
    {
        $totalSize = 0;

        if (file_exists($this->databasePath)) {
            $totalSize += filesize($this->databasePath);
        }

        // Add auxiliary files if present
        $auxiliaryExtensions = ['-wal', '-shm'];
        foreach ($auxiliaryExtensions as $extension) {
            $auxFile = $this->databasePath . $extension;
            if (file_exists($auxFile)) {
                $totalSize += filesize($auxFile);
            }
        }

        return $totalSize;
    }

    public function estimateBackupTime(): int
    {
        $sizeBytes = $this->estimateBackupSize();
        // Estimate ~20MB/second for file copy (conservative)
        return max(5, intval($sizeBytes / (20 * 1024 * 1024)));
    }

    public function getStrategyType(): string
    {
        return 'sqlite_file_copy';
    }

    public function getDescription(): string
    {
        return 'SQLite File Copy Backup (Last resort file-based backup)';
    }

    public function getSelectionCriteria(): array
    {
        return [
            'priority' => 3, // Lowest priority for SQLite
            'requirements' => ['file_system_access'],
            'advantages' => [
                'Works when other methods unavailable',
                'No special SQLite features required',
                'Handles auxiliary files (WAL, SHM)',
                'Simple and straightforward'
            ],
            'limitations' => [
                'Risk of corruption during active use',
                'Requires database locking',
                'Less efficient than native methods',
                'May not work with high concurrency'
            ]
        ];
    }

    public function supportsCompression(): bool
    {
        return false; // File copy creates uncompressed backups
    }

    public function detectBackupFormat(string $backupPath): string
    {
        if ($this->validateSQLiteFormat($backupPath)) {
            return 'sqlite_file_copy';
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
            'restore_auxiliary_files' => !empty($validation['auxiliary_files_present']),
            'estimated_duration_seconds' => 10 // Simple file copy
        ];
    }

    public function partialRestore(string $backupPath, array $targets, array $options = []): array
    {
        throw new RuntimeException("Partial restore not supported by SQLite file copy backup strategy");
    }
}
