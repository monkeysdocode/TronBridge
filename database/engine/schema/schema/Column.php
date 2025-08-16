<?php

/**
 * Represents a database column, providing a database-agnostic representation of its definition.
 *
 * This class is used to define the properties of a column, such as its name, type, length,
 * default value, and whether it is nullable. It supports various data types and
 * provides methods for checking the column's characteristics.
 *
 * @package Database\Schema
 * @author Enhanced Model System
 * @version 1.0.0
 */
class Column
{
    private string $name;
    private string $type;
    private ?string $originalType = null;
    private ?int $length = null;
    private ?int $precision = null;
    private ?int $scale = null;
    private bool $nullable = true;
    private bool $autoIncrement = false;
    private bool $unsigned = false;
    private mixed $default = null;
    private ?string $comment = null;
    private array $enumValues = [];
    private array $customOptions = [];
    private ?Table $table = null;
    private bool $generated = false;
    private ?string $generatedExpression = null;
    private string $generatedStorageType = 'stored';
    private ?string $after = null;
    private bool $first = false;
    private ?string $originalDefinition = null;

    /**
     * Constructs a new Column instance.
     *
     * @param string $name The name of the column.
     * @param string $type The data type of the column.
     */
    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = strtolower($type);
    }

    // --------------------------------------------------------------------
    // Getters
    // --------------------------------------------------------------------

    /**
     * Gets the name of the column.
     *
     * @return string The column name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the fully qualified name of the column (e.g., `table`.`column`).
     *
     * @return string The fully qualified column name.
     */
    public function getFullyQualifiedName(): string
    {
        $tableName = $this->getTableName();
        return $tableName ? "`{$tableName}`.`{$this->name}`" : "`{$this->name}`";
    }

    /**
     * Gets the data type of the column.
     *
     * @return string The column data type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Gets the original, platform-specific data type of the column.
     *
     * @return string|null The original data type, or null if not set.
     */
    public function getOriginalType(): ?string
    {
        return $this->originalType;
    }

    /**
     * Gets the length of the column (for types like VARCHAR).
     *
     * @return int|null The column length, or null if not applicable.
     */
    public function getLength(): ?int
    {
        return $this->length;
    }

    /**
     * Gets the precision of the column (for numeric types).
     *
     * @return int|null The column precision, or null if not applicable.
     */
    public function getPrecision(): ?int
    {
        return $this->precision;
    }

    /**
     * Gets the scale of the column (for numeric types).
     *
     * @return int|null The column scale, or null if not applicable.
     */
    public function getScale(): ?int
    {
        return $this->scale;
    }

    /**
     * Gets the default value of the column.
     *
     * @return mixed The default value.
     */
    public function getDefault(): mixed
    {
        return $this->default;
    }

    /**
     * Gets the comment for the column.
     *
     * @return string|null The column comment, or null if not set.
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Gets the allowed values for an ENUM or SET column.
     *
     * @return string[] An array of enum or set values.
     */
    public function getEnumValues(): array
    {
        return $this->enumValues;
    }

    /**
     * Gets the name of the column after which this column should be positioned.
     *
     * @return string|null The preceding column's name, or null.
     */
    public function getAfter(): ?string
    {
        return $this->after;
    }

    /**
     * Gets a custom option for the column.
     *
     * @param string $key The option key.
     * @return mixed The option value, or null if not found.
     */
    public function getCustomOption(string $key): mixed
    {
        return $this->customOptions[$key] ?? null;
    }

    /**
     * Gets all custom options for the column.
     *
     * @return array An associative array of custom options.
     */
    public function getCustomOptions(): array
    {
        return $this->customOptions;
    }

    /**
     * Gets the original SQL definition for the column.
     *
     * @return string|null The original SQL definition, or null if not available.
     */
    public function getOriginalDefinition(): ?string
    {
        return $this->originalDefinition;
    }

    // --------------------------------------------------------------------
    // Setters
    // --------------------------------------------------------------------

    /**
     * Sets the name of the column.
     *
     * @param string $name The new column name.
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Sets the data type of the column.
     *
     * @param string $type The new data type.
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = strtolower($type);
        return $this;
    }

    /**
     * Sets the original, platform-specific data type.
     *
     * @param string|null $type The original data type.
     * @return self
     */
    public function setOriginalType(?string $type): self
    {
        $this->originalType = $type;
        return $this;
    }

    /**
     * Sets the length of the column.
     *
     * @param int|null $length The column length.
     * @return self
     */
    public function setLength(?int $length): self
    {
        $this->length = $length;
        return $this;
    }

    /**
     * Sets the precision of the column.
     *
     * @param int|null $precision The column precision.
     * @return self
     */
    public function setPrecision(?int $precision): self
    {
        $this->precision = $precision;
        return $this;
    }

    /**
     * Sets the scale of the column.
     *
     * @param int|null $scale The column scale.
     * @return self
     */
    public function setScale(?int $scale): self
    {
        $this->scale = $scale;
        return $this;
    }

    /**
     * Sets whether the column can be null.
     *
     * @param bool $nullable True if the column is nullable, false otherwise.
     * @return self
     */
    public function setNullable(bool $nullable): self
    {
        $this->nullable = $nullable;
        return $this;
    }

    /**
     * Sets whether the column is auto-incrementing.
     *
     * @param bool $autoIncrement True if auto-incrementing, false otherwise.
     * @return self
     */
    public function setAutoIncrement(bool $autoIncrement): self
    {
        $this->autoIncrement = $autoIncrement;
        return $this;
    }

    /**
     * Sets whether the column is unsigned.
     *
     * @param bool $unsigned True if unsigned, false otherwise.
     * @return self
     */
    public function setUnsigned(bool $unsigned): self
    {
        $this->unsigned = $unsigned;
        return $this;
    }

    /**
     * Sets the default value of the column.
     *
     * @param mixed $default The default value.
     * @return self
     */
    public function setDefault(mixed $default): self
    {
        $this->default = $default;
        return $this;
    }

    /**
     * Sets the comment for the column.
     *
     * @param string|null $comment The column comment.
     * @return self
     */
    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Sets the allowed values for an ENUM or SET column.
     *
     * @param string[] $values An array of enum or set values.
     * @return self
     */
    public function setEnumValues(array $values): self
    {
        $this->enumValues = $values;
        return $this;
    }

    /**
     * Sets the name of the column after which this column should be positioned.
     *
     * @param string|null $after The preceding column's name.
     * @return self
     */
    public function setAfter(?string $after): self
    {
        $this->after = $after;
        return $this;
    }

    /**
     * Sets whether this column should be the first in the table.
     *
     * @param bool $first True if it should be the first column.
     * @return self
     */
    public function setFirst(bool $first): self
    {
        $this->first = $first;
        return $this;
    }

    /**
     * Sets a custom option for the column.
     *
     * @param string $key The option key.
     * @param mixed $value The option value.
     * @return self
     */
    public function setCustomOption(string $key, mixed $value): self
    {
        $this->customOptions[$key] = $value;
        return $this;
    }

    /**
     * Sets the original SQL definition for the column.
     *
     * @param string $definition The original SQL definition.
     * @return self
     */
    public function setOriginalDefinition(string $definition): self
    {
        $this->originalDefinition = $definition;
        return $this;
    }

    // --------------------------------------------------------------------
    // Boolean Checkers
    // --------------------------------------------------------------------

    /**
     * Checks if the column is nullable.
     *
     * @return bool True if nullable, false otherwise.
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Checks if the column is auto-incrementing.
     *
     * @return bool True if auto-incrementing, false otherwise.
     */
    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    /**
     * Checks if the column is unsigned.
     *
     * @return bool True if unsigned, false otherwise.
     */
    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    /**
     * Checks if this column should be positioned first.
     *
     * @return bool True if it should be the first column.
     */
    public function isFirst(): bool
    {
        return $this->first;
    }

    /**
     * Checks if the column is part of the primary key.
     *
     * @return bool True if it is part of the primary key, false otherwise.
     */
    public function isPrimaryKey(): bool
    {
        if (!$this->table) {
            return false;
        }

        $primaryKey = $this->table->getPrimaryKey();
        return $primaryKey && in_array($this->name, $primaryKey->getColumnNames(), true);
    }

    /**
     * Checks if the column is referenced by any foreign keys.
     *
     * @return bool True if referenced, false otherwise.
     */
    public function isReferencedByForeignKeys(): bool
    {
        if (!$this->table) {
            return false;
        }

        foreach ($this->table->getForeignKeys() as $constraint) {
            if (in_array($this->name, $constraint->getColumns(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the column has a unique index.
     *
     * @return bool True if it has a unique index, false otherwise.
     */
    public function hasUniqueIndex(): bool
    {
        if (!$this->table) {
            return false;
        }

        foreach ($this->table->getIndexes() as $index) {
            if ($index->isUnique() && in_array($this->name, $index->getColumnNames(), true)) {
                return true;
            }
        }

        return false;
    }

    // --------------------------------------------------------------------
    // Generated Column Methods
    // --------------------------------------------------------------------

    /**
     * Checks if the column is a generated (computed) column.
     *
     * @return bool True if it is a generated column.
     */
    public function isGenerated(): bool
    {
        return $this->generated;
    }

    /**
     * Gets the expression for a generated column.
     *
     * @return string|null The generation expression, or null.
     */
    public function getGeneratedExpression(): ?string
    {
        return $this->generatedExpression;
    }

    /**
     * Gets the storage type for a generated column ('STORED' or 'VIRTUAL').
     *
     * @return string The storage type.
     */
    public function getGeneratedStorageType(): string
    {
        return $this->generatedStorageType;
    }

    /**
     * Checks if the column is a stored generated column.
     *
     * @return bool True if it is a stored generated column.
     */
    public function isStoredGenerated(): bool
    {
        return $this->isGenerated() && $this->generatedStorageType === 'stored';
    }

    /**
     * Checks if the column is a virtual generated column.
     *
     * @return bool True if it is a virtual generated column.
     */
    public function isVirtualGenerated(): bool
    {
        return $this->isGenerated() && $this->generatedStorageType === 'virtual';
    }

    /**
     * Sets whether the column is a generated column.
     *
     * @param bool $generated True if it is a generated column.
     * @return self
     */
    public function setGenerated(bool $generated): self
    {
        $this->generated = $generated;
        return $this;
    }

    /**
     * Sets the expression for a generated column.
     *
     * @param string|null $expression The generation expression.
     * @return self
     */
    public function setGeneratedExpression(?string $expression): self
    {
        $this->generatedExpression = $expression;
        if ($expression !== null) {
            $this->setGenerated(true);
        }
        return $this;
    }

    /**
     * Sets the storage type for a generated column.
     *
     * @param string $storageType The storage type ('STORED' or 'VIRTUAL').
     * @return self
     * @throws \InvalidArgumentException If the storage type is invalid.
     */
    public function setGeneratedStorageType(string $storageType): self
    {
        $storageType = strtolower($storageType);
        if (!in_array($storageType, ['stored', 'virtual'])) {
            throw new \InvalidArgumentException("Generated storage type must be 'stored' or 'virtual', got: {$storageType}");
        }
        $this->generatedStorageType = $storageType;
        return $this;
    }

    // --------------------------------------------------------------------
    // Table Relationship Methods
    // --------------------------------------------------------------------

    /**
     * Gets the table this column belongs to.
     *
     * @return Table|null The parent table, or null if not set.
     */
    public function getTable(): ?Table
    {
        return $this->table;
    }

    /**
     * Gets the name of the table this column belongs to.
     *
     * @return string|null The table name, or null if no table is set.
     */
    public function getTableName(): ?string
    {
        return $this->table ? $this->table->getName() : null;
    }

    /**
     * Sets the table this column belongs to.
     *
     * @param Table|null $table The parent table.
     * @return self
     */
    public function setTable(?Table $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Checks if the column belongs to a specific table instance.
     *
     * @param Table $table The table to check against.
     * @return bool True if the column belongs to the table.
     */
    public function belongsToTable(Table $table): bool
    {
        return $this->table === $table;
    }

    /**
     * Checks if the column belongs to a table with a specific name.
     *
     * @param string $tableName The table name to check against.
     * @return bool True if the column belongs to a table with that name.
     */
    public function belongsToTableName(string $tableName): bool
    {
        return $this->table && strcasecmp($this->table->getName(), $tableName) === 0;
    }

    // --------------------------------------------------------------------
    // Type Checking Methods
    // --------------------------------------------------------------------

    /**
     * Checks if the column is a numeric type.
     *
     * @return bool True if it is a numeric type.
     */
    public function isNumericType(): bool
    {
        return in_array($this->type, [
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'decimal', 'numeric', 'float', 'double', 'real',
            'serial', 'bigserial', 'smallserial'
        ], true);
    }

    /**
     * Checks if the column is a string type.
     *
     * @return bool True if it is a string type.
     */
    public function isStringType(): bool
    {
        return in_array($this->type, [
            'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext',
            'enum', 'set', 'string'
        ], true);
    }

    /**
     * Checks if the column is a date/time type.
     *
     * @return bool True if it is a date/time type.
     */
    public function isDateTimeType(): bool
    {
        return in_array($this->type, ['date', 'datetime', 'timestamp', 'time', 'year'], true);
    }

    /**
     * Checks if the column is a binary type.
     *
     * @return bool True if it is a binary type.
     */
    public function isBinaryType(): bool
    {
        return in_array($this->type, [
            'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea'
        ], true);
    }

    /**
     * Checks if the column is a JSON type.
     *
     * @return bool True if it is a JSON type.
     */
    public function isJsonType(): bool
    {
        return in_array($this->type, ['json', 'jsonb'], true);
    }

    // --------------------------------------------------------------------
    // Validation and Cloning
    // --------------------------------------------------------------------

    /**
     * Checks if the column requires special handling for a target database.
     *
     * @param string $targetDatabase The target database platform.
     * @return bool True if special handling is required.
     */
    public function requiresSpecialHandling(string $targetDatabase): bool
    {
        if ($this->type === 'enum' && $targetDatabase !== 'mysql') {
            return true;
        }

        if ($this->autoIncrement && $targetDatabase === 'sqlite' && !$this->isNumericType()) {
            return true;
        }

        if ($this->isJsonType() && $targetDatabase === 'sqlite') {
            return true;
        }

        return false;
    }

    /**
     * Creates a deep clone of the column with a new name.
     *
     * @param string $newName The new name for the cloned column.
     * @return self A new Column instance.
     */
    public function cloneWithName(string $newName): self
    {
        $clone = clone $this;
        $clone->name = $newName;
        return $clone;
    }

    // --------------------------------------------------------------------
    // Magic Methods
    // --------------------------------------------------------------------

    /**
     * Returns a string representation of the column for debugging.
     *
     * @return string A summary of the column definition.
     */
    public function __toString(): string
    {
        $parts = [$this->name, $this->type];

        if ($this->length !== null) {
            $parts[] = "({$this->length})";
        } elseif ($this->precision !== null) {
            $parts[] = "({$this->precision},{$this->scale})";
        }

        if ($this->unsigned) {
            $parts[] = 'UNSIGNED';
        }

        if (!$this->nullable) {
            $parts[] = 'NOT NULL';
        }

        if ($this->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }

        if ($this->default !== null) {
            $parts[] = 'DEFAULT ' . var_export($this->default, true);
        }

        return implode(' ', $parts);
    }
}
