#!/usr/bin/env php
<?php
/**
 * reap-stale-tasks.php — release tasks whose agent is gone, and kill sessions
 * whose task is gone.
 *
 * The Stop hook releases a task when Claude finishes normally. It cannot help
 * when there IS no normal finish: the jail dies, the box reboots, somebody kills
 * the pane, PHP fatals mid-run. The task then reads `running` forever, its port
 * and session name stay claimed, and every later attempt is refused with "a
 * session for this task is already active" — naming a session for work that
 * stopped hours ago.
 *
 * Nothing was reaping these. TmuxManager::cleanupOrphaned() was written for it
 * and is called from nowhere, and there is no cron entry, so orphans only ever
 * accumulated.
 *
 * TWO directions, because the two states rot independently:
 *   task running, session gone  -> release the task (it cannot make progress)
 *   session alive, task not running -> kill the session (nothing is watching it)
 *
 * A released task is marked `awaiting`, NOT `failed`. We know the agent stopped;
 * we do not know whether it finished the work first — task 45 had committed
 * everything and was idling at its prompt. Calling that a failure would be a
 * guess, and the wrong one destroys the record of completed work. `awaiting` says
 * what is true: it is a person's turn.
 *
 * Usage:
 *   php scripts/reap-stale-tasks.php              # report only, changes nothing
 *   php scripts/reap-stale-tasks.php --apply
 *   php scripts/reap-stale-tasks.php --apply --grace=300
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';
$app = new \app\Bootstrap();

$opt   = getopt('', ['apply', 'grace::', 'quiet']);
$apply = isset($opt['apply']);
$quiet = isset($opt['quiet']);

// A task that started seconds ago may not have its session up yet. Default five
// minutes: long enough that a slow start is never mistaken for a dead one.
$grace = max(60, (int) ($opt['grace'] ?? 300));

$say = function (string $s) use ($quiet) { if (!$quiet) echo $s . "\n"; };
$say($apply ? 'reaping stale tasks' : 'DRY RUN — nothing will change (pass --apply)');

// Live sessions, once. tmux is asked a single time rather than per task: on a box
// with fifty instances that is fifty forks for one answer that does not change.
$live = [];
exec('tmux list-sessions -F "#{session_name}" 2>/dev/null', $live);
$live = array_flip(array_filter(array_map('trim', $live)));
$say('  ' . count($live) . ' live tmux session(s)');

$released = 0; $killed = 0; $claimed = [];

foreach (glob('/var/www/html/default/*.tiknix/data/workbench.db') ?: [] as $db) {
    $slug = basename(dirname(dirname($db)));

    try {
        $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // A project that has never run a task has no workbenchtask table at all —
        // the schema is fluid, so it appears on first store. That is a normal
        // state, not a fault, and reporting it as one every five minutes is how a
        // cron mail becomes something nobody opens.
        $has = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='workbenchtask'")->fetchColumn();
        if (!$has) continue;

        // Only columns guaranteed to exist. tmux_session and test_server_session
        // are also fluid — absent until something first writes one — so they are
        // read from the row rather than named in the SELECT.
        $rows = $pdo->query("SELECT * FROM workbenchtask
                              WHERE status IN ('running','queued')")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // A database that exists but cannot be read IS worth shouting about: its
        // stale tasks will never be reaped and silence would hide that forever.
        $say("  ! {$slug}: could not read workbench.db — " . $e->getMessage());
        continue;
    }

    // Test-server sessions are claimed too, and by a DIFFERENT column. Without
    // this the sweep below sees tiknix-serve-1-26, finds no task in
    // `tmux_session` claiming it, and kills somebody's live preview.
    try {
        foreach ($pdo->query("SELECT test_server_session FROM workbenchtask
                               WHERE test_server_session IS NOT NULL AND test_server_session != ''")
                     ->fetchAll(PDO::FETCH_COLUMN) as $s) {
            $claimed[trim((string) $s)] = true;
        }
    } catch (Throwable $e) { /* column not created yet: nothing to claim */ }

    foreach ($rows as $t) {
        $session = trim((string) ($t['tmux_session'] ?? ''));
        if ($session !== '') $claimed[$session] = true;

        $age = $t['started_at'] ? (time() - strtotime((string) $t['started_at'])) : PHP_INT_MAX;
        if ($age < $grace) continue;

        // A task with no session recorded and no agent is stale too: that is the
        // shape left by a run that died before it could store the name.
        if ($session !== '' && isset($live[$session])) continue;

        $why = $session === '' ? 'never recorded a session' : "session {$session} is gone";
        $say(sprintf('  %-24s task %-4s %s (%s, %s)',
            $slug, $t['id'], $apply ? 'RELEASED' : 'would release', $why,
            $t['started_at'] ?: 'no start time'));

        if ($apply) {
            $st = $pdo->prepare("UPDATE workbenchtask
                                    SET status = 'awaiting',
                                        progress_message = 'The agent is no longer running. Nothing was lost — check its last output.',
                                        tmux_session = NULL,
                                        updated_at = ?
                                  WHERE id = ? AND status IN ('running','queued')");
            $st->execute([date('Y-m-d H:i:s'), (int) $t['id']]);
        }
        $released++;
    }
}

// The other direction: a tiknix session nobody is waiting on.
foreach (array_keys($live) as $name) {
    if (strpos($name, 'tiknix-') !== 0) continue;      // not ours
    if (isset($claimed[$name])) continue;              // a live task owns it
    if (preg_match('/-(orchestrator|terminal)$/', $name)) continue;

    // A test server outlives the run that started it — you stop a preview when
    // you are done looking at it, not when the agent finishes. Killing one
    // because no task is currently `running` would close a preview somebody is
    // reading. They are reaped when their task is cleaned up, not from here.
    if (strpos($name, 'tiknix-serve-') === 0) continue;

    $say('  ' . ($apply ? 'KILLED  ' : 'would kill ') . $name . ' (no running task claims it)');
    if ($apply) exec('tmux kill-session -t ' . escapeshellarg($name) . ' 2>/dev/null');
    $killed++;
}

$say(sprintf('  %s: %d task(s), %d session(s)', $apply ? 'reaped' : 'would reap', $released, $killed));
