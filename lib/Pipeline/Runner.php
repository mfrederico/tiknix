<?php
/**
 * Pipeline\Runner — the public entry point. From any instance controller:
 *
 *     $result = \app\Pipeline\Runner::run('lead-triage', ['email' => $e]);
 *
 * Operates on the CURRENT app root (core or the instance the code runs in), so a
 * pipeline is genuinely "part of the code" — it ships and runs with the instance.
 * Later phases target a specific instance dir (the editor sidecar) via the same API.
 */

namespace app\Pipeline;

class Runner {

    /** The app root whose pipelines/ dir + DB we use (lib/Pipeline → lib → root). */
    public static function root(): string {
        return dirname(__DIR__, 2);
    }

    private static function loader(): Loader {
        return new Loader(self::root());
    }

    /** [slug => definition] for every valid pipeline file. */
    public static function list(): array {
        return self::loader()->all();
    }

    public static function get(string $slug): ?array {
        return self::loader()->get($slug);
    }

    /** The step-type components (schemas) an agent/editor can build with. */
    public static function components(): array {
        return StepRegistry::components();
    }

    /**
     * Run a pipeline by slug SYNCHRONOUSLY (in-process). Best for short pipelines /
     * in-app callers that want the result inline. Returns the run summary
     * (['run_id','run_uid','status','steps_done','error','output']).
     */
    public static function run(string $slug, array $context = [], string $source = 'code'): array {
        return (new Executor(self::root()))->run(self::def($slug), $context, $source);
    }

    /**
     * Run a pipeline in the BACKGROUND (jailed on capricorn instances). Returns
     * immediately with { run_id, status:'queued' }; poll get_run. Use for anything
     * long-running (agent steps) and for triggers/REST/editor.
     */
    public static function dispatch(string $slug, array $context = [], string $source = 'trigger'): array {
        return (new Dispatcher(self::root()))->dispatch(self::def($slug), $context, $source);
    }

    /** Resume a paused await_input run, injecting the supplied input. */
    public static function continueRun(int $runId, array $input): array {
        return (new Executor(self::root()))->continueRun($runId, $input);
    }

    // ---- debug / step-trace (the editor's debugger) ------------------------

    /** Start a step-trace debug run: runs the first step, then pauses at a breakpoint. */
    public static function debugRun(string $slug, array $context = []): array {
        return (new Executor(self::root()))->debugRun(self::def($slug), $context, 'debug');
    }

    /** Advance a paused debug run one step, first merging $patch into the bag. */
    public static function debugStep(int $runId, array $patch = []): array {
        return (new Executor(self::root()))->debugStep($runId, $patch);
    }

    /** Let a paused debug run finish (merging $patch first). */
    public static function debugContinueToEnd(int $runId, array $patch = []): array {
        return (new Executor(self::root()))->debugContinueToEnd($runId, $patch);
    }

    /** Abort a paused debug run. */
    public static function debugAbort(int $runId): array {
        return (new Executor(self::root()))->debugAbort($runId);
    }

    /** Load + validate a definition, or throw. */
    private static function def(string $slug): array {
        $def = self::get($slug);
        if (!$def) throw new \RuntimeException("pipeline '$slug' not found");
        $errors = Loader::validate($def);
        if ($errors) throw new \RuntimeException("pipeline '$slug' invalid: " . implode('; ', $errors));
        return $def;
    }

    /** Validate a definition without running it (dry_run). */
    public static function validate(array $def): array {
        return Loader::validate($def);
    }
}
