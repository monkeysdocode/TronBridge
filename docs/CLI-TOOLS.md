# Command Line Tools Guide
> Powerful CLI utilities for database operations, migrations, and SQL translation

TronBridge includes comprehensive command-line tools for **database backup/restore**, **cross-database migration**, and **SQL dump translation**. These tools provide automation capabilities, batch processing, and advanced features for database management.

## 🎯 **Available CLI Tools**

- **🗄️ backup-cli.php** - Multi-database backup and restore operations
- **🔄 migration-cli.php** - Database-to-database migration and schema management  
- **📄 sql-dump-translator.php** - SQL dump translation between database types

All tools support **MySQL**, **SQLite**, and **PostgreSQL** with intelligent strategy selection and comprehensive error handling.

---

## 🗄️ **Database Backup CLI** (`backup-cli.php`)

Enterprise-grade database backup and restore with intelligent strategy selection.

### **Available Commands**

```bash
# Create backup
php database/scripts/backup-cli.php backup --source="CONNECTION" --output="FILE" [OPTIONS]

# Restore backup  
php database/scripts/backup-cli.php restore --target="CONNECTION" --backup="FILE" [OPTIONS]

# Test capabilities
php database/scripts/backup-cli.php test --source="CONNECTION" [OPTIONS]

# Validate backup file
php database/scripts/backup-cli.php validate --backup="FILE" [OPTIONS]

# List strategies
php database/scripts/backup-cli.php strategies --source="CONNECTION" [OPTIONS]

# Estimate backup size
php database/scripts/backup-cli.php estimate --source="CONNECTION" [OPTIONS]

# Show help
php database/scripts/backup-cli.php help [COMMAND]
```

### **Connection String Formats**

```bash
# SQLite
--source="sqlite:/path/to/database.sqlite"
--source="sqlite::memory:"  # In-memory database

# MySQL
--source="mysql:host=localhost;dbname=app;user=root;pass=secret"
--source="mysql:host=db.example.com;port=3307;dbname=production;user=app;pass=secret"

# PostgreSQL
--source="postgresql:host=localhost;dbname=app;user=postgres;pass=secret"
--source="postgres:host=analytics.db.com;port=5433;dbname=warehouse;user=analyst;pass=secret"
```

### **Backup Operations**

#### **Basic Backup**
```bash
# SQLite backup
php database/scripts/backup-cli.php backup \
  --source="sqlite:./app.sqlite" \
  --output="/backups/app_backup.sql"

# MySQL backup with compression
php database/scripts/backup-cli.php backup \
  --source="mysql:host=localhost;dbname=ecommerce;user=backup;pass=secret" \
  --output="/backups/mysql_backup.sql.gz" \
  --compress

# PostgreSQL backup with progress tracking
php database/scripts/backup-cli.php backup \
  --source="postgresql:host=db.example.com;dbname=production;user=readonly;pass=secret" \
  --output="/backups/production.sql" \
  --verbose \
  --progress
```

#### **Advanced Backup Options**
```bash
# Schema-only backup
php database/scripts/backup-cli.php backup \
  --source="mysql:host=localhost;dbname=app" \
  --output="/backups/schema.sql" \
  --no-data

# Data-only backup
php database/scripts/backup-cli.php backup \
  --source="sqlite:./app.sqlite" \
  --output="/backups/data.sql" \
  --no-schema

# Force specific strategy
php database/scripts/backup-cli.php backup \
  --source="sqlite:./app.sqlite" \
  --output="/backups/forced.sql" \
  --force-strategy="sqlite_sql_dump" \
  --format-sql

# Backup with timeout and validation
php database/scripts/backup-cli.php backup \
  --source="postgresql:host=localhost;dbname=analytics" \
  --output="/backups/analytics.sql" \
  --timeout=7200 \
  --validate-backup \
  --chunk-size=5000
```

### **Restore Operations**

#### **Basic Restore**
```bash
# Restore to SQLite
php database/scripts/backup-cli.php restore \
  --target="sqlite:./restored.sqlite" \
  --backup="/backups/app_backup.sql"

# Restore to MySQL with validation
php database/scripts/backup-cli.php restore \
  --target="mysql:host=localhost;dbname=restored;user=admin;pass=secret" \
  --backup="/backups/backup.sql" \
  --validate-before-restore

# Restore with progress tracking
php database/scripts/backup-cli.php restore \
  --target="postgresql:host=localhost;dbname=target;user=postgres;pass=secret" \
  --backup="/backups/source.sql" \
  --verbose \
  --progress
```

#### **Safe Restore Options**
```bash
# Restore with safety backup
php database/scripts/backup-cli.php restore \
  --target="mysql:host=localhost;dbname=production;user=admin;pass=secret" \
  --backup="/backups/restore.sql" \
  --backup-current \
  --use-transaction

# Restore with error tolerance
php database/scripts/backup-cli.php restore \
  --target="sqlite:./app.sqlite" \
  --backup="/backups/partial.sql" \
  --continue-on-error \
  --no-validate
```

### **Testing & Validation**

#### **Test Database Capabilities**
```bash
# Test SQLite capabilities
php database/scripts/backup-cli.php test --source="sqlite:./test.sqlite"

# Test MySQL with detailed output
php database/scripts/backup-cli.php test \
  --source="mysql:host=localhost;dbname=test;user=root;pass=secret" \
  --verbose

# Test PostgreSQL with debug info
php database/scripts/backup-cli.php test \
  --source="postgresql:host=localhost;dbname=test;user=postgres;pass=secret" \
  --debug
```

#### **Validate Backup Files**
```bash
# Validate backup file
php database/scripts/backup-cli.php validate --backup="/backups/backup.sql"

# Validate with detailed analysis
php database/scripts/backup-cli.php validate \
  --backup="/backups/complex.sql" \
  --verbose

# Validate multiple files
for file in /backups/*.sql; do
  php database/scripts/backup-cli.php validate --backup="$file" --quiet
done
```

#### **List Available Strategies**
```bash
# List strategies for SQLite
php database/scripts/backup-cli.php strategies --source="sqlite:./app.sqlite"

# List strategies with detailed info
php database/scripts/backup-cli.php strategies \
  --source="mysql:host=localhost;dbname=test" \
  --verbose

# Show all supported strategies
php database/scripts/backup-cli.php strategies --show-all
```

### **Backup CLI Options Reference**

| Option | Description | Example |
|--------|-------------|---------|
| `--source=CONNECTION` | Source database connection | `--source="sqlite:./app.sqlite"` |
| `--target=CONNECTION` | Target database connection | `--target="mysql:host=localhost;dbname=app"` |
| `--output=FILE` | Output backup file path | `--output="/backups/backup.sql"` |
| `--backup=FILE` | Backup file to restore | `--backup="/backups/restore.sql"` |
| `--compress` | Compress backup output | `--compress` |
| `--format-sql` | Format SQL for readability | `--format-sql` |
| `--no-schema` | Exclude schema from backup | `--no-schema` |
| `--no-data` | Exclude data from backup | `--no-data` |
| `--validate-backup` | Validate backup after creation | `--validate-backup` |
| `--no-validate` | Skip backup validation | `--no-validate` |
| `--backup-current` | Backup current before restore | `--backup-current` |
| `--use-transaction` | Use transaction for restore | `--use-transaction` |
| `--continue-on-error` | Continue despite errors | `--continue-on-error` |
| `--force-strategy=NAME` | Force specific backup strategy | `--force-strategy="sqlite_sql_dump"` |
| `--timeout=SECONDS` | Operation timeout | `--timeout=3600` |
| `--chunk-size=NUMBER` | Chunk size for processing | `--chunk-size=5000` |
| `--verbose` | Verbose output | `--verbose` |
| `--debug` | Debug output | `--debug` |
| `--quiet` | Minimal output | `--quiet` |
| `--progress` | Show progress (default: true) | `--no-progress` |

---

## 🔄 **Database Migration CLI** (`migration-cli.php`)

Complete database-to-database migration with schema translation and data transfer.

### **Available Commands**

```bash
# Full migration
php database/scripts/migration-cli.php migrate --source="CONNECTION" --target="CONNECTION" [OPTIONS]

# Schema-only migration
php database/scripts/migration-cli.php schema-only --source="CONNECTION" --target="CONNECTION" [OPTIONS]

# Data-only migration  
php database/scripts/migration-cli.php data-only --source="CONNECTION" --target="CONNECTION" [OPTIONS]

# Validate compatibility
php database/scripts/migration-cli.php validate --source="CONNECTION" --target="CONNECTION" [OPTIONS]

# Generate migration report
php database/scripts/migration-cli.php report --source="CONNECTION" --target="CONNECTION" [OPTIONS]

# Create cross-database backup
php database/scripts/migration-cli.php backup --source="CONNECTION" --target-type="DATABASE" --output="FILE" [OPTIONS]

# Restore cross-database backup
php database/scripts/migration-cli.php restore --target="CONNECTION" --backup="FILE" [OPTIONS]

# Batch migration
php database/scripts/migration-cli.php batch --config="CONFIG.json" [OPTIONS]
```

### **Migration Operations**

#### **Full Database Migration**
```bash
# SQLite to MySQL migration
php database/scripts/migration-cli.php migrate \
  --source="sqlite:./development.sqlite" \
  --target="mysql:host=localhost;dbname=production;user=app;pass=secret" \
  --validate-before-migration \
  --validate-after-migration

# PostgreSQL to SQLite migration
php database/scripts/migration-cli.php migrate \
  --source="postgresql:host=analytics.db.com;dbname=warehouse;user=analyst;pass=secret" \
  --target="sqlite:./local_analytics.sqlite" \
  --chunk-size=1000 \
  --verbose

# MySQL to PostgreSQL with custom options
php database/scripts/migration-cli.php migrate \
  --source="mysql:host=legacy.db.com;dbname=old_app;user=migrate;pass=secret" \
  --target="postgresql:host=new.db.com;dbname=new_app;user=postgres;pass=secret" \
  --exclude-tables="temp_*,log_*" \
  --memory-limit="1G"
```

#### **Schema-Only Migration**
```bash
# Migrate schema structure only
php database/scripts/migration-cli.php schema-only \
  --source="mysql:host=localhost;dbname=source;user=root;pass=secret" \
  --target="postgresql:host=localhost;dbname=target;user=postgres;pass=secret" \
  --include-indexes \
  --include-constraints

# Schema migration with exclusions
php database/scripts/migration-cli.php schema-only \
  --source="sqlite:./source.sqlite" \
  --target="mysql:host=localhost;dbname=target" \
  --exclude-tables="temp_*" \
  --no-indexes
```

#### **Data-Only Migration**
```bash
# Migrate data only (assumes schema exists)
php database/scripts/migration-cli.php data-only \
  --source="sqlite:./source.sqlite" \
  --target="mysql:host=localhost;dbname=target;user=app;pass=secret" \
  --chunk-size=5000 \
  --handle-conflicts=update

# Data migration with filtering
php database/scripts/migration-cli.php data-only \
  --source="postgresql:host=source.db.com;dbname=app" \
  --target="sqlite:./target.sqlite" \
  --include-tables="users,products,orders" \
  --validate-data-types
```

### **Migration Analysis & Reports**

#### **Validate Migration Compatibility**
```bash
# Check migration compatibility
php database/scripts/migration-cli.php validate \
  --source="sqlite:./app.sqlite" \
  --target="postgresql:host=localhost;dbname=app;user=postgres;pass=secret"

# Detailed compatibility report
php database/scripts/migration-cli.php validate \
  --source="mysql:host=localhost;dbname=legacy" \
  --target="postgresql:host=localhost;dbname=modern" \
  --verbose \
  --output=compatibility-report.json
```

#### **Generate Migration Reports**
```bash
# Generate comprehensive migration report
php database/scripts/migration-cli.php report \
  --source="sqlite:./development.sqlite" \
  --target="mysql:host=localhost;dbname=production" \
  --output=migration-report.json

# Schema analysis report
php database/scripts/migration-cli.php report \
  --source="mysql:host=localhost;dbname=app" \
  --target="postgresql:host=localhost;dbname=app" \
  --schema-only \
  --output=schema-analysis.json
```

### **Cross-Database Backup & Restore**

#### **Create Cross-Database Backup**
```bash
# Create MySQL-compatible backup from SQLite
php database/scripts/migration-cli.php backup \
  --source="sqlite:./app.sqlite" \
  --target-type=mysql \
  --output=/backups/mysql-compatible.sql \
  --include-drops \
  --if-not-exists

# Create PostgreSQL-compatible backup
php database/scripts/migration-cli.php backup \
  --source="mysql:host=localhost;dbname=app" \
  --target-type=postgresql \
  --output=/backups/postgresql-compatible.sql \
  --format-sql
```

#### **Restore Cross-Database Backup**
```bash
# Restore cross-database backup
php database/scripts/migration-cli.php restore \
  --target="postgresql:host=localhost;dbname=restored;user=postgres;pass=secret" \
  --backup="/backups/mysql-compatible.sql" \
  --validate-before-restore \
  --use-transaction
```

### **Batch Migration**

#### **Batch Configuration File** (`batch-migration.json`)
```json
{
  "global_options": {
    "stop_on_error": true,
    "chunk_size": 1000,
    "memory_limit": "512M",
    "validate_before_migration": true
  },
  "migrations": {
    "users_migration": {
      "source": "sqlite:./users.sqlite",
      "target": "mysql:host=localhost;dbname=users_prod;user=app;pass=secret",
      "options": {
        "include_tables": ["users", "user_profiles", "user_preferences"]
      }
    },
    "products_migration": {
      "source": "mysql:host=old.db.com;dbname=products;user=migrate;pass=secret",
      "target": "postgresql:host=new.db.com;dbname=products;user=postgres;pass=secret",
      "options": {
        "exclude_tables": ["temp_*", "log_*"],
        "chunk_size": 2000
      }
    }
  }
}
```

#### **Run Batch Migration**
```bash
# Execute batch migration
php database/scripts/migration-cli.php batch --config=batch-migration.json

# Batch migration with override options
php database/scripts/migration-cli.php batch \
  --config=batch-migration.json \
  --verbose \
  --continue-on-error
```

### **Migration CLI Options Reference**

| Option | Description | Example |
|--------|-------------|---------|
| `--source=CONNECTION` | Source database connection | `--source="sqlite:./app.sqlite"` |
| `--target=CONNECTION` | Target database connection | `--target="mysql:host=localhost;dbname=app"` |
| `--target-type=DATABASE` | Target database type for backup | `--target-type=postgresql` |
| `--output=FILE` | Output file path | `--output=migration-report.json` |
| `--backup=FILE` | Backup file to restore | `--backup="/backups/backup.sql"` |
| `--config=FILE` | Batch configuration file | `--config=batch-config.json` |
| `--chunk-size=NUMBER` | Records per chunk | `--chunk-size=1000` |
| `--memory-limit=SIZE` | Memory limit | `--memory-limit="1G"` |
| `--include-tables=LIST` | Include specific tables | `--include-tables="users,products"` |
| `--exclude-tables=LIST` | Exclude tables (supports wildcards) | `--exclude-tables="temp_*,log_*"` |
| `--include-indexes` | Include indexes in schema migration | `--include-indexes` |
| `--no-indexes` | Exclude indexes | `--no-indexes` |
| `--include-constraints` | Include constraints | `--include-constraints` |
| `--no-constraints` | Exclude constraints | `--no-constraints` |
| `--validate-before-migration` | Validate before starting | `--validate-before-migration` |
| `--validate-after-migration` | Validate after completion | `--validate-after-migration` |
| `--no-validate` | Skip validation | `--no-validate` |
| `--validate-data-types` | Validate data type compatibility | `--validate-data-types` |
| `--handle-conflicts=METHOD` | Handle data conflicts | `--handle-conflicts=update` |
| `--schema-only` | Schema operations only | `--schema-only` |
| `--continue-on-error` | Continue despite errors | `--continue-on-error` |
| `--verbose` | Verbose output | `--verbose` |
| `--debug` | Debug output | `--debug` |
| `--quiet` | Minimal output | `--quiet` |

---

## 📄 **SQL Dump Translator CLI** (`sql-dump-translator.php`)

Convert SQL dump files between different database types with automatic dependency sorting.

### **Usage Formats**

```bash
# Positional arguments
php database/scripts/sql-dump-translator.php INPUT.sql SOURCE_TYPE TARGET_TYPE [OUTPUT.sql]

# Named options
php database/scripts/sql-dump-translator.php -i INPUT.sql -s SOURCE_TYPE -t TARGET_TYPE [-o OUTPUT.sql]

# Mixed format
php database/scripts/sql-dump-translator.php --input=INPUT.sql mysql postgresql --output=OUTPUT.sql
```

### **Translation Operations**

#### **Basic Translation**
```bash
# MySQL to SQLite
php database/scripts/sql-dump-translator.php mysql_dump.sql mysql sqlite sqlite_output.sql

# PostgreSQL to MySQL
php database/scripts/sql-dump-translator.php postgres_dump.sql postgresql mysql mysql_output.sql

# SQLite to PostgreSQL with verbose output
php database/scripts/sql-dump-translator.php \
  --input=sqlite_dump.sql \
  --source=sqlite \
  --target=postgresql \
  --output=postgres_dump.sql \
  --verbose
```

#### **Translation with Validation**
```bash
# Validate source file only
php database/scripts/sql-dump-translator.php \
  --input=mysql_dump.sql \
  --source=mysql \
  --validate-only

# Translate with strict mode (fail on errors)
php database/scripts/sql-dump-translator.php \
  mysql_dump.sql mysql sqlite output.sql \
  --strict

# Translate with warning control
php database/scripts/sql-dump-translator.php \
  --input=complex_dump.sql \
  --source=postgresql \
  --target=mysql \
  --warnings=hide \
  --output=mysql_output.sql
```

#### **Advanced Translation Options**
```bash
# Output to stdout (pipe to other commands)
php database/scripts/sql-dump-translator.php mysql_dump.sql mysql postgresql

# Debug mode for troubleshooting
php database/scripts/sql-dump-translator.php \
  problematic_dump.sql mysql sqlite \
  --debug \
  --output=debug_output.sql

# Quiet mode for automation
php database/scripts/sql-dump-translator.php \
  source.sql mysql postgresql target.sql \
  --quiet
```

### **Translation Features**

#### **Automatic Dependency Sorting**
- Tables created in correct order based on foreign key relationships
- Handles complex dependency chains automatically
- Resolves circular dependencies when possible

#### **Cross-Database Syntax Translation**
- **AUTO_INCREMENT** ↔ **AUTOINCREMENT** ↔ **SERIAL**
- **MySQL types** → **SQLite/PostgreSQL equivalents**
- **PostgreSQL features** → **MySQL/SQLite compatibility**
- **Constraint syntax** adapted per database

#### **ALTER TABLE Support**
- Converts MySQL phpMyAdmin-style ALTER TABLE statements
- Translates ADD CONSTRAINT statements appropriately
- Handles database-specific ALTER syntax

#### **Comprehensive Error Handling**
- Validates source SQL syntax
- Reports translation issues clearly
- Continues processing when possible

### **Translation Examples**

#### **E-commerce Database Migration**
```bash
# Convert MySQL e-commerce dump to PostgreSQL
php database/scripts/sql-dump-translator.php \
  ecommerce_mysql.sql mysql postgresql \
  ecommerce_postgresql.sql \
  --verbose

# Output shows:
# Processing table dependencies...
# ✅ Translating 'users' table (no dependencies)
# ✅ Translating 'categories' table (no dependencies)  
# ✅ Translating 'products' table (depends on: categories)
# ✅ Translating 'orders' table (depends on: users)
# ✅ Translating 'order_items' table (depends on: orders, products)
# Translation completed: 5 tables, 12 constraints, 8 indexes
```

#### **Development to Production**
```bash
# Convert SQLite development DB to MySQL production
php database/scripts/sql-dump-translator.php \
  dev_app.sql sqlite mysql production_app.sql

# Translate for staging PostgreSQL environment
php database/scripts/sql-dump-translator.php \
  dev_app.sql sqlite postgresql staging_app.sql \
  --strict \
  --warnings=show
```

### **SQL Translator Options Reference**

| Option | Description | Example |
|--------|-------------|---------|
| `-i, --input=FILE` | Input SQL dump file | `--input=source.sql` |
| `-o, --output=FILE` | Output file (default: stdout) | `--output=target.sql` |
| `-s, --source=TYPE` | Source database type | `--source=mysql` |
| `-t, --target=TYPE` | Target database type | `--target=postgresql` |
| `--validate-only` | Only validate input file | `--validate-only` |
| `--strict` | Strict mode (fail on errors) | `--strict` |
| `--warnings=MODE` | Warning display (show/hide) | `--warnings=hide` |
| `-v, --verbose` | Verbose output | `--verbose` |
| `-q, --quiet` | Quiet mode | `--quiet` |
| `--debug` | Debug mode (very verbose) | `--debug` |
| `-h, --help` | Show help | `--help` |
| `--version` | Show version | `--version` |

**Supported Database Types**: `mysql`, `postgresql`, `sqlite`

---

## 🔧 **Automation & Integration**

### **Shell Script Integration**

#### **Automated Backup Script**
```bash
#!/bin/bash
# automated_backup.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/backups"
LOG_FILE="/var/log/backup.log"

# Backup multiple databases
php database/scripts/backup-cli.php backup \
  --source="mysql:host=localhost;dbname=app_production;user=backup;pass=$BACKUP_PASSWORD" \
  --output="$BACKUP_DIR/app_${DATE}.sql" \
  --compress \
  --validate-backup \
  --verbose >> "$LOG_FILE" 2>&1

# Check backup success
if [ $? -eq 0 ]; then
    echo "$(date): Backup successful - $BACKUP_DIR/app_${DATE}.sql" >> "$LOG_FILE"
    
    # Optional: Upload to cloud storage
    # aws s3 cp "$BACKUP_DIR/app_${DATE}.sql.gz" s3://backups/
else
    echo "$(date): Backup failed" >> "$LOG_FILE"
    # Send alert notification
    # mail -s "Backup Failed" admin@example.com < "$LOG_FILE"
fi
```

#### **Migration Pipeline Script**
```bash
#!/bin/bash
# migration_pipeline.sh

SOURCE="sqlite:./development.sqlite"
TARGET="postgresql:host=staging.db.com;dbname=app;user=app;pass=$DB_PASSWORD"

echo "Starting migration pipeline..."

# Step 1: Validate compatibility
php database/scripts/migration-cli.php validate --source="$SOURCE" --target="$TARGET"
if [ $? -ne 0 ]; then
    echo "Migration validation failed"
    exit 1
fi

# Step 2: Create backup of target
php database/scripts/backup-cli.php backup \
  --source="$TARGET" \
  --output="/backups/pre_migration_$(date +%Y%m%d_%H%M%S).sql"

# Step 3: Perform migration  
php database/scripts/migration-cli.php migrate \
  --source="$SOURCE" \
  --target="$TARGET" \
  --validate-before-migration \
  --validate-after-migration \
  --verbose

if [ $? -eq 0 ]; then
    echo "Migration completed successfully"
else
    echo "Migration failed - check logs"
    exit 1
fi
```

### **Cron Job Examples**

```bash
# Add to crontab (crontab -e)

# Daily backup at 2 AM
0 2 * * * /usr/bin/php /path/to/scripts/backup-cli.php backup --source="mysql:host=localhost;dbname=app" --output="/backups/daily_$(date +\%Y\%m\%d).sql" --compress >> /var/log/backup.log 2>&1

# Weekly full backup with validation
0 3 * * 0 /usr/bin/php /path/to/scripts/backup-cli.php backup --source="postgresql:host=localhost;dbname=warehouse" --output="/backups/weekly_$(date +\%Y\%m\%d).sql" --validate-backup --verbose >> /var/log/backup.log 2>&1

# Monthly migration from development to staging
0 4 1 * * /path/to/migration_pipeline.sh >> /var/log/migration.log 2>&1
```

---

## 🚨 **Error Handling & Troubleshooting**

### **Common Issues & Solutions**

#### **Connection Issues**
```bash
# Test database connection first
php database/scripts/backup-cli.php test --source="mysql:host=localhost;dbname=test;user=root;pass=secret"

# Common connection errors:
# - "Connection refused" → Check host/port
# - "Access denied" → Check username/password
# - "Unknown database" → Check database name
# - "Connection timeout" → Check network/firewall
```

#### **Permission Issues**
```bash
# Check file permissions
ls -la /backups/
chmod 755 /backups/
chmod 644 /backups/*.sql

# Check directory writable
touch /backups/test.txt && rm /backups/test.txt
```

#### **Memory Issues**
```bash
# For large databases, increase memory limit
php -d memory_limit=2G scripts/backup-cli.php backup \
  --source="mysql:host=localhost;dbname=large_db" \
  --output="/backups/large.sql" \
  --chunk-size=1000
```

#### **Strategy Issues**
```bash
# Check available strategies
php database/scripts/backup-cli.php strategies --source="sqlite:./app.sqlite"

# Force specific strategy if auto-selection fails
php database/scripts/backup-cli.php backup \
  --source="sqlite:./app.sqlite" \
  --output="/backups/backup.sql" \
  --force-strategy="sqlite_sql_dump"
```

### **Debug Mode**

Enable debug mode for detailed troubleshooting:

```bash
# Backup with debug output
php database/scripts/backup-cli.php backup \
  --source="mysql:host=localhost;dbname=app" \
  --output="/backups/debug.sql" \
  --debug

# Migration with debug output  
php database/scripts/migration-cli.php migrate \
  --source="sqlite:./source.sqlite" \
  --target="mysql:host=localhost;dbname=target" \
  --debug

# SQL translation with debug output
php database/scripts/sql-dump-translator.php \
  source.sql mysql postgresql target.sql \
  --debug
```

Debug output includes:
- Strategy selection process
- SQL statement parsing
- Connection establishment  
- Error stack traces
- Performance timing
- Memory usage

---

## 📊 **Performance Optimization**

### **Large Database Operations**

#### **Backup Optimization**
```bash
# Use compression for large backups
php database/scripts/backup-cli.php backup \
  --source="mysql:host=localhost;dbname=large_db" \
  --output="/backups/large.sql.gz" \
  --compress \
  --chunk-size=10000 \
  --timeout=7200

# Parallel processing (where supported)
php database/scripts/backup-cli.php backup \
  --source="postgresql:host=localhost;dbname=warehouse" \
  --output="/backups/parallel.backup" \
  --jobs=4 \
  --format=custom
```

#### **Migration Optimization**
```bash
# Optimize chunk size for your data
php database/scripts/migration-cli.php migrate \
  --source="sqlite:./large.sqlite" \
  --target="postgresql:host=localhost;dbname=target" \
  --chunk-size=5000 \
  --memory-limit="2G" \
  --no-validate  # Skip validation for speed
```

### **Monitoring & Logging**

#### **Performance Monitoring**
```bash
# Add timing to operations
time php database/scripts/backup-cli.php backup \
  --source="mysql:host=localhost;dbname=app" \
  --output="/backups/timed.sql"

# Monitor resource usage
/usr/bin/time -v php database/scripts/migration-cli.php migrate \
  --source="sqlite:./source.sqlite" \
  --target="mysql:host=localhost;dbname=target"
```

#### **Log Analysis**
```bash
# Analyze backup logs
grep "completed successfully" /var/log/backup.log | wc -l
grep "failed" /var/log/backup.log

# Monitor backup sizes
ls -lh /backups/*.sql | awk '{print $5, $9}'

# Check migration success rate
grep "Migration completed" /var/log/migration.log | wc -l
```

---

## 🎉 **Summary**

TronBridge CLI tools provide:

- **✅ Comprehensive Database Operations** - Backup, restore, migrate, and translate across MySQL, SQLite, PostgreSQL
- **✅ Intelligent Strategy Selection** - Automatic selection of optimal methods with graceful fallback
- **✅ Cross-Database Compatibility** - Seamless operations between different database types
- **✅ Enterprise Features** - Validation, progress tracking, error handling, and automation support
- **✅ Production Ready** - Robust error handling, logging, and monitoring capabilities
- **✅ Automation Friendly** - Perfect for scripts, cron jobs, and CI/CD pipelines

**Perfect for database administrators, developers, and DevOps teams who need reliable, scriptable database operations with cross-database compatibility.**