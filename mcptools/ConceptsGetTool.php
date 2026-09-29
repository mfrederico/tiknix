<?php
/**
 * concepts_get — one catalog concept in detail: its manifest and its file list.
 *
 * Enough to decide whether to ADOPT it and to plan the adaptation — which slots it fills,
 * which beans it owns and which it borrows, what it needs from core. It returns pointers
 * (paths and sizes), not file bodies: install it to read the code.
 */

namespace app\mcptools;

use app\ConceptCatalog;
use app\ConceptException;

class ConceptsGetTool extends BaseTool {

    public static string $name = 'concepts_get';
    public static string $description = 'Full detail for one concept in the shared catalog: its manifest (requires, provides, the slots it fills or hosts, beans it owns vs borrows) and its guidelines (how to use it), and its file list with sizes. Use after concepts_search to decide whether to ADOPT a concept and to plan how it must be adapted. Returns pointers, not file contents — install it (clitool --concept-install=<name>) to read the code.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string', 'description' => 'The concept name, exactly as concepts_search returned it.']],
        'required' => ['name'],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $name = trim((string) $args['name']);
        try {
            $c = ConceptCatalog::forInstall()->get($name);
        } catch (ConceptException $e) {
            return "# concepts_get(\"{$name}\") FAILED\n\n" . $e->getMessage() . "\n";
        }
        $bytes = array_sum(array_column($c['files'], 'bytes'));
        $out  = "# {$c['name']} v{$c['version']}" . ($c['title'] !== '' ? " — {$c['title']}" : '') . "\n_catalog: {$c['source']}_\n\n";
        if ($c['blurb'] !== '') $out .= "{$c['blurb']}\n\n";
        // The concept's own rules for agents — what an ADOPT decision should be made on.
        if (($c['guidelines'] ?? '') !== '') $out .= "## How to use it (guidelines.md)\n\n{$c['guidelines']}\n\n";
        $out .= "## Manifest\n```json\n" . json_encode($c['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
        $out .= '## Files (' . count($c['files']) . ", {$bytes} bytes)\n";
        foreach ($c['files'] as $f) $out .= "- {$f['path']}  ({$f['bytes']})\n";
        $out .= "\nInstalls to `concepts/{$c['name']}/` with namespace `app\\concepts\\{$c['name']}\\`.\n";
        return $out;
    }
}
