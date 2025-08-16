#!/usr/bin/env php
<?php

/**
 * SQL Dump Translator CLI
 * 
 * Clean CLI interface that leverages SchemaTranslator's built-in dependency sorting
 * and translation capabilities. All complex logic is handled internally by 
 * SchemaTranslator, making this a simple file I/O wrapper.
 * 
 * Usage:
 *   php sql-dump-translator.php input.sql mysql sqlite [output.sql]
 *   php sql-dump-translator.php -i input.sql -s mysql -t sqlite -o output.sql
 * 
 * @package Database\Scripts
 * @author Enhanced Model System
 * @version 2.0.0 - Simplified using existing SchemaTranslator
 */

// Prevent running from web interface
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once dirname(__DIR__) . '/engine/core/DatabaseSecurity.php';
require_once dirname(__DIR__) . '/engine/schema/core/SchemaTranslator.php';

/**
 * SQL Dump CLI Handler
 */
class SQLDumpCLI
{
    private array $options = [
        'input' => null,
        'output' => null,
        'source' => null,
        'target' => null,
        'verbose' => false,
        'debug' => false,
        'quiet' => false,
        'help' => false,
        'version' => false,
        'validate-only' => false,
        'strict' => false,
        'warnings' => 'show'
    ];

    private array $stats = [];
    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Main entry point
     */
    public function run(array $argv): int
    {
        try {
            $this->parseArguments($argv);

            if ($this->options['help']) {
                $this->showHelp();
                return 0;
            }

            if ($this->options['version']) {
                $this->showVersion();
                return 0;
            }

            $this->validateArguments();

            if ($this->options['validate-only']) {
                return $this->validateOnly();
            }

            return $this->performTranslation();
        } catch (Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Parse command line arguments
     */
    private function parseArguments(array $argv): void
    {
        $i = 1; // Skip script name
        $positionalArgs = [];

        while ($i < count($argv)) {
            $arg = $argv[$i];

            if ($arg === '-h' || $arg === '--help') {
                $this->options['help'] = true;
            } elseif ($arg === '--version') {
                $this->options['version'] = true;
            } elseif ($arg === '-v' || $arg === '--verbose') {
                $this->options['verbose'] = true;
            } elseif ($arg === '--debug') {
                $this->options['debug'] = true;
            } elseif ($arg === '-q' || $arg === '--quiet') {
                $this->options['quiet'] = true;
            } elseif ($arg === '--validate-only') {
                $this->options['validate-only'] = true;
            } elseif ($arg === '--strict') {
                $this->options['strict'] = true;
            } elseif (str_starts_with($arg, '-i=') || str_starts_with($arg, '--input=')) {
                $this->options['input'] = substr($arg, strpos($arg, '=') + 1);
            } elseif (str_starts_with($arg, '-o=') || str_starts_with($arg, '--output=')) {
                $this->options['output'] = substr($arg, strpos($arg, '=') + 1);
            } elseif (str_starts_with($arg, '-s=') || str_starts_with($arg, '--source=')) {
                $this->options['source'] = substr($arg, strpos($arg, '=') + 1);
            } elseif (str_starts_with($arg, '-t=') || str_starts_with($arg, '--target=')) {
                $this->options['target'] = substr($arg, strpos($arg, '=') + 1);
            } elseif (str_starts_with($arg, '--warnings=')) {
                $this->options['warnings'] = substr($arg, strpos($arg, '=') + 1);
            } elseif ($arg === '-i' || $arg === '--input') {
                $this->options['input'] = $argv[++$i] ?? null;
            } elseif ($arg === '-o' || $arg === '--output') {
                $this->options['output'] = $argv[++$i] ?? null;
            } elseif ($arg === '-s' || $arg === '--source') {
                $this->options['source'] = $argv[++$i] ?? null;
            } elseif ($arg === '-t' || $arg === '--target') {
                $this->options['target'] = $argv[++$i] ?? null;
            } elseif (!str_starts_with($arg, '-')) {
                $positionalArgs[] = $arg;
            } else {
                throw new Exception("Unknown option: $arg");
            }

            $i++;
        }

        // Handle positional arguments: INPUT SOURCE TARGET [OUTPUT]
        if (!empty($positionalArgs)) {
            if (!$this->options['input'] && isset($positionalArgs[0])) {
                $this->options['input'] = $positionalArgs[0];
            }
            if (!$this->options['source'] && isset($positionalArgs[1])) {
                $this->options['source'] = strtolower($positionalArgs[1]);
            }
            if (!$this->options['target'] && isset($positionalArgs[2])) {
                $this->options['target'] = strtolower($positionalArgs[2]);
            }
            if (!$this->options['output'] && isset($positionalArgs[3])) {
                $this->options['output'] = $positionalArgs[3];
            }
        }
    }

    /**
     * Validate arguments
     */
    private function validateArguments(): void
    {
        if (!$this->options['input']) {
            throw new Exception("Input file is required");
        }

        DatabaseSecurity::validateBackupPath($this->options['input']);
        if ($this->options['output']) {
            DatabaseSecurity::validateBackupPath($this->options['output']);
        }

        if (!file_exists($this->options['input'])) {
            throw new Exception("Input file does not exist: {$this->options['input']}");
        }

        if (!is_readable($this->options['input'])) {
            throw new Exception("Input file is not readable: {$this->options['input']}");
        }

        if (!$this->options['source']) {
            throw new Exception("Source database type is required");
        }

        if (!$this->options['target']) {
            throw new Exception("Target database type is required");
        }

        $supportedTypes = ['mysql', 'postgresql', 'postgres', 'sqlite'];

        if (!in_array($this->options['source'], $supportedTypes)) {
            throw new Exception("Unsupported source database type: {$this->options['source']}");
        }

        if (!in_array($this->options['target'], $supportedTypes)) {
            throw new Exception("Unsupported target database type: {$this->options['target']}");
        }

        // Normalize postgres
        if ($this->options['source'] === 'postgres') {
            $this->options['source'] = 'postgresql';
        }
        if ($this->options['target'] === 'postgres') {
            $this->options['target'] = 'postgresql';
        }

        if ($this->options['warnings'] && !in_array($this->options['warnings'], ['show', 'hide'])) {
            throw new Exception("Invalid warnings mode: {$this->options['warnings']}");
        }
    }

    /**
     * Validate input file only
     */
    private function validateOnly(): int
    {
        $this->info("Validating SQL dump file...");

        try {
            $sqlContent = file_get_contents($this->options['input']);
            $this->stats['input_size'] = strlen($sqlContent);

            // Create translator just for validation
            $translator = new SchemaTranslator(['strict' => true]);

            // Try to parse (validation)
            $tables = $translator->parseSQL($sqlContent, $this->options['source']);

            $this->success("✅ Validation successful!");
            $this->info("Found " . count($tables) . " tables");

            if (!$this->options['quiet']) {
                foreach ($tables as $table) {
                    echo "  - {$table->getName()}\n";
                }
            }

            return 0;
        } catch (Exception $e) {
            $this->error("❌ Validation failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Perform the actual translation
     */
    private function performTranslation(): int
    {
        if (!$this->options['quiet']) {
            echo "==========================================\n";
            echo "Enhanced Model SQL Dump Translator v2.1\n";
            echo "==========================================\n";
            echo "Input: {$this->options['input']}\n";
            echo "Source: " . strtoupper($this->options['source']) . "\n";
            echo "Target: " . strtoupper($this->options['target']) . "\n";
            if ($this->options['output']) {
                echo "Output: {$this->options['output']}\n";
            }
            echo "==========================================\n";
        }

        try {
            // Step 1: Read SQL dump file
            $this->info("Reading SQL dump file...");
            $sqlContent = file_get_contents($this->options['input']);
            $this->stats['input_size'] = strlen($sqlContent);

            // Step 2: Create SchemaTranslator with appropriate options
            $translatorOptions = [
                'strict' => $this->options['strict'],
                'preserve_indexes' => true,
                'preserve_constraints' => true,
                'handle_unsupported' => 'warn',
                'enum_conversion' => 'text_with_check',
                'auto_increment_conversion' => 'native',
                'dependency_sort' => true,  // Always enabled - handled internally
                'add_header_comments' => true
            ];

            $translator = new SchemaTranslator($translatorOptions);

            // Set debug callback if verbose
            if ($this->options['verbose'] || $this->options['debug']) {
                $translator->setDebugCallback(function ($message, $context = []) {
                    if ($this->options['debug']) {
                        $timestamp = date('H:i:s');
                        echo "[$timestamp] $message\n";
                    } elseif ($this->options['verbose'] && (strpos($message, 'Error') !== false || strpos($message, 'Warning') !== false)) {
                        echo "🔍 $message\n";
                    }
                });
            }

            // Step 3: Translate SQL (dependency sorting handled internally)
            $this->info("Translating SQL...");
            $translationStart = microtime(true);

            $translatedSQL = $translator->translateSQL(
                $sqlContent,
                $this->options['source'],
                $this->options['target']
            );

            $translationTime = microtime(true) - $translationStart;
            $this->stats['translation_time'] = $translationTime;
            $this->stats['output_size'] = strlen($translatedSQL);

            // Step 4: Handle warnings
            $warnings = $translator->getConversionWarnings();
            $this->stats['warnings_count'] = count($warnings);

            if (!empty($warnings) && $this->options['warnings'] === 'show' && !$this->options['quiet']) {
                echo "\n⚠️  Translation Warnings (" . count($warnings) . "):\n";
                foreach (array_slice($warnings, 0, 5) as $i => $warning) {
                    echo "   " . ($i + 1) . ". $warning\n";
                }
                if (count($warnings) > 5) {
                    echo "   ... and " . (count($warnings) - 5) . " more warnings\n";
                }
                echo "\n";
            }

            // Step 5: Write output
            if ($this->options['output']) {
                $this->info("Writing output file...");
                if (file_put_contents($this->options['output'], $translatedSQL) === false) {
                    throw new Exception("Failed to write output file: {$this->options['output']}");
                }
                $this->success("✅ Translation completed!");
                echo "Output written to: {$this->options['output']}\n";
            } else {
                // Output to stdout
                echo $translatedSQL;
            }

            // Step 6: Show statistics
            if (!$this->options['quiet']) {
                $this->showStatistics();
            }

            return 0;
        } catch (Exception $e) {
            $this->error("Translation failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Show performance statistics
     */
    private function showStatistics(): void
    {
        $totalTime = microtime(true) - $this->startTime;

        echo "\n📊 Translation Statistics:\n";
        echo "   Input size: " . $this->formatBytes($this->stats['input_size'] ?? 0) . "\n";
        echo "   Output size: " . $this->formatBytes($this->stats['output_size'] ?? 0) . "\n";
        echo "   Translation time: " . number_format(($this->stats['translation_time'] ?? 0) * 1000, 2) . "ms\n";
        echo "   Total time: " . number_format($totalTime * 1000, 2) . "ms\n";
        echo "   Warnings: " . ($this->stats['warnings_count'] ?? 0) . "\n";
    }

    /**
     * Format bytes for display
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Output methods
     */
    private function info(string $message): void
    {
        if (!$this->options['quiet']) {
            echo "ℹ️  $message\n";
        }
    }

    private function success(string $message): void
    {
        if (!$this->options['quiet']) {
            echo "$message\n";
        }
    }

    private function error(string $message): void
    {
        fwrite(STDERR, "❌ $message\n");
    }

    /**
     * Show help
     */
    private function showHelp(): void
    {
        echo <<<HELP
Enhanced Model SQL Dump Translator v2.1

DESCRIPTION:
    Translate SQL dumps between database types with automatic dependency sorting
    and comprehensive schema transformation support.

USAGE:
    sql-dump-translator.php [OPTIONS] [INPUT] [SOURCE] [TARGET] [OUTPUT]
    sql-dump-translator.php -i INPUT -s SOURCE -t TARGET [-o OUTPUT]

ARGUMENTS:
    INPUT     Path to input SQL dump file
    SOURCE    Source database type (mysql|postgresql|sqlite)  
    TARGET    Target database type (mysql|postgresql|sqlite)
    OUTPUT    Output file path (optional, defaults to stdout)

OPTIONS:
    -i, --input=FILE        Input SQL dump file
    -o, --output=FILE       Output file (default: stdout)
    -s, --source=TYPE       Source database type
    -t, --target=TYPE       Target database type
    
    --validate-only         Only validate input file
    --strict                Enable strict mode (fail on errors)
    --warnings=MODE         show|hide (default: show)
    
    -v, --verbose           Verbose output
    -q, --quiet             Quiet mode
    --debug                 Debug mode (very verbose)
    -h, --help              Show help
    --version               Show version

FEATURES:
    ✅ Automatic dependency sorting (tables created in correct order)
    ✅ ALTER TABLE statement support (MySQL phpMyAdmin dumps)
    ✅ AUTO_INCREMENT → AUTOINCREMENT conversion
    ✅ Cross-database constraint translation
    ✅ Comprehensive error handling and validation

EXAMPLES:
    # Basic translation
    sql-dump-translator.php backup.sql mysql sqlite
    
    # With output file
    sql-dump-translator.php backup.sql mysql sqlite output.sql
    
    # Using options
    sql-dump-translator.php -i backup.sql -s mysql -t sqlite -o output.sql
    
    # Verbose MySQL to PostgreSQL
    sql-dump-translator.php -i mysql_dump.sql -s mysql -t postgresql --verbose
    
    # Validate only
    sql-dump-translator.php --input=dump.sql --source=mysql --validate-only

HELP;
    }

    private function showVersion(): void
    {
        echo "Enhanced Model SQL Dump Translator v2.1\n";
        echo "Built-in dependency sorting and ALTER TABLE support\n";
        echo "Supported: mysql, postgresql, sqlite\n";
    }
}

// Execute if run directly
if (isset($argv) && realpath($argv[0]) === __FILE__) {
    $cli = new SQLDumpCLI();
    exit($cli->run($argv));
}
