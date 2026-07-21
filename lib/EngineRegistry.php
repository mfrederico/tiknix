<?php
/**
 * EngineRegistry — the single resolution point for coding-agent engines.
 *
 * Everything that spawns an agent (PlanRunner, PlanExecutor, AuditRunner, the
 * terminal bridge, resolveconflict) resolves the engine through here instead of
 * hardcoding `claude`. Engines are ROWS in conf/aibuilder.ini ([engine.*]) — not
 * a `claude` + `if qwen` branch — so kimi / gemini / qwen / goose / hermes are
 * first-class. The engine-name lists that used to be duplicated three ways
 * (aibuilder-terminal/server.js, controls/Aibuilder.php, lib/PlanIngestor.php)
 * derive from here.
 *
 * Each [engine.<name>] row may declare:
 *   transport      cli-headless | pty | acp   (only cli-headless is dispatchable today)
 *   command        the CLI binary (e.g. "claude", "qwen")
 *   cli_flavor     "claude" (Claude Code flag set) | other (awaits its own launcher)
 *   headless_ready true once the engine's jailed `-p` dispatch is proven
 *   planner_model / worker_model / auditor_model / resolver_model   per-tier models
 *                  (resolver = merge-conflict resolution, decorrelated from worker, §5)
 *
 * See AGENT_ORCHESTRATION.md §7 and its Status section.
 */

namespace app;

class EngineRegistry {

    /**
     * Built-in baseline so the registry is never empty even before any ini rows
     * exist (older instance clones, fresh checkouts). Claude is the one proven
     * headless engine. Ini [engine.claude] entries override these fields.
     */
    private const BUILTIN = [
        'claude' => [
            'label'          => 'Claude Code',
            'transport'      => 'cli-headless',
            'command'        => 'claude',
            'cli_flavor'     => 'claude',
            'headless_ready' => true,
            'planner_model'  => 'opus',
            'worker_model'   => 'sonnet',
            'auditor_model'  => 'sonnet',
            'resolver_model' => 'opus',
        ],
    ];

    private static ?array $cache = null;

    private static function iniPath(): string {
        return dirname(__DIR__) . '/conf/aibuilder.ini';
    }

    private static function ini(): array {
        return @parse_ini_file(self::iniPath(), true) ?: [];
    }

    private static function truthy($v): bool {
        if (is_bool($v)) return $v;
        return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
    }

    /** All engines keyed by name: BUILTIN overlaid with the [engine.*] ini rows. */
    public static function all(): array {
        if (self::$cache !== null) return self::$cache;
        $engines = self::BUILTIN;
        foreach (self::ini() as $section => $vals) {
            if (strncmp($section, 'engine.', 7) !== 0 || !is_array($vals)) continue;
            $name = substr($section, 7);
            if ($name === '') continue;
            $engines[$name] = array_merge($engines[$name] ?? [], $vals);
        }
        foreach ($engines as &$e) {
            $e['headless_ready'] = self::truthy($e['headless_ready'] ?? false);
        }
        unset($e);
        return self::$cache = $engines;
    }

    /** Registered engine names — the source of truth for validation + UI menus. */
    public static function names(): array { return array_keys(self::all()); }

    /** Human-friendly label for an engine (falls back to the name itself). */
    public static function label(string $engine): string {
        $l = (string)(self::all()[$engine]['label'] ?? '');
        return $l !== '' ? $l : $engine;
    }

    /**
     * Whether an engine should be OFFERED to users (create dropdown, member prefs).
     * Registered-but-unavailable engines (e.g. a closed beta we lack access to) stay
     * valid for isValid/coerce so any stored value resolves, but are hidden from menus.
     * Available unless the row sets `available = false`.
     */
    public static function available(string $engine): bool {
        $e = self::all()[$engine] ?? null;
        if ($e === null) return false;
        return !array_key_exists('available', $e) || self::truthy($e['available']);
    }

    /** [name => label] of user-selectable engines (available only), for building UI menus. */
    public static function menu(): array {
        $out = [];
        foreach (self::all() as $name => $_) {
            if (self::available($name)) $out[$name] = self::label($name);
        }
        return $out;
    }

    /** True when $name is a registered engine. */
    public static function isValid(?string $name): bool {
        return $name !== null && $name !== '' && isset(self::all()[$name]);
    }

    /** The instance-provisioning default ([engine] default, falling back to claude). */
    public static function defaultEngine(): string {
        $d = (string)(self::ini()['engine']['default'] ?? 'claude');
        return self::isValid($d) ? $d : 'claude';
    }

    /**
     * Coerce an arbitrary engine value to a valid one so an unknown engine never
     * persists. Falls back to $fallback (when valid) else the instance default.
     */
    public static function coerce(?string $name, ?string $fallback = null): string {
        if (self::isValid($name)) return (string)$name;
        return ($fallback !== null && self::isValid($fallback)) ? $fallback : self::defaultEngine();
    }

    /** Resolve a model tier (planner|worker|auditor) for an engine. */
    public static function model(string $engine, string $tier, string $fallback = 'sonnet'): string {
        $e = self::all()[$engine] ?? self::all()['claude'] ?? [];
        $m = (string)($e[$tier . '_model'] ?? '');
        return $m !== '' ? $m : $fallback;
    }

    /** True when this engine can be launched headless (`-p`) TODAY. */
    public static function supportsHeadless(string $engine): bool {
        $e = self::all()[$engine] ?? null;
        return $e !== null
            && ($e['transport'] ?? '') === 'cli-headless'
            && !empty($e['headless_ready'])
            && !empty($e['command']);
    }

    /**
     * Build the inner headless agent command (the part after `cd <wt> &&`), or
     * NULL when the engine has no proven headless launcher — in which case the
     * caller runs claude with the resolved model and logs a warning (the honest
     * version of the old hardcoded path). See AGENT_ORCHESTRATION.md §7 + Status.
     *
     * $opts: ['stream' => bool]  add stream-json + --verbose (live agent.log tail).
     */
    public static function agentCommand(string $engine, string $prompt, ?string $model, array $opts = []): ?string {
        if (!self::supportsHeadless($engine)) return null;
        $e   = self::all()[$engine];
        $bin = (string)$e['command'];

        if ((string)($e['cli_flavor'] ?? 'claude') === 'claude') {
            $cmd = escapeshellarg($bin) . ' --permission-mode bypassPermissions -p ' . escapeshellarg($prompt);
            if ($model) $cmd .= ' --model ' . escapeshellarg($model);
            if (!empty($opts['stream'])) $cmd .= ' --output-format stream-json --verbose';
            return $cmd;
        }
        // Non-claude flavors (openai-compatible qwen-code, ACP engines, …) declare
        // their own headless invocation once wired into jail dispatch (Phase A/B).
        // Until then, fall back so a task is never failed over engine choice.
        return null;
    }

    /** Reset the in-process cache (tests / after an ini edit). */
    public static function flush(): void { self::$cache = null; }
}
