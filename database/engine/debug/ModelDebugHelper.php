<?php

/**
 * Model Debug Helper - Extracted Debug Logic from Model Class
 * 
 * This class contains debug helper methods that were previously cluttering
 * the main Model class. It provides utilities for debug analysis, strategy
 * explanations, and performance insights.
 */
class ModelDebugHelper
{
    /**
     * Get currently applied optimizations for debug reporting
     * 
     * @param Model $model Model instance
     * @return array List of applied optimizations
     */
    public static function getAppliedOptimizations(Model $model): array
    {
        $optimizations = [];

        if ($model->performance()->isBulkModeActive()) {
            $optimizations[] = 'bulk_mode_enabled';
        }

        if ($model->performance()->isPerformanceModeActive()) {
            $optimizations[] = 'performance_mode_enabled';
        }

        // Add database-specific optimizations
        $dbType = $model->getDbType();
        switch ($dbType) {
            case 'mysql':
                if (self::isMySQLOptimizationActive($model)) {
                    $optimizations[] = 'mysql_bulk_optimizations';
                }
                break;

            case 'sqlite':
                if (self::isSQLiteOptimizationActive($model)) {
                    $optimizations[] = 'sqlite_wal_optimizations';
                }
                break;

            case 'postgresql':
                if (self::isPostgreSQLOptimizationActive($model)) {
                    $optimizations[] = 'postgresql_async_commit';
                }
                break;
        }

        return $optimizations;
    }

    /**
     * Get reason for strategy selection in batch updates
     * 
     * @param string $table Table name
     * @param array $updates Update array
     * @param string $dbType Database type
     * @return string Reason for strategy choice
     */
    public static function getStrategyReason(string $table, array $updates, string $dbType): string
    {
        $recordCount = count($updates);
        $sampleUpdate = reset($updates);
        $columnCount = count($sampleUpdate['data'] ?? []);

        if ($recordCount < 300) {
            return "Small dataset ({$recordCount} records) - CASE statements more efficient";
        }

        if ($recordCount >= 2000) {
            return "Large dataset ({$recordCount} records) - temp table strategy scales better";
        }

        if ($columnCount >= 8) {
            return "Many columns ({$columnCount}) - temp table reduces query complexity";
        }

        if ($dbType === 'sqlite' && $recordCount >= 600) {
            return "SQLite with {$recordCount} records - temp tables are very fast";
        }

        return "Dataset analysis suggests this strategy for optimal performance";
    }

    /**
     * Detect query type from SQL
     * 
     * @param string $sql SQL query
     * @return string Query type
     */
    public static function detectQueryType(string $sql): string
    {
        $sql = trim(strtoupper($sql));

        if (str_starts_with($sql, 'SELECT')) return 'SELECT';
        if (str_starts_with($sql, 'INSERT')) return 'INSERT';
        if (str_starts_with($sql, 'UPDATE')) return 'UPDATE';
        if (str_starts_with($sql, 'DELETE')) return 'DELETE';
        if (str_starts_with($sql, 'CREATE')) return 'CREATE';
        if (str_starts_with($sql, 'DROP')) return 'DROP';
        if (str_starts_with($sql, 'ALTER')) return 'ALTER';

        return 'UNKNOWN';
    }

    /**
     * Get indexed columns from update operation (placeholder for schema awareness)
     * 
     * @param string $table Table name
     * @param array $columns Columns being updated
     * @return array Indexed columns (empty array for now, can be enhanced with SchemaAwareOptimizer)
     */
    public static function getIndexedColumnsFromUpdate(string $table, array $columns): array
    {
        // Placeholder - could integrate with SchemaAwareOptimizer if available
        if (class_exists('SchemaAwareOptimizer')) {
            // Could call SchemaAwareOptimizer methods here
            return [];
        }

        return []; // Simple placeholder
    }

    /**
     * Check if MySQL-specific optimizations are active
     * 
     * @param Model $model Model instance
     * @return bool Whether MySQL optimizations are active
     */
    private static function isMySQLOptimizationActive(Model $model): bool
    {
        try {
            $pdo = $model->getPDO();
            $stmt = $pdo->query("SELECT @@foreign_key_checks");
            return $stmt && $stmt->fetchColumn() == 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if SQLite-specific optimizations are active
     * 
     * @param Model $model Model instance
     * @return bool Whether SQLite optimizations are active
     */
    private static function isSQLiteOptimizationActive(Model $model): bool
    {
        try {
            $pdo = $model->getPDO();
            $stmt = $pdo->query("PRAGMA journal_mode");
            $mode = $stmt ? $stmt->fetchColumn() : '';
            return in_array(strtolower($mode), ['wal', 'memory']);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if PostgreSQL-specific optimizations are active
     * 
     * @param Model $model Model instance
     * @return bool Whether PostgreSQL optimizations are active
     */
    private static function isPostgreSQLOptimizationActive(Model $model): bool
    {
        try {
            $pdo = $model->getPDO();
            $stmt = $pdo->query("SHOW synchronous_commit");
            $value = $stmt ? $stmt->fetchColumn() : '';
            return strtolower($value) === 'off';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get category name from category constant
     * 
     * @param int $category Category constant
     * @return string Category name
     */
    public static function getCategoryName(int $category): string
    {
        return match ($category) {
            DebugCategory::SQL => 'SQL',
            DebugCategory::PERFORMANCE => 'PERFORMANCE',
            DebugCategory::BULK => 'BULK',
            DebugCategory::CACHE => 'CACHE',
            DebugCategory::TRANSACTION => 'TRANSACTION',
            DebugCategory::MAINTENANCE => 'MAINTENANCE',
            DebugCategory::SECURITY => 'SECURITY',
            default => 'DEBUG'
        };
    }

    /**
     * Analyze bulk operation performance and provide insights
     * 
     * @param string $operation Operation type
     * @param int $recordCount Number of records
     * @param float $executionTime Execution time in seconds
     * @param array $context Operation context
     * @return array Performance insights
     */
    public static function analyzeBulkPerformance(string $operation, int $recordCount, float $executionTime, array $context): array
    {
        $recordsPerSecond = $executionTime > 0 ? round($recordCount / $executionTime) : 0;

        $analysis = [
            'performance_tier' => self::classifyPerformance($recordsPerSecond),
            'records_per_second' => $recordsPerSecond,
            'insights' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        // Performance tier analysis
        switch ($analysis['performance_tier']) {
            case 'excellent':
                $analysis['insights'][] = "Excellent performance: {$recordsPerSecond} records/sec";
                break;
            case 'good':
                $analysis['insights'][] = "Good performance: {$recordsPerSecond} records/sec";
                break;
            case 'acceptable':
                $analysis['insights'][] = "Acceptable performance: {$recordsPerSecond} records/sec";
                $analysis['recommendations'][] = "Consider optimization for better performance";
                break;
            case 'poor':
                $analysis['warnings'][] = "Poor performance: {$recordsPerSecond} records/sec";
                $analysis['recommendations'][] = "Performance optimization strongly recommended";
                break;
            case 'very_poor':
                $analysis['warnings'][] = "Very poor performance: {$recordsPerSecond} records/sec";
                $analysis['recommendations'][] = "Immediate performance optimization required";
                break;
        }

        // Context-specific analysis
        if (isset($context['chunk_size'])) {
            $chunkSize = $context['chunk_size'];
            if ($chunkSize < 50) {
                $analysis['insights'][] = "Small chunk size ({$chunkSize}) - may be inefficient for large datasets";
            } elseif ($chunkSize > 2000) {
                $analysis['warnings'][] = "Large chunk size ({$chunkSize}) - may cause memory issues";
            }
        }

        if (isset($context['database_type'])) {
            $dbType = $context['database_type'];
            $analysis['insights'][] = "Database type: {$dbType}";

            // Database-specific recommendations
            switch ($dbType) {
                case 'sqlite':
                    if ($recordCount > 1000 && $recordsPerSecond < 1000) {
                        $analysis['recommendations'][] = "Consider WAL mode and smaller chunk sizes for SQLite";
                    }
                    break;
                case 'mysql':
                    if ($recordCount > 5000 && $recordsPerSecond < 2000) {
                        $analysis['recommendations'][] = "Consider disabling foreign key checks for large MySQL operations";
                    }
                    break;
                case 'postgresql':
                    if ($recordCount > 2000 && $recordsPerSecond < 1500) {
                        $analysis['recommendations'][] = "Consider synchronous_commit=off for PostgreSQL bulk operations";
                    }
                    break;
            }
        }

        return $analysis;
    }

    /**
     * Classify performance based on records per second
     * 
     * @param int $recordsPerSecond Records processed per second
     * @return string Performance classification
     */
    private static function classifyPerformance(int $recordsPerSecond): string
    {
        if ($recordsPerSecond >= 10000) {
            return 'excellent';
        } elseif ($recordsPerSecond >= 5000) {
            return 'good';
        } elseif ($recordsPerSecond >= 1000) {
            return 'acceptable';
        } elseif ($recordsPerSecond >= 500) {
            return 'poor';
        } else {
            return 'very_poor';
        }
    }

    /**
     * Generate maintenance operation insights
     * 
     * @param string $operation Maintenance operation
     * @param array $results Operation results
     * @return array Maintenance insights
     */
    public static function analyzeMaintenanceOperation(string $operation, array $results): array
    {
        $insights = [
            'operation' => $operation,
            'performance_impact' => 'low',
            'recommendations' => [],
            'warnings' => []
        ];

        switch ($operation) {
            case 'vacuum':
                if (isset($results['space_reclaimed_bytes']) && $results['space_reclaimed_bytes'] > 0) {
                    $spaceReclaimed = self::formatBytes($results['space_reclaimed_bytes']);
                    $insights['recommendations'][] = "Space reclaimed: {$spaceReclaimed}";
                } else {
                    $insights['recommendations'][] = "No significant space reclamation - database already optimized";
                }

                if (isset($results['duration_seconds']) && $results['duration_seconds'] > 60) {
                    $insights['warnings'][] = "Long vacuum operation - consider scheduling during low-activity periods";
                    $insights['performance_impact'] = 'high';
                }
                break;

            case 'analyze':
                if (isset($results['tables_processed'])) {
                    $insights['recommendations'][] = "Statistics updated for {$results['tables_processed']} tables";
                }
                $insights['recommendations'][] = "Query performance should improve with updated statistics";
                break;

            case 'reindex':
                if (isset($results['indexes_rebuilt'])) {
                    $indexCount = count($results['indexes_rebuilt']);
                    $insights['recommendations'][] = "Rebuilt {$indexCount} indexes";
                }

                if (isset($results['duration_seconds']) && $results['duration_seconds'] > 300) {
                    $insights['warnings'][] = "Long reindex operation - monitor query performance improvement";
                    $insights['performance_impact'] = 'high';
                }
                break;
        }

        return $insights;
    }

    /**
     * Format bytes to human-readable string
     * 
     * @param int $bytes Number of bytes
     * @return string Formatted size string
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Analyze transaction complexity and provide insights
     * 
     * @param array $transactionContext Transaction context
     * @return array Transaction insights
     */
    public static function analyzeTransactionComplexity(array $transactionContext): array
    {
        $insights = [
            'complexity' => 'simple',
            'recommendations' => [],
            'warnings' => []
        ];

        $level = $transactionContext['level'] ?? 0;
        $duration = $transactionContext['duration'] ?? 0;
        $operationCount = $transactionContext['operation_count'] ?? 0;

        // Analyze nesting complexity
        if ($level > 1) {
            $insights['complexity'] = 'nested';
            $insights['recommendations'][] = "Nested transaction at level {$level}";

            if ($level > 3) {
                $insights['warnings'][] = "Deep nesting (level {$level}) may indicate complex business logic";
                $insights['complexity'] = 'complex';
            }
        }

        // Analyze duration
        if ($duration > 5) {
            $insights['warnings'][] = "Long-running transaction ({$duration}s) - consider optimization";
            $insights['complexity'] = 'complex';
        }

        // Analyze operation count
        if ($operationCount > 100) {
            $insights['warnings'][] = "Many operations ({$operationCount}) in single transaction";
            $insights['recommendations'][] = "Consider breaking into smaller transactions";
            $insights['complexity'] = 'complex';
        }

        return $insights;
    }
}
