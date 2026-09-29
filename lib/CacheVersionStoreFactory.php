<?php
/**
 * Chooses the version store. See CacheVersionStore for the design.
 */

namespace app;

/** Builds the store named by [cache] version_store. Defaults to apcu — the prior behaviour. */
final class CacheVersionStoreFactory {

    public static function fromConfig(): CacheVersionStore {
        $get = function (string $k, $default) {
            if (!class_exists('Flight')) return $default;
            $v = \Flight::get($k);
            return ($v === null || $v === '') ? $default : $v;
        };

        $which = strtolower(trim((string) $get('cache.version_store', 'apcu')));
        if (in_array($which, ['redis', 'valkey'], true)) {
            $host = (string) $get('cache.redis_host', '');
            if ($host === '') {
                throw new \RuntimeException("[cache] version_store = {$which} but redis_host is not set in conf/config.ini.");
            }
            return new RedisVersionStore(
                $host,
                (int)    $get('cache.redis_port', 6379),   // the protocol's port: a fact, not a guess
                (int)    $get('cache.redis_database', 0),
                (string) $get('cache.redis_password', '')
            );
        }
        if ($which !== 'apcu') {
            // CacheVersionStore's own contract: NO SILENT FALLBACK. 'rediss', 'valky' or any
            // other misspelling used to become APCu — the cross-process blind spot the
            // store exists to remove, restored by a typo.
            throw new \RuntimeException("[cache] version_store = '{$which}' is not a version store; use apcu, redis or valkey.");
        }
        return new ApcuVersionStore();
    }
}
