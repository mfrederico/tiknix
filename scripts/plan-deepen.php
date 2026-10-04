#!/usr/bin/env php
<?php
/**
 * plan-deepen.php — the deeper planning passes, between the planner's first run and the ingest.
 *
 * The planner's first plan is a draft (*.plan.draft.json) when the planning depth is not "off".
 * This runs PlanRunner::refine(): when the planner marked tasks `complex` (or the depth is
 * "always") a second pass splits them and a third re-checks dependencies and priorities; the
 * result is written as the final *.plan.json, which the runner then ingests. No draft means
 * the first run produced no plan (the goal is already met, or it failed) — nothing to do here,
 * and the runner's own checks say which.
 *
 *   php scripts/plan-deepen.php --slug=S --dir=WORKSPACE --member=N --level=50 --engine=claude --agent=NAME --mode=flagged
 */
if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require_once __DIR__ . '/../vendor/autoload.php';
new \app\Bootstrap();

$o = getopt('', ['slug:', 'dir:', 'member:', 'level::', 'engine::', 'agent::', 'mode::']);
foreach (['slug', 'dir', 'member'] as $k) if (empty($o[$k])) { fwrite(STDERR, "ERROR --{$k} is required\n"); exit(2); }
try {
    $runner = new \app\PlanRunner((string) $o['slug'], (string) $o['dir'], (int) $o['member'], (int) ($o['level'] ?? 50), (string) ($o['engine'] ?? 'claude'));
    $runner->useAgent((string) ($o['agent'] ?? ''))->deepen((string) ($o['mode'] ?? 'flagged'));
    if ($runner->draftFile() === null) { echo "[planner] no draft plan — nothing to deepen\n"; exit(0); }
    $runner->refine();
} catch (\Throwable $e) {
    fwrite(STDERR, '[planner] ERROR deeper planning could not run: ' . $e->getMessage() . "\n");
    exit(1);
}
