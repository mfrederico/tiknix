<?php
/**
 * pipeline_get — the full definition of one pipeline (the file's JSON).
 */

namespace app\mcptools;

use app\Pipeline\Runner;

class PipelineGetTool extends BaseTool {

    public static string $name = 'pipeline_get';
    public static string $description = 'Get the full definition of one pipeline by slug (name, steps, exposure, trigger).';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => ['slug' => ['type' => 'string']],
        'required' => ['slug'],
    ];

    public function execute(array $args): string {
        $def = Runner::get((string) ($args['slug'] ?? ''));
        if (!$def) throw new \Exception("pipeline '" . ($args['slug'] ?? '') . "' not found");
        return json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
