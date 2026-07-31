#!/usr/bin/env php
<?php
/**
 * plan-failed.php — record that a detached planner finished WITHOUT producing a plan.
 *
 * A decompose is fire-and-forget: the browser is told the planner started and the request
 * ends. When the planner then dies — a session limit, a bad credential, an engine that
 * will not start — the only evidence was a line in .aibuilder/planner.log on disk, and the
 * Prompts page went on saying "never ran" as though nothing had been attempted. Someone
 * pressing the button, seeing "decomposing…", and finding nothing five minutes later had
 * no way to learn why, which is how "it said it did, but nothing showed up" happens.
 *
 * Run by the planner's own runner script (PlanRunner::buildRunnerScript) in the branch
 * where plan.json is absent — i.e. at the exact moment we know it failed, rather than by
 * polling for something that will never arrive.
 *
 * Usage: plan-failed.php --prompt=<id> --dir=<instanceDir> [--exit=<code>]
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R;

$o        = getopt('', ['prompt:', 'dir:', 'exit::']);
$promptId = (int) ($o['prompt'] ?? 0);
$dir      = rtrim((string) ($o['dir'] ?? ''), '/');
$exitCode = (int) ($o['exit'] ?? 0);
if ($promptId <= 0 || $dir === '') { fwrite(STDERR, "usage: --prompt=<id> --dir=<instanceDir>\n"); exit(2); }

/**
 * The planner's own last words. Its log is mostly the engine's chatter, so take the tail
 * and drop our own bookkeeping lines — what is left is the reason, in the engine's words,
 * which beats any message this script could invent ("You've hit your session limit ·
 * resets 3pm" is the whole answer, and no paraphrase of it would be).
 */
$why = '';
$log = $dir . '/.aibuilder/planner.log';
if (is_file($log)) {
    $lines = array_slice(array_filter(array_map('trim', file($log, FILE_IGNORE_NEW_LINES) ?: [])), -12);
    $lines = array_values(array_filter($lines, fn($l) => strncmp($l, '[planner]', 9) !== 0));
    $why   = trim(implode(' | ', array_slice($lines, -3)));
}
if ($why === '') $why = 'the planner exited ' . $exitCode . ' without producing a plan (no output captured)';

R::setup('sqlite:' . dirname(__DIR__) . '/database/tiknix.db');
R::freeze(false);
if (!R::testConnection()) { fwrite(STDERR, "cannot open core db\n"); exit(1); }

$row = R::load('promptlog', $promptId);
if (!$row->id) { fwrite(STDERR, "no prompt #$promptId\n"); exit(1); }

// A rate limit is not this prompt's problem — it blocks every decompose, build and
// terminal for this member until it resets. Recorded once, per member, so one banner
// explains all of it instead of five unrelated failures appearing in five places.
$limitUntil = \app\AgentLimit::note((int) $row->memberId, $why);
if ($limitUntil > 0) {
    echo "[plan-failed] agent limit recorded for member {$row->memberId} until "
       . date('c', $limitUntil) . "\n";
}

$row->lastError     = mb_substr($why, 0, 500);
$row->lastAttemptAt = date('Y-m-d H:i:s');
// A planner that started and died is NOT still waiting for a free slot. Leaving it queued
// would retry it against whatever killed it, on a timer, which for a session limit means
// burning the retry budget before the limit even resets.
$row->queuedAt      = null;
R::store($row);

echo "[plan-failed] prompt #$promptId: " . mb_substr($why, 0, 160) . "\n";
R::close();
