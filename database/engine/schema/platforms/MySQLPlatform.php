<?php

require_once __DIR__ . '/AbstractPlatform.php';

/**
 * MySQL Database Platform
 *
 * Generates MySQL-compatible SQL statements from schema objects,
 * supporting features like engines, charsets, and inline indexing.
 *
 * @package Database\Platforms
 */
class MySQLPlatform extends AbstractPlatform
{
    /**
     * The quote character for identifiers in MySQL.
     *
     * @var string
     */
    protected string $identifierQuote = '`';

    /*
    |--------------------------------------------------------------------------
    | Platform Information
    |--------------------------------------------------------------------------
    */

    /**
     * Get the name of the platform.
     *
     * @return string The name of the platform (e.g., 'mysql').
     */
    public function getName(): string
    {
        return 'mysql';
    }

    /*
    |--------------------------------------------------------------------------
    | DDL Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Generate the SQL for an index definition (used within CREATE TABLE).
     *
     * @param Index $index The index object.
     * @return string The SQL snippet for the index.
     */
    public function getIndexSQL(Index $index): string
    {
        $indexName = $this->quoteIdentifier($index->getName());
        $columns = implode(', ', array_map([$this, 'quoteIdentifier'], $index->getColumnNames()));
        $typeClause = $this->getIndexTypeClause($index);

        if ($index->isUnique()) {
            return "UNIQUE KEY {$indexName} ({$columns}){$typeClause}";
        }
        if ($index->getType() === 'fulltext') {
            return "FULLTEXT KEY {$indexName} ({$columns})";
        }
        if ($index->getType() === 'spatial') {
            return "SPATIAL KEY {$indexName} ({$columns})";
        }
        
        return "KEY {$indexName} ({$columns}){$typeClause}";
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
        $attributes = $this->getColumnAttributesSQL($column, $table);

        return trim("{$columnName} {$columnType}{$attributes}");
    }

    /**
     * Generate the SQL for a column's data type.
     *
     * @param Column $column The column object.
     * @return string The SQL snippet for the column's type.
     */
    public function getColumnTypeSQL(Column $column): string
    {
        $type = strtolower($column->getType());

        switch ($type) {
            case 'enum':
            case 'set':
                return $this->getEnumSetSQL($column);

            case 'varchar':
            case 'char':
            case 'binary':
            case 'varbinary':
            case 'bit':
                return $this->getTypeWithLength($type, $column->getLength());

            case 'decimal':
            case 'numeric':
            case 'float':
            case 'double':
                return $this->getTypeWithPrecisionAndScale($type, $column->getPrecision(), $column->getScale());

            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'integer':
            case 'bigint':
                return $this->getIntegerTypeSQL($type, $column);

            case 'time':
            case 'datetime':
            case 'timestamp':
                return $this->getTypeWithPrecision($type, $column->getPrecision());

            default:
                return strtoupper($type);
        }
    }

    /**
     * Generate the SQL for a column's attributes (NULL, DEFAULT, etc.).
     *
     * @param Column $column The column object.
     * @param Table $table The table object.
     * @return string The SQL snippet for the column attributes.
     */
    protected function getColumnAttributesSQL(Column $column, Table $table): string
    {
        $attributes = [];

        if ($charset = $column->getCustomOption('charset')) {
            $attributes[] = "CHARACTER SET {$charset}";
        }
        if ($collation = $column->getCustomOption('collation')) {
            $attributes[] = "COLLATE {$collation}";
        }

        $attributes[] = $this->getNullableSQL($column, $table);

        if ($column->getDefault() !== null) {
            $attributes[] = $this->getDefaultValueSQL($column);
        }

        if ($onUpdate = $column->getCustomOption('on_update')) {
            $attributes[] = "ON UPDATE {$onUpdate}";
        }

        if ($column->isAutoIncrement()) {
            $attributes[] = 'AUTO_INCREMENT';
        }

        if ($comment = $column->getComment()) {
            $attributes[] = 'COMMENT ' . $this->quoteValue($comment);
        }

        return empty($attributes) ? '' : ' ' . implode(' ', array_filter($attributes));
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
            case Constraint::TYPE_FOREIGN_KEY:
                return $this->getForeignKeySQL($constraint);

            case Constraint::TYPE_UNIQUE:
                return "CONSTRAINT {$constraintName} UNIQUE ({$columns})";

            case Constraint::TYPE_CHECK:
                return "CONSTRAINT {$constraintName} CHECK (" . $constraint->getExpression() . ")";

            default:
                throw new InvalidArgumentException("Unsupported constraint type: " . $constraint->getType());
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

        if ($onUpdate = $constraint->getOnUpdate()) {
            $sql .= " ON UPDATE {$onUpdate}";
        }
        if ($onDelete = $constraint->getOnDelete()) {
            $sql .= " ON DELETE {$onDelete}";
        }

        return $sql;
    }

    /*
    |--------------------------------------------------------------------------
    | Type System Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Generate SQL for ENUM and SET types.
     *
     * @param Column $column The column object.
     * @return string The SQL for the ENUM or SET type.
     */
    protected function getEnumSetSQL(Column $column): string
    {
        $type = strtoupper($column->getType());
        $values = ($type === 'ENUM') ? $column->getEnumValues() : $column->getCustomOption('set_values', []);

        if (empty($values)) {
            throw new InvalidArgumentException("{$type} column '" . $column->getName() . "' must have values.");
        }

        $quotedValues = implode(', ', array_map([$this, 'quoteValue'], $values));
        return "{$type}({$quotedValues})";
    }

    /**
     * Generate SQL for integer types, including UNSIGNED and ZEROFILL.
     *
     * @param string $type The integer type (e.g., 'int', 'bigint').
     * @param Column $column The column object.
     * @return string The SQL for the integer type.
     */
    protected function getIntegerTypeSQL(string $type, Column $column): string
    {
        $sql = strtoupper($type);
        if ($length = $column->getLength()) {
            $sql .= "({$length})";
        }
        if ($column->isUnsigned() || $column->getCustomOption('unsigned')) {
            $sql .= ' UNSIGNED';
        }
        if ($column->getCustomOption('zerofill')) {
            $sql .= ' ZEROFILL';
        }
        return $sql;
    }

    /**
     * Generate SQL for types with a length attribute.
     *
     * @param string $type The data type.
     * @param int|null $length The length.
     * @return string The SQL for the type with length.
     */
    protected function getTypeWithLength(string $type, ?int $length): string
    {
        $sql = strtoupper($type);
        if ($length) {
            $sql .= "({$length})";
        }
        return $sql;
    }

    /**
     * Generate SQL for types with precision and scale.
     *
     * @param string $type The data type.
     * @param int|null $precision The precision.
     * @param int|null $scale The scale.
     * @return string The SQL for the type with precision and scale.
     */
    protected function getTypeWithPrecisionAndScale(string $type, ?int $precision, ?int $scale): string
    {
        $sql = strtoupper($type);
        if ($precision !== null && $scale !== null) {
            $sql .= "({$precision}, {$scale})";
        } elseif ($precision !== null) {
            $sql .= "({$precision})";
        }
        return $sql;
    }

    /**
     * Generate SQL for types with optional precision.
     *
     * @param string $type The data type.
     * @param int|null $precision The precision.
     * @return string The SQL for the type with precision.
     */
    protected function getTypeWithPrecision(string $type, ?int $precision): string
    {
        $sql = strtoupper($type);
        if ($precision) {
            $sql .= "({$precision})";
        }
        return $sql;
    }

    /**
     * Get the mapping of abstract types to platform-specific types.
     *
     * @return array The type mapping.
     */
    public function getTypeMapping(): array
    {
        return [
            'string' => 'VARCHAR',
            'text' => 'TEXT',
            'integer' => 'INT',
            'bigint' => 'BIGINT',
            'smallint' => 'SMALLINT',
            'decimal' => 'DECIMAL',
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'boolean' => 'TINYINT(1)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'time' => 'TIME',
            'binary' => 'BINARY',
            'varbinary' => 'VARBINARY',
            'blob' => 'BLOB',
            'json' => 'JSON',
            'uuid' => 'CHAR(36)',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Feature Support
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the platform supports ENUM types directly.
     *
     * @return bool True.
     */
    public function supportsEnumTypes(): bool
    {
        return true;
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
     * Check if the platform supports full-text indexes.
     *
     * @return bool True.
     */
    public function supportsFulltextIndexes(): bool
    {
        return true;
    }

    /**
     * Check if the platform supports inline column comments.
     *
     * @return bool True.
     */
    public function supportsColumnComments(): bool
    {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Render table options (ENGINE, CHARSET, etc.) into a SQL string.
     *
     * @param Table $table The table object.
     * @return string The SQL snippet for table options.
     */
    protected function renderTableOptions(Table $table): string
    {
        $options = [];

        if ($engine = $table->getEngine()) {
            $options[] = "ENGINE={$engine}";
        }
        if ($charset = $table->getCharset()) {
            $options[] = "DEFAULT CHARSET={$charset}";
        }
        if ($collation = $table->getCollation()) {
            $options[] = "COLLATE={$collation}";
        }
        if ($autoIncrement = $table->getOption('auto_increment_start')) {
            $options[] = "AUTO_INCREMENT={$autoIncrement}";
        }
        if ($comment = $table->getComment()) {
            $options[] = 'COMMENT=' . $this->quoteValue($comment);
        }

        return implode(' ', $options);
    }

    /**
     * Get the index type clause (e.g., USING BTREE) for an index definition.
     *
     * @param Index $index The index object.
     * @return string The SQL snippet for the index type.
     */
    protected function getIndexTypeClause(Index $index): string
    {
        if ($method = $index->getMethod()) {
            if (!in_array($index->getType(), ['fulltext', 'spatial'])) {
                return " USING {$method}";
            }
        }
        return '';
    }
}