<?php
/**
 * reuse_digest — the pre-baked "what already exists, reuse it" inventory.
 *
 * Same data the AI Builder planner is fed at decomposition time, exposed as an
 * MCP tool so ANY agent working inside an instance (a build task, an interactive
 * workbench session, a manual claude session) can pull the exact reuse surface
 * deterministically instead of piecing it together from codebase_map + repeated
 * describe() calls. Controllers+levels, models+columns+relations, lib services,
 * authcontrol wildcards, config sections, and the seeder inventory — in one call.
 */

namespace app\mcptools;

class ReuseDigestTool extends BaseTool {

    public static string $name = 'reuse_digest';
    public static string $description = 'Compact, token-bounded inventory of everything that already exists in THIS codebase (controllers with permission levels, models with columns+relations, lib services with methods, authcontrol wildcards, config sections, seeders). Call this to REUSE existing primitives instead of reinventing them — it is the pre-baked ground truth the planner uses. Drill into any single item with describe("<name>").';
    public static array $inputSchema = ['type' => 'object', 'properties' => [], 'required' => []];

    public function execute(array $args): string {
        $digest = (new Introspector())->digest();
        return "# Reuse digest — existing primitives (reuse before building)\n\n" . $digest . "\n";
    }
}
