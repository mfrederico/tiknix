<?php
/**
 * Pipeline\Executor — run a pipeline against a context, persisting a `piperun` + one
 * `pipesteprun` per step ACTUALLY run (lazy). Walks steps honoring on_success /
 * on_fail ∈ { next | goto:<name> | exit }. Three entry points:
 *   run($def,$ctx,$src)   — sync, creates the run (in-app calls; short pipelines)
 *   resume($runId)        — a Dispatcher-created queued run; execute in the background
 *   continueRun($id,$in)  — resume a paused await_input run, injecting the input
 *
 * A step may return ['await'=>true, 'prompt'=>, 'token'=>] (the wait/await_input
 * step) — the run persists its bag+next-index to stateJson, goes 'paused', and stops
 * until continueRun. The variable bag accumulates context, run built-ins, {time.*},
 * and each finished step's { output, stdout, stderr, exit } under its name + {prev.*}.
 */

namespace app\Pipeline;

use app\Bean;
use RedBeanPHP\R;

class Executor {

    public const MAX_STEPS = 500;   // loop backstop for goto cycles

    private string $root;

    public function __construct(string $root) {
        $this->root = rtrim($root, '/');
    }

    /**
     * Sync run: create the piperun, execute from the start. $extra is merged into the
     * variable bag (durable objects inject state/message/trigger; normal runs pass []).
     */
    public function run(array $def, array $context = [], string $source = 'manual', array $extra = []): array {
        $run = $this->newRun($def, $context, $source, 'running');
        $bag = $this->freshBag($def, $context, $run);
        if ($extra) $bag = array_merge($bag, $extra);
        return $this->execute($def, $run, 0, $bag);
    }

    /** Background: execute a Dispatcher-created 'queued' run to completion. */
    public function resume(int $runId): array {
        $run = R::load('piperun', $runId);
        if (!$run->id) throw new \RuntimeException("run $runId not found");
        $def = (new Loader($this->root))->get((string) $run->slug);
        if (!$def) { $run->status = 'failed'; $run->error = 'definition missing'; Bean::store($run); throw new \RuntimeException('definition missing'); }
        $run->status = 'running'; $run->startedAt = $run->startedAt ?: date('Y-m-d H:i:s'); Bean::store($run);
        $context = json_decode((string) $run->contextJson, true) ?: [];
        $bag = $this->freshBag($def, $context, $run);
        return $this->execute($def, $run, 0, $bag);
    }

    /** Resume a paused await_input run, injecting the supplied input under {input.*}. */
    public function continueRun(int $runId, array $input): array {
        $run = R::load('piperun', $runId);
        if (!$run->id) throw new \RuntimeException("run $runId not found");
        if ($run->status !== 'paused') throw new \RuntimeException("run $runId is not awaiting input (status={$run->status})");
        $def = (new Loader($this->root))->get((string) $run->slug);
        if (!$def) throw new \RuntimeException('definition missing');
        $state = json_decode((string) $run->stateJson, true) ?: [];
        $bag = $state['bag'] ?? $this->freshBag($def, [], $run);
        $bag['input'] = $input;                          // the awaited input
        $bag[(string) $run->awaitStep]['output'] = $input;  // and as the await step's output
        $run->status = 'running'; Bean::store($run);
        return $this->execute($def, $run, (int) ($state['next'] ?? 0), $bag);
    }

    // ---- debug / step-trace ------------------------------------------------

    /** Start a debug run: run the first step, then pause at a breakpoint. */
    public function debugRun(array $def, array $context = [], string $source = 'debug'): array {
        $run = $this->newRun($def, $context, $source, 'running');
        $bag = $this->freshBag($def, $context, $run);
        return $this->execute($def, $run, 0, $bag, true);
    }

    /** Advance a paused debug run by ONE step, first merging $patch into the bag. */
    public function debugStep(int $runId, array $patch = []): array {
        return $this->resumeDebug($runId, $patch, true);
    }

    /** Let a paused debug run finish (merging $patch first); still honors await pauses. */
    public function debugContinueToEnd(int $runId, array $patch = []): array {
        return $this->resumeDebug($runId, $patch, false);
    }

    /** Abort a paused debug run. */
    public function debugAbort(int $runId): array {
        $run = R::load('piperun', $runId);
        if (!$run->id) throw new \RuntimeException("run $runId not found");
        $run->status = 'failed'; $run->error = 'aborted from debugger';
        $run->finishedAt = date('Y-m-d H:i:s'); Bean::store($run);
        return ['run_id' => $runId, 'status' => 'failed', 'error' => 'aborted'];
    }

    private function resumeDebug(int $runId, array $patch, bool $stepMode): array {
        $run = R::load('piperun', $runId);
        if (!$run->id) throw new \RuntimeException("run $runId not found");
        if ($run->status !== 'paused') throw new \RuntimeException("run $runId is not at a breakpoint (status={$run->status})");
        $state = json_decode((string) $run->stateJson, true) ?: [];
        if (($state['kind'] ?? '') !== 'debug') throw new \RuntimeException("run $runId is not a debug breakpoint");
        $def = (new Loader($this->root))->get((string) $run->slug);
        if (!$def) throw new \RuntimeException('definition missing');
        $bag = $state['bag'] ?? $this->freshBag($def, [], $run);
        if ($patch) $bag = self::mergeBag($bag, $patch);
        $run->status = 'running'; Bean::store($run);
        return $this->execute($def, $run, (int) ($state['next'] ?? 0), $bag, $stepMode);
    }

    /** Deep-merge injected data into the variable bag (patch wins; arrays merge by key). */
    private static function mergeBag(array $bag, array $patch): array {
        foreach ($patch as $k => $v) {
            $bag[$k] = (is_array($v) && isset($bag[$k]) && is_array($bag[$k])) ? self::mergeBag($bag[$k], $v) : $v;
        }
        return $bag;
    }

    // ---- core loop ---------------------------------------------------------

    private function execute(array $def, $run, int $i, array $bag, bool $stepMode = false): array {
        $steps = array_values($def['steps'] ?? []);
        $byName = [];
        foreach ($steps as $k => $s) $byName[(string) $s['name']] = $k;
        $runMeta = ['run_id' => (int) $run->id, 'run_uid' => (string) $run->runUid,
                    'run_directory' => (string) $run->runDir, 'root' => $this->root];

        $status = 'completed'; $error = ''; $done = (int) $run->stepsDone; $guard = 0; $lastName = '';

        while ($i < count($steps)) {
            if (++$guard > self::MAX_STEPS) { $status = 'failed'; $error = 'step budget exceeded (goto cycle?)'; break; }
            $step = $steps[$i];
            $name = (string) $step['name'];
            $lastName = $name;

            $res = $this->runStep($step, $bag, $runMeta, (int) $run->id);

            // await_input: persist state, pause, stop.
            if (!empty($res['await'])) {
                $run->status    = 'paused';
                $run->awaitStep = $name;
                $run->awaitPrompt = (string) ($res['prompt'] ?? '');
                $run->stateJson = json_encode(['bag' => $bag, 'next' => $i + 1, 'kind' => 'await'], JSON_UNESCAPED_SLASHES);
                Bean::store($run);
                return ['run_id' => (int) $run->id, 'run_uid' => (string) $run->runUid, 'status' => 'paused',
                        'steps_done' => $done, 'awaiting' => $name, 'prompt' => $run->awaitPrompt];
            }

            $bag[$name] = ['output' => $res['output'], 'stdout' => $res['stdout'], 'stderr' => $res['stderr'], 'exit' => $res['exit']];
            $bag['prev'] = is_array($res['output']) ? $res['output'] : ['value' => $res['output']];
            $done++;
            $run->stepsDone = $done; Bean::store($run);

            $flow = (string) ($step[$res['ok'] ? 'on_success' : 'on_fail'] ?? ($res['ok'] ? 'next' : 'exit'));
            $nextI = $i + 1;
            if ($flow === 'exit' || $flow === '') {
                if (!$res['ok']) { $status = 'failed'; $error = $res['stderr'] ?: "step '$name' failed"; }
                break;
            }
            if (strncmp($flow, 'goto:', 5) === 0) {
                $target = substr($flow, 5);
                if (!isset($byName[$target])) { $status = 'failed'; $error = "goto:$target — no such step"; break; }
                $nextI = $byName[$target];
            }

            // Debug/step mode: pause AFTER each step so the caller can inspect the
            // resolved input + output and inject/override data (the bag) before the
            // next step runs. Resumed via debugStep()/debugContinueToEnd().
            if ($stepMode) {
                $run->status      = 'paused';
                $run->awaitStep   = $name;
                $run->awaitPrompt = '';
                $run->stateJson   = json_encode(['bag' => $bag, 'next' => $nextI, 'kind' => 'debug', 'last' => $name], JSON_UNESCAPED_SLASHES);
                Bean::store($run);
                return ['run_id' => (int) $run->id, 'run_uid' => (string) $run->runUid, 'status' => 'paused',
                        'debug' => true, 'steps_done' => $done, 'last_step' => $name,
                        'next_step' => $steps[$nextI]['name'] ?? null];
            }
            $i = $nextI;
        }

        $run->status     = $status;
        $run->error      = $error;
        $run->finishedAt = date('Y-m-d H:i:s');
        $run->outputJson = json_encode($bag[$lastName]['output'] ?? null, JSON_UNESCAPED_SLASHES);
        Bean::store($run);
        return ['run_id' => (int) $run->id, 'run_uid' => (string) $run->runUid, 'status' => $status,
                'steps_done' => $done, 'error' => $error, 'output' => $bag[$lastName]['output'] ?? null];
    }

    /** Resolve variables, dispatch to the step type, persist the step-run. */
    private function runStep(array $step, array $bag, array $runMeta, int $runId): array {
        $name = (string) $step['name'];
        $type = (string) ($step['type'] ?? '');
        $handler = StepRegistry::get($type);

        $sr = Bean::dispense('pipesteprun');
        $sr->runId = $runId; $sr->stepName = $name; $sr->stepType = $type;
        $sr->status = 'running'; $sr->startedAt = date('Y-m-d H:i:s');
        Bean::store($sr);

        $t0 = microtime(true);
        if (!$handler) {
            $res = ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => "unknown step type '$type'", 'exit' => 1];
        } else {
            $config = Vars::resolve((array) ($step['config'] ?? []), $bag);
            $sr->inputJson = json_encode($config, JSON_UNESCAPED_SLASHES);
            try { $res = $handler->run($config, $runMeta); }
            catch (\Throwable $e) { $res = ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => $e->getMessage(), 'exit' => 1]; }
        }
        $res += ['ok' => false, 'output' => null, 'stdout' => '', 'stderr' => '', 'exit' => 1, 'await' => false];

        $sr->status     = !empty($res['await']) ? 'awaiting' : ($res['ok'] ? 'completed' : 'failed');
        $sr->outputJson = json_encode($res['output'], JSON_UNESCAPED_SLASHES);
        $sr->stdout     = mb_substr((string) $res['stdout'], 0, 65535);
        $sr->stderr     = mb_substr((string) $res['stderr'], 0, 65535);
        $sr->exitCode   = (int) $res['exit'];
        $sr->durationMs = (int) round((microtime(true) - $t0) * 1000);
        $sr->finishedAt = date('Y-m-d H:i:s');
        Bean::store($sr);
        return $res;
    }

    // ---- run + bag setup ---------------------------------------------------

    /** Create a new piperun bean (status set by caller: 'running' sync, 'queued' dispatched). */
    public function newRun(array $def, array $context, string $source, string $status): object {
        $slug = (string) ($def['slug'] ?? 'pipeline');
        $uid  = bin2hex(random_bytes(12));
        $dir  = sys_get_temp_dir() . '/tiknix-pipe/' . $slug . '/' . $uid;
        @mkdir($dir, 0775, true);
        $run = Bean::dispense('piperun');
        $run->slug = $slug; $run->runUid = $uid; $run->status = $status; $run->source = $source;
        $run->contextJson = json_encode($context, JSON_UNESCAPED_SLASHES);
        $run->stepsTotal = count($def['steps'] ?? []); $run->stepsDone = 0;
        $run->runDir = $dir; $run->createdAt = date('Y-m-d H:i:s');
        if ($status === 'running') $run->startedAt = date('Y-m-d H:i:s');
        Bean::store($run);
        return $run;
    }

    private function freshBag(array $def, array $context, $run): array {
        return [
            'context'       => $context,
            'time'          => Vars::timeBag(),
            'run_id'        => (int) $run->id,
            'run_uid'       => (string) $run->runUid,
            'run_directory' => (string) $run->runDir,
            'pipeline_slug' => (string) $run->slug,
        ];
    }
}
