# Performance Optimization
> When and how to optimize with TronBridge

TronBridge provides comprehensive performance optimization features designed to handle everything from simple web requests to massive bulk data processing. This guide covers when and how to use these optimizations effectively.

## 🎯 **Performance Overview**

TronBridge operates in multiple performance modes, each optimized for different scenarios:

| Scenario | Mode | Performance vs Original Trongate | Best For |
|----------|------|----------------------------------|-----------|
| **Simple Web Requests** | Default | ~20% slower | Standard CRUD operations |
| **Bulk Data Processing** | Performance Mode | 60-97% faster | ETL, imports, CLI scripts |
| **Cross-Database Operations** | Adaptive Mode | N/A (only option) | Multi-database workflows |
| **Development/Debugging** | Debug Mode | Variable | Optimization analysis |

---

## ⚡ **Quick Start - Performance Modes**

### **Default Mode (Web-Optimized)**
```php
// Lightweight mode - optimized for per-request performance
$model = new Model('users');

// Standard operations work normally
$users = $model->get_where_custom('status', 'active');
```

### **Performance Mode (Bulk-Optimized)**
```php
// Enable performance optimizations for bulk operations
$model = new Model('users');
$model->enablePerformanceMode();

// Now optimized for bulk processing
$inserted = $model->insert_batch('users', $largeUserArray);
```

### **Adaptive Mode (Intelligent Optimization)**
```php
// Enable adaptive learning and auto-optimization
$model = new Model('orders');
$perf = $model->performance();
$perf->setAdaptiveMode(true);
$perf->setBulkThresholds(100, 20); // Auto-enable bulk mode at 100+ records

// TronBridge learns and optimizes automatically
foreach ($orderBatches as $batch) {
    $model->insert_batch('orders', $batch); // Auto-optimizes based on size
}
```

---

## 🚀 **When to Optimize**

### **Enable Performance Mode When:**

✅ **Processing 50+ records** in a single operation  
✅ **Bulk imports/exports** from files or APIs  
✅ **CLI scripts** or background jobs  
✅ **ETL processes** and data migrations  
✅ **Reporting queries** on large datasets  
✅ **Database maintenance** operations  

### **Stick with Default Mode When:**

⚠️ **Simple web requests** with 1-10 records  
⚠️ **High-frequency operations** where startup overhead matters  
⚠️ **Real-time user interactions** requiring immediate response  
⚠️ **Memory-constrained environments** with limited resources  

---

## 📊 **Bulk Operations & Intelligent Chunking**

### **Automatic Batch Operations**

TronBridge automatically optimizes bulk operations with intelligent chunking:

```php
// Auto-calculated chunk sizes based on database limits and record complexity
$model = new Model('products');
$model->enablePerformanceMode();

// SQLite: Respects 999-variable limit automatically
// MySQL: Optimizes for max_allowed_packet
// PostgreSQL: Uses optimal batch sizes for performance
$inserted = $model->insert_batch('products', $hugeProductArray);
```

### **Manual Chunk Size Control**
```php
// Override automatic chunk calculation
$inserted = $model->insert_batch('products', $products, 500); // Force 500 per chunk

// Get optimal chunk size for your data
$optimalSize = $model->performance()->calculateOptimalChunkSize($products[0]);
echo "Recommended chunk size: $optimalSize";
```

### **Batch Update Operations**
```php
// Intelligent strategy selection: CASE statements vs temp tables
$updates = [
    ['id' => 1, 'status' => 'processed', 'priority' => 'high'],
    ['id' => 2, 'status' => 'pending', 'priority' => 'medium'],
    // ... thousands more
];

$batchUpdate = $model->batchUpdate();
$updated = $batchUpdate->executeBatchUpdate('orders', 'id', $updates);

// TronBridge automatically chooses:
// - CASE statements for moderate datasets (< 1000 records)
// - Temporary tables for large datasets (1000+ records)
```

### **Memory-Aware Processing**
```php
// Large datasets with memory monitoring
$model->enablePerformanceMode();

$stats = $model->getPerformanceStats();
echo "Memory usage: " . $stats['memory']['current'];
echo "Peak memory: " . $stats['memory']['peak'];
```

---

## 🧠 **Schema-Aware Intelligence**

TronBridge analyzes your database schema to provide intelligent optimizations:

### **Automatic Index Analysis**
```php
// Enable schema intelligence for automatic recommendations
$model = new Model('users');
$model->setDebugPreset('developer');

$users = $model->get_where_custom('email', 'john@example.com');

// Automatic output includes:
// ⚠️  No index found for column 'email' 
// 🎯 Recommendation: CREATE INDEX idx_users_email ON users (email);
```

### **Performance Predictions**
```php
// Get performance predictions before executing operations
$optimizer = SchemaAwareOptimizer::class;
$prediction = $optimizer::predictPerformance('users', 'select', 10000, [
    'where_column' => 'email'
]);

echo "Estimated time: " . $prediction['estimated_time'] . "ms";
echo "Complexity score: " . $prediction['complexity_score'];
```

### **Intelligent Cache Warming**
```php
// Warm caches based on schema relationships
$warmed = SchemaAwareOptimizer::warmCachesIntelligently($model);

echo "Validation cache: " . $warmed['validation_cache'] . " entries";
echo "Query cache: " . $warmed['query_cache'] . " patterns";
echo "Relationship cache: " . $warmed['relationship_cache'] . " mappings";
```

---

## 📈 **Performance Monitoring & Analysis**

### **Comprehensive Performance Statistics**
```php
$model = new Model('orders');
$model->enablePerformanceMode();

// Process some operations
$model->insert_batch('orders', $orderData);

// Get detailed performance stats
$stats = $model->getPerformanceStats();

echo "Operations per second: " . $stats['performance']['operations_per_second'];
echo "Average execution time: " . $stats['performance']['average_execution_time'];
echo "Memory efficiency: " . $stats['memory']['efficiency_score'];
echo "Cache hit rate: " . $stats['cache']['hit_rate'];
```

### **Real-Time Performance Debugging**
```php
// Enable performance-focused debugging
$model->setDebugPreset('performance');

// Operations automatically include performance analysis
$model->insert_batch('analytics_events', $eventData);

// Rich performance insights:
// 📊 Bulk insert: 5,000 records in 1.2s (4,167 records/sec)
// 🎯 Performance tier: GOOD
// 💡 Optimization: Bulk mode reduced execution time by 73%
// ⚠️  Memory usage: 45MB (monitor for larger datasets)
```

### **CLI Performance Monitoring**
```php
// CLI-friendly performance monitoring with ANSI colors
$model->setDebugPreset('cli');

$model->insert_batch('logs', $logData);

// Real-time colored output:
// [14:23:15] 🚀 Bulk insert started: 2,500 records
// [14:23:16] ⚡ Performance mode active: chunk_size=500
// [14:23:17] ✅ Completed: 2,500 records in 1.8s (1,389 rec/sec)
// [14:23:17] 📊 Performance tier: GOOD
```

---

## 🔧 **Database-Specific Optimizations**

### **MySQL Optimizations**
```php
// MySQL-specific bulk optimizations
$model->enablePerformanceMode();

// Automatically enables:
// - LOAD DATA LOCAL INFILE for large imports
// - Disabled foreign key checks during bulk operations
// - Optimized INSERT statements with extended syntax
// - Intelligent use of REPLACE vs INSERT ON DUPLICATE KEY UPDATE
```

### **SQLite Optimizations**
```php
// SQLite-specific optimizations
$model->enablePerformanceMode();

// Automatically applies:
// - Pragma optimizations (journal_mode=WAL, synchronous=NORMAL)
// - Respects 999-variable limit with intelligent chunking
// - VACUUM operations for space reclamation
// - Optimal transaction batching
```

### **PostgreSQL Optimizations**
```php
// PostgreSQL-specific optimizations
$model->enablePerformanceMode();

// Automatically configures:
// - COPY FROM for bulk imports
// - Disabled triggers during bulk operations (session_replication_role=replica)
// - Optimal use of unnest() for array operations
// - ANALYZE commands after bulk operations
```

---

## 🎛️ **Advanced Configuration**

### **Fine-Grained Performance Control**
```php
$model = new Model('analytics');
$perf = $model->performance();

// Configure bulk thresholds
$perf->setBulkThresholds(
    100,  // Auto-enable bulk mode at 100+ records
    20    // Auto-enable after 20+ operations
);

// Enable adaptive learning
$perf->setAdaptiveMode(true);

// Manual optimizations
$perf->enablePerformanceMode();
```

### **Memory Management**
```php
// Monitor and control memory usage
$stats = $model->getPerformanceStats();

if ($stats['memory']['usage_mb'] > 100) {
    // Reduce chunk size for memory-constrained operations
    $smallerChunkSize = 100;
    $model->insert_batch('large_table', $data, $smallerChunkSize);
}
```

### **Custom Performance Profiles**
```php
// Create performance profiles for different scenarios
class PerformanceProfiles 
{
    public static function webOptimized(Model $model): void 
    {
        // Optimized for web requests
        $model->disablePerformanceMode();
        $model->performance()->setBulkThresholds(1000, 100);
    }
    
    public static function batchProcessing(Model $model): void 
    {
        // Optimized for bulk operations
        $model->enablePerformanceMode();
        $model->performance()->setBulkThresholds(10, 5);
        $model->performance()->setAdaptiveMode(true);
    }
    
    public static function etlPipeline(Model $model): void 
    {
        // Optimized for ETL processes
        $model->enablePerformanceMode();
        $model->performance()->setBulkThresholds(1, 1);
        // All operations use bulk optimizations
    }
}

// Usage
PerformanceProfiles::batchProcessing($model);
```

---

## 🚨 **Performance Troubleshooting**

### **Common Performance Issues**

#### **1. Slow Bulk Operations**
```php
// Problem: Bulk operations slower than expected
$model->setDebugPreset('performance');
$model->insert_batch('users', $userData);

// Debug output will show:
// ⚠️  Performance tier: POOR (500 records/sec)
// 💡 Suggestion: Enable performance mode
// 🎯 Missing index: CREATE INDEX idx_users_email ON users (email);
```

#### **2. Memory Issues**
```php
// Problem: Out of memory errors
$stats = $model->getPerformanceStats();
echo "Memory usage: " . $stats['memory']['usage_mb'] . "MB";

// Solution: Reduce chunk size
$model->insert_batch('large_table', $data, 100); // Smaller chunks
```

#### **3. Database Lock Issues**
```php
// Problem: Database locks during bulk operations
$model->enablePerformanceMode(); // Enables optimized transaction handling

// For very large operations, use chunked processing
$totalRecords = count($hugeDataset);
$chunkSize = 1000;

for ($i = 0; $i < $totalRecords; $i += $chunkSize) {
    $chunk = array_slice($hugeDataset, $i, $chunkSize);
    $model->insert_batch('table', $chunk);
    
    // Optional: brief pause to release locks
    usleep(10000); // 10ms pause
}
```

### **Performance Diagnostics**
```php
// Comprehensive performance diagnostics
$model->setDebugPreset('verbose');

$diagnostics = [
    'performance_stats' => $model->getPerformanceStats(),
    'schema_analysis' => SchemaAwareOptimizer::generateOptimizationReport(),
    'debug_output' => $model->getDebugOutput()
];

// Review diagnostics for optimization opportunities
foreach ($diagnostics as $category => $data) {
    echo "\n=== $category ===\n";
    if (is_array($data)) {
        print_r($data);
    } else {
        echo $data;
    }
}
```

---

## 📋 **Performance Best Practices**

### **✅ Do's**

1. **Enable Performance Mode** for bulk operations (50+ records)
2. **Use batch methods** (`insert_batch`, `update_batch`, `delete_batch`)
3. **Monitor performance** with debug presets in development
4. **Implement index recommendations** from schema analysis
5. **Use adaptive mode** for variable workloads
6. **Profile before optimizing** to identify actual bottlenecks
7. **Test optimizations** with realistic datasets

### **❌ Don'ts**

1. **Don't enable performance mode** for simple web requests
2. **Don't ignore memory usage** in bulk operations
3. **Don't use bulk operations** for single records
4. **Don't skip index analysis** for frequently queried columns
5. **Don't optimize prematurely** - measure first
6. **Don't enable verbose debugging** in production
7. **Don't ignore database-specific limits** (SQLite 999-variable limit)

### **🔧 Optimization Workflow**

1. **Baseline Measurement**
   ```php
   $model->setDebugPreset('performance');
   // Run operations and measure current performance
   ```

2. **Schema Analysis**
   ```php
   echo SchemaAwareOptimizer::generateOptimizationReport();
   // Review index recommendations
   ```

3. **Enable Optimizations**
   ```php
   $model->enablePerformanceMode();
   $model->performance()->setAdaptiveMode(true);
   ```

4. **Measure Improvements**
   ```php
   $stats = $model->getPerformanceStats();
   // Compare with baseline metrics
   ```

5. **Iterate and Refine**
   ```php
   // Adjust thresholds and configurations based on results
   $model->performance()->setBulkThresholds(50, 10);
   ```

---

## 📊 **Performance Benchmarks**

### **Potential Performance Improvements**

| Operation | Records | Default Mode | Performance Mode | Improvement |
|-----------|---------|--------------|------------------|-------------|
| Insert Batch | 1,000 | 2.5s | 0.8s | 68% faster |
| Insert Batch | 10,000 | 25s | 3.2s | 87% faster |
| Update Batch | 5,000 | 8.3s | 2.1s | 75% faster |
| Bulk Delete | 2,000 | 4.1s | 1.2s | 71% faster |

### **Memory Usage**

| Dataset Size | Default Mode | Performance Mode | Memory Savings |
|--------------|--------------|------------------|----------------|
| 1,000 records | 12MB | 8MB | 33% less |
| 10,000 records | 95MB | 45MB | 53% less |
| 50,000 records | 420MB | 180MB | 57% less |

*Results vary based on database type, record complexity, and system resources.*

---

## 🎉 **Summary**

TronBridge's performance optimization provides:

- **✅ 60-97% faster bulk operations** with intelligent optimization
- **✅ Automatic chunking and memory management** for large datasets
- **✅ Schema-aware intelligence** with index recommendations
- **✅ Database-specific optimizations** for MySQL, SQLite, PostgreSQL
- **✅ Comprehensive performance monitoring** and analysis tools
- **✅ Adaptive learning** that improves performance over time

**Choose the right performance mode for your use case, monitor with debug tools, and implement schema recommendations for optimal database performance.**