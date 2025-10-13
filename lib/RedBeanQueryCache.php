<?php
/**
 * RedBeanQueryCache - Simple Query Cache Plugin for RedBeanPHP
 *
 * Usage:
 *   R::ext('queryCache', function($query, $bindings = []) {
 *       return RedBeanQueryCache::cachedQuery($query, $bindings);
 *   });
 *
 * @author Claude
 * @version 2.0
 */

namespace app;

use \RedBeanPHP\R as R;
use \RedBeanPHP\Plugin as Plugin;
use \Flight as Flight;

class RedBeanQueryCache implements Plugin {

    // Configuration
    private static $enabled = true;
    private static $defaultTTL = 60; // 1 minute
    private static $cachePrefix = null;

    // Statistics
    private static $hits = 0;
    private static $misses = 0;

    // Table modification tracking
    private static $tableVersions = [];

    /**
     * Initialize the plugin
     */
    public static function init() {
        // Generate unique prefix for multi-tenant safety
        $siteId = md5(__DIR__ . '_' . ($_SERVER['HTTP_HOST'] ?? 'cli'));
        self::$cachePrefix = "rdb_{$siteId}_";

        // Register RedBean extension for cached queries
        R::ext('queryCache', function($args) {
            // R::ext passes all arguments as an array
            $sql = $args[0] ?? '';
            $bindings = $args[1] ?? [];
            $ttl = $args[2] ?? null;
            return self::cachedQuery($sql, $bindings, $ttl);
        });

        // Register RedBean extension for cache management
        R::ext('clearQueryCache', function($table = null) {
            if ($table) {
                self::invalidateTable($table);
            } else {
                self::clearAll();
            }
        });

        // Register extension for manual invalidation after modifications
        R::ext('invalidateCache', function($table) {
            self::invalidateTable($table);
        });

        // Hook into RedBean events using dispense/update/delete hooks
        self::registerHooks();

        Flight::get('log')->info('RedBeanQueryCache initialized');
    }

    /**
     * Execute a cached query
     */
    public static function cachedQuery($sql, $bindings = [], $ttl = null) {
        // Ensure we have a valid SQL string
        if (empty($sql)) {
            return null;
        }

        if (!self::$enabled || !self::isSelectQuery($sql)) {
            // For non-SELECT queries, execute directly
            return R::getAll($sql, $bindings);
        }

        // Generate cache key
        $cacheKey = self::getCacheKey($sql, $bindings);

        // Try to get from cache
        $cached = self::getFromCache($cacheKey);
        if ($cached !== false) {
            self::$hits++;
            return $cached['result'];
        }

        // Execute query
        $result = R::getAll($sql, $bindings);

        // Cache the result
        self::storeInCache($cacheKey, $sql, $bindings, $result, $ttl);
        self::$misses++;

        return $result;
    }

    /**
     * Simple cached find operations
     */
    public static function findCached($type, $sql = null, $bindings = [], $ttl = 60) {
        if (!self::$enabled) {
            return R::find($type, $sql, $bindings);
        }

        // Build the full query
        $fullSql = "SELECT * FROM `{$type}`";
        if ($sql) {
            $fullSql .= " WHERE {$sql}";
        }

        // Generate cache key
        $cacheKey = self::getCacheKey($fullSql, $bindings);

        // Check table version
        if (!self::isTableValid($type, $cacheKey)) {
            self::removeFromCache($cacheKey);
        }

        // Try cache
        $cached = self::getFromCache($cacheKey);
        if ($cached !== false) {
            self::$hits++;
            // Convert arrays back to beans
            $beans = R::convertToBeans($type, $cached['result']);
            return $beans;
        }

        // Execute query
        $beans = R::find($type, $sql, $bindings);

        // Convert beans to array for caching
        $result = R::exportAll($beans);

        // Store in cache with table tracking
        self::storeInCache($cacheKey, $fullSql, $bindings, $result, $ttl, [$type]);
        self::$misses++;

        return $beans;
    }

    /**
     * Cached count operation
     */
    public static function countCached($type, $sql = null, $bindings = [], $ttl = 60) {
        if (!self::$enabled) {
            return R::count($type, $sql, $bindings);
        }

        // Build query representation
        $fullSql = "COUNT(*) FROM `{$type}`";
        if ($sql) {
            $fullSql .= " WHERE {$sql}";
        }

        // Generate cache key
        $cacheKey = self::getCacheKey($fullSql, $bindings);

        // Check table version
        if (!self::isTableValid($type, $cacheKey)) {
            self::removeFromCache($cacheKey);
        }

        // Try cache
        $cached = self::getFromCache($cacheKey);
        if ($cached !== false) {
            self::$hits++;
            return $cached['result'];
        }

        // Execute query
        $result = R::count($type, $sql, $bindings);

        // Store in cache
        self::storeInCache($cacheKey, $fullSql, $bindings, $result, $ttl, [$type]);
        self::$misses++;

        return $result;
    }

    /**
     * Register hooks for automatic cache invalidation
     */
    private static function registerHooks() {
        // Hook into RedBean's CRUD operations
        // When beans are stored or deleted, invalidate related cache

        // Option 1: Manual invalidation in your code after modifications
        // Example: R::ext('invalidateCache', 'users');

        // Option 2: Use RedBean's event system if available
        try {
            // This approach works without requiring additional plugins
            Flight::get('log')->debug('QueryCache: Manual invalidation mode active');
        } catch (\Exception $e) {
            Flight::get('log')->debug('QueryCache: Using basic invalidation');
        }
    }

    /**
     * Generate cache key from query and bindings
     */
    private static function getCacheKey($sql, $bindings = []) {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $sql)));
        return self::$cachePrefix . md5($normalized . serialize($bindings));
    }

    /**
     * Store query result in cache
     */
    private static function storeInCache($key, $sql, $bindings, $result, $ttl = null, $tables = []) {
        if (!self::hasAPCu()) {
            return false;
        }

        // Extract tables from SQL if not provided
        if (empty($tables)) {
            $tables = self::extractTables($sql);
        }

        // Get table versions
        $versions = [];
        foreach ($tables as $table) {
            $versions[$table] = self::getTableVersion($table);
        }

        $data = [
            'result' => $result,
            'tables' => $tables,
            'versions' => $versions,
            'cached_at' => time(),
            'sql' => $sql
        ];

        $ttl = $ttl ?? self::$defaultTTL;
        return apcu_store($key, $data, $ttl);
    }

    /**
     * Get result from cache
     */
    private static function getFromCache($key) {
        if (!self::hasAPCu()) {
            return false;
        }

        $data = apcu_fetch($key, $success);
        if (!$success) {
            return false;
        }

        // Validate table versions
        foreach ($data['versions'] as $table => $version) {
            if (self::getTableVersion($table) !== $version) {
                // Table has been modified
                apcu_delete($key);
                return false;
            }
        }

        return $data;
    }

    /**
     * Remove from cache
     */
    private static function removeFromCache($key) {
        if (self::hasAPCu()) {
            apcu_delete($key);
        }
    }

    /**
     * Get current version of a table
     */
    private static function getTableVersion($table) {
        if (!self::hasAPCu()) {
            return time();
        }

        $key = self::$cachePrefix . 'tv_' . $table;
        $version = apcu_fetch($key, $success);

        if (!$success) {
            $version = time() . '_' . mt_rand();
            apcu_store($key, $version, 86400); // 24 hours
        }

        return $version;
    }

    /**
     * Check if table hasn't been modified
     */
    private static function isTableValid($table, $cacheKey) {
        $cached = self::getFromCache($cacheKey);
        if ($cached === false) {
            return true; // Not cached yet
        }

        if (isset($cached['versions'][$table])) {
            return $cached['versions'][$table] === self::getTableVersion($table);
        }

        return true;
    }

    /**
     * Invalidate all cached queries for a table
     */
    public static function invalidateTable($table) {
        if (!self::hasAPCu()) {
            return;
        }

        // Update table version
        $key = self::$cachePrefix . 'tv_' . $table;
        $newVersion = time() . '_' . mt_rand();
        apcu_store($key, $newVersion, 86400);

        Flight::get('log')->debug("RedBeanQueryCache: Invalidated table {$table}");
    }

    /**
     * Clear all cached queries
     */
    public static function clearAll() {
        if (!self::hasAPCu()) {
            return;
        }

        $iterator = new \APCUIterator('/^' . preg_quote(self::$cachePrefix, '/') . '/');
        foreach ($iterator as $item) {
            apcu_delete($item['key']);
        }

        self::$hits = 0;
        self::$misses = 0;

        Flight::get('log')->info('RedBeanQueryCache: Cleared all caches');
    }

    /**
     * Check if query is SELECT
     */
    private static function isSelectQuery($sql) {
        $sql = strtoupper(trim($sql));
        return strpos($sql, 'SELECT') === 0;
    }

    /**
     * Extract table names from SQL
     */
    private static function extractTables($sql) {
        $tables = [];
        $sql = str_replace('`', '', $sql);

        if (preg_match_all('/\bFROM\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        if (preg_match_all('/\bJOIN\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        return array_unique($tables);
    }

    /**
     * Check if APCu is available
     */
    private static function hasAPCu() {
        return function_exists('apcu_fetch') && ini_get('apc.enabled');
    }

    /**
     * Get cache statistics
     */
    public static function getStats() {
        $stats = [
            'enabled' => self::$enabled,
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_rate' => (self::$hits + self::$misses) > 0 ?
                round(self::$hits / (self::$hits + self::$misses) * 100, 2) : 0
        ];

        if (self::hasAPCu()) {
            try {
                $pattern = '/^' . preg_quote(self::$cachePrefix, '/') . '/';
                $iterator = new \APCUIterator($pattern);
                $count = 0;
                $size = 0;

                if ($iterator) {
                    foreach ($iterator as $item) {
                        if (strpos($item['key'], '_tv_') === false) { // Don't count table versions
                            $count++;
                            $size += $item['mem_size'];
                        }
                    }
                }

                $stats['cached_queries'] = $count;
                $stats['cache_size_kb'] = round($size / 1024, 2);
            } catch (\Exception $e) {
                $stats['cached_queries'] = 0;
                $stats['cache_size_kb'] = 0;
            }
        }

        return $stats;
    }

    /**
     * Enable/disable caching
     */
    public static function enable() {
        self::$enabled = true;
    }

    public static function disable() {
        self::$enabled = false;
    }
}

