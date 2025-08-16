<?php

/**
 * Transferer Detection Helper
 * 
 * Handles detection of Enhanced Model availability, database types,
 * and translation requirements for the enhanced transferer system.
 * 
 * @package Trongate\TgTransferer
 * @version 1.0.0
 */
class TransfererDetection
{
    /**
     * Check if Enhanced Model ORM is available
     */
    public static function hasEnhancedModel(): bool
    {
        // Check if SQLDumpTranslator factory exists
        $factoryPath = dirname(__DIR__, 2) . '/database/engine/factories/SQLDumpTranslator.php';
        return file_exists($factoryPath);
    }

    /**
     * Get current database configuration from Enhanced Model
     */
    public static function getCurrentDatabaseConfig(): ?array
    {
        try {
            // Try to instantiate a Model to get current database config
            $modelPath = dirname(__DIR__) . '/Model.php';
            if (!file_exists($modelPath)) {
                return null;
            }
            
            require_once $modelPath;
            $model = new Model();
            
            // Get database configuration
            $config = $model->getConfig();
            
            return [
                'type' => $config->getType(),
                'config' => $config
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Detect source database type from SQL content
     */
    public static function detectSourceDatabaseType(string $sqlContent): string
    {
        $sqlLower = strtolower($sqlContent);
        
        // SQLite indicators (strongest indicators first)
        $sqliteIndicators = [
            'pragma foreign_keys',
            'pragma table_info',
            'autoincrement',
            'sqlite_master',
            'sqlite_sequence',
            'without rowid',
            'pragma'
        ];
        
        // PostgreSQL indicators
        $postgresIndicators = [
            'serial primary key',
            'bigserial',
            'nextval(',
            'create sequence',
            'information_schema.',
            'pg_catalog.',
            'postgresql',
            'uuid',
            'interval',
            'timestamp with time zone'
        ];
        
        // MySQL indicators
        $mysqlIndicators = [
            'auto_increment',
            'engine=',
            'character set',
            'collate',
            'mysql',
            'mariadb',
            'information_schema.tables',
            'show tables',
            'describe '
        ];
        
        // Count indicators for each database type
        $scores = [
            'sqlite' => 0,
            'postgresql' => 0,
            'mysql' => 0
        ];
        
        // Check SQLite indicators
        foreach ($sqliteIndicators as $indicator) {
            if (strpos($sqlLower, $indicator) !== false) {
                $scores['sqlite'] += 2; // SQLite indicators get double weight
            }
        }
        
        // Check PostgreSQL indicators
        foreach ($postgresIndicators as $indicator) {
            if (strpos($sqlLower, $indicator) !== false) {
                $scores['postgresql']++;
            }
        }
        
        // Check MySQL indicators
        foreach ($mysqlIndicators as $indicator) {
            if (strpos($sqlLower, $indicator) !== false) {
                $scores['mysql']++;
            }
        }
        
        // Find highest scoring database type
        $maxScore = max($scores);
        if ($maxScore === 0) {
            // No clear indicators found, default to MySQL as per user requirement
            return 'mysql';
        }
        
        // Return the database type with highest score
        $detectedType = array_search($maxScore, $scores);
        return $detectedType ?: 'mysql';
    }

    /**
     * Check if translation is required
     */
    public static function isTranslationRequired(string $sourceType, string $targetType): bool
    {
        return strtolower($sourceType) !== strtolower($targetType);
    }

    /**
     * Get supported database types for Enhanced Model
     */
    public static function getSupportedDatabaseTypes(): array
    {
        return ['mysql', 'sqlite', 'postgresql'];
    }

    /**
     * Validate database type
     */
    public static function isValidDatabaseType(string $type): bool
    {
        return in_array(strtolower($type), self::getSupportedDatabaseTypes());
    }

    /**
     * Get user-friendly database type name
     */
    public static function getDatabaseTypeName(string $type): string
    {
        $names = [
            'mysql' => 'MySQL',
            'sqlite' => 'SQLite',
            'postgresql' => 'PostgreSQL'
        ];
        
        return $names[strtolower($type)] ?? ucfirst(strtolower($type));
    }

    /**
     * Analyze SQL file for enhanced transferer info
     */
    public static function analyzeSQLFile(string $filepath): array
    {
        if (!file_exists($filepath)) {
            return [
                'exists' => false,
                'error' => 'File not found'
            ];
        }
        
        $filesize = filesize($filepath);
        $content = file_get_contents($filepath);
        $sourceType = self::detectSourceDatabaseType($content);
        
        // Get current target database type
        $currentDb = self::getCurrentDatabaseConfig();
        $targetType = $currentDb ? $currentDb['type'] : 'mysql';
        
        return [
            'exists' => true,
            'filesize' => $filesize,
            'filesize_kb' => round($filesize / 1024, 2),
            'source_type' => $sourceType,
            'target_type' => $targetType,
            'translation_required' => self::isTranslationRequired($sourceType, $targetType),
            'enhanced_model_available' => self::hasEnhancedModel(),
            'source_type_name' => self::getDatabaseTypeName($sourceType),
            'target_type_name' => self::getDatabaseTypeName($targetType),
            'content_preview' => substr($content, 0, 500) // First 500 chars for preview
        ];
    }
}