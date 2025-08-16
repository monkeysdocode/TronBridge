<?php

require_once dirname(__DIR__, 2) . '/core/DatabaseSQLParser.php';
require_once dirname(__DIR__) . '/exceptions/ParseException.php';
require_once dirname(__DIR__) . '/schema/Column.php';
require_once dirname(__DIR__) . '/schema/Constraint.php';
require_once dirname(__DIR__) . '/schema/Index.php';
require_once dirname(__DIR__) . '/schema/Table.php';

/**
 * Abstract SQL Parser
 *
 * Provides a common interface and base functionality for parsing SQL statements
 * into database-agnostic schema objects (Table, Column, etc.).
 *
 * @package Database\Parsers
 */
abstract class AbstractParser
{
    /**
     * Parser options.
     *
     * @var array
     */
    protected array $options = [];

    /**
     * Warnings generated during parsing.
     *
     * @var array
     */
    protected array $warnings = [];

    /**
     * Initializes the parser with given options.
     *
     * @param array $options An array of parser options.
     *        - 'strict': bool, throw exceptions on parsing errors (default: false).
     *        - 'preserve_comments': bool, keep comments in parsed objects (default: false).
     *        - 'debug': bool, log debug messages (default: false).
     *        - 'process_insert_statements': bool, parse INSERT statements (default: true).
     *        - 'insert_batch_size': int, batch size for INSERT processing (default: 1000).
     *        - 'validate_insert_columns': bool, validate INSERT columns exist (default: true).
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'strict' => false,
            'preserve_comments' => false,
            'debug' => false,
            'process_insert_statements' => true,
            'insert_batch_size' => 1000,
            'validate_insert_columns' => true,
            'normalize_insert_data' => true
        ], $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Abstract Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get the name of the parser (e.g., 'mysql', 'postgresql').
     *
     * @return string The parser name.
     */
    abstract public function getName(): string;

    /**
     * Parse a CREATE TABLE statement into a Table object.
     *
     * @param string $sql The CREATE TABLE statement.
     * @return Table A Table object representing the parsed statement.
     * @throws ParseException If the statement is invalid.
     */
    abstract public function parseCreateTable(string $sql): Table;

    /**
     * Parse a column definition string into a Column object.
     *
     * @param Table $table The table the column belongs to.
     * @param string $definition The column definition string.
     * @return Column A Column object.
     */
    abstract public function parseColumnDefinition(Table $table, string $definition): Column;

    /**
     * Parse an index definition string into an Index object.
     *
     * @param Table $table The table the index belongs to.
     * @param string $definition The index definition string.
     * @return Index An Index object.
     */
    abstract public function parseIndexDefinition(Table $table, string $definition): Index;

    /**
     * Parse a constraint definition string into a Constraint object.
     *
     * @param Table $table The table the constraint belongs to.
     * @param string $definition The constraint definition string.
     * @return Constraint A Constraint object.
     */
    abstract public function parseConstraintDefinition(Table $table, string $definition): Constraint;

    /**
     * Process a single ALTER TABLE operation.
     * 
     * This method must be implemented by each database-specific parser to handle
     * the database-specific syntax for ALTER TABLE operations.
     *
     * @param Table $table The table object being altered.
     * @param string $operation The operation SQL string (e.g., 'ADD COLUMN ...').
     * @return void
     * @throws ParseException If the operation cannot be parsed.
     */
    abstract protected function processAlterOperation(Table $table, string $operation): void;

    /**
     * Parse INSERT data from an INSERT statement
     * 
     * This method must be implemented by each database-specific parser to handle
     * database-specific INSERT syntax and data formats.
     *
     * @param string $statement The INSERT statement
     * @return array Array of row data (each row is associative array)
     * @throws ParseException If the INSERT statement cannot be parsed
     */
    abstract protected function parseInsertData(string $statement): array;


    /*
    |--------------------------------------------------------------------------
    | Parsing Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Parse a string containing multiple SQL statements.
     *
     * @param string $sql The SQL string to parse.
     * @return Table[] An array of Table objects created or modified by the statements.
     * @throws ParseException In strict mode if any statement fails to parse.
     */
    public function parseStatements(string $sql): array
    {
        $tables = [];
        $parser = new \DatabaseSQLParser($this->getDatabaseType(), [
            'skip_empty_statements' => true,
            'skip_comments' => !$this->options['preserve_comments'],
        ]);

        $statements = $parser->parseStatements($sql);

        foreach ($statements as $index => $statement) {
            try {
                $this->parseSingleStatement($statement, $tables);
            } catch (\Exception $e) {
                if ($this->options['strict']) {
                    throw new ParseException("Failed to parse statement #{$index}: " . $e->getMessage(), 0);
                }
                $this->addWarning("Skipping statement due to error: " . $e->getMessage());
            }
        }

        return $tables;
    }

    /**
     * Parse a single SQL statement and update the tables array.
     *
     * @param string $statement The SQL statement.
     * @param array &$tables A reference to the array of Table objects.
     * @return void
     */
    protected function parseSingleStatement(string $statement, array &$tables): void
    {
        $statement = trim($statement);
        if (empty($statement)) {
            return;
        }

        if ($this->isCreateTableStatement($statement)) {
            $table = $this->parseCreateTable($statement);
            $tables[$table->getName()] = $table;
        } elseif ($this->isAlterTableStatement($statement)) {
            $this->parseAlterTable($statement, $tables);
        } elseif ($this->isCreateIndexStatement($statement)) {
            $this->parseStandaloneCreateIndex($statement, $tables);
        } elseif ($this->isCreateSequenceStatement($statement)) {
            $this->parseCreateSequence($statement, $tables);
        } elseif ($this->isInsertStatement($statement) && $this->options['process_insert_statements']) {
            $this->parseInsertStatement($statement, $tables);
        } else {
            $this->addWarning("Skipping unsupported statement type: " . $this->getStatementType($statement));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT Statement Parsing (NEW)
    |--------------------------------------------------------------------------
    */

    /**
     * Parse INSERT statement and associate data with appropriate table
     *
     * @param string $statement The INSERT statement
     * @param array &$tables Reference to tables array
     * @return void
     */
    protected function parseInsertStatement(string $statement, array &$tables): void
    {
        try {
            // Extract table name from INSERT statement
            $tableName = $this->extractTableNameFromInsert($statement);

            if (!$tableName) {
                $this->addWarning("Could not extract table name from INSERT statement");
                return;
            }

            // Check if table exists in our parsed tables
            if (!isset($tables[$tableName])) {
                $this->addWarning("INSERT statement references unknown table: $tableName");
                return;
            }

            $table = $tables[$tableName];

            // Parse INSERT data using database-specific implementation
            $insertData = $this->parseInsertData($statement);

            if (empty($insertData)) {
                if ($this->options['debug']) {
                    error_log("AbstractParser: No data extracted from INSERT statement for table: $tableName");
                }
                return;
            }

            // Validate columns if enabled
            if ($this->options['validate_insert_columns']) {
                $insertData = $this->validateInsertColumns($table, $insertData);
            }

            // Add data to table
            $existingData = $table->getData();
            $newData = array_merge($existingData, $insertData);

            $table->setData($newData, [
                'source_format' => 'associative',
                'conflict_handling' => 'error',
                'validate_columns' => $this->options['validate_insert_columns'],
                'normalize_data' => $this->options['normalize_insert_data']
            ]);

            if ($this->options['debug']) {
                error_log("AbstractParser: Added " . count($insertData) . " rows to table '$tableName'");
            }
        } catch (\Exception $e) {
            if ($this->options['strict']) {
                throw new ParseException("Failed to parse INSERT statement: " . $e->getMessage());
            } else {
                $this->addWarning("Failed to parse INSERT statement: " . $e->getMessage());
            }
        }
    }

    /**
     * Extract table name from INSERT statement
     *
     * @param string $statement The INSERT statement
     * @return string|null The table name or null if not found
     */
    protected function extractTableNameFromInsert(string $statement): ?string
    {
        // Match: INSERT INTO table_name or INSERT INTO `table_name` or INSERT INTO "table_name"
        if (preg_match('/INSERT\s+(?:IGNORE\s+)?(?:OR\s+(?:IGNORE|REPLACE)\s+)?INTO\s+(?:`([^`]+)`|"([^"]+)"|(\w+))/i', $statement, $matches)) {
            return $matches[1] ?: $matches[2] ?: $matches[3];
        }

        return null;
    }

    /**
     * Validate INSERT data columns against table schema
     *
     * @param Table $table The target table
     * @param array $insertData Array of row data
     * @return array Validated and filtered row data
     */
    protected function validateInsertColumns(Table $table, array $insertData): array
    {
        $tableColumns = $table->getColumnNames();
        $validatedData = [];

        foreach ($insertData as $rowIndex => $row) {
            $validRow = [];
            $invalidColumns = [];

            foreach ($row as $columnName => $value) {
                if (in_array($columnName, $tableColumns)) {
                    $validRow[$columnName] = $value;
                } else {
                    $invalidColumns[] = $columnName;
                }
            }

            if (!empty($invalidColumns)) {
                $this->addWarning("Row $rowIndex: Invalid columns ignored: " . implode(', ', $invalidColumns));
            }

            if (!empty($validRow)) {
                $validatedData[] = $validRow;
            }
        }

        return $validatedData;
    }

    /**
     * Parse ALTER TABLE statement and apply changes to the tables array.
     * 
     * This method contains the general logic for processing ALTER TABLE statements
     * that is common across all database types. Database-specific operation parsing
     * is delegated to the abstract processAlterOperation() method.
     *
     * @param string $statement The ALTER TABLE statement.
     * @param array &$tables A reference to the array of Table objects.
     * @return void
     */
    protected function parseAlterTable(string $statement, array &$tables): void
    {
        // Extract table name
        $tableName = $this->extractTableNameFromAlter($statement);
        if (!$tableName) {
            $this->addWarning("Could not parse table name from ALTER TABLE statement");
            return;
        }

        // Check if table exists in our parsed tables
        if (!isset($tables[$tableName])) {
            $this->addWarning("ALTER TABLE references unknown table: $tableName");
            return;
        }

        $table = $tables[$tableName];

        // Extract the ALTER operations part - using generic regex that should work for most SQL databases
        if (!preg_match('/ALTER\s+TABLE\s+(?:`[^`]+`|"[^"]+"|[\w]+)\s+(.+)$/is', $statement, $matches)) {
            $this->addWarning("Could not parse ALTER TABLE operations");
            return;
        }

        $alterClause = $matches[1];

        // Split multiple operations (comma-separated) - using existing method
        $operations = $this->splitAlterOperations($alterClause);

        // Process each operation using database-specific logic
        foreach ($operations as $index => $operation) {
            $operation = trim($operation);
            if (empty($operation)) continue;

            try {
                $this->processAlterOperation($table, $operation);
            } catch (\Exception $e) {
                if ($this->options['strict']) {
                    throw new ParseException("Failed to parse ALTER operation '$operation' for table '$tableName': " . $e->getMessage());
                } else {
                    $this->addWarning("Failed to parse ALTER operation for table '$tableName': " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Parse a standalone CREATE INDEX statement.
     *
     * @param string $statement The CREATE INDEX statement.
     * @param array &$tables A reference to the array of Table objects.
     * @return void
     */
    protected function parseStandaloneCreateIndex(string $statement, array &$tables): void
    {
        $this->addWarning("Standalone CREATE INDEX statements are not fully supported by this parser.");
    }

    /**
     * Parse a CREATE SEQUENCE statement.
     *
     * @param string $statement The CREATE SEQUENCE statement.
     * @param array &$tables A reference to the array of Table objects.
     * @return void
     */
    protected function parseCreateSequence(string $statement, array &$tables): void
    {
        $this->addWarning("CREATE SEQUENCE statements are not supported by this parser.");
    }

    /*
    |--------------------------------------------------------------------------
    | Statement Type Identification
    |--------------------------------------------------------------------------
    */

    /**
     * Check if a statement is a CREATE TABLE statement.
     *
     * @param string $sql The SQL statement.
     * @return bool True if it is a CREATE TABLE statement.
     */
    protected function isCreateTableStatement(string $sql): bool
    {
        return preg_match('/^CREATE\s+(?:TEMPORARY\s+|TEMP\s+)?TABLE/i', trim($sql)) === 1;
    }

    /**
     * Check if a statement is a CREATE INDEX statement.
     *
     * @param string $sql The SQL statement.
     * @return bool True if it is a CREATE INDEX statement.
     */
    protected function isCreateIndexStatement(string $sql): bool
    {
        return preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX/i', trim($sql)) === 1;
    }

    /**
     * Check if a statement is an ALTER TABLE statement.
     *
     * @param string $sql The SQL statement.
     * @return bool True if it is an ALTER TABLE statement.
     */
    protected function isAlterTableStatement(string $sql): bool
    {
        return preg_match('/^ALTER\s+TABLE/i', trim($sql)) === 1;
    }

    /**
     * Check if a statement is a CREATE SEQUENCE statement.
     *
     * @param string $sql The SQL statement.
     * @return bool True if it is a CREATE SEQUENCE statement.
     */
    protected function isCreateSequenceStatement(string $sql): bool
    {
        return preg_match('/^CREATE\s+SEQUENCE/i', trim($sql)) === 1;
    }

    /**
     * Check if a statement is an INSERT statement
     *
     * @param string $sql The SQL statement.
     * @return bool True if it is an INSERT statement.
     */
    protected function isInsertStatement(string $sql): bool
    {
        return preg_match('/^INSERT\s+(?:IGNORE\s+|OR\s+(?:IGNORE|REPLACE)\s+)?INTO/i', trim($sql)) === 1;
    }

    /**
     * Get the general type of an SQL statement for debugging or logging.
     *
     * @param string $sql The SQL statement.
     * @return string The statement type (e.g., 'CREATE TABLE', 'UNKNOWN').
     */
    protected function getStatementType(string $sql): string
    {
        if (preg_match('/^(\w+(?:\s+\w+)?)/i', trim($sql), $matches)) {
            return strtoupper($matches[1]);
        }
        return 'UNKNOWN';
    }

    /*
    |--------------------------------------------------------------------------
    | Parsing Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Parse column modifiers like NOT NULL, DEFAULT, etc.
     *
     * @param Column $column The column object to update.
     * @param string $modifiers The string of modifiers.
     * @return void
     */
    protected function parseColumnModifiers(Column $column, string $modifiers, Table $table): void
    {
        if (preg_match('/NOT\s+NULL/i', $modifiers)) {
            $column->setNullable(false);
        }
        if (preg_match('/AUTO_INCREMENT/i', $modifiers)) {
            $column->setAutoIncrement(true);
        }
        if (preg_match('/DEFAULT\s+([\'"]?)(.*?)\1(?:\s|$)/i', $modifiers, $matches)) {
            $column->setDefault($matches[2] === 'NULL' ? null : $matches[2]);
        }
        if (preg_match('/COMMENT\s+\'([^\']+)\'/i', $modifiers, $matches)) {
            $column->setComment($matches[1]);
        }
    }

    /**
     * Find the matching closing parenthesis for a given opening parenthesis, respecting nesting and quotes.
     *
     * @param string $sql The SQL string to search within.
     * @param int $startPos The position of the opening parenthesis.
     * @return int|false The position of the matching closing parenthesis, or false if not found.
     */
    protected function findMatchingParenthesis(string $sql, int $startPos): int|false
    {
        $depth = 0;
        $inQuote = false;
        $quoteChar = null;
        $escaped = false;

        for ($i = $startPos; $i < strlen($sql); $i++) {
            $char = $sql[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            // Handle quotes
            if (!$inQuote && ($char === '"' || $char === "'" || $char === '`')) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar) {
                // Check for doubled quotes
                if ($i + 1 < strlen($sql) && $sql[$i + 1] === $quoteChar) {
                    $i++; // Skip next quote
                    continue;
                }
                $inQuote = false;
                $quoteChar = null;
            }

            // Count parentheses when not in quotes
            if (!$inQuote) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                    if ($depth === 0) {
                        return $i;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Split a string of column or constraint definitions, respecting parentheses and quotes.
     *
     * @param string $definitions The string of definitions.
     * @return array An array of individual definition strings.
     */
    protected function splitDefinitions(string $definitions): array
    {
        return $this->splitStringByDelimiter($definitions, ',');
    }

    /**
     * Split ALTER TABLE operations respecting commas, parentheses, and quotes
     * 
     * Handles complex statements like:
     * ALTER TABLE `users` 
     *   ADD PRIMARY KEY (`id`),
     *   ADD UNIQUE KEY `email` (`email`),
     *   ADD KEY `status` (`status`);
     * 
     * @param string $operations The string of operations.
     * @return array An array of individual operation strings.
     */
    protected function splitAlterOperations(string $operations): array
    {
        return $this->splitStringByDelimiter($operations, ',');
    }


    /**
     * Split a string by a delimiter, respecting parentheses and quotes.
     *
     * @param string $input The string to split.
     * @param string $delimiter The delimiter to split by.
     * @return array An array of substrings.
     */
    protected function splitStringByDelimiter(string $input, string $delimiter = ','): array
    {
        $input = trim($input); // Trim outer whitespace early

        // Early return optimization: If delimiter isn't present, treat as single value
        if (strpos($input, $delimiter) === false) {
            // Still validate for unbalanced quotes/parentheses
            $this->validateBalanced($input);
            return [$input]; // Return as single-item array (trimmed already)
        }

        $values = [];
        $current = '';
        $i = 0;
        $length = strlen($input);
        $depth = 0; // Tracks parenthesis nesting
        $inQuote = false;
        $quoteChar = ''; // Current quote character (', ", or `)
        $escaped = false;

        while ($i < $length) {
            $char = $input[$i];

            if ($escaped) {
                $current .= $char; // Add escaped character
                $escaped = false;
                $i++;
                continue;
            }

            if ($char === '\\') {
                $escaped = true; // Next character is escaped
                $current .= $char;
                $i++;
                continue;
            }

            // Handle quotes
            if (!$inQuote && ($char === "'" || $char === '"' || $char === '`')) {
                $inQuote = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($inQuote && $char === $quoteChar) {
                // Check for doubled quotes (e.g., '' or "")
                if ($i + 1 < $length && $input[$i + 1] === $quoteChar) {
                    $current .= $char . $quoteChar; // Add both
                    $i += 2;
                    continue;
                }
                $inQuote = false;
                $quoteChar = '';
                $current .= $char;
            } elseif ($inQuote) {
                $current .= $char; // Inside quote, add normally
            } elseif ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new ParseException("Unbalanced closing parenthesis in input string.");
                }
                $current .= $char;
            } elseif ($char === $delimiter && $depth === 0 && !$inQuote) {
                // Top-level delimiter: end current value and start new one
                $values[] = trim($current);
                $current = '';
            } else {
                $current .= $char; // Default: add character
            }

            $i++;
        }

        // Add the last value
        if (trim($current) !== '') {
            $values[] = trim($current);
        }

        // Final checks
        if ($inQuote) {
            throw new ParseException("Unclosed quote in input string.");
        }
        if ($depth !== 0) {
            throw new ParseException("Unbalanced parentheses in input string.");
        }

        return $values;
    }

    /**
     * Helper to validate balanced quotes and parentheses in a string.
     * This is a simplified check for the early return case.
     *
     * @param string $input The string to validate.
     * @throws ParseException If unbalanced.
     */
    private function validateBalanced(string $input): void
    {
        $depth = 0;
        $inQuote = false;
        $quoteChar = '';
        $escaped = false;
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if (!$inQuote && ($char === "'" || $char === '"' || $char === '`')) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar) {
                // Check for doubled quotes
                if ($i + 1 < $length && $input[$i + 1] === $quoteChar) {
                    $i++; // Skip next
                    continue;
                }
                $inQuote = false;
                $quoteChar = '';
            } elseif (!$inQuote) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                    if ($depth < 0) {
                        throw new ParseException("Unbalanced closing parenthesis in input string.");
                    }
                }
            }
        }

        if ($inQuote) {
            throw new ParseException("Unclosed quote in input string.");
        }
        if ($depth !== 0) {
            throw new ParseException("Unbalanced parentheses in input string.");
        }
    }

    /**
     * Extract a table name from an ALTER TABLE statement.
     *
     * @param string $statement The ALTER TABLE statement.
     * @return string|null The extracted table name or null if not found.
     */
    protected function extractTableNameFromAlter(string $statement): ?string
    {
        if (preg_match('/ALTER\s+TABLE\s+(?:`([^`]+)`|"([^"]+)"|(\w+))/i', $statement, $matches)) {
            return $matches[3] ?? $matches[2] ?? $matches[1] ?? null;
        }
        return null;
    }

    /**
     * Remove quotes from an identifier.
     *
     * @param string $identifier The identifier to unquote.
     * @return string The unquoted identifier.
     */
    protected function unquoteIdentifier(string $identifier): string
    {
        return preg_replace(['/^`(.+)`$/', '/^"(.+)"$/', '/^\'(.)\'(?:\s|$)/'], '$1', $identifier);
    }

    /*
    |--------------------------------------------------------------------------
    | Utility Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get the database type constant for DatabaseSQLParser.
     *
     * @return string The database type.
     */
    protected function getDatabaseType(): string
    {
        return \DatabaseSQLParser::DB_GENERIC;
    }

    /**
     * Add a warning message to the parser's log.
     *
     * @param string $warning The warning message.
     * @return void
     */
    protected function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Get all warnings generated during parsing.
     *
     * @return string[] An array of warning messages.
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
