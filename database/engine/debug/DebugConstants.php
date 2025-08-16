<?php 

/**
 * Debug Level Constants
 * 
 * Controls the verbosity of debug output with hierarchical levels.
 * Higher levels include all lower level output.
 */
class DebugLevel
{
    const BASIC = 1;     // Essential operations only (SQL queries, major operations)
    const DETAILED = 2;  // + Performance metrics, optimization decisions, cache stats
    const VERBOSE = 3;   // + All internal operations, detailed analysis, suggestions
}

/**
 * Debug Category Constants
 * 
 * Allows filtering debug output by operation type. Categories can be combined
 * using bitwise OR operations for flexible filtering.
 */
class DebugCategory
{
    const SQL = 1;          // Query execution, parameters, EXPLAIN plans
    const PERFORMANCE = 2;  // Timing, memory usage, optimization decisions
    const BULK = 4;         // Bulk operation detection, chunking, batch processing
    const CACHE = 8;        // Cache hits/misses, warming, validation cache
    const TRANSACTION = 16; // Transaction lifecycle, savepoints, commits/rollbacks
    const MAINTENANCE = 32; // Database maintenance operations, vacuum, analyze
    const SECURITY = 64;    // Validation, escaping, security checks

    // Convenience combinations
    const ALL = 127;        // All categories
    const DEVELOPER = 7;    // SQL + PERFORMANCE + BULK (most useful for development)
    const PRODUCTION = 2;   // PERFORMANCE only (for production monitoring)
}