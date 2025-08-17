# TronBridge Quick Start Guide
> Get multi-database Trongate applications running in 15 minutes

This guide assumes you've already [installed TronBridge](INSTALLATION.md). We'll walk through the core features that make TronBridge valuable: **cross-database compatibility, migration capabilities, and advanced debugging**.

## 🎯 **What You'll Learn**

By the end of this guide, you'll have:
- ✅ Verified TronBridge works with your existing code
- ✅ Connected to multiple database types (SQLite, MySQL, PostgreSQL)
- ✅ Performed a cross-database migration
- ✅ Enabled debug tools for optimization insights
- ✅ Created your first backup with TronBridge

**Time Required**: 15-20 minutes

---

## 🚀 **Step 1: Verify Existing Code Works** (2 minutes)

TronBridge is 100% API compatible. Your existing Trongate code should work unchanged.

### **Test Your Existing Models**
```php
<?php
// Use your existing model code - it should work identically
$model = new Model('users'); // or whatever table you normally use

// All your normal operations work unchanged
$users = $model->get();
$count = $model->count();

echo "✅ Found {$count} records in your existing database\n";
echo "✅ TronBridge is working with your existing code!\n";
```

**Expected Result**: Your existing queries work exactly as before.

---

## 🌉 **Step 2: Try Multi-Database Support** (5 minutes)

Now let's explore TronBridge's main feature: connecting to different database types.

### **Test SQLite Connection**
```php
<?php
// Connect to SQLite (great for development and testing)
$sqliteModel = new Model('users', 'sqlite:./test_database.sqlite');

// Create a test table and insert some data
try {
    $sqliteModel->query("CREATE TABLE IF NOT EXISTS test_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Insert test data
    $testUsers = [
        ['name' => 'Alice SQLite', 'email' => 'alice@sqlite.test'],
        ['name' => 'Bob SQLite', 'email' => 'bob@sqlite.test'],
        ['name' => 'Carol SQLite', 'email' => 'carol@sqlite.test']
    ];
    
    $inserted = $sqliteModel->insert_batch('test_users', $testUsers);
    echo "✅ SQLite: Created and inserted {$inserted} test records\n";
    
    // Retrieve data
    $sqliteUsers = $sqliteModel->get('name', 'test_users');
    echo "✅ SQLite: Retrieved " . count($sqliteUsers) . " records\n";
    
} catch (Exception $e) {
    echo "❌ SQLite test failed: " . $e->getMessage() . "\n";
}
```

### **Test PostgreSQL Connection** (if available)
```php
<?php
// Connect to PostgreSQL (excellent for production and analytics)
// Adjust connection details for your PostgreSQL setup
$postgresModel = new Model('users', 'postgresql:host=localhost;dbname=test;user=postgres;pass=yourpassword');

try {
    // Test connection
    $postgresModel->query("SELECT version()");
    echo "✅ PostgreSQL: Connection successful\n";
    
    // Create test table with PostgreSQL-specific features
    $postgresModel->query("CREATE TABLE IF NOT EXISTS test_users (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Insert test data
    $testUsers = [
        ['name' => 'Alice PostgreSQL', 'email' => 'alice@postgres.test'],
        ['name' => 'Bob PostgreSQL', 'email' => 'bob@postgres.test']
    ];
    
    $inserted = $postgresModel->insert_batch('test_users', $testUsers);
    echo "✅ PostgreSQL: Inserted {$inserted} test records\n";
    
} catch (Exception $e) {
    echo "ℹ️ PostgreSQL not available (skipping): " . $e->getMessage() . "\n";
}
```

### **Compare Database Capabilities**
```php
<?php
// Check what databases are available in your environment
$databases = [
    'SQLite' => 'sqlite::memory:',
    'MySQL' => null, // Uses your default config
];

// Add PostgreSQL if available
if (extension_loaded('pdo_pgsql')) {
    $databases['PostgreSQL'] = 'postgresql:host=localhost;dbname=test;user=postgres;pass=yourpassword';
}

foreach ($databases as $name => $connection) {
    try {
        $model = new Model('test', $connection);
        $capabilities = $model->backup()->getCapabilities();
        
        echo "✅ {$name}: ";
        echo "Type: {$capabilities['database_type']}, ";
        echo "Strategies: " . count($capabilities['strategies']) . "\n";
        
    } catch (Exception $e) {
        echo "❌ {$name}: Not available\n";
    }
}
```

**Expected Result**: You can connect to different database types with the same TronBridge Model API.

---

## 🔄 **Step 3: Cross-Database Migration** (5 minutes)

Let's migrate data between different database types using TronBridge's dedicated migration system.

### **SQLite to MySQL Migration Example**
```php
<?php
// Source: SQLite database with some data
$sourceModel = new Model('products', 'sqlite:./source_data.sqlite');

// Create sample data in SQLite
$sourceModel->query("CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    price DECIMAL(10,2),
    category TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$sampleProducts = [
    ['name' => 'Laptop', 'price' => 999.99, 'category' => 'Electronics'],
    ['name' => 'Coffee Mug', 'price' => 12.50, 'category' => 'Kitchen'],
    ['name' => 'Notebook', 'price' => 4.99, 'category' => 'Office'],
    ['name' => 'Wireless Mouse', 'price' => 29.99, 'category' => 'Electronics']
];

$sourceModel->insert_batch('products', $sampleProducts);
echo "✅ Created sample data in SQLite\n";

// Target: Your MySQL database
$targetModel = new Model('products'); // Uses your default MySQL config

try {
    // Use TronBridge's migration system for database-to-database migration
    $migrationResult = $sourceModel->migration()->quickMigrate($sourceModel, $targetModel, [
        'include_data' => true,
        'validate_before_migration' => true,
        'validate_after_migration' => true,
        'chunk_size' => 1000
    ]);
    
    if ($migrationResult['success']) {
        echo "✅ Successfully migrated from SQLite to MySQL!\n";
        echo "📊 Tables migrated: " . count($migrationResult['migrated_tables']) . "\n";
        echo "📊 Records migrated: " . ($migrationResult['total_records'] ?? 'Unknown') . "\n";
        echo "⏱️ Duration: " . round($migrationResult['duration_seconds'], 2) . " seconds\n";
        
        // Verify migration
        $migratedCount = $targetModel->count('products');
        echo "✅ Verified: {$migratedCount} products in target MySQL database\n";
    } else {
        echo "❌ Migration failed: " . $migrationResult['error'] . "\n";
        if (isset($migrationResult['warnings']) && !empty($migrationResult['warnings'])) {
            echo "⚠️ Warnings: " . implode(', ', $migrationResult['warnings']) . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Migration error: " . $e->getMessage() . "\n";
}

// Alternative: Schema-only migration (if you only need structure)
try {
    echo "\n🏗️ Testing schema-only migration:\n";
    $schemaResult = $sourceModel->migration()->migrateSchemaOnly($sourceModel, $targetModel, [
        'validate_before_migration' => true
    ]);
    
    if ($schemaResult['success']) {
        echo "✅ Schema migrated successfully\n";
        echo "📊 Tables created: " . count($schemaResult['migrated_tables']) . "\n";
    }
} catch (Exception $e) {
    echo "ℹ️ Schema migration test: " . $e->getMessage() . "\n";
}
```

**Expected Result**: Data migrates seamlessly between database types with automatic schema translation.

---

## 🐛 **Step 4: Enable Debug Tools** (3 minutes)

TronBridge includes powerful debugging tools to optimize your database operations.

### **Basic Debug Output**
```php
<?php
// Enable developer-friendly debugging
$model = new Model('users');
$model->setDebugPreset('developer');

// Run some queries to see debug output
$users = $model->get('name', 'users', 5);
$count = $model->count('users');

// Get rich debug information
$debugOutput = $model->getDebugOutput();
echo $debugOutput; // Rich HTML debug panel (save to file and open in browser)

// Or for CLI debugging
$model->setDebugPreset('cli');
$users = $model->get_where_custom('id', 1, '>', 'name', 'users', 3);

echo "\n📊 Performance Statistics:\n";
$stats = $model->getPerformanceStats();
echo "Cache stats: " . json_encode($stats['cache_stats'], JSON_PRETTY_PRINT) . "\n";
```

### **Database-Specific Analysis**
```php
<?php
// Test different databases with debug enabled
$databases = [
    'SQLite' => 'sqlite:./test.sqlite',
    'MySQL' => null // Your default config
];

foreach ($databases as $dbType => $connection) {
    try {
        $model = new Model('test_users', $connection);
        $model->setDebugPreset('cli');
        
        echo "\n🔍 {$dbType} Analysis:\n";
        
        // Run a test query
        $results = $model->get('name', 'test_users', 3);
        
        // Get database-specific insights
        $debugData = $model->getDebugData();
        if (!empty($debugData['messages'])) {
            foreach ($debugData['messages'] as $message) {
                if (isset($message['message']) && strpos($message['message'], 'Query') !== false) {
                    echo "  📝 " . $message['message'] . "\n";
                }
            }
        }
        
    } catch (Exception $e) {
        echo "  ❌ {$dbType}: " . $e->getMessage() . "\n";
    }
}
```

**Expected Result**: Detailed query analysis with database-specific optimization suggestions.

---

## 💾 **Step 5: Create Your First Backup** (3 minutes)

TronBridge provides intelligent backup capabilities across all database types.

### **Simple Backup**
```php
<?php
$model = new Model('users'); // Your main database

// Create a simple backup
$backupResult = $model->backup()->createBackup('./my_first_backup.sql', [
    'include_schema' => true,
    'include_data' => true,
    'validate_backup' => true
]);

if ($backupResult['success']) {
    echo "✅ Backup created successfully!\n";
    echo "📁 File: " . $backupResult['output_path'] . "\n";
    echo "📊 Size: " . number_format($backupResult['backup_size_bytes']) . " bytes\n";
    echo "⏱️ Duration: " . round($backupResult['duration_seconds'], 2) . " seconds\n";
    echo "🔧 Strategy: " . $backupResult['strategy_used'] . "\n";
} else {
    echo "❌ Backup failed: " . $backupResult['error'] . "\n";
}
```

### **Advanced Backup with Options**
```php
<?php
// Create backup with progress tracking and compression
$model = new Model('users');

$backupResult = $model->backup()->createBackup('./advanced_backup.sql', [
    'include_schema' => true,
    'include_data' => true,
    'validate_backup' => true,
    'timeout' => 1800, // 30 minutes
    'progress_callback' => function($progress) {
        echo "Progress: " . round($progress['progress_percent'], 1) . "%\r";
    }
]);

if ($backupResult['success']) {
    echo "\n✅ Advanced backup completed!\n";
    echo "📊 Records exported: " . ($backupResult['records_exported'] ?? 'N/A') . "\n";
    echo "📊 Tables exported: " . ($backupResult['tables_exported'] ?? 'N/A') . "\n";
}
```

### **Test Backup Capabilities**
```php
<?php
// Check what backup strategies are available
$model = new Model('users');
$capabilities = $model->backup()->getCapabilities();

echo "📋 Backup Capabilities:\n";
echo "Database type: " . $capabilities['database_type'] . "\n";
echo "Available strategies: " . count($capabilities['strategies']) . "\n";

foreach ($capabilities['strategies'] as $strategy) {
    echo "  - " . basename($strategy) . "\n";
}

// Test all strategies
$testResults = $model->backup()->testCapabilities();
echo "\n🧪 Strategy Tests:\n";
foreach ($testResults as $strategy => $result) {
    $status = $result['success'] ? '✅' : '❌';
    $name = basename($strategy, '.php');
    echo "  {$status} {$name}\n";
    if (!$result['success'] && isset($result['error'])) {
        echo "    Error: " . $result['error'] . "\n";
    }
}
```

### **SQL Dump Translation** (Bonus Feature)
```php
<?php
// Translate existing SQL dumps between database types
$model = new Model('database');

try {
    // Translate a MySQL dump to PostgreSQL format
    $translationResult = $model->sqlDumpTranslator()->translateFile(
        './mysql_dump.sql',     // Input file
        'mysql',                // Source database type
        'postgresql',           // Target database type
        './postgresql_dump.sql' // Output file
    );
    
    if ($translationResult['success']) {
        echo "✅ SQL dump translated successfully!\n";
        echo "📁 Output: " . $translationResult['output_file'] . "\n";
        echo "📊 Statements translated: " . $translationResult['statements_processed'] . "\n";
        echo "📊 Tables found: " . count($translationResult['tables_processed']) . "\n";
    } else {
        echo "❌ Translation failed: " . $translationResult['error'] . "\n";
    }
    
} catch (Exception $e) {
    echo "ℹ️ SQL dump translation: " . $e->getMessage() . "\n";
}
```

**Expected Result**: Successful backup creation with detailed capability information, plus bonus SQL translation functionality.

---

## 🎯 **Step 6: Real-World Example** (3 minutes)

Let's put it all together with a practical scenario: **development workflow with multiple databases**.

### **Multi-Environment Setup**
```php
<?php
// Simulate a real development workflow

// 1. Development: Use SQLite for rapid prototyping
echo "🔧 Development Environment (SQLite):\n";
$devModel = new Model('blog_posts', 'sqlite:./dev_blog.sqlite');
$devModel->setDebugPreset('cli');

// Create development schema
$devModel->query("CREATE TABLE IF NOT EXISTS blog_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    content TEXT,
    author_id INTEGER,
    status TEXT DEFAULT 'draft',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Add development data
$devPosts = [
    ['title' => 'My First Post', 'content' => 'Hello world from SQLite!', 'author_id' => 1, 'status' => 'published'],
    ['title' => 'Draft Post', 'content' => 'Still working on this...', 'author_id' => 1, 'status' => 'draft'],
    ['title' => 'SQLite is Great', 'content' => 'Perfect for development!', 'author_id' => 2, 'status' => 'published']
];

$devModel->insert_batch('blog_posts', $devPosts);
$devCount = $devModel->count('blog_posts');
echo "  ✅ Created {$devCount} blog posts in development\n";

// 2. Ready for production: Migrate to MySQL
echo "\n🚀 Production Deployment (MySQL):\n";
$prodModel = new Model('blog_posts'); // Uses your MySQL config

// Use TronBridge migration system for deployment
$deployResult = $devModel->migration()->quickMigrate($devModel, $prodModel, [
    'include_data' => true,
    'validate_before_migration' => true,
    'validate_after_migration' => true,
    'chunk_size' => 1000
]);

if ($deployResult['success']) {
    $prodCount = $prodModel->count('blog_posts');
    echo "  ✅ Deployed {$prodCount} blog posts to production MySQL\n";
    echo "  📊 Tables migrated: " . count($deployResult['migrated_tables']) . "\n";
    echo "  ⏱️ Migration time: " . round($deployResult['duration_seconds'], 2) . " seconds\n";
} else {
    echo "  ❌ Deployment failed: " . $deployResult['error'] . "\n";
    
    // Show any warnings if available
    if (isset($deployResult['warnings']) && !empty($deployResult['warnings'])) {
        echo "  ⚠️ Warnings:\n";
        foreach ($deployResult['warnings'] as $warning) {
            echo "    - " . $warning . "\n";
        }
    }
}

// 3. Backup production for safety
echo "\n💾 Production Backup:\n";
$backupResult = $prodModel->backup()->createBackup('./production_backup.sql', [
    'include_schema' => true,
    'include_data' => true,
    'validate_backup' => true
]);

if ($backupResult['success']) {
    echo "  ✅ Production backup created\n";
    echo "  📁 File: " . $backupResult['output_path'] . "\n";
    echo "  📊 Size: " . number_format($backupResult['backup_size_bytes']) . " bytes\n";
}

// 4. Performance comparison
echo "\n📊 Performance Comparison:\n";
$stats = $prodModel->getPerformanceStats();
echo "  Cache efficiency: " . ($stats['cache_stats']['cached_sql'] ?? 0) . " cached queries\n";
echo "  Session operations: " . ($stats['session_operation_count'] ?? 0) . "\n";

echo "\n🎉 Multi-environment workflow complete!\n";
echo "   Development: SQLite → Production: MySQL\n";
echo "   ✅ Migration with automatic schema translation\n";
echo "   ✅ Validation and safety checks\n";
echo "   ✅ Production backup created\n";
echo "   Same code, different databases, seamless migration!\n";

// 5. Bonus: Validate migration was successful
echo "\n🔍 Validation Check:\n";
try {
    $validationResult = $devModel->migration()->validateCompatibility($devModel, $prodModel);
    
    if ($validationResult['compatible']) {
        echo "  ✅ Development and production databases are compatible\n";
        echo "  📊 Tables checked: " . count($validationResult['table_compatibility']) . "\n";
    } else {
        echo "  ⚠️ Compatibility issues found:\n";
        foreach ($validationResult['issues'] as $issue) {
            echo "    - " . $issue . "\n";
        }
    }
} catch (Exception $e) {
    echo "  ℹ️ Validation check: " . $e->getMessage() . "\n";
}
```

**Expected Result**: A complete development-to-production workflow using different database types with proper migration, backup, and validation.

---

## 🎉 **Quick Start Complete!**

Congratulations! You've successfully:

- ✅ **Verified compatibility** - Your existing Trongate code works unchanged
- ✅ **Connected to multiple databases** - SQLite, MySQL, and potentially PostgreSQL
- ✅ **Performed cross-database migration** - SQLite → MySQL with automatic schema translation
- ✅ **Enabled debug tools** - Rich insights into query performance and optimization
- ✅ **Created backups** - Intelligent backup strategies with cross-database support
- ✅ **Built a real workflow** - Development to production with different database types

## 🚀 **What's Next?**

Now that you've seen TronBridge in action, explore these advanced features:

### **📚 Dive Deeper**
- **[Multi-Database Guide](DATABASE-SUPPORT.md)** - Complete database compatibility reference
- **[Backup System](BACKUP-SYSTEM.md)** - Advanced backup, restore, and migration
- **[Debug Tools](DEBUG-SYSTEM.md)** - Performance optimization and query analysis
- **[Expression Methods](EXPRESSION-METHODS.md)** - Atomic operations and cross-database functions

### **🔧 Advanced Features**
- **[CLI Tools](CLI-TOOLS.md)** - Command-line database operations
- **[Migration System](MIGRATION-GUIDE.md)** - Complete database migration workflows
- **[Performance Optimization](PERFORMANCE.md)** - When and how to optimize with TronBridge

### **🛠️ Practical Applications**
- **Multi-environment workflows** - Development (SQLite) → Staging (PostgreSQL) → Production (MySQL)
- **Database migrations** - Legacy system modernization
- **Performance optimization** - Bulk operations and data processing
- **Cross-database analytics** - Aggregate data from multiple database types

### **💡 Get Involved**
- **[Contributing Guide](CONTRIBUTING.md)** - Help improve TronBridge
- **[GitHub Discussions](https://github.com/tronbridge/tronbridge/discussions)** - Ask questions and share experiences
- **[GitHub Issues](https://github.com/tronbridge/tronbridge/issues)** - Report bugs or request features

---

## 📝 **Quick Reference**

### **Essential TronBridge Methods**
```php
// Multi-database connections
$model = new Model('table', 'sqlite:./database.sqlite');
$model = new Model('table', 'postgresql:host=localhost;dbname=app');
$model = new Model('table'); // MySQL (default)

// Backup and migration
$model->backup()->createBackup('/path/to/backup.sql');
$model->backup()->restoreBackup('/path/to/backup.sql');

// Debug tools
$model->setDebugPreset('developer'); // Rich HTML debug
$model->setDebugPreset('cli');       // CLI-friendly output

// Performance stats
$stats = $model->getPerformanceStats();
```

### **Configuration Quick Setup**
```php
// In config/database.php, add:
define('DB_TYPE', 'sqlite');  // or 'mysql', 'postgresql'
define('DB_FILE', APPPATH . 'storage/app.sqlite'); // for SQLite
```

**🌉 Welcome to the world of cross-database Trongate applications with TronBridge!**