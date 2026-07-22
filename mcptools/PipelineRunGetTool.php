<?php
/**
 * pipeline_run_get — a run's status + each step's result (from the run-history DB).
 */

namespace app\mcptools;

use RedBeanPHP\R;

class PipelineRunGetTool extends BaseTool {

    public static string $name = 'pipeline_run_get';
    public static string $description = 'Get a pipeline run: its status + each step-run (type, status, exit, duration, output).';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => ['run_id' => ['type' => 'integer']],
        'required' => ['run_id'],
    ];

    public function execute(array $args): string {
        $runId = (int) ($args['run_id'] ?? 0);
        $run = R::load('piperun', $runId);
        if (!$run->id) throw new \Exception("run $runId not found");
        $steps = [];
        foreach (R::find('pipesteprun', 'run_id = ? ORDER BY id', [$runId]) as $sr) {
            $steps[] = [
                'step'     => $sr->stepName,
                'type'     => $sr->stepType,
                'status'   => $sr->status,
                'exit'     => (int) $sr->exitCode,
                'duration_ms' => (int) $sr->durationMs,
                'output'   => json_decode((string) $sr->outputJson, true),
            ];
        }
        return json_encode([
            'run_id'      => (int) $run->id,
            'slug'        => $run->slug,
            'status'      => $run->status,
            'source'      => $run->source,
            'steps_total' => (int) $run->stepsTotal,
            'steps_done'  => (int) $run->stepsDone,
            'error'       => (string) $run->error,
            'output'      => json_decode((string) $run->outputJson, true),
            'steps'       => $steps,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
