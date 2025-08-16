<?php

/**
 * Backup Strategy Interface - Contract for Database Backup Implementations
 * 
 * Defines the standard interface that all database backup strategies must implement.
 * Provides a unified API for backup and restore operations across different database
 * types and backup methods, with comprehensive capability reporting and testing.
 * 
 * This interface supports both traditional backup strategies (shell-based tools)
 * and PHP-based implementations, allowing for flexible deployment in various
 * hosting environments with different security constraints.
 * 
 * @package Database\Backup\Strategy
 * @author Enhanced Model System
 * @version 1.0.0 - Initial Interface Definition
 */
interface BackupStrategyInterface
{
    /**
     * Create database backup
     * 
     * Main backup method that creates a backup of the database using the specific
     * strategy implementation. Should handle all aspects of backup creation including
     * error handling, progress tracking, and cleanup.
     * 
     * @param string $outputPath Path where backup file should be created
     * @param array $options Backup configuration options
     * @return array Backup result with success status, metadata, and diagnostics
     * @throws RuntimeException If backup fails and cannot be recovered
     */
    public function createBackup(string $outputPath, array $options = []): array;

    /**
     * Restore database from backup
     * 
     * Restores database from a backup file created by this or compatible strategy.
     * Should handle format detection, validation, and proper restoration process.
     * 
     * @param string $backupPath Path to backup file
     * @param array $options Restore configuration options
     * @return array Restore result with success status and metadata
     * @throws RuntimeException If restore fails
     */
    public function restoreBackup(string $backupPath, array $options = []): array;

    /**
     * Test strategy capabilities without creating backup
     * 
     * Performs a comprehensive test of the strategy's capabilities, checking
     * all dependencies, permissions, and configuration requirements. Used for
     * troubleshooting and capability verification.
     * 
     * @return array Test results with detailed capability analysis
     * @throws RuntimeException If critical capabilities are missing
     */
    public function testCapabilities(): array;

    /**
     * Estimate backup size in bytes
     * 
     * Calculates estimated backup file size based on current database size,
     * compression settings, and backup format. Used for storage planning
     * and progress estimation.
     * 
     * @return int Estimated backup size in bytes
     */
    public function estimateBackupSize(): int;

    /**
     * Estimate backup duration in seconds
     * 
     * Estimates how long the backup process will take based on database size,
     * system performance, and historical data. Used for timeout configuration
     * and progress monitoring.
     * 
     * @return int Estimated backup duration in seconds
     */
    public function estimateBackupTime(): int;

    /**
     * Get strategy type identifier
     * 
     * Returns a unique identifier for this strategy type, used for logging,
     * debugging, and strategy selection logic.
     * 
     * @return string Strategy type identifier (e.g., 'mysql_shell', 'sqlite_native')
     */
    public function getStrategyType(): string;

    /**
     * Get human-readable strategy description
     * 
     * Returns a descriptive name for this strategy, suitable for user interfaces
     * and diagnostic output.
     * 
     * @return string Strategy description
     */
    public function getDescription(): string;

    /**
     * Get strategy selection criteria
     * 
     * Returns information about what makes this strategy preferable or suitable
     * for specific situations. Used by the factory for strategy selection.
     * 
     * @return array Selection criteria and preferences
     */
    public function getSelectionCriteria(): array;

    /**
     * Check if strategy supports compression
     * 
     * @return bool True if strategy can create compressed backups
     */
    public function supportsCompression(): bool;

    /**
     * Detect backup format from file
     * 
     * Analyzes a backup file to determine its format and compatibility with
     * this strategy for restoration purposes.
     * 
     * @param string $backupPath Path to backup file
     * @return string Detected format identifier
     */
    public function detectBackupFormat(string $backupPath): string;
}