#!/usr/bin/env php
<?php
/**
 * concept-install.php — queue a plugin install for a PROJECT, as a build.
 *
 * Builds the install plan (ConceptCatalog::installPlan — the concept plus everything it
 * requires that the project does not already have), writes it as a plan file in the project's
 * .aibuilder/, and hands it to plan-ingest.php. From there it is an ordinary plan: it shows
 * up in Builder, is approved and run like any other, and the executor installs the files,
 * commits them and merges them. No agent runs.
 *
 * Why a build and not a copy into the live tree: every worktree is cut from the project's
 * COMMITTED base, so files copied into the working tree are invisible to every agent that
 * runs afterwards. An install has to end as a commit.
 *
 * Usage:
 *   php scripts/concept-install.php --concept=<name> --slug=<slug> --dir=<instanceDir> \
 *       --member=<id> [--autobuild=1] [--app=tiknix] [--db=<sqlite path>]
 *
 * --autobuild=1 approves and starts the build at once. Off by default, like every plan.
 * Authorisation is plan-ingest.php's: the member must have access to the project.
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

use app\ConceptCatalog;
use app\ConceptException;
use app\PlanIngestor;

$o = getopt('', ['concept:', 'slug:', 'dir:', 'member:', 'autobuild::', 'app::', 'db::', 'level::']);
$concept = (string) ($o['concept'] ?? '');
$slug    = (string) ($o['slug'] ?? '');
$dir     = rtrim((string) ($o['dir'] ?? ''), '/');
$member  = (int) ($o['member'] ?? 0);

if ($concept === '' || $slug === '' || $dir === '' || $member <= 0) {
    fwrite(STDERR, "usage: --concept=<name> --slug=<slug> --dir=<instanceDir> --member=<id> [--autobuild=1]\n");
    exit(2);
}
if (!is_dir($dir . '/.git') && !is_file($dir . '/.git')) {
    fwrite(STDERR, "[concept-install] {$dir} is not a git repository, so there is no base branch to install onto.\n");
    exit(1);
}

try {
    $plan = ConceptCatalog::forInstall()->installPlan($concept, $dir);
} catch (ConceptException $e) {
    fwrite(STDERR, '[concept-install] ' . $e->getMessage() . "\n");
    exit(1);
}
if (!PlanIngestor::isValidPlan($plan)) {
    fwrite(STDERR, "[concept-install] the install plan did not validate — this is a bug in ConceptCatalog::installPlan.\n");
    exit(1);
}

$abDir = $dir . '/.aibuilder';
if (!is_dir($abDir) && !mkdir($abDir, 0775, true)) {
    fwrite(STDERR, "[concept-install] could not create {$abDir}\n");
    exit(1);
}
// A unique *.plan.json, the form PlanIngestor::pending() collects — never the bare
// plan.json, which a planner running for the same project might be about to write.
$file = $abDir . '/install-' . $concept . '-' . bin2hex(random_bytes(4)) . '.plan.json';
if (file_put_contents($file, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    fwrite(STDERR, "[concept-install] could not write {$file}\n");
    exit(1);
}
echo "[concept-install] {$plan['title']}\n";
echo '[concept-install] installs: ' . implode(', ', $plan['subtasks'][0]['adopts']) . "\n";

// Hand off to the one ingester. It resolves the project, checks the member's access, and
// writes the task tree into the project's own workbench.db — all of which it already does
// correctly, and none of which should exist twice.
$args = ['--slug=' . $slug, '--dir=' . $dir, '--member=' . $member];
foreach (['autobuild', 'app', 'db', 'level'] as $k) {
    if (isset($o[$k]) && $o[$k] !== false) $args[] = "--{$k}=" . $o[$k];
}
$cmd = 'php ' . escapeshellarg(__DIR__ . '/plan-ingest.php') . ' ' . implode(' ', array_map('escapeshellarg', $args));
passthru($cmd, $code);
if ($code !== 0) {
    // The plan file may still be sitting there unclaimed; say so rather than leave a mystery.
    fwrite(STDERR, "[concept-install] plan-ingest.php exited {$code}." . (is_file($file) ? " The plan is still at {$file}." : '') . "\n");
}
exit($code);
