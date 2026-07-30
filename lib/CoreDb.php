<?php
/**
 * CoreDb — run a piece of work against CORE's registry database, from anywhere.
 *
 * A sidecar's RedBean connection points at the INSTANCE it is working on
 * (data/workbench.db), which is correct for task data and wrong for everything that
 * belongs to the platform: members, teams, the instance registry, API keys. Writing one of
 * those on the ambient connection puts the row in a file nobody will look in — the row is
 * created, the write "succeeds", and the feature is simply broken.
 *
 * That has now happened twice for the same reason, so the fix lives in one place:
 *
 *   - decomposed plans went to core's db instead of the instance's (the mirror image);
 *   - the Workbench's auto-minted MCP API key went to the INSTANCE's workbench.db while
 *     core's /mcp/message validates against core's apikey table, so every task agent got
 *     a token that could never authenticate and no task could report its own completion.
 *
 * Restoring the caller's connection matters as much as switching it: leaving a sidecar's
 * ORM pointed at core is the same bug wearing the other hat.
 */

namespace app;

use RedBeanPHP\R;

class CoreDb {

    /** Named connection key. Distinct from 'default'/'ws' so it can never be confused. */
    private const KEY = 'coredb';

    private static string $lastError = '';

    public static function lastError(): string { return self::$lastError; }

    /** Absolute path to core's registry db. This class only ever lives in core's lib. */
    public static function path(): string {
        return dirname(__DIR__) . '/database/tiknix.db';
    }

    /**
     * Run $fn with RedBean on core's db; always restore the previous connection.
     *
     * Returns $fn's value, or $onError if core is unreachable or $fn threw. Never throws:
     * callers use this inside actions that must not fail because a platform lookup did.
     * Check lastError() when the result is ambiguous.
     */
    public static function with(callable $fn, $onError = null) {
        $core = self::path();
        if (!is_file($core)) {
            self::$lastError = 'no core db at ' . $core;
            self::warn(self::$lastError);
            return $onError;
        }

        $restore = self::currentKey();
        try {
            if (!R::hasDatabase(self::KEY)) R::addDatabase(self::KEY, 'sqlite:' . $core);
            R::selectDatabase(self::KEY);
            R::freeze(false);
            return $fn();
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            self::warn($e->getMessage());
            return $onError;
        } finally {
            if ($restore !== self::KEY) {
                try { R::selectDatabase($restore); } catch (\Throwable $e) { /* nothing to restore to */ }
            }
        }
    }

    /**
     * Which database key RedBean is on. It keeps this in a private static with no
     * accessor, so it is read by reflection; if that ever stops working the caller's
     * connection would be silently left on core — the very bug this class exists to
     * prevent — so the failure is logged loudly and 'default' (RedBean's own name for the
     * connection R::setup() creates) is the last resort.
     */
    private static function currentKey(): string {
        try {
            $p = new \ReflectionProperty(R::class, 'currentDB');
            $p->setAccessible(true);
            $k = (string) $p->getValue();
            if ($k !== '') return $k;
        } catch (\Throwable $e) {
            self::warn('cannot read RedBean\'s current database key (' . $e->getMessage()
                . ') — restoring to "default"; if this process uses another key its ORM may '
                . 'be left on core\'s db');
        }
        return 'default';
    }

    private static function warn(string $msg): void {
        try {
            $log = \Flight::get('log');
            if ($log) { $log->warning('CoreDb: ' . $msg); return; }
        } catch (\Throwable $e) { /* no Flight bootstrap here */ }
        error_log('[CoreDb] ' . $msg);
    }
}
