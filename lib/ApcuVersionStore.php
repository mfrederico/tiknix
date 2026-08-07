<?php
/**
 * APCu-backed generation counters. See CacheVersionStore for the design.
 */

namespace app;

/**
 * APCu-backed versions — the original behaviour, correct only within ONE process family.
 * Still the right choice for a database that only web requests ever write.
 */
class ApcuVersionStore implements CacheVersionStore {

    public function usable(): bool {
        return function_exists('apcu_fetch')
            && ini_get('apc.enabled')
            && (php_sapi_name() !== 'cli' || ini_get('apc.enable_cli'));
    }

    public function version(string $key): string {
        if (!$this->usable()) return '';
        $v = apcu_fetch($key, $ok);
        if (!$ok) { $v = self::mint(); apcu_store($key, $v, 86400); }
        return (string) $v;
    }

    public function bump(string $key): void {
        if (!$this->usable()) return;
        apcu_store($key, self::mint(), 86400);
    }

    /** One process family only: php-fpm's workers share a segment, CLI never joins it. */
    public function isShared(): bool { return false; }

    public function describe(): string { return 'apcu'; }

    /**
     * A generation value that can never repeat.
     *
     * Not a counter: a counter that expires and restarts can hand back a number a stale
     * payload already recorded, which reads as a HIT and serves rows from before the
     * write. Time plus randomness cannot collide with a previous generation.
     */
    public static function mint(): string {
        return dechex((int) (microtime(true) * 1000000)) . '.' . bin2hex(random_bytes(4));
    }
}
