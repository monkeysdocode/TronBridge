<?php

/**
 * Extracts schema information directly from a live database connection.
 *
 * The SchemaExtractor is responsible for introspecting a live database
 * (MySQL, PostgreSQL, or SQLite) and extracting its complete schema metadata.
 * It connects to the database and queries its system catalogs (e.g.,
 * INFORMATION_SCHEMA, PRAGMA tables) to gather detailed information about
 * tables, columns, indexes, constraints, and triggers.
 *
 * This class serves as the starting point for a "live migration" workflow,
 * acting as an alternative to the SchemaDumpExtractor (which reads from a .sql file).
 * The structured array returned by this class can be used to build schema
 * objects for further processing, such as translation to another database
 * platform.
 *
 * Key Responsibilities:
 * - Connecting to a live database via a PDO object.
 * - Executing platform-specific queries to retrieve schema metadata.
 * - Extracting details for tables, columns, indexes, and constraints.
 * - Consolidating the extracted information into a structured PHP array.
 *
 * @package Database\Schema\Core
 * @author Enhanced Model System
 * @version 2.0.0
 */
class SchemaExtractor
{
    protected $debugCallback = null;
    private array $extractionCache = [];

    public function __construct() {}

    /**
     * Set debug callback
     */
    public function setDebugCallback(?callable $callback): void
    {
        $this->debugCallback = $callback;
    }

    /**
     * Extract complete database schema
     */
    public function extractFullSchema($model): array
    {
        $pdo = $model->getPDO();
        $databaseType = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        $this->debug("Extracting full schema", ['database_type' => $databaseType]);

        // Get all table names using proven methods from backup strategies
        $tableNames = $this->getTableNames($pdo, $databaseType);
        $this->debug("Found tables", ['count' => count($tableNames), 'tables' => $tableNames]);

        $schema = [];
        foreach ($tableNames as $tableName) {
            $schema[$tableName] = $this->extractTable($model, $tableName);
        }

        return $schema;
    }

    /**
     * Extract single table schema
     */
    public function extractTable($model, string $tableName): ?array
    {
        $pdo = $model->getPDO();
        $databaseType = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        $cacheKey = $databaseType . '_' . $tableName;
        if (isset($this->extractionCache[$cacheKey])) {
            return $this->extractionCache[$cacheKey];
        }

        $this->debug("Extracting table schema", ['table' => $tableName, 'type' => $databaseType]);

        try {
            $tableSchema = [
                'name' => $tableName,
                'columns' => $this->getTableColumns($pdo, $tableName, $databaseType),
                'indexes' => $this->getTableIndexes($pdo, $tableName, $databaseType),
                'constraints' => $this->getTableConstraints($pdo, $tableName, $databaseType),
                'triggers' => $this->getTableTriggers($pdo, $tableName, $databaseType),
                'row_count' => $this->getTableRowCount($pdo, $tableName, $databaseType),
                'metadata' => $this->getTableMetadata($pdo, $tableName, $databaseType)
            ];

            $this->extractionCache[$cacheKey] = $tableSchema;
            return $tableSchema;
        } catch (Exception $e) {
            $this->debug("Failed to extract table schema", [
                'table' => $tableName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get table names using proven methods from backup strategies
     */
    public function getTableNames(PDO $pdo, string $databaseType): array
    {
        switch ($databaseType) {
            case 'mysql':
                return $this->getMySQLTableNames($pdo);
            case 'postgresql':
                return $this->getPostgreSQLTableNames($pdo);
            case 'sqlite':
                return $this->getSQLiteTableNames($pdo);
            default:
                throw new Exception("Unsupported database type: $databaseType");
        }
    }

    /**
     * MySQL table names
     */
    public function getMySQLTableNames(PDO $pdo): array
    {
        // Get database name first
        $databaseName = $pdo->query("SELECT DATABASE()")->fetchColumn();

        $sql = "
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = ? 
            AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$databaseName]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * PostgreSQL table names (from PostgreSQLPHPBackupStrategy)
     */
    private function getPostgreSQLTableNames(PDO $pdo): array
    {
        $sql = "SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_type = 'BASE TABLE' 
            ORDER BY table_name";

        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * SQLite table names (from SQLiteSQLDumpStrategy)
     */
    private function getSQLiteTableNames(PDO $pdo): array
    {
        $sql = "
            SELECT name 
            FROM sqlite_master 
            WHERE type = 'table' 
            AND name NOT LIKE 'sqlite_%'
            ORDER BY name
        ";

        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get table columns with detailed information
     */
    public function getTableColumns(PDO $pdo, string $tableName, string $databaseType): array
    {
        switch ($databaseType) {
            case 'mysql':
                return $this->getMySQLColumns($pdo, $tableName);
            case 'postgresql':
                return $this->getPostgreSQLColumns($pdo, $tableName);
            case 'sqlite':
                return $this->getSQLiteColumns($pdo, $tableName);
            default:
                return [];
        }
    }

    /**
     * MySQL column information (proven from backup strategy)
     */
    private function getMySQLColumns(PDO $pdo, string $tableName): array
    {
        // Get database name
        $databaseName = $pdo->query("SELECT DATABASE()")->fetchColumn();

        $sql = "
            SELECT 
                COLUMN_NAME as name,
                DATA_TYPE as data_type,        -- Base type for conversion: 'int', 'varchar'
                COLUMN_TYPE as full_type,      -- Full type with length: 'int(11)', 'varchar(255)'
                IS_NULLABLE as nullable,
                COLUMN_DEFAULT as default_value,
                EXTRA as extra,
                COLUMN_COMMENT as comment,
                COLLATION_NAME as collation,
                NUMERIC_PRECISION as numeric_precision,
                NUMERIC_SCALE as numeric_scale,
                CHARACTER_MAXIMUM_LENGTH as length
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$databaseName, $tableName]);
        $columns = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = [
                'name' => $row['name'],
                'type' => $row['data_type'],           // Use DATA_TYPE for schema conversion
                'full_type' => $row['full_type'],      // Keep COLUMN_TYPE for reference
                'data_type' => $row['data_type'],      // Explicit data_type field
                'null' => $row['nullable'] === 'YES',
                'default' => $row['default_value'],
                'extra' => $row['extra'],
                'comment' => $row['comment'],
                'collation' => $row['collation'],
                'precision' => $row['numeric_precision'],
                'scale' => $row['numeric_scale'],
                'length' => $row['length'],
                'auto_increment' => strpos($row['extra'], 'auto_increment') !== false,
                'primary_key' => $this->isMySQLPrimaryKey($pdo, $databaseName, $tableName, $row['name'])
            ];
        }

        return $columns;
    }

    /**
     * Check if column is primary key in MySQL
     */
    private function isMySQLPrimaryKey(PDO $pdo, string $databaseName, string $tableName, string $columnName): bool
    {
        $sql = "
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = ? 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ? 
            AND CONSTRAINT_NAME = 'PRIMARY'
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$databaseName, $tableName, $columnName]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * PostgreSQL column information (proven from backup strategy)
     */
    private function getPostgreSQLColumns(PDO $pdo, string $tableName): array
    {
        $sql = "SELECT 
                c.column_name,
                c.data_type,
                c.udt_name,
                c.character_maximum_length,
                c.numeric_precision,
                c.numeric_scale,
                c.is_nullable,
                c.column_default,
                c.ordinal_position,
                -- Array type detection
                CASE WHEN c.data_type = 'ARRAY' THEN true ELSE false END as is_array,
                -- SERIAL detection  
                CASE WHEN c.column_default LIKE 'nextval%' THEN true ELSE false END as is_serial,
                -- Extract sequence name
                CASE 
                    WHEN c.column_default LIKE 'nextval%' THEN 
                        substring(c.column_default from '''(.+?)''')
                    ELSE null
                END as sequence_name
            FROM information_schema.columns c
            WHERE c.table_name = :tableName 
            AND c.table_schema = 'public'
            ORDER BY c.ordinal_position";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tableName', $tableName);
        $stmt->execute();
        $columns = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = [
                'name' => $row['column_name'],
                'type' => $row['data_type'],
                'null' => $row['is_nullable'] === 'YES',
                'default' => $row['column_default'],
                'comment' => $row['comment'],
                'max_length' => $row['character_maximum_length'],
                'precision' => $row['numeric_precision'],
                'scale' => $row['numeric_scale'],
                'primary_key' => $this->isPostgreSQLPrimaryKey($pdo, $tableName, $row['column_name']),
                'auto_increment' => strpos($row['column_default'], 'nextval') !== false
            ];
        }

        return $columns;
    }

    /**
     * Check if column is primary key in PostgreSQL
     */
    private function isPostgreSQLPrimaryKey(PDO $pdo, string $tableName, string $columnName): bool
    {
        $sql = "
            SELECT COUNT(*)
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu 
            ON tc.constraint_name = kcu.constraint_name
            WHERE tc.constraint_type = 'PRIMARY KEY'
            AND tc.table_name = ?
            AND kcu.column_name = ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tableName, $columnName]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * SQLite column information (from SQLiteSQLDumpStrategy - proven working)
     */
    private function getSQLiteColumns(PDO $pdo, string $tableName): array
    {
        $sql = "PRAGMA table_info(`$tableName`)";
        $stmt = $pdo->query($sql);
        $columns = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = [
                'name' => $row['name'],
                'type' => $row['type'],
                'null' => !$row['notnull'],
                'default' => $row['dflt_value'],
                'primary_key' => $row['pk'] > 0,
                'auto_increment' => $this->isSQLiteAutoIncrement($pdo, $tableName, $row['name'])
            ];
        }

        return $columns;
    }

    /**
     * Check if SQLite column is auto increment (from backup strategy)
     */
    private function isSQLiteAutoIncrement(PDO $pdo, string $tableName, string $columnName): bool
    {
        $sql = "SELECT sql FROM sqlite_master WHERE type='table' AND name=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tableName]);
        $createSQL = $stmt->fetchColumn();

        if ($createSQL) {
            return stripos($createSQL, $columnName) !== false &&
                stripos($createSQL, "AUTOINCREMENT") !== false;
        }

        return false;
    }

    /**
     * Get table indexes using proven methods from backup strategies
     */
    public function getTableIndexes(PDO $pdo, string $tableName, string $databaseType): array
    {
        switch ($databaseType) {
            case 'mysql':
                return $this->getMySQLIndexes($pdo, $tableName);
            case 'postgresql':
                return $this->getPostgreSQLIndexes($pdo, $tableName);
            case 'sqlite':
                return $this->getSQLiteIndexes($pdo, $tableName);
            default:
                return [];
        }
    }

    /**
     * MySQL indexes (proven method)
     */
    private function getMySQLIndexes(PDO $pdo, string $tableName): array
    {
        $sql = "SHOW INDEX FROM `$tableName`";
        $stmt = $pdo->query($sql);
        $indexes = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $indexName = $row['Key_name'];
            if ($indexName === 'PRIMARY') continue; // Skip primary key

            if (!isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'name' => $indexName,
                    'unique' => !$row['Non_unique'],
                    'columns' => []
                ];
            }

            $indexes[$indexName]['columns'][] = [
                'name' => $row['Column_name'],
                'order' => $row['Collation'] === 'A' ? 'ASC' : 'DESC',
                'length' => $row['Sub_part']
            ];
        }

        return array_values($indexes);
    }

    /**
     * PostgreSQL indexes (proven method)
     */
    private function getPostgreSQLIndexes(PDO $pdo, string $tableName): array
    {
        $sql = "
            SELECT 
                i.relname as index_name,
                ix.indisunique as is_unique,
                string_agg(a.attname, ',' ORDER BY c.ordinality) as columns
            FROM pg_class t
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS c(colnum, ordinality)
            JOIN pg_attribute a ON t.oid = a.attrelid AND a.attnum = c.colnum
            WHERE t.relname = ? AND NOT ix.indisprimary
            GROUP BY i.relname, ix.indisunique
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tableName]);
        $indexes = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $indexes[] = [
                'name' => $row['index_name'],
                'unique' => $row['is_unique'],
                'columns' => array_map(function ($col) {
                    return ['name' => trim($col), 'order' => 'ASC'];
                }, explode(',', $row['columns']))
            ];
        }

        return $indexes;
    }

    /**
     * SQLite indexes (from SQLiteSQLDumpStrategy - proven working)
     */
    private function getSQLiteIndexes(PDO $pdo, string $tableName): array
    {
        $sql = "PRAGMA index_list(`$tableName`)";
        $stmt = $pdo->query($sql);
        $indexes = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['origin'] === 'pk') continue; // Skip primary key

            $indexName = $row['name'];
            $indexInfo = [
                'name' => $indexName,
                'unique' => $row['unique'],
                'columns' => []
            ];

            // Get index columns
            $colSQL = "PRAGMA index_info(`$indexName`)";
            $colStmt = $pdo->query($colSQL);
            while ($colRow = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $indexInfo['columns'][] = [
                    'name' => $colRow['name'],
                    'order' => 'ASC' // SQLite doesn't store sort order in PRAGMA
                ];
            }

            $indexes[] = $indexInfo;
        }

        return $indexes;
    }

    /**
     * Get table constraints using proven methods
     */
    public function getTableConstraints(PDO $pdo, string $tableName, string $databaseType): array
    {
        switch ($databaseType) {
            case 'mysql':
                return $this->getMySQLConstraints($pdo, $tableName);
            case 'postgresql':
                return $this->getPostgreSQLConstraints($pdo, $tableName);
            case 'sqlite':
                return $this->getSQLiteConstraints($pdo, $tableName);
            default:
                return [];
        }
    }

    /**
     * MySQL constraints (fixed to use correct tables)
     */
    private function getMySQLConstraints(PDO $pdo, string $tableName): array
    {
        $databaseName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $constraints = [];

        // Get foreign keys using the correct join
        $sql = "
            SELECT 
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = ? 
            AND kcu.TABLE_NAME = ?
            AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$databaseName, $tableName]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $constraints[] = [
                'name' => $row['CONSTRAINT_NAME'],
                'type' => 'foreign_key',
                'column' => $row['COLUMN_NAME'],
                'references_table' => $row['REFERENCED_TABLE_NAME'],
                'references_column' => $row['REFERENCED_COLUMN_NAME'],
                'on_update' => $row['UPDATE_RULE'],
                'on_delete' => $row['DELETE_RULE']
            ];
        }

        return $constraints;
    }

    /**
     * PostgreSQL constraints
     */
    private function getPostgreSQLConstraints(PDO $pdo, string $tableName): array
    {
        $constraints = [];

        // Get foreign keys
        $sql = "SELECT 
                tc.constraint_name,
                kcu.column_name,
                ccu.table_name AS foreign_table_name,
                ccu.column_name AS foreign_column_name,
                rc.update_rule,
                rc.delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu 
                ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage ccu 
                ON ccu.constraint_name = tc.constraint_name
            JOIN information_schema.referential_constraints rc 
                ON tc.constraint_name = rc.constraint_name
            WHERE tc.table_name = :tableName 
            AND tc.table_schema = 'public'
            AND tc.constraint_type = 'FOREIGN KEY'";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tableName', $tableName);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $constraints[] = [
                'name' => $row['constraint_name'],
                'type' => 'foreign_key',
                'column' => $row['column_name'],
                'references_table' => $row['foreign_table_name'],
                'references_column' => $row['foreign_column_name'],
                'on_update' => $row['update_rule'],
                'on_delete' => $row['delete_rule']
            ];
        }

        return $constraints;
    }

    /**
     * SQLite constraints (from SQLiteSQLDumpStrategy - proven working)
     */
    private function getSQLiteConstraints(PDO $pdo, string $tableName): array
    {
        $constraints = [];

        // Get foreign keys
        $sql = "PRAGMA foreign_key_list(`$tableName`)";
        $stmt = $pdo->query($sql);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $constraints[] = [
                'name' => "fk_{$tableName}_{$row['from']}_{$row['table']}_{$row['to']}",
                'type' => 'foreign_key',
                'column' => $row['from'],
                'references_table' => $row['table'],
                'references_column' => $row['to'],
                'on_update' => $row['on_update'],
                'on_delete' => $row['on_delete']
            ];
        }

        return $constraints;
    }

    /**
     * Get table triggers using proven methods
     */
    public function getTableTriggers(PDO $pdo, string $tableName, string $databaseType): array
    {
        switch ($databaseType) {
            case 'mysql':
                return $this->getMySQLTriggers($pdo, $tableName);
            case 'postgresql':
                return $this->getPostgreSQLTriggers($pdo, $tableName);
            case 'sqlite':
                return $this->getSQLiteTriggers($pdo, $tableName);
            default:
                return [];
        }
    }

    /**
     * MySQL triggers
     */
    private function getMySQLTriggers(PDO $pdo, string $tableName): array
    {
        $databaseName = $pdo->query("SELECT DATABASE()")->fetchColumn();

        $sql = "
            SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, 
                       ACTION_STATEMENT, ACTION_ORIENTATION
                FROM INFORMATION_SCHEMA.TRIGGERS 
                WHERE EVENT_OBJECT_SCHEMA = ? 
                AND EVENT_OBJECT_TABLE = ?
                ORDER BY TRIGGER_NAME
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$databaseName, $tableName]);

        $triggers = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $triggers[] = [
                'name' => $row['TRIGGER_NAME'],
                'timing' => $row['ACTION_TIMING'],
                'event' => $row['EVENT_MANIPULATION'],
                'statement' => $row['ACTION_STATEMENT']
            ];
        }

        return $triggers;
    }

    /**
     * PostgreSQL triggers
     */
    private function getPostgreSQLTriggers(PDO $pdo, string $tableName): array
    {
        $sql = "
            SELECT trigger_name, action_timing, event_manipulation, action_statement
            FROM information_schema.triggers
            WHERE table_name = ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tableName]);

        $triggers = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $triggers[] = [
                'name' => $row['trigger_name'],
                'timing' => $row['action_timing'],
                'event' => $row['event_manipulation'],
                'statement' => $row['action_statement']
            ];
        }

        return $triggers;
    }

    /**
     * SQLite triggers (from SQLiteSQLDumpStrategy - proven working)
     */
    private function getSQLiteTriggers(PDO $pdo, string $tableName): array
    {
        $sql = "
            SELECT name, sql
            FROM sqlite_master 
            WHERE type = 'trigger' 
            AND tbl_name = ?
            AND sql IS NOT NULL
            ORDER BY name
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tableName]);

        $triggers = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $triggers[] = [
                'name' => $row['name'],
                'sql' => $row['sql']
            ];
        }

        return $triggers;
    }

    /**
     * Get table row count
     */
    public function getTableRowCount(PDO $pdo, string $tableName, string $databaseType): int
    {
        try {
            // Quote table name appropriately for each database type
            switch ($databaseType) {
                case 'mysql':
                    $quotedName = "`$tableName`";
                    break;
                case 'postgresql':
                    $quotedName = "\"$tableName\"";
                    break;
                case 'sqlite':
                    $quotedName = "`$tableName`";
                    break;
                default:
                    $quotedName = $tableName;
            }

            $sql = "SELECT COUNT(*) FROM $quotedName";
            $stmt = $pdo->query($sql);
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            $this->debug("Failed to get row count for table $tableName", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get table metadata
     */
    public function getTableMetadata(PDO $pdo, string $tableName, string $databaseType): array
    {
        $metadata = [
            'database_type' => $databaseType,
            'extraction_time' => date('Y-m-d H:i:s')
        ];

        // Add database-specific metadata
        switch ($databaseType) {
            case 'mysql':
                $metadata = array_merge($metadata, $this->getMySQLTableMetadata($pdo, $tableName));
                break;
            case 'postgresql':
                $metadata = array_merge($metadata, $this->getPostgreSQLTableMetadata($pdo, $tableName));
                break;
            case 'sqlite':
                $metadata = array_merge($metadata, $this->getSQLiteTableMetadata($pdo, $tableName));
                break;
        }

        return $metadata;
    }

    /**
     * MySQL table metadata
     */
    private function getMySQLTableMetadata(PDO $pdo, string $tableName): array
    {
        try {
            $databaseName = $pdo->query("SELECT DATABASE()")->fetchColumn();

            $sql = "
                SELECT ENGINE, TABLE_COLLATION, TABLE_COMMENT
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$databaseName, $tableName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'engine' => $result['ENGINE'] ?? null,
                'collation' => $result['TABLE_COLLATION'] ?? null,
                'comment' => $result['TABLE_COMMENT'] ?? null
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * PostgreSQL table metadata
     */
    private function getPostgreSQLTableMetadata(PDO $pdo, string $tableName): array
    {
        try {
            $sql = "
                SELECT obj_description(c.oid) as comment
                FROM pg_class c
                WHERE c.relname = ?
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tableName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'comment' => $result['comment'] ?? null
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * SQLite table metadata
     */
    private function getSQLiteTableMetadata(PDO $pdo, string $tableName): array
    {
        try {
            $sql = "SELECT sql FROM sqlite_master WHERE type='table' AND name=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tableName]);
            $createSQL = $stmt->fetchColumn();

            return [
                'create_sql' => $createSQL,
                'version' => $pdo->query("SELECT sqlite_version()")->fetchColumn()
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Clear extraction cache
     */
    public function clearCache(): void
    {
        $this->extractionCache = [];
    }

    /**
     * Debug logging
     */
    private function debug(string $message, array $context = []): void
    {
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, "[Schema Extractor] $message", $context);
        }
    }
}
