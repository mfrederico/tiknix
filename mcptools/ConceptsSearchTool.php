<?php
/**
 * concepts_search — find a ready-made, installable feature before building one.
 *
 * reuse_digest answers "what already exists in THIS codebase". This answers the next
 * question: "has somebody already built it, anywhere?" The catalog holds concepts extracted
 * from real instances — tickets, calendars, booking slots, profiles — as self-contained
 * directories that install into concepts/<name>/ and switch on with a flag.
 *
 * On the control plane this reads the local catalog; on an instance it asks the control
 * plane with the instance's broker key. A catalog that cannot be reached is reported as a
 * FAILURE — never as "no matches", which would quietly turn every ADOPT into a NEW.
 */

namespace app\mcptools;

use app\ConceptCatalog;
use app\ConceptException;

class ConceptsSearchTool extends BaseTool {

    public static string $name = 'concepts_search';
    public static string $description = 'Search the shared catalog of ready-made, installable features ("concepts") by capability, in plain words — e.g. "add to calendar", "qr tickets check-in", "bookable time slots", "staff profiles". Call this AFTER reuse_digest and BEFORE classifying a capability as NEW: a match can be ADOPTED (installed into concepts/<name>/ and adapted) instead of built from scratch. Returns ranked matches with what each provides and requires. An empty query lists everything. Inspect one with concepts_get("<name>").';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'The capability you need, in plain words. Empty lists the whole catalog.'],
            'limit' => ['type' => 'integer', 'description' => 'Maximum results (default 10).'],
        ],
        'required' => [],
    ];

    public function execute(array $args): string {
        $query = trim((string) ($args['query'] ?? ''));
        try {
            $found = ConceptCatalog::forInstall()->search($query, (int) ($args['limit'] ?? 10));
        } catch (ConceptException $e) {
            return "# concepts_search FAILED — this is NOT an empty result\n\n" . $e->getMessage()
                 . "\n\nDo not conclude that nothing exists. Report that the catalog could not be reached.\n";
        }

        $out = '# Concepts matching ' . ($query === '' ? '(everything)' : "\"{$query}\"") . "\n_catalog: {$found['source']}_\n\n";
        if (!$found['results']) {
            $out .= "No concept matched. The catalog WAS reached, so this is a real \"nothing fits\" — classify as NEW and say so.\n";
        }
        foreach ($found['results'] as $r) {
            $out .= "## {$r['name']} v{$r['version']}" . ($r['title'] !== '' ? " — {$r['title']}" : '') . "  (score {$r['score']})\n";
            if ($r['blurb'] !== '') $out .= "{$r['blurb']}\n";
            $bits = [];
            if ($r['capabilities'])             $bits[] = 'capabilities: ' . implode(', ', $r['capabilities']);
            if ($r['provides']['controllers'])  $bits[] = 'controllers: ' . implode(', ', $r['provides']['controllers']);
            if ($r['provides']['beans'])        $bits[] = 'owns beans: ' . implode(', ', $r['provides']['beans']);
            if ($r['uses_beans'])               $bits[] = 'uses beans: ' . implode(', ', $r['uses_beans']);
            if ($r['requires']['concepts'])     $bits[] = 'requires concepts: ' . implode(', ', $r['requires']['concepts']);
            if ($r['requires']['lib'])          $bits[] = 'requires core: ' . implode(', ', $r['requires']['lib']);
            if ($r['fills_slots'])              $bits[] = 'fills slots: ' . implode(', ', $r['fills_slots']);
            if ($r['hosts_slots'])              $bits[] = 'hosts slots: ' . implode(', ', $r['hosts_slots']);
            foreach ($bits as $b) $out .= "- {$b}\n";
            $out .= "\n";
        }
        foreach ($found['broken'] as $name => $why) {
            $out .= "> catalog entry `{$name}` is BROKEN and was skipped: {$why}\n";
        }
        if ($found['results']) {
            $out .= "\nTo adopt one: `php scripts/clitool.php --concept-install=<name>`, then `--concept-verify=<name>` and `--concept-enable=<name>`. "
                  . "It is copied in and becomes this install's own code — adapt it freely.\n";
        }
        return $out;
    }
}
