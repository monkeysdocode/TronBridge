<?php 

/**
 * Restore Strategy Interface - Contract for Database Restore Implementations
 * 
 * Extended interface for strategies that provide enhanced restore capabilities.
 * Some backup strategies may implement this interface to provide additional
 * restore-specific functionality.
 * 
 * @package Database\Backup\Strategy
 */
interface RestoreStrategyInterface extends BackupStrategyInterface
{
    /**
     * Validate backup file before restoration
     * 
     * Performs comprehensive validation of backup file including format verification,
     * integrity checks, and compatibility analysis.
     * 
     * @param string $backupPath Path to backup file
     * @return array Validation results
     */
    public function validateBackupFile(string $backupPath): array;

    /**
     * Get restore options for backup file
     * 
     * Analyzes backup file and returns available restore options based on
     * the backup content and format.
     * 
     * @param string $backupPath Path to backup file
     * @return array Available restore options
     */
    public function getRestoreOptions(string $backupPath): array;

    /**
     * Perform partial restore
     * 
     * Restores only specific tables or data subsets from backup file.
     * 
     * @param string $backupPath Path to backup file
     * @param array $targets Specific tables or data to restore
     * @param array $options Restore configuration options
     * @return array Restore result
     */
    public function partialRestore(string $backupPath, array $targets, array $options = []): array;
}