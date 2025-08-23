<?php

require_once 'Transferer.php';
require_once 'TransfererDetection.php';

/**
 * Enhanced Transferer - SQL Import with Cross-Database Translation
 * 
 * Extends the standard Trongate Transferer to support cross-database
 * SQL dump translation using the Enhanced Model ORM system.
 * 
 * Features:
 * - Automatic database type detection
 * - Cross-database SQL translation
 * - Translation preview and validation
 * - Enhanced error handling and user feedback
 * - Backward compatibility with standard Transferer
 * 
 * @package Trongate\TgTransferer
 * @version 1.0.0
 */
class EnhancedTransferer extends Transferer
{
    private $enhancedModelAvailable = false;
    private $sqlTranslator = null;
    private $currentAnalysis = null;

    public function __construct()
    {
        parent::__construct();

        // Check if Enhanced Model is available
        $this->enhancedModelAvailable = TransfererDetection::hasEnhancedModel();

        // Initialize SQL translator if available
        if ($this->enhancedModelAvailable) {
            $this->initializeSQLTranslator();
        }
    }

    /**
     * Initialize SQL translator from Enhanced Model
     */
    private function initializeSQLTranslator(): void
    {
        try {
            // Load Enhanced Model and SQL translator
            $modelPath = dirname(__DIR__) . '/Model.php';
            if (file_exists($modelPath)) {
                require_once $modelPath;
                $model = new Model();

                // Get SQL translator factory
                if (method_exists($model, 'sqlDumpTranslator')) {
                    $this->sqlTranslator = $model->sqlDumpTranslator();
                }
            }
        } catch (Exception $e) {
            // Silently fall back to standard behavior
            $this->enhancedModelAvailable = false;
            $this->sqlTranslator = null;
        }
    }

    /**
     * Enhanced process_post with translation support
     */
    public function process_post(): void
    {
        $posted_data = file_get_contents('php://input');
        $data = json_decode($posted_data);

        if (!isset($data->action)) {
            die();
        }

        // Handle enhanced actions
        switch ($data->action) {
            case 'analyzeSql':
                $this->analyze_sql($data->controllerPath);
                die();

            case 'translateSql':
                $this->translate_sql($data);
                die();

            case 'previewTranslation':
                $this->preview_translation($data);
                die();

            case 'runSql':
                require_once dirname(__DIR__, 2) . '/database/engine/core/DatabaseSecurity.php';
                $safePath = DatabaseSecurity::validateRestorePath($data->targetFile);
                $this->run_sql(file_get_contents($safePath));
                if (isset($data->originalFile) && !empty($data->originalFile)) {
                    $originalSafePath = DatabaseSecurity::validateRestorePath($data->originalFile);
                    $this->delete_file($originalSafePath);
                }
                $this->cleanup();
                die();

            default:
                // Fall back to parent implementation for standard actions
                parent::process_post();
                break;
        }
    }

    /**
     * Analyze SQL file for database type and translation requirements
     */
    private function analyze_sql(string $filepath): void
    {
        $analysis = TransfererDetection::analyzeSQLFile($filepath);

        // Store analysis for later use
        $this->currentAnalysis = $analysis;

        // Return analysis as JSON
        header('Content-Type: application/json');
        echo json_encode($analysis);
    }

    /**
     * Translate SQL dump using Enhanced Model
     */
    private function translate_sql($data): void
    {
        if (!$this->enhancedModelAvailable || !$this->sqlTranslator) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Enhanced Model not available for translation'
            ]);
            return;
        }

        try {
            $filepath = $data->filepath ?? '';
            $sourceType = $data->sourceType ?? 'mysql';
            $targetType = $data->targetType ?? 'mysql';

            if (!file_exists($filepath)) {
                throw new Exception('SQL file not found');
            }

            // Read SQL content
            $sqlContent = file_get_contents($filepath);

            // Perform translation
            $result = $this->sqlTranslator->translateSQL(
                $sqlContent,
                $sourceType,
                $targetType,
                [
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
                    'conflict_handling' => 'skip',     // 'error', 'update', 'skip'
                    'batch_size' => 1000,               // INSERT batch size
                    'validate_data' => true,            // Validate INSERT data
                    'normalize_data' => true,           // Normalize data types
                    'separate_data_section' => false,    // Separate DDL and DML sections
                    'data_section_header' => false
                ]
            );

            if ($result['success']) {
                // Store translated SQL temporarily for preview/execution
                $tempFile = $this->createTempTranslatedFile($result['sql'], $filepath);

                $response = [
                    'success' => true,
                    'translated_sql' => $result['sql'],
                    'temp_file' => $tempFile,
                    'warnings' => $result['warnings'] ?? [],
                    'statistics' => $result['statistics'] ?? [],
                    'source_type' => $sourceType,
                    'target_type' => $targetType
                ];
            } else {
                $response = [
                    'success' => false,
                    'error' => $result['error'] ?? 'Translation failed'
                ];
            }

            header('Content-Type: application/json');
            echo json_encode($response);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Preview translation results
     */
    private function preview_translation($data): void
    {
        $filepath = $data->filepath ?? '';
        $tempFile = $data->tempFile ?? '';

        if (!file_exists($tempFile)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Translation preview not available'
            ]);
            return;
        }

        $translatedContent = file_get_contents($tempFile);

        // Return preview data
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'translated_sql' => $translatedContent,
            'original_file' => $filepath,
            'temp_file' => $tempFile
        ]);
    }

    /**
     * Create temporary file for translated SQL
     */
    private function createTempTranslatedFile(string $translatedSQL, string $originalPath): string
    {
        $tempDir = sys_get_temp_dir();
        $originalFilename = basename($originalPath);
        $tempFilename = 'translated_' . time() . '_' . $originalFilename;
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . $tempFilename;

        file_put_contents($tempPath, $translatedSQL);

        return $tempPath;
    }

    /**
     * Enhanced run_sql with translation support
     */
    protected function run_sql(string $sql): void
    {
        try {
            require_once dirname(__DIR__, 2) . '/database/engine/core/DatabaseSQLParser.php';
            $parser = new DatabaseSQLParser();
            require_once dirname(__DIR__) . '/Model.php';
            $model = new Model();

            // Replace Trongate placeholder
            $rand_str = make_rand_str(32);
            $sql = str_replace('Tz8tehsWsTPUHEtzfbYjXzaKNqLmfAUz', $rand_str, $sql);

            $statements = $parser->parseStatements($sql);

            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    $model->exec($statement);
                }
            }

            //http_response_code(200);
            echo 'Finished.';
        } catch (Exception $e) {
            http_response_code(500);
            echo 'SQL Error: ' . $e->getMessage();
            exit;
        }
    }

    /**
     * Enhanced check_sql with better detection
     */
    public function check_sql(string $file_contents): bool
    {
        // Start with parent check
        $parentResult = parent::check_sql($file_contents);

        // If Enhanced Model is available, do additional checks
        if ($this->enhancedModelAvailable && $this->sqlTranslator) {
            // Add enhanced validation here if needed
            // For now, use parent result
        }

        return $parentResult;
    }

    /**
     * Get enhanced transferer capabilities
     */
    public function getCapabilities(): array
    {
        return [
            'enhanced_model_available' => $this->enhancedModelAvailable,
            'translation_supported' => $this->enhancedModelAvailable && $this->sqlTranslator !== null,
            'supported_databases' => TransfererDetection::getSupportedDatabaseTypes(),
            'features' => [
                'cross_database_translation',
                'automatic_type_detection',
                'translation_preview',
                'enhanced_error_handling'
            ]
        ];
    }

    /**
     * Deletes the specified file if it exists and is writable.
     * If the file does not exist or is not writable, it sends a 403 HTTP response code.
     *
     * @param string $filepath The path to the file to be deleted.
     * @return void
     */
    private function delete_file(string $filepath): void
    {
        if ((file_exists($filepath)) && (is_writable($filepath))) {
            unlink($filepath);
        } else {
            http_response_code(403);
            echo $filepath;
            die();
        }
    }

    /**
     * Clean up temporary files
     */
    public function cleanup(): void
    {
        // Clean up any temporary translation files
        $tempDir = sys_get_temp_dir();
        $pattern = $tempDir . DIRECTORY_SEPARATOR . 'translated_*';

        foreach (glob($pattern) as $tempFile) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

}
