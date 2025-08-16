<?php

require_once dirname(__DIR__) . '/schema/Column.php';
require_once dirname(__DIR__) . '/schema/Constraint.php';
require_once dirname(__DIR__) . '/schema/Index.php';
require_once dirname(__DIR__) . '/schema/Table.php';

/**
 * Transforms database-agnostic schema objects to a specific platform's conventions.
 *
 * The SchemaTransformer is a specialized class responsible for the detailed,
 * object-level conversion of a schema. It takes language-agnostic schema objects
 * (Table, Column, Index, Constraint) and modifies them to be compatible with the
 * syntax, data types, and features of a target database platform.
 *
 * This class works in concert with the SchemaTranslator. While the Translator
 * handles the high-level, end-to-end process of converting raw SQL, the
 * Transformer focuses on the "nitty-gritty" details of the conversion, such as:
 * - Mapping data types (e.g., MySQL's 'TINYINT' to PostgreSQL's 'SMALLINT').
 * - Converting or emulating features (e.g., ENUMs, AUTO_INCREMENT).
 * - Transforming default values and constraints.
 * - Generating warnings for unsupported features.
 *
 * @package Database\Schema\Core
 * @author Enhanced Model System
 * @version 2.0.0
 */
class SchemaTransformer
{
    private array $options;
    private array $warnings = [];
    private array $postTransformActions = [];
    private $debugCallback = null;

    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'fulltext_strategy' => 'convert', // 'convert' or 'remove' 
            'sqlite_fts_version' => 'fts5',   // 'fts5', 'fts4', or 'fts3'
            'postgresql_language' => 'english' // Language for PostgreSQL full-text search
        ], $options);
    }

    public function setDebugCallback(?callable $callback): void
    {
        $this->debugCallback = $callback;
    }

    /**
     * Transform table for target database
     */
    public function transformTable(Table $table, string $sourceDB, string $targetDB): Table
    {
        $this->warnings = [];

        $this->debug("Transforming table '{$table->getName()}' from $sourceDB to $targetDB");

        // Clone table to avoid modifying original
        $transformed = clone $table;

        // Transform columns
        $this->transformColumns($transformed, $sourceDB, $targetDB);

        // Transform indexes
        $this->transformIndexes($transformed, $sourceDB, $targetDB);

        // Transform constraints
        $this->transformConstraints($transformed, $sourceDB, $targetDB);

        // Transform table options
        $this->transformTableOptions($transformed, $sourceDB, $targetDB);

        // Handle special features that need post-processing
        $this->handleSpecialFeatures($transformed, $sourceDB, $targetDB);

        return $transformed;
    }

    /**
     * Transform columns
     */
    private function transformColumns(Table $table, string $sourceDB, string $targetDB): void
    {
        foreach ($table->getColumns() as $column) {
            $this->transformColumn($column, $sourceDB, $targetDB, $table);
        }
    }

    /**
     * Transform individual column
     */
    private function transformColumn(Column $column, string $sourceDB, string $targetDB, Table $table): void
    {
        $column->setOriginalType($column->getType());
        $originalType = $column->getType();

        // Transform data type
        $newType = $this->transformDataType($column, $sourceDB, $targetDB);
        if ($newType !== $originalType) {
            $column->setType($newType);
            $this->debug("Column '{$column->getName()}': type changed from '$originalType' to '$newType'");
        }

        // Handle ENUM transformation
        if ($originalType === 'enum' && $targetDB !== 'mysql') {
            $this->transformEnumColumn($column, $targetDB, $table);
        }

        // Handle SET transformation
        if ($originalType === 'set') {
            $this->transformSetColumn($column, $sourceDB, $targetDB, $table);
        }

        // Handle AUTO_INCREMENT transformation
        if ($column->isAutoIncrement()) {
            $this->transformAutoIncrementColumn($column, $targetDB, $table);
        }

        // Transform default values
        $this->transformDefaultValue($column, $targetDB);

        // Handle unsigned integers
        if ($column->isUnsigned()) {
            if ($targetDB === 'sqlite') {
                $this->addWarning("Column '{$column->getName()}': UNSIGNED attribute removed (not supported in SQLite)");
                $column->setUnsigned(false);
            } elseif ($targetDB === 'postgresql') {
                $this->addWarning("Column '{$column->getName()}': UNSIGNED attribute removed (PostgreSQL uses signed integers)");
                $column->setUnsigned(false);
            }
        }

        // Handle array types
        if ($column->getCustomOption('is_array')) {
            $this->transformArrayColumn($column, $sourceDB, $targetDB);
        }

        // Handle ON UPDATE CURRENT_TIMESTAMP
        if ($column->getCustomOption('on_update') === 'CURRENT_TIMESTAMP') {
            if ($targetDB !== 'mysql') {
                $this->addWarning("Column '{$column->getName()}': ON UPDATE CURRENT_TIMESTAMP requires a trigger");
                $table->setOption('needs_update_trigger', true);
            }
        }
    }

    /**
     * Transform data type
     */
    private function transformDataType(Column $column, string $sourceDB, string $targetDB): string
    {
        $type = $column->getType();

        // Handle UUID specially
        if (strtolower($type) === 'uuid') {
            if ($targetDB === 'mysql') {
                $column->setLength(36);
                return 'char';
            } elseif ($targetDB === 'sqlite') {
                return 'text';
            }
        }

        // MySQL to SQLite transformations
        if ($sourceDB === 'mysql' && $targetDB === 'sqlite') {
            return $this->mysqlToSqliteType($type);
        }

        // MySQL to PostgreSQL transformations
        if ($sourceDB === 'mysql' && $targetDB === 'postgresql') {
            return $this->mysqlToPostgreSQLType($type);
        }

        // PostgreSQL to MySQL transformations
        if ($sourceDB === 'postgresql' && $targetDB === 'mysql') {
            return $this->postgreSQLToMysqlType($type);
        }

        // PostgreSQL to SQLite transformations
        if ($sourceDB === 'postgresql' && $targetDB === 'sqlite') {
            return $this->postgreSQLToSqliteType($type);
        }

        // SQLite to MySQL transformations
        if ($sourceDB === 'sqlite' && $targetDB === 'mysql') {
            return $this->sqliteToMysqlType($type);
        }

        // SQLite to PostgreSQL transformations
        if ($sourceDB === 'sqlite' && $targetDB === 'postgresql') {
            return $this->sqliteToPostgreSQLType($type);
        }

        return $type;
    }

    /**
     * MySQL to SQLite type mapping
     */
    private function mysqlToSqliteType(string $type): string
    {
        $mapping = [
            'tinyint' => 'integer',
            'smallint' => 'integer',
            'mediumint' => 'integer',
            'int' => 'integer',
            'bigint' => 'integer',
            'decimal' => 'real',
            'numeric' => 'real',
            'float' => 'real',
            'double' => 'real',
            'bit' => 'integer',

            'char' => 'text',
            'varchar' => 'text',
            'tinytext' => 'text',
            'text' => 'text',
            'mediumtext' => 'text',
            'longtext' => 'text',
            'enum' => 'text',
            'set' => 'text',

            'binary' => 'blob',
            'varbinary' => 'blob',
            'tinyblob' => 'blob',
            'blob' => 'blob',
            'mediumblob' => 'blob',
            'longblob' => 'blob',

            'date' => 'text',
            'datetime' => 'text',
            'timestamp' => 'text',
            'time' => 'text',
            'year' => 'text',

            'json' => 'text',
            'boolean' => 'integer',
            'bool' => 'integer'
        ];

        return $mapping[strtolower($type)] ?? 'text';
    }

    /**
     * MySQL to PostgreSQL type mapping
     */
    private function mysqlToPostgreSQLType(string $type): string
    {
        $mapping = [
            'tinyint' => 'smallint',
            'mediumint' => 'integer',
            'int' => 'integer',
            'double' => 'double precision',
            'tinytext' => 'text',
            'mediumtext' => 'text',
            'longtext' => 'text',
            'datetime' => 'timestamp',
            'enum' => 'text',
            'set' => 'text',  // Will be converted to array later
            'binary' => 'bytea',
            'varbinary' => 'bytea',
            'tinyblob' => 'bytea',
            'blob' => 'bytea',
            'mediumblob' => 'bytea',
            'longblob' => 'bytea',
            'year' => 'smallint'
        ];

        return $mapping[strtolower($type)] ?? $type;
    }

    /**
     * PostgreSQL to MySQL type mapping
     */
    private function postgreSQLToMysqlType(string $type): string
    {
        $mapping = [
            'serial' => 'int',
            'bigserial' => 'bigint',
            'smallserial' => 'smallint',
            'money' => 'decimal',
            'bytea' => 'longblob',
            'timestamp with time zone' => 'datetime',
            'timestamp without time zone' => 'datetime',
            'timestamptz' => 'datetime',
            'interval' => 'time',
            'boolean' => 'tinyint',
            'uuid' => 'char',
            'jsonb' => 'json',
            'double precision' => 'double',
            'real' => 'float',
            'numeric' => 'decimal'
        ];

        // Handle array types
        if (preg_match('/^(.+)\[\]$/i', $type, $matches)) {
            return 'json';  // Convert arrays to JSON
        }

        return $mapping[strtolower($type)] ?? $type;
    }

    /**
     * PostgreSQL to SQLite type mapping
     */
    private function postgreSQLToSqliteType(string $type): string
    {
        $mapping = [
            'serial' => 'integer',
            'bigserial' => 'integer',
            'smallserial' => 'integer',
            'smallint' => 'integer',
            'bigint' => 'integer',
            'decimal' => 'real',
            'numeric' => 'real',
            'double precision' => 'real',
            'real' => 'real',
            'money' => 'real',
            'character varying' => 'text',
            'varchar' => 'text',
            'character' => 'text',
            'char' => 'text',
            'bytea' => 'blob',
            'timestamp' => 'text',
            'timestamp with time zone' => 'text',
            'timestamp without time zone' => 'text',
            'timestamptz' => 'text',
            'date' => 'text',
            'time' => 'text',
            'interval' => 'text',
            'boolean' => 'integer',
            'uuid' => 'text',
            'json' => 'text',
            'jsonb' => 'text'
        ];

        // Handle array types
        if (preg_match('/^(.+)\[\]$/i', $type)) {
            return 'text';
        }

        return $mapping[strtolower($type)] ?? 'text';
    }

    /**
     * SQLite to MySQL type mapping
     */
    private function sqliteToMysqlType(string $type): string
    {
        $mapping = [
            'integer' => 'int',
            'real' => 'double',
            'text' => 'text',
            'blob' => 'blob',
            'numeric' => 'decimal'
        ];

        return $mapping[strtolower($type)] ?? $type;
    }

    /**
     * SQLite to PostgreSQL type mapping
     */
    private function sqliteToPostgreSQLType(string $type): string
    {
        $mapping = [
            'integer' => 'integer',
            'real' => 'double precision',
            'text' => 'text',
            'blob' => 'bytea',
            'numeric' => 'numeric'
        ];

        return $mapping[strtolower($type)] ?? $type;
    }

    /**
     * Transform ENUM column
     */
    private function transformEnumColumn(Column $column, string $targetDB, Table $table): void
    {
        $enumValues = $column->getEnumValues();

        if (empty($enumValues)) {
            return;
        }

        $column->setType('text');

        if ($this->options['enum_conversion'] === 'text_with_check') {
            // Create a CHECK constraint for ENUM values
            $quotedValues = array_map(function ($val) {
                return "'" . str_replace("'", "''", $val) . "'";
            }, $enumValues);

            $constraintName = $table->getName() . '_' . $column->getName() . '_check';
            $expression = $column->getName() . ' IN (' . implode(', ', $quotedValues) . ')';

            $constraint = new Constraint($constraintName, Constraint::TYPE_CHECK);
            $constraint->setExpression($expression);

            $table->addConstraint($constraint);

            $this->addWarning(
                "Column '{$column->getName()}': ENUM converted to TEXT with CHECK constraint"
            );
        } else {
            $this->addWarning(
                "Column '{$column->getName()}': ENUM converted to TEXT (values: " .
                    implode(', ', $enumValues) . ")"
            );
        }
    }

    /**
     * Transform SET column
     */
    private function transformSetColumn(Column $column, string $sourceDB, string $targetDB, Table $table): void
    {
        $setValues = $column->getEnumValues(); // SET uses same storage as ENUM

        if ($targetDB === 'postgresql') {
            // Convert to TEXT[] array type
            $column->setType('text');
            $column->setCustomOption('is_array', true);

            // Handle default values properly for arrays
            $defaultValue = $column->getDefault();
            if ($defaultValue && $defaultValue !== 'NULL') {
                // Clean up quotes but don't add ARRAY[] - platform will do it
                $cleanDefault = trim($defaultValue, " '\"");
                $column->setDefault($cleanDefault);
            }

            $this->addWarning(
                "Column '{$column->getName()}': SET converted to TEXT[] (values as array)"
            );
        } else {
            // Convert to TEXT for other databases
            $column->setType('text');

            if (!empty($setValues)) {
                $this->addWarning(
                    "Column '{$column->getName()}': SET converted to TEXT (values: " .
                        implode(', ', $setValues) . ")"
                );
            } else {
                $this->addWarning(
                    "Column '{$column->getName()}': SET converted to TEXT"
                );
            }
        }
    }

    /**
     * Transform array column
     */
    private function transformArrayColumn(Column $column, string $sourceDB, string $targetDB): void
    {
        if ($targetDB === 'mysql') {
            $column->setType('json');
            $column->setCustomOption('is_array', false);

            $this->addWarning(
                "Column '{$column->getName()}': Array type converted to JSON"
            );
        } elseif ($targetDB === 'sqlite') {
            $column->setType('text');
            $column->setCustomOption('is_array', false);

            $this->addWarning(
                "Column '{$column->getName()}': Array type converted to TEXT"
            );
        }
    }

    /**
     * Transform AUTO_INCREMENT column
     */
    private function transformAutoIncrementColumn(Column $column, string $targetDB, Table $table): void
    {
        if ($targetDB === 'sqlite') {
            // SQLite requires INTEGER PRIMARY KEY AUTOINCREMENT
            if (!$column->isNumericType() || $column->getType() !== 'integer') {
                $column->setType('integer');
                $this->addWarning(
                    "Column '{$column->getName()}': type changed to INTEGER for AUTOINCREMENT"
                );
            }

            // Check if it's part of primary key
            $primaryKey = $table->getPrimaryKey();
            if (!$primaryKey || !in_array($column->getName(), $primaryKey->getColumns())) {
                
                /*
                $this->addWarning(
                    "Column '{$column->getName()}': AUTOINCREMENT requires PRIMARY KEY in SQLite"
                );
                */
            }
        } elseif ($targetDB === 'postgresql') {
            // PostgreSQL uses SERIAL types
            $currentType = $column->getType();
            if ($currentType === 'bigint') {
                $column->setType('bigserial');
            } elseif ($currentType === 'smallint') {
                $column->setType('smallserial');
            } else {
                $column->setType('serial');
            }
            $column->setAutoIncrement(false); // SERIAL implies auto-increment
        }
    }

    /**
     * Transform default value
     */
    private function transformDefaultValue(Column $column, string $targetDB): void
    {
        $default = $column->getDefault();

        if ($default === null || is_numeric($default)) {
            return;
        }

        // Clean up default value
        $default = trim($default);

        // Remove extra quotes and spaces
        if (is_string($default)) {
            // Remove surrounding quotes if they're there
            $default = trim($default, "'\"");

            // Handle boolean values
            if (strtoupper($default) === 'TRUE' || $default === '1') {
                if ($column->getType() === 'integer' || $column->getType() === 'tinyint') {
                    $column->setDefault(1);
                } else {
                    $column->setDefault(true);
                }
                return;
            }

            if (strtoupper($default) === 'FALSE' || $default === '0') {
                if ($column->getType() === 'integer' || $column->getType() === 'tinyint') {
                    $column->setDefault(0);
                } else {
                    $column->setDefault(false);
                }
                return;
            }

            // Handle timestamp defaults
            if (preg_match('/CURRENT_TIMESTAMP/i', $default)) {
                $column->setDefault('CURRENT_TIMESTAMP');
                return;
            }

            // Handle function defaults
            if ($default === 'gen_random_uuid()' && $targetDB === 'mysql') {
                $column->setDefault('UUID()');
                $this->addWarning(
                    "Column '{$column->getName()}': gen_random_uuid() converted to UUID()"
                );
                return;
            }

            // Otherwise keep the cleaned default
            $column->setDefault($default);
        }
    }

    /**
     * Transform indexes
     */
    private function transformIndexes(Table $table, string $sourceDB, string $targetDB): void
    {
        foreach ($table->getIndexes() as $index) {
            // Remove unsupported indexes
            if (!$index->isSupportedBy($targetDB)) {
                $table->removeIndex($index->getName());

                // Add specific warning based on index type
                if ($index->getType() === Index::TYPE_FULLTEXT) {
                if ($this->options['fulltext_strategy'] === 'convert') {
                    $this->convertFulltextIndex($table, $index, $sourceDB, $targetDB);
                    $indexesToRemove[] = $index->getName();
                } else {
                    // Original behavior - just remove
                    $table->removeIndex($index->getName());
                    $this->addWarning(
                        "Index '{$index->getName()}': FULLTEXT index not supported in $targetDB, removed"
                    );
                }
            } elseif (!$index->isSupportedBy($targetDB)) {
                // Handle other unsupported indexes
                $table->removeIndex($index->getName());
                $this->addWarning(
                    "Index '{$index->getName()}': {$index->getType()} index not supported in $targetDB, removed"
                );
            }
            }

            // Warn about partial indexes
            if ($index->getWhere() && !in_array($targetDB, ['postgresql'])) {
                $this->addWarning(
                    "Index '{$index->getName()}': Partial index not supported in $targetDB, WHERE clause removed"
                );
                $index->setWhere(null);
            }
        }
    }

    /**
     * Transform constraints
     */
    private function transformConstraints(Table $table, string $sourceDB, string $targetDB): void
    {
        foreach ($table->getConstraints() as $constraint) {
            // Transform foreign key actions
            if ($constraint->isForeignKey()) {
                $this->transformForeignKeyActions($constraint, $targetDB);
            }

            // Handle EXCLUDE constraints (PostgreSQL specific)
            if ($constraint->getOption('is_exclude') && $targetDB !== 'postgresql') {
                $this->addWarning(
                    "Constraint '{$constraint->getName()}': EXCLUDE constraint not supported in $targetDB, converted to CHECK"
                );
            }
        }
    }

    /**
     * Transform foreign key actions
     */
    private function transformForeignKeyActions(Constraint $constraint, string $targetDB): void
    {
        if ($targetDB === 'sqlite') {
            if ($constraint->getOnDelete() === Constraint::ACTION_SET_DEFAULT) {
                $this->addWarning("Constraint '{$constraint->getName()}': ON DELETE SET DEFAULT changed to SET NULL");
                $constraint->setOnDelete(Constraint::ACTION_SET_NULL);
            }
            if ($constraint->getOnUpdate() === Constraint::ACTION_SET_DEFAULT) {
                $this->addWarning("Constraint '{$constraint->getName()}': ON UPDATE SET DEFAULT changed to SET NULL");
                $constraint->setOnUpdate(Constraint::ACTION_SET_NULL);
            }
        }
    }


    /**
     * Transform table options
     */
    private function transformTableOptions(Table $table, string $sourceDB, string $targetDB): void
    {
        // Remove MySQL-specific options for other databases
        if ($sourceDB === 'mysql' && $targetDB !== 'mysql') {
            if ($table->getEngine()) {
                $table->setEngine(null);
                $this->debug("Removed ENGINE option");
            }

            if ($table->getCharset()) {
                $table->setCharset(null);
                $this->debug("Removed CHARSET option");
            }

            if ($table->getCollation()) {
                $table->setCollation(null);
                $this->debug("Removed COLLATION option");
            }
        }
    }

    /**
     * Handle special features that need post-processing
     */
    private function handleSpecialFeatures(Table $table, string $sourceDB, string $targetDB): void
    {
        // Add trigger information for ON UPDATE CURRENT_TIMESTAMP
        if ($table->getOption('needs_update_trigger') && $targetDB === 'postgresql') {
            $columns = [];
            foreach ($table->getColumns() as $column) {
                if ($column->getCustomOption('on_update') === 'CURRENT_TIMESTAMP') {
                    $columns[] = $column->getName();
                }
            }

            if (!empty($columns)) {
                $table->setOption('update_trigger_columns', $columns);
            }
        }

        $triggerColumns = [];
        foreach ($table->getColumns() as $column) {
            if ($column->getCustomOption('on_update') === 'CURRENT_TIMESTAMP') {
                $triggerColumns[] = $column->getName();
                $table->setOption('needs_update_trigger', true);
                $table->setOption('update_trigger_columns', $triggerColumns);
            }
        }
    }

    /**
     * Convert to PostgreSQL tsvector + GIN index
     */
    private function convertToPostgreSQLFulltext(Table $table, Index $index, array $columns): void
    {
        $indexName = $index->getName();
        $tableName = $table->getName();
        $language = $this->options['postgresql_language'];

        if (count($columns) === 1) {
            // Single column - use expression index
            $columnName = $columns[0];
            $newIndex = new Index($indexName . '_gin', Index::TYPE_INDEX);
            $newIndex->setMethod('gin');
            $newIndex->addColumn($columnName);
            $newIndex->setExpression("to_tsvector('$language', $columnName)");
            
            $table->addIndex($newIndex);
            
            $this->addWarning(
                "Index '{$indexName}': FULLTEXT converted to PostgreSQL GIN index on to_tsvector('$language', $columnName)"
            );
            
            // Add post-transform action for index creation
            $this->postTransformActions[] = [
                'type' => 'postgresql_gin_index',
                'sql' => "CREATE INDEX {$indexName}_gin ON $tableName USING gin(to_tsvector('$language', $columnName));",
                'description' => "PostgreSQL GIN index for full-text search on $columnName"
            ];
            
        } else {
            // Multiple columns - suggest generated column approach
            $searchColumnName = $tableName . '_search_vector';
            
            // Create a generated column for the search vector
            $searchColumn = new Column($searchColumnName, 'tsvector');
            $searchColumn->setNullable(true);
            
            // Build the generated expression with weighted columns
            $weights = ['A', 'B', 'C', 'D']; // PostgreSQL supports A-D weights
            $expressions = [];
            
            foreach ($columns as $i => $columnName) {
                $weight = $weights[$i] ?? 'D'; // Default to lowest weight if we run out
                $expressions[] = "setweight(to_tsvector('$language', coalesce($columnName, '')), '$weight')";
            }
            
            $generatedExpression = implode(' || ', $expressions);
            $searchColumn->setGeneratedExpression($generatedExpression);
            $searchColumn->setGenerated(true);
            
            $table->addColumn($searchColumn);
            
            // Create GIN index on the generated column
            $newIndex = new Index($indexName . '_gin', Index::TYPE_INDEX);
            $newIndex->setMethod('gin');
            $newIndex->addColumn($searchColumnName);
            
            $table->addIndex($newIndex);
            
            $this->addWarning(
                "Index '{$indexName}': FULLTEXT converted to PostgreSQL generated column '$searchColumnName' with GIN index"
            );
            
            // Add post-transform actions
            $this->postTransformActions[] = [
                'type' => 'postgresql_generated_column',
                'sql' => "ALTER TABLE $tableName ADD COLUMN $searchColumnName tsvector GENERATED ALWAYS AS ($generatedExpression) STORED;",
                'description' => "Generated tsvector column for full-text search"
            ];
            
            $this->postTransformActions[] = [
                'type' => 'postgresql_gin_index',
                'sql' => "CREATE INDEX {$indexName}_gin ON $tableName USING gin($searchColumnName);",
                'description' => "GIN index on generated search vector column"
            ];
        }
    }

    /**
     * Convert to SQLite FTS virtual table
     */
    private function convertToSQLiteFulltext(Table $table, Index $index, array $columns): void
    {
        $indexName = $index->getName();
        $tableName = $table->getName();
        $ftsVersion = $this->options['sqlite_fts_version'];
        $ftsTableName = $tableName . '_fts';
        
        // Create FTS virtual table
        $columnList = implode(', ', $columns);
        
        $this->addWarning(
            "Index '{$indexName}': FULLTEXT converted to SQLite $ftsVersion virtual table '$ftsTableName'"
        );
        
        // Add post-transform actions for FTS setup
        $this->postTransformActions[] = [
            'type' => 'sqlite_fts_table',
            'sql' => "CREATE VIRTUAL TABLE $ftsTableName USING $ftsVersion($columnList, content='$tableName');",
            'description' => "SQLite $ftsVersion virtual table for full-text search"
        ];
        
        $this->postTransformActions[] = [
            'type' => 'sqlite_fts_populate',
            'sql' => "INSERT INTO $ftsTableName($ftsTableName) VALUES('rebuild');",
            'description' => "Populate FTS virtual table with existing data"
        ];
        
        // Add trigger to keep FTS table in sync
        $insertTrigger = "CREATE TRIGGER {$tableName}_fts_insert AFTER INSERT ON $tableName BEGIN
            INSERT INTO $ftsTableName(rowid, " . implode(', ', $columns) . ") 
            VALUES (new.rowid, " . implode(', ', array_map(fn($col) => "new.$col", $columns)) . ");
        END;";
        
        $updateTrigger = "CREATE TRIGGER {$tableName}_fts_update AFTER UPDATE ON $tableName BEGIN
            UPDATE $ftsTableName SET " . implode(', ', array_map(fn($col) => "$col = new.$col", $columns)) . " 
            WHERE rowid = new.rowid;
        END;";
        
        $deleteTrigger = "CREATE TRIGGER {$tableName}_fts_delete AFTER DELETE ON $tableName BEGIN
            DELETE FROM $ftsTableName WHERE rowid = old.rowid;
        END;";
        
        $this->postTransformActions[] = [
            'type' => 'sqlite_fts_triggers',
            'sql' => "$insertTrigger\n\n$updateTrigger\n\n$deleteTrigger",
            'description' => "Triggers to keep FTS table synchronized with main table"
        ];
    }

    /**
     * Convert MySQL FULLTEXT index to target database equivalent
     */
    private function convertFulltextIndex(Table $table, Index $index, string $sourceDB, string $targetDB): void
    {
        $indexName = $index->getName();
        $columns = $index->getColumnNames();
        
        switch ($targetDB) {
            case 'postgresql':
                $this->convertToPostgreSQLFulltext($table, $index, $columns);
                break;
                
            case 'sqlite':
                $this->convertToSQLiteFulltext($table, $index, $columns);
                break;
                
            default:
                // Fallback to regular index for unsupported databases
                $this->convertToRegularIndex($table, $index, $columns, $targetDB);
        }
    }

    /**
     * Fallback: Convert to regular index for unsupported databases
     */
    private function convertToRegularIndex(Table $table, Index $index, array $columns, string $targetDB): void
    {
        $indexName = $index->getName();
        
        // Create a regular composite index
        $newIndex = new Index($indexName . '_text', Index::TYPE_INDEX);
        
        foreach ($columns as $columnName) {
            $newIndex->addColumn($columnName);
        }
        
        $table->addIndex($newIndex);
        
        $this->addWarning(
            "Index '{$indexName}': FULLTEXT converted to regular index on (" . implode(', ', $columns) . ") for $targetDB"
        );
    }

    /**
     * Get post-transformation actions (SQL statements to execute after table creation)
     */
    public function getPostTransformActions(): array
    {
        return $this->postTransformActions;
    }

    /**
     * Clear post-transformation actions
     */
    public function clearPostTransformActions(): void
    {
        $this->postTransformActions = [];
    }

    /**
     * Get transformation warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Add warning
     */
    private function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Debug logging
     */
    private function debug(string $message, array $context = []): void
    {
        if ($this->debugCallback !== null) {
            call_user_func($this->debugCallback, $message, $context);
        }
    }
}
