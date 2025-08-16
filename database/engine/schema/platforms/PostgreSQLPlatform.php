<?php

require_once __DIR__ . '/AbstractPlatform.php';

/**
 * PostgreSQL Database Platform
 *
 * Generates PostgreSQL-compatible SQL statements from schema objects,
 * supporting PostgreSQL-specific features like SERIAL, schemas, and comments.
 *
 * @package Database\Platforms
 */
class PostgreSQLPlatform extends AbstractPlatform
{
    /**
     * The quote character for identifiers in PostgreSQL.
     *
     * @var string
     */
    protected string $identifierQuote = '"';

    /*
    |--------------------------------------------------------------------------
    | Platform Information
    |--------------------------------------------------------------------------
    */

    /**
     * Get the name of the platform.
     *
     * @return string The name of the platform (e.g., 'postgresql').
     */
    public function getName(): string
    {
        return 'postgresql';
    }

    /*
    |--------------------------------------------------------------------------
    | DDL Generation
    |--------------------------------------------------------------------------
    */


    /**
     * Generate the SQL for creating a new index.
     * In PostgreSQL, most indexes are created with a separate statement.
     *
     * @param string $tableName The name of the table.
     * @param Index $index The index object.
     * @return string The SQL statement for creating the index.
     */
    public function getCreateIndexSQL(string $tableName, Index $index): string
    {
        if ($index->isPrimary() || $index->isUnique()) {
            // Primary and unique constraints are handled within CREATE TABLE.
            return '';
        }

        $indexName = $this->quoteIdentifier($index->getName());
        $tableName = $this->quoteIdentifier($tableName);
        $sql = "CREATE INDEX {$indexName} ON {$tableName}";

        // Handle specific index methods like GIN with expressions
        if ($index->getMethod() === 'gin' && $index->getExpression()) {
            $sql .= " USING gin(" . $index->getExpression() . ")";
            return $sql;
        }

        // Standard index creation
        $algorithm = $index->getAlgorithm() ?: 'btree';
        $sql .= " USING {$algorithm}";

        $columns = [];
        foreach ($index->getColumns() as $col) {
            if (is_array($col)) {
                $colStr = $this->quoteIdentifier($col['name']);
                if (isset($col['direction'])) {
                    $colStr .= ' ' . $col['direction'];
                }
                $columns[] = $colStr;
            } else {
                $columns[] = $this->quoteIdentifier($col);
            }
        }

        $sql .= ' (' . implode(', ', $columns) . ')';

        // Add WHERE clause for partial indexes
        if ($where = $index->getWhere()) {
            $sql .= " WHERE {$where}";
        }

        return $sql;
    }

    /**
     * Generate COMMENT ON statements for a table and its columns.
     *
     * @param Table $table The table object.
     * @return array An array of SQL COMMENT statements.
     */
    public function getCommentStatements(Table $table): array
    {
        $statements = [];
        $tableName = $this->quoteIdentifier($table->getName());

        // Table comment
        if ($comment = $table->getComment()) {
            $statements[] = "COMMENT ON TABLE {$tableName} IS " . $this->quoteValue($comment) . ';';
        }

        // Column comments
        foreach ($table->getColumns() as $column) {
            if ($comment = $column->getComment()) {
                $columnName = $this->quoteIdentifier($column->getName());
                $statements[] = "COMMENT ON COLUMN {$tableName}.{$columnName} IS " . $this->quoteValue($comment) . ';';
            }
        }

        return $statements;
    }

    /*
    |--------------------------------------------------------------------------
    | SQL Snippet Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Generate the SQL for a single column definition.
     *
     * @param Column $column The column object.
     * @param Table $table The table object.
     * @return string The SQL snippet for the column.
     */
    public function getColumnSQL(Column $column, Table $table): string
    {
        $columnName = $this->quoteIdentifier($column->getName());
        $columnType = $this->getColumnTypeSQL($column);
        
        $sql = "{$columnName} {$columnType}";

        // NULL / NOT NULL
        $sql .= $this->getNullableSQL($column, $table);

        // DEFAULT
        if ($defaultSQL = $this->getDefaultValueSQL($column)) {
            $sql .= $defaultSQL;
        }
        
        // GENERATED column
        if ($generatedSQL = $this->getGeneratedColumnSQL($column)) {
            $sql .= $generatedSQL;
        }

        return $sql;
    }

    /**
     * Generate the SQL for a column's data type.
     *
     * @param Column $column The column object.
     * @return string The SQL snippet for the column's type.
     */
    public function getColumnTypeSQL(Column $column): string
    {
        // Handle SERIAL types first
        if ($column->isAutoIncrement()) {
            switch (strtolower($column->getType())) {
                case 'smallint':
                case 'smallserial':
                    return 'SMALLSERIAL';
                case 'bigint':
                case 'bigserial':
                    return 'BIGSERIAL';
                case 'serial':
                default:
                    return 'SERIAL';
            }
        }

        $type = $this->mapType($column->getType());

        // Handle array types by appending '[]'
        if ($column->getCustomOption('is_array')) {
            return $type . '[]';
        }

        // Add length/precision for applicable types
        if ($column->getLength() && $this->typeRequiresLength($type)) {
            $type .= '(' . $column->getLength() . ')';
        } elseif ($column->getPrecision() && $this->typeSupportsScale($type)) {
            $type .= '(' . $column->getPrecision();
            if ($column->getScale() !== null) {
                $type .= ', ' . $column->getScale();
            }
            $type .= ')';
        }

        return $type;
    }

    /**
     * Generate the SQL for a constraint.
     *
     * @param Constraint $constraint The constraint object.
     * @return string The SQL snippet for the constraint.
     */
    public function getConstraintSQL(Constraint $constraint): string
    {
        $constraintName = $this->quoteIdentifier($constraint->getName());
        $columns = implode(', ', array_map([$this, 'quoteIdentifier'], $constraint->getColumns()));

        switch ($constraint->getType()) {
            case Constraint::TYPE_PRIMARY_KEY:
                $pkName = $constraint->getName() === 'PRIMARY' && $constraint->getTable()
                    ? $constraint->getTable()->getName() . '_pkey' 
                    : $constraint->getName();
                return "CONSTRAINT " . $this->quoteIdentifier($pkName) . " PRIMARY KEY ({$columns})";

            case Constraint::TYPE_FOREIGN_KEY:
                return $this->getForeignKeySQL($constraint);

            case Constraint::TYPE_UNIQUE:
                return "CONSTRAINT {$constraintName} UNIQUE ({$columns})";

            case Constraint::TYPE_CHECK:
                return "CONSTRAINT {$constraintName} CHECK (" . $constraint->getExpression() . ")";

            default:
                return '';
        }
    }
    
    /**
     * Generate the SQL for a foreign key constraint.
     *
     * @param Constraint $constraint The foreign key constraint object.
     * @return string The SQL snippet for the foreign key.
     */
    public function getForeignKeySQL(Constraint $constraint): string
    {
        $constraintName = $this->quoteIdentifier($constraint->getName());
        $columns = implode(', ', array_map([$this, 'quoteIdentifier'], $constraint->getColumns()));
        $referencedTable = $this->quoteIdentifier($constraint->getReferencedTable());
        $referencedColumns = implode(', ', array_map([$this, 'quoteIdentifier'], $constraint->getReferencedColumns()));

        $sql = "CONSTRAINT {$constraintName} FOREIGN KEY ({$columns}) REFERENCES {$referencedTable} ({$referencedColumns})";

        if ($onDelete = $constraint->getOnDelete()) {
            $sql .= " ON DELETE {$onDelete}";
        }
        if ($onUpdate = $constraint->getOnUpdate()) {
            $sql .= " ON UPDATE {$onUpdate}";
        }

        return $sql;
    }

    /**
     * Generate the SQL for an index.
     *
     * @param Index $index The index object.
     * @return string The SQL snippet for the index.
     */
    public function getIndexSQL(Index $index): string
    {
        // This method is not used for inline index creation in PostgreSQL
        return '';
    }

    /**
     * Generate the SQL for a generated column expression.
     *
     * @param Column $column The column object.
     * @return string The SQL snippet for the generated column.
     */
    public function getGeneratedColumnSQL(Column $column): string
    {
        if (!$column->isGenerated() || !$column->getGeneratedExpression()) {
            return '';
        }
        return ' GENERATED ALWAYS AS (' . $column->getGeneratedExpression() . ') STORED';
    }

    /**
     * Generate the SQL for a column's default value.
     * Overrides parent to handle PostgreSQL-specific array syntax.
     *
     * @param Column $column The column object.
     * @return string The SQL snippet for the default value.
     */
    public function getDefaultValueSQL(Column $column): string
    {
        $default = $column->getDefault();

        if ($default === null) {
            return '';
        }

        // Handle PostgreSQL arrays
        if ($column->getCustomOption('is_array') && !empty($default)) {
            return $this->renderArrayDefault($default);
        }

        // Use parent logic for non-array columns
        return parent::getDefaultValueSQL($column);
    }

    /**
     * Renders a default value as a PostgreSQL array literal.
     *
     * @param mixed $value The value to render.
     * @return string The array literal (e.g., "ARRAY['foo','bar']").
     */
    public function renderArrayDefault($value): string
    {
        if (is_array($value)) {
            $items = array_map(function($item) {
                return "'" . str_replace("'", "''", $item) . "'";
            }, $value);
            return "ARRAY[" . implode(',', $items) . "]";
        }
        
        if (is_string($value) && str_starts_with(trim($value), 'ARRAY[')) {
            return trim($value);
        }

        if (is_string($value)) {
            $cleanValue = trim($value, " '\t\n\r\0\x0B");
            return "ARRAY['" . str_replace("'", "''", $cleanValue) . "']";
        }

        return 'NULL';
    }
    
    /**
     * Get the name of the sequence for a SERIAL column.
     *
     * @param string $tableName The name of the table.
     * @param string $columnName The name of the column.
     * @return string The conventional sequence name.
     */
    public function getSequenceName(string $tableName, string $columnName): string
    {
        return $tableName . '_' . $columnName . '_seq';
    }

    /*
    |--------------------------------------------------------------------------
    | Type System
    |--------------------------------------------------------------------------
    */

    /**
     * Get the mapping of abstract types to platform-specific types.
     *
     * @return array The type mapping.
     */
    public function getTypeMapping(): array
    {
        return [
            'tinyint' => 'SMALLINT',
            'smallint' => 'SMALLINT',
            'mediumint' => 'INTEGER',
            'int' => 'INTEGER',
            'integer' => 'INTEGER',
            'bigint' => 'BIGINT',
            'decimal' => 'NUMERIC',
            'numeric' => 'NUMERIC',
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'real' => 'REAL',
            'char' => 'CHARACTER',
            'varchar' => 'CHARACTER VARYING',
            'text' => 'TEXT',
            'tinytext' => 'TEXT',
            'mediumtext' => 'TEXT',
            'longtext' => 'TEXT',
            'binary' => 'BYTEA',
            'varbinary' => 'BYTEA',
            'blob' => 'BYTEA',
            'tinyblob' => 'BYTEA',
            'mediumblob' => 'BYTEA',
            'longblob' => 'BYTEA',
            'date' => 'DATE',
            'time' => 'TIME',
            'datetime' => 'TIMESTAMP',
            'timestamp' => 'TIMESTAMP',
            'year' => 'SMALLINT',
            'enum' => 'TEXT',
            'set' => 'TEXT[]',
            'json' => 'JSONB',
            'jsonb' => 'JSONB',
            'boolean' => 'BOOLEAN',
            'bool' => 'BOOLEAN',
            'uuid' => 'UUID',
            'serial' => 'SERIAL',
            'bigserial' => 'BIGSERIAL',
            'smallserial' => 'SMALLSERIAL',
            // Internal array type mappings
            '_text' => 'TEXT',
            '_varchar' => 'CHARACTER VARYING',
            '_int4' => 'INTEGER',
            '_int8' => 'BIGINT',
            '_numeric' => 'NUMERIC'
        ];
    }

    /**
     * Check if a data type requires a length specification.
     *
     * @param string $type The data type.
     * @return bool True if the type requires a length.
     */
    protected function typeRequiresLength(string $type): bool
    {
        $typesWithLength = [
            'character varying',
            'character',
            'varchar',
            'char'
        ];
        return in_array(strtolower($type), $typesWithLength, true);
    }

    /**
     * Check if a data type supports precision and scale.
     *
     * @param string $type The data type.
     * @return bool True if the type supports scale.
     */
    protected function typeSupportsScale(string $type): bool
    {
        $typesWithScale = [
            'numeric',
            'decimal'
        ];
        return in_array(strtolower($type), $typesWithScale, true);
    }

    /*
    |--------------------------------------------------------------------------
    | Feature Support
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the platform supports ENUM types directly.
     * PostgreSQL supports them via CREATE TYPE, which is handled separately.
     *
     * @return bool False, as ENUMs are converted to TEXT by default.
     */
    public function supportsEnumTypes(): bool
    {
        return false;
    }

    /**
     * Check if the platform supports foreign key constraints.
     *
     * @return bool True.
     */
    public function supportsForeignKeys(): bool
    {
        return true;
    }

    /**
     * Check if the platform supports full-text indexes in the standard way.
     *
     * @return bool False, as PostgreSQL has a specialized full-text search system.
     */
    public function supportsFulltextIndexes(): bool
    {
        return false;
    }

    /**
     * Check if the platform supports inline column comments in CREATE TABLE.
     *
     * @param Column $column The column object.
     * @return bool False, as PostgreSQL uses COMMENT ON statements.
     */
    public function supportsColumnComments(): bool
    {
        return false;
    }
}