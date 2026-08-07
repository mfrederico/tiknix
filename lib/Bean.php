<?php
/**
 * Bean - RedBeanPHP Wrapper
 *
 * Normalizes bean type names to work around RedBeanPHP's strict naming requirements.
 * R::dispense() requires all lowercase, no underscores. This wrapper accepts:
 * - camelCase: 'enterpriseSettings' -> 'enterprisesettings'
 * - snake_case: 'enterprise_settings' -> 'enterprisesettings'
 * - Already lowercase: 'enterprisesettings' -> 'enterprisesettings'
 *
 * Usage:
 *   use \app\Bean;
 *
 *   $setting = Bean::findOne('enterpriseSettings', 'setting_key = ?', ['api_key']);
 *   if (!$setting) {
 *       $setting = Bean::dispense('enterpriseSettings');
 *       $setting->setting_key = 'api_key';
 *   }
 *   Bean::store($setting);
 */

namespace app;

use \RedBeanPHP\R as R;

class Bean {

    /**
     * Normalize bean type to lowercase, no underscores
     * This is required for R::dispense() to work
     *
     * @param string $type Bean type (camelCase, snake_case, or lowercase)
     * @return string Normalized lowercase bean type
     */
    public static function normalize(string $type): string {
        return strtolower(str_replace('_', '', $type));
    }

    /**
     * Create a new bean
     *
     * @param string $type Bean type
     * @return \RedBeanPHP\OODBBean
     */
    public static function dispense(string $type) {
        return R::dispense(self::normalize($type));
    }

    /**
     * Load a bean by ID
     *
     * @param string $type Bean type
     * @param int $id Bean ID
     * @return \RedBeanPHP\OODBBean
     */
    public static function load(string $type, int $id) {
        return R::load(self::normalize($type), $id);
    }

    /**
     * Find a single bean matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function findOne(string $type, ?string $sql = null, array $params = []) {
        // Convert null to empty string for PHP 8.1+ compatibility with RedBeanPHP
        return R::findOne(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Find all beans matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return array Array of beans
     */
    public static function find(string $type, ?string $sql = null, array $params = []) {
        // Convert null to empty string for PHP 8.1+ compatibility with RedBeanPHP
        return R::find(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Find all beans matching criteria (alias for find)
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return array Array of beans
     */
    public static function findAll(string $type, ?string $sql = null, array $params = []) {
        // Convert null to empty string for PHP 8.1+ compatibility with RedBeanPHP
        return R::findAll(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Count beans matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return int Count
     */
    public static function count(string $type, ?string $sql = null, array $params = []) {
        // Convert null to empty string for PHP 8.1+ compatibility with RedBeanPHP
        return R::count(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Store a bean
     *
     * @param \RedBeanPHP\OODBBean $bean Bean to store
     * @return int|string Bean ID
     */
    public static function store($bean) {
        return R::store($bean);
    }

    /**
     * Delete a bean
     *
     * @param \RedBeanPHP\OODBBean|string $beanOrType Bean or type name
     * @param int|null $id Optional ID if first param is type
     * @return void
     */
    public static function trash($beanOrType, ?int $id = null) {
        if (is_string($beanOrType) && $id !== null) {
            return R::trash(self::normalize($beanOrType), $id);
        }
        return R::trash($beanOrType);
    }

    /**
     * Delete multiple beans
     *
     * @param array $beans Array of beans to delete
     * @return void
     */
    public static function trashAll(array $beans) {
        return R::trashAll($beans);
    }

    /**
     * Execute raw SQL (use sparingly - only for DDL or complex operations)
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return int Affected rows
     */
    public static function exec(string $sql, array $params = []) {
        return R::exec($sql, $params);
    }

    /**
     * Get all rows as arrays (for complex SELECT with joins)
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array Array of arrays
     */
    public static function getAll(string $sql, array $params = []) {
        return R::getAll($sql, $params);
    }

    /**
     * Get a single cell value from a query
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return mixed Single value
     */
    public static function getCell(string $sql, array $params = []) {
        return R::getCell($sql, $params);
    }

    /**
     * Get a single column as an array
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array Column values
     */
    public static function getCol(string $sql, array $params = []) {
        return R::getCol($sql, $params);
    }

    /**
     * Get a single row as an array
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array|null Row data
     */
    public static function getRow(string $sql, array $params = []) {
        return R::getRow($sql, $params);
    }

    /**
     * Add a database connection
     *
     * @param string $key Database key
     * @param string $dsn DSN string
     * @param string|null $user Username
     * @param string|null $pass Password
     * @param bool $frozen Freeze mode
     */
    public static function addDatabase(string $key, string $dsn, ?string $user = null, ?string $pass = null, bool $frozen = false, bool $cached = true) {
        $result = R::addDatabase($key, $dsn, $user, $pass, $frozen);
        if ($cached) self::attachQueryCache($key);
        return $result;
    }

    /**
     * Give a SECONDARY connection the same query cache the default one has.
     *
     * bootstrap.php wraps only the 'default' toolbox, so every connection opened with
     * addDatabase ran on the plain adapter: uncached reads, and writes that invalidated
     * nothing. That is most of the sidecar and pipeline surface.
     *
     * PASS $cached = false WHEN A CLI PROCESS WRITES THE DATABASE AND A WEB REQUEST READS
     * IT. APCu memory is per-SAPI — a CLI process cannot see php-fpm's segment or stamp a
     * version it will read (proven: a key written in CLI is invisible over HTTP, and the
     * reverse). So for those databases a cached web read can sit stale until the TTL
     * lapses, with the writer having no way to say otherwise. The per-instance
     * workbench.db is exactly that shape — the build board is written by
     * plan-orchestrate/plan-ingest on the CLI and read over HTTP — so it opts out. This is
     * the one limitation Redis would remove, since every process would share a namespace.
     *
     * Never fatal, and never silent: a connection that cannot be wrapped keeps working
     * uncached and says so at WARNING. It is a performance regression, not a correctness
     * one, and pretending otherwise would hide it.
     */
    private static function attachQueryCache(string $key): void {
        try {
            if (!class_exists('Flight') || !\Flight::get('cache.query_cache')) return;
            if (!class_exists('\app\CachedDatabaseAdapter')) return;

            $old = R::getToolBoxByKey($key);
            if (!$old) return;

            $adapter = $old->getDatabaseAdapter();
            if ($adapter instanceof CachedDatabaseAdapter) return;   // already wrapped

            $cached = new CachedDatabaseAdapter($adapter->getDatabase());
            $writer = $old->getWriter();

            // Bean CRUD (find/load/store/trash) goes through the WRITER's own adapter,
            // not the toolbox's, so without this rebind writes on this connection would
            // never invalidate what reads on it had cached. Same reflection the default
            // connection needs in bootstrap.php, and guarded the same way: a RedBean
            // internals change must degrade to "uncached", never to a fatal.
            try {
                $rp = new \ReflectionProperty($writer, 'adapter');
                $rp->setAccessible(true);
                $rp->setValue($writer, $cached);
            } catch (\Throwable $e) {
                self::warn("query cache: could not rebind the writer for '{$key}' ("
                    . $e->getMessage() . ') — connection left UNCACHED rather than half-cached');
                return;
            }

            R::addToolBoxWithKey($key, new \RedBeanPHP\ToolBox($old->getRedBean(), $cached, $writer));
            self::$cacheAdapters[$key] = $cached;
        } catch (\Throwable $e) {
            self::warn("query cache: could not wrap connection '{$key}' (" . $e->getMessage() . ') — left uncached');
        }
    }

    /** Cached adapters by connection key, so a caller can invalidate the RIGHT database. */
    private static array $cacheAdapters = [];

    /**
     * The query-cache adapter for a connection, or null when that connection is uncached.
     *
     * Callers that bust a table MUST use this rather than the single
     * Flight::get('cachedDatabaseAdapter') handle, which is always the DEFAULT connection.
     * Busting 'workbenchtask' on the default adapter stamps a version in core's namespace
     * for a table that lives in workbench.db — it invalidates nothing and reads as though
     * it did.
     */
    public static function cacheAdapter(?string $key = null): ?CachedDatabaseAdapter {
        if ($key === null || $key === 'default') {
            $ad = class_exists('Flight') ? \Flight::get('cachedDatabaseAdapter') : null;
            return $ad instanceof CachedDatabaseAdapter ? $ad : null;
        }
        return self::$cacheAdapters[$key] ?? null;
    }

    /**
     * EVERY cached connection this request has opened, keyed by connection name.
     *
     * The admin page used to read the single Flight handle, which is always 'default' — so
     * it reported core's own database and silently omitted every secondary connection,
     * and its "clear query cache" button cleared one prefix out of several while saying it
     * had cleared the cache.
     *
     * @return array<string,CachedDatabaseAdapter>
     */
    public static function cacheAdapters(): array {
        $all = self::$cacheAdapters;
        $default = self::cacheAdapter('default');
        if ($default) $all = ['default' => $default] + $all;
        return $all;
    }

    /**
     * Drop cached rows on every cached connection. Returns the connections it cleared.
     *
     * Version keys are deliberately left alone: they are markers, not data, they carry
     * their own expiry, and bumping them frees nothing. Removing the PAYLOADS is what
     * both frees memory and forces the next read to go to the database.
     */
    public static function flushQueryCache(): array {
        $cleared = [];
        foreach (self::cacheAdapters() as $key => $ad) {
            try { $ad->clearAllCache(); $cleared[] = $key; }
            catch (\Throwable $e) { self::warn("query cache: could not clear '{$key}' — " . $e->getMessage()); }
        }
        return $cleared;
    }

    private static function warn(string $msg): void {
        try {
            if (class_exists('Flight') && \Flight::has('log')) { \Flight::get('log')->warning($msg); return; }
        } catch (\Throwable $e) { /* fall through to error_log */ }
        error_log($msg);
    }

    /**
     * Select a database connection
     *
     * @param string $key Database key
     */
    public static function selectDatabase(string $key) {
        return R::selectDatabase($key);
    }

    /**
     * Check whether a database connection has been registered
     *
     * @param string $key Database key
     * @return bool
     */
    public static function hasDatabase(string $key): bool {
        return R::hasDatabase($key);
    }

    /**
     * Which database key RedBean is currently on.
     *
     * Callers that switch connections need this to put the previous one back —
     * leaving another surface's ORM pointed at the wrong database is how rows end
     * up in the wrong file. Returns '' when RedBean has no connection selected;
     * the caller decides what to fall back to.
     *
     * R::$currentDB is a public static on the Facade, so this is a plain read.
     *
     * @return string Database key, or '' if none is selected
     */
    public static function currentDatabaseKey(): string {
        return (string) R::$currentDB;
    }

    /**
     * Set freeze mode
     *
     * @param bool $frozen Freeze mode
     */
    public static function freeze(bool $frozen = true) {
        return R::freeze($frozen);
    }

    /**
     * Begin a transaction
     */
    public static function begin() {
        return R::begin();
    }

    /**
     * Commit a transaction
     */
    public static function commit() {
        return R::commit();
    }

    /**
     * Rollback a transaction
     */
    public static function rollback() {
        return R::rollback();
    }

    /**
     * Get the database adapter
     *
     * @return \RedBeanPHP\Adapter\DBAdapter
     */
    public static function getDatabaseAdapter() {
        return R::getDatabaseAdapter();
    }

    /**
     * Inspect the schema: table list, or the columns of one table
     *
     * @param string|null $type Bean type; omit for the full table list
     * @return array
     */
    public static function inspect(?string $type = null) {
        return $type === null ? R::inspect() : R::inspect(self::normalize($type));
    }

    /**
     * Build a comma-separated placeholder list ("?,?,?") for an IN() binding
     *
     * NOTE: find()/findAll() return id-KEYED arrays. array_values() the parameter
     * array before binding it, or RedBeanPHP maps each id key to a positional
     * parameter index and throws "column index out of range".
     *
     * @param array $array Parameter array the slots are generated for
     * @param string|null $tpl Optional sprintf template wrapping the slots
     * @return string
     */
    public static function genSlots(array $array, ?string $tpl = null) {
        return R::genSlots($array, $tpl);
    }
}
