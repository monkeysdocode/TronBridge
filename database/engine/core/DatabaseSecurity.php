<?php

/**
 * Enhanced Database Security Utilities - Single Source of Truth
 * 
 * Consolidated identifier validation and escaping system that serves as the
 * single source of truth for all database security operations. Eliminates
 * duplication between QueryBuilder and security validation while maintaining
 * high performance through intelligent caching.
 * 
 * Features:
 * - Database-agnostic validation with database-specific escaping
 * - Multi-database identifier caching (MySQL, SQLite, PostgreSQL)
 * - High-performance validation with aggressive caching
 * - SQL injection prevention and security validation
 * - Bulk operations for optimal performance
 * - Comprehensive cache warming and management
 * 
 * @package Database\Security
 * @author Enhanced Model System  
 * @version 2.0.0
 */
class DatabaseSecurity
{
    /**
     * Consolidated identifier cache with xxh3 keys
     * Format: [xxh3_hash => ['original' => 'identifier', 'validated' => true, 'mysql' => '`identifier`', ...]]
     * 
     * @var array<string, array>
     */
    private static array $identifierCache = [];

    /**
     * SQLite path validation cache for file system security
     * 
     * @var array<string, bool>
     */
    private static array $validatedPaths = [];

    /**
     * Pre-compiled regex patterns for performance optimization
     * 
     * @var array<string, mixed>
     */
    private static array $precompiledPatterns = [];

    /**
     * Initialization flag for pattern compilation
     * 
     * @var bool
     */
    private static bool $patternsInitialized = false;

    /**
     * Maximum identifier length (MySQL/PostgreSQL limit)
     */
    private const MAX_IDENTIFIER_LENGTH = 64;

    /**
     * Valid identifier pattern (database-agnostic)
     */
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_-]*$/';

    /**
     * Cache size limit to prevent memory bloat
     */
    private const MAX_CACHE_SIZE = 1000;

    /**
     * Common SQL reserved words (shared across databases)
     * 
     * @var array<string, bool>
     */
    private static array $reservedWords = [
        'SELECT' => true,
        'INSERT' => true,
        'UPDATE' => true,
        'DELETE' => true,
        'DROP' => true,
        'CREATE' => true,
        'ALTER' => true,
        'TABLE' => true,
        'INDEX' => true,
        'VIEW' => true,
        'TRIGGER' => true,
        'PROCEDURE' => true,
        'FUNCTION' => true,
        'FROM' => true,
        'WHERE' => true,
        'ORDER' => true,
        'GROUP' => true,
        'HAVING' => true,
        'UNION' => true,
        'JOIN' => true,
        'LEFT' => true,
        'RIGHT' => true,
        'INNER' => true,
        'OUTER' => true,
        'ON' => true,
        'AS' => true,
        'AND' => true,
        'OR' => true,
        'NOT' => true,
        'NULL' => true,
        'TRUE' => true,
        'FALSE' => true,
        'EXISTS' => true,
        'BETWEEN' => true,
        'LIKE' => true,
        'IN' => true,
        'IS' => true,
        'DISTINCT' => true,
        'ALL' => true,
        'ANY' => true,
        'SOME' => true,
        'LIMIT' => true,
        'OFFSET' => true
    ];

    // Expression validation patterns
    private const ARITHMETIC_PATTERN = '/^([a-zA-Z_][a-zA-Z0-9_]*)\s*([+\-*\/])\s*([0-9]+(?:\.[0-9]+)?|[a-zA-Z_][a-zA-Z0-9_]*)$/';
    private const ENHANCED_ARITHMETIC_PATTERN = '/^(.+)\s*([+\-*\/])\s*(.+)$/';
    private const FUNCTION_PATTERN = '/^([A-Z_]+)\s*\(\s*([^;\'\"]*)\s*\)$/i';
    private const CASE_PATTERN = '/^CASE\s+WHEN\s+.+\s+THEN\s+.+(\s+WHEN\s+.+\s+THEN\s+.+)*\s+ELSE\s+.+\s+END$/i';

    // Expression cache for performance
    private static array $expressionCache = [];

    // =============================================================================
    // CORE VALIDATION METHODS (SINGLE SOURCE OF TRUTH)
    // =============================================================================

    /**
     * Validate table name with comprehensive caching
     * 
     * Primary validation method for table names used throughout the system.
     * Provides consistent validation rules and caching across all components.
     * 
     * @param string $tableName Table name to validate
     * @throws InvalidArgumentException If validation fails
     * @return void
     */
    public static function validateTableName(string $tableName): void
    {
        self::validateIdentifier($tableName, 'table');
    }

    /**
     * Validate column name with comprehensive caching
     * 
     * Primary validation method for column names used throughout the system.
     * Provides consistent validation rules and caching across all components.
     * 
     * @param string $columnName Column name to validate
     * @throws InvalidArgumentException If validation fails
     * @return void
     */
    public static function validateColumnName(string $columnName): void
    {
        self::validateIdentifier($columnName, 'column');
    }

    /**
     * Validate multiple identifiers in bulk for maximum performance
     * 
     * Optimized bulk validation that processes multiple identifiers efficiently
     * while maintaining full validation coverage and caching benefits.
     * 
     * @param array $identifiers Array of identifiers to validate
     * @param string $type Type of identifier ('table', 'column', or 'identifier')
     * @throws InvalidArgumentException If any validation fails
     * @return void
     */
    public static function validateIdentifiersBulk(array $identifiers, string $type = 'identifier'): void
    {
        foreach ($identifiers as $identifier) {
            self::validateIdentifier($identifier, $type);
        }
    }

    /**
     * Core identifier validation with intelligent caching
     * 
     * Central validation logic that all other validation methods delegate to.
     * Implements comprehensive caching to avoid redundant validation while
     * maintaining security and performance.
     * 
     * @param string $identifier Identifier to validate
     * @param string $type Type for error messages ('table', 'column', 'identifier')
     * @throws InvalidArgumentException If validation fails
     * @return void
     */
    private static function validateIdentifier(string $identifier, string $type): void
    {
        // Generate xxh3 cache key - always 16 characters, ultra-fast lookup
        $cacheKey = FastCacheKey::generate($identifier);

        // Ultra-fast cache check with fixed-length key
        if (isset(self::$identifierCache[$cacheKey]['validated'])) {
            return;
        }

        // Perform validation
        self::performIdentifierValidation($identifier, $type);

        // Cache with xxh3 key
        if (!isset(self::$identifierCache[$cacheKey])) {
            self::$identifierCache[$cacheKey] = ['original' => $identifier];
        }
        self::$identifierCache[$cacheKey]['validated'] = true;

        // Manage cache size
        if (count(self::$identifierCache) > self::MAX_CACHE_SIZE) {
            self::manageCacheSize();
        }
    }

    /**
     * Validate and escape ORDER BY clause with support for multiple columns and directions
     * 
     * Parses ORDER BY clauses to validate individual column names and direction keywords.
     * Supports multiple columns, proper direction validation, and database-specific escaping.
     * Individual column validation is cached via existing validateColumnName() method.
     * 
     * @param string $orderBy ORDER BY clause (e.g., 'id desc', 'name asc, created_at desc')
     * @param string $dbType Database type for proper escaping
     * @return string Validated and escaped ORDER BY clause
     * @throws InvalidArgumentException If validation fails
     * 
     * @example
     * // Single column with direction
     * $orderBy = DatabaseSecurity::validateOrderBy('id desc', 'mysql');
     * // Returns: "`id` DESC"
     * 
     * @example  
     * // Multiple columns
     * $orderBy = DatabaseSecurity::validateOrderBy('name asc, created_at desc', 'sqlite');
     * // Returns: "`name` ASC, `created_at` DESC"
     */
    public static function validateOrderBy(string $orderBy, string $dbType): string
    {
        if (empty(trim($orderBy))) {
            throw new InvalidArgumentException('ORDER BY clause cannot be empty');
        }

        $validDirections = ['ASC', 'DESC'];
        $escapedParts = [];

        // Split by comma for multiple columns
        $parts = array_map('trim', explode(',', $orderBy));

        foreach ($parts as $part) {
            if (empty($part)) {
                throw new InvalidArgumentException('Empty ORDER BY part found');
            }

            // Split by space to separate column and direction
            $tokens = preg_split('/\s+/', trim($part));

            if (count($tokens) > 2) {
                throw new InvalidArgumentException("Invalid ORDER BY format: '$part'. Expected 'column [ASC|DESC]'");
            }

            $columnName = $tokens[0];
            $direction = isset($tokens[1]) ? strtoupper($tokens[1]) : 'ASC';

            // Validate column name (uses existing caching)
            self::validateColumnName($columnName);

            // Validate direction
            if (!in_array($direction, $validDirections)) {
                throw new InvalidArgumentException("Invalid ORDER BY direction: '$direction'. Must be ASC or DESC");
            }

            // Escape column name and reconstruct (uses existing caching)
            $escapedColumn = self::escapeIdentifier($columnName, $dbType);
            $escapedParts[] = "$escapedColumn $direction";
        }

        return implode(', ', $escapedParts);
    }

    /**
     * Helper method to validate just the column names in an ORDER BY clause (no escaping)
     * 
     * Use this when you only need validation without escaping (e.g., for QueryBuilder).
     * Individual column validation uses existing caching via validateColumnName().
     * 
     * @param string $orderBy ORDER BY clause to validate
     * @throws InvalidArgumentException If validation fails
     * @return void
     */
    public static function validateOrderByColumns(string $orderBy): void
    {
        if (empty(trim($orderBy))) {
            return; // Allow empty ORDER BY
        }

        $validDirections = ['ASC', 'DESC'];

        // Split by comma for multiple columns
        $parts = array_map('trim', explode(',', $orderBy));

        foreach ($parts as $part) {
            if (empty($part)) {
                throw new InvalidArgumentException('Empty ORDER BY part found');
            }

            // Split by space to separate column and direction
            $tokens = preg_split('/\s+/', trim($part));

            if (count($tokens) > 2) {
                throw new InvalidArgumentException("Invalid ORDER BY format: '$part'. Expected 'column [ASC|DESC]'");
            }

            $columnName = $tokens[0];
            $direction = isset($tokens[1]) ? strtoupper($tokens[1]) : 'ASC';

            // Validate column name only (uses existing caching)
            self::validateColumnName($columnName);

            // Validate direction
            if (!in_array($direction, $validDirections)) {
                throw new InvalidArgumentException("Invalid ORDER BY direction: '$direction'. Must be ASC or DESC");
            }
        }
    }

    /**
     * Internal validation logic with comprehensive security checks
     * 
     * Performs the actual validation work including pattern matching,
     * length validation, and reserved word checking. Called only when
     * identifier is not already cached.
     * 
     * @param string $identifier Identifier to validate
     * @param string $type Type for error messages
     * @throws InvalidArgumentException If validation fails
     * @return void
     */
    private static function performIdentifierValidation(string $identifier, string $type): void
    {
        // Empty check
        if ($identifier === '') {
            throw new InvalidArgumentException("Empty {$type} name not allowed");
        }

        // Length validation
        if (strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException(
                "{$type} name too long (max " . self::MAX_IDENTIFIER_LENGTH . " characters): $identifier"
            );
        }

        // Pattern validation
        if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            throw new InvalidArgumentException(
                "Invalid {$type} name format: $identifier. " .
                    "Only letters, numbers, underscores, and hyphens allowed. " .
                    "Must start with letter or underscore."
            );
        }

        // Reserved word check
        if (isset(self::$reservedWords[strtoupper($identifier)])) {
            throw new InvalidArgumentException(
                "{$type} name cannot be a reserved SQL word: $identifier"
            );
        }
    }

    // =============================================================================
    // EXPRESSIONS VALIDATION METHODS
    // =============================================================================

    /**
     * Validate literal expressions for security compliance
     * 
     * This is the main entry point for expression security validation. It performs
     * comprehensive security checks but does NOT handle database-specific translation
     * (that's QueryBuilder's responsibility).
     * 
     * @param string $expression Raw expression to validate
     * @param string $context Context: 'update_set', 'insert_value', 'where_condition' 
     * @param array $allowedColumns Columns that can be referenced in expression
     * @return string Validated expression (database-agnostic)
     * @throws InvalidArgumentException If expression is not whitelisted or unsafe
     */
    public static function validateExpression(
        string $expression,
        string $context,
        array $allowedColumns = []
    ): string {
        // Generate cache key (no dbType - this is database-agnostic validation)
        $cacheKey = FastCacheKey::fromComponents([$expression, $context, implode(',', $allowedColumns)]);

        // Check cache first
        if (isset(self::$expressionCache[$cacheKey])) {
            return self::$expressionCache[$cacheKey];
        }

        // Normalize expression
        $expression = trim($expression);

        if (empty($expression)) {
            throw new InvalidArgumentException("Empty expression not allowed");
        }

        // Context-specific validation
        if ($context === 'where_condition') {
            throw new InvalidArgumentException("Expressions not allowed in WHERE conditions - use parameter binding");
        }

        // Validate expression type and safety (no database-specific logic)
        $validatedExpression = self::validateExpressionSafety($expression, $context, $allowedColumns);

        // Cache result (validated, but not translated)
        self::$expressionCache[$cacheKey] = $validatedExpression;

        return $validatedExpression;
    }

    /**
     * Validate expression safety using pattern matching and whitelist checks
     */
    private static function validateExpressionSafety(
        string $expression,
        string $context,
        array $allowedColumns
    ): string {
        // Check for dangerous patterns first
        self::checkForDangerousPatterns($expression);

        // Try to match against safe expression patterns
        if (self::isValidLiteralNumber($expression)) {
            return $expression; // Literal numbers are safe
        }

        if (self::isValidArithmeticExpression($expression, $allowedColumns)) {
            return $expression; // Arithmetic is safest
        }

        if (self::isValidFunctionExpression($expression, $allowedColumns)) {
            return $expression; // Function calls (with possible arithmetic)
        }

        if (self::isValidGenericFunctionCall($expression)) {
            return $expression; // Simple function calls
        }

        if (self::isValidKeywordExpression($expression)) {
            return $expression; // SQL keywords like CURRENT_TIMESTAMP
        }

        if (self::isValidCaseExpression($expression, $allowedColumns)) {
            return $expression; // CASE expressions
        }

        // If no pattern matches, reject
        throw new InvalidArgumentException("Expression not in whitelist: {$expression}");
    }

    /**
     * Check for dangerous SQL patterns that should never be allowed
     */
    private static function checkForDangerousPatterns(string $expression): void
    {
        $dangerousPatterns = [
            '/;\s*\w+/i',           // Multiple statements
            '/\/\*.*?\*\//s',       // SQL comments
            '/--.*$/m',             // SQL line comments
            '/\b(DROP|DELETE|TRUNCATE|ALTER|CREATE)\b/i', // DDL/DML
            '/\b(UNION|SELECT)\b/i', // Subqueries/injections
            '/\bEXEC\b/i',          // Stored procedures
            '/\b(LOAD_FILE|INTO\s+OUTFILE)\b/i', // File operations
        ];

        // Check for string literals OUTSIDE of function calls (more permissive)
        // Allow string literals inside function parentheses
        if (preg_match('/[\'\"]/i', $expression)) {
            // Check if the quotes are inside function calls
            if (!preg_match('/\w+\s*\([^)]*[\'\"]/i', $expression)) {
                throw new InvalidArgumentException("String literals not allowed outside function calls: {$expression}");
            }
        }

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $expression)) {
                throw new InvalidArgumentException("Dangerous pattern detected in expression: {$expression}");
            }
        }
    }

    /**
     * Validate arithmetic expressions (safest expression type)
     */
    public static function isValidArithmeticExpression(string $expression, array $allowedColumns): bool
    {
        // Try basic arithmetic pattern first
        if (preg_match(self::ARITHMETIC_PATTERN, $expression, $matches)) {
            $leftOperand = $matches[1];
            $operator = $matches[2];
            $rightOperand = $matches[3];

            // Validate left operand (must be column name)
            if (!empty($allowedColumns) && !in_array($leftOperand, $allowedColumns)) {
                throw new InvalidArgumentException("Column '{$leftOperand}' not in allowed columns list");
            }

            self::validateColumnName($leftOperand);

            // Validate right operand (number or column)
            if (!is_numeric($rightOperand)) {
                if (!empty($allowedColumns) && !in_array($rightOperand, $allowedColumns)) {
                    throw new InvalidArgumentException("Column '{$rightOperand}' not in allowed columns list");
                }
                self::validateColumnName($rightOperand);
            }

            return true;
        }

        // Try enhanced arithmetic pattern for more complex expressions like "15.5 + 4.5"
        if (preg_match(self::ENHANCED_ARITHMETIC_PATTERN, $expression, $matches)) {
            $leftOperand = trim($matches[1]);
            $operator = $matches[2];
            $rightOperand = trim($matches[3]);

            // Both operands can be numbers, columns, or simple function calls
            if (
                !self::isValidOperand($leftOperand, $allowedColumns) ||
                !self::isValidOperand($rightOperand, $allowedColumns)
            ) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Validate an operand in an arithmetic expression
     */
    private static function isValidOperand(string $operand, array $allowedColumns): bool
    {
        // Numbers are always valid
        if (is_numeric($operand)) {
            return true;
        }

        // Column names need validation and allowlist check
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $operand)) {
            if (!empty($allowedColumns) && !in_array($operand, $allowedColumns)) {
                throw new InvalidArgumentException("Column '{$operand}' not in allowed columns list");
            }
            self::validateColumnName($operand);
            return true;
        }

        // Simple function calls are valid
        if (self::isValidGenericFunctionCall($operand)) {
            return true;
        }

        return false;
    }

    /**
     * Validate literal numbers (safest expression type)
     */
    public static function isValidLiteralNumber(string $expression): bool
    {
        // Match integers and decimals
        return preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $expression);
    }

    /**
     * Validate function expressions with arithmetic operations
     */
    public static function isValidFunctionExpression(string $expression, array $allowedColumns): bool
    {
        // Pattern: FUNCTION() [operator] [number|column|FUNCTION()]
        // Examples: RAND() * 100, NOW() + INTERVAL 1 DAY (if supported)
        $pattern = '/^([A-Z_]+)\s*\(\s*([^;\'\"]*)\s*\)\s*([+\-*\/])\s*(.+)$/i';

        if (!preg_match($pattern, $expression, $matches)) {
            return false;
        }

        $functionName = strtoupper($matches[1]);
        $functionArgs = $matches[2];
        $operator = $matches[3];
        $rightOperand = trim($matches[4]);

        // Validate function name
        $genericSafeFunctions = [
            'NOW',
            'COALESCE',
            'UPPER',
            'LOWER',
            'LENGTH',
            'SUBSTRING',
            'SUBSTR',
            'CURDATE',
            'CURTIME',
            'DATE',
            'TIME',
            'DATETIME',
            'CURRENT_DATE',
            'CURRENT_TIME',
            'CURRENT_TIMESTAMP',
            'CONCAT',
            'TRIM',
            'LTRIM',
            'RTRIM',
            'REPLACE',
            'ABS',
            'ROUND',
            'FLOOR',
            'RAND',
            'RANDOM',
            'NULLIF',
            'GREATEST',
            'LEAST'
        ];

        if (!in_array($functionName, $genericSafeFunctions)) {
            throw new InvalidArgumentException("Function '{$functionName}' not in whitelist");
        }

        // Validate function arguments (basic validation)
        if (!empty($functionArgs) && preg_match('/[;]/', $functionArgs)) {
            throw new InvalidArgumentException("Invalid function arguments: {$functionArgs}");
        }

        // Validate right operand (number, column, or another function)
        if (is_numeric($rightOperand)) {
            return true; // Number is safe
        }

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $rightOperand)) {
            // Column reference - check against allowlist
            if (!empty($allowedColumns) && !in_array($rightOperand, $allowedColumns)) {
                throw new InvalidArgumentException("Column '{$rightOperand}' not in allowed columns list");
            }
            self::validateColumnName($rightOperand);
            return true;
        }

        // Could be another function call - validate recursively
        if (self::isValidGenericFunctionCall($rightOperand)) {
            return true;
        }

        throw new InvalidArgumentException("Invalid right operand in function expression: {$rightOperand}");
    }

    /**
     * Validate SQL keyword expressions (like CURRENT_TIMESTAMP without parentheses)
     */
    public static function isValidKeywordExpression(string $expression): bool
    {
        $safeKeywords = [
            'CURRENT_DATE',
            'CURRENT_TIME',
            'CURRENT_TIMESTAMP',
            'CURRENT_USER',
            'SESSION_USER'
        ];

        return in_array(strtoupper($expression), $safeKeywords);
    }

    /**
     * Validate function calls using generic pattern matching
     */
    public static function isValidGenericFunctionCall(string $expression): bool
    {
        if (!preg_match(self::FUNCTION_PATTERN, $expression, $matches)) {
            return false;
        }

        $functionName = strtoupper($matches[1]);
        $functionArgs = $matches[2];

        // Generic safe functions that exist across all major databases (or can be translated)
        $genericSafeFunctions = [
            // Date/time functions
            'NOW',
            'COALESCE',
            'UPPER',
            'LOWER',
            'LENGTH',
            'SUBSTRING',
            'SUBSTR',
            'CURDATE',
            'CURTIME',
            'DATE',
            'TIME',
            'DATETIME',
            'CURRENT_DATE',
            'CURRENT_TIME',
            'CURRENT_TIMESTAMP',

            // String functions
            'CONCAT',
            'TRIM',
            'LTRIM',
            'RTRIM',
            'REPLACE',

            // Mathematical functions
            'ABS',
            'ROUND',
            'FLOOR',
            'RAND',
            'RANDOM',

            // Conditional functions
            'NULLIF',
            'GREATEST',
            'LEAST'
        ];

        if (!in_array($functionName, $genericSafeFunctions)) {
            throw new InvalidArgumentException("Function '{$functionName}' not in generic whitelist");
        }

        // Basic argument validation (no nested functions, no strings)
        if (!empty($functionArgs) && preg_match('/[\'\";\(\)]/', $functionArgs)) {
            throw new InvalidArgumentException("Invalid function arguments: {$functionArgs}");
        }

        return true;
    }

    /**
     * Validate CASE expressions
     */
    public static function isValidCaseExpression(string $expression, array $allowedColumns): bool
    {
        if (!preg_match(self::CASE_PATTERN, $expression)) {
            return false;
        }

        // Extract column references and validate them
        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $expression, $matches)) {
            $referencedColumns = array_unique($matches[1]);

            // Filter out SQL keywords
            $sqlKeywords = ['CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'AND', 'OR'];
            $referencedColumns = array_diff($referencedColumns, $sqlKeywords);

            foreach ($referencedColumns as $column) {
                if (!empty($allowedColumns) && !in_array($column, $allowedColumns)) {
                    throw new InvalidArgumentException("Column '{$column}' not in allowed columns list");
                }
                self::validateColumnName($column);
            }
        }

        return true;
    }

    // =============================================================================
    // DATABASE-SPECIFIC ESCAPING METHODS
    // =============================================================================

    /**
     * Escape identifier for specific database type with intelligent caching
     * 
     * Provides database-specific identifier escaping with comprehensive caching
     * to eliminate redundant escaping operations. Supports all major databases
     * with proper escaping rules for each platform.
     * 
     * @param string $identifier Identifier to escape
     * @param string $dbType Database type ('mysql', 'sqlite', 'postgresql')
     * @return string Properly escaped identifier ready for use in SQL
     * @throws InvalidArgumentException If validation fails or unsupported database type
     * 
     * @example
     * $escaped = DatabaseSecurity::escapeIdentifier('user_table', 'mysql');     // Returns: `user_table`
     * $escaped = DatabaseSecurity::escapeIdentifier('user_table', 'postgresql'); // Returns: "user_table"
     */
    public static function escapeIdentifier(string $identifier, string $dbType): string
    {
        // Validate first (uses xxh3 cache)
        self::validateIdentifier($identifier, 'identifier');

        // Generate xxh3 cache key
        $cacheKey = FastCacheKey::generate($identifier);

        // Check if already escaped for this database type
        if (isset(self::$identifierCache[$cacheKey][$dbType])) {
            return self::$identifierCache[$cacheKey][$dbType];
        }

        // Perform database-specific escaping
        $escaped = match ($dbType) {
            'mysql', 'sqlite' => '`' . str_replace('`', '``', $identifier) . '`',
            'postgresql', 'postgres', 'pgsql' => '"' . str_replace('"', '""', $identifier) . '"',
            default => throw new InvalidArgumentException("Unsupported database type: $dbType")
        };

        // Cache with xxh3 key
        if (!isset(self::$identifierCache[$cacheKey])) {
            self::$identifierCache[$cacheKey] = ['original' => $identifier];
        }
        self::$identifierCache[$cacheKey][$dbType] = $escaped;

        return $escaped;
    }

    /**
     * Validate and escape identifier in single operation for optimal performance
     * 
     * Convenience method that combines validation and escaping in a single call.
     * Optimized for scenarios where both operations are needed and provides
     * better performance than separate calls.
     * 
     * @param string $identifier Identifier to validate and escape
     * @param string $dbType Database type for escaping
     * @param string $type Type for error messages ('table', 'column', 'identifier')
     * @return string Validated and escaped identifier
     * @throws InvalidArgumentException If validation fails
     * 
     * @example
     * // Single call instead of separate validate + escape
     * $escaped = DatabaseSecurity::validateAndEscape('products', 'mysql', 'table');
     */
    public static function validateAndEscape(string $identifier, string $dbType, string $type = 'identifier'): string
    {
        self::validateIdentifier($identifier, $type);
        return self::escapeIdentifier($identifier, $dbType);
    }

    /**
     * Bulk validate and escape multiple identifiers for maximum efficiency
     * 
     * High-performance bulk operation that processes multiple identifiers
     * with optimal caching and minimal overhead. Ideal for bulk operations
     * and complex queries with many identifiers.
     * 
     * @param array $identifiers Array of identifiers to process
     * @param string $dbType Database type for escaping
     * @param string $type Type for error messages
     * @return array Array of escaped identifiers in same order as input
     * @throws InvalidArgumentException If any validation fails
     * 
     * @example
     * $columns = ['id', 'name', 'email', 'created_at'];
     * $escaped = DatabaseSecurity::validateAndEscapeBulk($columns, 'postgresql', 'column');
     * // Returns: ['"id"', '"name"', '"email"', '"created_at"']
     */
    public static function validateAndEscapeBulk(array $identifiers, string $dbType, string $type = 'identifier'): array
    {
        $escaped = [];
        foreach ($identifiers as $identifier) {
            $escaped[] = self::validateAndEscape($identifier, $dbType, $type);
        }
        return $escaped;
    }

    // =============================================================================
    // PERFORMANCE OPTIMIZATION METHODS
    // =============================================================================

    /**
     * Pre-warm identifier cache for optimal performance
     * 
     * Proactively validates and escapes common identifiers for multiple database
     * types to eliminate cold start penalties. Ideal for application initialization
     * or before processing large datasets.
     * 
     * @param array $tableNames Common table names to pre-validate
     * @param array $columnNames Common column names to pre-validate  
     * @param array $dbTypes Database types to pre-escape for
     * @return array Statistics about cache warming operation
     * 
     * @example
     * // Warm cache for common identifiers across all databases
     * $stats = DatabaseSecurity::warmCache(
     *     ['users', 'products', 'orders'],           // tables
     *     ['id', 'name', 'email', 'created_at'],    // columns  
     *     ['mysql', 'sqlite', 'postgresql']         // databases
     * );
     * echo "Warmed {$stats['total_operations']} cache entries";
     */
    public static function warmCache(
        array $tableNames = [],
        array $columnNames = [],
        array $dbTypes = ['mysql', 'sqlite', 'postgresql']
    ): array {
        $stats = [
            'tables_validated' => 0,
            'columns_validated' => 0,
            'total_escapes' => 0,
            'total_operations' => 0,
            'skipped_invalid' => 0
        ];

        // Pre-warm table names
        foreach ($tableNames as $table) {
            try {
                self::validateIdentifier($table, 'table');
                foreach ($dbTypes as $dbType) {
                    self::escapeIdentifier($table, $dbType);
                    $stats['total_escapes']++;
                }
                $stats['tables_validated']++;
            } catch (InvalidArgumentException $e) {
                $stats['skipped_invalid']++;
            }
        }

        // Pre-warm column names
        foreach ($columnNames as $column) {
            try {
                self::validateIdentifier($column, 'column');
                foreach ($dbTypes as $dbType) {
                    self::escapeIdentifier($column, $dbType);
                    $stats['total_escapes']++;
                }
                $stats['columns_validated']++;
            } catch (InvalidArgumentException $e) {
                $stats['skipped_invalid']++;
            }
        }

        $stats['total_operations'] = $stats['tables_validated'] + $stats['columns_validated'];
        return $stats;
    }

    /**
     * Manage cache size to prevent memory bloat in long-running processes
     * 
     * Intelligent cache management that removes oldest entries when cache
     * size exceeds limits. Maintains performance while preventing unbounded
     * memory growth in long-running applications.
     * 
     * @return void
     */
    private static function manageCacheSize(): void
    {
        if (count(self::$identifierCache) <= self::MAX_CACHE_SIZE) {
            return;
        }

        // Remove oldest 25% of cache entries
        $removeCount = intval(count(self::$identifierCache) * 0.25);
        $keys = array_keys(self::$identifierCache);

        for ($i = 0; $i < $removeCount; $i++) {
            unset(self::$identifierCache[$keys[$i]]);
        }
    }

    /**
     * Clear all caches and reset state for testing or memory management
     * 
     * Completely resets all internal caches and state. Useful for testing,
     * memory management in long-running processes, or when switching contexts.
     * 
     * @return void
     */
    public static function clearCaches(): void
    {
        self::$identifierCache = [];
        self::$validatedPaths = [];
        self::$precompiledPatterns = [];
        self::$patternsInitialized = false;
    }

    /**
     * Get comprehensive cache statistics for monitoring and debugging
     * 
     * Returns detailed statistics about cache usage, hit ratios, and memory
     * consumption for performance monitoring and optimization purposes.
     * 
     * @return array Comprehensive cache statistics
     */
    public static function getCacheStats(): array
    {
        $memoryUsage = 0;
        if (!empty(self::$identifierCache)) {
            $memoryUsage = strlen(serialize(self::$identifierCache));
        }

        return [
            'identifier_cache_size' => count(self::$identifierCache),
            'path_cache_size' => count(self::$validatedPaths),
            'patterns_initialized' => self::$patternsInitialized,
            'memory_usage_bytes' => $memoryUsage,
            'memory_usage_kb' => round($memoryUsage / 1024, 2),
            'cache_limit' => self::MAX_CACHE_SIZE,
            'cache_utilization_percent' => round((count(self::$identifierCache) / self::MAX_CACHE_SIZE) * 100, 1)
        ];
    }

    // =============================================================================
    // FILE PATH SECURITY METHODS  
    // =============================================================================

    /**
     * Validate SQLite database file path with in-memory database support
     * 
     * Supports legitimate in-memory SQLite databases (:memory:)
     * while maintaining all existing security checks for file-based databases.
     * 
     * @param string $path SQLite database path or special connection string
     * @return string Validated path or connection string
     * @throws InvalidArgumentException If path validation fails
     */
    public static function validateSQLitePath(string $path): string
    {
        // Allow in-memory SQLite databases - these are legitimate and secure
        if (self::isInMemoryDatabase($path)) {
            return $path;
        }

        // Allow temporary databases - also legitimate for testing/temporary operations
        if (self::isTemporaryDatabase($path)) {
            return $path;
        }

        // For file-based databases, use existing validation logic
        return self::validateSQLitePathInternal($path);
    }

    /**
     * Check if database path represents an in-memory database
     * 
     * Identifies legitimate in-memory database connection strings that don't
     * correspond to actual files and therefore don't need file-based validation.
     * 
     * @param string $path Database path to check
     * @return bool True if this is an in-memory database
     */
    private static function isInMemoryDatabase(string $path): bool
    {
        // SQLite in-memory database identifiers
        $memoryIdentifiers = [
            ':memory:',           // Standard SQLite in-memory database
            'file::memory:',      // URI format in-memory database
            'file::memory:?cache=shared'  // Shared cache in-memory database
        ];

        $normalizedPath = strtolower(trim($path));

        foreach ($memoryIdentifiers as $identifier) {
            if ($normalizedPath === strtolower($identifier)) {
                return true;
            }
        }

        // Check for URI-style in-memory databases
        if (str_starts_with($normalizedPath, 'file::memory:')) {
            return true;
        }

        return false;
    }

    /**
     * Check if database path represents a temporary database
     * 
     * Identifies temporary database files that are acceptable for certain operations
     * like testing, validation, or temporary processing.
     * 
     * @param string $path Database path to check
     * @return bool True if this is a temporary database
     */
    private static function isTemporaryDatabase(string $path): bool
    {
        $normalizedPath = strtolower(trim($path));

        // Empty string means temporary file
        if ($normalizedPath === '') {
            return true;
        }

        // Check for temporary file patterns
        $tempPatterns = [
            '/tmp/',
            '\\temp\\',
            sys_get_temp_dir(),
            ini_get('upload_tmp_dir') ?: '/tmp'
        ];

        foreach ($tempPatterns as $pattern) {
            if (str_contains($normalizedPath, strtolower($pattern))) {
                // Still need to validate extension for temp files
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $allowedExtensions = ['db', 'db2', 'db3', 'sdb', 'sqlite', 'sqlite2', 'sqlite3', 's3db'];

                return in_array($extension, $allowedExtensions);
            }
        }

        return false;
    }

    /**
     * Get application root path with fallback detection
     * 
     * Determines the application root directory by checking for APPPATH constant
     * or calculating it based on the current file location.
     * 
     * @return string Application root path
     */
    private static function getApplicationPath(): string
    {
        // First preference: Use APPPATH if defined
        if (defined('APPPATH')) {
            return rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR;
        }

        // Second preference: Calculate from current file location
        // DatabaseSecurity is in /database/engine/core/, so app root is 3 levels up
        $currentDir = __DIR__;  // /path/to/app/database/engine/core
        $databaseDir = dirname($currentDir, 2);  // /path/to/app/database  
        $appRoot = dirname($databaseDir);     // /path/to/app

        // Verify this looks like an application root
        $expectedDirs = ['database', 'engine', 'modules'];
        $foundDirs = 0;

        foreach ($expectedDirs as $expectedDir) {
            if (is_dir($appRoot . DIRECTORY_SEPARATOR . $expectedDir)) {
                $foundDirs++;
            }
        }

        // If it looks like an app root, use it
        if ($foundDirs >= 2) {
            return rtrim($appRoot, '/\\') . DIRECTORY_SEPARATOR;
        }

        // Last resort: Return current directory parent
        // This ensures we always have some restriction even in unknown environments
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    /**
     * Core database path validation logic (shared between connections and backups)
     * 
     * @param string $path Path to validate
     * @param string $operation Operation type ('connection', 'backup', 'restore')
     * @param array $allowedExtensions Allowed file extensions for this operation
     * @return string Validated path
     * @throws InvalidArgumentException If validation fails
     */
    private static function validateDatabasePathCore(string $path, string $operation, array $allowedExtensions): string
    {
        // Step 1: Basic sanitization
        $path = str_replace("\0", '', $path); // Remove null bytes

        // Step 2: Path traversal prevention
        if (str_contains($path, '..')) {
            throw new InvalidArgumentException("Path traversal detected in $operation path: $path");
        }

        // Step 3: Validate file extension
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions)) {
            throw new InvalidArgumentException(
                "Invalid $operation file extension: $extension. Allowed: " . implode(', ', $allowedExtensions)
            );
        }

        // Step 4: Determine application root path (with APPPATH fallback)
        $appPath = self::getApplicationPath();

        // Step 5: Build comprehensive restricted directories
        $restricted_dirs = self::getRestrictedDirectories($appPath);

        // Step 6: Validate against restricted directories
        $validation_target = file_exists($path) ? $path : dirname($path);

        if (file_exists($validation_target)) {
            $normalized_path = realpath($validation_target);

            if ($normalized_path !== false) {
                foreach ($restricted_dirs as $restricted_dir) {
                    // Handle both defined and calculated paths
                    $restricted_real_path = is_dir($restricted_dir) ? realpath($restricted_dir) : false;

                    if ($restricted_real_path && strpos($normalized_path, $restricted_real_path) === 0) {
                        throw new InvalidArgumentException(
                            "Security violation: $operation path '$path' attempts to access restricted directory '$restricted_dir'"
                        );
                    }
                }
            }
        }

        // Step 7: Dangerous filename check
        $filename = basename($path);
        $dangerous_filenames = [
            'passwd',
            'shadow',
            'hosts',
            'fstab',
            'crontab',
            'sudoers',
            'boot.ini',
            'ntldr',
            'autoexec.bat',
            'config.sys'
        ];

        if (in_array(strtolower($filename), $dangerous_filenames)) {
            throw new InvalidArgumentException(
                "Security violation: $operation filename '$filename' matches system critical file"
            );
        }

        return $path;
    }

    /**
     * Internal SQLite path validation logic (UPDATED)
     * 
     * @param string $path Path to validate
     * @return string Validated path
     * @throws InvalidArgumentException If validation fails
     */
    private static function validateSQLitePathInternal(string $path): string
    {
        // Use core validation with SQLite-specific extensions
        $sqliteExtensions = [
            'db',
            'db2',
            'db3',
            'sdb',
            'sqlite',
            'sqlite2',
            'sqlite3',
            's3db',
            'sql',
            'dump',
            'backup',
            'gz',
            'zip',
            'bz2'
        ];

        return self::validateDatabasePathCore($path, 'database connection', $sqliteExtensions);
    }

    /**
     * Validate database backup file path for security (UPDATED)
     * 
     * @param string $path Backup file path to validate
     * @param string $operation Operation type for logging ('backup', 'restore')
     * @return string Validated and sanitized path
     * @throws InvalidArgumentException If path validation fails
     */
    public static function validateBackupPath(string $path, string $operation = 'backup'): string
    {
        // Use core validation with backup-specific extensions
        $backupExtensions = [
            // SQLite extensions
            'db',
            'db2',
            'db3',
            'sdb',
            'sqlite',
            'sqlite2',
            'sqlite3',
            's3db',
            // SQL dump extensions  
            'sql',
            'dump',
            'backup',
            // Compressed backup extensions
            'gz',
            'zip',
            'bz2'
        ];

        return self::validateDatabasePathCore($path, $operation, $backupExtensions);
    }

    /**
     * Validate database restore file path for security
     * 
     * @param string $path Restore file path to validate
     * @return string Validated path
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateRestorePath(string $path): string
    {
        return self::validateBackupPath($path, 'restore');
    }

    /**
     * Validate database backup directory for security
     * 
     * Validates that a directory is safe for backup operations, including
     * permission checks and security boundary validation.
     * 
     * @param string $directory Directory path to validate
     * @return bool True if directory is safe for backups
     * @throws InvalidArgumentException If directory validation fails
     */
    public static function validateBackupDirectory(string $directory): bool
    {
        // Use the main validation method for directory validation
        try {
            // Create a temporary filename to validate the directory path
            $test_path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'test_backup.sql';
            self::validateBackupPath($test_path, 'backup directory');

            // Additional directory-specific checks
            if (!is_dir($directory)) {
                throw new InvalidArgumentException("Backup directory does not exist: $directory");
            }

            if (!is_writable($directory)) {
                throw new InvalidArgumentException("Backup directory is not writable: $directory");
            }

            return true;
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Backup directory validation failed: " . $e->getMessage());
        }
    }

    /**
     * Get comprehensive list of restricted directories
     * 
     * @param string $appPath Application root path
     * @return array List of restricted directory paths
     */
    private static function getRestrictedDirectories(string $appPath): array
    {
        $restricted_dirs = [];

        // Application-specific restricted directories
        $app_restricted = [
            'config',                    // Configuration files
            'database/classes',          // Database class files  
            'engine',                    // Framework core
            'public',                    // Framework public folder
            'templates',                 // Templates
            'vendor',                    // Composer dependencies (if present)
            '.git',                      // Git repository data
            '.env',                      // Environment files
            'logs',                      // Log files (may contain sensitive data)
        ];

        // Build full paths for application directories
        foreach ($app_restricted as $dir) {
            $full_path = $appPath . $dir;
            $restricted_dirs[] = $full_path;
        }

        // System directories (OS-specific)
        if (DIRECTORY_SEPARATOR === '/') {
            // Unix/Linux/macOS system directories
            $system_dirs = [
                '/etc',      // System configuration
                '/var',      // Variable data
                '/usr',      // User programs
                '/bin',      // Essential binaries
                '/sbin',     // System binaries  
                '/boot',     // Boot files
                '/root',     // Root user home
                '/sys',      // System filesystem
                '/proc',     // Process information
                '/dev',      // Device files
                '/lib',      // Libraries
                '/opt',      // Optional software
                '/tmp',      // Temporary files (could be sensitive)
            ];
        } else {
            // Windows system directories
            $system_dirs = [
                'C:\\Windows',
                'C:\\Program Files',
                'C:\\Program Files (x86)',
                'C:\\Users\\Administrator',
                'C:\\ProgramData',
                'C:\\System Volume Information',
                'C:\\Recovery',
                'C:\\$Recycle.Bin',
            ];
        }

        $restricted_dirs = array_merge($restricted_dirs, $system_dirs);

        return $restricted_dirs;
    }

    /**
     * Get safe database storage directories
     * 
     * Returns a list of directories that are considered safe for database files.
     * Useful for providing guidance when database path validation fails.
     * 
     * @return array List of safe directory paths
     */
    public static function getSafeDatabaseDirectories(): array
    {
        $appPath = self::getApplicationPath();

        $safe_dirs = [
            $appPath . 'database/storage',     // Primary database storage
            $appPath . 'storage/database',     // Alternative database storage
            $appPath . 'data',                 // Data directory
            $appPath . 'var/database',         // Variable database storage
        ];

        // Add system temporary directories as backup options
        $temp_dirs = [
            sys_get_temp_dir(),               // System temp directory
            dirname(sys_get_temp_dir()) . '/database_backups'  // Dedicated backup temp
        ];

        return array_merge($safe_dirs, $temp_dirs);
    }

    /**
     * Check if a path is in a safe database directory
     * 
     * @param string $path Path to check
     * @return bool True if path is in a safe directory
     */
    public static function isPathInSafeDirectory(string $path): bool
    {
        $safeDirs = self::getSafeDatabaseDirectories();
        $normalizedPath = str_replace('\\', '/', realpath(dirname($path)) ?: dirname($path));

        foreach ($safeDirs as $safeDir) {
            $normalizedSafeDir = str_replace('\\', '/', realpath($safeDir) ?: $safeDir);

            if (strpos($normalizedPath, $normalizedSafeDir) === 0) {
                return true;
            }
        }

        return false;
    }

    // =============================================================================
    // ADDITIONAL SECURITY METHODS (SQL INJECTION, ETC.)
    // =============================================================================

    /**
     * Validate limit and offset values for pagination security
     * 
     * @param int|null $value Value to validate
     * @param string $type Type ('limit' or 'offset')
     * @return int|null Validated value
     * @throws InvalidArgumentException If validation fails
     */
    public static function validateLimitOffset(?int $value, string $type): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0) {
            throw new InvalidArgumentException("$type cannot be negative: $value");
        }

        static $maxLimits = ['limit' => 100000, 'offset' => 1000000];
        $maxLimit = $maxLimits[$type] ?? 100000;

        if ($value > $maxLimit) {
            throw new InvalidArgumentException("$type too large (max $maxLimit): $value");
        }

        return $value;
    }

    /**
     * Detect suspicious values that may indicate SQL injection attempts
     * 
     * @param mixed $value Value to check
     * @return bool True if value appears suspicious
     */
    public static function isSuspiciousValue($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        self::initializePatterns();

        foreach (self::$precompiledPatterns['sql_injection'] as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize output for safe HTML display
     * 
     * @param string $value Value to sanitize
     * @return string Sanitized value
     */
    public static function sanitizeOutput(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // =============================================================================
    // PATTERN INITIALIZATION AND UTILITIES
    // =============================================================================

    /**
     * Initialize precompiled regex patterns for maximum performance
     * 
     * @return void
     */
    private static function initializePatterns(): void
    {
        if (self::$patternsInitialized) {
            return;
        }

        self::$precompiledPatterns = [
            'identifier' => self::IDENTIFIER_PATTERN,
            'path_traversal' => '/\.\./',
            'sql_injection' => [
                '/\b(union|select|insert|update|delete|drop|create|alter)\b/i',
                '/[\'";]/',
                '/--/',
                '/\/\*.*\*\//',
                '/\bor\s+1\s*=\s*1\b/i',
                '/\band\s+1\s*=\s*1\b/i'
            ]
        ];

        self::$patternsInitialized = true;
    }

    /**
     * Log security violations for monitoring and analysis
     * 
     * @param string $violation Type of violation
     * @param mixed $value Problematic value
     * @return void
     */
    public static function logSecurityViolation(string $violation, $value): void
    {
        static $logBuffer = [];

        $logMessage = date('Y-m-d H:i:s') . " SECURITY: $violation - " .
            (is_string($value) ? substr($value, 0, 100) : gettype($value));

        $logBuffer[] = $logMessage;

        if (count($logBuffer) >= 10) {
            error_log(implode("\n", $logBuffer));
            $logBuffer = [];
        }
    }
}


/**
 * Ultra-Fast Cache Key Generation with xxh3
 * 
 * Simplified implementation assuming PHP 8.1+ with xxh3 always available.
 * Eliminates all algorithm detection overhead for maximum performance.
 * 
 * @package Database\Performance
 */
class FastCacheKey
{
    /**
     * Generate optimized cache key using xxh3
     * 
     * @param string $data Data to generate key for
     * @return string 16-character xxh3 hash
     */
    public static function generate(string $data): string
    {
        return hash('xxh3', $data);
    }

    /**
     * Generate cache key from multiple components efficiently
     * 
     * @param array $components Array of cache key components
     * @return string Optimized xxh3 cache key
     */
    public static function fromComponents(array $components): string
    {
        // Use null separator for maximum efficiency
        return hash('xxh3', implode("\0", $components));
    }
}
