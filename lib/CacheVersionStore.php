<?php
/**
 * CacheVersionStore — where the query cache keeps its per-table generation counters.
 *
 * The payloads stay in APCu: they are big, they are read constantly, and APCu is
 * in-process shared memory measured in microseconds. It is the VERSIONS that have to be
 * shared more widely, and they are tiny — one short string per table.
 *
 * Why that split matters here. APCu memory is created at process startup: php-fpm's master
 * allocates one segment every worker inherits, and each CLI invocation allocates its own
 * and destroys it on exit. Measured, not assumed — a key written by CLI is invisible to
 * fpm and to the next CLI process, both directions. So with versions in APCu a CLI writer
 * could never tell a web reader that a table had changed. That is the whole reason the
 * per-instance workbench.db had to run uncached, why promptlog is excluded, and why the
 * sidecar busts tables before reading.
 *
 * Valkey (a Redis fork, spoken to by the same php-redis extension) has no process
 * boundary, so one INCR-equivalent from a CLI orchestrator is seen by every fpm worker on
 * its next read. Payloads never cross the wire; only the version does.
 *
 * NO SILENT FALLBACK. If the configured store cannot be reached, caching is turned OFF and
 * the reason is logged at ERROR. Quietly dropping back to APCu versions would restore
 * exactly the cross-process blind spot this exists to remove, and it would do it invisibly
 * — a correctness bug wearing a performance bug's clothes.
 */

namespace app;

interface CacheVersionStore {
    /** Current generation for a table, minting one if absent. '' signals the store is unusable. */
    public function version(string $key): string;

    /** Start a new generation for a table — every cached entry referencing the old one dies. */
    public function bump(string $key): void;

    /** False once the store has failed; the adapter then stops caching entirely. */
    public function usable(): bool;

    /**
     * Is this store visible to EVERY process on the box, not just one process family?
     *
     * The answer decides whether a table written by a CLI process may be cached for web
     * readers at all — see CachedDatabaseAdapter::isCrossHostTable. A false here is not a
     * defect, it is APCu being what it is.
     */
    public function isShared(): bool;

    /** Human-readable, for the cache stats panel. */
    public function describe(): string;
}
