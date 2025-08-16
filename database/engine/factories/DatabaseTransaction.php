<?php

/**
 * Transaction Manager for Manual Transaction Control
 * 
 * Provides manual transaction management with the same safety features
 * as the automatic transaction() method. Supports nested transactions,
 * timeout detection, and comprehensive error handling.
 * 
 * @package Database\Transaction
 */
class TransactionManager
{
    private Model $model;
    private array $options;
    private bool $committed = false;
    private bool $rolledBack = false;
    private float $startTime;
    private int $transactionLevel;

    /**
     * Initialize transaction manager
     * 
     * @param Model $model Database model instance
     * @param array $options Transaction options
     */
    public function __construct(Model $model, array $options = [])
    {
        $this->model = $model;
        $this->options = array_merge([
            'timeout' => 300,
            'memory_limit' => null,
            'debug' => false
        ], $options);

        $this->startTime = microtime(true);
        $this->transactionLevel = $model->getTransactionLevel();

        // Start transaction using the same logic as transaction() method
        $this->startTransaction();
    }

    /**
     * Start the transaction
     */
    private function startTransaction(): void
    {
        $isNested = $this->model->getTransactionLevel() > 0;

        $this->model->debugLog("Manual transaction started", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
            'transaction_level' => $this->transactionLevel,
            'timeout' => $this->options['timeout'],
            'memory_limit' => $this->options['memory_limit'] ?? 'none',
            'nested' => $isNested
        ]);

        if ($isNested) {
            // Handle nested transaction with savepoint if supported
            if ($this->model->supportsSavepoints()) {
                $savepointName = 'sp_manual_' . uniqid();
                $this->model->createSavepoint($savepointName);
                $this->options['savepoint'] = $savepointName;
                
                $this->model->debugLog("Savepoint created", DebugCategory::TRANSACTION, DebugLevel::DETAILED, [
                    'savepoint_name' => $savepointName,
                    'transaction_level' => $this->transactionLevel,
                    'nested_transaction' => true
                ]);
            }
        } else {
            // Start root transaction
            $this->model->beginTransaction();
        }
    }

    /**
     * Commit the transaction
     * 
     * @return bool True on success, false on failure
     * @throws RuntimeException If transaction has already been committed or rolled back
     */
    public function commit(): bool
    {
        $this->checkTransactionState();

        try {
            if (isset($this->options['savepoint'])) {
                // Release savepoint for nested transaction
                $this->model->releaseSavepoint($this->options['savepoint']);
            } else {
                // Commit root transaction
                $result = $this->model->commit();
                if (!$result) {
                    throw new RuntimeException('Failed to commit transaction');
                }
            }

            $this->committed = true;

            $duration = round((microtime(true) - $this->startTime) * 1000, 2);
            $this->model->debugLog("Manual transaction committed", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
                'duration_ms' => $duration,
                'transaction_level' => $this->transactionLevel,
                'has_savepoint' => isset($this->options['savepoint']),
                'savepoint_name' => $this->options['savepoint'] ?? null
            ]);

            return true;
        } catch (Exception $e) {
            throw new RuntimeException("Failed to commit transaction: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Rollback the transaction
     * 
     * @return bool True on success, false on failure
     * @throws RuntimeException If transaction has already been committed or rolled back
     */
    public function rollback(): bool
    {
        $this->checkTransactionState();

        try {
            if (isset($this->options['savepoint'])) {
                // Rollback to savepoint for nested transaction
                $this->model->rollbackToSavepoint($this->options['savepoint']);
            } else {
                // Rollback root transaction
                $result = $this->model->rollback();
                if (!$result) {
                    throw new RuntimeException('Failed to rollback transaction');
                }
            }

            $this->rolledBack = true;

            $duration = round((microtime(true) - $this->startTime) * 1000, 2);
            $this->model->debugLog("Manual transaction rolled back", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
                'duration_ms' => $duration,
                'transaction_level' => $this->transactionLevel,
                'has_savepoint' => isset($this->options['savepoint']),
                'savepoint_name' => $this->options['savepoint'] ?? null
            ]);

            return true;
        } catch (Exception $e) {
            throw new RuntimeException("Failed to rollback transaction: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if transaction is still active
     * 
     * @throws RuntimeException If transaction has been finalized
     */
    private function checkTransactionState(): void
    {
        if ($this->committed) {
            throw new RuntimeException('Transaction has already been committed');
        }

        if ($this->rolledBack) {
            throw new RuntimeException('Transaction has already been rolled back');
        }

        // Check timeout
        $duration = microtime(true) - $this->startTime;
        if ($duration > $this->options['timeout']) {
            throw new TransactionTimeoutException(
                "Transaction exceeded timeout limit of {$this->options['timeout']}s"
            );
        }
    }

    /**
     * Get transaction status information
     * 
     * @return array Transaction status details
     */
    public function getStatus(): array
    {
        return [
            'active' => !$this->committed && !$this->rolledBack,
            'committed' => $this->committed,
            'rolled_back' => $this->rolledBack,
            'duration' => microtime(true) - $this->startTime,
            'level' => $this->transactionLevel,
            'timeout' => $this->options['timeout'],
            'has_savepoint' => isset($this->options['savepoint'])
        ];
    }

    /**
     * Destructor ensures transaction is properly finalized
     */
    public function __destruct()
    {
        // Auto-rollback if transaction was never explicitly committed or rolled back
        if (!$this->committed && !$this->rolledBack) {
            try {
                $this->rollback();

                $this->model->debugLog("Auto-rollback: Transaction not explicitly committed", DebugCategory::TRANSACTION, DebugLevel::BASIC, [
                    'auto_rollback' => true,
                    'transaction_level' => $this->transactionLevel,
                    'duration_ms' => round((microtime(true) - $this->startTime) * 1000, 2),
                    'warning' => 'transaction_not_explicitly_handled'
                ]);
            } catch (Exception $e) {
                error_log("Failed to auto-rollback transaction: " . $e->getMessage());
            }
        }
    }
}

/**
 * Exception thrown when transaction exceeds time limit
 */
class TransactionTimeoutException extends RuntimeException
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Exception thrown when transaction exceeds memory limit
 */
class TransactionMemoryException extends RuntimeException
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
