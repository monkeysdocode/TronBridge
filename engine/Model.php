<?php

require_once dirname(__DIR__) . '/database/engine/core/DatabaseConfig.php';
require_once dirname(__DIR__) . '/database/engine/core/DatabaseSecurity.php';
require_once dirname(__DIR__) . '/database/engine/core/DatabaseQueryBuilder.php';
require_once dirname(__DIR__) . '/database/engine/debug/DebugConstants.php';


/**
 * Enhanced Model with QueryBuilder Integration
 * 
 * Key improvements:
 * - Uses QueryBuilder static methods for all identifier validation and escaping
 * - Eliminates duplicate caching logic
 * - Consolidated identifier handling in one place
 * - Maintains all performance optimizations
 * - Zero breaking changes to public API
 */
class Model
{
    private PDO $dbh;
    private PDOStatement $stmt;
    private string $dbType;
    private DatabaseConfig $config;
    private QueryBuilder $queryBuilder;
    private ?string $current_module;
    private bool $debug = false;
    private ?DebugCollector $debugCollector = null;
    private bool $connected = false;
    private array $options = [];

    // Performance caches
    private static array $preparedStatements = [];
    private static array $cachedSQL = [];

    // Factory methods
    private ?DatabaseBackupFactory $backupHelper = null;
    private ?DatabasePerformance $performanceHelper = null;
    private ?DatabaseMigrationFactory $migrationFactory = null;
    private ?SQLDumpTranslator $sqlTranslator = null;

    // Simple error handling
    private ?string $lastError = null;
    private string $query_caveat = '* Enhanced Model Query';

    // Transaction support 
    /**
     * Transaction nesting level counter (per instance)
     * @var int
     */
    private int $transactionLevel = 0;

    /**
     * Savepoint counter for nested transactions (per instance)
     * @var int
     */
    private int $savepointCounter = 0;

    /**
     * Transaction start time for timeout detection (per instance)
     * @var float|null
     */
    private ?float $transactionStartTime = null;

    /**
     * Maximum transaction duration in seconds
     */
    private const MAX_TRANSACTION_DURATION = 300; // 5 minutes

    /**
     * Enhanced Model constructor with intelligent mode selection and multi-database support
     * 
     * Creates a Model instance with configurable optimization modes and database connections.
     * Supports two primary modes:
     * 
     * **Lightweight Mode (default)**: Optimized for per-request performance
     * - Minimal initialization overhead
     * - Best for typical web applications
     * - 69% faster initialization for simple operations
     * 
     * **Full Mode**: Optimized for bulk operations and long-running processes
     * - Advanced performance tracking and optimization
     * - Auto-bulk detection and adaptive thresholds
     * - Best for CLI scripts, background jobs, data processing
     * 
     * **Database Support**: MySQL, SQLite, PostgreSQL with automatic driver detection
     * 
     * @param string|null $current_module Module name for automatic table resolution
     * @param PDO|string|array|null $connection Database connection or configuration:
     *   - PDO: Direct database connection (auto-detects type)
     *   - string: Connection string ("mysql:host=localhost;dbname=test", "sqlite:/path/to/db")
     *   - array: Configuration array (['type' => 'postgresql', 'host' => 'localhost', ...])
     *   - null: Use global constants (traditional Trongate approach)
     * @param array $options Configuration options:
     *   - lightweightMode: bool (default: true) - Enable lightweight mode for per-request performance
     *   - debug: bool (default: false) - Enable SQL query debugging and performance monitoring
     * 
     * @throws InvalidArgumentException If database configuration is invalid
     * @throws RuntimeException If database connection fails
     * 
     * @example
     * // Default lightweight mode for web applications
     * $model = new Model('users');
     * 
     * @example
     * // Full mode for bulk operations
     * $model = new Model('products', null, ['lightweightMode' => false]);
     * 
     * @example
     * // PostgreSQL with custom connection
     * $model = new Model('analytics', 'postgresql:host=localhost;dbname=analytics;user=app;pass=secret');
     * 
     * @example
     * // SQLite with debugging enabled
     * $model = new Model('cache', 'sqlite:/tmp/cache.db', ['debug' => true]);
     */
    public function __construct(?string $current_module = null, $connection = null, array $options = [])
    {
        $this->current_module = $current_module;

        $defaultOptions = [
            'lightweightMode' => true,
            'debug' => false,
            'bulkThreshold' => 50,      // Simple threshold
            'autoOptimize' => true      // Auto-enable bulk optimizations
        ];

        $this->options = array_merge($defaultOptions, $options);

        // Standard setup
        $this->setupConfiguration($connection);
        $this->dbType = $this->config->getType();
        $this->queryBuilder = QueryBuilder::create($this->dbType);

        if (isset($this->options['debug'])) {
            $this->debug = $this->options['debug'];
        }
    }

    // =============================================================================
    // CONFIGURATION METHODS
    // =============================================================================

    /**
     * Default: Optimized for web applications
     */
    public static function createForWeb(?string $current_module = null, $connection = null, array $additionalOptions = []): self
    {
        $options = array_merge([
            'lightweightMode' => true,
            'bulkThreshold' => 50,    // Still auto-optimize bulk operations
            'autoOptimize' => true,   // But no complex learning
            'debug' => false
        ], $additionalOptions);

        return new self($current_module, $connection, $options);
    }

    /**
     * For bulk operations - lower threshold, more aggressive optimization
     */
    public static function createForBulk(?string $current_module = null, $connection = null, array $additionalOptions = []): self
    {
        $options = array_merge([
            'lightweightMode' => false,  // Enable all optimizations
            'bulkThreshold' => 25,       // Lower threshold for bulk
            'autoOptimize' => true,
            'debug' => false
        ], $additionalOptions);

        return new self($current_module, $connection, $options);
    }

    /**
     * For long-running processes - immediate optimization
     */
    public static function createForLongRunning(?string $current_module = null, $connection = null, array $additionalOptions = []): self
    {
        $options = array_merge([
            'lightweightMode' => false,
            'bulkThreshold' => 1,        // Immediate optimization
            'autoOptimize' => true,
            'debug' => false
        ], $additionalOptions);

        return new self($current_module, $connection, $options);
    }


    // =============================================================================
    // PUBLIC API METHODS (USING QUERYBUILDER STATIC METHODS)
    // =============================================================================

    /**
     * Get all records from table with optional ordering and pagination
     * 
     * High-performance record retrieval with QueryBuilder validation, intelligent
     * SQL caching, and comprehensive result control. Supports flexible ordering
     * and pagination for large datasets.
     * 
     * @param string|null $order_by Column name and direction for ORDER BY (e.g., 'id desc', 'name asc')
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @param int|null $limit Maximum number of records to return
     * @param int $offset Number of records to skip for pagination (default: 0)
     * @return array Array of database records as objects
     * @throws InvalidArgumentException If table name or ORDER BY validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get all users ordered by ID descending
     * $users = $model->get('id desc', 'users');
     * 
     * @example
     * // Get latest 10 posts with pagination
     * $posts = $model->get('created_at desc', 'posts', 10, 20);
     * 
     * @example
     * // Multiple column ordering
     * $products = $model->get('category_id asc, price desc', 'products');
     */
    public function get(?string $order_by = null, ?string $target_tbl = null, ?int $limit = null, int $offset = 0): array
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_tbl);

        if ($order_by) {
            QueryBuilder::validateOrderBy($order_by);
        }

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'order_by' => $order_by,
            'limit' => $limit,
            'offset' => $offset
        ]);

        $this->debugLog("SQL query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql,
            'order_by' => $order_by,
            'limit' => $limit,
            'offset' => $offset
        ]);

        $stmt = $this->getPreparedStatement($sql);
        $this->executeStatement($stmt);
        $results = $stmt->fetchAll();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            // Basic operation logging
            $this->debugLog("SELECT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                'table' => $table,
                'sql' => $sql,
                'params' => [],
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2),
                'result_count' => count($results),
                'order_by' => $order_by,
                'limit' => $limit,
                'offset' => $offset,
                'operation' => 'select'
            ]);

            // Advanced query analysis
            $this->debug()->analyzeQuery($sql, [], $executionTime, [
                'table' => $table,
                'operation' => 'select',
                'result_count' => count($results),
                'has_order_by' => $order_by !== null,
                'has_limit' => $limit !== null,
                'large_result_set' => count($results) > 1000
            ]);
        }

        return $results;
    }

    /**
     * Retrieve single record by ID with optimized prepared statement caching
     * 
     * Ultra-fast record lookup by primary key using QueryBuilder validation
     * and intelligent SQL caching. Optimized for high-frequency access patterns
     * common in web applications.
     * 
     * @param int $id Primary key value to search for
     * @param string|null $target_table Target table name (defaults to current module)
     * @return object|false Database record as object, or false if not found
     * @throws InvalidArgumentException If table name fails validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get user by ID
     * $user = $model->get_where(123);
     * if ($user) {
     *     echo "Found user: " . $user->name;
     * }
     * 
     * @example
     * // From specific table
     * $product = $model->get_where(456, 'products');
     */
    public function get_where(int $id, ?string $target_table = null): object|false
    {
        $this->connect();

        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'where_id' => $id
        ]);


        $this->debugLog("SQL query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql
        ]);

        $stmt = $this->getPreparedStatement($sql);
        $this->executeStatement($stmt, ['id' => $id]);

        $results = $stmt->fetch() ?: false;

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("SELECT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                'table' => $table,
                'sql' => $sql,
                'params' => [],
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2),
                'result_count' => count($results),
                'operation' => 'select'
            ]);

            $this->debug()->analyzeQuery($sql, [], $executionTime, [
                'table' => $table,
                'operation' => 'select',
                'result_count' => count($results),
                'has_order_by' => false,
                'has_limit' => false,
                'large_result_set' => count($results) > 1000
            ]);
        }

        return $results;
    }

    /**
     * Fetch single record matching column condition with validation and optimization
     * 
     * High-performance single record retrieval with QueryBuilder validation, automatic
     * column name verification, and intelligent prepared statement caching. Designed
     * for scenarios where you expect exactly one matching record.
     * 
     * @param string $column Column name for WHERE condition (validated)
     * @param mixed $value Value to match against specified column (auto-escaped)
     * @param string|null $target_table Target table name (defaults to current module)
     * @return object|false Database record as object, or false if not found
     * @throws InvalidArgumentException If column name fails validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get user by email
     * $user = $model->get_one_where('email', 'john@example.com', 'users');
     * if ($user) {
     *     echo "Found user: " . $user->name;
     * }
     * 
     * @example
     * // Get product by SKU
     * $product = $model->get_one_where('sku', 'ABC-123');
     * 
     * @example
     * // Get setting by key
     * $setting = $model->get_one_where('setting_key', 'site_title', 'settings');
     */
    public function get_one_where(string $column, $value, ?string $target_table = null): object|false
    {
        $this->connect();
        $startTime = microtime(true);

        $table = $this->getTableName($target_table);

        QueryBuilder::validateColumnName($column);

        $this->debugLog("Single record query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'value_type' => gettype($value),
            'value_length' => is_string($value) ? strlen($value) : null,
            'operation' => 'get_one_where'
        ]);

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'where_column' => $column,
            'where_operator' => '=',
            'limit' => 1
        ]);

        $params = ['value' => $value];

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);

            $result = $stmt->fetch(PDO::FETCH_OBJ);
            $found = $result !== false;

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Single record query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'sql' => $sql,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'record_found' => $found,
                    'result_count' => $found ? 1 : 0,
                    'operation' => 'get_one_where'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'get_one_where',
                    'where_column' => $column,
                    'where_operator' => '=',
                    'result_count' => $found ? 1 : 0,
                    'single_record_query' => true,
                    'expected_single_result' => true,
                    'has_limit' => true,
                    'limit_value' => 1
                ]);
            }

            return $found ? $result : false;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Single record query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column,
                'sql' => $sql ?? 'SQL generation failed'
            ]);
            throw new RuntimeException("Failed to execute get_one_where query: " . $e->getMessage());
        }
    }

    /**
     * Advanced WHERE clause queries with operator support and result limiting
     * 
     * Flexible query method supporting various comparison operators, automatic
     * LIKE pattern handling, and comprehensive result control. Includes
     * QueryBuilder validation and intelligent operator normalization.
     * 
     * Supported operators:
     * - Equality: '=', '!=', '<>'
     * - Comparison: '<', '>', '<=', '>='
     * - Pattern matching: 'LIKE', 'NOT LIKE' (auto-wraps with %)
     * 
     * @param string $column Column name for WHERE condition (validated)
     * @param mixed $value Value to compare against (auto-escaped)
     * @param string $operator Comparison operator (default: '=')
     * @param string $order_by Column name for ORDER BY (default: 'id')
     * @param string|null $target_table Target table name (defaults to current module)
     * @param int|null $limit Maximum number of records to return
     * @param int|null $offset Number of records to skip for pagination
     * @return array Array of matching database records as objects
     * @throws InvalidArgumentException If column names or operator fail validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Find active users
     * $activeUsers = $model->get_where_custom('status', 'active');
     * 
     * @example
     * // Pattern matching with pagination
     * $results = $model->get_where_custom('name', 'John', 'LIKE', 'created_at', 'users', 10, 0);
     * 
     * @example
     * // Numeric comparison
     * $expensiveProducts = $model->get_where_custom('price', 1000, '>', 'price', 'products');
     */
    public function get_where_custom(string $column, $value, string $operator = '=', string $order_by = 'id', ?string $target_table = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->connect();

        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateColumnName($column);
        QueryBuilder::validateColumnName($order_by);

        // Validate operator (cached)
        static $validOperators = ['=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE', 'NOT LIKE'];
        if (!in_array($operator, $validOperators)) {
            throw new InvalidArgumentException("Invalid operator: $operator");
        }

        // Handle LIKE operators
        if (in_array($operator, ['LIKE', 'NOT LIKE'])) {
            $value = "%$value%";
        }

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'where_column' => $column,
            'where_operator' => $operator,
            'order_by' => $order_by,
            'limit' => $limit,
            'offset' => $offset
        ]);

        $this->debugLog("SQL query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql
        ]);

        $stmt = $this->getPreparedStatement($sql);
        $this->executeStatement($stmt, ['value' => $value]);

        $results = $stmt->fetchAll();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("SELECT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                'table' => $table,
                'sql' => $sql,
                'params' => [],
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2),
                'result_count' => count($results),
                'order_by' => $order_by,
                'limit' => $limit,
                'offset' => $offset,
                'operation' => 'select'
            ]);

            $this->debug()->analyzeQuery($sql, ['value' => $value], $executionTime, [
                'table' => $table,
                'operation' => 'select',
                'result_count' => count($results),
                'has_order_by' => $order_by !== null,
                'has_limit' => $limit !== null,
                'large_result_set' => count($results) > 1000
            ]);
        }

        return $results;
    }

    /**
     * Retrieve multiple records from database table based on single column condition
     * 
     * Enhanced version of get_many_where with QueryBuilder integration, comprehensive
     * validation, and database-agnostic SQL generation. Provides simple interface
     * for retrieving all records matching a specific column value.
     * 
     * @param string $column Column name to filter by (validated for safety)
     * @param mixed $value Value to match against the specified column
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of database records as objects matching the condition
     * @throws InvalidArgumentException If column name fails validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get all active users
     * $activeUsers = $model->get_many_where('status', 'active', 'users');
     * 
     * @example
     * // Get all products in a category
     * $products = $model->get_many_where('category_id', 5, 'products');
     */
    public function get_many_where(string $column, $value, ?string $target_table = null): array
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateColumnName($column);

        $sql = $this->getOptimizedSQL('simple_select', [
            'table' => $table,
            'where_column' => $column,
            'where_operator' => '='
        ]);

        $this->debugLog("SQL query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql,
            'column' => $column
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, ['value' => $value]);
            $results = $stmt->fetchAll();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("SELECT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'sql' => $sql,
                    'params' => ['value' => $value],
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'result_count' => count($results),
                    'operation' => 'select'
                ]);

                $this->debug()->analyzeQuery($sql, ['value' => $value], $executionTime, [
                    'table' => $table,
                    'operation' => 'select',
                    'result_count' => count($results),
                    'where_column' => $column,
                    'large_result_set' => count($results) > 1000
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Query execution failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'sql' => $sql,
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute get_many_where query: " . $e->getMessage());
        }
    }

    /**
     * Retrieve records where column value exists in specified array of values
     * 
     * High-performance IN clause query with automatic parameter binding, validation,
     * and support for large value arrays. Handles database-specific limits and
     * provides flexible result formatting options.
     * 
     * @param string $column Column name to filter by (validated for safety)
     * @param array $values Array of values to match against (must not be empty)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string $return_type Result format: 'object' (default) or 'array'
     * @return array Array of matching database records in specified format
     * @throws InvalidArgumentException If column validation fails or values array is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get users by multiple IDs
     * $users = $model->get_where_in('id', [1, 2, 3, 4, 5], 'users');
     * 
     * @example
     * // Get products in multiple categories, return as arrays
     * $products = $model->get_where_in('category_id', [10, 20, 30], 'products', 'array');
     */
    public function get_where_in(string $column, array $values, ?string $target_table = null, string $return_type = 'object'): array
    {
        if (empty($values)) {
            throw new InvalidArgumentException('The values array must not be empty.');
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateColumnName($column);

        // Build IN clause with proper parameter binding
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);
        $sql = "SELECT * FROM $escapedTable WHERE $escapedColumn IN ($placeholders)";

        $this->debugLog("WHERE IN query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'values_count' => count($values),
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);

            // Bind values as positional parameters
            foreach ($values as $index => $value) {
                $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
            }

            $stmt->execute();
            $results = ($return_type === 'array') ?
                $stmt->fetchAll(PDO::FETCH_ASSOC) :
                $stmt->fetchAll(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("WHERE IN query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'values_count' => count($values),
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'result_count' => count($results),
                    'return_type' => $return_type,
                    'operation' => 'get_where_in'
                ]);

                // Advanced analysis for large IN clauses
                $this->debug()->analyzeQuery($sql, array_slice($values, 0, 10), $executionTime, [
                    'table' => $table,
                    'operation' => 'get_where_in',
                    'result_count' => count($results),
                    'where_column' => $column,
                    'in_clause_size' => count($values),
                    'large_in_clause' => count($values) > 100,
                    'return_type' => $return_type
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("WHERE IN query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'values_count' => count($values)
            ]);
            throw new RuntimeException("Failed to execute get_where_in query: " . $e->getMessage());
        }
    }

    /**
     * Get distinct values from column
     * 
     * Retrieves unique values from a column, commonly used for populating
     * filter dropdowns, category lists, and data analysis. Includes automatic
     * NULL filtering and optional ordering.
     * 
     * @param string $column Column name to get distinct values from (validated)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $order_by Optional ordering for results
     * @return array Array of distinct values from the column
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get all product categories
     * $categories = $model->get_distinct('category', 'products');
     * 
     * @example
     * // Get all countries with ordering
     * $countries = $model->get_distinct('country', 'users', 'country ASC');
     * 
     * @example
     * // Get all order statuses
     * $statuses = $model->get_distinct('status', 'orders');
     */
    public function get_distinct(string $column, ?string $target_table = null, ?string $order_by = null): array
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT DISTINCT $escapedColumn FROM $escapedTable WHERE $escapedColumn IS NOT NULL";

        if ($order_by) {
            // Use existing ORDER BY validation and escaping
            QueryBuilder::validateOrderBy($order_by);
            $orderClause = DatabaseSecurity::validateOrderBy($order_by, $this->dbType);
            $sql .= " ORDER BY $orderClause";
        }

        $this->debugLog("Get distinct values query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'order_by' => $order_by,
            'operation' => 'get_distinct'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Get distinct values completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'distinct_values' => count($results),
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_distinct'
                ]);

                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'table' => $table,
                    'operation' => 'get_distinct',
                    'aggregation_function' => 'DISTINCT',
                    'has_order_by' => $order_by !== null,
                    'result_count' => count($results)
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Get distinct values failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute get_distinct query: " . $e->getMessage());
        }
    }

    /**
     * Get maximum ID value from specified table (convenience wrapper)
     * 
     * Convenience method for the common pattern of getting the highest ID value.
     * This method is now implemented as a wrapper around the more general max()
     * method, ensuring consistency and reducing code duplication.
     * 
     * @param string|null $target_table Target table name (defaults to current module)
     * @return int Maximum ID value, or 0 if table is empty
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get highest user ID
     * $maxUserId = $model->get_max('users');
     * 
     * @example
     * // Check latest order ID
     * $latestOrderId = $model->get_max('orders');
     * 
     * @example
     * // Get max ID from current module table
     * $maxId = $model->get_max();
     */
    public function get_max(?string $target_table = null): int
    {
        // Delegate to the general max() method for ID column
        $result = $this->max('id', $target_table);

        // Convert to int and handle null case (empty table)
        return $result !== null ? (int)$result : 0;
    }

    /**
     * Built-in pagination with comprehensive metadata
     * 
     * High-performance pagination implementation with intelligent total count caching
     * and comprehensive result metadata. Provides all information needed for
     * pagination UI components and navigation.
     * 
     * @param int $page Current page number (1-based)
     * @param int $per_page Records per page (default: 20)
     * @param string|null $order_by Optional ordering clause
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Pagination result with data and metadata
     * @throws InvalidArgumentException If page or per_page parameters are invalid
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Basic pagination
     * $result = $model->paginate(2, 15, 'created_at desc', 'posts');
     * // Returns: [
     * //   'data' => [...],           // Array of records
     * //   'total' => 150,            // Total record count
     * //   'page' => 2,               // Current page
     * //   'per_page' => 15,          // Records per page
     * //   'total_pages' => 10,       // Total pages
     * //   'has_previous' => true,    // Has previous page
     * //   'has_next' => true,        // Has next page
     * //   'previous_page' => 1,      // Previous page number
     * //   'next_page' => 3           // Next page number
     * // ]
     * 
     * @example
     * // Simple pagination without ordering
     * $users = $model->paginate(1, 10, null, 'users');
     */
    public function paginate(int $page, int $per_page = 20, ?string $order_by = null, ?string $target_table = null): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException("Page must be at least 1");
        }

        if ($per_page < 1 || $per_page > 1000) {
            throw new InvalidArgumentException("Per page must be between 1 and 1000");
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);

        $this->debugLog("Pagination query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'page' => $page,
            'per_page' => $per_page,
            'order_by' => $order_by,
            'operation' => 'paginate'
        ]);

        try {
            // Get total count for pagination metadata
            $total = $this->count($table);
            $total_pages = ceil($total / $per_page);

            // Calculate offset
            $offset = ($page - 1) * $per_page;

            // Get paginated data
            $data = $this->get($order_by, $table, $per_page, $offset);

            // Build pagination metadata
            $result = [
                'data' => $data,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => $total_pages,
                'has_previous' => $page > 1,
                'has_next' => $page < $total_pages,
                'previous_page' => $page > 1 ? $page - 1 : null,
                'next_page' => $page < $total_pages ? $page + 1 : null,
                'from' => $offset + 1,
                'to' => min($offset + $per_page, $total)
            ];

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Pagination query completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_records' => $total,
                    'records_returned' => count($data),
                    'total_pages' => $total_pages,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'paginate'
                ]);

                $this->debug()->analyzeBulkOperation('pagination', count($data), [
                    'table' => $table,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_records' => $total,
                    'execution_time' => $executionTime
                ]);
            }

            return $result;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Pagination query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'page' => $page
            ]);
            throw new RuntimeException("Failed to execute pagination query: " . $e->getMessage());
        }
    }

    /**
     * Update existing record by ID with validation and error handling
     * 
     * High-performance UPDATE operation with QueryBuilder validation, automatic
     * column name verification, and intelligent prepared statement caching.
     * Supports partial record updates and maintains data integrity.
     * 
     * @param int $update_id Primary key of record to update
     * @param array $data Associative array of column => value pairs to update
     * @param string|null $target_table Target table name (defaults to current module)
     * @return bool True on successful update, false on failure
     * @throws InvalidArgumentException If table/column names fail validation or data is empty
     * @throws RuntimeException If database operation fails catastrophically
     * 
     * @example
     * // Update user profile
     * $success = $model->update(123, [
     *     'name' => 'John Doe',
     *     'email' => 'john@example.com',
     *     'updated_at' => date('Y-m-d H:i:s')
     * ]);
     * 
     * @example
     * // Update product price
     * $updated = $model->update(456, ['price' => 99.99], 'products');
     */
    public function update(int $update_id, array $data, ?string $target_table = null): bool
    {
        if (empty($data)) {
            return false;
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);
        $columns = array_keys($data);

        // Validate using QueryBuilder bulk validation
        QueryBuilder::validateIdentifiersBulk($columns, 'column');

        $sql = $this->getOptimizedSQL('simple_update', [
            'table' => $table,
            'columns' => $columns
        ]);

        $data['update_id'] = $update_id;

        $this->debugLog("UPDATE query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'update_id' => $update_id,
            'columns' => $columns,
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $result = $this->executeStatement($stmt, $data);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("UPDATE query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'update_id' => $update_id,
                    'columns' => $columns,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'success' => $result,
                    'operation' => 'update'
                ]);

                // Check for indexed columns being updated
                $indexedColumnsUpdated = ModelDebugHelper::getIndexedColumnsFromUpdate($table, $columns);

                $this->debug()->analyzeQuery($sql, $data, $executionTime, [
                    'table' => $table,
                    'operation' => 'update',
                    'result_count' => $result ? 1 : 0,
                    'columns_updated' => $columns,
                    'indexed_columns_updated' => $indexedColumnsUpdated,
                    'update_id' => $update_id
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("UPDATE query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'update_id' => $update_id
            ]);
            throw new RuntimeException("Failed to execute update query: " . $e->getMessage());
        }
    }

    /**
     * Update records based on custom column condition
     * 
     * Flexible update method that modifies all records matching a specific column
     * condition. Includes comprehensive validation, bulk update detection, and
     * database-agnostic SQL generation for cross-platform compatibility.
     * 
     * @param string $column Column name for WHERE condition (validated)
     * @param mixed $column_value Value that must match for update targeting
     * @param array $data Associative array of column => value pairs to update
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @return bool True if update succeeded, false on failure
     * @throws InvalidArgumentException If validation fails or data is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Update all users in a city
     * $updated = $model->update_where('city', 'Toronto', [
     *     'timezone' => 'America/Toronto',
     *     'updated_at' => date('Y-m-d H:i:s')
     * ], 'users');
     * 
     * @example
     * // Deactivate products in category
     * $model->update_where('category_id', 5, ['status' => 'inactive'], 'products');
     */
    public function update_where(string $column, $column_value, array $data, ?string $target_tbl = null): bool
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Update data array cannot be empty');
        }

        $this->connect();
        $startTime = microtime(true);

        $table = $this->getTableName($target_tbl);

        // Validate column names using QueryBuilder
        QueryBuilder::validateColumnName($column);
        $updateColumns = array_keys($data);
        QueryBuilder::validateIdentifiersBulk($updateColumns, 'column');

        // Build UPDATE SQL with proper escaping
        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedWhereColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $setClauses = [];
        foreach ($updateColumns as $updateColumn) {
            $escaped = QueryBuilder::escapeIdentifier($updateColumn, $this->dbType);
            $setClauses[] = "$escaped = :$updateColumn";
        }

        $setClause = implode(', ', $setClauses);
        $sql = "UPDATE $escapedTable SET $setClause WHERE $escapedWhereColumn = :where_value";

        $this->debugLog("UPDATE query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $escapedWhereColumn,
            'sql' => $sql
        ]);

        // Prepare parameter array
        $params = $data;
        $params['where_value'] = $column_value;

        /*
        if ($this->debug) {
            $this->debugQuery($sql, $params);
        }
            */

        try {
            $stmt = $this->getPreparedStatement($sql);
            $result = $this->executeStatement($stmt, $params);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("UPDATE query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $escapedWhereColumn,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'success' => $result,
                    'operation' => 'update'
                ]);

                // Check for indexed columns being updated
                $indexedColumnsUpdated = ModelDebugHelper::getIndexedColumnsFromUpdate($table, [$escapedWhereColumn]);

                $this->debug()->analyzeQuery($sql, $data, $executionTime, [
                    'table' => $table,
                    'operation' => 'update',
                    'result_count' => $result ? 1 : 0,
                    'columns_updated' => $escapedWhereColumn,
                    'indexed_columns_updated' => $indexedColumnsUpdated
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("UPDATE query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute update_where query: " . $e->getMessage());
        }
    }


    /**
     * Delete record by ID with safety validation and error handling
     * 
     * Secure DELETE operation targeting specific record by primary key.
     * Includes QueryBuilder validation and comprehensive error handling
     * to prevent accidental data loss.
     * 
     * @param int $id Primary key of record to delete
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @return bool True on successful deletion, false on failure
     * @throws InvalidArgumentException If table name fails validation
     * @throws RuntimeException If database operation fails catastrophically
     * 
     * @example
     * // Delete user account
     * $deleted = $model->delete(123);
     * if ($deleted) {
     *     echo "User account successfully removed";
     * }
     * 
     * @example
     * // Delete from specific table
     * $model->delete(456, 'products');
     */
    public function delete(int $id, ?string $target_tbl = null): bool
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_tbl);

        $sql = $this->getOptimizedSQL('simple_delete', [
            'table' => $table
        ]);

        $this->debugLog("DELETE query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'delete_id' => $id,
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $result = $this->executeStatement($stmt, ['id' => $id]);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("DELETE query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'delete_id' => $id,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'success' => $result,
                    'operation' => 'delete'
                ]);

                // Safety analysis
                $this->debug()->analyzeQuery($sql, ['id' => $id], $executionTime, [
                    'table' => $table,
                    'operation' => 'delete',
                    'result_count' => $result ? 1 : 0,
                    'has_where_clause' => true,
                    'delete_by_id' => true,
                    'safety_level' => 'safe'
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("DELETE query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'delete_id' => $id
            ]);
            throw new RuntimeException("Failed to execute delete query: " . $e->getMessage());
        }
    }

    /**
     * Count total records in table with efficient COUNT(*) query
     * 
     * Optimized record counting using QueryBuilder identifier escaping
     * and prepared statement caching for repeated count operations.
     * 
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @return int Total number of records in the table
     * @throws InvalidArgumentException If table name fails validation
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Count all users
     * $userCount = $model->count('users');
     * echo "Total users: $userCount";
     * 
     * @example
     * // Count current module records
     * $recordCount = $model->count();
     */
    public function count(?string $target_tbl = null): int
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_tbl);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $sql = "SELECT COUNT(*) FROM $escapedTable";

        $this->debugLog("COUNT query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $result = (int)$stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("COUNT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'sql' => $sql,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'count_result' => $result,
                    'operation' => 'count'
                ]);

                // Performance analysis for large tables
                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'table' => $table,
                    'operation' => 'count',
                    'result_count' => 1,
                    'aggregation_function' => 'COUNT',
                    'large_table' => $result > 100000
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("COUNT query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute count query: " . $e->getMessage());
        }
    }

    /**
     * Count records matching column condition with flexible operator support
     * 
     * Enhanced counting method with comprehensive operator validation, LIKE pattern
     * handling, and QueryBuilder integration. Supports all standard comparison
     * operators and provides optimized COUNT queries for performance.
     * 
     * @param string $column Column name for WHERE condition (validated)
     * @param mixed $value Value to compare against (auto-escaped)
     * @param string $operator Comparison operator (default: '=')
     * @param string|null $target_tbl Target table name (defaults to current module)
     * @return int Number of records matching the specified condition
     * @throws InvalidArgumentException If column name or operator validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Count active users
     * $activeCount = $model->count_where('status', 'active');
     * 
     * @example
     * // Count expensive products
     * $expensiveCount = $model->count_where('price', 1000, '>', 'products');
     * 
     * @example
     * // Count users with name pattern
     * $johnCount = $model->count_where('name', 'John', 'LIKE', 'users');
     */
    public function count_where(string $column, $value, string $operator = '=', ?string $target_tbl = null): int
    {
        $this->connect();
        $startTime = microtime(true);

        $table = $this->getTableName($target_tbl);

        // Validate column name using QueryBuilder
        QueryBuilder::validateColumnName($column);

        // Validate operator (cached for performance)
        static $validOperators = ['=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE', 'NOT LIKE'];
        if (!in_array($operator, $validOperators)) {
            throw new InvalidArgumentException("Invalid operator: $operator");
        }

        // Handle LIKE operators
        if (in_array($operator, ['LIKE', 'NOT LIKE'])) {
            $value = "%$value%";
        }

        $sql = $this->getOptimizedSQL('count_query', [
            'table' => $table,
            'where_column' => $column,
            'where_operator' => $operator
        ]);

        $this->debugLog("COUNT query generated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql
        ]);

        /*
        if ($this->debug) {
            $this->debugQuery($sql, ['value' => $value]);
        }
            */

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, ['value' => $value]);
            $result =  (int)$stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("COUNT query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'operator' => $operator,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'count_result' => $result,
                    'operation' => 'count_where'
                ]);

                // Add this missing analysis
                $this->debug()->analyzeQuery($sql, ['value' => $value], $executionTime, [
                    'table' => $table,
                    'operation' => 'count_where',
                    'aggregation_function' => 'COUNT',
                    'where_column' => $column,
                    'where_operator' => $operator,
                    'result_count' => 1
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("COUNT query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute count_where query: " . $e->getMessage());
        }
    }

    /**
     * Count records matching single column equality condition
     * 
     * Simplified counting method for basic equality matching. Optimized for
     * high-frequency counting operations with minimal overhead and automatic
     * validation through QueryBuilder integration.
     * 
     * @param string $column Column name for equality condition (validated)
     * @param mixed $value Value that must match exactly
     * @param string|null $target_table Target table name (defaults to current module)
     * @return int Number of records with matching column value
     * @throws InvalidArgumentException If column name validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Count users in specific city
     * $cityCount = $model->count_rows('city', 'Toronto', 'users');
     * 
     * @example
     * // Count active sessions
     * $activeSessionCount = $model->count_rows('status', 'active', 'sessions');
     */
    public function count_rows(string $column, $value, ?string $target_table = null): int
    {
        // Delegate to count_where with equality operator for consistency
        return $this->count_where($column, $value, '=', $target_table);
    }

    // =============================================================================
    // ENHANCED AGGREGATE FUNCTIONS
    // =============================================================================

    /**
     * Calculate sum of numeric column with optional WHERE condition
     * 
     * High-performance aggregate calculation with QueryBuilder validation, optional
     * filtering, and cross-database compatibility. Handles NULL values appropriately
     * and provides comprehensive debug integration for performance analysis.
     * 
     * @param string $column Column name to sum (validated for safety)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $where_column Optional WHERE condition column
     * @param mixed $where_value Optional WHERE condition value
     * @return float Sum of column values, 0.0 if no matching records
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Calculate total revenue
     * $totalRevenue = $model->sum('amount', 'orders');
     * 
     * @example
     * // Calculate completed orders revenue
     * $completedRevenue = $model->sum('amount', 'orders', 'status', 'completed');
     * 
     * @example
     * // Calculate user's total spending
     * $userSpending = $model->sum('total', 'orders', 'user_id', 123);
     */
    public function sum(string $column, ?string $target_table = null, ?string $where_column = null, $where_value = null): float
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        // Validate column and table names
        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($where_column !== null) {
            QueryBuilder::validateColumnName($where_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT COALESCE(SUM($escapedColumn), 0) FROM $escapedTable";
        $params = [];

        if ($where_column !== null && $where_value !== null) {
            $escapedWhereColumn = QueryBuilder::escapeIdentifier($where_column, $this->dbType);
            $sql .= " WHERE $escapedWhereColumn = :where_value";
            $params['where_value'] = $where_value;
        }

        $this->debugLog("SUM aggregate query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'where_column' => $where_column,
            'has_where_condition' => $where_column !== null,
            'sql' => $sql
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $result = (float)$stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("SUM aggregate query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'sum_result' => $result,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'sum'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'sum',
                    'aggregation_function' => 'SUM',
                    'where_column' => $where_column,
                    'result_count' => 1
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("SUM aggregate query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute sum query: " . $e->getMessage());
        }
    }

    /**
     * Calculate average of numeric column with optional WHERE condition
     * 
     * High-performance aggregate calculation with proper NULL handling and
     * cross-database compatibility. Returns precise decimal average with
     * comprehensive error handling and debug integration.
     * 
     * @param string $column Column name to average (validated for safety)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $where_column Optional WHERE condition column
     * @param mixed $where_value Optional WHERE condition value
     * @return float Average of column values, 0.0 if no matching records
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Calculate average product rating
     * $avgRating = $model->avg('rating', 'reviews');
     * 
     * @example
     * // Calculate average price for category
     * $avgPrice = $model->avg('price', 'products', 'category_id', 5);
     */
    public function avg(string $column, ?string $target_table = null, ?string $where_column = null, $where_value = null): float
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($where_column !== null) {
            QueryBuilder::validateColumnName($where_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT COALESCE(AVG($escapedColumn), 0) FROM $escapedTable";
        $params = [];

        if ($where_column !== null && $where_value !== null) {
            $escapedWhereColumn = QueryBuilder::escapeIdentifier($where_column, $this->dbType);
            $sql .= " WHERE $escapedWhereColumn = :where_value";
            $params['where_value'] = $where_value;
        }

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $result = (float)$stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;
                $this->debugLog("AVG aggregate query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'avg_result' => $result,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'avg'
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to execute average query: " . $e->getMessage());
        }
    }

    /**
     * Find minimum value in column with optional WHERE condition
     * 
     * Cross-database compatible minimum value calculation supporting numeric,
     * date, and text columns. Handles NULL values appropriately and provides
     * type-appropriate return values.
     * 
     * @param string $column Column name to find minimum (validated for safety)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $where_column Optional WHERE condition column
     * @param mixed $where_value Optional WHERE condition value
     * @return mixed Minimum value from column, null if no matching records
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Find oldest user registration
     * $oldestUser = $model->min('created_at', 'users');
     * 
     * @example
     * // Find lowest price in category
     * $lowestPrice = $model->min('price', 'products', 'category_id', 3);
     */
    public function min(string $column, ?string $target_table = null, ?string $where_column = null, $where_value = null): mixed
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($where_column !== null) {
            QueryBuilder::validateColumnName($where_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT MIN($escapedColumn) FROM $escapedTable";
        $params = [];

        if ($where_column !== null && $where_value !== null) {
            $escapedWhereColumn = QueryBuilder::escapeIdentifier($where_column, $this->dbType);
            $sql .= " WHERE $escapedWhereColumn = :where_value";
            $params['where_value'] = $where_value;
        }

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $result = $stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;
                $this->debugLog("MIN aggregate query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'min_result' => $result,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'min'
                ]);
            }

            return $result === false ? null : $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to execute minimum query: " . $e->getMessage());
        }
    }

    /**
     * Find maximum value in column with optional WHERE condition
     * 
     * Cross-database compatible maximum value calculation supporting numeric,
     * date, and text columns. General-purpose aggregate method that serves
     * as the foundation for specialized methods like get_max().
     * 
     * @param string $column Column name to find maximum (validated for safety)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $where_column Optional WHERE condition column
     * @param mixed $where_value Optional WHERE condition value
     * @return mixed Maximum value from column, null if no matching records
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Find highest price
     * $highestPrice = $model->max('price', 'products');
     * 
     * @example
     * // Find latest order date for user
     * $latestOrder = $model->max('order_date', 'orders', 'user_id', 123);
     * 
     * @example
     * // Find highest rating in category
     * $topRating = $model->max('rating', 'reviews', 'category', 'electronics');
     * 
     * @example
     * // Find maximum ID (though get_max() is more convenient for this)
     * $maxId = $model->max('id', 'users');
     */
    public function max(string $column, ?string $target_table = null, ?string $where_column = null, $where_value = null): mixed
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($where_column !== null) {
            QueryBuilder::validateColumnName($where_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT MAX($escapedColumn) FROM $escapedTable";
        $params = [];

        if ($where_column !== null && $where_value !== null) {
            $escapedWhereColumn = QueryBuilder::escapeIdentifier($where_column, $this->dbType);
            $sql .= " WHERE $escapedWhereColumn = :where_value";
            $params['where_value'] = $where_value;
        }

        $this->debugLog("MAX aggregate query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'where_column' => $where_column,
            'has_where_condition' => $where_column !== null,
            'operation' => 'max'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $result = $stmt->fetchColumn();

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("MAX aggregate query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'max_result' => $result,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'max'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'max',
                    'aggregation_function' => 'MAX',
                    'where_column' => $where_column,
                    'result_count' => 1
                ]);
            }

            return $result === false ? null : $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("MAX aggregate query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute maximum query: " . $e->getMessage());
        }
    }

    // =============================================================================
    // EXISTENCE & VALIDATION HELPERS
    // =============================================================================

    /**
     * Check if record exists with specified column value (optimized for large tables)
     * 
     * High-performance existence check using optimized LIMIT 1 query instead of COUNT.
     * Significantly faster than count() > 0 for large tables since it stops at first match.
     * Includes comprehensive validation and debug integration.
     * 
     * @param string $column Column name for condition (validated for safety)
     * @param mixed $value Value to check for existence
     * @param string|null $target_table Target table name (defaults to current module)
     * @return bool True if record exists, false otherwise
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Check if email exists (faster than count for large tables)
     * if ($model->exists('email', 'user@example.com', 'users')) {
     *     echo "Email already registered";
     * }
     * 
     * @example
     * // Check if product SKU exists
     * if ($model->exists('sku', 'ABC-123', 'products')) {
     *     echo "SKU already in use";
     * }
     * 
     * @example
     * // Check if order exists for user
     * if ($model->exists('user_id', 123, 'orders')) {
     *     echo "User has orders";
     * }
     */
    public function exists(string $column, $value, ?string $target_table = null): bool
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT 1 FROM $escapedTable WHERE $escapedColumn = :value LIMIT 1";

        $this->debugLog("Existence check query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'value_type' => gettype($value),
            'operation' => 'exists'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, ['value' => $value]);
            $exists = $stmt->fetch() !== false;

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Existence check completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'exists' => $exists,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'exists'
                ]);

                $this->debug()->analyzeQuery($sql, ['value' => $value], $executionTime, [
                    'table' => $table,
                    'operation' => 'exists',
                    'where_column' => $column,
                    'optimized_existence_check' => true,
                    'result_count' => 1
                ]);
            }

            return $exists;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Existence check failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute existence check: " . $e->getMessage());
        }
    }

    /**
     * Check if column value is unique (excluding specified record)
     * 
     * Essential validation helper for update operations where you need to ensure
     * uniqueness while excluding the current record. Commonly used for email,
     * username, and SKU validation during updates.
     * 
     * @param string $column Column name to check for uniqueness (validated)
     * @param mixed $value Value to check for uniqueness
     * @param string|null $target_table Target table name (defaults to current module)
     * @param int|null $exclude_id Optional ID to exclude from uniqueness check
     * @return bool True if value is unique, false if duplicate exists
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Check username uniqueness during user update
     * if (!$model->is_unique('username', 'john_doe', 'users', $currentUserId)) {
     *     echo "Username already taken";
     * }
     * 
     * @example
     * // Check email uniqueness for new registration
     * if (!$model->is_unique('email', 'user@example.com', 'users')) {
     *     echo "Email already registered";
     * }
     * 
     * @example
     * // Check product SKU uniqueness during update
     * if (!$model->is_unique('sku', 'ABC-123', 'products', $productId)) {
     *     echo "SKU already exists";
     * }
     */
    public function is_unique(string $column, $value, ?string $target_table = null, ?int $exclude_id = null): bool
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT 1 FROM $escapedTable WHERE $escapedColumn = :value";
        $params = ['value' => $value];

        if ($exclude_id !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $exclude_id;
        }

        $sql .= " LIMIT 1";

        $this->debugLog("Uniqueness check query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'exclude_id' => $exclude_id,
            'has_exclusion' => $exclude_id !== null,
            'operation' => 'is_unique'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $duplicate_exists = $stmt->fetch() !== false;
            $is_unique = !$duplicate_exists;

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Uniqueness check completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'is_unique' => $is_unique,
                    'exclude_id' => $exclude_id,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'is_unique'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'uniqueness_check',
                    'where_column' => $column,
                    'has_exclusion' => $exclude_id !== null,
                    'result_count' => 1
                ]);
            }

            return $is_unique;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Uniqueness check failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute uniqueness check: " . $e->getMessage());
        }
    }

    // =============================================================================
    // DATA RETRIEVAL HELPERS
    // =============================================================================

    /**
     * Get first record from table with optional ordering
     * 
     * Retrieves the first record based on specified ordering criteria. Optimized
     * with LIMIT 1 for performance and includes comprehensive validation and
     * debug integration. Commonly used for getting oldest, newest, or alphabetically first records.
     * 
     * @param string|null $order_by Column and direction for ordering (e.g., 'created_at', 'id desc')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return object|false First record as object, or false if table is empty
     * @throws InvalidArgumentException If table name or order validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get first user by creation date
     * $firstUser = $model->get_first('created_at', 'users');
     * 
     * @example
     * // Get alphabetically first product
     * $firstProduct = $model->get_first('name', 'products');
     * 
     * @example
     * // Get oldest order (default id ordering)
     * $oldestOrder = $model->get_first(null, 'orders');
     */
    public function get_first(?string $order_by = 'id', ?string $target_table = null): object|false
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $sql = "SELECT * FROM $escapedTable";

        if ($order_by) {
            $orderClause = DatabaseSecurity::validateOrderBy($order_by, $this->dbType);
            $sql .= " ORDER BY $orderClause";
        }

        $sql .= " LIMIT 1";

        $this->debugLog("Get first record query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'order_by' => $order_by,
            'operation' => 'get_first'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $result = $stmt->fetch(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Get first record completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'order_by' => $order_by,
                    'record_found' => $result !== false,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_first'
                ]);

                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'table' => $table,
                    'operation' => 'get_first',
                    'has_order_by' => $order_by !== null,
                    'has_limit' => true,
                    'limit_value' => 1,
                    'result_count' => $result !== false ? 1 : 0
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Get first record failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table
            ]);
            throw new RuntimeException("Failed to execute get_first query: " . $e->getMessage());
        }
    }

    /**
     * Get last record from table with optional ordering
     * 
     * Retrieves the last record based on specified ordering criteria. Automatically
     * reverses the order direction to get the last record efficiently. Commonly used
     * for getting newest, latest, or alphabetically last records.
     * 
     * @param string|null $order_by Column and direction for ordering (e.g., 'created_at', 'id desc')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return object|false Last record as object, or false if table is empty
     * @throws InvalidArgumentException If table name or order validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get latest user registration
     * $latestUser = $model->get_last('created_at', 'users');
     * 
     * @example
     * // Get highest ID order
     * $latestOrder = $model->get_last('id', 'orders');
     * 
     * @example
     * // Get most recent blog post
     * $recentPost = $model->get_last('published_at', 'posts');
     */
    public function get_last(?string $order_by = 'id', ?string $target_table = null): object|false
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $sql = "SELECT * FROM $escapedTable";

        if ($order_by) {
            // Reverse the order direction to get the last record
            $reversedOrder = $this->reverseOrderDirection($order_by);

            // Use existing ORDER BY validation and escaping
            QueryBuilder::validateOrderBy($reversedOrder);
            $orderClause = DatabaseSecurity::validateOrderBy($reversedOrder, $this->dbType);
            $sql .= " ORDER BY $orderClause";
        } else {
            // Default to DESC order for last record
            $sql .= " ORDER BY id DESC";
        }

        $sql .= " LIMIT 1";

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $result = $stmt->fetch(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;
                $this->debugLog("Get last record completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'order_by' => $order_by,
                    'record_found' => $result !== false,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_last'
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to execute get_last query: " . $e->getMessage());
        }
    }

    /**
     * Get random records from table with cross-database compatibility
     * 
     * Retrieves random records using database-specific RANDOM functions with automatic
     * translation. Provides true randomness across MySQL (RAND()), SQLite (RANDOM()),
     * and PostgreSQL (RANDOM()) with consistent API.
     * 
     * @param int $limit Number of random records to retrieve (default: 1)
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of random records as objects
     * @throws InvalidArgumentException If table validation fails or limit is invalid
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get single random product
     * $randomProduct = $model->get_random(1, 'products');
     * 
     * @example
     * // Get 5 random featured articles
     * $randomArticles = $model->get_random(5, 'featured_articles');
     * 
     * @example
     * // Get random user for spotlight
     * $spotlightUser = $model->get_random(1, 'users');
     */
    public function get_random(int $limit = 1, ?string $target_table = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("Limit must be at least 1");
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);

        // Database-specific random function
        $randomFunction = match ($this->dbType) {
            'mysql' => 'RAND()',
            'sqlite' => 'RANDOM()',
            'postgresql' => 'RANDOM()',
            default => 'RANDOM()'
        };

        $sql = "SELECT * FROM $escapedTable ORDER BY $randomFunction LIMIT :limit";

        $this->debugLog("Get random records query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'limit' => $limit,
            'random_function' => $randomFunction,
            'operation' => 'get_random'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, ['limit' => $limit]);
            $results = $stmt->fetchAll(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Get random records completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'requested_limit' => $limit,
                    'records_found' => count($results),
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_random'
                ]);

                $this->debug()->analyzeQuery($sql, ['limit' => $limit], $executionTime, [
                    'table' => $table,
                    'operation' => 'get_random',
                    'has_order_by' => true,
                    'order_by_type' => 'random',
                    'has_limit' => true,
                    'limit_value' => $limit,
                    'result_count' => count($results)
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Get random records failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'limit' => $limit
            ]);
            throw new RuntimeException("Failed to execute get_random query: " . $e->getMessage());
        }
    }

    // =============================================================================
    // COLLECTION HELPERS
    // =============================================================================

    /**
     * Extract single column values as array with optional key column
     * 
     * Powerful collection helper that extracts a single column as an array, optionally
     * using another column as array keys. Extremely useful for creating lookup arrays,
     * option lists, and data transformations.
     * 
     * @param string $column Column name to extract values from (validated)
     * @param string|null $target_table Target table name (defaults to current module)
     * @param string|null $key_column Optional column to use as array keys (validated)
     * @return array Array of column values, optionally keyed by key_column
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get array of user emails
     * $emails = $model->pluck('email', 'users');
     * // Returns: ['user1@example.com', 'user2@example.com', ...]
     * 
     * @example
     * // Get user emails keyed by ID
     * $emailsById = $model->pluck('email', 'users', 'id');
     * // Returns: [1 => 'user1@example.com', 2 => 'user2@example.com', ...]
     * 
     * @example
     * // Get product names keyed by SKU
     * $productNames = $model->pluck('name', 'products', 'sku');
     * // Returns: ['ABC-123' => 'Widget A', 'DEF-456' => 'Widget B', ...]
     */
    public function pluck(string $column, ?string $target_table = null, ?string $key_column = null): array
    {
        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($column);
        if ($key_column !== null) {
            QueryBuilder::validateColumnName($key_column);
        }

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedColumn = QueryBuilder::escapeIdentifier($column, $this->dbType);

        $sql = "SELECT $escapedColumn";
        if ($key_column !== null) {
            $escapedKeyColumn = QueryBuilder::escapeIdentifier($key_column, $this->dbType);
            $sql .= ", $escapedKeyColumn";
        }
        $sql .= " FROM $escapedTable";

        $this->debugLog("Pluck operation initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'column' => $column,
            'key_column' => $key_column,
            'has_key_column' => $key_column !== null,
            'operation' => 'pluck'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);

            $result = [];
            if ($key_column !== null) {
                // Use key column for array keys
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $result[$row[$key_column]] = $row[$column];
                }
            } else {
                // Simple indexed array
                $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Pluck operation completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'column' => $column,
                    'key_column' => $key_column,
                    'values_extracted' => count($result),
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'pluck'
                ]);

                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'table' => $table,
                    'operation' => 'pluck',
                    'columns_selected' => $key_column !== null ? 2 : 1,
                    'result_count' => count($result),
                    'collection_transformation' => true
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Pluck operation failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to execute pluck operation: " . $e->getMessage());
        }
    }

    /**
     * Insert single record with auto-bulk detection and performance optimization
     * 
     * High-performance INSERT operation with automatic bulk operation detection
     * for pattern recognition and adaptive optimization. Triggers bulk mode
     * when multiple inserts are detected within a session.
     * 
     * Features:
     * - Automatic bulk operation detection for performance optimization
     * - QueryBuilder validation for all identifiers
     * - Intelligent prepared statement caching
     * - Returns auto-increment ID for inserted records
     * 
     * @param array $data Associative array of column => value pairs to insert
     * @param string|null $target_table Target table name (defaults to current module)
     * @return int|false Auto-increment ID of inserted record, or false on failure
     * @throws InvalidArgumentException If table/column names fail validation or data is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Insert new user
     * $userId = $model->insert([
     *     'name' => 'Jane Doe',
     *     'email' => 'jane@example.com',
     *     'created_at' => date('Y-m-d H:i:s')
     * ]);
     * 
     * @example
     * // Insert product
     * $productId = $model->insert([
     *     'name' => 'Widget',
     *     'price' => 29.99,
     *     'category_id' => 5
     * ], 'products');
     */
    public function insert(array $data, ?string $target_table = null): int|false
    {
        if (empty($data)) {
            return false;
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $target_table ?? $this->current_module ?? 'default_table';

        // Trigger bulk detection for pattern recognition
        $this->performance()->detectBulkOperation(1);

        $result = $this->fastSingleInsert($table, $data);

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("Single insert completed", DebugCategory::SQL, DebugLevel::BASIC, [
                'table' => $table,
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2),
                'insert_id' => $result,
                'success' => $result !== false,
                'operation' => 'insert'
            ]);

            // Advanced insert analysis
            $this->debug()->analyzeQuery("INSERT INTO $table", $data, $executionTime, [
                'table' => $table,
                'operation' => 'insert',
                'result_count' => $result ? 1 : 0,
                'column_count' => count($data),
                'bulk_detection_active' => $this->performance()->isBulkModeActive()
            ]);
        }

        return $result;
    }

    /**
     * Insert or update record based on unique column (UPSERT operation)
     * 
     * High-performance UPSERT implementation using database-specific syntax for optimal
     * performance. Automatically determines whether to INSERT or UPDATE based on the
     * existence of a record with the specified unique column value.
     * 
     * @param array $data Associative array of column => value pairs
     * @param string $unique_column Column name to check for uniqueness (validated)
     * @param string|null $target_table Target table name (defaults to current module)
     * @return int ID of inserted or updated record
     * @throws InvalidArgumentException If data validation fails or unique_column is invalid
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Insert new user or update existing by email
     * $userId = $model->upsert([
     *     'email' => 'user@example.com',
     *     'name' => 'John Doe',
     *     'updated_at' => date('Y-m-d H:i:s')
     * ], 'email', 'users');
     * 
     * @example
     * // Product catalog sync with SKU as unique identifier
     * $productId = $model->upsert([
     *     'sku' => 'ABC-123',
     *     'name' => 'Widget Pro',
     *     'price' => 29.99
     * ], 'sku', 'products');
     */
    public function upsert(array $data, string $unique_column, ?string $target_table = null): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Data array cannot be empty");
        }

        if (!isset($data[$unique_column])) {
            throw new InvalidArgumentException("Data must contain the unique column: $unique_column");
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($unique_column);

        $unique_value = $data[$unique_column];

        $this->debugLog("UPSERT operation initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'unique_column' => $unique_column,
            'data_fields' => array_keys($data),
            'operation' => 'upsert'
        ]);

        try {
            // Check if record exists
            $existingRecord = $this->get_one_where($unique_column, $unique_value, $table);

            if ($existingRecord) {
                // Update existing record
                $this->debugLog("UPSERT: Updating existing record", DebugCategory::SQL, DebugLevel::DETAILED, [
                    'existing_id' => $existingRecord->id,
                    'unique_column' => $unique_column,
                    'unique_value' => $unique_value
                ]);

                $success = $this->update($existingRecord->id, $data, $table);
                $recordId = $success ? $existingRecord->id : 0;
            } else {
                // Insert new record
                $this->debugLog("UPSERT: Inserting new record", DebugCategory::SQL, DebugLevel::DETAILED, [
                    'unique_column' => $unique_column,
                    'unique_value' => $unique_value
                ]);

                $recordId = $this->insert($data, $table);
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("UPSERT operation completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'unique_column' => $unique_column,
                    'record_id' => $recordId,
                    'operation_type' => $existingRecord ? 'update' : 'insert',
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'upsert'
                ]);

                $this->debug()->analyzeBulkOperation('upsert', 1, [
                    'table' => $table,
                    'operation_type' => $existingRecord ? 'update' : 'insert',
                    'execution_time' => $executionTime,
                    'unique_column' => $unique_column
                ]);
            }

            return $recordId;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("UPSERT operation failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'unique_column' => $unique_column
            ]);
            throw new RuntimeException("Failed to execute upsert operation: " . $e->getMessage());
        }
    }

    // =============================================================================
    // TIME-BASED CONVENIENCE METHODS
    // =============================================================================

    /**
     * Get records within date range
     * 
     * Efficient date range filtering with automatic date validation and timezone
     * handling. Commonly used for reporting, analytics, and time-based data retrieval.
     * 
     * @param string $start_date Start date (YYYY-MM-DD format)
     * @param string $end_date End date (YYYY-MM-DD format)
     * @param string $date_column Column name containing date values (default: 'created_at')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of records within the date range
     * @throws InvalidArgumentException If date format is invalid or column validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get orders from January 2024
     * $orders = $model->get_by_date_range('2024-01-01', '2024-01-31', 'order_date', 'orders');
     * 
     * @example
     * // Get recent user registrations
     * $recentUsers = $model->get_by_date_range('2024-01-01', '2024-12-31', 'created_at', 'users');
     * 
     * @example
     * // Get posts published this year
     * $posts = $model->get_by_date_range('2024-01-01', '2024-12-31', 'published_at', 'posts');
     */
    public function get_by_date_range(string $start_date, string $end_date, string $date_column = 'created_at', ?string $target_table = null): array
    {
        // Validate date formats
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            throw new InvalidArgumentException("Start date must be in YYYY-MM-DD format");
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            throw new InvalidArgumentException("End date must be in YYYY-MM-DD format");
        }

        if ($start_date > $end_date) {
            throw new InvalidArgumentException("Start date must be before or equal to end date");
        }

        $this->connect();
        $startTime = microtime(true);
        $table = $this->getTableName($target_table);

        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($date_column);

        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
        $escapedDateColumn = QueryBuilder::escapeIdentifier($date_column, $this->dbType);

        $sql = "SELECT * FROM $escapedTable WHERE $escapedDateColumn >= :start_date AND $escapedDateColumn <= :end_date";

        $params = [
            'start_date' => $start_date,
            'end_date' => $end_date
        ];

        $this->debugLog("Date range query initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'date_column' => $date_column,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'operation' => 'get_by_date_range'
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $params);
            $results = $stmt->fetchAll(PDO::FETCH_OBJ);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Date range query completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'table' => $table,
                    'date_column' => $date_column,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'records_found' => count($results),
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'get_by_date_range'
                ]);

                $this->debug()->analyzeQuery($sql, $params, $executionTime, [
                    'table' => $table,
                    'operation' => 'date_range_filter',
                    'where_column' => $date_column,
                    'date_range_query' => true,
                    'result_count' => count($results)
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Date range query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'date_column' => $date_column
            ]);
            throw new RuntimeException("Failed to execute date range query: " . $e->getMessage());
        }
    }

    /**
     * Get records created today
     * 
     * Convenience method for retrieving today's records using database-specific
     * date functions. Automatically handles timezone considerations and provides
     * cross-database compatibility.
     * 
     * @param string $date_column Column containing date values (default: 'created_at')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of records created today
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get today's orders
     * $todaysOrders = $model->get_today('order_date', 'orders');
     * 
     * @example
     * // Get today's user registrations
     * $newUsers = $model->get_today('created_at', 'users');
     * 
     * @example
     * // Get today's blog posts
     * $todaysPosts = $model->get_today('published_at', 'posts');
     */
    public function get_today(string $date_column = 'created_at', ?string $target_table = null): array
    {
        $today = date('Y-m-d');
        return $this->get_by_date_range($today, $today, $date_column, $target_table);
    }

    /**
     * Get records from the past N days
     * 
     * Retrieves records from a specified number of days ago up to today.
     * Commonly used for recent activity feeds, analytics dashboards, and
     * time-based reporting.
     * 
     * @param int $days Number of days to look back (default: 7)
     * @param string $date_column Column containing date values (default: 'created_at')
     * @param string|null $target_table Target table name (defaults to current module)
     * @return array Array of records from the specified time period
     * @throws InvalidArgumentException If days parameter or validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get orders from the past week
     * $recentOrders = $model->get_recent(7, 'order_date', 'orders');
     * 
     * @example
     * // Get posts from the past month
     * $recentPosts = $model->get_recent(30, 'published_at', 'posts');
     * 
     * @example
     * // Get user activity from past 3 days
     * $recentActivity = $model->get_recent(3, 'last_login', 'users');
     */
    public function get_recent(int $days = 7, string $date_column = 'created_at', ?string $target_table = null): array
    {
        if ($days < 1) {
            throw new InvalidArgumentException("Days must be at least 1");
        }

        $start_date = date('Y-m-d', strtotime("-$days days"));
        $end_date = date('Y-m-d');

        return $this->get_by_date_range($start_date, $end_date, $date_column, $target_table);
    }

    // =============================================================================
    // EXPRESSION-ENABLED METHODS
    // =============================================================================

    /**
     * Update record with expression support for atomic operations
     * 
     * Enables atomic updates using SQL expressions instead of requiring
     * separate SELECT + UPDATE operations. Auto-bulk detection is disabled
     * for expression operations to maintain predictable behavior.
     * 
     * @param int $update_id Record ID to update
     * @param array $data Standard column => value pairs for parameter binding
     * @param array $expressions Column => expression pairs for literal SQL
     * @param string|null $target_table Target table name
     * @param array $allowed_columns Columns that can be referenced in expressions
     * @return bool Success status
     * 
     * @example
     * // Atomic counter increment
     * $model->update_with_expressions(123, [], ['counter' => 'counter + 1'], 'stats', ['counter']);
     * 
     * @example  
     * // Mixed parameter and expression update
     * $model->update_with_expressions(123, 
     *     ['name' => 'New Name'],                    // Parameter binding
     *     ['counter' => 'counter + 1'],              // Expression  
     *     'users',
     *     ['counter']                                // Allow counter in expressions
     * );
     */
    public function update_with_expressions(
        int $update_id,
        array $data = [],
        array $expressions = [],
        ?string $target_table = null,
        array $allowed_columns = []
    ): bool {
        if (empty($data) && empty($expressions)) {
            throw new InvalidArgumentException("Either data or expressions must be provided");
        }

        $this->connect();
        $startTime = microtime(true);

        // Disable auto-bulk detection for expression operations
        $this->performance()->disableAutoBulkForCurrentOperation();

        $table = $this->getTableName($target_table);

        // Build query using expression-enabled builder
        $sql = $this->getOptimizedSQL('update_with_expressions', [
            'table' => $table,
            'columns' => array_keys($data),
            'expressions' => $expressions,
            'allowed_columns' => $allowed_columns
        ]);

        $this->debugLog("Update with expressions started", DebugCategory::SQL, DebugLevel::BASIC, [
            'table' => $table,
            'update_id' => $update_id,
            'data_columns' => array_keys($data),
            'expression_columns' => array_keys($expressions),
            'auto_bulk_disabled' => true
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);

            // Bind regular parameters
            $params = array_merge($data, ['update_id' => $update_id]);
            $this->executeStatement($stmt, $params);

            $executionTime = microtime(true) - $startTime;
            $success = $stmt->rowCount() > 0;

            $this->debugLog("Update with expressions completed", DebugCategory::SQL, DebugLevel::BASIC, [
                'success' => $success,
                'rows_affected' => $stmt->rowCount(),
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);

            return $success;
        } catch (PDOException $e) {
            $executionTime = microtime(true) - $startTime;
            $this->lastError = $e->getMessage();
            $this->debugLog("Update with expressions failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);
            return false;
        } finally {
            // Reset the skip flag after operation
            $this->performance()->resetAutoBulkSkipFlag();
        }
    }

    /**
     * Insert record with expression support for computed values
     * 
     * Enables inserts with computed values using SQL expressions. Auto-bulk
     * detection is disabled for expression operations to maintain predictable behavior.
     * 
     * @param array $data Standard column => value pairs for parameter binding
     * @param array $expressions Column => expression pairs for literal SQL
     * @param string|null $target_table Target table name
     * @param array $allowed_columns Columns that can be referenced in expressions
     * @return int|false New record ID or false on failure
     * 
     * @example
     * // Insert with computed timestamp
     * $id = $model->insert_with_expressions(
     *     ['name' => 'Test User'],           // Parameter binding
     *     ['created_at' => 'NOW()'],         // Expression
     *     'users'
     * );
     */
    public function insert_with_expressions(
        array $data = [],
        array $expressions = [],
        ?string $target_table = null,
        array $allowed_columns = []
    ): int|false {
        if (empty($data) && empty($expressions)) {
            throw new InvalidArgumentException("Either data or expressions must be provided");
        }

        $this->connect();
        $startTime = microtime(true);

        // Disable auto-bulk detection for expression operations
        $this->performance()->disableAutoBulkForCurrentOperation();

        $table = $this->getTableName($target_table);

        // Build query using expression-enabled builder
        $sql = $this->getOptimizedSQL('insert_with_expressions', [
            'table' => $table,
            'columns' => array_keys($data),
            'expressions' => $expressions,
            'allowed_columns' => $allowed_columns
        ]);

        $this->debugLog("Insert with expressions started", DebugCategory::SQL, DebugLevel::BASIC, [
            'table' => $table,
            'data_columns' => array_keys($data),
            'expression_columns' => array_keys($expressions),
            'auto_bulk_disabled' => true
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt, $data);

            $executionTime = microtime(true) - $startTime;
            $insertId = (int) $this->dbh->lastInsertId();

            $this->debugLog("Insert with expressions completed", DebugCategory::SQL, DebugLevel::BASIC, [
                'insert_id' => $insertId,
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);

            return $insertId;
        } catch (PDOException $e) {
            $executionTime = microtime(true) - $startTime;
            $this->lastError = $e->getMessage();
            $this->debugLog("Insert with expressions failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);
            return false;
        } finally {
            // Reset the skip flag after operation
            $this->performance()->resetAutoBulkSkipFlag();
        }
    }

    /**
     * Update multiple records matching a condition with expression support
     * 
     * Updates all records where a column matches a value, with support for
     * both parameter binding and SQL expressions. Auto-bulk detection is disabled.
     * 
     * @param string $column Column name for WHERE condition
     * @param mixed $column_value Value to match in WHERE condition
     * @param array $data Standard column => value pairs for parameter binding
     * @param array $expressions Column => expression pairs for literal SQL
     * @param string|null $target_table Target table name
     * @param array $allowed_columns Columns that can be referenced in expressions
     * @return bool Success status
     * 
     * @example
     * // Update all products in a category with price increase
     * $model->update_where_with_expressions('category_id', 5, [], [
     *     'price' => 'price * 1.15',       // 15% price increase
     *     'last_updated' => 'NOW()'
     * ], 'products', ['price']);
     */
    public function update_where_with_expressions(
        string $column,
        $column_value,
        array $data = [],
        array $expressions = [],
        ?string $target_table = null,
        array $allowed_columns = []
    ): bool {
        if (empty($data) && empty($expressions)) {
            throw new InvalidArgumentException("Either data or expressions must be provided");
        }

        $this->connect();
        $startTime = microtime(true);

        // Disable auto-bulk detection for expression operations
        $this->performance()->disableAutoBulkForCurrentOperation();

        $table = $this->getTableName($target_table);

        // Build query using expression-enabled builder (WHERE column validated in QueryBuilder)
        $sql = $this->getOptimizedSQL('update_where_with_expressions', [
            'table' => $table,
            'where_column' => $column,
            'columns' => array_keys($data),
            'expressions' => $expressions,
            'allowed_columns' => $allowed_columns
        ]);

        $this->debugLog("Update WHERE with expressions started", DebugCategory::SQL, DebugLevel::BASIC, [
            'table' => $table,
            'where_column' => $column,
            'where_value' => $column_value,
            'data_columns' => array_keys($data),
            'expression_columns' => array_keys($expressions),
            'auto_bulk_disabled' => true
        ]);

        try {
            $stmt = $this->getPreparedStatement($sql);

            // Bind parameters (WHERE value + data values)
            $params = array_merge($data, ['where_value' => $column_value]);
            $this->executeStatement($stmt, $params);

            $executionTime = microtime(true) - $startTime;
            $rowsAffected = $stmt->rowCount();
            $success = $rowsAffected > 0;

            $this->debugLog("Update WHERE with expressions completed", DebugCategory::SQL, DebugLevel::BASIC, [
                'success' => $success,
                'rows_affected' => $rowsAffected,
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);

            return $success;
        } catch (PDOException $e) {
            $executionTime = microtime(true) - $startTime;
            $this->lastError = $e->getMessage();
            $this->debugLog("Update WHERE with expressions failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]);
            return false;
        } finally {
            // Reset the skip flag after operation
            $this->performance()->resetAutoBulkSkipFlag();
        }
    }

    // =============================================================================
    // CONVENIENCE HELPER METHODS FOR COMMON EXPRESSION PATTERNS
    // =============================================================================

    /**
     * Increment a numeric column atomically
     * 
     * Convenience method for the common pattern of incrementing counters,
     * view counts, etc. without race conditions.
     * 
     * @param int $id Record ID to update
     * @param string $column Column name to increment
     * @param int|float $amount Amount to increment by (default: 1)
     * @param string|null $target_table Target table name
     * @return bool Success status
     * 
     * @example
     * // Increment view count by 1
     * $model->increment_column(123, 'view_count', 1, 'posts');
     * 
     * @example
     * // Increase price by 5.50
     * $model->increment_column(456, 'price', 5.50, 'products');
     */
    public function increment_column(
        int $id,
        string $column,
        int|float $amount = 1,
        ?string $target_table = null
    ): bool {
        // Validate amount is numeric and positive
        if (!is_numeric($amount) || $amount <= 0) {
            throw new InvalidArgumentException("Increment amount must be a positive number");
        }

        return $this->update_with_expressions($id, [], [
            $column => "$column + $amount"
        ], $target_table, [$column]);
    }

    /**
     * Decrement a numeric column atomically
     * 
     * Convenience method for decreasing counters, quantities, etc.
     * 
     * @param int $id Record ID to update
     * @param string $column Column name to decrement
     * @param int|float $amount Amount to decrement by (default: 1)
     * @param string|null $target_table Target table name
     * @return bool Success status
     * 
     * @example
     * // Decrease stock quantity by 1
     * $model->decrement_column(123, 'stock_quantity', 1, 'products');
     */
    public function decrement_column(
        int $id,
        string $column,
        int|float $amount = 1,
        ?string $target_table = null
    ): bool {
        // Validate amount is numeric and positive
        if (!is_numeric($amount) || $amount <= 0) {
            throw new InvalidArgumentException("Decrement amount must be a positive number");
        }

        return $this->update_with_expressions($id, [], [
            $column => "$column - $amount"
        ], $target_table, [$column]);
    }

    /**
     * Update timestamp column to current database time
     * 
     * Convenience method for updating timestamp fields using database-specific
     * current time functions.
     * 
     * @param int $id Record ID to update
     * @param string $column Timestamp column name (default: 'updated_at')
     * @param string|null $target_table Target table name
     * @return bool Success status
     * 
     * @example
     * // Update last_login timestamp
     * $model->touch_timestamp(123, 'last_login', 'users');
     */
    public function touch_timestamp(
        int $id,
        string $column = 'updated_at',
        ?string $target_table = null
    ): bool {
        return $this->update_with_expressions($id, [], [
            $column => 'NOW()'  // Will be translated per database type
        ], $target_table);
    }

    /**
     * Toggle boolean column value (0 to 1, 1 to 0)
     * 
     * Atomic toggle operation for boolean fields using database-level calculation
     * to prevent race conditions. Commonly used for status flags, active/inactive
     * toggles, and feature switches.
     * 
     * @param int $id Record ID to update
     * @param string $column Boolean column name to toggle (validated)
     * @param string|null $target_table Target table name (defaults to current module)
     * @return bool True if toggle succeeded, false otherwise
     * @throws InvalidArgumentException If column or table validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Toggle user active status
     * $model->toggle_column(123, 'is_active', 'users');
     * 
     * @example
     * // Toggle product featured status
     * $model->toggle_column(456, 'is_featured', 'products');
     * 
     * @example
     * // Toggle notification setting
     * $model->toggle_column(789, 'email_notifications', 'user_settings');
     */
    public function toggle_column(int $id, string $column, ?string $target_table = null): bool
    {
        $this->connect();
        $startTime = microtime(true);

        $this->debugLog("Toggle column operation initiated", DebugCategory::SQL, DebugLevel::DETAILED, [
            'id' => $id,
            'column' => $column,
            'table' => $target_table ?: $this->current_module,
            'operation' => 'toggle_column'
        ]);

        try {
            // Use atomic expression update to toggle the boolean value
            $success = $this->update_with_expressions($id, [], [
                $column => "CASE WHEN $column = 1 THEN 0 ELSE 1 END"
            ], $target_table, [$column]);

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Toggle column operation completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'id' => $id,
                    'column' => $column,
                    'success' => $success,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'operation' => 'toggle_column'
                ]);
            }

            return $success;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Toggle column operation failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'id' => $id,
                'column' => $column
            ]);
            throw new RuntimeException("Failed to toggle column: " . $e->getMessage());
        }
    }

    // =============================================================================
    // RAW SQL METHODS
    // =============================================================================

    /**
     * Execute custom SQL query with optional result type specification
     * 
     * Direct SQL execution for complex queries that don't fit standard CRUD patterns.
     * Supports multiple return formats and includes comprehensive error handling.
     * Use with caution - bypasses validation and optimization layers.
     * 
     * @param string $sql Raw SQL query to execute (user responsibility for safety)
     * @param string|null $return_type Result format: 'object', 'array', or null for no results
     * @return mixed Query results in specified format, or null for non-SELECT queries
     * @throws RuntimeException If query execution fails
     * 
     * @example
     * // Complex reporting query
     * $results = $model->query("
     *     SELECT u.name, COUNT(o.id) as order_count 
     *     FROM users u 
     *     LEFT JOIN orders o ON u.id = o.user_id 
     *     GROUP BY u.id
     * ", 'array');
     * 
     * @example
     * // DDL operation
     * $model->query("CREATE INDEX idx_users_email ON users (email)");
     */
    public function query(string $sql, ?string $return_type = null): mixed
    {
        $this->connect();
        $startTime = microtime(true);

        $this->debugLog("Raw query execution", DebugCategory::SQL, DebugLevel::DETAILED, [
            'sql' => $sql,
            'return_type' => $return_type,
            'sql_length' => strlen($sql)
        ]);

        try {
            $this->stmt = $this->dbh->prepare($sql);
            $this->stmt->execute();

            $results = null;
            if ($return_type === 'object') {
                $results = $this->stmt->fetchAll(PDO::FETCH_OBJ);
            } elseif ($return_type === 'array') {
                $results = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Raw query executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'sql' => $sql,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'return_type' => $return_type,
                    'result_count' => is_array($results) ? count($results) : 0,
                    'operation' => 'raw_query'
                ]);

                // Basic query analysis (limited since it's raw SQL)
                $this->debug()->analyzeQuery($sql, [], $executionTime, [
                    'operation' => 'raw_query',
                    'return_type' => $return_type,
                    'result_count' => is_array($results) ? count($results) : 0,
                    'sql_length' => strlen($sql),
                    'query_type' => ModelDebugHelper::detectQueryType($sql)
                ]);
            }

            return $results;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Raw query failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'sql' => $sql
            ]);
            throw new RuntimeException("Query execution failed: " . $e->getMessage());
        }
    }

    /**
     * Execute parameterized SQL query with secure parameter binding
     * 
     * Safe SQL execution with PDO parameter binding to prevent SQL injection.
     * Supports named parameters and multiple result formats. Preferred method
     * for custom queries requiring user input.
     * 
     * @param string $sql SQL query with named placeholders (:param_name)
     * @param array $data Associative array of parameter values to bind
     * @param string|null $return_type Result format: 'object', 'array', or null for no results
     * @return array|object|null Query results in specified format, or null for non-SELECT queries
     * @throws RuntimeException If query execution fails
     * 
     * @example
     * // Safe parameterized query
     * $users = $model->query_bind(
     *     "SELECT * FROM users WHERE status = :status AND created_at > :date",
     *     ['status' => 'active', 'date' => '2024-01-01'],
     *     'object'
     * );
     * 
     * @example
     * // Update with parameters
     * $model->query_bind(
     *     "UPDATE users SET last_login = :now WHERE id = :user_id",
     *     ['now' => date('Y-m-d H:i:s'), 'user_id' => 123]
     * );
     */
    public function query_bind(string $sql, array $data, ?string $return_type = null): array|object|null
    {
        $this->connect();
        $startTime = microtime(true);

        $this->debugLog("Raw query bind execution", DebugCategory::SQL, DebugLevel::DETAILED, [
            'sql' => $sql,
            'data_keys' => array_keys($data),
            'return_type' => $return_type,
            'sql_length' => strlen($sql)
        ]);

        try {
            $this->stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($this->stmt, $data);

            $result = null;
            if ($return_type === 'object') {
                $result = $this->stmt->fetchAll(PDO::FETCH_OBJ);
            } elseif ($return_type === 'array') {
                $result = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Raw query bind executed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'sql' => $sql,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'return_type' => $return_type,
                    'result_count' => is_array($result) ? count($result) : 0,
                    'operation' => 'raw_query'
                ]);

                // Basic query analysis (limited since it's raw SQL)
                $this->debug()->analyzeQuery($sql, $data, $executionTime, [
                    'operation' => 'raw_query',
                    'return_type' => $return_type,
                    'result_count' => is_array($result) ? count($result) : 0,
                    'sql_length' => strlen($sql),
                    'query_type' => ModelDebugHelper::detectQueryType($sql)
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Raw query bind failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'sql' => $sql
            ]);
            throw new RuntimeException("Query execution failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Execute SQL statement for development/administrative purposes only
     * 
     * Direct SQL execution method restricted to development environments for
     * database schema operations, data migration, and administrative tasks.
     * Provides safety controls to prevent misuse in production.
     * 
     * ⚠️ **SECURITY WARNING**: This method executes raw SQL without validation.
     * Use only in controlled environments with trusted input.
     * 
     * @param string $sql SQL statement to execute
     * @return mixed Query results if applicable, null for non-SELECT operations
     * @throws Exception If not in development environment
     * @throws RuntimeException If SQL execution fails
     * 
     * @example
     * // Schema operations (development only)
     * if (ENV === 'dev') {
     *     $model->exec("CREATE INDEX idx_users_email ON users (email)");
     *     $model->exec("ALTER TABLE products ADD COLUMN category_id INT");
     * }
     * 
     * @example
     * // Data migration (development only)
     * $model->exec("UPDATE users SET created_at = NOW() WHERE created_at IS NULL");
     */
    public function exec(string $sql): mixed
    {
        if (!defined('ENV') || ENV !== 'dev') {
            throw new Exception("This feature is disabled...");
        }

        $this->connect();
        $startTime = microtime(true);  // Add timing

        $this->debugLog("Raw SQL execution started", DebugCategory::SQL, DebugLevel::DETAILED, [
            'sql' => $sql,
            'sql_length' => strlen($sql),
            'environment' => 'development'
        ]);

        try {
            $this->stmt = $this->dbh->prepare($sql);
            $this->stmt->execute();

            $result = null;
            if (stripos(trim($sql), 'SELECT') === 0) {
                $result = $this->stmt->fetchAll(PDO::FETCH_OBJ);
            }

            // Add success logging
            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Raw SQL execution completed", DebugCategory::SQL, DebugLevel::BASIC, [
                    'sql_type' => ModelDebugHelper::detectQueryType($sql),
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'result_count' => is_array($result) ? count($result) : 0,
                    'operation' => 'exec'
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();

            // Add error logging
            $this->debugLog("Raw SQL execution failed", DebugCategory::SQL, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'sql' => substr($sql, 0, 200) // Truncate for safety
            ]);

            throw new RuntimeException("Failed to execute SQL statement: " . $e->getMessage());
        }
    }

    // =============================================================================
    // BATCH OPERATIONS (ADDITIONAL BULK METHODS)
    // =============================================================================

    /**
     * Delete multiple records in batch using IN clause with optimized chunking
     * 
     * High-performance batch deletion method using IN clauses with automatic
     * chunking for database compatibility. Supports large deletion sets while
     * respecting database variable limits and maintaining transaction safety.
     * 
     * @param string $table Table name to delete from (validated)
     * @param string $identifierField Column name for WHERE condition (validated)  
     * @param array $identifiers Array of values to match for deletion
     * @param int|null $chunkSize Optional chunk size override (auto-calculated if null)
     * @return int Total number of records successfully deleted
     * @throws InvalidArgumentException If validation fails or identifiers is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Delete multiple users by ID
     * $deletedCount = $model->delete_batch('users', 'id', [1, 2, 3, 4, 5]);
     * 
     * @example
     * // Delete products by category with custom chunk size
     * $categories = [10, 20, 30, 40, 50];
     * $deleted = $model->delete_batch('products', 'category_id', $categories, 100);
     * 
     * @example
     * // Delete sessions by status
     * $expiredSessions = ['expired', 'invalid', 'terminated'];
     * $model->delete_batch('sessions', 'status', $expiredSessions);
     */
    public function delete_batch(string $table, string $identifierField, array $identifiers, int|null $chunkSize = null): int
    {
        if (empty($identifiers)) {
            return 0;
        }

        $this->connect();
        $startTime = microtime(true);

        // Validate table and column names
        QueryBuilder::validateTableName($table);
        QueryBuilder::validateColumnName($identifierField);

        // Auto-detect bulk operation for optimization
        $this->performance()->detectBulkOperation(count($identifiers));

        $this->debugLog("Batch delete started", DebugCategory::BULK, DebugLevel::BASIC, [
            'table' => $table,
            'identifier_field' => $identifierField,
            'identifier_count' => count($identifiers),
            'chunk_size' => $chunkSize,
            'bulk_mode_active' => $this->performance()->isBulkModeActive()
        ]);

        // Calculate optimal chunk size if not provided
        if ($chunkSize === null) {
            $chunkSize = match ($this->dbType) {
                'sqlite' => min(999, 500),
                'mysql' => 1000,
                'postgresql' => 1000,
                default => 500
            };

            $this->debugLog("Chunk size auto-calculated", DebugCategory::BULK, DebugLevel::DETAILED, [
                'calculated_chunk_size' => $chunkSize,
                'database_type' => $this->dbType,
                'identifier_count' => count($identifiers)
            ]);
        }

        try {
            $totalDeleted = 0;
            $chunks = array_chunk($identifiers, $chunkSize);

            $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
            $escapedField = QueryBuilder::escapeIdentifier($identifierField, $this->dbType);

            foreach ($chunks as $chunkIndex => $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sql = "DELETE FROM $escapedTable WHERE $escapedField IN ($placeholders)";

                $stmt = $this->getPreparedStatement($sql);

                foreach ($chunk as $index => $value) {
                    $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
                }

                $stmt->execute();
                $deletedInChunk = $stmt->rowCount();
                $totalDeleted += $deletedInChunk;

                if ($this->debug && ($chunkIndex % 10 === 0 || $chunkIndex === count($chunks) - 1)) {
                    $this->debugLog("Batch delete progress", DebugCategory::BULK, DebugLevel::DETAILED, [
                        'chunk' => $chunkIndex + 1,
                        'total_chunks' => count($chunks),
                        'chunk_size' => count($chunk),
                        'deleted_in_chunk' => $deletedInChunk,
                        'total_deleted' => $totalDeleted
                    ]);
                }
            }

            if ($this->debug) {
                $executionTime = microtime(true) - $startTime;

                $this->debugLog("Batch delete completed", DebugCategory::BULK, DebugLevel::BASIC, [
                    'table' => $table,
                    'identifier_field' => $identifierField,
                    'identifiers_requested' => count($identifiers),
                    'records_deleted' => $totalDeleted,
                    'chunks_processed' => count($chunks),
                    'chunk_size' => $chunkSize,
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'deletions_per_second' => $executionTime > 0 ? round($totalDeleted / $executionTime) : 0,
                    'operation' => 'delete_batch'
                ]);

                // Bulk operation analysis
                $this->debug()->analyzeBulkOperation('delete_batch', count($identifiers), [
                    'table' => $table,
                    'chunk_size' => $chunkSize,
                    'execution_time' => $executionTime,
                    'records_per_second' => $executionTime > 0 ? round($totalDeleted / $executionTime) : 0,
                    'database_type' => $this->dbType,
                    'total_deleted' => $totalDeleted,
                    'success_rate' => count($identifiers) > 0 ? ($totalDeleted / count($identifiers)) * 100 : 0
                ]);
            }

            return $totalDeleted;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->debugLog("Batch delete failed", DebugCategory::BULK, DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'table' => $table,
                'identifiers_count' => count($identifiers)
            ]);
            throw new RuntimeException("Failed to execute delete_batch operation: " . $e->getMessage());
        }
    }

    /**
     * High-performance bulk insert with automatic optimization and smart chunking
     * 
     * Advanced bulk insertion method with comprehensive optimization features:
     * - Automatic chunk size calculation based on record complexity and system resources
     * - Database-specific variable limit handling (SQLite 999-variable limit)
     * - Adaptive performance monitoring and learning
     * - Memory-aware processing to prevent system overload
     * - Transaction safety with automatic rollback on failure
     * 
     * Optimization features:
     * - Auto-detects optimal chunk sizes for your table structure
     * - Respects SQLite variable limits automatically
     * - Tracks and learns from performance patterns
     * - Enables database-specific bulk optimizations
     * 
     * @param string $table Target table name (validated for safety)
     * @param array $records Array of associative arrays (column => value pairs)
     * @param int|null $chunkSize Optional chunk size override (auto-calculated if null)
     * @return int Number of records successfully inserted
     * @throws InvalidArgumentException If table/column validation fails or records are empty
     * @throws RuntimeException If bulk operation fails
     * 
     * @example
     * // Bulk insert with auto-optimization
     * $inserted = $model->insert_batch('users', [
     *     ['name' => 'John', 'email' => 'john@example.com'],
     *     ['name' => 'Jane', 'email' => 'jane@example.com'],
     *     // ... thousands more records
     * ]);
     * 
     * @example
     * // With custom chunk size
     * $inserted = $model->insert_batch('products', $productData, 500);
     * 
     * @example
     * // Large dataset processing
     * $model->performance()->enablePerformanceMode(); // Enable all optimizations
     * $inserted = $model->insert_batch('analytics_events', $largeDataset);
     */
    public function insert_batch(string $table, array $records, int|null $chunkSize = null): int
    {
        if (empty($records)) {
            return 0;
        }

        $this->connect();
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $this->debugLog("Bulk insert started", DebugCategory::BULK, DebugLevel::BASIC, [
            'table' => $table,
            'record_count' => count($records),
            'requested_chunk_size' => $chunkSize,
            'start_memory' => $startMemory
        ]);

        // Simple bulk detection - no complex learning
        $this->performance()->detectBulkOperation(count($records));

        // Calculate optimal chunk size based on database limits
        if ($chunkSize === null) {
            $chunkSize = $this->performance()->calculateOptimalChunkSize($records[0]);

            $this->debugLog("Chunk size auto-calculated", DebugCategory::BULK, DebugLevel::DETAILED, [
                'calculated_chunk_size' => $chunkSize,
                'field_count' => count($records[0]),
                'record_complexity' => strlen(serialize($records[0]))
            ]);
        }

        $totalInserted = $this->ultraFastBulkInsert($table, $records, $chunkSize);

        $executionTime = microtime(true) - $startTime;
        $endMemory = memory_get_usage(true);
        $recordsPerSecond = $executionTime > 0 ? round(count($records) / $executionTime) : 0;

        // Comprehensive operation logging
        $this->debugLog("Bulk insert completed", DebugCategory::BULK, DebugLevel::BASIC, [
            'operation' => 'insert_batch',
            'table' => $table,
            'record_count' => count($records),
            'records_inserted' => $totalInserted,
            'chunk_size' => $chunkSize,
            'execution_time' => $executionTime,
            'execution_time_ms' => round($executionTime * 1000, 2),
            'records_per_second' => $recordsPerSecond,
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => memory_get_peak_usage(true),
            'bulk_mode_active' => $this->performance()->isBulkModeActive(),
            'database_type' => $this->dbType,
            'success_rate' => count($records) > 0 ? ($totalInserted / count($records)) * 100 : 0
        ]);

        // Advanced bulk operation analysis
        if ($this->debug) {
            $this->debug()->analyzeBulkOperation('insert_batch', count($records), [
                'table' => $table,
                'chunk_size' => $chunkSize,
                'execution_time' => $executionTime,
                'records_per_second' => $recordsPerSecond,
                'bulk_mode_active' => $this->performance()->isBulkModeActive(),
                'optimizations' => ModelDebugHelper::getAppliedOptimizations($this),
                'database_type' => $this->dbType,
                'memory_usage' => $endMemory - $startMemory,
                'field_count' => count($records[0])
            ]);
        }

        return $totalInserted;
    }

    /**
     * Update multiple records in batch with intelligent strategy selection
     * 
     * High-performance batch update method that automatically selects the optimal
     * strategy based on dataset size and database type. Delegates complex logic
     * to BatchUpdateFactory for clean separation of concerns.
     * 
     * **CASE Statement Strategy** (Default for moderate datasets):
     * - Uses SQL CASE statements for updates up to 2,000 records
     * - Fast for moderate-sized batches with good memory usage
     * - Single query execution with parameter binding
     * 
     * **Temp Table Strategy** (Massive datasets):
     * - Uses temporary tables with JOIN/UPDATE for 2,000+ records
     * - Dramatically faster for massive datasets (10x-50x improvement)
     * - Optimized for PostgreSQL with synchronous_commit=off
     * - Automatic cleanup and transaction safety
     * 
     * @param string $table Table name to update (validated)
     * @param string $identifierField Column name for record identification (validated)
     * @param array $updates Array of updates: [['id' => 1, 'data' => ['name' => 'New Name']], ...]
     * @param int|null $chunkSize Optional chunk size override (auto-calculated if null)
     * @return int Total number of records successfully updated
     * @throws InvalidArgumentException If validation fails or updates is empty
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Moderate batch update (uses CASE statements)
     * $updates = [
     *     ['id' => 1, 'data' => ['name' => 'John Doe', 'email' => 'john@example.com']],
     *     ['id' => 2, 'data' => ['name' => 'Jane Smith', 'email' => 'jane@example.com']]
     * ];
     * $updated = $model->update_batch('users', 'id', $updates);
     * 
     * @example
     * // Massive batch update (automatically uses temp table strategy)
     * $massiveUpdates = []; // 5,000+ records
     * foreach ($csvData as $row) {
     *     $massiveUpdates[] = ['id' => $row['id'], 'data' => ['price' => $row['new_price']]];
     * }
     * $model->update_batch('products', 'id', $massiveUpdates); // Uses temp table for performance
     */
    public function update_batch(string $table, string $identifierField, array $updates, int|null $chunkSize = null): int
    {
        if (empty($updates)) {
            $this->debugLog("Empty updates array provided", DebugCategory::BULK, DebugLevel::BASIC);
            return 0;
        }

        $this->connect();

        require_once dirname(__DIR__) . '/database/engine/factories/BatchUpdate.php';

        // Create factory instance and delegate the complex logic
        $factory = new BatchUpdateFactory($this);

        // Execute batch update using intelligent strategy selection
        return $factory->executeBatchUpdate($table, $identifierField, $updates, $chunkSize);
    }


    // =============================================================================
    // SAFE TRANSACTION MANAGEMENT WITH NESTED SUPPORT
    // =============================================================================

    /**
     * Safe transaction wrapper with automatic nested transaction handling
     * 
     * Provides comprehensive transaction management with automatic rollback on exceptions,
     * nested transaction support using savepoints, timeout detection, and memory management.
     * Eliminates common transaction pitfalls and provides foolproof database operations.
     * 
     * @param callable|null $callback Optional callback function to execute within transaction
     * @param array $options Transaction configuration options
     * @return mixed Result of callback function, or TransactionManager instance for manual use
     * @throws InvalidArgumentException If callback is not callable when provided
     * @throws RuntimeException If transaction operations fail
     * @throws TransactionTimeoutException If transaction exceeds time limit
     * 
     * @example
     * // Closure-based transaction (recommended)
     * $result = $model->transaction(function() use ($model) {
     *     $userId = $model->insert($userData, 'users');
     *     $model->insert(['user_id' => $userId, 'role' => 'admin'], 'user_roles');
     *     return $userId; // Automatically committed
     * });
     * 
     * @example
     * // Manual transaction management
     * $tx = $model->transaction();
     * try {
     *     $userId = $model->insert($userData, 'users');
     *     $model->insert(['user_id' => $userId, 'role' => 'admin'], 'user_roles');
     *     $tx->commit();
     * } catch (Exception $e) {
     *     $tx->rollback(); // Explicit rollback
     *     throw $e;
     * }
     * 
     * @example
     * // Nested transactions with savepoints
     * $model->transaction(function() use ($model) {
     *     $model->insert($outerData, 'table1');
     *     
     *     $model->transaction(function() use ($model) {
     *         $model->insert($innerData, 'table2'); // Uses savepoint
     *         // Inner transaction can rollback independently
     *     });
     *     
     *     $model->insert($moreData, 'table3');
     * });
     */
    public function transaction(?callable $callback = null, array $options = []): mixed
    {
        $this->connect();
        $startTime = microtime(true);

        // If no callback provided, return a TransactionManager for manual use
        if ($callback === null) {
            require_once dirname(__DIR__) . '/database/engine/factories/DatabaseTransaction.php';
            return new TransactionManager($this, $options);
        }

        // Validate callback
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Transaction callback must be callable');
        }

        // Parse options with defaults
        $timeout = $options['timeout'] ?? self::MAX_TRANSACTION_DURATION;
        $memoryLimit = $options['memory_limit'] ?? null;
        $enableDebugging = $options['debug'] ?? $this->debug;

        // FIXED: Check if nested BEFORE incrementing
        $isNestedTransaction = $this->transactionLevel > 0;
        $savepointName = null;
        $startMemory = memory_get_usage(true);

        // Replace echo with debug system integration
        $this->debugLog(
            "Transaction starting",
            DebugCategory::TRANSACTION,
            DebugLevel::BASIC,
            [
                'transaction_level' => $this->transactionLevel + 1,
                'is_nested' => $isNestedTransaction,
                'transaction_type' => $isNestedTransaction ? 'nested_savepoint' : 'root_transaction',
                'timeout' => $timeout,
                'memory_limit' => $memoryLimit,
                'debugging_enabled' => $enableDebugging,
                'supports_savepoints' => $this->supportsSavepoints()
            ]
        );

        // FIXED: Increment level AFTER determining if nested
        $this->transactionLevel++;

        try {
            // Handle nested transactions with savepoints
            if ($isNestedTransaction) {
                if ($this->supportsSavepoints()) {
                    $savepointName = 'sp_' . (++$this->savepointCounter);
                    $this->createSavepoint($savepointName);

                    $this->debugLog(
                        "Savepoint created for nested transaction",
                        DebugCategory::TRANSACTION,
                        DebugLevel::DETAILED,
                        [
                            'savepoint_name' => $savepointName,
                            'transaction_level' => $this->transactionLevel,
                            'savepoint_counter' => $this->savepointCounter,
                            'database_type' => $this->dbType
                        ]
                    );
                } else {
                    // For databases without savepoint support, use reference counting
                    $this->debugLog(
                        "Nested transaction using reference counting (no savepoint support)",
                        DebugCategory::TRANSACTION,
                        DebugLevel::DETAILED,
                        [
                            'transaction_level' => $this->transactionLevel,
                            'database_type' => $this->dbType,
                            'savepoint_support' => false,
                            'fallback_method' => 'reference_counting'
                        ]
                    );
                }
            } else {
                // Start root transaction
                if ($this->transactionStartTime === null) {
                    $this->transactionStartTime = $startTime;
                }

                if (!$this->dbh->beginTransaction()) {
                    throw new RuntimeException('Failed to begin transaction');
                }

                $this->debugLog(
                    "Root transaction started",
                    DebugCategory::TRANSACTION,
                    DebugLevel::BASIC,
                    [
                        'transaction_level' => $this->transactionLevel,
                        'transaction_start_time' => $this->transactionStartTime,
                        'database_type' => $this->dbType,
                        'pdo_transaction_active' => $this->dbh->inTransaction()
                    ]
                );
            }

            // Execute callback with monitoring
            $result = $this->executeWithMonitoring($callback, $timeout, $memoryLimit, $enableDebugging);

            // Handle commit based on transaction level
            if ($isNestedTransaction) {
                if ($savepointName) {
                    $this->releaseSavepoint($savepointName);

                    $this->debugLog(
                        "Savepoint released successfully",
                        DebugCategory::TRANSACTION,
                        DebugLevel::DETAILED,
                        [
                            'savepoint_name' => $savepointName,
                            'transaction_level' => $this->transactionLevel,
                            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                        ]
                    );
                }
            } else {
                // Commit root transaction
                if (!$this->dbh->commit()) {
                    throw new RuntimeException('Failed to commit transaction');
                }

                $this->transactionStartTime = null;
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $memoryUsed = $this->formatBytes(memory_get_usage(true) - $startMemory);

                $this->debugLog(
                    "Root transaction committed successfully",
                    DebugCategory::TRANSACTION,
                    DebugLevel::BASIC,
                    [
                        'transaction_level' => $this->transactionLevel,
                        'execution_time_ms' => $duration,
                        'memory_used' => $memoryUsed,
                        'memory_used_bytes' => memory_get_usage(true) - $startMemory,
                        'callback_executed' => true,
                        'operation' => 'transaction_commit'
                    ]
                );
            }

            // FIXED: Decrement transaction level only on success
            $this->transactionLevel--;

            return $result;
        } catch (Exception $e) {
            // FIXED: Handle rollback based on transaction level and error type
            try {
                if ($isNestedTransaction) {
                    if ($savepointName) {
                        $this->rollbackToSavepoint($savepointName);

                        $this->debugLog(
                            "Rollback to savepoint completed",
                            DebugCategory::TRANSACTION,
                            DebugLevel::BASIC,
                            [
                                'savepoint_name' => $savepointName,
                                'transaction_level' => $this->transactionLevel,
                                'error_message' => $e->getMessage(),
                                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                                'rollback_reason' => 'exception_occurred'
                            ]
                        );
                    }
                } else {
                    // Rollback root transaction
                    if ($this->dbh->inTransaction()) {
                        $this->dbh->rollback();
                    }

                    $this->transactionStartTime = null;

                    $this->debugLog(
                        "Root transaction rolled back due to exception",
                        DebugCategory::TRANSACTION,
                        DebugLevel::BASIC,
                        [
                            'transaction_level' => $this->transactionLevel,
                            'error_message' => $e->getMessage(),
                            'error_code' => $e->getCode(),
                            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                            'rollback_reason' => 'exception_occurred',
                            'operation' => 'transaction_rollback'
                        ]
                    );
                }
            } catch (Exception $rollbackException) {
                // Log rollback failure but throw original exception
                $this->debugLog(
                    "Transaction rollback failed",
                    DebugCategory::TRANSACTION,
                    DebugLevel::BASIC,
                    [
                        'original_error' => $e->getMessage(),
                        'rollback_error' => $rollbackException->getMessage(),
                        'transaction_level' => $this->transactionLevel,
                        'critical_failure' => true
                    ]
                );
                error_log("Transaction rollback failed: " . $rollbackException->getMessage());
            }

            // FIXED: Decrement transaction level in finally block to ensure it always happens
            $this->transactionLevel--;

            // Re-throw original exception with enhanced context
            throw new RuntimeException(
                "Transaction failed at level " . $this->transactionLevel . ": " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }






    /**
     * Check if database supports savepoints
     * 
     * @return bool True if savepoints are supported
     */
    public function supportsSavepoints(): bool
    {
        return match ($this->dbType) {
            'mysql', 'postgresql' => true,
            'sqlite' => false, // SQLite savepoints have limitations in PDO
            default => false
        };
    }

    /**
     * Create a savepoint for nested transactions
     * 
     * @param string $name Savepoint name
     * @throws RuntimeException If savepoint creation fails
     */
    public function createSavepoint(string $name): void
    {
        $sql = match ($this->dbType) {
            'mysql', 'postgresql' => "SAVEPOINT $name",
            default => throw new RuntimeException("Savepoints not supported for database type: {$this->dbType}")
        };

        $this->dbh->exec($sql);
    }

    /**
     * Release a savepoint (commit nested transaction)
     * 
     * @param string $name Savepoint name
     * @throws RuntimeException If savepoint release fails
     */
    public function releaseSavepoint(string $name): void
    {
        $sql = match ($this->dbType) {
            'mysql', 'postgresql' => "RELEASE SAVEPOINT $name",
            default => throw new RuntimeException("Savepoints not supported for database type: {$this->dbType}")
        };

        $this->dbh->exec($sql);
    }

    /**
     * Rollback to a savepoint (rollback nested transaction)
     * 
     * @param string $name Savepoint name
     * @throws RuntimeException If rollback to savepoint fails
     */
    public function rollbackToSavepoint(string $name): void
    {
        $sql = match ($this->dbType) {
            'mysql', 'postgresql' => "ROLLBACK TO SAVEPOINT $name",
            default => throw new RuntimeException("Savepoints not supported for database type: {$this->dbType}")
        };

        $this->dbh->exec($sql);
    }

    // =============================================================================
    // DATABASE INTROSPECTION METHODS (CROSS-DATABASE COMPATIBILITY)
    // =============================================================================

    /**
     * Check if a table exists in the database (cross-database compatible)
     * 
     * Database-agnostic table existence check that works across MySQL, SQLite,
     * and PostgreSQL. Uses appropriate system catalog queries for each database
     * type while maintaining consistent API and validation.
     * 
     * @param string $table_name Name of table to check for existence (validated)
     * @return bool True if table exists, false otherwise
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Check if users table exists before creating
     * if (!$model->table_exists('users')) {
     *     $model->query("CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(255))");
     * }
     * 
     * @example
     * // Conditional table operations
     * if ($model->table_exists('temp_data')) {
     *     $model->query("DROP TABLE temp_data");
     * }
     */
    public function table_exists(string $table_name): bool
    {
        $this->connect();
        QueryBuilder::validateTableName($table_name);

        try {
            switch ($this->dbType) {
                case 'mysql':
                    // FIXED: Use direct query with escaped table name
                    $escapedTableName = $this->dbh->quote($table_name);
                    $sql = "SHOW TABLES LIKE $escapedTableName";
                    $stmt = $this->dbh->query($sql);
                    return $stmt !== false && $stmt->fetch() !== false;

                case 'sqlite':
                    $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name = :table_name";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt, ['table_name' => $table_name]);
                    return $stmt->fetch() !== false;

                case 'postgresql':
                    $sql = "SELECT tablename FROM pg_tables WHERE tablename = :table_name";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt, ['table_name' => $table_name]);
                    return $stmt->fetch() !== false;

                default:
                    throw new RuntimeException("Unsupported database type: {$this->dbType}");
            }
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to check table existence: " . $e->getMessage());
        }
    }

    /**
     * Get all table names from the database (cross-database compatible)
     * 
     * Retrieves a list of all user tables in the database, excluding system
     * tables. Works consistently across MySQL, SQLite, and PostgreSQL with
     * appropriate filtering for each database type.
     * 
     * @return array Array of table names in the database
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // List all tables for backup
     * $tables = $model->get_all_tables();
     * foreach ($tables as $table) {
     *     echo "Backing up table: $table\n";
     * }
     * 
     * @example
     * // Check if specific tables exist
     * $tables = $model->get_all_tables();
     * $requiredTables = ['users', 'products', 'orders'];
     * $missing = array_diff($requiredTables, $tables);
     */
    public function get_all_tables(): array
    {
        $this->connect();

        try {
            switch ($this->dbType) {
                case 'mysql':
                    $sql = "SHOW TABLES";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt);
                    return $stmt->fetchAll(PDO::FETCH_COLUMN);

                case 'sqlite':
                    $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt);
                    return $stmt->fetchAll(PDO::FETCH_COLUMN);

                case 'postgresql':
                    $sql = "SELECT tablename FROM pg_tables WHERE schemaname = 'public'";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt);
                    return $stmt->fetchAll(PDO::FETCH_COLUMN);

                default:
                    throw new RuntimeException("Unsupported database type for get_all_tables: {$this->dbType}");
            }
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to retrieve table list: " . $e->getMessage());
        }
    }

    /**
     * Describe table structure with cross-database compatibility
     * 
     * Retrieves detailed information about table columns including names, types,
     * nullability, and default values. Provides consistent output format across
     * different database systems with optional column-names-only mode.
     * 
     * @param string $table Table name to describe (validated)
     * @param bool $column_names_only If true, return only column names array
     * @return array|false Column details array or column names, false on failure
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If database operation fails
     * 
     * @example
     * // Get full table structure
     * $structure = $model->describe_table('users');
     * foreach ($structure as $column) {
     *     echo "Column: {$column['Field']}, Type: {$column['Type']}\n";
     * }
     * 
     * @example
     * // Get just column names
     * $columns = $model->describe_table('products', true);
     * // Returns: ['id', 'name', 'price', 'category_id', 'created_at']
     */
    /**
     * Describe table structure with cross-database compatibility
     * 
     * Retrieves detailed information about table columns including names, types,
     * nullability, and default values. Provides consistent output format across
     * different database systems with optional column-names-only mode.
     * 
     * @param string $table Table name to describe (validated)
     * @param bool $column_names_only If true, return only column names array
     * @return array|false Column details array or column names, false on failure
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If database operation fails
     */
    public function describe_table(string $table, bool $column_names_only = false): array|false
    {
        $this->connect();
        QueryBuilder::validateTableName($table);

        // Use the fixed table_exists method
        if (!$this->table_exists($table)) {
            return false;
        }

        try {
            switch ($this->dbType) {
                case 'mysql':
                    // FIXED: Use direct query for MySQL DESCRIBE
                    $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);
                    $sql = "DESCRIBE $escapedTable";
                    $stmt = $this->dbh->query($sql);

                    if ($stmt === false) {
                        return false;
                    }

                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if ($column_names_only) {
                        return array_column($columns, 'Field');
                    }
                    return $columns;

                case 'sqlite':
                    $sql = "PRAGMA table_info(" . QueryBuilder::escapeIdentifier($table, $this->dbType) . ")";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt);
                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // FIXED: Double-check that we got results (shouldn't happen now with table_exists check)
                    if (empty($columns)) {
                        return false;
                    }

                    if ($column_names_only) {
                        return array_column($columns, 'name');
                    }

                    // Convert SQLite format to MySQL-like format for consistency
                    $formatted = [];
                    foreach ($columns as $col) {
                        $formatted[] = [
                            'Field' => $col['name'],
                            'Type' => $col['type'],
                            'Null' => $col['notnull'] ? 'NO' : 'YES',
                            'Key' => $col['pk'] ? 'PRI' : '',
                            'Default' => $col['dflt_value'],
                            'Extra' => $col['pk'] ? 'auto_increment' : ''
                        ];
                    }
                    return $formatted;

                case 'postgresql':
                    $sql = "SELECT column_name, data_type, is_nullable, column_default 
                            FROM information_schema.columns 
                            WHERE table_name = :table_name 
                            ORDER BY ordinal_position";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt, ['table_name' => $table]);
                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // FIXED: Check for empty results
                    if (empty($columns)) {
                        return false;
                    }

                    if ($column_names_only) {
                        return array_column($columns, 'column_name');
                    }

                    // Convert PostgreSQL format to MySQL-like format for consistency
                    $formatted = [];
                    foreach ($columns as $col) {
                        $formatted[] = [
                            'Field' => $col['column_name'],
                            'Type' => $col['data_type'],
                            'Null' => $col['is_nullable'],
                            'Key' => '',
                            'Default' => $col['column_default'],
                            'Extra' => ''
                        ];
                    }
                    return $formatted;

                default:
                    throw new RuntimeException("Unsupported database type for describe_table: {$this->dbType}");
            }
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }


    /**
     * Resequence table IDs with cross-database compatibility and safety checks
     * 
     * Safely resequences auto-increment IDs in a table starting from 1, handling
     * all major database types with appropriate auto-increment reset procedures.
     * Includes comprehensive transaction safety and conflict resolution.
     * 
     * ⚠️ **WARNING**: This operation modifies primary keys and may affect referential
     * integrity. Use with extreme caution and always backup data first.
     * 
     * @param string $table_name Table to resequence (validated)
     * @return bool True if resequencing succeeded, false otherwise
     * @throws InvalidArgumentException If table name validation fails
     * @throws RuntimeException If operation fails or database is unsupported
     * 
     * @example
     * // Resequence user IDs after cleanup
     * $model->beginTransaction();
     * try {
     *     $success = $model->resequence_ids('users');
     *     if ($success) {
     *         $model->commit();
     *         echo "User IDs resequenced successfully";
     *     } else {
     *         $model->rollback();
     *     }
     * } catch (Exception $e) {
     *     $model->rollback();
     *     error_log("Resequencing failed: " . $e->getMessage());
     * }
     */
    public function resequence_ids(string $table_name): bool
    {
        $this->connect();

        // Validate table name using QueryBuilder
        QueryBuilder::validateTableName($table_name);

        // Check if table is empty first
        $count = $this->count($table_name);
        if ($count === 0) {
            // Reset auto-increment for empty table
            return $this->resetAutoIncrement($table_name);
        }

        try {
            // Begin transaction for safety
            $wasInTransaction = $this->dbh->inTransaction();
            if (!$wasInTransaction) {
                $this->dbh->beginTransaction();
            }

            // Get all rows ordered by current ID
            $escapedTable = QueryBuilder::escapeIdentifier($table_name, $this->dbType);
            $sql = "SELECT * FROM $escapedTable ORDER BY id";
            $stmt = $this->getPreparedStatement($sql);
            $this->executeStatement($stmt);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                if (!$wasInTransaction) {
                    $this->dbh->commit();
                }
                return $this->resetAutoIncrement($table_name);
            }

            // First pass: assign temporary negative IDs to avoid conflicts
            $counter = 1;
            foreach ($rows as $row) {
                $currentId = $row['id'];
                $tempId = -$counter;

                $updateSql = "UPDATE $escapedTable SET id = :temp_id WHERE id = :current_id";
                $updateStmt = $this->getPreparedStatement($updateSql);
                $this->executeStatement($updateStmt, [
                    'temp_id' => $tempId,
                    'current_id' => $currentId
                ]);
                $counter++;
            }

            // Second pass: assign new sequential IDs
            $counter = 1;
            foreach ($rows as $row) {
                $tempId = -$counter;
                $newId = $counter;

                $updateSql = "UPDATE $escapedTable SET id = :new_id WHERE id = :temp_id";
                $updateStmt = $this->getPreparedStatement($updateSql);
                $this->executeStatement($updateStmt, [
                    'new_id' => $newId,
                    'temp_id' => $tempId
                ]);
                $counter++;
            }

            // Reset auto-increment counter
            $this->resetAutoIncrement($table_name);

            // Commit transaction if we started it
            if (!$wasInTransaction) {
                $this->dbh->commit();
            }

            return true;
        } catch (Exception $e) {
            // Rollback if we started the transaction
            if (!$wasInTransaction && $this->dbh->inTransaction()) {
                $this->dbh->rollback();
            }

            $this->lastError = $e->getMessage();
            throw new RuntimeException("Failed to resequence IDs for table '$table_name': " . $e->getMessage());
        }
    }

    // =============================================================================
    // DATABASE MAINTENANCE FACTORY METHOD
    // =============================================================================

    /**
     * Database maintenance helper instance
     * 
     * @var DatabaseMaintenance|null
     */
    private ?DatabaseMaintenance $maintenanceHelper = null;

    /**
     * Access database maintenance operations through unified interface
     * 
     * Provides cross-database maintenance operations including vacuum, analyze,
     * reindex, and integrity checking. Each operation is automatically adapted
     * for the current database type (MySQL, SQLite, PostgreSQL).
     * 
     * @return DatabaseMaintenance Maintenance operations interface
     * 
     * @example
     * // Basic maintenance operations
     * $model->maintenance()->vacuum();           // Reclaim space
     * $model->maintenance()->analyze();          // Update statistics
     * $model->maintenance()->checkIntegrity();   // Verify integrity
     * 
     * @example
     * // Comprehensive optimization
     * $model->maintenance()->optimize();         // Full maintenance
     * 
     * @example
     * // Multiple operations with stored reference
     * $maintenance = $model->maintenance();
     * $maintenance->analyze();
     * $maintenance->vacuum();
     * $maintenance->reindex();
     */
    public function maintenance(): DatabaseMaintenance
    {
        if ($this->maintenanceHelper === null) {
            require_once dirname(__DIR__) . '/database/engine/factories/DatabaseMaintenance.php';
            $this->maintenanceHelper = new DatabaseMaintenance($this);
        }
        return $this->maintenanceHelper;
    }

    /**
     * Get migration factory instance
     */
    public function migration(): DatabaseMigrationFactory
    {
        if ($this->migrationFactory === null) {
            require_once dirname(__FILE__) . '/database/engine/factories/DatabaseMigration.php';

            $this->migrationFactory = new DatabaseMigrationFactory($this, [
                'default_chunk_size' => 1000,
                'default_memory_limit' => '256M',
                'enable_progress_tracking' => true,
                'enable_rollback' => true,
                'validate_by_default' => true,
                'log_migrations' => true
            ]);

            // Set debug callback if debug is enabled
            if ($this->debug) {
                $this->migrationFactory->setDebugCallback([$this, 'debugCallback']);
            }
        }

        return $this->migrationFactory;
    }

    /**
     * Enhanced Model Integration
     * 
     * Add this method to your Enhanced Model class:
     */
    public function sqlDumpTranslator(array $options = []): SQLDumpTranslator
    {
        if ($this->sqlTranslator === null) {
            require_once dirname(__FILE__, 2) . '/database/engine/factories/SQLDumpTranslator.php';
            $this->sqlTranslator = new SQLDumpTranslator($this, $options);
        }
        return $this->sqlTranslator;
    }

    /**
     * Access database performance operations through unified interface
     * 
     * Provides comprehensive performance monitoring, optimization, and adaptive learning
     * for bulk operations. Features include auto-bulk detection, memory-aware chunking,
     * and database-specific optimization strategies.
     * 
     * @return DatabasePerformance Performance management interface
     * 
     * @example
     * // Enable performance optimizations
     * $model->performance()->enablePerformanceMode();
     * 
     * @example
     * // Get performance statistics
     * $stats = $model->performance()->getPerformanceStats();
     * 
     * @example
     * // Configure adaptive thresholds
     * $perf = $model->performance();
     * $perf->setBulkThresholds(100, 20);
     * $perf->setAdaptiveMode(true);
     */
    public function performance(): DatabasePerformance
    {
        if ($this->performanceHelper === null) {
            require_once dirname(__DIR__) . '/database/engine/factories/DatabasePerformance.php';

            // Pass simple options only
            $performanceOptions = [
                'debug' => $this->debug,
                'bulkThreshold' => $this->options['bulkThreshold'] ?? 50,
                'autoOptimize' => $this->options['autoOptimize'] ?? true
            ];

            $this->performanceHelper = new DatabasePerformance($this, $performanceOptions);
        }
        return $this->performanceHelper;
    }

    /**
     * Access database backup operations through unified factory interface
     * 
     * Provides comprehensive backup and restore operations across MySQL, SQLite, and PostgreSQL
     * databases with intelligent strategy selection, secure shell command execution, and 
     * comprehensive fallback mechanisms. Features automatic capability detection and 
     * enhanced debug integration.
     * 
     * @return DatabaseBackupFactory Backup operations interface
     * 
     * @example
     * // Basic database backup
     * $result = $model->backup()->createBackup('/path/to/backup.sql');
     * 
     * @example
     * // Advanced backup with options
     * $result = $model->backup()->createBackup('/path/to/backup.sql', [
     *     'compress' => true,
     *     'include_schema' => true,
     *     'timeout' => 1800
     * ]);
     * 
     * @example
     * // Test backup capabilities
     * $capabilities = $model->backup()->testCapabilities();
     * 
     * @example
     * // Restore from backup
     * $result = $model->backup()->restoreBackup('/path/to/backup.sql');
     * 
     * @example
     * // Get backup estimates
     * $estimate = $model->backup()->estimateBackup();
     * echo "Estimated backup size: " . $estimate['estimated_size_bytes'] . " bytes";
     * echo "Estimated duration: " . $estimate['estimated_duration_seconds'] . " seconds";
     */
    public function backup(): DatabaseBackupFactory
    {
        if ($this->backupHelper === null) {
            require_once dirname(__DIR__) . '/database/engine/factories/DatabaseBackup.php';
            $this->backupHelper = new DatabaseBackupFactory($this);
        }
        return $this->backupHelper;
    }

    /**
     * Ultra-lightweight debug logging (zero overhead when disabled)
     * 
     * @param string $message Debug message
     * @param int $category Debug category (DebugCategory constants)
     * @param int $level Debug level (DebugLevel constants)  
     * @param array $context Additional context data
     * @return void
     */
    public function debugLog(string $message, int $category = DebugCategory::SQL, int $level = DebugLevel::BASIC, array $context = []): void
    {
        // Zero overhead when debugging disabled - no objects created, no processing
        if (!$this->debug) return;

        // Lazy load debug collector only when needed
        $this->debug()->log($message, $category, $level, $context);
    }

    /**
     * Factory method for debug collector (lazy loading)
     * 
     * @return DebugCollector Debug collector instance
     */
    private function debug(): DebugCollector
    {
        if ($this->debugCollector === null) {
            $this->debugCollector = DebugFactory::createDebugCollector($this);
        }
        return $this->debugCollector;
    }

    /**
     * Check if debug mode is currently enabled
     * 
     * Returns the current state of debug mode. This method is used by
     * factory classes and backup strategies to determine whether to
     * enable debug callbacks and detailed logging.
     * 
     * @return bool True if debug mode is enabled, false otherwise
     * 
     * @example
     * // Check debug status before setting up callbacks
     * if ($model->isDebugEnabled()) {
     *     $strategy->setDebugCallback(function($msg, $context) {
     *         $this->debugLog($msg, DebugLevel::DETAILED, $context);
     *     });
     * }
     * 
     * @example
     * // Conditional debug setup in factory classes
     * $verboseLogging = $model->isDebugEnabled();
     * $helper = new RobustRestoreHelper($pdo, $dbType, [
     *     'debug_parsing' => $verboseLogging
     * ]);
     */
    public function isDebugEnabled(): bool
    {
        return $this->debug;
    }

    /**
     * Enhanced setDebug method with new capabilities
     * 
     * @param DebugLevel|bool $level Debug level or boolean for backwards compatibility
     * @param int|null $categories Debug categories bitmask
     * @param string $format Output format ('html', 'text', 'json', 'ansi')
     * @return void
     */
    public function setDebug(DebugLevel|bool $level = false, int|null $categories = null, string $format = 'html'): void
    {
        $this->debug = (bool)$level;

        if ($this->debug) {
            require_once dirname(__DIR__) . '/database/engine/factories/Debug.php';
            $this->debug()->configure($level, $categories, $format);
        }
    }

    /**
     * Set debug mode using predefined presets
     * 
     * Available presets:
     * - 'off': Disable debugging completely
     * - 'basic': Basic SQL logging only
     * - 'developer': Detailed debugging with HTML output (recommended for development)
     * - 'performance': Performance monitoring and bulk operation analysis
     * - 'cli': CLI-friendly debugging with ANSI colors
     * - 'production': Production-safe performance monitoring with JSON output
     * - 'verbose': Maximum debugging with all categories and verbose output
     * 
     * @param string $preset Debug preset name
     * @return void
     * @throws InvalidArgumentException If preset name is invalid
     * 
     * @example
     * // Enable developer-friendly debugging
     * $model->setDebugPreset('developer');
     * 
     * @example
     * // Enable CLI debugging for scripts
     * $model->setDebugPreset('cli');
     * 
     * @example
     * // Production monitoring
     * $model->setDebugPreset('production');
     */
    public function setDebugPreset(string $preset): void
    {
        require_once dirname(__DIR__) . '/database/engine/factories/Debug.php';
        DebugPresets::apply($this, $preset);
    }

    /**
     * Enable or disable automatic debug output for real-time feedback
     * 
     * When enabled, debug messages are output immediately as they occur.
     * When disabled, debug messages are collected for later retrieval.
     * 
     * @param bool $enabled True to enable real-time output
     * @return void
     * 
     * @example
     * // Enable real-time debug output for CLI scripts
     * $model->setDebugPreset('cli');
     * $model->setDebugAutoOutput(true);
     */
    public function setDebugAutoOutput(bool $enabled): void
    {
        if ($this->debug && $this->debugCollector !== null) {
            $this->debug()->setAutoOutput($enabled);
        }
    }

    /**
     * Get formatted debug output
     * 
     * @return string Formatted debug output
     */
    public function getDebugOutput(): string
    {
        if (!$this->debug || $this->debugCollector === null) {
            return 'Debug mode not enabled';
        }

        return $this->debug()->getOutput();
    }

    /**
     * Get raw debug data for programmatic access
     * 
     * @return array Raw debug data
     */
    public function getDebugData(): array
    {
        if (!$this->debug || $this->debugCollector === null) {
            return [];
        }

        return $this->debug()->getDebugData();
    }

    /**
     * Export debug session for external analysis
     * 
     * @param string $format Export format ('json', 'csv', 'xml', 'html')
     * @return string Exported debug data
     */
    public function exportDebugSession(string $format = 'json'): string
    {
        return DebugFactory::exportDebugSession($this->debugCollector, $format);
    }

    /**
     * Clear debug messages and reset counters
     * 
     * @return void
     */
    public function clearDebugMessages(): void
    {
        if ($this->debugCollector !== null) {
            $this->debugCollector->clear();
        }
    }

    /**
     * Get database type for maintenance operations
     * 
     * @return string Database type identifier
     */
    public function getDbType(): string
    {
        return $this->dbType;
    }

    // =============================================================================
    // HELPER METHODS
    // =============================================================================

    /**
     * Reverse order direction for get_last() method
     * 
     * @param string $order_by Original order clause
     * @return string Reversed order clause
     */
    private function reverseOrderDirection(string $order_by): string
    {
        // Simple reversal - if contains DESC, remove it; if no direction or ASC, add DESC
        if (stripos($order_by, ' desc') !== false) {
            return str_ireplace(' desc', '', $order_by);
        } elseif (stripos($order_by, ' asc') !== false) {
            return str_ireplace(' asc', ' desc', $order_by);
        } else {
            return $order_by . ' desc';
        }
    }

    /**
     * Retrieve validated and normalized table name with automatic resolution
     * 
     * Resolves table name using the following priority:
     * 1. Explicitly provided target_table parameter
     * 2. Current module name (set in constructor)
     * 3. Throws exception if neither is available
     * 
     * Automatically validates table name using QueryBuilder security validation
     * to prevent SQL injection and ensure identifier compliance.
     * 
     * @param string|null $target_table Optional explicit table name
     * @return string Validated table name ready for use in queries
     * @throws InvalidArgumentException If no table name can be resolved or validation fails
     * 
     * @example
     * // With explicit table name
     * $table = $this->getTableName('custom_table');  // Returns 'custom_table'
     * 
     * @example
     * // Using module name (if current_module = 'users')
     * $table = $this->getTableName(null);  // Returns 'users'
     */
    private function getTableName(?string $target_table = null): string
    {
        $table = $target_table ?? $this->current_module;
        QueryBuilder::validateTableName($table);
        return $table;
    }

    /**
     * Generate optimized SQL using QueryBuilder with intelligent caching
     * 
     * Routes SQL generation through either direct QueryBuilder methods or
     * an additional caching layer depending on performance mode. In fast path
     * mode, bypasses extra caching for maximum speed.
     * 
     * @param string $operation SQL operation type ('simple_select', 'simple_insert', etc.)
     * @param array $params Query parameters for SQL generation
     * @return string Generated SQL statement ready for execution
     */
    private function getOptimizedSQL(string $operation, array $params): string
    {
        // For performance mode, always use direct SQL
        if ($this->shouldUseFastPath()) {
            return $this->queryBuilder->buildQuery($operation, $params);
        }

        // Create cache key for this SQL pattern
        $cacheKey = $operation . '|' . $this->dbType . '|' . serialize($params);

        if (isset(self::$cachedSQL[$cacheKey])) {
            return self::$cachedSQL[$cacheKey];
        }

        // Use QueryBuilder for all SQL generation
        $sql = $this->queryBuilder->buildQuery($operation, $params);

        // Cache the result for reuse
        self::$cachedSQL[$cacheKey] = $sql;
        return $sql;
    }

    /**
     * High-performance single record insert with comprehensive validation
     * 
     * Optimized INSERT operation for single records with QueryBuilder validation,
     * automatic bulk pattern detection, and intelligent prepared statement handling.
     * Designed for maximum speed while maintaining data integrity and security.
     * 
     * @param string $table Target table name (pre-validated)
     * @param array $data Associative array of column => value pairs
     * @return int|false Auto-increment ID of inserted record, or false on failure
     * @throws InvalidArgumentException If table/column validation fails
     * @throws RuntimeException If database operation fails
     */
    private function fastSingleInsert(string $table, array $data): int|false
    {
        // Validate table name using QueryBuilder
        QueryBuilder::validateTableName($table);

        $columns = array_keys($data);

        // Validate columns using QueryBuilder bulk validation
        QueryBuilder::validateIdentifiersBulk($columns, 'column');

        // Build SQL using QueryBuilder escaping
        $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);

        $escapedColumns = [];
        foreach ($columns as $column) {
            $escapedColumns[] = QueryBuilder::escapeIdentifier($column, $this->dbType);
        }

        $columnList = implode(', ', $escapedColumns);
        $placeholders = ':' . implode(', :', $columns);

        $sql = "INSERT INTO $escapedTable ($columnList) VALUES ($placeholders)";

        $this->debugLog("Single insert started", DebugCategory::SQL, DebugLevel::DETAILED, [
            'table' => $table,
            'sql' => $sql,
            'data_keys' => array_keys($data),
            'bulk_mode_triggered' => $this->performance()->isBulkModeActive()
        ]);

        try {
            // Direct prepare for single inserts (no caching needed)
            $stmt = $this->dbh->prepare($sql);

            // Fast parameter binding
            foreach ($data as $key => $value) {
                $stmt->bindValue(":$key", $value, PDO::PARAM_STR);
            }

            $success = $stmt->execute();
            return $success ? (int)$this->dbh->lastInsertId() : false;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Ultra-high-performance bulk insert with comprehensive optimization
     * 
     * Advanced bulk insertion engine with database-specific optimizations,
     * intelligent chunking, and comprehensive error handling. Bypasses
     * most overhead layers for maximum throughput while maintaining safety.
     * 
     * Features:
     * - Automatic chunk size calculation based on database limits
     * - Database-specific variable limit handling (SQLite 999-param limit)
     * - Optimized parameter binding with minimal type detection overhead
     * - Comprehensive error reporting with rollback capabilities
     * - Memory-efficient processing for large datasets
     * 
     * @param string $table Target table name (will be validated)
     * @param array $records Array of record arrays to insert
     * @param int $chunkSize Number of records per database round-trip
     * @return int Total number of records successfully inserted
     * @throws InvalidArgumentException If validation fails
     * @throws RuntimeException If bulk operation fails
     */
    private function ultraFastBulkInsert(string $table, array $records, int $chunkSize): int
    {
        // Validate using QueryBuilder
        QueryBuilder::validateTableName($table);

        $columns = array_keys($records[0]);
        QueryBuilder::validateIdentifiersBulk($columns, 'column');

        try {
            $totalInserted = 0;
            $chunks = array_chunk($records, $chunkSize);

            // Pre-build SQL template using QueryBuilder escaping
            $escapedTable = QueryBuilder::escapeIdentifier($table, $this->dbType);

            $escapedColumns = [];
            foreach ($columns as $column) {
                $escapedColumns[] = QueryBuilder::escapeIdentifier($column, $this->dbType);
            }
            $columnList = implode(', ', $escapedColumns);

            foreach ($chunks as $chunk) {
                if (empty($chunk)) {
                    continue;
                }

                // Build VALUES clause directly (fastest possible)
                $valuePlaceholder = '(' . str_repeat('?,', count($columns));
                $valuePlaceholder = rtrim($valuePlaceholder, ',') . ')';
                $allValues = str_repeat($valuePlaceholder . ',', count($chunk));
                $allValues = rtrim($allValues, ',');

                $sql = "INSERT INTO $escapedTable ($columnList) VALUES $allValues";

                // Prepare statement directly (no caching overhead for bulk)
                $stmt = $this->dbh->prepare($sql);

                // Bind parameters as fast as possible
                $paramIndex = 1;
                foreach ($chunk as $record) {
                    foreach ($columns as $column) {
                        $stmt->bindValue($paramIndex++, $record[$column] ?? null, PDO::PARAM_STR);
                    }
                }

                /*
                if ($this->debug) {
                    $this->debugQuery($sql, ['chunk_size' => count($chunk)]);
                }
                    */

                $stmt->execute();
                $totalInserted += $stmt->rowCount();
            }

            return $totalInserted;
        } catch (PDOException $e) {
            $this->lastError = "Ultra-fast bulk insert failed: " . $e->getMessage();
            throw new RuntimeException($this->lastError);
        }
    }



    /**
     * Reset auto-increment counter for table (database-specific implementation)
     * 
     * @param string $table_name Table name to reset auto-increment
     * @return bool Success status
     */
    private function resetAutoIncrement(string $table_name): bool
    {
        try {
            $escapedTable = QueryBuilder::escapeIdentifier($table_name, $this->dbType);

            switch ($this->dbType) {
                case 'mysql':
                    $sql = "ALTER TABLE $escapedTable AUTO_INCREMENT = 1";
                    break;

                case 'sqlite':
                    $sql = "UPDATE sqlite_sequence SET seq = 0 WHERE name = :table_name";
                    $stmt = $this->getPreparedStatement($sql);
                    $this->executeStatement($stmt, ['table_name' => $table_name]);
                    return true;

                case 'postgresql':
                    // PostgreSQL uses sequences, need to find the sequence name
                    $seqSql = "SELECT pg_get_serial_sequence(:table_name, 'id') as seq_name";
                    $seqStmt = $this->getPreparedStatement($seqSql);
                    $this->executeStatement($seqStmt, ['table_name' => $table_name]);
                    $seqResult = $seqStmt->fetch(PDO::FETCH_ASSOC);

                    if ($seqResult && $seqResult['seq_name']) {
                        $sql = "SELECT setval('{$seqResult['seq_name']}', 1, false)";
                    } else {
                        return true; // No sequence found, nothing to reset
                    }
                    break;

                default:
                    throw new RuntimeException("Unsupported database type for auto-increment reset: {$this->dbType}");
            }

            if (isset($sql)) {
                $stmt = $this->getPreparedStatement($sql);
                $this->executeStatement($stmt);
            }

            return true;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Execute callback with timeout and memory monitoring
     * 
     * @param callable $callback Callback function to execute
     * @param int $timeout Maximum execution time in seconds
     * @param string|null $memoryLimit Maximum memory usage (e.g., '512MB')
     * @param bool $enableDebugging Whether to enable debug output
     * @return mixed Result of callback execution
     * @throws TransactionTimeoutException If timeout is exceeded
     * @throws TransactionMemoryException If memory limit is exceeded
     */
    private function executeWithMonitoring(callable $callback, int $timeout, ?string $memoryLimit, bool $enableDebugging): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Parse memory limit if provided
        $memoryLimitBytes = null;
        if ($memoryLimit !== null) {
            $memoryLimitBytes = $this->parseMemoryLimit($memoryLimit);
        }

        $this->debugLog(
            "Callback execution started with monitoring",
            DebugCategory::TRANSACTION,
            DebugLevel::VERBOSE,
            [
                'timeout_seconds' => $timeout,
                'memory_limit' => $memoryLimit,
                'memory_limit_bytes' => $memoryLimitBytes,
                'start_memory_bytes' => $startMemory,
                'monitoring_enabled' => true
            ]
        );

        try {
            // Execute callback
            $result = $callback();

            $executionTime = microtime(true) - $startTime;
            $memoryDelta = memory_get_usage(true) - $startMemory;

            $this->debugLog(
                "Callback execution completed successfully",
                DebugCategory::TRANSACTION,
                DebugLevel::BASIC,
                [
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'memory_delta_bytes' => $memoryDelta,
                    'memory_delta' => $this->formatBytes($memoryDelta),
                    'timeout_exceeded' => $executionTime > $timeout,
                    'memory_limit_exceeded' => $memoryLimitBytes && memory_get_usage(true) > $memoryLimitBytes,
                    'operation' => 'callback_execution'
                ]
            );

            // Check timeout
            if ($executionTime > $timeout) {
                throw new TransactionTimeoutException(
                    "Transaction exceeded timeout limit of {$timeout} seconds (actual: " .
                        round($executionTime, 2) . "s)"
                );
            }

            // Check memory limit
            if ($memoryLimitBytes && memory_get_usage(true) > $memoryLimitBytes) {
                throw new TransactionMemoryException(
                    "Transaction exceeded memory limit of {$memoryLimit} (current: " .
                        $this->formatBytes(memory_get_usage(true)) . ")"
                );
            }

            return $result;
        } catch (Exception $e) {
            $executionTime = microtime(true) - $startTime;
            $memoryDelta = memory_get_usage(true) - $startMemory;

            $this->debugLog(
                "Callback execution failed",
                DebugCategory::TRANSACTION,
                DebugLevel::BASIC,
                [
                    'error_message' => $e->getMessage(),
                    'error_type' => get_class($e),
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'memory_delta_bytes' => $memoryDelta,
                    'memory_delta' => $this->formatBytes($memoryDelta),
                    'operation' => 'callback_execution_failed'
                ]
            );

            throw $e;
        }
    }

    /**
     * Parse memory limit string into bytes
     * 
     * @param string $limit Memory limit (e.g., '512MB', '2GB', '1024KB')
     * @return int Memory limit in bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim(strtoupper($limit));

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?B?)$/', $limit, $matches)) {
            $number = (float)$matches[1];
            $unit = $matches[2] ?? '';

            $multiplier = match ($unit) {
                'KB', 'K' => 1024,
                'MB', 'M' => 1024 * 1024,
                'GB', 'G' => 1024 * 1024 * 1024,
                'TB', 'T' => 1024 * 1024 * 1024 * 1024,
                default => 1
            };

            return (int)($number * $multiplier);
        }

        // Fallback: assume bytes if parsing fails
        return (int)$limit;
    }

    /**
     * Format bytes to human-readable string
     * 
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g., '1.5 GB', '512 MB')
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }


    // =============================================================================
    // PERFORMANCE METHODS (DELEGATED TO FACTORY)
    // =============================================================================

    /**
     * Enable performance mode with database optimizations
     * 
     * @return void
     */
    public function enablePerformanceMode(): void
    {
        $this->performance()->enablePerformanceMode();
    }

    /**
     * Disable performance mode and restore normal settings
     * 
     * @return void
     */
    public function disablePerformanceMode(): void
    {
        $this->performance()->disablePerformanceMode();
    }

    /**
     * Enable or disable adaptive mode for intelligent optimization
     * 
     * @param bool $enabled True to enable adaptive learning
     * @return void
     */
    public function setAdaptiveMode(bool $enabled): void
    {
        $this->performance()->setAdaptiveMode($enabled);
    }

    /**
     * Manually configure bulk operation thresholds
     * 
     * @param int $recordThreshold Number of records to trigger bulk mode
     * @param int $operationThreshold Number of operations to trigger bulk mode
     * @return void
     */
    public function setBulkThresholds(int $recordThreshold, int $operationThreshold): void
    {
        $this->performance()->setBulkThresholds($recordThreshold, $operationThreshold);
    }

    /**
     * Get comprehensive performance statistics
     * 
     * @return array Performance statistics including cache stats and metrics
     */
    public function getPerformanceStats(): array
    {
        $stats = $this->performance()->getPerformanceStats();

        // Add cache stats from Model if available
        $stats['cache_stats']['cached_sql'] = count(self::$cachedSQL);
        $stats['cache_stats']['prepared_statements'] = count(self::$preparedStatements);

        // Add QueryBuilder performance stats
        $stats['query_builder_stats'] = QueryBuilder::getPerformanceStats();

        return $stats;
    }


    private function shouldUseFastPath(): bool
    {
        return $this->performance()->isPerformanceModeActive() || $this->performance()->isBulkModeActive();
    }

    // =============================================================================
    // STATEMENT AND EXECUTION METHODS
    // =============================================================================

    /**
     * Optimized prepared statement handling
     */
    private function getPreparedStatement(string $sql): PDOStatement
    {
        // Use simpler hash for better performance
        $key = hash('xxh3', $sql . $this->dbType);

        if (!isset(self::$preparedStatements[$key])) {
            self::$preparedStatements[$key] = $this->dbh->prepare($sql);
        }

        return self::$preparedStatements[$key];
    }

    /**
     * Clear prepared statement cache with PostgreSQL-specific handling
     * 
     * @param bool $deallocatePostgreSQL Whether to also run DEALLOCATE ALL for PostgreSQL
     * @return void
     */
    public function clearPreparedStatementCache(bool $deallocatePostgreSQL = false): void
    {
        // Clear the Model's internal caches
        self::$preparedStatements = [];
        self::$cachedSQL = [];

        // PostgreSQL-specific handling
        if ($deallocatePostgreSQL && $this->dbType === 'postgresql') {
            try {
                $this->dbh->exec("DEALLOCATE ALL");
                $this->debugLog("PostgreSQL prepared statements deallocated", DebugCategory::PERFORMANCE, DebugLevel::BASIC);
            } catch (PDOException $e) {
                $this->debugLog("PostgreSQL DEALLOCATE failed", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Clear related component caches
        if (class_exists('DatabaseSecurity')) {
            DatabaseSecurity::clearCaches();
        }

        if (class_exists('DatabaseQueryBuilder')) {
            QueryBuilder::clearCaches();
        }

        $this->debugLog("Complete cache clear completed", DebugCategory::PERFORMANCE, DebugLevel::BASIC, [
            'database_type' => $this->dbType,
            'deallocated_postgresql' => $deallocatePostgreSQL && $this->dbType === 'postgresql'
        ]);
    }

    /**
     * Fast parameter binding
     */
    private function executeStatement(PDOStatement $stmt, array $data = []): bool
    {
        if (empty($data)) {
            return $stmt->execute();
        }

        // Optimized binding - detect types once
        foreach ($data as $key => $value) {
            $paramName = is_int($key) ? $key + 1 : ":$key";
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default => PDO::PARAM_STR
            };
            $stmt->bindValue($paramName, $value, $type);
        }

        return $stmt->execute();
    }

    /**
     * Helper method to display query for debugging
     */
    private static function show_query(string $sql, array $data, string $caveat = ''): string
    {
        $query_to_execute = $sql;

        if (!empty($data)) {
            foreach ($data as $key => $value) {
                $placeholder = is_string($key) ? ":$key" : "?";
                $replacement = is_string($value) ? "'$value'" : $value;
                $query_to_execute = str_replace($placeholder, $replacement, $query_to_execute);
            }
        }

        echo "<div style='background:#ffe79e;padding:10px;margin:10px 0;border:1px solid #ccc;'>";
        echo "<strong>Enhanced Model Query:</strong><br>";
        echo htmlspecialchars($query_to_execute);
        if ($caveat) {
            echo "<br><em>$caveat</em>";
        }
        echo "</div>";

        return $query_to_execute;
    }

    // =============================================================================
    // CONNECTION METHODS
    // =============================================================================

    /**
     * Fast configuration setup with multiple input types
     */
    private function setupConfiguration(PDO|string|array|null $connection): void
    {
        if ($connection instanceof PDO) {
            // Direct PDO connection provided
            $this->dbh = $connection;
            $this->connected = true;
            $this->dbType = DatabaseConfig::detectDbTypeFromPdo($connection);
            return;
        }

        if (is_string($connection)) {
            // Direct connection string provided
            $this->config = DatabaseConfig::parseConnectionString($connection);
        } elseif (is_array($connection)) {
            // Configuration array provided
            $this->config = DatabaseConfig::parseConnectionArray($connection);
        } else {
            // Use global constants (traditional Trongate approach)
            $this->config = DatabaseConfig::createFromGlobals();
        }

        $this->dbType = $this->config->getType();
        return;
    }

    /**
     * Lazy connection establishment with optimizations
     */
    private function connect(): void
    {
        if ($this->connected) return;

        try {
            $this->dbh = $this->createConnection();
            $this->connected = true;

            // Apply database-specific optimizations
            $this->queryBuilder->applyOptimizations($this->dbh);
        } catch (PDOException $e) {
            $this->lastError = "Connection failed: " . $e->getMessage();
            throw new RuntimeException($this->lastError);
        }
    }

    /**
     * Create database connection based on configured database type
     * 
     * Factory method that creates appropriate PDO connection with database-specific
     * optimization settings. Supports MySQL, SQLite, and PostgreSQL databases
     * with optimized connection parameters for each platform.
     * 
     * @return PDO Configured database connection with optimized settings
     * @throws RuntimeException If database type is unsupported or connection fails
     */
    private function createConnection(): PDO
    {
        switch ($this->dbType) {
            case 'mysql':
                return $this->createMySQLConnection();
            case 'sqlite':
                return $this->createSQLiteConnection();
            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                return $this->createPostgreSQLConnection();
            default:
                throw new RuntimeException("Unsupported database type: {$this->dbType}");
        }
    }

    /**
     * Create MySQL database connection with performance-optimized settings
     * 
     * Configures MySQL connection with:
     * - Persistent connections for reduced overhead
     * - Exception error mode for proper error handling
     * - Disabled emulated prepares for true prepared statements
     * - Object fetch mode as default
     * - UTF-8 character set support
     * 
     * @return PDO MySQL database connection
     * @throws RuntimeException If MySQL connection fails
     */
    private function createMySQLConnection(): PDO
    {
        $dsn = 'mysql:host=' . $this->config->getHost() .
            ';port=' . $this->config->getPort() .
            ';dbname=' . $this->config->getDbname() .
            ';charset=' . $this->config->getCharset();

        $options = [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ];

        return new PDO($dsn, $this->config->getUser(), $this->config->getPass(), $options);
    }

    /**
     * Create SQLite database connection with security validation
     * 
     * Configures SQLite connection with:
     * - Database file path validation for security
     * - Exception error mode for proper error handling
     * - Object fetch mode as default
     * - WAL mode and other optimizations applied post-connection
     * 
     * @return PDO SQLite database connection
     * @throws RuntimeException If SQLite connection fails or path is invalid
     */
    private function createSQLiteConnection(): PDO
    {
        DatabaseSecurity::validateSQLitePath($this->config->getDbfile());

        $dsn = 'sqlite:' . $this->config->getDbfile();

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ];

        return new PDO($dsn, null, null, $options);
    }

    /**
     * Create PostgreSQL database connection with performance-optimized settings
     * 
     * Configures PostgreSQL connection with:
     * - Persistent connections for reduced overhead
     * - Exception error mode for proper error handling
     * - Disabled emulated prepares for true prepared statements
     * - Object fetch mode as default
     * - UTF-8 character set support
     * 
     * @return PDO PostgreSQL database connection
     * @throws RuntimeException If PostgreSQL connection fails
     */
    private function createPostgreSQLConnection(): PDO
    {
        $dsn = 'pgsql:host=' . $this->config->getHost() .
            ';port=' . $this->config->getPort() .
            ';dbname=' . $this->config->getDbname();

        $options = [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ];

        return new PDO($dsn, $this->config->getUser(), $this->config->getPass(), $options);
    }

    /**
     * Enhanced debug output with intelligent SQL truncation for bulk operations
     * 
     * Displays comprehensive debugging information including SQL query,
     * parameter values, database type, and QueryBuilder cache statistics.
     * Automatically truncates long bulk SQL queries to show pattern without
     * overwhelming output.
     * 
     * Debug output includes:
     * - Formatted SQL query (truncated for bulk operations)
     * - Parameter values (truncated for security)
     * - Database type and connection info
     * - QueryBuilder cache hit/miss statistics
     * - Bulk operation detection and statistics
     * 
     * @param string $sql SQL query to display
     * @param array $data Parameter values (limited to first 10 for security)
     * @return void
     */
    private function debugQuery(string $sql, array $data): void
    {
        // Legacy HTML output (backwards compatibility)
        if ($this->debug === true) {
            echo self::show_query($sql, $data, $this->query_caveat);
        }

        // New debug system integration
        if ($this->debug && $this->debugCollector !== null) {
            // Just log the SQL - analysis will be done separately
            $this->debugLog("Legacy debug query", DebugCategory::SQL, DebugLevel::VERBOSE, [
                'sql' => $sql,
                'params' => array_slice($data, 0, 10), // Limit params for performance
                'legacy_mode' => true
            ]);
        }
    }



    /**
     * Get direct access to underlying PDO connection
     * 
     * Provides access to the raw PDO instance for advanced operations
     * that require direct database access. Automatically establishes
     * connection if not already connected.
     * 
     * Use cases:
     * - Custom transactions with savepoints
     * - Database-specific operations not covered by Model API
     * - Advanced prepared statement management
     * - Direct access to PDO-specific features
     * 
     * @return PDO Active database connection
     * @throws RuntimeException If connection cannot be established
     * 
     * @example
     * // Advanced transaction with savepoints
     * $pdo = $model->getPDO();
     * $pdo->exec("SAVEPOINT sp1");
     * 
     * @example
     * // Database-specific operations
     * $pdo = $model->getPDO();
     * $pdo->exec("ANALYZE TABLE users");
     */
    public function getPDO(): PDO
    {
        $this->connect();
        return $this->dbh;
    }

    /**
     * Get database configuration instance
     * 
     * Returns the DatabaseConfig instance used by this Model for connection management.
     * Provides access to database type, connection parameters, and configuration details
     * needed by factory classes and advanced components.
     * 
     * @return DatabaseConfig Database configuration instance
     * 
     * @example
     * // Access database configuration details
     * $config = $model->getConfig();
     * echo "Database type: " . $config->getType();
     * echo "Host: " . $config->getHost();
     * echo "Database name: " . $config->getDbname();
     * 
     * @example  
     * // Use in factory classes for connection details
     * $backupFactory = new DatabaseBackupFactory($model);
     * $config = $model->getConfig(); // Used internally by backup factory
     */
    public function getConfig(): DatabaseConfig
    {
        // Ensure configuration is initialized
        if (!isset($this->config)) {
            throw new RuntimeException("Database configuration not initialized. Model connection must be established first.");
        }

        return $this->config;
    }


    // =============================================================================
    // TRANSACTION MANAGEMENT METHODS
    // =============================================================================

    /**
     * Begin database transaction with automatic connection management
     * 
     * Starts a new database transaction, automatically establishing connection
     * if needed. Supports nested transaction detection and provides proper
     * error handling for transaction failures.
     * 
     * Transaction benefits:
     * - Atomic operations (all succeed or all fail)
     * - Data consistency during multi-table operations
     * - Rollback capability for error recovery
     * - Improved performance for bulk operations
     * 
     * @return bool True if transaction started successfully, false on failure
     * @throws RuntimeException If database connection fails
     * 
     * @example
     * // Safe multi-table operation
     * $model->beginTransaction();
     * try {
     *     $userId = $model->insert($userData, 'users');
     *     $model->insert(['user_id' => $userId, 'role' => 'admin'], 'user_roles');
     *     $model->commit();
     * } catch (Exception $e) {
     *     $model->rollback();
     *     throw $e;
     * }
     */
    public function beginTransaction(): bool
    {
        $this->connect();
        $startTime = microtime(true);

        $this->debugLog("Transaction begin requested", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
            'current_level' => $this->getTransactionLevel(),
            'in_transaction' => $this->dbh->inTransaction()
        ]);

        $result = $this->dbh->beginTransaction();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("Transaction started", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
                'success' => $result,
                'transaction_level' => $this->getTransactionLevel(),
                'execution_time_ms' => round($executionTime * 1000, 2),
                'operation' => 'begin_transaction'
            ]);
        }

        return $result;
    }

    /**
     * Commit current transaction and make changes permanent
     * 
     * Permanently saves all changes made within the current transaction
     * to the database. Once committed, changes cannot be rolled back.
     * 
     * @return bool True if commit succeeded, false on failure
     * @throws RuntimeException If no active transaction or commit fails
     * 
     * @example
     * if ($model->beginTransaction()) {
     *     // ... perform operations ...
     *     if ($allOperationsSuccessful) {
     *         $model->commit(); // Make changes permanent
     *     } else {
     *         $model->rollback(); // Cancel changes
     *     }
     * }
     */
    public function commit(): bool
    {
        $startTime = microtime(true);

        $this->debugLog("Transaction commit requested", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
            'current_level' => $this->getTransactionLevel(),
            'in_transaction' => $this->dbh->inTransaction()
        ]);

        $result = $this->dbh->commit();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("Transaction committed", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
                'success' => $result,
                'transaction_level' => $this->getTransactionLevel(),
                'execution_time_ms' => round($executionTime * 1000, 2),
                'operation' => 'commit'
            ]);
        }

        return $result;
    }

    /**
     * Rollback current transaction and discard all changes
     * 
     * Cancels all changes made within the current transaction and returns
     * the database to its state before the transaction began. Use for
     * error recovery and maintaining data consistency.
     * 
     * @return bool True if rollback succeeded, false on failure
     * @throws RuntimeException If no active transaction or rollback fails
     * 
     * @example
     * $model->beginTransaction();
     * try {
     *     $model->insert($criticalData, 'important_table');
     *     $model->update($id, $updateData, 'related_table');
     *     $model->commit();
     * } catch (Exception $e) {
     *     $model->rollback(); // Undo all changes on any error
     *     error_log("Transaction failed: " . $e->getMessage());
     * }
     */
    public function rollback(): bool
    {
        $startTime = microtime(true);

        $this->debugLog("Transaction rollback requested", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
            'current_level' => $this->getTransactionLevel(),
            'in_transaction' => $this->dbh->inTransaction()
        ]);

        $result = $this->dbh->rollback();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("Transaction rolled back", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
                'success' => $result,
                'transaction_level' => $this->getTransactionLevel(),
                'execution_time_ms' => round($executionTime * 1000, 2),
                'operation' => 'rollback'
            ]);
        }

        return $result;
    }

    /**
     * Get current transaction level for debugging
     * 
     * @return int Current nesting level (0 = no transaction, 1+ = nested level)
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Check if currently in a transaction
     * 
     * @return bool True if in transaction, false otherwise
     */
    public function inTransaction(): bool
    {
        return $this->dbh->inTransaction() || $this->transactionLevel > 0;
    }

    /**
     * Force rollback any active transaction - for cleanup purposes
     */
    public function forceTransactionCleanup(): bool
    {
        if (!$this->connected) {
            // Reset state even if not connected
            $this->transactionLevel = 0;
            $this->savepointCounter = 0;
            $this->transactionStartTime = null;
            return true;
        }

        try {
            // Check PDO transaction state directly
            while ($this->dbh->inTransaction()) {
                $this->dbh->rollback();
            }

            // FIXED: Always reset instance-level transaction state
            $this->transactionLevel = 0;
            $this->savepointCounter = 0;
            $this->transactionStartTime = null;

            return true;
        } catch (PDOException $e) {
            // Log but don't throw - this is cleanup
            error_log("Transaction cleanup warning: " . $e->getMessage());

            // FIXED: Always reset state even if rollback fails
            $this->transactionLevel = 0;
            $this->savepointCounter = 0;
            $this->transactionStartTime = null;

            return false;
        }
    }

    /**
     * Reset transaction state and cleanup any dangling transactions
     */
    public function resetTransactionState(): void
    {
        $this->transactionLevel = 0;
        $this->savepointCounter = 0;
        $this->transactionStartTime = null;

        $this->forceTransactionCleanup();
    }

    /**
     * Get current transaction status for debugging
     */
    public function getTransactionStatus(): array
    {
        $this->connect();

        return [
            'pdo_in_transaction' => $this->dbh->inTransaction(),
            'transaction_level' => $this->getTransactionLevel(),
            'database_type' => $this->dbType ?? 'unknown',
            'savepoint_counter' => $this->savepointCounter,
            'transaction_start_time' => $this->transactionStartTime
        ];
    }


    // =============================================================================
    // UTILITY AND CONFIGURATION METHODS
    // =============================================================================

    /**
     * Get last database error message for debugging and logging
     * 
     * Retrieves the most recent error message from failed database operations.
     * Useful for error handling, logging, and debugging failed queries.
     * Returns null if no errors have occurred.
     * 
     * @return string|null Last error message, or null if no errors
     * 
     * @example
     * $success = $model->insert($invalidData);
     * if (!$success) {
     *     $error = $model->getLastError();
     *     error_log("Database insert failed: " . $error);
     * }
     * 
     * @example
     * // Check for errors after bulk operation
     * try {
     *     $model->insert_batch('table', $data);
     * } catch (RuntimeException $e) {
     *     $dbError = $model->getLastError();
     *     // Log both exception and database error details
     * }
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get memory limit in bytes
     */
    public function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int)substr($memoryLimit, 0, -1);

        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value
        };
    }


    public function __destruct()
    {

        // SQLite optimization for connection closing (your original idea!)
        if ($this->connected && $this->dbType === 'sqlite') {
            try {
                $this->dbh->exec('PRAGMA optimize');

                if ($this->debug) {
                    echo "🔧 SQLITE OPTIMIZE: Connection closing optimization\n";
                }
            } catch (Exception $e) {
                // Silently handle errors in destructor
            }
        }
    }
}
