<?php
/**
 * pipeline_delete — remove a pipeline file. Deleting instance code → ADMIN only.
 */

namespace app\mcptools;

use app\Pipeline\Loader;
use app\Pipeline\Runner;

class PipelineDeleteTool extends BaseTool {

    public static string $name = 'pipeline_delete';
    public static string $description = 'Delete a pipeline by slug (removes its file).';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => ['slug' => ['type' => 'string']],
        'required' => ['slug'],
    ];

    public function execute(array $args): string {
        $this->requireAdmin();
        $slug = (string) ($args['slug'] ?? '');
        $ok = (new Loader(Runner::root()))->delete($slug);
        return json_encode(['ok' => $ok, 'slug' => $slug, 'deleted' => $ok], JSON_PRETTY_PRINT);
    }
}
