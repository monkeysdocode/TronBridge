<?php

require_once __DIR__ . '/AbstractParser.php';

/**
 * MySQL Parser - Complete Implementation with AbstractParser Compatibility
 * 
 * Handles MySQL-specific syntax including ENUM, SET, AUTO_INCREMENT,
 * ENGINE specifications, indexes, constraints, and all MySQL features.
 * 
 * @package Database\Schema\Parsers
 * @author Enhanced Model System
 * @version 2.0.0 - Complete Implementation with AbstractParser Compatibility
 */
class MySQLParser extends AbstractParser
{
    /**
     * Stores ALTER TABLE statements that need to be applied after initial table creation.
     *
     * @var array<string, array<string>>
     */
    private array $pendingAlterStatements = [];

    /*
    |--------------------------------------------------------------------------
    | Parser Identification
    |--------------------------------------------------------------------------
    */

    /**
     * Get the name of the parser.
     *
     * @return string The parser name.
     */
    public function getName(): string
    {
        return 'mysql';
    }

    /**
     * Get the database type for integration with DatabaseSQLParser.
     *
     * @return string The database type constant.
     */
    protected function getDatabaseType(): string
    {
        return 'mysql';
    }

    /*
    |--------------------------------------------------------------------------
    | Statement-Level Parsing
    |--------------------------------------------------------------------------
    */

    /**
     * Parse multiple SQL statements from a string.
     *
     * This method overrides the parent to specifically handle deferred ALTER TABLE statements.
     *
     * @param string $sql The SQL string containing one or more statements.
     * @return array<string, Table> An associative array of parsed Table objects, keyed by table name.
     * @throws ParseException If strict mode is enabled and a parsing error occurs.
     */
    public function parseStatements(string $sql): array
    {
        $this->pendingAlterStatements = [];
        $tables = [];

        $parser = new \DatabaseSQLParser($this->getDatabaseType(), [
            'skip_empty_statements' => true,
            'skip_comments' => !$this->options['preserve_comments'],
            'preserve_comments' => $this->options['preserve_comments']
        ]);

        $statements = $parser->parseStatements($sql);

        // First pass: Handle CREATE TABLE statements
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }

            // Ignore SET statements (common in phpMyAdmin dumps)
            if (stripos($statement, 'SET ') === 0) {
                if ($this->options['debug']) {
                    error_log("Ignored SET statement: " . substr($statement, 0, 50));
                }
                continue;
            }

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
                    if ($this->options['debug']) {
                        error_log("Failed to parse CREATE TABLE: " . $e->getMessage());
                    }
                }
            }
            // Handle ALTER TABLE statements
            elseif (stripos($statement, 'ALTER TABLE') === 0) {
                $tableName = $this->extractTableNameFromAlter($statement);
                if ($tableName) {
                    $this->pendingAlterStatements[$tableName][] = $statement;
                    if ($this->options['debug']) {
                        error_log("Found ALTER TABLE statement for: " . $tableName);
                    }
                }
            }
            // TODO Handle CREATE INDEX statements
            elseif ($this->isCreateIndexStatement($statement)) {
                if ($this->options['debug']) {
                    error_log("Found CREATE INDEX statement (not yet implemented)");
                }
            } elseif ($this->isInsertStatement($statement) && $this->options['process_insert_statements']) {
                $this->parseInsertStatement($statement, $tables);
            }
        }

        // Second pass: Apply ALTER TABLE statements to existing tables
        $this->applyAlterTableStatements($tables);

        return $tables;
    }

    /**
     * Extract table name from ALTER TABLE statement
     */
    protected function extractTableNameFromAlter(string $statement): ?string
    {
        if (preg_match('/ALTER\s+TABLE\s+(?:`([^`]+)`|(\w+))\s+/i', $statement, $matches)) {
            return $matches[1] ?: $matches[2];
        }
        return null;
    }

    /**
     * Update column from MODIFY operation
     */
    private function updateColumnFromModify(Column $column, string $definition): void
    {
        // Parse the new type and modifiers
        $typeInfo = $this->parseDataType($definition);

        // Update column properties
        if (isset($typeInfo['type'])) {
            $column->setType($typeInfo['type']);
        }
        if (isset($typeInfo['length'])) {
            $column->setLength($typeInfo['length']);
        }
        if (isset($typeInfo['precision'])) {
            $column->setPrecision($typeInfo['precision']);
        }
        if (isset($typeInfo['scale'])) {
            $column->setScale($typeInfo['scale']);
        }
        if (isset($typeInfo['unsigned'])) {
            $column->setUnsigned($typeInfo['unsigned']);
        }

        // Parse modifiers (AUTO_INCREMENT, NULL/NOT NULL, DEFAULT, etc.)
        $modifiers = $typeInfo['remainder'] ?? '';
        $this->parseColumnModifiers($column, $modifiers, $column->getTable());
        $this->parseMySQLColumnModifiers($column, $modifiers);
    }


    /**
     * Add PRIMARY KEY to existing table
     */
    protected function addPrimaryKeyToTable(Table $table, string $columnList): void
    {
        // Remove any existing primary key
        $existingIndexes = $table->getIndexes();
        foreach ($existingIndexes as $name => $index) {
            if ($index->isPrimary()) {
                $table->removeIndex($name);
                break;
            }
        }

        // Create new primary key using existing parseIndexColumns method
        $primaryKey = new Index('PRIMARY', Index::TYPE_PRIMARY);
        $this->parseIndexColumns($primaryKey, $columnList);
        $table->addIndex($primaryKey);

        // Mark columns as primary key for SQLite conversion
        $pkColumns = $primaryKey->getColumnNames();
        foreach ($pkColumns as $columnName) {
            $column = $table->getColumn($columnName);
            if ($column) {
                $column->setCustomOption('is_primary_key', true);
            }
        }

        if ($this->options['debug']) {
            error_log("Added PRIMARY KEY to table: " . $table->getName());
        }
    }

    /**
     * Modify existing column in table (handles AUTO_INCREMENT)
     */
    protected function modifyExistingColumn(Table $table, string $columnName, string $definition): void
    {
        $column = $table->getColumn($columnName);
        if (!$column) {
            if ($this->options['debug']) {
                error_log("Column '$columnName' not found for MODIFY in table: " . $table->getName());
            }
            return;
        }

        // Use existing parseDataType method
        $typeInfo = $this->parseDataType($definition);

        // Update column type if changed
        if (isset($typeInfo['type'])) {
            $column->setType($typeInfo['type']);
        }

        // Update type-specific attributes using existing logic
        if (isset($typeInfo['length'])) {
            $column->setLength($typeInfo['length']);
        }
        if (isset($typeInfo['precision'])) {
            $column->setPrecision($typeInfo['precision']);
        }
        if (isset($typeInfo['scale'])) {
            $column->setScale($typeInfo['scale']);
        }
        if (isset($typeInfo['unsigned'])) {
            $column->setUnsigned($typeInfo['unsigned']);
        }

        // Use existing modifier parsing methods - this is where AUTO_INCREMENT gets set
        $this->parseColumnModifiers($column, $typeInfo['remainder'], $table);
        $this->parseMySQLColumnModifiers($column, $typeInfo['remainder']);

        if ($this->options['debug']) {
            error_log("Modified column '$columnName' in table: " . $table->getName());
        }
    }

    /**
     * Parse a CREATE TABLE statement into a Table object.
     *
     * @param string $statement The CREATE TABLE SQL statement.
     * @return Table The parsed Table object.
     * @throws ParseException If the table name or body cannot be extracted.
     */
    public function parseCreateTable(string $statement): Table
    {
        // Extract table name
        if (!preg_match('/CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`([^`]+)`|(\w+))\s*\(/i', $statement, $matches)) {
            throw new ParseException("Could not extract table name from CREATE TABLE statement");
        }

        $tableName = $matches[1] ?: $matches[2];
        $table = new Table($tableName);

        // Extract table body (between parentheses)
        if (!preg_match('/\((.*)\)\s*(?:ENGINE|DEFAULT|COMMENT|$)/is', $statement, $bodyMatches)) {
            throw new ParseException("Could not extract table body from CREATE TABLE statement");
        }

        $tableBody = $bodyMatches[1];

        // Split into individual definitions
        $definitions = $this->splitDefinitions($tableBody);

        foreach ($definitions as $index => $definition) {
            $definition = trim($definition);
            if (empty($definition)) continue;

            try {
                if ($this->isColumnDefinition($definition)) {
                    $this->parseColumnDefinition($table, $definition);
                } elseif ($this->isIndexDefinition($definition)) {
                    $this->parseIndexDefinition($table, $definition);
                } elseif ($this->isConstraintDefinition($definition)) {
                    $this->parseConstraintDefinition($table, $definition);
                } else {
                    if ($this->options['debug']) {
                        error_log("MySQLParser: Unknown definition type: " . substr($definition, 0, 50));
                    }
                    $this->addWarning("Unknown table definition: " . substr($definition, 0, 50) . "...");
                }
            } catch (\Exception $e) {
                if ($this->options['strict']) {
                    throw new ParseException("Failed to parse table definition #$index in table '$tableName': $definition\nError: " . $e->getMessage());
                } else {
                    if ($this->options['debug']) {
                        error_log("MySQLParser: Error parsing definition #$index: " . $e->getMessage());
                    }
                    $this->addWarning("Failed to parse table definition in '$tableName': " . $e->getMessage());
                }
            }
        }

        // Parse table options (ENGINE, CHARSET, etc.)
        $this->parseTableOptions($table, $statement);

        if ($this->options['debug']) {
            error_log("MySQLParser: Completed parsing table '$tableName' - " .
                count($table->getColumns()) . " columns, " .
                count($table->getIndexes()) . " indexes, " .
                count($table->getConstraints()) . " constraints");
        }

        return $table;
    }

    /**
     * Parse table definitions (columns, indexes, constraints)
     * 
     */
    protected function parseTableDefinitions(Table $table, string $definitions): void
    {
        $parts = $this->splitDefinitions($definitions);

        foreach ($parts as $part) {
            $part = trim($part);

            if (empty($part)) {
                continue;
            }

            // Determine type of definition
            if ($this->isConstraintDefinition($part)) {
                $this->parseConstraintDefinition($table, $part);
            } elseif ($this->isIndexDefinition($part)) {
                $this->parseIndexDefinition($table, $part);
            } else {
                // It's a column definition
                $this->parseColumnDefinition($table, $part);
            }
        }
    }

    /**
     * Parse a column definition string into a Column object.
     *
     * @param Table $table The table to which the column belongs.
     * @param string $definition The column definition SQL string.
     * @return Column The parsed Column object.
     * @throws ParseException If the column definition is invalid.
     */
    public function parseColumnDefinition(Table $table, string $definition): Column
    {
        // Extract column name (handle MySQL backticks)
        if (!preg_match('/^(?:`([^`]+)`|(\w+))\s*(.*)$/i', $definition, $matches)) {
            throw new ParseException("Could not parse column definition: $definition");
        }

        $columnName = $matches[1] ?: $matches[2];
        $remainder = trim($matches[3]);

        // Extract type and create column
        $typeInfo = $this->parseDataType($remainder);
        $column = new Column($columnName, $typeInfo['type']);
        $column->setOriginalDefinition($definition);

        // Set type-specific attributes
        if (isset($typeInfo['length'])) {
            $column->setLength($typeInfo['length']);
        }
        if (isset($typeInfo['precision'])) {
            $column->setPrecision($typeInfo['precision']);
        }
        if (isset($typeInfo['scale'])) {
            $column->setScale($typeInfo['scale']);
        }
        if (isset($typeInfo['enum_values'])) {
            $column->setEnumValues($typeInfo['enum_values']);
        }
        if (isset($typeInfo['set_values'])) {
            $column->setCustomOption('set_values', $typeInfo['set_values']);
        }
        if (isset($typeInfo['unsigned'])) {
            $column->setUnsigned($typeInfo['unsigned']);
        }
        if (isset($typeInfo['zerofill'])) {
            $column->setCustomOption('zerofill', $typeInfo['zerofill']);
        }

        // Parse remaining modifiers using AbstractParser method
        $this->parseColumnModifiers($column, $typeInfo['remainder'], $table);

        $this->parseMySQLColumnModifiers($column, $typeInfo['remainder']);

        $table->addColumn($column);
        return $column;
    }

    /**
     * Parse an index definition string into an Index object.
     *
     * @param Table $table The table to which the index belongs.
     * @param string $definition The index definition SQL string.
     * @return Index The parsed Index object.
     * @throws ParseException If the index definition is invalid.
     */
    public function parseIndexDefinition(Table $table, string $definition): Index
    {
        try {
            // PRIMARY KEY
            if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]+)\)/i', $definition, $matches)) {
                $index = new Index('PRIMARY', Index::TYPE_PRIMARY);
                $this->parseIndexColumns($index, $matches[1]);
                $table->addIndex($index);
                return $index;
            }

            // UNIQUE KEY/INDEX
            if (preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)(?:\s+USING\s+([A-Z]+))?/i', $definition, $matches)) {
                $indexName = $matches[1] ?: $matches[2];
                $index = new Index($indexName, Index::TYPE_UNIQUE);
                $this->parseIndexColumns($index, $matches[3]);
                if (isset($matches[4])) {
                    $index->setMethod(strtoupper($matches[4]));
                }
                $table->addIndex($index);
                return $index;
            }

            // Regular KEY/INDEX
            if (preg_match('/^(?:KEY|INDEX)\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)(?:\s+USING\s+([A-Z]+))?/i', $definition, $matches)) {
                $indexName = $matches[1] ?: $matches[2];
                $index = new Index($indexName, Index::TYPE_INDEX);
                $this->parseIndexColumns($index, $matches[3]);
                if (isset($matches[4])) {
                    $index->setMethod(strtoupper($matches[4]));
                }
                $table->addIndex($index);
                return $index;
            }

            // FULLTEXT KEY/INDEX
            if (preg_match('/^FULLTEXT\s+(?:KEY|INDEX)\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)/i', $definition, $matches)) {
                $indexName = $matches[1] ?: $matches[2];
                $index = new Index($indexName, Index::TYPE_FULLTEXT);
                $this->parseIndexColumns($index, $matches[3]);
                $table->addIndex($index);
                return $index;
            }

            // SPATIAL KEY/INDEX
            if (preg_match('/^SPATIAL\s+(?:KEY|INDEX)\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)/i', $definition, $matches)) {
                $indexName = $matches[1] ?: $matches[2];
                $index = new Index($indexName, Index::TYPE_SPATIAL);
                $this->parseIndexColumns($index, $matches[3]);
                $table->addIndex($index);
                return $index;
            }

            throw new ParseException("Could not parse index definition: $definition");
        } catch (\Exception $e) {
            if ($this->options['debug']) {
                error_log("MySQLParser: Error in parseIndexDefinition: " . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Parse standalone CREATE INDEX statement
     */
    protected function parseStandaloneCreateIndex(string $statement, array &$tables): void
    {
        // Extract table and index information
        if (!preg_match('/CREATE\s+(UNIQUE\s+)?INDEX\s+(?:`([^`]+)`|(\w+))\s+ON\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)/i', $statement, $matches)) {
            if ($this->options['debug']) {
                error_log("MySQLParser: Could not parse CREATE INDEX statement");
            }
            $this->addWarning("Could not parse CREATE INDEX statement");
            return;
        }

        $isUnique = !empty($matches[1]);
        $indexName = $matches[2] ?: $matches[3];
        $tableName = $matches[4] ?: $matches[5];
        $columns = $matches[6];

        // Check if table exists
        if (!isset($tables[$tableName])) {
            if ($this->options['debug']) {
                error_log("MySQLParser: CREATE INDEX references unknown table: $tableName");
            }
            $this->addWarning("CREATE INDEX references unknown table: $tableName");
            return;
        }

        $table = $tables[$tableName];

        // Create appropriate index definition
        if ($isUnique) {
            $indexDef = "UNIQUE KEY `$indexName` ($columns)";
        } else {
            $indexDef = "KEY `$indexName` ($columns)";
        }

        try {
            $this->parseIndexDefinition($table, $indexDef);

            if ($this->options['debug']) {
                error_log("MySQLParser: Added standalone index '$indexName' to table '$tableName'");
            }
        } catch (\Exception $e) {
            if ($this->options['debug']) {
                error_log("MySQLParser: Error parsing standalone CREATE INDEX: " . $e->getMessage());
            }
            $this->addWarning("Error parsing CREATE INDEX: " . $e->getMessage());
        }
    }

    /**
     * Parse a constraint definition string into a Constraint object.
     *
     * @param Table $table The table to which the constraint belongs.
     * @param string $definition The constraint definition SQL string.
     * @return Constraint The parsed Constraint object.
     * @throws ParseException If the constraint definition is invalid.
     */
    public function parseConstraintDefinition(Table $table, string $definition): Constraint
    {
        if (!preg_match('/^CONSTRAINT\s+(?:`([^`]+)`|(\w+))\s+(.+)$/i', $definition, $matches)) {
            throw new ParseException("Could not parse constraint definition: {$definition}");
        }

        $name = $matches[1] ?: $matches[2];
        $constraintBody = $matches[3];

        // FOREIGN KEY
        if (preg_match('/^FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)(.*)$/i', $constraintBody, $fkMatches)) {
            $constraint = new Constraint($name, Constraint::TYPE_FOREIGN_KEY);
            $constraint->setColumns($this->parseColumnList($fkMatches[1]));
            $constraint->setReferencedTable($fkMatches[2] ?: $fkMatches[3]);
            $constraint->setReferencedColumns($this->parseColumnList($fkMatches[4]));

            // Parse foreign key actions
            if (!empty($fkMatches[5])) {
                $this->parseForeignKeyActions($constraint, $fkMatches[5]);
            }

            $table->addConstraint($constraint);
            return $constraint;
        }

        // CHECK constraint
        if (preg_match('/^CHECK\s*\((.+)\)$/i', $constraintBody, $checkMatches)) {
            $constraint = new Constraint($name, Constraint::TYPE_CHECK);
            $constraint->setExpression($checkMatches[1]);
            $table->addConstraint($constraint);
            return $constraint;
        }

        // UNIQUE constraint (different from UNIQUE INDEX)
        if (preg_match('/^UNIQUE\s*\(([^)]+)\)$/i', $constraintBody, $uniqueMatches)) {
            $constraint = new Constraint($name, Constraint::TYPE_UNIQUE);
            $constraint->setColumns($this->parseColumnList($uniqueMatches[1]));
            $table->addConstraint($constraint);
            return $constraint;
        }

        throw new ParseException("Could not parse constraint body: {$constraintBody}");
    }

    /*
    |--------------------------------------------------------------------------
    | ALTER TABLE Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Apply collected ALTER TABLE statements to the table objects.
     *
     * @param array<string, Table> &$tables A reference to the array of Table objects.
     * @return void
     */
    protected function applyAlterTableStatements(array &$tables): void
    {
        foreach ($this->pendingAlterStatements as $tableName => $alterStatements) {
            if (isset($tables[$tableName])) {
                foreach ($alterStatements as $alterSql) {
                    try {
                        $this->parseAlterTable($alterSql, $tables);
                    } catch (\Exception $e) {
                        if ($this->options['strict']) {
                            throw $e;
                        }
                        $this->addWarning("Failed to parse ALTER TABLE statement: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Process a single ALTER TABLE operation clause.
     *
     * @param Table $table The table object being altered.
     * @param string $operation The ALTER TABLE operation SQL string (e.g., 'ADD COLUMN ...').
     * @return void
     */
    protected function processAlterOperation(Table $table, string $operation): void
    {
        $operation = trim($operation);

        // ADD PRIMARY KEY
        if (preg_match('/^ADD\s+PRIMARY\s+KEY\s*\(([^)]+)\)$/i', $operation, $matches)) {
            $this->parseIndexDefinition($table, "PRIMARY KEY ({$matches[1]})");
            return;
        }

        // ADD UNIQUE KEY/INDEX
        if (preg_match('/^ADD\s+UNIQUE\s+(?:KEY|INDEX)\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)(?:\s+USING\s+([A-Z]+))?$/i', $operation, $matches)) {
            $indexName = $matches[1] ?: $matches[2];
            $columns = $matches[3];
            $method = isset($matches[4]) ? " USING {$matches[4]}" : '';
            $this->parseIndexDefinition($table, "UNIQUE KEY `$indexName` ($columns)$method");
            return;
        }

        // ADD KEY/INDEX (regular)
        if (preg_match('/^ADD\s+(?:KEY|INDEX)\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)(?:\s+USING\s+([A-Z]+))?$/i', $operation, $matches)) {
            $indexName = $matches[1] ?: $matches[2];
            $columns = $matches[3];
            $method = isset($matches[4]) ? " USING {$matches[4]}" : '';
            $this->parseIndexDefinition($table, "KEY `$indexName` ($columns)$method");
            return;
        }

        // ADD FULLTEXT KEY/INDEX
        if (preg_match('/^ADD\s+FULLTEXT\s+(?:KEY|INDEX)\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)$/i', $operation, $matches)) {
            $indexName = $matches[1] ?: $matches[2];
            $columns = $matches[3];
            $this->parseIndexDefinition($table, "FULLTEXT KEY `$indexName` ($columns)");
            return;
        }

        // ADD SPATIAL KEY/INDEX
        if (preg_match('/^ADD\s+SPATIAL\s+(?:KEY|INDEX)\s+(?:`([^`]+)`|(\w+))\s*\(([^)]+)\)$/i', $operation, $matches)) {
            $indexName = $matches[1] ?: $matches[2];
            $columns = $matches[3];
            $this->parseIndexDefinition($table, "SPATIAL KEY `$indexName` ($columns)");
            return;
        }

        // ADD CONSTRAINT (foreign keys, check constraints)
        if (preg_match('/^ADD\s+CONSTRAINT\s+(.+)$/i', $operation, $matches)) {
            $constraintDef = $matches[1];

            try {
                $this->parseConstraintDefinition($table, "CONSTRAINT $constraintDef");
            } catch (\Exception $e) {

                if ($this->options['strict']) {
                    throw $e;
                }
                $this->addWarning("Failed to parse constraint: " . $e->getMessage());
            }
            return;
        }

        // MODIFY column (for AUTO_INCREMENT and other column changes)
        if (preg_match('/^MODIFY\s+(?:COLUMN\s+)?(?:`([^`]+)`|(\w+))\s+(.+)$/i', $operation, $matches)) {
            $columnName = $matches[1] ?: $matches[2];
            $columnDef = $matches[3];

            // Check if column exists
            if ($table->hasColumn($columnName)) {
                $this->updateColumnFromModify($table->getColumn($columnName), $columnDef);
            } else {
                $this->addWarning("MODIFY references unknown column '$columnName' in table '{$table->getName()}'");
            }
            return;
        }

        // ADD COLUMN (less common in ALTER TABLE, but possible)
        if (preg_match('/^ADD\s+(?:COLUMN\s+)?(.+)$/i', $operation, $matches)) {
            $columnDef = $matches[1];
            try {
                $this->parseColumnDefinition($table, $columnDef);
            } catch (\Exception $e) {
                if ($this->options['strict']) {
                    throw $e;
                }
                $this->addWarning("Could not parse ADD COLUMN operation: " . $e->getMessage());
            }
            return;
        }

        // TODO Other operations (DROP, CHANGE, etc.) - log for future implementation
        if (preg_match('/^(DROP|CHANGE|RENAME|ALTER)\s+/i', $operation, $matches)) {
            $operationType = strtoupper($matches[1]);
            if ($this->options['debug']) {
                error_log("MySQLParser: Skipping unsupported ALTER operation: $operationType");
            }
            $this->addWarning("Unsupported ALTER TABLE operation: $operationType");
            return;
        }

        // Unknown operation
        $this->addWarning("Unknown ALTER TABLE operation: " . substr($operation, 0, 50) . "...");
    }


    /*
    |--------------------------------------------------------------------------
    | Parsing Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Parse the data type and its associated parameters (length, precision, enum values).
     *
     * @param string $definition The string containing the data type and its parameters.
     * @return array An associative array containing 'type', 'length', 'precision', 'scale', 'enum_values', 'set_values', and 'remainder'.
     */
    private function parseDataType(string $definition): array
    {
        $result = [
            'type' => 'varchar',
            'remainder' => $definition
        ];

        if (preg_match('/^([A-Z]+)(?:\(([^)]*)\))?(?:\s+(UNSIGNED|SIGNED|ZEROFILL))*\s*(.*)$/i', $definition, $matches)) {
            $result['type'] = strtolower($matches[1]);
            $parameters = $matches[2] ?? '';
            $modifiers = $matches[3] ?? '';
            $result['remainder'] = $matches[4] ?? '';

            // Handle modifiers
            if (stripos($modifiers, 'UNSIGNED') !== false) {
                $result['unsigned'] = true;
            }
            if (stripos($modifiers, 'ZEROFILL') !== false) {
                $result['zerofill'] = true;
            }

            // Parse parameters based on type
            switch ($result['type']) {
                case 'enum':
                case 'set':
                    if (preg_match_all('/[\'"]([^\'"]*)[\'"]/', $parameters, $enumMatches)) {
                        $result[$result['type'] . '_values'] = $enumMatches[1]; // enum_values, set_values
                    }
                    break;

                case 'varchar':
                case 'char':
                case 'binary':
                case 'varbinary':
                case 'bit':
                    if (is_numeric($parameters)) {
                        $result['length'] = (int)$parameters;
                    }
                    break;

                case 'decimal':
                case 'numeric':
                case 'float':
                case 'double':
                    if (strpos($parameters, ',') !== false) {
                        $parts = explode(',', $parameters);
                        $result['precision'] = (int)trim($parts[0]);
                        $result['scale'] = (int)trim($parts[1]);
                    } elseif ($parameters && is_numeric($parameters)) {
                        $result['precision'] = (int)$parameters;
                    }
                    break;

                case 'time':
                case 'datetime':
                case 'timestamp':
                    if (is_numeric($parameters)) {
                        $result['precision'] = (int)$parameters;
                    }
                    break;
            }
        }

        return $result;
    }

    /**
     * Parse MySQL-specific column modifiers (e.g., ON UPDATE, CHARACTER SET, COLLATE).
     *
     * @param Column $column The column object to update.
     * @param string $modifiers The string containing the modifiers.
     * @return void
     */
    private function parseMySQLColumnModifiers(Column $column, string $modifiers): void
    {
        if (preg_match('/\sON\s+UPDATE\s+(CURRENT_TIMESTAMP|[\'"][^\'"]*[\'"])/i', $modifiers, $matches)) {
            $column->setCustomOption('on_update', trim($matches[1], '\'"'));
        }

        if (preg_match('/\s(?:CHARACTER\s+SET|CHARSET)\s+([A-Za-z0-9_]+)/i', $modifiers, $matches)) {
            $column->setCustomOption('charset', $matches[1]);
        }

        if (preg_match('/\sCOLLATE\s+([A-Za-z0-9_]+)/i', $modifiers, $matches)) {
            $column->setCustomOption('collation', $matches[1]);
        }

        if (preg_match('/\sCOMMENT\s+[\'"]([^\'"]*)[\'"]/', $modifiers, $matches)) {
            $column->setComment($matches[1]);
        }
    }

    /**
     * Parse the columns and their optional prefix lengths/directions for an index definition.
     *
     * @param Index $index The index object to add columns to.
     * @param string $columnList The comma-separated list of columns (e.g., 'col1, col2(10) DESC').
     * @return void
     */
    protected function parseIndexColumns(Index $index, string $columnList): void
    {
        $columns = $this->splitDefinitions($columnList);

        foreach ($columns as $col) {
            $col = trim($col);

            // Handle column with prefix length: `column_name`(10)
            if (preg_match('/^(?:`([^`]+)`|(\w+))(?:\((\d+)\))?(?:\s+(ASC|DESC))?/i', $col, $matches)) {
                $columnName = $matches[1] ?: $matches[2];
                $prefixLength = isset($matches[3]) ? (int)$matches[3] : null;
                $direction = isset($matches[4]) ? strtoupper($matches[4]) : null;
                $index->addColumn($columnName, $prefixLength, $direction);
            }
        }
    }

    /**
     * Parse foreign key actions (ON UPDATE, ON DELETE).
     *
     * @param Constraint $constraint The constraint object to update.
     * @param string $actions The string containing the ON UPDATE/ON DELETE clauses.
     * @return void
     */
    protected function parseForeignKeyActions(Constraint $constraint, string $actions): void
    {
        if (preg_match('/ON\s+DELETE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT)/i', $actions, $matches)) {
            $constraint->setOnDelete(str_replace(' ', ' ', strtoupper($matches[1])));
        }

        if (preg_match('/ON\s+UPDATE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT)/i', $actions, $matches)) {
            $constraint->setOnUpdate(str_replace(' ', ' ', strtoupper($matches[1])));
        }
    }

    /**
     * Parse a comma-separated list of column names.
     *
     * @param string $list The comma-separated list string.
     * @return string[] An array of unquoted column names.
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
     * Parse table options (e.g., ENGINE, CHARSET, COLLATE, AUTO_INCREMENT, COMMENT, ROW_FORMAT, KEY_BLOCK_SIZE).
     *
     * @param Table $table The table object to update.
     * @param string $sql The full CREATE TABLE statement string.
     * @return void
     */
    private function parseTableOptions(Table $table, string $options): void
    {
        $options = trim($options);

        if (preg_match('/ENGINE\s*=\s*([A-Za-z0-9_]+)/i', $options, $matches)) {
            $table->setEngine($matches[1]);
        }

        if (preg_match('/(?:DEFAULT\s+)?(?:CHARACTER\s+SET|CHARSET)\s*=?\s*([A-Za-z0-9_]+)/i', $options, $matches)) {
            $table->setCharset($matches[1]);
        }

        if (preg_match('/COLLATE\s*=?\s*([A-Za-z0-9_]+)/i', $options, $matches)) {
            $table->setCollation($matches[1]);
        }

        if (preg_match('/AUTO_INCREMENT\s*=\s*(\d+)/i', $options, $matches)) {
            $table->setOption('auto_increment_start', (int)$matches[1]);
        }

        if (preg_match('/COMMENT\s*=\s*[\'"]([^\'"]*)[\'"]/', $options, $matches)) {
            $table->setComment($matches[1]);
        }

        if (preg_match('/ROW_FORMAT\s*=\s*([A-Za-z0-9_]+)/i', $options, $matches)) {
            $table->setOption('row_format', $matches[1]);
        }

        if (preg_match('/KEY_BLOCK_SIZE\s*=\s*(\d+)/i', $options, $matches)) {
            $table->setOption('key_block_size', (int)$matches[1]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT Statement Parsing (NEW - MySQL Implementation)
    |--------------------------------------------------------------------------
    */

    /**
     * Parse INSERT data from MySQL INSERT statement
     * 
     * Handles MySQL-specific INSERT syntax including:
     * - INSERT INTO table VALUES (...)
     * - INSERT INTO table (columns) VALUES (...)
     * - INSERT IGNORE INTO table VALUES (...)
     * - Multi-row INSERT: VALUES (...), (...), (...)
     * - INSERT INTO table SET col1=val1, col2=val2
     *
     * @param string $statement The INSERT statement
     * @return array Array of row data (each row is associative array)
     * @throws ParseException If the INSERT statement cannot be parsed
     */
    protected function parseInsertData(string $statement): array
    {
        $statement = trim($statement);
        $rows = [];

        // Handle INSERT ... SET syntax (MySQL specific)
        if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+(?:`[^`]+`|"[^"]+"|[\w]+)\s+SET\s+(.+?)(?:\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*)?$/is', $statement, $matches)) {
            return $this->parseInsertSetSyntax($matches[1]);
        }

        // Handle standard INSERT ... VALUES syntax
        if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+(?:`([^`]+)`|"([^"]+)"|([\w]+))(?:\s*\(([^)]+)\))?\s+VALUES\s+(.+?)(?:\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*)?$/is', $statement, $matches)) {
            $tableName = $matches[1] ?: $matches[2] ?: $matches[3];
            $columnList = isset($matches[4]) ? $matches[4] : null;
            $valuesClause = $matches[5];

            // Parse column names if specified
            $columns = [];
            if ($columnList) {
                $columns = $this->parseColumnNamesFromInsert($columnList);
            }

            // Parse VALUES clause
            $rows = $this->parseValuesClause($valuesClause, $columns);

            if ($this->options['debug']) {
                error_log("MySQLParser: Parsed INSERT for table '$tableName' - " . count($rows) . " rows");
            }

            return $rows;
        }

        // If we can't parse it, log and return empty
        if ($this->options['debug']) {
            error_log("MySQLParser: Could not parse INSERT statement: " . substr($statement, 0, 100));
        }

        return [];
    }

    /**
     * Parse INSERT SET syntax (MySQL specific)
     * 
     * Example: INSERT INTO table SET col1='value1', col2='value2'
     *
     * @param string $setClause The SET clause content
     * @return array Array with single row of data
     */
    private function parseInsertSetSyntax(string $setClause): array
    {
        $row = [];
        $assignments = $this->splitStringByDelimiter($setClause, ',');

        foreach ($assignments as $assignment) {
            $assignment = trim($assignment);

            // Parse: column = value
            if (preg_match('/^(?:`([^`]+)`|"([^"]+)"|([\w]+))\s*=\s*(.+)$/i', $assignment, $matches)) {
                $columnName = $matches[1] ?: $matches[2] ?: $matches[3];
                $value = $this->parseValueLiteral(trim($matches[4]));

                $row[$columnName] = $value;
            }
        }

        return [$row]; // Return array with single row
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
     * Parse VALUES clause with multiple rows
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
     * Parse value sets from VALUES clause
     * 
     * Handles: (...), (...), (...) with proper nesting and quote handling
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
                $end = $this->findMatchingParenthesis($valuesClause, $i);

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
     * Parse individual value list from a value set
     *
     * @param string $valueSet Comma-separated values
     * @return array Array of parsed values
     */
    private function parseValueList(string $valueSet): array
    {
        $values = [];
        $parts = $this->splitStringByDelimiter($valueSet, ',');

        foreach ($parts as $part) {
            $value = $this->parseValueLiteral(trim($part));
            $values[] = $value;
        }

        return $values;
    }

    /**
     * Parse a single value literal (string, number, NULL, function call, etc.)
     *
     * @param string $literal The value literal
     * @return mixed The parsed value
     */
    private function parseValueLiteral(string $literal)
    {       
        $literal = trim($literal);
        $content = null;
        $length = strlen($literal);

        // NULL
        if (strtoupper($literal) === 'NULL') {
            return null;
        }

        // String literals - FIXED: Handle MySQL escaping properly
        if ($length >= 2 && $literal[0] === '\'' && $literal[$length - 1] === '\'') {
            // Single-quoted string - handle MySQL-style escaped quotes
            $content = substr($literal, 1, -1);

            // Un-escape backslashes first
            $content = str_replace("\\\\", "\\", $content);

            // Handle other common MySQL escape sequences
            $content = str_replace("\\'", "'", $content);
            $content = str_replace("\\n", "\n", $content);
            $content = str_replace("\\r", "\r", $content);
            $content = str_replace("\\t", "\t", $content);
            $content = str_replace("\\\\", "\\", $content);
            $content = str_replace("\\\"", "\"", $content);

            return $content;
        }

        if (preg_match('/^"(.*)"$/s', $literal, $matches)) {
            // Double-quoted string - handle escaped quotes
            $content = $matches[1];
            $content = str_replace('\\"', '"', $content);
            $content = str_replace("\\'", "'", $content);
            return $content;
        }

        // Numeric literals
        if (is_numeric($literal)) {
            return strpos($literal, '.') !== false ? (float)$literal : (int)$literal;
        }

        // Boolean literals (MySQL specific)
        if (strtoupper($literal) === 'TRUE') {
            return true;
        }
        if (strtoupper($literal) === 'FALSE') {
            return false;
        }

        // MySQL functions and expressions (keep as string for now)
        if (preg_match('/^[A-Z_][A-Z0-9_]*\s*\(/i', $literal)) {
            return $literal; // Function call
        }

        // Default: return as string
        return $literal;
    }

    /*
    |--------------------------------------------------------------------------
    | Definition Type Identification
    |--------------------------------------------------------------------------
    */

    /**
     * Check if a definition string represents a constraint.
     *
     * @param string $definition The definition string.
     * @return bool True if it is a constraint definition.
     */
    protected function isConstraintDefinition(string $definition): bool
    {
        return preg_match('/^(CONSTRAINT|CHECK|FOREIGN\s+KEY)/i', $definition) === 1;
    }

    /**
     * Check if a definition string represents an index.
     *
     * @param string $definition The definition string.
     * @return bool True if it is an index definition.
     */
    protected function isIndexDefinition(string $definition): bool
    {
        return preg_match('/^(PRIMARY\s+KEY|UNIQUE(\s+KEY|\s+INDEX)?|KEY|INDEX|FULLTEXT(\s+KEY|\s+INDEX)?|SPATIAL(\s+KEY|\s+INDEX)?)/i', $definition) === 1;
    }

    /**
     * Check if a definition string represents a column.
     *
     * @param string $definition The definition string.
     * @return bool True if it is a column definition.
     */
    protected function isColumnDefinition(string $definition): bool
    {
        // Check for common MySQL data types
        return preg_match('/^(?:`\w+`|\w+)\s+(TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT|DECIMAL|NUMERIC|FLOAT|DOUBLE|REAL|BIT|BOOLEAN|SERIAL|DATE|DATETIME|TIMESTAMP|TIME|YEAR|CHAR|VARCHAR|BINARY|VARBINARY|TINYBLOB|BLOB|MEDIUMBLOB|LONGBLOB|TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|ENUM|SET|JSON|GEOMETRY|POINT|LINESTRING|POLYGON|MULTIPOINT|MULTILINESTRING|MULTIPOLYGON|GEOMETRYCOLLECTION)/i', $definition) === 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
    */

    /**
     * Generate a unique constraint name for a given table and type.
     *
     * @param Table $table The table object.
     * @param string $type The type of constraint (e.g., 'uk', 'fk', 'chk').
     * @return string The generated unique constraint name.
     */
    private function generateConstraintName(Table $table, string $type): string
    {
        $i = 1;
        do {
            $name = $table->getName() . '_' . $type . '_' . $i;
            $i++;
        } while ($table->getConstraint($name) !== null);

        return $name;
    }

    /**
     * Remove quotes (backticks, double quotes, single quotes) from an identifier.
     *
     * @param string $identifier The identifier string.
     * @return string The unquoted identifier.
     */
    protected function unquoteIdentifier(string $identifier): string
    {
        return trim($identifier, '`"[]');
    }

    /**
     * Split a string of definitions (e.g., column definitions, index definitions) by commas,
     * respecting parentheses and quotes.
     *
     * @param string $definitions The string to split.
     * @return array<string> An array of individual definition strings.
     */
    protected function splitDefinitions(string $definitions): array
    {
        $parts = [];
        $current = '';
        $level = 0;
        $inQuotes = false;
        $quoteChar = '';
        $length = strlen($definitions);

        for ($i = 0; $i < $length; $i++) {
            $char = $definitions[$i];
            $prev = $i > 0 ? $definitions[$i - 1] : '';

            if (($char === '"' || $char === "'" || $char === '`') && $prev !== '\\') {
                if (!$inQuotes) {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuotes = false;
                    $quoteChar = '';
                }
            }

            if (!$inQuotes) {
                if ($char === '(') {
                    $level++;
                } elseif ($char === ')') {
                    $level--;
                } elseif ($char === ',' && $level === 0) {
                    $parts[] = trim($current);
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if (!empty(trim($current))) {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * Normalize SQL by removing extra whitespace and standardizing line endings.
     *
     * @param string $sql The SQL string to normalize.
     * @return string The normalized SQL string.
     */
    protected function normalizeSql(string $sql): string
    {
        // Remove extra whitespace and normalize line endings
        $sql = preg_replace('/\s+/', ' ', $sql);
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        return trim($sql);
    }

    /**
     * Check if a statement is a CREATE TABLE statement.
     *
     * @param string $statement The SQL statement.
     * @return bool True if it is a CREATE TABLE statement.
     */
    protected function isCreateTableStatement(string $statement): bool
    {
        return preg_match('/^\s*CREATE\s+(?:TEMPORARY\s+)?TABLE\s+/i', $statement) === 1;
    }

    /**
     * Check if a statement is a CREATE INDEX statement.
     *
     * @param string $statement The SQL statement.
     * @return bool True if it is a CREATE INDEX statement.
     */
    protected function isCreateIndexStatement(string $statement): bool
    {
        return preg_match('/^\s*CREATE\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX\s+/i', $statement) === 1;
    }
}
