<?php
/**
 * pipeline_continue — resume a run that paused at a wait/await_input step, supplying
 * the input. The input arrives in the pipeline as {input.*} and as the await step's
 * output, and the run continues from the next step.
 */

namespace app\mcptools;

use app\Pipeline\Runner;

class PipelineContinueTool extends BaseTool {

    public static string $name = 'pipeline_continue';
    public static string $description = 'Resume a paused (await_input) run with the awaited input. Returns the run summary (may complete, fail, or pause again).';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'run_id' => ['type' => 'integer'],
            'input'  => ['type' => 'object', 'description' => 'the awaited input, available as {input.*}'],
        ],
        'required' => ['run_id'],
    ];

    public function execute(array $args): string {
        // Resuming a paused run executes further steps with the install's stored
        // credentials — same privilege as pipeline_run; gate it the same way.
        $this->requireAdmin();
        $runId = (int) ($args['run_id'] ?? 0);
        $input = (array) ($args['input'] ?? []);
        try {
            $r = Runner::continueRun($runId, $input);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage());
        }
        return json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
