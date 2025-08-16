<?php

/**
 * Performance Analyzer - Advanced Performance Analysis and Optimization Suggestions
 * 
 * Analyzes bulk operations, performance patterns, and provides intelligent
 * optimization recommendations based on actual performance data and patterns.
 */
class PerformanceAnalyzer
{
    private array $performanceHistory = [];
    private array $optimizationPatterns = [];
    private array $performanceBaselines = [];
    
    // Performance thresholds
    const EXCELLENT_PERFORMANCE = 10000;   // Records per second
    const GOOD_PERFORMANCE = 5000;
    const ACCEPTABLE_PERFORMANCE = 1000;
    const POOR_PERFORMANCE = 500;

    /**
     * Analyze bulk operation performance and provide optimization insights
     * 
     * @param string $operation Operation type (insert_batch, update_batch, etc.)
     * @param int $recordCount Number of records processed
     * @param array $context Operation context (timing, optimizations, etc.)
     * @return array Analysis results with insights and suggestions
     */
    public function analyzeBulkOperation(string $operation, int $recordCount, array $context): array
    {
        $analysis = [
            'operation' => $operation,
            'record_count' => $recordCount,
            'performance_tier' => 'unknown',
            'records_per_second' => 0,
            'optimization_decisions' => [],
            'warnings' => [],
            'insights' => [],
            'improvement_suggestions' => []
        ];

        // Calculate performance metrics
        if (isset($context['execution_time']) && $context['execution_time'] > 0) {
            $analysis['records_per_second'] = round($recordCount / $context['execution_time']);
            $analysis['performance_tier'] = $this->classifyPerformance($analysis['records_per_second']);
        }

        // Analyze optimization decisions
        $analysis['optimization_decisions'] = $this->analyzeOptimizationDecisions($context);

        // Generate warnings for potential issues
        $analysis['warnings'] = $this->generatePerformanceWarnings($operation, $recordCount, $context);

        // Generate insights based on performance patterns
        $analysis['insights'] = $this->generatePerformanceInsights($operation, $recordCount, $context);

        // Store performance data for pattern analysis
        $this->recordPerformanceData($operation, $recordCount, $context, $analysis);

        return $analysis;
    }

    /**
     * Classify performance based on records per second
     * 
     * @param int $recordsPerSecond Records processed per second
     * @return string Performance classification
     */
    private function classifyPerformance(int $recordsPerSecond): string
    {
        if ($recordsPerSecond >= self::EXCELLENT_PERFORMANCE) {
            return 'excellent';
        } elseif ($recordsPerSecond >= self::GOOD_PERFORMANCE) {
            return 'good';
        } elseif ($recordsPerSecond >= self::ACCEPTABLE_PERFORMANCE) {
            return 'acceptable';
        } elseif ($recordsPerSecond >= self::POOR_PERFORMANCE) {
            return 'poor';
        } else {
            return 'very_poor';
        }
    }

    /**
     * Analyze optimization decisions made during the operation
     * 
     * @param array $context Operation context
     * @return array Optimization decision analysis
     */
    private function analyzeOptimizationDecisions(array $context): array
    {
        $decisions = [];

        // Chunk size decisions
        if (isset($context['chunk_size'])) {
            if ($context['chunk_size'] === 'auto') {
                $decisions[] = "Auto-calculated optimal chunk size";
            } else {
                $decisions[] = "Used chunk size: {$context['chunk_size']}";
            }
        }

        // Bulk mode activation
        if (isset($context['bulk_mode_active']) && $context['bulk_mode_active']) {
            $decisions[] = "Bulk mode optimizations activated";
        }

        // Foreign key handling
        if (isset($context['optimizations']) && is_array($context['optimizations'])) {
            foreach ($context['optimizations'] as $optimization) {
                $decisions[] = "Applied optimization: $optimization";
            }
        }

        // Load factor adjustments
        if (isset($context['load_factor'])) {
            $loadFactor = $context['load_factor'];
            $decisions[] = "System load factor: $loadFactor (chunk size adjusted accordingly)";
        }

        return $decisions;
    }

    /**
     * Generate performance warnings based on operation analysis
     * 
     * @param string $operation Operation type
     * @param int $recordCount Number of records
     * @param array $context Operation context
     * @return array Performance warnings
     */
    private function generatePerformanceWarnings(string $operation, int $recordCount, array $context): array
    {
        $warnings = [];

        // Large dataset warnings
        if ($recordCount > 10000) {
            if (!isset($context['bulk_mode_active']) || !$context['bulk_mode_active']) {
                $warnings[] = "Processing {$recordCount} records without bulk mode - performance may be suboptimal";
            }
        }

        // Execution time warnings
        if (isset($context['execution_time'])) {
            $timePerRecord = ($context['execution_time'] / $recordCount) * 1000; // ms per record
            
            if ($timePerRecord > 1.0) {
                $warnings[] = "Slow processing: " . round($timePerRecord, 2) . "ms per record - investigate bottlenecks";
            }
        }

        // Memory usage warnings
        if (isset($context['memory_usage'])) {
            $memoryPerRecord = $context['memory_usage'] / $recordCount;
            
            if ($memoryPerRecord > 1024) { // More than 1KB per record
                $warnings[] = "High memory usage: " . round($memoryPerRecord) . " bytes per record";
            }
        }

        // Database-specific warnings
        if (isset($context['database_type'])) {
            switch ($context['database_type']) {
                case 'sqlite':
                    if ($recordCount > 1000 && (!isset($context['chunk_size']) || $context['chunk_size'] > 500)) {
                        $warnings[] = "SQLite may benefit from smaller chunk sizes for large datasets";
                    }
                    break;
                    
                case 'mysql':
                    if ($recordCount > 5000 && !isset($context['foreign_keys_disabled'])) {
                        $warnings[] = "Consider disabling foreign key checks for large MySQL bulk operations";
                    }
                    break;
            }
        }

        return $warnings;
    }

    /**
     * Generate performance insights based on patterns and history
     * 
     * @param string $operation Operation type
     * @param int $recordCount Number of records
     * @param array $context Operation context
     * @return array Performance insights
     */
    private function generatePerformanceInsights(string $operation, int $recordCount, array $context): array
    {
        $insights = [];

        // Performance comparison with previous operations
        $baseline = $this->getPerformanceBaseline($operation);
        if ($baseline && isset($context['execution_time'])) {
            $currentRate = $recordCount / $context['execution_time'];
            $baselineRate = $baseline['records_per_second'];
            
            $improvementPercent = (($currentRate - $baselineRate) / $baselineRate) * 100;
            
            if ($improvementPercent > 20) {
                $insights[] = "Performance improved by " . round($improvementPercent) . "% vs baseline";
            } elseif ($improvementPercent < -20) {
                $insights[] = "Performance degraded by " . round(abs($improvementPercent)) . "% vs baseline";
            }
        }

        // Optimization effectiveness
        if (isset($context['optimizations']) && !empty($context['optimizations'])) {
            $optimizationCount = count($context['optimizations']);
            $insights[] = "Applied {$optimizationCount} optimization(s) - monitor for effectiveness";
        }

        // Pattern recognition
        $pattern = $this->detectPerformancePattern($operation, $recordCount);
        if ($pattern) {
            $insights[] = "Pattern detected: {$pattern['description']}";
        }

        return $insights;
    }

    /**
     * Record performance data for pattern analysis
     * 
     * @param string $operation Operation type
     * @param int $recordCount Number of records
     * @param array $context Operation context
     * @param array $analysis Analysis results
     * @return void
     */
    private function recordPerformanceData(string $operation, int $recordCount, array $context, array $analysis): void
    {
        $dataPoint = [
            'timestamp' => time(),
            'operation' => $operation,
            'record_count' => $recordCount,
            'execution_time' => $context['execution_time'] ?? 0,
            'records_per_second' => $analysis['records_per_second'],
            'performance_tier' => $analysis['performance_tier'],
            'optimizations' => $context['optimizations'] ?? [],
            'context' => $context
        ];

        $this->performanceHistory[] = $dataPoint;

        // Keep only recent history to prevent memory bloat
        if (count($this->performanceHistory) > 100) {
            $this->performanceHistory = array_slice($this->performanceHistory, -50);
        }

        // Update baseline if this is significantly better performance
        $this->updatePerformanceBaseline($operation, $dataPoint);
    }

    /**
     * Get performance baseline for operation type
     * 
     * @param string $operation Operation type
     * @return array|null Baseline performance data
     */
    private function getPerformanceBaseline(string $operation): ?array
    {
        return $this->performanceBaselines[$operation] ?? null;
    }

    /**
     * Update performance baseline if current performance is significantly better
     * 
     * @param string $operation Operation type
     * @param array $dataPoint Current performance data
     * @return void
     */
    private function updatePerformanceBaseline(string $operation, array $dataPoint): void
    {
        $currentBaseline = $this->getPerformanceBaseline($operation);
        
        if (!$currentBaseline || $dataPoint['records_per_second'] > $currentBaseline['records_per_second'] * 1.5) {
            $this->performanceBaselines[$operation] = $dataPoint;
        }
    }

    /**
     * Detect performance patterns in historical data
     * 
     * @param string $operation Operation type
     * @param int $recordCount Current record count
     * @return array|null Pattern information
     */
    private function detectPerformancePattern(string $operation, int $recordCount): ?array
    {
        // Simple pattern detection - can be enhanced with more sophisticated algorithms
        $recentOperations = array_filter($this->performanceHistory, function($item) use ($operation) {
            return $item['operation'] === $operation && $item['timestamp'] > (time() - 3600); // Last hour
        });

        if (count($recentOperations) >= 3) {
            $performances = array_column($recentOperations, 'records_per_second');
            $trend = $this->calculateTrend($performances);
            
            if ($trend > 0.1) {
                return ['description' => 'Performance trending upward', 'trend' => $trend];
            } elseif ($trend < -0.1) {
                return ['description' => 'Performance trending downward', 'trend' => $trend];
            }
        }

        return null;
    }

    /**
     * Calculate performance trend from array of values
     * 
     * @param array $values Performance values
     * @return float Trend coefficient (positive = improving, negative = degrading)
     */
    private function calculateTrend(array $values): float
    {
        if (count($values) < 2) return 0;
        
        $n = count($values);
        $x = range(1, $n);
        $y = $values;
        
        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumXX = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumXX += $x[$i] * $x[$i];
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
        
        // Normalize slope relative to average performance
        $avgPerformance = array_sum($y) / count($y);
        return $avgPerformance > 0 ? $slope / $avgPerformance : 0;
    }

    /**
     * Get comprehensive performance statistics
     * 
     * @return array Performance statistics and patterns
     */
    public function getPerformanceStatistics(): array
    {
        return [
            'total_operations' => count($this->performanceHistory),
            'baselines' => $this->performanceBaselines,
            'recent_operations' => array_slice($this->performanceHistory, -10),
            'performance_distribution' => $this->getPerformanceDistribution(),
            'optimization_effectiveness' => $this->getOptimizationEffectiveness()
        ];
    }

    /**
     * Get performance distribution across tiers
     * 
     * @return array Performance tier distribution
     */
    private function getPerformanceDistribution(): array
    {
        $distribution = [
            'excellent' => 0,
            'good' => 0,
            'acceptable' => 0,
            'poor' => 0,
            'very_poor' => 0
        ];

        foreach ($this->performanceHistory as $operation) {
            $tier = $operation['performance_tier'];
            if (isset($distribution[$tier])) {
                $distribution[$tier]++;
            }
        }

        return $distribution;
    }

    /**
     * Analyze optimization effectiveness
     * 
     * @return array Optimization effectiveness analysis
     */
    private function getOptimizationEffectiveness(): array
    {
        $optimizedOperations = array_filter($this->performanceHistory, function($op) {
            return !empty($op['optimizations']);
        });

        $unoptimizedOperations = array_filter($this->performanceHistory, function($op) {
            return empty($op['optimizations']);
        });

        if (empty($optimizedOperations) || empty($unoptimizedOperations)) {
            return ['status' => 'insufficient_data'];
        }

        $optimizedAvg = array_sum(array_column($optimizedOperations, 'records_per_second')) / count($optimizedOperations);
        $unoptimizedAvg = array_sum(array_column($unoptimizedOperations, 'records_per_second')) / count($unoptimizedOperations);

        $improvement = $unoptimizedAvg > 0 ? (($optimizedAvg - $unoptimizedAvg) / $unoptimizedAvg) * 100 : 0;

        return [
            'status' => 'analyzed',
            'optimized_avg_rps' => round($optimizedAvg),
            'unoptimized_avg_rps' => round($unoptimizedAvg),
            'improvement_percent' => round($improvement, 1)
        ];
    }
}

// =============================================================================
// DEBUG FORMATTERS - MULTIPLE OUTPUT FORMATS
// =============================================================================

/**
 * Abstract Debug Formatter - Base for Different Output Formats
 */
abstract class DebugFormatter
{
    /**
     * Format debug messages for output
     * 
     * @param array $messages Debug messages
     * @param array $summary Session summary
     * @return string Formatted output
     */
    abstract public function format(array $messages, array $summary): string;

    /**
     * Format single debug message for immediate output
     * 
     * @param array $entry Debug message entry
     * @return string Formatted single message
     */
    abstract public function formatSingle(array $entry): string;

    /**
     * Get category icon for message
     * 
     * @param int $category Debug category
     * @return string Icon/emoji for category
     */
    protected function getCategoryIcon(int $category): string
    {
        return match($category) {
            DebugCategory::SQL => '🔍',
            DebugCategory::PERFORMANCE => '⚡',
            DebugCategory::BULK => '📦',
            DebugCategory::CACHE => '💾',
            DebugCategory::TRANSACTION => '🔄',
            DebugCategory::MAINTENANCE => '🔧',
            DebugCategory::SECURITY => '🛡️',
            default => '📝'
        };
    }

    /**
     * Get category name
     * 
     * @param int $category Debug category
     * @return string Category name
     */
    protected function getCategoryName(int $category): string
    {
        return match($category) {
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
}

/**
 * HTML Debug Formatter - Rich HTML Output for Web Development
 */
class HtmlDebugFormatter extends DebugFormatter
{
    public function format(array $messages, array $summary): string
    {
        if (empty($messages)) {
            return '<div style="font-family: monospace; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin: 10px 0;">No debug messages</div>';
        }

        $html = '<div style="font-family: Arial, sans-serif; max-width: 1200px; margin: 10px 0; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px;">';
        
        // Header with summary
        $html .= $this->formatSummaryHeader($summary);
        
        // Messages grouped by category
        $messagesByCategory = $this->groupMessagesByCategory($messages);
        
        foreach ($messagesByCategory as $category => $categoryMessages) {
            $html .= $this->formatCategorySection($category, $categoryMessages);
        }
        
        $html .= '</div>';
        
        return $html;
    }

    public function formatSingle(array $entry): string
    {
        $icon = $this->getCategoryIcon($entry['category']);
        $category = $this->getCategoryName($entry['category']);
        $timestamp = date('H:i:s', (int)$entry['timestamp']);
        $message = htmlspecialchars($entry['message']);
        
        $style = $this->getCategoryStyle($entry['category']);
        
        $html = "<div style='font-family: monospace; padding: 8px; margin: 2px 0; border-left: 4px solid {$style['color']}; background: {$style['background']};'>";
        $html .= "<span style='color: #666; font-size: 0.9em;'>[{$timestamp}]</span> ";
        $html .= "<span style='color: {$style['color']}; font-weight: bold;'>{$icon} {$category}:</span> ";
        $html .= "<span>{$message}</span>";
        
        // Add context details if available
        if (!empty($entry['context'])) {
            $html .= "<div style='margin-top: 4px; font-size: 0.9em; color: #666;'>";
            $html .= $this->formatContextDetails($entry['context']);
            $html .= "</div>";
        }
        
        $html .= "</div>";
        
        return $html;
    }

    private function formatSummaryHeader(array $summary): string
    {
        $duration = round($summary['session_duration'], 2);
        $memory = round($summary['peak_memory_usage'] / 1024 / 1024, 1);
        
        $html = '<div style="background: #343a40; color: white; padding: 12px; border-radius: 8px 8px 0 0;">';
        $html .= '<h3 style="margin: 0; font-size: 16px;">🐛 Enhanced Model Debug Session</h3>';
        $html .= '<div style="font-size: 14px; margin-top: 8px;">';
        $html .= "⏱️ Duration: {$duration}s | ";
        $html .= "🔍 Queries: {$summary['total_queries']} | ";
        $html .= "🐌 Slow: {$summary['slow_queries']} | ";
        $html .= "📝 Messages: {$summary['messages_logged']} | ";
        $html .= "💾 Memory: {$memory}MB";
        $html .= '</div></div>';
        
        return $html;
    }

    private function formatCategorySection(int $category, array $messages): string
    {
        $icon = $this->getCategoryIcon($category);
        $name = $this->getCategoryName($category);
        $style = $this->getCategoryStyle($category);
        
        $html = "<div style='margin: 16px; border: 1px solid {$style['color']}; border-radius: 6px;'>";
        $html .= "<div style='background: {$style['color']}; color: white; padding: 8px; font-weight: bold;'>";
        $html .= "{$icon} {$name} (" . count($messages) . " messages)";
        $html .= "</div>";
        
        $html .= "<div style='padding: 12px; background: white;'>";
        foreach ($messages as $message) {
            $html .= $this->formatSingleMessage($message);
        }
        $html .= "</div></div>";
        
        return $html;
    }

    private function formatSingleMessage(array $entry): string
    {
        $timestamp = date('H:i:s.v', (int)$entry['timestamp']);
        $message = htmlspecialchars($entry['message']);
        
        $html = "<div style='margin: 8px 0; padding: 8px; border-left: 3px solid #ddd; background: #f8f9fa;'>";
        $html .= "<div style='font-weight: bold; color: #495057;'>{$message}</div>";
        
        if (!empty($entry['context'])) {
            $html .= "<div style='margin-top: 8px; font-size: 0.9em;'>";
            $html .= $this->formatContextDetails($entry['context']);
            $html .= "</div>";
        }
        
        $html .= "<div style='text-align: right; font-size: 0.8em; color: #6c757d; margin-top: 4px;'>{$timestamp}</div>";
        $html .= "</div>";
        
        return $html;
    }

    private function formatContextDetails(array $context): string
    {
        $html = '';
        
        foreach ($context as $key => $value) {
            if ($key === 'sql' && is_string($value)) {
                // Truncate bulk SQL for readability
                $truncatedSql = $this->truncateBulkSQL($value);
                $html .= "<div><strong>SQL:</strong><br><code style='background: #e9ecef; padding: 4px; border-radius: 3px; display: block; margin: 4px 0; max-height: 300px; overflow-y: auto;'>" . htmlspecialchars($truncatedSql) . "</code></div>";
            } elseif ($key === 'params' && is_array($value) && !empty($value)) {
                $html .= "<div><strong>Parameters:</strong> " . htmlspecialchars(json_encode($value)) . "</div>";
            } elseif ($key === 'execution_time_ms') {
                $html .= "<div><strong>Execution Time:</strong> {$value}ms</div>";
            } elseif ($key === 'records_per_second') {
                $html .= "<div><strong>Performance:</strong> " . number_format($value) . " records/sec</div>";
            } elseif ($value !== null && !is_array($value) && !is_object($value)) {
                $html .= "<div><strong>" . ucfirst(str_replace('_', ' ', $key)) . ":</strong> " . htmlspecialchars((string)$value) . "</div>";          
            }
        }
        
        return $html;
    }

    /**
     * Intelligently truncate bulk SQL queries for readable debug output
     * 
     * @param string $sql SQL query to truncate
     * @return string Truncated SQL suitable for debug display
     */
    private function truncateBulkSQL(string $sql): string
    {
        // Don't truncate short queries
        if (strlen($sql) < 500) {
            return $sql;
        }

        // Handle bulk INSERT operations
        if (preg_match('/INSERT INTO.+?VALUES\s*(.+)/i', $sql, $matches)) {
            return $this->truncateBulkInsert($sql, $matches[1]);
        }

        // Handle bulk UPDATE operations with CASE statements
        if (preg_match('/UPDATE.+?SET.+?CASE/i', $sql) && substr_count($sql, 'CASE') > 2) {
            return $this->truncateBulkUpdate($sql);
        }

        // Handle very long queries (generic truncation)
        if (strlen($sql) > 2000) {
            return substr($sql, 0, 1000) . "\n\n... [TRUNCATED - Query length: " . number_format(strlen($sql)) . " characters] ...\n\n" . substr($sql, -200);
        }

        return $sql;
    }

    /**
     * Truncate bulk INSERT VALUES clause to show pattern
     * 
     * @param string $fullSql Complete INSERT statement
     * @param string $valuesClause The VALUES portion of the query
     * @return string Truncated INSERT statement
     */
    private function truncateBulkInsert(string $fullSql, string $valuesClause): string
    {
        // Count parameter sets in VALUES clause
        $parameterSets = substr_count($valuesClause, '(');

        if ($parameterSets <= 3) {
            return $fullSql; // Short enough, don't truncate
        }

        // Extract the first few parameter sets
        $pattern = '/(\([^)]+\))/';
        preg_match_all($pattern, $valuesClause, $matches);

        if (count($matches[1]) >= 3) {
            $firstThreeSets = implode(',', array_slice($matches[1], 0, 3));
            $remainingCount = $parameterSets - 3;

            $insertPart = substr($fullSql, 0, strpos($fullSql, 'VALUES') + 6);
            $truncatedSql = $insertPart . " $firstThreeSets,\n... [+ $remainingCount more parameter sets] ...";

            return $truncatedSql;
        }

        return $fullSql;
    }

    /**
     * Truncate bulk UPDATE with CASE statements
     * 
     * @param string $sql Complete UPDATE statement with CASE clauses
     * @return string Truncated UPDATE statement
     */
    private function truncateBulkUpdate(string $sql): string
    {
        $caseCount = substr_count($sql, 'CASE');

        if ($caseCount <= 3) {
            return $sql; // Short enough, don't truncate
        }

        // Find the position after the first few CASE statements
        $casePositions = [];
        $offset = 0;
        while (($pos = stripos($sql, 'CASE', $offset)) !== false) {
            $casePositions[] = $pos;
            $offset = $pos + 4;
            if (count($casePositions) >= 2) break; // Get first 2 CASE positions
        }

        if (count($casePositions) >= 2) {
            // Find the end of the second CASE statement
            $secondCaseEnd = stripos($sql, 'END', $casePositions[1]);
            if ($secondCaseEnd !== false) {
                $truncatedSql = substr($sql, 0, $secondCaseEnd + 3);
                $remainingCases = $caseCount - 2;
                $truncatedSql .= ",\n... [+ $remainingCases more CASE statements] ...\nWHERE " .
                    substr($sql, stripos($sql, 'WHERE') + 5);

                return $truncatedSql;
            }
        }

        return $sql;
    }

    private function getCategoryStyle(int $category): array
    {
        return match($category) {
            DebugCategory::SQL => ['color' => '#007bff', 'background' => '#e3f2fd'],
            DebugCategory::PERFORMANCE => ['color' => '#dc3545', 'background' => '#ffebee'],
            DebugCategory::BULK => ['color' => '#28a745', 'background' => '#e8f5e9'],
            DebugCategory::CACHE => ['color' => '#6f42c1', 'background' => '#f3e5f5'],
            DebugCategory::TRANSACTION => ['color' => '#fd7e14', 'background' => '#fff3e0'],
            DebugCategory::MAINTENANCE => ['color' => '#20c997', 'background' => '#e0f2f1'],
            DebugCategory::SECURITY => ['color' => '#e83e8c', 'background' => '#fce4ec'],
            default => ['color' => '#6c757d', 'background' => '#f8f9fa']
        };
    }

    private function groupMessagesByCategory(array $messages): array
    {
        $grouped = [];
        foreach ($messages as $message) {
            $category = $message['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $message;
        }
        return $grouped;
    }
}

/**
 * Text Debug Formatter - Clean Text Output for CLI/Logs
 */
class TextDebugFormatter extends DebugFormatter
{
    public function format(array $messages, array $summary): string
    {
        if (empty($messages)) {
            return "=== Enhanced Model Debug Session ===\nNo debug messages\n";
        }

        $output = "=== Enhanced Model Debug Session ===\n";
        $output .= $this->formatTextSummary($summary);
        $output .= "\n=== Debug Messages ===\n";
        
        foreach ($messages as $message) {
            $output .= $this->formatSingleMessage($message);
        }
        
        return $output;
    }

    public function formatSingle(array $entry): string
    {
        $icon = $this->getCategoryIcon($entry['category']);
        $category = $this->getCategoryName($entry['category']);
        $timestamp = date('H:i:s', (int)$entry['timestamp']);
        $message = $entry['message'];
        
        $output = "[{$timestamp}] {$icon} {$category}: {$message}\n";
        
        if (!empty($entry['context'])) {
            $output .= $this->formatTextContext($entry['context']);
        }
        
        return $output;
    }

    private function formatTextSummary(array $summary): string
    {
        $duration = round($summary['session_duration'], 2);
        $memory = round($summary['peak_memory_usage'] / 1024 / 1024, 1);
        
        $output = "Duration: {$duration}s | ";
        $output .= "Queries: {$summary['total_queries']} | ";
        $output .= "Slow: {$summary['slow_queries']} | ";
        $output .= "Messages: {$summary['messages_logged']} | ";
        $output .= "Memory: {$memory}MB\n";
        
        return $output;
    }

    private function formatSingleMessage(array $entry): string
    {
        return $this->formatSingle($entry) . "\n";
    }

    private function formatTextContext(array $context): string
    {
        $output = '';
        
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            $output .= "  {$key}: {$value}\n";
        }
        
        return $output;
    }
}

/**
 * JSON Debug Formatter - Structured Data for Programmatic Access
 */
class JsonDebugFormatter extends DebugFormatter
{
    public function format(array $messages, array $summary): string
    {
        return json_encode([
            'debug_session' => [
                'summary' => $summary,
                'messages' => $messages,
                'generated_at' => date('c'),
                'format' => 'json'
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function formatSingle(array $entry): string
    {
        return json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    }
}

/**
 * ANSI Debug Formatter - Colored Text Output for CLI
 */
class AnsiDebugFormatter extends DebugFormatter
{
    private const COLORS = [
        'reset' => "\033[0m",
        'bold' => "\033[1m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'purple' => "\033[35m",
        'cyan' => "\033[36m",
        'gray' => "\033[90m"
    ];

    public function format(array $messages, array $summary): string
    {
        if (empty($messages)) {
            return $this->color('=== Enhanced Model Debug Session ===', 'bold') . "\n" .
                   $this->color('No debug messages', 'gray') . "\n";
        }

        $output = $this->color('=== Enhanced Model Debug Session ===', 'bold') . "\n";
        $output .= $this->formatAnsiSummary($summary);
        $output .= "\n" . $this->color('=== Debug Messages ===', 'bold') . "\n";
        
        foreach ($messages as $message) {
            $output .= $this->formatSingleMessage($message);
        }
        
        return $output;
    }

    public function formatSingle(array $entry): string
    {
        $color = $this->getCategoryColor($entry['category']);
        $icon = $this->getCategoryIcon($entry['category']);
        $category = $this->getCategoryName($entry['category']);
        $timestamp = date('H:i:s', (int)$entry['timestamp']);
        $message = $entry['message'];
        
        $output = $this->color("[{$timestamp}]", 'gray') . " ";
        $output .= $this->color("{$icon} {$category}:", $color) . " ";
        $output .= $message . "\n";
        
        if (!empty($entry['context'])) {
            $output .= $this->formatAnsiContext($entry['context']);
        }
        
        return $output;
    }

    private function formatAnsiSummary(array $summary): string
    {
        $duration = round($summary['session_duration'], 2);
        $memory = round($summary['peak_memory_usage'] / 1024 / 1024, 1);
        
        $output = $this->color("Duration:", 'bold') . " {$duration}s | ";
        $output .= $this->color("Queries:", 'bold') . " {$summary['total_queries']} | ";
        $output .= $this->color("Slow:", 'bold') . " " . $this->color($summary['slow_queries'], $summary['slow_queries'] > 0 ? 'red' : 'green') . " | ";
        $output .= $this->color("Messages:", 'bold') . " {$summary['messages_logged']} | ";
        $output .= $this->color("Memory:", 'bold') . " {$memory}MB\n";
        
        return $output;
    }

    private function formatSingleMessage(array $entry): string
    {
        return $this->formatSingle($entry) . "\n";
    }

    private function formatAnsiContext(array $context): string
    {
        $output = '';
        
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            $output .= "  " . $this->color($key . ":", 'cyan') . " {$value}\n";
        }
        
        return $output;
    }

    private function getCategoryColor(int $category): string
    {
        return match($category) {
            DebugCategory::SQL => 'blue',
            DebugCategory::PERFORMANCE => 'red',
            DebugCategory::BULK => 'green',
            DebugCategory::CACHE => 'purple',
            DebugCategory::TRANSACTION => 'yellow',
            DebugCategory::MAINTENANCE => 'cyan',
            DebugCategory::SECURITY => 'purple',
            default => 'gray'
        };
    }

    private function color(string $text, string $color): string
    {
        return (self::COLORS[$color] ?? '') . $text . self::COLORS['reset'];
    }
}