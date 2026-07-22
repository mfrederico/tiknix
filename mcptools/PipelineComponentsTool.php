<?php
/**
 * pipeline_components — what an agent can build a pipeline FROM: the step types +
 * their config schemas (one registry, no drift). Phase 3 adds this instance's
 * connections so the agent knows what it can wire. Read-only; call this first.
 */

namespace app\mcptools;

use app\Pipeline\StepRegistry;

class PipelineComponentsTool extends BaseTool {

    public static string $name = 'pipeline_components';
    public static string $description = 'List the pipeline step types you can build with, each with its config schema. Call FIRST, then pipeline_set to create/update a pipeline. Flow per step: on_success/on_fail = "next" | "goto:<step>" | "exit". Variables: {context.x}, {<step>.output.path}, {prev.x}, {time.*}.';
    public static array $inputSchema = ['type' => 'object', 'properties' => [], 'required' => []];

    public function execute(array $args): string {
        return json_encode([
            'step_types' => StepRegistry::components(),
            'flow'       => ['on_success/on_fail' => ['next', 'goto:<step_name>', 'exit']],
            'variables'  => ['{context.x}', '{<step>.output.path}', '{<step>.stdout}', '{prev.x}', '{time.now|date|ts}', '{run_id}', '{run_uid}'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
