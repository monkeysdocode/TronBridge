<?php

require_once dirname(__DIR__) . '/schema/Column.php';
require_once dirname(__DIR__) . '/schema/Constraint.php';
require_once dirname(__DIR__) . '/schema/Index.php';
require_once dirname(__DIR__) . '/schema/Table.php';

/**
 * Abstract Database Platform
 *
 * Defines the interface and common functionality for generating SQL statements
 * specific to different database platforms (MySQL, PostgreSQL, SQLite).
 *
 * @package Database\Platforms
 */
abstract class AbstractPlatform
{
    /**
     * Platform-specific options.
     *
     * @var array
     */
    protected array $options = [];

    /**
     * The quote character for identifiers.
     *
     * @var string
     */
    protected string $identifierQuote = '"';

    /**
     * Initializes the platform with any given options.
     *
     * @param array $options An array of platform-specific options.
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /*
    |--------------------------------------------------------------------------
    | Abstract Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get the name of the database platform.
     *
     * @return string The platform's name (e.g., 'mysql', 'postgresql').
     */
    abstract public function getName(): string;

    /**
     * Generate the SQL for a single column definition.
     *
     * @param Column $column The column object.
     * @param Table $table The table object.
     * @return string The SQL snippet for the column.
     */
    abstract public function getColumnSQL(Column $column, Table $table): string;

    /**
     * Generate the SQL for a column's data type.
     *
     * @param Column $column The column object.
     * @return string The SQL snippet for the column's type.
     */
    abstract public function getColumnTypeSQL(Column $column): string;

    /**
     * Generate the SQL for an index definition.
     *
     * @param Index $index The index object.
     * @return string The SQL snippet for the index.
     */
    abstract public function getIndexSQL(Index $index): string;

    /**
     * Generate the SQL for a constraint.
     *
     * @param Constraint $constraint The constraint object.
     * @return string The SQL snippet for the constraint.
     */
    abstract public function getConstraintSQL(Constraint $constraint): string;

    /**
     * Generate the SQL for a foreign key constraint.
     *
     * @param Constraint $constraint The foreign key constraint object.
     * @return string The SQL snippet for the foreign key.
     */
    abstract public function getForeignKeySQL(Constraint $constraint): string;

    /**
     * Get the mapping of abstract types to platform-specific types.
     *
     * @return array The type mapping.
     */
    abstract public function getTypeMapping(): array;

    /*
    |--------------------------------------------------------------------------
    | Platform Feature Support
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the platform supports UNSIGNED integers.
     *
     * @return bool True if UNSIGNED is supported, false otherwise.
     */
    public function supportsUnsigned(): bool
    {
        return $this->getName() === 'mysql';
    }

    /**
     * Check if the platform supports inline UNIQUE constraints.
     *
     * @return bool True, as all supported platforms do.
     */
    public function supportsInlineUnique(): bool
    {
        return true;
    }

    /**
     * Check if the platform supports column comments.
     *
     * @return bool True if column comments are supported.
     */
    public function supportsColumnComments(): bool
    {
        return in_array($this->getName(), ['mysql', 'postgresql']);
    }

    /**
     * Check if the platform supports index length specification.
     *
     * @return bool True if index length is supported.
     */
    public function supportsIndexLength(): bool
    {
        return $this->getName() === 'mysql';
    }

    /**
     * Check if the platform supports partial indexes (WHERE clause).
     *
     * @return bool True if partial indexes are supported.
     */
    public function supportsPartialIndexes(): bool
    {
        return $this->getName() === 'postgresql';
    }

    /**
     * Check if the platform supports ENUM types.
     *
     * @return bool True if ENUM types are supported.
     */
    public function supportsEnumTypes(): bool
    {
        return $this->getName() === 'mysql';
    }

    /**
     * Check if the platform supports foreign keys.
     *
     * @return bool True, as all modern databases do.
     */
    public function supportsForeignKeys(): bool
    {
        return true;
    }

    /**
     * Check if the platform supports full-text indexes.
     *
     * @return bool True if full-text indexes are supported.
     */
    public function supportsFulltextIndexes(): bool
    {
        return $this->getName() === 'mysql';
    }

    /*
    |--------------------------------------------------------------------------
    | SQL Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Generate the platform-specific SQL for AUTO_INCREMENT.
     *
     * @return string The SQL for auto-incrementing columns.
     */
    public function getAutoIncrementSQL(): string
    {
        switch ($this->getName()) {
            case 'mysql':
                return 'AUTO_INCREMENT';
            case 'sqlite':
                return 'AUTOINCREMENT';
            case 'postgresql':
            default:
                return ''; // PostgreSQL uses SERIAL types
        }
    }

    /**
     * Generate the SQL for a boolean literal value.
     *
     * @param bool $value The boolean value.
     * @return string The SQL representation of the boolean value.
     */
    public function getBooleanLiteralSQL(bool $value): string
    {
        switch ($this->getName()) {
            case 'mysql':
            case 'sqlite':
                return $value ? '1' : '0';
            case 'postgresql':
            default:
                return $value ? 'TRUE' : 'FALSE';
        }
    }

    /**
     * Generate the SQL for a column's nullability.
     *
     * @param Column $column The column object.
     * @param Table $table The table object.
     * @return string The SQL snippet for nullability (e.g., 'NOT NULL').
     */
    public function getNullableSQL(Column $column, Table $table): string
    {
        $primaryKey = $table->getPrimaryKey();
        $isPKColumn = $primaryKey && in_array($column->getName(), $primaryKey->getColumnNames());

        if ($column->isAutoIncrement() || $isPKColumn) {
            return ' NOT NULL';
        }

        return $column->isNullable() ? ' NULL' : ' NOT NULL';
    }

    /**
     * Generate the SQL for a column's default value.
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

        $expressions = ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME', 'NULL', 'TRUE', 'FALSE'];
        if (is_string($default)) {
            $defaultUpper = strtoupper(trim($default));
            if (in_array($defaultUpper, $expressions, true)) {
                return ' DEFAULT ' . $defaultUpper;
            }
            if (preg_match('/^\w+\s*\(.*\)$/i', trim($default))) {
                return ' DEFAULT ' . trim($default);
            }
        }

        return ' DEFAULT ' . $this->quoteValue($default);
    }

    /**
     * Generate the SQL for a column's comment.
     *
     * @param Column $column The column object.
     * @return string The SQL snippet for the column comment.
     */
    protected function getColumnCommentSQL(Column $column): string
    {
        if (!$this->supportsColumnComments() || ($comment = $column->getComment()) === null) {
            return '';
        }
        return ' COMMENT ' . $this->quoteValue($comment);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Quote an identifier (e.g., table or column name).
     *
     * @param string $identifier The identifier to quote.
     * @return string The quoted identifier.
     */
    public function quoteIdentifier(string $identifier): string
    {
        if (str_starts_with($identifier, $this->identifierQuote) && str_ends_with($identifier, $this->identifierQuote)) {
            return $identifier;
        }
        return $this->identifierQuote . $identifier . $this->identifierQuote;
    }

    /**
     * Quote a string value for use in a SQL query.
     *
     * @param mixed $value The value to quote.
     * @return string The quoted value.
     */
    public function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $this->getBooleanLiteralSQL($value);
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        // String values - escape and quote
        if (is_string($value)) {
            return "'" . $this->escapeString($value) . "'";
        }

        // Arrays (PostgreSQL support)
        if (is_array($value) && $this->getName() === 'postgresql') {
            $escapedValues = array_map(function ($v) {
                return "'" . $this->escapeString((string)$v) . "'";
            }, $value);
            return "ARRAY[" . implode(', ', $escapedValues) . "]";
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * Escape string for SQL
     *
     * @param string $string String to escape
     * @return string Escaped string
     */
    private function escapeString(string $string): string
    {
        $platformName = $this->getName();

        switch ($platformName) {
            case 'mysql':
                // MySQL escaping
                $string = str_replace('\\', '\\\\', $string);  // Escape backslashes first
                $string = str_replace("'", "\\'", $string);    // Escape single quotes
                $string = str_replace('"', '\\"', $string);    // Escape double quotes
                $string = str_replace("\n", "\\n", $string);   // Escape newlines
                $string = str_replace("\r", "\\r", $string);   // Escape carriage returns
                $string = str_replace("\t", "\\t", $string);   // Escape tabs
                return $string;

            case 'postgresql':
            case 'sqlite':
            default:
                // PostgreSQL and SQLite use doubled single quotes
                return str_replace("'", "''", $string);
        }
    }

    /**
     * Map a generic data type to a platform-specific data type.
     *
     * @param string $type The generic data type.
     * @return string The platform-specific data type.
     */
    protected function mapType(string $type): string
    {
        $mapping = $this->getTypeMapping();
        return $mapping[strtolower($type)] ?? strtoupper($type);
    }

    /**
     * Check if a data type requires a length specification.
     *
     * @param string $type The data type.
     * @return bool True if the type requires a length.
     */
    protected function typeRequiresLength(string $type): bool
    {
        $typesWithLength = ['varchar', 'char', 'binary', 'varbinary'];
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
        $typesWithScale = ['decimal', 'numeric', 'float', 'double', 'real'];
        return in_array(strtolower($type), $typesWithScale, true);
    }

    /**
     * Get the platform version, if available.
     *
     * @return string|null The platform version.
     */
    public function getVersion(): ?string
    {
        return $this->options['version'] ?? null;
    }
}
