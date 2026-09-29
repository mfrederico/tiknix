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

    // Where the per-table generation counters live. APCu keeps them inside one process
    // family; redis/valkey shares them with every process on the box, which is what lets a
    // CLI writer invalidate a web reader. See lib/CacheVersionStore.php.
    private CacheVersionStore $versions;

    /**
     * Constructor - wraps existing adapter
     */
    public function __construct($database) {
        parent::__construct($database);

        // THE CACHE NAMESPACE IS THE DATABASE. Nothing else.
        //
        // The key must contain the database, because the rest of the key is just SQL
        // text: any request touching two databases otherwise got cross-database hits on
        // identical SQL. Not hypothetical — every sidecar request talks to core's db and
        // an instance's, and RedBean's own "which tables exist?" lookup is byte-identical
        // between them. It answered from the wrong database, RedBean concluded a table
        // was missing, and issued CREATE TABLE: "table piperun already exists". The same
        // collision could return one database's ROWS for another's query, which is worse
        // than an error message.
        //
        // It must contain NOTHING ELSE. The prefix used to mix in HTTP_HOST and __DIR__,
        // which SPLIT one database into several private namespaces — one per site, one
        // per codebase reading it. Rows do not change depending on who asks, so the split
        // bought no safety; what it did was break invalidation between them. A sidecar
        // (workbench.tiknix.com) writing a row into core's db stamped the new table
        // version under ITS prefix while core read under core's, so core kept serving the
        // list it had cached before the write. That is the bug isCrossHostTable() exists
        // to paper over, one table at a time, and the bug core's Firehose would hit
        // reading an instance's db through core's lib. Keyed on the DSN alone, any writer
        // invalidates for every reader.
        //
        // The DSN is safe to use alone because every R::setup / addDatabase call in this
        // codebase passes an ABSOLUTE path (verified: sqlite:/var/www/html/default/...),
        // so one DSN means exactly one file.
        //
        // If the database cannot be identified there is NO namespace and NO fallback.
        // Not a shared one — that is the collision above, reappearing silently. Not an
        // invented random one either: caching is off, but invalidateTable() is public and
        // called from controllers, and neither it nor getTableVersion() consults
        // $enabled. A made-up prefix therefore wrote rdb_<random>_tv_* into a 32MB APCu
        // segment on every request — entries nothing can ever read, that clearAllCache()
        // and getCacheStats() cannot even see, because both iterate by prefix.
        //
        // An empty prefix is the honest answer, and cacheUsable() below turns it into a
        // hard off switch for every APCu path at once.
        $this->versions = CacheVersionStoreFactory::fromConfig();

        $dbId = self::databaseIdentity($database);
        $this->identityFailed = ($dbId === '');
        $this->cachePrefix = $this->identityFailed ? '' : 'rdb_' . md5($dbId) . '_';

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
        // Only cache SELECT queries, and NEVER schema ones — see isSchemaQuery() — nor
        // tables another host writes — see isCrossHostTable().
        if (!$this->enabled || !$this->isSelectQuery($sql) || $this->isSchemaQuery($sql)
            || $this->isCrossHostTable($sql)) {
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
        if (!$this->cacheUsable()) {
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
        if (!$this->cacheUsable()) {
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
        if (!$this->cacheUsable()) {
            return time();
        }
        return $this->versions->version($this->cachePrefix . 'tv_' . $table);
    }

    /**
     * Invalidate cache for a table
     */
    public function invalidateTable($table) {
        // versionsUsable, not cacheUsable: a CLI writer holds no payload cache of its own
        // but still has to bump the generation the web workers read.
        if (!$this->versionsUsable()) {
            return;
        }
        $this->versions->bump($this->cachePrefix . 'tv_' . $table);
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
    /**
     * Tables written by a process that CANNOT invalidate this cache.
     *
     * This used to be described as a cross-HOST problem, blamed on HTTP_HOST being part
     * of the cache prefix. That was only half true and the prefix no longer carries the
     * host, so web-to-web invalidation now works everywhere. The half that is REAL, and
     * unfixable from here, is the process boundary:
     *
     *   APCu memory is per-SAPI. A CLI process does not share php-fpm's segment — proven
     *   by writing a key in CLI and failing to read it over HTTP, and vice versa. So a
     *   CLI writer cannot stamp a new table version that an fpm reader will ever see, no
     *   matter what the key looks like. (CLI does not read from cache either —
     *   apc.enable_cli is Off, so hasAPCu() is false there — which is why CLI itself
     *   never serves anything stale.)
     *
     * `promptlog` qualifies because scripts/plan-ingest.php links a plan to its prompt
     * from the CLI while the Prompts page reads it over HTTP. Caching it means you write
     * a prompt and it appears to have vanished — precisely the complaint the feature
     * exists to fix. The cost of not caching is nothing: one small member-scoped read.
     *
     * Add a table here when a CLI process writes it and a web request reads it. A table
     * written and read only by web requests invalidates correctly and should stay cached.
     */
    private function isCrossHostTable($sql): bool {
        // A SHARED version store removes the reason for this list entirely: a CLI writer
        // can bump a generation every web worker reads, so these tables invalidate like
        // any other and there is nothing to exclude. The list is not deleted, because
        // version_store is configurable and reverting it to apcu must bring the guard
        // back with it rather than leaving a stale-read bug behind.
        if ($this->versions->isShared()) return false;

        foreach ($this->extractTables($sql) as $t) {
            if (strtolower($t) === 'promptlog') return true;
        }
        return false;
    }

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
     * May this adapter touch APCu at all?
     *
     * Two conditions, and the second is the one that used to be missing. Without a
     * database identity there is no namespace to write under, and EVERY APCu path has to
     * stop — not just the read/write ones that check $enabled. getTableVersion() and the
     * public invalidateTable() do not consult $enabled, so they would happily stamp keys
     * under an empty or invented prefix forever.
     *
     * In CLI, apc.enable_cli must also be on. It is Off here, which is deliberate and
     * load-bearing: CLI has its own APCu segment (proven — a key written in CLI is
     * invisible over HTTP and vice versa), so a CLI process could never see an fpm entry
     * anyway. Returning false means CLI reads are always fresh from the database.
     */
    private function cacheUsable() {
        // Storing or serving a PAYLOAD needs both: APCu to hold it, and a readable version
        // to say whether it is still true.
        return $this->versionsUsable() && $this->hasAPCu();
    }

    /**
     * May this adapter touch the VERSION store? Deliberately NOT gated on APCu.
     *
     * This distinction is the entire point of moving versions out of APCu. Invalidation
     * has to work in processes that never cache anything themselves — a CLI orchestrator
     * writing a task row caches nothing (apc.enable_cli is Off) but MUST still tell the web
     * workers that the table moved. Gating this on hasAPCu() made the bump a no-op in
     * exactly the process the shared store exists to serve, leaving the feature looking
     * wired up and doing nothing.
     */
    private function versionsUsable() {
        if ($this->identityFailed || $this->cachePrefix === '') return false;
        return $this->versions->usable();
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
        if (!$this->cacheUsable()) {
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

        if ($this->cacheUsable()) {
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

    /** The version store this adapter uses, for the admin page. */
    public function versionStore(): CacheVersionStore { return $this->versions; }

    /** True when this adapter knows which database it fronts (and may therefore cache). */
    public function identified(): bool { return !$this->identityFailed; }

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
     * Per-query cache tracing — OFF unless you ask for it.
     *
     * This logged every hit, miss, invalidation and init at debug level, which came to
     * 93,855 of 166,635 lines in one day: 56% of the log, for a cache that is working
     * correctly. A log that is mostly cache chatter is a log nobody reads, and this
     * codebase holds the rule that any ERROR in the log is a bug — a rule you cannot
     * apply to a file where the errors are one line in two hundred.
     *
     * Turn it back on per environment when actually debugging the cache:
     *
     *   [logging]
     *   query_cache_debug = true
     *
     * Real problems — a DSN it cannot read, an invalidation that fails — are logged at
     * warning/error elsewhere and are NOT gated by this.
     */
    private static ?bool $traceEnabled = null;

    private function log($message, $context = []) {
        if (self::$traceEnabled === null) {
            $v = class_exists('Flight') ? Flight::get('logging.query_cache_debug') : null;
            self::$traceEnabled = is_bool($v)
                ? $v
                : in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
        }
        if (!self::$traceEnabled) return;

        if (class_exists('Flight') && Flight::has('log')) {
            Flight::get('log')->debug("CachedDatabaseAdapter: $message", $context);
        }
    }
}