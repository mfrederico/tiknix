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
     * Run a pipeline by slug. Returns the run summary
     * (['run_id','run_uid','status','steps_done','error','output']).
     * Throws if the pipeline file is missing or invalid.
     */
    public static function run(string $slug, array $context = [], string $source = 'code'): array {
        $def = self::get($slug);
        if (!$def) throw new \RuntimeException("pipeline '$slug' not found");
        $errors = Loader::validate($def);
        if ($errors) throw new \RuntimeException("pipeline '$slug' invalid: " . implode('; ', $errors));
        return (new Executor(self::root()))->run($def, $context, $source);
    }

    /** Validate a definition without running it (dry_run). */
    public static function validate(array $def): array {
        return Loader::validate($def);
    }
}
