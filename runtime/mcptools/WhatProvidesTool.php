<?php
/**
 * whatprovides — concept → ranked pointers across all primitives.
 */

namespace app\mcptools;

class WhatProvidesTool extends BaseTool {

    public static string $name = 'whatprovides';
    public static string $description = 'Find everything that provides a concept (e.g. "auth", "email", "checkpoints"). Returns the top ranked controllers, routes, models, lib classes, and config sections as path:line pointers with a one-line reason — use this instead of grepping the whole codebase.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => ['concept' => ['type' => 'string', 'description' => 'A capability or concept, e.g. "auth", "two factor", "mailer", "permissions"']],
        'required' => ['concept'],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $hits = (new Introspector())->whatprovides((string)$args['concept']);
        if (!$hits) return "Nothing matched \"" . $args['concept'] . "\". Try a broader term or codebase_map.";
        $out = "# What provides \"" . $args['concept'] . "\"\n";
        foreach ($hits as $h) {
            $loc = $h['path'] . ($h['line'] ? ':' . $h['line'] : '');
            $out .= "- [{$h['kind']}] {$h['name']}  ({$loc})  — {$h['why']}\n";
        }
        $out .= "\nDrill down with describe(\"<name>\") or Read the file at the pointer.\n";
        return $out;
    }
}
