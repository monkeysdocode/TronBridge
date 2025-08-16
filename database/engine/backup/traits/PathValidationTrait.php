<?php 

/**
 * Trait PathValidationTrait
 * 
 * Provides consistent path validation for backup and restore operations.
 * Delegates to DatabaseSecurity for comprehensive security checks.
 * 
 * Usage: Add `use PathValidationTrait;` to strategy classes.
 * Call `$this->validateBackupPath($path);` or `$this->validateRestorePath($path);` as before.
 */
trait PathValidationTrait
{
    /**
     * Validate backup path using comprehensive DatabaseSecurity validation
     * (Add this method to ALL MySQL and PostgreSQL strategy classes)
     * 
     * @param string $outputPath Backup output path
     * @throws InvalidArgumentException If path is unsafe
     */
    protected function validateBackupPath(string $outputPath): void
    {
        try {
            // Use the comprehensive DatabaseSecurity validation
            DatabaseSecurity::validateBackupPath($outputPath, 'backup');

            $this->debugLog("Strategy-level path validation passed", DebugLevel::VERBOSE, [
                'strategy' => static::class,
                'validated_path' => $outputPath
            ]);
        } catch (InvalidArgumentException $e) {
            $this->debugLog("SECURITY: Strategy-level validation blocked malicious path", DebugLevel::BASIC, [
                'strategy' => static::class,
                'blocked_path' => $outputPath,
                'security_violation' => $e->getMessage()
            ]);

            throw $e; // Re-throw the security exception
        }
    }

    /**
     * Validate restore backup file path
     * 
     * @param string $backupPath Path to backup file for restore
     * @throws InvalidArgumentException If path is unsafe
     */
    private function validateRestorePath(string $backupPath): void
    {
        try {
            DatabaseSecurity::validateBackupPath($backupPath, 'restore');

            $this->debugLog("Restore path validation passed", DebugLevel::VERBOSE, [
                'strategy' => static::class,
                'backup_file' => $backupPath
            ]);
        } catch (InvalidArgumentException $e) {
            $this->debugLog("SECURITY: Restore path blocked", DebugLevel::BASIC, [
                'strategy' => static::class,
                'blocked_path' => $backupPath,
                'security_violation' => $e->getMessage()
            ]);

            throw new InvalidArgumentException("Restore file security validation failed: " . $e->getMessage());
        }
    }
}