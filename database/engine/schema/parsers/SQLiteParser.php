<?php

require_once __DIR__ . '/AbstractParser.php';

/**
 * SQLite SQL Parser - Parse SQLite CREATE TABLE statements
 * 
 * Improved version that properly handles SQLite's simplified type system
 * and specific constraints like the strict AUTOINCREMENT requirements.
 * 
 * @package Database\Parsers
 * @author Enhanced Model System
 * @version 2.0.0
 */
class SQLiteParser extends AbstractParser
{
    /**
     * Get database type for DatabaseSQLParser
     */
    protected function getDatabaseType(): string
    {
        return \DatabaseSQLParser::DB_SQLITE;
    }

    /**
     * Parse CREATE TABLE statement
     */
    public function parseCreateTable(string $sql): Table
    {
        // Store original
        $originalSql = $sql;

        // Normalize whitespace but preserve structure
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // Extract table name (with or without quotes)
        if (!preg_match(
            '/CREATE\s+(?:TEMP\s+|TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|(\w+))\s*\(/i',
            $sql,
            $matches
        )) {
            throw new ParseException("Could not parse table name from CREATE TABLE statement");
        }

        $tableName = $matches[1] ?: $matches[2];

        $table = new Table($tableName);
        $table->setOriginalDefinition($originalSql);

        // Check if it's a temporary table
        if (preg_match('/CREATE\s+(?:TEMP|TEMPORARY)\s+TABLE/i', $sql)) {
            $table->setOption('temporary', true);
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

        // Parse definitions
        $this->parseTableDefinitions($table, $definitions);

        // Parse table options (WITHOUT ROWID, STRICT)
        if ($endPos + 1 < strlen($sql)) {
            $options = substr($sql, $endPos + 1);
            $this->parseTableOptions($table, $options);
        }

        return $table;
    }

    /**
     * Parse table definitions
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
     * Parse standalone CREATE INDEX statement - SQLite Implementation
     */
    protected function parseStandaloneCreateIndex(string $statement, array &$tables): void
    {
        // SQLite CREATE INDEX syntax: CREATE [UNIQUE] INDEX [IF NOT EXISTS] name ON table (columns)
        if (!preg_match('/CREATE\s+(UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|(\w+))\s+ON\s+(?:"([^"]+)"|(\w+))\s*\(([^)]+)\)/i', $statement, $matches)) {
            if ($this->options['debug']) {
                error_log("SQLiteParser: Could not parse CREATE INDEX statement");
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
                error_log("SQLiteParser: CREATE INDEX references unknown table: $tableName");
            }
            $this->addWarning("CREATE INDEX references unknown table: $tableName");
            return;
        }

        $table = $tables[$tableName];

        // Create appropriate index definition
        if ($isUnique) {
            $indexDef = "UNIQUE INDEX \"$indexName\" ($columns)";
        } else {
            $indexDef = "INDEX \"$indexName\" ($columns)";
        }

        try {
            $this->parseIndexDefinition($table, $indexDef);

            if ($this->options['debug']) {
                error_log("SQLiteParser: Added standalone index '$indexName' to table '$tableName'");
            }
        } catch (\Exception $e) {
            if ($this->options['debug']) {
                error_log("SQLiteParser: Error parsing standalone CREATE INDEX: " . $e->getMessage());
            }
            $this->addWarning("Error parsing CREATE INDEX: " . $e->getMessage());
        }
    }

    /**
     * Process a single ALTER TABLE operation for SQLite.
     * 
     * This method implements the abstract processAlterOperation from AbstractParser
     * and handles SQLite's limited ALTER TABLE syntax. SQLite has very limited 
     * ALTER TABLE support compared to other databases.
     *
     * @param Table $table The table object being altered.
     * @param string $operation The operation SQL string (e.g., 'ADD COLUMN ...').
     * @return void
     */
    protected function processAlterOperation(Table $table, string $operation): void
    {
        $operation = trim($operation);

        // =============================================================================
        // SUPPORTED OPERATIONS
        // =============================================================================

        // 1. ADD COLUMN (✅ Supported by SQLite)
        if (preg_match('/^ADD\s+(?:COLUMN\s+)?(.+)$/i', $operation, $matches)) {
            $columnDef = $matches[1];
            try {
                $this->parseColumnDefinition($table, $columnDef);
                if ($this->options['debug']) {
                    error_log("SQLiteParser: Added column via ALTER TABLE ADD COLUMN");
                }
            } catch (\Exception $e) {
                if ($this->options['strict']) {
                    throw $e;
                }
                if ($this->options['debug']) {
                    error_log("SQLiteParser: Could not parse ADD COLUMN: " . $e->getMessage());
                }
                $this->addWarning("Could not parse ADD COLUMN operation: " . $e->getMessage());
            }
            return;
        }

        // 2. RENAME TO (✅ Supported by SQLite - renames the table)
        if (preg_match('/^RENAME\s+TO\s+(?:`([^`]+)`|"([^"]+)"|(\w+))$/i', $operation, $matches)) {
            $newTableName = $matches[1] ?: $matches[2] ?: $matches[3];
            $table->setName($newTableName);
            if ($this->options['debug']) {
                error_log("SQLiteParser: Renamed table to '$newTableName'");
            }
            return;
        }

        // 3. RENAME COLUMN (✅ Supported by SQLite 3.25.0+)
        if (preg_match('/^RENAME\s+(?:COLUMN\s+)?(?:`([^`]+)`|"([^"]+)"|(\w+))\s+TO\s+(?:`([^`]+)`|"([^"]+)"|(\w+))$/i', $operation, $matches)) {
            $oldColumnName = $matches[1] ?: $matches[2] ?: $matches[3];
            $newColumnName = $matches[4] ?: $matches[5] ?: $matches[6];

            if ($table->hasColumn($oldColumnName)) {
                $column = $table->getColumn($oldColumnName);
                $column->setName($newColumnName);
                if ($this->options['debug']) {
                    error_log("SQLiteParser: Renamed column '$oldColumnName' to '$newColumnName'");
                }
            } else {
                $this->addWarning("RENAME COLUMN references unknown column: $oldColumnName");
            }
            return;
        }

        // 4. DROP COLUMN (✅ Supported by SQLite 3.35.0+)
        if (preg_match('/^DROP\s+(?:COLUMN\s+)?(?:`([^`]+)`|"([^"]+)"|(\w+))$/i', $operation, $matches)) {
            $columnName = $matches[1] ?: $matches[2] ?: $matches[3];

            if ($table->hasColumn($columnName)) {
                $table->removeColumn($columnName);
                if ($this->options['debug']) {
                    error_log("SQLiteParser: Dropped column '$columnName'");
                }
            } else {
                $this->addWarning("DROP COLUMN references unknown column: $columnName");
            }
            return;
        }

        // =============================================================================
        // UNSUPPORTED OPERATIONS - Generate warnings and suggestions
        // =============================================================================

        // MySQL-style index operations (❌ Not supported in ALTER TABLE for SQLite)
        if (preg_match('/^ADD\s+(PRIMARY\s+KEY|(?:UNIQUE\s+)?(?:KEY|INDEX)|FULLTEXT|SPATIAL)/i', $operation)) {
            $this->addWarning("SQLite does not support adding indexes via ALTER TABLE. Use separate CREATE INDEX statements.");
            if ($this->options['debug']) {
                error_log("SQLiteParser: Unsupported index operation in ALTER TABLE: " . substr($operation, 0, 50));
            }

            // Try to extract and suggest CREATE INDEX equivalent
            $this->suggestCreateIndexEquivalent($table, $operation);
            return;
        }

        // Constraint operations (❌ Not supported by SQLite ALTER TABLE)
        if (preg_match('/^ADD\s+CONSTRAINT/i', $operation)) {
            $this->addWarning("SQLite does not support adding constraints via ALTER TABLE. Constraints must be defined during table creation.");
            if ($this->options['debug']) {
                error_log("SQLiteParser: Unsupported constraint operation: " . substr($operation, 0, 50));
            }
            return;
        }

        // MODIFY column (❌ Not supported by SQLite)
        if (preg_match('/^MODIFY\s+/i', $operation)) {
            $this->addWarning("SQLite does not support MODIFY COLUMN. Use table recreation strategy instead.");
            if ($this->options['debug']) {
                error_log("SQLiteParser: Unsupported MODIFY operation: " . substr($operation, 0, 50));
            }
            return;
        }

        // ALTER COLUMN (❌ Not supported by SQLite)
        if (preg_match('/^ALTER\s+(?:COLUMN\s+)/i', $operation)) {
            $this->addWarning("SQLite does not support ALTER COLUMN. Use table recreation strategy instead.");
            if ($this->options['debug']) {
                error_log("SQLiteParser: Unsupported ALTER COLUMN operation: " . substr($operation, 0, 50));
            }
            return;
        }

        // DROP operations (except column, which is handled above)
        if (preg_match('/^DROP\s+(INDEX|KEY|CONSTRAINT)/i', $operation, $matches)) {
            $dropType = strtoupper($matches[1]);
            $this->addWarning("SQLite does not support DROP $dropType via ALTER TABLE. Use separate DROP statements.");
            return;
        }

        // CHANGE column (MySQL syntax - not supported)
        if (preg_match('/^CHANGE\s+/i', $operation)) {
            $this->addWarning("SQLite does not support CHANGE COLUMN syntax. Use RENAME COLUMN or table recreation instead.");
            return;
        }

        // Unknown operation
        if ($this->options['debug']) {
            error_log("SQLiteParser: Unknown ALTER operation: " . substr($operation, 0, 100));
        }
        $this->addWarning("Unknown ALTER TABLE operation: " . substr($operation, 0, 50) . "...");
    }

    /**
     * Suggest CREATE INDEX equivalent for unsupported ALTER TABLE index operations.
     * 
     * Since SQLite doesn't support adding indexes via ALTER TABLE, this method
     * analyzes the attempted operation and provides helpful suggestions.
     *
     * @param Table $table The table being altered
     * @param string $operation The unsupported index operation
     * @return void
     */
    private function suggestCreateIndexEquivalent(Table $table, string $operation): void
    {
        // Try to extract index information and suggest CREATE INDEX
        if (preg_match('/^ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+(?:`([^`]+)`|"([^"]+)"|(\w+))\s*\(([^)]+)\)/i', $operation, $matches)) {
            $indexName = $matches[1] ?: $matches[2] ?: $matches[3];
            $columns = $matches[4];
            $isUnique = stripos($operation, 'UNIQUE') !== false;

            $suggestion = $isUnique
                ? "CREATE UNIQUE INDEX \"$indexName\" ON \"{$table->getName()}\" ($columns);"
                : "CREATE INDEX \"$indexName\" ON \"{$table->getName()}\" ($columns);";

            $this->addWarning("Suggested equivalent: $suggestion");

            if ($this->options['debug']) {
                error_log("SQLiteParser: Suggested CREATE INDEX equivalent: $suggestion");
            }
        }
        // Handle PRIMARY KEY
        elseif (preg_match('/^ADD\s+PRIMARY\s+KEY\s*\(([^)]+)\)/i', $operation, $matches)) {
            $columns = $matches[1];
            $this->addWarning("SQLite PRIMARY KEY must be defined during table creation. Consider recreating the table with PRIMARY KEY ($columns).");
        }
        // Handle other index types
        elseif (preg_match('/^ADD\s+(FULLTEXT|SPATIAL)/i', $operation, $matches)) {
            $indexType = strtoupper($matches[1]);
            $this->addWarning("SQLite does not support $indexType indexes. Consider using FTS (Full-Text Search) virtual tables for text search.");
        }
    }

    /**
     * Check if definition is a constraint
     */
    protected function isConstraintDefinition(string $definition): bool
    {
        return preg_match('/^(CONSTRAINT|CHECK|FOREIGN\s+KEY)/i', $definition) === 1;
    }

    /**
     * Check if definition is an index
     */
    protected function isIndexDefinition(string $definition): bool
    {
        return preg_match('/^(PRIMARY\s+KEY|UNIQUE)/i', $definition) === 1;
    }

    /**
     * Parse column definition
     */
    public function parseColumnDefinition(Table $table, string $definition): Column
    {
        // Extract column name (handle double quotes)
        if (!preg_match('/^(?:"([^"]+)"|(\w+))\s*(.*)$/i', $definition, $matches)) {
            throw new ParseException("Could not parse column definition: $definition");
        }

        $columnName = $matches[1] ?: $matches[2];
        $remainder = $matches[3];

        $column = new Column($columnName, 'text'); // Will be updated by parseDataType

        // Parse data type (if present - SQLite allows typeless columns)
        if (!empty($remainder)) {
            $typeInfo = $this->parseDataType($remainder);

            if (!empty($typeInfo['type'])) {
                // Normalize SQLite types
                $column->setType($this->normalizeSQLiteType($typeInfo['type']));

                if (isset($typeInfo['length'])) {
                    $column->setLength($typeInfo['length']);
                }

                if (isset($typeInfo['precision'])) {
                    $column->setPrecision($typeInfo['precision']);
                }

                if (isset($typeInfo['scale'])) {
                    $column->setScale($typeInfo['scale']);
                }
            }

            // Parse modifiers
            $this->parseColumnModifiers($column, $typeInfo['remainder'], $table);
        }

        $table->addColumn($column);

        return $column;
    }

    /**
     * Normalize SQLite type to standard types
     */
    protected function normalizeSQLiteType(string $type): string
    {
        $type = strtoupper($type);

        // SQLite type affinity rules
        if (strpos($type, 'INT') !== false) {
            return 'integer';
        }

        if (
            strpos($type, 'CHAR') !== false ||
            strpos($type, 'CLOB') !== false ||
            strpos($type, 'TEXT') !== false
        ) {
            return 'text';
        }

        if (strpos($type, 'BLOB') !== false || empty($type)) {
            return 'blob';
        }

        if (
            strpos($type, 'REAL') !== false ||
            strpos($type, 'FLOA') !== false ||
            strpos($type, 'DOUB') !== false
        ) {
            return 'real';
        }

        if (
            $type === 'NUMERIC' ||
            strpos($type, 'DECIMAL') !== false ||
            strpos($type, 'BOOLEAN') !== false ||
            strpos($type, 'DATE') !== false ||
            strpos($type, 'DATETIME') !== false
        ) {
            return 'numeric';
        }

        // Default affinity
        return 'numeric';
    }

    /**
     * Parse data type from column definition
     */
    protected function parseDataType(string $definition): array
    {
        $result = ['remainder' => $definition];

        // No type specified (valid in SQLite)
        if (preg_match('/^(PRIMARY|UNIQUE|NOT|DEFAULT|CHECK|REFERENCES|COLLATE)/i', $definition)) {
            return $result;
        }

        // Type with precision/scale
        if (preg_match('/^(\w+)\s*\((\d+)\s*,\s*(\d+)\)(.*)$/i', $definition, $matches)) {
            $result['type'] = $matches[1];
            $result['precision'] = (int)$matches[2];
            $result['scale'] = (int)$matches[3];
            $result['remainder'] = $matches[4];
            return $result;
        }

        // Type with length
        if (preg_match('/^(\w+)\s*\((\d+)\)(.*)$/i', $definition, $matches)) {
            $result['type'] = $matches[1];
            $result['length'] = (int)$matches[2];
            $result['remainder'] = $matches[3];
            return $result;
        }

        // Simple type
        if (preg_match('/^(\w+)(.*)$/i', $definition, $matches)) {
            $result['type'] = $matches[1];
            $result['remainder'] = $matches[2];
            return $result;
        }

        return $result;
    }

    /**
     * Parse column modifiers
     */
    protected function parseColumnModifiers(Column $column, string $modifiers, Table $table): void
    {
        $modifiers = ' ' . trim($modifiers) . ' ';

        // PRIMARY KEY with optional AUTOINCREMENT
        if (preg_match('/\sPRIMARY\s+KEY(?:\s+AUTOINCREMENT)?\s/i', $modifiers, $matches)) {
            $column->setCustomOption('primary_key', true);

            // AUTOINCREMENT only valid with INTEGER PRIMARY KEY
            if (stripos($matches[0], 'AUTOINCREMENT') !== false) {
                if ($column->getType() === 'integer') {
                    $column->setAutoIncrement(true);
                } else {
                    // SQLite requires INTEGER type for AUTOINCREMENT
                    $column->setType('integer');
                    $column->setAutoIncrement(true);
                }
            }
        }

        // NOT NULL / NULL
        if (preg_match('/\sNOT\s+NULL\s/i', $modifiers)) {
            $column->setNullable(false);
        } elseif (preg_match('/\sNULL\s/i', $modifiers)) {
            $column->setNullable(true);
        }

        // UNIQUE
        if (preg_match('/\sUNIQUE\s/i', $modifiers)) {
            $column->setCustomOption('unique', true);
        }

        // DEFAULT value
        if (preg_match('/\sDEFAULT\s+([^\s]+)(?:\s|$)/i', $modifiers, $matches)) {
            $this->parseDefaultValue($column, $matches[1]);
        }

        // CHECK constraint
        if (preg_match('/\sCHECK\s*\(([^)]+)\)/i', $modifiers, $matches)) {
            // Create inline check constraint
            $constraint = new Constraint(
                $this->generateConstraintName($table, 'chk'),
                Constraint::TYPE_CHECK
            );
            $constraint->setExpression($matches[1]);
            $constraint->setColumns([$column->getName()]);
            $table->addConstraint($constraint);
        }

        // COLLATE
        if (preg_match('/\sCOLLATE\s+(\w+)/i', $modifiers, $matches)) {
            $column->setCustomOption('collation', $matches[1]);
        }

        // REFERENCES (inline foreign key)
        if (preg_match(
            '/\sREFERENCES\s+(?:"([^"]+)"|(\w+))(?:\s*\((?:"([^"]+)"|(\w+))\))?/i',
            $modifiers,
            $matches
        )) {

            $foreignTable = $matches[1] ?: $matches[2];
            $foreignColumn = null;

            if (!empty($matches[3]) || !empty($matches[4])) {
                $foreignColumn = $matches[3] ?: $matches[4];
            }

            // Create inline foreign key constraint
            $constraint = new Constraint(
                $this->generateConstraintName($table, 'fk'),
                Constraint::TYPE_FOREIGN_KEY
            );

            $constraint->setColumns([$column->getName()]);
            $constraint->setReferencedTable($foreignTable);

            if ($foreignColumn) {
                $constraint->setReferencedColumns([$foreignColumn]);
            }

            // Parse FK actions if present
            if (preg_match(
                '/ON\s+DELETE\s+(CASCADE|RESTRICT|SET\s+NULL|SET\s+DEFAULT|NO\s+ACTION)/i',
                $modifiers,
                $actionMatches
            )) {
                $action = str_replace(' ', '_', strtoupper($actionMatches[1]));
                $constraint->setOnDelete($action);
            }

            if (preg_match(
                '/ON\s+UPDATE\s+(CASCADE|RESTRICT|SET\s+NULL|SET\s+DEFAULT|NO\s+ACTION)/i',
                $modifiers,
                $actionMatches
            )) {
                $action = str_replace(' ', '_', strtoupper($actionMatches[1]));
                $constraint->setOnUpdate($action);
            }

            $table->addConstraint($constraint);
        }
    }

    /**
     * Parse default value
     */
    protected function parseDefaultValue(Column $column, string $default): void
    {
        // Remove surrounding quotes if present
        if (preg_match('/^[\'"](.*)[\'""]$/', $default, $matches)) {
            $column->setDefault($matches[1]);
            return;
        }

        // NULL
        if (strtoupper($default) === 'NULL') {
            $column->setDefault(null);
            return;
        }

        // Boolean values (stored as 0/1 in SQLite)
        if (strtoupper($default) === 'TRUE') {
            $column->setDefault(1);
            return;
        }

        if (strtoupper($default) === 'FALSE') {
            $column->setDefault(0);
            return;
        }

        // Special SQLite defaults
        if (in_array(strtoupper($default), ['CURRENT_TIME', 'CURRENT_DATE', 'CURRENT_TIMESTAMP'])) {
            $column->setDefault(strtoupper($default));
            return;
        }

        // Numeric or expression
        $column->setDefault($default);
    }

    /**
     * Parse table options
     */
    protected function parseTableOptions(Table $table, string $options): void
    {
        // WITHOUT ROWID
        if (preg_match('/WITHOUT\s+ROWID/i', $options)) {
            $table->setOption('without_rowid', true);
        }

        // STRICT (SQLite 3.37.0+)
        if (preg_match('/STRICT/i', $options)) {
            $table->setOption('strict', true);
        }
    }

    /**
     * Parse index definition
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

        // UNIQUE with or without name
        if (preg_match('/^UNIQUE\s+(?:INDEX\s+)?(?:"?(\w+)"?\s+)?\(([^)]+)\)/i', $definition, $matches)) {
            $name = $matches[1] ?: $this->generateIndexName($table, 'unique');
            $index = new Index($name, Index::TYPE_UNIQUE);
            $this->parseIndexColumns($index, $matches[2]);
            $table->addIndex($index);
            return $index;
        }

        throw new ParseException("Could not parse index definition: $definition");
    }

    /**
     * Parse index columns
     */
    protected function parseIndexColumns(Index $index, string $columnList): void
    {
        $columns = $this->splitDefinitions($columnList);

        foreach ($columns as $col) {
            $col = trim($col);

            // Remove quotes
            $col = $this->unquoteIdentifier($col);

            // Column with COLLATE
            if (preg_match('/^(\w+)\s+COLLATE\s+(\w+)/i', $col, $matches)) {
                $index->addColumn($matches[1]);
                $index->setOption('collate_' . $matches[1], $matches[2]);
            }
            // Column with direction
            elseif (preg_match('/^(\w+)\s+(ASC|DESC)/i', $col, $matches)) {
                $index->addColumn($matches[1], null, strtoupper($matches[2]));
            }
            // Simple column
            else {
                $index->addColumn($col);
            }
        }
    }

    /**
     * Parse constraint definition
     */
    public function parseConstraintDefinition(Table $table, string $definition): Constraint
    {
        // CONSTRAINT name ...
        if (preg_match('/^CONSTRAINT\s+(?:"([^"]+)"|(\w+))\s+(.+)$/i', $definition, $matches)) {
            $name = $matches[1] ?: $matches[2];
            return $this->parseNamedConstraint($table, $name, $matches[3]);
        }

        // CHECK
        if (preg_match('/^CHECK\s*\((.+)\)$/i', $definition, $matches)) {
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
            '/^FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+(?:"([^"]+)"|(\w+))\s*(?:\(([^)]+)\))?(.*)$/i',
            $definition,
            $matches
        )) {

            $localColumns = $this->parseColumnList($matches[1]);
            $foreignTable = $matches[2] ?: $matches[3];
            $foreignColumns = !empty($matches[4]) ? $this->parseColumnList($matches[4]) : [];
            $actions = $matches[5] ?? '';

            $constraint = new Constraint(
                $this->generateConstraintName($table, 'fk'),
                Constraint::TYPE_FOREIGN_KEY
            );

            $constraint->setColumns($localColumns);
            $constraint->setReferencedTable($foreignTable);

            if (!empty($foreignColumns)) {
                $constraint->setReferencedColumns($foreignColumns);
            }

            // Parse actions
            $this->parseForeignKeyActions($constraint, $actions);

            $table->addConstraint($constraint);
            return $constraint;
        }

        throw new ParseException("Could not parse constraint definition: $definition");
    }

    /**
     * Parse named constraint
     */
    protected function parseNamedConstraint(Table $table, string $name, string $definition): Constraint
    {
        // CHECK
        if (preg_match('/^CHECK\s*\((.+)\)$/i', $definition, $matches)) {
            $constraint = new Constraint($name, Constraint::TYPE_CHECK);
            $constraint->setExpression($matches[1]);
            $table->addConstraint($constraint);
            return $constraint;
        }

        // UNIQUE
        if (preg_match('/^UNIQUE\s*\(([^)]+)\)/i', $definition, $matches)) {
            $constraint = new Constraint($name, Constraint::TYPE_UNIQUE);
            $constraint->setColumns($this->parseColumnList($matches[1]));
            $table->addConstraint($constraint);
            return $constraint;
        }

        // FOREIGN KEY
        if (preg_match(
            '/^FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+(?:"([^"]+)"|(\w+))\s*(?:\(([^)]+)\))?(.*)$/i',
            $definition,
            $matches
        )) {

            $constraint = new Constraint($name, Constraint::TYPE_FOREIGN_KEY);

            $constraint->setColumns($this->parseColumnList($matches[1]));
            $constraint->setReferencedTable($matches[2] ?: $matches[3]);

            if (!empty($matches[4])) {
                $constraint->setReferencedColumns($this->parseColumnList($matches[4]));
            }

            // Parse actions
            if (!empty($matches[5])) {
                $this->parseForeignKeyActions($constraint, $matches[5]);
            }

            $table->addConstraint($constraint);
            return $constraint;
        }

        throw new ParseException("Could not parse named constraint: $definition");
    }

    /**
     * Parse column list
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
     * Parse foreign key actions
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

            // SQLite doesn't support SET DEFAULT
            if ($action === 'SET_DEFAULT') {
                $action = 'SET_NULL';
                // This warning should be added by the transformer
            }

            $constraint->setOnDelete($action);
        }

        // ON UPDATE
        if (preg_match(
            '/ON\s+UPDATE\s+(CASCADE|RESTRICT|SET\s+NULL|SET\s+DEFAULT|NO\s+ACTION)/i',
            $actions,
            $matches
        )) {
            $action = str_replace(' ', '_', strtoupper($matches[1]));

            // SQLite doesn't support SET DEFAULT
            if ($action === 'SET_DEFAULT') {
                $action = 'SET_NULL';
            }

            $constraint->setOnUpdate($action);
        }

        // DEFERRABLE (SQLite supports this)
        if (preg_match('/DEFERRABLE/i', $actions)) {
            $constraint->setOption('deferrable', true);

            if (preg_match('/INITIALLY\s+(DEFERRED|IMMEDIATE)/i', $actions, $matches)) {
                $constraint->setOption('initially', strtoupper($matches[1]));
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT Statement Parsing (NEW - SQLite Implementation)
    |--------------------------------------------------------------------------
    */

    /**
     * Parse INSERT data from SQLite INSERT statement
     * 
     * Handles SQLite-specific INSERT syntax including:
     * - INSERT INTO table VALUES (...)
     * - INSERT INTO table (columns) VALUES (...)
     * - INSERT OR IGNORE INTO table VALUES (...)
     * - INSERT OR REPLACE INTO table VALUES (...)
     * - Multi-row INSERT: VALUES (...), (...), (...)
     * - INSERT INTO table VALUES (...) ON CONFLICT ...
     *
     * @param string $statement The INSERT statement
     * @return array Array of row data (each row is associative array)
     * @throws ParseException If the INSERT statement cannot be parsed
     */
    protected function parseInsertData(string $statement): array
    {
        $statement = trim($statement);
        $rows = [];

        // Handle standard INSERT ... VALUES syntax with SQLite-specific conflict handling
        // Remove ON CONFLICT clause if present for parsing
        $cleanStatement = preg_replace('/\s+ON\s+CONFLICT\s+.*$/is', '', $statement);

        if (preg_match('/INSERT\s+(?:OR\s+(?:IGNORE|REPLACE|ABORT|FAIL|ROLLBACK)\s+)?INTO\s+(?:"([^"]+)"|(\w+))(?:\s*\(([^)]+)\))?\s+VALUES\s+(.+)$/is', $cleanStatement, $matches)) {
            $tableName = $matches[1] ?: $matches[2];
            $columnList = isset($matches[3]) ? $matches[3] : null;
            $valuesClause = $matches[4];

            // Parse column names if specified
            $columns = [];
            if ($columnList) {
                $columns = $this->parseColumnNamesFromInsert($columnList);
            }

            // Parse VALUES clause
            $rows = $this->parseValuesClause($valuesClause, $columns);

            if ($this->options['debug']) {
                error_log("SQLiteParser: Parsed INSERT for table '$tableName' - " . count($rows) . " rows");
            }

            return $rows;
        }

        // If we can't parse it, log and return empty
        if ($this->options['debug']) {
            error_log("SQLiteParser: Could not parse INSERT statement: " . substr($statement, 0, 100));
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
     * Parse VALUES clause with multiple rows (SQLite version)
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
     * Parse value sets from VALUES clause (SQLite version)
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
     * Parse individual value list from a value set (SQLite version)
     *
     * @param string $valueSet Comma-separated values
     * @return array Array of parsed values
     */
    private function parseValueList(string $valueSet): array
    {
        $values = [];
        $parts = $this->splitStringByDelimiter($valueSet, ',');

        foreach ($parts as $part) {
            $value = $this->parseSQLiteValueLiteral(trim($part));
            $values[] = $value;
        }

        return $values;
    }

    /**
     * Parse a single value literal (SQLite version)
     *
     * @param string $literal The value literal
     * @return mixed The parsed value
     */
    private function parseSQLiteValueLiteral(string $literal)
    {
        $literal = trim($literal);

        // NULL
        if (strtoupper($literal) === 'NULL') {
            return null;
        }

        // String literals (SQLite supports both single and double quotes)
        if (preg_match('/^\'(.*)\'$/s', $literal, $matches)) {
            // Single-quoted string - SQLite doubles single quotes for escaping
            return str_replace("''", "'", $matches[1]);
        }

        if (preg_match('/^"(.*)"$/s', $literal, $matches)) {
            // Double-quoted string in SQLite (also valid as identifier)
            return str_replace('""', '"', $matches[1]);
        }

        // Bracket-quoted identifiers (SQLite specific)
        if (preg_match('/^\[([^\]]*)\]$/s', $literal, $matches)) {
            return $matches[1];
        }

        // Numeric literals
        if (is_numeric($literal)) {
            // SQLite is flexible with numeric types
            if (strpos($literal, '.') !== false || stripos($literal, 'e') !== false) {
                return (float)$literal;
            } else {
                return (int)$literal;
            }
        }

        // Boolean literals (SQLite stores as 0/1)
        if (strtoupper($literal) === 'TRUE') {
            return 1; // SQLite stores true as 1
        }
        if (strtoupper($literal) === 'FALSE') {
            return 0; // SQLite stores false as 0
        }

        // BLOB literals (SQLite specific: X'hexstring' or x'hexstring')
        if (preg_match('/^[Xx]\'([0-9A-Fa-f]*)\'$/s', $literal, $matches)) {
            return hex2bin($matches[1]);
        }

        // SQLite date/time functions
        if (in_array(strtoupper($literal), ['CURRENT_TIME', 'CURRENT_DATE', 'CURRENT_TIMESTAMP'])) {
            return $literal; // Keep as function call
        }

        // SQLite functions (keep as string for now)
        if (preg_match('/^[A-Z_][A-Z0-9_]*\s*\(/i', $literal)) {
            return $literal; // Function call
        }

        // Cast expressions (SQLite: CAST(value AS type))
        if (preg_match('/^CAST\s*\(\s*(.+?)\s+AS\s+\w+\s*\)$/i', $literal, $matches)) {
            return $this->parseSQLiteValueLiteral($matches[1]);
        }

        // Default: return as string
        return $literal;
    }

    /**
     * Extract SQLite-specific conflict handling from INSERT statement
     *
     * @param string $statement The INSERT statement
     * @return string|null The conflict handling method (IGNORE, REPLACE, etc.)
     */
    private function extractSQLiteConflictHandling(string $statement): ?string
    {
        if (preg_match('/INSERT\s+OR\s+(IGNORE|REPLACE|ABORT|FAIL|ROLLBACK)\s+INTO/i', $statement, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/ON\s+CONFLICT\s+.*\s+DO\s+(NOTHING|UPDATE)/i', $statement, $matches)) {
            return strtoupper($matches[1]) === 'NOTHING' ? 'IGNORE' : 'UPDATE';
        }

        return null;
    }

    /**
     * Normalize SQLite value based on column affinity
     *
     * @param mixed $value The value to normalize
     * @param string $columnType The SQLite column type/affinity
     * @return mixed Normalized value
     */
    private function normalizeSQLiteValue($value, string $columnType = 'NUMERIC')
    {
        if ($value === null) {
            return null;
        }

        $affinity = $this->getSQLiteAffinity($columnType);

        switch ($affinity) {
            case 'INTEGER':
                return is_numeric($value) ? (int)$value : $value;

            case 'REAL':
                return is_numeric($value) ? (float)$value : $value;

            case 'TEXT':
                return (string)$value;

            case 'BLOB':
                return $value; // Keep as-is for BLOB

            case 'NUMERIC':
            default:
                // Try to convert to number, fallback to original value
                if (is_numeric($value)) {
                    return strpos($value, '.') !== false ? (float)$value : (int)$value;
                }
                return $value;
        }
    }

    /**
     * Determine SQLite type affinity from column type
     *
     * @param string $columnType The column type
     * @return string The SQLite affinity (INTEGER, REAL, TEXT, BLOB, NUMERIC)
     */
    private function getSQLiteAffinity(string $columnType): string
    {
        $type = strtoupper($columnType);

        // INTEGER affinity
        if (strpos($type, 'INT') !== false) {
            return 'INTEGER';
        }

        // TEXT affinity
        if (
            strpos($type, 'CHAR') !== false ||
            strpos($type, 'CLOB') !== false ||
            strpos($type, 'TEXT') !== false
        ) {
            return 'TEXT';
        }

        // BLOB affinity
        if (strpos($type, 'BLOB') !== false || $type === '') {
            return 'BLOB';
        }

        // REAL affinity
        if (
            strpos($type, 'REAL') !== false ||
            strpos($type, 'FLOA') !== false ||
            strpos($type, 'DOUB') !== false
        ) {
            return 'REAL';
        }

        // NUMERIC affinity (default)
        return 'NUMERIC';
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

    /**
     * Get parser name
     */
    public function getName(): string
    {
        return 'sqlite';
    }
}
