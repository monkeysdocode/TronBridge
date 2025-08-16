<?php

/**
 * Represents a database constraint, such as a foreign key, primary key, unique, or check constraint.
 *
 * This class provides a database-agnostic way to define and manage constraints,
 * facilitating schema manipulation and migration tasks.
 *
 * @package Database\Schema
 * @author Enhanced Model System
 * @version 1.0.0
 */
class Constraint
{
    public const TYPE_FOREIGN_KEY = 'foreign';
    public const TYPE_CHECK = 'check';
    public const TYPE_UNIQUE = 'unique';
    public const TYPE_PRIMARY_KEY = 'primary';

    public const ACTION_CASCADE = 'CASCADE';
    public const ACTION_SET_NULL = 'SET NULL';
    public const ACTION_SET_DEFAULT = 'SET DEFAULT';
    public const ACTION_RESTRICT = 'RESTRICT';
    public const ACTION_NO_ACTION = 'NO ACTION';

    private string $name;
    private string $type;
    private array $columns = [];
    private ?string $referencedTable = null;
    private array $referencedColumns = [];
    private ?string $onDelete = null;
    private ?string $onUpdate = null;
    private ?string $expression = null;
    private ?string $comment = null;
    private array $options = [];
    private ?string $originalDefinition = null;
    private ?Table $table = null;

    /**
     * Constructs a new Constraint instance.
     *
     * @param string $name The name of the constraint.
     * @param string $type The type of the constraint.
     */
    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    /**
     * Creates a new Constraint instance from an Index.
     *
     * @param Index $index The index to create the constraint from.
     * @param Table|null $table The table the constraint belongs to.
     * @return self A new Constraint instance.
     */
    public static function createFromIndex(Index $index, ?Table $table = null): self
    {
        $type = $index->isUnique() ? self::TYPE_UNIQUE : ($index->isPrimary() ? self::TYPE_PRIMARY_KEY : 'index');
        $constraint = new self($index->getName(), $type);
        $constraint->setColumns($index->getColumnNames());
        if ($table) {
            $constraint->setTable($table);
        }
        return $constraint;
    }

    // --------------------------------------------------------------------
    // Getters
    // --------------------------------------------------------------------

    /**
     * Gets the name of the constraint.
     *
     * @return string The constraint name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the type of the constraint.
     *
     * @return string The constraint type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Gets the columns associated with the constraint.
     *
     * @return string[] An array of column names.
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Gets the referenced table for a foreign key constraint.
     *
     * @return string|null The name of the referenced table, or null if not applicable.
     */
    public function getReferencedTable(): ?string
    {
        return $this->referencedTable;
    }

    /**
     * Gets the referenced columns for a foreign key constraint.
     *
     * @return string[] An array of referenced column names.
     */
    public function getReferencedColumns(): array
    {
        return $this->referencedColumns;
    }

    /**
     * Gets the ON DELETE action for a foreign key constraint.
     *
     * @return string|null The ON DELETE action, or null if not set.
     */
    public function getOnDelete(): ?string
    {
        return $this->onDelete;
    }

    /**
     * Gets the ON UPDATE action for a foreign key constraint.
     *
     * @return string|null The ON UPDATE action, or null if not set.
     */
    public function getOnUpdate(): ?string
    {
        return $this->onUpdate;
    }

    /**
     * Gets the expression for a check constraint.
     *
     * @return string|null The check constraint expression, or null if not applicable.
     */
    public function getExpression(): ?string
    {
        return $this->expression;
    }

    /**
     * Gets the comment for the constraint.
     *
     * @return string|null The constraint comment, or null if not set.
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Gets all constraint options.
     *
     * @return array An associative array of options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Gets a specific constraint option by key.
     *
     * @param string $key The option key.
     * @return mixed The option value, or null if not found.
     */
    public function getOption(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Gets the original SQL definition for the constraint.
     *
     * @return string|null The original SQL definition, or null if not available.
     */
    public function getOriginalDefinition(): ?string
    {
        return $this->originalDefinition;
    }

    /**
     * Gets the table associated with the constraint.
     *
     * @return Table|null The table object, or null if not set.
     */
    public function getTable(): ?Table
    {
        return $this->table;
    }

    // --------------------------------------------------------------------
    // Setters
    // --------------------------------------------------------------------

    /**
     * Sets the name of the constraint.
     *
     * @param string $name The new constraint name.
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Sets the type of the constraint.
     *
     * @param string $type The new constraint type.
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Sets the columns for the constraint.
     *
     * @param string[] $columns An array of column names.
     * @return self
     */
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * Adds a column to the constraint.
     *
     * @param string $column The name of the column to add.
     * @return self
     */
    public function addColumn(string $column): self
    {
        $this->columns[] = $column;
        return $this;
    }

    /**
     * Sets the referenced table for a foreign key constraint.
     *
     * @param string $table The name of the referenced table.
     * @return self
     */
    public function setReferencedTable(string $table): self
    {
        $this->referencedTable = $table;
        return $this;
    }

    /**
     * Sets the referenced columns for a foreign key constraint.
     *
     * @param string[] $columns An array of referenced column names.
     * @return self
     */
    public function setReferencedColumns(array $columns): self
    {
        $this->referencedColumns = $columns;
        return $this;
    }

    /**
     * Adds a referenced column to a foreign key constraint.
     *
     * @param string $column The name of the referenced column to add.
     * @return self
     */
    public function addReferencedColumn(string $column): self
    {
        $this->referencedColumns[] = $column;
        return $this;
    }

    /**
     * Sets the ON DELETE action for a foreign key constraint.
     *
     * @param string|null $action The ON DELETE action.
     * @return self
     */
    public function setOnDelete(?string $action): self
    {
        $this->onDelete = $action;
        return $this;
    }

    /**
     * Sets the ON UPDATE action for a foreign key constraint.
     *
     * @param string|null $action The ON UPDATE action.
     * @return self
     */
    public function setOnUpdate(?string $action): self
    {
        $this->onUpdate = $action;
        return $this;
    }

    /**
     * Sets the expression for a check constraint.
     *
     * @param string $expression The check constraint expression.
     * @return self
     */
    public function setExpression(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    /**
     * Sets the comment for the constraint.
     *
     * @param string|null $comment The constraint comment.
     * @return self
     */
    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Sets a specific constraint option.
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
     * Sets multiple constraint options at once.
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
     * Sets the original SQL definition for the constraint.
     *
     * @param string $definition The original SQL definition.
     * @return self
     */
    public function setOriginalDefinition(string $definition): self
    {
        $this->originalDefinition = $definition;
        return $this;
    }

    /**
     * Sets the table associated with the constraint.
     *
     * @param Table $table The table object.
     * @return self
     */
    public function setTable(Table $table): self
    {
        $this->table = $table;
        return $this;
    }

    // --------------------------------------------------------------------
    // Type Checking Methods
    // --------------------------------------------------------------------

    /**
     * Checks if the constraint is a foreign key.
     *
     * @return bool True if it is a foreign key, false otherwise.
     */
    public function isForeignKey(): bool
    {
        return $this->type === self::TYPE_FOREIGN_KEY;
    }

    /**
     * Checks if the constraint is a check constraint.
     *
     * @return bool True if it is a check constraint, false otherwise.
     */
    public function isCheck(): bool
    {
        return $this->type === self::TYPE_CHECK;
    }

    /**
     * Checks if the constraint is a unique constraint.
     *
     * @return bool True if it is a unique constraint, false otherwise.
     */
    public function isUnique(): bool
    {
        return $this->type === self::TYPE_UNIQUE;
    }

    /**
     * Checks if the constraint is a primary key.
     *
     * @return bool True if it is a primary key, false otherwise.
     */
    public function isPrimaryKey(): bool
    {
        return $this->type === self::TYPE_PRIMARY_KEY;
    }

    // --------------------------------------------------------------------
    // Validation and Cloning
    // --------------------------------------------------------------------

    /**
     * Checks if the constraint is supported by a target database platform.
     *
     * @param string $targetDatabase The target database (e.g., 'mysql', 'sqlite').
     * @return bool True if supported, false otherwise.
     */
    public function isSupportedBy(string $targetDatabase): bool
    {
        if ($this->isForeignKey() && $targetDatabase === 'sqlite') {
            if (
                $this->onDelete === self::ACTION_SET_DEFAULT ||
                $this->onUpdate === self::ACTION_SET_DEFAULT
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates constraint actions for a target database platform.
     *
     * @param string $targetDatabase The target database.
     * @return array An array of warning messages.
     */
    public function validateActionsFor(string $targetDatabase): array
    {
        $warnings = [];

        if ($this->isForeignKey() && $targetDatabase === 'sqlite') {
            if ($this->onDelete === self::ACTION_SET_DEFAULT) {
                $warnings[] = "SQLite does not support ON DELETE SET DEFAULT.";
            }
            if ($this->onUpdate === self::ACTION_SET_DEFAULT) {
                $warnings[] = "SQLite does not support ON UPDATE SET DEFAULT.";
            }
        }

        return $warnings;
    }

    /**
     * Creates a deep clone of the constraint with a new name.
     *
     * @param string $newName The new name for the cloned constraint.
     * @return self A new Constraint instance.
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
     * Returns a string representation of the constraint for debugging.
     *
     * @return string A summary of the constraint.
     */
    public function __toString(): string
    {
        switch ($this->type) {
            case self::TYPE_FOREIGN_KEY:
                $str = "FOREIGN KEY {$this->name} (" . implode(', ', $this->columns) . ")";
                $str .= " REFERENCES {$this->referencedTable} (" . implode(', ', $this->referencedColumns) . ")";
                if ($this->onDelete) {
                    $str .= " ON DELETE {$this->onDelete}";
                }
                if ($this->onUpdate) {
                    $str .= " ON UPDATE {$this->onUpdate}";
                }
                return $str;

            case self::TYPE_CHECK:
                return "CHECK {$this->name} ({$this->expression})";

            case self::TYPE_UNIQUE:
                return "UNIQUE {$this->name} (" . implode(', ', $this->columns) . ")";

            case self::TYPE_PRIMARY_KEY:
                return "PRIMARY KEY {$this->name} (" . implode(', ', $this->columns) . ")";

            default:
                return "{$this->type} {$this->name}";
        }
    }
}