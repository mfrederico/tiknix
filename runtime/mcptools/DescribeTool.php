<?php
/**
 * describe — detail for one primitive (controller | model | lib), as pointers.
 */

namespace app\mcptools;

class DescribeTool extends BaseTool {

    public static string $name = 'describe';
    public static string $description = 'Describe one primitive by name: a controller (its routes/methods + permission levels), a model (table columns + inferred relations), or a lib class (public methods). Returns path:line pointers, not file contents — Read the file for bodies.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string', 'description' => 'Controller, model/bean, or lib class name (e.g. "Auth", "member", "TwoFactorAuth")']],
        'required' => ['name'],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $matches = (new Introspector())->describe((string)$args['name']);
        if (!$matches) return "No controller, model, or lib class named \"" . $args['name'] . "\". Try codebase_map or whatprovides.";

        $out = '';
        foreach ($matches as $d) {
            if ($d['kind'] === 'controller') {
                $out .= "# Controller {$d['name']}  ({$d['path']}:{$d['line']})\n## Routes\n";
                foreach ($d['routes'] as $r) {
                    $lvl = $r['level'] === null ? '' : "  [level {$r['level']}]";
                    $out .= "- {$r['route']}{$lvl}  ({$d['path']}:{$r['line']})\n";
                }
            } elseif ($d['kind'] === 'model') {
                $out .= "# Model {$d['name']}  → table `{$d['table']}`  ({$d['path']})\n## Columns\n";
                foreach ($d['columns'] as $c) $out .= "- {$c['name']} {$c['type']}\n";
                if ($d['relations']) { $out .= "## Relations (inferred from *_id)\n"; foreach ($d['relations'] as $r) $out .= "- belongs to `{$r['belongsTo']}` via {$r['via']}\n"; }
            } else {
                $out .= "# Lib {$d['name']}  ({$d['path']})\n## Public methods\n- " . implode("\n- ", $d['methods'] ?: ['(none)']) . "\n";
            }
            $out .= "\n";
        }
        return rtrim($out) . "\n";
    }
}
