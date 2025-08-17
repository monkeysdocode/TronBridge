# Advanced Debug & Performance System
> Zero-overhead debugging with database-specific optimization insights

TronBridge includes a comprehensive **factory-based debug system** that provides powerful debugging capabilities while maintaining **zero performance regression** when debugging is disabled.

## 🎯 **Key Features**

- **🏭 Factory-Based Architecture** - Zero overhead when disabled, comprehensive insights when enabled
- **🔍 Database-Specific Analysis** - MySQL, SQLite, PostgreSQL specific query profilers  
- **📊 Performance Optimization** - Automatic index recommendations with actual SQL commands
- **🎨 Multiple Output Formats** - HTML, CLI, JSON, ANSI for different environments
- **🧠 Session Intelligence** - Pattern recognition and grouped recommendations
- **⚡ Zero Breaking Changes** - Perfect integration with existing TronBridge code

---

## ⚡ **Quick Start**

### **Enable Developer Debugging**
```php
$model = new Model('users');
$model->setDebugPreset('developer');

$users = $model->get_where_custom('status', 'active', '=', 'created_at', 'users', 50);

// Automatically shows:
// - Query execution time and analysis
// - Database-specific EXPLAIN plan interpretation
// - Index usage recommendations with CREATE INDEX statements
// - Session-level performance insights

echo $model->getDebugOutput(); // Rich HTML debug panel
```

### **CLI-Friendly Debugging**
```php
$model->setDebugPreset('cli');

$products = $model->get_where_custom('category_id', 5, '=', 'name', 'products', 20);

// Real-time colored output:
// [14:23:15] 🔍 Query Q1: SELECT execution (23ms)
// [14:23:15] ⚠️  Full table scan detected - add index recommendation
// [14:23:15] 🎯 CREATE INDEX idx_products_category ON products (category_id);
```

### **Production Monitoring**
```php
$model->setDebugPreset('production');

// Process bulk operations
$model->insert_batch('orders', $orderData);

// Log performance data for monitoring systems
$debugData = $model->getDebugData();
file_put_contents('/var/log/database_performance.json', json_encode($debugData));
```

---

## 🏗️ **Debug System Architecture**

### **Factory Pattern Benefits**
- **Zero Performance Impact** - No debug objects created when debugging disabled
- **Clean Model Class** - All debug logic external to main Model via factory methods
- **Lazy Loading** - Debug components created only when needed
- **Database-Specific** - MySQL, SQLite, PostgreSQL specific analyzers with fallback
- **Extensible** - Easy to add new debug analyzers

### **Core Components**
1. **DebugFactory** - Central factory for all debug components
2. **DebugCollector** - Main debug information collection and management
3. **QueryProfiler** - Database-specific query analysis with EXPLAIN plans
4. **PerformanceAnalyzer** - Advanced performance analysis and optimization suggestions
5. **Debug Formatters** - Multiple output formats (HTML, Text, JSON, ANSI)

---

## 🔧 **Debug Configuration**

### **Debug Levels** (Hierarchical)
```php
// Debug levels include all lower levels
DebugLevel::BASIC     // Essential operations only
DebugLevel::DETAILED  // + Performance metrics, optimization decisions  
DebugLevel::VERBOSE   // + All internal operations, detailed analysis
```

### **Debug Categories** (Combinable)
```php
// Categories can be combined with bitwise OR
DebugCategory::SQL          // Query execution, EXPLAIN plans
DebugCategory::PERFORMANCE  // Timing, memory, optimization decisions
DebugCategory::BULK         // Bulk operation analysis
DebugCategory::CACHE        // Cache hits/misses, warming
DebugCategory::TRANSACTION  // Transaction lifecycle
DebugCategory::MAINTENANCE  // Database maintenance operations
DebugCategory::SECURITY     // Validation, escaping, security checks

// Convenience combinations
DebugCategory::ALL          // All categories
DebugCategory::DEVELOPER    // SQL + PERFORMANCE + BULK (most useful for development)
DebugCategory::PRODUCTION   // PERFORMANCE only (for production monitoring)
```

### **Quick Presets**
```php
// Simple preset configuration
$model->setDebugPreset('off');         // Disable debugging completely
$model->setDebugPreset('basic');       // Basic SQL logging only
$model->setDebugPreset('developer');   // Rich HTML output for development
$model->setDebugPreset('performance'); // Performance monitoring and bulk operation analysis
$model->setDebugPreset('cli');         // CLI-friendly debugging with ANSI colors
$model->setDebugPreset('production');  // Production-safe performance monitoring with JSON
$model->setDebugPreset('verbose');     // Maximum debugging with all categories
```

### **Advanced Configuration**
```php
// Fine-grained control
$model->setDebug(DebugLevel::DETAILED, DebugCategory::SQL | DebugCategory::PERFORMANCE, 'html');

// Legacy boolean support (backwards compatible)
$model->setDebug(true);  // Basic debugging with default settings
```

---

## 🔍 **Database-Specific Analysis**

TronBridge provides specialized query analysis for each supported database type.

### **MySQL QueryProfiler**

#### **Features**
- **JSON EXPLAIN Analysis** - MySQL EXPLAIN with JSON format support
- **Access Type Detection** - Identifies table scans, index usage, join types
- **Performance Warnings** - Filesort, temporary tables, complex joins
- **Index Recommendations** - Specific CREATE INDEX statements

#### **Example Output**
```php
$model = new Model('products', 'mysql:host=localhost;dbname=shop');
$model->setDebugPreset('developer');

$products = $model->get_where_custom('category_id', 5, '=', 'price', 'products', 20);

// Automatic output includes:
// 🚨 Query Q1: SLOW query on 'products' (245ms) - optimization needed
// ⚠️  Query Q1: Full table scan on 'products' (15,000 rows examined)
// 🎯 Query Q1: CREATE INDEX idx_products_category ON products (category_id);
// 💡 Query Q1: Using filesort - consider ORDER BY optimization
// ✅ Query Q2: Efficient ref access using index 'idx_price'
```

#### **MySQL-Specific Optimizations**
```php
// MySQL profiler automatically detects:
// - Table access types (const, eq_ref, ref, range, index, ALL)
// - Index usage patterns
// - Join algorithm selection
// - Temporary table creation
// - Filesort operations
// - Query cache effectiveness

$debugOutput = $model->getDebugOutput();
// Shows MySQL-specific EXPLAIN analysis with recommendations
```

### **SQLite QueryProfiler**

#### **Features**
- **EXPLAIN QUERY PLAN Analysis** - SQLite's query plan interpretation
- **Covering Index Detection** - Identifies optimal index usage
- **Table Scan Warnings** - Alerts for inefficient full table scans
- **SQLite-Specific Recommendations** - Database-appropriate optimizations

#### **Example Output**
```php
$model = new Model('products', 'sqlite:./shop.sqlite');
$model->setDebugPreset('developer');

$products = $model->get_where_custom('category_id', 5, '=', 'name', 'products');

// SQLite-specific analysis:
// 🔍 Query Q1: SQLite EXPLAIN QUERY PLAN analysis
// ⚠️  SCAN TABLE products - consider adding index
// 🎯 Query Q1: CREATE INDEX idx_products_category ON products (category_id);
// ⭐ Query Q2: Excellent! Using covering index - no table access needed
// 💡 LIKE pattern detected - consider SQLite FTS for text search
```

#### **SQLite-Specific Features**
```php
// SQLite profiler handles:
// - SCAN vs SEARCH operations
// - Covering index detection
// - Automatic index creation suggestions
// - FTS (Full-Text Search) recommendations
// - WAL mode optimization tips

$stats = $model->getPerformanceStats();
// Includes SQLite-specific performance metrics
```

### **PostgreSQL QueryProfiler**

#### **Features**
- **Cost-Based Analysis** - PostgreSQL's cost-based optimizer insights
- **Advanced EXPLAIN** - ANALYZE and BUFFERS support
- **Index-Only Scans** - Detection of optimal index usage
- **Join Optimization** - PostgreSQL-specific join strategies

#### **Example Output**
```php
$model = new Model('products', 'postgresql:host=localhost;dbname=shop');
$model->setDebugPreset('developer');

$products = $model->get_where_custom('category_id', 5, '=', 'name', 'products');

// PostgreSQL-specific analysis:
// 🔍 Query Q1: PostgreSQL EXPLAIN ANALYZE results
// ⚠️  Sequential scan on 'products' (cost: 15,000) - add index
// 🎯 Query Q1: CREATE INDEX idx_products_category ON products (category_id);
// ⭐ Query Q2: Index-only scan - excellent performance!
// 💡 Complex JOIN detected - consider PostgreSQL work_mem optimization
```

#### **PostgreSQL-Specific Features**
```php
// PostgreSQL profiler analyzes:
// - Query execution costs
// - Buffer hit ratios
// - Index-only scan opportunities
// - Parallel query execution
// - Advanced join strategies
// - Constraint exclusion

$debugData = $model->getDebugData();
// Includes PostgreSQL cost analysis and optimization suggestions
```

---

## 💡 **Enhanced Suggestions System**

### **Before: Basic Suggestions**
```
💡 SUGGESTION: Consider selecting specific columns instead of SELECT *
💡 SUGGESTION: Full index scan - may benefit from covering index
```

### **After: Context-Aware Suggestions**
```
🚨 Query Q1: VERY SLOW query on 'users' (1,245ms) - immediate optimization needed
⚠️  Query Q1: Full table scan on 'users' (25,000 rows examined) - add appropriate indexes
🎯 Query Q1: CREATE INDEX idx_users_email ON users (email) -- to eliminate table scan
💡 Query Q1 on 'users': Replace SELECT * with specific columns (1,245ms)
    Example: SELECT id, name, email FROM users instead of SELECT *

⚠️  Query Q2: Using filesort on 'products' (167ms) - create index on ORDER BY columns
🎯 Query Q2: CREATE INDEX idx_products_category_price ON products (category_id, price);

✅ Query Q3: Efficient ref access on 'orders' using index 'idx_customer_id' (12ms)
⭐ Query Q4: Excellent! Using covering index 'idx_products_category_price' - no table access needed

📊 SESSION ANALYSIS: 5 queries executed
🎯 TOP INDEX RECOMMENDATIONS:
  • CREATE INDEX idx_users_email ON users (email);
  • CREATE INDEX idx_products_category_price ON products (category_id, price);

⚠️  PERFORMANCE ISSUES DETECTED:
  • Query Q1: VERY SLOW query requiring immediate attention
```

### **Priority-Based Suggestions**
- **🚨 Critical Priority** (>1 second queries) - Immediate optimization needed
- **⚠️ High Priority** - Index opportunities, slow queries
- **💡 Medium Priority** - Structure improvements  
- **✅ Low Priority** - Positive feedback

---

## 📊 **Session-Level Intelligence**

### **Pattern Recognition**
```php
$model = new Model('products');
$model->setDebugPreset('developer');

// Execute multiple queries
for ($i = 0; $i < 10; $i++) {
    $products = $model->get_where_custom('category_id', rand(1, 5), '=', 'name', 'products');
}

$debugOutput = $model->getDebugOutput();
// Shows session summary with pattern analysis:

// 📊 SESSION ANALYSIS: 10 queries executed
// 🔍 PATTERNS DETECTED:
//   • Repeated queries on 'category_id' column (10 times)
//   • Same table access pattern detected
//   • Consistent full table scans
// 
// 🎯 CONSOLIDATED RECOMMENDATIONS:
//   • CREATE INDEX idx_products_category ON products (category_id); -- affects all 10 queries
//   • Consider caching for repeated category queries
// 
// ⚠️  PERFORMANCE IMPACT:
//   • Total scan time: 2.1 seconds
//   • Potential improvement with index: ~85% faster
```

### **Suggestion Deduplication**
- Tracks repeated suggestions to avoid spam
- Groups related recommendations
- Provides impact analysis for optimization decisions
- Suggests broader architectural improvements

---

## 🎨 **Output Formats**

### **HTML Format** (Web Development)
```php
$model->setDebugPreset('developer');
// or
$model->setDebug(DebugLevel::DETAILED, DebugCategory::ALL, 'html');

$output = $model->getDebugOutput();
// Rich HTML with:
// - Collapsible sections
// - Syntax highlighting
// - Color-coded priority levels
// - Interactive elements
// - Copy-paste ready SQL statements
```

### **ANSI Format** (CLI Scripts)
```php
$model->setDebugPreset('cli');
// or  
$model->setDebug(DebugLevel::DETAILED, DebugCategory::ALL, 'ansi');

// Colored CLI output with:
// - Color-coded messages
// - Progress indicators
// - Formatted tables
// - Easy-to-read hierarchy
```

### **JSON Format** (Production Monitoring)
```php
$model->setDebugPreset('production');
// or
$model->setDebug(DebugLevel::BASIC, DebugCategory::PERFORMANCE, 'json');

$debugData = $model->getDebugData();
file_put_contents('/var/log/db_performance.json', json_encode($debugData, JSON_PRETTY_PRINT));

// Structured data perfect for:
// - Log aggregation systems
// - Monitoring dashboards  
// - Performance analytics
// - Automated alerting
```

### **Text Format** (Simple Logging)
```php
$model->setDebug(DebugLevel::BASIC, DebugCategory::SQL, 'text');

$output = $model->getDebugOutput();
// Clean text output for:
// - Simple log files
// - Email reports
// - Basic documentation
```

---

## 🚀 **Performance Impact Analysis**

### **Zero Overhead When Disabled**
```php
$model->setDebug(false);
// OR
$model->setDebugPreset('off');
// Absolutely zero debug code runs - no objects created, no processing
```

### **Minimal Overhead When Enabled**
Based on comprehensive testing:

| Debug Level | Overhead | Use Case |
|-------------|----------|----------|
| **No Debug** | 0% | Production |
| **Basic Debug** | 2-5% | Basic SQL logging |
| **Detailed Debug** | 5-10% | Development debugging |
| **Verbose Debug** | 10-15% | Comprehensive analysis |

### **Smart Caching Eliminates Redundancy**
- Query analysis cached to avoid re-analyzing similar queries
- Prepared statement profiling cached by database type
- Validation results cached for repeated operations

---

## 🎯 **Real-World Usage Examples**

### **Development Workflow**
```php
// Enable rich debugging during development
$model = new Model('blog');
$model->setDebugPreset('developer');

// Your normal development work
$posts = $model->get_where_custom('status', 'published', '=', 'created_at', 'posts', 10);
$comments = $model->get_where_custom('post_id', $postId, '=', 'created_at', 'comments');

// Get comprehensive debug insights
$debugOutput = $model->getDebugOutput();
// Save to file and open in browser for rich HTML analysis
file_put_contents('debug_output.html', $debugOutput);

// Or get raw data for analysis
$debugData = $model->getDebugData();
echo "Session queries: " . $debugData['session_summary']['total_queries'];
echo "Slow queries: " . $debugData['session_summary']['slow_queries'];
```

### **Performance Optimization Workflow**
```php
// Step 1: Identify slow queries
$model->setDebugPreset('performance');

// Run your application normally
$users = $model->get_where_custom('email', $email, '=', 'last_login', 'users');
$orders = $model->get_where_custom('user_id', $userId, '=', 'created_at', 'orders', 20);

// Step 2: Analyze recommendations
$debugData = $model->getDebugData();
foreach ($debugData['messages'] as $message) {
    if (strpos($message['message'], 'CREATE INDEX') !== false) {
        echo "Index recommendation: " . $message['message'] . "\n";
    }
}

// Step 3: Implement suggestions
// Copy-paste the CREATE INDEX statements from debug output

// Step 4: Verify improvements
$model->setDebugPreset('developer');
// Re-run the same queries and see improved performance
```

### **Production Monitoring**
```php
// Lightweight production monitoring
$model->setDebugPreset('production');

// Your production operations
$model->insert_batch('analytics', $analyticsData);

// Log performance metrics
$performanceData = $model->getDebugData();
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'operation' => 'analytics_batch_insert',
    'records' => count($analyticsData),
    'duration' => $performanceData['session_summary']['session_duration'],
    'memory_peak' => $performanceData['session_summary']['peak_memory_usage']
];

file_put_contents('/var/log/app_performance.json', json_encode($logEntry) . "\n", FILE_APPEND);

// Set up alerts for slow operations
if ($performanceData['session_summary']['session_duration'] > 5.0) {
    // Send alert - operation took longer than 5 seconds
    error_log("SLOW OPERATION ALERT: Analytics insert took " . $performanceData['session_summary']['session_duration'] . " seconds");
}
```

### **CLI Script Debugging**
```php
#!/usr/bin/env php
<?php
// batch_processor.php

require_once 'engine/Model.php';

$model = new Model('batch_processing');
$model->setDebugPreset('cli');

echo "Starting batch processing...\n";

$processed = 0;
foreach ($dataFiles as $file) {
    $records = parseDataFile($file);
    $inserted = $model->insert_batch('processed_data', $records);
    $processed += $inserted;
    
    echo "Processed $inserted records from $file\n";
}

echo "\nBatch processing completed: $processed total records\n";

// Show performance summary
$stats = $model->getPerformanceStats();
echo "Performance summary:\n";
echo "- Total queries: " . $stats['session_operation_count'] . "\n";
echo "- Cache hits: " . $stats['cache_stats']['cached_sql'] . "\n";
echo "- Bulk mode: " . ($stats['bulk_mode_active'] ? 'Active' : 'Inactive') . "\n";
```

---

## 🔧 **Advanced Debug Features**

### **Custom Debug Logging**
```php
// Add custom debug messages to your application
$model->debugLog("Starting user authentication process", DebugCategory::SECURITY, DebugLevel::DETAILED, [
    'user_id' => $userId,
    'ip_address' => $_SERVER['REMOTE_ADDR'],
    'timestamp' => time()
]);

// Custom performance tracking
$startTime = microtime(true);
// ... your complex operation ...
$endTime = microtime(true);

$model->debugLog("Complex operation completed", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
    'operation' => 'user_data_aggregation',
    'duration_ms' => round(($endTime - $startTime) * 1000, 2),
    'records_processed' => $recordCount
]);
```

### **Debug Output Customization**
```php
// Get raw debug data for custom processing
$debugData = $model->getDebugData();

// Extract specific information
$slowQueries = array_filter($debugData['messages'], function($message) {
    return $message['category'] === DebugCategory::SQL && 
           isset($message['context']['execution_time_ms']) && 
           $message['context']['execution_time_ms'] > 100;
});

// Create custom reports
$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'total_queries' => $debugData['session_summary']['total_queries'],
    'slow_queries' => count($slowQueries),
    'recommendations' => extractRecommendations($debugData['messages'])
];

// Send to monitoring system
sendToMonitoring($report);
```

### **Conditional Debug Activation**
```php
// Enable debugging based on conditions
if ($_GET['debug'] === 'true' && isAdmin()) {
    $model->setDebugPreset('developer');
}

// Environment-based debugging
if (ENVIRONMENT === 'development') {
    $model->setDebugPreset('developer');
} elseif (ENVIRONMENT === 'staging') {
    $model->setDebugPreset('performance');
} else {
    // Production - no debugging by default
    $model->setDebugPreset('off');
}

// Performance-based activation
$startMemory = memory_get_usage();
// ... operations ...
$endMemory = memory_get_usage();

if (($endMemory - $startMemory) > 50 * 1024 * 1024) { // 50MB threshold
    $model->setDebugPreset('verbose');
    $model->debugLog("High memory usage detected", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
        'memory_increase' => $endMemory - $startMemory,
        'threshold_exceeded' => true
    ]);
}
```

---

## 🛠️ **Troubleshooting Debug Issues**

### **Debug Output Not Showing**
```php
// Verify debug configuration
$model->setDebugPreset('developer');

// Check if debug is actually enabled
$debugData = $model->getDebugData();
var_dump($debugData['configuration']);

// Manual verification
$model->debugLog("Test debug message", DebugCategory::SQL, DebugLevel::BASIC);
echo $model->getDebugOutput();
```

### **Performance Regression with Debug Enabled**
```php
// Check debug level - reduce if needed
$model->setDebug(DebugLevel::BASIC, DebugCategory::PERFORMANCE, 'text');

// For production monitoring, use minimal categories
$model->setDebug(DebugLevel::BASIC, DebugCategory::PERFORMANCE, 'json');

// Disable debug completely if no longer needed
$model->setDebugPreset('off');
```

### **Missing Database-Specific Analysis**
```php
// Verify database connection and type
$dbType = $model->getDbType();
echo "Database type: $dbType\n";

// Check if EXPLAIN is available
try {
    $pdo = $model->getPDO();
    if ($dbType === 'mysql') {
        $stmt = $pdo->query("EXPLAIN SELECT 1");
        echo "MySQL EXPLAIN available\n";
    } elseif ($dbType === 'sqlite') {
        $stmt = $pdo->query("EXPLAIN QUERY PLAN SELECT 1");
        echo "SQLite EXPLAIN available\n";
    } elseif ($dbType === 'postgresql') {
        $stmt = $pdo->query("EXPLAIN SELECT 1");
        echo "PostgreSQL EXPLAIN available\n";
    }
} catch (Exception $e) {
    echo "EXPLAIN not available: " . $e->getMessage() . "\n";
}
```

---

## 📊 **Debug System Best Practices**

### **Development Best Practices**
1. **Use Developer Preset**: `setDebugPreset('developer')` for rich HTML output
2. **Regular Analysis**: Review debug output regularly during development
3. **Implement Suggestions**: Act on CREATE INDEX recommendations promptly
4. **Session Analysis**: Pay attention to session-level patterns and insights
5. **Custom Logging**: Add custom debug messages for complex business logic

### **Production Best Practices**
1. **Minimal Categories**: Use only `DebugCategory::PERFORMANCE` in production
2. **JSON Format**: Use JSON output for log aggregation systems
3. **Conditional Activation**: Enable debugging only when needed
4. **Performance Monitoring**: Set up alerts for slow operations
5. **Regular Cleanup**: Rotate debug logs to prevent disk space issues

### **Performance Monitoring Best Practices**
1. **Baseline Establishment**: Measure performance before and after optimizations
2. **Trend Analysis**: Track query performance over time
3. **Alert Thresholds**: Set up alerts for queries exceeding time thresholds
4. **Index Effectiveness**: Monitor whether implemented indexes are being used
5. **Resource Usage**: Track memory and CPU usage during database operations

---

## 🎉 **Summary**

TronBridge's debug system provides:

- **✅ Zero performance regression** when debugging is disabled
- **✅ Database-specific optimization insights** with actual SQL commands  
- **✅ Multiple output formats** for different environments and use cases
- **✅ Session-level intelligence** with pattern recognition and grouped recommendations
- **✅ Production-safe monitoring** with structured data export
- **✅ Developer-friendly experience** with rich debugging and immediate feedback

**Transform your database optimization workflow with actionable, context-aware debugging that helps you build faster, more efficient applications.**