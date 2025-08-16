<?php

/**
 * Migration Validator - Comprehensive migration validation system
 * 
 * Validates database compatibility, migration feasibility, and
 * post-migration data integrity with detailed reporting.
 * 
 * @package Database\Migration
 * @author Enhanced Model System
 * @version 1.0.0
 */
class MigrationValidator
{
    private $sourceModel;
    private $targetModel;
    private $debugCallback = null;
    private $warnings = [];
    private $errors = [];

    /**
     * Initialize validator
     */
    public function __construct($sourceModel, $targetModel)
    {
        $this->sourceModel = $sourceModel;
        $this->targetModel = $targetModel;
    }

    /**
     * Set debug callback
     */
    public function setDebugCallback(?callable $callback): void
    {
        $this->debugCallback = $callback;
    }

    /**
     * Validate migration compatibility (pre-migration)
     */
    public function validateCompatibility(): array
    {
        $this->warnings = [];
        $this->errors = [];
        
        $this->debug("Starting compatibility validation");
        
        try {
            $sourceDB = $this->detectDatabaseType($this->sourceModel);
            $targetDB = $this->detectDatabaseType($this->targetModel);
            
            $this->debug("Database types detected", [
                'source' => $sourceDB,
                'target' => $targetDB
            ]);
            
            // Test database connections
            $this->validateConnections();
            
            // Check database compatibility
            $this->validateDatabaseCompatibility($sourceDB, $targetDB);
            
            // Extract and validate schemas
            $extractor = new SchemaExtractor();
            $extractor->setDebugCallback($this->debugCallback);
            
            $sourceSchema = $extractor->extractFullSchema($this->sourceModel);
            $this->validateSourceSchema($sourceSchema, $sourceDB);
            
            // Check target database permissions
            $this->validateTargetPermissions();
            
            // Validate data type compatibility
            $this->validateDataTypeCompatibility($sourceSchema, $sourceDB, $targetDB);
            
            // Check for unsupported features
            $this->validateFeatureSupport($sourceSchema, $sourceDB, $targetDB);
            
            $compatible = empty($this->errors);
            
            $result = [
                'compatible' => $compatible,
                'source_database' => $sourceDB,
                'target_database' => $targetDB,
                'tables_count' => count($sourceSchema),
                'warnings' => $this->warnings,
                'errors' => $this->errors,
                'recommendations' => $this->generateRecommendations($sourceDB, $targetDB)
            ];
            
            $this->debug("Compatibility validation completed", [
                'compatible' => $compatible,
                'warnings' => count($this->warnings),
                'errors' => count($this->errors)
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->errors[] = "Validation failed: " . $e->getMessage();
            return [
                'compatible' => false,
                'errors' => $this->errors,
                'warnings' => $this->warnings
            ];
        }
    }

    /**
     * Validate migration results (post-migration)
     */
    public function validateMigration(array $options = []): array
    {
        $options = array_merge([
            'verify_data_integrity' => true,
            'verify_row_counts' => true,
            'verify_constraints' => true,
            'verify_indexes' => true,
            'sample_data_verification' => true,
            'sample_size' => 100
        ], $options);
        
        $this->warnings = [];
        $this->errors = [];
        
        $this->debug("Starting post-migration validation");
        
        try {
            $extractor = new SchemaExtractor();
            $extractor->setDebugCallback($this->debugCallback);
            
            $sourceSchema = $extractor->extractFullSchema($this->sourceModel);
            $targetSchema = $extractor->extractFullSchema($this->targetModel);
            
            // Verify schema structure
            $this->validateSchemaStructure($sourceSchema, $targetSchema);
            
            // Verify row counts
            if ($options['verify_row_counts']) {
                $this->validateRowCounts($sourceSchema, $targetSchema);
            }
            
            // Verify data integrity
            if ($options['verify_data_integrity']) {
                $this->validateDataIntegrity($sourceSchema, $targetSchema, $options);
            }
            
            // Verify constraints
            if ($options['verify_constraints']) {
                $this->validateConstraints($targetSchema);
            }
            
            // Verify indexes
            if ($options['verify_indexes']) {
                $this->validateIndexes($targetSchema);
            }
            
            $success = empty($this->errors);
            
            $result = [
                'success' => $success,
                'is_valid' => true,
                'tables_verified' => count($sourceSchema),
                'warnings' => $this->warnings,
                'errors' => $this->errors,
                'validation_time' => date('Y-m-d H:i:s')
            ];
            
            $this->debug("Post-migration validation completed", [
                'success' => $success,
                'warnings' => count($this->warnings),
                'errors' => count($this->errors)
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->errors[] = "Post-migration validation failed: " . $e->getMessage();
            return [
                'success' => false,
                'errors' => $this->errors,
                'warnings' => $this->warnings
            ];
        }
    }

    /**
     * Validate database connections
     */
    private function validateConnections(): void
    {
        try {
            $sourcePdo = $this->sourceModel->getPDO();
            $sourcePdo->query("SELECT 1")->fetchColumn();
            $this->debug("Source database connection validated");
        } catch (Exception $e) {
            $this->errors[] = "Source database connection failed: " . $e->getMessage();
        }
        
        try {
            $targetPdo = $this->targetModel->getPDO();
            $targetPdo->query("SELECT 1")->fetchColumn();
            $this->debug("Target database connection validated");
        } catch (Exception $e) {
            $this->errors[] = "Target database connection failed: " . $e->getMessage();
        }
    }

    /**
     * Validate database compatibility
     */
    private function validateDatabaseCompatibility(string $sourceDB, string $targetDB): void
    {
        $supportedMigrations = [
            'sqlite' => ['mysql', 'postgresql'],
            'mysql' => ['postgresql', 'sqlite'],
            'postgresql' => ['mysql', 'sqlite']
        ];
        
        if (!isset($supportedMigrations[$sourceDB])) {
            $this->errors[] = "Unsupported source database type: $sourceDB";
            return;
        }
        
        if (!in_array($targetDB, $supportedMigrations[$sourceDB])) {
            $this->errors[] = "Migration from $sourceDB to $targetDB is not supported";
            return;
        }
        
        // Check for known compatibility issues
        $this->checkKnownCompatibilityIssues($sourceDB, $targetDB);
    }

    /**
     * Check for known compatibility issues
     */
    private function checkKnownCompatibilityIssues(string $sourceDB, string $targetDB): void
    {
        if ($sourceDB === 'sqlite' && $targetDB === 'mysql') {
            $this->warnings[] = "SQLite to MySQL: Date/time formats may need adjustment";
            $this->warnings[] = "SQLite to MySQL: Boolean values will be converted to TINYINT";
        }
        
        if ($sourceDB === 'sqlite' && $targetDB === 'postgresql') {
            $this->warnings[] = "SQLite to PostgreSQL: Case-sensitive column names may cause issues";
            $this->warnings[] = "SQLite to PostgreSQL: Auto-increment will be converted to SERIAL";
        }
        
        if ($sourceDB === 'mysql' && $targetDB === 'postgresql') {
            $this->warnings[] = "MySQL to PostgreSQL: Unsigned integers not supported";
            $this->warnings[] = "MySQL to PostgreSQL: ENUM types will be converted to CHECK constraints";
        }
    }

    /**
     * Validate source schema
     */
    private function validateSourceSchema(array $sourceSchema, string $sourceDB): void
    {
        if (empty($sourceSchema)) {
            $this->errors[] = "Source database appears to be empty";
            return;
        }
        
        foreach ($sourceSchema as $tableName => $tableSchema) {
            if (empty($tableSchema['columns'])) {
                $this->warnings[] = "Table '$tableName' has no columns";
                continue;
            }
            
            // Check for problematic column names
            foreach ($tableSchema['columns'] as $column) {
                if (in_array(strtoupper($column['name']), $this->getReservedWords())) {
                    $this->warnings[] = "Table '$tableName' has column '{$column['name']}' which is a reserved word";
                }
            }
            
            // Check for very large tables
            if (isset($tableSchema['row_count']) && $tableSchema['row_count'] > 1000000) {
                $this->warnings[] = "Table '$tableName' has {$tableSchema['row_count']} rows - migration may take significant time";
            }
        }
    }

    /**
     * Validate target database permissions
     */
    private function validateTargetPermissions(): void
    {
        try {
            $targetPdo = $this->targetModel->getPDO();
            $targetDB = $this->detectDatabaseType($this->targetModel);
            
            // Test CREATE TABLE permission
            $testTableName = 'migration_test_' . uniqid();
            
            switch ($targetDB) {
                case 'mysql':
                    $sql = "CREATE TABLE `$testTableName` (id INT PRIMARY KEY)";
                    break;
                case 'postgresql':
                    $sql = "CREATE TABLE \"$testTableName\" (id INTEGER PRIMARY KEY)";
                    break;
                case 'sqlite':
                    $sql = "CREATE TABLE `$testTableName` (id INTEGER PRIMARY KEY)";
                    break;
                default:
                    throw new Exception("Unsupported database type: $targetDB");
            }
            
            $targetPdo->exec($sql);
            $targetPdo->exec("DROP TABLE `$testTableName`");
            
            $this->debug("Target database permissions validated");
            
        } catch (Exception $e) {
            $this->errors[] = "Insufficient permissions on target database: " . $e->getMessage();
        }
    }

    /**
     * Validate data type compatibility
     */
    private function validateDataTypeCompatibility(array $sourceSchema, string $sourceDB, string $targetDB): void
    {
        $incompatibleTypes = $this->getIncompatibleDataTypes($sourceDB, $targetDB);
        foreach ($sourceSchema as $tableName => $tableSchema) {
            foreach ($tableSchema['columns'] as $column) {
                $sourceType = strtoupper($column['type']);
                
                if (isset($incompatibleTypes[$sourceType])) {
                    $this->warnings[] = "Table '$tableName', column '{$column['name']}': " .
                        "Type '$sourceType' {$incompatibleTypes[$sourceType]}";
                }
            }
        }
    }

    /**
     * Get incompatible data types mapping
     */
    private function getIncompatibleDataTypes(string $sourceDB, string $targetDB): array
    {
        $incompatibilities = [];
        
        if ($sourceDB === 'sqlite' && $targetDB === 'mysql') {
            $incompatibilities = [
                'TEXT' => 'will be converted to TEXT or VARCHAR',
                'REAL' => 'will be converted to DOUBLE',
                'BLOB' => 'will be converted to LONGBLOB'
            ];
        }
        
        if ($sourceDB === 'mysql' && $targetDB === 'postgresql') {
            $incompatibilities = [
                'TINYINT' => 'will be converted to SMALLINT',
                'MEDIUMTEXT' => 'will be converted to TEXT',
                'LONGTEXT' => 'will be converted to TEXT',
                'ENUM' => 'will be converted to VARCHAR with CHECK constraint'
            ];
        }
        
        return $incompatibilities;
    }

    /**
     * Validate feature support
     */
    private function validateFeatureSupport(array $sourceSchema, string $sourceDB, string $targetDB): void
    {
        foreach ($sourceSchema as $tableName => $tableSchema) {
            // Check for unsupported index types
            foreach ($tableSchema['indexes'] as $index) {
                if ($this->isUnsupportedIndex($index, $sourceDB, $targetDB)) {
                    $this->warnings[] = "Table '$tableName': Index '{$index['name']}' may not be fully supported";
                }
            }
            
            // Check for unsupported constraints
            foreach ($tableSchema['constraints'] as $constraint) {
                if ($this->isUnsupportedConstraint($constraint, $sourceDB, $targetDB)) {
                    $this->warnings[] = "Table '$tableName': Constraint '{$constraint['name']}' may not be supported";
                }
            }
        }
    }

    /**
     * Check if index is unsupported
     */
    private function isUnsupportedIndex(array $index, string $sourceDB, string $targetDB): bool
    {
        // Add logic for unsupported index types
        return false;
    }

    /**
     * Check if constraint is unsupported
     */
    private function isUnsupportedConstraint(array $constraint, string $sourceDB, string $targetDB): bool
    {
        // Add logic for unsupported constraint types
        return false;
    }

    /**
     * Validate schema structure after migration
     */
    private function validateSchemaStructure(array $sourceSchema, array $targetSchema): void
    {
        foreach ($sourceSchema as $tableName => $sourceTable) {
            if (!isset($targetSchema[$tableName])) {
                $this->errors[] = "Table '$tableName' missing in target database";
                continue;
            }
            
            $targetTable = $targetSchema[$tableName];
            
            // Validate column count
            $sourceColumnCount = count($sourceTable['columns']);
            $targetColumnCount = count($targetTable['columns']);
            
            if ($sourceColumnCount !== $targetColumnCount) {
                $this->warnings[] = "Table '$tableName': Column count mismatch (source: $sourceColumnCount, target: $targetColumnCount)";
            }
            
            // Validate column names (basic check)
            $sourceColumns = array_column($sourceTable['columns'], 'name');
            $targetColumns = array_column($targetTable['columns'], 'name');
            
            $missingColumns = array_diff($sourceColumns, $targetColumns);
            foreach ($missingColumns as $column) {
                $this->errors[] = "Table '$tableName': Column '$column' missing in target";
            }
        }
    }

    /**
     * Validate row counts
     */
    private function validateRowCounts(array $sourceSchema, array $targetSchema): void
    {
        foreach ($sourceSchema as $tableName => $sourceTable) {
            if (!isset($targetSchema[$tableName])) {
                continue; // Already reported in schema validation
            }
            
            $sourceCount = $sourceTable['row_count'] ?? 0;
            $targetCount = $targetSchema[$tableName]['row_count'] ?? 0;
            
            if ($sourceCount !== $targetCount) {
                $this->errors[] = "Table '$tableName': Row count mismatch (source: $sourceCount, target: $targetCount)";
            }
        }
    }

    /**
     * Validate data integrity
     */
    private function validateDataIntegrity(array $sourceSchema, array $targetSchema, array $options): void
    {
        if (!$options['sample_data_verification']) {
            return;
        }
        
        $sampleSize = $options['sample_size'];
        
        foreach ($sourceSchema as $tableName => $sourceTable) {
            if (!isset($targetSchema[$tableName])) {
                continue;
            }
            
            if (($sourceTable['row_count'] ?? 0) === 0) {
                continue; // Empty table, skip
            }
            
            try {
                $this->validateTableDataSample($tableName, $sampleSize);
            } catch (Exception $e) {
                $this->warnings[] = "Table '$tableName': Data integrity check failed - " . $e->getMessage();
            }
        }
    }

    /**
     * Validate sample data from a table
     */
    private function validateTableDataSample(string $tableName, int $sampleSize): void
    {
        $sourcePdo = $this->sourceModel->getPDO();
        $targetPdo = $this->targetModel->getPDO();
        
        // Get sample data from source
        $sql = "SELECT * FROM `$tableName` LIMIT $sampleSize";
        $sourceStmt = $sourcePdo->query($sql);
        $sourceData = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($sourceData)) {
            return;
        }
        
        // Check if target has the same data (simplified check)
        $firstRow = $sourceData[0];
        $columns = array_keys($firstRow);
        $whereClause = [];
        $params = [];
        
        foreach ($columns as $column) {
            $whereClause[] = "`$column` = ?";
            $params[] = $firstRow[$column];
        }
        
        $sql = "SELECT COUNT(*) FROM `$tableName` WHERE " . implode(' AND ', $whereClause);
        $targetStmt = $targetPdo->prepare($sql);
        $targetStmt->execute($params);
        $count = $targetStmt->fetchColumn();
        
        if ($count === 0) {
            $this->warnings[] = "Table '$tableName': Sample data verification failed - no matching rows found";
        }
    }

    /**
     * Validate constraints
     */
    private function validateConstraints(array $targetSchema): void
    {
        // Implementation for constraint validation
        // This would check if foreign key constraints are properly created
        foreach ($targetSchema as $tableName => $tableSchema) {
            // Placeholder for constraint validation logic
        }
    }

    /**
     * Validate indexes
     */
    private function validateIndexes(array $targetSchema): void
    {
        // Implementation for index validation
        // This would check if indexes are properly created and functional
        foreach ($targetSchema as $tableName => $tableSchema) {
            // Placeholder for index validation logic
        }
    }

    /**
     * Generate recommendations
     */
    private function generateRecommendations(string $sourceDB, string $targetDB): array
    {
        $recommendations = [];
        
        if ($sourceDB === 'sqlite' && $targetDB === 'mysql') {
            $recommendations[] = "Consider backing up your data before migration";
            $recommendations[] = "Review date/time column formats after migration";
            $recommendations[] = "Test your application thoroughly with the new database";
        }
        
        if (!empty($this->warnings)) {
            $recommendations[] = "Address all warnings before proceeding with migration";
        }
        
        if (count($this->warnings) > 10) {
            $recommendations[] = "Consider a phased migration approach for complex schemas";
        }
        
        return $recommendations;
    }

    /**
     * Get database reserved words
     */
    private function getReservedWords(): array
    {
        return [
            'SELECT', 'FROM', 'WHERE', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP',
            'ALTER', 'TABLE', 'INDEX', 'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES',
            'CONSTRAINT', 'NULL', 'NOT', 'DEFAULT', 'AUTO_INCREMENT', 'UNIQUE',
            'ORDER', 'BY', 'GROUP', 'HAVING', 'LIMIT', 'OFFSET', 'JOIN', 'INNER',
            'LEFT', 'RIGHT', 'OUTER', 'ON', 'AS', 'IN', 'EXISTS', 'BETWEEN',
            'LIKE', 'AND', 'OR', 'XOR', 'IS', 'TRUE', 'FALSE'
        ];
    }

    /**
     * Detect database type from model
     */
    private function detectDatabaseType($model): string
    {
        $pdo = $model->getPDO();
        return strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Debug logging
     */
    private function debug(string $message, array $context = []): void
    {
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, $message, $context);
        }
    }
}
