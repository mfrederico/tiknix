<?php
/**
 * PlanExecutor — runs an approved plan as parallel, dependency-ordered build
 * agents in git worktrees of ONE instance.
 *
 * Model — everything runs IN THE PROJECT'S CONTAINER (the control plane only orchestrates):
 *   - Each subtask runs `clitool --agent-task` there in its own tmux session (TenantRun): the app's
 *     own agent and credential, in a worktree on task/plan-<planId>-task-<id> (AgentTask).
 *   - When the run ends, the orchestrator reads its result from the container and merges the
 *     branch into the app there (merge is publish; the app's seeds run), or discards it.
 *   - A task is "ready" only when every task in its depends_on is merged, so
 *     dependents build on top of their prerequisites' merged code. Independent
 *     tasks (empty depends_on) run in parallel, capped at MAX_CONCURRENT.
 *
 * runOnce() is one tick (reap → launch). A thin script loops it until the plan
 * reaches a terminal state; that loop runs detached so it survives the browser.
 */

namespace app;

use app\EngineRegistry;
use app\MemberEnginePrefs;

class PlanExecutor {

    public const MAX_CONCURRENT = 3;   // hard cap — protects the operator's Claude quota
    public const MAX_AUTO_RETRIES = 3; // bounded backstop for auto-correction; stops earlier if the same error repeats

    private int $planId;
    private string $slug;
    private string $instanceDir;
    private int $memberLevel;
    /** The instance row when this project lives in its own container (the builder works there). */
    private ?object $tenant = null;

    /* No model parameter: the model is resolved per task from that task's engine
       (launchTask). One model for a whole plan could only ever be right when every task
       ran on the same provider. */
    public function __construct(int $planId, string $slug, string $instanceDir, int $memberLevel = 50) {
        $this->planId      = $planId;
        $this->slug        = $slug;
        $this->instanceDir = rtrim($instanceDir, '/');
        $this->memberLevel = $memberLevel;
        // The registry is core's database; the orchestrator's ambient one is the task board.
        $inst = CoreDb::with(fn() => Bean::findOne('instance', 'slug = ?', [$slug]));
        if (!$inst || !$inst->id || !\Model_Instance::tenantRow($inst)) {
            throw new \RuntimeException("{$slug} is not running in its own container — plans build only in a project's container");
        }
        $this->tenant = $inst;
    }

    /** Per-task budget when there is not enough history to measure one. */
    private const TASK_BUDGET_TICKS = 180;      // 30 minutes

    /** Fewer finished subtasks than this and the measurement is noise, not a p95. */
    private const BUDGET_MIN_SAMPLES = 5;

    /** Clamp on anything measured: no task budget below 15m or above 90m. */
    private const BUDGET_FLOOR_TICKS = 90;
    private const BUDGET_CEIL_TICKS  = 540;

    /** Absolute ceiling however big the plan is — a runaway must still end. */
    private const MAX_BUDGET_TICKS = 4320;      // 12 hours

    /**
     * How long ONE subtask on this engine+model may take, measured from what they have
     * actually done here.
     *
     * A single constant could not fit: across 387 finished subtasks on this host, claude's
     * p95 was 13 minutes and z.ai's was 35 — nearly three times longer for the same kind of
     * work. A 30-minute budget was therefore both wasteful for one and too tight for the
     * other, and plan #111 timed out on exactly that mismatch.
     *
     * p95 doubled: the p95 is what a slow-but-normal task costs, and the doubling covers a
     * task that is genuinely harder than the ones already measured. Clamped either way,
     * because a handful of quick tasks must not produce a budget that kills the first slow
     * one, and no measurement should permit a task to run for a working day.
     *
     * Falls back to the documented default ONLY when there are too few samples to measure,
     * and that is a real answer rather than a guess dressed as one: with four data points
     * a p95 is just the maximum.
     */
    private function taskBudgetTicks(string $engine, string $model): int {
        $secs = [];
        try {
            $rows = Bean::getAll(
                'SELECT started_at, completed_at, updated_at FROM workbenchtask
                  WHERE parent_task_id IS NOT NULL AND started_at IS NOT NULL
                    AND engine = ? AND status IN (?, ?, ?)',
                [$engine, 'merged', 'resolved', 'completed']
            );
            foreach ($rows as $r) {
                $end = $r['completed_at'] ?: $r['updated_at'];
                if (!$end || !$r['started_at']) continue;
                $d = strtotime((string) $end) - strtotime((string) $r['started_at']);
                // Discard the impossible: a clock skew, or a row whose end was written by
                // something other than the task finishing.
                if ($d > 0 && $d <= 86400) $secs[] = $d;
            }
        } catch (\Throwable $e) {
            return self::TASK_BUDGET_TICKS;      // no history table here
        }

        if (count($secs) < self::BUDGET_MIN_SAMPLES) return self::TASK_BUDGET_TICKS;

        sort($secs);
        $p95   = $secs[(int) floor(0.95 * (count($secs) - 1))];
        $ticks = (int) ceil(($p95 * 2) / 10);    // doubled, converted to 10s ticks
        return max(self::BUDGET_FLOOR_TICKS, min(self::BUDGET_CEIL_TICKS, $ticks));
    }

    /**
     * How long this plan may take, derived from the work and the provider's limits.
     *
     * A fixed ceiling could only ever be right for one shape of plan. 720 ticks (2h) was
     * generous for eight tasks running three wide — 40 minutes a wave — and far too tight
     * for the same eight tasks against a provider that serves one at a time, which is 15
     * minutes each. Plan #111 hit exactly that and was reported as finished.
     *
     * Waves, not tasks: work runs concurrently, so what bounds the clock is how many
     * ROUNDS the remaining tasks must run in. Counted per model, because the limit belongs
     * to the model, and SUMMED rather than maxed — two saturated models do not overlap for
     * free when MAX_CONCURRENT bounds the total, and a ceiling that is too generous costs
     * only a later timeout while one that is too tight kills working builds.
     *
     * Only pending and running tasks count. Resuming a plan that is most of the way done
     * should not re-budget for the parts already merged.
     */
    public function timeBudgetTicks(): int {
        $groups = [];
        foreach ($this->subtasks() as $t) {
            if (!in_array((string) $t->status, ['pending', 'running'], true)) continue;
            $eng = (string) ($t->engine ?: EngineRegistry::defaultEngine());
            $mdl = (string) ($t->model ?? '');
            $key = $eng . ':' . $mdl;
            if (!isset($groups[$key])) {
                $cap = EngineRegistry::maxConcurrency($eng, $mdl);
                // No declared provider limit: our own cap is the only one that applies.
                $groups[$key] = ['n' => 0, 'cap' => max(1, min(self::MAX_CONCURRENT, $cap > 0 ? $cap : self::MAX_CONCURRENT))];
            }
            $groups[$key]['n']++;
        }

        // Each group carries its own measured per-task cost, so a plan mixing a fast
        // engine and a slow one is budgeted for what each actually takes.
        $ticks = 0;
        foreach ($groups as $key => $g) {
            [$eng, $mdl] = array_pad(explode(':', $key, 2), 2, '');
            $waves  = (int) ceil($g['n'] / $g['cap']);
            $ticks += $waves * $this->taskBudgetTicks($eng, $mdl);
        }
        if ($ticks < 1) $ticks = self::TASK_BUDGET_TICKS;

        return min($ticks, self::MAX_BUDGET_TICKS);
    }

    // ---- public API --------------------------------------------------------

    /** The plan (parent) bean. */
    public function plan() { return Bean::load('workbenchtask', $this->planId); }

    /** Subtasks of this plan, priority-ordered. */
    public function subtasks(): array {
        return Bean::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [$this->planId]);
    }

    /**
     * One orchestration tick: reap finished agents (commit + merge), then launch
     * as many ready tasks as the concurrency cap allows. Returns a status summary.
     */
    public function runOnce(): array {
        $tasks = $this->subtasks();
        $byId  = [];
        foreach ($tasks as $t) $byId[(int)$t->id] = $t;

        // 1) Reap: any running task whose agent session has ended.
        foreach ($tasks as $t) {
            if ($t->status === 'running' && !$this->sessionAlive((string)$t->agentSession)) {
                $this->reapTask($t);
            }
        }

        /* 2) Launch: fill open slots with ready tasks (deps all merged).
         *
         * TWO caps, because they protect different things. MAX_CONCURRENT is ours — it
         * bounds what this machine and the operator's quota will carry. The per-engine cap
         * is the PROVIDER's: z.ai serves GLM-5.3 with a concurrency of 1, so launching
         * three agents against it put two of them into 529 "overloaded" retry loops,
         * spending wall-clock and quota to accomplish nothing. Launching fewer is faster.
         *
         * Counted per engine rather than globally, since a plan may mix engines and one
         * saturated provider must not block a task bound for another. */
        $fresh   = $this->subtasks();
        $running = $this->countByStatus($fresh, 'running');
        $perEngine = [];
        foreach ($fresh as $t) {
            if ($t->status !== 'running') continue;
            $e = (string) ($t->engine ?: EngineRegistry::defaultEngine()) . ':' . (string) ($t->model ?? '');
            $perEngine[$e] = ($perEngine[$e] ?? 0) + 1;
        }

        $slots = self::MAX_CONCURRENT - $running;
        if ($slots > 0) {
            foreach ($fresh as $t) {
                if ($slots <= 0) break;
                if ($t->status !== 'pending') continue;
                if (!$this->depsMerged($t, $byId)) continue;

                // Keyed by engine AND model: the provider's limit is per model, and a
                // plan may mix tiers (a fast worker model alongside a frontier planner).
                $eng = (string) ($t->engine ?: EngineRegistry::defaultEngine());
                $mdl = (string) ($t->model ?? '');
                $key = $eng . ':' . $mdl;
                $cap = EngineRegistry::maxConcurrency($eng, $mdl);   // 0 = none declared
                if ($cap > 0 && ($perEngine[$key] ?? 0) >= $cap) {
                    // Saturated upstream. Leave it pending; the next tick tries again.
                    continue;
                }

                if ($this->launchTask($t)) {
                    $slots--;
                    $perEngine[$key] = ($perEngine[$key] ?? 0) + 1;
                }
            }
        }

        // 3) Roll up plan state.
        $fresh = $this->subtasks();
        $counts = ['pending'=>0,'running'=>0,'merged'=>0,'resolved'=>0,'failed'=>0,'conflict'=>0];
        foreach ($fresh as $t) { $counts[$t->status] = ($counts[$t->status] ?? 0) + 1; }
        $total    = count($fresh);
        $terminal = ($counts['merged'] + $counts['resolved'] + $counts['failed'] + $counts['conflict']);
        $done     = ($total > 0 && $terminal >= $total);
        // Deadlock guard: nothing running, nothing launchable, but not all merged.
        $stalled  = (!$done && $counts['running'] === 0 && $counts['pending'] > 0
                     && !$this->anyLaunchable($fresh, $byId));

        return ['done' => $done || $stalled, 'stalled' => $stalled, 'counts' => $counts, 'total' => $total,
                // WHY it stalled, not just that it did. Computed here because this is
                // the only place that knows both the dependency graph and the rule
                // for what satisfies a dependency.
                'blocked' => $stalled ? $this->blockers($fresh, $byId) : []];
    }

    /**
     * Can this plan make progress right now, and if not, what is stopping it?
     *
     * Read-only: launches nothing, writes nothing, starts no session. It exists so a
     * caller can answer that question BEFORE spawning an orchestrator. Without it,
     * pressing Build on a plan whose remaining subtasks are all blocked started a
     * detached orchestrator that ticked once, found nothing launchable, wrote "stalled"
     * and exited — so the page refreshed to the same stalled plan with no error and no
     * explanation, which is indistinguishable from the button not working.
     *
     * @return array{ready:int,running:int,blocked:array,roots:string[]}
     */
    public function progressCheck(): array {
        $tasks = $this->subtasks();
        $byId  = [];
        foreach ($tasks as $t) $byId[(int) $t->id] = $t;

        $ready = 0; $running = 0;
        foreach ($tasks as $t) {
            $status = (string) $t->status;
            if ($status === 'running') { $running++; continue; }
            if ($status !== 'pending') continue;
            if ($this->depsMerged($t, $byId)) $ready++;
        }

        $blocked = $this->blockers($tasks, $byId);
        return [
            'ready'   => $ready,
            'running' => $running,
            'blocked' => $blocked,
            'roots'   => self::rootCauses($blocked),
        ];
    }

    /**
     * Post-build: apply the DB seed scripts the plan introduced (database/seeds/*.php)
     * against the LIVE instance, then rebuild the permission cache. Plan branches never
     * carry the binary DB (reapTask discards it), so DB / permission changes are
     * expressed as committed, idempotent seed scripts and applied here — once, ledgered
     * so a resumed orchestrator never double-applies. Returns human-readable log lines.
     */
    public function finalize(): array {
        // Every merge already ran the app's seeds in its container (AgentTask::merge → --build).
        return ['seeds: run in the container at each merge (' . $this->slug . ')'];
    }

    /**
     * Take the plan's rollback checkpoint before its first task runs — once per plan: a git tag
     * on the app's HEAD in its container plus a consistent copy of each of its databases
     * (checkpointTenant). A relaunch — a retry, a resumed build — keeps the first one: that IS the
     * before-the-plan point. A plan that cannot be checkpointed does not run (ok=false, logged).
     *
     * @return array{ok:bool, tag:string, message:string}
     */
    public function checkpointBeforeRun(): array {
        $plan = $this->plan();
        if (!$plan->id) return ['ok' => false, 'tag' => '', 'message' => "no plan #{$this->planId} in the tasks db"];
        $have = trim((string) ($plan->planCheckpoint ?? ''));
        if ($have !== '') return ['ok' => true, 'tag' => $have, 'message' => "checkpoint {$have} already taken for this plan — kept"];
        return $this->checkpointTenant($plan);
    }

    // ---- task lifecycle ----------------------------------------------------

    /** Start the subtask in the project's container. */
    private function launchTask($t): bool {
        return $this->launchTenantTask($t);
    }

    /** Agent finished: commit its changes, merge back, unlock dependents. */
    private function reapTask($t): void {
        $this->reapTenantTask($t);
    }

    /* ---- a project in its own container (TenantBuilder, RUNTIME-SPLIT-MAP.md step 5) ---------- */

    /** AgentTask's id for a subtask: [a-z0-9-], unique per plan and task. */
    private function tenantTaskId($t): string { return 'plan-' . $this->planId . '-task-' . (int) $t->id; }

    /**
     * The subtask runs IN THE APP'S CONTAINER: its own agent on its own credential, in a
     * worktree on task/<id> there (AgentTask), inside a detached session here whose ending
     * is the signal — the same contract as a local task, so runOnce() reaps it the same way.
     */
    private function launchTenantTask($t): bool {
        if ((string) $t->taskType === 'install') {
            $this->fail($t, "a plugin install is not built into a container yet — install it in the app: php scripts/tenant.php --clitool={$this->slug} -- --concept-install=NAME, then --concept-enable=NAME");
            return false;
        }
        $id = $this->tenantTaskId($t);
        try {
            $session = TmuxManager::buildPlanTaskSessionName($this->planId, (int) $t->id, $this->slug);
            // The app's agent the plan runs on (the builder's picker); '' = the app's default.
            $agent = PlanIngestor::agentName($t->agent ?? '');
            // In a tmux session IN the container (TenantRun): it outlives anything on core.
            TenantRun::start($this->tenant, $session, $id,
                '--agent-task=' . escapeshellarg($id) . TenantHost::agentArg($agent) . ' --timeout=1800', $this->buildTaskBrief($t), $this->author());
            $this->logEvent($t, 'info', "Build agent started in {$this->slug}'s container on " . ($agent !== '' ? "agent '{$agent}'" : "the app's default agent"));
        } catch (\Throwable $e) {
            $this->fail($t, 'could not start the task in the container: ' . $e->getMessage());
            return false;
        }
        $t->status         = 'running';
        $t->worktreeBranch = 'task/' . $id;   // the container's branch
        $t->agentSession   = $session;
        $t->startedAt      = date('Y-m-d H:i:s');
        Bean::store($t);
        $this->logEvent($t, 'info', "Build agent started in {$this->slug}'s container on task/{$id} (the app's own credential)");
        return true;
    }

    /** The container task's session ended: read its result, then merge (publish) or discard. */
    private function reapTenantTask($t): void {
        $id = $this->tenantTaskId($t);
        try {
            $run = TenantRun::result($this->tenant, $id);
        } catch (\RuntimeException $e) {
            $this->logEvent($t, 'error', 'could not read the result from the container (will retry): ' . $e->getMessage());
            return;
        }
        $r = $run['result'] ?? null;
        if ($r === null) {
            TenantHost::discardTask($this->tenant, $id);
            $why = $run === null ? 'its session ended before it finished (stopped, or the container restarted)'
                 : "it exited {$run['exit']} without an answer" . ($run['log'] !== '' ? ': ' . mb_substr($run['log'], -800) : '');
            $this->finish($t, 'failed', "the container task gave no result — {$why}");
            return;
        }
        $tail = trim((string) ($r['output'] ?? ''));
        if ($tail !== '') $this->logEvent($t, 'info', 'Agent output (tail): ' . mb_substr($tail, -1500));
        if (!empty($r['credential'])) $this->logEvent($t, 'info', 'Ran on ' . $r['credential']);
        $status = (string) ($r['status'] ?? '');
        if ($status === 'no-change') {
            TenantHost::discardTask($this->tenant, $id);
            $this->finish($t, 'resolved', "nothing to change — the task's goal was already satisfied, or the agent made no edits");
            return;
        }
        if ($status !== 'changed') {
            TenantHost::discardTask($this->tenant, $id);
            $this->finish($t, 'failed', (string) ($r['error'] ?? '') !== '' ? (string) $r['error'] : "the container task ended '{$status}'");
            return;
        }
        if (!empty($r['diffstat'])) $this->logEvent($t, 'info', "Changed ({$r['commit']}):\n" . $r['diffstat']);
        // Merging IS publishing: the app's branch in its container, then its seeds.
        try {
            $m = TenantHost::mergeTask($this->tenant, $id, $this->author());
        } catch (\RuntimeException $e) {
            $m = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!empty($m['ok'])) { $this->finish($t, 'merged', 'merged into the app as ' . ($m['merged'] ?? '?')); return; }
        $err = (string) ($m['error'] ?? 'the merge failed');
        $this->finish($t, str_contains($err, 'merge of task/') ? 'conflict' : 'failed', $err);
    }

    /**
     * The rollback point in the container: a tag on the app's HEAD and a consistent copy of
     * each of its databases (.aibuilder/backups/<tag>/), taken over SSH before the first task.
     */
    private function checkpointTenant($plan): array {
        $tag = 'checkpoint-plan-' . $this->planId . '-' . date('Ymd-His');
        try {
            $c = TenantHost::checkpoint($this->tenant, $tag, 'plan: ' . mb_substr((string) $plan->title, 0, 80), $this->author());
        } catch (\RuntimeException $e) {
            $c = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!$c['ok']) {
            $msg = $c['error'] . '; the plan will not run without a rollback point';
            $this->logEvent($plan, 'error', $msg);
            return ['ok' => false, 'tag' => '', 'message' => $msg];
        }
        $plan->planCheckpoint = $tag;
        $plan->updatedAt      = date('Y-m-d H:i:s');
        Bean::store($plan);
        $this->logEvent($plan, 'info', "Checkpoint {$tag} taken in the container before the first task (" . ($c['committed'] ? 'uncommitted changes committed, ' : '')
            . "git tag + .aibuilder/backups/{$tag}/: {$c['databases']}) — roll back to it if this plan goes wrong");
        return ['ok' => true, 'tag' => $tag, 'message' => "checkpoint {$tag} taken"];
    }

    /** The plan's member — the author of every commit its build makes (TenantHost::author). */
    private function author(): array {
        $memberId = (int) ($this->plan()->memberId ?? 0);
        if ($memberId <= 0) throw new \RuntimeException("plan #{$this->planId} records no member, so nobody can author its commits");
        return TenantHost::author($memberId);
    }

    // ---- readiness / status helpers ---------------------------------------

    /**
     * A dependency is satisfied when it MERGED or RESOLVED. Resolved means it produced
     * no diff because there was nothing to change — the prerequisite state exists, it
     * just already existed. Blocking on it would strand a whole plan behind a
     * verification that passed.
     */
    private function depsMerged($t, array $byId): bool {
        foreach ($this->deps($t) as $depId) {
            $dep = $byId[$depId] ?? null;
            if (!$dep || !in_array((string) $dep->status, ['merged', 'resolved'], true)) return false;
        }
        return true;
    }

    /**
     * Every pending task that cannot start, and the dependency stopping it.
     *
     * A stalled plan used to report the single word "stalled" and nothing else, so
     * the only way to learn WHY was to read the dependency JSON of every pending
     * task by hand against the status of every other. Observed on floorplan: seven
     * tasks frozen by one merge conflict and two tasks awaiting a person, with
     * nothing on screen naming any of the three.
     *
     * depsMerged() accepts merged and resolved. Everything else — failed, conflict,
     * awaiting, or a dependency that does not exist — is a dead end for whatever
     * sits downstream of it, which is exactly what this reports.
     *
     * @return array<int,array{task:int,title:string,blockers:string[]}>
     */
    private function blockers(array $tasks, array $byId): array {
        $ok  = ['merged', 'resolved'];
        $out = [];

        foreach ($tasks as $t) {
            if ((string) $t->status !== 'pending') continue;

            $why = [];
            foreach ($this->deps($t) as $depId) {
                $dep = $byId[$depId] ?? null;
                if (!$dep) {
                    $why[] = "#{$depId} (no such task in this plan)";
                    continue;
                }
                $st = (string) $dep->status;
                if (in_array($st, $ok, true)) continue;
                $why[] = sprintf('#%d %s — %s', (int) $dep->id, $st,
                    self::shorten((string) $dep->title));
            }
            if ($why) {
                $out[] = ['task' => (int) $t->id, 'title' => self::shorten((string) $t->title), 'blockers' => $why];
            }
        }
        return $out;
    }

    /**
     * The ROOT causes: blockers that are not themselves waiting on something else.
     *
     * A cascade reads as a wall of blocked tasks when only one or two of them are
     * actionable — fixing the root frees the chain. This is what belongs in a
     * one-line status.
     */
    public static function rootCauses(array $blocked): array {
        $blockedIds = [];
        foreach ($blocked as $b) $blockedIds[$b['task']] = true;

        $roots = [];
        foreach ($blocked as $b) {
            foreach ($b['blockers'] as $why) {
                if (!preg_match('/^#(\d+)\s+(\S+)/', $why, $m)) continue;
                // A pending blocker is itself blocked — not a root cause, just a
                // link in the chain.
                if ($m[2] === 'pending' && isset($blockedIds[(int) $m[1]])) continue;
                $roots[$why] = true;
            }
        }
        return array_keys($roots);
    }

    private static function shorten(string $s): string {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > 48 ? mb_substr($s, 0, 47) . '…' : $s;
    }

    private function anyLaunchable(array $tasks, array $byId): bool {
        foreach ($tasks as $t) {
            if ($t->status === 'pending' && $this->depsMerged($t, $byId)) return true;
        }
        return false;
    }

    private function deps($t): array {
        $d = json_decode(((string)$t->dependsOn) ?? '', true);
        return is_array($d) ? array_map('intval', $d) : [];
    }

    private function countByStatus(array $tasks, string $status): int {
        $n = 0; foreach ($tasks as $t) if ($t->status === $status) $n++; return $n;
    }

    private function finish($t, string $status, string $note): void {
        // Auto-retry: a retryable failure — under the cap and not repeating the exact
        // same error — gets its blocker corrected and is re-queued (status 'pending')
        // instead of failing terminally. The orchestrator loop then re-attempts it, so
        // it keeps trying toward the goal until it merges or the backstop trips.
        if ($status === 'failed' && $this->autoRetry($t, $note)) {
            return;
        }

        $t->status       = $status;
        $t->completedAt  = date('Y-m-d H:i:s');
        if ($note !== '') $t->errorMessage = mb_substr($note, 0, 1000);
        Bean::store($t);

        $labels = [
            'merged'   => ['info',    'Merged into the instance base branch'],
            'resolved' => ['info',    'Nothing to change — already satisfied'],
            'conflict' => ['warning', 'Merge conflict — left on its branch for manual review'],
            'failed'   => ['error',   'Build failed'],
        ];
        [$lvl, $msg] = $labels[$status] ?? ['info', 'Finished: ' . $status];
        if ($note !== '') $msg .= ' — ' . mb_substr($note, 0, 300);
        $this->logEvent($t, $lvl, $msg);
    }

    private function fail($t, string $note): void { $this->finish($t, 'failed', $note); }

    /**
     * Try to auto-correct a failed task and re-queue it. Returns true if it took
     * over (task set back to 'pending' for the orchestrator to re-launch), false to
     * let the caller fail it terminally. Bounded by MAX_AUTO_RETRIES; also stops
     * early when the exact same failure repeats (correction isn't helping → futile).
     */
    private function autoRetry($t, string $note): bool {
        $tri = $this->triage($note);
        if (!$tri['retryable']) return false;

        $retries = (int)$t->retryCount;
        if ($retries >= self::MAX_AUTO_RETRIES) {
            $this->logEvent($t, 'error', 'Gave up after ' . $retries . ' auto-retries (' . $tri['class'] . ')');
            return false;
        }
        if ((string)$t->lastFailReason === $note) {
            $this->logEvent($t, 'error', 'Auto-retry stopped — same failure repeated (' . $tri['class'] . '); correction did not resolve it');
            return false;
        }
        $t->retryCount     = $retries + 1;
        $t->lastFailReason = mb_substr($note, 0, 1000);
        $t->status         = 'pending';   // orchestrator re-launches it next tick
        $t->errorMessage   = '';
        Bean::store($t);
        $this->logEvent($t, 'warning', 'Auto-retry ' . ($retries + 1) . '/' . self::MAX_AUTO_RETRIES . ' — ' . $tri['class'] . ': ' . mb_substr($note, 0, 160));
        return true;
    }

    /** Classify a failure reason → correction class + whether it's auto-retryable. */
    private function triage(string $note): array {
        $n = strtolower($note);
        // Starting a run in the container is mechanical; a fresh launch is the retry. Merge
        // conflicts and code/logic failures are not — they need a rebase or a correction agent.
        if (strpos($n, 'could not start the task in the container') !== false) return ['class' => 'session', 'retryable' => true];
        return ['class' => 'unknown', 'retryable' => false];
    }

    /**
     * Append a tasklog row for a subtask so the Workbench "Recent Logs" panel
     * reflects the orchestrator path too — it previously only logged the manual
     * "Run with Claude" flow, leaving every plan-built task blank. Never throws:
     * a logging failure must not break the build loop.
     */
    private function logEvent($t, string $level, string $message): void {
        try {
            $log = Bean::dispense('tasklog');
            $log->taskId    = (int)$t->id;
            $log->memberId  = ((int)$t->memberId) ?: null;
            $log->logLevel  = $level;              // info | warning | error
            $log->logType   = 'orchestrator';
            $log->message   = $message;
            $log->createdAt = date('Y-m-d H:i:s');
            Bean::store($log);
        } catch (\Throwable $e) { /* swallow — logging is best-effort */ }
    }

    // ---- agent invocation --------------------------------------------------

    private function buildTaskBrief($t): string {
        $files = json_decode(((string)$t->relatedFiles) ?? '', true);
        $files = is_array($files) ? implode("\n", array_map(fn($f) => "- $f", $files)) : '';
        $title = (string)$t->title;
        $desc  = (string)$t->description;
        $reuse = $this->reuseBrief($t);
        return <<<MD
# Build task: {$title}

You are one of several agents building a larger plan. Implement ONLY this task, in
this working directory (a git worktree of the instance). Another process will
commit and merge your work — you just make the code changes.

## What to build

{$desc}

## Likely files

{$files}
{$reuse}
## Rules
- Follow the existing codebase conventions (FlightPHP controllers, RedBeanPHP via
  the Bean wrapper, the project's CLAUDE.md standards). Use the tiknix MCP
  (reuse_digest / codebase_map / whatprovides / describe) to check conventions
  before writing. If a "Reuse these" section is present above, build ON those
  primitives — extend them, do not create parallel duplicates.
- Stay within the scope of THIS task. Do not edit files owned by other tasks.
- Write any summary, notes, or final message in **Markdown** (`##` sub-headers,
  `-` lists, `` `code` `` for files/beans/routes) — it renders in the task view,
  so keep it scannable header-first.
- Do not run git, do not push, do not start servers of your own. Implement, CHECK YOUR
  WORK in your sandbox when you have one (the "Your sandbox" section at the very end of this
  brief says what you have — a running copy of the app at a local URL, on its own database —
  or that there is none and why), then stop. If no such section follows, this app cannot give
  you one: say in your summary what you could not check.
- Config: you CANNOT change this project's runtime settings from here, in either
  direction. `conf/config.ini` is what the app loads and is gitignored, so an edit to it
  never merges; `conf/config.<slug>.ini` is what merges and is NOT what the app loads.
  Editing either looks like it worked and changes nothing — a task that wrote a version
  key to the tracked file shipped an endpoint reporting "unknown" for it. If your task
  needs a setting that does not exist yet, NAME IT IN YOUR SUMMARY for a person to add.
  (Core's config is outside your sandbox entirely; you could not reach it if you tried.)
- Database / permission changes: only a SEED merges. Your worktree's database is the
  sandbox's own, thrown away with it, so anything written to it directly is lost. If this
  task needs a DB or permission change (e.g. an authcontrol route entry to make a page
  public), write an IDEMPOTENT numbered seed in services/Schema/Seeds/NN_Name.php (the
  CLAUDE.md convention; permissions through PermissionCache::seedRule), and apply it in
  YOUR SANDBOX to see it work (`php scripts/clitool.php --build`). The orchestrator runs
  the same build against the live instance after your work merges. A legacy standalone script in
  database/seeds/<descriptive-name>.php is also applied (once, ledgered); if you write
  one, use the \\app\\Bean wrapper (Bean::findOne / dispense / store).
  The seed file lives TWO levels below the instance root, so bootstrap the app with
  EXACTLY this (do not add a chdir, the CWD is already the instance root):
      require_once __DIR__ . '/../../vendor/autoload.php';
      \$app = new \\app\\Bootstrap();
  A wrong relative depth (e.g. '/../bootstrap.php') will fatal — the seed is two dirs deep.
- **NO FALLBACKS. Fail loudly.** If something you need is missing — a config key, a
  credential, a dependency, a column — raise, or log an ERROR that NAMES it. Do NOT return
  a placeholder ('unknown', 'default', 0, ''), do NOT substitute the nearest working
  alternative, and do NOT catch an exception just to keep going. A fallback turns a broken
  thing into a plausible answer, and a plausible answer gets believed instead of fixed.
  This is rule 6 in CLAUDE.md — read its "No Fallbacks" section before writing one.
  Concretely: an endpoint whose config key is absent must SAY SO, not answer `status: ok`
  with `"version": "unknown"`. That exact thing shipped from a build like this one, and the
  generated code's own comment described the fallback as deliberate.
  If a task needs a setting that does not exist yet, note it in your summary for a person to
  add. You may not create it yourself: conf files are off-limits per the rule above, and
  `conf/config.<slug>.ini` is NOT the file bootstrap loads — writing there looks like it
  worked and changes nothing.
MD;
    }

    /**
     * Expand a task's declared `reuses` (["controller/Lead","model/member",...])
     * into a focused detail block for the build brief, using the same
     * Introspector that backs the tiknix MCP tools — pointed at the instance base
     * (not the worktree) so it reflects the live primitives + schema. Deterministic:
     * the builder agent gets the exact routes/columns/methods of what it extends,
     * rather than being told to go look. Never throws — empty on any failure.
     */
    private function reuseBrief($t): string {
        $reuses = json_decode((string)$t->reuses, true);
        if (!is_array($reuses) || !$reuses) return '';
        // The code is in the app's container, not here: the agent looks each one up with the app's
        // own `describe` tool (its MCP server) rather than reading an inventory taken elsewhere.
        $items = array_values(array_filter(array_map(fn($r) => trim((string) $r), $reuses)));
        if (!$items) return '';
        return "\n## Reuse these — build on them, do not reinvent\n" . implode("\n", array_map(fn($r) => "- **{$r}**", $items))
             . "\n\nBefore writing code, call the `describe` tool (this app's MCP server) for each of these and extend them — do not add a new controller, model or lib where one of these fits.\n";
    }

    // ---- git plumbing ------------------------------------------------------

    private function sessionAlive(string $session): bool {
        if ($session === '') return false;
        // In the container (TenantRun). Unreachable is not "ended": reaping now would discard
        // a live task's work, so it counts as alive until the container answers.
        try { return TenantRun::alive($this->tenant, $session); }
        catch (\RuntimeException $e) { error_log('ERROR PlanExecutor: ' . $e->getMessage()); return true; }
    }

}
