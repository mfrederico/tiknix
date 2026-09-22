<?php
/**
 * Pipeline\Trace — a run as the editor and the debugger see it.
 *
 * One shape for "what happened in this run", read by both doors: the machine endpoints
 * in controls/Pipeline.php (bearer = trigger_secret; cron, webhooks, the retired sidecar)
 * and the in-app editor in controls/Pipelines.php (a signed-in ADMIN). Two controllers
 * building the same arrays would drift the moment one gained a field.
 */

namespace app\Pipeline;

use app\Bean;

class Trace {

    /**
     * A run and its step rows, for polling: status, error, output, each step's status,
     * exit, duration, stderr and output. Null when there is no such run.
     */
    public static function runStatus(int $runId): ?array {
        $run = Bean::load('piperun', $runId);
        if (!$run->id) return null;
        $steps = [];
        foreach (Bean::find('pipesteprun', 'run_id = ? ORDER BY id', [$runId]) as $s) {
            $steps[] = ['step' => (string) $s->stepName, 'type' => (string) $s->stepType, 'status' => (string) $s->status,
                'exit' => (int) $s->exitCode, 'duration_ms' => (int) $s->durationMs,
                'stdout' => (string) $s->stdout, 'stderr' => (string) $s->stderr,
                'output' => json_decode((string) $s->outputJson, true)];
        }
        return ['run_id' => (int) $run->id, 'slug' => (string) $run->slug, 'status' => (string) $run->status,
            'source' => (string) $run->source, 'parent_run_id' => (int) ($run->parentRunId ?? 0),
            'steps_total' => (int) $run->stepsTotal, 'steps_done' => (int) $run->stepsDone,
            'error' => (string) $run->error, 'await_prompt' => (string) ($run->awaitPrompt ?? ''),
            'output' => json_decode((string) $run->outputJson, true), 'steps' => $steps];
    }

    /**
     * The debugger's view at a breakpoint: every step so far with its RESOLVED input and
     * output, the live bag while paused, and where the run is. $r is what Runner::debug*
     * returned (last/next step).
     */
    public static function breakpoint(int $runId, array $r): array {
        $run = Bean::load('piperun', $runId);
        $steps = [];
        foreach (Bean::find('pipesteprun', 'run_id = ? ORDER BY id', [$runId]) as $s) {
            $steps[] = ['step' => (string) $s->stepName, 'type' => (string) $s->stepType, 'status' => (string) $s->status,
                'input' => json_decode((string) $s->inputJson, true), 'output' => json_decode((string) $s->outputJson, true),
                'stdout' => (string) $s->stdout, 'stderr' => (string) $s->stderr,
                'exit' => (int) $s->exitCode, 'duration_ms' => (int) $s->durationMs];
        }
        $state = json_decode((string) $run->stateJson, true) ?: [];
        return [
            'run_id'      => $runId,
            'status'      => (string) $run->status,
            'debug'       => ($state['kind'] ?? '') === 'debug' && $run->status === 'paused',
            'steps_total' => (int) $run->stepsTotal,
            'steps_done'  => (int) $run->stepsDone,
            'error'       => (string) $run->error,
            'output'      => json_decode((string) $run->outputJson, true),
            'last_step'   => $r['last_step'] ?? ($state['last'] ?? null),
            'next_step'   => $r['next_step'] ?? null,
            'bag'         => $run->status === 'paused' ? ($state['bag'] ?? null) : null,
            'steps'       => $steps,
        ];
    }

    /**
     * Shape-only (keys and types, never values) of the latest run's step outputs, for the
     * editor's `{` autocomplete. Team-shared and durable, so no value crosses it: a short
     * sample would still leak short PII. The person debugging sees real values in their
     * own live trace.
     *
     * @return array{ok:bool,run_id:int,shapes:array<string,array>}
     */
    public static function varShapes(string $slug): array {
        $run = Bean::findOne('piperun', 'slug = ? ORDER BY id DESC', [$slug]);
        $shapes = [];
        if ($run && $run->id) {
            foreach (Bean::find('pipesteprun', 'run_id = ? ORDER BY id', [(int) $run->id]) as $s) {
                $name = (string) $s->stepName;
                if ($name === '') continue;
                $shapes[$name] = self::shapeOf(json_decode((string) $s->outputJson, true), 0);
            }
        }
        return ['ok' => true, 'run_id' => $run && $run->id ? (int) $run->id : 0, 'shapes' => $shapes];
    }

    public static function shapeOf($v, int $depth): array {
        if ($depth > 6) return ['t' => 'deep'];
        if (is_array($v)) {
            if ($v !== [] && array_keys($v) === range(0, count($v) - 1)) {
                return ['t' => 'array', 'n' => count($v), 'of' => self::shapeOf($v[0], $depth + 1)];
            }
            $keys = []; $i = 0;
            foreach ($v as $k => $vv) {
                if (++$i > 60) { $keys['…'] = ['t' => 'more']; break; }
                $keys[(string) $k] = self::shapeOf($vv, $depth + 1);
            }
            return ['t' => 'object', 'keys' => $keys];
        }
        return ['t' => is_bool($v) ? 'bool' : (is_int($v) ? 'int' : (is_float($v) ? 'float' : (is_null($v) ? 'null' : 'string')))];
    }
}
