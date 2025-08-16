<?php

require_once dirname(__DIR__, 2) . '/core/DatabaseSQLParser.php';
require_once __DIR__ . '/SchemaExtractor.php';
require_once dirname(__DIR__, 2) . '/schema/schema/Table.php';
require_once dirname(__DIR__, 2) . '/schema/schema/Column.php';
require_once dirname(__DIR__, 2) . '/schema/schema/Index.php';
require_once dirname(__DIR__, 2) . '/schema/schema/Constraint.php';
require_once dirname(__DIR__, 2) . '/schema/parsers/AbstractParser.php';
require_once dirname(__DIR__, 2) . '/schema/parsers/MySQLParser.php';
require_once dirname(__DIR__, 2) . '/schema/parsers/PostgreSQLParser.php';
require_once dirname(__DIR__, 2) . '/schema/parsers/SQLiteParser.php';

/**
 * Extracts a database schema by parsing a raw SQL dump file.
 *
 * The SchemaDumpExtractor is a specialized class designed to read a database
 * schema from a .sql file. It serves as the primary entry point for a
 * file-based migration or translation workflow. The class can automatically
 * detect the SQL dialect (MySQL, PostgreSQL, SQLite) within the dump file
 * and then uses the appropriate schema parser to convert the raw SQL into a
 * collection of language-agnostic schema objects (Table, Column, etc.).
 *
 * This class is the file-based counterpart to the SchemaExtractor, which
 * extracts schemas from a live database connection. The objects produced by 
 * this class are ready to be used by the SchemaTransformer and SchemaRenderer.
 *
 * Key Features:
 * - Reads and processes .sql dump files.
 * - Automatically detects the database dialect of the SQL dump.
 * - Utilizes the robust parsing engine to handle complex SQL files.
 * - Produces a standardized array of schema objects for further processing.
 *
 * @package Database\Schema\Core
 * @author Enhanced Model System
 * @version 2.0.0
 */
class SchemaDumpExtractor extends SchemaExtractor
{
    private $sqlParser = null;
    private array $supportedSQLSources = ['mysql', 'postgresql', 'sqlite'];
    private array $availableParsers = [];

    /**
     * Initialize schema dump extractor
     */
    public function __construct(array $options = [])
    {
        parent::__construct();
        $parserOptions = $this->buildParserOptions($options);
        $this->initializeParsers($parserOptions);
    }

    /**
     * Builds a parser options array from the main options array.
     */
    private function buildParserOptions(array $options): array
    {
        return [
            'strict' => $options['strict'] ?? false,
            'preserve_comments' => $options['preserve_comments'] ?? false,
            'debug' => $options['debug'] ?? false,
            'process_insert_statements' => $options['process_insert_statements'] ?? true,
            'insert_batch_size' => $options['insert_batch_size'] ?? 1000,
            'validate_insert_columns' => $options['validate_insert_data'] ?? true,
            'normalize_insert_data' => $options['normalize_insert_data'] ?? true
        ];
    }

    /**
     * Initialize available schema parsers
     */
    private function initializeParsers(array $parserOptions = []): void
    {
        try {
            $this->availableParsers = [
                'mysql' => new MySQLParser($parserOptions),
                'postgresql' => new PostgreSQLParser($parserOptions), 
                'sqlite' => new SQLiteParser($parserOptions)
            ];

            $this->debug("Schema parsers initialized successfully", [
                'available_parsers' => array_keys($this->availableParsers)
            ]);

        } catch (Exception $e) {
            throw new RuntimeException("Failed to initialize schema parsers: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract schema from SQL dump file
     * 
     * @param string $sqlDumpPath Path to SQL dump file
     * @param string $sourceDatabase Source database type (mysql, postgresql, sqlite)
     * @param array $options Extraction options
     * @return array Schema array compatible with existing migration system
     */
    public function extractFromSQLDump(string $sqlDumpPath, string $sourceDatabase, array $options = []): array
    {
        if (!file_exists($sqlDumpPath) || !is_readable($sqlDumpPath)) {
            throw new InvalidArgumentException("SQL dump file not found or not readable: $sqlDumpPath");
        }

        $sourceDatabase = strtolower($sourceDatabase);
        if (!in_array($sourceDatabase, $this->supportedSQLSources)) {
            throw new InvalidArgumentException("Unsupported source database type: $sourceDatabase. Supported: " . implode(', ', $this->supportedSQLSources));
        }

        $extractionOptions = array_merge([
            'include_data' => false,          // Schema extraction only by default
            'include_drop_statements' => false,
            'validate_syntax' => true,
            'chunk_size' => 1000,
            'memory_limit' => '256M',
            'max_statement_size' => 50 * 1024 * 1024, // 50MB max statement
        ], $options);

        $this->debug("Starting SQL dump schema extraction", [
            'file_path' => $sqlDumpPath,
            'file_size' => filesize($sqlDumpPath),
            'source_database' => $sourceDatabase,
            'parser_available' => isset($this->availableParsers[$sourceDatabase])
        ]);

        try {
            // Initialize SQL parser for statement parsing
            $this->initializeSQLParser($sourceDatabase, $extractionOptions);
            
            // Parse SQL dump into statements
            $sqlContent = $this->readSQLDumpFile($sqlDumpPath);

            // Convert statements to schema using appropriate parser
            $schema = $this->convertStatementsToSchema($sqlContent, $sourceDatabase);
            
            $this->debug("Schema extraction completed successfully", [
                'tables_extracted' => count($schema)
            ]);

            return $schema;

        } catch (Exception $e) {
            $this->debug("Schema extraction failed", [
                'error' => $e->getMessage(),
                'trace' => $this->debugCallback ? $e->getTraceAsString() : null
            ]);
            throw new RuntimeException("SQL dump schema extraction failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Detect SQL dump database type automatically
     * 
     * @param string $sqlDumpPath Path to SQL dump file
     * @return string Detected database type (mysql, postgresql, sqlite)
     */
    public function detectSQLDumpType(string $sqlDumpPath): string
    {
        if (!file_exists($sqlDumpPath)) {
            throw new InvalidArgumentException("SQL dump file not found: $sqlDumpPath");
        }

        // Read first few KB to detect database type
        $handle = fopen($sqlDumpPath, 'r');
        $sample = fread($handle, 8192);
        fclose($handle);

        $sample = strtolower($sample);

        // MySQL indicators
        if (strpos($sample, 'mysqldump') !== false || 
            strpos($sample, 'auto_increment') !== false ||
            preg_match('/engine\s*=\s*(innodb|myisam)/i', $sample) ||
            strpos($sample, '`') !== false) {
            return 'mysql';
        }

        // PostgreSQL indicators  
        if (strpos($sample, 'pg_dump') !== false ||
            strpos($sample, 'serial') !== false ||
            strpos($sample, 'nextval') !== false ||
            preg_match('/\$\$.*\$\$/s', $sample)) {
            return 'postgresql';
        }

        // SQLite indicators
        if (strpos($sample, 'sqlite') !== false ||
            strpos($sample, 'autoincrement') !== false ||
            preg_match('/pragma\s+/i', $sample)) {
            return 'sqlite';
        }

        // Default to MySQL if unclear (most common SQL dump format)
        $this->debug("Could not definitively detect database type, defaulting to MySQL", [
            'sample_length' => strlen($sample),
            'first_100_chars' => substr($sample, 0, 100)
        ]);
        
        return 'mysql';
    }

    /**
     * Extract schema with automatic database type detection
     * 
     * @param string $sqlDumpPath Path to SQL dump file
     * @param array $options Extraction options
     * @return array Schema array with detected type information
     */
    public function extractFromSQLDumpAuto(string $sqlDumpPath, array $options = []): array
    {
        $detectedType = $this->detectSQLDumpType($sqlDumpPath);
        
        $this->debug("Auto-detected database type", [
            'detected_type' => $detectedType,
            'file_path' => $sqlDumpPath
        ]);

        $schema = $this->extractFromSQLDump($sqlDumpPath, $detectedType, $options);
        
        // Add metadata about the extraction
        $schema['_metadata'] = [
            'source_type' => 'sql_dump',
            'detected_database_type' => $detectedType,
            'extraction_timestamp' => time(),
            'original_file' => basename($sqlDumpPath)
        ];

        return $schema;
    }

    /**
     * Initialize SQL parser for specific database type
     */
    private function initializeSQLParser(string $databaseType, array $options): void
    {
        $this->sqlParser = new DatabaseSQLParser($databaseType, [
            'debug_parsing' => $this->debugCallback !== null,
            'handle_delimiters' => true,
            'handle_dollar_quotes' => $databaseType === 'postgresql',
            'preserve_comments' => false,
            'max_statement_size' => $options['max_statement_size'],
            'validate_statements' => $options['validate_syntax']
        ]);

        if ($this->debugCallback) {
            $this->sqlParser->setDebugMode(true);
        }

        $this->debug("SQL parser initialized", [
            'database_type' => $databaseType,
            'parser_class' => get_class($this->sqlParser)
        ]);
    }

    /**
     * Read SQL dump file with memory management
     */
    private function readSQLDumpFile(string $filePath): string
    {
        $fileSize = filesize($filePath);
        
        // For very large files, log a warning
        if ($fileSize > 100 * 1024 * 1024) { // 100MB
            $this->debug("Large SQL dump detected", [
                'file_size_mb' => round($fileSize / (1024 * 1024), 2)
            ]);
        }

        $content = file_get_contents($filePath);
        
        if ($content === false) {
            throw new RuntimeException("Failed to read SQL dump file: $filePath");
        }

        return $content;
    }

    /**
     * Convert parsed SQL statements to schema objects using schema parsers
     */
    public function convertStatementsToSchema(string $statements, string $sourceDatabase): array
    {
        $parser = $this->getParserForDatabase($sourceDatabase);
        
        if (!$parser) {
            throw new RuntimeException("No schema parser available for database type: $sourceDatabase");
        }

        if (!method_exists($parser, 'parseStatements')) {
            throw new RuntimeException("Schema parser for $sourceDatabase missing required parseStatements method");
        }

        try {
            $schema = $parser->parseStatements($statements);
            
            $this->debug("Schema conversion completed", [
                'tables_parsed' => count($schema),
                'parser_class' => get_class($parser)
            ]);

            // Validate that we got a reasonable result
            if (empty($schema)) {
                $this->debug("Warning: Parser returned empty schema", [
                    'statements_processed' => count($schema),
                    'parser_class' => get_class($parser)
                ]);
            }

            return $schema;
            
        } catch (Exception $e) {
            throw new RuntimeException("Schema parser failed for $sourceDatabase: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get appropriate parser for database type
     */
    private function getParserForDatabase(string $databaseType): ?object
    {
        $databaseType = strtolower($databaseType);
        
        if (!isset($this->availableParsers[$databaseType])) {
            $this->debug("No parser available for database type", [
                'requested_type' => $databaseType,
                'available_types' => array_keys($this->availableParsers)
            ]);
            return null;
        }

        $parser = $this->availableParsers[$databaseType];
        
        // Set debug callback if available
        if ($this->debugCallback && method_exists($parser, 'setDebugCallback')) {
            $parser->setDebugCallback($this->debugCallback);
        }
        
        return $parser;
    }

    /**
     * Validate that parsers are working correctly
     */
    public function validateParsers(): array
    {
        $results = [];
        
        foreach ($this->availableParsers as $type => $parser) {
            try {
                // Test with a simple CREATE TABLE statement
                $testSQL = $this->getTestSQL($type);
                
                if (method_exists($parser, 'parseStatements')) {
                    $result = $parser->parseStatements([$testSQL]);
                    $results[$type] = [
                        'available' => true,
                        'functional' => !empty($result),
                        'parser_class' => get_class($parser)
                    ];
                } else {
                    $results[$type] = [
                        'available' => true,
                        'functional' => false,
                        'error' => 'parseStatements method not available'
                    ];
                }
                
            } catch (Exception $e) {
                $results[$type] = [
                    'available' => true,
                    'functional' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Get simple test SQL for parser validation
     */
    private function getTestSQL(string $type): string
    {
        switch ($type) {
            case 'mysql':
                return "CREATE TABLE `test` (`id` int(11) AUTO_INCREMENT PRIMARY KEY, `name` varchar(50) NOT NULL);";
            case 'postgresql':
                return 'CREATE TABLE test (id SERIAL PRIMARY KEY, name VARCHAR(50) NOT NULL);';
            case 'sqlite':
                return 'CREATE TABLE test (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL);';
            default:
                return "CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT NOT NULL);";
        }
    }

    /**
     * Get supported database types
     */
    public function getSupportedDatabaseTypes(): array
    {
        return $this->supportedSQLSources;
    }

    /**
     * Check if database type is supported
     */
    public function isDatabaseTypeSupported(string $databaseType): bool
    {
        return in_array(strtolower($databaseType), $this->supportedSQLSources);
    }

    /**
     * Get available parser information
     */
    public function getParserInfo(): array
    {
        $info = [];
        
        foreach ($this->availableParsers as $type => $parser) {
            $info[$type] = [
                'class' => get_class($parser),
                'available' => true,
                'methods' => get_class_methods($parser)
            ];
        }
        
        return $info;
    }

    /**
     * Debug helper method
     */
    private function debug(string $message, array $context = []): void
    {
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, "[SchemaDumpExtractor] $message", $context);
        }
    }
}
