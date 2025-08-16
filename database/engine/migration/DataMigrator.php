<?php

/**
 * Data Migrator - High-performance data migration with chunking
 * 
 * Handles efficient data transfer between databases with memory management,
 * progress tracking, data transformation, and error recovery.
 * 
 * @package Database\Migration
 * @author Enhanced Model System
 * @version 1.0.0
 */
class DataMigrator
{
    private $sourceModel;
    private $targetModel;
    private $debugCallback = null;
    private $progressCallback = null;
    private array $transformationRules = [];
    private array $columnMappings = [];
    private array $transformedSchemas = [];
    private array $primaryKeyCache = [];
    
    // Performance tracking
    private $stats = [
        'total_records' => 0,
        'processed_records' => 0,
        'failed_records' => 0,
        'chunks_processed' => 0,
        'start_time' => null,
        'current_table' => null
    ];

    /**
     * Initialize data migrator
     */
    public function __construct($sourceModel, $targetModel)
    {
        $this->sourceModel = $sourceModel;
        $this->targetModel = $targetModel;
    }

    /**
     * Set debug callback
     */
    public function setDebugCallback(?callable $callback): void
    {
        $this->debugCallback = $callback;
    }

    /**
     * Set progress callback
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Add transformation rule for a table
     */
    public function addTransformationRule(string $table, callable $transformer): void
    {
        $this->transformationRules[$table] = $transformer;
    }

    /**
     * Add column mapping for a table
     */
    public function addColumnMapping(string $table, array $mapping): void
    {
        $this->columnMappings[$table] = $mapping;
    }

    /**
     * Set transformed schemas from DatabaseMigrator
     * This avoids the need for additional schema lookups
     */
    public function setTransformedSchemas(array $transformedSchemas): void
    {
        $this->transformedSchemas = $transformedSchemas;
        
        // Pre-cache primary keys for all tables
        foreach ($transformedSchemas as $tableName => $tableSchema) {
            if ($tableSchema instanceof Table) {
                $this->primaryKeyCache[$tableName] = $this->extractPrimaryKeyFromTable($tableSchema);
            }
        }
    }

    /**
     * Get primary key columns for a table (uses cached data)
     */
    private function getPrimaryKeyColumns(string $tableName): array
    {
        // Use cached primary key info if available
        if (isset($this->primaryKeyCache[$tableName])) {
            return $this->primaryKeyCache[$tableName];
        }
        
        // Fallback: extract from transformed schema if available
        if (isset($this->transformedSchemas[$tableName])) {
            $pkColumns = $this->extractPrimaryKeyFromTable($this->transformedSchemas[$tableName]);
            $this->primaryKeyCache[$tableName] = $pkColumns;
            return $pkColumns;
        }
        
        // Last resort: return empty array (will disable conflict handling)
        $this->debug("No primary key information available for table: $tableName");
        return [];
    }

    /**
     * Migrate data for all tables
     */
    public function migrateAllTables(array $tables, array $options = []): array
    {
        $options = array_merge([
            'chunk_size' => 1000,
            'memory_limit' => '256M',
            'max_execution_time' => 0,
            'handle_conflicts' => 'update', // 'skip', 'update', 'error'
            'preserve_relationships' => true,
            'validate_data_types' => true,
            'stop_on_error' => false,
            'parallel_processing' => false
        ], $options);
        
        $this->initializeStats();
        $this->debug("Starting data migration for all tables", [
            'tables_count' => count($tables),
            'chunk_size' => $options['chunk_size']
        ]);
        
        // Set execution limits
        if ($options['max_execution_time'] > 0) {
            set_time_limit($options['max_execution_time']);
        }
        if ($options['memory_limit']) {
            ini_set('memory_limit', $options['memory_limit']);
        }
        
        $results = [
            'success' => true,
            'tables_migrated' => [],
            'tables_failed' => [],
            'records_migrated' => 0,
            'performance_stats' => [],
            'errors' => []
        ];
        
        // Sort tables by dependency order if preserving relationships
        if ($options['preserve_relationships']) {
            $tables = $this->sortTablesByDependencies($tables);
        }
        
        foreach ($tables as $tableName => $tableSchema) {
            try {
                $this->debug("Migrating table data: $tableName");
                $this->stats['current_table'] = $tableName;
                
                $tableResult = $this->migrateTable($tableName, $options);
                
                if ($tableResult['success']) {
                    $results['tables_migrated'][] = $tableName;
                    $results['records_migrated'] += $tableResult['records_migrated'];
                } else {
                    $results['tables_failed'][] = $tableName;
                    $results['errors'][] = "Table '$tableName': " . $tableResult['error'];
                    
                    if ($options['stop_on_error']) {
                        $results['success'] = false;
                        break;
                    }
                }
                
                // Memory management
                $this->performMemoryCleanup();
                
            } catch (Exception $e) {
                $results['tables_failed'][] = $tableName;
                $results['errors'][] = "Table '$tableName': " . $e->getMessage();
                
                if ($options['stop_on_error']) {
                    $results['success'] = false;
                    break;
                }
            }
        }
        
        $results['performance_stats'] = $this->getPerformanceStats();
        $this->debug("Data migration completed", $results['performance_stats']);
        
        return $results;
    }

    /**
     * Migrate data for a single table
     */
    public function migrateTable(string $tableName, array $options = []): array
    {
        $options = array_merge([
            'chunk_size' => 1000,
            'handle_conflicts' => 'update',
            'validate_data_types' => true,
            'use_transaction' => true
        ], $options);
        
        $this->debug("Starting table migration: $tableName");
        $startTime = microtime(true);
        
        try {
            // Get table metadata
            $sourceColumns = $this->getTableColumns($this->sourceModel, $tableName);
            $targetColumns = $this->getTableColumns($this->targetModel, $tableName);
            $totalRows = $this->getTableRowCount($this->sourceModel, $tableName);
            
            if ($totalRows === 0) {
                return [
                    'success' => true,
                    'table' => $tableName,
                    'records_migrated' => 0,
                    'execution_time' => microtime(true) - $startTime
                ];
            }
            
            $this->debug("Table migration info", [
                'table' => $tableName,
                'total_rows' => $totalRows,
                'source_columns' => count($sourceColumns),
                'target_columns' => count($targetColumns)
            ]);
            
            // Prepare column mapping
            $columnMapping = $this->prepareColumnMapping($tableName, $sourceColumns, $targetColumns);
            
            // Prepare insert statement
            $insertSQL = $this->prepareInsertStatement($tableName, $columnMapping, $options);
            $targetPdo = $this->targetModel->getPDO();
            $insertStmt = $targetPdo->prepare($insertSQL);
            
            // Process data in chunks
            $recordsMigrated = 0;
            $chunkNumber = 0;
            $offset = 0;
            
            while ($offset < $totalRows) {
                $chunkNumber++;
                
                // Start transaction for chunk
                if ($options['use_transaction']) {
                    $targetPdo->beginTransaction();
                }
                
                try {
                    $chunkData = $this->fetchChunk($tableName, $offset, $options['chunk_size'], $sourceColumns);
                    
                    if (empty($chunkData)) {
                        break;
                    }
                    
                    $chunkRecords = $this->processChunk($chunkData, $tableName, $insertStmt, $columnMapping, $options);
                    $recordsMigrated += $chunkRecords;
                    
                    if ($options['use_transaction']) {
                        $targetPdo->commit();
                    }
                    
                    // Update progress
                    $this->updateProgress($recordsMigrated, $totalRows, $tableName);
                    
                    $this->debug("Chunk processed", [
                        'table' => $tableName,
                        'chunk' => $chunkNumber,
                        'records' => $chunkRecords,
                        'total_migrated' => $recordsMigrated,
                        'memory_usage' => $this->formatBytes(memory_get_usage(true))
                    ]);
                    
                } catch (Exception $e) {
                    if ($options['use_transaction']) {
                        $targetPdo->rollBack();
                    }
                    throw new Exception("Chunk $chunkNumber failed: " . $e->getMessage());
                }
                
                $offset += $options['chunk_size'];
                
                // Memory management
                if ($chunkNumber % 10 === 0) {
                    $this->performMemoryCleanup();
                }
            }
            
            return [
                'success' => true,
                'table' => $tableName,
                'records_migrated' => $recordsMigrated,
                'chunks_processed' => $chunkNumber,
                'execution_time' => microtime(true) - $startTime
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'table' => $tableName,
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $startTime
            ];
        }
    }

    /**
     * Fetch data chunk from source table
     */
    private function fetchChunk(string $tableName, int $offset, int $limit, array $columns): array
    {
        $sourcePdo = $this->sourceModel->getPDO();
        $columnNames = array_map(function($col) { return "`{$col['name']}`"; }, $columns);
        
        $sql = "SELECT " . implode(', ', $columnNames) . " FROM `$tableName` LIMIT $limit OFFSET $offset";
        $stmt = $sourcePdo->query($sql);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Process a chunk of data
     */
    private function processChunk(array $chunkData, string $tableName, PDOStatement $insertStmt, array $columnMapping, array $options): int
    {
        $recordsProcessed = 0;
        
        foreach ($chunkData as $row) {
            try {
                // Apply transformations
                if (isset($this->transformationRules[$tableName])) {
                    $row = call_user_func($this->transformationRules[$tableName], $row);
                }
                
                // Map columns
                $mappedRow = $this->mapRowColumns($row, $columnMapping);
                
                // Validate data types
                if ($options['validate_data_types']) {
                    $mappedRow = $this->validateAndConvertDataTypes($mappedRow, $tableName);
                }
                
                // Execute insert
                $success = $insertStmt->execute(array_values($mappedRow));
                
                if ($success) {
                    $recordsProcessed++;
                } else {
                    $this->debug("Insert failed for record", [
                        'table' => $tableName,
                        'error' => $insertStmt->errorInfo()
                    ]);
                }
                
            } catch (Exception $e) {
                $this->debug("Record processing failed", [
                    'table' => $tableName,
                    'error' => $e->getMessage(),
                    'row_data' => array_keys($row)
                ]);
                
                if ($options['handle_conflicts'] === 'error') {
                    throw $e;
                }
                // Otherwise continue with next record
            }
        }
        
        return $recordsProcessed;
    }

    /**
     * Prepare column mapping between source and target
     */
    private function prepareColumnMapping(string $tableName, array $sourceColumns, array $targetColumns): array
    {
        $mapping = [];
        
        // Start with direct column name mapping
        $sourceColNames = array_column($sourceColumns, 'name');
        $targetColNames = array_column($targetColumns, 'name');
        
        foreach ($sourceColNames as $sourceCol) {
            if (in_array($sourceCol, $targetColNames)) {
                $mapping[$sourceCol] = $sourceCol;
            }
        }
        
        // Apply custom column mappings
        if (isset($this->columnMappings[$tableName])) {
            $mapping = array_merge($mapping, $this->columnMappings[$tableName]);
        }
        
        return $mapping;
    }

    /**
     * Prepare INSERT statement
     */
    private function prepareInsertStatement(string $tableName, array $columnMapping, array $options): string
    {
        $targetPdo = $this->targetModel->getPDO();
        $targetDB = strtolower($targetPdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        
        $targetColumns = array_values($columnMapping);
        
        // Validate that all column names are strings
        foreach ($targetColumns as $index => $column) {
            if (!is_string($column)) {
                $this->debug("Invalid column type found in mapping", [
                    'table' => $tableName,
                    'index' => $index,
                    'type' => gettype($column),
                    'value' => $column
                ]);
                throw new InvalidArgumentException("Column mapping contains non-string value at index $index");
            }
        }
        
        $placeholders = array_fill(0, count($targetColumns), '?');
        
        $columnsList = $this->quoteIdentifiers($targetColumns, $targetDB);
        $placeholdersList = implode(', ', $placeholders);
        
        $sql = "INSERT INTO " . $this->quoteIdentifier($tableName, $targetDB) . 
               " ($columnsList) VALUES ($placeholdersList)";
        
        // Handle conflicts based on database type
        if (isset($options['handle_conflicts']) && $options['handle_conflicts'] !== 'error') {
            $pkColumns = $this->getPrimaryKeyColumns($tableName);
            
            if (empty($pkColumns)) {
                $this->debug("Warning: No primary key found for $tableName, conflict handling disabled");
                return $sql;
            }
            
            // Validate primary key columns are strings
            foreach ($pkColumns as $index => $pkColumn) {
                if (!is_string($pkColumn)) {
                    $this->debug("Invalid primary key column type", [
                        'table' => $tableName,
                        'index' => $index,
                        'type' => gettype($pkColumn),
                        'value' => $pkColumn
                    ]);
                    // Skip conflict handling if PK columns are invalid
                    return $sql;
                }
            }
            
            $quotedPk = $this->quoteIdentifiers($pkColumns, $targetDB);
            $conflictTarget = $quotedPk; // Already properly quoted and joined
            
            if ($options['handle_conflicts'] === 'update') {
                $sql = $this->addUpdateConflictHandling($sql, $targetColumns, $conflictTarget, $targetDB);
            } elseif ($options['handle_conflicts'] === 'skip') {
                $sql = $this->addSkipConflictHandling($sql, $targetDB);
            }
        }
        
        return $sql;
    }

    /**
     * UPDATE conflict handling
     */
    private function addUpdateConflictHandling(string $sql, array $targetColumns, string $conflictTarget, string $targetDB): string
    {
        switch ($targetDB) {
            case 'mysql':
                $updateList = [];
                foreach ($targetColumns as $column) {
                    $quotedCol = $this->quoteIdentifier($column, $targetDB);
                    $updateList[] = "$quotedCol = VALUES($quotedCol)";
                }
                return $sql . " ON DUPLICATE KEY UPDATE " . implode(', ', $updateList);
                
            case 'pgsql':
            case 'postgres':
            case 'postgresql':
                $updateList = [];
                foreach ($targetColumns as $column) {
                    $quotedCol = $this->quoteIdentifier($column, $targetDB);
                    $updateList[] = "$quotedCol = EXCLUDED.$quotedCol";
                }
                return $sql . " ON CONFLICT ($conflictTarget) DO UPDATE SET " . implode(', ', $updateList);
                
            case 'sqlite':
                $updateList = [];
                foreach ($targetColumns as $column) {
                    $quotedCol = $this->quoteIdentifier($column, $targetDB);
                    $updateList[] = "$quotedCol = EXCLUDED.$quotedCol";
                }
                return $sql . " ON CONFLICT ($conflictTarget) DO UPDATE SET " . implode(', ', $updateList);
                
            default:
                $this->debug("Warning: UPDATE conflict handling not supported for database type: $targetDB");
                return $sql;
        }
    }

    /**
     * SKIP conflict handling
     */
    private function addSkipConflictHandling(string $sql, string $targetDB): string
    {
        switch ($targetDB) {
            case 'mysql':
                return str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sql);
                
            case 'pgsql':
            case 'postgres': 
            case 'postgresql':
                return $sql . " ON CONFLICT DO NOTHING";
                
            case 'sqlite':
                return str_replace('INSERT INTO', 'INSERT OR IGNORE INTO', $sql);
                
            default:
                $this->debug("Warning: SKIP conflict handling not supported for database type: $targetDB");
                return $sql;
        }
    }


    /**
     * Quote identifier based on database type
     */
    private function quoteIdentifier(string $identifier, string $dbType): string
    {
        switch ($dbType) {
            case 'mysql':
                return "`$identifier`";
            case 'pgsql':
            case 'postgres':
            case 'postgresql':
                return "\"$identifier\"";
            case 'sqlite':
                return "\"$identifier\"";
            default:
                return $identifier;
        }
    }

    /**
     * Quote multiple identifiers
     */
    private function quoteIdentifiers(array $identifiers, string $dbType): string
    {
        if (empty($identifiers)) {
            throw new InvalidArgumentException("Cannot quote empty identifier array");
        }
        
        $quoted = [];
        foreach ($identifiers as $index => $identifier) {
            if (!is_string($identifier)) {
                throw new InvalidArgumentException("Identifier at index $index is not a string: " . gettype($identifier));
            }
            if (empty(trim($identifier))) {
                throw new InvalidArgumentException("Identifier at index $index is empty or whitespace");
            }
            $quoted[] = $this->quoteIdentifier($identifier, $dbType);
        }
        
        return implode(', ', $quoted);
    }

    /**
     * Extract primary key columns from Table object
     */
    private function extractPrimaryKeyFromTable(Table $table): array
    {
        $pkColumns = [];
        
        // Check indexes for primary key
        foreach ($table->getIndexes() as $index) {
            if ($index->getType() === Index::TYPE_PRIMARY || $index->isPrimary()) {
                // Use the proper getColumnNames() method
                $pkColumns = $index->getColumnNames();
                
                // Validate that all column names are strings
                $pkColumns = array_filter($pkColumns, 'is_string');
                
                if (!empty($pkColumns)) {
                    break;
                }
            }
        }
        
        // Fallback: check columns for primary key markers
        if (empty($pkColumns)) {
            foreach ($table->getColumns() as $column) {
                if (method_exists($column, 'isPrimaryKey') && $column->isPrimaryKey()) {
                    $pkColumns[] = $column->getName();
                }
            }
        }
        
        // Debug log the extracted primary keys
        $this->debug("Extracted primary key columns for table", [
            'table' => $table->getName(),
            'primary_keys' => $pkColumns
        ]);
        
        return $pkColumns;
    }

    /**
     * Map row columns according to mapping
     */
    private function mapRowColumns(array $row, array $columnMapping): array
    {
        $mappedRow = [];
        
        foreach ($columnMapping as $sourceCol => $targetCol) {
            $mappedRow[$targetCol] = $row[$sourceCol] ?? null;
        }
        
        return $mappedRow;
    }

    /**
     * Validate and convert data types
     */
    private function validateAndConvertDataTypes(array $row, string $tableName): array
    {
        // Get target database type
        $targetPdo = $this->targetModel->getPDO();
        $targetDB = strtolower($targetPdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }
            
            // Apply database-specific conversions
            $row[$column] = $this->convertDataType($value, $targetDB);
        }
        
        return $row;
    }

    /**
     * Convert data type for target database
     */
    private function convertDataType($value, string $targetDB): mixed
    {
        if ($value === null) {
            return null;
        }
        
        // Handle boolean conversions
        if (is_bool($value)) {
            switch ($targetDB) {
                case 'mysql':
                    return $value ? 1 : 0;
                case 'postgresql':
                    return $value ? 'true' : 'false';
                case 'sqlite':
                    return $value ? 1 : 0;
            }
        }
        
        // Handle date/time conversions
        if ($this->isDateTimeValue($value)) {
            return $this->convertDateTime($value, $targetDB);
        }
        
        return $value;
    }

    /**
     * Check if value is a date/time value
     */
    private function isDateTimeValue($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        // Simple check for common date/time formats
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || 
               preg_match('/^\d{2}:\d{2}:\d{2}/', $value);
    }

    /**
     * Convert date/time format for target database
     */
    private function convertDateTime($value, string $targetDB): string
    {
        // For now, return as-is since most databases use ISO format
        // Add specific conversions as needed
        return $value;
    }

    /**
     * Get table columns information
     */
    private function getTableColumns($model, string $tableName): array
    {
        $extractor = new SchemaExtractor();
        $extractor->setDebugCallback($this->debugCallback);
        
        $tableSchema = $extractor->extractTable($model, $tableName);
        return $tableSchema['columns'] ?? [];
    }

    /**
     * Get table row count
     */
    private function getTableRowCount($model, string $tableName): int
    {
        $pdo = $model->getPDO();
        $sql = "SELECT COUNT(*) FROM `$tableName`";
        $stmt = $pdo->query($sql);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Sort tables by dependency order
     */
    private function sortTablesByDependencies(array $tables): array
    {
        // Simple implementation - could be enhanced with proper dependency analysis
        // For now, just return tables as-is
        return $tables;
    }

    /**
     * Initialize performance statistics
     */
    private function initializeStats(): void
    {
        $this->stats = [
            'total_records' => 0,
            'processed_records' => 0,
            'failed_records' => 0,
            'chunks_processed' => 0,
            'start_time' => microtime(true),
            'current_table' => null
        ];
    }

    /**
     * Get performance statistics
     */
    private function getPerformanceStats(): array
    {
        $executionTime = microtime(true) - $this->stats['start_time'];
        $recordsPerSecond = $executionTime > 0 ? $this->stats['processed_records'] / $executionTime : 0;
        
        return [
            'total_records' => $this->stats['total_records'],
            'processed_records' => $this->stats['processed_records'],
            'failed_records' => $this->stats['failed_records'],
            'chunks_processed' => $this->stats['chunks_processed'],
            'execution_time' => $executionTime,
            'records_per_second' => round($recordsPerSecond, 2),
            'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
            'memory_current' => $this->formatBytes(memory_get_usage(true))
        ];
    }

    /**
     * Update progress
     */
    private function updateProgress(int $processed, int $total, string $tableName): void
    {
        if ($this->progressCallback) {
            $percentage = $total > 0 ? round(($processed / $total) * 100, 1) : 0;
            
            call_user_func($this->progressCallback, [
                'table' => $tableName,
                'processed' => $processed,
                'total' => $total,
                'percentage' => $percentage,
                'records_per_second' => $this->calculateCurrentSpeed()
            ]);
        }
    }

    /**
     * Calculate current processing speed
     */
    private function calculateCurrentSpeed(): float
    {
        $elapsed = microtime(true) - $this->stats['start_time'];
        return $elapsed > 0 ? $this->stats['processed_records'] / $elapsed : 0;
    }

    /**
     * Perform memory cleanup
     */
    private function performMemoryCleanup(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        
        if ($memoryUsage > ($memoryLimit * 0.8)) {
            $this->debug("High memory usage detected", [
                'current' => $this->formatBytes($memoryUsage),
                'limit' => $this->formatBytes($memoryLimit),
                'percentage' => round(($memoryUsage / $memoryLimit) * 100, 1)
            ]);
        }
    }

    /**
     * Parse memory limit string
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit)-1]);
        $value = (int) $limit;
        
        switch ($last) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Format bytes for human reading
     */
    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;
        
        return round($size / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Debug logging
     */
    private function debug(string $message, array $context = []): void
    {
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, $message, $context);
        }
    }
}
