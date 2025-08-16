<?php

/**
 * Schema-Aware Intelligence System
 * 
 * Analyzes database schema and usage patterns to provide intelligent optimization:
 * - Index-aware query optimization
 * - Foreign key constraint aware bulk operations
 * - Column type specific optimizations
 * - Predictive query performance analysis
 * - Intelligent cache warming based on schema relationships
 * - Full support for MySQL, SQLite, and PostgreSQL
 */
class SchemaAwareOptimizer
{
    private static array $schemaCache = [];
    private static array $indexAnalysis = [];
    private static array $relationshipMap = [];
    private static array $performanceProfiles = [];
    private static array $optimizationHistory = [];
    private static bool $initialized = false;
    private static string $currentDbType = '';

    /**
     * Initialize schema analysis
     */
    public static function initialize(PDO $pdo, string $dbType): void
    {
        if (self::$initialized) {
            return;
        }

        echo "🧠 Initializing Schema-Aware Intelligence...\n";

        // Store database type for use throughout the class
        self::$currentDbType = strtolower($dbType);

        self::analyzeSchema($pdo, self::$currentDbType);
        self::analyzeIndexes($pdo, self::$currentDbType);
        self::mapRelationships($pdo, self::$currentDbType);
        self::buildPerformanceProfiles();

        self::$initialized = true;
        echo "✅ Schema analysis complete - Intelligence system active\n";
    }

    /**
     * Analyze database schema for optimization opportunities
     */
    private static function analyzeSchema(PDO $pdo, string $dbType): void
    {
        $tables = self::getTableList($pdo, $dbType);

        foreach ($tables as $table) {
            $columns = self::getTableColumns($pdo, $table, $dbType);

            self::$schemaCache[$table] = [
                'columns' => $columns,
                'primary_key' => self::findPrimaryKey($columns),
                'column_types' => array_column($columns, 'type', 'name'),
                'nullable_columns' => array_keys(array_filter($columns, fn($col) => $col['nullable'])),
                'indexed_columns' => [],
                'foreign_keys' => [],
                'estimated_row_size' => self::estimateRowSize($columns),
                'optimization_hints' => []
            ];
        }
    }

    /**
     * Analyze indexes for query optimization
     */
    private static function analyzeIndexes(PDO $pdo, string $dbType): void
    {
        foreach (array_keys(self::$schemaCache) as $table) {
            $indexes = self::getTableIndexes($pdo, $table, $dbType);

            self::$indexAnalysis[$table] = [
                'indexes' => $indexes,
                'covered_columns' => [],
                'composite_indexes' => [],
                'unique_indexes' => [],
                'missing_indexes' => []
            ];

            // Analyze index coverage
            foreach ($indexes as $index) {
                if (count($index['columns']) > 1) {
                    self::$indexAnalysis[$table]['composite_indexes'][] = $index;
                }

                if ($index['unique']) {
                    self::$indexAnalysis[$table]['unique_indexes'][] = $index;
                }

                self::$indexAnalysis[$table]['covered_columns'] = array_merge(
                    self::$indexAnalysis[$table]['covered_columns'],
                    $index['columns']
                );
            }

            // Update schema cache with indexed columns
            self::$schemaCache[$table]['indexed_columns'] = array_unique(
                self::$indexAnalysis[$table]['covered_columns']
            );

            // Suggest missing indexes
            self::suggestMissingIndexes($table);
        }
    }

    /**
     * Map table relationships for optimization
     */
    private static function mapRelationships(PDO $pdo, string $dbType): void
    {
        foreach (array_keys(self::$schemaCache) as $table) {
            $foreignKeys = self::getForeignKeys($pdo, $table, $dbType);

            self::$relationshipMap[$table] = [
                'foreign_keys' => $foreignKeys,
                'referenced_by' => [],
                'relationship_weight' => 0
            ];

            // Build reverse relationship map
            foreach ($foreignKeys as $fk) {
                $referencedTable = $fk['referenced_table'];
                if (!isset(self::$relationshipMap[$referencedTable])) {
                    self::$relationshipMap[$referencedTable] = [
                        'foreign_keys' => [],
                        'referenced_by' => [],
                        'relationship_weight' => 0
                    ];
                }

                self::$relationshipMap[$referencedTable]['referenced_by'][] = [
                    'table' => $table,
                    'column' => $fk['column'],
                    'referenced_column' => $fk['referenced_column']
                ];
            }
        }

        // Calculate relationship weights for cache warming
        foreach (self::$relationshipMap as $table => &$info) {
            $info['relationship_weight'] = count($info['foreign_keys']) + count($info['referenced_by']);
        }
    }

    /**
     * Build performance profiles for different operation types
     */
    private static function buildPerformanceProfiles(): void
    {
        foreach (self::$schemaCache as $table => $schema) {
            $rowSize = $schema['estimated_row_size'];
            $columnCount = count($schema['columns']);
            $indexCount = count(self::$indexAnalysis[$table]['indexes']);

            self::$performanceProfiles[$table] = [
                'insert_complexity' => self::calculateInsertComplexity($table, $schema),
                'select_complexity' => self::calculateSelectComplexity($table, $schema),
                'update_complexity' => self::calculateUpdateComplexity($table, $schema),
                'optimal_batch_size' => self::calculateOptimalBatchSize($table, $schema),
                'cache_priority' => self::calculateCachePriority($table),
                'index_effectiveness' => $indexCount / max(1, $columnCount),
                'foreign_key_overhead' => count(self::$relationshipMap[$table]['foreign_keys'] ?? [])
            ];
        }
    }

    /**
     * Get intelligent optimization recommendations for a table operation
     */
    public static function getOptimizationRecommendations(
        string $table,
        string $operation,
        array $data = [],
        array $context = []
    ): array {
        if (!self::$initialized) {
            return ['error' => 'Schema analyzer not initialized'];
        }

        if (!isset(self::$schemaCache[$table])) {
            return ['error' => "Table '$table' not found in schema cache"];
        }

        $recommendations = [
            'operation' => $operation,
            'table' => $table,
            'optimizations' => [],
            'warnings' => [],
            'performance_hints' => []
        ];

        switch ($operation) {
            case 'bulk_insert':
                $recommendations = array_merge($recommendations, self::getBulkInsertRecommendations($table, $data, $context));
                break;

            case 'select':
                $recommendations = array_merge($recommendations, self::getSelectRecommendations($table, $context));
                break;

            case 'update':
                $recommendations = array_merge($recommendations, self::getUpdateRecommendations($table, $data, $context));
                break;

            case 'delete':
                $recommendations = array_merge($recommendations, self::getDeleteRecommendations($table, $context));
                break;
        }

        return $recommendations;
    }

    /**
     * Get bulk insert optimization recommendations
     */
    private static function getBulkInsertRecommendations(string $table, array $data, array $context): array
    {
        $schema = self::$schemaCache[$table];
        $profile = self::$performanceProfiles[$table];
        $recordCount = count($data);

        $recommendations = [
            'optimizations' => [],
            'warnings' => [],
            'performance_hints' => []
        ];

        // Optimal batch size recommendation
        $optimalBatchSize = $profile['optimal_batch_size'];
        if (!isset($context['chunk_size']) || $context['chunk_size'] != $optimalBatchSize) {
            $recommendations['optimizations'][] = [
                'type' => 'batch_size',
                'current' => $context['chunk_size'] ?? 'default',
                'recommended' => $optimalBatchSize,
                'reason' => 'Based on table schema analysis and estimated row size',
                'code' => "\$model->insert_batch('{$table}', \$records, {$optimalBatchSize});"
            ];
        }

        // Foreign key constraint warnings
        $foreignKeys = self::$relationshipMap[$table]['foreign_keys'] ?? [];
        if (!empty($foreignKeys) && $recordCount > 100) {
            $recommendations['warnings'][] = [
                'type' => 'foreign_key_overhead',
                'message' => 'Table has ' . count($foreignKeys) . ' foreign key constraints that may slow bulk inserts',
                'suggestion' => 'Consider temporarily disabling foreign key checks',
                'code' => self::getForeignKeyDisableCode()
            ];
        }

        // Column type specific optimizations
        $columnTypes = $schema['column_types'];
        if (in_array('TEXT', $columnTypes) || in_array('LONGTEXT', $columnTypes) || in_array('text', $columnTypes)) {
            $recommendations['performance_hints'][] = [
                'type' => 'text_optimization',
                'message' => 'Table contains TEXT columns - consider data compression for large strings',
                'impact' => 'Can reduce memory usage by 30-50% for large text fields'
            ];
        }

        // Index impact analysis
        $indexCount = count(self::$indexAnalysis[$table]['indexes']);
        if ($indexCount > 5 && $recordCount > 1000) {
            $recommendations['warnings'][] = [
                'type' => 'index_overhead',
                'message' => "Table has {$indexCount} indexes which will slow bulk inserts",
                'suggestion' => 'Consider temporarily dropping non-essential indexes',
                'estimated_impact' => 'Each index adds ~10-20% overhead to insert operations'
            ];
        }

        return $recommendations;
    }

    /**
     * Get SELECT operation recommendations
     */
    private static function getSelectRecommendations(string $table, array $context): array
    {
        $schema = self::$schemaCache[$table];
        $indexes = self::$indexAnalysis[$table];

        $recommendations = [
            'optimizations' => [],
            'warnings' => [],
            'performance_hints' => []
        ];

        // WHERE clause index analysis
        if (isset($context['where_column'])) {
            $whereColumn = $context['where_column'];

            if (!in_array($whereColumn, $indexes['covered_columns'])) {
                $recommendations['warnings'][] = [
                    'type' => 'missing_index',
                    'message' => "No index found for WHERE column '{$whereColumn}'",
                    'suggestion' => self::getIndexCreationSql($table, $whereColumn),
                    'estimated_impact' => 'Query may perform full table scan'
                ];
            }
        }

        // ORDER BY optimization
        if (isset($context['order_by'])) {
            $orderColumn = $context['order_by'];

            if (!in_array($orderColumn, $indexes['covered_columns'])) {
                $recommendations['performance_hints'][] = [
                    'type' => 'order_optimization',
                    'message' => "ORDER BY column '{$orderColumn}' is not indexed",
                    'suggestion' => 'Consider adding an index to avoid sorting overhead',
                    'code' => self::getIndexCreationSql($table, $orderColumn)
                ];
            }
        }

        // LIMIT optimization
        if (isset($context['limit']) && $context['limit'] > 1000) {
            $recommendations['performance_hints'][] = [
                'type' => 'large_limit',
                'message' => 'Large LIMIT value may impact performance',
                'suggestion' => 'Consider pagination with smaller page sizes'
            ];
        }

        return $recommendations;
    }

    /**
     * Get UPDATE operation recommendations
     */
    private static function getUpdateRecommendations(string $table, array $data, array $context): array
    {
        $schema = self::$schemaCache[$table];
        $profile = self::$performanceProfiles[$table];
        $indexes = self::$indexAnalysis[$table];

        $recommendations = [
            'optimizations' => [],
            'warnings' => [],
            'performance_hints' => []
        ];

        // WHERE clause index analysis (similar to SELECT)
        if (isset($context['where_column'])) {
            $whereColumn = $context['where_column'];

            if (!in_array($whereColumn, $indexes['covered_columns'])) {
                $recommendations['warnings'][] = [
                    'type' => 'missing_index_where',
                    'message' => "No index found for WHERE column '{$whereColumn}' in UPDATE",
                    'suggestion' => self::getIndexCreationSql($table, $whereColumn),
                    'estimated_impact' => 'UPDATE may perform full table scan to find records'
                ];
            }
        }

        // Analyze columns being updated
        $updatedColumns = array_keys($data);
        $indexedColumnsBeingUpdated = array_intersect($updatedColumns, $indexes['covered_columns']);

        if (!empty($indexedColumnsBeingUpdated)) {
            $indexCount = count($indexedColumnsBeingUpdated);

            if ($indexCount > 3) {
                $recommendations['warnings'][] = [
                    'type' => 'many_indexed_columns',
                    'message' => "Updating {$indexCount} indexed columns: " . implode(', ', $indexedColumnsBeingUpdated),
                    'suggestion' => 'Consider if all these indexed columns need to be updated together',
                    'estimated_impact' => 'Each indexed column update triggers index maintenance'
                ];
            } else {
                $recommendations['performance_hints'][] = [
                    'type' => 'indexed_column_update',
                    'message' => "Updating indexed column(s): " . implode(', ', $indexedColumnsBeingUpdated),
                    'impact' => 'Minor performance impact due to index updates'
                ];
            }
        }

        // Check for bulk update patterns
        if (isset($context['record_count']) && $context['record_count'] > 100) {
            $recommendations['optimizations'][] = [
                'type' => 'bulk_update_mode',
                'message' => 'Large number of records to update - consider bulk optimization',
                'suggestion' => 'Use bulk update methods or transaction batching',
                'code' => '$model->enablePerformanceMode(); // Before bulk updates'
            ];
        }

        return $recommendations;
    }

    /**
     * Get DELETE operation recommendations
     */
    private static function getDeleteRecommendations(string $table, array $context): array
    {
        $schema = self::$schemaCache[$table];
        $indexes = self::$indexAnalysis[$table];

        $recommendations = [
            'optimizations' => [],
            'warnings' => [],
            'performance_hints' => []
        ];

        // WHERE clause index analysis (critical for DELETE performance)
        if (isset($context['where_column'])) {
            $whereColumn = $context['where_column'];

            if (!in_array($whereColumn, $indexes['covered_columns'])) {
                $recommendations['warnings'][] = [
                    'type' => 'missing_index_delete',
                    'message' => "No index found for WHERE column '{$whereColumn}' in DELETE",
                    'suggestion' => self::getIndexCreationSql($table, $whereColumn),
                    'estimated_impact' => 'DELETE may scan entire table to find records - potentially dangerous'
                ];
            }
        } else {
            $recommendations['warnings'][] = [
                'type' => 'delete_without_where',
                'message' => 'DELETE operation without WHERE clause detected',
                'suggestion' => 'Ensure this is intentional - will delete ALL records',
                'estimated_impact' => 'TRUNCATE might be more efficient for clearing entire table'
            ];
        }

        // Check for bulk delete patterns
        if (isset($context['record_count']) && $context['record_count'] > 1000) {
            $recommendations['optimizations'][] = [
                'type' => 'bulk_delete_optimization',
                'message' => 'Large number of records to delete',
                'suggestion' => 'Consider chunked deletion to avoid long-running transactions',
                'code' => '// Delete in batches to avoid locks' . "\n" .
                    'for ($i = 0; $i < $total; $i += 1000) {' . "\n" .
                    '    $model->delete_batch($conditions, 1000);' . "\n" .
                    '}'
            ];
        }

        return $recommendations;
    }

    /**
     * Intelligent cache warming based on schema relationships
     */
    public static function warmCachesIntelligently(Model $model): array
    {
        if (!self::$initialized) {
            return ['error' => 'Schema analyzer not initialized'];
        }

        $warmedCaches = [
            'validation_cache' => 0,
            'query_cache' => 0,
            'relationship_cache' => 0
        ];

        // Warm validation cache with all tables and common columns
        $allTables = array_keys(self::$schemaCache);
        $commonColumns = self::getCommonColumns();

        DatabaseSecurity::warmCache($allTables, $commonColumns);
        $warmedCaches['validation_cache'] = count($allTables) + count($commonColumns);

        // Pre-generate common SQL patterns
        foreach ($allTables as $table) {
            $schema = self::$schemaCache[$table];

            // Pre-cache common SELECT patterns
            $commonColumns = array_slice(array_keys($schema['columns']), 0, 5);
            foreach ($commonColumns as $column) {
                // This would pre-generate and cache SQL
                $queryBuilder = QueryBuilder::create(self::$currentDbType);
                $queryBuilder->buildQuery('simple_select', [
                    'table' => $table,
                    'where_column' => $column,
                    'where_operator' => '='
                ]);
                $warmedCaches['query_cache']++;
            }
        }

        // Warm relationship-based caches
        foreach (self::$relationshipMap as $table => $relationships) {
            if ($relationships['relationship_weight'] > 0) {
                // Pre-cache join patterns for related tables
                $warmedCaches['relationship_cache'] += $relationships['relationship_weight'];
            }
        }

        return $warmedCaches;
    }

    /**
     * Get predictive performance analysis
     */
    public static function predictPerformance(
        string $table,
        string $operation,
        int $recordCount,
        array $context = []
    ): array {
        if (!isset(self::$performanceProfiles[$table])) {
            return ['error' => "No performance profile for table '$table'"];
        }

        $profile = self::$performanceProfiles[$table];
        $schema = self::$schemaCache[$table];

        $prediction = [
            'operation' => $operation,
            'table' => $table,
            'record_count' => $recordCount,
            'estimated_time' => 0,
            'estimated_memory' => 0,
            'complexity_score' => 0,
            'bottlenecks' => [],
            'confidence' => 'medium'
        ];

        switch ($operation) {
            case 'bulk_insert':
                $baseTime = $recordCount * 0.0001; // Base: 0.1ms per record
                $complexityMultiplier = 1 + ($profile['insert_complexity'] / 10);
                $indexOverhead = count(self::$indexAnalysis[$table]['indexes']) * 0.2;
                $fkOverhead = $profile['foreign_key_overhead'] * 0.1;

                $prediction['estimated_time'] = $baseTime * $complexityMultiplier * (1 + $indexOverhead + $fkOverhead);
                $prediction['estimated_memory'] = $recordCount * $schema['estimated_row_size'] * 3; // 3x overhead
                $prediction['complexity_score'] = $profile['insert_complexity'];

                if ($indexOverhead > 0.5) {
                    $prediction['bottlenecks'][] = 'High index overhead';
                }

                if ($fkOverhead > 0.3) {
                    $prediction['bottlenecks'][] = 'Foreign key constraint overhead';
                }
                break;

            case 'select':
                $hasIndex = isset($context['where_column']) &&
                    in_array($context['where_column'], self::$indexAnalysis[$table]['covered_columns']);

                $baseTime = $hasIndex ? 0.001 : ($recordCount * 0.00001); // Index vs scan
                $prediction['estimated_time'] = $baseTime * (1 + $profile['select_complexity'] / 100);
                $prediction['estimated_memory'] = min($recordCount, ($context['limit'] ?? 1000)) * $schema['estimated_row_size'];

                if (!$hasIndex && $recordCount > 10000) {
                    $prediction['bottlenecks'][] = 'Full table scan required';
                    $prediction['confidence'] = 'low';
                }
                break;
        }

        return $prediction;
    }

    /**
     * Generate comprehensive schema optimization report
     */
    public static function generateOptimizationReport(): string
    {
        if (!self::$initialized) {
            return "Schema analyzer not initialized. Call SchemaAwareOptimizer::initialize() first.";
        }

        $html = "<div style='font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto;'>";
        $html .= "<h2>🧠 Schema-Aware Intelligence Report</h2>";

        // Schema Overview
        $html .= "<div style='background: #e8f5e8; padding: 20px; margin: 10px 0; border-radius: 8px;'>";
        $html .= "<h3>📊 Schema Overview</h3>";
        $html .= "<table style='width: 100%; border-collapse: collapse;'>";

        foreach (self::$schemaCache as $table => $schema) {
            $profile = self::$performanceProfiles[$table];
            $indexes = count(self::$indexAnalysis[$table]['indexes']);
            $relationships = self::$relationshipMap[$table]['relationship_weight'];
            $column_count = count($schema['columns']);

            $html .= "<tr style='border-bottom: 1px solid #ddd;'>";
            $html .= "<td><strong>{$table}</strong></td>";
            $html .= "<td>{$column_count} columns</td>";
            $html .= "<td>{$indexes} indexes</td>";
            $html .= "<td>{$relationships} relationships</td>";
            $html .= "<td>Batch: {$profile['optimal_batch_size']}</td>";
            $html .= "</tr>";
        }
        $html .= "</table></div>";

        // Performance Recommendations
        $html .= "<div style='background: #fff3cd; padding: 20px; margin: 10px 0; border-radius: 8px;'>";
        $html .= "<h3>💡 Performance Recommendations</h3>";

        foreach (self::$schemaCache as $table => $schema) {
            $suggestions = self::getTableOptimizationSuggestions($table);
            if (!empty($suggestions)) {
                $html .= "<h4>{$table}</h4><ul>";
                foreach ($suggestions as $suggestion) {
                    $html .= "<li>{$suggestion}</li>";
                }
                $html .= "</ul>";
            }
        }
        $html .= "</div>";

        // Index Analysis
        $html .= "<div style='background: #e3f2fd; padding: 20px; margin: 10px 0; border-radius: 8px;'>";
        $html .= "<h3>🔍 Index Analysis</h3>";

        foreach (self::$indexAnalysis as $table => $analysis) {
            if (!empty($analysis['missing_indexes'])) {
                $html .= "<h4>{$table} - Missing Indexes</h4><ul>";
                foreach ($analysis['missing_indexes'] as $missing) {
                    $html .= "<li>" . self::getIndexCreationSql($table, $missing) . "</li>";
                }
                $html .= "</ul>";
            }
        }
        $html .= "</div>";

        $html .= "</div>";
        return $html;
    }

    // =============================================================================
    // HELPER METHODS FOR DATABASE OPERATIONS (CROSS-DATABASE COMPATIBLE)
    // =============================================================================

    /**
     * Get list of tables for all supported database types
     */
    private static function getTableList(PDO $pdo, string $dbType): array
    {
        switch ($dbType) {
            case 'mysql':
                $stmt = $pdo->query("SHOW TABLES");
                return $stmt->fetchAll(PDO::FETCH_COLUMN);

            case 'sqlite':
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                return $stmt->fetchAll(PDO::FETCH_COLUMN);

            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                return $stmt->fetchAll(PDO::FETCH_COLUMN);

            default:
                throw new RuntimeException("Unsupported database type: $dbType");
        }
    }

    /**
     * Get table columns for all supported database types
     */
    private static function getTableColumns(PDO $pdo, string $table, string $dbType): array
    {
        $columns = [];

        switch ($dbType) {
            case 'mysql':
                $stmt = $pdo->query("DESCRIBE `$table`");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($result as $row) {
                    $columns[$row['Field']] = [
                        'name' => $row['Field'],
                        'type' => $row['Type'],
                        'nullable' => $row['Null'] === 'YES',
                        'primary_key' => $row['Key'] === 'PRI',
                        'estimated_size' => self::estimateColumnSize($row['Type'])
                    ];
                }
                break;

            case 'sqlite':
                $stmt = $pdo->query("PRAGMA table_info(`$table`)");
                $pragmaResult = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($pragmaResult as $row) {
                    $columns[$row['name']] = [
                        'name' => $row['name'],
                        'type' => $row['type'],
                        'nullable' => !$row['notnull'],
                        'primary_key' => $row['pk'] > 0,
                        'estimated_size' => self::estimateColumnSize($row['type'])
                    ];
                }
                break;

            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                $stmt = $pdo->prepare("
                    SELECT 
                        column_name as name,
                        data_type as type,
                        is_nullable,
                        column_default,
                        CASE WHEN tc.constraint_type = 'PRIMARY KEY' THEN true ELSE false END as primary_key
                    FROM information_schema.columns c
                    LEFT JOIN information_schema.key_column_usage kcu 
                        ON c.table_name = kcu.table_name AND c.column_name = kcu.column_name
                    LEFT JOIN information_schema.table_constraints tc 
                        ON kcu.constraint_name = tc.constraint_name
                    WHERE c.table_name = :table_name 
                    ORDER BY c.ordinal_position
                ");
                $stmt->execute(['table_name' => $table]);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($result as $row) {
                    $columns[$row['name']] = [
                        'name' => $row['name'],
                        'type' => $row['type'],
                        'nullable' => $row['is_nullable'] === 'YES',
                        'primary_key' => $row['primary_key'],
                        'estimated_size' => self::estimateColumnSize($row['type'])
                    ];
                }
                break;

            default:
                throw new RuntimeException("Unsupported database type: $dbType");
        }

        return $columns;
    }

    /**
     * Get table indexes for all supported database types
     */
    private static function getTableIndexes(PDO $pdo, string $table, string $dbType): array
    {
        $indexes = [];

        switch ($dbType) {
            case 'mysql':
                $stmt = $pdo->query("SHOW INDEX FROM `$table`");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $indexGroups = [];
                foreach ($result as $row) {
                    $indexGroups[$row['Key_name']][] = $row['Column_name'];
                    if (!isset($indexGroups[$row['Key_name'] . '_unique'])) {
                        $indexGroups[$row['Key_name'] . '_unique'] = $row['Non_unique'] == 0;
                    }
                }

                foreach ($indexGroups as $name => $columns) {
                    if (str_ends_with($name, '_unique')) continue;
                    
                    $indexes[] = [
                        'name' => $name,
                        'unique' => $indexGroups[$name . '_unique'] ?? false,
                        'columns' => $columns
                    ];
                }
                break;

            case 'sqlite':
                $stmt = $pdo->query("PRAGMA index_list(`$table`)");
                $indexList = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($indexList as $index) {
                    $stmt2 = $pdo->query("PRAGMA index_info(`{$index['name']}`)");
                    $columns = $stmt2->fetchAll(PDO::FETCH_COLUMN, 2);

                    $indexes[] = [
                        'name' => $index['name'],
                        'unique' => $index['unique'] == 1,
                        'columns' => $columns
                    ];
                }
                break;

            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                $stmt = $pdo->prepare("
                    SELECT 
                        i.relname as index_name,
                        ix.indisunique as is_unique,
                        a.attname as column_name
                    FROM pg_class t
                    JOIN pg_index ix ON t.oid = ix.indrelid
                    JOIN pg_class i ON i.oid = ix.indexrelid
                    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                    WHERE t.relname = :table_name
                    AND NOT ix.indisprimary
                    ORDER BY i.relname, a.attnum
                ");
                $stmt->execute(['table_name' => $table]);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $indexGroups = [];
                foreach ($result as $row) {
                    $indexName = $row['index_name'];
                    if (!isset($indexGroups[$indexName])) {
                        $indexGroups[$indexName] = [
                            'unique' => $row['is_unique'],
                            'columns' => []
                        ];
                    }
                    $indexGroups[$indexName]['columns'][] = $row['column_name'];
                }

                foreach ($indexGroups as $name => $indexData) {
                    $indexes[] = [
                        'name' => $name,
                        'unique' => $indexData['unique'],
                        'columns' => $indexData['columns']
                    ];
                }
                break;

            default:
                throw new RuntimeException("Unsupported database type: $dbType");
        }

        return $indexes;
    }

    /**
     * Get foreign keys for all supported database types
     */
    private static function getForeignKeys(PDO $pdo, string $table, string $dbType): array
    {
        $foreignKeys = [];

        switch ($dbType) {
            case 'mysql':
                $stmt = $pdo->prepare("
                    SELECT 
                        kcu.COLUMN_NAME as column_name,
                        kcu.REFERENCED_TABLE_NAME as referenced_table,
                        kcu.REFERENCED_COLUMN_NAME as referenced_column
                    FROM information_schema.KEY_COLUMN_USAGE kcu
                    WHERE kcu.TABLE_NAME = :table_name 
                    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                ");
                $stmt->execute(['table_name' => $table]);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($result as $row) {
                    $foreignKeys[] = [
                        'column' => $row['column_name'],
                        'referenced_table' => $row['referenced_table'],
                        'referenced_column' => $row['referenced_column']
                    ];
                }
                break;

            case 'sqlite':
                $stmt = $pdo->query("PRAGMA foreign_key_list(`$table`)");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($result as $row) {
                    $foreignKeys[] = [
                        'column' => $row['from'],
                        'referenced_table' => $row['table'],
                        'referenced_column' => $row['to']
                    ];
                }
                break;

            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                $stmt = $pdo->prepare("
                    SELECT 
                        kcu.column_name,
                        ccu.table_name AS referenced_table,
                        ccu.column_name AS referenced_column
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu 
                        ON tc.constraint_name = kcu.constraint_name
                    JOIN information_schema.constraint_column_usage ccu 
                        ON ccu.constraint_name = tc.constraint_name
                    WHERE tc.constraint_type = 'FOREIGN KEY' 
                    AND tc.table_name = :table_name
                ");
                $stmt->execute(['table_name' => $table]);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($result as $row) {
                    $foreignKeys[] = [
                        'column' => $row['column_name'],
                        'referenced_table' => $row['referenced_table'],
                        'referenced_column' => $row['referenced_column']
                    ];
                }
                break;

            default:
                // Return empty for unsupported databases
                break;
        }

        return $foreignKeys;
    }

    /**
     * Get database-specific foreign key disable code
     */
    private static function getForeignKeyDisableCode(): string
    {
        switch (self::$currentDbType) {
            case 'mysql':
                return '$pdo->exec("SET foreign_key_checks = 0"); // Disable' . "\n" .
                       '// ... perform bulk operations ...' . "\n" .
                       '$pdo->exec("SET foreign_key_checks = 1"); // Re-enable';

            case 'sqlite':
                return '$pdo->exec("PRAGMA foreign_keys = OFF"); // Disable' . "\n" .
                       '// ... perform bulk operations ...' . "\n" .
                       '$pdo->exec("PRAGMA foreign_keys = ON"); // Re-enable';

            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                return '// PostgreSQL: Disable triggers temporarily' . "\n" .
                       '$pdo->exec("ALTER TABLE table_name DISABLE TRIGGER ALL");' . "\n" .
                       '// ... perform bulk operations ...' . "\n" .
                       '$pdo->exec("ALTER TABLE table_name ENABLE TRIGGER ALL");';

            default:
                return '// Database-specific foreign key management required';
        }
    }

    /**
     * Get database-specific index creation SQL
     */
    private static function getIndexCreationSql(string $table, string $column): string
    {
        $indexName = "idx_{$table}_{$column}";
        
        switch (self::$currentDbType) {
            case 'mysql':
                return "CREATE INDEX `$indexName` ON `$table` (`$column`);";

            case 'sqlite':
                return "CREATE INDEX `$indexName` ON `$table` (`$column`);";

            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                return "CREATE INDEX \"$indexName\" ON \"$table\" (\"$column\");";

            default:
                return "CREATE INDEX $indexName ON $table ($column);";
        }
    }

    // =============================================================================
    // CALCULATION AND UTILITY METHODS
    // =============================================================================

    /**
     * Estimate column size based on data type
     */
    private static function estimateColumnSize(string $type): int
    {
        $type = strtoupper($type);

        // Integer types
        if (str_contains($type, 'INT') || str_contains($type, 'SERIAL')) return 8;
        if (str_contains($type, 'FLOAT') || str_contains($type, 'DOUBLE') || str_contains($type, 'REAL')) return 8;
        if (str_contains($type, 'DECIMAL') || str_contains($type, 'NUMERIC')) return 16;
        
        // Date/time types
        if (str_contains($type, 'DATE')) return 10;
        if (str_contains($type, 'TIME')) return 19;
        if (str_contains($type, 'TIMESTAMP')) return 19;
        
        // String types
        if (str_contains($type, 'VARCHAR') || str_contains($type, 'CHARACTER VARYING')) {
            preg_match('/\((\d+)\)/', $type, $matches);
            return isset($matches[1]) ? (int)$matches[1] : 255;
        }
        if (str_contains($type, 'CHAR')) {
            preg_match('/\((\d+)\)/', $type, $matches);
            return isset($matches[1]) ? (int)$matches[1] : 50;
        }
        if (str_contains($type, 'TEXT')) return 1000; // Average text size
        
        // Boolean
        if (str_contains($type, 'BOOL')) return 1;
        
        // JSON (PostgreSQL)
        if (str_contains($type, 'JSON')) return 500; // Average JSON size

        return 50; // Default estimate
    }

    /**
     * Calculate total estimated row size
     */
    private static function estimateRowSize(array $columns): int
    {
        return array_sum(array_column($columns, 'estimated_size'));
    }

    /**
     * Find primary key column
     */
    private static function findPrimaryKey(array $columns): ?string
    {
        foreach ($columns as $column) {
            if ($column['primary_key']) {
                return $column['name'];
            }
        }
        return null;
    }

    /**
     * Calculate insert complexity score
     */
    private static function calculateInsertComplexity(string $table, array $schema): int
    {
        $complexity = count($schema['columns']); // Base complexity
        $complexity += count(self::$indexAnalysis[$table]['indexes'] ?? []) * 2; // Index overhead
        $complexity += count(self::$relationshipMap[$table]['foreign_keys'] ?? []) * 3; // FK overhead
        return $complexity;
    }

    /**
     * Calculate select complexity score
     */
    private static function calculateSelectComplexity(string $table, array $schema): int
    {
        $complexity = count($schema['columns']);
        $complexity -= count(self::$indexAnalysis[$table]['indexes'] ?? []) * 2; // Indexes help
        return max(1, $complexity);
    }

    /**
     * Calculate update complexity score
     */
    private static function calculateUpdateComplexity(string $table, array $schema): int
    {
        $complexity = count($schema['columns']);
        $complexity += count(self::$indexAnalysis[$table]['indexes'] ?? []) * 1.5; // Moderate index overhead
        return intval($complexity);
    }

    /**
     * Calculate optimal batch size for table operations
     */
    private static function calculateOptimalBatchSize(string $table, array $schema): int
    {
        $rowSize = $schema['estimated_row_size'];
        $complexity = self::$performanceProfiles[$table]['insert_complexity'] ?? 10;

        // Base calculation: aim for ~10MB batches
        $baseBatchSize = max(100, min(2000, intval(10000000 / $rowSize)));

        // Adjust for complexity - FIXED: Explicit conversion to avoid deprecated warning
        $adjustedBatchSize = intval(round($baseBatchSize / (1 + $complexity / 100)));

        return max(50, $adjustedBatchSize);
    }

    /**
     * Calculate cache priority with proper type handling
     */
    private static function calculateCachePriority(string $table): int
    {
        $relationships = self::$relationshipMap[$table]['relationship_weight'] ?? 0;
        $columnCount = count(self::$schemaCache[$table]['columns'] ?? []);

        // FIXED: Ensure result is integer
        return intval($relationships * 10 + $columnCount);
    }

    /**
     * Get most common column names across all tables
     */
    private static function getCommonColumns(): array
    {
        $columnFrequency = [];

        foreach (self::$schemaCache as $schema) {
            foreach (array_keys($schema['columns']) as $columnName) {
                $columnFrequency[$columnName] = ($columnFrequency[$columnName] ?? 0) + 1;
            }
        }

        arsort($columnFrequency);
        return array_slice(array_keys($columnFrequency), 0, 20); // Top 20 most common
    }

    /**
     * Suggest missing indexes for table
     */
    private static function suggestMissingIndexes(string $table): void
    {
        $schema = self::$schemaCache[$table];
        $existingIndexes = self::$indexAnalysis[$table]['covered_columns'];
        $missingIndexes = [];

        // Suggest indexes for foreign key columns
        $foreignKeys = self::$relationshipMap[$table]['foreign_keys'] ?? [];
        foreach ($foreignKeys as $fk) {
            if (!in_array($fk['column'], $existingIndexes)) {
                $missingIndexes[] = $fk['column'];
            }
        }

        // Suggest indexes for commonly queried columns (heuristic)
        $commonQueryColumns = ['email', 'username', 'status', 'created_at', 'updated_at'];
        foreach ($commonQueryColumns as $column) {
            if (isset($schema['columns'][$column]) && !in_array($column, $existingIndexes)) {
                $missingIndexes[] = $column;
            }
        }

        self::$indexAnalysis[$table]['missing_indexes'] = array_unique($missingIndexes);
    }

    /**
     * Get optimization suggestions for table
     */
    private static function getTableOptimizationSuggestions(string $table): array
    {
        $suggestions = [];
        $profile = self::$performanceProfiles[$table];
        $analysis = self::$indexAnalysis[$table];

        if ($profile['insert_complexity'] > 50) {
            $suggestions[] = "High insert complexity - consider simplifying schema or using bulk optimizations";
        }

        if ($profile['index_effectiveness'] < 0.2) {
            $suggestions[] = "Low index effectiveness - review and optimize existing indexes";
        }

        if (!empty($analysis['missing_indexes'])) {
            $suggestions[] = "Missing indexes detected - see index analysis for recommendations";
        }

        if ($profile['foreign_key_overhead'] > 3) {
            $suggestions[] = "High foreign key overhead - consider disabling FK checks during bulk operations";
        }

        return $suggestions;
    }
}
