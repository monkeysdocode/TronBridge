<?php

require_once dirname(__DIR__) . '/schema/core/SchemaTranslator.php';

/**
 * Simple SQL Dump Translator Factory - Using Existing SchemaTranslator
 * 
 * Simplified factory that leverages SchemaTranslator's built-in dependency sorting
 * and translation capabilities. All complex logic is handled internally by
 * SchemaTranslator, making this a clean file I/O and Enhanced Model integration wrapper.
 * 
 * Usage Examples:
 * 
 * // Basic usage
 * $model = new Model('database');
 * $factory = new SQLDumpTranslator($model);
 * $result = $factory->translateFile('/path/to/mysql.sql', 'mysql', 'sqlite', '/path/to/output.sql');
 * 
 * // With Enhanced Model integration
 * $model = new Model('database');
 * $result = $model->sqlDumpTranslator()->translateFile('/path/to/dump.sql', 'mysql', 'postgresql', '/path/to/output.sql');
 * 
 * // Translate SQL content directly
 * $result = $factory->translateSQL($sqlContent, 'mysql', 'sqlite');
 * echo $result['sql'];
 * 
 * // Advanced INSERT options
 * $result = $factory->translateSQL($sqlContent, 'mysql', 'postgresql', [
 *     'include_data' => true,
 *     'conflict_handling' => 'skip',
 *     'batch_size' => 5000,
 *     'validate_data' => true
 * ]);
 * 
 * // Batch processing
 * $files = [
 *     ['input' => '/dumps/db1.sql', 'source' => 'mysql', 'target' => 'sqlite', 'output' => '/converted/db1.sql'],
 *     ['input' => '/dumps/db2.sql', 'source' => 'postgresql', 'target' => 'mysql', 'output' => '/converted/db2.sql']
 * ];
 * $batchResult = $factory->translateBatch($files);
 * 
 * @package Database\Factories
 * @author Enhanced Model System
 * @version 2.1.0 - Simplified using existing SchemaTranslator
 */
class SQLDumpTranslator
{
    private Model $model;
    private array $defaultOptions;

    /**
     * Initialize with Enhanced Model integration
     */
    public function __construct(Model $model, array $options = [])
    {
        $this->model = $model;
        $this->defaultOptions = array_merge([
            'strict' => false,
            'preserve_indexes' => true,
            'preserve_constraints' => true,
            'handle_unsupported' => 'warn',
            'enum_conversion' => 'text_with_check',
            'auto_increment_conversion' => 'native',
            'dependency_sort' => true,        // Always enabled, handled by SchemaTranslator
            'add_header_comments' => true,
            'add_statistics' => true,

            // INSERT/Data options
            'include_data' => true,             // Whether to process INSERT statements
            'conflict_handling' => 'error',     // 'error', 'update', 'skip'
            'batch_size' => 1000,               // INSERT batch size
            'validate_data' => true,            // Validate INSERT data
            'normalize_data' => true,           // Normalize data types
            'separate_data_section' => true,    // Separate DDL and DML sections
            'data_section_header' => true       // Add header for data section
        ], $options);

        $this->debugLog("SQL Dump Translator initialized", DebugLevel::BASIC, [
            'default_options' => $this->defaultOptions
        ]);
    }

    /**
     * Translate SQL dump from file to file
     *
     * @param string $inputPath Input SQL dump file
     * @param string $sourceDB Source database type
     * @param string $targetDB Target database type
     * @param string|null $outputPath Output file (null for return as string)
     * @param array $options Additional options
     * @return array Translation result
     */
    public function translateFile(string $inputPath, string $sourceDB, string $targetDB, ?string $outputPath = null, array $options = []): array
    {
        $startTime = microtime(true);

        $this->debugLog("Starting file translation", DebugLevel::BASIC, [
            'input_path' => $inputPath,
            'source_db' => $sourceDB,
            'target_db' => $targetDB,
            'output_path' => $outputPath
        ]);

        try {
            // Validate inputs
            DatabaseSecurity::validateBackupPath($inputPath);
            DatabaseSecurity::validateBackupPath($outputPath);
            $this->validateInputs($inputPath, $sourceDB, $targetDB);

            // Read SQL content
            $this->debugLog("Reading SQL dump file", DebugLevel::DETAILED, [
                'file_size' => filesize($inputPath)
            ]);

            $sqlContent = file_get_contents($inputPath);
            if ($sqlContent === false) {
                throw new Exception("Failed to read input file: $inputPath");
            }

            // Perform translation
            $result = $this->translateSQL($sqlContent, $sourceDB, $targetDB, $options);

            // Add file operation metadata
            $result['input_file'] = $inputPath;
            $result['input_size'] = strlen($sqlContent);
            $result['total_duration'] = microtime(true) - $startTime;

            // Write output if path specified
            if (!empty($outputPath) && $result['success']) {
                $bytesWritten = file_put_contents($outputPath, $result['sql']);

                $this->debugLog("Output file written", DebugLevel::BASIC, [
                    'output_path' => $outputPath,
                    'bytes_written' => $bytesWritten,
                    'success' => $result['success'],
                    'total_duration' => $result['total_duration'],
                    'performance' => true
                ]);

                $result['output_path'] = $outputPath;
                $result['bytes_written'] = $bytesWritten;
            }

            return $result;
        } catch (Exception $e) {
            $this->debugLog("File translation failed", DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'duration' => microtime(true) - $startTime
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'input_file' => $inputPath,
                'duration' => microtime(true) - $startTime
            ];
        }
    }

    /**
     * Translate SQL content directly
     *
     * @param string $sqlContent SQL content to translate
     * @param string $sourceDB Source database type
     * @param string $targetDB Target database type
     * @param array $options Additional options
     * @return array Translation result
     */
    public function translateSQL(string $sqlContent, string $sourceDB, string $targetDB, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            // Merge options with defaults
            $translatorOptions = array_merge($this->defaultOptions, $options);

            $translator = new SchemaTranslator([
                'strict' => $translatorOptions['strict'],
                'preserve_indexes' => $translatorOptions['preserve_indexes'],
                'preserve_constraints' => $translatorOptions['preserve_constraints'],
                'handle_unsupported' => $translatorOptions['handle_unsupported'],
                'enum_conversion' => $translatorOptions['enum_conversion'],
                'auto_increment_conversion' => $translatorOptions['auto_increment_conversion'],
                'dependency_sort' => $translatorOptions['dependency_sort'],
                'add_header_comments' => $translatorOptions['add_header_comments'],
                'process_insert_statements' => $translatorOptions['include_data'],
                'insert_conflict_handling' => $translatorOptions['conflict_handling'],
                'insert_batch_size' => $translatorOptions['batch_size'],
                'include_column_names' => true,
                'validate_insert_data' => $translatorOptions['validate_data'],
                'normalize_insert_data' => $translatorOptions['normalize_data'],
                'separate_data_section' => $translatorOptions['separate_data_section'],
                'data_section_header' => $translatorOptions['data_section_header'],
                'add_statistics' => $translatorOptions['add_statistics']
            ]);

            // Set debug callback for Enhanced Model integration
            $translator->setDebugCallback(function($message, $context = []) {
                $this->debugLog("SchemaTranslator: $message", DebugLevel::VERBOSE, $context);
            });

            // Perform the actual translation (includes dependency sorting)
            $this->debugLog("Executing schema translation", DebugLevel::DETAILED);
            
            $translationStart = microtime(true);
            $translatedSQL = $translator->translateSQL($sqlContent, $sourceDB, $targetDB);
            $translationDuration = microtime(true) - $translationStart;
            
            // Get stats and warnings from translator
            $translatorStats = $translator->getStatistics();
            $warnings = $translator->getConversionWarnings();
            
            $this->debugLog("Schema translation completed", DebugLevel::BASIC, [
                'output_length' => strlen($translatedSQL),
                'warnings_count' => count($warnings),
                'translation_duration' => $translationDuration,
                'performance' => true
            ]);

            // Get warnings from translator
            if (!empty($warnings)) {
                $this->debugLog("Translation warnings detected", DebugLevel::DETAILED, [
                    'warnings' => $warnings
                ]);
            }

            // Build result array
            $result = [
                'success' => true,
                'sql' => $translatedSQL,
                'source_database' => $sourceDB,
                'target_database' => $targetDB,
                'translation_duration' => $translationDuration,
                'total_duration' => microtime(true) - $startTime,
                'warnings' => $warnings,
                'warnings_count' => count($warnings),
                'dependency_sort_enabled' => true, // Always enabled now
                'options_used' => $translatorOptions,
                'statistics' => []
            ];

            // Add statistics if requested
            if ($translatorOptions['add_statistics']) {
                $result['statistics'] = [
                    'input_length' => strlen($sqlContent),
                    'output_length' => strlen($translatedSQL),
                    'compression_ratio' => strlen($sqlContent) > 0 ? strlen($translatedSQL) / strlen($sqlContent) : 1.0,
                    'processing_speed' => strlen($sqlContent) / max($translationDuration, 0.001), // bytes per second

                    // Processing statistics from SchemaTranslator
                    'tables_processed' => $translatorStats['tables_processed'] ?? 0,
                    'indexes_processed' => $translatorStats['indexes_processed'] ?? 0,
                    'constraints_processed' => $translatorStats['constraints_processed'] ?? 0,
                    'inserts_processed' => $translatorStats['inserts_processed'] ?? 0,
                    'rows_processed' => $translatorStats['rows_processed'] ?? 0,
                    
                    // Translation configuration statistics
                    'dependency_sort_enabled' => $translatorStats['dependency_sort_enabled'] ?? false,
                    'insert_processing_enabled' => $translatorStats['insert_processing_enabled'] ?? false,
                    'insert_conflict_handling' => $translatorStats['insert_conflict_handling'] ?? 'error',
                    'warnings_count' => $translatorStats['warnings_count'] ?? 0
                ];

                $this->debugLog("Translation statistics calculated", DebugLevel::VERBOSE, [
                    'statistics' => $result['statistics']
                ]);
            }

            return $result;
        } catch (Exception $e) {
            $this->debugLog("SQL translation failed", DebugLevel::BASIC, [
                'error' => $e->getMessage(),
                'source_db' => $sourceDB,
                'target_db' => $targetDB,
                'duration' => microtime(true) - $startTime
            ]); 

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'source_database' => $sourceDB,
                'target_database' => $targetDB,
                'duration' => microtime(true) - $startTime
            ];
        }
    }

    /**
     * Process multiple files in batch
     */
    public function translateBatch(array $files, array $globalOptions = []): array
    {
        $startTime = microtime(true);
        $results = [];
        $successCount = 0;
        $errorCount = 0;

        $aggregateStats = [
            'total_tables_processed' => 0,
            'total_indexes_processed' => 0,
            'total_constraints_processed' => 0,
            'total_inserts_processed' => 0,
            'total_rows_processed' => 0,
            'total_input_size' => 0,
            'total_output_size' => 0
        ];
        
        $this->debugLog("Starting batch translation", DebugLevel::BASIC, [
            'file_count' => count($files),
            'global_options' => $globalOptions
        ]);
        
        foreach ($files as $index => $fileConfig) {
            $this->debugLog("Processing batch file", DebugLevel::DETAILED, [
                'file_index' => $index + 1,
                'total_files' => count($files),
                'input' => $fileConfig['input'] ?? 'unknown'
            ]);
            
            try {
                $options = array_merge($globalOptions, $fileConfig['options'] ?? []);
                
                $result = $this->translateFile(
                    $fileConfig['input'],
                    $fileConfig['source'],
                    $fileConfig['target'],
                    $fileConfig['output'] ?? '',
                    $options
                );
                
                $results[] = $result;
                
                if ($result['success']) {
                    $successCount++;

                    if (isset($result['statistics'])) {
                        $stats = $result['statistics'];
                        $aggregateStats['total_tables_processed'] += $stats['tables_processed'] ?? 0;
                        $aggregateStats['total_indexes_processed'] += $stats['indexes_processed'] ?? 0;
                        $aggregateStats['total_constraints_processed'] += $stats['constraints_processed'] ?? 0;
                        $aggregateStats['total_inserts_processed'] += $stats['inserts_processed'] ?? 0;
                        $aggregateStats['total_rows_processed'] += $stats['rows_processed'] ?? 0;
                        $aggregateStats['total_input_size'] += $stats['input_length'] ?? 0;
                        $aggregateStats['total_output_size'] += $stats['output_length'] ?? 0;
                    }
                } else {
                    $errorCount++;
                }
                
            } catch (Exception $e) {
                $errorCount++;
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'input' => $fileConfig['input'] ?? 'unknown'
                ];
                
                $this->debugLog("Batch file processing failed", DebugLevel::BASIC, [
                    'file_index' => $index + 1,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $batchResult = [
            'success' => $errorCount === 0,
            'total_files' => count($files),
            'successful' => $successCount,
            'failed' => $errorCount,
            'results' => $results,
            'duration' => microtime(true) - $startTime,
            'aggregate_statistics' => $aggregateStats
        ];
        
        $this->debugLog("Batch translation completed", DebugLevel::BASIC, [
            'total_files' => $batchResult['total_files'],
            'successful' => $batchResult['successful'],
            'failed' => $batchResult['failed'],
            'duration' => $batchResult['duration'],
            'aggregate_statistics' => $aggregateStats,
            'performance' => true
        ]);
        
        return $batchResult;
    }

    /**
     * Validate input parameters
     */
    private function validateInputs(string $inputPath, string $sourceDB, string $targetDB): void
    {        
        if (!file_exists($inputPath)) {
            throw new Exception("Input file does not exist: $inputPath");
        }

        if (!is_readable($inputPath)) {
            throw new Exception("Input file is not readable: $inputPath");
        }

        $supportedDatabases = ['mysql', 'postgresql', 'postgres', 'sqlite'];

        if (!in_array(strtolower($sourceDB), $supportedDatabases)) {
            throw new Exception("Unsupported source database: $sourceDB");
        }

        if (!in_array(strtolower($targetDB), $supportedDatabases)) {
            throw new Exception("Unsupported target database: $targetDB");
        }
    }

    /**
     * Get supported database types
     */
    public function getSupportedDatabases(): array
    {
        return ['mysql', 'postgresql', 'sqlite'];
    }

    /**
     * Get current default options
     */
    public function getDefaultOptions(): array
    {
        return $this->defaultOptions;
    }

    /**
     * Update default options
     */
    public function setDefaultOptions(array $options): void
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $options);

        $this->debugLog("Default options updated", DebugLevel::DETAILED, [
            'new_options' => $this->defaultOptions
        ]);
    }

    // =============================================================================
    // DEBUG-AWARE METHODS
    // =============================================================================

    /**
     * Enable debug mode with specific configuration
     * 
     * Convenience method to configure debug settings for SQL dump translation
     */
    public function enableDebug(int $level = DebugLevel::DETAILED, string $format = 'html'): void
    {
        $this->model->setDebug($level, DebugCategory::SQL | DebugCategory::PERFORMANCE, $format);

        $this->debugLog("Debug mode enabled for SQL dump translation", DebugLevel::BASIC, [
            'debug_level' => $level,
            'debug_format' => $format
        ]);
    }

    /**
     * Get debug output from the Model
     * 
     * Returns formatted debug output from all translation operations
     */
    public function getDebugOutput(): string
    {
        return $this->model->getDebugOutput();
    }

    /**
     * Get raw debug data from the Model
     * 
     * Returns structured debug data for programmatic analysis
     */
    public function getDebugData(): array
    {
        return $this->model->getDebugData();
    }

    /**
     * Debug logging with Enhanced Model integration
     * 
     * Routes debug messages through the parent Model's debug system using the
     * proper API and appropriate categories for SQL translation operations.
     * Maintains zero overhead when debugging is disabled.
     * 
     * @param string $message Debug message to log
     * @param int $level Debug level (DebugLevel constants)
     * @param array $context Additional context data
     * @return void
     */
    private function debugLog(string $message, int $level = DebugLevel::BASIC, array $context = []): void
    {
        // Use DebugCategory::SQL for schema translation operations
        // Can also use DebugCategory::PERFORMANCE for timing-related logs
        $category = isset($context['performance']) ? DebugCategory::PERFORMANCE : DebugCategory::SQL;

        $this->model->debugLog("SQLDumpTranslator: $message", $category, $level, $context);
    }
}
