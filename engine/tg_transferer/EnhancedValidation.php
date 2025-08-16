<?php

/**
 * Enhanced Validation and Error Handling
 * 
 * Provides sophisticated validation, error handling, and user feedback
 * for the Enhanced Transferer system with detailed diagnostics.
 * 
 * @package Trongate\TgTransferer
 * @version 1.0.0
 */
class EnhancedValidation
{
    private $errors = [];
    private $warnings = [];
    private $validationRules = [];
    private $context = [];

    public function __construct(array $context = [])
    {
        $this->context = $context;
        $this->initializeDefaultRules();
    }

    /**
     * Initialize default validation rules
     */
    private function initializeDefaultRules(): void
    {
        $this->validationRules = [
            'file_size' => [
                'max_size' => 1048576, // 1MB in bytes
                'message' => 'File exceeds maximum size limit of 1MB'
            ],
            'file_extension' => [
                'allowed' => ['sql'],
                'message' => 'Only .sql files are allowed'
            ],
            'sql_content' => [
                'min_length' => 10,
                'max_length' => 10485760, // 10MB of SQL content
                'message' => 'SQL content must be between 10 bytes and 10MB'
            ],
            'dangerous_sql' => [
                'patterns' => [
                    '/\bdrop\s+database\b/i',
                    '/\bdrop\s+schema\b/i',
                    '/\btruncate\s+table\b/i',
                    '/\bdelete\s+from\s+\w+\s*$/i', // DELETE without WHERE
                    '/\bupdate\s+\w+\s+set\s+.*\s*$/i', // UPDATE without WHERE
                    '/\bgrant\s+/i',
                    '/\brevoke\s+/i',
                    '/\bcreate\s+user\b/i',
                    '/\balter\s+user\b/i',
                    '/\bdrop\s+user\b/i'
                ],
                'message' => 'SQL contains potentially dangerous operations'
            ],
            'database_compatibility' => [
                'check_types' => true,
                'message' => 'Database type compatibility issues detected'
            ]
        ];
    }

    /**
     * Validate SQL file comprehensively
     */
    public function validateSQLFile(string $filepath): array
    {
        $this->clearResults();
        
        $result = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'recommendations' => [],
            'file_info' => [],
            'sql_analysis' => [],
            'security_assessment' => []
        ];

        // File existence and basic checks
        if (!$this->validateFileExists($filepath)) {
            $result['valid'] = false;
            $result['errors'] = $this->errors;
            return $result;
        }

        // File properties validation
        $fileInfo = $this->analyzeFileProperties($filepath);
        $result['file_info'] = $fileInfo;

        if (!$this->validateFileProperties($fileInfo)) {
            $result['valid'] = false;
        }

        // SQL content validation
        $sqlContent = file_get_contents($filepath);
        $sqlAnalysis = $this->analyzeSQLContent($sqlContent);
        $result['sql_analysis'] = $sqlAnalysis;

        if (!$this->validateSQLContent($sqlContent, $sqlAnalysis)) {
            $result['valid'] = false;
        }

        // Security assessment
        $securityAssessment = $this->assessSQLSecurity($sqlContent);
        $result['security_assessment'] = $securityAssessment;

        if ($securityAssessment['risk_level'] === 'high') {
            $result['valid'] = false;
        }

        // Database compatibility check
        if (isset($this->context['target_database'])) {
            $compatibilityCheck = $this->checkDatabaseCompatibility(
                $sqlAnalysis, 
                $this->context['target_database']
            );
            $result['compatibility'] = $compatibilityCheck;
        }

        // Compile results
        $result['errors'] = $this->errors;
        $result['warnings'] = $this->warnings;
        $result['recommendations'] = $this->generateRecommendations($result);

        return $result;
    }

    /**
     * Validate file existence and accessibility
     */
    private function validateFileExists(string $filepath): bool
    {
        if (!file_exists($filepath)) {
            $this->addError('file_not_found', "File does not exist: {$filepath}");
            return false;
        }

        if (!is_readable($filepath)) {
            $this->addError('file_not_readable', "File is not readable: {$filepath}");
            return false;
        }

        return true;
    }

    /**
     * Analyze file properties
     */
    private function analyzeFileProperties(string $filepath): array
    {
        $fileInfo = [
            'path' => $filepath,
            'name' => basename($filepath),
            'size' => filesize($filepath),
            'extension' => strtolower(pathinfo($filepath, PATHINFO_EXTENSION)),
            'mime_type' => $this->getMimeType($filepath),
            'modified_time' => filemtime($filepath),
            'permissions' => fileperms($filepath)
        ];

        $fileInfo['size_kb'] = round($fileInfo['size'] / 1024, 2);
        $fileInfo['size_mb'] = round($fileInfo['size'] / 1048576, 2);
        $fileInfo['is_large'] = $fileInfo['size'] > $this->validationRules['file_size']['max_size'];

        return $fileInfo;
    }

    /**
     * Validate file properties against rules
     */
    private function validateFileProperties(array $fileInfo): bool
    {
        $valid = true;

        // File size validation
        if ($fileInfo['size'] > $this->validationRules['file_size']['max_size']) {
            $this->addError('file_too_large', $this->validationRules['file_size']['message']);
            $valid = false;
        }

        // File extension validation
        if (!in_array($fileInfo['extension'], $this->validationRules['file_extension']['allowed'])) {
            $this->addError('invalid_extension', $this->validationRules['file_extension']['message']);
            $valid = false;
        }

        // MIME type validation (additional security)
        if (!$this->isValidSQLMimeType($fileInfo['mime_type'])) {
            $this->addWarning('suspicious_mime_type', 
                "File MIME type '{$fileInfo['mime_type']}' may not be a valid SQL file");
        }

        return $valid;
    }

    /**
     * Analyze SQL content structure
     */
    private function analyzeSQLContent(string $content): array
    {
        $analysis = [
            'length' => strlen($content),
            'lines' => substr_count($content, "\n") + 1,
            'statements' => $this->countSQLStatements($content),
            'tables' => $this->extractTableNames($content),
            'database_indicators' => $this->detectDatabaseIndicators($content),
            'encoding' => mb_detect_encoding($content),
            'has_comments' => $this->hasComments($content),
            'complexity_score' => $this->calculateComplexityScore($content)
        ];

        return $analysis;
    }

    /**
     * Validate SQL content
     */
    private function validateSQLContent(string $content, array $analysis): bool
    {
        $valid = true;

        // Length validation
        if ($analysis['length'] < $this->validationRules['sql_content']['min_length']) {
            $this->addError('sql_too_short', 'SQL content is too short to be valid');
            $valid = false;
        }

        if ($analysis['length'] > $this->validationRules['sql_content']['max_length']) {
            $this->addError('sql_too_long', 'SQL content exceeds maximum length');
            $valid = false;
        }

        // Basic SQL structure validation
        if ($analysis['statements'] === 0) {
            $this->addError('no_sql_statements', 'No valid SQL statements found');
            $valid = false;
        }

        // Encoding validation
        if ($analysis['encoding'] === false) {
            $this->addWarning('encoding_detection_failed', 'Could not detect file encoding');
        } elseif (!in_array($analysis['encoding'], ['UTF-8', 'ASCII', 'ISO-8859-1'])) {
            $this->addWarning('unusual_encoding', "Unusual encoding detected: {$analysis['encoding']}");
        }

        return $valid;
    }

    /**
     * Assess SQL security risks
     */
    private function assessSQLSecurity(string $content): array
    {
        $assessment = [
            'risk_level' => 'low',
            'dangerous_patterns' => [],
            'suspicious_content' => [],
            'recommendations' => []
        ];

        $content_lower = strtolower($content);

        // Check for dangerous patterns
        foreach ($this->validationRules['dangerous_sql']['patterns'] as $pattern) {
            if (preg_match($pattern, $content_lower, $matches)) {
                $assessment['dangerous_patterns'][] = [
                    'pattern' => $pattern,
                    'match' => $matches[0],
                    'severity' => $this->getPatternSeverity($pattern)
                ];
            }
        }

        // Additional suspicious content checks
        $suspiciousPatterns = [
            '/\bexec\s*\(/i' => 'Execution of dynamic SQL',
            '/\beval\s*\(/i' => 'Code evaluation',
            '/\bload_file\s*\(/i' => 'File system access',
            '/\binto\s+outfile\b/i' => 'File writing operation',
            '/\bunion\s+select\b/i' => 'Potential SQL injection pattern',
            '/\bconcat\s*\(/i' => 'String concatenation (potential injection)',
            '/\bsubstring\s*\(/i' => 'Data extraction function'
        ];

        foreach ($suspiciousPatterns as $pattern => $description) {
            if (preg_match($pattern, $content_lower)) {
                $assessment['suspicious_content'][] = [
                    'description' => $description,
                    'recommendation' => 'Review this operation carefully'
                ];
            }
        }

        // Determine overall risk level
        $dangerousCount = count($assessment['dangerous_patterns']);
        $suspiciousCount = count($assessment['suspicious_content']);

        if ($dangerousCount > 0) {
            $assessment['risk_level'] = 'high';
            $this->addError('high_security_risk', $this->validationRules['dangerous_sql']['message']);
        } elseif ($suspiciousCount > 2) {
            $assessment['risk_level'] = 'medium';
            $this->addWarning('medium_security_risk', 'SQL contains several suspicious patterns');
        } elseif ($suspiciousCount > 0) {
            $assessment['risk_level'] = 'low-medium';
            $this->addWarning('low_security_risk', 'SQL contains some patterns that should be reviewed');
        }

        return $assessment;
    }

    /**
     * Check database compatibility
     */
    private function checkDatabaseCompatibility(array $sqlAnalysis, string $targetDatabase): array
    {
        $compatibility = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'required_translations' => []
        ];

        $sourceDatabase = $sqlAnalysis['database_indicators']['primary'] ?? 'unknown';

        if ($sourceDatabase !== $targetDatabase && $sourceDatabase !== 'unknown') {
            $compatibility['compatible'] = false;
            $compatibility['required_translations'][] = "Translation required from {$sourceDatabase} to {$targetDatabase}";
            
            // Specific compatibility checks
            $this->checkSpecificCompatibilityIssues($sourceDatabase, $targetDatabase, $compatibility);
        }

        return $compatibility;
    }

    /**
     * Check specific database compatibility issues
     */
    private function checkSpecificCompatibilityIssues(string $source, string $target, array &$compatibility): void
    {
        $incompatibilityMatrix = [
            'mysql' => [
                'sqlite' => [
                    'AUTO_INCREMENT' => 'Use AUTOINCREMENT instead',
                    'ENGINE=' => 'Remove ENGINE clauses',
                    'CHARSET=' => 'Remove CHARSET specifications'
                ],
                'postgresql' => [
                    'AUTO_INCREMENT' => 'Use SERIAL type instead',
                    'ENUM' => 'Create custom type or use CHECK constraint',
                    'UNSIGNED' => 'Remove UNSIGNED keyword'
                ]
            ],
            'sqlite' => [
                'mysql' => [
                    'AUTOINCREMENT' => 'Use AUTO_INCREMENT instead',
                    'TEXT PRIMARY KEY' => 'Consider using INT AUTO_INCREMENT',
                    'PRAGMA' => 'Remove SQLite-specific PRAGMA statements'
                ],
                'postgresql' => [
                    'AUTOINCREMENT' => 'Use SERIAL type instead',
                    'TEXT' => 'Consider using VARCHAR with length',
                    'INTEGER PRIMARY KEY' => 'Use SERIAL PRIMARY KEY'
                ]
            ]
        ];

        if (isset($incompatibilityMatrix[$source][$target])) {
            foreach ($incompatibilityMatrix[$source][$target] as $issue => $solution) {
                $compatibility['issues'][] = [
                    'issue' => $issue,
                    'solution' => $solution
                ];
            }
        }
    }

    /**
     * Generate recommendations based on validation results
     */
    private function generateRecommendations(array $validationResult): array
    {
        $recommendations = [];

        // File size recommendations
        if ($validationResult['file_info']['is_large']) {
            $recommendations[] = [
                'type' => 'performance',
                'message' => 'Consider splitting large SQL files into smaller chunks for better performance',
                'action' => 'Split file or use manual import for files over 1MB'
            ];
        }

        // Security recommendations
        if ($validationResult['security_assessment']['risk_level'] === 'high') {
            $recommendations[] = [
                'type' => 'security',
                'message' => 'High security risk detected - review all dangerous operations',
                'action' => 'Manually review SQL content before execution'
            ];
        }

        // Compatibility recommendations
        if (isset($validationResult['compatibility']) && !$validationResult['compatibility']['compatible']) {
            $recommendations[] = [
                'type' => 'compatibility',
                'message' => 'Cross-database translation required',
                'action' => 'Use Enhanced Model translation features'
            ];
        }

        // Performance recommendations
        if ($validationResult['sql_analysis']['complexity_score'] > 7) {
            $recommendations[] = [
                'type' => 'performance',
                'message' => 'Complex SQL detected - execution may take longer',
                'action' => 'Consider executing during low-traffic periods'
            ];
        }

        return $recommendations;
    }

    /**
     * Helper methods
     */
    private function addError(string $code, string $message): void
    {
        $this->errors[] = ['code' => $code, 'message' => $message];
    }

    private function addWarning(string $code, string $message): void
    {
        $this->warnings[] = ['code' => $code, 'message' => $message];
    }

    private function clearResults(): void
    {
        $this->errors = [];
        $this->warnings = [];
    }

    private function getMimeType(string $filepath): string
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($filepath) ?: 'application/octet-stream';
        }
        
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filepath);
            finfo_close($finfo);
            return $mimeType ?: 'application/octet-stream';
        }
        
        return 'application/octet-stream';
    }

    private function isValidSQLMimeType(string $mimeType): bool
    {
        $validMimeTypes = [
            'text/plain',
            'text/x-sql',
            'application/sql',
            'application/x-sql',
            'application/octet-stream'
        ];
        
        return in_array($mimeType, $validMimeTypes);
    }

    private function countSQLStatements(string $content): int
    {
        // Simple statement counting - count semicolons outside of quotes
        $statements = 0;
        $inQuotes = false;
        $quoteChar = '';
        
        for ($i = 0; $i < strlen($content); $i++) {
            $char = $content[$i];
            
            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                // Check for escaped quotes
                if ($i === 0 || $content[$i-1] !== '\\') {
                    $inQuotes = false;
                }
            } elseif (!$inQuotes && $char === ';') {
                $statements++;
            }
        }
        
        return $statements;
    }

    private function extractTableNames(string $content): array
    {
        $tables = [];
        
        // Extract CREATE TABLE statements
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\'"]?(\w+)[`\'"]?/i', $content, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        
        // Extract INSERT INTO statements
        if (preg_match_all('/INSERT\s+INTO\s+[`\'"]?(\w+)[`\'"]?/i', $content, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        
        return array_unique($tables);
    }

    private function detectDatabaseIndicators(string $content): array
    {
        $indicators = [
            'mysql' => 0,
            'sqlite' => 0,
            'postgresql' => 0,
            'primary' => 'unknown'
        ];
        
        $patterns = [
            'mysql' => ['/AUTO_INCREMENT/i', '/ENGINE=/i', '/CHARSET=/i', '/COLLATE=/i'],
            'sqlite' => ['/AUTOINCREMENT/i', '/PRAGMA/i', '/sqlite_master/i'],
            'postgresql' => ['/SERIAL/i', '/nextval\(/i', '/SEQUENCE/i', '/::text/i']
        ];
        
        foreach ($patterns as $db => $dbPatterns) {
            foreach ($dbPatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $indicators[$db]++;
                }
            }
        }
        
        // Determine primary database type
        $maxScore = max($indicators['mysql'], $indicators['sqlite'], $indicators['postgresql']);
        if ($maxScore > 0) {
            $indicators['primary'] = array_search($maxScore, $indicators);
        }
        
        return $indicators;
    }

    private function hasComments(string $content): bool
    {
        return (strpos($content, '--') !== false || strpos($content, '/*') !== false);
    }

    private function calculateComplexityScore(string $content): int
    {
        $score = 0;
        
        // Count complex SQL features
        $complexFeatures = [
            'JOIN' => 2,
            'UNION' => 2,
            'SUBQUERY' => 3,
            'TRIGGER' => 4,
            'PROCEDURE' => 4,
            'FUNCTION' => 4,
            'VIEW' => 2,
            'INDEX' => 1,
            'CONSTRAINT' => 2
        ];
        
        foreach ($complexFeatures as $feature => $weight) {
            $count = preg_match_all("/{$feature}/i", $content);
            $score += $count * $weight;
        }
        
        return min($score, 10); // Cap at 10
    }

    private function getPatternSeverity(string $pattern): string
    {
        $highSeverityPatterns = [
            '/\bdrop\s+database\b/i',
            '/\btruncate\s+table\b/i',
            '/\bdelete\s+from\s+\w+\s*$/i'
        ];
        
        return in_array($pattern, $highSeverityPatterns) ? 'high' : 'medium';
    }

    /**
     * Get validation summary
     */
    public function getValidationSummary(): array
    {
        return [
            'error_count' => count($this->errors),
            'warning_count' => count($this->warnings),
            'has_errors' => !empty($this->errors),
            'has_warnings' => !empty($this->warnings),
            'errors' => $this->errors,
            'warnings' => $this->warnings
        ];
    }
}