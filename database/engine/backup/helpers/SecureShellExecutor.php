<?php

/**
 * Secure Shell Executor - Cross-Platform Shell Command Execution
 * 
 * Provides secure, cross-platform shell command execution using proc_open() with
 * comprehensive security measures, timeout protection, and proper resource management.
 * Designed specifically for database backup/restore operations with credential security.
 * 
 * **ENHANCED WITH FULL DEBUG SYSTEM INTEGRATION**
 * - All operations logged through Model's debug system
 * - Real-time progress tracking and command monitoring
 * - Detailed error analysis and troubleshooting information
 * - Zero overhead when debugging disabled
 * 
 * Security Features:
 * - Credentials passed via stdin/environment variables (never in command line)
 * - Proper shell argument escaping and validation
 * - Resource cleanup on errors and timeouts
 * - Process termination protection
 * - Command execution monitoring
 * 
 * Cross-Platform Features:
 * - Unified interface for Windows and Unix-like systems
 * - Automatic platform detection for file operations
 * - Platform-specific optimization strategies
 * - Environment variable handling
 * 
 * @package Database\Backup\Shell
 * @author Enhanced Model System
 * @version 1.0.0 - Initial Implementation with Debug Integration
 */
class SecureShellExecutor
{
    private Model $model;
    private array $platformConfig;
    private int $defaultTimeout;

    /**
     * Initialize secure shell executor
     * 
     * @param Model $model Enhanced Model instance for debug logging
     * @param int $defaultTimeout Default command timeout in seconds
     */
    public function __construct(Model $model, int $defaultTimeout = 300)
    {
        $this->model = $model;
        $this->defaultTimeout = $defaultTimeout;
        $this->platformConfig = $this->detectPlatform();
        
        $this->debugLog("Secure shell executor initialized", DebugLevel::VERBOSE, [
            'platform' => $this->platformConfig['platform'],
            'shell_available' => $this->platformConfig['shell_available'],
            'default_timeout' => $defaultTimeout
        ]);
    }

    // =============================================================================
    // DEBUG INTEGRATION
    // =============================================================================

    /**
     * Log debug message through Model's debug system
     * 
     * @param string $message Debug message
     * @param int $level Debug level
     * @param array $context Additional context data
     */
    private function debugLog(string $message, int $level = DebugLevel::BASIC, array $context = []): void
    {
        $this->model->debugLog($message, DebugCategory::MAINTENANCE, $level, $context);
    }

    // =============================================================================
    // SECURE COMMAND EXECUTION
    // =============================================================================

    /**
     * Execute database command with secure credential handling
     * 
     * Main method for executing database tools (mysqldump, pg_dump, etc.) with
     * proper credential security and comprehensive error handling.
     * 
     * @param array $command Command array (program and arguments)
     * @param array $credentials Sensitive credentials (password, etc.)
     * @param array $options Execution options
     * @return array Execution result with output, errors, and metadata
     * @throws RuntimeException If command execution fails
     * 
     * @example
     * $result = $executor->executeDatabaseCommand(
     *     ['mysqldump', '--host=localhost', '--user=root', '--password'],
     *     ['password' => 'secret123'],
     *     ['timeout' => 1800, 'environment' => ['MYSQL_PWD' => 'secret123']]
     * );
     */
    public function executeDatabaseCommand(array $command, array $credentials = [], array $options = []): array
    {
        $startTime = microtime(true);
        $commandString = $this->buildSecureCommand($command);
        
        // Merge options with defaults
        $options = array_merge([
            'timeout' => $this->defaultTimeout,
            'environment' => [],
            'working_directory' => null,
            'progress_callback' => null,
            'stdin_data' => null
        ], $options);

        $this->debugLog("Executing database command", DebugLevel::BASIC, [
            'command' => $this->sanitizeCommandForLogging($command),
            'timeout' => $options['timeout'],
            'has_credentials' => !empty($credentials),
            'environment_vars' => array_keys($options['environment'])
        ]);

        // Prepare environment variables including credentials
        $environment = $this->prepareEnvironment($credentials, $options['environment']);
        
        try {
            $result = $this->executeWithProcOpen($commandString, $credentials, $environment, $options);
            
            $duration = microtime(true) - $startTime;
            
            $this->debugLog("Command execution completed", DebugLevel::DETAILED, [
                'duration_seconds' => round($duration, 3),
                'return_code' => $result['return_code'],
                'success' => $result['success'],
                'output_size_bytes' => strlen($result['output']),
                'error_output_size_bytes' => strlen($result['error'])
            ]);

            // Add execution metadata
            $result['metadata'] = [
                'duration_seconds' => round($duration, 3),
                'command_sanitized' => $this->sanitizeCommandForLogging($command),
                'platform' => $this->platformConfig['platform']
            ];

            return $result;

        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            $this->debugLog("Command execution failed", DebugLevel::BASIC, [
                'duration_seconds' => round($duration, 3),
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e)
            ]);

            throw new RuntimeException("Command execution failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute command using proc_open with comprehensive error handling
     * 
     * @param string $command Command string to execute
     * @param array $credentials Credentials for stdin
     * @param array $environment Environment variables
     * @param array $options Execution options
     * @return array Execution result
     */
    private function executeWithProcOpen(string $command, array $credentials, array $environment, array $options): array
    {
        // Configure process descriptors
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin - for credentials and input data
            1 => ["pipe", "w"],  // stdout - command output
            2 => ["pipe", "w"]   // stderr - error messages
        ];

        $this->debugLog("Starting proc_open process", DebugLevel::VERBOSE, [
            'descriptors' => array_keys($descriptorspec),
            'working_directory' => $options['working_directory'],
            'environment_count' => count($environment)
        ]);

        // Start the process
        $process = proc_open(
            $command,
            $descriptorspec,
            $pipes,
            $options['working_directory'],
            $environment
        );

        if (!is_resource($process)) {
            throw new RuntimeException("Failed to start process: $command");
        }

        try {
            // Handle stdin (credentials and input data)
            $this->handleStdin($pipes[0], $credentials, $options);
            
            // Read stdout and stderr with timeout protection
            $result = $this->readProcessOutput($pipes, $options, $process);
            
            // Wait for process completion
            $returnCode = proc_close($process);
            
            $result['return_code'] = $returnCode;
            $result['success'] = $returnCode === 0;

            return $result;

        } catch (Exception $e) {
            // Cleanup on error
            $this->cleanupProcess($process, $pipes);
            throw $e;
        }
    }

    /**
     * Handle stdin data (credentials and input)
     * 
     * @param resource $stdin Stdin pipe
     * @param array $credentials Credentials to send
     * @param array $options Execution options
     */
    private function handleStdin($stdin, array $credentials, array $options): void
    {
        try {
            // Send password if provided
            if (!empty($credentials['password'])) {
                fwrite($stdin, $credentials['password'] . "\n");
                
                $this->debugLog("Credentials sent via stdin", DebugLevel::VERBOSE, [
                    'credential_types' => array_keys($credentials)
                ]);
            }

            // Send additional stdin data if provided
            if (!empty($options['stdin_data'])) {
                fwrite($stdin, $options['stdin_data']);
                
                $this->debugLog("Additional stdin data sent", DebugLevel::VERBOSE, [
                    'data_size_bytes' => strlen($options['stdin_data'])
                ]);
            }

        } finally {
            fclose($stdin);
        }
    }

    /**
     * Read process output with timeout protection and progress tracking
     * 
     * @param array $pipes Process pipes
     * @param array $options Execution options
     * @param resource $process Process resource for monitoring
     * @return array Output data
     */
    private function readProcessOutput(array $pipes, array $options, $process): array
    {
        $stdout = '';
        $stderr = '';
        $startTime = time();
        $lastProgressUpdate = 0;

        // Set streams to non-blocking mode
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->debugLog("Reading process output", DebugLevel::VERBOSE, [
            'timeout_seconds' => $options['timeout'],
            'progress_callback_enabled' => !empty($options['progress_callback'])
        ]);

        while (true) {
            $currentTime = time();
            
            // Check timeout
            if (($currentTime - $startTime) > $options['timeout']) {
                proc_terminate($process);
                throw new RuntimeException("Command timeout after {$options['timeout']} seconds");
            }

            // Check if process is still running
            $status = proc_get_status($process);
            $processRunning = $status['running'];

            // Read from stdout
            $stdoutChunk = fread($pipes[1], 8192);
            if ($stdoutChunk !== false && $stdoutChunk !== '') {
                $stdout .= $stdoutChunk;
            }

            // Read from stderr  
            $stderrChunk = fread($pipes[2], 8192);
            if ($stderrChunk !== false && $stderrChunk !== '') {
                $stderr .= $stderrChunk;
            }

            // Progress callback
            if ($options['progress_callback'] && ($currentTime - $lastProgressUpdate) >= 5) {
                $progress = [
                    'duration_seconds' => $currentTime - $startTime,
                    'output_size_bytes' => strlen($stdout),
                    'process_running' => $processRunning,
                    'process_status' => $status
                ];
                
                call_user_func($options['progress_callback'], $progress);
                $lastProgressUpdate = $currentTime;
                
                $this->debugLog("Progress update", DebugLevel::VERBOSE, $progress);
            }

            // Exit if process finished and no more data
            if (!$processRunning && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            // Small delay to prevent busy waiting
            usleep(10000); // 10ms
        }

        // Close output pipes
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->debugLog("Process output reading completed", DebugLevel::VERBOSE, [
            'stdout_size_bytes' => strlen($stdout),
            'stderr_size_bytes' => strlen($stderr),
            'total_duration_seconds' => time() - $startTime
        ]);

        return [
            'output' => $stdout,
            'error' => $stderr
        ];
    }

    // =============================================================================
    // COMMAND BUILDING AND SECURITY
    // =============================================================================

    /**
     * Build secure command string with proper escaping
     * 
     * @param array $command Command components
     * @return string Escaped command string
     */
    private function buildSecureCommand(array $command): string
    {
        if (empty($command)) {
            throw new InvalidArgumentException("Command array cannot be empty");
        }

        // Escape each component
        $escapedComponents = array_map([$this, 'escapeShellArgument'], $command);
        
        $commandString = implode(' ', $escapedComponents);
        
        $this->debugLog("Command built and escaped", DebugLevel::VERBOSE, [
            'component_count' => count($command),
            'command_length' => strlen($commandString)
        ]);

        return $commandString;
    }

    /**
     * Escape shell argument safely across platforms
     * 
     * @param string $argument Argument to escape
     * @return string Escaped argument
     */
    private function escapeShellArgument(string $argument): string
    {
        // Use PHP's built-in escaping which handles platform differences
        return escapeshellarg($argument);
    }

    /**
     * Prepare environment variables including credentials
     * 
     * @param array $credentials Sensitive credentials
     * @param array $additionalEnv Additional environment variables
     * @return array Complete environment array
     */
    private function prepareEnvironment(array $credentials, array $additionalEnv): array
    {
        $environment = $_ENV; // Start with current environment

        // Add database-specific credential environment variables
        if (!empty($credentials['password'])) {
            $environment['MYSQL_PWD'] = $credentials['password'];      // MySQL
            $environment['PGPASSWORD'] = $credentials['password'];     // PostgreSQL
        }

        // Add additional environment variables
        foreach ($additionalEnv as $key => $value) {
            $environment[$key] = $value;
        }

        $this->debugLog("Environment prepared", DebugLevel::VERBOSE, [
            'total_variables' => count($environment),
            'credential_variables' => array_intersect_key($environment, [
                'MYSQL_PWD' => '', 'PGPASSWORD' => ''
            ]),
            'additional_variables' => array_keys($additionalEnv)
        ]);

        return $environment;
    }

    /**
     * Sanitize command for safe logging (remove sensitive information)
     * 
     * @param array $command Original command array
     * @return array Sanitized command for logging
     */
    private function sanitizeCommandForLogging(array $command): array
    {
        $sanitized = [];
        
        foreach ($command as $arg) {
            // Hide password-related arguments
            if (strpos($arg, '--password') !== false || strpos($arg, '-p') === 0) {
                $sanitized[] = '[PASSWORD_HIDDEN]';
            } elseif (preg_match('/password=/i', $arg)) {
                $sanitized[] = preg_replace('/password=[^&\s]*/i', 'password=[HIDDEN]', $arg);
            } else {
                $sanitized[] = $arg;
            }
        }
        
        return $sanitized;
    }

    // =============================================================================
    // PLATFORM DETECTION AND CONFIGURATION
    // =============================================================================

    /**
     * Detect platform and configure execution environment
     * 
     * @return array Platform configuration
     */
    private function detectPlatform(): array
    {
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        
        $config = [
            'platform' => $isWindows ? 'windows' : 'unix',
            'is_windows' => $isWindows,
            'shell_available' => function_exists('proc_open'),
            'directory_separator' => DIRECTORY_SEPARATOR,
            'path_separator' => PATH_SEPARATOR,
            'null_device' => $isWindows ? 'NUL' : '/dev/null'
        ];

        $this->debugLog("Platform detected", DebugLevel::VERBOSE, $config);

        return $config;
    }

    // =============================================================================
    // CLEANUP AND ERROR HANDLING
    // =============================================================================

    /**
     * Clean up process and pipes on error
     * 
     * @param resource $process Process resource
     * @param array $pipes Process pipes
     */
    private function cleanupProcess($process, array $pipes): void
    {
        $this->debugLog("Cleaning up process resources", DebugLevel::VERBOSE);

        // Close pipes
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        // Terminate and close process
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }

    // =============================================================================
    // PUBLIC UTILITY METHODS
    // =============================================================================

    /**
     * Test if shell command execution is available
     * 
     * @return array Test results
     */
    public function testShellAccess(): array
    {
        $this->debugLog("Testing shell access", DebugLevel::BASIC);

        if (!function_exists('proc_open')) {
            return [
                'available' => false,
                'error' => 'proc_open function not available'
            ];
        }

        try {
            // Test with a simple command
            $testCommand = $this->platformConfig['is_windows'] ? ['echo', 'test'] : ['echo', 'test'];
            
            $result = $this->executeDatabaseCommand($testCommand, [], ['timeout' => 10]);
            
            $available = $result['success'] && trim($result['output']) === 'test';
            
            $this->debugLog("Shell access test completed", DebugLevel::DETAILED, [
                'available' => $available,
                'test_output' => trim($result['output']),
                'return_code' => $result['return_code']
            ]);

            return [
                'available' => $available,
                'test_output' => trim($result['output']),
                'platform' => $this->platformConfig['platform']
            ];

        } catch (Exception $e) {
            $this->debugLog("Shell access test failed", DebugLevel::BASIC, [
                'error' => $e->getMessage()
            ]);

            return [
                'available' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get platform configuration
     * 
     * @return array Current platform configuration
     */
    public function getPlatformConfig(): array
    {
        return $this->platformConfig;
    }

    /**
     * Check if specific command is available
     * 
     * @param string $command Command name to check
     * @return bool True if command is available
     */
    public function isCommandAvailable(string $command): bool
    {
        try {
            $checkCommand = $this->platformConfig['is_windows'] 
                ? ['where', $command] 
                : ['which', $command];
            
            $result = $this->executeDatabaseCommand($checkCommand, [], ['timeout' => 10]);
            
            $available = $result['success'] && !empty(trim($result['output']));
            
            $this->debugLog("Command availability check", DebugLevel::VERBOSE, [
                'command' => $command,
                'available' => $available,
                'check_output' => trim($result['output'])
            ]);

            return $available;

        } catch (Exception $e) {
            $this->debugLog("Command availability check failed", DebugLevel::VERBOSE, [
                'command' => $command,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}