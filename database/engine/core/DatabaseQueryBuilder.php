<?php

/**
 * Database Query Builder - Delegates Validation to DatabaseSecurity
 * 
 * Streamlined query builder that focuses purely on SQL generation while
 * delegating all validation and escaping to DatabaseSecurity. 
 * 
 * 
 * @package Database
 * @author Enhanced Model System
 * @version 2.1.0 - Simplified
 */
abstract class QueryBuilder
{
    /**
     * Database type identifier (mysql, sqlite, postgresql)
     * 
     * @var string
     */
    protected string $dbType;

    /**
     * SQL query cache for performance optimization
     * 
     * @var array<string, string>
     */
    private static array $sqlCache = [];

    /**
     * Expression translation cache for performance
     * @var array<string, string>
     */
    private static array $translationCache = [];

    /**
     * Cache performance counters
     * 
     * @var int
     */
    private static int $cacheHits = 0;
    private static int $cacheMisses = 0;

    /**
     * Maximum number of cached queries to prevent memory bloat
     */
    private const MAX_CACHE_SIZE = 500;

    /**
     * Factory method to create database-specific query builder instances
     * 
     * Creates and caches query builder instances for optimal performance.
     * Supports MySQL, SQLite, and PostgreSQL databases.
     * 
     * @param string $dbType Database type: 'mysql', 'sqlite', 'postgresql', 'postgres', or 'pgsql'
     * @return QueryBuilder Database-specific query builder instance
     * @throws InvalidArgumentException When unsupported database type is provided
     */
    public static function create(string $dbType): QueryBuilder
    {
        static $builders = [];

        if (!isset($builders[$dbType])) {
            $builders[$dbType] = match ($dbType) {
                'mysql' => new MySQLQueryBuilder(),
                'sqlite' => new SQLiteQueryBuilder(),
                'postgresql', 'postgres', 'pgsql' => new PostgreSQLQueryBuilder(),
                default => throw new InvalidArgumentException("Unsupported database type: $dbType")
            };
        }

        return $builders[$dbType];
    }

    // =============================================================================
    // SIMPLIFIED VALIDATION METHODS - DELEGATE TO DATABASESECURITY
    // =============================================================================

    /**
     * Validate table name by delegating to DatabaseSecurity
     * 
     * @param string $tableName Table name to validate
     * @throws InvalidArgumentException If validation fails
     * @return void
     */
    public static function validateTableName(string $tableName): void
    {
        DatabaseSecurity::validateTableName($tableName);
    }

    /**
     * Validate column name by delegating to DatabaseSecurity
     * 
     * @param string $columnName Column name to validate
     * @throws InvalidArgumentException If validation fails
     * @return void
     */
    public static function validateColumnName(string $columnName): void
    {
        DatabaseSecurity::validateColumnName($columnName);
    }

    /**
     * Validate ORDER BY clause by delegating to DatabaseSecurity
     * 
     * @param string $orderBy ORDER BY clause to validate
     * @throws InvalidArgumentException If validation fails
     * @return void
     */
    public static function validateOrderBy(string $orderBy): void
    {
        DatabaseSecurity::validateOrderByColumns($orderBy);
    }

    /**
     * Validate and escape ORDER BY clause by delegating to DatabaseSecurity
     * 
     * @param string $orderBy ORDER BY clause to validate and escape
     * @param string $dbType Database type
     * @return string Validated and escaped ORDER BY clause
     */
    public static function validateAndEscapeOrderBy(string $orderBy, string $dbType): string
    {
        return DatabaseSecurity::validateOrderBy($orderBy, $dbType);
    }

    /**
     * Validate multiple identifiers by delegating to DatabaseSecurity
     * 
     * @param array $identifiers Array of identifiers to validate
     * @param string $type Type of identifier ('table', 'column', or 'identifier')
     * @throws InvalidArgumentException If any validation fails
     * @return void
     */
    public static function validateIdentifiersBulk(array $identifiers, string $type = 'identifier'): void
    {
        DatabaseSecurity::validateIdentifiersBulk($identifiers, $type);
    }

    /**
     * Escape identifier by delegating to DatabaseSecurity
     * 
     * @param string $identifier Identifier to escape
     * @param string $dbType Database type
     * @return string Escaped identifier
     * @throws InvalidArgumentException If validation fails
     */
    public static function escapeIdentifier(string $identifier, string $dbType): string
    {
        return DatabaseSecurity::escapeIdentifier($identifier, $dbType);
    }

    /**
     * Validate and escape identifier by delegating to DatabaseSecurity
     * 
     * @param string $identifier Identifier to validate and escape
     * @param string $dbType Database type
     * @param string $type Type for error messages
     * @return string Validated and escaped identifier
     */
    public static function validateAndEscape(string $identifier, string $dbType, string $type = 'identifier'): string
    {
        return DatabaseSecurity::validateAndEscape($identifier, $dbType, $type);
    }

    /**
     * Pre-warm cache by delegating to DatabaseSecurity
     * 
     * @param array $tableNames Common table names
     * @param array $columnNames Common column names
     * @param array $dbTypes Database types to pre-escape for
     * @return array Statistics about cache warming
     */
    public static function warmIdentifierCache(
        array $tableNames = [],
        array $columnNames = [],
        array $dbTypes = ['mysql', 'sqlite', 'postgresql']
    ): array {
        return DatabaseSecurity::warmCache($tableNames, $columnNames, $dbTypes);
    }

    // =============================================================================
    // SQL GENERATION METHODS (CORE FUNCTIONALITY)
    // =============================================================================

    /**
     * Build SQL query with caching for improved performance
     * 
     * Main entry point for SQL generation. Checks cache first, then generates
     * SQL directly for maximum performance. All validation is handled by
     * individual build methods through DatabaseSecurity delegation.
     * 
     * @param string $operation Query operation type ('simple_select', 'simple_insert', etc.)
     * @param array<string, mixed> $params Query parameters including table, columns, conditions
     * @return string Generated SQL query
     */
    public function buildQuery(string $operation, array $params): string
    {
        // Generate xxh3 cache key - always 16 chars, ultra-fast lookup
        $cacheKey = FastCacheKey::fromComponents([
            $this->dbType,
            $operation,
            $this->getSimpleCacheKey($params)
        ]);

        // Ultra-fast cache check with fixed-length key
        if (isset(self::$sqlCache[$cacheKey])) {
            self::$cacheHits++;
            return self::$sqlCache[$cacheKey];
        }

        self::$cacheMisses++;

        // Generate SQL
        $sql = $this->generateSQL($operation, $params);

        // Cache with xxh3 key
        self::$sqlCache[$cacheKey] = $sql;

        // Prevent cache bloat
        if (count(self::$sqlCache) > self::MAX_CACHE_SIZE) {
            self::$sqlCache = array_slice(self::$sqlCache, self::MAX_CACHE_SIZE / 2, null, true);
        }

        return $sql;
    }

    /**
     * Generate SQL directly using DatabaseSecurity for validation/escaping
     * 
     * @param string $operation Query operation type
     * @param array<string, mixed> $params Query parameters
     * @return string Generated SQL query
     */
    /**
     * Enhanced generateSQL to route expression-enabled operations
     */
    private function generateSQL(string $operation, array $params): string
    {
        return match ($operation) {
            'simple_select' => $this->buildSelect($params),
            'simple_insert' => $this->buildInsert($params),
            'insert_with_expressions' => $this->buildInsertWithExpressionSupport($params),
            'simple_update' => $this->buildUpdate($params),
            'update_with_expressions' => $this->buildUpdateWithExpressions($params),
            'update_where_with_expressions' => $this->buildUpdateWhereWithExpressions($params),
            'simple_delete' => $this->buildDelete($params),
            'bulk_insert' => $this->buildBulkInsert($params),
            'count_query' => $this->buildCount($params),
            default => $this->buildSelect($params) // Safe fallback
        };
    }

    /**
     * Generate simple cache key with minimal overhead
     * 
     * @param array<string, mixed> $params Query parameters
     * @return string Cache key string
     */
    private function getSimpleCacheKey(array $params): string
    {
        return serialize($params);
    }

    /**
     * Build SELECT SQL statement using DatabaseSecurity for validation/escaping
     * 
     * @param array<string, mixed> $params Query parameters
     * @return string Generated SELECT SQL
     */
    protected function buildSelect(array $params): string
    {
        // Validate and escape table name using DatabaseSecurity
        $table = DatabaseSecurity::validateAndEscape($params['table'], $this->dbType, 'table');
        $sql = "SELECT * FROM $table";

        // WHERE clause
        if (isset($params['where_id'])) {
            $idColumn = DatabaseSecurity::validateAndEscape('id', $this->dbType, 'column');
            $sql .= " WHERE $idColumn = :id";
        } elseif (isset($params['where_column'])) {
            $column = DatabaseSecurity::validateAndEscape($params['where_column'], $this->dbType, 'column');
            $operator = $params['where_operator'] ?? '=';
            $sql .= " WHERE $column $operator :value";
        }

        // ORDER BY (FIXED: use new ORDER BY validation)
        if (isset($params['order_by']) && !empty($params['order_by'])) {
            $orderBy = DatabaseSecurity::validateOrderBy($params['order_by'], $this->dbType);
            $sql .= " ORDER BY $orderBy";
        }

        // LIMIT
        if (isset($params['limit'])) {
            $sql .= $this->buildLimitClause((int)$params['limit'], (int)($params['offset'] ?? 0));
        }

        return $sql;
    }

    /**
     * Build INSERT SQL statement using DatabaseSecurity for validation/escaping
     * 
     * @param array<string, mixed> $params Query parameters
     * @return string Generated INSERT SQL
     */
    protected function buildInsert(array $params): string
    {
        // Validate and escape table name
        $table = DatabaseSecurity::validateAndEscape($params['table'], $this->dbType, 'table');

        // Validate and escape all columns using bulk operation
        $escapedColumns = DatabaseSecurity::validateAndEscapeBulk($params['columns'], $this->dbType, 'column');

        $placeholders = ':' . implode(', :', $params['columns']);

        return "INSERT INTO $table (" . implode(', ', $escapedColumns) . ") VALUES ($placeholders)";
    }

    /**
     * Build UPDATE SQL statement using DatabaseSecurity for validation/escaping
     * 
     * @param array<string, mixed> $params Query parameters
     * @return string Generated UPDATE SQL
     */
    protected function buildUpdate(array $params): string
    {
        // Validate and escape table name
        $table = DatabaseSecurity::validateAndEscape($params['table'], $this->dbType, 'table');

        // Build SET clauses with validated columns
        $setClauses = [];
        foreach ($params['columns'] as $column) {
            $escaped = DatabaseSecurity::validateAndEscape($column, $this->dbType, 'column');
            $setClauses[] = "$escaped = :$column";
        }

        $setClause = implode(', ', $setClauses);
        $idColumn = DatabaseSecurity::validateAndEscape('id', $this->dbType, 'column');

        return "UPDATE $table SET $setClause WHERE $idColumn = :update_id";
    }

    /**
     * Build DELETE SQL statement using DatabaseSecurity for validation/escaping
     * 
     * @param array<string, mixed> $params Query parameters
     * @return string Generated DELETE SQL
     */
    protected function buildDelete(array $params): string
    {
        $table = DatabaseSecurity::validateAndEscape($params['table'], $this->dbType, 'table');
        $idColumn = DatabaseSecurity::validateAndEscape('id', $this->dbType, 'column');

        return "DELETE FROM $table WHERE $idColumn = :id";
    }

    /**
     * Build bulk INSERT SQL statement using DatabaseSecurity for validation/escaping
     * 
     * @param array<string, mixed> $params Query parameters
     * @return string Generated bulk INSERT SQL
     */
    protected function buildBulkInsert(array $params): string
    {
        $table = DatabaseSecurity::validateAndEscape($params['table'], $this->dbType, 'table');

        $escapedColumns = DatabaseSecurity::validateAndEscapeBulk($params['columns'], $this->dbType, 'column');
        $columnList = implode(', ', $escapedColumns);

        // Build VALUES clause
        $columnCount = count($params['columns']);
        $rowCount = $params['row_count'];

        $valuePlaceholder = '(' . str_repeat('?,', $columnCount);
        $valuePlaceholder = rtrim($valuePlaceholder, ',') . ')';

        $allValues = str_repeat($valuePlaceholder . ',', $rowCount);
        $allValues = rtrim($allValues, ',');

        return "INSERT INTO $table ($columnList) VALUES $allValues";
    }

    /**
     * Build COUNT SQL statement using DatabaseSecurity for validation/escaping
     * 
     * @param array<string, mixed> $params Query parameters
     * @return string Generated COUNT SQL
     */
    protected function buildCount(array $params): string
    {
        $table = DatabaseSecurity::validateAndEscape($params['table'], $this->dbType, 'table');
        $sql = "SELECT COUNT(*) FROM $table";

        if (isset($params['where_column'])) {
            $column = DatabaseSecurity::validateAndEscape($params['where_column'], $this->dbType, 'column');
            $operator = $params['where_operator'] ?? '=';
            $sql .= " WHERE $column $operator :value";
        }

        return $sql;
    }

    // =============================================================================
    // EXPRESSION QUERY GENERATION AND TRANSLATION METHODS  
    // =============================================================================

    /**
     * Translate validated expression for database-specific syntax
     * 
     * Takes a security-validated expression and translates it for the specific
     * database type. This is where database-specific function names and syntax
     * differences are handled.
     * 
     * @param string $validatedExpression Expression that passed security validation
     * @return string Database-specific expression ready for SQL
     */
    protected function translateExpression(string $validatedExpression): string
    {
        // Generate cache key including database type
        $cacheKey = FastCacheKey::fromComponents([$this->dbType, $validatedExpression]);

        // Check translation cache
        if (isset(self::$translationCache[$cacheKey])) {
            return self::$translationCache[$cacheKey];
        }

        // Apply database-specific translation
        $translatedExpression = $this->applyDatabaseSpecificTranslation($validatedExpression);

        // Cache result
        self::$translationCache[$cacheKey] = $translatedExpression;

        return $translatedExpression;
    }

    /**
     * Database-specific translation logic
     * 
     * Must be implemented by subclasses to handle database-specific syntax
     * differences, particularly for function names and operators.
     * 
     * @param string $expression Validated expression to translate
     * @return string Database-specific expression
     */
    abstract protected function applyDatabaseSpecificTranslation(string $expression): string;
    /**
     * Build UPDATE SQL statement with expression support
     * 
     * @param array<string, mixed> $params Query parameters including expressions
     * @return string Generated UPDATE SQL with expressions
     */
    protected function buildUpdateWithExpressions(array $params): string
    {
        // Validate and escape table name
        $table = DatabaseSecurity::validateAndEscape($params['table'], $this->dbType, 'table');

        // Build SET clauses for regular columns
        $setClauses = [];

        // Regular parameter-bound columns
        if (!empty($params['columns'])) {
            foreach ($params['columns'] as $column) {
                $escaped = DatabaseSecurity::validateAndEscape($column, $this->dbType, 'column');
                $setClauses[] = "$escaped = :$column";
            }
        }

        // Expression-based columns
        if (!empty($params['expressions'])) {
            $allowedColumns = $params['allowed_columns'] ?? [];

            foreach ($params['expressions'] as $column => $expression) {
                $escapedColumn = DatabaseSecurity::validateAndEscape($column, $this->dbType, 'column');

                // Step 1: Security validation (database-agnostic)
                $validatedExpression = DatabaseSecurity::validateExpression(
                    $expression,
                    'update_set',
                    $allowedColumns
                );

                // Step 2: Database-specific translation
                $translatedExpression = $this->translateExpression($validatedExpression);

                $setClauses[] = "$escapedColumn = $translatedExpression";
            }
        }

        if (empty($setClauses)) {
            throw new InvalidArgumentException("No columns or expressions provided for UPDATE");
        }

        $setClause = implode(', ', $setClauses);
        $idColumn = DatabaseSecurity::validateAndEscape('id', $this->dbType, 'column');

        return "UPDATE $table SET $setClause WHERE $idColumn = :update_id";
    }

    /**
     * Build INSERT SQL statement with expression support
     * 
     * @param array<string, mixed> $params Query parameters including expressions
     * @return string Generated INSERT SQL with expressions
     */
    protected function buildInsertWithExpressionSupport(array $params): string
    {
        $table = DatabaseSecurity::validateAndEscape($params['table'], $this->dbType, 'table');

        $allColumns = [];
        $allValues = [];

        // Regular parameter-bound columns
        if (!empty($params['columns'])) {
            foreach ($params['columns'] as $column) {
                $escaped = DatabaseSecurity::validateAndEscape($column, $this->dbType, 'column');
                $allColumns[] = $escaped;
                $allValues[] = ":$column";
            }
        }

        // Expression-based columns  
        if (!empty($params['expressions'])) {
            $allowedColumns = $params['allowed_columns'] ?? [];

            foreach ($params['expressions'] as $column => $expression) {
                $escapedColumn = DatabaseSecurity::validateAndEscape($column, $this->dbType, 'column');

                // Step 1: Security validation (database-agnostic)
                $validatedExpression = DatabaseSecurity::validateExpression(
                    $expression,
                    'insert_value',
                    $allowedColumns
                );

                // Step 2: Database-specific translation
                $translatedExpression = $this->translateExpression($validatedExpression);

                $allColumns[] = $escapedColumn;
                $allValues[] = $translatedExpression;
            }
        }

        if (empty($allColumns)) {
            throw new InvalidArgumentException("No columns or expressions provided for INSERT");
        }

        $columnList = implode(', ', $allColumns);
        $valueList = implode(', ', $allValues);

        return "INSERT INTO $table ($columnList) VALUES ($valueList)";
    }

    /**
     * Build UPDATE WHERE SQL statement with expression support
     * 
     * @param array<string, mixed> $params Query parameters including expressions
     * @return string Generated UPDATE SQL with WHERE condition and expressions
     */
    protected function buildUpdateWhereWithExpressions(array $params): string
    {
        // Validate and escape table name
        $table = DatabaseSecurity::validateAndEscape($params['table'], $this->dbType, 'table');

        // Build SET clauses for regular columns
        $setClauses = [];

        // Regular parameter-bound columns
        if (!empty($params['columns'])) {
            foreach ($params['columns'] as $column) {
                $escaped = DatabaseSecurity::validateAndEscape($column, $this->dbType, 'column');
                $setClauses[] = "$escaped = :$column";
            }
        }

        // Expression-based columns
        if (!empty($params['expressions'])) {
            $allowedColumns = $params['allowed_columns'] ?? [];

            foreach ($params['expressions'] as $column => $expression) {
                $escapedColumn = DatabaseSecurity::validateAndEscape($column, $this->dbType, 'column');

                // Step 1: Security validation (database-agnostic)
                $validatedExpression = DatabaseSecurity::validateExpression(
                    $expression,
                    'update_set',
                    $allowedColumns
                );

                // Step 2: Database-specific translation  
                $translatedExpression = $this->translateExpression($validatedExpression);

                $setClauses[] = "$escapedColumn = $translatedExpression";
            }
        }

        if (empty($setClauses)) {
            throw new InvalidArgumentException("No columns or expressions provided for UPDATE WHERE");
        }

        $setClause = implode(', ', $setClauses);
        $whereColumn = DatabaseSecurity::validateAndEscape($params['where_column'], $this->dbType, 'column');

        return "UPDATE $table SET $setClause WHERE $whereColumn = :where_value";
    }

    // =============================================================================
    // PERFORMANCE AND MONITORING METHODS
    // =============================================================================

    /**
     * Get comprehensive performance statistics including DatabaseSecurity stats
     * 
     * @return array<string, mixed> Performance statistics
     */
    public static function getPerformanceStats(): array
    {
        $total = self::$cacheHits + self::$cacheMisses;

        return [
            'cache_hits' => self::$cacheHits,
            'cache_misses' => self::$cacheMisses,
            'cache_hit_ratio' => $total > 0 ? (self::$cacheHits / $total) * 100 : 0,
            'cached_queries' => count(self::$sqlCache),
            'sql_cache_memory_kb' => round(strlen(serialize(self::$sqlCache)) / 1024, 2),
            'database_security_stats' => DatabaseSecurity::getCacheStats(),
            'approach' => 'simplified_with_security_delegation'
        ];
    }

    /**
     * Get cache statistics for monitoring
     * 
     * @return array Cache usage statistics
     */
    public static function getCacheStats(): array
    {
        return [
            'sql_cache_size' => count(self::$sqlCache),
            'total_cache_hits' => self::$cacheHits,
            'total_cache_misses' => self::$cacheMisses,
            'hit_ratio_percentage' => self::$cacheHits + self::$cacheMisses > 0
                ? round((self::$cacheHits / (self::$cacheHits + self::$cacheMisses)) * 100, 2)
                : 0
        ];
    }

    /**
     * Clear all caches and reset performance counters
     * 
     * @return void
     */
    public static function clearCaches(): void
    {
        self::$sqlCache = [];
        self::$cacheHits = 0;
        self::$cacheMisses = 0;

        // Also clear DatabaseSecurity caches
        DatabaseSecurity::clearCaches();
    }

    // =============================================================================
    // ABSTRACT METHODS FOR DATABASE-SPECIFIC IMPLEMENTATIONS
    // =============================================================================

    /**
     * Build database-specific LIMIT clause with optional OFFSET
     * 
     * @param int $limit Maximum number of rows to return
     * @param int $offset Number of rows to skip (default: 0)
     * @return string Database-specific LIMIT clause
     */
    abstract protected function buildLimitClause(int $limit, int $offset): string;

    /**
     * Apply database-specific optimizations to PDO connection
     * 
     * @param PDO $dbh Database connection handle
     * @return void
     */
    abstract public function applyOptimizations(PDO $dbh): void;
}

/**
 * MySQL Query Builder - Database-specific implementation
 */
class MySQLQueryBuilder extends QueryBuilder
{
    protected string $dbType = 'mysql';

    protected function buildLimitClause(int $limit, int $offset): string
    {
        return $offset > 0 ? " LIMIT $offset, $limit" : " LIMIT $limit";
    }

    public function applyOptimizations(PDO $dbh): void
    {
        $dbh->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        //$dbh->exec("SET SESSION query_cache_type = ON");
        //$dbh->exec("SET SESSION innodb_flush_log_at_trx_commit = 2");
    }

    protected function applyDatabaseSpecificTranslation(string $expression): string
    {


        // MySQL translations for PostgreSQL-style and other database functions
        // CRITICAL: Order from LONGEST to SHORTEST to prevent substring conflicts!
        $translations = [
            // Date/time functions - LONGEST FIRST
            'CURRENT_TIMESTAMP' => 'NOW()',      // Must come before CURRENT_TIME
            'CURRENT_DATE' => 'CURDATE()',       // Must come before CURRENT_TIME
            'CURRENT_TIME' => 'CURTIME()',       // Must come after longer matches

            // String functions  
            'SUBSTR(' => 'SUBSTRING(',  // PostgreSQL/SQLite style to MySQL

            // Mathematical functions
            'RANDOM()' => 'RAND()', // PostgreSQL/SQLite style to MySQL
        ];

        return str_ireplace(array_keys($translations), array_values($translations), $expression);
    }
}

/**
 * SQLite Query Builder - Database-specific implementation
 */
class SQLiteQueryBuilder extends QueryBuilder
{
    protected string $dbType = 'sqlite';

    protected function buildLimitClause(int $limit, int $offset): string
    {
        return $offset > 0 ? " LIMIT $limit OFFSET $offset" : " LIMIT $limit";
    }

    public function applyOptimizations(PDO $dbh): void
    {
        $dbh->exec('PRAGMA foreign_keys = ON');
        $dbh->exec('PRAGMA journal_mode = WAL');
        $dbh->exec('PRAGMA synchronous = NORMAL');
        $dbh->exec('PRAGMA cache_size = -20000'); // 20MB cache
        $dbh->exec('PRAGMA temp_store = MEMORY');
        $dbh->exec('PRAGMA busy_timeout = 5000');
    }

    protected function applyDatabaseSpecificTranslation(string $expression): string
    {
        // SQLite-specific function translations
        // CRITICAL: Order from LONGEST to SHORTEST to prevent substring conflicts!
        $translations = [
            'CURRENT_TIMESTAMP' => "datetime('now')",
            'CURRENT_DATE' => "date('now')",
            'CURRENT_TIME' => "time('now')",
            'NOW()' => "datetime('now')",
            'CURDATE()' => "date('now')",
            'CURTIME()' => "time('now')",
            'SUBSTRING(' => 'SUBSTR(',
            'RAND()' => 'RANDOM()',
        ];

        return str_ireplace(array_keys($translations), array_values($translations), $expression);
    }
}

/**
 * PostgreSQL Query Builder - Database-specific implementation
 */
class PostgreSQLQueryBuilder extends QueryBuilder
{
    protected string $dbType = 'postgresql';

    protected function buildLimitClause(int $limit, int $offset): string
    {
        return $offset > 0 ? " LIMIT $limit OFFSET $offset" : " LIMIT $limit";
    }

    public function applyOptimizations(PDO $dbh): void
    {
        $dbh->exec("SET statement_timeout = 30000");
        $dbh->exec("SET lock_timeout = 5000");
        $dbh->exec("SET synchronous_commit = off");
        //$dbh->exec("SET wal_buffers = '16MB'");
        //$dbh->exec("SET checkpoint_completion_target = 0.9");
        $dbh->exec("SET effective_cache_size = '1GB'");
    }

    protected function applyDatabaseSpecificTranslation(string $expression): string
    {
        // PostgreSQL translations for MySQL-style and other database functions
        $translations = [
            // Date/time functions
            'CURDATE()' => 'CURRENT_DATE',
            'CURTIME()' => 'CURRENT_TIME',
            'CURRENT_TIMESTAMP()' => 'CURRENT_TIMESTAMP',

            // String functions
            'SUBSTR(' => 'SUBSTRING(',   // SQLite style to PostgreSQL

            // Mathematical functions
            'RAND()' => 'RANDOM()',      // MySQL style to PostgreSQL

            // Note: NOW(), SUBSTRING(), RANDOM() are already standard in PostgreSQL
        ];

        return str_ireplace(array_keys($translations), array_values($translations), $expression);
    }
}
