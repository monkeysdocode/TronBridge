<?php

/**
 * A dialect-aware SQL parser for splitting complex SQL files into individual statements.
 *
 * This class provides a robust, state-machine-based parser designed to accurately
 * split large SQL dumps, schema files, and backup scripts into an array of
 * executable statements. It is built to handle the nuances of different SQL
 * dialects, including MySQL, PostgreSQL, and SQLite.
 *
 * Key Features:
 * - Stateful Parsing: Correctly handles multi-line statements, various quoting
 *   styles (single, double, backticks), and nested SQL structures.
 * - Dialect-Specific Logic:
 *   - PostgreSQL: Natively handles dollar-quoted strings ($tag$...$tag$),
 *     type casting (::), and complex array syntax.
 *   - MySQL: Correctly processes `DELIMITER` commands and backticked identifiers.
 * - Complex Object Support: Intelligently parses multi-statement triggers,
 *   functions, and procedures by tracking `BEGIN...END` block depth.
 * - Configurable Behavior: Parsing options can be customized, such as
 *   preserving comments or handling specific dialect features.
 * - Utility Methods: Includes functionality for parsing statistics, basic
 *   statement validation, and identifying statement types (e.g., DDL, DML).
 *
 * @package Database\Core
 * @author The TronBridge Project
 * @version 1.2.0
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
     * Processes the current character when the parser is in a normal, non-escaped state.
     *
     * This method acts as the primary router for the state machine. It examines the
     * current character to decide whether to transition into a new state, such as
     * inside a quoted string, a comment, a dollar-quoted string (PostgreSQL),
     * or a special `DELIMITER` command (MySQL).
     *
     * @param string $char              The single character being processed.
     * @param string $nextChar          The next character in the sequence for lookahead checks (e.g., for '/*' or '--').
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @param int    $state             The current state of the parser (will be STATE_NORMAL).
     * @param int    $position          The current character's index in the full SQL string.
     * @return int The new parser state, which may be the same or a new state constant.
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

    /**
     * Tracks the nesting level of BEGIN/END blocks within triggers, functions, or procedures.
     *
     * This method identifies the start of complex, multi-statement definitions 
     * (e.g., CREATE TRIGGER) and then maintains a depth counter for any nested 
     * BEGIN/END blocks. This is crucial for ensuring that semicolons (;) inside these 
     * definitions are correctly treated as part of the block's body and do not 
     * prematurely terminate the overall statement parsing.
     *
     * @param string $char             The current character being processed from the SQL content.
     * @param string $currentStatement The SQL statement that has been built up to the current position.
     * @param int    $position         The current character's index in the full SQL string.
     * @return void
     */
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

    /**
     * Resets the state flags used for tracking complex BEGIN/END blocks.
     *
     * This is called after a statement has been fully parsed to ensure the
     * state is clean for the next statement.
     *
     * @return void
     */
    private function resetParserState(): void
    {
        $this->beginEndDepth = 0;
        $this->inTrigger = false;
        $this->inFunction = false;
        $this->inProcedure = false;
    }

    /**
     * Manages the parser state while inside a single-quoted string literal.
     *
     * This method appends characters to the current statement until it finds a
     * terminating single quote. It correctly handles standard SQL escaped quotes
     * (e.g., 'It''s a test') by checking for two consecutive single quotes and
     * remaining in the current state if they are found.
     *
     * @param string $char              The character currently being processed.
     * @param string $nextChar          The next character in the sequence, for detecting escaped quotes.
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @param int    $state             The current parser state (will be STATE_IN_SINGLE_QUOTE).
     * @param int    $position          The current character's index in the full SQL string.
     * @return int Returns STATE_NORMAL if the quote is terminated, otherwise returns the current state.
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
     * Manages the parser state while inside a double-quoted identifier.
     *
     * Appends characters to the current statement until a terminating double quote
     * is found. It handles standard SQL escaped double quotes (e.g., "my""ident")
     * by checking for two consecutive double quotes.
     *
     * @param string $char              The character currently being processed.
     * @param string $nextChar          The next character in the sequence, for detecting escaped quotes.
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @param int    $state             The current parser state (will be STATE_IN_DOUBLE_QUOTE).
     * @param int    $position          The current character's index in the full SQL string.
     * @return int Returns STATE_NORMAL if the identifier is terminated, otherwise returns the current state.
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
     * Manages the parser state while inside a backtick-quoted identifier (MySQL-specific).
     *
     * This method appends characters to the current statement until it finds the
     * terminating backtick (`). It assumes no escaping within the identifier.
     *
     * @param string $char              The character currently being processed.
     * @param string $nextChar          The next character in the sequence (unused).
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @param int    $state             The current parser state (will be STATE_IN_BACKTICK).
     * @param int    $position          The current character's index in the full SQL string.
     * @return int Returns STATE_NORMAL if the identifier is terminated, otherwise returns the current state.
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
     * Manages the parser state while inside a single-line SQL comment (e.g., -- comment).
     *
     * This method consumes and optionally preserves characters until a newline
     * character is found, at which point it transitions the parser back to the
     * normal state.
     *
     * @param string $char              The character currently being processed.
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @param int    $state             The current parser state (will be STATE_IN_LINE_COMMENT).
     * @return int Returns STATE_NORMAL if a newline is found, otherwise returns the current state.
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
     * Manages the parser state while inside a multi-line block comment.
     *
     * This method consumes and optionally preserves characters until the closing
     * sequence is detected, at which point it transitions the parser back
     * to the normal state.
     *
     * @param string $char              The character currently being processed.
     * @param string $nextChar          The next character in the sequence, for detecting the '*\/' delimiter.
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @param int    $state             The current parser state (will be STATE_IN_BLOCK_COMMENT).
     * @param int    $position          The current character's index in the full SQL string.
     * @return int Returns STATE_NORMAL if the block comment is closed, otherwise returns the current state.
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
     * Manages the parser state while inside a PostgreSQL dollar-quoted string.
     *
     * This method consumes characters until it finds the matching closing dollar-quote
     * tag (e.g., the second `$tag$` in `$tag$some string$tag$`). It relies on the
     * `isDollarQuoteEnd` method to perform the lookahead check for the correct tag.
     *
     * @param string $char              The character currently being processed.
     * @param string $content           The entire SQL input string, used for lookahead matching.
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @param int    $state             The current parser state (will be STATE_IN_DOLLAR_QUOTE).
     * @param int    $position          The current character's index in the full SQL string.
     * @return int Returns STATE_NORMAL if the closing tag is found, otherwise returns the current state.
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
     * Transitions the parser into the line comment state.
     *
     * If the 'preserve_comments' option is enabled, this method appends the
     * comment delimiter (`--`) to the current statement before changing the state.
     *
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @return int                      Always returns STATE_IN_LINE_COMMENT.
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
     * Transitions the parser into the block comment state.
     *
     * If the 'preserve_comments' option is enabled, this method appends the
     * opening block comment delimiter (`/*`) to the current statement before
     * changing the state.
     *
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @return int                      Always returns STATE_IN_BLOCK_COMMENT.
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
     * Determines if the current character marks the end of a complete SQL statement.
     *
     * This method checks three primary conditions:
     * 1. The character must match the current delimiter (e.g., ';').
     * 2. The parser must not be inside a complex BEGIN...END block (e.g., in a trigger).
     * 3. The delimiter must not be part of an incomplete PostgreSQL type cast.
     *
     * @param string $char             The character to evaluate.
     * @param string $currentStatement The statement buffer collected so far.
     * @return bool True if the statement is complete, false otherwise.
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
     * Checks if the statement buffer currently ends with an incomplete PostgreSQL type cast.
     *
     * This method uses a simple heuristic to prevent the parser from splitting a
     * statement on a semicolon that might be part of a type-casting expression
     * (e.g., `some_value::text`). It specifically looks for patterns like `::typename`
     * or `::typename[` at the end of the string.
     *
     * @param string $statement The current statement buffer to inspect.
     * @return bool             True if the statement appears to end with an incomplete type cast, false otherwise.
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
     * 
     * @param string $text
     * @return bool
     */
    private function isDelimiterStatement(string $text): bool
    {
        return preg_match('/^\s*DELIMITER\s+/i', trim($text));
    }

    /**
     * Handles a MySQL-specific `DELIMITER` command.
     *
     * When a `DELIMITER` statement is detected, this method extracts the new
     * delimiter, updates the parser's state to use it, and then clears the
     * `DELIMITER` command from the current statement buffer so it is not treated
     * as an executable query.
     *
     * @param string &$currentStatement The accumulating SQL statement, passed by reference.
     * @param string $char              The character currently being processed.
     * @return int                      Always returns STATE_NORMAL.
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
     * Cleans, validates, and finalizes a raw SQL statement string.
     *
     * This method is called once the parser has identified a complete statement.
     * It trims whitespace, removes the trailing delimiter, and discards any
     * statements that are empty or contain only comments (unless configured otherwise).
     * It may also perform dialect-specific syntax validation.
     *
     * @param string $statement The raw statement string to be processed.
     * @return string|null The cleaned SQL statement, or null if the statement should be discarded.
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
     * 
     * @param string $statement The raw statement string to be processed.
     * @return bool True if the statement contains only comments, false otherwise.
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
     * 
     * @param string $statement The statement string to be processed.
     * @return string The processed statement.
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
     * 
     * @param string $statement The statement string to be processed
     * @return string The processed statement
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
     * 
     * @param string $arrayContent The content of the array literal
     * @return bool True if valid, false otherwise
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
     * 
     * @param string $arrayContent The content of the array literal
     * @return string The repaired array syntax
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
     * 
     * @param string $statement The statement to be processed
     * @return string The processed statement
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
     * 
     * @param string $jsonString The string to be validated
     * @return bool True if valid, false otherwise
     */
    private function isValidJson(string $jsonString): bool
    {
        // PHP <= v8.2
        if (!function_exists('json_validate')) {
            try {
                json_decode($jsonString, false, 512, 0 | JSON_THROW_ON_ERROR);
                return true;
            } catch (\JsonException $e) {
                return false;
            }
        }

        return json_validate($jsonString);
    }

    /**
     * Preserve type casting syntax
     * 
     * @param string $statement The statement to be processed
     * @return string The processed statement
     */
    private function preserveTypeCasting(string $statement): string
    {
        // Don't modify type casting unless there's a specific issue
        // The restore helper will handle any necessary fixes
        return $statement;
    }

    /**
     * Get parsing statistics
     * 
     * @return array Array of parsing statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Reset statistics
     * 
     * @return void
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
     * 
     * @param bool $debug Debug mode flag
     * @return void
     */
    public function setDebugMode(bool $debug): void
    {
        $this->parseOptions['debug_parsing'] = $debug;
    }

    /**
     * Get current delimiter
     * 
     * @return string Current delimiter
     */
    public function getCurrentDelimiter(): string
    {
        return $this->currentDelimiter;
    }

    /**
     * Validate statement syntax for specific database type
     * 
     * @param string $statement The statement to be validated
     * @return array Array of validation issues
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
     * 
     * @param string $statement The statement to be processed
     * @return array Array of validation issues
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
     * 
     * @param string $statement The statement to be processed
     * @return array Array of validation issues
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
     * 
     * @param string $statement The statement to be processed
     * @return string The statement type
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
