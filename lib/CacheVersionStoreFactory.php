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
            return new RedisVersionStore(
                (string) $get('cache.redis_host', '127.0.0.1'),
                (int)    $get('cache.redis_port', 6379),
                (int)    $get('cache.redis_database', 0),
                (string) $get('cache.redis_password', '')
            );
        }
        return new ApcuVersionStore();
    }
}
