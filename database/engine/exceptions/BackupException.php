<?php

/**
 * Backup Exception - Custom exception for backup and restore operations
 * 
 * Provides specialized exception handling for backup/restore operations with
 * additional context and error categorization to help with debugging and
 * user-friendly error reporting.
 * 
 * @package Database\Exceptions
 * @author Enhanced Model System
 * @version 1.0.0
 */
class BackupException extends Exception
{
    /**
     * Exception types for categorization
     */
    public const TYPE_FILE_NOT_FOUND = 'file_not_found';
    public const TYPE_FILE_EMPTY = 'file_empty';
    public const TYPE_FILE_CORRUPT = 'file_corrupt';
    public const TYPE_PERMISSION_DENIED = 'permission_denied';
    public const TYPE_DISK_SPACE = 'disk_space';
    public const TYPE_STRATEGY_UNAVAILABLE = 'strategy_unavailable';
    public const TYPE_DATABASE_CONNECTION = 'database_connection';
    public const TYPE_VALIDATION_FAILED = 'validation_failed';
    public const TYPE_RESTORE_FAILED = 'restore_failed';
    public const TYPE_BACKUP_FAILED = 'backup_failed';
    public const TYPE_TIMEOUT = 'timeout';
    public const TYPE_GENERAL = 'general';
    
    /**
     * Exception type
     */
    private string $exceptionType;
    
    /**
     * Additional context data
     */
    private array $context;
    
    /**
     * Path related to the exception (if applicable)
     */
    private ?string $path;
    
    /**
     * Database type related to the exception (if applicable)
     */
    private ?string $databaseType;
    
    /**
     * Strategy type related to the exception (if applicable)
     */
    private ?string $strategyType;
    
    /**
     * Create new BackupException
     * 
     * @param string $message Exception message
     * @param string $type Exception type (use TYPE_* constants)
     * @param array $context Additional context data
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message, 
        string $type = self::TYPE_GENERAL, 
        array $context = [], 
        int $code = 0, 
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->exceptionType = $type;
        $this->context = $context;
        $this->path = $context['path'] ?? null;
        $this->databaseType = $context['database_type'] ?? null;
        $this->strategyType = $context['strategy_type'] ?? null;
    }
    
    /**
     * Create file not found exception
     * 
     * @param string $filePath Path to missing file
     * @param string $operation Operation being performed
     * @return static
     */
    public static function fileNotFound(string $filePath, string $operation = 'backup operation'): static
    {
        return new static(
            "Backup file not found: $filePath",
            self::TYPE_FILE_NOT_FOUND,
            [
                'path' => $filePath,
                'operation' => $operation,
                'file_exists' => file_exists($filePath)
            ]
        );
    }
    
    /**
     * Create empty file exception
     * 
     * @param string $filePath Path to empty file
     * @param string $operation Operation being performed
     * @return static
     */
    public static function fileEmpty(string $filePath, string $operation = 'backup operation'): static
    {
        return new static(
            "Backup file is empty: $filePath",
            self::TYPE_FILE_EMPTY,
            [
                'path' => $filePath,
                'operation' => $operation,
                'file_size' => file_exists($filePath) ? filesize($filePath) : 0
            ]
        );
    }
    
    /**
     * Create corrupt file exception
     * 
     * @param string $filePath Path to corrupt file
     * @param string $reason Reason for corruption detection
     * @return static
     */
    public static function fileCorrupt(string $filePath, string $reason = 'Invalid format detected'): static
    {
        return new static(
            "Backup file appears to be corrupt: $filePath - $reason",
            self::TYPE_FILE_CORRUPT,
            [
                'path' => $filePath,
                'corruption_reason' => $reason,
                'file_size' => file_exists($filePath) ? filesize($filePath) : 0
            ]
        );
    }
    
    /**
     * Create permission denied exception
     * 
     * @param string $filePath Path with permission issues
     * @param string $operation Operation being attempted
     * @return static
     */
    public static function permissionDenied(string $filePath, string $operation): static
    {
        return new static(
            "Permission denied for $operation: $filePath",
            self::TYPE_PERMISSION_DENIED,
            [
                'path' => $filePath,
                'operation' => $operation,
                'is_readable' => is_readable($filePath),
                'is_writable' => is_writable(dirname($filePath))
            ]
        );
    }
    
    /**
     * Create disk space exception
     * 
     * @param string $path Path where space is needed
     * @param int $requiredBytes Bytes required
     * @param int $availableBytes Bytes available
     * @return static
     */
    public static function diskSpace(string $path, int $requiredBytes, int $availableBytes): static
    {
        return new static(
            "Insufficient disk space for backup operation. Required: " . 
            number_format($requiredBytes) . " bytes, Available: " . 
            number_format($availableBytes) . " bytes",
            self::TYPE_DISK_SPACE,
            [
                'path' => $path,
                'required_bytes' => $requiredBytes,
                'available_bytes' => $availableBytes,
                'shortfall_bytes' => $requiredBytes - $availableBytes
            ]
        );
    }
    
    /**
     * Create strategy unavailable exception
     * 
     * @param string $strategyType Strategy type that's unavailable
     * @param string $databaseType Database type
     * @param string $reason Reason why strategy is unavailable
     * @return static
     */
    public static function strategyUnavailable(string $strategyType, string $databaseType, string $reason): static
    {
        return new static(
            "Backup strategy '$strategyType' unavailable for $databaseType: $reason",
            self::TYPE_STRATEGY_UNAVAILABLE,
            [
                'strategy_type' => $strategyType,
                'database_type' => $databaseType,
                'unavailable_reason' => $reason
            ]
        );
    }
    
    /**
     * Create database connection exception
     * 
     * @param string $databaseType Database type
     * @param string $connectionDetails Connection details (safe for logging)
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function databaseConnection(string $databaseType, string $connectionDetails, ?Throwable $previous = null): static
    {
        return new static(
            "Database connection failed for $databaseType backup: $connectionDetails",
            self::TYPE_DATABASE_CONNECTION,
            [
                'database_type' => $databaseType,
                'connection_details' => $connectionDetails
            ],
            0,
            $previous
        );
    }
    
    /**
     * Create validation failed exception
     * 
     * @param string $filePath Path to file that failed validation
     * @param array $validationErrors Array of validation errors
     * @return static
     */
    public static function validationFailed(string $filePath, array $validationErrors): static
    {
        return new static(
            "Backup file validation failed: $filePath - " . implode(', ', $validationErrors),
            self::TYPE_VALIDATION_FAILED,
            [
                'path' => $filePath,
                'validation_errors' => $validationErrors,
                'error_count' => count($validationErrors)
            ]
        );
    }
    
    /**
     * Create restore failed exception
     * 
     * @param string $filePath Path to backup file
     * @param string $reason Reason for restore failure
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function restoreFailed(string $filePath, string $reason, array $context = [], ?Throwable $previous = null): static
    {
        return new static(
            "Database restore failed: $reason",
            self::TYPE_RESTORE_FAILED,
            array_merge([
                'path' => $filePath,
                'failure_reason' => $reason
            ], $context),
            0,
            $previous
        );
    }
    
    /**
     * Create backup failed exception
     * 
     * @param string $outputPath Path where backup was being created
     * @param string $reason Reason for backup failure
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function backupFailed(string $outputPath, string $reason, array $context = [], ?Throwable $previous = null): static
    {
        return new static(
            "Database backup failed: $reason",
            self::TYPE_BACKUP_FAILED,
            array_merge([
                'path' => $outputPath,
                'failure_reason' => $reason
            ], $context),
            0,
            $previous
        );
    }
    
    /**
     * Create timeout exception
     * 
     * @param string $operation Operation that timed out
     * @param int $timeoutSeconds Timeout value in seconds
     * @param array $context Additional context
     * @return static
     */
    public static function timeout(string $operation, int $timeoutSeconds, array $context = []): static
    {
        return new static(
            "Operation timed out after {$timeoutSeconds} seconds: $operation",
            self::TYPE_TIMEOUT,
            array_merge([
                'operation' => $operation,
                'timeout_seconds' => $timeoutSeconds
            ], $context)
        );
    }
    
    /**
     * Get exception type
     * 
     * @return string Exception type
     */
    public function getExceptionType(): string
    {
        return $this->exceptionType;
    }
    
    /**
     * Get context data
     * 
     * @return array Context data
     */
    public function getContext(): array
    {
        return $this->context;
    }
    
    /**
     * Get file path related to exception
     * 
     * @return string|null File path or null if not applicable
     */
    public function getPath(): ?string
    {
        return $this->path;
    }
    
    /**
     * Get database type related to exception
     * 
     * @return string|null Database type or null if not applicable
     */
    public function getDatabaseType(): ?string
    {
        return $this->databaseType;
    }
    
    /**
     * Get strategy type related to exception
     * 
     * @return string|null Strategy type or null if not applicable
     */
    public function getStrategyType(): ?string
    {
        return $this->strategyType;
    }
    
    /**
     * Check if exception is of specific type
     * 
     * @param string $type Exception type to check
     * @return bool True if exception is of specified type
     */
    public function isType(string $type): bool
    {
        return $this->exceptionType === $type;
    }
    
    /**
     * Check if exception is related to file operations
     * 
     * @return bool True if file-related exception
     */
    public function isFileRelated(): bool
    {
        return in_array($this->exceptionType, [
            self::TYPE_FILE_NOT_FOUND,
            self::TYPE_FILE_EMPTY,
            self::TYPE_FILE_CORRUPT,
            self::TYPE_PERMISSION_DENIED
        ]);
    }
    
    /**
     * Check if exception is related to system resources
     * 
     * @return bool True if system resource exception
     */
    public function isSystemResourceRelated(): bool
    {
        return in_array($this->exceptionType, [
            self::TYPE_DISK_SPACE,
            self::TYPE_TIMEOUT,
            self::TYPE_PERMISSION_DENIED
        ]);
    }
    
    /**
     * Get user-friendly error message
     * 
     * @return string User-friendly message
     */
    public function getUserFriendlyMessage(): string
    {
        switch ($this->exceptionType) {
            case self::TYPE_FILE_NOT_FOUND:
                return "The backup file could not be found. Please check the file path and try again.";
                
            case self::TYPE_FILE_EMPTY:
                return "The backup file is empty or corrupted. Please create a new backup and try again.";
                
            case self::TYPE_FILE_CORRUPT:
                return "The backup file appears to be corrupted and cannot be restored.";
                
            case self::TYPE_PERMISSION_DENIED:
                return "Permission denied. Please check file permissions and try again.";
                
            case self::TYPE_DISK_SPACE:
                return "Insufficient disk space to complete the operation. Please free up space and try again.";
                
            case self::TYPE_STRATEGY_UNAVAILABLE:
                return "The required backup method is not available. Please check your system configuration.";
                
            case self::TYPE_DATABASE_CONNECTION:
                return "Unable to connect to the database. Please check your connection settings.";
                
            case self::TYPE_VALIDATION_FAILED:
                return "The backup file failed validation checks and may be corrupted.";
                
            case self::TYPE_RESTORE_FAILED:
                return "The database restore operation failed. Please check the backup file and try again.";
                
            case self::TYPE_BACKUP_FAILED:
                return "The database backup operation failed. Please try again.";
                
            case self::TYPE_TIMEOUT:
                return "The operation timed out. Please try again or increase the timeout limit.";
                
            default:
                return "A backup/restore error occurred: " . $this->getMessage();
        }
    }
    
    /**
     * Get debugging information
     * 
     * @return array Debug information
     */
    public function getDebugInfo(): array
    {
        return [
            'exception_type' => $this->exceptionType,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->context,
            'path' => $this->path,
            'database_type' => $this->databaseType,
            'strategy_type' => $this->strategyType,
            'trace_count' => count($this->getTrace())
        ];
    }
    
    /**
     * Convert exception to array for logging/serialization
     * 
     * @return array Exception data
     */
    public function toArray(): array
    {
        return [
            'type' => $this->exceptionType,
            'message' => $this->getMessage(),
            'user_message' => $this->getUserFriendlyMessage(),
            'code' => $this->getCode(),
            'context' => $this->context,
            'path' => $this->path,
            'database_type' => $this->databaseType,
            'strategy_type' => $this->strategyType,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'is_file_related' => $this->isFileRelated(),
            'is_system_resource_related' => $this->isSystemResourceRelated()
        ];
    }
}