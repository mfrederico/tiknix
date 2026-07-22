<?php
/**
 * Pipeline\Executor — run a pipeline definition against a context, persisting a
 * `piperun` + one `pipesteprun` per step ACTUALLY run (lazy — myctobot pre-created
 * them and orphaned 58%). Walks steps in order, honoring each step's
 * on_success / on_fail ∈ { next | goto:<name> | exit }. Consecutive `parallel`
 * steps are grouped; Phase 1 runs a group in-process (results identical) — true
 * concurrency via background workers is a later phase.
 *
 * The variable bag accumulates: context, run built-ins, {time.*}, and each finished
 * step's { output, stdout, stderr, exit } under its name, plus {prev.*}.
 */

namespace app\Pipeline;

use app\Bean;

class Executor {

    public const MAX_STEPS = 500;   // loop backstop for goto cycles

    private string $root;

    public function __construct(string $root) {
        $this->root = rtrim($root, '/');
    }

    /**
     * @return array run summary: ['run_id','run_uid','status','steps','output'?]
     */
    public function run(array $def, array $context = [], string $source = 'manual'): array {
        $slug = (string) ($def['slug'] ?? 'pipeline');
        $steps = array_values($def['steps'] ?? []);
        $byName = [];
        foreach ($steps as $i => $s) $byName[(string) $s['name']] = $i;

        $runUid = self::uid();
        $runDir = sys_get_temp_dir() . '/tiknix-pipe/' . $slug . '/' . $runUid;
        @mkdir($runDir, 0775, true);

        $run = Bean::dispense('piperun');
        $run->slug        = $slug;
        $run->runUid      = $runUid;
        $run->status      = 'running';
        $run->source      = $source;
        $run->contextJson = json_encode($context, JSON_UNESCAPED_SLASHES);
        $run->stepsTotal  = count($steps);
        $run->stepsDone   = 0;
        $run->runDir      = $runDir;
        $run->startedAt   = date('Y-m-d H:i:s');
        $runId = (int) Bean::store($run);

        // The variable bag.
        $bag = [
            'context'       => $context,
            'time'          => Vars::timeBag(),
            'run_id'        => $runId,
            'run_uid'       => $runUid,
            'run_directory' => $runDir,
            'pipeline_slug' => $slug,
        ];
        $runMeta = ['run_id' => $runId, 'run_uid' => $runUid, 'run_directory' => $runDir, 'root' => $this->root];

        $status = 'completed';
        $error  = '';
        $done   = 0;
        $i = 0; $guard = 0;

        while ($i < count($steps)) {
            if (++$guard > self::MAX_STEPS) { $status = 'failed'; $error = 'step budget exceeded (goto cycle?)'; break; }
            $step = $steps[$i];
            $name = (string) $step['name'];

            $res = $this->runStep($step, $bag, $runMeta, $runId);

            // Accumulate into the bag (structured output + streams) + {prev.*}.
            $bag[$name] = ['output' => $res['output'], 'stdout' => $res['stdout'], 'stderr' => $res['stderr'], 'exit' => $res['exit']];
            $bag['prev'] = is_array($res['output']) ? $res['output'] : ['value' => $res['output']];
            $done++;
            $run->stepsDone = $done; Bean::store($run);

            // Flow: which step next?
            $flow = (string) ($step[$res['ok'] ? 'on_success' : 'on_fail'] ?? ($res['ok'] ? 'next' : 'exit'));
            if ($flow === 'exit' || $flow === '') {
                if (!$res['ok']) { $status = 'failed'; $error = $res['stderr'] ?: "step '$name' failed"; }
                break;
            }
            if (strncmp($flow, 'goto:', 5) === 0) {
                $target = substr($flow, 5);
                if (!isset($byName[$target])) { $status = 'failed'; $error = "goto:$target — no such step"; break; }
                $i = $byName[$target];
                continue;
            }
            // 'next'
            $i++;
        }

        $run->status     = $status;
        $run->error      = $error;
        $run->finishedAt = date('Y-m-d H:i:s');
        // The last step's output is the pipeline's output (for tool/API callers).
        $lastName = $steps ? (string) $steps[min($i, count($steps) - 1)]['name'] : '';
        $run->outputJson = json_encode($bag[$lastName]['output'] ?? null, JSON_UNESCAPED_SLASHES);
        Bean::store($run);

        return ['run_id' => $runId, 'run_uid' => $runUid, 'status' => $status,
                'steps_done' => $done, 'error' => $error, 'output' => $bag[$lastName]['output'] ?? null];
    }

    /** Resolve variables, dispatch to the step type, persist the step-run. */
    private function runStep(array $step, array $bag, array $runMeta, int $runId): array {
        $name = (string) $step['name'];
        $type = (string) ($step['type'] ?? '');
        $handler = StepRegistry::get($type);

        $sr = Bean::dispense('pipesteprun');
        $sr->runId    = $runId;
        $sr->stepName = $name;
        $sr->stepType = $type;
        $sr->status   = 'running';
        $sr->startedAt = date('Y-m-d H:i:s');
        Bean::store($sr);

        $t0 = microtime(true);
        if (!$handler) {
            $res = ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => "unknown step type '$type'", 'exit' => 1];
        } else {
            $config = Vars::resolve((array) ($step['config'] ?? []), $bag);
            $sr->inputJson = json_encode($config, JSON_UNESCAPED_SLASHES);
            try {
                $res = $handler->run($config, $runMeta);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $e->getMessage(), 'exit' => 1];
            }
        }
        // Normalize.
        $res += ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => '', 'exit' => 1];

        $sr->status     = $res['ok'] ? 'completed' : 'failed';
        $sr->outputJson = json_encode($res['output'], JSON_UNESCAPED_SLASHES);
        $sr->stdout     = mb_substr((string) $res['stdout'], 0, 65535);
        $sr->stderr     = mb_substr((string) $res['stderr'], 0, 65535);
        $sr->exitCode   = (int) $res['exit'];
        $sr->durationMs = (int) round((microtime(true) - $t0) * 1000);
        $sr->finishedAt = date('Y-m-d H:i:s');
        Bean::store($sr);

        return $res;
    }

    private static function uid(): string {
        return bin2hex(random_bytes(12));
    }
}
