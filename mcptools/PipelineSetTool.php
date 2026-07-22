<?php
/**
 * pipeline_set — create or update a WHOLE pipeline (with its steps) in one call,
 * writing the versioned file `pipelines/<slug>.json` in this instance's repo. Set
 * dry_run to validate without writing. This + pipeline_run is the agent's build loop.
 *
 * Writing a pipeline is editing the instance's code → ADMIN only.
 */

namespace app\mcptools;

use app\Pipeline\Loader;
use app\Pipeline\Runner;

class PipelineSetTool extends BaseTool {

    public static string $name = 'pipeline_set';
    public static string $description = 'Create/update a whole pipeline (with steps[]) as a versioned file. Pass dry_run:true to validate only. Each step: {name, type, config, on_success?, on_fail?}. Get valid types + schemas from pipeline_components first.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug'           => ['type' => 'string', 'description' => 'url-safe id (lowercase, hyphens)'],
            'name'           => ['type' => 'string'],
            'description'    => ['type' => 'string'],
            'context_schema' => ['type' => 'object', 'description' => 'input params: name => {type, required}'],
            'expose_as_tool' => ['type' => 'boolean', 'description' => 'register on this instance\'s /mcp as a callable tool'],
            'expose_as_api'  => ['type' => 'boolean', 'description' => 'expose as a REST endpoint (per-member api key)'],
            'trigger'        => ['type' => 'object', 'description' => 'optional { cron: "..." } or { webhook: true }'],
            'steps'          => ['type' => 'array', 'description' => 'ordered steps: [{name, type, config, on_success, on_fail}]',
                'items' => ['type' => 'object']],
            'dry_run'        => ['type' => 'boolean', 'description' => 'validate only, do not write'],
        ],
        'required' => ['slug', 'steps'],
    ];

    public function execute(array $args): string {
        $def = [
            'slug'        => (string) ($args['slug'] ?? ''),
            'name'        => (string) ($args['name'] ?? ($args['slug'] ?? '')),
            'description' => (string) ($args['description'] ?? ''),
            'steps'       => array_values((array) ($args['steps'] ?? [])),
        ];
        foreach (['context_schema', 'trigger'] as $k) if (isset($args[$k]) && is_array($args[$k])) $def[$k] = $args[$k];
        foreach (['expose_as_tool', 'expose_as_api'] as $k) if (isset($args[$k])) $def[$k] = (bool) $args[$k];

        $errors = Loader::validate($def);
        if ($errors) {
            return json_encode(['ok' => false, 'valid' => false, 'errors' => $errors], JSON_PRETTY_PRINT);
        }
        if (!empty($args['dry_run'])) {
            return json_encode(['ok' => true, 'valid' => true, 'dry_run' => true, 'slug' => $def['slug'], 'steps' => count($def['steps'])], JSON_PRETTY_PRINT);
        }

        $this->requireAdmin();   // writing a pipeline = editing the instance's code
        $file = (new Loader(Runner::root()))->save($def);
        return json_encode(['ok' => true, 'valid' => true, 'saved' => true, 'slug' => $def['slug'],
            'file' => 'pipelines/' . $def['slug'] . '.json', 'steps' => count($def['steps'])], JSON_PRETTY_PRINT);
    }
}
