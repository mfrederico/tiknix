<?php
/**
 * pipeline — run another pipeline of this install: the chainable step.
 *
 * Before this the only way to chain was an `http` step POSTing /pipeline/trigger/<slug>
 * with the secret, and the child's output never came back. Here the child is a run of
 * its own (its own piperun row, parent_run_id set, its own step trace — so the debugger
 * and pipeline_run_get show it whole) and the parent's trace shows one row.
 *
 *   mode: sync   run the child to completion in-process; output = the child's final
 *                output, plus run_id / status. ok = the child completed.
 *   mode: async  dispatch it like a trigger and return at once; output = run_id.
 *
 * Resolution is the Loader's: the install's own pipelines/ and its enabled concepts'.
 * A slug that is not there fails the step by name — there is nothing to fall back to.
 * A cycle (the slug is already on the chain) or a chain deeper than MAX_DEPTH fails
 * loudly too; a runaway recursion is not a pipeline.
 */

namespace app\Pipeline\Steps;

use app\Pipeline\Dispatcher;
use app\Pipeline\Executor;
use app\Pipeline\Loader;

class PipelineStep implements StepInterface {

    public const MAX_DEPTH = 8;

    public static function type(): string { return 'pipeline'; }

    public static function schema(): array {
        return [
            'summary' => 'Run another pipeline of this install; sync returns its output as {step.output}, async returns a run id.',
            'fields'  => [
                ['name' => 'slug',    'label' => 'Pipeline',  'type' => 'text',   'required' => true, 'help' => 'Slug of the pipeline to run (the install\'s own, or an enabled concept\'s).'],
                ['name' => 'context', 'label' => 'Context',   'type' => 'keyval', 'help' => 'Input for the child run — its context_schema keys. Values may be {variables}.'],
                ['name' => 'mode',    'label' => 'Mode',      'type' => 'select', 'options' => ['sync', 'async'],
                    'help' => 'sync (default): wait and take its output. async: start it and continue with its run id.'],
            ],
        ];
    }

    public function run(array $config, array $run): array {
        $slug = Loader::safeSlug((string) ($config['slug'] ?? ''));
        if ($slug === '') return self::fail("pipeline step: 'slug' is missing or not a valid slug.");
        $mode = strtolower((string) ($config['mode'] ?? 'sync')) ?: 'sync';
        if (!in_array($mode, ['sync', 'async'], true)) return self::fail("pipeline step: mode must be sync or async, not '{$mode}'.");
        $context = $config['context'] ?? [];
        if (!is_array($context)) return self::fail("pipeline step: 'context' must be an object.");

        $root  = (string) ($run['root'] ?? '');
        $chain = array_values(array_map('strval', (array) ($run['chain'] ?? [])));
        if ($root === '') return self::fail('pipeline step: no install root in the run built-ins.');
        if (in_array($slug, $chain, true)) {
            return self::fail("pipeline '{$slug}' is already running above this step (" . implode(' → ', $chain) . " → {$slug}): a pipeline may not call itself, directly or through others.");
        }
        if (count($chain) >= self::MAX_DEPTH) {
            return self::fail("pipeline chain is " . count($chain) . " deep (" . implode(' → ', $chain) . "); the limit is " . self::MAX_DEPTH . '.');
        }

        $def = Loader::forInstall($root)->get($slug);
        if (!$def) return self::fail("pipeline '{$slug}' not found on this install (own pipelines/ and enabled concepts).");
        $errors = Loader::validate($def);
        if ($errors) return self::fail("pipeline '{$slug}' is invalid: " . implode('; ', $errors));

        $source = 'pipeline:' . (string) ($run['slug'] ?? '?');
        $parent = (int) ($run['run_id'] ?? 0);

        if ($mode === 'async') {
            $r = (new Dispatcher($root))->dispatch($def, $context, $source);
            return ['ok' => true, 'output' => ['run_id' => (int) ($r['run_id'] ?? 0), 'status' => (string) ($r['status'] ?? 'queued'), 'slug' => $slug],
                    'stdout' => "dispatched {$slug} as run " . (int) ($r['run_id'] ?? 0), 'stderr' => '', 'exit' => 0];
        }

        $r = (new Executor($root))->withChain($chain)->withParentRun($parent)->run($def, $context, $source);
        $ok = (($r['status'] ?? '') === 'completed');
        return [
            'ok'     => $ok,
            'output' => ['run_id' => (int) ($r['run_id'] ?? 0), 'status' => (string) ($r['status'] ?? ''), 'slug' => $slug,
                         'steps_done' => (int) ($r['steps_done'] ?? 0), 'result' => $r['output'] ?? null],
            'stdout' => is_scalar($r['output'] ?? null) ? (string) $r['output'] : json_encode($r['output'] ?? null, JSON_UNESCAPED_SLASHES),
            'stderr' => $ok ? '' : ("pipeline '{$slug}' run " . (int) ($r['run_id'] ?? 0) . ' ' . ($r['status'] ?? '?') . ': ' . (string) ($r['error'] ?? '')),
            'exit'   => $ok ? 0 : 1,
        ];
    }

    private static function fail(string $why): array {
        return ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $why, 'exit' => 1];
    }
}
