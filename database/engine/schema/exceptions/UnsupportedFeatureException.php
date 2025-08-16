<?php

require_once __DIR__ .'/TranslationException.php';

/**
 * Unsupported Feature Exception
 * 
 * Thrown when attempting to translate a feature that is not supported
 * by the target database platform.
 * 
 * @package Database\Exceptions
 * @author Enhanced Model System
 * @version 1.0.0
 */
class UnsupportedFeatureException extends TranslationException
{
    private string $feature;
    private string $sourceDatabase;
    private string $targetDatabase;
    
    public function __construct(
        string $feature, 
        string $sourceDatabase, 
        string $targetDatabase,
        string $message = ""
    ) {
        $this->feature = $feature;
        $this->sourceDatabase = $sourceDatabase;
        $this->targetDatabase = $targetDatabase;
        
        if (empty($message)) {
            $message = "Feature '$feature' from $sourceDatabase is not supported in $targetDatabase";
        }
        
        parent::__construct($message, 0, null, [
            'feature' => $feature,
            'source_database' => $sourceDatabase,
            'target_database' => $targetDatabase
        ]);
    }
    
    public function getFeature(): string
    {
        return $this->feature;
    }
    
    public function getSourceDatabase(): string
    {
        return $this->sourceDatabase;
    }
    
    public function getTargetDatabase(): string
    {
        return $this->targetDatabase;
    }
}