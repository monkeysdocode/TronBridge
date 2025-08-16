<?php 

/**
 * Trait ConfigSanitizationTrait
 * 
 * Provides secure configuration sanitization by masking sensitive data.
 * Centralizes password masking logic for consistent security handling.
 * 
 * Usage: Add `use ConfigSanitizationTrait;` to strategy classes.
 * Call `$sanitizedConfig = $this->sanitizeConfig($config);` as before.
 */
trait ConfigSanitizationTrait
{
    /**
     * Sanitize configuration array by masking sensitive fields
     * 
     * Masks passwords and other sensitive data while preserving structure.
     * 
     * @param array $config Configuration array to sanitize
     * @return array Sanitized configuration
     */
    protected function sanitizeConfig(array $config): array
    {
        $sanitized = $config;
        
        // Mask password fields
        if (isset($sanitized['password'])) {
            $sanitized['password'] = '[HIDDEN]';
        }
        
        // Add other sensitive fields to mask as needed
        // Example: if (isset($sanitized['api_key'])) { $sanitized['api_key'] = '[HIDDEN]'; }
        
        return $sanitized;
    }
}