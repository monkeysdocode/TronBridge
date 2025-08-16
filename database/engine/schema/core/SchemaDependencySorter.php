<?php

/**
 * Sorts schema tables based on foreign key dependencies.
 *
 * The SchemaDependencySorter is a utility class that performs a topological
 * sort on a collection of Table objects. Its primary purpose is to order tables
 * correctly for database creation (parents first, then children) and deletion
 * (children first, then parents). This prevents errors related to foreign key
 * constraints during schema manipulation.
 *
 * The class builds a dependency graph from the foreign key relationships
 * defined in the Table objects and uses Kahn's algorithm to produce a
 * linear ordering. It also includes cycle detection to handle and report
 * circular dependencies, which would otherwise make a valid sorting impossible.
 *
 * Key Features:
 * - Sorts tables for dependency-safe CREATE operations.
 * - Sorts tables for dependency-safe DROP operations.
 * - Implements a topological sort (Kahn's algorithm).
 * - Detects and throws an exception for circular dependencies.
 *
 * @package Database\Schema\Core
 * @author Enhanced Model System
 * @version 2.0.0
 */
class SchemaDependencySorter
{
    /**
     * Topologically sort tables for CREATE (parents first, children after FKs).
     * @param Table[] $tables Array of Table objects (key: table name or numeric)
     * @return Table[] Sorted for dependency-safe CREATE order
     */
    public function sortForCreate(array $tables): array
    {
        // Map table name to Table object
        $byName = [];
        foreach ($tables as $table) {
            $byName[$table->getName()] = $table;
        }

        // Build dependency graph: $deps[table] = [table names it depends on]
        $deps = [];
        foreach ($tables as $table) {
            $name = $table->getName();
            $deps[$name] = [];
            foreach ($table->getConstraints() as $constraint) {
                if ($constraint->isForeignKey()) {
                    $depTable = $constraint->getReferencedTable();
                    if ($depTable && $depTable !== $name && isset($byName[$depTable])) {
                        $deps[$name][] = $depTable;
                    }
                }
            }
        }

        // Topological sort
        $orderedNames = $this->topoSort($deps);

        // Return ordered Table objects
        $ordered = [];
        foreach ($orderedNames as $name) {
            $ordered[] = $byName[$name];
        }
        return $ordered;
    }

    /**
     * Dependency-aware reverse (e.g. for DROP statements). Tables with dependents are dropped last.
     * @param Table[] $tables
     * @return Table[] Sorted for safe DROP (children first, then parents)
     */
    public function sortForDrop(array $tables): array
    {
        return array_reverse($this->sortForCreate($tables));
    }

    /**
     * Topological sort utility (Kahn’s algorithm)
     * @param array $deps [table => array of dependencies]
     * @return array Sorted list of names
     */
    private function topoSort(array $deps): array
    {
        // FIXED: Calculate in-degree as "how many things I depend on"
        $inDegree = [];
        foreach ($deps as $table => $tableDeps) {
            // FIXED: Set in-degree to count of dependencies (not incrementing dependencies)
            $inDegree[$table] = count($tableDeps);
        }
        
        // Make sure all referenced tables are in the in-degree map
        foreach ($deps as $table => $tableDeps) {
            foreach ($tableDeps as $dep) {
                if (!isset($inDegree[$dep])) {
                    $inDegree[$dep] = 0; // Referenced table with no dependencies
                }
            }
        }

        // Start with tables that have no dependencies (in-degree = 0)
        $queue = [];
        foreach ($inDegree as $table => $degree) {
            if ($degree === 0) {
                $queue[] = $table;
            }
        }

        $result = [];
        while (!empty($queue)) {
            $table = array_shift($queue);
            $result[] = $table;
            
            // For each table that depends on the current table, decrease its in-degree
            foreach ($deps as $dependentTable => $dependencies) {
                if (in_array($table, $dependencies)) {
                    $inDegree[$dependentTable]--;
                    if ($inDegree[$dependentTable] === 0) {
                        $queue[] = $dependentTable;
                    }
                }
            }
        }

        // Cycle detection: If result count != all tables, there is a cycle
        if (count($result) !== count($inDegree)) {
            throw new Exception("Circular dependency detected in schema tables. Unable to sort.");
        }
        
        return $result;
    }
}
