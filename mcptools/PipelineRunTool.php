<?php
/**
 * pipeline_run — run a pipeline by slug with a context, returning the run summary
 * (run_id, status, output). Synchronous in Phase 2; a later phase runs long
 * pipelines async and you poll pipeline_run_get.
 */

namespace app\mcptools;

use app\Pipeline\Runner;

class PipelineRunTool extends BaseTool {

    public static string $name = 'pipeline_run';
    public static string $description = 'Run a pipeline by slug with a context object. Runs in the BACKGROUND by default (returns { run_id, status:"queued" } — poll pipeline_run_get); pass wait:true to run synchronously and get the output inline (only for short pipelines).';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug'    => ['type' => 'string'],
            'context' => ['type' => 'object', 'description' => 'input params referenced as {context.x}'],
            'wait'    => ['type' => 'boolean', 'description' => 'run synchronously and return the output (default false = background)'],
        ],
        'required' => ['slug'],
    ];

    public function execute(array $args): string {
        $slug = (string) ($args['slug'] ?? '');
        $ctx  = (array) ($args['context'] ?? []);
        try {
            $r = !empty($args['wait']) ? Runner::run($slug, $ctx, 'mcp') : Runner::dispatch($slug, $ctx, 'mcp');
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage());
        }
        return json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
