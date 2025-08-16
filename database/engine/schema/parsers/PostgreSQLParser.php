<?php

require_once __DIR__ . '/AbstractParser.php';

/**
 * PostgreSQL SQL Parser
 *
 * This class is responsible for parsing PostgreSQL CREATE TABLE, ALTER TABLE, and
 * CREATE INDEX statements into database-agnostic schema objects. It is designed
 * to handle PostgreSQL-specific syntax features such as schemas, dollar-quoted
 * strings, array types, generated columns, and various constraints.
 *
 * @package Database\Parsers
 * @author Enhanced Model System
 * @version 2.0.0
 */
class PostgreSQLParser extends AbstractParser
{
    protected $currentDollarTag = '';

    /*
    |--------------------------------------------------------------------------
    | Public API Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get the name of the parser.
     *
     * @return string The name of the parser, i.e., 'postgresql'.
     */
    public function getName(): string
    {
        return 'postgresql';
    }

    /**
     * Parse a string containing multiple SQL statements.
     *
     * This method overrides the parent implementation to provide more robust
     * handling of PostgreSQL-specific statement types and syntax.
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
            'preserve_comments' => $this->options['preserve_comments']
        ]);

        $statements = $parser->parseStatements($sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }

            // Ignore preliminary SET statements
            if (stripos($statement, 'SET ') === 0) {
                if ($this->options['debug']) {
                    error_log("Ignored SET statement: " . substr($statement, 0, 50));
                }
                continue;
            }
            // More robust CREATE TABLE detection
            if ($this->isCreateTableStatement($statement)) {
                try {
                    $table = $this->parseCreateTable($statement);
                    $tables[$table->getName()] = $table;
                    if ($this->options['debug']) {
                        error_log("Successfully parsed table: " . $table->getName());
                    }
                } catch (\Exception $e) {
                    if ($this->options['strict']) {
                        throw $e;
                    }
                    // Log error in non-strict mode
                    if ($this->options['debug']) {
                        error_log("Failed to parse CREATE TABLE: " . $e->getMessage());
                    }
                }
            }
            // Support for CREATE INDEX statements
            elseif ($this->isCreateIndexStatement($statement)) {
                try {
                    $index = $this->parseCreateIndex($statement);
                    // Assuming we can attach to table later; for now, log or store
                    if ($this->options['debug']) {
                        error_log("Parsed CREATE INDEX: " . $index->getName());
                    }
                    // TODO: Attach to relevant table in $tables
                } catch (\Exception $e) {
                    if ($this->options['debug']) {
                        error_log("Failed to parse CREATE INDEX: " . $e->getMessage());
                    }
                }
            }
            // Basic support for ALTER TABLE (e.g., adding constraints)
            elseif (stripos($statement, 'ALTER TABLE') === 0) {
                try {
                    $this->parseAlterTable($statement, $tables);
                    if ($this->options['debug']) {
                        error_log("Processed ALTER TABLE statement");
                    }
                } catch (\Exception $e) {
                    if ($this->options['debug']) {
                        error_log("Failed to parse ALTER TABLE: " . $e->getMessage());
                    }
                }
            }
            // TODO: Add handling for CREATE SEQUENCE, CREATE FUNCTION, etc.
        }

        return $tables;
    }

    /**
     * Parse a CREATE TABLE statement into a Table object.
     *
     * @param string $sql The CREATE TABLE statement.
     * @return Table A Table object representing the parsed statement.
     * @throws ParseException If the statement is invalid.
     */
    public function parseCreateTable(string $sql): Table
    {
        $originalSql = $sql;
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // Extract table name (may include schema, with or without quotes)
        if (!preg_match(
            '/CREATE\s+(?:TEMPORARY\s+|TEMP\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|(\w+))(?:\.(?:"([^"]+)"|(\w+)))?\s*\(/i',
            $sql,
            $matches
        )) {
            throw new ParseException("Could not parse table name from CREATE TABLE statement");
        }

        // Handle schema.table notation
        if (!empty($matches[3]) || !empty($matches[4])) {
            // Schema was specified
            $schema = $matches[1] ?: $matches[2];
            $tableName = $matches[3] ?: $matches[4];
        } else {
            // No schema
            $schema = null;
            $tableName = $matches[1] ?: $matches[2];
        }

        $table = new Table($tableName);
        $table->setOriginalDefinition($originalSql);

        if ($schema) {
            $table->setOption('schema', $schema);
        }

        // Find the column/constraint definitions
        $startPos = strpos($sql, '(');
        if ($startPos === false) {
            throw new ParseException("Invalid CREATE TABLE syntax - missing opening parenthesis");
        }

        $endPos = $this->findMatchingParenthesis($sql, $startPos);
        if ($endPos === false) {
            throw new ParseException("Invalid CREATE TABLE syntax - unmatched parentheses");
        }

        $definitions = substr($sql, $startPos + 1, $endPos - $startPos - 1);
        $this->parseTableDefinitions($table, $definitions);

        // Parse table options (INHERITS, WITH, etc.)
        if ($endPos + 1 < strlen($sql)) {
            $options = substr($sql, $endPos + 1);
            $this->parseTableOptions($table, $options);
        }

        return $table;
    }

    /**
     * Parse a standalone CREATE INDEX statement.
     *
     * @param string $sql The CREATE INDEX statement.
     * @return Index|null An Index object or null if parsing fails.
     */
    public function parseCreateIndex(string $sql): ?Index
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // Match CREATE [UNIQUE] INDEX name ON table [USING method] (columns) [WHERE condition]
        if (!preg_match(
            '/CREATE\s+(UNIQUE\s+)?INDEX\s+(?:"([^"]+)"|(\w+))\s+ON\s+(?:"([^"]+)"|(\w+))(?:\s+USING\s+(\w+))?\s*\(([^)]+)\)(?:\s+WHERE\s+(.+))?/i',
            $sql,
            $matches
        )) {
            return null;
        }

        $isUnique = !empty($matches[1]);
        $indexName = $matches[2] ?: $matches[3];
        $tableName = $matches[4] ?: $matches[5];
        $method = $matches[6] ?? null;
        $columnList = $matches[7];
        $whereClause = $matches[8] ?? null;

        $index = new Index($indexName, $isUnique ? Index::TYPE_UNIQUE : Index::TYPE_INDEX);
        $index->setOption('table', $tableName); // Store the table name for later association.

        if ($method) {
            $index->setMethod($method);
        }

        if ($whereClause) {
            $index->setWhere(trim($whereClause));
        }

        $this->parseIndexColumns($index, $columnList);

        return $index;
    }

    /**
     * Parse a column definition string into a Column object.
     *
     * @param Table $table The table the column belongs to.
     * @param string $definition The column definition string.
     * @return Column A Column object.
     * @throws ParseException If the column definition cannot be parsed.
     */
    public function parseColumnDefinition(Table $table, string $definition): Column
    {
        // Extract column name (handles quoted identifiers)
        if (!preg_match('/^(?:"([^"]+)"|(\w+))\s+(.*)$/i', $definition, $matches)) {
            throw new ParseException("Could not parse column definition: $definition");
        }

        $columnName = $matches[1] ?? $matches[2];
        $remainder = $matches[3];

        $column = new Column($columnName, ''); // type will be set later

        $typeInfo = $this->parseDataType($remainder);
        $column->setType(strtolower($typeInfo['type']));
        if (isset($typeInfo['is_array']) && $typeInfo['is_array']) {
            $column->setCustomOption('is_array', true);
        }
        $remainder = $typeInfo['remainder'];

        // Parse remaining modifiers like NOT NULL, DEFAULT, etc. 
        $this->parseColumnModifiers($column, $remainder, $table);

        // Detect auto-increment via nextval in default
        $default = $column->getDefault();
        if (is_string($default) && preg_match('/nextval\(\'(.+?)\'::regclass\)/i', $default)) {
            $column->setAutoIncrement(true);
        }

        $table->addColumn($column);
        return $column;
    }

    /**
     * Parse an index definition string (from within CREATE TABLE) into an Index object.
     *
     * @param Table $table The table the index belongs to.
     * @param string $definition The index definition string.
     * @return Index An Index object.
     * @throws ParseException If the index definition is not a PRIMARY KEY.
     */
    public function parseIndexDefinition(Table $table, string $definition): Index
    {
        // PRIMARY KEY
        if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]+)\)/i', $definition, $matches)) {
            $index = new Index('PRIMARY', Index::TYPE_PRIMARY);
            $this->parseIndexColumns($index, $matches[1]);
            $table->addIndex($index);
            return $index;
        }

        // Note: Other index types in PostgreSQL are created with CREATE INDEX
        // not inline in CREATE TABLE

        throw new ParseException("Could not parse index definition: $definition");
    }

    /**
     * Parse a constraint definition string into a Constraint object.
     *
     * @param Table $table The table the constraint belongs to.
     * @param string $definition The constraint definition string.
     * @return Constraint A Constraint object.
     * @throws ParseException If the constraint definition cannot be parsed.
     */
    public function parseConstraintDefinition(Table $table, string $definition): Constraint
    {
        // CONSTRAINT name ...
        if (preg_match('/^CONSTRAINT\s+(?:"([^"]+)"|(\w+))\s+(.+)$/i', $definition, $matches)) {
            $name = $matches[1] ?: $matches[2];
            return $this->parseNamedConstraint($table, $name, $matches[3]);
        }

        // UNIQUE columns
        if (preg_match('/^UNIQUE\s*\(([^)]+)\)/i', $definition, $matches)) {
            $constraint = new Constraint(
                $this->generateConstraintName($table, 'uk'),
                Constraint::TYPE_UNIQUE
            );
            $constraint->setColumns($this->parseColumnList($matches[1]));
            $table->addConstraint($constraint);
            return $constraint;
        }

        // CHECK constraint
        if (preg_match('/^CHECK\s*\((.+)\)$/i', $definition, $matches)) {
            // CHECK (expression) - with parentheses
            $constraint = new Constraint(
                $this->generateConstraintName($table, 'chk'),
                Constraint::TYPE_CHECK
            );
            $constraint->setExpression($matches[1]);
            $table->addConstraint($constraint);
            return $constraint;
        } elseif (preg_match('/^CHECK\s+(.+)$/i', $definition, $matches)) {
            // CHECK expression - without parentheses
            $constraint = new Constraint(
                $this->generateConstraintName($table, 'chk'),
                Constraint::TYPE_CHECK
            );
            $constraint->setExpression($matches[1]);
            $table->addConstraint($constraint);
            return $constraint;
        }

        // FOREIGN KEY
        if (preg_match(
            '/^FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+(?:"([^"]+)"|(\w+))(?:\.(?:"([^"]+)"|(\w+)))?\s*\(([^)]+)\)(.*)$/i',
            $definition,
            $matches
        )) {

            $localColumns = $this->parseColumnList($matches[1]);
            $foreignSchema = !empty($matches[4]) || !empty($matches[5]) ? ($matches[2] ?: $matches[3]) : null;
            $foreignTable = !empty($matches[4]) || !empty($matches[5]) ? ($matches[4] ?: $matches[5]) : ($matches[2] ?: $matches[3]);
            $foreignColumns = $this->parseColumnList($matches[6]);
            $actions = $matches[7];

            $constraint = new Constraint(
                $this->generateConstraintName($table, 'fk'),
                Constraint::TYPE_FOREIGN_KEY
            );

            $constraint->setColumns($localColumns);
            $constraint->setReferencedTable($foreignTable);
            $constraint->setReferencedColumns($foreignColumns);

            if ($foreignSchema) {
                $constraint->setOption('foreign_schema', $foreignSchema);
            }

            $this->parseForeignKeyActions($constraint, $actions);

            $table->addConstraint($constraint);
            return $constraint;
        }

        throw new ParseException("Could not parse constraint definition: $definition");
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
        $normalized = preg_replace('/\s+/', ' ', trim($sql));
        return preg_match('/^CREATE\s+(TEMPORARY\s+|TEMP\s+|UNLOGGED\s+)?TABLE/i', $normalized) === 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Core Parsing Logic
    |--------------------------------------------------------------------------
    */

    /**
     * Process a single ALTER TABLE operation for PostgreSQL.
     * 
     * This method implements the abstract processAlterOperation from AbstractParser
     * and handles PostgreSQL-specific ALTER TABLE syntax.
     *
     * @param Table $table The table object being altered.
     * @param string $operation The operation SQL string (e.g., 'ADD CONSTRAINT ...').
     * @return void
     */
    protected function processAlterOperation(Table $table, string $operation): void
    {
        $operation = trim($operation);

        // ADD CONSTRAINT (existing functionality - maintains backward compatibility)
        if (preg_match('/^ADD\s+(?:CONSTRAINT\s+(?:"([^"]+)"|(\w+))\s+)?(.+)$/i', $operation, $matches)) {
            $constraintName = $matches[1] ?: $matches[2] ?: null;
            $constraintDefinition = $matches[3];

            try {
                // If constraint name is provided, prepend it to the definition
                if ($constraintName) {
                    $fullDefinition = "CONSTRAINT \"$constraintName\" $constraintDefinition";
                } else {
                    $fullDefinition = $constraintDefinition;
                }

                $this->parseConstraintDefinition($table, $fullDefinition);
            } catch (\Exception $e) {
                if ($this->options['strict']) {
                    throw $e;
                }
                $this->addWarning("Failed to parse ADD CONSTRAINT: " . $e->getMessage());
            }
            return;
        }

        // ADD COLUMN
        if (preg_match('/^ADD\s+(?:COLUMN\s+)?(.+)$/i', $operation, $matches)) {
            try {
                $this->parseColumnDefinition($table, $matches[1]);
            } catch (\Exception $e) {
                if ($this->options['strict']) {
                    throw $e;
                }
                $this->addWarning("Could not parse ADD COLUMN operation: " . $e->getMessage());
            }
            return;
        }

        // ALTER COLUMN (PostgreSQL-specific syntax)
        if (preg_match('/^ALTER\s+(?:COLUMN\s+)?(?:"([^"]+)"|(\w+))\s+(.+)$/i', $operation, $matches)) {
            $columnName = $matches[1] ?: $matches[2];
            $alterSpec = $matches[3];

            if ($table->hasColumn($columnName)) {
                $this->processAlterColumn($table->getColumn($columnName), $alterSpec);
            } else {
                $this->addWarning("ALTER COLUMN references unknown column '$columnName' in table '{$table->getName()}'");
            }
            return;
        }

        // DROP COLUMN
        if (preg_match('/^DROP\s+(?:COLUMN\s+)?(?:"([^"]+)"|(\w+))(?:\s+(CASCADE|RESTRICT))?$/i', $operation, $matches)) {
            $columnName = $matches[1] ?: $matches[2];
            $dropBehavior = isset($matches[3]) ? strtoupper($matches[3]) : null;

            if ($table->hasColumn($columnName)) {
                $table->removeColumn($columnName);
            } else {
                $this->addWarning("DROP COLUMN references unknown column '$columnName' in table '{$table->getName()}'");
            }
            return;
        }

        // DROP
        if (preg_match('/^DROP\s+(?:COLUMN|CONSTRAINT)\s+(?:"([^"]+)"|(\w+))/i', $operation, $matches)) {
            $name = $matches[1] ?: $matches[2];
            if (stripos($operation, 'COLUMN') !== false) {
                $table->removeColumn($name);
            } else {
                $table->removeConstraint($name);
            }
            return;
        }

        // RENAME TO
        if (preg_match('/^RENAME\s+TO\s+(?:"([^"]+)"|(\w+))$/i', $operation, $matches)) {
            $this->addWarning("Table RENAME TO operations are not fully supported during parsing.");
            return;
        }

        // OWNER TO
        if (preg_match('/^OWNER\s+TO\s+(.+)$/i', $operation, $matches)) {
            $table->setOption('owner', trim($matches[1]));
            return;
        }

        // SET TABLESPACE (PostgreSQL-specific)
        if (preg_match('/^SET\s+TABLESPACE\s+(.+)$/i', $operation, $matches)) {
            $tablespace = trim($matches[1]);
            $table->setOption('tablespace', $tablespace);

            if ($this->options['debug']) {
                error_log("PostgreSQLParser: Set tablespace to '$tablespace' for table '{$table->getName()}'");
            }
            return;
        }

        // Other unsupported operations
        if (preg_match('/^(ENABLE|DISABLE|VALIDATE|CLUSTER|SET|RESET)\s+/i', $operation, $matches)) {
            $operationType = strtoupper($matches[1]);
            if ($this->options['debug']) {
                error_log("PostgreSQLParser: Skipping unsupported ALTER operation: $operationType");
            }
            $this->addWarning("Unsupported ALTER TABLE operation: $operationType");
            return;
        }

        // Unknown operation
        $this->addWarning("Unknown PostgreSQL ALTER TABLE operation: " . substr($operation, 0, 50) . "...");
    }

    /**
     * Process specific ALTER COLUMN actions (e.g., SET DEFAULT, TYPE).
     *
     * @param Column $column The column to alter.
     * @param string $alterSpec The ALTER specification string.
     * @return void
     */
    private function processAlterColumn(Column $column, string $alterSpec): void
    {
        if (preg_match('/^SET\s+DEFAULT\s+(.+)$/i', $alterSpec, $matches)) {
            $this->parseDefaultValue($column, trim($matches[1]));
        } elseif (preg_match('/^DROP\s+DEFAULT$/i', $alterSpec)) {
            $column->setDefault(null);
        } elseif (preg_match('/^SET\s+NOT\s+NULL$/i', $alterSpec)) {
            $column->setNullable(false);
        } elseif (preg_match('/^DROP\s+NOT\s+NULL$/i', $alterSpec)) {
            $column->setNullable(true);
        } elseif (preg_match('/^TYPE\s+(.+?)(?:\s+USING\s+.+)?$/i', $alterSpec, $matches)) {
            $typeInfo = $this->parseDataType(trim($matches[1]));
            if (isset($typeInfo['type'])) $column->setType($typeInfo['type']);
            if (isset($typeInfo['length'])) $column->setLength($typeInfo['length']);
            if (isset($typeInfo['precision'])) $column->setPrecision($typeInfo['precision']);
            if (isset($typeInfo['scale'])) $column->setScale($typeInfo['scale']);
        } else {
            $this->addWarning("Unknown ALTER COLUMN operation: " . substr($alterSpec, 0, 50) . "...");
        }
    }

    /**
     * Parse table definitions
     */
    protected function parseTableDefinitions(Table $table, string $definitions): void
    {
        $parts = $this->splitDefinitions($definitions);

        foreach ($parts as $part) {
            $part = trim($part);

            if (empty($part)) continue;

            if ($this->isConstraintDefinition($part)) {
                $this->parseConstraintDefinition($table, $part);
            } elseif ($this->isIndexDefinition($part)) {
                $this->parseIndexDefinition($table, $part);
            } else {
                $this->parseColumnDefinition($table, $part);
            }
        }
    }

    /**
     * Parse modifiers for a column, such as NOT NULL, DEFAULT, etc.
     *
     * @param Column $column The column object to update.
     * @param string $modifiers The string of modifiers.
     * @param Table $table The table the column belongs to.
     * @return void
     */
    protected function parseColumnModifiers(Column $column, string $modifiers, Table $table): void
    {
        // NOT NULL / NULL
        if (preg_match('/\sNOT\s+NULL\s/i', $modifiers)) {
            $column->setNullable(false);
        } elseif (preg_match('/\sNULL\s/i', $modifiers)) {
            $column->setNullable(true);
        }

        // PRIMARY KEY
        if (preg_match('/\sPRIMARY\s+KEY\s/i', $modifiers)) {
            $column->setCustomOption('primary_key', true);
        }

        // UNIQUE
        if (preg_match('/\sUNIQUE\s/i', $modifiers)) {
            $column->setCustomOption('unique', true);
        }

        // DEFAULT value
        if (preg_match(
            '/\sDEFAULT\s+(.+?)(?:\s+(?:NOT|NULL|PRIMARY|UNIQUE|CHECK|REFERENCES)|$)/i',
            $modifiers,
            $matches
        )) {
            $this->parseDefaultValue($column, trim($matches[1]));
        }

        // REFERENCES (inline foreign key)
        if (preg_match(
            '/\sREFERENCES\s+(?:"([^"]+)"|(\w+))(?:\s*\((?:"([^"]+)"|(\w+))\))?/i',
            $modifiers,
            $matches
        )) {
            $column->setCustomOption('references_table', $matches[1] ?: $matches[2]);
            if (!empty($matches[3]) || !empty($matches[4])) {
                $column->setCustomOption('references_column', $matches[3] ?: $matches[4]);
            }
        }

        // CHECK constraint
        if (preg_match('/\sCHECK\s*\(([^)]+)\)/i', $modifiers, $matches)) {
            $column->setCustomOption('check_constraint', $matches[1]);
        }
    }

    /**
     * Parse a named constraint definition.
     *
     * @param Table $table The table the constraint belongs to.
     * @param string $name The name of the constraint.
     * @param string $definition The constraint definition string.
     * @return Constraint A Constraint object.
     * @throws ParseException If the named constraint cannot be parsed.
     */
    protected function parseNamedConstraint(Table $table, string $name, string $definition): Constraint
    {
        if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]+)\)/i', $definition, $matches)) {
            $constraint = new Constraint($name, Constraint::TYPE_PRIMARY_KEY);
            $constraint->setColumns($this->parseColumnList($matches[1]));
        } elseif (preg_match('/^UNIQUE\s*\(([^)]+)\)/i', $definition, $matches)) {
            $constraint = new Constraint($name, Constraint::TYPE_UNIQUE);
            $constraint->setColumns($this->parseColumnList($matches[1]));
        } elseif (preg_match('/^CHECK\s*\((.+)\)$/i', $definition, $matches)) {
            $constraint = new Constraint($name, Constraint::TYPE_CHECK);
            $constraint->setExpression($matches[1]);
        } elseif (preg_match('/^FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+(?:"([^"]+)"|(\w+))(?:\.(?:"([^"]+)"|(\w+)))?\s*\(([^)]+)\)(.*)$/i', $definition, $matches)) {
            $constraint = new Constraint($name, Constraint::TYPE_FOREIGN_KEY);
            $constraint->setColumns($this->parseColumnList($matches[1]));
            $foreignSchema = !empty($matches[4]) || !empty($matches[5]) ? ($matches[2] ?: $matches[3]) : null;
            $foreignTable = !empty($matches[4]) || !empty($matches[5]) ? ($matches[4] ?: $matches[5]) : ($matches[2] ?: $matches[3]);
            $constraint->setReferencedTable($foreignTable);
            if ($foreignSchema) {
                $constraint->setOption('foreign_schema', $foreignSchema);
            }
            $constraint->setReferencedColumns($this->parseColumnList($matches[6]));
            if (!empty($matches[7])) {
                $this->parseForeignKeyActions($constraint, $matches[7]);
            }
        } elseif (preg_match('/^EXCLUDE\s+/i', $definition)) {
            $constraint = new Constraint($name, Constraint::TYPE_CHECK);
            $constraint->setExpression($definition);
            $constraint->setOption('is_exclude', true);
        } else {
            throw new ParseException("Could not parse named constraint: $definition");
        }

        $table->addConstraint($constraint);
        return $constraint;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper & Utility Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get the database type constant for DatabaseSQLParser.
     *
     * @return string The database type.
     */
    protected function getDatabaseType(): string
    {
        return \DatabaseSQLParser::DB_POSTGRESQL;
    }

    /**
     * Split a string of definitions, respecting parentheses, quotes, and dollar-quoted strings.
     *
     * @param string $definitions The string of definitions.
     * @return array An array of individual definition strings.
     */
    protected function splitDefinitions(string $definitions): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inQuote = false;
        $quoteChar = null;
        $escaped = false;
        $inDollarQuote = false;
        $dollarTag = '';
        $length = strlen($definitions);
        for ($i = 0; $i < $length; $i++) {
            $char = $definitions[$i];
            $prevChar = $i > 0 ? $definitions[$i - 1] : '';
            $nextChar = $i + 1 < $length ? $definitions[$i + 1] : '';

            // Handle escape sequences
            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                $current .= $char;
                continue;
            }

            // Handle standard quotes
            if (!$inQuote && !$inDollarQuote && ($char === '"' || $char === "'" || $char === '`')) {
                $inQuote = true;
                $quoteChar = $char;
                $current .= $char;
                continue;
            }
            if ($inQuote && $char === $quoteChar) {
                // Check for doubled quotes
                if ($nextChar === $quoteChar) {
                    $current .= $char . $char;
                    $i++;
                    continue;
                }
                $inQuote = false;
                $quoteChar = null;
                $current .= $char;
                continue;
            }

            // Handle dollar quotes - ADDED
            if (!$inQuote && !$inDollarQuote && $char === '$') {
                // Extract tag
                $tagStart = $i;
                $tag = '$';
                $i++;
                while ($i < $length && $definitions[$i] !== '$') {
                    $tag .= $definitions[$i];
                    $i++;
                }
                if ($i < $length && $definitions[$i] === '$') {
                    $tag .= '$';
                    $inDollarQuote = true;
                    $dollarTag = $tag;
                    $current .= $tag;
                    continue;
                } else {
                    // Not a valid tag, reset i
                    $i = $tagStart;
                    $current .= $char;
                    continue;
                }
            }
            if ($inDollarQuote && substr($definitions, $i, strlen($dollarTag)) === $dollarTag) {
                $current .= $dollarTag;
                $i += strlen($dollarTag) - 1;
                $inDollarQuote = false;
                $dollarTag = '';
                continue;
            }

            // Track parentheses depth when not in quotes
            if (!$inQuote && !$inDollarQuote) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
            }

            // Split on comma only at depth 0 and not in quotes
            if ($char === ',' && $depth === 0 && !$inQuote && !$inDollarQuote) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }
        // Add last part
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }
        return $parts;
    }

    /**
     * Find the matching closing parenthesis, accounting for nesting, quotes, and dollar-quoted strings.
     *
     * @param string $sql The SQL string to search within.
     * @param int $startPos The position of the opening parenthesis.
     * @return int|false The position of the matching closing parenthesis, or false if not found.
     */
    protected function findMatchingParenthesis(string $sql, int $startPos): int
    {
        $depth = 0;
        $inQuote = false;
        $quoteChar = null;
        $escaped = false;
        $inDollarQuote = false;
        $dollarTag = '';
        for ($i = $startPos; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            $nextChar = $i + 1 < strlen($sql) ? $sql[$i + 1] : '';

            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            // Handle quotes
            if (!$inQuote && !$inDollarQuote && ($char === '"' || $char === "'" || $char === '`')) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar) {
                if ($nextChar === $quoteChar) {
                    $i++; // Skip next quote
                    continue;
                }
                $inQuote = false;
                $quoteChar = null;
            }

            // Handle dollar quotes - ADDED
            if (!$inQuote && !$inDollarQuote && $char === '$') {
                // Extract tag
                $tagStart = $i;
                $tag = '$';
                $i++;
                while ($i < strlen($sql) && $sql[$i] !== '$') {
                    $tag .= $sql[$i];
                    $i++;
                }
                if ($i < strlen($sql) && $sql[$i] === '$') {
                    $tag .= '$';
                    $inDollarQuote = true;
                    $dollarTag = $tag;
                    continue;
                } else {
                    $i = $tagStart;
                    continue;
                }
            }
            if ($inDollarQuote && substr($sql, $i, strlen($dollarTag)) === $dollarTag) {
                $i += strlen($dollarTag) - 1;
                $inDollarQuote = false;
                $dollarTag = '';
                continue;
            }

            // Count parentheses when not in quotes
            if (!$inQuote && !$inDollarQuote) {
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
     * Parse a default value string and set it on the column.
     *
     * @param Column $column The column to set the default value on.
     * @param string $default The default value string.
     * @return void
     */
    protected function parseDefaultValue(Column $column, string $default): void
    {
        $default = trim($default);
        if (strcasecmp($default, 'NULL') === 0) {
            $column->setDefault(null);
        } elseif (preg_match('/^\'(.*)\'$/', $default, $matches)) {
            $column->setDefault($matches[1]);
        } elseif (is_numeric($default)) {
            $column->setDefault($default);
        } elseif (in_array(strtoupper($default), ['TRUE', 'FALSE'])) {
            $column->setDefault(strtoupper($default) === 'TRUE');
        } else {
            // Assumes function call or other expression
            $column->setDefault($default);
        }
    }

    /**
     * Parse table options such as INHERITS, WITH, and TABLESPACE.
     *
     * @param Table $table The table object to update.
     * @param string $options The string of table options.
     * @return void
     */
    protected function parseTableOptions(Table $table, string $options): void
    {
        if (preg_match('/INHERITS\s*\(([^)]+)\)/i', $options, $matches)) {
            $table->setOption('inherits', $this->parseTableList($matches[1]));
        }
        if (preg_match('/WITH\s*\(([^)]+)\)/i', $options, $matches)) {
            $table->setOption('with_options', $matches[1]);
        }
        if (preg_match('/TABLESPACE\s+(\w+)/i', $options, $matches)) {
            $table->setOption('tablespace', $matches[1]);
        }
    }

    /**
     * Parse a comma-separated list of table names.
     *
     * @param string $list The string containing the list of tables.
     * @return array An array of table names.
     */
    protected function parseTableList(string $list): array
    {
        $tables = [];
        $parts = explode(',', $list);

        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^(?:"([^"]+)"|(\w+))$/', $part, $matches)) {
                $tables[] = $matches[1] ?: $matches[2];
            }
        }

        return $tables;
    }

    /**
     * Parse a comma-separated list of column names.
     *
     * @param string $list The string containing the list of columns.
     * @return array An array of column names.
     */
    protected function parseColumnList(string $list): array
    {
        $columns = [];
        $parts = $this->splitDefinitions($list);

        foreach ($parts as $part) {
            $column = $this->unquoteIdentifier(trim($part));
            if (!empty($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Parse the columns for an index definition.
     *
     * @param Index $index The index object to update.
     * @param string $columnList The string of column definitions.
     * @return void
     */
    protected function parseIndexColumns(Index $index, string $columnList): void
    {
        $columns = $this->splitDefinitions($columnList);

        foreach ($columns as $col) {
            $col = trim($col);

            $col = $this->unquoteIdentifier($col);

            if (preg_match('/^(\w+)\s+(ASC|DESC)/i', $col, $matches)) {
                $index->addColumn($matches[1], null, strtoupper($matches[2]));
            } else {
                $index->addColumn($col);
            }
        }
    }

    /**
     * Parse foreign key actions such as ON DELETE and ON UPDATE.
     *
     * @param Constraint $constraint The constraint object to update.
     * @param string $actions The string of foreign key actions.
     * @return void
     */
    protected function parseForeignKeyActions(Constraint $constraint, string $actions): void
    {
        // ON DELETE
        if (preg_match(
            '/ON\s+DELETE\s+(CASCADE|RESTRICT|SET\s+NULL|SET\s+DEFAULT|NO\s+ACTION)/i',
            $actions,
            $matches
        )) {
            $action = str_replace(' ', '_', strtoupper($matches[1]));
            $constraint->setOnDelete($action);
        }

        // ON UPDATE
        if (preg_match(
            '/ON\s+UPDATE\s+(CASCADE|RESTRICT|SET\s+NULL|SET\s+DEFAULT|NO\s+ACTION)/i',
            $actions,
            $matches
        )) {
            $action = str_replace(' ', '_', strtoupper($matches[1]));
            $constraint->setOnUpdate($action);
        }

        // MATCH type
        if (preg_match('/MATCH\s+(FULL|PARTIAL|SIMPLE)/i', $actions, $matches)) {
            $constraint->setOption('match_type', strtoupper($matches[1]));
        }

        // DEFERRABLE
        if (preg_match('/DEFERRABLE/i', $actions)) {
            $constraint->setOption('deferrable', true);

            if (preg_match('/INITIALLY\s+(DEFERRED|IMMEDIATE)/i', $actions, $matches)) {
                $constraint->setOption('initially', strtoupper($matches[1]));
            }
        }
    }

    /**
     * Parses a column's data type from its definition string.
     *
     * This method is responsible for interpreting the data type portion of a column
     * definition in a PostgreSQL CREATE TABLE statement. It handles various
     * PostgreSQL-specific syntax, including basic types (e.g., INTEGER, VARCHAR),
     * types with length/precision (e.g., VARCHAR(255), NUMERIC(10,2)), array
     * types (e.g., TEXT[]), and generated columns.
     *
     * @param string $definition The remaining part of the column definition after the column name.
     * @return array An associative array containing the parsed type information.
     *               The array may include keys such as 'type', 'length', 'precision',
     *               'scale', 'is_array', 'generated', and 'remainder' (the part of
     *               the definition string left after parsing the type).
     * @throws ParseException If the data type cannot be determined from the definition.
     */
    protected function parseDataType(string $definition): array
    {
        $result = [];
        // Generated columns (PostgreSQL 12+)
        if (preg_match('/^(.+)\s+GENERATED\s+ALWAYS\s+AS\s+\((.+)\)\s+STORED(.*)$/i', $definition, $matches)) {
            $result['type'] = $this->parseDataType($matches[1])['type']; // Recursive for base type
            $result['generated'] = $matches[2];
            $result['remainder'] = $matches[3];
            return $result;
        }
        // Array types (e.g., TEXT[])
        if (preg_match('/^(\w+)\s*\[\](.*)$/i', $definition, $matches)) {
            $result['type'] = $matches[1];
            $result['is_array'] = true;
            $result['remainder'] = $matches[2];
            return $result;
        }
        // Type with precision/scale (e.g., NUMERIC(10,2))
        if (preg_match('/^(\w+)\s*\((\d+)\s*,\s*(\d+)\)(.*)$/i', $definition, $matches)) {
            $result['type'] = $matches[1];
            $result['precision'] = (int)$matches[2];
            $result['scale'] = (int)$matches[3];
            $result['remainder'] = $matches[4];
            return $result;
        }
        // Type with length (e.g., VARCHAR(255))
        if (preg_match('/^(\w+)\s*\((\d+)\)(.*)$/i', $definition, $matches)) {
            $result['type'] = $matches[1];
            $result['length'] = (int)$matches[2];
            $result['remainder'] = $matches[3];
            return $result;
        }
        // Type with timezone (TIMESTAMP WITH TIME ZONE)
        if (preg_match('/^TIMESTAMP\s+WITH(?:OUT)?\s+TIME\s+ZONE(.*)$/i', $definition, $matches)) {
            $result['type'] = 'timestamp';
            $result['with_timezone'] = !preg_match('/WITHOUT/i', $definition);
            $result['remainder'] = $matches[1];
            return $result;
        }
        // Simple or user-defined type
        if (preg_match('/^("?[a-zA-Z0-9_]+"?)(.*)$/i', $definition, $matches)) {
            $result['type'] = $this->unquoteIdentifier($matches[1]);
            $result['remainder'] = $matches[2];
            return $result;
        }
        throw new ParseException("Could not parse data type from: $definition");
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT Statement Parsing (NEW - PostgreSQL Implementation)
    |--------------------------------------------------------------------------
    */

    /**
     * Parse INSERT data from PostgreSQL INSERT statement
     * 
     * Handles PostgreSQL-specific INSERT syntax including:
     * - INSERT INTO table VALUES (...)
     * - INSERT INTO table (columns) VALUES (...)
     * - Multi-row INSERT: VALUES (...), (...), (...)
     * - INSERT INTO table VALUES (...) ON CONFLICT ...
     * - Dollar-quoted strings: $$string$$
     * - Array literals: ARRAY[...] or '{...}'
     * - Type casts: 'value'::type
     *
     * @param string $statement The INSERT statement
     * @return array Array of row data (each row is associative array)
     * @throws ParseException If the INSERT statement cannot be parsed
     */
    protected function parseInsertData(string $statement): array
    {
        $statement = trim($statement);
        $rows = [];

        // Handle standard INSERT ... VALUES syntax
        // Remove ON CONFLICT clause if present for parsing
        $cleanStatement = preg_replace('/\s+ON\s+CONFLICT\s+.*$/is', '', $statement);

        if (preg_match('/INSERT\s+INTO\s+(?:"([^"]+)"|(\w+))(?:\.(?:"([^"]+)"|(\w+)))?(?:\s*\(([^)]+)\))?\s+VALUES\s+(.+)$/is', $cleanStatement, $matches)) {
            // Handle schema.table notation
            $schema = null;
            $tableName = null;

            if (!empty($matches[3]) || !empty($matches[4])) {
                // Schema was specified
                $schema = $matches[1] ?: $matches[2];
                $tableName = $matches[3] ?: $matches[4];
            } else {
                // No schema
                $tableName = $matches[1] ?: $matches[2];
            }

            $columnList = isset($matches[5]) ? $matches[5] : null;
            $valuesClause = $matches[6];

            // Parse column names if specified
            $columns = [];
            if ($columnList) {
                $columns = $this->parseColumnNamesFromInsert($columnList);
            }

            // Parse VALUES clause
            $rows = $this->parseValuesClause($valuesClause, $columns);

            if ($this->options['debug']) {
                $fullTableName = $schema ? "$schema.$tableName" : $tableName;
                error_log("PostgreSQLParser: Parsed INSERT for table '$fullTableName' - " . count($rows) . " rows");
            }

            return $rows;
        }

        // If we can't parse it, log and return empty
        if ($this->options['debug']) {
            error_log("PostgreSQLParser: Could not parse INSERT statement: " . substr($statement, 0, 100));
        }

        return [];
    }

    /**
     * Parse column names from INSERT statement
     *
     * @param string $columnList Comma-separated column list
     * @return array Array of column names
     */
    private function parseColumnNamesFromInsert(string $columnList): array
    {
        $columns = [];
        $parts = $this->splitStringByDelimiter($columnList, ',');

        foreach ($parts as $part) {
            $part = trim($part);
            $column = $this->unquoteIdentifier($part);
            if (!empty($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Parse VALUES clause with multiple rows (PostgreSQL version)
     *
     * @param string $valuesClause The VALUES clause content
     * @param array $columns Column names (if specified in INSERT)
     * @return array Array of row data
     */
    private function parseValuesClause(string $valuesClause, array $columns = []): array
    {
        $rows = [];

        // Split multiple value sets: (...), (...), (...)
        $valueSets = $this->parseValueSets($valuesClause);

        foreach ($valueSets as $valueSet) {
            $values = $this->parseValueList($valueSet);

            // Create associative array if column names provided
            if (!empty($columns)) {
                $row = [];
                for ($i = 0; $i < count($values) && $i < count($columns); $i++) {
                    $row[$columns[$i]] = $values[$i];
                }
                $rows[] = $row;
            } else {
                // If no column names, use numeric indices (will need column inference)
                $rows[] = array_combine(range(0, count($values) - 1), $values);
            }
        }

        return $rows;
    }

    /**
     * Parse value sets from VALUES clause (PostgreSQL version)
     * 
     * Handles: (...), (...), (...) with proper nesting, quotes, and dollar-quotes
     *
     * @param string $valuesClause The VALUES clause
     * @return array Array of value set strings
     */
    private function parseValueSets(string $valuesClause): array
    {
        $valueSets = [];
        $i = 0;
        $len = strlen($valuesClause);

        while ($i < $len) {
            // Skip whitespace and commas
            while ($i < $len && (ctype_space($valuesClause[$i]) || $valuesClause[$i] === ',')) {
                $i++;
            }

            if ($i >= $len) break;

            // Look for opening parenthesis
            if ($valuesClause[$i] === '(') {
                $start = $i;
                $end = $this->findMatchingParenthesisWithDollarQuotes($valuesClause, $i);

                if ($end !== false) {
                    // Extract content between parentheses
                    $valueSetContent = substr($valuesClause, $start + 1, $end - $start - 1);
                    $valueSets[] = $valueSetContent;
                    $i = $end + 1;
                } else {
                    // Unmatched parenthesis - skip character
                    $i++;
                }
            } else {
                // Skip unexpected character
                $i++;
            }
        }

        return $valueSets;
    }

    /**
     * Find matching parenthesis accounting for dollar-quoted strings (PostgreSQL specific)
     *
     * @param string $sql The SQL string
     * @param int $startPos Position of opening parenthesis
     * @return int|false Position of matching closing parenthesis
     */
    private function findMatchingParenthesisWithDollarQuotes(string $sql, int $startPos): int|false
    {
        $depth = 0;
        $inQuote = false;
        $quoteChar = null;
        $escaped = false;
        $inDollarQuote = false;
        $dollarTag = '';

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

            // Handle standard quotes
            if (!$inQuote && !$inDollarQuote && ($char === '"' || $char === "'" || $char === '`')) {
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

            // Handle dollar quotes (PostgreSQL specific)
            if (!$inQuote && !$inDollarQuote && $char === '$') {
                // Try to parse dollar tag
                $tagStart = $i;
                $tag = '$';
                $i++;
                while ($i < strlen($sql) && $sql[$i] !== '$') {
                    $tag .= $sql[$i];
                    $i++;
                }
                if ($i < strlen($sql) && $sql[$i] === '$') {
                    $tag .= '$';
                    $inDollarQuote = true;
                    $dollarTag = $tag;
                    continue;
                } else {
                    // Not a valid tag, reset
                    $i = $tagStart;
                }
            }

            if ($inDollarQuote && substr($sql, $i, strlen($dollarTag)) === $dollarTag) {
                $i += strlen($dollarTag) - 1;
                $inDollarQuote = false;
                $dollarTag = '';
                continue;
            }

            // Count parentheses when not in quotes
            if (!$inQuote && !$inDollarQuote) {
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
     * Parse individual value list from a value set (PostgreSQL version)
     *
     * @param string $valueSet Comma-separated values
     * @return array Array of parsed values
     */
    private function parseValueList(string $valueSet): array
    {
        $values = [];
        $parts = $this->splitStringByDelimiterWithDollarQuotes($valueSet, ',');

        foreach ($parts as $part) {
            $value = $this->parsePostgreSQLValueLiteral(trim($part));
            $values[] = $value;
        }

        return $values;
    }

    /**
     * Split string by delimiter accounting for dollar-quoted strings
     *
     * @param string $input String to split
     * @param string $delimiter Delimiter to split by
     * @return array Array of parts
     */
    private function splitStringByDelimiterWithDollarQuotes(string $input, string $delimiter): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $inQuote = false;
        $quoteChar = '';
        $inDollarQuote = false;
        $dollarTag = '';

        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];

            // Handle standard quotes
            if ($inQuote) {
                if ($char === $quoteChar) {
                    $inQuote = false;
                }
            } elseif (!$inDollarQuote && ($char === '\'' || $char === '"' || $char === '`')) {
                $inQuote = true;
                $quoteChar = $char;
            }

            // Handle dollar quotes
            if (!$inQuote && !$inDollarQuote && $char === '$') {
                // Try to parse dollar tag
                $tagStart = $i;
                $tag = '$';
                $i++;
                while ($i < $len && $input[$i] !== '$') {
                    $tag .= $input[$i];
                    $i++;
                }
                if ($i < $len && $input[$i] === '$') {
                    $tag .= '$';
                    $inDollarQuote = true;
                    $dollarTag = $tag;
                    $buffer .= $tag;
                    continue;
                } else {
                    // Not a valid tag, reset
                    $i = $tagStart;
                }
            }

            if ($inDollarQuote && substr($input, $i, strlen($dollarTag)) === $dollarTag) {
                $buffer .= $dollarTag;
                $i += strlen($dollarTag) - 1;
                $inDollarQuote = false;
                $dollarTag = '';
                continue;
            }

            // Handle parentheses and delimiter
            if (!$inQuote && !$inDollarQuote) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === $delimiter && $depth === 0) {
                    $parts[] = trim($buffer);
                    $buffer = '';
                    continue;
                }
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    /**
     * Parse a single value literal (PostgreSQL version)
     *
     * @param string $literal The value literal
     * @return mixed The parsed value
     */
    private function parsePostgreSQLValueLiteral(string $literal)
    {
        $literal = trim($literal);

        // NULL
        if (strtoupper($literal) === 'NULL') {
            return null;
        }

        // Dollar-quoted strings
        if (preg_match('/^\$([^$]*)\$(.*)\$\1\$/s', $literal, $matches)) {
            return $matches[2]; // Content between dollar quotes (no escaping needed)
        }

        // Standard quoted strings
        if (preg_match('/^\'(.*)\'(?:::[\w\[\]]+)?$/s', $literal, $matches)) {
            // Single-quoted string with optional type cast
            $content = $matches[1];
            // PostgreSQL doubles single quotes for escaping
            return str_replace("''", "'", $content);
        }

        if (preg_match('/^"(.*)"$/s', $literal, $matches)) {
            // Double-quoted identifier (not a string literal in PostgreSQL)
            return $matches[1];
        }

        // Array literals
        if (preg_match('/^ARRAY\[(.*)\](?:::[\w\[\]]+)?$/i', $literal, $matches)) {
            // ARRAY[...] syntax
            $arrayContents = $matches[1];
            return $this->parsePostgreSQLArray($arrayContents);
        }

        if (preg_match('/^\{(.*)\}(?:::[\w\[\]]+)?$/s', $literal, $matches)) {
            // {...} array syntax
            $arrayContents = $matches[1];
            return $this->parsePostgreSQLArray($arrayContents);
        }

        // Numeric literals
        if (is_numeric($literal)) {
            return strpos($literal, '.') !== false ? (float)$literal : (int)$literal;
        }

        // Boolean literals
        if (strtoupper($literal) === 'TRUE' || strtoupper($literal) === 'T') {
            return true;
        }
        if (strtoupper($literal) === 'FALSE' || strtoupper($literal) === 'F') {
            return false;
        }

        // Remove type casts for parsing
        if (preg_match('/^(.+)::[\w\[\]]+$/s', $literal, $matches)) {
            return $this->parsePostgreSQLValueLiteral($matches[1]);
        }

        // PostgreSQL functions and expressions (keep as string for now)
        if (preg_match('/^[A-Z_][A-Z0-9_]*\s*\(/i', $literal)) {
            return $literal; // Function call
        }

        // Default: return as string
        return $literal;
    }

    /**
     * Parse PostgreSQL array literal
     *
     * @param string $arrayContents Content inside array brackets
     * @return array Parsed array
     */
    private function parsePostgreSQLArray(string $arrayContents): array
    {
        if (trim($arrayContents) === '') {
            return [];
        }

        $elements = $this->splitStringByDelimiterWithDollarQuotes($arrayContents, ',');
        $result = [];

        foreach ($elements as $element) {
            $result[] = $this->parsePostgreSQLValueLiteral(trim($element));
        }

        return $result;
    }

    /**
     * Check if a definition is a constraint.
     *
     * @param string $definition The definition string.
     * @return bool True if it is a constraint definition.
     */
    protected function isConstraintDefinition(string $definition): bool
    {
        return preg_match('/^(CONSTRAINT|CHECK|FOREIGN\s+KEY|EXCLUDE|UNIQUE)/i', $definition) === 1;
    }

    /**
     * Check if a definition is an inline index (i.e., PRIMARY KEY).
     *
     * @param string $definition The definition string.
     * @return bool True if it is an inline index definition.
     */
    protected function isIndexDefinition(string $definition): bool
    {
        return preg_match('/^PRIMARY\s+KEY/i', $definition) === 1;
    }

    /**
     * Configures a Column object for PostgreSQL SERIAL types.
     *
     * This method translates PostgreSQL's serial pseudo-types (SERIAL, SMALLSERIAL,
     * BIGSERIAL) into their underlying integer types and sets the appropriate
     * column properties for auto-incrementing behavior. It marks the column as
     * auto-incrementing and non-nullable.
     *
     * @param Column $column The column object to be configured.
     * @param string $serialType The specific serial type (e.g., 'SERIAL', 'BIGSERIAL').
     * @return void
     */
    protected function handleSerialType(Column $column, string $serialType): void
    {
        switch (strtoupper($serialType)) {
            case 'SMALLSERIAL':
                $column->setType('smallint');
                break;
            case 'BIGSERIAL':
                $column->setType('bigint');
                break;
            default:
                $column->setType('integer');
                break;
        }

        $column->setAutoIncrement(true);
        $column->setNullable(false);
    }


    /**
     * Generate unique index name
     */
    protected function generateIndexName(Table $table, string $type): string
    {
        $i = 1;
        do {
            $name = $table->getName() . '_' . $type . '_' . $i;
            $i++;
        } while ($table->getIndex($name) !== null);

        return $name;
    }

    /**
     * Generate unique constraint name
     */
    protected function generateConstraintName(Table $table, string $type): string
    {
        $i = 1;
        do {
            $name = $table->getName() . '_' . $type . '_' . $i;
            $i++;
        } while ($table->getConstraint($name) !== null);

        return $name;
    }
}
