# TronBridge Installation Guide

This guide walks you through installing TronBridge in your existing Trongate application with multiple installation methods from simple to advanced.

## 📋 **Requirements**

### **System Requirements**
- **PHP**: 8.0 or higher
- **Trongate Framework**: Any recent version (TronBridge is compatible with all Trongate versions)
- **PDO Extension**: Required (usually included with PHP)

### **Database Extensions**
Install the PDO extensions for the databases you plan to use:

| Database | Required Extension | Check Installation |
|----------|-------------------|-------------------|
| **MySQL/MariaDB** | `pdo_mysql` | `php -m \| grep pdo_mysql` |
| **SQLite** | `pdo_sqlite` | `php -m \| grep pdo_sqlite` |
| **PostgreSQL** | `pdo_pgsql` | `php -m \| grep pdo_pgsql` |

### **Optional Tools** (for enhanced backup capabilities)
- **mysqldump** - MySQL command-line backup tool
- **pg_dump** - PostgreSQL command-line backup tool  
- **sqlite3** - SQLite command-line tool

## 🚀 **Quick Installation** (Recommended)

The simplest way to install TronBridge is by downloading the ZIP file and copying the necessary files.

### **Step 1: Download TronBridge**

**Option A: Download ZIP from GitHub**
1. Go to the [TronBridge GitHub repository](https://github.com/tronbridge/tronbridge)
2. Click the green **"Code"** button
3. Select **"Download ZIP"**
4. Extract the ZIP file to a temporary location

**Option B: Clone with Git**
```bash
git clone https://github.com/tronbridge/tronbridge.git
cd tronbridge
```

### **Step 2: Backup Your Existing Files**
```bash
# Navigate to your Trongate application directory
cd /path/to/your/trongate/app

# Backup your original Model.php (recommended)
cp engine/Model.php engine/Model.php.backup
```

### **Step 3: Install TronBridge Core Files**

**Copy the database folder:**
```bash
# Copy the entire database folder to your Trongate app
cp -r /path/to/tronbridge/database /path/to/your/trongate/app/
```

**Replace Model.php:**
```bash
# Replace your existing Model.php with TronBridge version
cp /path/to/tronbridge/engine/Model.php /path/to/your/trongate/app/engine/Model.php
```

### **Step 4: Configure Database** (Optional)

TronBridge works with your existing MySQL configuration by default. To use SQLite or PostgreSQL, add these constants to your `/config/database.php`:

```php
<?php
//Database settings (existing)
define('HOST', '127.0.0.1');
define('PORT', '3306');
define('USER', 'root');
define('PASSWORD', '');
define('DATABASE', '');

// ADD THESE FOR TRONBRIDGE IF USING POSTGRESQL OR SQLITE FOR MAIN DATABASE

// For SQLite
// define('DB_TYPE', 'sqlite');
// define('DB_FILE', APPPATH . 'database/storage/trongate.sqlite');

// For PostgreSQL 
// define('DB_TYPE', 'postgres');
// define('HOST', 'localhost');
// define('PORT', '5432');
// define('DATABASE', 'your_database');
// define('USER', 'your_username');
// define('PASSWORD', 'your_password');

// For MySQL/MariaDB (default - no changes needed)
// define('DB_TYPE', 'mysql');  // This is the default if not specified
```

### **Step 5: Verify Installation**

Create a simple test file to verify TronBridge is working:

```php
<?php
// test_tronbridge.php
require_once 'engine/Model.php';

try {
    $model = new Model();
    
    // Test basic functionality
    echo "✅ TronBridge Model loaded successfully\n";
    
    // Test database connection
    $pdo = $model->getPDO();
    echo "✅ Database connection established\n";
    
    // Test TronBridge features
    $capabilities = $model->backup()->getCapabilities();
    echo "✅ Database type: " . $capabilities['database_type'] . "\n";
    echo "✅ Backup strategies available: " . count($capabilities['available_strategies']) . "\n";
    
    // Test debug system
    $model->setDebugPreset('cli');
    echo "✅ Debug system ready\n";
    
    echo "\n🎉 TronBridge installation successful!\n";
    
} catch (Exception $e) {
    echo "❌ Installation error: " . $e->getMessage() . "\n";
    echo "Please check the troubleshooting section below.\n";
}
```

Run the test:
```bash
php test_tronbridge.php
```

## 🔧 **Advanced Installation Options**

### **Option 1: Selective Installation**

If you only want specific TronBridge features, you can install components selectively:

#### **Core Model Only** (Multi-database support)
```bash
# Minimum installation for multi-database support
cp /path/to/tronbridge/engine/Model.php /path/to/your/trongate/app/engine/Model.php
cp -r /path/to/tronbridge/database/engine/core /path/to/your/trongate/app/database/engine/
cp -r /path/to/tronbridge/database/engine/factories /path/to/your/trongate/app/database/engine/
```

#### **Core + Debug System**
```bash
# Add debug capabilities
cp -r /path/to/tronbridge/database/engine/debug /path/to/your/trongate/app/database/engine/
cp -r /path/to/tronbridge/database/engine/helpers /path/to/your/trongate/app/database/engine/
```

#### **Core + Backup System**
```bash
# Add backup and migration capabilities
cp -r /path/to/tronbridge/database/engine/backup /path/to/your/trongate/app/database/engine/
cp -r /path/to/tronbridge/database/engine/migration /path/to/your/trongate/app/database/engine/
```

### **Option 2: Development vs Production Installation**

#### **Full Development Installation**
```bash
# Complete installation with all features for development
cp -r /path/to/tronbridge/database /path/to/your/trongate/app/
cp -r /path/to/tronbridge/scripts /path/to/your/trongate/app/
cp /path/to/tronbridge/engine/Model.php /path/to/your/trongate/app/engine/Model.php

# Optional: Enhanced Transferer for module SQL imports
cp -r /path/to/tronbridge/engine/tg_transferer /path/to/your/trongate/app/engine/
```

#### **Minimal Production Installation**
```bash
# Lightweight installation for production (excludes CLI tools and some debug features)
cp /path/to/tronbridge/engine/Model.php /path/to/your/trongate/app/engine/Model.php
cp -r /path/to/tronbridge/database/engine/core /path/to/your/trongate/app/database/engine/
cp -r /path/to/tronbridge/database/engine/factories /path/to/your/trongate/app/database/engine/
cp -r /path/to/tronbridge/database/engine/backup /path/to/your/trongate/app/database/engine/
```

## 📦 **Enhanced Transferer Installation** (Optional)

If you use **Trongate Module Market add-ons** that include SQL dump files, you can install the Enhanced Transferer for cross-database SQL translation:

### **What it Does**
- Translates SQL dumps from Trongate modules between database types
- Enables using MySQL-based modules with SQLite or PostgreSQL
- **Note**: Does not yet support SQL files created with the Trongate Desktop App

### **Installation**
```bash
# Install Enhanced Transferer
cp -r /path/to/tronbridge/engine/tg_transferer /path/to/your/trongate/app/engine/
```


## 🗂️ **File Structure After Installation**

After successful installation, your Trongate application should have this structure:

```
your-trongate-app/
├── config/
│   └── database.php                     # Your database configuration (possibly updated)
├── engine/
│   ├── Model.php                        # ✨ TronBridge Enhanced Model (replaced)
│   └── tg_transferer/                   # 📦 Optional: Enhanced Transferer
│       ├── enhanced-animations.css
│       ├── enhanced-transferer.css
│       ├── enhanced-transferer.js
│       ├── EnhancedTransferer.php
│       ├── index.php
│       ├── Transferer.php
│       └── TransfererDetection.php
└── database/                            # ✨ TronBridge Components (new)
    ├── engine/
    │   ├── backup/                      # Backup and restore system
    │   ├── core/                        # Core TronBridge components
    │   ├── debug/                       # Debug system
    │   ├── exceptions/                  # Exception classes
    │   ├── factories/                   # Factory classes
    │   ├── helpers/                     # Helper utilities
    │   ├── migration/                   # Migration tools
    │   ├── schema/                      # Schema translation
    │   └── traits/                      # Shared traits
    └── scripts/                         # 🔧 Optional: CLI tools
    │   ├── backup-cli.php
    │   ├── migration-cli.php
    │   └── sql-dump-translator.php
    └── storage/                         # Database files (SQLite)
```

## 🔍 **Installation Verification**

### **Multi-Database Test**
```php
<?php
// Test different database connections
try {
    // SQLite test
    $sqliteModel = new Model(null, 'sqlite::memory:');
    echo "✅ SQLite support working\n";
    
    // MySQL test (using existing config)
    $mysqlModel = new Model('test');
    echo "✅ MySQL support working\n";
    
    // PostgreSQL test (if configured)
    if (defined('DB_TYPE') && DB_TYPE === 'postgresql') {
        $pgModel = new Model('test');
        echo "✅ PostgreSQL support working\n";
    }
    
} catch (Exception $e) {
    echo "❌ Database test failed: " . $e->getMessage() . "\n";
}
```

### **CLI Tools Test** (if installed)
```bash
# Test backup CLI
php database/scripts/backup-cli.php --help

# Test migration CLI  
php database/scripts/migration-cli.php --help

# Test SQL translator
php database/scripts/sql-dump-translator.php --help
```

## 🔄 **Rollback Instructions**

If you need to rollback to the original Trongate Model:

```bash
# Restore original Model.php
cp engine/Model.php.backup engine/Model.php

# Remove TronBridge database folder
rm -rf database/

# Remove enhanced transferer (if installed)
rm -rf engine/tg_transferer/
```

Your application will return to standard Trongate functionality.

## 🛠️ **Troubleshooting**

### **"Class not found" Errors**

**Problem**: PHP cannot find TronBridge classes
```
Fatal error: Class 'DatabaseBackupFactory' not found
```

**Solution**: Verify file paths and permissions
```bash
# Check if database folder exists and has correct structure
ls -la database/engine/

# Check file permissions
chmod -R 755 database/
```

### **Database Connection Issues**

**Problem**: Cannot connect to database
```
SQLSTATE[HY000] [2002] Connection refused
```

**Solutions**:

**For MySQL/MariaDB:**
```bash
# Check if MySQL service is running
sudo systemctl status mysql

# Verify connection details in config/database.php
```

**For SQLite:**
```bash
# Check if directory is writable
chmod 755 database/storage/
ls -la database/storage/

# Verify DB_FILE path exists
php -r "echo realpath(APPPATH . 'database/storage/') . '\n';"
```

**For PostgreSQL:**
```bash
# Check if PostgreSQL service is running
sudo systemctl status postgresql

# Test connection manually
psql -h localhost -U your_username -d your_database
```

### **Performance Issues**

**Problem**: Application slower than expected

**Solutions**:

1. **Check if you're using appropriate mode:**
```php
// For web applications, use lightweight mode (default)
$model = new Model('users');

// For bulk operations, enable performance mode
$model->enablePerformanceMode();
```

2. **Disable debug in production:**
```php
// Make sure debug is disabled in production
$model->setDebug(false);
```

3. **Consider selective installation** if you don't need all features

### **Getting Additional Help**

If you continue to experience issues:

1. **Check the TronBridge GitHub Issues**: Search for similar problems
2. **Create a new issue**: Include your PHP version, database type, and error messages
3. **Join the community**: Participate in GitHub Discussions
4. **Consult the documentation**: Check `/docs/` for detailed guides

## 📚 **Next Steps**

After successful installation:

1. **Read the [Quick Start Guide](QUICK-START.md)** - Learn basic multi-database usage
2. **Explore [Database Features](DATABASE-SUPPORT.md)** - Understand cross-database capabilities  
3. **Set up [Backup Procedures](BACKUP-SYSTEM.md)** - Implement backup and migration workflows
4. **Enable [Debug Tools](DEBUG-SYSTEM.md)** - Optimize your database operations
5. **Try [CLI Tools](CLI-TOOLS.md)** - Automate database tasks

**🎉 Welcome to TronBridge! You now have multi-database Trongate applications.** 🌉