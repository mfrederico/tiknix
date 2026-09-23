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
     * A member's stored override, WITHOUT requiring the framework to be booted.
     *
     * Flight::getSetting is a mapped method, so it exists only after FlightMap has been
     * loaded — i.e. in a web request. The plan pipeline runs in CLIs that deliberately
     * skip bootstrap, and calling it there dies with "getSetting must be a mapped
     * method", which is how automatic re-planning failed before it ever ran once. Fall
     * back to reading the row directly, which is all getSetting does.
     */
    private static function stored(string $key, int $memberId): string {
        try {
            return (string) Flight::getSetting($key, $memberId);
        } catch (\Throwable $e) {
            // Not mapped: a CLI that skipped bootstrap. Read the row itself — which is
            // all getSetting does — and treat any failure as "no override", since a
            // missing preference must never stop a build.
            try {
                $row = \app\Bean::findOne('settings', 'member_id = ? AND setting_key = ?', [$memberId, $key]);
                return $row && $row->id ? (string) $row->settingValue : '';
            } catch (\Throwable $e2) {
                // A per-instance tasks db has no settings table — expected there. Anything
                // else is a read failure that would silently drop the member's chosen model
                // (a paid build on the wrong model), so it is named either way.
                error_log(sprintf('ERROR MemberEnginePrefs::stored member=%d key=%s: could not read settings (%s); answering "no override"', $memberId, $key, $e2->getMessage()));
                return '';
            }
        }
    }

    /**
     * The member's effective model for (engine, tier): their override if set + valid,
     * else the registry default for that engine/tier.
     */
    public static function model(?int $memberId, string $engine, string $tier): string {
        $default = EngineRegistry::model($engine, $tier);
        if (!$memberId || !in_array($tier, self::TIERS, true)) return $default;
        $override = self::clean(self::stored(self::key($engine, $tier), $memberId));
        return $override !== '' ? $override : $default;
    }

    /**
     * Effective tier map for a member+engine, for the settings form. Each entry:
     *   ['default' => <registry>, 'override' => <member '' if none>, 'effective' => …]
     */
    public static function effective(?int $memberId, string $engine): array {
        $out = [];
        foreach (self::TIERS as $tier) {
            $default  = EngineRegistry::model($engine, $tier);
            $override = $memberId ? self::clean(self::stored(self::key($engine, $tier), $memberId)) : '';
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
        $default = EngineRegistry::model($engine, $tier);
        Flight::setSetting(self::key($engine, $tier), ($clean === '' || $clean === $default) ? '' : $clean, $memberId);
    }

    /* Per-member API keys (engine.<e>.auth_token) were removed 2026-09-23: a member's own
       key is a model connection now (Connections → Models, Model_Modelconnection), which
       every runner and the Builder terminal honour. Seed 15 migrated the stored ones. */

    /**
     * CORE's app_key, read from core's own config regardless of which app is running.
     *
     * Core-owned secrets (model connection keys) are encrypted under core's key — but sidecars
     * load this class through CORE's autoloader while running on THEIR OWN config, and the
     * sidecars have no app_key at all. EncryptionService::encrypt()/decrypt() read the
     * running app's config, so in a sidecar they threw, and a catch turned that into "this
     * member has no key" — the operator's key would then be used in place of the member's,
     * silently and with no way to tell from the UI.
     *
     * dirname(__DIR__) is core's root by construction: this file lives in core's lib/.
     */
    public static function coreKey(): string {
        $cfg = @parse_ini_file(dirname(__DIR__) . '/conf/config.ini', true) ?: [];
        $key = trim((string) ($cfg['security']['app_key'] ?? ''));
        if ($key === '') {
            throw new \RuntimeException('MemberEnginePrefs: no [security] app_key in core config; model-connection keys of members cannot be read or written.');
        }
        return $key;
    }
}
