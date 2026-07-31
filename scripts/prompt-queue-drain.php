#!/usr/bin/env php
<?php
/**
 * prompt-queue-drain.php — retry decomposes that were refused because the project was busy.
 *
 * PlanRunner allows one planner per member+instance, so firing a decompose while another
 * is mid-flight is refused outright. Workbench::decompose() records the prompt BEFORE
 * starting the planner, so the goal survives — and if it was submitted straight-through,
 * PromptQueue::enqueue marks it for retry. This is what goes back for it.
 *
 * ONLY straight-through goals are ever started here. "Run it straight through" means
 * "don't stop and ask me", so finishing it later is completing the instruction. A
 * decompose without it produces a draft that waits for approval anyway, so starting one
 * unattended would be inventing an instruction nobody gave — those keep the manual
 * "Run decompose" button on the Prompts page. See app\PromptQueue for the bounds.
 *
 * Deliberately NOT folded into pipeline-cron.php: that is the fake-cron tick for
 * pipelines, and a prompt queue is not a pipeline concern. Separate tool, separate line.
 *
 * Install (every minute is plenty — the work is a cheap query when the queue is empty):
 *   * * * * * php /var/www/html/default/tiknix/scripts/prompt-queue-drain.php >> /var/log/tiknix-promptqueue.log 2>&1
 *
 * Safe to run by hand, and safe to run concurrently with itself: a prompt whose planner
 * is still busy is left alone without consuming an attempt.
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R;
use app\PromptQueue;

$opt     = getopt('', ['dry-run', 'quiet']);
$dryRun  = isset($opt['dry-run']);
$quiet   = isset($opt['quiet']);

// The queue lives in core's registry, which is also this script's default connection.
R::setup('sqlite:' . dirname(__DIR__) . '/database/tiknix.db');
R::freeze(false);
if (!R::testConnection()) { fwrite(STDERR, "cannot open core db\n"); exit(1); }

if ($dryRun) {
    // Show what WOULD be retried without touching anything — the queue is a thing that
    // starts frontier model runs unattended, so it should be inspectable before it does.
    $rows = R::find('promptlog', 'queued_at IS NOT NULL ORDER BY queued_at ASC');
    if (!$rows) { echo "[" . date('c') . "] queue empty\n"; exit(0); }
    foreach ($rows as $r) {
        printf("  #%d %s attempts=%d queued=%s straight_through=%s\n",
            (int) $r->id, (string) $r->instanceTag, (int) $r->queueAttempts,
            (string) $r->queuedAt, !empty($r->autoBuild) ? 'yes' : 'NO (will be dequeued)');
    }
    exit(0);
}

$lines = PromptQueue::drain();

// Silence when there is nothing to do, so a per-minute cron does not fill a log with
// "queue empty" — but ALWAYS report when something happened, because a queue that starts
// work invisibly is one nobody can trust.
if ($lines) {
    echo '[' . date('c') . "] prompt queue: " . count($lines) . " item(s)\n";
    foreach ($lines as $l) echo "  $l\n";
} elseif (!$quiet) {
    // One line per run is fine by hand; cron should pass --quiet.
    echo '[' . date('c') . "] prompt queue: nothing to do\n";
}

R::close();
