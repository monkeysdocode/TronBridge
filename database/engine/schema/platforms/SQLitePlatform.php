<?php

require_once __DIR__ . '/AbstractPlatform.php';

/**
 * Provides SQLite-specific SQL generation for database schema operations.
 *
 * This class translates schema definition objects (Table, Column, Index, Constraint)
 * into SQL statements compatible with SQLite. It includes enhanced handling for
 * AUTO_INCREMENT columns and their relationship with PRIMARY KEY constraints.
 *
 * @package Database\Platforms
 * @author Enhanced Model System
 * @version 2.1.0
 */
class SQLitePlatform extends AbstractPlatform
{
    protected string $identifierQuote = '"';

    /**
     * Gets the name of the database platform.
     *
     * @return string The platform name ('sqlite').
     */
    public function getName(): string
    {
        return 'sqlite';
    }

    // --------------------------------------------------------------------
    // SQL Generation Methods
    // --------------------------------------------------------------------

    /**
     * Generates the SQL fragment for a column definition.
     *
     * @param Column $column The column definition.
     * @param Table $table The table the column belongs to.
     * @return string The column definition SQL.
     */
    public function getColumnSQL(Column $column, Table $table): string
    {
        if ($this->isInlinePrimaryKey($column, $table)) {
            return $this->quoteIdentifier($column->getName()) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sql = $this->quoteIdentifier($column->getName()) . ' ' . $this->getColumnTypeSQL($column);

        if (!$column->isNullable()) {
            $sql .= ' NOT NULL';
        }

        $default = $this->getDefaultValueSQL($column);
        if ($default) {
            $sql .= $default;
        }

        return $sql;
    }

    /**
     * Generates the SQL for a table-level constraint.
     *
     * @param Constraint $constraint The constraint definition.
     * @return string The constraint SQL.
     * @throws \InvalidArgumentException If the constraint type is unsupported.
     */
    public function getConstraintSQL(Constraint $constraint): string
    {
        switch ($constraint->getType()) {
            case Constraint::TYPE_FOREIGN_KEY:
                return $this->getForeignKeySQL($constraint);
            case Constraint::TYPE_CHECK:
                return 'CHECK (' . $constraint->getExpression() . ')';
            case Constraint::TYPE_UNIQUE:
                $columns = array_map([$this, 'quoteIdentifier'], $constraint->getColumns());
                return 'UNIQUE (' . implode(', ', $columns) . ')';
            case Constraint::TYPE_PRIMARY_KEY:
                $columns = array_map([$this, 'quoteIdentifier'], $constraint->getColumns());
                return 'PRIMARY KEY (' . implode(', ', $columns) . ')';
            default:
                throw new \InvalidArgumentException("Unsupported constraint type: " . $constraint->getType());
        }
    }

    /**
     * Generates the SQL for a foreign key constraint.
     *
     * @param Constraint $constraint The foreign key constraint definition.
     * @return string The FOREIGN KEY constraint SQL.
     */
    public function getForeignKeySQL(Constraint $constraint): string
    {
        $sql = 'FOREIGN KEY (' . implode(', ', array_map([$this, 'quoteIdentifier'], $constraint->getColumns())) . ')';
        $sql .= ' REFERENCES ' . $this->quoteIdentifier($constraint->getReferencedTable());
        $sql .= ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $constraint->getReferencedColumns())) . ')';

        if ($constraint->getOnDelete()) {
            $sql .= ' ON DELETE ' . $constraint->getOnDelete();
        }
        if ($constraint->getOnUpdate()) {
            $sql .= ' ON UPDATE ' . $constraint->getOnUpdate();
        }

        return $sql;
    }

    /**
     * Generates the SQL for an index.
     *
     * @param Index $index The index definition.
     * @return string The CREATE INDEX SQL statement (or an empty string if handled in CREATE TABLE).
     */
    public function getIndexSQL(Index $index): string
    {
        // In SQLite, all indexes except regular indexes are defined in CREATE TABLE.
        if ($index->isPrimary() || $index->isUnique()) {
            return '';
        }

        return $this->getCreateIndexSQL($index->getTable()->getName(), $index);
    }

    /**
     * Generates the SQL to create a new index.
     *
     * @param string $tableName The name of the table.
     * @param Index $index The index definition.
     * @return string The CREATE INDEX SQL statement.
     */
    public function getCreateIndexSQL(string $tableName, Index $index): string
    {
        if ($index->isPrimary() || $index->isFulltext()) {
            return ''; // Handled in CREATE TABLE or not supported directly
        }

        $columns = array_map([$this, 'quoteIdentifier'], $index->getColumnNames());
        $sql = $index->isUnique() ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
        
        return sprintf('%s %s ON %s (%s)',
            $sql,
            $this->quoteIdentifier($index->getName()),
            $this->quoteIdentifier($tableName),
            implode(', ', $columns)
        );
    }

    /**
     * Generates the SQL for a column's data type.
     *
     * @param Column $column The column definition.
     * @return string The column type SQL.
     */
    public function getColumnTypeSQL(Column $column): string
    {
        $type = strtolower($column->getType());
        $mapping = $this->getTypeMapping();
        return $mapping[$type] ?? 'TEXT';
    }

    /**
     * Generates the SQL for a column's default value.
     *
     * @param Column $column The column definition.
     * @return string The DEFAULT clause SQL.
     */
    public function getDefaultValueSQL(Column $column): string
    {
        $default = $column->getDefault();
        if ($default === null) {
            return '';
        }

        if (is_string($default)) {
            if (strtoupper($default) === 'CURRENT_TIMESTAMP') {
                return ' DEFAULT CURRENT_TIMESTAMP';
            }
            if (strtoupper($default) === 'NULL') {
                return ' DEFAULT NULL';
            }
            return ' DEFAULT ' . $this->quoteValue($default);
        }

        return ' DEFAULT ' . (is_bool($default) ? (int)$default : $default);
    }

    /**
     * Generates the SQL to drop a table.
     *
     * @param string $tableName The name of the table to drop.
     * @return string The DROP TABLE SQL statement.
     */
    public function getDropTableSQL(string $tableName): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($tableName);
    }

    /**
     * Generates the SQL to drop an index.
     *
     * @param string $indexName The name of the index to drop.
     * @param string $tableName The name of the table the index belongs to.
     * @return string The DROP INDEX SQL statement.
     */
    public function getDropIndexSQL(string $indexName, string $tableName): string
    {
        return 'DROP INDEX IF EXISTS ' . $this->quoteIdentifier($indexName);
    }

    /**
     * Generates the SQL to truncate a table.
     *
     * @param string $tableName The name of the table to truncate.
     * @return string The TRUNCATE TABLE SQL statement.
     */
    public function getTruncateTableSQL(string $tableName): string
    {
        return 'DELETE FROM ' . $this->quoteIdentifier($tableName);
    }

    // --------------------------------------------------------------------
    // Platform Support Methods
    // --------------------------------------------------------------------

    /**
     * Checks if the platform supports foreign key constraints.
     *
     * @return bool True, as SQLite supports foreign keys (with PRAGMA). 
     */
    public function supportsForeignKeys(): bool
    {
        return true;
    }

    /**
     * Checks if the platform supports specifying index length.
     *
     * @return bool False, as SQLite does not support index length.
     */
    public function supportsIndexLength(): bool
    {
        return false;
    }

    /**
     * Checks if the platform supports Full-Text Search (FTS).
     *
     * @return bool True, as modern SQLite builds include FTS5.
     */
    public function supportsFTS(): bool
    {
        return true;
    }

    // --------------------------------------------------------------------
    // Type Mapping
    // --------------------------------------------------------------------

    /**
     * Gets the platform-specific type mapping.
     *
     * @return array The mapping of generic types to SQLite-specific types.
     */
    public function getTypeMapping(): array
    {
        return [
            'tinyint' => 'INTEGER', 'smallint' => 'INTEGER', 'mediumint' => 'INTEGER',
            'int' => 'INTEGER', 'integer' => 'INTEGER', 'bigint' => 'INTEGER',
            'boolean' => 'INTEGER', 'bool' => 'INTEGER',
            'float' => 'REAL', 'double' => 'REAL', 'decimal' => 'REAL',
            'numeric' => 'REAL', 'real' => 'REAL',
            'char' => 'TEXT', 'varchar' => 'TEXT', 'text' => 'TEXT',
            'tinytext' => 'TEXT', 'mediumtext' => 'TEXT', 'longtext' => 'TEXT',
            'enum' => 'TEXT', 'set' => 'TEXT',
            'date' => 'TEXT', 'datetime' => 'TEXT', 'timestamp' => 'TEXT',
            'time' => 'TEXT', 'year' => 'TEXT',
            'json' => 'TEXT', 'jsonb' => 'TEXT', 'uuid' => 'TEXT',
            'binary' => 'BLOB', 'varbinary' => 'BLOB', 'blob' => 'BLOB',
            'tinyblob' => 'BLOB', 'mediumblob' => 'BLOB', 'longblob' => 'BLOB',
            'bytea' => 'BLOB',
        ];
    }

    // --------------------------------------------------------------------
    // Helper Methods
    // --------------------------------------------------------------------

    /**
     * Checks if a column represents an inline primary key in SQLite.
     *
     * @param Column $column The column to check.
     * @param Table $table The table the column belongs to.
     * @return bool True if it is an inline primary key.
     */
    private function isInlinePrimaryKey(Column $column, Table $table): bool
    {
        if (!$column->isAutoIncrement()) {
            return false;
        }

        $primaryKey = $table->getPrimaryKey();
        if (!$primaryKey) {
            return false;
        }

        $pkColumns = $primaryKey->getColumnNames();
        return count($pkColumns) === 1 && $pkColumns[0] === $column->getName();
    }

    /**
     * Gets the SQL for all table-level constraints.
     *
     * @param Table $table The table definition.
     * @return array An array of constraint SQL strings.
     */
    private function getTableConstraintSQL(Table $table): array
    {
        $constraints = [];
        $primaryKey = $table->getPrimaryKey();

        if ($primaryKey && !$this->isPrimaryKeyHandledInline($table)) {
            $constraints[] = '    ' . $this->getConstraintSQL(Constraint::createFromIndex($primaryKey)) . ' -- primary key not handled inline';
        }

        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique() && !$index->isPrimary()) {
                $constraints[] = '    ' . $this->getConstraintSQL(Constraint::createFromIndex($index));
            }
        }

        if ($this->supportsForeignKeys()) {
            foreach ($table->getForeignKeys() as $fk) {
                $constraints[] = '    ' . $this->getForeignKeySQL($fk);
            }
        }

        foreach ($table->getConstraints() as $constraint) {
            if ($constraint->isCheck()) {
                $constraints[] = '    ' . $this->getConstraintSQL($constraint);
            }
        }

        return $constraints;
    }

    /**
     * Checks if the primary key is handled inline within a column definition.
     *
     * @param Table $table The table definition.
     * @return bool True if the primary key is handled inline.
     */
    private function isPrimaryKeyHandledInline(Table $table): bool
    {
        $primaryKey = $table->getPrimaryKey();
        if (!$primaryKey || count($primaryKey->getColumnNames()) !== 1) {
            return false;
        }

        $column = $table->getColumn($primaryKey->getColumnNames()[0]);
        return $column && $column->isAutoIncrement();
    }
}