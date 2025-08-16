<?php

/**
 * Enhanced Database Performance Management with Load-Aware Chunking
 * 
 * Improvements made:
 * 1. Integrated sys_getloadavg() for real-time load awareness
 * 2. Enhanced SQLite variable limit handling with auto-fallback
 * 3. Added system resource monitoring for optimal chunk sizing
 * 4. Improved error handling and fallback mechanisms
 * 
 * @package Database\Performance
 * @author Enhanced Model System - Load-Aware Edition
 * @version 3.1.0
 */
class DatabasePerformance
{
    private Model $model;
    private PDO $pdo;
    private string $dbType;
    private bool $debug = false;

    // Simple state tracking
    private bool $performanceMode = false;
    private bool $bulkModeActive = false;

    /**
     * Flag to skip auto-bulk detection for current operation
     * @var bool
     */
    private bool $skipAutoBulkForCurrentOp = false;

    // Fixed thresholds 
    private int $bulkThreshold = 50;      // Records to trigger bulk mode
    private bool $autoOptimize = true;     // Auto-enable bulk optimizations

    // Simple metrics (session only)
    private int $sessionOperations = 0;
    private float $sessionStartTime;

    // NEW: Load monitoring and SQLite fallback caching
    private static ?array $systemLoadCache = null;
    private static float $lastLoadCheck = 0;
    private static int $sqliteVariableLimitCache = 0;
    private static bool $sqliteVariableLimitTested = false;
    private static array $loadAdjustmentHistory = [];

    /**
     * Initialize simple performance management
     */
    public function __construct(Model $model, array $options = [])
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->dbType = $model->getDbType();
        $this->sessionStartTime = microtime(true);

        // Apply options
        if (isset($options['debug'])) {
            $this->debug = $options['debug'];
        }

        if (isset($options['bulkThreshold'])) {
            $this->bulkThreshold = max(1, $options['bulkThreshold']);
        }

        if (isset($options['autoOptimize'])) {
            $this->autoOptimize = $options['autoOptimize'];
        }

        $this->model->debugLog("Load-aware performance system initialized", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
            'bulk_threshold' => $this->bulkThreshold,
            'auto_optimize' => $this->autoOptimize
        ]);
    }

    /**
     * Simple bulk detection - just based on record count
     */
    public function detectBulkOperation(int $recordCount): bool
    {
        if ($this->shouldSkipAutoBulkDetection()) {
            return false;
        }
        
        $this->sessionOperations++;

        $shouldEnableBulk = $recordCount >= $this->bulkThreshold;

        if ($shouldEnableBulk && !$this->bulkModeActive && $this->autoOptimize) {
            $this->model->debugLog("Bulk mode enabled", DebugCategory::BULK, DebugLevel::BASIC, [
                'record_count' => $recordCount,
                'threshold' => $this->bulkThreshold,
                'trigger_reason' => 'record_count_threshold'
            ]);
            $this->enableBulkOptimizations();
            $this->bulkModeActive = true;
        }

        return $shouldEnableBulk;
    }

    /**
     * Disable auto-bulk detection for the current operation only
     * 
     * Used by expression methods to prevent automatic conversion to batch operations
     * which would be incompatible with heterogeneous expressions.
     * 
     * @return void
     */
    public function disableAutoBulkForCurrentOperation(): void
    {
        $this->skipAutoBulkForCurrentOp = true;
    }
    
    /**
     * Check if auto-bulk detection should be skipped for current operation
     * 
     * @return bool True if auto-bulk should be skipped
     */
    public function shouldSkipAutoBulkDetection(): bool
    {
        return $this->skipAutoBulkForCurrentOp;
    }
    
    /**
     * Reset the skip flag after operation completes
     * 
     * @return void
     */
    public function resetAutoBulkSkipFlag(): void
    {
        $this->skipAutoBulkForCurrentOp = false;
    }

    /**
     * ENHANCED: Calculate optimal chunk size with real-time load awareness and robust SQLite handling
     */
    public function calculateOptimalChunkSize(array $sampleRecord): int
    {
        $fieldCount = count($sampleRecord);
        $recordSize = strlen(serialize($sampleRecord));

        // NEW: Get system load factor for dynamic adjustment
        $loadFactor = $this->getSystemLoadFactor();

        // Memory-based calculation with load awareness
        $availableMemory = $this->model->getMemoryLimitInBytes() - memory_get_usage(true);
        $memoryBuffer = min($availableMemory * 0.1, 104857600); // Max 100MB buffer
        $memoryBasedChunkSize = max(50, intval($memoryBuffer / ($recordSize * 3)));

        // Apply load-based adjustment to memory calculation
        $loadAdjustedMemorySize = intval($memoryBasedChunkSize * $loadFactor);

        // Database-specific limits with enhanced SQLite handling
        $databaseLimitChunkSize = match ($this->dbType) {
            'sqlite' => $this->calculateSQLiteChunkSizeWithFallback($fieldCount),
            'mysql' => min(1000, $loadAdjustedMemorySize),
            'postgresql' => min(1000, $loadAdjustedMemorySize),
            default => 500
        };

        // Combine all factors
        $optimalSize = min($loadAdjustedMemorySize, $databaseLimitChunkSize);
        $optimalSize = max(50, min(2000, $optimalSize));

        // NEW: Track load adjustment history for learning
        self::$loadAdjustmentHistory[] = [
            'timestamp' => time(),
            'load_factor' => $loadFactor,
            'memory_based' => $memoryBasedChunkSize,
            'load_adjusted' => $loadAdjustedMemorySize,
            'final_size' => $optimalSize
        ];

        // Keep only last 10 entries to prevent memory bloat
        if (count(self::$loadAdjustmentHistory) > 10) {
            self::$loadAdjustmentHistory = array_slice(self::$loadAdjustmentHistory, -10);
        }

        $this->model->debugLog("Optimal chunk size calculated", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
            'chunk_size' => $optimalSize,
            'field_count' => $fieldCount,
            'memory_based_size' => $memoryBasedChunkSize,
            'load_factor' => $loadFactor,
            'load_adjusted_size' => $loadAdjustedMemorySize,
            'db_limit_size' => $databaseLimitChunkSize
        ]);

        return $optimalSize;
    }

    /**
     * NEW: Get system load factor for dynamic chunk size adjustment
     * 
     * Returns a multiplier between 0.3 and 1.0 based on system load:
     * - Low load (< 1.0): Factor closer to 1.0 (larger chunks)
     * - High load (> 2.0): Factor closer to 0.3 (smaller chunks)
     * - No load data: Factor of 0.8 (conservative default)
     */
    private function getSystemLoadFactor(): float
    {
        // Check if we have recent cached load data (cache for 5 seconds)
        $now = microtime(true);
        if (self::$systemLoadCache !== null && ($now - self::$lastLoadCheck) < 5.0) {
            return self::$systemLoadCache['factor'];
        }

        // Try to get system load if function exists (Unix systems only)
        if (function_exists('sys_getloadavg')) {
            try {
                $loadAvg = sys_getloadavg();
                if ($loadAvg !== false && is_array($loadAvg) && count($loadAvg) >= 1) {
                    $oneMinuteLoad = $loadAvg[0]; // 1-minute load average

                    // Calculate load factor with smooth scaling
                    // Low load (0.0-1.0): Factor 0.9-1.0 (allow larger chunks)
                    // Medium load (1.0-2.0): Factor 0.6-0.9 (moderate chunks)
                    // High load (2.0+): Factor 0.3-0.6 (smaller chunks)

                    if ($oneMinuteLoad <= 1.0) {
                        // Low load: scale from 0.9 to 1.0
                        $factor = 0.9 + ($oneMinuteLoad * 0.1);
                    } elseif ($oneMinuteLoad <= 2.0) {
                        // Medium load: scale from 0.6 to 0.9
                        $factor = 0.6 + ((2.0 - $oneMinuteLoad) * 0.3);
                    } else {
                        // High load: scale from 0.3 to 0.6, max at load 4.0
                        $loadRatio = min(($oneMinuteLoad - 2.0) / 2.0, 1.0);
                        $factor = 0.6 - ($loadRatio * 0.3);
                    }

                    $factor = max(0.3, min(1.0, $factor)); // Ensure bounds

                    // Cache the result
                    self::$systemLoadCache = [
                        'load' => $oneMinuteLoad,
                        'factor' => $factor,
                        'timestamp' => $now
                    ];
                    self::$lastLoadCheck = $now;

                    $this->model->debugLog("System load detected", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
                        'load_average' => $oneMinuteLoad,
                        'load_factor' => $factor,
                        'detection_method' => 'sys_getloadavg'
                    ]);

                    return $factor;
                }
            } catch (Exception $e) {
                // fallback silently if sys_getloadavg fails
            }
        }

        // Fallback: Use conservative factor for unknown load
        $defaultFactor = 0.8;
        self::$systemLoadCache = [
            'load' => null,
            'factor' => $defaultFactor,
            'timestamp' => $now
        ];
        self::$lastLoadCheck = $now;

        $this->model->debugLog("System load unknown, using conservative factor", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
            'default_factor' => $defaultFactor,
            'reason' => 'sys_getloadavg_unavailable'
        ]);

        return $defaultFactor;
    }

    /**
     * ENHANCED: Calculate SQLite-specific chunk size with robust fallback handling
     */
    private function calculateSQLiteChunkSizeWithFallback(int $fieldCount): int
    {
        if ($fieldCount <= 0) {
            return 500;
        }

        // Try to get SQLite variable limit with enhanced error handling
        $variableLimit = $this->getSQLiteVariableLimitWithFallback();
        $maxRecordsPerChunk = intval($variableLimit / $fieldCount);

        // 90% safety margin
        $safeChunkSize = intval($maxRecordsPerChunk * 0.9);

        // Additional safety check: ensure minimum viable chunk size
        $safeChunkSize = max(10, $safeChunkSize);

        // Additional safety check: prevent excessively large chunks even with few fields
        $safeChunkSize = min(1000, $safeChunkSize);

        $this->model->debugLog("SQLite chunk size calculated", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
            'field_count' => $fieldCount,
            'variable_limit' => $variableLimit,
            'safe_chunk_size' => $safeChunkSize,
            'database_type' => 'sqlite'
        ]);

        return $safeChunkSize;
    }

    /**
     * ENHANCED: Get SQLite variable limit with comprehensive fallback mechanisms
     */
    private function getSQLiteVariableLimitWithFallback(): int
    {
        // Use cached value if already determined
        if (self::$sqliteVariableLimitTested && self::$sqliteVariableLimitCache > 0) {
            return self::$sqliteVariableLimitCache;
        }

        // Mark as tested to prevent infinite recursion
        self::$sqliteVariableLimitTested = true;

        // Primary method: Try PRAGMA compile_options
        try {
            $stmt = $this->pdo->query("PRAGMA compile_options");
            if ($stmt !== false) {
                $options = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($options as $option) {
                    if (str_starts_with($option, 'MAX_VARIABLE_NUMBER=')) {
                        $limit = (int)substr($option, 20);
                        if ($limit > 0) {
                            self::$sqliteVariableLimitCache = $limit;

                            $this->model->debugLog("SQLite variable limit detected", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
                                'variable_limit' => $limit,
                                'detection_method' => 'PRAGMA_compile_options'
                            ]);

                            return $limit;
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $this->model->debugLog("SQLite PRAGMA detection failed", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
                'error' => $e->getMessage(),
                'fallback_method' => 'empirical_detection'
            ]);
        }

        // Fallback 1: Try to detect limit empirically by testing a query
        $empiricalLimit = $this->detectSQLiteVariableLimitEmpirically();
        if ($empiricalLimit > 0) {
            self::$sqliteVariableLimitCache = $empiricalLimit;

            $this->model->debugLog("SQLite variable limit detected", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
                'variable_limit' => $empiricalLimit,
                'detection_method' => 'empirical_testing'
            ]);

            return $empiricalLimit;
        }

        // Fallback 2: Use SQLite version-based defaults
        $versionBasedLimit = $this->getSQLiteVariableLimitByVersion();
        if ($versionBasedLimit > 0) {
            self::$sqliteVariableLimitCache = $versionBasedLimit;

            $this->model->debugLog("SQLite variable limit detected", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
                'variable_limit' => $versionBasedLimit,
                'detection_method' => 'version_based_default'
            ]);

            return $versionBasedLimit;
        }

        // Final fallback: Conservative default
        $defaultLimit = 500; // Very conservative
        self::$sqliteVariableLimitCache = $defaultLimit;

        $this->model->debugLog("SQLite variable limit using fallback", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
            'variable_limit' => $defaultLimit,
            'detection_method' => 'conservative_fallback',
            'reason' => 'all_detection_methods_failed'
        ]);

        return $defaultLimit;
    }

    /**
     * NEW: Detect SQLite variable limit empirically by testing queries
     */
    private function detectSQLiteVariableLimitEmpirically(): int
    {
        try {
            // Test with a simple query that uses many parameters
            // We'll test common limits: 999, 32766, 999999
            $testLimits = [999, 32766, 999999];

            foreach ($testLimits as $testLimit) {
                // Create a test query with exactly this many parameters
                $placeholders = str_repeat('?,', $testLimit);
                $placeholders = rtrim($placeholders, ',');

                $sql = "SELECT 1 WHERE 1 IN ($placeholders)";

                try {
                    $stmt = $this->pdo->prepare($sql);
                    if ($stmt !== false) {
                        return $testLimit;
                    }
                } catch (PDOException $e) {
                    // Continue to next lower limit
                    continue;
                }
            }
        } catch (Exception $e) {
        }

        return 0; // Failed to detect
    }

    /**
     * NEW: Get SQLite variable limit based on version information
     */
    private function getSQLiteVariableLimitByVersion(): int
    {
        try {
            // Get SQLite version
            $versionQuery = $this->pdo->query("SELECT sqlite_version()");
            if ($versionQuery !== false) {
                $version = $versionQuery->fetchColumn();

                if ($version) {
                    // Parse version string (e.g., "3.40.1")
                    $versionParts = explode('.', $version);
                    $majorVersion = (int)($versionParts[0] ?? 0);
                    $minorVersion = (int)($versionParts[1] ?? 0);

                    $this->model->debugLog("SQLite version detected", DebugCategory::PERFORMANCE, DebugLevel::VERBOSE, [
                        'sqlite_version' => $version,
                        'major_version' => $majorVersion,
                        'minor_version' => $minorVersion
                    ]);

                    // Version-based defaults (based on SQLite documentation)
                    if ($majorVersion >= 3) {
                        if ($minorVersion >= 32) {
                            return 32766; // SQLite 3.32+ increased default limit
                        } elseif ($minorVersion >= 15) {
                            return 999;   // SQLite 3.15+ standard limit
                        } else {
                            return 500;   // Older versions, be conservative
                        }
                    }
                }
            }
        } catch (PDOException $e) {
        }

        return 0; // Failed to detect
    }

    /**
     * Enable database-specific bulk optimizations
     */
    public function enableBulkOptimizations(): void
    {
        $inTxn = $this->pdo->inTransaction();
        $this->model->debugLog("Bulk optimizations enabled", DebugCategory::BULK, DebugLevel::BASIC, [
            'transaction_active' => $inTxn,
            'database_type' => $this->model->getDbType(),
            'optimization_mode' => 'bulk_performance'
        ]);

        try {
            switch ($this->dbType) {
                case 'mysql':
                    // Disable FK and unique checks, batch in one transaction
                    $this->pdo->exec("SET SESSION foreign_key_checks = 0");
                    $this->pdo->exec("SET SESSION unique_checks = 0");
                    if (!$inTxn) {
                        $this->pdo->exec("SET SESSION autocommit = 0");
                    }
                    break;

                case 'sqlite':
                    // Turn off journaling sync, keep WAL mode
                    if ($inTxn) {
                        // Safe inside txn
                        $this->pdo->exec('PRAGMA cache_size = -100000');   // 100MB
                        $this->pdo->exec('PRAGMA temp_store = MEMORY');
                    } else {
                        // Must be set outside txn
                        $this->pdo->exec('PRAGMA foreign_keys = OFF');
                        $this->pdo->exec('PRAGMA synchronous = OFF');
                        $this->pdo->exec('PRAGMA journal_mode = MEMORY');
                        $this->pdo->exec('PRAGMA cache_size = -100000');
                        $this->pdo->exec('PRAGMA temp_store = MEMORY');
                    }
                    break;

                case 'postgresql':
                    // Fast async commit
                    $this->pdo->exec("SET SESSION synchronous_commit = OFF");
                    // Disable all triggers including FK checks
                    $this->pdo->exec("SET session_replication_role TO replica");
                    break;

                default:
                    // conservative default for other DBs
                    break;
            }
        } catch (PDOException $e) {
            $this->model->debugLog("Bulk optimization warning", DebugCategory::BULK, DebugLevel::DETAILED, [
                'error' => $e->getMessage(),
                'operation' => 'enable_bulk_optimizations'
            ]);
        }
    }

    /**
     * Disable database-specific bulk optimizations
     */
    public function disableBulkOptimizations(): void
    {
        if (!$this->bulkModeActive) {
            return;
        }
        $inTxn = $this->pdo->inTransaction();
        $this->model->debugLog("Bulk optimizations disabled", DebugCategory::BULK, DebugLevel::BASIC, [
            'transaction_active' => $inTxn,
            'database_type' => $this->model->getDbType(),
            'optimization_mode' => 'normal_performance'
        ]);

        try {
            switch ($this->dbType) {
                case 'mysql':
                    $this->pdo->exec("SET SESSION foreign_key_checks = 1");
                    $this->pdo->exec("SET SESSION unique_checks = 1");
                    if (!$inTxn) {
                        $this->pdo->exec("SET SESSION autocommit = 1");
                    }
                    break;

                case 'sqlite':
                    if ($inTxn) {
                        // only safe resets inside txn
                        $this->pdo->exec('PRAGMA cache_size = -20000');   // 20MB
                        $this->pdo->exec('PRAGMA temp_store = DEFAULT');
                    } else {
                        // full restore outside txn
                        $this->pdo->exec('PRAGMA foreign_keys = ON');
                        $this->pdo->exec('PRAGMA synchronous = NORMAL');
                        $this->pdo->exec('PRAGMA journal_mode = WAL');
                        $this->pdo->exec('PRAGMA cache_size = -20000');
                        $this->pdo->exec('PRAGMA temp_store = DEFAULT');
                        $this->pdo->exec('PRAGMA optimize');
                    }
                    break;

                case 'postgresql':
                    // Restore async commit
                    $this->pdo->exec("SET SESSION synchronous_commit = ON");
                    // Re-enable triggers
                    $this->pdo->exec("SET session_replication_role TO DEFAULT");
                    break;
            }
        } catch (PDOException $e) {
            $this->model->debugLog("Bulk optimization cleanup warning", DebugCategory::BULK, DebugLevel::DETAILED, [
                'error' => $e->getMessage(),
                'operation' => 'disable_bulk_optimizations'
            ]);
        }

        $this->bulkModeActive  = false;
        //$this->operationCount  = 0;
        if ($this->debug && $this->dbType === 'postgresql') {
            // Optionally rebuild stats for PostgreSQL
            $this->pdo->exec("ANALYZE");
        }
    }

    // =============================================================================
    // SIMPLE API METHODS
    // =============================================================================

    /**
     * Enable performance mode (manual)
     */
    public function enablePerformanceMode(): void
    {
        $this->performanceMode = true;
        if ($this->autoOptimize) {
            $this->enableBulkOptimizations();
            $this->bulkModeActive = true;
        }

        $this->model->debugLog("Performance mode enabled", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
            'auto_optimize' => $this->autoOptimize,
            'bulk_mode_active' => $this->bulkModeActive
        ]);
    }

    /**
     * Disable performance mode
     */
    public function disablePerformanceMode(): void
    {
        $this->performanceMode = false;
        if ($this->bulkModeActive) {
            $this->disableBulkOptimizations();
        }

        $this->model->debugLog("Performance mode disabled", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
            'bulk_mode_active' => $this->bulkModeActive
        ]);
    }

    /**
     * Set bulk threshold
     */
    public function setBulkThreshold(int $threshold): void
    {
        $this->bulkThreshold = max(1, $threshold);

        $this->model->debugLog("Bulk threshold updated", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
            'new_threshold' => $this->bulkThreshold
        ]);
    }

    /**
     * ENHANCED: Get performance statistics with load monitoring data
     */
    public function getPerformanceStats(): array
    {
        $stats = [
            'bulk_mode_active' => $this->bulkModeActive,
            'performance_mode_active' => $this->performanceMode,
            'bulk_threshold' => $this->bulkThreshold,
            'session_operations' => $this->sessionOperations,
            'session_duration' => microtime(true) - $this->sessionStartTime,
            'database_type' => $this->dbType,
            'auto_optimize' => $this->autoOptimize,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => $this->model->getMemoryLimitInBytes()
        ];

        // NEW: Add load monitoring data
        if (self::$systemLoadCache !== null) {
            $stats['system_load'] = [
                'current_load' => self::$systemLoadCache['load'],
                'load_factor' => self::$systemLoadCache['factor'],
                'last_check' => self::$systemLoadCache['timestamp'],
                'load_available' => function_exists('sys_getloadavg')
            ];
        }

        // NEW: Add SQLite variable limit info
        if ($this->dbType === 'sqlite' && self::$sqliteVariableLimitTested) {
            $stats['sqlite_info'] = [
                'variable_limit' => self::$sqliteVariableLimitCache,
                'limit_detection_method' => self::$sqliteVariableLimitCache === 500 ? 'fallback' : 'detected'
            ];
        }

        // NEW: Add load adjustment history
        if (!empty(self::$loadAdjustmentHistory)) {
            $stats['load_adjustment_history'] = array_slice(self::$loadAdjustmentHistory, -5); // Last 5 adjustments
        }

        return $stats;
    }

    /**
     * Check if bulk mode is active
     */
    public function isBulkModeActive(): bool
    {
        return $this->bulkModeActive;
    }

    /**
     * Check if performance mode is active
     */
    public function isPerformanceModeActive(): bool
    {
        return $this->performanceMode;
    }

    /**
     * Force bulk mode off
     */
    public function forceBulkModeOff(): void
    {
        if ($this->bulkModeActive) {
            $this->disableBulkOptimizations();
        }
    }

    /**
     * Set debug mode
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    // =============================================================================
    // UTILITY METHODS
    // =============================================================================

    /**
     * Get memory limit in bytes
     */
    /*
    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int)substr($memoryLimit, 0, -1);

        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value
        };
    }
        */

    /**
     * Cleanup on destruction
     */
    public function __destruct()
    {
        if ($this->bulkModeActive) {
            $this->disableBulkOptimizations();
        }
    }
}
