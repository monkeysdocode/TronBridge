# Advanced Backup & Restore System
> Enterprise-grade database backup and restore with intelligent strategy selection

TronBridge provides a comprehensive backup and restore system with **intelligent strategy selection**, **cross-database compatibility**, and **enterprise-grade security** across MySQL, SQLite, and PostgreSQL databases.

## 🎯 **Key Features**

- **🏭 Intelligent Strategy Selection** - Automatic selection of optimal backup method per database
- **🔄 Cross-Database Support** - Unified API across MySQL, SQLite, and PostgreSQL
- **🛡️ Secure Operations** - Credential protection and secure shell execution
- **📊 Progress Tracking** - Real-time progress updates for large operations
- **⚡ Multiple Fallback Strategies** - Graceful degradation when preferred methods unavailable
- **🔍 Enhanced Debugging** - Full integration with TronBridge debug system

---

## ⚡ **Quick Start**

### **Simple Backup**
```php
$model = new Model('users');
$result = $model->backup()->createBackup('/backups/users.sql');

if ($result['success']) {
    echo "✅ Backup created: " . $result['output_path'];
    echo "📊 Size: " . number_format($result['backup_size_bytes']) . " bytes";
    echo "⏱️ Duration: " . round($result['duration_seconds'], 2) . " seconds";
} else {
    echo "❌ Backup failed: " . $result['error'];
}
```

### **Simple Restore**
```php
$model = new Model('users');
$result = $model->backup()->restoreBackup('/backups/users.sql');

if ($result['success']) {
    echo "✅ Restore completed: " . $result['statements_executed'] . " statements";
} else {
    echo "❌ Restore failed: " . $result['error'];
}
```

### **Check Capabilities**
```php
$model = new Model('database');
$capabilities = $model->backup()->getCapabilities();
$testResults = $model->backup()->testCapabilities();

echo "Database type: " . $capabilities['database_type'] . "\n";
foreach ($testResults as $strategy => $result) {
    $status = $result['success'] ? '✅' : '❌';
    echo "$status " . basename($strategy) . "\n";
}
```

---

## 🗄️ **Database-Specific Strategies**

TronBridge automatically selects the best backup strategy based on your database type and available system capabilities.

### **SQLite Strategies** (Priority Order)

| Priority | Strategy | Type | Performance | Use Case |
|----------|----------|------|-------------|----------|
| **1** | **SQLiteNativeBackupStrategy** | Binary | ~10MB/sec | Fast, reliable SQLite-to-SQLite backup |
| **2** | **SQLiteVacuumBackupStrategy** | Binary | ~8MB/sec | Compressed, optimized backup with defragmentation |
| **3** | **SQLiteSQLDumpStrategy** | SQL Text | ~3MB/sec | Human-readable, version control friendly |
| **4** | **SQLiteFileCopyBackupStrategy** | File Copy | ~20MB/sec | Last resort, requires exclusive database access |

#### **SQLite Strategy Selection Logic**
```php
// TronBridge automatically selects the best available strategy:

// 1. If SQLite3 extension available → Native backup (preferred)
// 2. If SQLite 3.27.0+ → VACUUM INTO backup
// 3. Always available → SQL dump backup
// 4. Fallback → File copy (with safety measures)

$model = new Model('app', 'sqlite:./app.sqlite');
$result = $model->backup()->createBackup('/backup/app.sql');
// Automatically uses best available strategy
```

#### **SQLite Strategy Examples**
```php
// Force specific SQLite strategy (advanced usage)
$model = new Model('app', 'sqlite:./app.sqlite');

// Force SQL dump for version control
$result = $model->backup()->createBackup('/backup/readable.sql', [
    'force_strategy' => 'sqlite_sql_dump',
    'format_sql' => true,
    'add_comments' => true
]);

// Force binary backup for speed
$result = $model->backup()->createBackup('/backup/binary.db', [
    'force_strategy' => 'sqlite_native'
]);
```

### **MySQL Strategies** (Priority Order)

| Priority | Strategy | Type | Performance | Use Case |
|----------|----------|------|-------------|----------|
| **1** | **MySQLShellBackupStrategy** | Shell (mysqldump) | ~5MB/sec | Complete MySQL backup with all features |
| **2** | **MySQLPHPBackupStrategy** | PHP SQL | ~2MB/sec | Pure PHP for restricted hosting environments |

#### **MySQL Strategy Examples**
```php
$model = new Model('app', 'mysql:host=localhost;dbname=app;user=app;pass=secret');

// Advanced MySQL backup with shell strategy
$result = $model->backup()->createBackup('/backup/mysql_full.sql', [
    'single_transaction' => true,    // Consistent snapshot
    'routines' => true,              // Include stored procedures/functions
    'triggers' => true,              // Include triggers
    'events' => true,                // Include scheduled events
    'complete_insert' => true,       // Include column names in INSERT
    'add_drop_database' => false,    // Don't include DROP DATABASE
    'compress_output' => true        // Compress the output
]);

// PHP-only backup (fallback for restricted hosting)
$result = $model->backup()->createBackup('/backup/mysql_php.sql', [
    'force_strategy' => 'mysql_php',
    'chunk_size' => 1000,           // Process 1000 records at a time
    'include_schema' => true,
    'include_data' => true
]);
```

### **PostgreSQL Strategies** (Priority Order)

| Priority | Strategy | Type | Performance | Use Case |
|----------|----------|------|-------------|----------|
| **1** | **PostgreSQLShellBackupStrategy** | Shell (pg_dump) | ~8MB/sec | Complete PostgreSQL backup with all features |
| **2** | **PostgreSQLPHPBackupStrategy** | PHP SQL | ~2MB/sec | Pure PHP for restricted hosting environments |

#### **PostgreSQL Strategy Examples**
```php
$model = new Model('app', 'postgresql:host=localhost;dbname=app;user=postgres;pass=secret');

// Advanced PostgreSQL backup
$result = $model->backup()->createBackup('/backup/postgres_full.backup', [
    'format' => 'custom',           // PostgreSQL custom format
    'compress' => 9,                // Maximum compression
    'verbose' => true,              // Detailed output
    'jobs' => 4,                    // Parallel dump (4 processes)
    'exclude_table_data' => ['temp_*', 'log_*']  // Exclude temporary tables
]);

// SQL format backup for portability
$result = $model->backup()->createBackup('/backup/postgres_sql.sql', [
    'format' => 'plain',            // Plain SQL format
    'inserts' => true,              // Use INSERT statements instead of COPY
    'create_database' => true,      // Include CREATE DATABASE statement
    'clean' => true                 // Include DROP statements
]);
```

---

## 📝 **Advanced Backup Options**

### **Progress Tracking**
```php
$model = new Model('large_database');

$result = $model->backup()->createBackup('/backup/large_db.sql', [
    'timeout' => 3600,  // 1 hour timeout
    'progress_callback' => function($progress) {
        $percent = round($progress['progress_percent'], 1);
        $operation = $progress['current_operation'];
        $speed = $progress['records_per_second'] ?? 0;
        
        echo "[" . date('H:i:s') . "] $operation: $percent% ($speed records/sec)\r";
        flush();
    }
]);
```

### **Backup Validation**
```php
// Create backup with automatic validation
$result = $model->backup()->createBackup('/backup/validated.sql', [
    'validate_backup' => true,       // Validate backup after creation
    'include_schema' => true,
    'include_data' => true
]);

if ($result['success'] && $result['validation']['valid']) {
    echo "✅ Backup created and validated successfully";
    echo "📊 Tables: " . count($result['validation']['tables_detected']);
    echo "📊 Records: " . $result['validation']['records_estimated'];
}

// Validate existing backup file
$validation = $model->backup()->validateBackupFile('/backup/existing.sql');
if ($validation['valid']) {
    echo "✅ Backup file is valid";
    echo "Format: " . $validation['format'];
    echo "Database type: " . $validation['database_type'];
} else {
    echo "❌ Backup validation failed: " . $validation['error'];
}
```

### **Compressed Backups**
```php
// Create compressed backup
$result = $model->backup()->createBackup('/backup/compressed.sql.gz', [
    'compress_output' => true,
    'compression_level' => 6,        // Balance of speed vs size (1-9)
    'include_schema' => true,
    'include_data' => true
]);

if ($result['success']) {
    $original = $result['uncompressed_size_bytes'];
    $compressed = $result['backup_size_bytes'];
    $ratio = round((1 - $compressed / $original) * 100, 1);
    
    echo "✅ Compressed backup created";
    echo "📊 Compression ratio: $ratio%";
    echo "📊 Space saved: " . number_format($original - $compressed) . " bytes";
}
```

### **Selective Backups**
```php
// Backup specific tables only
$result = $model->backup()->createBackup('/backup/users_only.sql', [
    'include_tables' => ['users', 'user_profiles', 'user_preferences'],
    'include_schema' => true,
    'include_data' => true
]);

// Exclude sensitive tables
$result = $model->backup()->createBackup('/backup/public_data.sql', [
    'exclude_tables' => ['password_resets', 'sessions', 'audit_logs'],
    'include_schema' => true,
    'include_data' => true
]);

// Schema-only backup
$result = $model->backup()->createBackup('/backup/schema_only.sql', [
    'include_schema' => true,
    'include_data' => false
]);

// Data-only backup  
$result = $model->backup()->createBackup('/backup/data_only.sql', [
    'include_schema' => false,
    'include_data' => true
]);
```

---

## 🔄 **Restore Operations**

### **Safe Restore with Validation**
```php
$model = new Model('users');

$result = $model->backup()->restoreBackup('/backup/users.sql', [
    'validate_before_restore' => true,   // Validate backup file first
    'backup_current' => true,            // Backup current database before restore
    'use_transaction' => true,           // Wrap restore in transaction
    'stop_on_error' => false,           // Continue despite individual statement failures
    'timeout' => 1800,                  // 30 minute timeout
    'progress_callback' => function($progress) {
        echo "Restore progress: " . round($progress['progress_percent'], 1) . "%\r";
    }
]);

if ($result['success']) {
    echo "✅ Restore completed successfully";
    echo "📊 Statements executed: " . $result['statements_executed'];
    echo "📊 Failed statements: " . $result['statements_failed'];
    echo "⏱️ Duration: " . round($result['duration_seconds'], 2) . " seconds";
    
    if ($result['backup_created']) {
        echo "🔒 Original database backed up to: " . $result['backup_path'];
    }
} else {
    echo "❌ Restore failed: " . $result['error'];
    
    if ($result['backup_created']) {
        echo "🔒 Original database is safe at: " . $result['backup_path'];
    }
}
```

### **Restore with Custom Options**
```php
// Restore specific tables only
$result = $model->backup()->restoreBackup('/backup/full.sql', [
    'include_tables' => ['users', 'products'],
    'validate_before_restore' => true,
    'use_transaction' => true
]);

// Restore without foreign key checks (MySQL)
$result = $model->backup()->restoreBackup('/backup/data.sql', [
    'disable_foreign_keys' => true,
    'use_transaction' => false,  // Required when disabling foreign keys
    'validate_before_restore' => true
]);

// Restore with error tolerance
$result = $model->backup()->restoreBackup('/backup/partial.sql', [
    'stop_on_error' => false,           // Continue despite errors
    'ignore_errors' => [                // Ignore specific error types
        'duplicate_entry',
        'table_already_exists'
    ],
    'max_errors' => 10                  // Stop if more than 10 errors
]);
```

---

## 🔧 **Backup Automation & Scheduling**

### **Automated Backup Scripts**
```php
#!/usr/bin/env php
<?php
// automated_backup.php

require_once 'engine/Model.php';

$model = new Model('app');
$model->setDebugPreset('cli');

$timestamp = date('Y-m-d_H-i-s');
$backupPath = "/backups/app_backup_$timestamp.sql";

echo "Starting automated backup...\n";

$result = $model->backup()->createBackup($backupPath, [
    'include_schema' => true,
    'include_data' => true,
    'validate_backup' => true,
    'compress_output' => true,
    'timeout' => 3600,
    'progress_callback' => function($progress) {
        echo "Progress: " . round($progress['progress_percent'], 1) . "%\r";
    }
]);

if ($result['success']) {
    echo "\n✅ Backup completed successfully\n";
    echo "📁 File: " . $result['output_path'] . "\n";
    echo "📊 Size: " . number_format($result['backup_size_bytes']) . " bytes\n";
    
    // Optional: Upload to cloud storage, send notification, etc.
    
    exit(0);
} else {
    echo "\n❌ Backup failed: " . $result['error'] . "\n";
    exit(1);
}
```

### **Retention Policy Script**
```php
#!/usr/bin/env php
<?php
// backup_retention.php

$backupDir = '/backups';
$retentionDays = 30;

// Clean up old backups
$files = glob($backupDir . '/app_backup_*.sql*');
$now = time();

foreach ($files as $file) {
    $fileAge = $now - filemtime($file);
    $ageDays = $fileAge / (24 * 60 * 60);
    
    if ($ageDays > $retentionDays) {
        echo "Removing old backup: " . basename($file) . " (age: " . round($ageDays, 1) . " days)\n";
        unlink($file);
    }
}

echo "Backup retention cleanup completed\n";
```

### **Cron Job Setup**
```bash
# Add to crontab (crontab -e)

# Daily backup at 2 AM
0 2 * * * /usr/bin/php /path/to/your/app/automated_backup.php >> /var/log/backup.log 2>&1

# Weekly cleanup at 3 AM on Sundays
0 3 * * 0 /usr/bin/php /path/to/your/app/backup_retention.php >> /var/log/backup.log 2>&1

# Hourly backup for critical systems
0 * * * * /usr/bin/php /path/to/your/app/automated_backup.php >> /var/log/backup.log 2>&1
```

---

## 🧪 **Testing & Validation**

### **Backup Strategy Testing**
```php
// Test all available backup strategies
$model = new Model('test_db');
$testResults = $model->backup()->testCapabilities();

echo "Backup Strategy Test Results:\n";
foreach ($testResults as $strategy => $result) {
    $status = $result['success'] ? '✅' : '❌';
    $name = basename($strategy, '.php');
    
    echo "$status $name";
    if ($result['success']) {
        echo " (Available)";
    } else {
        echo " (Unavailable: " . $result['error'] . ")";
    }
    echo "\n";
}

// Get detailed capabilities
$capabilities = $model->backup()->getCapabilities();
echo "\nSystem Capabilities:\n";
echo "Database type: " . $capabilities['database_type'] . "\n";
echo "Shell access: " . ($capabilities['proc_open'] ? 'Yes' : 'No') . "\n";
echo "SQLite3 extension: " . ($capabilities['sqlite3_extension'] ? 'Yes' : 'No') . "\n";
echo "Compression support: " . ($capabilities['gzip_support'] ? 'Yes' : 'No') . "\n";
```

### **Backup Verification**
```php
// Create test backup and verify restore
$model = new Model('test');

// Create test data
$testData = [
    ['name' => 'Test User 1', 'email' => 'test1@example.com'],
    ['name' => 'Test User 2', 'email' => 'test2@example.com'],
    ['name' => 'Test User 3', 'email' => 'test3@example.com']
];
$model->insert_batch('test_users', $testData);

// Create backup
$backupResult = $model->backup()->createBackup('/tmp/test_backup.sql', [
    'validate_backup' => true
]);

if ($backupResult['success']) {
    echo "✅ Test backup created\n";
    
    // Clear table
    $model->query("DELETE FROM test_users");
    
    // Restore backup
    $restoreResult = $model->backup()->restoreBackup('/tmp/test_backup.sql');
    
    if ($restoreResult['success']) {
        $restoredCount = $model->count('test_users');
        echo "✅ Backup verification successful: $restoredCount records restored\n";
    } else {
        echo "❌ Restore verification failed: " . $restoreResult['error'] . "\n";
    }
    
    // Cleanup
    unlink('/tmp/test_backup.sql');
} else {
    echo "❌ Test backup failed: " . $backupResult['error'] . "\n";
}
```

---

## 🚨 **Emergency Procedures**

### **Emergency Restore**
```php
// Emergency restore procedure
$model = new Model('critical_app');

echo "🚨 Starting emergency restore procedure...\n";

// Step 1: Validate backup file
$validation = $model->backup()->validateBackupFile('/emergency_backup/latest.sql');
if (!$validation['valid']) {
    die("❌ Backup file validation failed: " . $validation['error'] . "\n");
}

echo "✅ Backup file validated\n";

// Step 2: Create safety backup of current state (if possible)
echo "Creating safety backup of current state...\n";
$safetyBackup = $model->backup()->createBackup('/emergency_backup/safety_' . date('Y-m-d_H-i-s') . '.sql', [
    'timeout' => 300,  // Quick 5-minute backup
    'include_schema' => true,
    'include_data' => true
]);

if ($safetyBackup['success']) {
    echo "✅ Safety backup created: " . $safetyBackup['output_path'] . "\n";
} else {
    echo "⚠️ Safety backup failed, continuing with restore...\n";
}

// Step 3: Perform emergency restore
echo "Performing emergency restore...\n";
$restoreResult = $model->backup()->restoreBackup('/emergency_backup/latest.sql', [
    'validate_before_restore' => false,  // Skip validation for speed
    'backup_current' => false,           // We already made a safety backup
    'use_transaction' => true,
    'timeout' => 1800,                   // 30-minute timeout
    'progress_callback' => function($progress) {
        echo "Restore progress: " . round($progress['progress_percent'], 1) . "%\r";
    }
]);

if ($restoreResult['success']) {
    echo "\n✅ Emergency restore completed successfully\n";
    echo "📊 Statements executed: " . $restoreResult['statements_executed'] . "\n";
    echo "⏱️ Duration: " . round($restoreResult['duration_seconds'], 2) . " seconds\n";
} else {
    echo "\n❌ Emergency restore failed: " . $restoreResult['error'] . "\n";
    if ($safetyBackup['success']) {
        echo "🔒 Safety backup available at: " . $safetyBackup['output_path'] . "\n";
    }
    exit(1);
}
```

### **Disaster Recovery Checklist**
```php
// disaster_recovery.php
$steps = [
    '1. Assess the situation',
    '2. Locate most recent valid backup',
    '3. Validate backup file integrity', 
    '4. Create safety backup of current state (if possible)',
    '5. Estimate restore duration',
    '6. Notify stakeholders of downtime',
    '7. Perform restore operation',
    '8. Verify data integrity',
    '9. Test application functionality',
    '10. Document incident and lessons learned'
];

foreach ($steps as $step) {
    echo "☐ $step\n";
}
```

---

## 🛠️ **Troubleshooting**

### **Common Issues & Solutions**

#### **"No backup strategies available"**
```php
// Diagnose strategy availability
$model = new Model('test');
$capabilities = $model->backup()->getCapabilities();

echo "Database type: " . $capabilities['database_type'] . "\n";
echo "Shell access: " . ($capabilities['proc_open'] ? 'Available' : 'Not available') . "\n";

$testResults = $model->backup()->testCapabilities();
foreach ($testResults as $strategy => $result) {
    if (!$result['success']) {
        echo "❌ $strategy: " . $result['error'] . "\n";
    }
}

// Solutions:
// 1. Install missing extensions (SQLite3, MySQL, PostgreSQL)
// 2. Install command-line tools (mysqldump, pg_dump)
// 3. Check file permissions
// 4. Verify database connection
```

#### **"Backup file not recognized"**
```php
// Check backup file format
$validation = $model->backup()->validateBackupFile('/path/to/backup.sql');

echo "Valid: " . ($validation['valid'] ? 'Yes' : 'No') . "\n";
echo "Format: " . ($validation['format'] ?? 'Unknown') . "\n";
echo "Database type: " . ($validation['database_type'] ?? 'Unknown') . "\n";

if (!$validation['valid']) {
    echo "Error: " . $validation['error'] . "\n";
    
    // Common solutions:
    // 1. Check file is not corrupted
    // 2. Verify file is actual SQL dump
    // 3. Check file encoding (should be UTF-8)
    // 4. Try different backup strategy
}
```

#### **"Restore fails with parsing errors"**
```php
// Enable detailed debugging for restore
$model->setDebugPreset('verbose');

$result = $model->backup()->restoreBackup('/path/to/backup.sql', [
    'validate_statements' => true,
    'stop_on_error' => false,     // Continue despite errors
    'debug_parsing' => true
]);

echo $model->getDebugOutput();    // Shows detailed parsing information

// Check specific errors
if (!$result['success']) {
    echo "Failed statements: " . $result['statements_failed'] . "\n";
    if (isset($result['errors'])) {
        foreach ($result['errors'] as $error) {
            echo "Error: " . $error . "\n";
        }
    }
}
```

### **Performance Optimization**

#### **Large Database Backups**
```php
// Optimize for large databases
$result = $model->backup()->createBackup('/backup/large.sql', [
    'chunk_size' => 10000,          // Larger chunks
    'memory_limit' => '2G',         // Increase memory limit  
    'timeout' => 7200,              // 2 hour timeout
    'compress_output' => true,      // Reduce file size
    'include_drop_statements' => false,  // Reduce SQL size
    'progress_callback' => function($progress) {
        // Log to file instead of echo to avoid memory buildup
        error_log("Backup progress: " . $progress['progress_percent'] . "%");
    }
]);
```

#### **Restore Performance**
```php
// Optimize restore performance
$result = $model->backup()->restoreBackup('/backup/large.sql', [
    'chunk_size' => 5000,           // Smaller chunks for memory efficiency
    'use_transaction' => false,     // Disable for speed (less safe)
    'validate_statements' => false, // Skip validation for speed
    'disable_foreign_keys' => true, // Temporarily disable constraints
    'timeout' => 7200              // Extended timeout
]);
```

---

## 📊 **Best Practices**

### **Production Backup Strategy**
1. **Multiple Backup Types**: Combine full and incremental backups
2. **Geographically Distributed**: Store backups in multiple locations
3. **Regular Testing**: Verify backups can be restored successfully
4. **Retention Policy**: Balance storage costs with recovery needs
5. **Monitoring**: Track backup success/failure and file sizes
6. **Documentation**: Maintain clear recovery procedures

### **Security Best Practices**
1. **Encrypt Backups**: Encrypt backup files at rest
2. **Secure Storage**: Use appropriate file permissions and access controls
3. **Credential Protection**: Never store database credentials in backup files
4. **Network Security**: Use secure connections for remote backups
5. **Audit Logging**: Log all backup and restore operations

### **Performance Best Practices**
1. **Off-Peak Scheduling**: Run backups during low-traffic periods
2. **Resource Management**: Monitor CPU, memory, and disk I/O during operations
3. **Compression**: Use compression for large databases to save storage and transfer time
4. **Parallel Processing**: Use parallel backup strategies when available
5. **Regular Maintenance**: Keep databases optimized for faster backup operations

---

## 🎉 **Summary**

TronBridge's backup system provides:

- **✅ Enterprise-grade reliability** with intelligent fallback strategies
- **✅ Cross-database compatibility** with MySQL, SQLite, PostgreSQL support
- **✅ Secure operations** with credential protection and safe execution
- **✅ Production-ready monitoring** with comprehensive debugging integration
- **✅ Flexible automation** with progress tracking and validation
- **✅ Zero breaking changes** - perfect drop-in enhancement for TronBridge

Perfect for development, testing, production deployments, and disaster recovery with the confidence of enterprise-grade backup and restore capabilities.