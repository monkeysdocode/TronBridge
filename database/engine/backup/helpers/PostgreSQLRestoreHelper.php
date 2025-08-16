<?php

require_once dirname(__DIR__, 2) . '/schema/core/SchemaTranslator.php';
require_once dirname(__DIR__, 2) . '/schema/parsers/PostgreSQLParser.php';
require_once dirname(__DIR__, 2) . '/schema/platforms/PostgreSQLPlatform.php';
require_once dirname(__DIR__, 2) . '/core/DatabaseSQLParser.php';
require_once dirname(__DIR__, 1) . '/traits/PathValidationTrait.php';

/**
 * PostgreSQL Restore Helper
 * 
 * This version removes the complex dependency analysis and reordering logic
 * while keeping the excellent DatabaseSQLParser integration and security features.
 * 
 * 
 * @package Database\Classes
 * @author Enhanced Model System
 * @version 2.1.0 - Simplified Sequential Processing
 */
class PostgreSQLRestoreHelper
{
    use PathValidationTrait;

    private \PDO $pdo;
    private string $databaseType;
    private $progressCallback = null;
    private $debugLogCallback = null;
    private array $statistics = [];

    // Keep schema system components for analysis (but not for reordering)
    private ?SchemaTranslator $schemaTranslator = null;
    private ?PostgreSQLParser $pgParser = null;
    private ?PostgreSQLPlatform $pgPlatform = null;
    private ?DatabaseSQLParser $sqlParser = null;

    public function __construct(PDO $pdo, string $databaseType, array $options = [])
    {
        $this->pdo = $pdo;
        $this->databaseType = strtolower($databaseType);

        // Initialize schema system components for analysis
        $this->initializeSchemaSystem($options);

        // Initialize DatabaseSQLParser for proper statement parsing
        $this->initializeSQLParser();

        $this->debugLog("PostgreSQL restore helper initialized (simplified)", 3, [
            'database_type' => $this->databaseType,
            'schema_system_enabled' => $this->schemaTranslator !== null,
            'sql_parser_enabled' => $this->sqlParser !== null
        ]);
    }

    // =============================================================================
    // INITIALIZATION AND SETUP
    // =============================================================================

    /**
     * Initialize schema system for analysis
     *
     * @param array $options Restore options
     * @return void
     */
    private function initializeSchemaSystem(array $options): void
    {
        try {
            $this->schemaTranslator = new SchemaTranslator([
                'strict' => false,
                'handle_unsupported' => 'warn',
                'preserve_indexes' => true,
                'preserve_constraints' => true
            ]);

            $this->pgParser = new PostgreSQLParser();
            $this->pgPlatform = new PostgreSQLPlatform();

            $this->debugLog("Schema system initialized for analysis", 3);
        } catch (Exception $e) {
            $this->debugLog("Schema system initialization failed (continuing without it)", 2, [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Initialize DatabaseSQLParser for proper statement parsing
     * 
     * @return void
     */
    private function initializeSQLParser(): void
    {
        try {
            $this->sqlParser = new DatabaseSQLParser(DatabaseSQLParser::DB_POSTGRESQL, [
                'skip_empty_statements' => true,
                'skip_comments' => true,
                'preserve_comments' => false,
                'handle_dollar_quotes' => true,
                'handle_type_casting' => true
            ]);

            $this->debugLog("DatabaseSQLParser initialized for PostgreSQL", 3);
        } catch (Exception $e) {
            $this->debugLog("DatabaseSQLParser initialization failed", 2, [
                'error' => $e->getMessage()
            ]);
        }
    }

    // =============================================================================
    // RESTORE OPERATIONS
    // =============================================================================

    /**
     * Main restore method - sequential execution with proper parsing
     *
     * @param string $backupPath Path to backup file
     * @param array $options Restore options
     * @return array Restore results
     */
    public function restoreBackup(string $backupPath, array $options = []): array
    {
        $startTime = microtime(true);

        // ✅ SECURITY: Use PathValidationTrait for proper validation
        $this->validateRestorePath($backupPath);

        $options = array_merge([
            'execute_in_transaction' => true,
            'disable_constraints' => true,
            'reset_sequences' => true,
            'stop_on_error' => false,
            'validate_statements' => true,
            'progress_callback' => null
        ], $options);

        $this->progressCallback = $options['progress_callback'];
        $this->statistics = [
            'statements_executed' => 0,
            'statements_failed' => 0,
            'statements_skipped' => 0,
            'errors' => []
        ];

        $this->debugLog("Starting simplified PostgreSQL restore", 1, [
            'backup_path' => basename($backupPath),
            'options' => array_keys($options)
        ]);

        try {
            // Step 1: Read and validate backup file  
            $this->reportProgress(5, 'Reading backup file');
            $backupContent = $this->readBackupFile($backupPath);

            // Step 2: Parse statements using DatabaseSQLParser (not complex analysis)
            $this->reportProgress(10, 'Parsing SQL statements');
            $statements = $this->parseStatements($backupContent);

            $this->debugLog("Parsed backup file", 2, [
                'total_statements' => count($statements),
                'file_size_bytes' => strlen($backupContent)
            ]);

            // Step 3: Prepare database settings
            $this->reportProgress(15, 'Preparing database');
            if ($options['execute_in_transaction']) {
                $this->pdo->beginTransaction();
            }

            if ($options['disable_constraints']) {
                $this->disableConstraints();
            }

            // Step 4: Execute statements sequentially (the main fix!)
            $this->reportProgress(20, 'Executing restore statements');
            $this->executeStatements($statements, $options);

            // Step 5: Post-restore operations
            $this->reportProgress(95, 'Finalizing restore');
            if ($options['reset_sequences']) {
                $this->resetSequencesFromStatements($statements);
            }

            if ($options['disable_constraints']) {
                $this->enableConstraints();
            }

            if ($options['execute_in_transaction']) {
                $this->pdo->commit();
            }

            $this->reportProgress(100, 'Restore completed');

            $duration = microtime(true) - $startTime;

            $this->debugLog("Restore completed successfully", 1, [
                'duration_seconds' => round($duration, 2),
                'statements_executed' => $this->statistics['statements_executed'],
                'statements_failed' => $this->statistics['statements_failed']
            ]);

            return [
                'success' => true,
                'duration_seconds' => $duration,
                'strategy_used' => 'simplified_sequential_postgresql',
                'statements_executed' => $this->statistics['statements_executed'],
                'statements_failed' => $this->statistics['statements_failed'],
                'statements_skipped' => $this->statistics['statements_skipped'],
                'execution_statistics' => $this->statistics
            ];
        } catch (Exception $e) {
            // Rollback if in transaction
            if ($options['execute_in_transaction'] && $this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }

            $this->debugLog("Restore failed", 1, [
                'error' => $e->getMessage(),
                'statements_executed' => $this->statistics['statements_executed']
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'strategy_used' => 'simplified_sequential_postgresql',
                'statements_executed' => $this->statistics['statements_executed'],
                'statements_failed' => $this->statistics['statements_failed'],
                'execution_statistics' => $this->statistics
            ];
        }
    }

    /**
     * Execute statements sequentially
     *
     * @param array $statements List of SQL statements
     * @param array $options Restore options
     * @return void
     */
    private function executeStatements(array $statements, array $options): void
    {
        $totalStatements = count($statements);
        $stopOnError = $options['stop_on_error'] ?? false;
        $validateStatements = $options['validate_statements'] ?? false;

        $this->debugLog("Executing statements sequentially", 2, [
            'total_statements' => $totalStatements,
            'stop_on_error' => $stopOnError
        ]);

        foreach ($statements as $index => $statement) {
            $statementNum = $index + 1;

            try {
                // Basic validation if requested
                if ($validateStatements) {
                    $this->validateStatement($statement);
                }

                // Execute the statement
                $this->pdo->exec($statement);
                $this->statistics['statements_executed']++;

                // Progress reporting
                if ($statementNum % 10 === 0 || $statementNum === $totalStatements) {
                    $progressPercent = 20 + (($statementNum / $totalStatements) * 70); // 20-90%
                    $this->reportProgress($progressPercent, "Executed $statementNum/$totalStatements statements");
                }

                $this->debugLog("Statement executed", 3, [
                    'statement_num' => $statementNum,
                    'statement_type' => $this->getStatementType($statement)
                ]);
            } catch (PDOException $e) {
                $this->statistics['statements_failed']++;

                $error = [
                    'statement_num' => $statementNum,
                    'statement_preview' => substr($statement, 0, 100) . '...',
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode()
                ];

                $this->statistics['errors'][] = $error;
                $this->debugLog("Statement execution failed", 2, $error);

                if ($stopOnError) {
                    throw new Exception("Statement $statementNum failed: " . $e->getMessage());
                }
            }
        }

        $this->debugLog("Sequential execution completed", 2, [
            'success_rate' => round(($this->statistics['statements_executed'] / $totalStatements) * 100, 1) . '%'
        ]);
    }

    /**
     * Reset sequences from statements
     *
     * @param array $statements List of SQL statements
     * @return void
     */
    private function resetSequencesFromStatements(array $statements): void
    {
        $sequenceStatements = [];

        // Find setval statements
        foreach ($statements as $statement) {
            if (preg_match('/^\s*SELECT\s+setval/i', trim($statement))) {
                $sequenceStatements[] = $statement;
            }
        }

        $this->debugLog("Resetting sequences", 2, [
            'sequence_statements_found' => count($sequenceStatements)
        ]);

        foreach ($sequenceStatements as $statement) {
            try {
                $this->pdo->exec($statement);
                $this->debugLog("Sequence reset executed", 3, [
                    'statement' => substr($statement, 0, 100) . '...'
                ]);
            } catch (PDOException $e) {
                $this->debugLog("Sequence reset failed", 2, [
                    'statement' => substr($statement, 0, 100) . '...',
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Disable foreign key constraints for faster restore
     *
     * @return void
     */
    private function disableConstraints(): void
    {
        try {
            $this->pdo->exec("SET session_replication_role = replica");
            $this->debugLog("Foreign key constraints disabled", 3);
        } catch (PDOException $e) {
            $this->debugLog("Could not disable constraints", 2, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Re-enable foreign key constraints
     *
     * @return void
     */
    private function enableConstraints(): void
    {
        try {
            $this->pdo->exec("SET session_replication_role = DEFAULT");
            $this->debugLog("Foreign key constraints re-enabled", 3);
        } catch (PDOException $e) {
            $this->debugLog("Could not re-enable constraints", 2, ['error' => $e->getMessage()]);
        }
    }


    // =============================================================================
    // HELPER METHODS
    // =============================================================================

    /**
     * Read and validate backup file
     *
     * @param string $backupPath Path to backup file
     * @return string File content
     */
    private function readBackupFile(string $backupPath): string
    {
        if (!file_exists($backupPath)) {
            throw new Exception("Backup file not found: $backupPath");
        }

        if (!is_readable($backupPath)) {
            throw new Exception("Backup file not readable: $backupPath");
        }

        $content = file_get_contents($backupPath);
        if ($content === false) {
            throw new Exception("Failed to read backup file: $backupPath");
        }

        if (empty($content)) {
            throw new Exception("Backup file is empty: $backupPath");
        }

        // Basic validation - should contain PostgreSQL backup markers
        if (strpos($content, 'PostgreSQL') === false && strpos($content, 'CREATE TABLE') === false) {
            throw new Exception("File does not appear to be a PostgreSQL backup");
        }

        return $content;
    }

    /**
     * Parse statements using DatabaseSQLParser
     *
     * @param string $content Backup file content
     * @return array Parsed statements
     */
    private function parseStatements(string $content): array
    {
        if ($this->sqlParser) {
            try {
                // Use the excellent DatabaseSQLParser
                $statements = $this->sqlParser->parseStatements($content);

                $this->debugLog("DatabaseSQLParser completed parsing", 2, [
                    'statements_found' => count($statements),
                    'parser_statistics' => $this->sqlParser->getStatistics()
                ]);

                return $statements;
            } catch (Exception $e) {
                $this->debugLog("DatabaseSQLParser failed, falling back to simple parsing", 2, [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Fallback to simple parsing if DatabaseSQLParser fails
        return $this->parseStatementsSimple($content);
    }

    /**
     * Fallback simple statement parsing
     *
     * @param string $content Backup file content
     * @return array Parsed statements
     */
    private function parseStatementsSimple(string $content): array
    {
        $statements = [];
        $lines = explode("\n", $content);
        $currentStatement = '';
        $inFunction = false;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Skip empty lines and comments
            if (empty($trimmedLine) || strpos($trimmedLine, '--') === 0) {
                continue;
            }

            $currentStatement .= $line . "\n";

            // Handle multi-line functions
            if (preg_match('/CREATE\s+(OR\s+REPLACE\s+)?FUNCTION/i', $trimmedLine)) {
                $inFunction = true;
                continue;
            }

            if ($inFunction && (preg_match('/\$\$\s*LANGUAGE/i', $trimmedLine) ||
                preg_match('/END\s*;\s*\$\$/i', $trimmedLine))) {
                $inFunction = false;
                $statements[] = trim($currentStatement);
                $currentStatement = '';
                continue;
            }

            // Normal statement - ends with semicolon
            if (!$inFunction && preg_match('/;\s*$/', $trimmedLine)) {
                $stmt = trim($currentStatement);
                if (!empty($stmt)) {
                    $statements[] = $stmt;
                }
                $currentStatement = '';
            }
        }

        return array_filter($statements, function ($stmt) {
            return !empty(trim($stmt));
        });
    }

    /**
     * Get statement type for logging
     *
     * @param string $statement SQL statement
     * @return string Statement type
     */
    private function getStatementType(string $statement): string
    {
        $statement = trim(strtoupper($statement));

        if (strpos($statement, 'CREATE TABLE') === 0) return 'CREATE_TABLE';
        if (strpos($statement, 'INSERT INTO') === 0) return 'INSERT';
        if (strpos($statement, 'CREATE INDEX') === 0) return 'CREATE_INDEX';
        if (strpos($statement, 'ALTER TABLE') === 0) return 'ALTER_TABLE';
        if (strpos($statement, 'COMMENT ON') === 0) return 'COMMENT';
        if (strpos($statement, 'SELECT SETVAL') === 0) return 'SETVAL';
        if (strpos($statement, 'CREATE TRIGGER') === 0) return 'CREATE_TRIGGER';
        if (strpos($statement, 'CREATE FUNCTION') === 0) return 'CREATE_FUNCTION';
        if (strpos($statement, 'SET ') === 0) return 'SET';
        if (strpos($statement, 'DROP ') === 0) return 'DROP';

        return 'OTHER';
    }

    /**
     * Basic statement validation
     *
     * @param string $statement SQL statement
     * @return void
     * @throws Exception If validation fails
     */
    private function validateStatement(string $statement): void
    {
        $trimmed = trim($statement);

        if (empty($trimmed)) {
            throw new Exception("Empty statement");
        }

        // Check for dangerous patterns
        $dangerousPatterns = [
            '/^\s*DROP\s+DATABASE/i',
            '/^\s*DELETE\s+FROM\s+\w+\s*WHERE\s+1\s*=\s*1/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                throw new Exception("Potentially dangerous statement detected");
            }
        }
    }

    /**
     * Report progress to callback
     *
     * @param float $percent Progress percentage
     * @param string $message Progress message
     * @return void
     */
    private function reportProgress(float $percent, string $message): void
    {
        if ($this->progressCallback && is_callable($this->progressCallback)) {
            call_user_func($this->progressCallback, [
                'progress_percent' => $percent,
                'current_operation' => $message,
                'statements_executed' => $this->statistics['statements_executed'],
                'statements_failed' => $this->statistics['statements_failed']
            ]);
        }
    }

    /**
     * Debug logging with correct signature
     *
     * @param string $message Log message
     * @param int $level Log level
     * @param array $context Log context
     * @return void
     */
    private function debugLog(string $message, int $level = 3, array $context = []): void
    {
        if ($this->debugLogCallback && is_callable($this->debugLogCallback)) {
            call_user_func($this->debugLogCallback, $message, $context, $level);
        }
    }

    /**
     * Set debug callback
     *
     * @param ?callable $callback Debug callback function
     * @return void
     */
    public function setDebugCallback(?callable $callback): void
    {
        $this->debugLogCallback = $callback;
    }

    /**
     * Set progress callback
     *
     * @param ?callable $callback Progress callback function
     * @return void
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Get execution statistics
     *
     * @return array Execution statistics
     */
    public function getExecutionStatistics(): array
    {
        return $this->statistics;
    }
}
