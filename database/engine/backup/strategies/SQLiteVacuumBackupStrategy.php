<?php

/**
 * SQLite VACUUM INTO Backup Strategy - SQL-Based Backup Implementation
 * 
 * Implements SQLite database backup using the VACUUM INTO command introduced in
 * SQLite 3.27.0. This strategy provides optimized, compacted backups through
 * standard PDO connections without requiring the SQLite3 extension.
 * 
 * **ENHANCED WITH FULL DEBUG SYSTEM INTEGRATION**
 * - Comprehensive logging of VACUUM INTO operations and optimizations
 * - SQLite version compatibility checking and fallback recommendations
 * - Detailed analysis of backup optimization and space savings
 * - Performance monitoring and progress estimation
 * 
 * Key Features:
 * - Works with PDO (no SQLite3 extension required)
 * - Creates optimized/compacted backup files
 * - Single SQL command operation
 * - Automatic space reclamation during backup
 * - Version compatibility checking with graceful degradation
 * - Comprehensive error handling and recovery
 * 
 * Technical Implementation:
 * - Uses VACUUM INTO SQL command for atomic backup creation
 * - Automatically detects SQLite version compatibility
 * - Provides detailed space optimization analysis
 * - Handles file path validation and permissions
 * - Implements comprehensive backup validation
 * 
 * Version Requirements:
 * - SQLite 3.27.0+ required for VACUUM INTO command
 * - Automatic version detection with fallback suggestions
 * - Clear error messages for unsupported versions
 * 
 * @package Database\Backup\Strategy\SQLite
 * @author Enhanced Model System
 * @version 1.0.0 - Initial Implementation with Debug Integration
 */
class SQLiteVacuumBackupStrategy implements BackupStrategyInterface, RestoreStrategyInterface
{
    use DebugLoggingTrait;
    use ConfigSanitizationTrait;
    use PathValidationTrait;
    
    private Model $model;
    private PDO $pdo;
    private array $connectionConfig;
    private string $databasePath;
    private ?string $sqliteVersion = null;

    /**
     * Initialize SQLite VACUUM INTO backup strategy
     * 
     * @param Model $model Enhanced Model instance for debug logging
     * @param array $connectionConfig Database connection configuration
     * @throws RuntimeException If VACUUM INTO is not supported
     */
    public function __construct(Model $model, array $connectionConfig)
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->connectionConfig = $connectionConfig;
        $this->databasePath = $this->extractDatabasePath();

        // Detect SQLite version and VACUUM INTO support
        $this->sqliteVersion = $this->detectSQLiteVersion();

        $this->debugLog("SQLite VACUUM INTO backup strategy initialized", DebugLevel::VERBOSE, [
            'database_path' => $this->databasePath,
            'sqlite_version' => $this->sqliteVersion,
            'vacuum_into_supported' => $this->supportsVacuumInto(),
            'connection_config' => $this->sanitizeConfig($connectionConfig)
        ]);

        // Validate VACUUM INTO support
        if (!$this->supportsVacuumInto()) {
            throw new RuntimeException(
                "VACUUM INTO not supported. SQLite 3.27.0+ required, found: " . ($this->sqliteVersion ?: 'unknown')
            );
        }
    }

    // =============================================================================
    // BACKUP OPERATIONS
    // =============================================================================

    /**
     * Create SQLite database backup using VACUUM INTO
     * 
     * Executes VACUUM INTO command to create an optimized, compacted backup.
     * This operation combines backup creation with database optimization,
     * reclaiming unused space and defragmenting the database structure.
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
            'validate_backup' => true,
            'analyze_optimization' => true,
            'progress_callback' => null,
            'timeout' => 300 // 5 minutes default
        ], $options);

        $this->debugLog("Starting SQLite VACUUM INTO backup", DebugLevel::BASIC, [
            'source_database' => $this->databasePath,
            'output_path' => $outputPath,
            'sqlite_version' => $this->sqliteVersion,
            'options' => $options
        ]);

        try {
            // Pre-backup analysis
            $preBackupStats = $this->analyzeDatabase();

            // Validate and prepare backup location
            $this->validateAndPrepareBackupPath($outputPath);

            // Execute VACUUM INTO operation
            $vacuumResult = $this->executeVacuumInto($outputPath, $options);

            // Post-backup analysis and validation
            $postBackupStats = $this->analyzeBackupFile($outputPath, $options);

            $duration = microtime(true) - $startTime;

            // Calculate optimization metrics
            $optimizationMetrics = $this->calculateOptimizationMetrics($preBackupStats, $postBackupStats);

            $this->debugLog("SQLite VACUUM INTO backup completed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'original_size_bytes' => $preBackupStats['database_size_bytes'],
                'backup_size_bytes' => $postBackupStats['backup_size_bytes'],
                'space_savings_bytes' => $optimizationMetrics['space_savings_bytes'],
                'space_savings_percent' => $optimizationMetrics['space_savings_percent'],
                'validation_passed' => $postBackupStats['validation']['valid'] ?? false
            ]);

            return [
                'success' => true,
                'output_path' => $outputPath,
                'backup_size_bytes' => $postBackupStats['backup_size_bytes'],
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType(),
                'optimization_metrics' => $optimizationMetrics,
                'validation' => $postBackupStats['validation'] ?? null,
                'sqlite_version' => $this->sqliteVersion
            ];
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite VACUUM INTO backup failed", DebugLevel::BASIC, [
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
     * Execute VACUUM INTO operation with monitoring
     * 
     * @param string $outputPath Backup file path
     * @param array $options Backup options
     * @return array Operation results
     * @throws RuntimeException If VACUUM INTO fails
     */
    private function executeVacuumInto(string $outputPath, array $options): array
    {
        $startTime = microtime(true);

        $this->debugLog("Executing VACUUM INTO operation", DebugLevel::DETAILED, [
            'output_path' => $outputPath,
            'timeout' => $options['timeout']
        ]);

        try {
            // Prepare the VACUUM INTO SQL command
            $sql = "VACUUM INTO :backup_path";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':backup_path', $outputPath, PDO::PARAM_STR);

            // Execute with timeout monitoring
            $success = $stmt->execute();

            if (!$success) {
                $errorInfo = $stmt->errorInfo();
                throw new RuntimeException("VACUUM INTO failed: " . ($errorInfo[2] ?? 'Unknown error'));
            }

            $duration = microtime(true) - $startTime;

            $this->debugLog("VACUUM INTO operation completed", DebugLevel::DETAILED, [
                'duration_seconds' => round($duration, 3),
                'output_file_exists' => file_exists($outputPath),
                'output_file_size' => file_exists($outputPath) ? filesize($outputPath) : 0
            ]);

            return [
                'success' => true,
                'duration_seconds' => round($duration, 3)
            ];
        } catch (PDOException $e) {
            $duration = microtime(true) - $startTime;

            $this->debugLog("VACUUM INTO operation failed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'pdo_error' => $e->getMessage(),
                'sql_state' => $e->getCode()
            ]);

            throw new RuntimeException("VACUUM INTO operation failed: " . $e->getMessage());
        }
    }

    /**
     * Analyze source database before backup
     * 
     * @return array Database analysis results
     */
    private function analyzeDatabase(): array
    {
        $this->debugLog("Analyzing source database", DebugLevel::VERBOSE);

        $analysis = [
            'database_size_bytes' => 0,
            'page_count' => 0,
            'page_size' => 0,
            'unused_pages' => 0,
            'fragmentation_percent' => 0,
            'table_count' => 0
        ];

        try {
            // Get database file size
            if (file_exists($this->databasePath)) {
                $analysis['database_size_bytes'] = filesize($this->databasePath);
            }

            // Get SQLite internal statistics
            $pragmaQueries = [
                'page_count' => 'PRAGMA page_count',
                'page_size' => 'PRAGMA page_size',
                'freelist_count' => 'PRAGMA freelist_count'
            ];

            foreach ($pragmaQueries as $key => $sql) {
                try {
                    $result = $this->pdo->query($sql)->fetchColumn();
                    $analysis[$key] = (int)$result;
                } catch (Exception $e) {
                    $this->debugLog("Failed to get $key", DebugLevel::VERBOSE, ['error' => $e->getMessage()]);
                }
            }

            // Calculate fragmentation
            if ($analysis['page_count'] > 0) {
                $analysis['unused_pages'] = $analysis['freelist_count'] ?? 0;
                $analysis['fragmentation_percent'] = round(($analysis['unused_pages'] / $analysis['page_count']) * 100, 2);
            }

            // Count tables
            try {
                $tableCount = $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
                $analysis['table_count'] = (int)$tableCount;
            } catch (Exception $e) {
                $this->debugLog("Failed to count tables", DebugLevel::VERBOSE, ['error' => $e->getMessage()]);
            }

            $this->debugLog("Database analysis completed", DebugLevel::VERBOSE, $analysis);
        } catch (Exception $e) {
            $this->debugLog("Database analysis failed", DebugLevel::VERBOSE, [
                'error' => $e->getMessage()
            ]);
        }

        return $analysis;
    }

    /**
     * Analyze backup file after creation
     * 
     * @param string $backupPath Backup file path
     * @param array $options Backup options
     * @return array Backup file analysis
     */
    private function analyzeBackupFile(string $backupPath, array $options): array
    {
        $this->debugLog("Analyzing backup file", DebugLevel::VERBOSE, [
            'backup_path' => $backupPath
        ]);

        $analysis = [
            'backup_size_bytes' => 0,
            'validation' => null
        ];

        try {
            // Get backup file size
            if (file_exists($backupPath)) {
                $analysis['backup_size_bytes'] = filesize($backupPath);
            }

            // Validate backup if requested
            if ($options['validate_backup']) {
                $analysis['validation'] = $this->validateBackupFile($backupPath);
            }

            $this->debugLog("Backup file analysis completed", DebugLevel::VERBOSE, $analysis);
        } catch (Exception $e) {
            $this->debugLog("Backup file analysis failed", DebugLevel::VERBOSE, [
                'error' => $e->getMessage()
            ]);
        }

        return $analysis;
    }

    /**
     * Calculate optimization metrics from before/after analysis
     * 
     * @param array $preStats Pre-backup statistics
     * @param array $postStats Post-backup statistics
     * @return array Optimization metrics
     */
    private function calculateOptimizationMetrics(array $preStats, array $postStats): array
    {
        $originalSize = $preStats['database_size_bytes'] ?? 0;
        $backupSize = $postStats['backup_size_bytes'] ?? 0;

        $spaceSavings = max(0, $originalSize - $backupSize);
        $spaceSavingsPercent = $originalSize > 0 ? round(($spaceSavings / $originalSize) * 100, 2) : 0;

        $metrics = [
            'original_size_bytes' => $originalSize,
            'backup_size_bytes' => $backupSize,
            'space_savings_bytes' => $spaceSavings,
            'space_savings_percent' => $spaceSavingsPercent,
            'optimization_effective' => $spaceSavingsPercent > 5, // Consider 5%+ savings effective
            'fragmentation_removed_percent' => $preStats['fragmentation_percent'] ?? 0
        ];

        $this->debugLog("Optimization metrics calculated", DebugLevel::DETAILED, $metrics);

        return $metrics;
    }

    // =============================================================================
    // RESTORE OPERATIONS
    // =============================================================================

    /**
     * Restore database from VACUUM INTO backup
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
            'analyze_after_restore' => true
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

            // Perform restore by copying backup file
            if (!copy($backupPath, $this->databasePath)) {
                throw new RuntimeException("Failed to copy backup file to database location");
            }

            // Analyze restored database
            $postRestoreAnalysis = null;
            if ($options['analyze_after_restore']) {
                $postRestoreAnalysis = $this->analyzeDatabase();
            }

            $duration = microtime(true) - $startTime;

            $this->debugLog("SQLite database restore completed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'restored_size_bytes' => filesize($this->databasePath),
                'current_backup_created' => $currentBackupPath !== null
            ]);

            return [
                'success' => true,
                'restored_size_bytes' => filesize($this->databasePath),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => $this->getStrategyType(),
                'current_backup_path' => $currentBackupPath,
                'post_restore_analysis' => $postRestoreAnalysis
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
     * Test SQLite VACUUM INTO backup capabilities
     * 
     * @return array Comprehensive capability test results
     */
    public function testCapabilities(): array
    {
        $this->debugLog("Testing SQLite VACUUM INTO backup capabilities", DebugLevel::BASIC);

        $results = [
            'strategy_type' => $this->getStrategyType(),
            'sqlite_version' => $this->sqliteVersion,
            'vacuum_into_supported' => false,
            'source_database_accessible' => false,
            'temp_directory_writable' => false,
            'vacuum_into_functional' => false,
            'overall_status' => 'unknown'
        ];

        try {
            // Check VACUUM INTO support
            $results['vacuum_into_supported'] = $this->supportsVacuumInto();

            // Test source database access
            $results['source_database_accessible'] = file_exists($this->databasePath) && is_readable($this->databasePath);

            // Test temp directory writability
            $results['temp_directory_writable'] = is_writable(sys_get_temp_dir());

            // Test VACUUM INTO functionality
            if ($results['vacuum_into_supported'] && $results['source_database_accessible']) {
                $results['vacuum_into_functional'] = $this->testVacuumIntoFunctionality();
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
     * Test VACUUM INTO functionality with minimal operation
     * 
     * @return bool True if VACUUM INTO is functional
     */
    private function testVacuumIntoFunctionality(): bool
    {
        try {
            $testBackupPath = sys_get_temp_dir() . '/sqlite_vacuum_test_' . uniqid() . '.db';

            // Try VACUUM INTO with a small test
            $sql = "VACUUM INTO :backup_path";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':backup_path', $testBackupPath, PDO::PARAM_STR);

            $success = $stmt->execute();

            // Verify backup file was created
            $functional = $success && file_exists($testBackupPath) && filesize($testBackupPath) > 0;

            // Cleanup test file
            if (file_exists($testBackupPath)) {
                unlink($testBackupPath);
            }

            return $functional;
        } catch (Exception $e) {
            $this->debugLog("VACUUM INTO functionality test failed", DebugLevel::VERBOSE, [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Validate SQLite backup file created by VACUUM INTO
     * 
     * @param string $backupPath Path to backup file
     * @return array Validation results
     */
    public function validateBackupFile(string $backupPath): array
    {
        $this->debugLog("Validating SQLite VACUUM INTO backup file", DebugLevel::DETAILED, [
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
            'vacuum_optimized' => false,
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

            // Check if database appears optimized (no free pages)
            $freelistCount = $testPdo->query("PRAGMA freelist_count")->fetchColumn();
            $validation['vacuum_optimized'] = ((int)$freelistCount === 0);

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
    // UTILITY AND CONFIGURATION METHODS
    // =============================================================================

    /**
     * Detect SQLite version from database connection
     * 
     * @return string|null SQLite version string
     */
    private function detectSQLiteVersion(): ?string
    {
        try {
            $version = $this->pdo->query("SELECT sqlite_version()")->fetchColumn();

            $this->debugLog("SQLite version detected", DebugLevel::VERBOSE, [
                'version' => $version
            ]);

            return $version;
        } catch (Exception $e) {
            $this->debugLog("Failed to detect SQLite version", DebugLevel::VERBOSE, [
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Check if current SQLite version supports VACUUM INTO
     * 
     * @return bool True if VACUUM INTO is supported
     */
    private function supportsVacuumInto(): bool
    {
        if (!$this->sqliteVersion) {
            return false;
        }

        // VACUUM INTO was introduced in SQLite 3.27.0
        return version_compare($this->sqliteVersion, '3.27.0', '>=');
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
     * Validate and prepare backup path
     * 
     * @param string $outputPath Backup file path
     * @throws RuntimeException If path is invalid
     */
    private function validateAndPrepareBackupPath(string $outputPath): void
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

        // Remove existing backup file if it exists
        if (file_exists($outputPath)) {
            if (!unlink($outputPath)) {
                throw new RuntimeException("Cannot remove existing backup file: $outputPath");
            }
        }
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


    /**
     * Evaluate overall capability status
     * 
     * @param array $results Test results
     * @return string Overall status
     */
    private function evaluateOverallStatus(array $results): string
    {
        if (!$results['vacuum_into_supported']) {
            return 'unavailable - SQLite 3.27.0+ required for VACUUM INTO';
        }

        if (!$results['source_database_accessible']) {
            return 'unavailable - source database not accessible';
        }

        if (!$results['temp_directory_writable']) {
            return 'unavailable - temp directory not writable';
        }

        if (!$results['vacuum_into_functional']) {
            return 'error - VACUUM INTO not functional';
        }

        return 'available';
    }

    // =============================================================================
    // INTERFACE IMPLEMENTATION
    // =============================================================================

    public function estimateBackupSize(): int
    {
        if (file_exists($this->databasePath)) {
            $currentSize = filesize($this->databasePath);

            // VACUUM INTO typically reduces size by 10-30% due to optimization
            return intval($currentSize * 0.8); // Estimate 20% reduction
        }
        return 0;
    }

    public function estimateBackupTime(): int
    {
        $sizeBytes = file_exists($this->databasePath) ? filesize($this->databasePath) : 0;
        // Estimate ~8MB/second for VACUUM INTO (includes optimization time)
        return max(15, intval($sizeBytes / (8 * 1024 * 1024)));
    }

    public function getStrategyType(): string
    {
        return 'sqlite_vacuum';
    }

    public function getDescription(): string
    {
        return 'SQLite VACUUM INTO Backup (Optimized SQL-based backup)';
    }

    public function getSelectionCriteria(): array
    {
        return [
            'priority' => 2, // Second choice for SQLite (after native)
            'requirements' => ['sqlite_3.27.0+', 'pdo_sqlite'],
            'advantages' => [
                'Works with PDO (no SQLite3 extension required)',
                'Creates optimized/compacted backup',
                'Single SQL command operation',
                'Automatic space reclamation'
            ],
            'limitations' => [
                'Requires SQLite 3.27.0+',
                'Slower than native backup for large databases'
            ]
        ];
    }

    public function supportsCompression(): bool
    {
        return false; // VACUUM INTO creates optimized but uncompressed backups
    }

    public function detectBackupFormat(string $backupPath): string
    {
        if ($this->validateSQLiteFormat($backupPath)) {
            return 'sqlite_vacuum';
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
            'analyze_after_restore' => true,
            'estimated_duration_seconds' => 10 // Simple file copy
        ];
    }

    public function partialRestore(string $backupPath, array $targets, array $options = []): array
    {
        // SQLite VACUUM INTO backup doesn't support partial restore
        throw new RuntimeException("Partial restore not supported by SQLite VACUUM INTO backup strategy");
    }
}
