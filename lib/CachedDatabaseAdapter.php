<?php
/**
 * CachedDatabaseAdapter - Transparent Query Cache for RedBeanPHP
 *
 * Drop-in replacement for RedBeanPHP's database adapter that automatically
 * caches SELECT queries and invalidates on data modifications.
 *
 * @author Claude
 * @version 1.0
 */

namespace app;

use \RedBeanPHP\Adapter\DBAdapter;
use \RedBeanPHP\Driver;
use \RedBeanPHP\R;
use \Flight;

class CachedDatabaseAdapter extends DBAdapter {

    // Cache configuration
    private $enabled = true;
    private $defaultTTL = 60;
    private $cachePrefix;
    /** True when the connected database could not be identified — caching is then OFF. */
    private $identityFailed = false;

    // Statistics
    private $hits = 0;
    private $misses = 0;

    // Table version tracking
    private $tableVersions = [];

    /**
     * Constructor - wraps existing adapter
     */
    public function __construct($database) {
        parent::__construct($database);

        // Unique prefix per SITE **and per DATABASE**.
        //
        // The database used to be missing from this, and the key is otherwise just the
        // SQL text — so any request that touched two databases got cross-database hits on
        // identical SQL. That is not hypothetical: every sidecar request talks to core's
        // db and an instance's, and RedBean's own "which tables exist?" lookup is
        // byte-identical between them. It answered from the wrong database, RedBean
        // concluded a table was missing, and issued CREATE TABLE — surfacing as
        // "table `piperun` already exists" when running a pipeline in an instance.
        //
        // The same collision could return one database's ROWS for another's query, which
        // is a good deal worse than an error message.
        // If the database cannot be identified we do NOT fall back to a shared
        // namespace — that is precisely the bug this key exists to prevent, and it would
        // reappear silently. Caching is switched OFF for this adapter instead and the
        // reason is logged at ERROR: a cold cache is a performance problem, and one
        // database answering another's query is a correctness one.
        $dbId = self::databaseIdentity($database);
        $this->identityFailed = ($dbId === '');
        $siteId = md5(__DIR__ . '_' . ($_SERVER['HTTP_HOST'] ?? 'cli') . '_'
                      . ($this->identityFailed ? 'unidentified-' . bin2hex(random_bytes(8)) : $dbId));
        $this->cachePrefix = "rdb_{$siteId}_";

        // Get config if available
        if (class_exists('Flight')) {
            $this->enabled = Flight::get('cache.query_cache') ?? true;
            $this->defaultTTL = Flight::get('cache.query_cache_ttl') ?? 60;
        }

        if ($this->identityFailed) {
            // Belt: the config block above may have enabled it. Nothing enables caching
            // for an adapter that does not know which database it is in front of.
            $this->enabled = false;
            $msg = 'CachedDatabaseAdapter: could not identify the database (no readable DSN on '
                 . get_class($database) . ') — QUERY CACHING DISABLED for this connection. '
                 . 'Caching without a database identity can answer one database\'s query from '
                 . 'another\'s rows.';
            try {
                if (class_exists('Flight') && \Flight::get('log')) { \Flight::get('log')->error($msg); }
                else { error_log($msg); }
            } catch (\Throwable $e) { error_log($msg); }
        }

        $this->log('CachedDatabaseAdapter initialized');
    }

    /**
     * Override get() - intercepts SELECT queries
     */
    public function get($sql, $bindings = array()) {
        // Only cache SELECT queries, and NEVER schema ones — see isSchemaQuery().
        if (!$this->enabled || !$this->isSelectQuery($sql) || $this->isSchemaQuery($sql)) {
            $result = parent::get($sql, $bindings);
            $this->maybeInvalidate($sql);
            return $result;
        }

        // Check cache first
        $cacheKey = $this->getCacheKey($sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            $this->log("Cache HIT for query", ['sql' => $sql]);
            return $cached['result'];
        }

        // Execute query
        $result = parent::get($sql, $bindings);

        // Cache the result
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;
        $this->log("Cache MISS for query", ['sql' => $sql]);

        return $result;
    }

    /**
     * Override getCell() - intercepts single cell queries
     */
    public function getCell($sql, $bindings = array(), $noSignal = null) {
        if (!$this->enabled || !$this->isSelectQuery($sql)) {
            $result = parent::getCell($sql, $bindings, $noSignal);
            $this->maybeInvalidate($sql); // RedBean runs INSERTs through here
            return $result;
        }

        $cacheKey = $this->getCacheKey('cell_' . $sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            return $cached['result'];
        }

        $result = parent::getCell($sql, $bindings, $noSignal);
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;

        return $result;
    }

    /**
     * Override getCol() - intercepts column queries
     */
    public function getCol($sql, $bindings = array()) {
        if (!$this->enabled || !$this->isSelectQuery($sql)) {
            $result = parent::getCol($sql, $bindings);
            $this->maybeInvalidate($sql);
            return $result;
        }

        $cacheKey = $this->getCacheKey('col_' . $sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            return $cached['result'];
        }

        $result = parent::getCol($sql, $bindings);
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;

        return $result;
    }

    /**
     * Override getRow() - intercepts single row queries
     */
    public function getRow($sql, $bindings = array()) {
        if (!$this->enabled || !$this->isSelectQuery($sql)) {
            $result = parent::getRow($sql, $bindings);
            $this->maybeInvalidate($sql);
            return $result;
        }

        $cacheKey = $this->getCacheKey('row_' . $sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            return $cached['result'];
        }

        $result = parent::getRow($sql, $bindings);
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;

        return $result;
    }

    /**
     * Override getAssoc() - intercepts associative array queries
     */
    public function getAssoc($sql, $bindings = array()) {
        if (!$this->enabled || !$this->isSelectQuery($sql)) {
            $result = parent::getAssoc($sql, $bindings);
            $this->maybeInvalidate($sql);
            return $result;
        }

        $cacheKey = $this->getCacheKey('assoc_' . $sql, $bindings);
        $cached = $this->getFromCache($cacheKey);

        if ($cached !== false) {
            $this->hits++;
            return $cached['result'];
        }

        $result = parent::getAssoc($sql, $bindings);
        $this->storeInCache($cacheKey, $sql, $bindings, $result);
        $this->misses++;

        return $result;
    }

    /**
     * Override exec() - intercepts INSERT/UPDATE/DELETE
     */
    public function exec($sql, $bindings = array(), $noEvent = false) {
        $result = parent::exec($sql, $bindings, $noEvent);

        // Invalidate cache for affected tables
        if ($this->enabled && $result !== false) {
            $this->invalidateFromSQL($sql);
        }

        return $result;
    }

    /**
     * Something stable that identifies WHICH database this adapter talks to, for the
     * cache prefix. The DSN is exactly that, and RedBean keeps it protected — read it
     * without forcing a connection (RedBean connects lazily, and building an adapter
     * must not change that).
     */
    private static function databaseIdentity($database): string {
        // Returns '' when it cannot be determined. The caller treats that as fatal to
        // CACHING (not to the request) — see the constructor.
        try {
            $ref = new \ReflectionObject($database);
            if ($ref->hasProperty('dsn')) {
                $prop = $ref->getProperty('dsn');
                $prop->setAccessible(true);
                $dsn = (string) $prop->getValue($database);
                if ($dsn !== '') return $dsn;
            }
        } catch (\Throwable $e) { /* reported by the caller */ }
        return '';
    }

    /**
     * Generate cache key from query
     */
    private function getCacheKey($sql, $bindings = array()) {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $sql ?? '')));
        return $this->cachePrefix . md5($normalized . serialize($bindings));
    }

    /**
     * Store result in cache with table tracking
     */
    private function storeInCache($key, $sql, $bindings, $result, $ttl = null) {
        if (!$this->hasAPCu()) {
            return false;
        }

        // Extract tables from SQL
        $tables = $this->extractTables($sql);

        // Get current version for each table
        $versions = [];
        foreach ($tables as $table) {
            $versions[$table] = $this->getTableVersion($table);
        }

        $data = [
            'result' => $result,
            'tables' => $tables,
            'versions' => $versions,
            'cached_at' => time(),
            'sql' => $sql
        ];

        $ttl = $ttl ?? $this->defaultTTL;
        return apcu_store($key, $data, $ttl);
    }

    /**
     * Get result from cache with validation
     */
    private function getFromCache($key) {
        if (!$this->hasAPCu()) {
            return false;
        }

        $data = apcu_fetch($key, $success);
        if (!$success) {
            return false;
        }

        // Validate table versions
        foreach ($data['versions'] as $table => $version) {
            if ($this->getTableVersion($table) !== $version) {
                // Table has been modified, invalidate cache
                apcu_delete($key);
                return false;
            }
        }

        return $data;
    }

    /**
     * Get or create table version
     */
    private function getTableVersion($table) {
        if (!$this->hasAPCu()) {
            return time();
        }

        $key = $this->cachePrefix . 'tv_' . $table;
        $version = apcu_fetch($key, $success);

        if (!$success) {
            $version = time() . '_' . mt_rand();
            apcu_store($key, $version, 86400); // 24 hours
        }

        return $version;
    }

    /**
     * Invalidate cache for a table
     */
    public function invalidateTable($table) {
        if (!$this->hasAPCu()) {
            return;
        }

        // Update table version
        $key = $this->cachePrefix . 'tv_' . $table;
        $newVersion = time() . '_' . mt_rand();
        apcu_store($key, $newVersion, 86400);

        $this->log("Cache invalidated for table: $table");
    }

    /**
     * Extract tables from SQL statement
     */
    private function extractTables($sql) {
        $tables = [];
        $sql = str_replace(['`', '"'], '', $sql);

        // Extract from SELECT/FROM
        if (preg_match_all('/\bFROM\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Extract from JOIN
        if (preg_match_all('/\bJOIN\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Extract from INSERT INTO
        if (preg_match('/\bINTO\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Extract from UPDATE
        if (preg_match('/\bUPDATE\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Extract from DELETE FROM
        if (preg_match('/\bDELETE\s+FROM\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // DDL counts as a change to that table. Schema queries are no longer cached, so
        // this is not what fixes the "already exists" error — but a fluid ALTER adds a
        // COLUMN, and rows cached from before it are a stale shape. Make the invariant
        // simple and true: anything that touches a table busts that table.
        if (preg_match('/\b(?:CREATE|DROP)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"\[]?([a-z0-9_]+)/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }
        if (preg_match('/\bALTER\s+TABLE\s+[`"\[]?([a-z0-9_]+)/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        return array_unique($tables);
    }

    /**
     * Invalidate cache based on SQL statement
     */
    private function invalidateFromSQL($sql) {
        $tables = $this->extractTables($sql);

        foreach ($tables as $table) {
            $this->invalidateTable($table);
        }
    }

    /**
     * Invalidate affected tables when a NON-select statement slips through a get*()
     * method. RedBeanPHP executes INSERTs via getCell() (to return the new row id),
     * so those writes never reach exec() — without this, a bean insert would not bust
     * cached SELECTs and a new row could stay hidden until the TTL lapsed. Safe for
     * any non-write statement: extractTables() finds no tables and this is a no-op.
     */
    private function maybeInvalidate($sql) {
        if ($this->enabled && !$this->isSelectQuery($sql)) {
            $this->invalidateFromSQL($sql);
        }
    }

    /**
     * Is this the database describing ITSELF rather than returning data?
     *
     * These must never be cached. RedBean asks "which tables exist?" with
     * `SELECT name FROM sqlite_master ...` — a SELECT, so it was cached like any other —
     * and invalidation only ever fires on INSERT/UPDATE/DELETE. A CREATE TABLE matches
     * none of those, so the moment RedBean fluid-created a table, every process still
     * holding the cached list believed it was absent and tried to create it again:
     * "table `piperun` already exists".
     *
     * Caching them buys nothing anyway — they are cheap, and they are asked once per
     * bean type per process.
     */
    private function isSchemaQuery($sql): bool {
        $s = strtolower(trim((string) $sql));
        if (strpos($s, 'pragma') === 0) return true;
        if (preg_match('/^show\s+(tables|columns|create|databases)/', $s)) return true;
        return strpos($s, 'sqlite_master') !== false
            || strpos($s, 'information_schema') !== false;
    }

    /**
     * Check if query is SELECT
     */
    private function isSelectQuery($sql) {
        $sql = strtoupper(trim($sql ?? ''));
        return strpos($sql, 'SELECT') === 0 || strpos($sql, 'SHOW') === 0;
    }

    /**
     * Check if APCu is available and properly enabled
     * Note: In CLI mode, apc.enable_cli must also be enabled
     */
    private function hasAPCu() {
        return function_exists('apcu_fetch')
            && ini_get('apc.enabled')
            && (php_sapi_name() !== 'cli' || ini_get('apc.enable_cli'));
    }

    /**
     * Clear all cache
     */
    public function clearAllCache() {
        if (!$this->hasAPCu()) {
            return;
        }

        try {
            $iterator = new \APCUIterator('/^' . preg_quote($this->cachePrefix, '/') . '/');
            foreach ($iterator as $item) {
                apcu_delete($item['key']);
            }
        } catch (\Throwable $e) {
            // APCu not fully enabled (e.g., CLI without apc.enable_cli)
            $this->log('Failed to clear cache: ' . $e->getMessage());
        }

        $this->hits = 0;
        $this->misses = 0;

        $this->log('All cache cleared');
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        $stats = [
            'enabled' => $this->enabled,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => ($this->hits + $this->misses) > 0 ?
                round($this->hits / ($this->hits + $this->misses) * 100, 2) : 0
        ];

        if ($this->hasAPCu()) {
            try {
                $pattern = '/^' . preg_quote($this->cachePrefix, '/') . '/';
                $iterator = new \APCUIterator($pattern);
                $count = 0;
                $size = 0;

                foreach ($iterator as $item) {
                    if (strpos($item['key'], '_tv_') === false) {
                        $count++;
                        $size += $item['mem_size'];
                    }
                }

                $stats['cached_queries'] = $count;
                $stats['cache_size_kb'] = round($size / 1024, 2);
            } catch (\Throwable $e) {
                // APCu not fully enabled (e.g., CLI without apc.enable_cli)
                $stats['cached_queries'] = 0;
                $stats['cache_size_kb'] = 0;
            }
        }

        return $stats;
    }

    /**
     * Enable/disable caching
     */
    public function enableCache() {
        $this->enabled = true;
    }

    public function disableCache() {
        $this->enabled = false;
    }

    /**
     * Simple logging
     */
    private function log($message, $context = []) {
        if (class_exists('Flight') && Flight::has('log')) {
            Flight::get('log')->debug("CachedDatabaseAdapter: $message", $context);
        }
    }
}