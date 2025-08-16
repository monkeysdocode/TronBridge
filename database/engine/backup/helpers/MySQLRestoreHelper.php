<?php

require_once dirname(__DIR__, 2) . '/core/DatabaseSQLParser.php';
require_once dirname(__DIR__, 1) . '/traits/PathValidationTrait.php';

/**
 * MySQL Restore Helper - Simplified Sequential Processing
 * 
 * This modernized version removes complex dependency analysis and reordering logic
 * while keeping excellent DatabaseSQLParser integration and security features.
 * Follows the same simplified approach as the successful PostgreSQL implementation.
 * 
 * **Key Features:**
 * - Sequential statement execution (no complex dependency analysis)
 * - DatabaseSQLParser integration for robust MySQL statement parsing
 * - MySQL-specific optimizations (DELIMITER handling, foreign keys, AUTO_INCREMENT)
 * - PathValidationTrait security integration
 * - Comprehensive progress reporting and error handling
 * 
 * **MySQL-Specific Handling:**
 * - DELIMITER changes for triggers and procedures
 * - Foreign key and unique check management
 * - AUTO_INCREMENT preservation
 * - Engine and charset handling
 * - Proper transaction management
 * 
 * @package Database\Backup\Helpers
 * @author Enhanced Model System
 * @version 2.0.0 - Simplified Sequential Processing Following PostgreSQL Pattern
 */
class MySQLRestoreHelper
{
    use PathValidationTrait;

    private PDO $pdo;
    private string $databaseType;
    private $progressCallback = null;
    private $debugLogCallback = null;
    private array $statistics = [];

    // DatabaseSQLParser for robust statement parsing
    private ?DatabaseSQLParser $sqlParser = null;

    public function __construct(PDO $pdo, string $databaseType, array $options = [])
    {
        $this->pdo = $pdo;
        $this->databaseType = strtolower($databaseType);

        // Initialize DatabaseSQLParser for proper statement parsing
        $this->initializeSQLParser();

        $this->debugLog("MySQL restore helper initialized (simplified sequential)", 3, [
            'database_type' => $this->databaseType,
            'sql_parser_enabled' => $this->sqlParser !== null
        ]);
    }

    // =============================================================================
    // INITIALIZATION AND SETUP
    // =============================================================================

    /**
     * Initialize SQL parser for robust statement parsing
     */
    private function initializeSQLParser(): void
    {
        try {
            $this->sqlParser = new DatabaseSQLParser();
            
            $this->debugLog("DatabaseSQLParser initialized for MySQL restore", 2);
            
        } catch (Exception $e) {
            $this->debugLog("SQL parser initialization failed, using basic parsing", 1, [
                'error' => $e->getMessage()
            ]);
            // Continue without SQL parser as fallback
        }
    }

    // =============================================================================
    // MAIN RESTORE OPERATION
    // =============================================================================

    /**
     * Restore MySQL backup using simplified sequential processing
     * 
     * This method removes complex dependency analysis while maintaining
     * robust MySQL-specific handling and progress reporting.
     */
    public function restoreFromBackup(string $backupPath, array $options = []): array
    {
        $startTime = microtime(true);
        
        // Validate restore path using security trait
        $this->validateRestorePath($backupPath);
        
        $this->debugLog("Starting simplified MySQL restore", 2, [
            'backup_path' => $backupPath,
            'backup_size_bytes' => file_exists($backupPath) ? filesize($backupPath) : 0,
            'options' => $options
        ]);
        
        // Default options for MySQL
        $restoreOptions = array_merge([
            'execute_in_transaction' => true,
            'disable_foreign_keys' => true,
            'disable_unique_checks' => true,
            'preserve_auto_increment' => true,
            'handle_delimiters' => true,
            'stop_on_error' => false,
            'validate_statements' => true,
            'progress_callback' => null
        ], $options);
        
        $this->progressCallback = $restoreOptions['progress_callback'];
        
        try {
            // Read backup file
            $backupContent = file_get_contents($backupPath);
            if ($backupContent === false) {
                throw new Exception("Cannot read backup file: $backupPath");
            }
            
            $this->reportProgress(5, 'Reading MySQL backup file');
            
            // Parse SQL statements using DatabaseSQLParser
            $statements = $this->parseBackupContent($backupContent, $restoreOptions);
            
            $this->reportProgress(15, 'Parsed ' . count($statements) . ' SQL statements');
            
            // MySQL-specific pre-restore setup
            $this->setupMySQLEnvironment($restoreOptions);
            
            $this->reportProgress(20, 'MySQL environment configured');
            
            // Execute statements sequentially (simplified approach)
            $executionResult = $this->executeStatementsSequentially($statements, $restoreOptions);
            
            // MySQL-specific post-restore cleanup
            $this->cleanupMySQLEnvironment($restoreOptions);
            
            $this->reportProgress(100, 'MySQL restore completed');
            
            $duration = microtime(true) - $startTime;
            
            $this->debugLog("MySQL restore completed successfully", 2, [
                'duration_seconds' => round($duration, 3),
                'statements_executed' => $executionResult['executed'],
                'statements_failed' => $executionResult['failed']
            ]);
            
            return [
                'success' => true,
                'duration_seconds' => round($duration, 3),
                'strategy_used' => 'mysql_simplified_sequential',
                'statements_executed' => $executionResult['executed'],
                'statements_failed' => $executionResult['failed'],
                'statements_skipped' => $executionResult['skipped'],
                'execution_statistics' => $this->getExecutionStatistics()
            ];
            
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            $this->debugLog("MySQL restore failed", 1, [
                'duration_seconds' => round($duration, 3),
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 3),
                'strategy_used' => 'mysql_simplified_sequential'
            ];
        }
    }

    // =============================================================================
    // SQL PARSING AND ANALYSIS
    // =============================================================================

    /**
     * Parse backup content into individual SQL statements
     */
    private function parseBackupContent(string $backupContent, array $options): array
    {
        if ($this->sqlParser) {
            return $this->parseWithDatabaseSQLParser($backupContent, $options);
        } else {
            return $this->parseWithBasicMethod($backupContent, $options);
        }
    }
    
    /**
     * Parse using DatabaseSQLParser for robust MySQL statement parsing
     */
    private function parseWithDatabaseSQLParser(string $backupContent, array $options): array
    {
        $this->debugLog("Using DatabaseSQLParser for MySQL statement parsing", 3);
        
        try {
            // Configure parser for MySQL
            $parserOptions = [
                'handle_mysql_delimiters' => $options['handle_delimiters'] ?? true,
                'preserve_comments' => false,
                'skip_empty_statements' => true,
                'validate_statements' => $options['validate_statements'] ?? true
            ];
            
            $statements = $this->sqlParser->parseStatements($backupContent, $parserOptions);
            
            $this->debugLog("DatabaseSQLParser parsed statements successfully", 3, [
                'statement_count' => count($statements),
                'parser_options' => $parserOptions
            ]);
            
            return $statements;
            
        } catch (Exception $e) {
            $this->debugLog("DatabaseSQLParser failed, falling back to basic parsing", 2, [
                'error' => $e->getMessage()
            ]);
            
            return $this->parseWithBasicMethod($backupContent, $options);
        }
    }
    
    /**
     * Fallback basic parsing method for MySQL
     */
    private function parseWithBasicMethod(string $backupContent, array $options): array
    {
        $this->debugLog("Using basic MySQL statement parsing", 3);
        
        $statements = [];
        $currentStatement = '';
        $inDelimiterBlock = false;
        $currentDelimiter = ';';
        
        $lines = explode("\n", $backupContent);
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Skip empty lines and comments
            if (empty($trimmedLine) || strpos($trimmedLine, '--') === 0 || strpos($trimmedLine, '#') === 0) {
                continue;
            }
            
            // Handle MySQL DELIMITER changes
            if (stripos($trimmedLine, 'DELIMITER') === 0) {
                if ($currentStatement) {
                    $statements[] = trim($currentStatement);
                    $currentStatement = '';
                }
                
                $delimiterParts = explode(' ', $trimmedLine);
                if (count($delimiterParts) >= 2) {
                    $currentDelimiter = $delimiterParts[1];
                    $inDelimiterBlock = ($currentDelimiter !== ';');
                }
                continue;
            }
            
            $currentStatement .= $line . "\n";
            
            // Check for statement end
            if (substr($trimmedLine, -strlen($currentDelimiter)) === $currentDelimiter) {
                $statement = trim($currentStatement);
                
                // Remove delimiter from end
                if (substr($statement, -strlen($currentDelimiter)) === $currentDelimiter) {
                    $statement = trim(substr($statement, 0, -strlen($currentDelimiter)));
                }
                
                if (!empty($statement)) {
                    $statements[] = $statement;
                }
                
                $currentStatement = '';
                
                // Reset delimiter if we're back to semicolon
                if ($currentDelimiter === ';') {
                    $inDelimiterBlock = false;
                }
            }
        }
        
        // Add any remaining statement
        if (!empty(trim($currentStatement))) {
            $statements[] = trim($currentStatement);
        }
        
        $this->debugLog("Basic MySQL parsing completed", 3, [
            'statement_count' => count($statements)
        ]);
        
        return $statements;
    }

    // =============================================================================
    // MYSQL ENVIRONMENT MANAGEMENT
    // =============================================================================

    /**
     * Setup MySQL environment for restore operation
     */
    private function setupMySQLEnvironment(array $options): void
    {
        $this->debugLog("Setting up MySQL environment for restore", 3);
        
        try {
            // Start transaction if requested
            if ($options['execute_in_transaction']) {
                $this->pdo->beginTransaction();
                $this->debugLog("Started MySQL transaction", 3);
            }
            
            // Disable foreign key checks if requested
            if ($options['disable_foreign_keys']) {
                $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $this->debugLog("Disabled MySQL foreign key checks", 3);
            }
            
            // Disable unique checks if requested
            if ($options['disable_unique_checks']) {
                $this->pdo->exec("SET UNIQUE_CHECKS = 0");
                $this->debugLog("Disabled MySQL unique checks", 3);
            }
            
            // Set other MySQL-specific settings
            $this->pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
            $this->pdo->exec("SET AUTOCOMMIT = 0");
            $this->pdo->exec("SET time_zone = '+00:00'");
            
            $this->debugLog("MySQL environment setup completed", 2);
            
        } catch (Exception $e) {
            $this->debugLog("MySQL environment setup failed", 1, [
                'error' => $e->getMessage()
            ]);
            throw new Exception("MySQL environment setup failed: " . $e->getMessage());
        }
    }
    
    /**
     * Cleanup MySQL environment after restore operation
     */
    private function cleanupMySQLEnvironment(array $options): void
    {
        $this->debugLog("Cleaning up MySQL environment", 3);
        
        try {
            // Re-enable foreign key checks if they were disabled
            if ($options['disable_foreign_keys']) {
                $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $this->debugLog("Re-enabled MySQL foreign key checks", 3);
            }
            
            // Re-enable unique checks if they were disabled
            if ($options['disable_unique_checks']) {
                $this->pdo->exec("SET UNIQUE_CHECKS = 1");
                $this->debugLog("Re-enabled MySQL unique checks", 3);
            }
            
            // Commit transaction if it was started
            if ($options['execute_in_transaction'] && $this->pdo->inTransaction()) {
                $this->pdo->commit();
                $this->debugLog("Committed MySQL transaction", 3);
            }
            
            $this->debugLog("MySQL environment cleanup completed", 2);
            
        } catch (Exception $e) {
            $this->debugLog("MySQL environment cleanup failed", 1, [
                'error' => $e->getMessage()
            ]);
            
            // Try to rollback if in transaction
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollback();
                    $this->debugLog("Rolled back MySQL transaction due to cleanup failure", 2);
                } catch (Exception $rollbackException) {
                    $this->debugLog("Rollback also failed", 1, [
                        'rollback_error' => $rollbackException->getMessage()
                    ]);
                }
            }
        }
    }

    // =============================================================================
    // SEQUENTIAL STATEMENT EXECUTION
    // =============================================================================

    /**
     * Execute statements sequentially (simplified approach)
     * 
     * This removes complex dependency analysis and just executes statements
     * in the order they appear in the backup, which works well since the
     * backup generation already handles proper ordering.
     */
    private function executeStatementsSequentially(array $statements, array $options): array
    {
        $executed = 0;
        $failed = 0;
        $skipped = 0;
        $totalStatements = count($statements);
        
        $this->debugLog("Starting sequential execution of MySQL statements", 2, [
            'total_statements' => $totalStatements,
            'stop_on_error' => $options['stop_on_error']
        ]);
        
        foreach ($statements as $index => $statement) {
            $statementProgress = 20 + (($index / $totalStatements) * 75);
            $this->reportProgress($statementProgress, "Executing statement " . ($index + 1) . "/$totalStatements");
            
            try {
                $trimmedStatement = trim($statement);
                
                // Skip empty statements
                if (empty($trimmedStatement)) {
                    $skipped++;
                    continue;
                }
                
                // Skip comment-only statements
                if (strpos($trimmedStatement, '--') === 0 || strpos($trimmedStatement, '#') === 0) {
                    $skipped++;
                    continue;
                }
                
                // Validate statement if requested
                if ($options['validate_statements']) {
                    $this->validateMySQLStatement($trimmedStatement);
                }
                
                // Execute statement
                $this->pdo->exec($trimmedStatement);
                $executed++;
                
                $this->debugLog("Executed MySQL statement", 4, [
                    'statement_index' => $index + 1,
                    'statement_preview' => substr($trimmedStatement, 0, 100)
                ]);
                
            } catch (Exception $e) {
                $failed++;
                
                $this->debugLog("MySQL statement execution failed", 2, [
                    'statement_index' => $index + 1,
                    'statement_preview' => substr($trimmedStatement, 0, 100),
                    'error' => $e->getMessage()
                ]);
                
                if ($options['stop_on_error']) {
                    throw new Exception("Statement execution failed (stop_on_error=true): " . $e->getMessage());
                }
                
                // Continue with next statement if stop_on_error is false
            }
        }
        
        $this->debugLog("Sequential execution completed", 2, [
            'executed' => $executed,
            'failed' => $failed,
            'skipped' => $skipped,
            'total' => $totalStatements
        ]);
        
        return [
            'executed' => $executed,
            'failed' => $failed,
            'skipped' => $skipped,
            'total' => $totalStatements
        ];
    }

    // =============================================================================
    // VALIDATION AND SECURITY
    // =============================================================================

    /**
     * Validate MySQL statement for basic security and syntax
     */
    private function validateMySQLStatement(string $statement): void
    {
        $trimmed = trim($statement);
        
        // Basic security checks for MySQL
        $dangerousPatterns = [
            '/\bLOAD_FILE\s*\(/i',
            '/\bINTO\s+OUTFILE\s/i',
            '/\bINTO\s+DUMPFILE\s/i',
            '/\bSELECT\s+.*\bINTO\s+OUTFILE\b/i',
            '/@.*\s*:=\s*.*LOAD_FILE/i'
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                throw new Exception("Potentially dangerous MySQL statement detected");
            }
        }
        
        // Additional MySQL-specific validations could be added here
    }
    
    /**
     * Validate restore path using PathValidationTrait
     */
    private function validateRestorePath(string $backupPath): void
    {
        try {
            // Use the trait method for consistent validation
            $this->validateBackupPath($backupPath);
            
        } catch (Exception $e) {
            throw new InvalidArgumentException("Restore file security validation failed: " . $e->getMessage());
        }
    }

    // =============================================================================
    // PROGRESS REPORTING AND DEBUGGING
    // =============================================================================

    /**
     * Report progress to callback
     */
    private function reportProgress(float $percentage, string $operation): void
    {
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, [
                'progress_percent' => $percentage,
                'current_operation' => $operation
            ]);
        }
    }
    
    /**
     * Debug logging with level support
     */
    private function debugLog(string $message, int $level = 2, array $context = []): void
    {
        if ($this->debugLogCallback) {
            call_user_func($this->debugLogCallback, $message, $level, $context);
        }
    }
    
    /**
     * Set debug callback
     */
    public function setDebugCallback(?callable $callback): void
    {
        $this->debugLogCallback = $callback;
    }
    
    /**
     * Set progress callback
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }
    
    /**
     * Get execution statistics
     */
    private function getExecutionStatistics(): array
    {
        return $this->statistics;
    }
}