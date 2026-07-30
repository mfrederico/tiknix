#!/usr/bin/env php
<?php
/**
 * promptlog-backfill.php — recover the prompts that were written before the prompt log
 * existed, from wherever they still survive.
 *
 * Two sources, in descending order of how much we know about them:
 *
 *   1. workbenchtask.plan_goal — a plan that was ingested after the goal started being
 *      stored on it. These are exact: we have the text AND the plan it became, so they
 *      are linked. Each plan is also given a planUid if it predates that field, so the
 *      link is durable across a workbench.db rebuild.
 *
 *   2. <instance>/.aibuilder/plan-goal.md — the goal of that instance's most recent
 *      decompose, still on disk. Real text the member typed, but which plan it produced
 *      is genuinely unknown (it may have produced none, if the planner died). Imported
 *      UNLINKED and dated by the file's mtime, rather than guessed at: an invented link
 *      to the newest plan would be wrong exactly when the planner failed, which is the
 *      case this log exists to preserve.
 *
 * Everything older is gone — plan-goal.md is overwritten by each decompose, and nothing
 * else ever held the text. This script does not manufacture a substitute from the plan's
 * summary: that is the planner's wording, not the member's, and filing it as "a prompt you
 * wrote" would put words in their mouth.
 *
 * Idempotent: every row carries an extKey, so re-running imports nothing twice.
 *
 * Usage: php scripts/promptlog-backfill.php [--dry-run]
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R;
use app\PromptLog;

$opt    = getopt('', ['dry-run']);
$dryRun = isset($opt['dry-run']);

$coreDb = dirname(__DIR__) . '/database/tiknix.db';
R::setup('sqlite:' . $coreDb);
R::freeze(false);
if (!R::testConnection()) { fwrite(STDERR, "cannot open core db: $coreDb\n"); exit(1); }

$instances = R::getAll('SELECT id, slug, app, member_id FROM instance ORDER BY id');
printf("%d instance(s) in the registry%s\n\n", count($instances), $dryRun ? '  [DRY RUN — nothing will be written]' : '');

$totals = ['linked' => 0, 'loose' => 0, 'skipped' => 0, 'uids' => 0, 'failed' => 0];

foreach ($instances as $inst) {
    $tag      = $inst['slug'] . '.' . ($inst['app'] ?: 'tiknix');
    $dir      = '/var/www/html/default/' . $tag;
    $memberId = (int) $inst['member_id'];
    $tasksDb  = $dir . '/data/workbench.db';
    if ($memberId <= 0) continue;

    $found = [];

    // ---- Source 1: goals stored on plans -----------------------------------------
    if (is_file($tasksDb)) {
        try {
            $w = new PDO('sqlite:' . $tasksDb);
            $w->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $cols = $w->query('SELECT name FROM pragma_table_info("workbenchtask")')->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('plan_goal', $cols, true)) {
                $hasUid = in_array('plan_uid', $cols, true);
                $rows = $w->query(
                    'SELECT id, title, plan_goal, created_at' . ($hasUid ? ', plan_uid' : '') . '
                       FROM workbenchtask
                      WHERE parent_task_id IS NULL AND plan_goal IS NOT NULL AND plan_goal != ""
                      ORDER BY id'
                )->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $r) {
                    // Give older plans the durable handle they were minted without, so the
                    // link survives this file being rebuilt.
                    $uid = $hasUid ? trim((string) ($r['plan_uid'] ?? '')) : '';
                    if ($uid === '') {
                        $uid = bin2hex(random_bytes(8));
                        if (!$dryRun) {
                            if (!$hasUid) { $w->exec('ALTER TABLE workbenchtask ADD COLUMN plan_uid TEXT'); $hasUid = true; }
                            $st = $w->prepare('UPDATE workbenchtask SET plan_uid = ? WHERE id = ?');
                            $st->execute([$uid, (int) $r['id']]);
                        }
                        $totals['uids']++;
                    }
                    $found[] = [
                        'body'       => (string) $r['plan_goal'],
                        'title'      => (string) $r['title'],
                        'created_at' => (string) ($r['created_at'] ?: date('Y-m-d H:i:s')),
                        'plan_uid'   => $uid,
                        'plan_id'    => (int) $r['id'],
                        'ext_key'    => 'plan:' . $tag . ':' . $uid,
                        'kind'       => 'linked',
                    ];
                }
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "  ! $tag: could not read its tasks db: " . $e->getMessage() . "\n");
            $totals['failed']++;
        }
    }

    // ---- Source 2: the last goal still on disk, unlinked --------------------------
    $goalFile = $dir . '/.aibuilder/plan-goal.md';
    if (is_file($goalFile)) {
        $text = trim((string) @file_get_contents($goalFile));
        if ($text !== '') {
            // Dedup against source 1 by CONTENT: the file is usually the newest plan's
            // goal, already imported above with better information attached.
            $dupe = false;
            foreach ($found as $f) { if (trim($f['body']) === $text) { $dupe = true; break; } }
            if (!$dupe) {
                $found[] = [
                    'body'       => $text,
                    'title'      => '(goal on disk — which plan it produced is unknown)',
                    'created_at' => date('Y-m-d H:i:s', (int) @filemtime($goalFile)),
                    'plan_uid'   => '',
                    'plan_id'    => 0,
                    'ext_key'    => 'goalfile:' . $tag . ':' . substr(sha1($text), 0, 16),
                    'kind'       => 'loose',
                ];
            }
        }
    }

    if (!$found) continue;

    echo "$tag (member $memberId)\n";
    foreach ($found as $f) {
        // Idempotency: skip anything already carrying this extKey.
        $exists = (int) R::count('promptlog', 'member_id = ? AND ext_key = ?', [$memberId, $f['ext_key']]);
        if ($exists > 0) { $totals['skipped']++; echo "   = already logged: " . firstLine($f['body']) . "\n"; continue; }

        if ($dryRun) {
            $totals[$f['kind']]++;
            echo "   + would add [{$f['kind']}] " . firstLine($f['body']) . "\n";
            continue;
        }

        $id = PromptLog::record([
            'member_id'    => $memberId,
            'source'       => PromptLog::SOURCE_DECOMPOSE,
            'title'        => $f['kind'] === 'linked' ? $f['title'] : 'Decompose',
            'body'         => $f['body'],
            'instance_id'  => (int) $inst['id'],
            'instance_tag' => $tag,
            'plan_uid'     => $f['plan_uid'],
            'plan_title'   => $f['kind'] === 'linked' ? $f['title'] : '',
            'plan_id'      => $f['plan_id'],
            'ext_key'      => $f['ext_key'],
            'created_at'   => $f['created_at'],
        ]);
        if ($id) { $totals[$f['kind']]++; echo "   + [{$f['kind']}] " . firstLine($f['body']) . "\n"; }
        else     { $totals['failed']++;   echo "   ! FAILED: " . PromptLog::lastError() . "\n"; }
    }
    echo "\n";
}

printf(
    "linked to a plan: %d\nrecovered unlinked: %d\nalready present: %d\nplan uids minted: %d\nfailed: %d\n",
    $totals['linked'], $totals['loose'], $totals['skipped'], $totals['uids'], $totals['failed']
);
if ($totals['failed'] > 0) { R::close(); exit(1); }
R::close();

function firstLine(string $s): string {
    $s = trim(strtok(str_replace(["\r\n", "\r"], "\n", $s), "\n") ?: '');
    return mb_strlen($s) > 76 ? mb_substr($s, 0, 76) . '…' : $s;
}
