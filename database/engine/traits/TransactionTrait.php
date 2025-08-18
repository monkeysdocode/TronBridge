<?php

/**
 * Trait TransactionTrait
 *
 * Manages database transactions with support for nesting, timeouts, and memory limits.
 * Ensures data consistency across multiple operations with automatic rollback on errors.
 */
trait TransactionTrait {
    /**
     * Safe transaction wrapper with automatic nested transaction handling
     * 
     * Provides comprehensive transaction management with automatic rollback on exceptions,
     * nested transaction support using savepoints, timeout detection, and memory management.
     * Eliminates common transaction pitfalls and provides foolproof database operations.
     * 
     * @param callable|null $callback Optional callback function to execute within transaction
     * @param array $options Transaction configuration options
     * @return mixed Result of callback function, or TransactionManager instance for manual use
     * @throws InvalidArgumentException If callback is not callable when provided
     * @throws RuntimeException If transaction operations fail
     * @throws TransactionTimeoutException If transaction exceeds time limit
     * 
     * @example
     * // Closure-based transaction (recommended)
     * $result = $model->transaction(function() use ($model) {
     *     $userId = $model->insert($userData, 'users');
     *     $model->insert(['user_id' => $userId, 'role' => 'admin'], 'user_roles');
     *     return $userId; // Automatically committed
     * });
     * 
     * @example
     * // Manual transaction management
     * $tx = $model->transaction();
     * try {
     *     $userId = $model->insert($userData, 'users');
     *     $model->insert(['user_id' => $userId, 'role' => 'admin'], 'user_roles');
     *     $tx->commit();
     * } catch (Exception $e) {
     *     $tx->rollback(); // Explicit rollback
     *     throw $e;
     * }
     * 
     * @example
     * // Nested transactions with savepoints
     * $model->transaction(function() use ($model) {
     *     $model->insert($outerData, 'table1');
     *     
     *     $model->transaction(function() use ($model) {
     *         $model->insert($innerData, 'table2'); // Uses savepoint
     *         // Inner transaction can rollback independently
     *     });
     *     
     *     $model->insert($moreData, 'table3');
     * });
     */
    public function transaction(?callable $callback = null, array $options = []): mixed
    {
        $this->connect();
        $startTime = microtime(true);

        // If no callback provided, return a TransactionManager for manual use
        if ($callback === null) {
            require_once dirname(__DIR__) . '/database/engine/factories/DatabaseTransaction.php';
            return new TransactionManager($this, $options);
        }

        // Validate callback
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Transaction callback must be callable');
        }

        // Parse options with defaults
        $timeout = $options['timeout'] ?? $this->maxTransactionDuration;
        $memoryLimit = $options['memory_limit'] ?? null;
        $enableDebugging = $options['debug'] ?? $this->debug;

        // FIXED: Check if nested BEFORE incrementing
        $isNestedTransaction = $this->transactionLevel > 0;
        $savepointName = null;
        $startMemory = memory_get_usage(true);

        // Replace echo with debug system integration
        $this->debugLog(
            "Transaction starting",
            DebugCategory::TRANSACTION,
            DebugLevel::BASIC,
            [
                'transaction_level' => $this->transactionLevel + 1,
                'is_nested' => $isNestedTransaction,
                'transaction_type' => $isNestedTransaction ? 'nested_savepoint' : 'root_transaction',
                'timeout' => $timeout,
                'memory_limit' => $memoryLimit,
                'debugging_enabled' => $enableDebugging,
                'supports_savepoints' => $this->supportsSavepoints()
            ]
        );

        // FIXED: Increment level AFTER determining if nested
        $this->transactionLevel++;

        try {
            // Handle nested transactions with savepoints
            if ($isNestedTransaction) {
                if ($this->supportsSavepoints()) {
                    $savepointName = 'sp_' . (++$this->savepointCounter);
                    $this->createSavepoint($savepointName);

                    $this->debugLog(
                        "Savepoint created for nested transaction",
                        DebugCategory::TRANSACTION,
                        DebugLevel::DETAILED,
                        [
                            'savepoint_name' => $savepointName,
                            'transaction_level' => $this->transactionLevel,
                            'savepoint_counter' => $this->savepointCounter,
                            'database_type' => $this->dbType
                        ]
                    );
                } else {
                    // For databases without savepoint support, use reference counting
                    $this->debugLog(
                        "Nested transaction using reference counting (no savepoint support)",
                        DebugCategory::TRANSACTION,
                        DebugLevel::DETAILED,
                        [
                            'transaction_level' => $this->transactionLevel,
                            'database_type' => $this->dbType,
                            'savepoint_support' => false,
                            'fallback_method' => 'reference_counting'
                        ]
                    );
                }
            } else {
                // Start root transaction
                if ($this->transactionStartTime === null) {
                    $this->transactionStartTime = $startTime;
                }

                if (!$this->dbh->beginTransaction()) {
                    throw new RuntimeException('Failed to begin transaction');
                }

                $this->debugLog(
                    "Root transaction started",
                    DebugCategory::TRANSACTION,
                    DebugLevel::BASIC,
                    [
                        'transaction_level' => $this->transactionLevel,
                        'transaction_start_time' => $this->transactionStartTime,
                        'database_type' => $this->dbType,
                        'pdo_transaction_active' => $this->dbh->inTransaction()
                    ]
                );
            }

            // Execute callback with monitoring
            $result = $this->executeWithMonitoring($callback, $timeout, $memoryLimit, $enableDebugging);

            // Handle commit based on transaction level
            if ($isNestedTransaction) {
                if ($savepointName) {
                    $this->releaseSavepoint($savepointName);

                    $this->debugLog(
                        "Savepoint released successfully",
                        DebugCategory::TRANSACTION,
                        DebugLevel::DETAILED,
                        [
                            'savepoint_name' => $savepointName,
                            'transaction_level' => $this->transactionLevel,
                            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                        ]
                    );
                }
            } else {
                // Commit root transaction
                if (!$this->dbh->commit()) {
                    throw new RuntimeException('Failed to commit transaction');
                }

                $this->transactionStartTime = null;
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $memoryUsed = $this->formatBytes(memory_get_usage(true) - $startMemory);

                $this->debugLog(
                    "Root transaction committed successfully",
                    DebugCategory::TRANSACTION,
                    DebugLevel::BASIC,
                    [
                        'transaction_level' => $this->transactionLevel,
                        'execution_time_ms' => $duration,
                        'memory_used' => $memoryUsed,
                        'memory_used_bytes' => memory_get_usage(true) - $startMemory,
                        'callback_executed' => true,
                        'operation' => 'transaction_commit'
                    ]
                );
            }

            // FIXED: Decrement transaction level only on success
            $this->transactionLevel--;

            return $result;
        } catch (Exception $e) {
            // FIXED: Handle rollback based on transaction level and error type
            try {
                if ($isNestedTransaction) {
                    if ($savepointName) {
                        $this->rollbackToSavepoint($savepointName);

                        $this->debugLog(
                            "Rollback to savepoint completed",
                            DebugCategory::TRANSACTION,
                            DebugLevel::BASIC,
                            [
                                'savepoint_name' => $savepointName,
                                'transaction_level' => $this->transactionLevel,
                                'error_message' => $e->getMessage(),
                                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                                'rollback_reason' => 'exception_occurred'
                            ]
                        );
                    }
                } else {
                    // Rollback root transaction
                    if ($this->dbh->inTransaction()) {
                        $this->dbh->rollback();
                    }

                    $this->transactionStartTime = null;

                    $this->debugLog(
                        "Root transaction rolled back due to exception",
                        DebugCategory::TRANSACTION,
                        DebugLevel::BASIC,
                        [
                            'transaction_level' => $this->transactionLevel,
                            'error_message' => $e->getMessage(),
                            'error_code' => $e->getCode(),
                            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                            'rollback_reason' => 'exception_occurred',
                            'operation' => 'transaction_rollback'
                        ]
                    );
                }
            } catch (Exception $rollbackException) {
                // Log rollback failure but throw original exception
                $this->debugLog(
                    "Transaction rollback failed",
                    DebugCategory::TRANSACTION,
                    DebugLevel::BASIC,
                    [
                        'original_error' => $e->getMessage(),
                        'rollback_error' => $rollbackException->getMessage(),
                        'transaction_level' => $this->transactionLevel,
                        'critical_failure' => true
                    ]
                );
                error_log("Transaction rollback failed: " . $rollbackException->getMessage());
            }

            // FIXED: Decrement transaction level in finally block to ensure it always happens
            $this->transactionLevel--;

            // Re-throw original exception with enhanced context
            throw new RuntimeException(
                "Transaction failed at level " . $this->transactionLevel . ": " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Check if database supports savepoints
     * 
     * @return bool True if savepoints are supported
     */
    public function supportsSavepoints(): bool
    {
        return match ($this->dbType) {
            'mysql', 'postgresql' => true,
            'sqlite' => false, // SQLite savepoints have limitations in PDO
            default => false
        };
    }

    /**
     * Create a savepoint for nested transactions
     * 
     * @param string $name Savepoint name
     * @throws RuntimeException If savepoint creation fails
     */
    public function createSavepoint(string $name): void
    {
        $sql = match ($this->dbType) {
            'mysql', 'postgresql' => "SAVEPOINT $name",
            default => throw new RuntimeException("Savepoints not supported for database type: {$this->dbType}")
        };

        $this->dbh->exec($sql);
    }

    /**
     * Release a savepoint (commit nested transaction)
     * 
     * @param string $name Savepoint name
     * @throws RuntimeException If savepoint release fails
     */
    public function releaseSavepoint(string $name): void
    {
        $sql = match ($this->dbType) {
            'mysql', 'postgresql' => "RELEASE SAVEPOINT $name",
            default => throw new RuntimeException("Savepoints not supported for database type: {$this->dbType}")
        };

        $this->dbh->exec($sql);
    }

    /**
     * Rollback to a savepoint (rollback nested transaction)
     * 
     * @param string $name Savepoint name
     * @throws RuntimeException If rollback to savepoint fails
     */
    public function rollbackToSavepoint(string $name): void
    {
        $sql = match ($this->dbType) {
            'mysql', 'postgresql' => "ROLLBACK TO SAVEPOINT $name",
            default => throw new RuntimeException("Savepoints not supported for database type: {$this->dbType}")
        };

        $this->dbh->exec($sql);
    }

    /**
     * Begin database transaction with automatic connection management
     * 
     * Starts a new database transaction, automatically establishing connection
     * if needed. Supports nested transaction detection and provides proper
     * error handling for transaction failures.
     * 
     * Transaction benefits:
     * - Atomic operations (all succeed or all fail)
     * - Data consistency during multi-table operations
     * - Rollback capability for error recovery
     * - Improved performance for bulk operations
     * 
     * @return bool True if transaction started successfully, false on failure
     * @throws RuntimeException If database connection fails
     * 
     * @example
     * // Safe multi-table operation
     * $model->beginTransaction();
     * try {
     *     $userId = $model->insert($userData, 'users');
     *     $model->insert(['user_id' => $userId, 'role' => 'admin'], 'user_roles');
     *     $model->commit();
     * } catch (Exception $e) {
     *     $model->rollback();
     *     throw $e;
     * }
     */
    public function beginTransaction(): bool
    {
        $this->connect();
        $startTime = microtime(true);

        $this->debugLog("Transaction begin requested", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
            'current_level' => $this->getTransactionLevel(),
            'in_transaction' => $this->dbh->inTransaction()
        ]);

        $result = $this->dbh->beginTransaction();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("Transaction started", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
                'success' => $result,
                'transaction_level' => $this->getTransactionLevel(),
                'execution_time_ms' => round($executionTime * 1000, 2),
                'operation' => 'begin_transaction'
            ]);
        }

        return $result;
    }

    /**
     * Commit current transaction and make changes permanent
     * 
     * Permanently saves all changes made within the current transaction
     * to the database. Once committed, changes cannot be rolled back.
     * 
     * @return bool True if commit succeeded, false on failure
     * @throws RuntimeException If no active transaction or commit fails
     * 
     * @example
     * if ($model->beginTransaction()) {
     *     // ... perform operations ...
     *     if ($allOperationsSuccessful) {
     *         $model->commit(); // Make changes permanent
     *     } else {
     *         $model->rollback(); // Cancel changes
     *     }
     * }
     */
    public function commit(): bool
    {
        $startTime = microtime(true);

        $this->debugLog("Transaction commit requested", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
            'current_level' => $this->getTransactionLevel(),
            'in_transaction' => $this->dbh->inTransaction()
        ]);

        $result = $this->dbh->commit();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("Transaction committed", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
                'success' => $result,
                'transaction_level' => $this->getTransactionLevel(),
                'execution_time_ms' => round($executionTime * 1000, 2),
                'operation' => 'commit'
            ]);
        }

        return $result;
    }

    /**
     * Rollback current transaction and discard all changes
     * 
     * Cancels all changes made within the current transaction and returns
     * the database to its state before the transaction began. Use for
     * error recovery and maintaining data consistency.
     * 
     * @return bool True if rollback succeeded, false on failure
     * @throws RuntimeException If no active transaction or rollback fails
     * 
     * @example
     * $model->beginTransaction();
     * try {
     *     $model->insert($criticalData, 'important_table');
     *     $model->update($id, $updateData, 'related_table');
     *     $model->commit();
     * } catch (Exception $e) {
     *     $model->rollback(); // Undo all changes on any error
     *     error_log("Transaction failed: " . $e->getMessage());
     * }
     */
    public function rollback(): bool
    {
        $startTime = microtime(true);

        $this->debugLog("Transaction rollback requested", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
            'current_level' => $this->getTransactionLevel(),
            'in_transaction' => $this->dbh->inTransaction()
        ]);

        $result = $this->dbh->rollback();

        if ($this->debug) {
            $executionTime = microtime(true) - $startTime;

            $this->debugLog("Transaction rolled back", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
                'success' => $result,
                'transaction_level' => $this->getTransactionLevel(),
                'execution_time_ms' => round($executionTime * 1000, 2),
                'operation' => 'rollback'
            ]);
        }

        return $result;
    }

    /**
     * Get current transaction level for debugging
     * 
     * @return int Current nesting level (0 = no transaction, 1+ = nested level)
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Check if currently in a transaction
     * 
     * @return bool True if in transaction, false otherwise
     */
    public function inTransaction(): bool
    {
        return $this->dbh->inTransaction() || $this->transactionLevel > 0;
    }

    /**
     * Force rollback any active transaction - for cleanup purposes
     */
    public function forceTransactionCleanup(): bool
    {
        if (!$this->connected) {
            // Reset state even if not connected
            $this->transactionLevel = 0;
            $this->savepointCounter = 0;
            $this->transactionStartTime = null;
            return true;
        }

        try {
            // Check PDO transaction state directly
            while ($this->dbh->inTransaction()) {
                $this->dbh->rollback();
            }

            // FIXED: Always reset instance-level transaction state
            $this->transactionLevel = 0;
            $this->savepointCounter = 0;
            $this->transactionStartTime = null;

            return true;
        } catch (PDOException $e) {
            // Log but don't throw - this is cleanup
            error_log("Transaction cleanup warning: " . $e->getMessage());

            // FIXED: Always reset state even if rollback fails
            $this->transactionLevel = 0;
            $this->savepointCounter = 0;
            $this->transactionStartTime = null;

            return false;
        }
    }

    /**
     * Reset transaction state and cleanup any dangling transactions
     */
    public function resetTransactionState(): void
    {
        $this->transactionLevel = 0;
        $this->savepointCounter = 0;
        $this->transactionStartTime = null;

        $this->forceTransactionCleanup();
    }

    /**
     * Get current transaction status for debugging
     */
    public function getTransactionStatus(): array
    {
        $this->connect();

        return [
            'pdo_in_transaction' => $this->dbh->inTransaction(),
            'transaction_level' => $this->getTransactionLevel(),
            'database_type' => $this->dbType ?? 'unknown',
            'savepoint_counter' => $this->savepointCounter,
            'transaction_start_time' => $this->transactionStartTime
        ];
    }

    /**
     * Execute callback with timeout and memory monitoring
     * 
     * @param callable $callback Callback function to execute
     * @param int $timeout Maximum execution time in seconds
     * @param string|null $memoryLimit Maximum memory usage (e.g., '512MB')
     * @param bool $enableDebugging Whether to enable debug output
     * @return mixed Result of callback execution
     * @throws TransactionTimeoutException If timeout is exceeded
     * @throws TransactionMemoryException If memory limit is exceeded
     */
    private function executeWithMonitoring(callable $callback, int $timeout, ?string $memoryLimit, bool $enableDebugging): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Parse memory limit if provided
        $memoryLimitBytes = null;
        if ($memoryLimit !== null) {
            $memoryLimitBytes = $this->parseMemoryLimit($memoryLimit);
        }

        $this->debugLog(
            "Callback execution started with monitoring",
            DebugCategory::TRANSACTION,
            DebugLevel::VERBOSE,
            [
                'timeout_seconds' => $timeout,
                'memory_limit' => $memoryLimit,
                'memory_limit_bytes' => $memoryLimitBytes,
                'start_memory_bytes' => $startMemory,
                'monitoring_enabled' => true
            ]
        );

        try {
            // Execute callback
            $result = $callback();

            $executionTime = microtime(true) - $startTime;
            $memoryDelta = memory_get_usage(true) - $startMemory;

            $this->debugLog(
                "Callback execution completed successfully",
                DebugCategory::TRANSACTION,
                DebugLevel::BASIC,
                [
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'memory_delta_bytes' => $memoryDelta,
                    'memory_delta' => $this->formatBytes($memoryDelta),
                    'timeout_exceeded' => $executionTime > $timeout,
                    'memory_limit_exceeded' => $memoryLimitBytes && memory_get_usage(true) > $memoryLimitBytes,
                    'operation' => 'callback_execution'
                ]
            );

            // Check timeout
            if ($executionTime > $timeout) {
                throw new TransactionTimeoutException(
                    "Transaction exceeded timeout limit of {$timeout} seconds (actual: " .
                        round($executionTime, 2) . "s)"
                );
            }

            // Check memory limit
            if ($memoryLimitBytes && memory_get_usage(true) > $memoryLimitBytes) {
                throw new TransactionMemoryException(
                    "Transaction exceeded memory limit of {$memoryLimit} (current: " .
                        $this->formatBytes(memory_get_usage(true)) . ")"
                );
            }

            return $result;
        } catch (Exception $e) {
            $executionTime = microtime(true) - $startTime;
            $memoryDelta = memory_get_usage(true) - $startMemory;

            $this->debugLog(
                "Callback execution failed",
                DebugCategory::TRANSACTION,
                DebugLevel::BASIC,
                [
                    'error_message' => $e->getMessage(),
                    'error_type' => get_class($e),
                    'execution_time' => $executionTime,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                    'memory_delta_bytes' => $memoryDelta,
                    'memory_delta' => $this->formatBytes($memoryDelta),
                    'operation' => 'callback_execution_failed'
                ]
            );

            throw $e;
        }
    }
}
