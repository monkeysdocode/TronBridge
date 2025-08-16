<?php

/**
 * Enhanced Database SQL Parser - Robust SQL Statement Parsing with Fixed PostgreSQL Support
 * 
 * A sophisticated SQL parser designed to handle complex SQL dumps, backup files,
 * and schema files across multiple database types. This version properly handles
 * PostgreSQL-specific syntax without over-correcting valid statements.
 * 
 * Key PostgreSQL Features Properly Handled:
 * - Type casting with :: syntax (::jsonb, ::text[], ::integer)
 * - Array literals with proper quoting ('{"item1", "item2"}')
 * - JSONB data with complex nested structures
 * - Dollar-quoted strings ($tag$...$tag$) for functions and triggers
 * - Double-quoted identifiers with proper escaping
 * - Complex constraint definitions spanning multiple lines
 * - PostgreSQL-specific SET statements and configuration
 * 
 * @package Database\Classes
 * @author Enhanced Model System
 * @version 1.2.0 - Fixed PostgreSQL Support
 */
class DatabaseSQLParser
{
    /** Database type constants */
    const DB_MYSQL = 'mysql';
    const DB_POSTGRESQL = 'postgresql';
    const DB_SQLITE = 'sqlite';
    const DB_GENERIC = 'generic';

    /** Parser state constants */
    const STATE_NORMAL = 0;
    const STATE_IN_SINGLE_QUOTE = 1;
    const STATE_IN_DOUBLE_QUOTE = 2;
    const STATE_IN_BACKTICK = 3;
    const STATE_IN_LINE_COMMENT = 4;
    const STATE_IN_BLOCK_COMMENT = 5;
    const STATE_IN_ESCAPED = 6;
    const STATE_IN_DOLLAR_QUOTE = 7;

    private string $databaseType;
    private string $currentDelimiter = ';';
    private string $currentDollarTag = '';
    private array $parseOptions;
    private array $statistics = [];

    private int $beginEndDepth = 0;
    private bool $inTrigger = false;
    private bool $inFunction = false;
    private bool $inProcedure = false;

    public function __construct(string $databaseType = self::DB_GENERIC, array $options = [])
    {
        $this->databaseType = strtolower($databaseType);
        $this->parseOptions = array_merge([
            'skip_empty_statements' => true,
            'skip_comments' => true,
            'preserve_comments' => false,
            'handle_delimiters' => ($databaseType === 'mysql'),
            'handle_dollar_quotes' => ($databaseType === 'postgresql'),
            'handle_type_casting' => ($databaseType === 'postgresql'),
            'chunk_size' => 8192,
            'max_statement_size' => 100 * 1024 * 1024, // 100MB max statement
            'strict_mode' => false,
            'debug_parsing' => false
        ], $options);

        $this->resetStatistics();
    }

    /**
     * Parse SQL content into individual statements with enhanced PostgreSQL support
     * 
     * @param string $content SQL content to parse
     * @return array Array of parsed SQL statements
     */
    public function parseStatements(string $content): array
    {
        $this->resetStatistics();
        $startTime = microtime(true);

        $statements = [];
        $currentStatement = '';
        $state = self::STATE_NORMAL;
        $line = 1;
        $position = 0;
        $length = strlen($content);

        $this->statistics['total_bytes'] = $length;

        while ($position < $length) {
            $char = $content[$position];
            $nextChar = ($position + 1 < $length) ? $content[$position + 1] : '';
            $prevChar = ($position > 0) ? $content[$position - 1] : '';

            // Track line numbers for debugging
            if ($char === "\n") {
                $line++;
            }

            // State machine for SQL parsing
            switch ($state) {
                case self::STATE_NORMAL:
                    $state = $this->handleNormalState($char, $nextChar, $currentStatement, $state, $position);
                    break;

                case self::STATE_IN_SINGLE_QUOTE:
                    $state = $this->handleSingleQuoteState($char, $nextChar, $currentStatement, $state, $position);
                    break;

                case self::STATE_IN_DOUBLE_QUOTE:
                    $state = $this->handleDoubleQuoteState($char, $nextChar, $currentStatement, $state, $position);
                    break;

                case self::STATE_IN_BACKTICK:
                    $state = $this->handleBacktickState($char, $nextChar, $currentStatement, $state, $position);
                    break;

                case self::STATE_IN_LINE_COMMENT:
                    $state = $this->handleLineCommentState($char, $currentStatement, $state);
                    break;

                case self::STATE_IN_BLOCK_COMMENT:
                    $state = $this->handleBlockCommentState($char, $nextChar, $currentStatement, $state, $position);
                    break;

                case self::STATE_IN_DOLLAR_QUOTE:
                    $state = $this->handleDollarQuoteState($char, $content, $currentStatement, $state, $position);
                    break;

                case self::STATE_IN_ESCAPED:
                    $currentStatement .= $char;
                    $state = self::STATE_NORMAL;
                    break;
            }

            // Check for statement completion in normal state
            if ($state === self::STATE_NORMAL && $this->isStatementComplete($char, $currentStatement)) {
                $statement = $this->processStatement($currentStatement);

                if ($statement !== null) {
                    $statements[] = $statement;
                }
                $currentStatement = '';
                $this->resetParserState();
            }

            $position++;

            // Prevent infinite loops with very large statements
            if (strlen($currentStatement) > $this->parseOptions['max_statement_size']) {
                throw new Exception("Statement too large: " . substr($currentStatement, 0, 100) . "...");
            }
        }

        // Handle any remaining statement
        if (!empty(trim($currentStatement))) {
            $statement = $this->processStatement($currentStatement);
            if ($statement !== null) {
                $statements[] = $statement;
            }
            $this->resetParserState();
        }

        $this->statistics['parsing_time'] = microtime(true) - $startTime;
        $this->statistics['statements_parsed'] = count($statements);
        $this->statistics['final_line'] = $line;
        return $statements;
    }

    /**
     * Handle normal parsing state
     */
    private function handleNormalState(string $char, string $nextChar, string &$currentStatement, int $state, int $position): int
    {
        // Handle BEGIN...END block detection FIRST
        $this->handleBeginEndBlocks($char, $currentStatement, $position);

        switch ($char) {
            case "'":
                $currentStatement .= $char;
                return self::STATE_IN_SINGLE_QUOTE;

            case '"':
                $currentStatement .= $char;
                return self::STATE_IN_DOUBLE_QUOTE;

            case '`':
                if ($this->databaseType === self::DB_MYSQL) {
                    $currentStatement .= $char;
                    return self::STATE_IN_BACKTICK;
                }
                break;

            case '-':
                if ($nextChar === '-') {
                    return $this->handleLineCommentStart($currentStatement);
                }
                break;

            case '/':
                if ($nextChar === '*') {
                    return $this->handleBlockCommentStart($currentStatement);
                }
                break;

            case '$':
                if ($this->parseOptions['handle_dollar_quotes'] && $this->isDollarQuoteStart($char, $nextChar)) {
                    return $this->handleDollarQuoteStart($char, $currentStatement, $position);
                }
                break;

            case 'D':
            case 'd':
                if ($this->parseOptions['handle_delimiters'] && $this->isDelimiterStatement($currentStatement . $char)) {
                    return $this->handleDelimiterStatement($currentStatement, $char);
                }
                break;
        }

        $currentStatement .= $char;
        return $state;
    }


    private function handleBeginEndBlocks(string $char, string $currentStatement, int $position): void
    {
        $upperStatement = strtoupper($currentStatement . $char);

        // Detect start of block structures (only if not already in one)
        if (!$this->inTrigger && !$this->inFunction && !$this->inProcedure) {

            // Trigger detection
            if (preg_match('/\bCREATE\s+TRIGGER\s+\w+/', $upperStatement)) {
                $this->inTrigger = true;
            }

            // Function detection (PostgreSQL, MySQL)
            if (preg_match('/\bCREATE\s+(OR\s+REPLACE\s+)?FUNCTION\s+\w+/', $upperStatement)) {
                $this->inFunction = true;
            }

            // Procedure detection (MySQL, PostgreSQL)  
            if (preg_match('/\bCREATE\s+(OR\s+REPLACE\s+)?PROCEDURE\s+\w+/', $upperStatement)) {
                $this->inProcedure = true;
            }
        }

        // Only track BEGIN...END depth if we're in a block structure
        if ($this->inTrigger || $this->inFunction || $this->inProcedure) {

            // BEGIN detection - look for word boundary
            if (preg_match('/\bBEGIN\s*$/i', $currentStatement . $char)) {
                $this->beginEndDepth++;
            }

            // END detection - but not END IF, END LOOP, etc.
            if (
                preg_match('/\bEND\s*;?\s*$/i', $currentStatement . $char) &&
                !preg_match('/\b(END\s+(IF|LOOP|CASE|WHILE))\s*;?\s*$/i', $currentStatement . $char)
            ) {

                $this->beginEndDepth--;

                // If we've closed all blocks, reset state
                if ($this->beginEndDepth <= 0) {
                    $this->inTrigger = false;
                    $this->inFunction = false;
                    $this->inProcedure = false;
                    $this->beginEndDepth = 0;
                }
            }
        }
    }

    private function resetParserState(): void
    {
        $this->beginEndDepth = 0;
        $this->inTrigger = false;
        $this->inFunction = false;
        $this->inProcedure = false;
    }

    /**
     * Handle single quote state with proper escaping
     */
    private function handleSingleQuoteState(string $char, string $nextChar, string &$currentStatement, int $state, int $position): int
    {
        $currentStatement .= $char;

        if ($char === "'") {
            // Check for escaped quote ('' in SQL)
            if ($nextChar === "'") {
                // This is an escaped quote, continue in quote state
                return $state;
            } else {
                // End of quoted string
                return self::STATE_NORMAL;
            }
        }

        return $state;
    }

    /**
     * Handle double quote state (for identifiers)
     */
    private function handleDoubleQuoteState(string $char, string $nextChar, string &$currentStatement, int $state, int $position): int
    {
        $currentStatement .= $char;

        if ($char === '"') {
            // Check for escaped quote ("" in SQL)
            if ($nextChar === '"') {
                // This is an escaped quote, continue in quote state
                return $state;
            } else {
                // End of quoted identifier
                return self::STATE_NORMAL;
            }
        }

        return $state;
    }

    /**
     * Handle backtick state (MySQL identifiers)
     */
    private function handleBacktickState(string $char, string $nextChar, string &$currentStatement, int $state, int $position): int
    {
        $currentStatement .= $char;

        if ($char === '`') {
            return self::STATE_NORMAL;
        }

        return $state;
    }

    /**
     * Handle line comment state
     */
    private function handleLineCommentState(string $char, string &$currentStatement, int $state): int
    {
        if ($char === "\n") {
            if ($this->parseOptions['preserve_comments']) {
                $currentStatement .= $char;
            }
            return self::STATE_NORMAL;
        }

        if ($this->parseOptions['preserve_comments']) {
            $currentStatement .= $char;
        }

        return $state;
    }

    /**
     * Handle block comment state
     */
    private function handleBlockCommentState(string $char, string $nextChar, string &$currentStatement, int $state, int $position): int
    {
        if ($char === '*' && $nextChar === '/') {
            if ($this->parseOptions['preserve_comments']) {
                $currentStatement .= $char . $nextChar;
            }
            return self::STATE_NORMAL;
        }

        if ($this->parseOptions['preserve_comments']) {
            $currentStatement .= $char;
        }

        return $state;
    }

    /**
     * Handle PostgreSQL dollar quote state
     */
    private function handleDollarQuoteState(string $char, string $content, string &$currentStatement, int $state, int $position): int
    {
        $currentStatement .= $char;

        // Check if we've reached the end tag
        if ($char === '$' && $this->isDollarQuoteEnd($content, $position)) {
            $this->currentDollarTag = '';
            return self::STATE_NORMAL;
        }

        return $state;
    }

    /**
     * Check if current position starts a dollar quote
     */
    private function isDollarQuoteStart(string $char, string $nextChar): bool
    {
        return $char === '$' && ($nextChar === '$' || ctype_alnum($nextChar) || $nextChar === '_');
    }

    /**
     * Handle start of dollar quote
     */
    private function handleDollarQuoteStart(string $char, string &$currentStatement, int $position): int
    {
        // Extract the dollar tag
        $this->currentDollarTag = $this->extractDollarTag($char, $position);
        $currentStatement .= $char;
        return self::STATE_IN_DOLLAR_QUOTE;
    }

    /**
     * Extract dollar quote tag
     */
    private function extractDollarTag(string $content, int $position): string
    {
        $tag = '';
        $length = strlen($content);

        // Check bounds before accessing $content[$position]
        if ($position >= $length || $content[$position] !== '$') {
            return $tag;
        }

        $tag .= '$';
        $i = $position + 1;

        // Extract tag content
        while ($i < $length && $content[$i] !== '$') {
            if (ctype_alnum($content[$i]) || $content[$i] === '_') {
                $tag .= $content[$i];
            } else {
                break;
            }
            $i++;
        }

        if ($i < $length && $content[$i] === '$') {
            $tag .= '$';
        }

        return $tag;
    }


    /**
     * Check if we've reached the end of a dollar quote
     */
    private function isDollarQuoteEnd(string $content, int $position): bool
    {
        if (empty($this->currentDollarTag)) {
            return false;
        }

        $tagLength = strlen($this->currentDollarTag);
        $remaining = substr($content, $position, $tagLength);

        return $remaining === $this->currentDollarTag;
    }

    /**
     * Handle line comment start
     */
    private function handleLineCommentStart(string &$currentStatement): int
    {
        if (!$this->parseOptions['preserve_comments']) {
            return self::STATE_IN_LINE_COMMENT;
        }

        $currentStatement .= '--';
        return self::STATE_IN_LINE_COMMENT;
    }

    /**
     * Handle block comment start
     */
    private function handleBlockCommentStart(string &$currentStatement): int
    {
        if (!$this->parseOptions['preserve_comments']) {
            return self::STATE_IN_BLOCK_COMMENT;
        }

        $currentStatement .= '/*';
        return self::STATE_IN_BLOCK_COMMENT;
    }

    /**
     * Check if current character completes a statement
     */
    private function isStatementComplete(string $char, string $currentStatement): bool
    {
        if ($char !== $this->currentDelimiter) {
            return false;
        }

        if ($this->beginEndDepth > 0) {
            return false; // We're inside a block, semicolons don't terminate
        }

        // Don't break on delimiter inside type casting
        if ($this->parseOptions['handle_type_casting'] && $this->isInsideTypeCast($currentStatement)) {
            return false;
        }

        return true;
    }

    /**
     * Check if we're inside a PostgreSQL type cast
     */
    private function isInsideTypeCast(string $statement): bool
    {
        // Simple heuristic: if the last few characters suggest we're in the middle of a type cast
        $trimmed = trim($statement);

        // Check for incomplete type casting syntax
        if (preg_match('/::\s*[a-zA-Z_][a-zA-Z0-9_]*\s*$/', $trimmed)) {
            return true;
        }

        // Check for incomplete array syntax
        if (preg_match('/::\s*[a-zA-Z_][a-zA-Z0-9_]*\[\s*$/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * Check if current text is a DELIMITER statement
     */
    private function isDelimiterStatement(string $text): bool
    {
        return preg_match('/^\s*DELIMITER\s+/i', trim($text));
    }

    /**
     * Handle DELIMITER statement
     */
    private function handleDelimiterStatement(string &$currentStatement, string $char): int
    {
        $currentStatement .= $char;

        // Extract new delimiter from DELIMITER statement
        if (preg_match('/DELIMITER\s+(.+)$/i', trim($currentStatement), $matches)) {
            $this->currentDelimiter = trim($matches[1]);
            $currentStatement = ''; // Clear the DELIMITER statement
        }

        return self::STATE_NORMAL;
    }

    /**
     * Process and clean a statement
     */
    private function processStatement(string $statement): ?string
    {
        $statement = trim($statement);

        // Skip empty statements
        if (empty($statement)) {
            return null;
        }

        // Skip comment-only statements if not preserving comments
        if (!$this->parseOptions['preserve_comments'] && $this->isCommentOnlyStatement($statement)) {
            return null;
        }

        // Remove trailing delimiter
        if (substr($statement, -strlen($this->currentDelimiter)) === $this->currentDelimiter) {
            $statement = substr($statement, 0, -strlen($this->currentDelimiter));
            $statement = trim($statement);
        }

        // Skip if still empty after processing
        if (empty($statement)) {
            return null;
        }

        // Validate PostgreSQL-specific syntax if needed
        if ($this->databaseType === self::DB_POSTGRESQL && $this->parseOptions['handle_type_casting']) {
            $statement = $this->validatePostgreSQLSyntax($statement);
        }

        $this->statistics['statements_processed']++;

        return $statement;
    }

    /**
     * Check if statement contains only comments
     */
    private function isCommentOnlyStatement(string $statement): bool
    {
        // Remove all comments and see if anything remains
        $withoutComments = preg_replace('/--.*$/m', '', $statement);
        $withoutComments = preg_replace('/\/\*.*?\*\//s', '', $withoutComments);

        return empty(trim($withoutComments));
    }

    /**
     * Validate and preserve PostgreSQL syntax
     */
    private function validatePostgreSQLSyntax(string $statement): string
    {
        // Ensure proper array syntax is preserved
        $statement = $this->preserveArraySyntax($statement);

        // Ensure proper JSONB syntax is preserved  
        $statement = $this->preserveJsonbSyntax($statement);

        // Ensure proper type casting is preserved
        $statement = $this->preserveTypeCasting($statement);

        return $statement;
    }

    /**
     * Preserve PostgreSQL array syntax
     */
    private function preserveArraySyntax(string $statement): string
    {
        // Validate array literals with type casting
        return preg_replace_callback(
            '/\'(\{[^}]*\})\'\s*::\s*text\[\]/i',
            function ($matches) {
                $arrayContent = $matches[1];

                // Basic validation - ensure proper quoting structure
                if ($this->isValidArraySyntax($arrayContent)) {
                    return $matches[0]; // Keep original if valid
                }

                // Try to repair if invalid
                return "'" . $this->repairArraySyntax($arrayContent) . "'::text[]";
            },
            $statement
        );
    }

    /**
     * Check if array syntax is valid
     */
    private function isValidArraySyntax(string $arrayContent): bool
    {
        // Remove outer braces
        $content = trim($arrayContent, '{}');

        // Empty array is valid
        if (empty($content)) {
            return true;
        }

        // Check for proper element separation
        $elements = explode(',', $content);
        foreach ($elements as $element) {
            $trimmed = trim($element);
            // Each element should be properly quoted or be a simple value
            if (!preg_match('/^"[^"]*"$|^[^,{}]+$/', $trimmed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Repair array syntax
     */
    private function repairArraySyntax(string $arrayContent): string
    {
        $content = trim($arrayContent, '{}');

        if (empty($content)) {
            return '{}';
        }

        $elements = explode(',', $content);
        $repairedElements = array_map(function ($element) {
            $trimmed = trim($element, ' "\'');
            return '"' . str_replace('"', '""', $trimmed) . '"';
        }, $elements);

        return '{' . implode(',', $repairedElements) . '}';
    }

    /**
     * Preserve JSONB syntax
     */
    private function preserveJsonbSyntax(string $statement): string
    {
        return preg_replace_callback(
            '/\'([^\']*)\'\s*::\s*jsonb/i',
            function ($matches) {
                $jsonString = $matches[1];

                // Validate JSON
                if ($this->isValidJson($jsonString)) {
                    return $matches[0]; // Keep original if valid
                }

                // If invalid, we might want to keep it and let the database handle it
                // or try to fix simple issues
                return $matches[0];
            },
            $statement
        );
    }

    /**
     * Check if string is valid JSON
     */
    private function isValidJson(string $jsonString): bool
    {
        json_decode($jsonString);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Preserve type casting syntax
     */
    private function preserveTypeCasting(string $statement): string
    {
        // Don't modify type casting unless there's a specific issue
        // The restore helper will handle any necessary fixes
        return $statement;
    }

    /**
     * Get parsing statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Reset statistics
     */
    private function resetStatistics(): void
    {
        $this->statistics = [
            'total_bytes' => 0,
            'statements_parsed' => 0,
            'statements_processed' => 0,
            'parsing_time' => 0,
            'final_line' => 0,
            'delimiter_changes' => 0,
            'dollar_quotes_processed' => 0
        ];
    }

    /**
     * Set debug mode
     */
    public function setDebugMode(bool $debug): void
    {
        $this->parseOptions['debug_parsing'] = $debug;
    }

    /**
     * Get current delimiter
     */
    public function getCurrentDelimiter(): string
    {
        return $this->currentDelimiter;
    }

    /**
     * Validate statement syntax for specific database type
     */
    public function validateStatement(string $statement): array
    {
        $issues = [];

        if ($this->databaseType === self::DB_POSTGRESQL) {
            $issues = array_merge($issues, $this->validatePostgreSQLStatement($statement));
        } elseif ($this->databaseType === self::DB_MYSQL) {
            $issues = array_merge($issues, $this->validateMySQLStatement($statement));
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'statement_type' => $this->identifyStatementType($statement)
        ];
    }

    /**
     * Validate PostgreSQL-specific statement
     */
    private function validatePostgreSQLStatement(string $statement): array
    {
        $issues = [];

        // Check for unmatched dollar quotes
        if (preg_match_all('/\$([a-zA-Z0-9_]*)\$/', $statement, $matches)) {
            $tags = $matches[1];
            if (count($tags) % 2 !== 0) {
                $issues[] = 'Unmatched dollar quote tags';
            }
        }

        // Check for malformed array syntax
        if (preg_match('/\{[^}]*\}.*::\s*[a-zA-Z_][a-zA-Z0-9_]*\[\]/', $statement)) {
            // This is likely valid PostgreSQL array syntax
        }

        return $issues;
    }

    /**
     * Validate MySQL-specific statement
     */
    private function validateMySQLStatement(string $statement): array
    {
        $issues = [];

        // Check for unmatched backticks
        if (substr_count($statement, '`') % 2 !== 0) {
            $issues[] = 'Unmatched backticks';
        }

        return $issues;
    }

    /**
     * Identify statement type
     */
    private function identifyStatementType(string $statement): string
    {
        $statement = trim(strtoupper($statement));

        if (strpos($statement, 'SELECT') === 0) return 'SELECT';
        if (strpos($statement, 'INSERT') === 0) return 'INSERT';
        if (strpos($statement, 'UPDATE') === 0) return 'UPDATE';
        if (strpos($statement, 'DELETE') === 0) return 'DELETE';
        if (strpos($statement, 'CREATE TABLE') === 0) return 'CREATE_TABLE';
        if (strpos($statement, 'CREATE INDEX') === 0) return 'CREATE_INDEX';
        if (strpos($statement, 'CREATE') === 0) return 'CREATE';
        if (strpos($statement, 'ALTER') === 0) return 'ALTER';
        if (strpos($statement, 'DROP') === 0) return 'DROP';
        if (strpos($statement, 'SET') === 0) return 'SET';

        return 'OTHER';
    }
}
