# Migration System Guide
> Complete database migration workflows with TronBridge

TronBridge provides a comprehensive **cross-database migration system** that enables seamless data and schema migration between MySQL, SQLite, and PostgreSQL. This guide covers everything from simple backups to complex multi-database workflows.

## 🎯 **Migration Overview**

TronBridge supports multiple migration approaches:

| Migration Type | Use Case | Complexity | Best For |
|----------------|----------|------------|----------|
| **Backup/Restore** | Same database type | Simple | Regular backups, environment sync |
| **Cross-Database Backup** | Different database types | Medium | Development to production |
| **Schema Migration** | Structure only | Medium | Setup new environments |
| **Data Migration** | Data only | Medium | Existing schema populated |
| **Full Migration** | Schema + Data | Complex | Complete database transfer |
| **CLI Migration** | Automated workflows | Advanced | Scripts, CI/CD pipelines |

---

## ⚡ **Quick Migration Examples**

### **Simple Cross-Database Migration**
```php
// Migrate SQLite development database to MySQL production
$devModel = new Model('users', 'sqlite:./development.sqlite');
$prodModel = new Model('users', 'mysql:host=localhost;dbname=production');

// Full migration with validation
$migration = $devModel->migration();
$result = $migration->quickMigrate($devModel, $prodModel, [
    'validate_before_migration' => true,
    'validate_after_migration' => true,
    'chunk_size' => 1000
]);

if ($result['success']) {
    echo "Migration completed: {$result['tables_migrated']} tables, {$result['records_migrated']} records";
} else {
    echo "Migration failed: " . implode(', ', $result['errors']);
}
```

### **CLI Migration for Automation**
```bash
# Full database migration via CLI
php database/scripts/migration-cli.php migrate \
  --source="sqlite:./dev.sqlite" \
  --target="postgresql:host=prod.db.com;dbname=app;user=postgres;pass=secret" \
  --validate-before-migration \
  --validate-after-migration \
  --chunk-size=1000 \
  --verbose
```

### **Cross-Database Backup/Restore**
```php
// Create cross-database compatible backup
$sourceModel = new Model('products', 'mysql:host=localhost;dbname=shop');
$sourceModel->backup()->createBackup('/backups/shop_backup.sql', [
    'cross_database_compatible' => true,
    'target_database' => 'postgresql'
]);

// Restore to PostgreSQL
$targetModel = new Model('products', 'postgresql:host=analytics.db.com;dbname=shop');
$result = $targetModel->backup()->restoreBackup('/backups/shop_backup.sql');
```

---

## 🗄️ **Backup & Restore Migrations**

Perfect for syncing environments and regular backups with optional database type conversion.

### **Same Database Type Migration**
```php
// Standard backup and restore (same database type)
$source = new Model('orders', 'mysql:host=prod;dbname=ecommerce');
$target = new Model('orders', 'mysql:host=staging;dbname=ecommerce');

// Create backup
$backup = $source->backup();
$result = $backup->createBackup('/tmp/ecommerce_backup.sql', [
    'include_schema' => true,
    'include_data' => true,
    'compress' => true
]);

// Restore to staging
$restoreResult = $target->backup()->restoreBackup('/tmp/ecommerce_backup.sql');

echo "Backup size: " . $result['backup_size'] . " bytes\n";
echo "Restore time: " . $restoreResult['execution_time'] . " seconds\n";
```

### **Cross-Database Migration via Backup**
```php
// SQLite to PostgreSQL migration
$sqlite = new Model('analytics', 'sqlite:./analytics.sqlite');
$postgres = new Model('analytics', 'postgresql:host=warehouse;dbname=analytics');

// Create cross-database compatible backup
$backupResult = $sqlite->backup()->createBackup('/tmp/migration.sql', [
    'cross_database_compatible' => true,
    'target_database' => 'postgresql',
    'include_schema' => true,
    'include_data' => true
]);

// Restore to PostgreSQL
$restoreResult = $postgres->backup()->restoreBackup('/tmp/migration.sql');

if ($restoreResult['success']) {
    echo "Cross-database migration completed successfully\n";
    echo "Tables migrated: " . count($restoreResult['tables_processed']) . "\n";
} else {
    echo "Migration failed: " . implode(', ', $restoreResult['errors']) . "\n";
}
```

---

## 🔄 **Schema-Only Migration**

Migrate database structure without data - perfect for setting up new environments.

### **PHP API - Schema Migration**
```php
// Extract and migrate schema only
$source = new Model('shop', 'mysql:host=production;dbname=shop');
$target = new Model('shop', 'sqlite:./local_development.sqlite');

$migration = $source->migration();
$result = $migration->migrateSchemaOnly($source, $target, [
    'include_indexes' => true,
    'include_constraints' => true,
    'exclude_tables' => ['temp_*', 'log_*']
]);

if ($result['success']) {
    echo "Schema migration completed\n";
    echo "Tables created: " . implode(', ', $result['tables_migrated']) . "\n";
    echo "Indexes created: " . $result['indexes_created'] . "\n";
} else {
    echo "Schema migration failed\n";
    foreach ($result['errors'] as $error) {
        echo "Error: $error\n";
    }
}
```

### **CLI - Schema Migration**
```bash
# Schema-only migration with specific options
php database/scripts/migration-cli.php schema-only \
  --source="mysql:host=prod.db.com;dbname=app;user=readonly;pass=secret" \
  --target="sqlite:./dev_schema.sqlite" \
  --include-indexes \
  --include-constraints \
  --exclude-tables="audit_*,temp_*"

# PostgreSQL to MySQL schema migration
php database/scripts/migration-cli.php schema-only \
  --source="postgresql:host=analytics;dbname=warehouse" \
  --target="mysql:host=localhost;dbname=warehouse_copy" \
  --verbose
```

---

## 📊 **Data-Only Migration**

Migrate data into existing schema - useful when schema already exists but needs data refresh.

### **PHP API - Data Migration**
```php
// Migrate data only (assumes target schema exists)
$source = new Model('products', 'mysql:host=production;dbname=catalog');
$target = new Model('products', 'postgresql:host=analytics;dbname=catalog');

$migration = $source->migration();
$dataMigrator = $migration->createDataMigrator($source, $target);

// Add custom transformation rules
$dataMigrator->addTransformationRule('products', function($row) {
    // Transform data during migration
    $row['migrated_at'] = date('Y-m-d H:i:s');
    $row['price'] = floatval($row['price']); // Ensure numeric format
    return $row;
});

// Add column mapping
$dataMigrator->addColumnMapping('products', [
    'old_category_id' => 'category_id',
    'product_desc' => 'description'
]);

// Execute data migration
$result = $dataMigrator->migrateTable('products', [
    'chunk_size' => 500,
    'handle_conflicts' => 'update',
    'validate_data_types' => true
]);

echo "Records migrated: " . $result['records_migrated'] . "\n";
echo "Migration time: " . $result['execution_time'] . " seconds\n";
```

### **CLI - Data Migration**
```bash
# Data-only migration with conflict handling
php database/scripts/migration-cli.php data-only \
  --source="sqlite:./staging.sqlite" \
  --target="mysql:host=prod;dbname=app;user=migrate;pass=secret" \
  --chunk-size=1000 \
  --handle-conflicts=update \
  --validate-data-types

# Selective table data migration
php database/scripts/migration-cli.php data-only \
  --source="postgresql:host=warehouse;dbname=analytics" \
  --target="sqlite:./local_analytics.sqlite" \
  --include-tables="users,orders,products" \
  --exclude-tables="temp_*"
```

---

## 🔄 **Full Migration Workflows**

Complete database migration including schema transformation, data migration, and validation.

### **Development to Production Workflow**
```php
// Complete development to production migration
$dev = new Model('app', 'sqlite:./development.sqlite');
$prod = new Model('app', 'mysql:host=production;dbname=app');

$migration = $dev->migration();

// Step 1: Validate compatibility
$validation = $migration->createValidator($dev, $prod);
$compatibility = $validation->validateCompatibility();

if (!$compatibility['compatible']) {
    echo "Migration not compatible:\n";
    foreach ($compatibility['errors'] as $error) {
        echo "  - $error\n";
    }
    exit(1);
}

// Step 2: Create rollback point
$rollbackBackup = $prod->backup()->createBackup('/backups/pre_migration_' . date('Y_m_d_H_i_s') . '.sql');

// Step 3: Execute full migration
$migrator = $migration->createMigrator($dev, $prod, [
    'include_data' => true,
    'include_indexes' => true,
    'include_constraints' => true,
    'chunk_size' => 1000,
    'validate_before_migration' => true,
    'validate_after_migration' => true,
    'handle_conflicts' => 'update'
]);

$result = $migrator->migrateDatabase();

if ($result['success']) {
    echo "✅ Migration completed successfully!\n";
    echo "📊 Tables migrated: " . count($result['tables_migrated']) . "\n";
    echo "📈 Records migrated: " . $result['records_migrated'] . "\n";
    echo "⏱️ Total time: " . $result['total_time'] . " seconds\n";
} else {
    echo "❌ Migration failed!\n";
    foreach ($result['errors'] as $error) {
        echo "  Error: $error\n";
    }
    
    // Rollback if needed
    echo "🔄 Rolling back...\n";
    $prod->backup()->restoreBackup($rollbackBackup['backup_file']);
}
```

### **Multi-Environment Pipeline**
```php
// Automated pipeline: Development → Staging → Production
class MigrationPipeline 
{
    public function execute(): void 
    {
        $environments = [
            'dev' => new Model('app', 'sqlite:./dev.sqlite'),
            'staging' => new Model('app', 'postgresql:host=staging;dbname=app'),
            'production' => new Model('app', 'mysql:host=prod;dbname=app')
        ];
        
        // Dev → Staging
        $this->migrateEnvironment($environments['dev'], $environments['staging'], 'staging');
        
        // Staging → Production
        $this->migrateEnvironment($environments['staging'], $environments['production'], 'production');
    }
    
    private function migrateEnvironment($source, $target, $envName): void 
    {
        echo "🚀 Migrating to $envName...\n";
        
        $migration = $source->migration();
        $result = $migration->quickMigrate($source, $target, [
            'validate_before_migration' => true,
            'validate_after_migration' => true,
            'create_rollback_point' => true,
            'chunk_size' => 1000
        ]);
        
        if ($result['success']) {
            echo "✅ $envName migration completed\n";
        } else {
            echo "❌ $envName migration failed\n";
            throw new Exception("Migration to $envName failed");
        }
    }
}

// Execute pipeline
$pipeline = new MigrationPipeline();
$pipeline->execute();
```

---

## 🖥️ **CLI Migration Tools**

Powerful command-line tools for automation, CI/CD pipelines, and batch operations.

### **Migration CLI Commands**

#### **Full Migration**
```bash
# Complete database migration
php database/scripts/migration-cli.php migrate \
  --source="sqlite:./source.sqlite" \
  --target="postgresql:host=target;dbname=app;user=postgres;pass=secret" \
  --chunk-size=1000 \
  --memory-limit="1G" \
  --validate-before-migration \
  --validate-after-migration \
  --verbose

# MySQL to SQLite with custom options
php database/scripts/migration-cli.php migrate \
  --source="mysql:host=prod;dbname=legacy;user=migrate;pass=secret" \
  --target="sqlite:./modern.sqlite" \
  --exclude-tables="audit_*,temp_*,log_*" \
  --handle-conflicts=update \
  --continue-on-error
```

#### **Validation & Reports**
```bash
# Validate migration compatibility
php database/scripts/migration-cli.php validate \
  --source="sqlite:./dev.sqlite" \
  --target="mysql:host=prod;dbname=app" \
  --verbose

# Generate comprehensive migration report
php database/scripts/migration-cli.php report \
  --source="mysql:host=source;dbname=app" \
  --target="postgresql:host=target;dbname=app" \
  --output=migration-analysis.json
```

#### **Batch Migration**
```bash
# Batch migration from configuration file
php database/scripts/migration-cli.php batch \
  --config=batch-migration.json \
  --verbose

# Example batch-migration.json:
{
  "migrations": [
    {
      "name": "users_migration",
      "source": "sqlite:./users.sqlite",
      "target": "mysql:host=prod;dbname=users",
      "options": {
        "chunk_size": 500,
        "validate_after_migration": true
      }
    },
    {
      "name": "products_migration", 
      "source": "mysql:host=legacy;dbname=catalog",
      "target": "postgresql:host=warehouse;dbname=catalog",
      "options": {
        "exclude_tables": ["temp_*"],
        "handle_conflicts": "update"
      }
    }
  ]
}
```

### **Automation Scripts**

#### **Migration Pipeline Script**
```bash
#!/bin/bash
# migration-pipeline.sh

set -e

SOURCE="sqlite:./development.sqlite"
STAGING="postgresql:host=staging;dbname=app;user=app;pass=$STAGING_PASSWORD"
PRODUCTION="mysql:host=production;dbname=app;user=app;pass=$PROD_PASSWORD"

echo "🚀 Starting migration pipeline..."

# Step 1: Validate all migrations
echo "📋 Validating migrations..."
php database/scripts/migration-cli.php validate --source="$SOURCE" --target="$STAGING" --quiet
php database/scripts/migration-cli.php validate --source="$STAGING" --target="$PRODUCTION" --quiet

# Step 2: Backup production before migration
echo "💾 Creating production backup..."
BACKUP_FILE="/backups/pre_migration_$(date +%Y%m%d_%H%M%S).sql"
php database/scripts/backup-cli.php backup --source="$PRODUCTION" --output="$BACKUP_FILE"

# Step 3: Migrate to staging
echo "🔄 Migrating to staging..."
php database/scripts/migration-cli.php migrate \
  --source="$SOURCE" \
  --target="$STAGING" \
  --validate-before-migration \
  --validate-after-migration

# Step 4: Migrate to production
echo "🚀 Migrating to production..."
php database/scripts/migration-cli.php migrate \
  --source="$STAGING" \
  --target="$PRODUCTION" \
  --validate-before-migration \
  --validate-after-migration

echo "✅ Migration pipeline completed successfully!"
```

#### **Scheduled Migration Script**
```bash
#!/bin/bash
# scheduled-sync.sh - Daily development to staging sync

LOG_FILE="/var/log/migration-sync.log"
DATE=$(date '+%Y-%m-%d %H:%M:%S')

echo "[$DATE] Starting scheduled migration sync" >> "$LOG_FILE"

# Sync development to staging daily
php database/scripts/migration-cli.php migrate \
  --source="sqlite:./development.sqlite" \
  --target="postgresql:host=staging;dbname=app;user=sync;pass=$SYNC_PASSWORD" \
  --validate-before-migration \
  --handle-conflicts=update \
  --quiet >> "$LOG_FILE" 2>&1

if [ $? -eq 0 ]; then
    echo "[$DATE] Migration sync completed successfully" >> "$LOG_FILE"
else
    echo "[$DATE] Migration sync failed" >> "$LOG_FILE"
    # Send notification
    echo "Migration sync failed at $DATE" | mail -s "Migration Sync Alert" admin@example.com
fi
```

---

## 🔧 **Advanced Migration Features**

### **Custom Data Transformations**
```php
// Complex data transformation during migration
$migration = $sourceModel->migration();
$dataMigrator = $migration->createDataMigrator($sourceModel, $targetModel);

// Transform user data
$dataMigrator->addTransformationRule('users', function($row) {
    // Hash passwords if migrating from old system
    if (isset($row['plain_password'])) {
        $row['password'] = password_hash($row['plain_password'], PASSWORD_DEFAULT);
        unset($row['plain_password']);
    }
    
    // Convert date formats
    if (isset($row['created_date'])) {
        $row['created_at'] = date('Y-m-d H:i:s', strtotime($row['created_date']));
        unset($row['created_date']);
    }
    
    // Add migration metadata
    $row['migrated_at'] = date('Y-m-d H:i:s');
    $row['migration_version'] = '2.0';
    
    return $row;
});

// Transform product data with category mapping
$categoryMapping = [
    'electronics' => 'technology',
    'books' => 'media',
    'clothing' => 'fashion'
];

$dataMigrator->addTransformationRule('products', function($row) use ($categoryMapping) {
    if (isset($row['category']) && isset($categoryMapping[$row['category']])) {
        $row['category'] = $categoryMapping[$row['category']];
    }
    
    // Ensure price is numeric
    $row['price'] = floatval($row['price']);
    
    // Generate SKU if missing
    if (empty($row['sku'])) {
        $row['sku'] = 'MIGRATED_' . strtoupper(substr(md5($row['name']), 0, 8));
    }
    
    return $row;
});
```

### **Column Mapping & Schema Changes**
```php
// Handle schema differences between source and target
$dataMigrator->addColumnMapping('users', [
    'old_email_field' => 'email',
    'user_name' => 'username',
    'full_name' => 'display_name',
    'creation_date' => 'created_at'
]);

$dataMigrator->addColumnMapping('orders', [
    'order_total' => 'total_amount',
    'order_status' => 'status',
    'customer_id' => 'user_id'
]);

// Execute migration with mappings
$result = $dataMigrator->migrateTable('users', [
    'chunk_size' => 1000,
    'handle_conflicts' => 'update'
]);
```

### **Progress Tracking & Monitoring**
```php
// Set up progress tracking for large migrations
$migration = $sourceModel->migration();
$migrator = $migration->createMigrator($sourceModel, $targetModel);

// Custom progress callback
$migrator->setProgressCallback(function($phase, $current, $total, $message) {
    $percentage = $total > 0 ? round(($current / $total) * 100, 2) : 0;
    echo "[$phase] $message - Progress: $current/$total ($percentage%)\n";
    
    // Optional: Update database progress table
    // $progressModel->update(['progress' => $percentage, 'message' => $message], 'migration_id', $migrationId);
});

// Execute with progress tracking
$result = $migrator->migrateDatabase();
```

### **Rollback & Recovery**
```php
// Migration with rollback support
$migration = $sourceModel->migration();

try {
    // Create rollback point
    $rollbackFile = '/backups/rollback_' . time() . '.sql';
    $targetModel->backup()->createBackup($rollbackFile);
    
    // Execute migration
    $result = $migration->quickMigrate($sourceModel, $targetModel, [
        'create_rollback_point' => true,
        'validate_after_migration' => true
    ]);
    
    if (!$result['success']) {
        throw new Exception('Migration validation failed');
    }
    
    echo "Migration completed successfully\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    echo "Rolling back...\n";
    
    // Restore from rollback point
    $restoreResult = $targetModel->backup()->restoreBackup($rollbackFile);
    
    if ($restoreResult['success']) {
        echo "Rollback completed successfully\n";
    } else {
        echo "⚠️ Rollback failed - manual intervention required\n";
    }
}
```

---

## 🔍 **Migration Validation & Testing**

### **Pre-Migration Validation**
```php
// Comprehensive compatibility validation
$validator = $migration->createValidator($sourceModel, $targetModel);
$validation = $validator->validateCompatibility();

if (!$validation['compatible']) {
    echo "❌ Migration compatibility issues found:\n";
    
    foreach ($validation['errors'] as $error) {
        echo "  🚨 Error: $error\n";
    }
    
    foreach ($validation['warnings'] as $warning) {
        echo "  ⚠️ Warning: $warning\n";
    }
    
    exit(1);
}

echo "✅ Migration compatibility validated\n";
echo "📊 Database compatibility score: " . $validation['compatibility_score'] . "%\n";
```

### **Post-Migration Validation**
```php
// Validate migration results
$postValidation = $validator->validateMigration([
    'verify_row_counts' => true,
    'verify_data_integrity' => true,
    'verify_constraints' => true,
    'verify_indexes' => true,
    'sample_data_verification' => true,
    'sample_size' => 100
]);

if ($postValidation['success']) {
    echo "✅ Post-migration validation passed\n";
    echo "📊 Tables verified: " . $postValidation['tables_verified'] . "\n";
} else {
    echo "❌ Post-migration validation failed\n";
    foreach ($postValidation['errors'] as $error) {
        echo "  Error: $error\n";
    }
}
```

### **Migration Testing Workflow**
```php
// Complete testing workflow
class MigrationTester 
{
    public function testMigration($sourceModel, $targetModel): array 
    {
        $results = [
            'pre_validation' => false,
            'migration' => false,
            'post_validation' => false,
            'rollback_test' => false
        ];
        
        try {
            // 1. Pre-migration validation
            $migration = $sourceModel->migration();
            $validator = $migration->createValidator($sourceModel, $targetModel);
            
            $preValidation = $validator->validateCompatibility();
            $results['pre_validation'] = $preValidation['compatible'];
            
            if (!$results['pre_validation']) {
                return $results;
            }
            
            // 2. Test migration on copy
            $testTarget = $this->createTestCopy($targetModel);
            $migrationResult = $migration->quickMigrate($sourceModel, $testTarget);
            $results['migration'] = $migrationResult['success'];
            
            if (!$results['migration']) {
                return $results;
            }
            
            // 3. Post-migration validation
            $postValidation = $validator->validateMigration();
            $results['post_validation'] = $postValidation['success'];
            
            // 4. Test rollback capability
            $rollbackTest = $this->testRollback($testTarget);
            $results['rollback_test'] = $rollbackTest;
            
        } catch (Exception $e) {
            $results['error'] = $e->getMessage();
        }
        
        return $results;
    }
    
    private function createTestCopy($model) 
    {
        // Create temporary test database
        $testDb = 'sqlite:./test_migration_' . time() . '.sqlite';
        return new Model($model->getTableName(), $testDb);
    }
    
    private function testRollback($model): bool 
    {
        try {
            // Test backup and restore functionality
            $backup = $model->backup();
            $backupFile = '/tmp/rollback_test_' . time() . '.sql';
            
            $backupResult = $backup->createBackup($backupFile);
            $restoreResult = $backup->restoreBackup($backupFile);
            
            unlink($backupFile);
            return $backupResult['success'] && $restoreResult['success'];
            
        } catch (Exception $e) {
            return false;
        }
    }
}

// Use the tester
$tester = new MigrationTester();
$testResults = $tester->testMigration($sourceModel, $targetModel);

foreach ($testResults as $test => $passed) {
    $status = $passed ? '✅' : '❌';
    echo "$status $test\n";
}
```

---

## 📋 **Migration Best Practices**

### **✅ Migration Do's**

1. **Always validate first**
   ```php
   $validation = $validator->validateCompatibility();
   if (!$validation['compatible']) {
       // Handle compatibility issues before proceeding
   }
   ```

2. **Create rollback points**
   ```php
   $backupFile = $targetModel->backup()->createBackup('/backups/pre_migration.sql');
   ```

3. **Use appropriate chunk sizes**
   ```php
   // Large datasets: smaller chunks to manage memory
   'chunk_size' => 500,  // For datasets > 100K records
   
   // Medium datasets: balanced chunks
   'chunk_size' => 1000, // For datasets 10K-100K records
   
   // Small datasets: larger chunks for speed
   'chunk_size' => 5000  // For datasets < 10K records
   ```

4. **Handle conflicts appropriately**
   ```php
   'handle_conflicts' => 'update', // Update existing records
   'handle_conflicts' => 'skip',   // Skip conflicting records
   'handle_conflicts' => 'error'   // Stop on conflicts
   ```

5. **Monitor progress for large migrations**
   ```php
   $migrator->setProgressCallback(function($phase, $current, $total, $message) {
       echo "[$phase] $current/$total - $message\n";
   });
   ```

### **❌ Migration Don'ts**

1. **Don't migrate without testing** - Always test on copies first
2. **Don't ignore validation warnings** - Address compatibility issues
3. **Don't run migrations without rollback plans** - Always have recovery options
4. **Don't use large chunk sizes for huge datasets** - Can cause memory issues
5. **Don't migrate to production without staging tests** - Test the full pipeline
6. **Don't ignore failed validations** - Fix issues before proceeding

### **🔧 Performance Optimization**

1. **Optimize chunk sizes based on data**
   ```php
   // Calculate optimal chunk size
   $recordSize = strlen(serialize($sampleRecord));
   $optimalChunk = min(5000, max(100, 50000 / $recordSize));
   ```

2. **Use appropriate memory limits**
   ```php
   'memory_limit' => '512M', // For large datasets
   'memory_limit' => '1G',   // For very large datasets
   ```

3. **Disable constraints during migration**
   ```php
   'disable_foreign_keys' => true, // For large migrations
   ```

4. **Use transactions appropriately**
   ```php
   'use_transaction' => true,  // For data integrity
   'transaction_size' => 1000  // Commit every 1000 records
   ```

---

## 🎉 **Summary**

TronBridge's migration system provides:

- **✅ Complete cross-database migration** between MySQL, SQLite, and PostgreSQL
- **✅ Multiple migration approaches** from simple backup/restore to complex workflows
- **✅ Advanced data transformation** with custom rules and column mapping
- **✅ Comprehensive validation** before and after migration
- **✅ CLI tools for automation** and CI/CD pipeline integration
- **✅ Progress tracking and monitoring** for large-scale migrations
- **✅ Rollback and recovery support** for safe migration operations

**Whether you're syncing development environments, modernizing legacy systems, or building multi-database applications, TronBridge's migration system provides the tools and flexibility you need for successful database migrations.**