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

use app\Bean;

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
    // THE DIRECTORY IS <slug>.<app>; THE SLUG IS NOT THE DIRECTORY.
    //
    // Session names are built from the bare instance slug, so reading the directory
    // name whole gave "mileage.tiknix" where every session says "mileage", and the
    // orchestrator probe below looked for tiknix-mileage-tiknix-plan72-orchestrator —
    // a name nothing ever creates. It therefore answered "the orchestrator is gone"
    // for every plan on every instance, and this sweep marked each actively-building
    // plan failed/stalled five minutes after it started. The glob is *.tiknix, so the
    // suffix to drop is exact rather than guessed.
    $slug = preg_replace('/\.tiknix$/', '', basename(dirname(dirname($db))));

    try {
        // One named connection per file. Bean:: rather than raw PDO (CLAUDE.md): a
        // task is a bean, so releasing one goes through the model and its hooks
        // instead of an UPDATE that bypasses them.
        $conn = 'reap_' . substr(sha1($db), 0, 8);
        if (!Bean::hasDatabase($conn)) Bean::addDatabase($conn, 'sqlite:' . $db);
        Bean::selectDatabase($conn);

        // A project that has never run a task has no workbenchtask table at all —
        // the schema is fluid, so it appears on first store. That is a normal
        // state, not a fault, and reporting it as one every five minutes is how a
        // cron mail becomes something nobody opens. Fluid mode answers a query
        // against an absent table with nothing rather than an error, so an empty
        // result here means both "no such table" and "nothing running" — which
        // want the same thing done about them.
        $rows = Bean::find('workbenchtask', 'status IN (?,?)', ['running', 'queued']);

        // AWAITING TASKS CLAIM THEIR SESSION TOO.
        //
        // `awaiting` means Claude asked something and is holding at its prompt. The
        // session IS the question — it is where the text lives and the only place an
        // answer can be typed. It was not in the claim set, so the sweep below saw an
        // unclaimed session and killed it, destroying the question maybe five minutes
        // after it was asked. What the operator then found was a task marked
        // "waiting for user input" with a dead console and nothing to reply to, and
        // no way to tell that from a run that never asked anything.
        //
        // Claimed, not released: these tasks are NOT candidates for release below —
        // a person really is expected to answer them.
        foreach (Bean::find('workbenchtask', 'status = ?', ['awaiting']) as $a) {
            foreach ([$a->tmuxSession, $a->agentSession] as $sess) {
                $sess = trim((string) $sess);
                if ($sess !== '') $claimed[$sess] = true;
            }
        }
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
        foreach (Bean::find('workbenchtask', 'test_server_session IS NOT NULL AND test_server_session != ?', ['']) as $s) {
            $claimed[trim((string) $s->testServerSession)] = true;
        }
    } catch (Throwable $e) { /* column not created yet: nothing to claim */ }

    foreach ($rows as $t) {
        // BOTH columns. A task started from the board records tmux_session; a
        // subtask launched by PlanExecutor records agent_session, and reading only
        // the first made every plan-built subtask look like it had "never recorded
        // a session". This script runs from cron every five minutes with --apply,
        // so it released live plan builds the moment they passed the grace period
        // — including three on floorplan, two of which were then mistaken for
        // tasks waiting on a person.
        $session = trim((string) ($t->tmuxSession ?: $t->agentSession ?: ''));
        if ($session !== '') $claimed[$session] = true;

        // A PLAN PARENT IS NOT A TASK, and its liveness is not in these columns.
        //
        // plan-orchestrate.php sets the parent to `running` while it drives the build,
        // but the parent never stores a session name — the orchestrator runs as
        // tiknix-<slug>-plan<id>-orchestrator, which lives nowhere on this row. So the
        // check below read an empty session as "never recorded one", and released every
        // actively-building plan five minutes after it started, into `awaiting` — a
        // status the executor never launches and nothing ever moves. That is how a plan
        // that was building fine ended up looking like it was waiting on a person, and
        // it happened again on every rebuild.
        //
        // Ask the orchestrator instead. Claiming its session also stops the sweep at the
        // bottom of this script from killing it.
        if (empty($t->parentTaskId)) {
            $orch = \app\PlanOrchestrator::liveSession((int) $t->id, $slug);
            if ($orch !== '') { $claimed[$orch] = true; continue; }   // building — leave it alone
        }

        $age = $t->startedAt ? (time() - strtotime((string) $t->startedAt)) : PHP_INT_MAX;
        if ($age < $grace) continue;

        // A task with no session recorded and no agent is stale too: that is the
        // shape left by a run that died before it could store the name.
        if ($session !== '' && isset($live[$session])) continue;

        $why = $session === '' ? 'never recorded a session' : "session {$session} is gone";
        $say(sprintf('  %-24s task %-4s %s (%s, %s)',
            $slug, (int) $t->id, $apply ? 'RELEASED' : 'would release', $why,
            $t->startedAt ?: 'no start time'));

        if ($apply) {
            // The bean, not an UPDATE. The status guard the old statement carried in
            // its WHERE clause is unnecessary here: this bean was read inside this
            // same sweep and nothing else writes it between.
            // AWAITING MEANS "YOUR TURN", so it may only be used when there is
            // something to turn to. A released run has no live console to attach to
            // and asked no question — the operator opens it, finds the task and
            // nothing else, and has no way to tell this from a genuine prompt.
            //
            // A PLAN SUBTASK goes back to `pending` instead. Nothing is waiting on a
            // person, and pending is the one status the orchestrator will relaunch,
            // so the plan heals itself on the next build rather than deadlocking
            // behind a task that can never leave `awaiting` — which is exactly how
            // five subtasks on floorplan froze behind two.
            $isSubtask = !empty($t->parentTaskId);
            $isPlan    = !$isSubtask && (string) $t->planStatus !== '';

            if ($isPlan) {
                // A PLAN whose orchestrator is gone (checked above) did not finish. Mark
                // it stalled, which is a state Build accepts, so the operator can restart
                // it. Never `awaiting`: the plan is not asking anything, and awaiting is
                // the status nothing moves.
                $t->status          = 'failed';
                $t->planStatus      = 'stalled';
                $t->progressMessage = 'The build orchestrator stopped before the plan finished. '
                                    . 'Press Build to resume — the subtasks that already merged are kept.';
            } else {
                // AWAITING MEANS "YOUR TURN", so it may only be used when there is
                // something to turn to. A released run has no live console to attach to
                // and asked no question — the operator opens it, finds the task and
                // nothing else, and has no way to tell this from a genuine prompt.
                //
                // A PLAN SUBTASK goes back to `pending` instead. Nothing is waiting on a
                // person, and pending is the one status the orchestrator will relaunch,
                // so the plan heals itself on the next build rather than deadlocking
                // behind a task that can never leave `awaiting` — which is exactly how
                // five subtasks on floorplan froze behind two.
                $t->status          = $isSubtask ? 'pending' : 'awaiting';
                $t->progressMessage = $isSubtask
                    ? 'The run ended without finishing. Nothing is waiting on you — it will start again on the next build.'
                    : 'The run ended without a reply. Nothing is waiting on you — check the branch, or run it again.';
            }

            $t->tmuxSession     = null;
            $t->agentSession    = null;
            $t->updatedAt       = date('Y-m-d H:i:s');
            Bean::store($t);
        }
        $released++;
    }

    // Back to the app's own database before the next instance, so a failure part
    // way through cannot leave a later read pointed at the wrong file.
    Bean::selectDatabase('default');
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
    if (strpos($name, '-serve-') !== false) continue;

    // Plan orchestrators and their per-task builders: never touched from here.
    // They own tasks of their own and are managed by the orchestrator's own
    // lifecycle.
    if (\app\TmuxManager::isPlanSession($name)) continue;

    // PLANNERS get a rule of their own rather than a blanket skip.
    //
    // PlanRunner names its session tiknix-<member>-plan-<slug>, and nothing in
    // workbenchtask claims it — a plan is not a task. A blanket skip was the safe
    // first move, because a decompose legitimately runs for many minutes before
    // it writes anything and killing one mid-plan would be the cleaner causing
    // the exact failure it exists to clean up after.
    //
    // But a blanket skip leaves the opposite hole: PlanRunner::running() gates a
    // new decompose on TmuxManager::exists(), with no timeout. So a planner that
    // dies WITHOUT its script reaching the exit line — killed pane, host restart,
    // OOM — locks that instance out of decomposing forever, and nothing would
    // ever clear it.
    //
    // The test is not age. It is whether the jailed agent is still there: the
    // runner script wraps `bwrap … claude -p`, so a planner with no bwrap child
    // is a shell sitting on a corpse. Age alone would kill live work — an
    // observed decompose took 3 minutes, but a large goal can run far longer, and
    // guessing a number is how a cleaner starts eating real runs.
    if (preg_match('/^tiknix-\d+-plan-(.+)$/', $name, $pm)) {
        $planSlug = $pm[1];
        $alive = 0;
        // pgrep -f against the instance directory: the bwrap command line names
        // it, and it is the one string that distinguishes THIS planner's agent
        // from another instance's.
        exec('pgrep -fa ' . escapeshellarg('bwrap') . ' 2>/dev/null', $bw);
        foreach ($bw as $line) {
            if (strpos($line, '/' . $planSlug . '.') !== false) { $alive++; break; }
        }
        if ($alive) continue;                       // still working — leave it

        $age = 0;
        exec('tmux display-message -p -t ' . escapeshellarg($name)
             . ' "#{session_created}" 2>/dev/null', $cr);
        if (!empty($cr[0]) && ctype_digit(trim($cr[0]))) $age = time() - (int) trim($cr[0]);

        // A grace period on top, because a planner is briefly alive before bwrap
        // starts and briefly alive after it exits while the script ingests the
        // plan — killing it in either window would lose the plan it just wrote.
        if ($age < max($grace, 120)) continue;

        $say('  ' . ($apply ? 'KILLED  ' : 'would kill ') . $name
            . ' (planner: no jailed agent, idle ' . (int) round($age / 60) . 'm — the lock it holds'
            . ' blocks every future decompose for this instance)');
        if ($apply) exec('tmux kill-session -t ' . escapeshellarg($name) . ' 2>/dev/null');
        $killed++;
        continue;
    }

    $say('  ' . ($apply ? 'KILLED  ' : 'would kill ') . $name . ' (no running task claims it)');
    if ($apply) exec('tmux kill-session -t ' . escapeshellarg($name) . ' 2>/dev/null');
    $killed++;
}

$say(sprintf('  %s: %d task(s), %d session(s)', $apply ? 'reaped' : 'would reap', $released, $killed));
