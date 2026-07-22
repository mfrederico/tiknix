<?php
/**
 * pipeline_list — the instance's pipelines: slug, name, step count, exposure flags.
 */

namespace app\mcptools;

use app\Pipeline\Runner;

class PipelineListTool extends BaseTool {

    public static string $name = 'pipeline_list';
    public static string $description = 'List this instance\'s pipelines (slug, name, step count, expose_as_tool/api). Use pipeline_get for the full definition.';
    public static array $inputSchema = ['type' => 'object', 'properties' => [], 'required' => []];

    public function execute(array $args): string {
        $out = [];
        foreach (Runner::list() as $slug => $def) {
            $out[] = [
                'slug'           => $slug,
                'name'           => (string) ($def['name'] ?? $slug),
                'steps'          => count($def['steps'] ?? []),
                'expose_as_tool' => (bool) ($def['expose_as_tool'] ?? false),
                'expose_as_api'  => (bool) ($def['expose_as_api'] ?? false),
                'trigger'        => $def['trigger'] ?? null,
            ];
        }
        return json_encode(['pipelines' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
