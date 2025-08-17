# Multi-Database Support Guide
> Complete database compatibility reference for TronBridge

TronBridge provides seamless multi-database support across **MySQL/MariaDB**, **SQLite**, and **PostgreSQL** with automatic driver detection, database-specific optimizations, and unified API compatibility.

## 🎯 **Overview**

TronBridge extends Trongate's original MySQL-only support to include:
- **✅ MySQL/MariaDB**: Full compatibility with original Trongate behavior
- **✅ SQLite**: Perfect for development, testing, and embedded applications
- **✅ PostgreSQL**: Enterprise-grade features and advanced analytics capabilities

All databases use the **same TronBridge Model API** - your application code remains identical regardless of the underlying database.

---

## 🔌 **Connection Methods**

### **Method 1: Global Configuration** (Recommended for Single Database)

Configure your database globally in `/config/database.php`:

```php
<?php
//Database settings (existing Trongate configuration)
define('HOST', '127.0.0.1');
define('PORT', '3306');
define('USER', 'root');
define('PASSWORD', '');
define('DATABASE', '');

// ADD THESE FOR TRONBRIDGE:

// For SQLite 
define('DB_TYPE', 'sqlite');
define('DB_FILE', APPPATH . 'storage/app.sqlite');

// For PostgreSQL
// define('DB_TYPE', 'postgresql');
// define('HOST', 'localhost');
// define('PORT', '5432');
// define('DATABASE', 'myapp');
// define('USER', 'postgres');
// define('PASSWORD', 'secret');

// For MySQL/MariaDB (default - no changes needed)
// define('DB_TYPE', 'mysql');  // This is the default
```

Then use normally:
```php
$model = new Model('users');  // Uses DB_TYPE from configuration
```

### **Method 2: Connection Strings** (Recommended for Multiple Databases)

```php
// SQLite connection
$sqliteModel = new Model('users', 'sqlite:/path/to/database.sqlite');

// MySQL connection
$mysqlModel = new Model('users', 'mysql:host=localhost;dbname=app;user=root;pass=secret');

// PostgreSQL connection  
$postgresModel = new Model('users', 'postgresql:host=localhost;dbname=app;user=postgres;pass=secret');
```

### **Method 3: Configuration Arrays**

```php
$config = [
    'type' => 'postgresql',
    'host' => 'localhost',
    'dbname' => 'myapp',
    'user' => 'postgres',
    'pass' => 'secret',
    'port' => '5432'
];

$model = new Model('users', $config);
```

### **Method 4: Direct PDO Connections**

```php
$pdo = new PDO('sqlite:/tmp/cache.db');
$model = new Model('cache', $pdo);
```

---

## 🗄️ **Database-Specific Features**

### **SQLite Support**

#### **Connection Formats**
```php
// File path formats
$model = new Model('table', 'sqlite:/absolute/path/to/database.sqlite');
$model = new Model('table', 'sqlite:./relative/path/to/database.sqlite');
$model = new Model('table', 'sqlite::memory:');  // In-memory database

// Global configuration
define('DB_TYPE', 'sqlite');
define('DB_FILE', APPPATH . 'storage/app.sqlite');
```

#### **SQLite-Specific Optimizations**
- **WAL Mode**: Automatically enabled for better concurrency
- **Foreign Keys**: Enabled by default for referential integrity
- **Vacuum Optimization**: Smart VACUUM operations for performance
- **Page Size Optimization**: Configured for optimal performance

#### **SQLite Best Practices**
```php
// Development database
$devModel = new Model('app', 'sqlite:./dev.sqlite');

// Testing with in-memory database
$testModel = new Model('test', 'sqlite::memory:');

// Production with file database
define('DB_TYPE', 'sqlite');
define('DB_FILE', '/var/lib/myapp/production.sqlite');
```

#### **SQLite File Management**
```php
// Check database file size
$model = new Model('app', 'sqlite:./app.sqlite');
$maintenance = $model->maintenance();
$stats = $maintenance->getMaintenanceStats();
echo "Database size: " . $maintenance->formatBytes($stats['database_size_bytes']);

// Vacuum and optimize
$maintenance->vacuum();  // Reclaim space and defragment
$maintenance->analyze(); // Update query planner statistics
```

### **MySQL/MariaDB Support**

#### **Connection Formats**
```php
// Standard connection
$model = new Model('table', 'mysql:host=localhost;dbname=app;user=root;pass=secret');

// With custom port and charset
$model = new Model('table', 'mysql:host=db.example.com;port=3307;dbname=app;user=app;pass=secret;charset=utf8mb4');

// Global configuration (traditional Trongate)
define('DB_TYPE', 'mysql');  // Optional - this is the default
// Use existing HOST, DATABASE, USER, PASSWORD, PORT constants
```

#### **MySQL-Specific Optimizations**
- **Persistent Connections**: Automatically enabled for reduced overhead
- **UTF-8 Character Set**: Default utf8mb4 for full Unicode support
- **InnoDB Optimizations**: Transaction-safe operations
- **Query Cache**: Leverages MySQL query caching when available

#### **MySQL Best Practices**
```php
// Production MySQL configuration
$model = new Model('app', 'mysql:host=prod.example.com;dbname=app_prod;user=app;pass=secure_password');

// Read replica support
$writeModel = new Model('app', 'mysql:host=master.db.com;dbname=app;user=writer;pass=secret');
$readModel = new Model('app', 'mysql:host=replica.db.com;dbname=app;user=reader;pass=secret');
```

#### **MySQL Maintenance**
```php
$maintenance = $model->maintenance();

// MySQL-specific maintenance
$maintenance->analyze();  // Updates table statistics
$maintenance->optimize(); // Optimizes table structure
$maintenance->checkIntegrity(); // Checks table integrity
```

### **PostgreSQL Support**

#### **Connection Formats**
```php
// Standard connection
$model = new Model('table', 'postgresql:host=localhost;dbname=app;user=postgres;pass=secret');

// Alternative format names
$model = new Model('table', 'postgres:host=localhost;dbname=app;user=postgres;pass=secret');
$model = new Model('table', 'pgsql:host=localhost;dbname=app;user=postgres;pass=secret');

// With custom port
$model = new Model('table', 'postgresql:host=db.example.com;port=5433;dbname=app;user=app;pass=secret');

// Global configuration
define('DB_TYPE', 'postgresql');
define('HOST', 'localhost');
define('DATABASE', 'myapp');
define('USER', 'postgres');
define('PASSWORD', 'secret');
define('PORT', '5432');
```

#### **PostgreSQL-Specific Features**
- **Advanced Data Types**: JSON, JSONB, Arrays, UUID support
- **Full-Text Search**: Built-in FTS capabilities
- **Advanced Indexing**: GIN, GiST, and partial indexes
- **Schema Support**: Multi-schema database organization

#### **PostgreSQL Best Practices**
```php
// Analytics database
$analyticsModel = new Model('analytics', 'postgresql:host=analytics.db.com;dbname=warehouse;user=analyst;pass=secret');

// Full-text search setup
$model->query("CREATE INDEX idx_posts_search ON posts USING GIN(to_tsvector('english', content))");

// JSON data handling
$model->insert([
    'user_data' => json_encode(['preferences' => ['theme' => 'dark']]),
    'metadata' => json_encode(['tags' => ['important', 'customer']])
], 'user_profiles');
```

#### **PostgreSQL Maintenance**
```php
$maintenance = $model->maintenance();

// PostgreSQL-specific maintenance
$maintenance->vacuum();   // VACUUM or VACUUM FULL
$maintenance->analyze();  // Updates planner statistics
$maintenance->reindex();  // Rebuilds indexes
```

---

## 🔄 **Cross-Database Compatibility**

### **API Compatibility Matrix**

| Feature | SQLite | MySQL | PostgreSQL | Notes |
|---------|--------|-------|------------|-------|
| **Basic CRUD** | ✅ | ✅ | ✅ | Full compatibility |
| **Transactions** | ✅ | ✅ | ✅ | All transaction methods |
| **Prepared Statements** | ✅ | ✅ | ✅ | Automatic optimization |
| **Bulk Operations** | ✅ | ✅ | ✅ | Smart chunking per database |
| **Expression Methods** | ✅ | ✅ | ✅ | Cross-database function translation |
| **Backup/Restore** | ✅ | ✅ | ✅ | Database-specific strategies |
| **Schema Introspection** | ✅ | ✅ | ✅ | `table_exists()`, `describe_table()` |
| **Maintenance** | ✅ | ✅ | ✅ | Database-appropriate operations |

### **Data Type Mapping**

TronBridge automatically handles data type differences between databases:

| Concept | SQLite | MySQL | PostgreSQL |
|---------|--------|-------|------------|
| **Auto Increment** | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INT AUTO_INCREMENT PRIMARY KEY` | `SERIAL PRIMARY KEY` |
| **Text** | `TEXT` | `TEXT` / `VARCHAR(255)` | `TEXT` / `VARCHAR(255)` |
| **Boolean** | `INTEGER` (0/1) | `TINYINT(1)` | `BOOLEAN` |
| **Decimal** | `REAL` | `DECIMAL(10,2)` | `DECIMAL(10,2)` |
| **Timestamp** | `DATETIME` | `DATETIME` | `TIMESTAMP` |
| **Large Text** | `TEXT` | `LONGTEXT` | `TEXT` |

### **Function Translation**

TronBridge automatically translates database functions in expression methods:

| Function | SQLite | MySQL | PostgreSQL |
|----------|--------|-------|------------|
| **NOW()** | `datetime('now')` | `NOW()` | `NOW()` |
| **CURRENT_DATE** | `date('now')` | `CURDATE()` | `CURRENT_DATE` |
| **RANDOM()** | `RANDOM()` | `RAND()` | `RANDOM()` |
| **LENGTH()** | `LENGTH()` | `LENGTH()` | `LENGTH()` |
| **UPPER()** | `UPPER()` | `UPPER()` | `UPPER()` |

Example:
```php
// This works on all databases - TronBridge translates automatically
$model->update_with_expressions($id, [], [
    'last_updated' => 'NOW()',
    'random_value' => 'RANDOM()'
], 'posts');
```

---

## 🚀 **Performance Characteristics**

### **Database Performance Comparison**

| Operation | SQLite | MySQL | PostgreSQL |
|-----------|--------|-------|------------|
| **Simple Queries** | Very Fast | Fast | Fast |
| **Complex Joins** | Good | Very Fast | Very Fast |
| **Full Text Search** | Good | Good | Excellent |
| **Write Concurrency** | Limited | Excellent | Excellent |
| **Read Concurrency** | Excellent | Excellent | Excellent |
| **Bulk Inserts** | Very Fast | Fast | Fast |
| **Analytics Queries** | Good | Good | Excellent |

### **When to Use Each Database**

#### **Use SQLite When:**
- **Development and Testing**: Fast setup, no server required
- **Small to Medium Applications**: < 100GB data, moderate traffic
- **Embedded Applications**: Desktop apps, mobile backends
- **Simple Deployments**: Single-file database, easy backup
- **Read-Heavy Workloads**: Excellent read performance

```php
// Perfect for development
$devModel = new Model('blog', 'sqlite:./dev_blog.sqlite');

// Great for testing
$testModel = new Model('test', 'sqlite::memory:');
```

#### **Use MySQL/MariaDB When:**
- **Traditional Web Applications**: Proven LAMP/LEMP stack
- **High Write Concurrency**: Many simultaneous writers
- **Shared Hosting**: Wide hosting provider support
- **Existing Infrastructure**: Already using MySQL ecosystem
- **WordPress/PHP Ecosystem**: Maximum compatibility

```php
// Traditional web application
$webModel = new Model('app', 'mysql:host=localhost;dbname=webapp;user=app;pass=secret');
```

#### **Use PostgreSQL When:**
- **Analytics and Reporting**: Advanced query capabilities
- **Complex Data Types**: JSON, arrays, custom types
- **Advanced Features**: Full-text search, GIS data
- **Data Integrity**: Strict ACID compliance
- **Enterprise Applications**: Advanced security and features

```php
// Analytics database
$analyticsModel = new Model('analytics', 'postgresql:host=warehouse.db.com;dbname=analytics;user=analyst;pass=secret');
```

---

## 🔧 **Multi-Database Application Patterns**

### **Environment-Based Database Selection**

```php
// config/database.php
switch (ENVIRONMENT) {
    case 'development':
        define('DB_TYPE', 'sqlite');
        define('DB_FILE', APPPATH . 'storage/dev.sqlite');
        break;
        
    case 'testing':
        define('DB_TYPE', 'sqlite');
        define('DB_FILE', ':memory:');
        break;
        
    case 'staging':
        define('DB_TYPE', 'postgresql');
        define('HOST', 'staging.db.com');
        define('DATABASE', 'app_staging');
        define('USER', 'app');
        define('PASSWORD', 'staging_password');
        break;
        
    case 'production':
        define('DB_TYPE', 'mysql');
        define('HOST', 'prod.db.com');
        define('DATABASE', 'app_production');
        define('USER', 'app');
        define('PASSWORD', 'secure_production_password');
        break;
}

// Your models work the same across all environments
$model = new Model('users');
```

### **Multi-Database Application Architecture**

```php
// Primary application database (MySQL)
$appModel = new Model('users', 'mysql:host=app.db.com;dbname=webapp;user=app;pass=secret');

// Analytics database (PostgreSQL)
$analyticsModel = new Model('events', 'postgresql:host=analytics.db.com;dbname=warehouse;user=analyst;pass=secret');

// Cache database (SQLite)
$cacheModel = new Model('cache', 'sqlite:/tmp/app_cache.sqlite');

// Session storage (SQLite for simplicity)
$sessionModel = new Model('sessions', 'sqlite:' . APPPATH . 'storage/sessions.sqlite');

// Example: Log user activity to analytics
$appModel->update($userId, ['last_login' => date('Y-m-d H:i:s')], 'users');
$analyticsModel->insert([
    'user_id' => $userId,
    'event_type' => 'login',
    'timestamp' => date('Y-m-d H:i:s'),
    'metadata' => json_encode(['ip' => $_SERVER['REMOTE_ADDR']])
], 'user_events');
```

### **Database Migration Patterns**

```php
// Gradual migration: Start with SQLite, move to PostgreSQL
$legacyModel = new Model('app', 'sqlite:./legacy_app.sqlite');
$newModel = new Model('app', 'postgresql:host=localhost;dbname=app_v2;user=postgres;pass=secret');

// Migrate data using TronBridge migration system
$migrationResult = $legacyModel->migration()->quickMigrate($legacyModel, $newModel, [
    'include_data' => true,
    'validate_before_migration' => true,
    'chunk_size' => 1000
]);

if ($migrationResult['success']) {
    echo "Migration completed: " . $migrationResult['total_records'] . " records migrated";
}
```

---

## 🔍 **Database Introspection**

TronBridge provides unified database introspection methods that work across all database types:

### **Table Operations**
```php
// Check if table exists (works on all databases)
if ($model->table_exists('users')) {
    echo "Users table exists";
}

// Get all tables in database
$tables = $model->get_all_tables();
foreach ($tables as $table) {
    echo "Table: $table\n";
}

// Describe table structure
$structure = $model->describe_table('users');
foreach ($structure as $column) {
    echo "Column: {$column['Field']} Type: {$column['Type']}\n";
}
```

### **Database Information**
```php
// Get database type
$dbType = $model->getDbType();  // 'mysql', 'sqlite', or 'postgresql'

// Get database configuration
$config = $model->getDatabaseConfig();
echo "Database: " . $config->getDbType();

// Get connection info (safe - no credentials)
$connectionInfo = $model->getConnectionInfo();
echo "Connected to: " . $connectionInfo['database_type'];
```

---

## 🛠️ **Troubleshooting Multi-Database Issues**

### **Connection Issues**

#### **SQLite Issues**
```php
// Check if file is writable
$dbFile = '/path/to/database.sqlite';
if (!is_writable(dirname($dbFile))) {
    echo "Directory not writable: " . dirname($dbFile);
}

// Check SQLite extension
if (!extension_loaded('pdo_sqlite')) {
    echo "SQLite PDO extension not installed";
}

// Test SQLite connection
try {
    $model = new Model('test', 'sqlite::memory:');
    echo "SQLite connection working";
} catch (Exception $e) {
    echo "SQLite error: " . $e->getMessage();
}
```

#### **PostgreSQL Issues**
```php
// Check PostgreSQL extension
if (!extension_loaded('pdo_pgsql')) {
    echo "PostgreSQL PDO extension not installed";
}

// Test PostgreSQL connection
try {
    $model = new Model('test', 'postgresql:host=localhost;dbname=postgres;user=postgres;pass=password');
    echo "PostgreSQL connection working";
} catch (Exception $e) {
    echo "PostgreSQL error: " . $e->getMessage();
}
```

#### **MySQL Issues**
```php
// Check MySQL extension  
if (!extension_loaded('pdo_mysql')) {
    echo "MySQL PDO extension not installed";
}

// Test MySQL connection
try {
    $model = new Model('test', 'mysql:host=localhost;dbname=mysql;user=root;pass=password');
    echo "MySQL connection working";
} catch (Exception $e) {
    echo "MySQL error: " . $e->getMessage();
}
```

### **Performance Issues**

#### **Database-Specific Optimization**
```php
// Check current database performance
$stats = $model->getPerformanceStats();
echo "Database type: " . $stats['database_type'];
echo "Cache efficiency: " . $stats['cache_stats']['cached_sql'];

// Enable debug to see database-specific suggestions
$model->setDebugPreset('developer');
$users = $model->get('name', 'users', 50);
echo $model->getDebugOutput();  // Shows database-specific optimization suggestions
```

#### **Cross-Database Query Issues**
```php
// Some queries may need database-specific optimization
$model = new Model('users');

// This might be slow on SQLite with large datasets
$users = $model->get_where_custom('created_at', '2023-01-01', '>', 'name', 'users');

// Better: Use database-appropriate indexes and techniques
if ($model->getDbType() === 'sqlite') {
    // SQLite-specific optimization
    $model->query("CREATE INDEX IF NOT EXISTS idx_users_created ON users(created_at)");
} elseif ($model->getDbType() === 'postgresql') {
    // PostgreSQL-specific optimization
    $model->query("CREATE INDEX IF NOT EXISTS idx_users_created ON users(created_at) WHERE created_at > '2023-01-01'");
}
```

### **Migration Issues**

```php
// Test database compatibility before migration
$sourceModel = new Model('app', 'sqlite:./source.sqlite');
$targetModel = new Model('app', 'postgresql:host=localhost;dbname=target');

$validation = $sourceModel->migration()->validateCompatibility($sourceModel, $targetModel);

if (!$validation['compatible']) {
    echo "Migration issues found:\n";
    foreach ($validation['issues'] as $issue) {
        echo "- $issue\n";
    }
} else {
    echo "Migration should succeed";
}
```

---

## 📊 **Summary**

TronBridge provides **comprehensive multi-database support** that enables:

- **✅ Unified API** - Same code works across MySQL, SQLite, PostgreSQL
- **✅ Environment Flexibility** - Use different databases per environment
- **✅ Performance Optimization** - Database-specific optimizations automatically applied
- **✅ Easy Migration** - Move between database types with automated schema translation
- **✅ Cross-Database Applications** - Use multiple databases simultaneously for different purposes

**Choose the right database for each environment and use case, while maintaining the same familiar TronBridge Model API throughout your application.**