<?php

require_once dirname(__DIR__) . '/debug/DebugConstants.php';
require_once dirname(__DIR__) . '/debug/DebugPresets.php';
require_once dirname(__DIR__) . '/debug/ModelDebugHelper.php';
require_once dirname(__DIR__) . '/debug/PerformanceAnalyzer.php';
require_once dirname(__DIR__) . '/debug/QueryProfiler.php';



/**
 * Debug Factory - Central Factory for All Debug Components
 * 
 * Creates and manages debug-related objects with lazy loading and proper
 * database-specific implementations. Handles the complexity of debug
 * system initialization while keeping the main Model class clean.
 */
class DebugFactory
{
    /**
     * Static cache for created debug collectors to avoid recreation
     * 
     * @var array<string, DebugCollector>
     */
    private static array $collectorCache = [];

    /**
     * Static cache for query profilers by database type
     * 
     * @var array<string, QueryProfiler>
     */
    private static array $profilerCache = [];

    /**
     * Static cache for performance analyzer
     * 
     * @var PerformanceAnalyzer|null
     */
    private static ?PerformanceAnalyzer $performanceAnalyzer = null;

    /**
     * Create or retrieve cached debug collector for a Model instance
     * 
     * @param Model $model Model instance to create collector for
     * @return DebugCollector Configured debug collector
     */
    public static function createDebugCollector(Model $model): DebugCollector
    {
        // Use model object hash as cache key to allow multiple model instances
        $cacheKey = spl_object_hash($model);

        if (!isset(self::$collectorCache[$cacheKey])) {
            self::$collectorCache[$cacheKey] = new DebugCollector($model);
        }

        return self::$collectorCache[$cacheKey];
    }

    /**
     * Create or retrieve cached query profiler for specific database type
     * 
     * @param string $dbType Database type ('mysql', 'sqlite', 'postgresql')
     * @param PDO|null $pdo Optional PDO connection for EXPLAIN queries
     * @return QueryProfiler Database-specific query profiler
     */
    public static function createQueryProfiler(string $dbType, ?PDO $pdo = null): QueryProfiler
    {
        if (!isset(self::$profilerCache[$dbType])) {
            self::$profilerCache[$dbType] = match (strtolower($dbType)) {
                'mysql' => new MySQLQueryProfiler($pdo),
                'sqlite' => new SQLiteQueryProfiler($pdo),
                'postgresql', 'postgres', 'pgsql' => new PostgreSQLQueryProfiler($pdo),
                default => new GenericQueryProfiler($pdo)
            };
        }

        return self::$profilerCache[$dbType];
    }

    /**
     * Create or retrieve cached performance analyzer
     * 
     * @return PerformanceAnalyzer Performance analysis component
     */
    public static function createPerformanceAnalyzer(): PerformanceAnalyzer
    {
        if (self::$performanceAnalyzer === null) {
            self::$performanceAnalyzer = new PerformanceAnalyzer();
        }

        return self::$performanceAnalyzer;
    }

    /**
     * Create debug output formatter for specified format
     * 
     * @param string $format Output format ('html', 'text', 'json')
     * @return DebugFormatter Appropriate formatter for the specified format
     */
    public static function createFormatter(string $format): DebugFormatter
    {
        return match (strtolower($format)) {
            'html' => new HtmlDebugFormatter(),
            'text' => new TextDebugFormatter(),
            'json' => new JsonDebugFormatter(),
            'ansi' => new AnsiDebugFormatter(), // For CLI with colors
            default => new HtmlDebugFormatter()
        };
    }

    /**
     * Export debug session data in specified format
     * 
     * @param DebugCollector|null $debugCollector Debug collector instance
     * @param string $format Export format ('json', 'csv', 'xml', 'html')
     * @return string Exported debug data
     */
    public static function exportDebugSession(?DebugCollector $debugCollector, string $format = 'json'): string
    {
        if ($debugCollector === null) {
            return match ($format) {
                'json' => '{"error": "Debug mode not enabled"}',
                'csv' => 'error,Debug mode not enabled',
                'xml' => '<?xml version="1.0"?><error>Debug mode not enabled</error>',
                default => 'Debug mode not enabled'
            };
        }

        $debugData = $debugCollector->getDebugData();
        $formatter = self::createFormatter($format);

        return $formatter->format($debugData['messages'], $debugData['session_summary']);
    }

    /**
     * Clear all caches - useful for testing and memory management
     * 
     * @return void
     */
    public static function clearCaches(): void
    {
        self::$collectorCache = [];
        self::$profilerCache = [];
        self::$performanceAnalyzer = null;
    }

    /**
     * Get debug system statistics for monitoring
     * 
     * @return array Debug system usage statistics
     */
    public static function getDebugStats(): array
    {
        return [
            'collectors_created' => count(self::$collectorCache),
            'profilers_cached' => count(self::$profilerCache),
            'performance_analyzer_active' => self::$performanceAnalyzer !== null,
            'memory_usage_bytes' => memory_get_usage(true),
            'debug_overhead_estimate' => self::estimateDebugOverhead()
        ];
    }

    /**
     * Estimate memory overhead of debug system
     * 
     * @return string Human-readable memory usage estimate
     */
    private static function estimateDebugOverhead(): string
    {
        $overhead = 0;

        // Estimate collector memory usage
        foreach (self::$collectorCache as $collector) {
            $overhead += strlen(serialize($collector->getDebugData()));
        }

        // Add profiler cache overhead
        $overhead += count(self::$profilerCache) * 1024; // ~1KB per profiler

        // Format for human readability
        if ($overhead < 1024) {
            return $overhead . ' bytes';
        } elseif ($overhead < 1048576) {
            return round($overhead / 1024, 1) . ' KB';
        } else {
            return round($overhead / 1048576, 1) . ' MB';
        }
    }
}

/**
 * Main Debug Collector - Handles All Debug Logic
 * 
 * Central hub for debug information collection, analysis, and output generation.
 * Designed to be created via DebugFactory and handle all debug operations
 * without cluttering the main Model class.
 */
class DebugCollector
{
    private Model $model;
    private array $messages = [];
    private array $timings = [];
    private array $performanceProfile = [];
    private QueryProfiler $queryProfiler;
    private PerformanceAnalyzer $analyzer;

    // Configuration
    private int $level = DebugLevel::BASIC;
    private int $categories = DebugCategory::DEVELOPER; // Default to developer-friendly
    private string $format = 'html';
    private bool $autoOutput = true; // Whether to output debug info automatically

    // Performance tracking
    private float $sessionStart;
    private int $queryCount = 0;
    private int $slowQueryCount = 0;
    private float $totalQueryTime = 0;

    /**
     * Initialize debug collector with model instance
     * 
     * @param Model $model Model instance to collect debug info for
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->sessionStart = microtime(true);

        // Create profiler and analyzer via factory (lazy loading)
        $this->queryProfiler = DebugFactory::createQueryProfiler(
            $model->getDbType(),
            $model->getPDO()
        );
        $this->analyzer = DebugFactory::createPerformanceAnalyzer();
    }

    /**
     * Configure debug collector settings
     * 
     * @param int|bool $level Debug level (DebugLevel constants or boolean for backwards compatibility)
     * @param int|null $categories Debug categories bitmask (DebugCategory constants)
     * @param string $format Output format ('html', 'text', 'json', 'ansi')
     * @return void
     */
    public function configure($level, ?int $categories = null, string $format = 'html'): void
    {
        // Handle legacy boolean debug setting
        if (is_bool($level)) {
            $this->level = $level ? DebugLevel::DETAILED : DebugLevel::BASIC;
        } else {
            $this->level = (int)$level;
        }

        if ($categories !== null) {
            $this->categories = $categories;
        }

        $this->format = $format;
    }

    /**
     * Log debug message with category and level filtering
     * 
     * @param string $message Debug message to log
     * @param int $category Message category (DebugCategory constants)
     * @param int $level Message level (DebugLevel constants)
     * @param array $context Additional context data
     * @return void
     */
    public function log(string $message, int $category = DebugCategory::SQL, int $level = DebugLevel::BASIC, array $context = []): void
    {
        // Filter by category and level
        if (!$this->shouldLog($category, $level)) {
            return;
        }

        $entry = [
            'timestamp' => microtime(true),
            'message' => $message,
            'category' => $category,
            'level' => $level,
            'context' => $context,
            'memory_usage' => memory_get_usage(true),
            'session_time' => microtime(true) - $this->sessionStart
        ];

        $this->messages[] = $entry;

        // Perform automatic analysis on certain message types
        $this->analyzeEntry($entry);

        // Auto-output for immediate feedback (if enabled)
        if ($this->autoOutput && $level <= DebugLevel::BASIC) {
            echo $this->formatSingleMessage($entry);
        }
    }

    /**
     * Analyze SQL query with automatic EXPLAIN and performance suggestions
     * 
     * FIXED: Process all suggestion types from QueryProfiler analysis
     * 
     * @param string $sql SQL query to analyze
     * @param array $params Query parameters
     * @param float $executionTime Query execution time in seconds
     * @param array $context Additional query context
     * @return void
     */
    public function analyzeQuery(string $sql, array $params, float $executionTime, array $context): void
    {
        // Skip analysis if debug level is too low or SQL is simple DDL
        if ($this->level < DebugLevel::DETAILED && $executionTime < 0.05) {
            return;
        }

        $this->queryCount++;
        $this->totalQueryTime += $executionTime;

        // Create query profiler if not exists
        if ($this->queryProfiler === null) {
            $this->queryProfiler = DebugFactory::createQueryProfiler(
                $this->model->getDbType(),
                $this->model->getPDO()
            );
        }

        // Enhance context with additional information
        $enhancedContext = array_merge($context, [
            'table' => $context['table'] ?? $this->extractTableFromSql($sql),
            'operation' => $context['operation'] ?? $this->detectOperation($sql),
            'query_count' => $this->queryCount
        ]);

        // Run detailed analysis if debug level is DETAILED+ OR query is slow
        $shouldAnalyze = ($this->level >= DebugLevel::DETAILED) || ($executionTime > 0.1);
        
        if ($shouldAnalyze) {
            $analysis = $this->queryProfiler->analyze($sql, $params, $executionTime, $enhancedContext);

            // Process slow query detection
            if ($analysis['slow']) {
                $this->slowQueryCount++;
                $this->log("🐌 SLOW QUERY DETECTED", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
                    'query_id' => $analysis['query_id'] ?? 'unknown',
                    'execution_time' => $executionTime,
                    'table' => $analysis['table'] ?? 'unknown'
                ]);
            }

            // Process enhanced suggestions with smart formatting
            if (!empty($analysis['enhanced_suggestions'])) {
                $this->processEnhancedSuggestions($analysis['enhanced_suggestions']);
            }

            // Process legacy suggestions for backward compatibility
            if (!empty($analysis['suggestions'])) {
                foreach ($analysis['suggestions'] as $suggestion) {
                    // Skip if this is already processed as enhanced suggestion
                    if (!$this->isEnhancedSuggestion($suggestion, $analysis['enhanced_suggestions'] ?? [])) {
                        $this->log("💡 SUGGESTION: " . $suggestion, DebugCategory::PERFORMANCE, DebugLevel::DETAILED);
                    }
                }
            }

            // Process session summary periodically
            if (!empty($analysis['session_summary'])) {
                $this->processSessionSummary($analysis['session_summary']);
            }

            // Log EXPLAIN plan if available and verbose level is enabled
            if (!empty($analysis['explain_data']) && $this->level >= DebugLevel::VERBOSE) {
                $this->log("📊 QUERY PLAN", DebugCategory::SQL, DebugLevel::VERBOSE, [
                    'query_id' => $analysis['query_id'] ?? 'unknown',
                    'explain' => $analysis['explain_data']
                ]);
            }

            // Enhanced analysis logging for troubleshooting
            if ($this->level >= DebugLevel::VERBOSE) {
                $this->log("🔍 ENHANCED ANALYSIS", DebugCategory::SQL, DebugLevel::VERBOSE, [
                    'query_id' => $analysis['query_id'] ?? 'unknown',
                    'query_type' => $analysis['query_type'] ?? 'unknown',
                    'table' => $analysis['table'] ?? 'unknown',
                    'complexity_score' => $analysis['complexity_score'] ?? 0,
                    'suggestion_count' => count($analysis['enhanced_suggestions'] ?? []),
                    'has_explain' => !empty($analysis['explain_data'])
                ]);
            }
        }
    }

    /**
     * Process enhanced suggestions with smart formatting and prioritization
     */
    private function processEnhancedSuggestions(array $suggestions): void
    {
        // Group suggestions by priority for better display
        $suggestionsByPriority = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => []
        ];

        foreach ($suggestions as $suggestion) {
            $priority = $suggestion['priority'] ?? 'medium';
            $suggestionsByPriority[$priority][] = $suggestion;
        }

        // Process in priority order
        foreach ($suggestionsByPriority as $priority => $prioritySuggestions) {
            if (empty($prioritySuggestions)) continue;

            foreach ($prioritySuggestions as $suggestion) {
                $this->logEnhancedSuggestion($suggestion, $priority);
            }
        }
    }

    /**
     * Log individual enhanced suggestion with appropriate formatting
     */
    private function logEnhancedSuggestion(array $suggestion, string $priority): void
    {
        $type = $suggestion['type'] ?? 'general';
        $message = $suggestion['message'] ?? 'Unknown suggestion';
        
        // Format based on priority and type
        $icon = match($priority) {
            'critical' => '🚨',
            'high' => '⚠️',
            'medium' => '💡',
            'low' => 'ℹ️',
            default => '•'
        };

        // Determine appropriate debug category and level
        $category = match($type) {
            'index_recommendation' => DebugCategory::PERFORMANCE,
            'performance_critical' => DebugCategory::PERFORMANCE,
            'performance_warning' => DebugCategory::PERFORMANCE,
            'explain_analysis' => DebugCategory::SQL,
            'query_structure' => DebugCategory::SQL,
            'sqlite_optimization' => DebugCategory::SQL,
            'sqlite_configuration' => DebugCategory::SQL,
            'positive_feedback' => DebugCategory::SQL,
            default => DebugCategory::PERFORMANCE
        };

        $level = match($priority) {
            'critical' => DebugLevel::BASIC,  // Always show critical
            'high' => DebugLevel::DETAILED,
            'medium' => DebugLevel::DETAILED,
            'low' => DebugLevel::VERBOSE,
            default => DebugLevel::DETAILED
        };

        // Create comprehensive context for the suggestion
        $context = [
            'suggestion_type' => $type,
            'priority' => $priority,
            'query_id' => $suggestion['query_id'] ?? null,
            'table' => $suggestion['table'] ?? null,
            'explanation' => $suggestion['explanation'] ?? null,
        ];

        // Add SQL recommendation if available
        if (isset($suggestion['sql'])) {
            $context['sql_recommendation'] = $suggestion['sql'];
        }

        // Add example if available
        if (isset($suggestion['example'])) {
            $context['example'] = $suggestion['example'];
        }

        // Add impact assessment if available
        if (isset($suggestion['impact'])) {
            $context['impact'] = $suggestion['impact'];
        }

        // Add EXPLAIN detail if available
        if (isset($suggestion['detail'])) {
            $context['explain_detail'] = $suggestion['detail'];
        }

        $this->log("{$icon} {$message}", $category, $level, $context);
    }

    /**
     * Process session summary with smart insights
     */
    private function processSessionSummary(array $summary): void
    {
        if (empty($summary)) return;
        
        $totalQueries = $summary['total_queries'] ?? 0;
        
        // Log session overview every 5 queries
        if ($totalQueries > 0 && $totalQueries % 5 === 0) {
            $this->log("📊 SESSION ANALYSIS: {$totalQueries} queries executed", 
                DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
                    'total_queries' => $totalQueries,
                    'suggestion_types' => array_keys($summary['suggestions_by_type'] ?? []),
                    'index_recommendations_count' => count($summary['index_recommendations'] ?? []),
                    'performance_issues_count' => count($summary['performance_issues'] ?? [])
                ]);
            
            // Highlight critical index recommendations
            if (!empty($summary['index_recommendations'])) {
                $topRecommendations = array_slice($summary['index_recommendations'], 0, 3);
                $this->log("🎯 TOP INDEX RECOMMENDATIONS:", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
                    'recommendations' => $topRecommendations,
                    'total_count' => count($summary['index_recommendations'])
                ]);
            }
            
            // Highlight performance issues
            if (!empty($summary['performance_issues'])) {
                $criticalIssues = array_slice($summary['performance_issues'], 0, 3);
                $this->log("⚠️ PERFORMANCE ISSUES DETECTED:", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
                    'issues' => array_map(function($issue) {
                        return $issue['message'] ?? 'Unknown issue';
                    }, $criticalIssues),
                    'total_count' => count($summary['performance_issues'])
                ]);
            }
        }

        // Log session patterns every 10 queries
        if ($totalQueries > 0 && $totalQueries % 10 === 0) {
            $this->analyzeSessionPatterns($summary);
        }
    }

    /**
     * Analyze patterns across multiple queries in the session
     */
    private function analyzeSessionPatterns(array $summary): void
    {
        $patterns = [];
        
        // Analyze suggestion patterns
        $suggestionsByType = $summary['suggestions_by_type'] ?? [];
        
        if (isset($suggestionsByType['query_structure']) && count($suggestionsByType['query_structure']) > 2) {
            $patterns[] = "Frequent query structure issues detected - review SELECT * usage and LIMIT clauses";
        }
        
        if (isset($suggestionsByType['index_recommendation']) && count($suggestionsByType['index_recommendation']) > 1) {
            $patterns[] = "Multiple index opportunities identified - implementing these could significantly improve performance";
        }
        
        if (isset($suggestionsByType['performance_critical']) && count($suggestionsByType['performance_critical']) > 0) {
            $patterns[] = "Critical performance issues detected - immediate optimization required";
        }
        
        // Log patterns if found
        if (!empty($patterns)) {
            $this->log("🔍 SESSION PATTERNS DETECTED:", DebugCategory::PERFORMANCE, DebugLevel::DETAILED, [
                'patterns' => $patterns,
                'recommendation' => 'Consider implementing suggested optimizations for best results'
            ]);
        }
    }

    /**
     * Check if a suggestion is already processed as enhanced suggestion
     */
    private function isEnhancedSuggestion(string $legacySuggestion, array $enhancedSuggestions): bool
    {
        foreach ($enhancedSuggestions as $enhanced) {
            $enhancedMessage = $enhanced['message'] ?? '';
            if (str_contains($enhancedMessage, $legacySuggestion) || str_contains($legacySuggestion, $enhancedMessage)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract table name from SQL for context enhancement
     */
    private function extractTableFromSql(string $sql): ?string
    {
        if (preg_match('/(?:FROM|INTO|UPDATE|TABLE)\s+`?([a-zA-Z_]\w*)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Detect operation type from SQL
     */
    private function detectOperation(string $sql): string
    {
        $sqlUpper = strtoupper(trim($sql));
        if (str_starts_with($sqlUpper, 'SELECT')) return 'select';
        if (str_starts_with($sqlUpper, 'INSERT')) return 'insert';
        if (str_starts_with($sqlUpper, 'UPDATE')) return 'update';
        if (str_starts_with($sqlUpper, 'DELETE')) return 'delete';
        return 'unknown';
    }


    /**
     * Analyze bulk operation performance and optimization decisions
     * 
     * @param string $operation Operation type ('insert_batch', 'update_batch', etc.)
     * @param int $recordCount Number of records processed
     * @param array $optimizationContext Context about optimizations applied
     * @return void
     */
    public function analyzeBulkOperation(string $operation, int $recordCount, array $optimizationContext): void
    {
        $analysis = $this->analyzer->analyzeBulkOperation($operation, $recordCount, $optimizationContext);

        // Log bulk operation summary
        $this->log("📦 BULK OPERATION: {$operation}", DebugCategory::BULK, DebugLevel::BASIC, [
            'record_count' => $recordCount,
            'chunk_size' => $optimizationContext['chunk_size'] ?? 'auto',
            'execution_time_ms' => ($optimizationContext['execution_time'] ?? 0) * 1000,
            'records_per_second' => $analysis['records_per_second'] ?? 0,
            'optimizations_applied' => $optimizationContext['optimizations'] ?? []
        ]);

        // Log optimization decisions
        if (!empty($analysis['optimization_decisions'])) {
            foreach ($analysis['optimization_decisions'] as $decision) {
                $this->log("🧠 OPTIMIZATION: " . $decision, DebugCategory::BULK, DebugLevel::DETAILED);
            }
        }

        // Log warnings
        if (!empty($analysis['warnings'])) {
            foreach ($analysis['warnings'] as $warning) {
                $this->log("⚠️ WARNING: " . $warning, DebugCategory::BULK, DebugLevel::DETAILED);
            }
        }

        // Log performance insights
        if (!empty($analysis['insights'])) {
            foreach ($analysis['insights'] as $insight) {
                $this->log("📊 INSIGHT: " . $insight, DebugCategory::PERFORMANCE, DebugLevel::VERBOSE);
            }
        }
    }

    /**
     * Log cache operation (hits, misses, warming)
     * 
     * @param string $operation Cache operation type
     * @param array $context Cache operation context
     * @return void
     */
    public function logCacheOperation(string $operation, array $context): void
    {
        $this->log("💾 CACHE: {$operation}", DebugCategory::CACHE, DebugLevel::DETAILED, $context);
    }

    /**
     * Log transaction operation
     * 
     * @param string $operation Transaction operation type
     * @param array $context Transaction context
     * @return void
     */
    public function logTransaction(string $operation, array $context): void
    {
        $icon = match ($operation) {
            'begin' => '🚀',
            'commit' => '✅',
            'rollback' => '🔄',
            'savepoint' => '📍',
            default => '🔄'
        };

        $this->log("{$icon} TRANSACTION: {$operation}", DebugCategory::TRANSACTION, DebugLevel::BASIC, $context);
    }

    /**
     * Get formatted debug output based on configured format
     * 
     * @return string Formatted debug output ready for display
     */
    public function getOutput(): string
    {
        $formatter = DebugFactory::createFormatter($this->format);
        return $formatter->format($this->messages, $this->getSessionSummary());
    }

    /**
     * Get raw debug data for programmatic access
     * 
     * @return array Raw debug data and statistics
     */
    public function getDebugData(): array
    {
        return [
            'messages' => $this->messages,
            'session_summary' => $this->getSessionSummary(),
            'performance_profile' => $this->performanceProfile,
            'configuration' => [
                'level' => $this->level,
                'categories' => $this->categories,
                'format' => $this->format
            ]
        ];
    }

    /**
     * Clear collected debug messages and reset counters
     * 
     * @return void
     */
    public function clear(): void
    {
        $this->messages = [];
        $this->timings = [];
        $this->performanceProfile = [];
        $this->queryCount = 0;
        $this->slowQueryCount = 0;
        $this->totalQueryTime = 0;
        $this->sessionStart = microtime(true);
    }

    /**
     * Enable or disable automatic output
     * 
     * @param bool $enabled Whether to automatically output debug messages
     * @return void
     */
    public function setAutoOutput(bool $enabled): void
    {
        $this->autoOutput = $enabled;
    }

    // =============================================================================
    // PRIVATE HELPER METHODS
    // =============================================================================

    /**
     * Check if message should be logged based on category and level filters
     * 
     * @param int $category Message category
     * @param int $level Message level
     * @return bool Whether message should be logged
     */
    private function shouldLog(int $category, int $level): bool
    {
        // Check level threshold
        if ($level > $this->level) {
            return false;
        }

        // Check category filter (bitwise AND)
        if (!($this->categories & $category)) {
            return false;
        }

        return true;
    }

    /**
     * Analyze debug entry for automatic insights
     * 
     * @param array $entry Debug log entry
     * @return void
     */
    private function analyzeEntry(array $entry): void
    {
        // Track performance trends
        if ($entry['category'] === DebugCategory::SQL) {
            $this->updatePerformanceProfile('sql', $entry);
        } elseif ($entry['category'] === DebugCategory::BULK) {
            $this->updatePerformanceProfile('bulk', $entry);
        }
    }

    /**
     * Update performance profile with new data
     * 
     * @param string $type Operation type
     * @param array $entry Debug entry
     * @return void
     */
    private function updatePerformanceProfile(string $type, array $entry): void
    {
        if (!isset($this->performanceProfile[$type])) {
            $this->performanceProfile[$type] = [
                'count' => 0,
                'total_time' => 0,
                'avg_time' => 0,
                'peak_memory' => 0
            ];
        }

        $profile = &$this->performanceProfile[$type];
        $profile['count']++;

        if (isset($entry['context']['execution_time_ms'])) {
            $profile['total_time'] += $entry['context']['execution_time_ms'];
            $profile['avg_time'] = $profile['total_time'] / $profile['count'];
        }

        $profile['peak_memory'] = max($profile['peak_memory'], $entry['memory_usage']);
    }

    /**
     * Get session summary statistics
     * 
     * @return array Session summary data
     */
    private function getSessionSummary(): array
    {
        return [
            'session_duration' => microtime(true) - $this->sessionStart,
            'total_queries' => $this->queryCount,
            'slow_queries' => $this->slowQueryCount,
            'total_query_time' => $this->totalQueryTime,
            'avg_query_time' => $this->queryCount > 0 ? $this->totalQueryTime / $this->queryCount : 0,
            'messages_logged' => count($this->messages),
            'peak_memory_usage' => memory_get_peak_usage(true),
            'current_memory_usage' => memory_get_usage(true)
        ];
    }

    /**
     * Format single message for immediate output
     * 
     * @param array $entry Debug log entry
     * @return string Formatted message
     */
    private function formatSingleMessage(array $entry): string
    {
        $formatter = DebugFactory::createFormatter($this->format);
        return $formatter->formatSingle($entry);
    }
}
