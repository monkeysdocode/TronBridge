<?php

/**
 * Enhanced Abstract Query Profiler - Clean Version
 * 
 * No legacy compatibility code - only the modern enhanced suggestion system.
 */
abstract class QueryProfiler
{
    protected ?PDO $pdo;
    protected array $performanceThresholds;
    protected array $analysisCache = [];
    
    // Enhanced suggestion system properties
    protected array $sessionSuggestions = [];
    protected array $suggestionCounts = [];
    protected int $queryCounter = 0;
    
    // Performance thresholds
    const SLOW_QUERY_THRESHOLD = 0.1;  // 100ms
    const VERY_SLOW_THRESHOLD = 1.0;   // 1 second
    const MAX_CACHE_SIZE = 100;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
        $this->performanceThresholds = [
            'slow' => self::SLOW_QUERY_THRESHOLD,
            'very_slow' => self::VERY_SLOW_THRESHOLD,
            'large_result' => 1000,
            'missing_limit' => 10000
        ];
    }

    /**
     * Main analyze method with integrated enhanced suggestion system
     */
    public function analyze(string $sql, array $params, float $executionTime, array $context = []): array
    {
        $this->queryCounter++;
        $queryId = "Q{$this->queryCounter}";
        
        // Check cache first
        $cacheKey = $this->getCacheKey($sql, $context);
        if (isset($this->analysisCache[$cacheKey])) {
            $cached = $this->analysisCache[$cacheKey];
            $cached['execution_time'] = $executionTime;
            $cached['query_id'] = $queryId;
            $cached['slow'] = $executionTime > $this->performanceThresholds['slow'];
            
            // Generate fresh enhanced suggestions
            $cached['enhanced_suggestions'] = $this->generateEnhancedSuggestions($sql, $params, $executionTime, $context, $queryId, $cached['explain_data'] ?? null);
            
            return $cached;
        }

        // Parse SQL for enhanced analysis
        $sqlInfo = $this->parseSqlQuery($sql);

        $analysis = [
            'query_id' => $queryId,
            'execution_time' => $executionTime,
            'slow' => $executionTime > $this->performanceThresholds['slow'],
            'very_slow' => $executionTime > $this->performanceThresholds['very_slow'],
            'enhanced_suggestions' => [],
            'explain_data' => null,
            'query_type' => $sqlInfo['type'],
            'table' => $sqlInfo['table'],
            'sql_info' => $sqlInfo,
            'complexity_score' => $this->calculateComplexityScore($sql)
        ];

        // Database-specific analysis
        $dbSpecificAnalysis = $this->performDatabaseSpecificAnalysis($sql, $params, $context);
        $analysis = array_merge($analysis, $dbSpecificAnalysis);

        // Enhanced suggestion generation
        $analysis['enhanced_suggestions'] = $this->generateEnhancedSuggestions(
            $sql, $params, $executionTime, $context, $queryId, $analysis['explain_data']
        );

        // Store session data
        $this->sessionSuggestions[$queryId] = [
            'sql' => $this->truncateSql($sql),
            'table' => $sqlInfo['table'] ?? 'unknown',
            'execution_time' => $executionTime,
            'suggestions' => $analysis['enhanced_suggestions']
        ];

        // Add session summary every 5 queries
        if ($this->queryCounter % 5 === 0) {
            $analysis['session_summary'] = $this->getSessionSummary();
        }

        // Cache the analysis
        $this->cacheAnalysis($cacheKey, $analysis);

        return $analysis;
    }

    /**
     * Parse SQL query to extract actionable information
     */
    protected function parseSqlQuery(string $sql): array
    {
        $sqlUpper = strtoupper(trim($sql));
        $info = [
            'type' => 'unknown',
            'table' => null,
            'where_columns' => [],
            'order_columns' => [],
            'has_limit' => false,
            'has_select_star' => false,
            'join_tables' => [],
            'where_operators' => []
        ];
        
        // Detect query type
        if (str_starts_with($sqlUpper, 'SELECT')) {
            $info['type'] = 'select';
            
            // Extract table name
            if (preg_match('/FROM\s+`?([a-zA-Z_]\w*)`?/i', $sql, $matches)) {
                $info['table'] = $matches[1];
            }
            
            // Check for SELECT *
            if (str_contains($sqlUpper, 'SELECT *')) {
                $info['has_select_star'] = true;
            }
            
            // Extract WHERE columns with operators
            if (preg_match_all('/WHERE\s+.*?`?([a-zA-Z_]\w*)`?\s*([=<>!]+|LIKE|IN)/i', $sql, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $column = $match[1];
                    $operator = strtoupper($match[2]);
                    $info['where_columns'][] = $column;
                    $info['where_operators'][$column] = $operator;
                }
                $info['where_columns'] = array_unique($info['where_columns']);
            }
            
            // Extract ORDER BY columns
            if (preg_match_all('/ORDER\s+BY\s+`?([a-zA-Z_]\w*)`?/i', $sql, $matches)) {
                $info['order_columns'] = array_unique($matches[1]);
            }
            
            // Check for LIMIT
            $info['has_limit'] = str_contains($sqlUpper, 'LIMIT');
            
            // Detect JOINs
            if (preg_match_all('/JOIN\s+`?([a-zA-Z_]\w*)`?/i', $sql, $matches)) {
                $info['join_tables'] = array_unique($matches[1]);
            }
        } elseif (str_starts_with($sqlUpper, 'INSERT')) {
            $info['type'] = 'insert';
            if (preg_match('/INSERT\s+INTO\s+`?([a-zA-Z_]\w*)`?/i', $sql, $matches)) {
                $info['table'] = $matches[1];
            }
        } elseif (str_starts_with($sqlUpper, 'UPDATE')) {
            $info['type'] = 'update';
            if (preg_match('/UPDATE\s+`?([a-zA-Z_]\w*)`?/i', $sql, $matches)) {
                $info['table'] = $matches[1];
            }
        } elseif (str_starts_with($sqlUpper, 'DELETE')) {
            $info['type'] = 'delete';
            if (preg_match('/DELETE\s+FROM\s+`?([a-zA-Z_]\w*)`?/i', $sql, $matches)) {
                $info['table'] = $matches[1];
            }
        }
        
        return $info;
    }

    /**
     * Generate enhanced suggestions with query context and priorities
     */
    protected function generateEnhancedSuggestions(
        string $sql, 
        array $params, 
        float $executionTime, 
        array $context,
        string $queryId,
        ?array $explainData = null
    ): array {
        $suggestions = [];
        $sqlInfo = $this->parseSqlQuery($sql);

        // 1. Performance-based suggestions (highest priority)
        $suggestions = array_merge($suggestions, $this->generatePerformanceBasedSuggestions($sql, $sqlInfo, $executionTime, $queryId));

        // 2. Index recommendations
        $suggestions = array_merge($suggestions, $this->generateIndexSuggestions($sql, $sqlInfo, $explainData, $executionTime, $queryId));

        // 3. Query structure improvements
        $suggestions = array_merge($suggestions, $this->generateStructureSuggestions($sql, $sqlInfo, $executionTime, $queryId));

        // 4. Database-specific suggestions from EXPLAIN
        if ($explainData) {
            $suggestions = array_merge($suggestions, $this->generateExplainBasedSuggestions($explainData, $sqlInfo, $queryId));
        }

        return $suggestions;
    }

    /**
     * Generate performance-based suggestions
     */
    protected function generatePerformanceBasedSuggestions(string $sql, array $sqlInfo, float $executionTime, string $queryId): array
    {
        $suggestions = [];
        $timeMs = round($executionTime * 1000, 1);
        $table = $sqlInfo['table'] ?? 'table';
        
        if ($executionTime > 1.0) {
            $suggestions[] = [
                'type' => 'performance_critical',
                'priority' => 'critical',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "🚨 Query {$queryId}: VERY SLOW query on '{$table}' ({$timeMs}ms) - immediate optimization needed",
                'explanation' => "Queries over 1 second require immediate attention",
                'recommendation' => 'Review EXPLAIN plan and consider major optimization'
            ];
        } elseif ($executionTime > 0.1) {
            $suggestions[] = [
                'type' => 'performance_warning',
                'priority' => 'high',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "⚠️ Query {$queryId}: Slow query on '{$table}' ({$timeMs}ms) - optimization recommended",
                'explanation' => "Queries over 100ms impact user experience"
            ];
        }
        
        return $suggestions;
    }

    /**
     * Generate index-specific suggestions
     */
    protected function generateIndexSuggestions(string $sql, array $sqlInfo, ?array $explainData, float $executionTime, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        $timeMs = round($executionTime * 1000, 1);
        
        // Suggest index for WHERE columns
        if (!empty($sqlInfo['where_columns'])) {
            foreach ($sqlInfo['where_columns'] as $column) {
                $indexKey = "{$table}.{$column}";
                if (!isset($this->suggestionCounts[$indexKey])) {
                    $this->suggestionCounts[$indexKey] = 0;
                }
                
                $this->suggestionCounts[$indexKey]++;
                
                // Only suggest first time or if slow query
                if ($this->suggestionCounts[$indexKey] === 1 || $executionTime > 0.1) {
                    $operator = $sqlInfo['where_operators'][$column] ?? '=';
                    $indexType = $operator === 'LIKE' ? 'for LIKE searches' : 'for equality searches';
                    
                    $suggestions[] = [
                        'type' => 'index_recommendation',
                        'priority' => $executionTime > 0.1 ? 'high' : 'medium',
                        'query_id' => $queryId,
                        'table' => $table,
                        'column' => $column,
                        'message' => "Query {$queryId}: CREATE INDEX idx_{$table}_{$column} ON {$table} ({$column}) -- {$indexType} ({$timeMs}ms)",
                        'sql' => "CREATE INDEX idx_{$table}_{$column} ON {$table} ({$column});",
                        'explanation' => "Index on '{$column}' will speed up WHERE clause filtering",
                        'affected_count' => $this->suggestionCounts[$indexKey]
                    ];
                }
            }
        }
        
        // Suggest composite index for WHERE + ORDER BY
        if (!empty($sqlInfo['where_columns']) && !empty($sqlInfo['order_columns'])) {
            $whereCol = $sqlInfo['where_columns'][0];
            $orderCol = $sqlInfo['order_columns'][0];
            
            if ($whereCol !== $orderCol) {
                $suggestions[] = [
                    'type' => 'index_recommendation',
                    'priority' => 'high',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: Composite index for WHERE + ORDER BY ({$timeMs}ms)",
                    'sql' => "CREATE INDEX idx_{$table}_{$whereCol}_{$orderCol} ON {$table} ({$whereCol}, {$orderCol});",
                    'explanation' => "Composite index covers both filtering and sorting operations",
                    'impact' => 'Eliminates separate sorting step'
                ];
            }
        }
        
        return $suggestions;
    }

    /**
     * Generate query structure suggestions
     */
    protected function generateStructureSuggestions(string $sql, array $sqlInfo, float $executionTime, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        $timeMs = round($executionTime * 1000, 1);
        
        // SELECT * suggestions
        if ($sqlInfo['has_select_star']) {
            $suggestions[] = [
                'type' => 'query_structure',
                'priority' => 'medium',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId} on '{$table}': Replace SELECT * with specific columns ({$timeMs}ms)",
                'explanation' => "SELECT * retrieves all columns, increasing memory usage and transfer time",
                'example' => "-- Instead of: SELECT * FROM {$table}\n-- Use: SELECT id, name, email FROM {$table}",
                'impact' => 'Reduces memory usage and improves query performance'
            ];
        }
        
        // Missing LIMIT suggestions
        if ($sqlInfo['type'] === 'select' && !$sqlInfo['has_limit'] && empty($sqlInfo['where_columns'])) {
            $suggestions[] = [
                'type' => 'query_structure',
                'priority' => 'medium',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId} on '{$table}': Add LIMIT clause to prevent large result sets ({$timeMs}ms)",
                'example' => "-- Add: LIMIT 100  -- or appropriate limit for your use case",
                'explanation' => "Queries without LIMIT can return unexpectedly large result sets"
            ];
        }
        
        // Complex JOIN suggestions
        if (!empty($sqlInfo['join_tables'])) {
            $joinCount = count($sqlInfo['join_tables']);
            if ($joinCount > 3) {
                $suggestions[] = [
                    'type' => 'query_structure',
                    'priority' => 'high',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: Complex JOIN with {$joinCount} tables - consider denormalization ({$timeMs}ms)",
                    'explanation' => "Queries with many JOINs can be expensive",
                    'impact' => 'Consider breaking into smaller queries or denormalizing data'
                ];
            }
        }
        
        return $suggestions;
    }

    /**
     * Get session summary with grouped suggestions
     */
    public function getSessionSummary(): array
    {
        $summary = [
            'total_queries' => $this->queryCounter,
            'suggestions_by_type' => [],
            'index_recommendations' => [],
            'performance_issues' => [],
            'query_details' => $this->sessionSuggestions
        ];
        
        // Group suggestions by type
        foreach ($this->sessionSuggestions as $queryId => $queryData) {
            foreach ($queryData['suggestions'] as $suggestion) {
                $type = $suggestion['type'] ?? 'general';
                if (!isset($summary['suggestions_by_type'][$type])) {
                    $summary['suggestions_by_type'][$type] = [];
                }
                $summary['suggestions_by_type'][$type][] = $suggestion;
                
                // Collect index recommendations
                if ($type === 'index_recommendation' && isset($suggestion['sql'])) {
                    $summary['index_recommendations'][] = $suggestion['sql'];
                }
                
                // Collect performance issues
                if (in_array($type, ['performance_critical', 'performance_warning'])) {
                    $summary['performance_issues'][] = $suggestion;
                }
            }
        }
        
        // Remove duplicates
        $summary['index_recommendations'] = array_unique($summary['index_recommendations']);
        
        return $summary;
    }

    /**
     * Clear session data
     */
    public function clearSession(): void
    {
        $this->sessionSuggestions = [];
        $this->suggestionCounts = [];
        $this->queryCounter = 0;
    }

    /**
     * Truncate SQL for display
     */
    protected function truncateSql(string $sql, int $maxLength = 80): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return strlen($sql) > $maxLength ? substr($sql, 0, $maxLength) . '...' : $sql;
    }

    /**
     * Calculate query complexity score
     */
    protected function calculateComplexityScore(string $sql): int
    {
        $score = 0;
        $sqlUpper = strtoupper($sql);
        
        $score += 10; // Base score
        $score += substr_count($sqlUpper, 'JOIN') * 15;
        $score += substr_count($sqlUpper, 'SUBQUERY') * 20;
        $score += substr_count($sqlUpper, 'UNION') * 10;
        $score += substr_count($sqlUpper, 'GROUP BY') * 10;
        $score += substr_count($sqlUpper, 'ORDER BY') * 5;
        $score += substr_count($sqlUpper, 'HAVING') * 10;
        
        return min($score, 100);
    }

    /**
     * Generate cache key for analysis caching
     */
    protected function getCacheKey(string $sql, array $context): string
    {
        $normalizedSql = preg_replace('/:\w+/', '?', $sql); // Replace named params
        return hash('xxh3', $normalizedSql . serialize($context['table'] ?? ''));
    }

    /**
     * Cache analysis results
     */
    protected function cacheAnalysis(string $cacheKey, array $analysis): void
    {
        $cacheData = $analysis;
        unset($cacheData['execution_time']);
        unset($cacheData['query_id']);
        
        $this->analysisCache[$cacheKey] = $cacheData;
        
        // Limit cache size
        if (count($this->analysisCache) > self::MAX_CACHE_SIZE) {
            array_shift($this->analysisCache);
        }
    }

    // ========================================================================
    // ABSTRACT METHODS - Must be implemented by database-specific profilers
    // ========================================================================

    /**
     * Database-specific analysis implementation
     */
    abstract protected function performDatabaseSpecificAnalysis(string $sql, array $params, array $context): array;

    /**
     * Get EXPLAIN plan for query (database-specific implementation)
     */
    abstract protected function getExplainPlan(string $sql, array $params): ?array;

    /**
     * Generate suggestions based on EXPLAIN plan data
     * Override in database-specific profilers for detailed analysis
     */
    protected function generateExplainBasedSuggestions(array $explainData, array $sqlInfo, string $queryId): array
    {
        // Base implementation - override in database-specific profilers
        return [];
    }
}


/**
 * Generic Query Profiler Implementation
 * 
 * Fallback profiler for unsupported databases. Provides enhanced suggestions
 * based on query patterns without database-specific EXPLAIN analysis.
 */
class GenericQueryProfiler extends QueryProfiler
{
    /**
     * Perform generic query analysis without database-specific features
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param array $context Query context
     * @return array Generic analysis results
     */
    protected function performDatabaseSpecificAnalysis(string $sql, array $params, array $context): array
    {
        return [
            'explain_data' => null, // No EXPLAIN analysis for unknown databases
            'generic_suggestions' => []
        ];
    }

    /**
     * No EXPLAIN plan available for generic databases
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array|null Always returns null for generic profiler
     */
    protected function getExplainPlan(string $sql, array $params): ?array
    {
        return null; // Cannot provide EXPLAIN plans for unknown database types
    }

    /**
     * Generate enhanced suggestions for generic databases
     * 
     * @param array $explainData EXPLAIN plan data (always null for generic)
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Enhanced suggestions based on query patterns
     */
    protected function generateExplainBasedSuggestions(array $explainData, array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        
        // Since we can't analyze EXPLAIN plans, provide general database optimization advice
        $suggestions[] = [
            'type' => 'database_info',
            'priority' => 'low',
            'query_id' => $queryId,
            'table' => $table,
            'message' => "Query {$queryId}: Database type not recognized - EXPLAIN analysis unavailable",
            'explanation' => "Enhanced query analysis requires MySQL, PostgreSQL, or SQLite",
            'recommendation' => 'Consider using database-specific tools for detailed query analysis'
        ];
        
        // Add generic optimization suggestions based on query patterns
        $suggestions = array_merge($suggestions, $this->generateGenericOptimizationSuggestions($sqlInfo, $queryId));
        
        return $suggestions;
    }

    /**
     * Generate generic optimization suggestions that work across databases
     * 
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Generic optimization suggestions
     */
    private function generateGenericOptimizationSuggestions(array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        
        // Generic index recommendations based on WHERE clauses
        if (!empty($sqlInfo['where_columns'])) {
            foreach ($sqlInfo['where_columns'] as $column) {
                $operator = $sqlInfo['where_operators'][$column] ?? '=';
                $indexType = $operator === 'LIKE' ? 'for text searches' : 'for filtering';
                
                $suggestions[] = [
                    'type' => 'generic_index_recommendation',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $table,
                    'column' => $column,
                    'message' => "Query {$queryId}: Consider index on '{$table}.{$column}' {$indexType}",
                    'explanation' => "Index on '{$column}' will speed up WHERE clause filtering",
                    'sql' => "-- Database-specific syntax: CREATE INDEX idx_{$table}_{$column} ON {$table} ({$column});",
                    'recommendation' => 'Consult your database documentation for proper CREATE INDEX syntax'
                ];
            }
        }
        
        // Generic composite index suggestion for WHERE + ORDER BY
        if (!empty($sqlInfo['where_columns']) && !empty($sqlInfo['order_columns'])) {
            $whereCol = $sqlInfo['where_columns'][0];
            $orderCol = $sqlInfo['order_columns'][0];
            
            if ($whereCol !== $orderCol) {
                $suggestions[] = [
                    'type' => 'generic_index_recommendation',
                    'priority' => 'high',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: Consider composite index on '{$table}' for WHERE + ORDER BY",
                    'explanation' => "Composite index covers both filtering and sorting operations",
                    'sql' => "-- Database-specific syntax: CREATE INDEX idx_{$table}_composite ON {$table} ({$whereCol}, {$orderCol});",
                    'impact' => 'Eliminates separate sorting step and improves query performance'
                ];
            }
        }
        
        // Generic ORDER BY optimization
        if (!empty($sqlInfo['order_columns']) && empty($sqlInfo['where_columns'])) {
            $orderColumns = implode(', ', $sqlInfo['order_columns']);
            $suggestions[] = [
                'type' => 'generic_index_recommendation',
                'priority' => 'medium',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: Consider index on ORDER BY columns ({$orderColumns})",
                'explanation' => "Index on ORDER BY columns can eliminate sorting operations",
                'sql' => "-- Database-specific syntax: CREATE INDEX idx_{$table}_order ON {$table} ({$orderColumns});",
                'impact' => 'Provides pre-sorted data, eliminating sort operations'
            ];
        }
        
        // Generic LIKE pattern warning
        if (!empty($sqlInfo['where_columns'])) {
            foreach ($sqlInfo['where_columns'] as $column) {
                if (($sqlInfo['where_operators'][$column] ?? '') === 'LIKE') {
                    $suggestions[] = [
                        'type' => 'generic_optimization',
                        'priority' => 'medium',
                        'query_id' => $queryId,
                        'table' => $table,
                        'message' => "Query {$queryId}: LIKE pattern on '{$column}' - be aware of performance implications",
                        'explanation' => "LIKE patterns, especially with leading wildcards, can be slow",
                        'recommendation' => 'Consider full-text search features if your database supports them'
                    ];
                }
            }
        }
        
        // Generic JOIN optimization advice
        if (!empty($sqlInfo['join_tables'])) {
            $joinCount = count($sqlInfo['join_tables']);
            
            if ($joinCount > 3) {
                $suggestions[] = [
                    'type' => 'generic_optimization',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: Complex JOIN with {$joinCount} tables - consider optimization strategies",
                    'explanation' => "Queries with many JOINs can be expensive and may benefit from optimization",
                    'recommendation' => 'Consider: indexes on join columns, query restructuring, or denormalization'
                ];
            }
            
            // Suggest indexes on join columns
            $suggestions[] = [
                'type' => 'generic_index_recommendation',
                'priority' => 'medium',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: JOIN operations detected - ensure indexes on join columns",
                'explanation' => "JOINs perform better when both tables have indexes on the join columns",
                'recommendation' => 'Create indexes on foreign key columns used in JOIN conditions'
            ];
        }
        
        // Generic subquery advice
        if (str_contains(strtoupper($sqlInfo['type'] ?? ''), 'SELECT') && 
            (str_contains(strtoupper($sqlInfo['type'] ?? ''), 'SELECT') && 
             preg_match('/\([^)]*SELECT/i', $sqlInfo['type'] ?? ''))) {
            
            $suggestions[] = [
                'type' => 'generic_optimization',
                'priority' => 'medium',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: Subquery detected - consider JOIN alternative",
                'explanation' => "Subqueries can sometimes be rewritten as JOINs for better performance",
                'recommendation' => 'Evaluate if subquery can be rewritten as JOIN or EXISTS clause'
            ];
        }
        
        // Generic performance advice based on query structure
        if ($sqlInfo['has_select_star'] && !empty($sqlInfo['join_tables'])) {
            $suggestions[] = [
                'type' => 'generic_optimization',
                'priority' => 'high',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: SELECT * with JOINs - high risk for performance issues",
                'explanation' => "SELECT * with JOINs can retrieve excessive data and impact performance significantly",
                'recommendation' => 'Specify only needed columns, especially important with JOINs'
            ];
        }
        
        // Generic advice for queries without WHERE or LIMIT
        if ($sqlInfo['type'] === 'select' && empty($sqlInfo['where_columns']) && !$sqlInfo['has_limit']) {
            $suggestions[] = [
                'type' => 'generic_optimization',
                'priority' => 'high',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: SELECT without WHERE or LIMIT - potential full table scan",
                'explanation' => "Queries without filtering or limits can return large result sets and impact performance",
                'recommendation' => 'Add appropriate WHERE conditions and/or LIMIT clause'
            ];
        }
        
        return $suggestions;
    }
}



/**
 * Clean SQLite Query Profiler Implementation
 * 
 * Enhanced QueryProfiler for SQLite with EXPLAIN QUERY PLAN analysis
 * and SQLite-specific optimization suggestions.
 */
class SQLiteQueryProfiler extends QueryProfiler
{
    /**
     * Perform SQLite-specific query analysis
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param array $context Query context
     * @return array SQLite-specific analysis results
     */
    protected function performDatabaseSpecificAnalysis(string $sql, array $params, array $context): array
    {
        $analysis = [
            'explain_data' => null,
            'sqlite_specific_suggestions' => []
        ];

        // Get EXPLAIN QUERY PLAN for SELECT queries
        if ($this->pdo && str_starts_with(strtoupper(trim($sql)), 'SELECT')) {
            $analysis['explain_data'] = $this->getExplainPlan($sql, $params);
        }

        return $analysis;
    }

    /**
     * Get SQLite EXPLAIN QUERY PLAN
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array|null EXPLAIN plan or null if error
     */
    protected function getExplainPlan(string $sql, array $params): ?array
    {
        try {
            $explainSql = "EXPLAIN QUERY PLAN " . $sql;
            $stmt = $this->pdo->prepare($explainSql);
            
            // Bind parameters for both named and positional parameters
            foreach ($params as $key => $value) {
                if (is_string($key)) {
                    // Named parameter - ensure it has colon prefix
                    $paramName = str_starts_with($key, ':') ? $key : ':' . $key;
                    $stmt->bindValue($paramName, $value);
                } else {
                    // Positional parameter
                    $stmt->bindValue($key + 1, $value);
                }
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            if (function_exists('error_log')) {
                error_log("SQLite EXPLAIN QUERY PLAN failed: " . $e->getMessage() . " for SQL: " . $sql);
            }
            return null;
        }
    }

    /**
     * Enhanced EXPLAIN-based suggestions for SQLite
     * 
     * @param array $explainData EXPLAIN plan data
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Enhanced suggestions based on EXPLAIN plan
     */
    protected function generateExplainBasedSuggestions(array $explainData, array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        
        foreach ($explainData as $row) {
            $detail = $row['detail'] ?? '';
            $selectId = $row['selectid'] ?? 0;
            $order = $row['order'] ?? 0;
            
            // Table scan detection with specific recommendations
            if (str_contains($detail, 'SCAN TABLE')) {
                // Extract table name from detail
                if (preg_match('/SCAN TABLE (\w+)/', $detail, $matches)) {
                    $scannedTable = $matches[1];
                    
                    $suggestions[] = [
                        'type' => 'explain_analysis',
                        'priority' => 'high',
                        'query_id' => $queryId,
                        'table' => $scannedTable,
                        'message' => "Query {$queryId}: Full table scan on '{$scannedTable}' - add index on filtered columns",
                        'explanation' => "SQLite is scanning entire table instead of using an index",
                        'detail' => $detail,
                        'recommendation' => "Consider adding index on WHERE clause columns"
                    ];
                    
                    // Suggest specific index if we have WHERE columns
                    if (!empty($sqlInfo['where_columns'])) {
                        $whereCol = $sqlInfo['where_columns'][0];
                        $suggestions[] = [
                            'type' => 'index_recommendation',
                            'priority' => 'high',
                            'query_id' => $queryId,
                            'table' => $scannedTable,
                            'message' => "Query {$queryId}: CREATE INDEX idx_{$scannedTable}_{$whereCol} ON {$scannedTable} ({$whereCol}) -- to eliminate table scan",
                            'sql' => "CREATE INDEX idx_{$scannedTable}_{$whereCol} ON {$scannedTable} ({$whereCol});",
                            'explanation' => "Index on '{$whereCol}' will eliminate full table scan",
                            'impact' => "Transforms table scan into efficient index lookup"
                        ];
                    }
                }
            }
            
            // Automatic covering index detection
            if (str_contains($detail, 'AUTOMATIC COVERING INDEX')) {
                if (preg_match('/AUTOMATIC COVERING INDEX ON (\w+)\(([^)]+)\)/', $detail, $matches)) {
                    $indexTable = $matches[1];
                    $indexColumns = $matches[2];
                    
                    $suggestions[] = [
                        'type' => 'index_recommendation',
                        'priority' => 'medium',
                        'query_id' => $queryId,
                        'table' => $indexTable,
                        'message' => "Query {$queryId}: SQLite created automatic index - consider making permanent",
                        'sql' => "CREATE INDEX idx_{$indexTable}_auto ON {$indexTable} ({$indexColumns});",
                        'explanation' => "SQLite automatically created a covering index for this query",
                        'detail' => $detail,
                        'impact' => 'Making this index permanent will improve performance for similar queries'
                    ];
                }
            }
            
            // Temporary B-tree for ORDER BY
            if (str_contains($detail, 'USE TEMP B-TREE FOR ORDER BY')) {
                $orderColumns = implode(', ', $sqlInfo['order_columns'] ?? []);
                
                $suggestions[] = [
                    'type' => 'index_recommendation',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: Temporary sorting required - create index on ORDER BY columns",
                    'sql' => $orderColumns ? "CREATE INDEX idx_{$table}_order ON {$table} ({$orderColumns});" : null,
                    'explanation' => "SQLite is creating temporary B-tree for sorting",
                    'detail' => $detail,
                    'impact' => 'Index on ORDER BY columns eliminates temporary sorting'
                ];
            }
            
            // Search using index (positive feedback)
            if (str_contains($detail, 'SEARCH TABLE') && str_contains($detail, 'USING INDEX')) {
                if (preg_match('/SEARCH TABLE (\w+) USING INDEX ([^(]+)/', $detail, $matches)) {
                    $searchTable = $matches[1];
                    $indexName = trim($matches[2]);
                    
                    $suggestions[] = [
                        'type' => 'positive_feedback',
                        'priority' => 'low',
                        'query_id' => $queryId,
                        'table' => $searchTable,
                        'message' => "Query {$queryId}: ✅ Efficiently using index '{$indexName}' on '{$searchTable}'",
                        'explanation' => "This query is optimized and using indexes effectively",
                        'detail' => $detail
                    ];
                }
            }
            
            // Covering index usage (excellent performance)
            if (str_contains($detail, 'USING COVERING INDEX')) {
                if (preg_match('/USING COVERING INDEX ([^(]+)/', $detail, $matches)) {
                    $indexName = trim($matches[1]);
                    
                    $suggestions[] = [
                        'type' => 'positive_feedback',
                        'priority' => 'low',
                        'query_id' => $queryId,
                        'table' => $table,
                        'message' => "Query {$queryId}: ⭐ Excellent! Using covering index '{$indexName}' - no table access needed",
                        'explanation' => "Covering index provides all needed columns without accessing table data",
                        'detail' => $detail,
                        'impact' => 'This is optimal performance for this query pattern'
                    ];
                }
            }
            
            // Complex subquery detection
            if ($selectId > 0) {
                $suggestions[] = [
                    'type' => 'query_structure',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: Subquery detected (selectid={$selectId}) - consider JOIN or EXISTS",
                    'explanation' => "Subqueries can sometimes be rewritten as JOINs for better performance",
                    'detail' => $detail,
                    'recommendation' => 'Evaluate if subquery can be rewritten as JOIN'
                ];
            }
        }
        
        // Add SQLite-specific optimization suggestions
        $suggestions = array_merge($suggestions, $this->generateSQLiteSpecificSuggestions($sqlInfo, $queryId));
        
        return $suggestions;
    }

    /**
     * SQLite-specific optimization suggestions
     * 
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array SQLite-specific suggestions
     */
    private function generateSQLiteSpecificSuggestions(array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        
        // SQLite FTS recommendation for text search
        if (!empty($sqlInfo['where_columns'])) {
            $likeColumns = array_filter($sqlInfo['where_columns'], function($col) use ($sqlInfo) {
                return ($sqlInfo['where_operators'][$col] ?? '') === 'LIKE';
            });
            
            foreach ($likeColumns as $column) {
                $suggestions[] = [
                    'type' => 'sqlite_optimization',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: LIKE pattern on '{$column}' - consider SQLite FTS for text content search",
                    'explanation' => "For text search, SQLite FTS (Full-Text Search) may be more efficient than LIKE",
                    'sql' => "-- Consider: CREATE VIRTUAL TABLE {$table}_fts USING fts5({$column});",
                    'impact' => 'FTS provides faster and more sophisticated text searching'
                ];
            }
        }
        
        // WAL mode recommendation for write operations
        if (in_array($sqlInfo['type'], ['insert', 'update', 'delete'])) {
            $suggestions[] = [
                'type' => 'sqlite_configuration',
                'priority' => 'low',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: Write operation detected - ensure WAL mode for better concurrency",
                'sql' => "PRAGMA journal_mode=WAL;",
                'explanation' => "WAL mode allows concurrent readers during writes",
                'impact' => 'Improves performance for applications with mixed read/write workloads'
            ];
        }
        
        // SQLite limits and optimization
        if ($sqlInfo['type'] === 'select' && !empty($sqlInfo['where_columns']) && count($sqlInfo['where_columns']) > 1) {
            $suggestions[] = [
                'type' => 'sqlite_optimization',
                'priority' => 'low',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: Multiple WHERE conditions - be aware of SQLite expression complexity limits",
                'explanation' => "SQLite has limits on expression complexity that may affect very complex WHERE clauses",
                'recommendation' => 'Consider breaking complex conditions into simpler parts if performance issues occur'
            ];
        }
        
        // ANALYZE recommendation for better query planning
        if (!empty($sqlInfo['where_columns']) || !empty($sqlInfo['join_tables'])) {
            $suggestions[] = [
                'type' => 'sqlite_maintenance',
                'priority' => 'low',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: Complex query detected - ensure statistics are up to date",
                'sql' => "ANALYZE {$table};",
                'explanation' => "SQLite uses statistics for query optimization - keeping them current improves performance",
                'impact' => 'Updated statistics help SQLite choose better query execution plans'
            ];
        }
        
        return $suggestions;
    }
}



/**
 * Clean MySQL Query Profiler Implementation
 * 
 * Enhanced QueryProfiler for MySQL with no legacy compatibility code.
 * Only the modern enhanced suggestion system.
 */
class MySQLQueryProfiler extends QueryProfiler
{
    /**
     * Perform MySQL-specific query analysis
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param array $context Query context
     * @return array MySQL-specific analysis results
     */
    protected function performDatabaseSpecificAnalysis(string $sql, array $params, array $context): array
    {
        $analysis = [
            'explain_data' => null,
            'mysql_specific_suggestions' => []
        ];

        // Get EXPLAIN plan for SELECT queries
        if ($this->pdo && str_starts_with(strtoupper(trim($sql)), 'SELECT')) {
            $analysis['explain_data'] = $this->getExplainPlan($sql, $params);
            
            // Legacy suggestions are handled by the base class enhanced system
            // No need for separate mysql_specific_suggestions
        }

        return $analysis;
    }

    /**
     * Get MySQL EXPLAIN plan
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array|null EXPLAIN plan or null if error
     */
    protected function getExplainPlan(string $sql, array $params): ?array
    {
        try {
            // First try EXPLAIN FORMAT=JSON for detailed information
            $explainSql = "EXPLAIN FORMAT=JSON " . $sql;
            $stmt = $this->pdo->prepare($explainSql);
            
            // Bind parameters for both named and positional parameters
            foreach ($params as $key => $value) {
                if (is_string($key)) {
                    // Named parameter - ensure it has colon prefix
                    $paramName = str_starts_with($key, ':') ? $key : ':' . $key;
                    $stmt->bindValue($paramName, $value);
                } else {
                    // Positional parameter
                    $stmt->bindValue($key + 1, $value);
                }
            }
            
            $stmt->execute();
            $result = $stmt->fetchColumn();
            
            return $result ? json_decode($result, true) : null;
            
        } catch (PDOException $e) {
            // Fallback to tabular EXPLAIN if JSON format fails
            if (function_exists('error_log')) {
                error_log("MySQL EXPLAIN FORMAT=JSON failed: " . $e->getMessage() . ", trying tabular format");
            }
            
            try {
                $explainSql = "EXPLAIN " . $sql;
                $stmt = $this->pdo->prepare($explainSql);
                
                // Bind parameters again for fallback
                foreach ($params as $key => $value) {
                    if (is_string($key)) {
                        $paramName = str_starts_with($key, ':') ? $key : ':' . $key;
                        $stmt->bindValue($paramName, $value);
                    } else {
                        $stmt->bindValue($key + 1, $value);
                    }
                }
                
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (PDOException $e2) {
                if (function_exists('error_log')) {
                    error_log("MySQL EXPLAIN fallback also failed: " . $e2->getMessage());
                }
                return null;
            }
        }
    }

    /**
     * Enhanced EXPLAIN-based suggestions for MySQL
     * 
     * @param array $explainData EXPLAIN plan data
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Enhanced suggestions based on EXPLAIN plan
     */
    protected function generateExplainBasedSuggestions(array $explainData, array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        
        // Handle both JSON and tabular EXPLAIN formats
        if (isset($explainData['query_block'])) {
            // JSON format analysis
            $suggestions = $this->analyzeMySQLJsonExplain($explainData, $sqlInfo, $queryId);
        } else {
            // Tabular format analysis
            $suggestions = $this->analyzeMySQLTabularExplain($explainData, $sqlInfo, $queryId);
        }
        
        // Add MySQL-specific optimization suggestions
        $suggestions = array_merge($suggestions, $this->generateMySQLSpecificSuggestions($sql ?? '', $sqlInfo, 0, $queryId));
        
        return $suggestions;
    }

    /**
     * Analyze MySQL JSON EXPLAIN format
     * 
     * @param array $explainData JSON EXPLAIN data
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Enhanced suggestions
     */
    private function analyzeMySQLJsonExplain(array $explainData, array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        
        // Analyze query block
        if (isset($explainData['query_block']['table'])) {
            $tableInfo = $explainData['query_block']['table'];
            $tableName = $tableInfo['table_name'] ?? $table;
            $accessType = $tableInfo['access_type'] ?? 'unknown';
            $rowsExamined = $tableInfo['rows_examined_per_scan'] ?? 0;
            $usedKey = $tableInfo['key'] ?? null;
            
            switch ($accessType) {
                case 'ALL':
                    $suggestions[] = [
                        'type' => 'explain_analysis',
                        'priority' => 'high',
                        'query_id' => $queryId,
                        'table' => $tableName,
                        'message' => "Query {$queryId}: Full table scan on '{$tableName}' ({$rowsExamined} rows examined) - add appropriate indexes",
                        'explanation' => "MySQL is scanning entire table instead of using an index",
                        'detail' => "Access type: ALL, Rows examined: {$rowsExamined}",
                        'recommendation' => 'Add index on WHERE clause columns'
                    ];
                    
                    // Suggest specific index based on WHERE columns
                    if (!empty($sqlInfo['where_columns'])) {
                        $whereCol = $sqlInfo['where_columns'][0];
                        $suggestions[] = [
                            'type' => 'index_recommendation',
                            'priority' => 'high',
                            'query_id' => $queryId,
                            'table' => $tableName,
                            'message' => "Query {$queryId}: CREATE INDEX idx_{$tableName}_{$whereCol} ON {$tableName} ({$whereCol}) -- to eliminate table scan",
                            'sql' => "CREATE INDEX idx_{$tableName}_{$whereCol} ON {$tableName} ({$whereCol});",
                            'explanation' => "Index on '{$whereCol}' will eliminate full table scan",
                            'impact' => "Could reduce {$rowsExamined} row examination to index lookup"
                        ];
                    }
                    break;
                    
                case 'index':
                    $suggestions[] = [
                        'type' => 'index_optimization',
                        'priority' => 'medium',
                        'query_id' => $queryId,
                        'table' => $tableName,
                        'message' => "Query {$queryId}: Full index scan on '{$tableName}' - consider covering index",
                        'explanation' => "MySQL is scanning entire index instead of seeking specific values",
                        'detail' => "Access type: index, Index used: {$usedKey}, Rows: {$rowsExamined}",
                        'recommendation' => 'Consider covering index or more selective WHERE conditions'
                    ];
                    break;
                    
                case 'range':
                    if ($rowsExamined > 1000) {
                        $suggestions[] = [
                            'type' => 'index_optimization',
                            'priority' => 'medium',
                            'query_id' => $queryId,
                            'table' => $tableName,
                            'message' => "Query {$queryId}: Range scan examining {$rowsExamined} rows on '{$tableName}' - consider more selective index",
                            'explanation' => "Range scan is examining many rows - index may not be selective enough",
                            'detail' => "Access type: range, Index: {$usedKey}, Rows examined: {$rowsExamined}",
                            'recommendation' => 'Consider composite index or more selective WHERE conditions'
                        ];
                    } else {
                        $suggestions[] = [
                            'type' => 'positive_feedback',
                            'priority' => 'low',
                            'query_id' => $queryId,
                            'table' => $tableName,
                            'message' => "Query {$queryId}: ✅ Efficient range scan on '{$tableName}' using index '{$usedKey}' ({$rowsExamined} rows)",
                            'explanation' => "Range scan is efficiently using index with reasonable row count",
                            'detail' => "Access type: range, well-optimized"
                        ];
                    }
                    break;
                    
                case 'ref':
                case 'eq_ref':
                case 'const':
                    $suggestions[] = [
                        'type' => 'positive_feedback',
                        'priority' => 'low',
                        'query_id' => $queryId,
                        'table' => $tableName,
                        'message' => "Query {$queryId}: ⭐ Excellent! Using {$accessType} access on '{$tableName}' with index '{$usedKey}'",
                        'explanation' => "This is optimal index usage for equality lookups",
                        'detail' => "Access type: {$accessType}, highly efficient"
                    ];
                    break;
            }
            
            // Check for filesort
            if (isset($tableInfo['using_filesort']) && $tableInfo['using_filesort'] === true) {
                $orderColumns = implode(', ', $sqlInfo['order_columns'] ?? []);
                $suggestions[] = [
                    'type' => 'index_recommendation',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $tableName,
                    'message' => "Query {$queryId}: Using filesort on '{$tableName}' - create index on ORDER BY columns",
                    'sql' => $orderColumns ? "CREATE INDEX idx_{$tableName}_order ON {$tableName} ({$orderColumns});" : null,
                    'explanation' => "MySQL is sorting results in memory/disk instead of using index order",
                    'detail' => "Using filesort detected",
                    'impact' => 'Index on ORDER BY columns eliminates filesort operation'
                ];
            }
            
            // Check for temporary table
            if (isset($tableInfo['using_temporary_table']) && $tableInfo['using_temporary_table'] === true) {
                $suggestions[] = [
                    'type' => 'explain_analysis',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $tableName,
                    'message' => "Query {$queryId}: Using temporary table on '{$tableName}' - consider query optimization",
                    'explanation' => "MySQL is creating temporary table for query processing",
                    'detail' => "Using temporary table detected",
                    'recommendation' => 'Review GROUP BY, ORDER BY, and DISTINCT clauses'
                ];
            }
        }
        
        return $suggestions;
    }

    /**
     * Analyze MySQL tabular EXPLAIN format
     * 
     * @param array $explainData Tabular EXPLAIN data
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Enhanced suggestions
     */
    private function analyzeMySQLTabularExplain(array $explainData, array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        
        foreach ($explainData as $row) {
            $tableName = $row['table'] ?? 'unknown';
            $type = $row['type'] ?? '';
            $key = $row['key'] ?? null;
            $rows = $row['rows'] ?? 0;
            $filtered = $row['filtered'] ?? 100;
            $extra = $row['Extra'] ?? '';
            
            // Full table scan detection
            if ($type === 'ALL') {
                $suggestions[] = [
                    'type' => 'explain_analysis',
                    'priority' => 'high',
                    'query_id' => $queryId,
                    'table' => $tableName,
                    'message' => "Query {$queryId}: Full table scan on '{$tableName}' ({$rows} rows) - add appropriate indexes",
                    'explanation' => "MySQL is scanning entire table instead of using an index",
                    'detail' => "Type: ALL, Rows: {$rows}, Filtered: {$filtered}%",
                    'recommendation' => 'Add index on WHERE clause columns'
                ];
                
                // Suggest specific index
                if (!empty($sqlInfo['where_columns'])) {
                    $whereCol = $sqlInfo['where_columns'][0];
                    $suggestions[] = [
                        'type' => 'index_recommendation',
                        'priority' => 'high',
                        'query_id' => $queryId,
                        'table' => $tableName,
                        'message' => "Query {$queryId}: CREATE INDEX idx_{$tableName}_{$whereCol} ON {$tableName} ({$whereCol}) -- to eliminate table scan",
                        'sql' => "CREATE INDEX idx_{$tableName}_{$whereCol} ON {$tableName} ({$whereCol});",
                        'explanation' => "Index on '{$whereCol}' will eliminate full table scan",
                        'impact' => "Could reduce {$rows} row examination to index lookup"
                    ];
                }
            }
            
            // Efficient access types - positive feedback
            if (in_array($type, ['const', 'eq_ref', 'ref'])) {
                $suggestions[] = [
                    'type' => 'positive_feedback',
                    'priority' => 'low',
                    'query_id' => $queryId,
                    'table' => $tableName,
                    'message' => "Query {$queryId}: ✅ Efficient {$type} access on '{$tableName}' using index '{$key}'",
                    'explanation' => "This is optimal index usage",
                    'detail' => "Type: {$type}, Key: {$key}, highly efficient"
                ];
            }
            
            // Using filesort
            if (str_contains($extra, 'Using filesort')) {
                $orderColumns = implode(', ', $sqlInfo['order_columns'] ?? []);
                $suggestions[] = [
                    'type' => 'index_recommendation',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $tableName,
                    'message' => "Query {$queryId}: Using filesort on '{$tableName}' - create index on ORDER BY columns",
                    'sql' => $orderColumns ? "CREATE INDEX idx_{$tableName}_order ON {$tableName} ({$orderColumns});" : null,
                    'explanation' => "MySQL is sorting results in memory/disk instead of using index order",
                    'detail' => "Extra: Using filesort",
                    'impact' => 'Index on ORDER BY columns eliminates filesort operation'
                ];
            }
            
            // Using index (covering index) - positive feedback
            if (str_contains($extra, 'Using index') && !str_contains($extra, 'condition')) {
                $suggestions[] = [
                    'type' => 'positive_feedback',
                    'priority' => 'low',
                    'query_id' => $queryId,
                    'table' => $tableName,
                    'message' => "Query {$queryId}: ⭐ Excellent! Using covering index '{$key}' on '{$tableName}' - no table access needed",
                    'explanation' => "Covering index provides all needed columns without accessing table data",
                    'detail' => "Extra: Using index (covering)",
                    'impact' => 'This is optimal performance for this query pattern'
                ];
            }
        }
        
        return $suggestions;
    }

    /**
     * MySQL-specific optimization suggestions
     */
    private function generateMySQLSpecificSuggestions(string $sql, array $sqlInfo, float $executionTime, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        
        // Complex JOIN optimization
        if (!empty($sqlInfo['join_tables']) && count($sqlInfo['join_tables']) > 2) {
            $joinCount = count($sqlInfo['join_tables']) + 1; // +1 for main table
            $suggestions[] = [
                'type' => 'mysql_optimization',
                'priority' => 'medium',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: Complex JOIN with {$joinCount} tables - consider MySQL join_buffer_size optimization",
                'explanation' => "Multi-table JOINs may benefit from tuned join buffer settings",
                'sql' => "-- Consider: SET SESSION join_buffer_size = 1048576; -- 1MB",
                'impact' => 'Larger join buffer can improve multi-table JOIN performance'
            ];
        }
        
        // LIKE pattern optimization
        if (str_contains(strtoupper($sql), 'LIKE')) {
            if (str_contains($sql, "'%")) {
                $suggestions[] = [
                    'type' => 'mysql_optimization',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: Leading wildcard LIKE pattern - consider FULLTEXT index for text search",
                    'explanation' => "Leading wildcards prevent index usage - FULLTEXT may be better for text search",
                    'sql' => "-- Consider: ALTER TABLE {$table} ADD FULLTEXT(column_name);",
                    'impact' => 'FULLTEXT index provides efficient text searching for wildcard patterns'
                ];
            }
        }
        
        return $suggestions;
    }
}

/**
 * PostgreSQL Query Profiler Implementation
 * 
 * Enhanced QueryProfiler for PostgreSQL with advanced EXPLAIN analysis
 * and PostgreSQL-specific optimization suggestions.
 */
class PostgreSQLQueryProfiler extends QueryProfiler
{
    /**
     * Perform PostgreSQL-specific query analysis
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param array $context Query context
     * @return array PostgreSQL-specific analysis results
     */
    protected function performDatabaseSpecificAnalysis(string $sql, array $params, array $context): array
    {
        $analysis = [
            'explain_data' => null,
            'postgresql_specific_suggestions' => []
        ];

        // Get EXPLAIN plan for SELECT queries
        if ($this->pdo && str_starts_with(strtoupper(trim($sql)), 'SELECT')) {
            $analysis['explain_data'] = $this->getExplainPlan($sql, $params);
        }

        return $analysis;
    }

    /**
     * Get PostgreSQL EXPLAIN plan
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array|null EXPLAIN plan or null if error
     */
    protected function getExplainPlan(string $sql, array $params): ?array
    {
        try {
            // First try EXPLAIN (FORMAT JSON, ANALYZE, BUFFERS) for detailed info
            $explainSql = "EXPLAIN (FORMAT JSON, ANALYZE, BUFFERS) " . $sql;
            $stmt = $this->pdo->prepare($explainSql);
            
            // Bind parameters for both named and positional parameters
            foreach ($params as $key => $value) {
                if (is_string($key)) {
                    // Named parameter - ensure it has $ prefix for PostgreSQL
                    $paramName = str_starts_with($key, '$') ? $key : '$' . (is_numeric($key) ? $key : '1');
                    $stmt->bindValue($paramName, $value);
                } else {
                    // Positional parameter (PostgreSQL uses $1, $2, etc.)
                    $stmt->bindValue($key + 1, $value);
                }
            }
            
            $stmt->execute();
            $result = $stmt->fetchColumn();
            
            return $result ? json_decode($result, true) : null;
            
        } catch (PDOException $e) {
            // Fallback to simple EXPLAIN if detailed version fails
            if (function_exists('error_log')) {
                error_log("PostgreSQL EXPLAIN (FORMAT JSON, ANALYZE, BUFFERS) failed: " . $e->getMessage() . ", trying simple EXPLAIN");
            }
            
            try {
                $explainSql = "EXPLAIN " . $sql;
                $stmt = $this->pdo->prepare($explainSql);
                
                // Bind parameters again for fallback
                foreach ($params as $key => $value) {
                    if (is_string($key)) {
                        $paramName = str_starts_with($key, '$') ? $key : '$1';
                        $stmt->bindValue($paramName, $value);
                    } else {
                        $stmt->bindValue($key + 1, $value);
                    }
                }
                
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (PDOException $e2) {
                if (function_exists('error_log')) {
                    error_log("PostgreSQL simple EXPLAIN also failed: " . $e2->getMessage());
                }
                return null;
            }
        }
    }

    /**
     * Enhanced EXPLAIN-based suggestions for PostgreSQL
     * 
     * @param array $explainData EXPLAIN plan data
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Enhanced suggestions based on EXPLAIN plan
     */
    protected function generateExplainBasedSuggestions(array $explainData, array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        
        // Handle both JSON and text EXPLAIN formats
        if (isset($explainData[0]['Plan'])) {
            // JSON format analysis
            $suggestions = $this->analyzePostgreSQLJsonExplain($explainData[0], $sqlInfo, $queryId);
        } else {
            // Text format analysis
            $suggestions = $this->analyzePostgreSQLTextExplain($explainData, $sqlInfo, $queryId);
        }
        
        // Add PostgreSQL-specific optimization suggestions
        $suggestions = array_merge($suggestions, $this->generatePostgreSQLSpecificSuggestions($sqlInfo, $queryId));
        
        return $suggestions;
    }

    /**
     * Analyze PostgreSQL JSON EXPLAIN format
     * 
     * @param array $explainData JSON EXPLAIN data
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Enhanced suggestions
     */
    private function analyzePostgreSQLJsonExplain(array $explainData, array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        
        if (isset($explainData['Plan'])) {
            $suggestions = array_merge($suggestions, $this->analyzePostgreSQLPlan($explainData['Plan'], $sqlInfo, $queryId));
        }
        
        // Check execution statistics if available
        if (isset($explainData['Execution Time'])) {
            $executionTime = $explainData['Execution Time'];
            if ($executionTime > 1000) { // Over 1 second
                $suggestions[] = [
                    'type' => 'performance_critical',
                    'priority' => 'critical',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: PostgreSQL execution time {$executionTime}ms - immediate optimization needed",
                    'explanation' => "Query execution exceeded 1 second",
                    'detail' => "PostgreSQL EXPLAIN ANALYZE time: {$executionTime}ms"
                ];
            }
        }
        
        return $suggestions;
    }

    /**
     * Analyze individual PostgreSQL plan node
     * 
     * @param array $plan Plan node data
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Suggestions for this plan node
     */
    private function analyzePostgreSQLPlan(array $plan, array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        $nodeType = $plan['Node Type'] ?? 'Unknown';
        $relationName = $plan['Relation Name'] ?? ($sqlInfo['table'] ?? 'table');
        $totalCost = $plan['Total Cost'] ?? 0;
        $rows = $plan['Actual Rows'] ?? ($plan['Plan Rows'] ?? 0);
        
        switch ($nodeType) {
            case 'Seq Scan':
                $suggestions[] = [
                    'type' => 'explain_analysis',
                    'priority' => 'high',
                    'query_id' => $queryId,
                    'table' => $relationName,
                    'message' => "Query {$queryId}: Sequential scan on '{$relationName}' ({$rows} rows) - add appropriate indexes",
                    'explanation' => "PostgreSQL is scanning entire table instead of using an index",
                    'detail' => "Node: Seq Scan, Rows: {$rows}, Cost: {$totalCost}",
                    'recommendation' => 'Add index on WHERE clause columns'
                ];
                
                // Suggest specific index
                if (!empty($sqlInfo['where_columns'])) {
                    $whereCol = $sqlInfo['where_columns'][0];
                    $suggestions[] = [
                        'type' => 'index_recommendation',
                        'priority' => 'high',
                        'query_id' => $queryId,
                        'table' => $relationName,
                        'message' => "Query {$queryId}: CREATE INDEX idx_{$relationName}_{$whereCol} ON {$relationName} ({$whereCol}) -- to eliminate sequential scan",
                        'sql' => "CREATE INDEX idx_{$relationName}_{$whereCol} ON {$relationName} ({$whereCol});",
                        'explanation' => "B-tree index on '{$whereCol}' will eliminate sequential scan",
                        'impact' => "Could reduce {$rows} row examination to index lookup"
                    ];
                }
                break;
                
            case 'Index Scan':
                if ($totalCost > 100) {
                    $indexName = $plan['Index Name'] ?? 'unknown';
                    $suggestions[] = [
                        'type' => 'index_optimization',
                        'priority' => 'medium',
                        'query_id' => $queryId,
                        'table' => $relationName,
                        'message' => "Query {$queryId}: Index scan on '{$relationName}' with high cost ({$totalCost}) - consider index optimization",
                        'explanation' => "Index scan cost is high - may need better index or query optimization",
                        'detail' => "Node: Index Scan, Index: {$indexName}, Cost: {$totalCost}",
                        'recommendation' => 'Consider composite index or query restructuring'
                    ];
                } else {
                    $indexName = $plan['Index Name'] ?? 'unknown';
                    $suggestions[] = [
                        'type' => 'positive_feedback',
                        'priority' => 'low',
                        'query_id' => $queryId,
                        'table' => $relationName,
                        'message' => "Query {$queryId}: ✅ Efficient index scan on '{$relationName}' using '{$indexName}' (cost: {$totalCost})",
                        'explanation' => "Index scan is efficient with reasonable cost",
                        'detail' => "Node: Index Scan, well-optimized"
                    ];
                }
                break;
                
            case 'Index Only Scan':
                $indexName = $plan['Index Name'] ?? 'unknown';
                $suggestions[] = [
                    'type' => 'positive_feedback',
                    'priority' => 'low',
                    'query_id' => $queryId,
                    'table' => $relationName,
                    'message' => "Query {$queryId}: ⭐ Excellent! Index-only scan on '{$relationName}' using '{$indexName}' - no table access needed",
                    'explanation' => "Index contains all needed columns, avoiding table access",
                    'detail' => "Node: Index Only Scan, optimal performance",
                    'impact' => 'This is optimal performance for this query pattern'
                ];
                break;
                
            case 'Bitmap Index Scan':
            case 'Bitmap Heap Scan':
                if ($rows > 1000) {
                    $suggestions[] = [
                        'type' => 'index_optimization',
                        'priority' => 'medium',
                        'query_id' => $queryId,
                        'table' => $relationName,
                        'message' => "Query {$queryId}: Bitmap scan on '{$relationName}' processing {$rows} rows - consider more selective conditions",
                        'explanation' => "Bitmap scan is processing many rows - may benefit from more selective WHERE conditions",
                        'detail' => "Node: {$nodeType}, Rows: {$rows}, Cost: {$totalCost}",
                        'recommendation' => 'Consider additional WHERE conditions or composite index'
                    ];
                }
                break;
                
            case 'Sort':
                $sortKey = $plan['Sort Key'] ?? 'unknown';
                $orderColumns = implode(', ', $sqlInfo['order_columns'] ?? []);
                $suggestions[] = [
                    'type' => 'index_recommendation',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $relationName,
                    'message' => "Query {$queryId}: Explicit sort operation - create index on ORDER BY columns",
                    'sql' => $orderColumns ? "CREATE INDEX idx_{$relationName}_order ON {$relationName} ({$orderColumns});" : null,
                    'explanation' => "PostgreSQL is sorting results in memory - index can provide pre-sorted data",
                    'detail' => "Node: Sort, Sort Key: {$sortKey}, Cost: {$totalCost}",
                    'impact' => 'Index on ORDER BY columns eliminates sort operation'
                ];
                break;
                
            case 'Hash Join':
            case 'Nested Loop':
            case 'Merge Join':
                if ($totalCost > 1000) {
                    $suggestions[] = [
                        'type' => 'explain_analysis',
                        'priority' => 'medium',
                        'query_id' => $queryId,
                        'table' => $relationName,
                        'message' => "Query {$queryId}: Expensive {$nodeType} (cost: {$totalCost}) - consider index optimization",
                        'explanation' => "Join operation has high cost - may benefit from better indexes on join columns",
                        'detail' => "Node: {$nodeType}, Cost: {$totalCost}",
                        'recommendation' => 'Consider indexes on join columns'
                    ];
                } else {
                    $suggestions[] = [
                        'type' => 'positive_feedback',
                        'priority' => 'low',
                        'query_id' => $queryId,
                        'table' => $relationName,
                        'message' => "Query {$queryId}: ✅ Efficient {$nodeType} (cost: {$totalCost})",
                        'explanation' => "Join operation is reasonably efficient",
                        'detail' => "Node: {$nodeType}, well-optimized"
                    ];
                }
                break;
        }
        
        // Check for high cost operations
        if ($totalCost > 10000) {
            $suggestions[] = [
                'type' => 'performance_warning',
                'priority' => 'high',
                'query_id' => $queryId,
                'table' => $relationName,
                'message' => "Query {$queryId}: High cost operation ({$totalCost}) on '{$relationName}' - requires optimization",
                'explanation' => "Operation cost is very high and may impact performance",
                'detail' => "Node: {$nodeType}, Cost: {$totalCost}",
                'recommendation' => 'Review query structure and indexing strategy'
            ];
        }
        
        // Recursively analyze child plans
        if (isset($plan['Plans'])) {
            foreach ($plan['Plans'] as $childPlan) {
                $suggestions = array_merge($suggestions, $this->analyzePostgreSQLPlan($childPlan, $sqlInfo, $queryId));
            }
        }
        
        return $suggestions;
    }

    /**
     * Analyze PostgreSQL text EXPLAIN format
     * 
     * @param array $explainData Text EXPLAIN data
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array Enhanced suggestions
     */
    private function analyzePostgreSQLTextExplain(array $explainData, array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        
        foreach ($explainData as $row) {
            $line = $row['QUERY PLAN'] ?? '';
            
            if (str_contains($line, 'Seq Scan')) {
                $suggestions[] = [
                    'type' => 'explain_analysis',
                    'priority' => 'high',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: Sequential scan detected on '{$table}' - add appropriate indexes",
                    'explanation' => "PostgreSQL is scanning entire table instead of using an index",
                    'detail' => trim($line),
                    'recommendation' => 'Add index on WHERE clause columns'
                ];
            }
            
            if (str_contains($line, 'Sort')) {
                $suggestions[] = [
                    'type' => 'index_recommendation',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: Sort operation detected - create index on ORDER BY columns",
                    'explanation' => "PostgreSQL is sorting results in memory - index can provide pre-sorted data",
                    'detail' => trim($line),
                    'recommendation' => 'Consider index on ORDER BY columns'
                ];
            }
            
            if (str_contains($line, 'Hash Join') && str_contains($line, 'cost=')) {
                // Extract cost if available
                if (preg_match('/cost=[\d.]+\.\.(\d+)/', $line, $matches)) {
                    $cost = (int)$matches[1];
                    if ($cost > 1000) {
                        $suggestions[] = [
                            'type' => 'explain_analysis',
                            'priority' => 'medium',
                            'query_id' => $queryId,
                            'table' => $table,
                            'message' => "Query {$queryId}: Expensive hash join (cost: {$cost}) - consider index optimization",
                            'explanation' => "Hash join has high cost - may benefit from better indexes",
                            'detail' => trim($line),
                            'recommendation' => 'Consider indexes on join columns'
                        ];
                    }
                }
            }
        }
        
        return $suggestions;
    }

    /**
     * PostgreSQL-specific optimization suggestions
     * 
     * @param array $sqlInfo Parsed SQL information
     * @param string $queryId Query identifier
     * @return array PostgreSQL-specific suggestions
     */
    private function generatePostgreSQLSpecificSuggestions(array $sqlInfo, string $queryId): array
    {
        $suggestions = [];
        $table = $sqlInfo['table'] ?? 'table';
        
        // PostgreSQL-specific recommendations
        if (!empty($sqlInfo['join_tables']) && count($sqlInfo['join_tables']) > 2) {
            $joinCount = count($sqlInfo['join_tables']) + 1;
            $suggestions[] = [
                'type' => 'postgresql_optimization',
                'priority' => 'medium',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: Complex JOIN with {$joinCount} tables - consider PostgreSQL work_mem optimization",
                'explanation' => "Multi-table JOINs may benefit from increased work_mem for hash operations",
                'sql' => "-- Consider: SET work_mem = '256MB'; -- for this session",
                'impact' => 'Larger work_mem can improve hash join and sort performance'
            ];
        }
        
        // Text search optimization
        if (str_contains(strtoupper($sqlInfo['type'] ?? ''), 'SELECT')) {
            $likeColumns = array_filter($sqlInfo['where_columns'] ?? [], function($col) use ($sqlInfo) {
                return ($sqlInfo['where_operators'][$col] ?? '') === 'LIKE';
            });
            
            foreach ($likeColumns as $column) {
                $suggestions[] = [
                    'type' => 'postgresql_optimization',
                    'priority' => 'medium',
                    'query_id' => $queryId,
                    'table' => $table,
                    'message' => "Query {$queryId}: LIKE pattern on '{$column}' - consider PostgreSQL full-text search",
                    'explanation' => "For text search, PostgreSQL full-text search may be more efficient than LIKE",
                    'sql' => "-- Consider: ALTER TABLE {$table} ADD COLUMN {$column}_tsvector tsvector;\n-- CREATE INDEX idx_{$table}_{$column}_gin ON {$table} USING gin({$column}_tsvector);",
                    'impact' => 'Full-text search provides faster and more sophisticated text searching'
                ];
            }
        }
        
        // Partial index suggestion for filtered queries
        if (!empty($sqlInfo['where_columns']) && count($sqlInfo['where_columns']) > 1) {
            $whereColumns = implode(', ', $sqlInfo['where_columns']);
            $suggestions[] = [
                'type' => 'postgresql_optimization',
                'priority' => 'low',
                'query_id' => $queryId,
                'table' => $table,
                'message' => "Query {$queryId}: Multiple WHERE conditions - consider PostgreSQL partial index",
                'explanation' => "Partial indexes can be more efficient for queries with consistent WHERE conditions",
                'sql' => "-- Consider: CREATE INDEX idx_{$table}_partial ON {$table} ({$whereColumns}) WHERE condition;",
                'impact' => 'Partial indexes are smaller and faster for filtered queries'
            ];
        }
        
        return $suggestions;
    }
}