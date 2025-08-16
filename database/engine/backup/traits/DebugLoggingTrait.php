<?php

/**
 * Trait DebugLoggingTrait
 * 
 * Provides consistent debug logging functionality across backup strategies.
 * Routes all logs through the parent Model's debug system for centralized logging.
 * 
 * Usage: Add `use DebugLoggingTrait;` to strategy classes.
 * Call `$this->debugLog($message, $level, $context);` as before.
 */
trait DebugLoggingTrait
{
    /**
     * Log debug message through Model's debug system
     * 
     * @param string $message Debug message
     * @param int $level Debug level (use DebugLevel constants)
     * @param array $context Additional context data
     * @return void
     */
    protected function debugLog(string $message, int $level = DebugLevel::BASIC, array $context = []): void
    {
        $this->model->debugLog($message, DebugCategory::MAINTENANCE, $level, $context);
    }
}