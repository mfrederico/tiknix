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

        // Waves count how wide the work can run; they say nothing of how DEEP it must. Tasks
        // that depend on one another run one after the other whatever the concurrency, and
        // each may use its whole time limit: four tasks in a chain at 60 minutes each need up
        // to four hours, where two waves of measured cost budgeted under two (holistica plan 1).
        // The budget is never less than the longest such chain.
        $remaining = [];
        foreach ($this->subtasks() as $t) {
            if (!in_array((string) $t->status, ['pending', 'running'], true)) continue;
            $remaining[(int) $t->id] = ['deps' => array_map('intval', (array) (json_decode((string) $t->dependsOn, true) ?: [])), 'seconds' => $this->taskSeconds($t)];
        }
        $ticks = max($ticks, self::chainTicks($remaining));

        return min($ticks, self::MAX_BUDGET_TICKS);
    }

    /**
     * How long one task may run: its own limit, or its agent's Task time limit when that is
     * longer (the app applies the longer of the two — AgentTask::start; it reports each agent's
     * in its status report).
     */
    private function taskSeconds($t): int {
        $limit = self::timeLimit($t);
        $rj = json_decode((string) ($this->tenant->reportJson ?? ''), true);
        if (!is_array($rj)) return $limit;
        $name = trim((string) ($t->agent ?? '')) ?: (string) ($rj['default_agent'] ?? '');
        foreach ((array) ($rj['task_minutes'] ?? []) as $agent => $minutes) if ((string) $agent === $name) return max($limit, 60 * (int) $minutes);
        return $limit;
    }

    /**
     * Ticks (10 s each) for the longest dependency chain among the tasks still to run, each at its
     * full time limit plus a minute to merge. Dependencies on tasks no longer in the set are done.
     *
     * @param array<int,array{deps:int[],seconds:int}> $tasks
     */
    public static function chainTicks(array $tasks): int {
        $memo = [];
        $cost = function (int $id, array $seen) use (&$cost, &$memo, $tasks): int {
            if (isset($memo[$id])) return $memo[$id];
            if (isset($seen[$id])) return 0;   // a cycle cannot run at all; it is not this function's to report
            $seen[$id] = true;
            $before = 0;
            foreach ($tasks[$id]['deps'] as $d) if (isset($tasks[$d])) $before = max($before, $cost($d, $seen));
            return $memo[$id] = $before + (int) ceil(($tasks[$id]['seconds'] + 60) / 10);
        };
        $longest = 0;
        foreach (array_keys($tasks) as $id) $longest = max($longest, $cost($id, []));
        return $longest;
    }

    // ---- public API --------------------------------------------------------

    /** The plan (parent) bean. */
    public function plan() { return Bean::load('workbenchtask', $this->planId); }

    /** Subtasks of this plan, priority-ordered. */
    public function subtasks(): array {
        // RedBean keeps SELECT results in this process and drops them only when THIS process
        // writes. The orchestrator lives for hours and other processes change these rows (Retry
        // on the board, a stop, an edit): while it had nothing to write itself it kept reading
        // its own old answer, and a task reset to pending stayed "failed" to it forever. Every
        // read of the plan's tasks is therefore a real one.
        \RedBeanPHP\R::getWriter()->flushCache();
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
     * Post-build. Nothing is applied here: DB / permission changes ship as seeds in the app's
     * services/Schema/Seeds/, and every merge runs them in the container (AgentTask::merge →
     * --build). There is no second seed folder and no ledger — a task that writes one under
     * database/seeds/ is failed (straySeeds). Returns human-readable log lines.
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

    /**
     * A plugin install — the one task kind with no agent — runs to its end right here, in
     * seconds to minutes: the app's own `--concept-install` for each concept the task adopts
     * (the catalog resolved the order; the app fetches the bundle from the catalog with its
     * broker key), then the system software the installed plugins ask for (TenantHost::system —
     * the pdf plugin's Chrome, the mqtt plugin's broker; what `tenant.php --system` does), then
     * `--concept-enable` (verify, seeds, switch on, agent guidance), then ONE commit on the app's
     * branch as the member. No worktree and no merge: there is nothing an agent could have got
     * wrong, and the files are the catalog's. The task ends merged or failed — and a failure
     * leaves the app's tree as it was (concepts/, concepts.lock, connectors/, AGENTS.md + CLAUDE.md restored).
     */
    private function installInTenant($t): void {
        $names = json_decode((string) ($t->adopts ?? ''), true) ?: [];
        if (!$names) { $this->fail($t, 'the install task names no plugin (adopts is empty)'); return; }
        foreach ($names as $n) {
            if (!preg_match('/^[a-z][a-z0-9]*$/D', (string) $n)) { $this->fail($t, "'{$n}' is not a plugin name"); return; }
        }
        try { $env = TenantHost::gitEnv($this->author()); }
        catch (\RuntimeException $e) { $this->fail($t, $e->getMessage()); return; }

        // Every step in the container restores the tree on failure: a half-installed plugin would
        // otherwise block the app's next update (a dirty tree refuses --update).
        $restore = 'git checkout -q -- concepts.lock AGENTS.md CLAUDE.md 2>/dev/null; git clean -qfd concepts connectors 2>/dev/null';
        $run = function (array $steps, int $timeout) use ($restore): array {
            try {
                [$code, $out] = TenantHost::ssh($this->tenant, 'app', "cd /srv/app && ( " . implode(' && ', $steps) . " ) 2>&1 || { rc=\$?; {$restore}; exit \$rc; }", null, $timeout);
            } catch (\RuntimeException $e) { $code = 255; $out = $e->getMessage(); }
            return [$code, trim((string) $out)];
        };
        $last = fn(string $out): string => mb_substr(strrchr("\n" . $out, "\n") ?: $out, 1, 300);

        // 1. the files, from the catalog
        [$code, $out] = $run(array_map(fn($n) => 'php scripts/clitool.php --concept-install=' . escapeshellarg($n), $names), 600);
        if ($code !== 0) {
            $this->logEvent($t, 'error', "Install output:\n" . mb_substr($out, -1500));
            $this->fail($t, "the install failed in {$this->slug}'s container (exit {$code}): " . $last($out));
            return;
        }
        $this->logEvent($t, 'info', "Installed in {$this->slug}'s container:\n" . mb_substr($out, -1500));

        // 2. the system software the plugins ask for (requires.system), by core, as root
        try { $sys = TenantHost::system($this->tenant); }
        catch (\RuntimeException $e) { $sys = ['ok' => false, 'error' => $e->getMessage(), 'steps' => []]; }
        if (!empty($sys['steps'])) $this->logEvent($t, 'info', "System software:\n- " . implode("\n- ", $sys['steps']));
        if (!$sys['ok']) {
            try { TenantHost::ssh($this->tenant, 'app', 'cd /srv/app && ' . $restore, null, 60); } catch (\RuntimeException $e) {}
            $this->fail($t, "the system software the plugin needs could not be installed in {$this->slug}'s container: " . ($sys['error'] ?? 'unknown'));
            return;
        }

        // 3. switch on, and one commit
        $msg = 'Install plugin' . (count($names) > 1 ? 's' : '') . ': ' . implode(', ', $names);
        $steps = array_map(fn($n) => 'php scripts/clitool.php --concept-enable=' . escapeshellarg($n), $names);
        $steps[] = 'git add -A concepts concepts.lock connectors AGENTS.md CLAUDE.md';
        $steps[] = $env . 'git commit -q -m ' . escapeshellarg($msg);
        $steps[] = 'git rev-parse --short HEAD';
        [$code, $out] = $run($steps, 600);
        if ($code !== 0) {
            $this->logEvent($t, 'error', "Enable output:\n" . mb_substr($out, -1500));
            $this->fail($t, "the plugin could not be switched on in {$this->slug}'s container (exit {$code}): " . $last($out));
            return;
        }
        $this->logEvent($t, 'info', "Enabled in {$this->slug}'s container:\n" . mb_substr($out, -1500));
        $lines = explode("\n", $out);
        $this->finish($t, 'merged', 'installed and switched on in the app as ' . end($lines));
    }

    /** Agent finished: commit its changes, merge back, unlock dependents. */
    private function reapTask($t): void {
        $this->reapTenantTask($t);
    }

    /* ---- a project in its own container (TenantBuilder, RUNTIME-SPLIT-MAP.md step 5) ---------- */

    /** AgentTask's id for a subtask: [a-z0-9-], unique per plan and task. */
    /** A task's time limit: 30 minutes, or what a retry after running out of time raised it to. */
    public const TIME_LIMIT = 1800;
    public const TIME_LIMIT_MAX = 3600;

    public static function timeLimit($t): int {
        $l = (int) ($t->timeLimit ?? 0);
        return $l > 0 ? min(self::TIME_LIMIT_MAX, max(self::TIME_LIMIT, $l)) : self::TIME_LIMIT;
    }

    /** Did this failure note say the agent hit its time limit? (Both wordings: an app on an older runtime says "exited 124".) */
    public static function ranOutOfTime(string $note): bool {
        return str_contains($note, 'ran out of time') || (bool) preg_match('/\bexited 124\b/', $note);
    }

    private function tenantTaskId($t): string { return 'plan-' . $this->planId . '-task-' . (int) $t->id; }

    /**
     * The subtask runs IN THE APP'S CONTAINER: its own agent on its own credential, in a
     * worktree on task/<id> there (AgentTask), inside a detached session here whose ending
     * is the signal — the same contract as a local task, so runOnce() reaps it the same way.
     */
    private function launchTenantTask($t): bool {
        if ((string) $t->taskType === 'install') { $this->installInTenant($t); return false; }   // finished, not launched
        $id = $this->tenantTaskId($t);
        try {
            $session = TmuxManager::buildPlanTaskSessionName($this->planId, (int) $t->id, $this->slug);
            // The app's agent the plan runs on (the builder's picker); '' = the app's default.
            $agent = PlanIngestor::agentName($t->agent ?? '');
            // In a tmux session IN the container (TenantRun): it outlives anything on core.
            TenantRun::start($this->tenant, $session, $id,
                '--agent-task=' . escapeshellarg($id) . TenantHost::agentArg($agent) . ' --timeout=' . self::timeLimit($t), $this->buildTaskBrief($t), $this->author());
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
        // What the app noticed about the work (an unlinked page): on the task, where a person reads it.
        foreach ((array) ($r['notes'] ?? []) as $note) $this->logEvent($t, 'warning', 'Unlinked page — ' . (string) $note);
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
        // A seed outside services/Schema/Seeds/ is never applied: merging it would publish pages
        // whose permission rows and tables do not exist on the live app (holistica: five of them).
        if (($stray = self::straySeeds((string) ($r['diffstat'] ?? ''))) !== []) {
            TenantHost::discardTask($this->tenant, $id);
            $this->finish($t, 'failed', 'the task wrote ' . implode(', ', $stray) . ' — nothing applies a seed there, so it would never reach the live app. '
                . 'Write it as services/Schema/Seeds/NN_Name.php (NN from 50 up; no autoloader or Bootstrap lines — the build includes it inside the app).');
            return;
        }
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

    /** Seed scripts a task wrote where nothing applies them (database/seeds/), from its diffstat. @return string[] */
    public static function straySeeds(string $diffstat): array {
        preg_match_all('#^\s*(database/seeds/\S+\.php)\s*\|\s*\d+ \++#m', $diffstat, $m);   // lines with additions; a pure removal is a move away
        return array_values(array_unique($m[1]));
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

    /**
     * What the tasks this one depends on built: their title, the files they changed and the
     * summary their agent wrote — read from each task's own log. A task used to start knowing
     * only its own description, and spent its first minutes rediscovering what the task before
     * it had just made.
     *
     * @return array<int,array{id:int,title:string,files:string[],summary:string}>
     */
    private function priorWork($t): array {
        $out = [];
        foreach (array_map('intval', (array) (json_decode((string) $t->dependsOn, true) ?: [])) as $id) {
            $dep = Bean::load('workbenchtask', $id);
            if (!$dep->id || !in_array((string) $dep->status, ['merged', 'completed', 'resolved'], true)) continue;
            $files = []; $summary = '';
            foreach (Bean::find('tasklog', 'task_id = ? ORDER BY id DESC', [$id]) as $log) {
                $m = (string) $log->message;
                if (!$files && str_starts_with($m, 'Changed (')) {
                    preg_match_all('/^\s*(\S+)\s+\|/m', $m, $mm);
                    $files = $mm[1];
                }
                if ($summary === '' && str_starts_with($m, 'Agent output (tail): ')) $summary = self::handoffOf(substr($m, 21));
                if ($files && $summary !== '') break;
            }
            $out[] = ['id' => $id, 'title' => (string) $dep->title, 'files' => $files, 'summary' => $summary];
        }
        return $out;
    }

    /**
     * What a finished task's output says to the NEXT agent: its "## Handoff" section when it wrote
     * one (the brief asks for it, last), else the end of its message. The note about Claude Code's
     * model catalog is not part of what the agent said.
     */
    public static function handoffOf(string $output): string {
        $out = trim((string) preg_replace('/^\(Not an error: Claude Code has no catalog entry.*$/m', '', $output));
        $at = strripos($out, '## Handoff');
        return $at !== false ? trim(substr($out, $at + strlen('## Handoff'))) : $out;
    }

    /** The brief's section for priorWork() — '' when the task depends on nothing finished. Bounded: it is context, not the task. */
    public static function priorWorkSection(array $prior): string {
        if (!$prior) return '';
        $md = "\n## Already built — the tasks this one depends on\n\n"
            . "These are merged and in your working directory. Build ON them: read the files named here first, and do not rebuild or duplicate what they made.\n";
        $budget = 6000;
        foreach ($prior as $p) {
            $block = "\n### #{$p['id']} " . trim($p['title']) . "\n";
            if ($p['files']) $block .= 'Files: ' . implode(', ', array_map(fn($f) => "`{$f}`", array_slice($p['files'], 0, 25))) . (count($p['files']) > 25 ? ', …' : '') . "\n";
            if ($p['summary'] !== '') {
                // The END of its summary is where an agent says what it made and how to use it.
                $s = mb_strlen($p['summary']) > 1200 ? '…' . mb_substr($p['summary'], -1200) : $p['summary'];
                $block .= "\nWhat its agent left for you:\n\n> " . str_replace("\n", "\n> ", trim($s)) . "\n";
            }
            if (mb_strlen($block) > $budget) { $md .= "\n(" . (count($prior)) . " tasks in all — the rest are in the code; see `git log`.)\n"; break; }
            $md .= $block; $budget -= mb_strlen($block);
        }
        return $md;
    }

    private function buildTaskBrief($t): string {
        $files = json_decode(((string)$t->relatedFiles) ?? '', true);
        $files = is_array($files) ? implode("\n", array_map(fn($f) => "- $f", $files)) : '';
        $title = (string)$t->title;
        $desc  = (string)$t->description;
        $reuse = $this->reuseBrief($t);
        $prior = self::priorWorkSection($this->priorWork($t));
        return <<<MD
# Build task: {$title}

You are one of several agents building a larger plan. Implement ONLY this task, in
this working directory (a git worktree of the instance). Another process will
commit and merge your work — you just make the code changes.

## What to build

{$desc}

## Likely files

{$files}
{$reuse}{$prior}
## Rules
- Follow the existing codebase conventions (FlightPHP controllers, RedBeanPHP via
  the Bean wrapper, the project's AGENTS.md standards). Use the tiknix MCP
  (reuse_digest / codebase_map / whatprovides / describe) to check conventions
  before writing. If a "Reuse these" section is present above, build ON those
  primitives — extend them, do not create parallel duplicates.
- Stay within the scope of THIS task. Do not edit files owned by other tasks.
- A page you add must be REACHABLE by clicking: add it to the app's menu or link it from the
  page it belongs to (AGENTS.md, "Navigation"). Run `full_validation` on each controller you
  write — it reports a page that nothing links to. A page deliberately left unlinked (a
  webhook, an API) says so in its controller: `// nav: none — <why>`.
- Write any summary, notes, or final message in **Markdown** (`##` sub-headers,
  `-` lists, `` `code` `` for files/beans/routes) — it renders in the task view,
  so keep it scannable header-first.
- END your final message with a section headed exactly `## Handoff`, 3–8 lines, written for
  the NEXT agent, who starts knowing nothing of this session: what now exists (the classes,
  routes, tables and views you added, by name), how to use it (the call to make, the page to
  link to), and any trap you hit that they would hit too. It is handed to every task that
  depends on this one, so it must be the LAST thing you write, and it must be true.
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
  AGENTS.md convention; permissions through PermissionCache::seedRule), and apply it in
  YOUR SANDBOX to see it work (`php scripts/clitool.php --build`). The orchestrator runs
  the same build against the live instance when your work merges, and on every update.
  services/Schema/Seeds/ is the ONLY folder that is applied: a seed anywhere else —
  database/seeds/ above all, even if this task's description names it — never reaches the
  live app, and the task is failed for it. Number yours from 50 up (the platform's own
  seeds use the lower numbers and run in the same sequence). The build INCLUDES the file
  inside the running app: use \\app\\Bean and \\app\\PermissionCache directly, and do not
  require the autoloader or create a Bootstrap in it.
- **NO FALLBACKS. Fail loudly.** If something you need is missing — a config key, a
  credential, a dependency, a column — raise, or log an ERROR that NAMES it. Do NOT return
  a placeholder ('unknown', 'default', 0, ''), do NOT substitute the nearest working
  alternative, and do NOT catch an exception just to keep going. A fallback turns a broken
  thing into a plausible answer, and a plausible answer gets believed instead of fixed.
  This is rule 6 in AGENTS.md — read its "No Fallbacks" section before writing one.
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
