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
            // ...and per agent: an agent that runs one task at a time (its "Tasks at once") works
            // through its share in rounds of one, whatever the model allows.
            $own = $this->agentCap($this->agentOf($t));
            $key = $eng . ':' . $mdl . '|' . $this->agentOf($t);
            if (!isset($groups[$key])) {
                $cap = EngineRegistry::maxConcurrency($eng, $mdl);
                if ($own > 0) $cap = $cap > 0 ? min($cap, $own) : $own;
                // No declared provider limit: our own cap is the only one that applies.
                $groups[$key] = ['n' => 0, 'cap' => max(1, min(self::MAX_CONCURRENT, $cap > 0 ? $cap : self::MAX_CONCURRENT))];
            }
            $groups[$key]['n']++;
        }

        // Each group carries its own measured per-task cost, so a plan mixing a fast
        // engine and a slow one is budgeted for what each actually takes.
        $ticks = 0;
        foreach ($groups as $key => $g) {
            [$eng, $mdl] = array_pad(explode(':', explode('|', $key, 2)[0], 2), 2, '');
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

    /** The app's agent a task runs on: the one set on it, else the app's builder (as the app last reported it). */
    private function agentOf($t): string {
        $name = trim((string) ($t->agent ?? ''));
        if ($name !== '') return $name;
        $rj = json_decode((string) ($this->tenant->reportJson ?? ''), true);
        return is_array($rj) ? (string) ($rj['default_agent'] ?? '') : '';
    }

    /**
     * How many tasks may run on one of the app's agents at once — the agent's own "Tasks at once",
     * from the app's status report. 0 = it set none (MAX_CONCURRENT is then the only bound).
     */
    private function agentCap(string $agent): int {
        return self::capOf(json_decode((string) ($this->tenant->reportJson ?? ''), true), $agent);
    }

    /** The cap `$agent` set for itself in an app's report headline (Model_Projectreport::headline's `parallel`); 0 = none. */
    public static function capOf($report, string $agent): int {
        if (!is_array($report) || $agent === '') return 0;
        foreach ((array) ($report['parallel'] ?? []) as $name => $n) if ((string) $name === $agent) return max(0, (int) $n);
        return 0;
    }

    /**
     * May one more task start on an agent that already runs `$running`? The rule the launch loop
     * applies per agent: no cap of its own = yes (the plan-wide cap decides).
     */
    public static function agentHasRoom(int $running, int $cap): bool {
        return $cap <= 0 || $running < $cap;
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
        // 1b) Reviews (Plan and Fan, step three): a risky task's second reading, running beside the build.
        foreach ($tasks as $t) {
            if ((json_decode((string) ($t->reviewJson ?? ''), true)['state'] ?? '') === 'running') $this->collectReview($t);
            if ((json_decode((string) ($t->raceJson ?? ''), true)['state'] ?? '') === 'judging') $this->collectJudge($t);
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
         * saturated provider must not block a task bound for another.
         *
         * And per AGENT (its "Tasks at once" on the app's AI agents page): the app's agents
         * are separate accounts on separate providers, so a task set to a second Build agent
         * runs beside one that is waiting its turn on the first. */
        $fresh   = $this->subtasks();
        $running = $this->countByStatus($fresh, 'running');
        $perEngine = [];
        $perAgent  = [];
        foreach ($fresh as $t) {
            // A review runs on the task's own agent and model: it takes a place of the provider's and the agent's, as a task does.
            if ($t->status !== 'running' && (json_decode((string) ($t->reviewJson ?? ''), true)['state'] ?? '') !== 'running') continue;
            $e = (string) ($t->engine ?: EngineRegistry::defaultEngine()) . ':' . (string) ($t->model ?? '');
            $perEngine[$e] = ($perEngine[$e] ?? 0) + 1;
            $a = $this->agentOf($t);
            $perAgent[$a] = ($perAgent[$a] ?? 0) + 1;
        }

        // Plan and Fan, step five: what this request has spent on each agent, against the agent's
        // "Spend per request". An agent whose budget is used starts no more of its tasks.
        $spent = $this->spentByAgent($fresh);
        $this->warnNearBudget($spent);
        $overBudget = [];
        $slots = self::MAX_CONCURRENT - $running;
        // A task split itself and this plan waits for a person: what is running finishes, nothing new starts.
        $held = $this->heldForSplit();
        if ($held) $slots = 0;
        if ($slots > 0) {
            // Plan and Fan, step two: ready tasks start in rank order (PlanRank::order) — the chain the
            // whole request waits on first, then what most tasks wait on, then the riskiest.
            $rankOf = fn($t) => json_decode((string) ($t->rankJson ?? ''), true) ?: [];
            $ids = PlanRank::order(array_map(fn($t) => ['id' => (int) $t->id, 'deps' => $this->deps($t), 'effort' => $rankOf($t)['effort'] ?? null,
                                                        'risk' => $rankOf($t)['risk'] ?? null, 'priority' => (int) $t->priority], array_values($fresh)));
            $byFresh = []; foreach ($fresh as $t) $byFresh[(int) $t->id] = $t;
            foreach ($ids as $tid) {
                $t = $byFresh[$tid];
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

                $ag    = $this->agentOf($t);
                if (self::overBudget($spent[$ag] ?? 0, self::budgetOf(json_decode((string) ($this->tenant->reportJson ?? ''), true), $ag))) { $overBudget[$ag] = true; continue; }
                $agCap = $this->agentCap($ag);
                if (!self::agentHasRoom($perAgent[$ag] ?? 0, $agCap)) continue;   // that agent is busy; another agent's task may still start

                if ($this->launchTask($t)) {
                    $slots--;
                    $perEngine[$key] = ($perEngine[$key] ?? 0) + 1;
                    $perAgent[$ag]   = ($perAgent[$ag] ?? 0) + 1;
                }
            }
        }

        // 3) Roll up plan state — from what is true NOW. A task whose earlier work was merged in
        // step 2 (no agent started, nothing running) had still read 'pending' in the list taken
        // at the top, so everything waiting on it looked blocked and the plan was called stalled
        // in the same pass that unblocked it (inresonance plan #265, 2026-10-10).
        $fresh = $this->subtasks();
        $byId  = [];
        foreach ($fresh as $t) $byId[(int) $t->id] = $t;
        $counts = ['pending'=>0,'running'=>0,'merged'=>0,'resolved'=>0,'failed'=>0,'conflict'=>0];
        foreach ($fresh as $t) { $counts[$t->status] = ($counts[$t->status] ?? 0) + 1; }
        $total    = count($fresh);
        $terminal = ($counts['merged'] + $counts['resolved'] + $counts['failed'] + $counts['conflict']);
        // A review still reading a merged task holds the plan open: what it finds is built in this plan.
        $reviewing = $this->reviewing($fresh);
        $done     = ($total > 0 && $terminal >= $total && $reviewing === 0);
        // Deadlock guard: nothing running, nothing launchable, but not all merged.
        $stalled  = (!$done && $counts['running'] === 0 && $reviewing === 0 && $counts['pending'] > 0
                     && !$this->anyLaunchable($fresh, $byId));

        // Its budget is spent and nothing is left running: it waits for its person, who raises the
        // budget or presses Build to go on past it (clearBudgetHold) — never a quiet stop, never "stalled".
        if ($overBudget && !$done && $counts['running'] === 0 && $reviewing === 0) {
            $this->holdForBudget($spent, array_keys($overBudget));
            return ['done' => true, 'stalled' => false, 'held' => true, 'budget' => array_keys($overBudget), 'counts' => $counts, 'total' => $total, 'blocked' => []];
        }
        if ($held && !$done) {
            // Not stalled: stopped on purpose. Done for this run once nothing is running.
            return ['done' => $counts['running'] === 0 && $reviewing === 0, 'stalled' => false, 'held' => true, 'counts' => $counts, 'total' => $total, 'blocked' => []];
        }
        // Tasks wait on an answer from their person, and everything that could be built without it
        // has been: that is a pause for them, not a deadlock — no "stalled", no re-plan, no check.
        $asking = count(array_filter($fresh, fn($t) => (string) $t->status === 'awaiting' && trim((string) ($t->askedJson ?? '')) !== ''));
        if ($asking > 0 && !$done && $counts['running'] === 0 && $reviewing === 0 && !$this->anyLaunchable($fresh, $byId)) {
            return ['done' => true, 'stalled' => false, 'held' => true, 'asking' => $asking, 'counts' => $counts, 'total' => $total, 'blocked' => []];
        }
        return ['done' => $done || $stalled, 'stalled' => $stalled, 'held' => false, 'counts' => $counts, 'total' => $total,
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
        $r = $this->installPlugins($t, $names);
        if (!$r['ok']) { $this->fail($t, $r['error']); return; }
        $this->finish($t, 'merged', 'installed and switched on in the app as ' . $r['commit']);
    }

    /**
     * Install and switch on plugins in the app, as one commit by the member — the steps an
     * install task is, usable before a build task too (adoptFirst()). A failure leaves the app's
     * tree as it was. Each step is said on the task.
     * @return array{ok:bool, commit?:string, error?:string}
     */
    private function installPlugins($t, array $names): array {
        foreach ($names as $n) {
            if (!preg_match('/^[a-z][a-z0-9]*$/D', (string) $n)) return ['ok' => false, 'error' => "'{$n}' is not a plugin name"];
        }
        try { $env = TenantHost::gitEnv($this->author()); }
        catch (\RuntimeException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }

        // Every step in the container restores the tree on failure: a half-installed plugin would
        // otherwise block the app's next update (a dirty tree refuses --update). concepts.lock is
        // cleaned too: on an app's FIRST plugin it is a new file, which a checkout does not remove.
        $restore = 'git checkout -q -- concepts.lock AGENTS.md CLAUDE.md 2>/dev/null; git clean -qfd -- concepts connectors concepts.lock 2>/dev/null';
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
            return ['ok' => false, 'error' => "the install failed in {$this->slug}'s container (exit {$code}): " . $last($out)];
        }
        $this->logEvent($t, 'info', "Installed in {$this->slug}'s container:\n" . mb_substr($out, -1500));

        // 2. the system software the plugins ask for (requires.system), by core, as root
        try { $sys = TenantHost::system($this->tenant); }
        catch (\RuntimeException $e) { $sys = ['ok' => false, 'error' => $e->getMessage(), 'steps' => []]; }
        if (!empty($sys['steps'])) $this->logEvent($t, 'info', "System software:\n- " . implode("\n- ", $sys['steps']));
        if (!$sys['ok']) {
            try { TenantHost::ssh($this->tenant, 'app', 'cd /srv/app && ' . $restore, null, 60); } catch (\RuntimeException $e) {}
            return ['ok' => false, 'error' => "the system software the plugin needs could not be installed in {$this->slug}'s container: " . ($sys['error'] ?? 'unknown')];
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
            return ['ok' => false, 'error' => "the plugin could not be switched on in {$this->slug}'s container (exit {$code}): " . $last($out)];
        }
        $this->logEvent($t, 'info', "Enabled in {$this->slug}'s container:\n" . mb_substr($out, -1500));
        $lines = explode("\n", $out);
        return ['ok' => true, 'commit' => (string) end($lines)];
    }

    /**
     * The plugins a BUILD task adopts, installed in the app before its agent starts.
     *
     * The planner is told that what a task `adopts` is in its working copy when it begins, so the
     * task is to wire the plugin in, not to fetch it. Nothing did that for a project in its own
     * container: a task that adopted the CRM started in a copy with no CRM and no catalog, said
     * so, changed nothing — and the plan read "done" (dryrun, 2026-10-12). So: what the task
     * adopts and the app lacks is installed and switched on first (requirements first, resolved
     * from the catalog), as its own commit; the task's copy is then made from an app that has it.
     * '' = nothing to do or done; otherwise why it could not be, and the task does not start.
     */
    private function adoptFirst($t): string {
        $names = array_values(array_filter(array_map('strval', json_decode((string) ($t->adopts ?? ''), true) ?: [])));
        if (!$names) return '';
        foreach ($names as $n) if (!preg_match('/^[a-z][a-z0-9]*$/D', $n)) return "'{$n}' is not a plugin name";
        try {
            [$code, $out] = TenantHost::ssh($this->tenant, 'app', 'cd /srv/app && ls -1 concepts 2>/dev/null; true', null, 30);
        } catch (\RuntimeException $e) { return "could not ask {$this->slug} which plugins it has: " . $e->getMessage(); }
        if ($code !== 0) return "could not ask {$this->slug} which plugins it has (exit {$code})";
        $have = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
        try { $order = self::pluginOrder($names, $have, fn(string $n) => (array) (ConceptCatalog::forInstall()->get($n)['requires']['concepts'] ?? [])); }
        catch (\Throwable $e) { return 'the plugins this task adopts could not be resolved in the catalog: ' . $e->getMessage(); }
        if (!$order) return '';
        $this->logEvent($t, 'info', 'This task adopts ' . implode(', ', $names) . ': installing ' . implode(', ', $order) . ' in the app before its agent starts.');
        $r = $this->installPlugins($t, $order);
        return $r['ok'] ? '' : $r['error'];
    }

    /**
     * What to install, requirements first: $names and everything they require ($requires gives
     * one plugin's requirements), without what the app already has. A loop in the requirements throws.
     * @return string[]
     */
    public static function pluginOrder(array $names, array $have, callable $requires): array {
        $order = [];
        $visit = function (string $n, array $trail) use (&$visit, &$order, $have, $requires) {
            if (in_array($n, $trail, true)) throw new \RuntimeException('plugin requirements loop: ' . implode(' → ', [...$trail, $n]));
            if (in_array($n, $order, true) || in_array($n, $have, true)) return;
            foreach ($requires($n) as $req) $visit((string) $req, [...$trail, $n]);
            $order[] = $n;
        };
        foreach ($names as $n) $visit((string) $n, []);
        return $order;
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
        // Its work is ALREADY built and was only refused at the merge (the app had uncommitted
        // changes at that moment): merge that, do not build it again. Starting a second agent was
        // refused anyway ("already has a worktree"), so Retry could never get such a task moving.
        // (A task that ran out of time is not this: its kept worktree is resumed, below.)
        if (empty($t->resumable)) {
            try { $built = TenantHost::builtWork($this->tenant, $id); }
            catch (\Throwable $e) { $this->fail($t, 'could not check the container for this task\'s earlier work: ' . $e->getMessage()); return false; }
            if ($built !== '') {
                $this->logEvent($t, 'info', "An earlier attempt already built this ({$built}) and was stopped only at the merge — merging that work instead of building it again.");
                try { $m = TenantHost::mergeTask($this->tenant, $id, $this->author()); }
                catch (\RuntimeException $e) { $m = ['ok' => false, 'error' => $e->getMessage()]; }
                if (!empty($m['ok'])) { $this->finish($t, 'merged', 'merged into the app as ' . ($m['merged'] ?? '?') . ' (work built by an earlier attempt)'); $this->startReview($t, (string) ($m['merged'] ?? '')); return false; }
                $err = (string) ($m['error'] ?? 'the merge failed');
                if ($this->catchUp($t, $err)) return false;   // pending again: the next tick starts its catch-up run
                $this->finish($t, str_contains($err, 'merge of task/') ? 'conflict' : 'failed', $err . ' — its built work is kept; Retry merges it.');
                return false;
            }
        }
        // Critical (Plan and Fan, step five): built twice at once — this run and a second attempt beside it.
        if (empty($t->resumable)) $this->maybeRace($t);
        // What the task adopts is put in the app first (adoptFirst): its agent starts with the plugin there.
        if (empty($t->resumable) && ($why = $this->adoptFirst($t)) !== '') { $this->fail($t, 'the plugin this task adopts could not be installed first: ' . $why); return false; }
        try {
            $session = TmuxManager::buildPlanTaskSessionName($this->planId, (int) $t->id, $this->slug);
            // The app's agent the plan runs on (the builder's picker); '' = the app's default.
            $agent = PlanIngestor::agentName($t->agent ?? '');
            // In a tmux session IN the container (TenantRun): it outlives anything on core.
            $resume = !empty($t->resumable);   // an attempt that ran out of time left its work behind
            // …or it is built, and the app changed under it (catchUp()). Sent WITH --resume: an app on a
            // runtime from before --catch-up then resumes in the kept copy, where without it the run
            // would be refused ("already has a worktree") and the built work discarded as a failure.
            $catchUp = $resume && !empty($t->catchUp);
            // Small and safe (Plan and Fan): on the agent's fast model, where it has a different one —
            // when switched on. OFF by default: on the first real tasks (2026-10-14, sunrise-bakery) the
            // fast model took 5–7 times as long and 10–18 times the tokens of the main model on the
            // same kind of small task, wandering through dozens of turns. It is for step four (what
            // each model really costs a project) to say where it pays: aibuilder.ini [plan] fast_model_for_small.
            $fast = self::fastForSmall() && PlanRank::fast(json_decode((string) ($t->rankJson ?? ''), true) ?: []);
            TenantRun::start($this->tenant, $session, $id,
                '--agent-task=' . escapeshellarg($id) . TenantHost::agentArg($agent) . ' --timeout=' . self::timeLimit($t) . ($resume ? ' --resume' : '') . ($catchUp ? ' --catch-up' : '') . ($fast ? ' --fast' : '') . TenantBrowser::sandboxArg($this->tenant), $this->buildTaskBrief($t) . ((int) ($t->raceOf ?? 0) > 0 ? TaskRace::rivalNote() : ''), $this->author());
            $this->logEvent($t, 'info', ($catchUp ? 'Build agent sent back to bring this task up to date with the app' : ($resume ? 'Build agent RESUMED (continuing the earlier attempt\'s work)' : 'Build agent started')) . " in {$this->slug}'s container on " . ($agent !== '' ? "agent '{$agent}'" : "the app's default agent"));
            if ($resume) { $t->resumable = 0; $t->catchUp = 0; Bean::store($t); }
        } catch (\Throwable $e) {
            $this->fail($t, 'could not start the task in the container: ' . $e->getMessage());
            return false;
        }
        $t->status         = 'running';
        // Marked as more than one task: say what its agent is doing, so the board does not just read "running".
        if ($catchUp) {
            $t->progressMessage = 'Built. The app changed under it while it was building: its agent is bringing the two together, then it is merged.';
        } elseif (!empty($t->needsPlanning) && (string) ($t->planningNote ?? '') === self::UNPLANNED) {
            $t->progressMessage = 'Its agent is reading your app and sizing this up: it builds it if it is one piece of work, or splits it into steps if it is several.';
        } elseif (!empty($t->needsPlanning) && (int) ($t->splitDepth ?? 0) < self::MAX_SPLIT_DEPTH) {
            $t->progressMessage = 'Marked as more than one task: its agent is finding out first, then it splits it into pieces — or builds it as one if it turns out small.';
        }
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
        // What this run cost, added to the task's total (app\RunStats) and said on its log.
        if (is_array($r['stats'] ?? null)) {
            $total = RunStats::add(json_decode((string) ($t->statsJson ?? ''), true) ?: [], $r['stats']);
            $t->statsJson = json_encode($total);
            Bean::store($t);
            $this->logEvent($t, 'info', 'This run: ' . RunStats::line(RunStats::add([], $r['stats'])) . ((int) $total['attempts'] > 1 ? "\nTask so far: " . RunStats::line($total) : ''));
        }
        if (!empty($r['credential'])) $this->logEvent($t, 'info', 'Ran on ' . $r['credential']);
        if (!empty($r['fast'])) $this->logEvent($t, 'info', 'Built on the fast model: it was scored small and low-risk.');
        // What the app noticed about the work (an unlinked page): on the task, where a person reads it.
        foreach ((array) ($r['notes'] ?? []) as $note) $this->logEvent($t, 'warning', (string) $note);   // each note says what kind it is (AgentTask)
        $status = (string) ($r['status'] ?? '');
        // It stopped to ask its person something (AgentTask::QUESTION_FILE). That is neither done
        // nor failed: the task waits, its copy kept, and the answer continues it (Retry → --resume).
        if (($question = trim((string) ($r['question'] ?? ''))) !== '' && in_array($status, ['changed', 'no-change'], true)) {
            $this->ask($t, $question);
            return;
        }
        if ($status === 'no-change') {
            TenantHost::discardTask($this->tenant, $id);
            // It may have changed nothing because it split itself: then THAT is what happened to it, and
            // "nothing to change" on a task whose four pieces sit below it told the owner the opposite.
            $pieces = $this->applySplit($t, $r['split'] ?? null);
            if ($pieces) {
                $this->saySplitOutcome($t, 'split ' . count($pieces));
                $this->finish($t, 'resolved', 'split into ' . count($pieces) . ' tasks that take its place in this plan: #' . implode(', #', $pieces));
            } elseif (($unmet = self::unmetChecks((string) ($r['output'] ?? ''))) !== []) {
                // It changed nothing and says its own checks do NOT hold: that is a task that could
                // not do its work, not one whose work was already done. Calling it "nothing to
                // change" let a plan read "done" with nothing built.
                TenantHost::discardTask($this->tenant, $id);
                $this->finish($t, 'failed', 'it changed nothing, and its own checks did not pass: ' . implode(' · ', array_slice($unmet, 0, 3))
                    . (count($unmet) > 3 ? ' · and ' . (count($unmet) - 3) . ' more' : '') . '. What stopped it is in its output above.');
            } else {
                $this->finish($t, 'resolved', "nothing to change — the task's goal was already satisfied, or the agent made no edits");
            }
            return;
        }
        if ($status !== 'changed') {
            $why = (string) ($r['error'] ?? '') !== '' ? (string) $r['error'] : "the container task ended '{$status}'";
            // Sent back to be brought up to date with the app, and it could not be: its built work
            // is intact in its copy (AgentTask put it back) and is NOT thrown away. A person decides.
            if (!empty($r['unresolved'])) {
                $this->finish($t, 'conflict', $why . ' What its agent said is in the output above. Retry sends it back once more; or settle it by hand in the app (branch ' . 'task/' . $id . ').');
                return;
            }
            // Ran out of time WITH work done: the worktree is kept and the retry continues in it
            // (AgentTask resume). Thrown away, holistica's task #5 cost 30 minutes and then 27 more
            // for the same work. Any other failure leaves nothing worth keeping.
            if (!empty($r['resumable'])) {
                $t->resumable = 1;
                $this->finish($t, 'failed', $why . ' Its work so far is kept (' . ($r['commit'] ?? '?') . '): Retry continues from there instead of starting over.');
                return;
            }
            TenantHost::discardTask($this->tenant, $id);
            $t->resumable = 0;
            $this->finish($t, 'failed', $why);
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
        // In a race (TaskRace): it waits for its rival, its work kept on its branch; the race decides what is merged.
        if ($this->racing($t)) {
            $t->status = 'built'; $t->progressMessage = 'Built. Waiting for the other attempt to finish; one of the two is kept.';
            $t->completedAt = date('Y-m-d H:i:s'); Bean::store($t);
            $this->logEvent($t, 'info', 'Built (' . ($r['commit'] ?? '?') . '). In a race with ' . ((int) ($t->raceOf ?? 0) > 0 ? "the first attempt, #{$t->raceOf}" : 'its second attempt') . ': nothing is merged until both have finished.');
            $this->raceSettle($t);
            return;
        }
        // Merging IS publishing: the app's branch in its container, then its seeds.
        try {
            $m = TenantHost::mergeTask($this->tenant, $id, $this->author());
        } catch (\RuntimeException $e) {
            $m = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!empty($m['ok'])) {
            $this->finish($t, 'merged', 'merged into the app as ' . ($m['merged'] ?? '?'));
            $this->addToNotebook($t, (string) ($r['output'] ?? ''));
            $this->startReview($t, (string) ($m['merged'] ?? ''));
            $pieces = $this->applySplit($t, $r['split'] ?? null);
            if ($pieces) $this->saySplitOutcome($t, 'split ' . count($pieces));
            elseif (!empty($t->needsPlanning)) {
                // Marked as several, built as one: the agent's call, and it is told to say why. Said on the task.
                $this->saySplitOutcome($t, 'one');
                $this->logEvent($t, 'info', 'This was marked as more than one task. Its agent looked first and built it as ONE piece (its Handoff, in the output above, says why it did not need splitting).');
            }
            return;
        }
        $err = (string) ($m['error'] ?? 'the merge failed');
        if ($this->catchUp($t, $err)) return;
        $this->finish($t, str_contains($err, 'merge of task/') ? 'conflict' : 'failed', $err);
    }

    /** How often one task is sent back to be brought up to date with the app before a person is asked. */
    public const MAX_CATCH_UPS = 2;

    /** The "Spend per request" an agent set (Model_Projectreport::headline's `budget`), in tokens; 0 = none. */
    public static function budgetOf($report, string $agent): int {
        if (!is_array($report) || $agent === '') return 0;
        return max(0, (int) (((array) ($report['budget'] ?? []))[$agent] ?? 0));
    }

    /** The share of an agent's budget at which its owner is told the request is nearing it. */
    public const NEAR_BUDGET = 0.8;

    /** Has a request used most of an agent's budget (and not all of it)? */
    public static function nearBudget(int $spent, int $budget): bool { return $budget > 0 && $spent >= (int) ($budget * self::NEAR_BUDGET) && $spent < $budget; }

    private function coreDb(): string { return dirname(__DIR__) . '/database/tiknix.db'; }

    private function tellOwner(string $kind, array $more): void {
        $plan = $this->plan();
        $r = PlanNotifier::planBudget($this->coreDb(), ['kind' => $kind, 'plan_id' => $this->planId, 'member_id' => (int) $plan->memberId, 'slug' => $this->slug, 'title' => (string) $plan->title] + $more);
        $this->logEvent($plan, str_starts_with($r, 'FAILED') ? 'error' : 'info', 'Told its owner (' . $kind . '): ' . $r);
    }

    /** Most of an agent's budget used, while it builds: its owner is told once per agent per request. */
    private function warnNearBudget(array $spent): void {
        $rep = json_decode((string) ($this->tenant->reportJson ?? ''), true);
        $plan = $this->plan();
        $told = json_decode((string) ($plan->budgetNear ?? ''), true) ?: [];
        foreach ($spent as $a => $n) {
            $b = self::budgetOf($rep, (string) $a);
            if (!self::nearBudget((int) $n, $b) || in_array((string) $a, $told, true)) continue;
            $told[] = (string) $a;
            $plan->budgetNear = json_encode($told); Bean::store($plan);
            $this->tellOwner('near', ['spent' => $n, 'limit' => $b]);
        }
    }

    /**
     * Before the first task starts: is what this request's tasks are likely to use HERE (app\PlanLearn,
     * by each task's effort) more than its agent's budget? Then it does not start, and its owner is
     * asked (Build anyway). Not asked again for a request its owner let go, or one already building.
     * @return array{agent:string, estimate:int, limit:int}|null  the hold, or null to go ahead
     */
    public function budgetPrecheck(): ?array {
        $plan = $this->plan();
        if (!empty($plan->budgetAccepted)) return null;
        $tasks = $this->subtasks();
        foreach ($tasks as $t) if (trim((string) ($t->statsJson ?? '')) !== '' || (string) $t->status !== 'pending') return null;   // already under way
        $rep = json_decode((string) ($this->tenant->reportJson ?? ''), true);
        $byAgent = [];
        foreach ($tasks as $t) $byAgent[$this->agentOf($t)][] = (json_decode((string) ($t->rankJson ?? ''), true) ?: [])['effort'] ?? null;
        $learned = null;
        foreach ($byAgent as $a => $efforts) {
            $b = self::budgetOf($rep, (string) $a);
            if ($b <= 0) continue;
            $learned ??= PlanLearn::learn(PlanLearn::rows($this->instanceDir));
            $est = PlanLearn::estimate($efforts, $learned);
            if ($est === null || $est['tokens'] <= $b) continue;
            $plan->budgetHold = 2; $plan->budgetEstimate = json_encode(['agent' => (string) $a, 'estimate' => $est['tokens'], 'limit' => $b, 'unknown' => $est['unknown']]);
            $plan->updatedAt = date('Y-m-d H:i:s');
            Bean::store($plan);
            $this->logEvent($plan, 'warning', 'Not started: tasks like these have used about ' . RunStats::tokens($est['tokens']) . ' tokens in this project, more than '
                . ((string) $a !== '' ? $a : 'its agent') . "'s limit of " . RunStats::tokens($b) . ' for one request. Build anyway, or raise the limit.');
            $this->tellOwner('estimate', ['estimate' => $est['tokens'], 'limit' => $b]);
            return ['agent' => (string) $a, 'estimate' => $est['tokens'], 'limit' => $b];
        }
        return null;
    }

    /** Has a request used an agent's budget? No budget is never over it. */
    public static function overBudget(int $spent, int $budget): bool { return $budget > 0 && $spent >= $budget; }

    /**
     * Tokens this request's tasks have used on each agent since it was last let go past its budget
     * (the plan's budget_from). A task still running counts once it ends: its run reports what it used.
     * @return array<string,int>
     */
    private function spentByAgent(array $tasks): array {
        $out = $this->spentByAgentTotal($tasks);
        foreach ((array) (json_decode((string) ($this->plan()->budgetFrom ?? ''), true) ?: []) as $a => $n) if (isset($out[$a])) $out[$a] = max(0, $out[$a] - (int) $n);
        return $out;
    }

    private function holdForBudget(array $spent, array $agents): void {
        $plan = $this->plan();
        if (!empty($plan->budgetHold)) return;
        $plan->budgetHold = 1; $plan->updatedAt = date('Y-m-d H:i:s');
        Bean::store($plan);
        $rep = json_decode((string) ($this->tenant->reportJson ?? ''), true);
        $say = implode('; ', array_map(fn($a) => ($a !== '' ? $a : 'the default agent') . ' has used ' . RunStats::tokens((int) ($spent[$a] ?? 0)) . ' of its ' . RunStats::tokens(self::budgetOf($rep, $a)) . ' tokens', $agents));
        $this->logEvent($plan, 'warning', "This request reached its spending limit ({$say}). Nothing more is started until you press Build to go on, or raise Spend per request on the agent.");
        $a = (string) $agents[0];
        $this->tellOwner('paused', ['spent' => (int) ($spent[$a] ?? 0), 'limit' => self::budgetOf($rep, $a)]);
    }

    /** Build was pressed on a request held at its budget: it goes on, with a fresh budget from what it has spent so far. */
    public function clearBudgetHold(): void {
        $plan = $this->plan();
        if (empty($plan->budgetHold)) return;
        if ((int) $plan->budgetHold === 2) {   // held before it started (budgetPrecheck): its owner said build it anyway
            $plan->budgetHold = 0; $plan->budgetAccepted = 1; $plan->progressMessage = '';
            Bean::store($plan);
            $this->logEvent($plan, 'info', 'Building anyway, past what tasks like these have cost here.');
            return;
        }
        $plan->budgetFrom = json_encode($this->spentByAgentTotal($this->subtasks()), JSON_UNESCAPED_SLASHES);
        $plan->budgetHold = 0; $plan->progressMessage = '';
        Bean::store($plan);
        $this->logEvent($plan, 'info', 'Going on past the spending limit: the request starts a fresh budget from here.');
    }

    /** spentByAgent() without what an earlier "go on" already let through: everything used so far. */
    private function spentByAgentTotal(array $tasks): array {
        $out = [];
        foreach ($tasks as $t) {
            $s = json_decode((string) ($t->statsJson ?? ''), true) ?: [];
            $n = (int) ($s['input_tokens'] ?? 0) + (int) ($s['output_tokens'] ?? 0);
            if ($n > 0) { $a = $this->agentOf($t); $out[$a] = ($out[$a] ?? 0) + $n; }
        }
        return $out;
    }

    /** Set while a race is being settled, so a finish() inside it does not settle it again. */
    private bool $settling = false;

    /** Is this task one of a race still to be decided? */
    private function racing($t): bool {
        if ((int) ($t->raceOf ?? 0) > 0) return (json_decode((string) (Bean::load('workbenchtask', (int) $t->raceOf)->raceJson ?? ''), true)['state'] ?? '') !== 'decided';
        return in_array(json_decode((string) ($t->raceJson ?? ''), true)['state'] ?? '', ['racing', 'judging'], true);
    }

    /** A Critical task launched fresh: start its second attempt beside it, when the race is wanted (TaskRace::why). */
    private function maybeRace($t): void {
        if (trim((string) ($t->raceJson ?? '')) !== '') return;   // raced already (a retry is not raced again)
        $rank = json_decode((string) ($t->rankJson ?? ''), true) ?: [];
        if ((int) ($rank['risk'] ?? 0) < TaskRace::MIN_RISK) return;
        $ag = $this->agentOf($t);
        $budget = self::budgetOf(json_decode((string) ($this->tenant->reportJson ?? ''), true), $ag);
        $est = $budget > 0 ? (PlanLearn::estimate([$rank['effort'] ?? null], PlanLearn::learn(PlanLearn::rows($this->instanceDir)))['tokens'] ?? null) : null;
        $why = TaskRace::why($rank, (int) ($t->raceOf ?? 0) > 0, (int) ($t->reviewOf ?? 0) > 0, TaskRace::enabled(@parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: []),
                             $budget, (int) ($this->spentByAgent($this->subtasks())[$ag] ?? 0), $est !== null ? (int) $est : null);
        if ($why !== '') {
            if ($why !== 'off' && $why !== 'not a first attempt' && $why !== 'not Critical with effort 3 or more') $this->logEvent($t, 'info', 'Critical, but built once: ' . $why . '.');
            return;
        }
        $n = Bean::dispense('workbenchtask');
        foreach (['taskType', 'parentTaskId', 'instanceId', 'instanceTag', 'engine', 'model', 'agent', 'memberId', 'authcontrolLevel', 'baseBranch', 'dbSource',
                  'description', 'priority', 'relatedFiles', 'acceptanceCriteria', 'reuses', 'adopts', 'rankJson', 'dependsOn', 'planRef'] as $f) $n->{$f} = $t->{$f};
        $n->title = mb_substr((string) $t->title, 0, 180) . ' (second attempt)';
        $n->planRef = 'race-' . (int) $t->id; $n->status = 'pending'; $n->raceOf = (int) $t->id;
        $n->createdAt = date('Y-m-d H:i:s'); $n->updatedAt = $n->createdAt;
        Bean::store($n);
        $t->raceJson = json_encode(['rival' => (int) $n->id, 'state' => 'racing']);
        Bean::store($t);
        $this->logEvent($t, 'info', "Critical: built twice at once — this attempt and a second, #{$n->id}, which is told to take a different approach. Both finish; one is kept whole.");
    }

    /** A racer has ended (built, or not): when both have, decide — merge the one that built, or have the two judged. */
    private function raceSettle($racer): void {
        $first = (int) ($racer->raceOf ?? 0) > 0 ? Bean::load('workbenchtask', (int) $racer->raceOf) : Bean::load('workbenchtask', (int) $racer->id);
        $race = json_decode((string) ($first->raceJson ?? ''), true) ?: [];
        if (($race['state'] ?? '') !== 'racing') return;
        $rival = Bean::load('workbenchtask', (int) ($race['rival'] ?? 0));
        $ended = fn($x) => in_array((string) $x->status, ['built', 'failed', 'resolved', 'conflict'], true);
        if (!$rival->id || !$ended($first) || !$ended($rival)) return;
        $o = TaskRace::outcome((string) $first->status === 'built', (string) $rival->status === 'built');
        if ($o === 'none') {
            $first->raceJson = json_encode(['state' => 'decided', 'winner' => ''] + $race); Bean::store($first);
            $this->logEvent($first, 'error', 'Neither attempt produced work to keep.');
            return;
        }
        if ($o === 'judge') {
            $id = 'race-p' . $this->planId . '-t' . (int) $first->id;
            try {
                $brief = TaskRace::judgeBrief((string) $first->title, (string) $first->description, json_decode((string) ($first->acceptanceCriteria ?? ''), true) ?: [],
                    TenantHost::branchChange($this->tenant, $this->tenantTaskId($first)), TenantHost::branchChange($this->tenant, $this->tenantTaskId($rival)));
                TenantHost::askStart($this->tenant, $id, $brief, TaskRace::TIMEOUT, PlanIngestor::agentName($first->agent ?? ''));
            } catch (\Throwable $e) {
                // No judge: the first attempt is kept (it was planned; the second is the challenger) — said, never silent.
                $this->logEvent($first, 'error', 'Both attempts built, and they could not be judged (' . $e->getMessage() . '): the first attempt is kept.');
                $this->raceMerge($first, $first, $rival, 'not judged: ' . $e->getMessage());
                return;
            }
            $first->raceJson = json_encode(['state' => 'judging', 'run' => $id, 'started' => date('c')] + $race); Bean::store($first);
            $this->logEvent($first, 'info', 'Both attempts built: the app\'s agent is judging which to keep.');
            return;
        }
        $this->raceMerge($first, $o === 'A' ? $first : $rival, $o === 'A' ? $rival : $first, $o === 'A' ? 'the second attempt did not build' : 'the first attempt did not build');
    }

    /** A judge has answered (or not yet): the winner is merged. */
    private function collectJudge($first): void {
        $race = json_decode((string) ($first->raceJson ?? ''), true) ?: [];
        try { $r = TenantHost::askCollect($this->tenant, (string) $race['run']); }
        catch (\RuntimeException $e) { $this->logEvent($first, 'warning', 'Could not read the judge from the container (will try again): ' . $e->getMessage()); return; }
        if ($r === null) return;
        $rival = Bean::load('workbenchtask', (int) $race['rival']);
        $first->raceJson = json_encode(['state' => 'racing'] + $race); Bean::store($first);   // raceMerge decides it
        try {
            if (empty($r['ok'])) throw new \RuntimeException((string) ($r['error'] ?? 'the judge did not answer'));
            $v = TaskRace::parseJudge((string) ($r['text'] ?? ''));
        } catch (\Throwable $e) {
            $this->logEvent($first, 'error', 'The judge gave no verdict (' . $e->getMessage() . '): the first attempt is kept.');
            $this->raceMerge($first, $first, $rival, 'no verdict: ' . $e->getMessage());
            return;
        }
        $this->raceMerge($first, $v['pick'] === 'A' ? $first : $rival, $v['pick'] === 'A' ? $rival : $first, 'judged: ' . $v['why']);
    }

    /**
     * Keep one attempt whole: the loser's tests are saved and its branch discarded, the winner is
     * merged, and the FIRST attempt's row reads merged (what the plan waits on). Then the loser's
     * tests run against what was merged; one it fails becomes a fix task.
     */
    private function raceMerge($first, $winner, $loser, string $why): void {
        $this->settling = true;
        try {
            $race = json_decode((string) ($first->raceJson ?? ''), true) ?: [];
            $which = (int) $winner->id === (int) $first->id ? 'first' : 'second';
            $lid = $this->tenantTaskId($loser);
            $kept = 0;
            if ((string) $loser->status === 'built') {
                try { $kept = TenantHost::keepTests($this->tenant, $lid); }
                catch (\RuntimeException $e) { $this->logEvent($first, 'warning', 'The other attempt\'s tests could not be kept: ' . $e->getMessage()); }
                TenantHost::discardTask($this->tenant, $lid);
            }
            try { $m = TenantHost::mergeTask($this->tenant, $this->tenantTaskId($winner), $this->author()); }
            catch (\RuntimeException $e) { $m = ['ok' => false, 'error' => $e->getMessage()]; }
            if (empty($m['ok'])) {
                $first->raceJson = json_encode(['state' => 'decided', 'winner' => $which, 'why' => $why] + $race); Bean::store($first);
                $this->finish($first, 'failed', 'the ' . $which . ' attempt won (' . $why . ') but its merge failed: ' . (string) ($m['error'] ?? '?') . ' — its work is kept on its branch.');
                return;
            }
            $first->raceJson = json_encode(['state' => 'decided', 'winner' => $which, 'why' => $why, 'tests' => $kept] + $race); Bean::store($first);
            $this->finish($first, 'merged', 'merged into the app as ' . ($m['merged'] ?? '?') . " — the {$which} attempt's work, kept whole ({$why})");
            $other = (int) $first->raceOf > 0 ? $first : Bean::load('workbenchtask', (int) $race['rival']);
            $other->status = 'resolved'; $other->progressMessage = ''; $other->completedAt = date('Y-m-d H:i:s');
            $other->errorMessage = 'Raced with #' . (int) $first->id . '; ' . ($which === 'second' ? 'this attempt won and is merged as #' . (int) $first->id : 'the first attempt was kept') . '.';
            Bean::store($other);
            $this->logEvent($other, 'info', (string) $other->errorMessage);
            $this->startReview($first, (string) ($m['merged'] ?? ''));
            if ($kept > 0) {
                try { $run = TenantHost::runKeptTests($this->tenant, $lid); }
                catch (\RuntimeException $e) { $this->logEvent($first, 'warning', 'The other attempt\'s tests could not be run: ' . $e->getMessage()); return; }
                if ($run['ok']) { $this->logEvent($first, 'info', "The other attempt's {$kept} test file(s) pass against the kept one."); return; }
                $f = TaskRace::testsFix((int) $first->id, (string) $first->title, $run['output']);
                $fix = $this->addFixTask($first, $f, 'race-tests-' . (int) $first->id);
                $this->logEvent($first, 'warning', "The other attempt's tests do not all pass against the kept one; task #{$fix} looks at them.");
            }
        } finally { $this->settling = false; }
    }

    /** Tasks whose review is still reading them. */
    private function reviewing(array $tasks): int {
        return count(array_filter($tasks, fn($t) => (json_decode((string) ($t->reviewJson ?? ''), true)['state'] ?? '') === 'running'
                                                    || (json_decode((string) ($t->raceJson ?? ''), true)['state'] ?? '') === 'judging'));
    }

    private function setReview($t, array $review): void {
        $t->reviewJson = json_encode($review, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Bean::store($t);
    }

    /**
     * Plan and Fan, step three: a task scored Medium risk or above has just merged — the app's agent
     * reads its change against its checks (app\TaskReview), read-only, while the next tasks build.
     * A review that cannot start is said on the task; the task stays merged and the plan goes on.
     */
    private function startReview($t, string $merged): void {
        $rank = json_decode((string) ($t->rankJson ?? ''), true) ?: [];
        if (!TaskReview::enabled(@parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [])) return;
        if (!TaskReview::wanted($rank, (int) ($t->reviewOf ?? 0) > 0)) return;
        $id = TaskReview::runId($this->planId, (int) $t->id);
        try {
            if ($merged === '') throw new \RuntimeException('the merge did not say which commit it made');
            [$stat, $diff] = TenantHost::mergedChange($this->tenant, $merged);
            $brief = TaskReview::brief((string) $t->title, (string) $t->description, json_decode((string) ($t->acceptanceCriteria ?? ''), true) ?: [], $rank, $stat, $diff);
            TenantHost::askStart($this->tenant, $id, $brief, TaskReview::TIMEOUT, PlanIngestor::agentName($t->agent ?? ''));
        } catch (\Throwable $e) {
            $this->setReview($t, ['state' => 'failed', 'error' => $e->getMessage(), 'finished' => date('c')]);
            $this->logEvent($t, 'error', 'Its review could not start: ' . $e->getMessage() . ' — the task stays merged; nothing read it a second time.');
            return;
        }
        $this->setReview($t, ['state' => 'running', 'run' => $id, 'commit' => $merged, 'started' => date('c')]);
        $this->logEvent($t, 'info', 'Scored ' . (PlanRank::LABELS[(int) $rank['risk']] ?? '?') . ' risk: its agent is reading what it changed against its checks, while the next tasks build.');
    }

    /** A running review: still reading (nothing to do), or ended — its verdict recorded, and a fix task added for what it found. */
    private function collectReview($t): void {
        $review = json_decode((string) ($t->reviewJson ?? ''), true) ?: [];
        try { $r = TenantHost::askCollect($this->tenant, (string) $review['run']); }
        catch (\RuntimeException $e) { $this->logEvent($t, 'warning', 'Could not read its review from the container (will try again): ' . $e->getMessage()); return; }
        if ($r === null) return;
        $review['finished'] = date('c');
        $review['seconds'] = max(0, time() - (int) strtotime((string) ($review['started'] ?? 'now')));
        try {
            if (empty($r['ok'])) throw new \RuntimeException((string) ($r['error'] ?? 'the review run failed'));
            $review['said'] = mb_substr(trim((string) ($r['text'] ?? '')), 0, 2000);   // its own words, kept: a verdict alone cannot be checked
            $v = TaskReview::parse((string) ($r['text'] ?? ''));
        } catch (\Throwable $e) {
            $this->setReview($t, ['state' => 'failed', 'error' => $e->getMessage()] + $review);
            $this->logEvent($t, 'error', 'Its review did not finish: ' . $e->getMessage() . ' — the task stays merged; nothing read it a second time.');
            return;
        }
        if ($v['verdict'] === 'pass') {
            $this->setReview($t, ['state' => 'passed'] + $review);
            $this->logEvent($t, 'info', 'Review: nothing wrong found in what it changed (' . $review['seconds'] . ' s).');
            return;
        }
        $fix = $this->addReviewFix($t, $v['problems']);
        $this->setReview($t, ['state' => 'found', 'problems' => $v['problems'], 'fix' => $fix] + $review);
        $this->logEvent($t, 'warning', 'Review found ' . count($v['problems']) . ' thing' . (count($v['problems']) === 1 ? '' : 's') . " wrong; task #{$fix} fixes " . (count($v['problems']) === 1 ? 'it' : 'them') . ":\n- " . implode("\n- ", $v['problems']));
    }

    /**
     * What a review found, as one task of this plan: after the reviewed task, and before whatever
     * still waits on it (its work is not finished until the fix is). Returns the new task's id.
     */
    private function addReviewFix($t, array $problems): int {
        return $this->addFixTask($t, TaskReview::fixTask((int) $t->id, (string) $t->title, $problems, json_decode((string) ($t->acceptanceCriteria ?? ''), true) ?: []), 'review-' . (int) $t->id);
    }

    /** A fix for what was found in a merged task: after it, and before whatever still waits on it. Its id. */
    private function addFixTask($t, array $f, string $ref): int {
        $now = date('Y-m-d H:i:s');
        $n = Bean::dispense('workbenchtask');
        foreach (['taskType', 'parentTaskId', 'instanceId', 'instanceTag', 'engine', 'model', 'agent', 'memberId', 'authcontrolLevel', 'baseBranch', 'dbSource'] as $k) $n->{$k} = $t->{$k};
        $n->title = $f['title']; $n->description = $f['description']; $n->priority = (int) $t->priority; $n->status = 'pending';
        $n->relatedFiles = (string) ($t->relatedFiles ?? '[]'); $n->acceptanceCriteria = json_encode($f['verify']);
        $n->reuses = '[]'; $n->adopts = '[]'; $n->planRef = $ref;
        $rank = json_decode((string) ($t->rankJson ?? ''), true) ?: [];
        $n->rankJson = json_encode(['effort' => 1, 'planner' => null, 'risk' => $rank['risk'] ?? null, 'why' => ['it fixes what was found in #' . (int) $t->id . ' after it merged'], 'set_by' => 'review'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $n->reviewOf = (int) $t->id;
        $n->dependsOn = json_encode([(int) $t->id]);
        $n->createdAt = $now; $n->updatedAt = $now;
        Bean::store($n);
        foreach ($this->subtasks() as $other) {
            if ((int) $other->id === (int) $n->id || (string) $other->status !== 'pending') continue;
            $deps = $this->deps($other);
            if (!in_array((int) $t->id, $deps, true)) continue;
            $other->dependsOn = json_encode(array_values(array_unique(array_merge($deps, [(int) $n->id]))));
            Bean::store($other);
        }
        return (int) $n->id;
    }

    /** Is this merge error the app having changed under the task (and not uncommitted changes, or seeds)? */
    public static function isConflict(string $err): bool { return str_contains($err, 'merge of task/'); }

    /**
     * A task's merge failed because the app changed under it — another task, or another plan,
     * merged work on the same lines first. That used to stop the plan until a person untangled
     * it. Now the task goes back to its own agent, in its kept copy, with the app's current code
     * merged in and the files git stopped on named (AgentTask::catchUpWith): the agent that knows
     * what the task was for brings the two together, the tests run, and it is merged again.
     * Twice at most; after that — or when the agent says the two cannot both be kept — the task
     * is left at "conflict" with its work intact, for a person. True when it was sent back.
     */
    private function catchUp($t, string $err): bool {
        if (!self::isConflict($err)) return false;
        $n = (int) ($t->catchUps ?? 0);
        if ($n >= self::MAX_CATCH_UPS) {
            $this->logEvent($t, 'error', 'Brought up to date with the app ' . $n . ' times and its merge still fails — left for a person, with its work kept.');
            return false;
        }
        $t = Bean::load('workbenchtask', (int) $t->id);
        $t->catchUps     = $n + 1;
        $t->catchUp      = 1;
        $t->resumable    = 1;
        $t->status       = 'pending';   // the next tick starts it
        $t->errorMessage = '';
        Bean::store($t);
        $this->logEvent($t, 'warning', 'The app changed under this task (' . mb_substr($err, 0, 300) . '). Its work is kept: its agent is sent back to bring the two together, then it is merged again (' . ($n + 1) . ' of ' . self::MAX_CATCH_UPS . ').');
        return true;
    }

    /**
     * The checks a task's own report marks as failed (✗) in its "Verified" list — its agent answers
     * each planned check with ✓, ✗ or ? (PlanExecutor::proveSection). '?' (could not be run) is not
     * counted: a check nobody could run is said, not failed.
     * @return string[] the failed lines, without their number and mark
     */
    public static function unmetChecks(string $output): array {
        $at = stripos($output, '## Verified');
        if ($at === false) return [];
        $part = substr($output, $at + strlen('## Verified'));
        if (preg_match('/\n##\s/', $part, $m, PREG_OFFSET_CAPTURE)) $part = substr($part, 0, $m[0][1]);
        $out = [];
        foreach (explode("\n", $part) as $line) {
            if (preg_match('/^\s*(?:\d+[.)]|[-*])\s*(?:\*\*)?\s*✗\s*(?:\*\*)?\s*(.+)$/u', $line, $m)) $out[] = mb_substr(trim($m[1]), 0, 220);
        }
        return $out;
    }

    /** What became of a task marked as more than one: 'split N' or 'one'. The board's tag reads it. */
    private function saySplitOutcome($t, string $outcome): void {
        $t = Bean::load('workbenchtask', (int) $t->id);
        $t->splitOutcome = $outcome;
        $t->progressMessage = '';
        Bean::store($t);
    }

    // ---- a task that splits itself ----------------------------------------------------------

    /** A task split from a task split from a task is as deep as it goes: past that, the plan itself is wrong. */
    public const MAX_SPLIT_DEPTH = 2;
    /** How many tasks of one plan may split themselves before a person has to look. */
    public const MAX_SPLITS_PER_PLAN = 4;

    /**
     * A split a task handed in (its submit_plan), checked and put in order — pure, so the rules
     * are something a test can pin. Each piece: ref, title, description, files, verify, priority,
     * deps (refs of OTHER pieces). `leaves` are the pieces nothing else in the split waits on:
     * what the original task's dependents now wait for.
     *
     * @return array{problems:string[],pieces:array<int,array<string,mixed>>,leaves:string[]}
     */
    public static function splitPieces($split): array {
        $out = ['problems' => [], 'pieces' => [], 'leaves' => []];
        $subs = is_array($split) ? array_values(array_filter((array) ($split['subtasks'] ?? []), 'is_array')) : [];
        if (count($subs) < 2 || count($subs) > 6) { $out['problems'][] = 'a split is 2 to 6 pieces, not ' . count($subs); return $out; }
        $refs = [];
        foreach ($subs as $i => $st) {
            $ref = trim((string) ($st['id'] ?? '')) ?: 's' . ($i + 1);
            $title = trim((string) ($st['title'] ?? ''));
            if ($title === '') { $out['problems'][] = "piece '{$ref}' has no title"; continue; }
            if (isset($refs[$ref])) { $out['problems'][] = "two pieces are both called '{$ref}'"; continue; }
            $refs[$ref] = true;
            $out['pieces'][] = ['ref' => $ref, 'title' => mb_substr($title, 0, 200), 'description' => (string) ($st['description'] ?? ''),
                'files' => is_array($st['files'] ?? null) ? array_values($st['files']) : [], 'verify' => PlanIngestor::verify($st['verify'] ?? null),
                'priority' => max(1, min(4, (int) ($st['priority'] ?? 3))), 'deps' => array_values(array_map('strval', (array) ($st['depends_on'] ?? []))),
                'effort' => $st['effort'] ?? null, 'risk' => $st['risk'] ?? null, 'risk_reason' => (string) ($st['risk_reason'] ?? '')];
        }
        if ($out['problems']) return $out;
        $waitedOn = [];
        foreach ($out['pieces'] as $k => $p) {
            // A piece may wait on other pieces only; a ref to anything else is dropped (the pieces
            // all start after the task that split, which has everything it depended on).
            $out['pieces'][$k]['deps'] = array_values(array_unique(array_filter($p['deps'], fn($d) => isset($refs[$d]) && $d !== $p['ref'])));
            foreach ($out['pieces'][$k]['deps'] as $d) $waitedOn[$d] = true;
        }
        foreach ($out['pieces'] as $p) if (!isset($waitedOn[$p['ref']])) $out['leaves'][] = $p['ref'];
        if (!$out['leaves']) $out['problems'][] = 'the pieces wait on each other in a circle, so none can be last';
        return $out;
    }

    /**
     * The task found it was really several and handed in the pieces: they take its place in ITS
     * plan. Each piece starts after the task (so it is handed what the task left), runs on the
     * task's agent, and is one level deeper; whatever waited on the task now also waits on the
     * last pieces. The plan's own setting decides what happens next: built straight through, it
     * goes on; approved by a person, it stops after what is running so the person can read the
     * pieces and press Build (holdForSplit). A split that breaks a limit is refused and said on
     * the task — the task stays merged, and nothing is added.
     *
     * @return int[] the new tasks' ids ([] when there was no split, or it was refused)
     */
    private function applySplit($t, $split): array {
        if ($split === null) return [];
        $depth = (int) ($t->splitDepth ?? 0);
        $plan  = $this->plan();
        // The first split in a project makes the column; before that there is nothing to count.
        $already = array_key_exists('split_of', Bean::inspect('workbenchtask'))
            ? (int) Bean::getCell('SELECT COUNT(DISTINCT split_of) FROM workbenchtask WHERE parent_task_id = ? AND split_of > 0', [$this->planId]) : 0;
        $why = '';
        if ($depth >= self::MAX_SPLIT_DEPTH) $why = 'it is already ' . $depth . ' splits deep — past that the plan itself needs rethinking';
        elseif ($already >= self::MAX_SPLITS_PER_PLAN) $why = $already . ' tasks of this plan have already split themselves — a person should look at the plan';
        $sp = self::splitPieces($split);
        if ($why === '' && $sp['problems']) $why = implode('; ', $sp['problems']);
        if ($why !== '') {
            $this->logEvent($t, 'warning', 'The task asked to be split into smaller tasks, and it was NOT split: ' . $why . '. Re-plan what is left from the Builder.');
            return [];
        }

        $now = date('Y-m-d H:i:s'); $ids = [];
        foreach ($sp['pieces'] as $p) {
            $n = Bean::dispense('workbenchtask');
            foreach (['taskType', 'parentTaskId', 'instanceId', 'instanceTag', 'engine', 'model', 'agent', 'memberId', 'authcontrolLevel', 'baseBranch', 'dbSource'] as $f) $n->{$f} = $t->{$f};
            $n->title = $p['title']; $n->description = $p['description']; $n->priority = $p['priority']; $n->status = 'pending';
            $n->relatedFiles = json_encode($p['files']); $n->acceptanceCriteria = json_encode($p['verify']);
            $n->reuses = '[]'; $n->adopts = '[]'; $n->planRef = 'split-' . (int) $t->id . '-' . $p['ref'];
            // each piece scored as a planned task is (app\PlanRank), its siblings the other pieces
            $sib = PlanRank::concurrent($p['ref'], array_map(fn($o) => ['ref' => $o['ref'], 'deps' => $o['deps'], 'title' => $o['title'], 'files' => $o['files']], $sp['pieces']));
            $n->rankJson = json_encode(PlanRank::planned($p['effort'] ?? null, $p['risk'] ?? null, (string) ($p['risk_reason'] ?? ''), PlanRank::checks(['files' => $p['files'], 'adopts' => [], 'verify' => $p['verify']], $sib)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $n->splitOf = (int) $t->id; $n->splitDepth = $depth + 1;
            $n->createdAt = $now; $n->updatedAt = $now;
            Bean::store($n);
            $ids[$p['ref']] = (int) $n->id;
        }
        foreach ($sp['pieces'] as $p) {
            $n = Bean::load('workbenchtask', $ids[$p['ref']]);
            // After the task that split (already merged: satisfied at once, and its Handoff is handed on) and the pieces it names.
            $n->dependsOn = json_encode(array_values(array_unique(array_merge([(int) $t->id], array_map(fn($d) => $ids[$d], $p['deps'])))));
            Bean::store($n);
        }
        // Whatever waited on the task was waiting for its WORK — which the last pieces now finish.
        $leaves = array_map(fn($r) => $ids[$r], $sp['leaves']);
        foreach ($this->subtasks() as $other) {
            if (in_array((int) $other->id, $ids, true) || !in_array((string) $other->status, ['pending', 'failed', 'conflict'], true)) continue;
            $deps = $this->deps($other);
            if (!in_array((int) $t->id, $deps, true)) continue;
            $other->dependsOn = json_encode(array_values(array_unique(array_merge($deps, $leaves))));
            Bean::store($other);
        }
        $list = implode(', ', array_map(fn($p) => '#' . $ids[$p['ref']] . ' ' . $p['title'], $sp['pieces']));
        $this->logEvent($t, 'info', 'Split itself into ' . count($ids) . " smaller tasks, which take its place in this plan: {$list}");
        if (empty($plan->autoBuild)) {
            $plan->splitHold = 1; $plan->updatedAt = $now;
            Bean::store($plan);
            $this->logEvent($plan, 'warning', "Task #{$t->id} split itself into " . count($ids) . " tasks ({$list}). The build stops after what is running so you can read them — press Build to go on.");
        }
        return array_values($ids);
    }

    /** Is the plan waiting for a person because a task split itself (a plan that is not built straight through)? */
    public function heldForSplit(): bool { return !empty($this->plan()->splitHold); }

    /** The person pressed Build: the split has been seen. */
    public function clearSplitHold(): void {
        $plan = $this->plan();
        if (empty($plan->splitHold)) return;
        // …and the plan stops saying it is waiting (the orchestrator wrote that when it held).
        $plan->splitHold = 0; $plan->progressMessage = ''; Bean::store($plan);
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

    /** aibuilder.ini [plan] fast_model_for_small — small, low-risk tasks on the agent's fast model. Off unless set true. */
    public static function fastForSmall(): bool {
        $ini = @parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [];
        return filter_var($ini['plan']['fast_model_for_small'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function deps($t): array {
        $d = json_decode(((string)$t->dependsOn) ?? '', true);
        return is_array($d) ? array_map('intval', $d) : [];
    }

    private function countByStatus(array $tasks, string $status): int {
        $n = 0; foreach ($tasks as $t) if ($t->status === $status) $n++; return $n;
    }

    /**
     * What this task asked its person and what they answered — the decision is theirs and it is
     * made: the brief says it so the task acts on it instead of asking again or guessing after all.
     */
    public static function askedSection($asked): string {
        if (!is_array($asked)) return '';
        $out = [];
        foreach ($asked as $qa) {
            $q = trim((string) ($qa['q'] ?? '')); $a = trim((string) ($qa['a'] ?? ''));
            if ($q === '' || $a === '') continue;
            $out[] = "**You asked:** " . str_replace("\n", "\n  ", $q) . "\n\n**They answered:** " . str_replace("\n", "\n  ", $a);
        }
        if (!$out) return '';
        return "\n## You asked the owner, and they answered\n\nAn earlier run of this task stopped to ask. This is settled — build it the way they said, and do not ask it again.\n\n" . implode("\n\n---\n\n", $out) . "\n";
    }

    /**
     * A task asks its person a question and waits. `awaiting` with the question as its progress
     * message is what the board and Ask already show as "it needs an answer from you"; what was
     * asked and answered is kept on the task (askedJson) so the exchange stays in the conversation
     * after it is answered. Its working copy is kept: the answer resumes it.
     */
    private function ask($t, string $question): void {
        $asked = json_decode((string) ($t->askedJson ?? ''), true);
        if (!is_array($asked)) $asked = [];
        $asked[] = ['q' => $question, 'at' => date('Y-m-d H:i:s'), 'a' => '', 'answered_at' => ''];
        $t->askedJson       = json_encode($asked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t->status          = 'awaiting';
        $t->resumable       = 1;
        $t->progressMessage = mb_substr($question, 0, 1500);
        $t->errorMessage    = '';
        $t->agentSession    = '';
        $t->updatedAt       = date('Y-m-d H:i:s');
        Bean::store($t);
        $this->logEvent($t, 'warning', "It stopped to ask its person a question, and waits for the answer:\n\n" . $question);
    }

    /** How many tasks of this plan wait on an answer from their person. */
    public function asking(): int {
        return count(array_filter($this->subtasks(), fn($t) => (string) $t->status === 'awaiting' && trim((string) ($t->askedJson ?? '')) !== ''));
    }

    private function finish($t, string $status, string $note): void {
        // Auto-retry: a retryable failure — under the cap and not repeating the exact
        // same error — gets its blocker corrected and is re-queued (status 'pending')
        // instead of failing terminally. The orchestrator loop then re-attempts it, so
        // it keeps trying toward the goal until it merges or the backstop trips.
        if ($status === 'failed' && !$this->racing($t) && $this->autoRetry($t, $note)) {
            return;
        }

        $t->status       = $status;
        $t->completedAt  = date('Y-m-d H:i:s');
        $t->progressMessage = '';   // what its agent was doing; it has stopped doing it
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
        // A racer that ended without work to keep: its rival may now decide the race.
        if (in_array($status, ['failed', 'resolved', 'conflict'], true) && $this->racing($t) && !$this->settling) $this->raceSettle($t);
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
        // Built, but not looked at in the browser it was lent (SandboxCheck::browserMissed): its work
        // is kept, and the retry continues it with the reason in its brief — open the pages, or say why not.
        if (strpos($n, 'and the browser never opened') !== false) return ['class' => 'browser', 'retryable' => true];
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
     * What a merged task proposed for the project's notebook (its `## Notebook` section —
     * app\Notebook::parse) is added in the app, by this one writer, as the member whose task it
     * was. A notebook that cannot be written is logged on the task; the task itself is merged.
     */
    private function addToNotebook($t, string $output): void {
        $entries = \app\Notebook::parse($output);
        if (!$entries) return;
        try { $r = TenantHost::notebookAdd($this->tenant, $entries, 'task #' . (int) $t->id, $this->author()); }
        catch (\RuntimeException $e) { $r = ['ok' => false, 'error' => $e->getMessage()]; }
        if (empty($r['ok'])) { $this->logEvent($t, 'warning', 'Notebook: ' . count($entries) . ' entr' . (count($entries) === 1 ? 'y' : 'ies') . ' not added — ' . (string) ($r['error'] ?? 'the app did not answer')); return; }
        $lines = array_map(fn($e) => "- {$e['kind']}: {$e['text']}", $entries);
        $this->logEvent($t, 'info', 'Notebook: ' . (int) ($r['added'] ?? 0) . ' added' . (!empty($r['skipped']) ? ', ' . (int) $r['skipped'] . ' already there' : '') . ":\n" . implode("\n", $lines));
    }

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

    /**
     * The brief's "prove it works" section: the checks the planner wrote for this task, which its
     * agent runs in its sandbox and answers one by one. '' for a task planned before checks existed.
     */
    public static function proveSection($checks): string {
        $checks = is_array($checks) ? array_values(array_filter($checks, fn($c) => is_string($c) && trim($c) !== '')) : [];
        if (!$checks) return '';
        $md = "\n## Prove it works\n\nThis task is done when each of these is TRUE — run them in your sandbox, do not reason about them:\n\n";
        foreach ($checks as $i => $c) $md .= ($i + 1) . '. ' . trim($c) . "\n";
        return $md . "\nIn your final message, under a heading `## Verified`, answer each by number: `1. ✓` and what you saw, or `1. ✗` and what happened instead. "
            . "A check you could not run (no sandbox, a mail that cannot be sent from it) is `1. ?` with the reason — never a ✓ you did not see. A ✗ is not something to report and stop at: fix it, and run the check again.\n";
    }

    /** Is this a design task — one that shapes pages the earlier tasks made work? The planner titles it "Design: …". */
    public static function isDesignTask(string $title): bool {
        return (bool) preg_match('/^\s*design\s*:/i', $title);
    }

    /**
     * What a design task is told on top of its description: its job is how the pages read and
     * feel, judged by LOOKING at them, and it leaves the data alone. '' for every other task.
     */
    public static function designSection(string $title): string {
        if (!self::isDesignTask($title)) return '';
        return <<<MD

## This is a design task

The feature already works — the tasks before yours built the data, the rules and the routes, with
pages described in `\app\Ui`. Your job is how those pages read to the person using them. Nobody
can judge that from code: open each page in your sandbox in a browser, at a phone width (390) and
a desktop width (1280), signed in as the person it is for, and look.

Start with two lists: `full_validation` on `views` names what each page does by hand that `\\app\\Ui`
decides (a hand-written table, a second loud button, the browser's confirm()), and `ui_pattern`
on each kind of record says what pages its shape calls for. They are where to look, not the job.

For each page, answer first: who comes here, to do what, and what is the ONE thing they do most?
Then make the page say that:

- the most-used thing is the primary action, named as a verb with its object; everything else is quieter
- what tells records apart is in the list; what does not is on the record's own page
- a form asks only what it needs now — fold the rest under `more`, group long ones with `section`,
  pre-fill what can be guessed, and put the fields in the order a person thinks of them
- every label, heading and message is in the user's words, not the schema's; statuses read as words
- an empty page says what belongs there and offers the way to start
- nothing on the page is there "in case": remove what the person does not need to decide here

Change views, `\app\Ui` specs, wording and menu entries. Do NOT change models, seeds, permissions
or what a controller decides — if the data is wrong for the page, say so under `## Handoff` instead.
A page that is not a list, a form or a record may be hand-made on the design system's classes.
In your final message, list each page you looked at and what you changed about it, in a sentence.

MD;
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

    /**
     * The note on a task that no planner looked at: what a person typed, sent straight to a build
     * agent. The Builder's Ask view did that for short requests for one afternoon (2026-10-11);
     * every request is decomposed by the planner first now, and nothing writes this note any more.
     * Kept so the tasks made that way are still briefed as what they are if they are retried.
     */
    public const UNPLANNED = 'unplanned';

    /** What the agent of an unplanned task is told about its size. */
    public static function unplannedSection(): string {
        return <<<MD

## Nobody planned this — it is what the person typed

The description above is their request, word for word. No planner read the code first, so its
size is yours to judge, after a look (the tiknix MCP: `reuse_digest`, `describe`, `whatprovides`):

- **One piece of work** — what most short requests are: a change to a page, a field, a rule, a
  fix, one new page on things that exist. Build it now, whole: its permission row, its menu
  link, and a check of your own that it works. Do not split it to be safe.
- **Really several** — new tables AND pages AND rules, or parts that could be built side by
  side. Do not half-build it. Find out what the pieces need, call `submit_plan` with 2 to 6
  pieces as `subtasks` (the Rules below say what each needs), put what you found INTO their
  descriptions, and stop. They are built in this plan in your place.
- **It can be read two ways that build different things** — build the smaller reading, and say
  in your `## Handoff`, first line, what you took it to mean and what you left out. The person
  reads that and asks again; that costs them less than a wrong large build.

Write your `## Handoff` for the person who asked, in their words: what is different in their app
now and where to look.

MD;
    }

    /**
     * What a task the planner marked as really being SEVERAL is told on top of its description:
     * find out first, then split it into pieces (the split a build task can already make of itself)
     * unless it turns out to be small. Until this, the mark was only a warning on the board — a plan
     * sent in from a terminal never gets the planner's second pass that splits such tasks, and one
     * agent was then sent at all of it. '' for a task that is not marked, and for one that is already
     * a piece as deep as splits go (it cannot split again, so it is not told to).
     */
    public static function severalSection(bool $marked, string $why, int $depth = 0): string {
        if (!$marked || $depth >= self::MAX_SPLIT_DEPTH) return '';
        $why = trim($why);
        if ($why === self::UNPLANNED) return self::unplannedSection();
        $quoted = $why !== '' ? "\n> " . str_replace("\n", "\n> ", $why) . "\n" : '';
        return <<<MD

## The planner marked this as more than one task
{$quoted}
One agent sent at all of it tends to half-build it, so do this in two steps:

1. **Find out first.** Read the code this touches and answer what is still open (the note above
   says what). Use the tiknix MCP (`describe`, `whatprovides`, `reuse_digest`) and the files
   below. Build nothing yet beyond what you need in order to find out.
2. **Then split it** — the usual outcome for a task marked this way. Call `submit_plan` with 2 to
   6 pieces as `subtasks` (the Rules below say what each piece needs) and stop. Put what you found
   out INTO the pieces' descriptions: the agents that build them start knowing nothing, and must
   not have to investigate again. Leave the code working, and say in your `## Handoff` what you
   learned and why the pieces are cut where they are.

If finding out shows it is one small piece of work after all, build it — and say in your
`## Handoff` why it did not need splitting.

MD;
    }

    /** What people wrote on this task (its conversation on the board and in Ask), oldest first — the last five. */
    private function saidAbout($t): array {
        try {
            if (!in_array('taskcomment', Bean::inspect(), true)) return [];
            $rows = Bean::find('taskcomment', '(is_from_claude IS NULL OR is_from_claude = 0) AND (is_internal IS NULL OR is_internal = 0) AND task_id = ? ORDER BY id DESC LIMIT 5', [(int) $t->id]);
        } catch (\Throwable $e) {
            error_log("ERROR PlanExecutor: could not read what was said on task #{$t->id}: " . $e->getMessage());
            return [];
        }
        return array_reverse(array_values(array_filter(array_map(fn($c) => trim((string) $c->content), $rows), fn($c) => $c !== '')));
    }

    /**
     * What the person answered when this task stopped, for its next attempt. A task that fails,
     * or stops at a decision only a person can make, is answered from the board or from Ask; that
     * answer is the most important line in the retry's brief, so it is said before the files.
     * '' when nobody said anything.
     */
    public static function saidSection(array $said, string $stoppedOn = ''): string {
        if (!$said) return '';
        $stoppedOn = trim($stoppedOn);
        $md = "\n## The person answered — read this first\n\n"
            . ($stoppedOn !== '' ? "An earlier attempt at this task stopped on:\n\n> " . str_replace("\n", "\n> ", mb_substr($stoppedOn, 0, 600)) . "\n\n" : '')
            . "What they said about this task, oldest first. The LAST one is their answer now; where it disagrees with the description above, it wins:\n\n";
        foreach ($said as $line) $md .= '- ' . str_replace("\n", "\n  ", mb_substr($line, 0, 1500)) . "\n";
        return $md . "\n";
    }

    /** The heading under which a request lists the files a person attached to it (the Builder's Ask view writes it). */
    public const ATTACHED_HEAD = '## Attached files';

    /**
     * The files the person attached to the request this plan came from, for EVERY task of it.
     * The planner saw them and wrote the tasks; a build agent gets only its own task, and a
     * screenshot of what is wrong is worth more to it than the planner's sentence about it.
     * Taken as written from the plan's goal (the list of paths and how to treat them); '' when
     * the request carried none.
     */
    public static function attachedSection(string $goal): string {
        $at = strpos($goal, self::ATTACHED_HEAD);
        if ($at === false) return '';
        $rest = substr($goal, $at + strlen(self::ATTACHED_HEAD));
        if (preg_match('/\n##\s/', $rest, $m, PREG_OFFSET_CAPTURE)) $rest = substr($rest, 0, $m[0][1]);   // up to the next section of the goal
        $rest = trim($rest);
        if ($rest === '' || !str_contains($rest, '- `')) return '';
        return "\n## What the person attached to the request this task comes from\n\n"
             . "They belong to the whole request, of which this task is one part: open them, and use what bears on THIS task.\n\n" . $rest . "\n\n";
    }

    private function buildTaskBrief($t): string {
        $files = json_decode(((string)$t->relatedFiles) ?? '', true);
        $files = is_array($files) ? implode("\n", array_map(fn($f) => "- $f", $files)) : '';
        $title = (string)$t->title;
        $desc  = (string)$t->description;
        $reuse = $this->reuseBrief($t);
        $prior = self::priorWorkSection($this->priorWork($t));
        $prove = self::proveSection(json_decode((string) ($t->acceptanceCriteria ?? ''), true));
        $prove = self::designSection($title) . $prove;
        $several = self::severalSection(!empty($t->needsPlanning), (string) ($t->planningNote ?? ''), (int) ($t->splitDepth ?? 0));
        $several .= self::saidSection($this->saidAbout($t), (string) ($t->lastFailReason ?: $t->errorMessage ?: ''));
        $several .= self::askedSection(json_decode((string) ($t->askedJson ?? ''), true));
        $several .= self::attachedSection((string) ($this->plan()->planGoal ?? ''));
        return <<<MD
# Build task: {$title}

You are one of several agents building a larger plan. Implement ONLY this task, in
this working directory (a git worktree of the instance). Another process will
commit and merge your work — you just make the code changes.

## What to build

{$desc}
{$several}
## Likely files

{$files}
{$reuse}{$prior}{$prove}
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
- If this task turns out to be SEVERAL — separate pieces of work you cannot finish well in one
  go (not merely long) — do not rush it and do not half-build it: SPLIT it. Call `submit_plan`
  with 2 to 6 pieces as `subtasks` (each with an `id`, a `title`, a `description` a fresh agent
  could build from, `files`, `depends_on` among the pieces, and `verify` checks), then stop.
  The pieces take this task's place in the plan and are built after you; whatever you leave
  committed is merged first and they build on it — so leave the code working (finish or undo
  anything half-made) and say in your Handoff what you did complete. Split early, when you see
  it, not after spending the task's time. Most tasks do not need this.
- If you reach a DECISION ONLY THE OWNER CAN MAKE — two real ways to build this that they
  would notice the difference between, a word in the request that could mean two things, a
  credential or account only they have, something that would remove or overwrite their data —
  do not guess and do not build both: ASK. Write the question to `.aibuilder/task.question.md`
  (one question, in plain words, two or three sentences; give the options you see and say
  which you would pick and why), leave the code working, and stop. They are shown it and
  answer in a sentence; this task then continues in this same working copy with their answer
  in its brief. Not for what you can find out yourself (read the code, run it), not for
  preferences with an obvious default (pick it and say so in your summary), and never the
  same question twice. Most tasks never need this.
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
