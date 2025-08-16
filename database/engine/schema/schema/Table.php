<?php

/**
 * Represents a database table, providing a database-agnostic representation of its schema.
 *
 * This class holds information about the table's columns, indexes, constraints,
 * and other properties like storage engine and character set. It is designed to
 * facilitate database schema manipulation and migration tasks.
 *
 * @package Database\Schema
 * @author Enhanced Model System
 * @version 1.0.0
 */
class Table
{
    private string $name;
    private array $columns = [];
    private array $indexes = [];
    private array $constraints = [];
    private ?string $engine = null;
    private ?string $charset = null;
    private ?string $collation = null;
    private ?string $comment = null;
    private array $options = [];
    private ?string $originalDefinition = null;
    private array $columnOrder = [];
    private array $data = [];
    private bool $hasData = false;
    private array $dataOptions = [];

    /**
     * Constructs a new Table instance.
     *
     * @param string $name The name of the table.
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /*
    |--------------------------------------------------------------------------
    | Data Storage Methods (NEW)
    |--------------------------------------------------------------------------
    */

    /**
     * Set data rows for this table
     *
     * @param array $data Array of rows, where each row is an associative array
     * @param array $options Options for data handling
     * @return void
     */
    public function setData(array $data, array $options = []): void
    {
        $this->data = $data;
        $this->hasData = !empty($data);
        $this->dataOptions = array_merge([
            'source_format' => 'associative', // 'associative', 'indexed'
            'conflict_handling' => 'error',   // 'error', 'update', 'skip'
            'validate_columns' => true,       // Check if columns exist in table
            'normalize_data' => true          // Clean and normalize data
        ], $options);

        // Validate and normalize data if requested
        if ($this->dataOptions['validate_columns'] && $this->hasData) {
            $this->validateDataColumns();
        }

        if ($this->dataOptions['normalize_data'] && $this->hasData) {
            $this->normalizeData();
        }
    }

    /**
     * Add a single row of data
     *
     * @param array $row Associative array representing a row
     * @return void
     */
    public function addDataRow(array $row): void
    {
        $this->data[] = $row;
        $this->hasData = true;

        // Validate if enabled
        if ($this->dataOptions['validate_columns'] ?? true) {
            $this->validateRowColumns($row);
        }
    }

    /**
     * Add multiple rows of data
     *
     * @param array $rows Array of rows
     * @return void
     */
    public function addDataRows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->addDataRow($row);
        }
    }

    /**
     * Get all data rows
     *
     * @return array Array of data rows
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Check if table has data
     *
     * @return bool True if table has data rows
     */
    public function hasData(): bool
    {
        return $this->hasData && !empty($this->data);
    }

    /**
     * Get data row count
     *
     * @return int Number of data rows
     */
    public function getDataRowCount(): int
    {
        return count($this->data);
    }

    /**
     * Clear all data
     *
     * @return void
     */
    public function clearData(): void
    {
        $this->data = [];
        $this->hasData = false;
        $this->dataOptions = [];
    }

    /**
     * Get data options
     *
     * @return array Data handling options
     */
    public function getDataOptions(): array
    {
        return $this->dataOptions;
    }

    /**
     * Set specific data option
     *
     * @param string $key Option key
     * @param mixed $value Option value
     * @return void
     */
    public function setDataOption(string $key, $value): void
    {
        $this->dataOptions[$key] = $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Data Validation and Normalization (NEW)
    |--------------------------------------------------------------------------
    */

    /**
     * Validate that data columns exist in table schema
     *
     * @return void
     * @throws \InvalidArgumentException If invalid columns found
     */
    private function validateDataColumns(): void
    {
        if (empty($this->data)) {
            return;
        }

        $tableColumns = array_keys($this->columns);
        $invalidColumns = [];

        foreach ($this->data as $rowIndex => $row) {
            foreach (array_keys($row) as $columnName) {
                if (!in_array($columnName, $tableColumns)) {
                    $invalidColumns[$columnName] = $rowIndex;
                }
            }
        }

        if (!empty($invalidColumns)) {
            $errorMsg = "Invalid columns found in data for table '{$this->name}': " . 
                       implode(', ', array_keys($invalidColumns));
            
            if ($this->dataOptions['validate_columns'] === 'strict') {
                throw new \InvalidArgumentException($errorMsg);
            }
            // In non-strict mode, we could log a warning instead
        }
    }

    /**
     * Validate single row columns
     *
     * @param array $row Row data to validate
     * @return void
     */
    private function validateRowColumns(array $row): void
    {
        $tableColumns = array_keys($this->columns);
        
        foreach (array_keys($row) as $columnName) {
            if (!in_array($columnName, $tableColumns) && ($this->dataOptions['validate_columns'] ?? true)) {
                // Log warning or handle according to validation settings
                error_log("Warning: Column '$columnName' not found in table '{$this->name}' schema");
            }
        }
    }

    /**
     * Normalize data types and values
     *
     * @return void
     */
    private function normalizeData(): void
    {
        foreach ($this->data as &$row) {
            foreach ($row as $columnName => &$value) {
                if ($this->hasColumn($columnName)) {
                    $column = $this->getColumn($columnName);
                    $value = $this->normalizeValue($value, $column);
                }
            }
        }
    }

    /**
     * Normalize a single value based on column type
     *
     * @param mixed $value Value to normalize
     * @param Column $column Column definition
     * @return mixed Normalized value
     */
    private function normalizeValue($value, Column $column)
    {
        // NULL handling
        if ($value === null || $value === '' && $column->isNullable()) {
            return null;
        }

        $type = strtolower($column->getType());

        switch ($type) {
            case 'int':
            case 'integer':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
                return is_numeric($value) ? (int)$value : $value;

            case 'float':
            case 'double':
            case 'real':
            case 'decimal':
            case 'numeric':
                return is_numeric($value) ? (float)$value : $value;

            case 'bool':
            case 'boolean':
                if (is_bool($value)) return $value;
                if (is_numeric($value)) return (bool)$value;
                if (is_string($value)) {
                    $lower = strtolower($value);
                    return in_array($lower, ['true', '1', 'yes', 'on']) ? true : 
                           (in_array($lower, ['false', '0', 'no', 'off']) ? false : $value);
                }
                return $value;

            case 'date':
            case 'datetime':
            case 'timestamp':
                // Basic date validation - could be enhanced
                if (is_string($value) && strtotime($value) !== false) {
                    return $value; // Keep as string for SQL
                }
                return $value;

            default:
                // String types - ensure it's a string
                return is_scalar($value) ? (string)$value : $value;
        }
    }

    // --------------------------------------------------------------------
    // Getters
    // --------------------------------------------------------------------

    /**
     * Gets the name of the table.
     *
     * @return string The table name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the storage engine for the table.
     *
     * @return string|null The storage engine name, or null if not set.
     */
    public function getEngine(): ?string
    {
        return $this->engine;
    }

    /**
     * Gets the character set for the table.
     *
     * @return string|null The character set name, or null if not set.
     */
    public function getCharset(): ?string
    {
        return $this->charset;
    }

    /**
     * Gets the collation for the table.
     *
     * @return string|null The collation name, or null if not set.
     */
    public function getCollation(): ?string
    {
        return $this->collation;
    }

    /**
     * Gets the comment for the table.
     *
     * @return string|null The table comment, or null if not set.
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Gets all table options.
     *
     * @return array An associative array of table options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Gets a specific table option by key.
     *
     * @param string $key The option key.
     * @return mixed The option value, or null if not found.
     */
    public function getOption(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Gets the original CREATE TABLE statement.
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
     * Sets the name of the table.
     *
     * @param string $name The new table name.
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Sets the storage engine for the table.
     *
     * @param string|null $engine The storage engine name.
     * @return self
     */
    public function setEngine(?string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Sets the character set for the table.
     *
     * @param string|null $charset The character set name.
     * @return self
     */
    public function setCharset(?string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Sets the collation for the table.
     *
     * @param string|null $collation The collation name.
     * @return self
     */
    public function setCollation(?string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Sets the comment for the table.
     *
     * @param string|null $comment The table comment.
     * @return self
     */
    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Sets a specific table option.
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
     * Sets multiple table options at once.
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
     * Sets the original CREATE TABLE statement.
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
    // Column Methods
    // --------------------------------------------------------------------

    /**
     * Adds a column to the table.
     *
     * @param Column $column The column to add.
     * @return self
     */
    public function addColumn(Column $column): self
    {
        $this->columns[$column->getName()] = $column;
        if (!in_array($column->getName(), $this->columnOrder, true)) {
            $this->columnOrder[] = $column->getName();
        }
        $column->setTable($this);
        return $this;
    }

    /**
     * Removes a column from the table by name.
     *
     * @param string $columnName The name of the column to remove.
     * @return bool True if the column was removed, false otherwise.
     */
    public function removeColumn(string $columnName): bool
    {
        if ($this->hasColumn($columnName)) {
            $column = $this->getColumn($columnName);
            $column->setTable(null);
            unset($this->columns[$columnName]);
            $this->columnOrder = array_values(array_diff($this->columnOrder, [$columnName]));
            return true;
        }
        return false;
    }

    /**
     * Checks if a column exists in the table.
     *
     * @param string $columnName The name of the column.
     * @return bool True if the column exists, false otherwise.
     */
    public function hasColumn(string $columnName): bool
    {
        return isset($this->columns[$columnName]);
    }

    /**
     * Gets a column by its name.
     *
     * @param string $name The name of the column.
     * @return Column|null The column object, or null if not found.
     */
    public function getColumn(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    /**
     * Gets a column by its name or throws an exception if not found.
     *
     * @param string $columnName The name of the column.
     * @return Column The column object.
     * @throws \InvalidArgumentException If the column is not found.
     */
    public function getColumnOrFail(string $columnName): Column
    {
        $column = $this->getColumn($columnName);
        if ($column === null) {
            throw new \InvalidArgumentException("Column '$columnName' not found in table '{$this->name}'");
        }
        return $column;
    }

    /**
     * Gets all columns in the table.
     *
     * @return Column[] An array of column objects.
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Gets the names of all columns in the table.
     *
     * @return string[] An array of column names.
     */
    public function getColumnNames(): array
    {
        return array_keys($this->columns);
    }

    /**
     * Gets the original order of columns.
     *
     * @return string[] An array of column names in their original order.
     */
    public function getColumnOrder(): array
    {
        return $this->columnOrder;
    }

    /**
     * Gets columns in their original order.
     *
     * @return Column[] An array of column objects in order.
     */
    public function getOrderedColumns(): array
    {
        $ordered = [];
        foreach ($this->columnOrder as $name) {
            if (isset($this->columns[$name])) {
                $ordered[$name] = $this->columns[$name];
            }
        }
        return $ordered;
    }

    // --------------------------------------------------------------------
    // Index Methods
    // --------------------------------------------------------------------

    /**
     * Adds an index to the table.
     *
     * @param Index $index The index to add.
     * @return self
     */
    public function addIndex(Index $index): self
    {
        $this->indexes[$index->getName()] = $index;
        $index->setTable($this);
        return $this;
    }

    /**
     * Removes an index from the table by name.
     *
     * @param string $name The name of the index to remove.
     * @return self
     */
    public function removeIndex(string $name): self
    {
        unset($this->indexes[$name]);
        return $this;
    }

    /**
     * Checks if an index exists in the table.
     *
     * @param string $indexName The name of the index.
     * @return bool True if the index exists, false otherwise.
     */
    public function hasIndex(string $indexName): bool
    {
        return isset($this->indexes[$indexName]);
    }

    /**
     * Gets an index by its name.
     *
     * @param string $indexName The name of the index.
     * @return Index|null The index object, or null if not found.
     */
    public function getIndex(string $indexName): ?Index
    {
        return $this->indexes[$indexName] ?? null;
    }

    /**
     * Gets all indexes in the table.
     *
     * @return Index[] An array of index objects.
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Gets the primary key index of the table.
     *
     * @return Index|null The primary key index, or null if not defined.
     */
    public function getPrimaryKey(): ?Index
    {
        foreach ($this->indexes as $index) {
            if ($index->isPrimary()) {
                return $index;
            }
        }
        return null;
    }

    // --------------------------------------------------------------------
    // Constraint Methods
    // --------------------------------------------------------------------

    /**
     * Adds a constraint to the table.
     *
     * @param Constraint $constraint The constraint to add.
     * @return self
     */
    public function addConstraint(Constraint $constraint): self
    {
        $this->constraints[$constraint->getName()] = $constraint;
        return $this;
    }

    /**
     * Removes a constraint from the table by name.
     *
     * @param string $name The name of the constraint to remove.
     * @return self
     */
    public function removeConstraint(string $name): self
    {
        unset($this->constraints[$name]);
        return $this;
    }

    /**
     * Checks if a constraint exists in the table.
     *
     * @param string $constraintName The name of the constraint.
     * @return bool True if the constraint exists, false otherwise.
     */
    public function hasConstraint(string $constraintName): bool
    {
        return isset($this->constraints[$constraintName]);
    }

    /**
     * Gets a constraint by its name.
     *
     * @param string $constraintName The name of the constraint.
     * @return Constraint|null The constraint object, or null if not found.
     */
    public function getConstraint(string $constraintName): ?Constraint
    {
        return $this->constraints[$constraintName] ?? null;
    }

    /**
     * Gets all constraints in the table.
     *
     * @return Constraint[] An array of constraint objects.
     */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    /**
     * Gets all foreign key constraints in the table.
     *
     * @return Constraint[] An array of foreign key constraint objects.
     */
    public function getForeignKeys(): array
    {
        return array_filter($this->constraints, fn($c) => $c->isForeignKey());
    }

    /**
     * Gets all unique constraints, including unique indexes.
     *
     * @return array An array of unique constraint and index objects.
     */
    public function getUniqueConstraints(): array
    {
        $uniques = [];
        foreach ($this->indexes as $index) {
            if ($index->isUnique()) {
                $uniques[] = $index;
            }
        }
        foreach ($this->constraints as $constraint) {
            if ($constraint->isUnique()) {
                $uniques[] = $constraint;
            }
        }
        return $uniques;
    }

    // --------------------------------------------------------------------
    // Auto-increment Methods
    // --------------------------------------------------------------------

    /**
     * Checks if the table has an auto-incrementing column.
     *
     * @return bool True if an auto-increment column exists, false otherwise.
     */
    public function hasAutoIncrementColumn(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->isAutoIncrement()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets the auto-incrementing column of the table.
     *
     * @return Column|null The auto-increment column, or null if not found.
     */
    public function getAutoIncrementColumn(): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->isAutoIncrement()) {
                return $column;
            }
        }
        return null;
    }

    // --------------------------------------------------------------------
    // Validation and Cloning
    // --------------------------------------------------------------------

    /**
     * Validates the table schema against a target database platform.
     *
     * @param string $targetDatabase The target database (e.g., 'mysql', 'sqlite').
     * @return array An array of warning messages.
     */
    public function validateFor(string $targetDatabase): array
    {
        $warnings = [];

        foreach ($this->columns as $column) {
            if ($column->requiresSpecialHandling($targetDatabase)) {
                if ($column->getType() === 'enum' && $targetDatabase !== 'mysql') {
                    $warnings[] = "ENUM column '{$column->getName()}' requires conversion for {$targetDatabase}";
                }
                if ($column->isAutoIncrement() && $targetDatabase === 'sqlite' && !$column->isNumericType()) {
                    $warnings[] = "AUTO_INCREMENT column '{$column->getName()}' must be INTEGER in SQLite";
                }
            }
        }

        foreach ($this->indexes as $index) {
            if (!$index->isSupportedBy($targetDatabase)) {
                $warnings[] = "{$index->getType()} index '{$index->getName()}' not supported by {$targetDatabase}";
            }
        }

        foreach ($this->constraints as $constraint) {
            if (!$constraint->isSupportedBy($targetDatabase)) {
                $warnings[] = "{$constraint->getType()} constraint '{$constraint->getName()}' not supported by {$targetDatabase}";
            }
            $warnings = array_merge($warnings, $constraint->validateActionsFor($targetDatabase));
        }

        if ($targetDatabase === 'sqlite') {
            $autoIncCol = $this->getAutoIncrementColumn();
            $primaryKey = $this->getPrimaryKey();
            if ($autoIncCol && $primaryKey) {
                $pkColumns = $primaryKey->getColumnNames();
                if (count($pkColumns) > 1 || $pkColumns[0] !== $autoIncCol->getName()) {
                    $warnings[] = "SQLite AUTOINCREMENT requires single-column PRIMARY KEY on the AUTOINCREMENT column";
                }
            }
        }

        return $warnings;
    }

    /**
     * Creates a deep clone of the table with a new name.
     *
     * @param string $newName The new name for the cloned table.
     * @return self A new Table instance.
     */
    public function cloneWithName(string $newName): self
    {
        $clone = clone $this;
        $clone->name = $newName;

        $clone->columns = [];
        foreach ($this->columns as $name => $column) {
            $clone->columns[$name] = clone $column;
        }

        $clone->indexes = [];
        foreach ($this->indexes as $name => $index) {
            $clone->indexes[$name] = clone $index;
        }

        $clone->constraints = [];
        foreach ($this->constraints as $name => $constraint) {
            $clone->constraints[$name] = clone $constraint;
        }

        return $clone;
    }

    // --------------------------------------------------------------------
    // Magic Methods
    // --------------------------------------------------------------------

    /**
     * Returns a string representation of the table for debugging.
     *
     * @return string A summary of the table.
     */
    public function __toString(): string
    {
        $str = "Table: {$this->name}\n";
        $str .= "Columns: " . count($this->columns) . "\n";
        $str .= "Indexes: " . count($this->indexes) . "\n";
        $str .= "Constraints: " . count($this->constraints) . "\n";

        if ($this->engine) {
            $str .= "Engine: {$this->engine}\n";
        }
        if ($this->charset) {
            $str .= "Charset: {$this->charset}\n";
        }

        return $str;
    }
}
