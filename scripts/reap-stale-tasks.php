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
    // The glob matches core.tiknix, which is a SYMLINK to the control plane and has a
    // data/workbench.db of its own — so this sweep would release and reap core's tasks
    // believing they belonged to a customer project. Decide by structure, never by name.
    if (!\Model_Instance::isProvisionedInstance(dirname(dirname($db)))) continue;

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

    // PLANNERS AND AUDITORS get a rule of their own rather than a blanket skip.
    //
    // PlanRunner names its session tiknix-<member>-plan-<slug>, AuditRunner names its
    // own tiknix-<member>-audit-<slug>, and nothing in workbenchtask claims either — a
    // plan is not a task, and neither is a QA pass. A blanket skip was the safe first
    // move, because a decompose legitimately runs for many minutes before it writes
    // anything and killing one mid-plan would be the cleaner causing the exact failure
    // it exists to clean up after.
    //
    // But a blanket skip leaves the opposite hole: PlanRunner::running() gates a new
    // decompose on TmuxManager::exists() with no timeout, and AuditRunner::start()
    // throws "An audit is already running for this instance." off the identical check.
    // So a planner or auditor that dies WITHOUT its script reaching the exit line —
    // killed pane, host restart, OOM — locks that instance out of decomposing (or
    // auditing) forever, and nothing would ever clear it.
    //
    // THE AUDITOR WAS MISSING FROM THIS RULE. It matched no exception above, so it fell
    // through to the blanket "no running task claims it" kill at the bottom — which has
    // no liveness test at all. A QA pass two minutes into its run was a kill candidate
    // on the very next sweep, and the operator saw a plan that reported no manifest and
    // an audit.log that simply stopped. That is the same defect the serve, planner and
    // orchestrator exceptions were each added to fix, arriving once more through the
    // one session shape nobody had named yet.
    //
    // The test is not age. It is whether the jailed agent is still there: both runner
    // scripts wrap `bwrap … claude -p`, so one with no bwrap child is a shell sitting on
    // a corpse. Age alone would kill live work — an observed decompose took 3 minutes,
    // but a large goal can run far longer, and guessing a number is how a cleaner starts
    // eating real runs.
    //
    // (Both runners fall back to an UNJAILED `claude -p` when jailFor() finds no jail
    // script — a workspace outside /var/www/html/default, or one with no public/index.php.
    // No provisioned instance looks like that, so the bwrap test holds for every session
    // this sweep can actually see. If that ever stops being true the symptom is a live
    // run killed after the grace period, and the fix is to test the pane's process tree
    // for a live claude rather than to widen the pattern.)
    if (preg_match('/^tiknix-\d+-(plan|audit)-(.+)$/', $name, $pm)) {
        $kind     = $pm[1];                       // 'plan' | 'audit'
        $instSlug = $pm[2];
        $blocks   = $kind === 'plan' ? 'decompose' : 'audit';

        // exec() APPENDS to the array it is handed rather than replacing it. Both of
        // these were reused across loop iterations without being cleared, so the second
        // planner in a sweep read $cr[0] from the FIRST one and was aged by another
        // session's clock. For two sessions created hours apart that difference decides
        // the kill, and it silently made the grace period meaningless for every planner
        // after the first.
        $bw = [];
        $cr = [];

        $alive = 0;
        // pgrep -f against the instance directory: the bwrap command line names it, and
        // it is the one string that distinguishes THIS instance's agent from another's.
        exec('pgrep -fa ' . escapeshellarg('bwrap') . ' 2>/dev/null', $bw);
        foreach ($bw as $line) {
            if (strpos($line, '/' . $instSlug . '.') !== false) { $alive++; break; }
        }
        if ($alive) continue;                       // still working — leave it

        $age = 0;
        exec('tmux display-message -p -t ' . escapeshellarg($name)
             . ' "#{session_created}" 2>/dev/null', $cr);
        if (!empty($cr[0]) && ctype_digit(trim($cr[0]))) $age = time() - (int) trim($cr[0]);

        // A grace period on top, because both are briefly alive before bwrap starts and
        // briefly alive after it exits while the script ingests what it produced —
        // killing it in either window would lose the plan or manifest it just wrote.
        if ($age < max($grace, 120)) continue;

        $say('  ' . ($apply ? 'KILLED  ' : 'would kill ') . $name
            . " ({$kind}: no jailed agent, idle " . (int) round($age / 60) . 'm — the lock it holds'
            . " blocks every future {$blocks} for this instance)");
        if ($apply) exec('tmux kill-session -t ' . escapeshellarg($name) . ' 2>/dev/null');
        $killed++;
        continue;
    }

    $say('  ' . ($apply ? 'KILLED  ' : 'would kill ') . $name . ' (no running task claims it)');
    if ($apply) exec('tmux kill-session -t ' . escapeshellarg($name) . ' 2>/dev/null');
    $killed++;
}

/* ─── THE PROCESS SWEEP ──────────────────────────────────────────────────────
 *
 * Everything above reaps BOOKKEEPING: a tmux session, a task row. None of it
 * verifies that the agent's processes actually died, and routinely they do not.
 * `tmux kill-session` hangs up the pane; the runner's tree underneath it —
 * run-claude.sh → jail-run.sh → bwrap → claude → npm exec → playwright-mcp →
 * chrome — does not reliably go with it. What survives stays parented to the
 * tmux SERVER rather than to init, so it does not even read as an orphan in ps.
 *
 * Measured on this box before this sweep existed: nine such trees, the oldest
 * twelve days old, holding ~2.5 GB. That is enough to fill an 8 GB container's
 * entire 1 GB of swap, and it did. Their sessions were long gone and their task
 * rows still said `running`, so the sweep above reported them released and moved
 * on while every byte stayed allocated. Reaping the row without reaping the tree
 * is the failure this whole file was written for, repeated one level down.
 *
 * IDENTITY COMES FROM THE PROCESS'S OWN ENVIRONMENT, never from a pattern over
 * ps output. Each runner exports TIKNIX_SESSION_NAME (ClaudeRunner through
 * run-claude.sh, AuditRunner through run-audit.sh), so the tree states which
 * session owns it. When that cannot be read the tree is REPORTED AND LEFT
 * RUNNING: killing a process we could not identify is how a cleaner becomes the
 * outage, and this file already carries four comments about exactly that.
 *
 * ONLY run-claude.sh AND run-audit.sh. The aibuilder runner (run-agent.sh) is
 * deliberately absent: it runs on a PRIVATE tmux socket under the instance's
 * .aibuilder/, which `tmux list-sessions` here never sees. Every one of its live
 * agents would therefore look sessionless and be killed on the first sweep.
 * Reaping those needs the per-instance socket enumerated first; until then they
 * are out of scope rather than guessed at.
 * ─────────────────────────────────────────────────────────────────────────── */

/** pid => ppid for everything visible. One /proc walk: a recursive descent that
 *  stats per pid re-reads the same files hundreds of times. */
function procParents(): array {
    $out = [];
    foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
        $stat = @file_get_contents($dir . '/stat');
        if ($stat === false) continue;                       // exited mid-walk
        // comm is arbitrary text in parentheses and may itself contain ') ',
        // so every field is read relative to the LAST ')' rather than by split.
        $rp = strrpos($stat, ')');
        if ($rp === false) continue;
        $f = preg_split('/\s+/', trim(substr($stat, $rp + 1)));
        if (!isset($f[1])) continue;                         // [0]=state [1]=ppid
        $out[(int) basename($dir)] = (int) $f[1];
    }
    return $out;
}

/** pid => full command line, NULs flattened. Kernel threads have none and are skipped. */
function procCmdlines(): array {
    $out = [];
    foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
        $raw = @file_get_contents($dir . '/cmdline');
        if ($raw === false || $raw === '') continue;
        $out[(int) basename($dir)] = trim(str_replace("\0", ' ', $raw));
    }
    return $out;
}

/**
 * Seconds since a pid started, or null if that cannot be established.
 *
 * NOT `ps -o etimes=`, and NOT /proc/uptime. Under lxcfs this container's
 * /proc/uptime is virtualised to the CONTAINER's uptime (17 days here) while the
 * starttime field in /proc/<pid>/stat stays relative to the HOST's boot (156
 * days). Subtracting one from the other is meaningless, which is why ps reports
 * every process on this box as roughly 47581 days old — and an age that large
 * passes any grace period ever written. /proc/stat's btime is the host boot
 * epoch and is not virtualised, so btime + starttime/HZ is the real start.
 */
function procStartEpoch(int $pid, int $btime, int $hz): ?int {
    $stat = @file_get_contents("/proc/$pid/stat");
    if ($stat === false) return null;
    $rp = strrpos($stat, ')');
    if ($rp === false) return null;
    $f = preg_split('/\s+/', trim(substr($stat, $rp + 1)));
    if (!isset($f[19])) return null;                          // field 22 overall
    return $btime + (int) ((float) $f[19] / $hz);
}

/** A pid and every descendant of it. */
function procTree(int $root, array $kids): array {
    $tree = [];
    $stack = [$root];
    while ($stack) {
        $p = array_pop($stack);
        if (isset($tree[$p])) continue;                       // cycle guard
        $tree[$p] = true;
        foreach ($kids[$p] ?? [] as $c) $stack[] = $c;
    }
    return array_keys($tree);
}

$btime = 0;
foreach (file('/proc/stat') ?: [] as $l) {
    if (strncmp($l, 'btime ', 6) === 0) { $btime = (int) trim(substr($l, 6)); break; }
}
$hz = (int) trim((string) shell_exec('getconf CLK_TCK 2>/dev/null'));

// No fallback to a guessed 100. Without a real btime and HZ every age below is
// wrong, and an age that reads too large is precisely what kills live work.
if ($btime <= 0 || $hz <= 0) {
    $say('  ! cannot read btime (/proc/stat) or CLK_TCK — process sweep SKIPPED,'
       . ' agent trees will not be reaped this pass');
    $say(sprintf('  %s: %d task(s), %d session(s)', $apply ? 'reaped' : 'would reap', $released, $killed));
    exit(0);
}

$parents = procParents();
$kids    = [];
foreach ($parents as $pid => $ppid) $kids[$ppid][] = $pid;
$cmdlines = procCmdlines();

// Never signal ourselves or anything we are running under: cron -> sh -> php.
$selfChain = [];
for ($p = getmypid(); $p > 1; $p = $parents[$p] ?? 0) {
    $selfChain[$p] = true;
    if (!isset($parents[$p])) break;
}

// Live sessions are re-read rather than reused from the top of the script: the
// sweep above just killed some, and THOSE trees are exactly what this pass is
// here to collect.
$liveNow = [];
exec('tmux list-sessions -F "#{session_name}" 2>/dev/null', $liveNow);
$liveNow = array_flip(array_filter(array_map('trim', $liveNow)));

/** SIGTERM the whole set at once, then SIGKILL whatever is still up. Signalling
 *  parents first lets a supervisor respawn the child being killed; signalling
 *  children first orphans them onto init mid-teardown. One pass over the set
 *  avoids both. Returns the number of processes that were actually signalled. */
$killTree = function (array $pids) use ($apply): int {
    if (!$apply) return count($pids);
    foreach ($pids as $p) @posix_kill($p, SIGTERM);
    for ($i = 0; $i < 20; $i++) {                             // up to ~5s
        usleep(250000);
        $alive = array_filter($pids, fn($p) => @posix_kill($p, 0));
        if (!$alive) return count($pids);
    }
    foreach ($pids as $p) { if (@posix_kill($p, 0)) @posix_kill($p, SIGKILL); }
    return count($pids);
};

$treesReaped = 0; $procsKilled = 0;

foreach ($cmdlines as $pid => $cmd) {
    // TWO tests, because either alone matches the wrong thing. The path shape
    // (a slash before the name) rejects someone's `grep run-claude.sh`, and the
    // comm check rejects `vim /tmp/…/run-claude.sh` and `tail -f` on the same
    // path — a runner IS the shell executing the script, so anything whose comm
    // is not a shell is looking at the file rather than running it.
    if (!preg_match('#(^|\s)\S*/run-(claude|audit)\.sh(\s|$)#', $cmd)) continue;
    $comm = trim((string) @file_get_contents("/proc/$pid/comm"));
    if ($comm !== 'bash' && $comm !== 'sh') continue;
    if (isset($selfChain[$pid])) continue;

    $tree = procTree($pid, $kids);

    // THE WRAPPER'S OWN environ DOES NOT CARRY THIS. /proc/<pid>/environ is the
    // block handed over at execve and nothing more: run-claude.sh sets
    // TIKNIX_SESSION_NAME with `export` while it is ALREADY RUNNING, so the
    // variable never appears in the environ of the shell that exported it. Read
    // it there and every runner on the box looks unidentifiable — the first
    // version of this sweep declined to reap a single one of the trees it was
    // written for, and said so in a warning that looked like a permissions
    // problem.
    //
    // The children are the record. jail-run.sh, bwrap and claude are all exec'd
    // AFTER the export, so each inherits the finished environment. Any one of
    // them answers the question; the first that does, wins.
    $session = null;
    foreach ($tree as $tp) {
        $env = @file_get_contents("/proc/$tp/environ");
        if ($env === false || $env === '') continue;
        foreach (explode("\0", $env) as $kv) {
            if (strncmp($kv, 'TIKNIX_SESSION_NAME=', 20) === 0) {
                $session = trim(substr($kv, 20));
                break 2;
            }
        }
    }

    // NO FALLBACK. No descendant carrying the variable means we cannot say which
    // session owns this tree, and "probably stale" is not a reason to kill
    // somebody's build. Say so loudly instead — a runner this sweep can never
    // reap is a real fault, and silence would hide it for as long as it lives.
    //
    // A runner that has finished its agent and is sitting on the script's closing
    // `read` lands here legitimately: no children left, so nothing to read the
    // variable from. That is the correct outcome — it holds a shell and nothing
    // else, and the session sweep above is what clears it.
    if ($session === null || $session === '') {
        $say("  ! pid {$pid}: runner with no identifiable session — LEFT RUNNING ("
           . count($tree) . ' proc(s): ' . substr($cmd, 0, 70) . ')');
        continue;
    }

    if (isset($liveNow[$session])) continue;                  // its session is up: working

    $start = procStartEpoch($pid, $btime, $hz);
    if ($start === null) continue;                            // exited under us
    $age = time() - $start;
    if ($age < $grace) continue;                              // may not have registered yet
    $say('  ' . ($apply ? 'REAPED  ' : 'would reap ') . "tree pid {$pid} ({$session}): session gone, "
       . 'idle ' . (int) round($age / 3600) . 'h, ' . count($tree) . ' process(es)');
    $procsKilled += $killTree($tree);
    $treesReaped++;
}

// ORPHANED MCP SERVERS. A tree kill cannot reach these — the claude that opened
// them is already gone — so they are collected by their own rule or not at all.
// PPID 1 is the entire test: claude spawns its MCP servers as children and they
// exit with it, so one whose parent is init has outlived its agent. Chrome comes
// along as a child of the tree. Two days after the agent died, one of these was
// still holding 580 MB across node and eight chrome processes.
foreach ($cmdlines as $pid => $cmd) {
    if (strpos($cmd, 'playwright-mcp') === false) continue;
    if (($parents[$pid] ?? 0) !== 1) continue;
    if (isset($selfChain[$pid])) continue;

    $start = procStartEpoch($pid, $btime, $hz);
    if ($start === null) continue;
    $age = time() - $start;
    if ($age < $grace) continue;

    $tree = procTree($pid, $kids);
    $say('  ' . ($apply ? 'REAPED  ' : 'would reap ') . "orphan mcp pid {$pid}: parent gone, "
       . 'idle ' . (int) round($age / 3600) . 'h, ' . count($tree) . ' process(es)');
    $procsKilled += $killTree($tree);
    $treesReaped++;
}

$say(sprintf('  %s: %d task(s), %d session(s), %d tree(s)/%d process(es)', $apply ? 'reaped' : 'would reap', $released, $killed, $treesReaped, $procsKilled));
