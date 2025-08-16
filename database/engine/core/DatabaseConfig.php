<?php

/**
 * Database Configuration Class with Multi-Database Support
 * 
 * Handles database connection configuration for MySQL, SQLite, and PostgreSQL
 * with minimal overhead, easy switching capabilities, and comprehensive
 * configuration parsing from various input formats.
 * 
 * Supported input formats:
 * - Global constants (traditional Trongate approach)
 * - Connection strings (DSN-like format)
 * - Configuration arrays
 * - Direct PDO connections
 * 
 * @package Database
 * @author Enhanced Model System
 * @version 2.0.0
 */
class DatabaseConfig
{
    private string $type;
    private ?string $host = null;
    private ?string $port = null;
    private ?string $user = null;
    private ?string $pass = null;
    private ?string $dbname = null;
    private string $charset = 'utf8mb4';
    private ?string $dbfile = null;

    private function __construct() {}

    /**
     * Create MySQL database configuration
     * 
     * @param string $host Database server hostname or IP address
     * @param string $dbname Database name to connect to
     * @param string $user Database username for authentication
     * @param string $pass Database password for authentication
     * @param string $port Database server port (default: '3306')
     * @param string $charset Character set for connection (default: 'utf8mb4')
     * @return self MySQL configuration instance
     * 
     * @example
     * $config = DatabaseConfig::mysql('localhost', 'myapp', 'user', 'password');
     * $config = DatabaseConfig::mysql('db.example.com', 'production', 'app_user', 'secure_pass', '3307');
     */
    public static function mysql(string $host, string $dbname, string $user, string $pass, string $port = '3306', string $charset = 'utf8mb4'): self
    {
        $config = new self();
        $config->type = 'mysql';
        $config->host = $host;
        $config->port = $port;
        $config->user = $user;
        $config->pass = $pass;
        $config->dbname = $dbname;
        $config->charset = $charset;
        return $config;
    }

    /**
     * Create SQLite database configuration
     * 
     * @param string $filepath Absolute or relative path to SQLite database file
     * @return self SQLite configuration instance
     * 
     * @example
     * $config = DatabaseConfig::sqlite('/var/lib/myapp/database.sqlite');
     * $config = DatabaseConfig::sqlite('storage/app.db');
     */
    public static function sqlite(string $filepath): self
    {
        $config = new self();
        $config->type = 'sqlite';
        $config->dbfile = $filepath;
        return $config;
    }

    /**
     * Create PostgreSQL database configuration
     * 
     * @param string $host Database server hostname or IP address
     * @param string $dbname Database name to connect to
     * @param string $user Database username for authentication
     * @param string $pass Database password for authentication
     * @param string $port Database server port (default: '5432')
     * @return self PostgreSQL configuration instance
     * 
     * @example
     * $config = DatabaseConfig::postgresql('localhost', 'myapp', 'postgres', 'password');
     * $config = DatabaseConfig::postgresql('pg.example.com', 'production', 'app_user', 'secure_pass', '5433');
     */
    public static function postgresql(string $host, string $dbname, string $user, string $pass, string $port = '5432'): self
    {
        $config = new self();
        $config->type = 'postgresql';
        $config->host = $host;
        $config->port = $port;
        $config->user = $user;
        $config->pass = $pass;
        $config->dbname = $dbname;
        return $config;
    }

    /**
     * Create configuration from global constants/environment variables
     * 
     * Reads configuration from traditional Trongate global constants:
     * - DB_TYPE: *New* Database type ('mysql', 'sqlite', 'postgresql'). Defaults to 'mysql'
     * - HOST, DATABASE, USER, PASSWORD, PORT: MySQL/PostgreSQL settings
     * - DB_FILE: *New* SQLite database file path. Defaults to /database/storage/trongate.sqlite
     * 
     * @return self Configuration instance based on global constants
     * 
     * @example
     * // With global constants defined
     * define('DB_TYPE', 'postgresql');
     * define('HOST', 'localhost');
     * define('DATABASE', 'myapp');
     * $config = DatabaseConfig::createFromGlobals();
     */
    public static function createFromGlobals(): self
    {
        if (!defined('DB_TYPE')) define('DB_TYPE', 'mysql');
        if (!defined('DB_FILE')) define('DB_FILE', dirname(__DIR__, 2) . '/storage/trongate.sqlite');

        // Check if database type is defined
        $dbType = DB_TYPE;

        switch (strtolower($dbType)) {
            case 'sqlite':
                return self::sqlite(DB_FILE);

            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                return self::postgresql(
                    defined('HOST') ? HOST : 'localhost',
                    defined('DATABASE') ? DATABASE : '',
                    defined('USER') ? USER : '',
                    defined('PASSWORD') ? PASSWORD : '',
                    defined('PORT') ? PORT : '5432'
                );

            case 'mysql':
            default:
                return self::mysql(
                    defined('HOST') ? HOST : 'localhost',
                    defined('DATABASE') ? DATABASE : '',
                    defined('USER') ? USER : '',
                    defined('PASSWORD') ? PASSWORD : '',
                    defined('PORT') ? PORT : '3306'
                );
        }
    }

    // Getters with comprehensive documentation

    /**
     * Get database type identifier
     * 
     * @return string Database type ('mysql', 'sqlite', 'postgresql')
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get database server hostname
     * 
     * @return string|null Hostname for MySQL/PostgreSQL, null for SQLite
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * Get database server port
     * 
     * @return string|null Port number for MySQL/PostgreSQL, null for SQLite
     */
    public function getPort(): ?string
    {
        return $this->port;
    }

    /**
     * Get database username
     * 
     * @return string|null Username for MySQL/PostgreSQL, null for SQLite
     */
    public function getUser(): ?string
    {
        return $this->user;
    }

    /**
     * Get database password
     * 
     * @return string|null Password for MySQL/PostgreSQL, null for SQLite
     */
    public function getPass(): ?string
    {
        return $this->pass;
    }

    /**
     * Get database name
     * 
     * @return string|null Database name for MySQL/PostgreSQL, null for SQLite
     */
    public function getDbname(): ?string
    {
        return $this->dbname;
    }

    /**
     * Get character set encoding
     * 
     * @return string Character set (typically 'utf8mb4' for MySQL)
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * Get SQLite database file path
     * 
     * @return string|null File path for SQLite, null for MySQL/PostgreSQL
     */
    public function getDbfile(): ?string
    {
        return $this->dbfile;
    }

    /**
     * Validate configuration completeness and correctness
     * 
     * Verifies that all required parameters are present for the specified
     * database type and that values meet basic validation criteria.
     * 
     * @return bool True if configuration is valid and complete
     */
    public function validate(): bool
    {
        switch ($this->type) {
            case 'mysql':
                return !empty($this->host) && !empty($this->dbname) && !empty($this->user);
            case 'sqlite':
                return !empty($this->dbfile);
            case 'postgresql':
                return !empty($this->host) && !empty($this->dbname) && !empty($this->user);
            default:
                return false;
        }
    }

    /**
     * Parse connection string into configuration object
     * 
     * Supports various connection string formats:
     * - SQLite: "sqlite:/path/to/file.db" or just "/path/to/file.db"
     * - MySQL: "mysql:host=localhost;dbname=test;user=root;pass=secret"
     * - PostgreSQL: "postgresql:host=localhost;dbname=test;user=postgres;pass=secret"
     * 
     * @param string $connection Connection string to parse
     * @return DatabaseConfig Parsed configuration object
     * 
     * @example
     * $config = DatabaseConfig::parseConnectionString('postgresql:host=localhost;dbname=myapp;user=postgres;pass=secret');
     * $config = DatabaseConfig::parseConnectionString('sqlite:/var/lib/app.db');
     */
    public static function parseConnectionString(string $connection): DatabaseConfig
    {
        // Handle SQLite path shorthand
        if (str_starts_with($connection, 'sqlite:')) {
            $dbFile = substr($connection, 7); // Remove 'sqlite:' prefix
            return self::sqlite($dbFile);
        }

        // Handle PostgreSQL DSN
        if (str_starts_with($connection, 'postgresql:') || str_starts_with($connection, 'postgres:') || str_starts_with($connection, 'pgsql:')) {
            $parts = [];
            $prefix_len = str_starts_with($connection, 'postgresql:') ? 11 : (str_starts_with($connection, 'postgres:') ? 9 : 6);
            parse_str(str_replace(';', '&', substr($connection, $prefix_len)), $parts);

            return self::postgresql(
                $parts['host'] ?? (defined('HOST') ? HOST : 'localhost'),
                $parts['dbname'] ?? (defined('DATABASE') ? DATABASE : ''),
                $parts['user'] ?? (defined('USER') ? USER : ''),
                $parts['pass'] ?? (defined('PASSWORD') ? PASSWORD : ''),
                $parts['port'] ?? (defined('PORT') ? PORT : '5432')
            );
        }

        // Handle MySQL DSN
        if (str_starts_with($connection, 'mysql:')) {
            $parts = [];
            parse_str(str_replace(';', '&', substr($connection, 6)), $parts);

            return self::mysql(
                $parts['host'] ?? (defined('HOST') ? HOST : 'localhost'),
                $parts['dbname'] ?? (defined('DATABASE') ? DATABASE : ''),
                $parts['user'] ?? (defined('USER') ? USER : ''),
                $parts['pass'] ?? (defined('PASSWORD') ? PASSWORD : ''),
                $parts['port'] ?? (defined('PORT') ? PORT : '3306'),
                $parts['charset'] ?? 'utf8mb4'
            );
        }

        // Fallback - treat as sqlite file path
        return self::sqlite($connection);
    }

    /**
     * Parse configuration array into configuration object
     * 
     * Accepts associative array with database configuration parameters.
     * Required keys vary by database type.
     * 
     * @param array $connection Configuration array
     * @return DatabaseConfig Parsed configuration object
     * 
     * @example
     * $config = DatabaseConfig::parseConnectionArray([
     *     'type' => 'postgresql',
     *     'host' => 'localhost',
     *     'dbname' => 'myapp',
     *     'user' => 'postgres',
     *     'pass' => 'secret',
     *     'port' => '5432'
     * ]);
     */
    public static function parseConnectionArray(array $connection): DatabaseConfig
    {
        $type = $connection['type'] ?? 'mysql';

        switch (strtolower($type)) {
            case 'sqlite':
                return self::sqlite(
                    $connection['dbfile'] ?? (defined('APPPATH') ? APPPATH . 'database/storage/app.sqlite' : 'app.sqlite')
                );

            case 'postgresql':
            case 'postgres':
            case 'pgsql':
                return self::postgresql(
                    $connection['host'] ?? (defined('HOST') ? HOST : 'localhost'),
                    $connection['dbname'] ?? (defined('DATABASE') ? DATABASE : ''),
                    $connection['user'] ?? (defined('USER') ? USER : ''),
                    $connection['pass'] ?? (defined('PASSWORD') ? PASSWORD : ''),
                    $connection['port'] ?? (defined('PORT') ? PORT : '5432')
                );

            case 'mysql':
            default:
                return self::mysql(
                    $connection['host'] ?? (defined('HOST') ? HOST : 'localhost'),
                    $connection['dbname'] ?? (defined('DATABASE') ? DATABASE : ''),
                    $connection['user'] ?? (defined('USER') ? USER : ''),
                    $connection['pass'] ?? (defined('PASSWORD') ? PASSWORD : ''),
                    $connection['port'] ?? (defined('PORT') ? PORT : '3306'),
                    $connection['charset'] ?? 'utf8mb4'
                );
        }
    }

    /**
     * Detect database type from existing PDO connection
     * 
     * Analyzes PDO connection to determine the underlying database type.
     * Useful when working with existing connections of unknown type.
     * 
     * @param PDO $pdo Existing PDO database connection
     * @return string Database type identifier ('mysql', 'sqlite', 'postgresql')
     */
    public static function detectDbTypeFromPdo(PDO $pdo): string
    {
        $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return match ($driverName) {
            'sqlite' => 'sqlite',
            'mysql' => 'mysql',
            'pgsql' => 'postgresql',
            default => 'mysql' // Default fallback
        };
    }

    /**
     * Get connection string representation for debugging and logging
     * 
     * Generates a connection string suitable for debugging output.
     * Excludes sensitive information like passwords.
     * 
     * @return string Safe connection string for debugging
     */
    public function getConnectionString(): string
    {
        switch ($this->type) {
            case 'mysql':
                return "mysql:host={$this->host};port={$this->port};dbname={$this->dbname}";
            case 'sqlite':
                return "sqlite:{$this->dbfile}";
            case 'postgresql':
                return "postgresql:host={$this->host};port={$this->port};dbname={$this->dbname}";
            default:
                return 'unknown';
        }
    }
}