<?php

/**
 * Batch Update Factory - Intelligent Batch Update Strategy Selection and Execution
 * 
 * Handles the complexity of batch update operations by selecting optimal strategies
 * based on dataset size, database type, and system constraints. Supports both
 * CASE statement approach (moderate datasets) and temporary table approach (large datasets).
 * 
 * Features:
 * - Automatic strategy selection based on performance analysis
 * - Database-specific optimizations (MySQL, SQLite, PostgreSQL)
 * - Intelligent temp table management with cleanup
 * - Memory-aware chunking for large operations
 * - Comprehensive error handling and rollback
 * 
 * @package Database\BatchOperations
 * @author Enhanced Model System
 * @version 1.0.0
 */
class BatchUpdateFactory
{
    private Model $model;
    private PDO $pdo;
    private string $dbType;

    /**
     * Initialize batch update factory with Model instance
     * 
     * @param Model $model Enhanced Model instance with active database connection
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->pdo = $model->getPDO();
        $this->dbType = $model->getDbType();
    }

    /**
     * Execute batch update with intelligent strategy selection
     * 
     * Main entry point that handles strategy selection and execution for batch updates.
     * Automatically chooses between CASE statement and temporary table approaches
     * based on dataset characteristics and database capabilities.
     * 
     * @param string $table Target table name
     * @param string $identifierField Primary key field name
     * @param array $updates Array of update records
     * @param int|null $chunkSize Optional chunk size override
     * @return int Total number of records successfully updated
     * @throws InvalidArgumentException If validation fails
     * @throws RuntimeException If batch operation fails
     */
    public function executeBatchUpdate(string $table, string $identifierField, array $updates, ?int $chunkSize = null): int
    {
        $startTime = microtime(true);

        $this->model->debugLog("Batch update started", DebugCategory::BULK, DebugLevel::BASIC, [
            'table' => $table,
            'identifier_field' => $identifierField,
            'update_count' => count($updates),
            'chunk_size' => $chunkSize
        ]);

        // Validate table and column names
        DatabaseSecurity::validateTableName($table);
        DatabaseSecurity::validateColumnName($identifierField);

        // Auto-detect bulk operation for optimization
        $this->model->performance()->detectBulkOperation(count($updates));

        // Intelligent strategy selection based on dataset size and database capabilities
        $useTemporaryTableStrategy = $this->shouldUseTempTableStrategy($table, $updates);

        $this->model->debugLog("Update strategy determined", DebugCategory::BULK, DebugLevel::DETAILED, [
            'strategy' => $useTemporaryTableStrategy ? 'temp_table' : 'case_statements',
            'record_count' => count($updates),
            'reason' => $this->getStrategyReason($table, $updates)
        ]);

        $totalUpdated = $useTemporaryTableStrategy
            ? $this->executeMassiveBatchUpdateWithTempTable($table, $identifierField, $updates)
            : $this->executeModerateBatchUpdateWithCaseStatements($table, $identifierField, $updates, $chunkSize);

        $executionTime = microtime(true) - $startTime;

        // Log completion
        $this->model->debugLog("Batch update completed", DebugCategory::BULK, DebugLevel::BASIC, [
            'table' => $table,
            'updates_requested' => count($updates),
            'records_updated' => $totalUpdated,
            'execution_time' => $executionTime,
            'execution_time_ms' => round($executionTime * 1000, 2),
            'updates_per_second' => $executionTime > 0 ? round($totalUpdated / $executionTime) : 0,
            'strategy_used' => $useTemporaryTableStrategy ? 'temp_table' : 'case_statements'
        ]);

        return $totalUpdated;
    }

    // =============================================================================
    // STRATEGY SELECTION LOGIC
    // =============================================================================

    /**
     * Determine whether to use temp table strategy for massive batch updates
     * 
     * Decision logic based on comprehensive performance analysis:
     * - Dataset size: 2,000+ records favor temp table approach
     * - Database type: PostgreSQL and MySQL support temp tables well
     * - Column complexity: Many columns benefit more from temp table approach
     * - Memory considerations: Large datasets require temp table for efficiency
     * 
     * @param string $table Target table name
     * @param array $updates Array of update records
     * @return bool True if temp table strategy should be used
     */
    private function shouldUseTempTableStrategy(string $table, array $updates): bool
    {
        $recordCount = count($updates);

        // Always use CASE statements for very small datasets 
        if ($recordCount < 300) {
            return false;
        }

        // Get sample update to analyze complexity
        $sampleUpdate = reset($updates);
        $columnCount = count($sampleUpdate['data'] ?? []);

        // Calculate complexity score
        $complexityScore = $recordCount * $columnCount;

        // Database-specific thresholds based on benchmark performance
        $baseThreshold = match ($this->dbType) {
            'sqlite' => 800,        // SQLite temp tables are fast - use them aggressively
            'postgresql' => 900,    // PostgreSQL shows 2x improvement at moderate sizes
            'mysql' => 2000,        // MySQL temp tables are slower - be more conservative
            default => 1200        // Conservative default
        };

        // Sophisticated decision logic based on performance patterns
        $shouldUseTempTable = (
            // Size-based decisions (optimized based on benchmarks)
            $recordCount >= $baseThreshold ||

            // SQLite-specific: Fast temp table performance
            ($this->dbType === 'sqlite' && $recordCount >= 600) ||

            // PostgreSQL-specific: Excellent temp table performance
            ($this->dbType === 'postgresql' && $recordCount >= 700) ||

            // Complexity-based decisions
            $complexityScore >= 8000 ||

            // High column count favors temp tables earlier
            ($columnCount >= 6 && $recordCount >= 500) ||
            ($columnCount >= 8 && $recordCount >= 300) ||

            // Memory pressure favors temp tables
            $this->isMemoryConstrained($recordCount, $columnCount) ||

            // Very large datasets always use temp tables
            $recordCount >= 5000
        );

        // Database-specific fine-tuning
        if (!$shouldUseTempTable && $recordCount >= 500) {
            switch ($this->dbType) {
                case 'sqlite':
                    $shouldUseTempTable = ($recordCount >= 600 || $complexityScore >= 5000);
                    break;
                case 'postgresql':
                    $shouldUseTempTable = ($recordCount >= 800 || $complexityScore >= 6000);
                    break;
                case 'mysql':
                    $shouldUseTempTable = ($recordCount >= 1500 || $complexityScore >= 12000);
                    break;
            }
        }

        $strategy = $shouldUseTempTable ? "TEMP TABLE" : "CASE STATEMENTS";
        $this->model->debugLog("Strategy decision: Using {$strategy}", DebugCategory::BULK, DebugLevel::DETAILED, [
            'strategy' => $strategy,
            'record_count' => $recordCount,
            'column_count' => $columnCount,
            'complexity_score' => $complexityScore,
            'base_threshold' => $baseThreshold,
            'database_type' => $this->dbType,
            'reasons' => $this->getStrategyReasonDetails($recordCount, $columnCount, $complexityScore, $baseThreshold)
        ]);

        return $shouldUseTempTable;
    }

    /**
     * Get human-readable reason for strategy selection
     * 
     * @param string $table Table name
     * @param array $updates Update array
     * @return string Reason for strategy choice
     */
    private function getStrategyReason(string $table, array $updates): string
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

        if ($this->dbType === 'sqlite' && $recordCount >= 600) {
            return "SQLite with {$recordCount} records - temp tables are very fast";
        }

        return "Dataset analysis suggests this strategy for optimal performance";
    }

    /**
     * Get detailed reasons for strategy selection for debug logging
     * 
     * @param int $recordCount Number of records
     * @param int $columnCount Number of columns
     * @param int $complexityScore Calculated complexity score
     * @param int $baseThreshold Database-specific base threshold
     * @return array Detailed reason breakdown
     */
    private function getStrategyReasonDetails(int $recordCount, int $columnCount, int $complexityScore, int $baseThreshold): array
    {
        $reasons = [];

        if ($recordCount >= $baseThreshold) {
            $reasons[] = "record count >= base threshold ({$baseThreshold})";
        }

        if ($this->dbType === 'sqlite' && $recordCount >= 600) {
            $reasons[] = "SQLite fast temp tables optimization";
        }

        if ($this->dbType === 'postgresql' && $recordCount >= 700) {
            $reasons[] = "PostgreSQL temp table efficiency";
        }

        if ($complexityScore >= 8000) {
            $reasons[] = "high complexity score ({$complexityScore})";
        }

        if ($columnCount >= 6 && $recordCount >= 500) {
            $reasons[] = "many columns ({$columnCount}) with moderate size";
        }

        if ($recordCount >= 5000) {
            $reasons[] = "very large dataset";
        }

        return $reasons;
    }

    /**
     * Check if system is memory constrained for given dataset
     * 
     * @param int $recordCount Number of records to process
     * @param int $columnCount Number of columns per record
     * @return bool True if memory constrained
     */
    private function isMemoryConstrained(int $recordCount, int $columnCount): bool
    {
        // Estimate memory usage for CASE statement approach
        $estimatedMemoryUsage = $recordCount * $columnCount * 120;
        $currentMemoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitInBytes();

        // Use temp table if estimated usage would exceed 50% of available memory
        $availableMemory = $memoryLimit - $currentMemoryUsage;
        $memoryThreshold = $availableMemory * 0.5;

        return $estimatedMemoryUsage > $memoryThreshold;
    }

    // =============================================================================
    // TEMPORARY TABLE STRATEGY IMPLEMENTATION
    // =============================================================================

    /**
     * Execute massive batch update using temporary table strategy
     * 
     * Optimized for very large datasets (2,000+ records) using:
     * 1. Create temporary table with new values
     * 2. Bulk insert all updates into temp table
     * 3. Single JOIN/UPDATE operation
     * 4. Automatic cleanup
     * 
     * @param string $table Target table name
     * @param string $identifierField Primary key field name
     * @param array $updates Array of update records
     * @return int Number of records successfully updated
     */
    private function executeMassiveBatchUpdateWithTempTable(string $table, string $identifierField, array $updates): int
    {
        $startTime = microtime(true);
        $tempTableName = $this->generateTempTableName($table);
        $totalUpdated = 0;

        $this->model->debugLog("Massive batch update: Using TEMP TABLE strategy", DebugCategory::BULK, DebugLevel::BASIC, [
            'table' => $table,
            'record_count' => count($updates),
            'strategy' => 'temp_table',
            'temp_table_name' => $tempTableName
        ]);

        try {
            // Enable transaction for safety and performance
            $wasInTransaction = $this->pdo->inTransaction();
            if (!$wasInTransaction) {
                $this->pdo->beginTransaction();
            }

            // Step 1: Create temporary table  
            $this->createTempTableForUpdates($tempTableName, $table, $identifierField, $updates);

            // Step 2: Bulk insert updates into temp table
            $tempRecords = $this->prepareRecordsForTempTable($identifierField, $updates);
            $insertedCount = $this->bulkInsertIntoTempTable($tempTableName, $tempRecords);

            $this->model->debugLog("Temp table populated", DebugCategory::BULK, DebugLevel::DETAILED, [
                'temp_table_name' => $tempTableName,
                'records_inserted' => $insertedCount,
                'operation' => 'temp_table_insert'
            ]);

            // Step 3: Execute JOIN/UPDATE operation
            $totalUpdated = $this->executeJoinUpdate($table, $tempTableName, $identifierField, $updates);

            // Step 4: Cleanup temp table
            $this->dropTempTable($tempTableName);

            // Commit transaction if we started it
            if (!$wasInTransaction) {
                $this->pdo->commit();
            }

            $executionTime = microtime(true) - $startTime;
            $this->model->debugLog("Massive update completed", DebugCategory::BULK, DebugLevel::BASIC, [
                'table' => $table,
                'records_updated' => $totalUpdated,
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2),
                'strategy' => 'temp_table',
                'performance' => 'massive_batch_complete'
            ]);

            return $totalUpdated;

        } catch (Exception $e) {
            // Cleanup and rollback on error
            try {
                $this->dropTempTable($tempTableName);
                if (!$wasInTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollback();
                }
            } catch (Exception $cleanupException) {
                error_log("Temp table cleanup failed: " . $cleanupException->getMessage());
            }

            throw new RuntimeException("Massive batch update failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate unique temporary table name
     * 
     * @param string $baseTable Base table name for naming convention
     * @return string Unique temporary table name
     */
    private function generateTempTableName(string $baseTable): string
    {
        $timestamp = time();
        $randomSuffix = substr(hash('xxh3', uniqid()), 0, 8);
        return "temp_update_{$baseTable}_{$timestamp}_{$randomSuffix}";
    }

    /**
     * Create temporary table for batch updates using actual table schema
     * 
     * @param string $tempTableName Temporary table name
     * @param string $baseTable Original table name to copy schema from
     * @param string $identifierField Primary key field name
     * @param array $updates Sample updates for schema analysis
     * @return void
     */
    private function createTempTableForUpdates(string $tempTableName, string $baseTable, string $identifierField, array $updates): void
    {
        // Analyze schema from sample update
        $sampleUpdate = reset($updates);
        $columns = array_keys($sampleUpdate['data'] ?? []);

        // Get the actual table schema to match column types exactly
        $tableSchema = $this->model->describe_table($baseTable);

        if (!$tableSchema) {
            throw new RuntimeException("Could not describe table schema for: $baseTable");
        }

        // Build column type mapping from actual table
        $columnTypes = [];
        foreach ($tableSchema as $columnInfo) {
            $columnName = $columnInfo['Field'] ?? $columnInfo['name'];
            $columnType = $columnInfo['Type'] ?? $columnInfo['type'];
            $columnTypes[strtolower($columnName)] = $columnType;
        }

        // Build CREATE TABLE statement for temp table
        $escapedTempTable = DatabaseSecurity::escapeIdentifier($tempTableName, $this->dbType);
        $escapedIdField = DatabaseSecurity::escapeIdentifier($identifierField, $this->dbType);

        // Start with identifier field using its actual type
        $idType = $columnTypes[strtolower($identifierField)] ?? 'INTEGER';
        $columnDefinitions = [$escapedIdField . ' ' . $idType];

        // Add columns for each update field using their actual types
        foreach ($columns as $column) {
            $escapedColumn = DatabaseSecurity::escapeIdentifier($column, $this->dbType);
            $actualType = $columnTypes[strtolower($column)] ?? 'TEXT';
            $columnDefinitions[] = $escapedColumn . ' ' . $actualType;
        }

        // Database-specific CREATE TEMPORARY TABLE syntax
        $sql = match ($this->dbType) {
            'postgresql', 'postgres', 'pgsql' => "CREATE TEMPORARY TABLE $escapedTempTable (" . implode(', ', $columnDefinitions) . ")",
            'mysql' => "CREATE TEMPORARY TABLE $escapedTempTable (" . implode(', ', $columnDefinitions) . ") ENGINE=InnoDB",
            'sqlite' => "CREATE TEMPORARY TABLE $escapedTempTable (" . implode(', ', $columnDefinitions) . ")",
            default => "CREATE TEMPORARY TABLE $escapedTempTable (" . implode(', ', $columnDefinitions) . ")"
        };

        $this->model->debugLog("Creating temporary table", DebugCategory::BULK, DebugLevel::DETAILED, [
            'temp_table_name' => $tempTableName,
            'base_table' => $baseTable,
            'columns' => array_merge([$identifierField], $columns),
            'column_count' => count($columns) + 1,
            'operation' => 'create_temp_table'
        ]);

        $this->pdo->exec($sql);
    }

    /**
     * Prepare update records for temporary table insertion
     * 
     * @param string $identifierField Primary key field name
     * @param array $updates Array of update records
     * @return array Prepared records for bulk insert
     */
    private function prepareRecordsForTempTable(string $identifierField, array $updates): array
    {
        $tempRecords = [];

        foreach ($updates as $update) {
            if (!isset($update[$identifierField]) || !isset($update['data'])) {
                continue; // Skip invalid updates
            }

            $record = [$identifierField => $update[$identifierField]];
            $record = array_merge($record, $update['data']);
            $tempRecords[] = $record;
        }

        return $tempRecords;
    }

    /**
     * Bulk insert records into temporary table
     * 
     * @param string $tempTableName Temporary table name
     * @param array $tempRecords Records to insert
     * @return int Number of records inserted
     */
    private function bulkInsertIntoTempTable(string $tempTableName, array $tempRecords): int
    {
        if (empty($tempRecords)) {
            return 0;
        }

        // Use model's bulk insert capabilities
        return $this->model->insert_batch($tempTableName, $tempRecords, 1000);
    }

    /**
     * Execute JOIN/UPDATE operation using temporary table
     * 
     * @param string $table Target table name
     * @param string $tempTableName Temporary table name
     * @param string $identifierField Primary key field name
     * @param array $updates Original updates for column analysis
     * @return int Number of records updated
     */
    private function executeJoinUpdate(string $table, string $tempTableName, string $identifierField, array $updates): int
    {
        // Get columns to update
        $sampleUpdate = reset($updates);
        $columns = array_keys($sampleUpdate['data'] ?? []);

        // Validate columns
        DatabaseSecurity::validateIdentifiersBulk($columns, 'column');

        // Build SET clauses for JOIN UPDATE
        $escapedTable = DatabaseSecurity::escapeIdentifier($table, $this->dbType);
        $escapedTempTable = DatabaseSecurity::escapeIdentifier($tempTableName, $this->dbType);
        $escapedIdField = DatabaseSecurity::escapeIdentifier($identifierField, $this->dbType);

        $setClauses = [];
        foreach ($columns as $column) {
            $escapedColumn = DatabaseSecurity::escapeIdentifier($column, $this->dbType);

            // Database-specific SET clause syntax
            switch ($this->dbType) {
                case 'postgresql':
                case 'postgres':
                case 'pgsql':
                    // PostgreSQL: Don't qualify the target column in SET clause
                    $setClauses[] = "$escapedColumn = $escapedTempTable.$escapedColumn";
                    break;

                case 'mysql':
                    // MySQL: Can use qualified names in SET clause
                    $setClauses[] = "$escapedTable.$escapedColumn = $escapedTempTable.$escapedColumn";
                    break;

                case 'sqlite':
                    // SQLite: Use unqualified names
                    $setClauses[] = "$escapedColumn = $escapedTempTable.$escapedColumn";
                    break;

                default:
                    $setClauses[] = "$escapedColumn = $escapedTempTable.$escapedColumn";
                    break;
            }
        }

        // Build database-specific JOIN UPDATE syntax
        $sql = match ($this->dbType) {
            'postgresql', 'postgres', 'pgsql' => "UPDATE $escapedTable SET " . implode(', ', $setClauses) .
                " FROM $escapedTempTable WHERE $escapedTable.$escapedIdField = $escapedTempTable.$escapedIdField",

            'mysql' => "UPDATE $escapedTable INNER JOIN $escapedTempTable ON " .
                "$escapedTable.$escapedIdField = $escapedTempTable.$escapedIdField SET " . implode(', ', $setClauses),

            'sqlite' => "UPDATE $escapedTable SET " . implode(', ', $setClauses) .
                " FROM $escapedTempTable WHERE $escapedTable.$escapedIdField = $escapedTempTable.$escapedIdField",

            default => throw new RuntimeException("JOIN UPDATE not supported for database type: {$this->dbType}")
        };

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Drop temporary table with error handling
     * 
     * @param string $tempTableName Temporary table name to drop
     * @return void
     */
    private function dropTempTable(string $tempTableName): void
    {
        try {
            $escapedTempTable = DatabaseSecurity::escapeIdentifier($tempTableName, $this->dbType);

            $sql = match ($this->dbType) {
                'mysql' => "DROP TEMPORARY TABLE IF EXISTS $escapedTempTable",
                'postgresql', 'postgres', 'pgsql' => "DROP TABLE IF EXISTS $escapedTempTable",
                'sqlite' => "DROP TABLE IF EXISTS $escapedTempTable",
                default => "DROP TABLE IF EXISTS $escapedTempTable"
            };

            $this->pdo->exec($sql);

            $this->model->debugLog("Temporary table cleaned up", DebugCategory::BULK, DebugLevel::DETAILED, [
                'temp_table_name' => $tempTableName,
                'operation' => 'cleanup_temp_table'
            ]);

        } catch (Exception $e) {
            error_log("Failed to drop temp table $tempTableName: " . $e->getMessage());
        }
    }

    // =============================================================================
    // CASE STATEMENTS STRATEGY IMPLEMENTATION
    // =============================================================================

    /**
     * Execute moderate batch update using traditional CASE statement strategy
     * 
     * Optimized for moderate datasets (under 2,000 records) using SQL CASE 
     * statements for efficient updates.
     * 
     * @param string $table Target table name
     * @param string $identifierField Primary key field name
     * @param array $updates Array of update records
     * @param int|null $chunkSize Optional chunk size override
     * @return int Number of records successfully updated
     */
    private function executeModerateBatchUpdateWithCaseStatements(string $table, string $identifierField, array $updates, ?int $chunkSize = null): int
    {
        // Calculate optimal chunk size based on complexity
        if ($chunkSize === null) {
            $sampleUpdate = reset($updates);
            $columnCount = count($sampleUpdate['data'] ?? []);

            // More complex updates need smaller chunks
            $chunkSize = match ($this->dbType) {
                'sqlite' => max(50, min(200, intval(500 / max(1, $columnCount)))),
                'mysql' => max(100, min(500, intval(1000 / max(1, $columnCount)))),
                'postgresql' => max(100, min(500, intval(1000 / max(1, $columnCount)))),
                default => max(50, min(200, intval(500 / max(1, $columnCount))))
            };
        }

        try {
            $totalUpdated = 0;
            $chunks = array_chunk($updates, $chunkSize);

            foreach ($chunks as $chunk) {
                $totalUpdated += $this->executeBatchUpdateChunk($table, $identifierField, $chunk);
            }

            return $totalUpdated;

        } catch (PDOException $e) {
            throw new RuntimeException("Failed to execute batch update with CASE statements: " . $e->getMessage());
        }
    }

    /**
     * Execute a single batch update chunk using CASE statements
     * 
     * @param string $table Table name (already validated)
     * @param string $identifierField Identifier field (already validated)
     * @param array $chunk Chunk of updates to process
     * @return int Number of records updated in this chunk
     */
    private function executeBatchUpdateChunk(string $table, string $identifierField, array $chunk): int
    {
        if (empty($chunk)) {
            return 0;
        }

        // Get all unique column names from the chunk
        $allColumns = [];
        foreach ($chunk as $update) {
            if (isset($update['data']) && is_array($update['data'])) {
                $allColumns = array_merge($allColumns, array_keys($update['data']));
            }
        }
        $allColumns = array_unique($allColumns);

        if (empty($allColumns)) {
            return 0;
        }

        // Validate all column names
        DatabaseSecurity::validateIdentifiersBulk($allColumns, 'column');

        // Build CASE statements for each column using NAMED parameters
        $setClauses = [];
        $whereIds = [];
        $params = [];

        foreach ($allColumns as $column) {
            $escapedColumn = DatabaseSecurity::escapeIdentifier($column, $this->dbType);
            $escapedIdentifierField = DatabaseSecurity::escapeIdentifier($identifierField, $this->dbType);

            $caseStatement = "$escapedColumn = CASE $escapedIdentifierField";
            $hasValues = false;

            foreach ($chunk as $update) {
                if (!isset($update[$identifierField]) || !isset($update['data'][$column])) {
                    continue;
                }

                $id = $update[$identifierField];
                $value = $update['data'][$column];

                // Use unique parameter names to avoid conflicts
                $idParam = "id_" . $column . "_" . $id;
                $valueParam = "value_" . $column . "_" . $id;

                $caseStatement .= " WHEN :$idParam THEN :$valueParam";
                $params[$idParam] = $id;
                $params[$valueParam] = $value;
                $hasValues = true;
            }

            if ($hasValues) {
                $caseStatement .= " ELSE $escapedColumn END";
                $setClauses[] = $caseStatement;
            }
        }

        if (empty($setClauses)) {
            return 0; // No valid updates to perform
        }

        // Collect all IDs for WHERE clause using NAMED parameters
        foreach ($chunk as $update) {
            if (isset($update[$identifierField])) {
                $whereIds[] = $update[$identifierField];
            }
        }

        if (empty($whereIds)) {
            return 0; // No valid IDs to update
        }

        // Build final SQL with NAMED parameters for WHERE clause
        $escapedTable = DatabaseSecurity::escapeIdentifier($table, $this->dbType);
        $escapedIdentifierField = DatabaseSecurity::escapeIdentifier($identifierField, $this->dbType);

        // Create named parameters for WHERE clause
        $wherePlaceholders = [];
        foreach ($whereIds as $index => $id) {
            $whereParam = "where_id_$index";
            $wherePlaceholders[] = ":$whereParam";
            $params[$whereParam] = $id;
        }
        $whereClause = implode(',', $wherePlaceholders);

        $sql = "UPDATE $escapedTable SET " . implode(', ', $setClauses) .
            " WHERE $escapedIdentifierField IN ($whereClause)";

        try {
            $stmt = $this->pdo->prepare($sql);

            // Bind only named parameters
            foreach ($params as $key => $value) {
                $stmt->bindValue(":$key", $value, PDO::PARAM_STR);
            }

            $stmt->execute();
            return $stmt->rowCount();

        } catch (PDOException $e) {
            throw new RuntimeException("Failed to execute batch update chunk: " . $e->getMessage());
        }
    }

    // =============================================================================
    // UTILITY METHODS
    // =============================================================================

    /**
     * Get memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
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

}