<?php
/**
 * MemberEnginePrefs — per-member overrides for engine model tiers.
 *
 * The registry ([engine.*] in aibuilder.ini) sets good DEFAULTS for each engine's
 * planner / worker / auditor / resolver models (lib/EngineRegistry). A member may
 * override any tier for the runs THEY trigger — e.g. "decompose on sonnet to save my
 * quota" — from their settings page. The override only fills in where the registry
 * default sat; it never overrides a more explicit choice (a planner-assigned per-task
 * engine still wins — AGENT_ORCHESTRATION.md §7 precedence).
 *
 * A member with no override simply inherits the current system default, so a new
 * member starts on whatever the registry says and changes it if/when they want.
 *
 * Stored as `settings` rows (Flight::getSetting/setSetting), one key per tier:
 *   engine.<engine>.<tier>_model     (mirrors the ini field names exactly)
 * An empty value means "use the registry default" (no override).
 */

namespace app;

use \Flight as Flight;

class MemberEnginePrefs {

    /** Model tiers a member may override. */
    public const TIERS = ['planner', 'worker', 'auditor', 'resolver'];

    private static function key(string $engine, string $tier): string {
        return 'engine.' . $engine . '.' . $tier . '_model';
    }

    /** Normalize a model name to a safe token, or '' when it isn't a valid one. */
    public static function clean(?string $model): string {
        $m = trim((string)$model);
        // Model names flow into escapeshellarg(--model <x>) everywhere, so shell
        // injection is already impossible; this just rejects obvious garbage.
        return preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $m) ? $m : '';
    }

    /**
     * The member's effective model for (engine, tier): their override if set + valid,
     * else the registry default for that engine/tier.
     */
    public static function model(?int $memberId, string $engine, string $tier, string $fallback = 'sonnet'): string {
        $default = EngineRegistry::model($engine, $tier, $fallback);
        if (!$memberId || !in_array($tier, self::TIERS, true)) return $default;
        $override = self::clean(Flight::getSetting(self::key($engine, $tier), $memberId));
        return $override !== '' ? $override : $default;
    }

    /**
     * Effective tier map for a member+engine, for the settings form. Each entry:
     *   ['default' => <registry>, 'override' => <member '' if none>, 'effective' => …]
     */
    public static function effective(?int $memberId, string $engine): array {
        $out = [];
        foreach (self::TIERS as $tier) {
            $default  = EngineRegistry::model($engine, $tier, 'sonnet');
            $override = $memberId ? self::clean(Flight::getSetting(self::key($engine, $tier), $memberId)) : '';
            $out[$tier] = [
                'default'   => $default,
                'override'  => $override,
                'effective' => $override !== '' ? $override : $default,
            ];
        }
        return $out;
    }

    /**
     * Persist a member's override for one tier. A value equal to the registry default
     * (or empty/invalid) clears the override, so we never store a row that just
     * duplicates the default.
     */
    public static function set(int $memberId, string $engine, string $tier, ?string $model): void {
        if (!EngineRegistry::isValid($engine) || !in_array($tier, self::TIERS, true)) return;
        $clean   = self::clean($model);
        $default = EngineRegistry::model($engine, $tier, 'sonnet');
        Flight::setSetting(self::key($engine, $tier), ($clean === '' || $clean === $default) ? '' : $clean, $memberId);
    }
}
