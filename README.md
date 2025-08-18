# TronBridge
> Cross-database Trongate applications, simplified

TronBridge extends [Trongate](https://github.com/trongate/trongate-framework) with comprehensive multi-database support, enabling you to use **SQLite**, **PostgreSQL**, or **MySQL/MariaDB** with the same familiar Trongate Model API. Break free from MySQL-only constraints and build truly flexible database applications.

## 🌉 **What TronBridge Bridges**

- **🗄️ Database Types**: MySQL/MariaDB ↔ SQLite ↔ PostgreSQL  
- **🔄 Development Workflows**: Local SQLite → Staging PostgreSQL → Production MySQL
- **📦 Migration Paths**: Seamless cross-database migration with automatic schema translation
- **🔌 API Compatibility**: 100% compatible with original Trongate Model - **zero code changes required**

```php
// Original Trongate - MySQL only
$model = new Model('users'); 

// TronBridge - Choose your database
$sqliteModel = new Model('users', 'sqlite:/path/to/app.sqlite');        
$postgresModel = new Model('users', 'postgresql:host=localhost;dbname=app');   
$mysqlModel = new Model('users'); // Still works perfectly
```

## ✨ **Key Features**

### 🎯 **Multi-Database Freedom**
- **SQLite**: Perfect for development, testing, and embedded applications
- **PostgreSQL**: Advanced features, analytics, and enterprise deployments  
- **MySQL/MariaDB**: Production-ready with full original Trongate compatibility
- **Same API**: All your existing Trongate code works unchanged


### 🛠️ **Advanced Developer Tools**
- **Factory-Based Debug System**: Zero overhead when disabled, comprehensive insights when enabled
- **Database Backup & Restore**: Unified API across all database types with intelligent strategy selection
- **Expression Methods**: Atomic operations with automatic cross-database function translation
- **Schema Translation**: Convert database schemas between MySQL, SQLite, and PostgreSQL

### ⚡ **Performance Optimizations**
- **Bulk Operation Intelligence**: Automatic optimization for large datasets (60-97% faster)
- **Smart Caching**: Prepared statement caching and intelligent query optimization

## 🚀 **Quick Start**

### 1. **Drop-in Replacement** (Zero Code Changes)
```bash
# Replace your existing Model.php with TronBridge
# All existing code continues to work unchanged
```

```php
// Your existing Trongate code works identically
$this->model->get();
$id = $model->insert($userData);
// Now with multi-database support automatically available!
```

### 2. **Enable Multi-Database Support**

**Option A: Global Configuration** (Recommended for single database type per environment)
```php
// Configure in /config/database.php, then use normally
$model = new Model('users');  // Uses DB_TYPE from config
```

**Option B: Connection Strings** (Recommended for multiple database types)
```php
// SQLite for rapid development
$devModel = new Model('users', 'sqlite:./database/storage/app.sqlite');

// PostgreSQL for analytics and advanced features  
$analyticsModel = new Model('users', 'postgresql:host=localhost;dbname=analytics;user=analyst;pass=secret');

// MySQL for production (traditional Trongate)
$prodModel = new Model('users', 'mysql:host=localhost;dbname=production;user=app;pass=secret');
```

### 3. **Cross-Database Development Workflow**
```php
// Develop locally with SQLite
$local = new Model('products', 'sqlite:./dev.sqlite');
$local->insert(['name' => 'Test Product', 'price' => 29.99]);

// Deploy to PostgreSQL staging
$staging = new Model('products', 'postgresql:host=staging;dbname=app');
$local->backup()->createBackup('/tmp/migration.sql', [
    'cross_database_compatible' => true,
    'target_database' => 'postgresql'
]);
$staging->backup()->restoreBackup('/tmp/migration.sql');

// Same code, different databases - seamlessly bridged!
```

## 📊 **Database Support Matrix**

| Feature | SQLite | MySQL/MariaDB | PostgreSQL |
|---------|--------|---------------|------------|
| **Basic CRUD** | ✅ | ✅ | ✅ |
| **Transactions** | ✅ | ✅ | ✅ |
| **Bulk Operations** | ✅ | ✅ | ✅ |
| **Backup/Restore** | ✅ | ✅ | ✅ |
| **Cross-DB Migration** | ✅ → MySQL/PostgreSQL | ✅ → SQLite/PostgreSQL | ✅ → SQLite/MySQL |
| **Expression Methods** | ✅ | ✅ | ✅ |
| **Debug System** | ✅ | ✅ | ✅ |
| **Schema Translation** | ✅ | ✅ | ✅ |

## 🎯 **When to Use TronBridge vs Original Trongate**

### ✅ **Use TronBridge When:**
- **Multi-Database Needs**: You want SQLite for development or PostgreSQL for analytics
- **Cross-Database Migration**: Moving between database types
- **Advanced Tooling**: Need backup/restore, debugging, or migration capabilities
- **CLI Scripts & Bulk Operations**: Processing large datasets 
- **Future Flexibility**: Want database choice freedom

### ⚠️ **Stick with Original Trongate When:**
- **Simple MySQL Web Apps**: Standard per-request web applications with basic MySQL needs
- **Maximum Per-Request Speed**: Every millisecond counts in your web responses (~20% overhead)
- **Minimal Dependencies**: You prefer the simplest possible setup

## 🔧 **Installation**

### **Requirements**
- PHP 8.0+
- PDO extension
- Database-specific extensions (pdo_sqlite, pdo_mysql, pdo_pgsql)

### **Installation Steps**

1. **Download TronBridge**
```bash
# Clone or download TronBridge
git clone https://github.com/tronbridge/tronbridge.git
cd tronbridge
```

2. **Replace Model.php**
```bash
# Backup your original Model.php
cp /path/to/trongate/engine/Model.php /path/to/trongate/engine/Model.php.backup

# Install TronBridge Model
cp engine/Model.php /path/to/trongate/engine/Model.php
```

3. **Add TronBridge Components**
```bash
# Copy TronBridge database classes
cp -r database/ /path/to/trongate/
```

4. **Configure Database Type** (Optional - Global Configuration)

TronBridge works with your existing Trongate database configuration. To use SQLite or PostgreSQL globally, add these constants to your `/config/database.php`:

```php
<?php
//Database settings
define('HOST', '127.0.0.1');
define('PORT', '3306');
define('USER', 'root');
define('PASSWORD', '');
define('DATABASE', '');

// ADD THESE FOR TRONBRIDGE:
// For SQLite
define('DB_TYPE', 'sqlite');
// optional path to database file. Defaults to ./database/storage/trongate.sqlite
define('DB_FILE', APPPATH . 'database/storage/trongate.sqlite'); 

// For PostgreSQL  
// define('DB_TYPE', 'postgresql');
// define('PORT', '5432');  // PostgreSQL default port

// For MySQL/MariaDB (default - no changes needed)
// define('DB_TYPE', 'mysql');  // This is the default
```

**Database Type Options:**
- `'mysql'` - MySQL/MariaDB (default, uses existing HOST, DATABASE, USER, PASSWORD, PORT)
- `'sqlite'` - SQLite (uses DB_FILE path, ignores other connection settings)
- `'postgres'` - PostgreSQL (uses existing HOST, DATABASE, USER, PASSWORD, PORT)

5. **Verify Installation**
```php
$model = new Model('test_table');
$capabilities = $model->backup()->getCapabilities();
echo "Database type: " . $capabilities['database_type'] . "\n";
echo "TronBridge installed successfully!\n";
```

## 💡 **Usage Examples**

### **Cross-Database Analytics**
```php
// Aggregate data from multiple database types
$sqliteModel = new Model('logs', 'sqlite:./logs.sqlite');
$postgresModel = new Model('analytics', 'postgresql:host=analytics;dbname=warehouse;user=app;pass=secret');

// Extract from SQLite, load to PostgreSQL
$recentLogs = $sqliteModel->get_where_custom('date', date('Y-m-d'), '>=', 'created_at', 'logs');
$postgresModel->insert_batch('processed_logs', $recentLogs);
```

### **Advanced Debugging & Optimization**
```php
// Enable rich debugging for development
$model = new Model('products', 'sqlite:./store.sqlite');
$model->setDebugPreset('developer');

$products = $model->get_where_custom('category_id', 5, '=', 'name', 'products', 50);

// Automatically shows:
// - Query execution time and analysis
// - Optimization suggestions  
// - Index recommendations with CREATE INDEX statements

echo $model->getDebugOutput(); // Rich HTML debug panel
```

## 🏗️ **Architecture & Performance**

### **Factory-Based Design**
TronBridge uses a clean factory pattern that keeps the main Model class lightweight while providing advanced features on-demand:

```php
$model = new Model;

// Factories create components only when needed
$backup = $model->backup();        // Database backup/restore system
$debug = $model->debug();          // Debug and profiling system  
$maintenance = $model->maintenance(); // Database maintenance tools
$performance = $model->performance(); // Performance optimization
```

### **Performance Characteristics**

| Scenario | Original Trongate | TronBridge | Recommendation |
|----------|------------------|------------|----------------|
| **Simple Web Requests** | Baseline | ~20% slower | ⚠️ Consider Original for high-frequency simple operations |
| **Bulk Data Processing** | Baseline | 60-97% faster | ✅ Ideal for ETL, imports, CLI scripts |
| **Cross-Database Operations** | Not Available | Available | ✅ Only option for multi-database needs |
| **Advanced Debugging** | Limited | Comprehensive | ✅ Invaluable for development and optimization |

### **Performance Modes**
```php
// Lightweight mode (default) - optimized for per-request performance
$model = new Model;

// Full mode - optimized for bulk operations and long-running processes  
$model = new Model('users', $connection);
$model->enablePerformanceMode(); // Additional bulk optimization pre-enabled
```

## 🛠️ **Advanced Features**

### **Expression Methods for Atomic Operations**
```php
// Atomic counter updates (race-condition free)
$model->increment_column($userId, 'login_count', 1, 'users');

// Complex atomic updates with cross-database function translation
$model->update_with_expressions($postId, [], [
    'view_count' => 'view_count + 1',
    'last_viewed' => 'NOW()'    // Automatically translates to database-specific function
], 'posts', ['view_count']);
```

### **Database Maintenance Tools**
```php
$maintenance = $model->maintenance();

// Unified maintenance across all database types
$maintenance->vacuum();              // Reclaim space (SQLite, PostgreSQL)
$maintenance->analyze();             // Update query optimizer statistics
$maintenance->checkIntegrity();      // Verify database integrity
$maintenance->optimize();            // Comprehensive optimization

// Database health monitoring
$health = $maintenance->getDatabaseHealth();
echo "Database size: " . $maintenance->formatBytes($health['size_bytes']);
```

### **Backup & Restore with Cross-Database Support**
```php
$backup = $model->backup();

// Simple backup
$result = $backup->createBackup('/backups/daily.sql');

// Restore with validation
$result = $backup->restoreBackup('/backups/daily.sql', [
    'validate_before_restore' => true,
    'backup_current' => true
]);
```

## 🐛 **Debug System**

TronBridge includes a comprehensive debug system with zero performance impact when disabled:

```php
// Quick debug presets
$model->setDebugPreset('developer');  // Rich HTML output for web development
$model->setDebugPreset('cli');        // Colored CLI output for scripts  
$model->setDebugPreset('production'); // JSON output for monitoring

// Advanced configuration
$model->setDebug(DebugLevel::DETAILED, DebugCategory::SQL | DebugCategory::PERFORMANCE, 'html');

// Database-specific query analysis
$users = $model->get_where_custom('status', 'active', '=', 'created_at', 'users');
echo $model->getDebugOutput();

// Automatic output includes:
// - Query execution time and performance analysis
// - Database-specific EXPLAIN plan interpretation  
// - Index usage recommendations with CREATE INDEX statements
// - Session-level performance insights
```

## 🚀 **CLI Tools**

TronBridge includes powerful command-line tools for database operations:

```bash
# Database backup with cross-database support
php scripts/backup-cli.php backup --source="sqlite:/path/to/app.sqlite" --output="/backups/app.sql"

# Cross-database migration
php scripts/migration-cli.php migrate --source="sqlite:/dev.sqlite" --target="postgresql:host=localhost;dbname=prod"

# SQL dump translation between database types
php scripts/sql-dump-translator.php translate --input="mysql_dump.sql" --output="postgresql_dump.sql" --target="postgresql"
```

## 🤝 **Community & Contributing**

### **Beta Testing Welcome!**
We're actively seeking beta testers to help validate TronBridge across different environments and use cases.

**What we're looking for:**
- Multi-database usage feedback
- Performance testing in real applications  
- Cross-database migration experiences
- Documentation improvements
- Bug reports and edge cases

### **Getting Help**
- 📖 **Documentation**: Comprehensive guides in `/docs/`
- 🐛 **Issues**: GitHub Issues for bug reports and feature requests
- 💬 **Discussions**: GitHub Discussions for questions and community support

### **Contributing**
- Fork the repository and create feature branches
- Follow existing code style and patterns
- Include tests for new database features
- Update documentation for changes
- Test across multiple database types

## 📝 **License**

MIT License

## 🙏 **Acknowledgments**

- **Trongate Framework**: For providing the excellent foundation that TronBridge extends
- **Database Communities**: MySQL, SQLite, and PostgreSQL projects that make multi-database support possible
- **Contributors**: All the developers helping to test and improve Trongate and TronBridge

---

## 🚀 **Get Started Today**

Ready to bridge your databases? 

1. **[Install TronBridge](#-installation)** in under 5 minutes
2. **Try multi-database support** with your existing Trongate code
3. **Explore advanced features** like cross-database migration and debugging
4. **Join the community** and help shape the future of multi-database Trongate applications

**TronBridge: Cross-database Trongate applications, simplified** 🌉