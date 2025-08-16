<?php

require_once dirname(__DIR__) . '/platforms/AbstractPlatform.php';

/**
 * Renders platform-specific schema objects into a final SQL script.
 *
 * The SchemaRenderer is responsible for the final stage of the translation
 * process: converting the fully transformed, platform-aware schema objects
 * (Table, Column, etc.) into a string of valid SQL Data Definition Language (DDL).
 * It acts as the "writer" that generates the `CREATE TABLE`, `CREATE INDEX`,
 * and other statements.
 *
 * This class relies on a specific database Platform object (e.g., MySQLPlatform)
 * to ensure that all generated SQL adheres to the target database's syntax,
 * quoting rules, and data type representations. It intelligently decides whether
 * to create constraints and indexes inline or as as separate statements.
 *
 * Key Responsibilities:
 * - Generating `CREATE TABLE` statements from Table objects.
 * - Rendering column definitions with correct types, default values, and nullability.
 * - Creating `CREATE INDEX` and `ALTER TABLE` statements for indexes and constraints.
 * - Generating triggers to emulate features like 'ON UPDATE CURRENT_TIMESTAMP'.
 * - Assembling the final, executable SQL script.
 *
 * @package Database\Schema\Core
 * @author Enhanced Model System
 * @version 2.0.0
 */
class SchemaRenderer
{
    private AbstractPlatform $platform;
    private $debugCallback = null;
    private array $warnings = [];

    public function __construct(AbstractPlatform $platform)
    {
        $this->platform = $platform;
    }

    public function setDebugCallback(?callable $callback): void
    {
        $this->debugCallback = $callback;
    }

    /**
     * Render table as CREATE TABLE statement
     */
    public function renderTable(Table $table): string
    {
        // Enhanced rendering with platform-specific handling
        $sql = 'CREATE TABLE ' . $this->platform->quoteIdentifier($table->getName()) . ' (';
        $definitions = [];

        // Render columns with platform-specific modifications
        foreach ($table->getColumns() as $column) {
            $definitions[] = $this->renderColumn($column, $table);
        }

        // Render inline indexes (PRIMARY KEY, UNIQUE)
        foreach ($table->getIndexes() as $index) {
            if ($this->shouldRenderIndexInline($index)) {
                $indexDef = $this->renderInlineIndex($index, $table);
                if ($indexDef) {
                    $definitions[] = $indexDef;
                }
            }
        }

        // Render inline constraints
        foreach ($table->getConstraints() as $constraint) {
            if ($this->shouldRenderConstraintInline($constraint)) {
                $constraintDef = $this->renderInlineConstraint($constraint);
                if ($constraintDef) {
                    $definitions[] = $constraintDef;
                }
            }
        }

        $sql .= "\n " . implode(",\n ", $definitions) . "\n);";

        // Add table options for MySQL
        if ($this->platform->getName() === 'mysql') {
            $options = $this->renderTableOptions($table);
            if ($options) {
                $sql .= ' ' . $options;
            }
        }

        // FIXED: PostgreSQL sequence handling for SERIAL columns
        if ($this->platform->getName() === 'postgresql') {
            $additionalStatements = [];

            foreach ($table->getColumns() as $column) {
                if (in_array($column->getType(), ['serial', 'bigserial', 'smallserial'])) {
                    // PostgreSQL automatically creates sequences for SERIAL columns
                    // We don't need to manually create them
                    // The sequence will be named: tablename_columnname_seq
                }
            }

            // Add PostgreSQL-specific COMMENT ON statements
            if ($table->getComment()) {
                $commentSQL = "COMMENT ON TABLE " . $this->platform->quoteIdentifier($table->getName());
                $commentSQL .= " IS " . $this->platform->quoteValue($table->getComment()) . ";";
                $additionalStatements[] = $commentSQL;
            }

            // Add column comments as separate statements
            foreach ($table->getColumns() as $column) {
                if ($column->getComment()) {
                    $commentSQL = "COMMENT ON COLUMN ";
                    $commentSQL .= $this->platform->quoteIdentifier($table->getName()) . ".";
                    $commentSQL .= $this->platform->quoteIdentifier($column->getName());
                    $commentSQL .= " IS " . $this->platform->quoteValue($column->getComment()) . ";";
                    $additionalStatements[] = $commentSQL;
                }
            }

            if (!empty($additionalStatements)) {
                $sql .= "\n" . implode("\n", $additionalStatements);
            }
        }

        return $sql;
    }

    /**
     * Render table triggers
     *
     * @param Table $table
     * @return array
     */
    public function renderTriggers(Table $table): array
    {
        $triggers = [];
        if ($table->getOption('needs_update_trigger')) {
            $columns = $table->getOption('update_trigger_columns') ?? [];
            if (!empty($columns)) {
                $platformName = $this->platform->getName();
                if ($platformName === 'postgresql') {
                    $triggers[] = $this->renderPostgreSQLUpdateTrigger($table, $columns);
                } elseif ($platformName === 'sqlite') {
                    $triggers[] = $this->renderSQLiteUpdateTrigger($table, $columns);
                }
            }
        }
        return $triggers;
    }

    protected function renderPostgreSQLUpdateTrigger(Table $table, array $columns): string
    {
        $triggerName = $table->getName() . '_updated_at_trg';
        $functionName = $table->getName() . '_update_timestamp';
        $sql = "CREATE OR REPLACE FUNCTION $functionName() RETURNS TRIGGER AS $$\n";
        $sql .= "BEGIN\n";
        foreach ($columns as $col) {
            $sql .= "   NEW.\"$col\" = CURRENT_TIMESTAMP;\n";
        }
        $sql .= "   RETURN NEW;\n";
        $sql .= "END;\n$$ LANGUAGE plpgsql;\n\n";
        $sql .= "CREATE TRIGGER $triggerName\n";
        $sql .= "BEFORE UPDATE ON " . $this->platform->quoteIdentifier($table->getName()) . "\n";
        $sql .= "FOR EACH ROW EXECUTE PROCEDURE $functionName();";
        return $sql;
    }

    protected function renderSQLiteUpdateTrigger(Table $table, array $columns): string
    {
        $triggerName = $table->getName() . '_updated_at_trg';
        $sql = "CREATE TRIGGER $triggerName\n";
        $sql .= "AFTER UPDATE ON " . $this->platform->quoteIdentifier($table->getName()) . "\n";
        $sql .= "BEGIN\n";
        foreach ($columns as $col) {
            $sql .= "   UPDATE " . $this->platform->quoteIdentifier($table->getName()) . " SET \"$col\" = CURRENT_TIMESTAMP\n";
            $sql .= "   WHERE rowid = NEW.rowid;\n";
        }
        $sql .= "END;";
        return $sql;
    }

    /**
     * Render column definition
     */
    protected function renderColumn(Column $column, Table $table): string
    {
        // For SQLite AUTOINCREMENT, delegate completely to platform
        if ($this->platform->getName() === 'sqlite' && $column->isAutoIncrement()) {
            return $this->platform->getColumnSQL($column, $table);
        }

        // Standard column rendering for all other cases
        $sql = $this->platform->quoteIdentifier($column->getName());

        // Add data type
        $sql .= ' ' . $this->platform->getColumnTypeSQL($column);

        // Handle nullability using platform method
        $sql .= $this->platform->getNullableSQL($column, $table);

        // Add other column modifiers
        $sql .= $this->renderColumnModifiers($column, $table);

        return $sql;
    }


    /**
     * Render column modifiers
     */
    protected function renderColumnModifiers(Column $column, Table $table): string
    {
        $modifiers = [];

        // UNSIGNED (MySQL only)
        if ($column->isUnsigned() && $this->platform->supportsUnsigned()) {
            $modifiers[] = 'UNSIGNED';
        }

        // DEFAULT
        $default = $column->getDefault();
        if ($default !== null) {
            $modifiers[] = 'DEFAULT ' . $this->renderDefaultValue($column);
        }

        // AUTO_INCREMENT / AUTOINCREMENT
        if ($column->isAutoIncrement() && !$this->isSerialType($column)) {
            $modifiers[] = $this->platform->getAutoIncrementSQL();
        }

        // UNIQUE (inline)
        if ($column->getCustomOption('unique') && $this->platform->supportsInlineUnique()) {
            $modifiers[] = 'UNIQUE';
        }

        // ON UPDATE (MySQL only)
        $onUpdate = $column->getCustomOption('on_update');
        if ($onUpdate && $this->platform->getName() === 'mysql') {
            $modifiers[] = 'ON UPDATE ' . $onUpdate;
        }

        // COMMENT 
        // PostgreSQL uses separate COMMENT ON statements, not inline COMMENT
        if ($column->getComment() && $this->platform->supportsColumnComments()) {
            // Only add inline comments for platforms that support them (MySQL)
            if ($this->platform->getName() === 'mysql') {
                $modifiers[] = "COMMENT " . $this->platform->quoteValue($column->getComment());
            }
            // PostgreSQL comments will be handled in separate COMMENT ON statements
        }

        return $modifiers ? ' ' . implode(' ', $modifiers) : '';
    }


    /**
     * Render default value with proper formatting
     */
    protected function renderDefaultValue(Column $column): string
    {
        if ($column->getCustomOption('is_array') && $this->platform->getName() === 'postgresql') {
            return $this->platform->getDefaultValueSQL($column);
        }

        $default = $column->getDefault();

        // NULL default
        if ($default === null) {
            return 'NULL';
        }

        // Boolean values
        if (is_bool($default)) {
            return $this->platform->getBooleanLiteralSQL($default);
        }

        // Numeric values
        if (is_numeric($default)) {
            return (string)$default;
        }

        // Expression defaults (not quoted)
        $expressions = [
            'CURRENT_TIMESTAMP',
            'CURRENT_DATE',
            'CURRENT_TIME',
            'NULL',
            'TRUE',
            'FALSE'
        ];

        // Check if it's an expression (case-insensitive)
        $defaultUpper = strtoupper(trim($default));
        if (in_array($defaultUpper, $expressions)) {
            return $defaultUpper;
        }

        // Check for function calls (e.g., UUID(), gen_random_uuid())
        if (preg_match('/^\w+\s*\(.*\)$/i', trim($default))) {
            return $default;
        }

        // Handle PostgreSQL ARRAY[] syntax
        if (str_starts_with(trim($default), 'ARRAY[')) {
            return trim($default); // Don't quote ARRAY[] expressions
        }

        // Clean up already-quoted values before re-quoting
        $cleaned = trim($default);

        // If value is already quoted with single quotes, remove them first
        if (preg_match('/^\'(.*)\'$/', $cleaned, $matches)) {
            $cleaned = $matches[1];
        }

        // Handle double-quoted strings (remove outer quotes)
        if (preg_match('/^"(.*)"$/', $cleaned, $matches)) {
            $cleaned = $matches[1];
        }

        // Handle already triple-quoted strings (clean them up)
        if (preg_match('/^\'\'\'(.*)\'\'\'$/', $cleaned, $matches)) {
            $cleaned = $matches[1];
        }

        // String default - quote it
        return $this->platform->quoteValue($cleaned);
    }

    /**
     * Check if column is using SERIAL type
     */
    protected function isSerialType(Column $column): bool
    {
        $serialTypes = ['serial', 'bigserial', 'smallserial'];
        return in_array($column->getType(), $serialTypes);
    }

    /**
     * Should render index inline in CREATE TABLE?
     */
    protected function shouldRenderIndexInline(Index $index): bool
    {
        // PRIMARY KEY and UNIQUE in SQLite must be inline
        if ($this->platform->getName() === 'sqlite') {
            return in_array($index->getType(), [Index::TYPE_PRIMARY, Index::TYPE_UNIQUE]);
        }

        // For other databases, PRIMARY KEY is usually inline
        return $index->getType() === Index::TYPE_PRIMARY;
    }

    /**
     * Should render constraint inline in CREATE TABLE?
     */
    public function shouldRenderConstraintInline(Constraint $constraint): bool
    {
        return $constraint->getType() !== Constraint::TYPE_FOREIGN_KEY;
    }

    /**
     * Render inline index definition
     */
    protected function renderInlineIndex(Index $index, Table $table): string
    {
        switch ($index->getType()) {
            case Index::TYPE_PRIMARY:
                //return $this->renderPrimaryKey($index, $table);
                if ($this->platform->getName() === 'sqlite') {
                    return '';
                } else {
                    return $this->renderPrimaryKey($index, $table);
                }

            case Index::TYPE_UNIQUE:
                if ($this->platform->getName() === 'sqlite') {
                    // SQLite inline UNIQUE
                    $columns = $this->renderIndexColumns($index);
                    return 'UNIQUE (' . $columns . ')';
                }
                // Other databases use CONSTRAINT syntax
                return 'CONSTRAINT ' . $this->platform->quoteIdentifier($index->getName()) .
                    ' UNIQUE (' . $this->renderIndexColumns($index) . ')';

            default:
                return '';
        }
    }

    /**
     * Render PRIMARY KEY definition
     */
    protected function renderPrimaryKey(Index $index, Table $table): string
    {
        $columns = $this->renderIndexColumns($index);

        $name = $index->getName();
        if (empty($name) || $name === 'PRIMARY') {
            $name = $table->getName() . '_pkey';
        }

        return 'CONSTRAINT ' . $this->platform->quoteIdentifier($name) .
            ' PRIMARY KEY (' . $columns . ')';
    }

    /**
     * Render index columns
     */
    protected function renderIndexColumns(Index $index): string
    {
        $columns = [];

        foreach ($index->getColumns() as $columnName => $options) {
            $col = $this->platform->quoteIdentifier($columnName);

            // Add length if specified (MySQL)
            if (isset($options['length']) && $this->platform->supportsIndexLength()) {
                $col .= '(' . $options['length'] . ')';
            }

            // Add direction if specified
            if (isset($options['direction'])) {
                $col .= ' ' . $options['direction'];
            }

            $columns[] = $col;
        }

        return implode(', ', $columns);
    }

    /**
     * Render inline constraint definition
     */
    protected function renderInlineConstraint(Constraint $constraint): string
    {
        $sql = 'CONSTRAINT ' . $this->platform->quoteIdentifier($constraint->getName());

        switch ($constraint->getType()) {
            case Constraint::TYPE_FOREIGN_KEY:
                $sql .= ' FOREIGN KEY (' . $this->renderConstraintColumns($constraint->getColumns()) . ')';
                $sql .= ' REFERENCES ' . $this->platform->quoteIdentifier($constraint->getReferencedTable());
                $sql .= ' (' . $this->renderConstraintColumns($constraint->getReferencedColumns()) . ')';

                // Add actions
                if ($constraint->getOnDelete()) {
                    $sql .= ' ON DELETE ' . $constraint->getOnDelete();
                }
                if ($constraint->getOnUpdate()) {
                    $sql .= ' ON UPDATE ' . $constraint->getOnUpdate();
                }
                break;

            case Constraint::TYPE_CHECK:
                // Fix CHECK constraint expression quoting
                $expression = $constraint->getExpression();
                $expression = $this->fixCheckExpressionQuoting($expression);

                // NEW: Trim trailing commas and ensure balanced parentheses
                $expression = rtrim($expression, ', ');  // Remove trailing comma/space
                if (substr_count($expression, '(') > substr_count($expression, ')')) {
                    $expression .= ')';  // Append missing closing parenthesis if unbalanced
                }

                $sql .= ' CHECK (' . $expression . ')';
                break;

            case Constraint::TYPE_UNIQUE:
                $sql .= ' UNIQUE (' . $this->renderConstraintColumns($constraint->getColumns()) . ')';
                break;
        }

        return $sql;
    }


    /**
     * Fix quoting in CHECK constraint expressions
     */
    protected function fixCheckExpressionQuoting(string $expression): string
    {
        // Replace backticks with proper quotes for the platform
        if ($this->platform->getName() !== 'mysql') {
            $expression = str_replace('`', '"', $expression);
        }

        // For SQLite, we might want to remove quotes entirely from identifiers
        if ($this->platform->getName() === 'sqlite') {
            // Simple column names don't need quotes in CHECK constraints
            $expression = preg_replace('/["`](\w+)["`]/', '$1', $expression);
        }

        return $expression;
    }

    /**
     * Render constraint columns
     */
    protected function renderConstraintColumns(array $columns): string
    {
        $quoted = array_map([$this->platform, 'quoteIdentifier'], $columns);
        return implode(', ', $quoted);
    }

    /**
     * Render CREATE INDEX statements (separate from CREATE TABLE)
     */
    public function renderIndexes(Table $table): array
    {
        $statements = [];

        foreach ($table->getIndexes() as $index) {
            if (!$this->shouldRenderIndexInline($index)) {
                $sql = $this->renderCreateIndex($table, $index);
                if ($sql) {
                    $statements[] = rtrim($sql, ';') . ';';
                }
            }
        }

        return $statements;
    }

    /**
     * Render separate constraint statements (following the index pattern)
     */
    public function renderConstraints(Table $table): array
    {
        $statements = [];
        $constraintCount = 0;

        foreach ($table->getConstraints() as $constraint) {
            // Only render constraints that shouldn't be inline
            if (!$this->shouldRenderConstraintInline($constraint)) {
                $sql = $this->renderAlterTableConstraint($table, $constraint);
                if ($sql) {
                    $statements[] = rtrim($sql, ';') . ';';
                    $constraintCount++;
                }
            }
        }

        // Debug output
        if ($constraintCount > 0) {
            $this->debug("Rendered $constraintCount constraint(s) for table: " . $table->getName());
        }

        return $statements;
    }

    /**
     * Render foreign key constraints specifically
     * 
     * This method can be used to render only foreign key constraints from a table,
     * which is useful for the two-pass rendering approach.
     */
    public function renderForeignKeyConstraints(Table $table): array
    {
        $statements = [];

        foreach ($table->getConstraints() as $constraint) {
            if ($constraint->isForeignKey() && !$this->shouldRenderConstraintInline($constraint)) {
                $sql = $this->renderAlterTableConstraint($table, $constraint);
                if ($sql) {
                    $statements[] = rtrim($sql, ';') . ';';
                }
            }
        }

        return $statements;
    }

    /**
     * Render ALTER TABLE ADD CONSTRAINT statement
     */
    public function renderAlterTableConstraint(Table $table, Constraint $constraint): string
    {
        // Validate constraint before rendering
        if (!$this->validateConstraintForRendering($constraint)) {
            $this->addWarning("Skipping invalid constraint: " . $constraint->getName());
            return '';
        }

        $sql = 'ALTER TABLE ' . $this->platform->quoteIdentifier($table->getName());
        $sql .= ' ADD CONSTRAINT ' . $this->platform->quoteIdentifier($constraint->getName());

        switch ($constraint->getType()) {
            case Constraint::TYPE_FOREIGN_KEY:
                $sql .= ' FOREIGN KEY (' . $this->renderConstraintColumns($constraint->getColumns()) . ')';
                $sql .= ' REFERENCES ' . $this->platform->quoteIdentifier($constraint->getReferencedTable());
                $sql .= ' (' . $this->renderConstraintColumns($constraint->getReferencedColumns()) . ')';

                // Add actions with validation
                if ($constraint->getOnDelete()) {
                    $onDelete = $this->validateForeignKeyAction($constraint->getOnDelete(), 'DELETE');
                    if ($onDelete) {
                        $sql .= ' ON DELETE ' . $onDelete;
                    }
                }

                if ($constraint->getOnUpdate()) {
                    $onUpdate = $this->validateForeignKeyAction($constraint->getOnUpdate(), 'UPDATE');
                    if ($onUpdate) {
                        $sql .= ' ON UPDATE ' . $onUpdate;
                    }
                }
                break;

            case Constraint::TYPE_UNIQUE:
                $sql .= ' UNIQUE (' . $this->renderConstraintColumns($constraint->getColumns()) . ')';
                break;

            case Constraint::TYPE_CHECK:
                $expression = $constraint->getExpression();
                if ($expression) {
                    $sql .= ' CHECK (' . $this->fixCheckExpressionQuoting($expression) . ')';
                } else {
                    $this->addWarning("CHECK constraint '{$constraint->getName()}' has no expression");
                    return '';
                }
                break;

            default:
                $this->addWarning("Unknown constraint type: " . $constraint->getType());
                return '';
        }

        return $sql;
    }

    /**
     * Render CREATE INDEX statement
     */
    protected function renderCreateIndex(Table $table, Index $index): string
    {
        // Skip unsupported index types
        if (!$index->isSupportedBy($this->platform->getName())) {
            return '';
        }

        $sql = 'CREATE';

        // Add UNIQUE
        if ($index->getType() === Index::TYPE_UNIQUE) {
            $sql .= ' UNIQUE';
        }

        $sql .= ' INDEX ' . $this->platform->quoteIdentifier($index->getName());
        $sql .= ' ON ' . $this->platform->quoteIdentifier($table->getName());

        // Add USING clause for PostgreSQL
        if ($this->platform->getName() === 'postgresql' && $index->getMethod()) {
            $sql .= ' USING ' . $index->getMethod();
        }

        $sql .= ' (' . $this->renderIndexColumns($index) . ')';

        // Add WHERE clause for partial indexes
        if ($index->getWhere() && $this->platform->supportsPartialIndexes()) {
            $sql .= ' WHERE ' . $index->getWhere();
        } elseif ($index->getWhere()) {
            $this->addWarning("Partial index '{$index->getName()}' not supported, WHERE clause removed");
        }

        return $sql . ';';
    }

    /**
     * Render table options (MySQL specific)
     */
    protected function renderTableOptions(Table $table): string
    {
        $options = [];

        if ($table->getEngine()) {
            $options[] = 'ENGINE=' . $table->getEngine();
        }

        if ($table->getCharset()) {
            $options[] = 'DEFAULT CHARSET=' . $table->getCharset();
        }

        if ($table->getCollation()) {
            $options[] = 'COLLATE=' . $table->getCollation();
        }

        if ($table->getComment()) {
            $options[] = 'COMMENT=' . $this->platform->quoteValue($table->getComment());
        }

        $autoIncrementStart = $table->getOption('auto_increment_start');
        if ($autoIncrementStart) {
            $options[] = 'AUTO_INCREMENT=' . $autoIncrementStart;
        }

        return implode(' ', $options);
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT Statement Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Render INSERT statements for table data
     *
     * @param Table $table Table schema object  
     * @param array $data Array of row data (each row is associative array)
     * @param array $options Rendering options
     * @return array Array of INSERT SQL statements
     */
    public function renderInserts(Table $table, array $data, array $options = []): array
    {
        if (empty($data)) {
            return [];
        }

        $options = array_merge([
            'conflict_handling' => 'error', // 'error', 'update', 'skip'
            'batch_size' => 1000,
            'include_column_names' => true,
            'chunk_large_data' => true
        ], $options);

        $this->debug("Rendering INSERT statements for table: " . $table->getName(), [
            'rows_count' => count($data),
            'batch_size' => $options['batch_size'],
            'conflict_handling' => $options['conflict_handling']
        ]);

        $sql = [];

        if ($options['chunk_large_data'] && count($data) > $options['batch_size']) {
            // Process in chunks for large datasets
            $chunks = array_chunk($data, $options['batch_size']);

            foreach ($chunks as $chunkIndex => $chunk) {
                $chunkSQL = $this->renderSingleInsert($table, $chunk, $options);
                if (!empty($chunkSQL)) {
                    $sql[] = $chunkSQL;
                }
            }
        } else {
            // Single INSERT for smaller datasets
            $singleSQL = $this->renderSingleInsert($table, $data, $options);
            if (!empty($singleSQL)) {
                $sql[] = $singleSQL;
            }
        }

        $this->debug("Generated INSERT statements", [
            'statements_count' => count($sql),
            'total_rows' => count($data)
        ]);

        return $sql;
    }

    /**
     * Render single INSERT statement for multiple rows
     *
     * @param Table $table Table schema object
     * @param array $rows Array of row data 
     * @param array $options Rendering options
     * @return string INSERT SQL statement
     */
    private function renderSingleInsert(Table $table, array $rows, array $options): string
    {
        if (empty($rows)) {
            return '';
        }

        // Determine columns to insert (from first row or all table columns)
        $firstRow = reset($rows);
        $insertColumns = $options['include_column_names']
            ? array_keys($firstRow)
            : array_keys($firstRow);

        // Validate columns exist in table
        $validColumns = [];
        foreach ($insertColumns as $columnName) {
            if ($table->hasColumn($columnName)) {
                $validColumns[] = $columnName;
            } else {
                $this->addWarning("Column '$columnName' not found in table '{$table->getName()}', skipping");
            }
        }

        if (empty($validColumns)) {
            $this->addWarning("No valid columns found for INSERT into table '{$table->getName()}'");
            return '';
        }

        // Build base INSERT statement
        $tableName = $this->platform->quoteIdentifier($table->getName());
        $columnsList = implode(', ', array_map([$this->platform, 'quoteIdentifier'], $validColumns));

        $sql = "INSERT INTO {$tableName}";

        if ($options['include_column_names']) {
            $sql .= " ({$columnsList})";
        }

        $sql .= " VALUES ";

        // Build VALUES clauses
        $valuesClauses = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($validColumns as $columnName) {
                $value = isset($row[$columnName]) ? $row[$columnName] : null;
                $values[] = $this->platform->quoteValue($value, $table->getColumn($columnName));
            }
            $valuesClauses[] = '(' . implode(', ', $values) . ')';
        }

        $sql .= implode(', ', $valuesClauses);

        // Add conflict handling if requested
        if ($options['conflict_handling'] !== 'error') {
            $sql = $this->addConflictHandling($sql, $table, $validColumns, $options);
        }

        return $sql . ';';
    }

    /**
     * Add conflict handling to INSERT statement
     *
     * @param string $sql Base INSERT statement
     * @param Table $table Table schema object
     * @param array $columns Columns being inserted
     * @param array $options Options including conflict_handling type
     * @return string INSERT statement with conflict handling
     */
    private function addConflictHandling(string $sql, Table $table, array $columns, array $options): string
    {
        $conflictHandling = $options['conflict_handling'];
        $platformName = $this->platform->getName();

        // Get primary key columns for conflict target
        $primaryKeyColumns = $this->getPrimaryKeyColumns($table);

        if (empty($primaryKeyColumns)) {
            $this->addWarning("No primary key found for table '{$table->getName()}', conflict handling disabled");
            return $sql;
        }

        switch ($conflictHandling) {
            case 'update':
                return $this->addUpdateConflictHandling($sql, $table, $columns, $primaryKeyColumns, $platformName);

            case 'skip':
                return $this->addSkipConflictHandling($sql, $platformName);

            default:
                return $sql;
        }
    }

    /**
     * Add UPDATE conflict handling (ON DUPLICATE KEY UPDATE, ON CONFLICT DO UPDATE)
     *
     * @param string $sql Base INSERT statement  
     * @param Table $table Table schema object
     * @param array $columns Columns being inserted
     * @param array $primaryKeyColumns Primary key column names
     * @param string $platformName Database platform name
     * @return string INSERT statement with UPDATE conflict handling
     */
    private function addUpdateConflictHandling(string $sql, Table $table, array $columns, array $primaryKeyColumns, string $platformName): string
    {
        switch ($platformName) {
            case 'mysql':
                // MySQL: ON DUPLICATE KEY UPDATE
                $updateList = [];
                foreach ($columns as $column) {
                    if (!in_array($column, $primaryKeyColumns)) { // Don't update PK columns
                        $quotedCol = $this->platform->quoteIdentifier($column);
                        $updateList[] = "$quotedCol = VALUES($quotedCol)";
                    }
                }

                if (!empty($updateList)) {
                    $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updateList);
                }
                break;

            case 'postgresql':
                // PostgreSQL: ON CONFLICT DO UPDATE
                $conflictTarget = implode(', ', array_map([$this->platform, 'quoteIdentifier'], $primaryKeyColumns));
                $updateList = [];

                foreach ($columns as $column) {
                    if (!in_array($column, $primaryKeyColumns)) {
                        $quotedCol = $this->platform->quoteIdentifier($column);
                        $updateList[] = "$quotedCol = EXCLUDED.$quotedCol";
                    }
                }

                if (!empty($updateList)) {
                    $sql .= " ON CONFLICT ($conflictTarget) DO UPDATE SET " . implode(', ', $updateList);
                }
                break;

            case 'sqlite':
                // SQLite: ON CONFLICT DO UPDATE  
                $conflictTarget = implode(', ', array_map([$this->platform, 'quoteIdentifier'], $primaryKeyColumns));
                $updateList = [];

                foreach ($columns as $column) {
                    if (!in_array($column, $primaryKeyColumns)) {
                        $quotedCol = $this->platform->quoteIdentifier($column);
                        $updateList[] = "$quotedCol = EXCLUDED.$quotedCol";
                    }
                }

                if (!empty($updateList)) {
                    $sql .= " ON CONFLICT ($conflictTarget) DO UPDATE SET " . implode(', ', $updateList);
                }
                break;

            default:
                $this->addWarning("UPDATE conflict handling not supported for platform: $platformName");
                break;
        }

        return $sql;
    }

    /**
     * Add SKIP conflict handling (INSERT IGNORE, ON CONFLICT DO NOTHING)
     *
     * @param string $sql Base INSERT statement
     * @param string $platformName Database platform name  
     * @return string INSERT statement with SKIP conflict handling
     */
    private function addSkipConflictHandling(string $sql, string $platformName): string
    {
        switch ($platformName) {
            case 'mysql':
                // MySQL: INSERT IGNORE
                $sql = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sql);
                break;

            case 'postgresql':
                // PostgreSQL: ON CONFLICT DO NOTHING
                $sql .= " ON CONFLICT DO NOTHING";
                break;

            case 'sqlite':
                // SQLite: INSERT OR IGNORE
                $sql = str_replace('INSERT INTO', 'INSERT OR IGNORE INTO', $sql);
                break;

            default:
                $this->addWarning("SKIP conflict handling not supported for platform: $platformName");
                break;
        }

        return $sql;
    }


    /*
    |--------------------------------------------------------------------------
    | Validation Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Validate constraint before rendering
     */
    protected function validateConstraintForRendering(Constraint $constraint): bool
    {
        // Check if constraint has required properties
        if (empty($constraint->getName())) {
            return false;
        }

        if (empty($constraint->getColumns())) {
            return false;
        }

        // Foreign key specific validation
        if ($constraint->isForeignKey()) {
            if (empty($constraint->getReferencedTable())) {
                return false;
            }

            if (empty($constraint->getReferencedColumns())) {
                return false;
            }

            // Validate column counts match
            if (count($constraint->getColumns()) !== count($constraint->getReferencedColumns())) {
                return false;
            }
        }

        // CHECK constraint validation
        if ($constraint->isCheck() && empty($constraint->getExpression())) {
            return false;
        }

        return true;
    }

    /**
     * NEW METHOD: Validate foreign key actions
     */
    protected function validateForeignKeyAction(?string $action, string $actionType): ?string
    {
        if (!$action) {
            return null;
        }

        $validActions = [
            'CASCADE',
            'SET NULL',
            'SET DEFAULT',
            'RESTRICT',
            'NO ACTION'
        ];

        $upperAction = strtoupper($action);

        if (!in_array($upperAction, $validActions)) {
            $this->addWarning("Invalid foreign key $actionType action: $action");
            return null;
        }

        // Platform-specific validation
        $platformName = $this->platform->getName();

        if ($platformName === 'sqlite' && $upperAction === 'SET DEFAULT') {
            $this->addWarning("SQLite does not support SET DEFAULT for foreign keys, using SET NULL instead");
            return 'SET NULL';
        }

        return $upperAction;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get primary key column names from table
     *
     * @param Table $table Table schema object
     * @return array Array of primary key column names
     */
    private function getPrimaryKeyColumns(Table $table): array
    {
        $primaryKeyColumns = [];

        // Check for PRIMARY KEY index
        foreach ($table->getIndexes() as $index) {
            if ($index->isPrimary()) {
                $primaryKeyColumns = $index->getColumnNames();
                break;
            }
        }

        // Fallback: check for columns marked as primary key
        if (empty($primaryKeyColumns)) {
            foreach ($table->getColumns() as $column) {
                if (
                    $column->getCustomOption('primary_key') === true ||
                    $column->getCustomOption('is_primary_key') === true
                ) {
                    $primaryKeyColumns[] = $column->getName();
                }
            }
        }

        return $primaryKeyColumns;
    }

    /*
    |--------------------------------------------------------------------------
    | Utility Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Add warning
     */
    protected function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
        $this->debug("Renderer warning: $warning");
    }

    /**
     * Get warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Debug logging
     */
    protected function debug(string $message, array $context = []): void
    {
        if ($this->debugCallback !== null) {
            call_user_func($this->debugCallback, $message, $context);
        }
    }
}
