<?php

/**
 * Represents a database index, providing a database-agnostic representation of its definition.
 *
 * This class supports various index types, including primary, unique, full-text, and spatial indexes.
 * It also handles advanced features like expression-based and partial indexes.
 *
 * @package Database\Schema
 * @author Enhanced Model System
 * @version 2.0.0
 */
class Index
{
    public const TYPE_PRIMARY = 'primary';
    public const TYPE_UNIQUE = 'unique';
    public const TYPE_INDEX = 'index';
    public const TYPE_FULLTEXT = 'fulltext';
    public const TYPE_SPATIAL = 'spatial';

    private string $name;
    private string $type;
    private array $columns = [];
    private ?Table $table = null;
    private ?string $algorithm = null;
    private ?string $expression = null;
    private ?string $method = null;
    private ?string $where = null;
    private ?string $comment = null;
    private array $options = [];
    private ?string $originalDefinition = null;

    /**
     * Constructs a new Index instance.
     *
     * @param string $name The name of the index.
     * @param string $type The type of the index (e.g., 'primary', 'unique').
     */
    public function __construct(string $name, string $type = self::TYPE_INDEX)
    {
        $this->name = $name;
        $this->type = $type;
    }

    // --------------------------------------------------------------------
    // Getters
    // --------------------------------------------------------------------

    /**
     * Gets the name of the index.
     *
     * @return string The index name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the type of the index.
     *
     * @return string The index type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Gets the columns covered by the index.
     *
     * @return array An associative array of columns and their options.
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Gets the table this index belongs to.
     *
     * @return Table|null The parent table, or null if not set.
     */
    public function getTable(): ?Table
    {
        return $this->table;
    }

    /**
     * Gets the index algorithm.
     *
     * @return string|null The algorithm name, or null if not set.
     */
    public function getAlgorithm(): ?string
    {
        return $this->algorithm;
    }

    /**
     * Gets the expression for an expression-based index.
     *
     * @return string|null The index expression, or null if not set.
     */
    public function getExpression(): ?string
    {
        return $this->expression;
    }

    /**
     * Gets the index method (e.g., BTREE, HASH).
     *
     * @return string|null The index method, or null if not set.
     */
    public function getMethod(): ?string
    {
        return $this->method;
    }

    /**
     * Gets the WHERE clause for a partial index.
     *
     * @return string|null The WHERE clause, or null if not set.
     */
    public function getWhere(): ?string
    {
        return $this->where;
    }

    /**
     * Gets the comment for the index.
     *
     * @return string|null The index comment, or null if not set.
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Gets all index options.
     *
     * @return array An associative array of index options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Gets a specific index option by key.
     *
     * @param string $key The option key.
     * @return mixed The option value, or null if not found.
     */
    public function getOption(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Gets the original SQL definition for the index.
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
     * Sets the name of the index.
     *
     * @param string $name The new index name.
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Sets the type of the index.
     *
     * @param string $type The new index type.
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Sets the columns for the index.
     *
     * @param array $columns An array of column names.
     * @return self
     */
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * Adds a column to the index.
     *
     * @param string $column The name of the column.
     * @param int|null $length The length of the indexed part of the column.
     * @param string|null $direction The sort direction (ASC or DESC).
     * @return self
     */
    public function addColumn(string $column, ?int $length = null, ?string $direction = null): self
    {
        $this->columns[$column] = [
            'length' => $length,
            'direction' => $direction ? strtoupper($direction) : null
        ];
        return $this;
    }

    /**
     * Sets the table this index belongs to.
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
     * Sets the expression for an expression-based index.
     *
     * @param string|null $expression The index expression.
     * @return self
     */
    public function setExpression(?string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    /**
     * Sets the index method.
     *
     * @param string|null $method The index method.
     * @return self
     */
    public function setMethod(?string $method): self
    {
        $this->method = $method;
        return $this;
    }

    /**
     * Sets the WHERE clause for a partial index.
     *
     * @param string|null $where The WHERE clause.
     * @return self
     */
    public function setWhere(?string $where): self
    {
        $this->where = $where;
        return $this;
    }

    /**
     * Sets the comment for the index.
     *
     * @param string|null $comment The index comment.
     * @return self
     */
    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Sets a specific index option.
     *
     * @param string $key The option key.
     * @param mixed $value The option value.
     * @return self
     */
    public function setOption(string $key, mixed $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    /**
     * Sets multiple index options at once.
     *
     * @param array $options An associative array of options.
     * @return self
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Sets the original SQL definition for the index.
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
    // Type Checking Methods
    // --------------------------------------------------------------------

    /**
     * Checks if the index is a primary key.
     *
     * @return bool True if the index is a primary key, false otherwise.
     */
    public function isPrimary(): bool
    {
        return $this->type === self::TYPE_PRIMARY;
    }

    /**
     * Checks if the index is a unique index.
     *
     * @return bool True if the index is unique, false otherwise.
     */
    public function isUnique(): bool
    {
        return $this->type === self::TYPE_UNIQUE;
    }

    /**
     * Sets whether the index is unique, adjusting the type accordingly.
     *
     * @param bool $unique Whether the index should be unique.
     * @return self
     */
    public function setUnique(bool $unique): self
    {
        $this->type = $unique ? self::TYPE_UNIQUE : self::TYPE_INDEX;
        return $this;
    }

    /**
     * Checks if the index is a full-text index.
     *
     * @return bool True if the index is full-text, false otherwise.
     */
    public function isFulltext(): bool
    {
        return $this->type === self::TYPE_FULLTEXT;
    }

    /**
     * Checks if the index is a spatial index.
     *
     * @return bool True if the index is spatial, false otherwise.
     */
    public function isSpatial(): bool
    {
        return $this->type === self::TYPE_SPATIAL;
    }

    /**
     * Checks if this is an expression-based index.
     *
     * @return bool True if it is an expression-based index, false otherwise.
     */
    public function isExpressionIndex(): bool
    {
        return !empty($this->expression);
    }

    // --------------------------------------------------------------------
    // Column Methods
    // --------------------------------------------------------------------

    /**
     * Gets the names of the columns in the index.
     *
     * @return string[] An array of column names.
     */
    public function getColumnNames(): array
    {
        return array_keys($this->columns);
    }

    /**
     * Checks if the index uses a specific column.
     *
     * @param string $columnName The name of the column to check.
     * @return bool True if the column is used, false otherwise.
     */
    public function usesColumn(string $columnName): bool
    {
        return isset($this->columns[$columnName]);
    }

    // --------------------------------------------------------------------
    // Validation and Cloning
    // --------------------------------------------------------------------

    /**
     * Checks if the index is supported by a target database platform.
     *
     * @param string $targetDatabase The target database (e.g., 'mysql', 'sqlite').
     * @return bool True if supported, false otherwise.
     */
    public function isSupportedBy(string $targetDatabase): bool
    {
        if ($this->type === self::TYPE_FULLTEXT && in_array($targetDatabase, ['sqlite', 'postgresql'])) {
            return false;
        }

        if ($this->type === self::TYPE_SPATIAL && $targetDatabase === 'sqlite') {
            return false;
        }

        return true;
    }

    /**
     * Validates the index for a specific database platform.
     *
     * @param string $targetDatabase The target database.
     * @return array An array of warning messages.
     */
    public function validateFor(string $targetDatabase): array
    {
        $warnings = [];

        if (!$this->isSupportedBy($targetDatabase)) {
            $warnings[] = "{$this->type} index not supported by {$targetDatabase}";
        }

        if ($this->where && !in_array($targetDatabase, ['postgresql'])) {
            $warnings[] = "Partial indexes (WHERE clause) not supported by {$targetDatabase}";
        }

        if ($this->method && !in_array($targetDatabase, ['postgresql'])) {
            $warnings[] = "Index method specification not supported by {$targetDatabase}";
        }

        return $warnings;
    }

    /**
     * Creates a deep clone of the index with a new name.
     *
     * @param string $newName The new name for the cloned index.
     * @return self A new Index instance.
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
     * Returns a string representation of the index for debugging.
     *
     * @return string A summary of the index.
     */
    public function __toString(): string
    {
        $str = "{$this->type} INDEX {$this->name}";

        if ($this->expression) {
            $str .= " ({$this->expression})";
        } else {
            $cols = [];
            foreach ($this->columns as $col => $opts) {
                $colStr = $col;
                if (!empty($opts['length'])) {
                    $colStr .= '(' . $opts['length'] . ')';
                }
                if (!empty($opts['direction'])) {
                    $colStr .= ' ' . $opts['direction'];
                }
                $cols[] = $colStr;
            }
            $str .= ' (' . implode(', ', $cols) . ')';
        }

        if ($this->method) {
            $str .= ' USING ' . $this->method;
        }

        if ($this->where) {
            $str .= ' WHERE ' . $this->where;
        }

        return $str;
    }
}
