<?php
/**
 * codebase_map — lean table of contents for the instance codebase.
 * Names + counts only; call describe()/whatprovides() to drill down.
 */

namespace app\mcptools;

class CodebaseMapTool extends BaseTool {

    public static string $name = 'codebase_map';
    public static string $description = 'Lean map of this codebase: controllers (with route counts), models+tables, lib classes, and config sections. Call this FIRST to orient, then use describe(name) or whatprovides(concept) to drill down. Returns names/counts only — no file contents.';
    public static array $inputSchema = ['type' => 'object', 'properties' => [], 'required' => []];

    public function execute(array $args): string {
        $m = (new Introspector())->map();
        $out  = "# Codebase map\n";
        $out .= "{$m['routeCount']} routes across " . count($m['controllers']) . " controllers, " . count($m['models']) . " models.\n\n";
        $out .= "## Controllers (route = /name/method)\n";
        foreach ($m['controllers'] as $c) $out .= "- {$c['name']} ({$c['routes']} routes)\n";
        $out .= "\n## Models / tables\n";
        foreach ($m['models'] as $md) $out .= "- {$md['name']} → table `{$md['table']}`\n";
        $out .= "\n## Lib classes\n- " . implode(', ', $m['libs']) . "\n";
        $out .= "\n## Config sections\n- " . implode(', ', $m['config']) . "\n";
        $out .= "\nNext: describe(\"<controller|model|lib>\") or whatprovides(\"<concept>\").\n";
        return $out;
    }
}
