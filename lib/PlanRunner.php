<?php
/**
 * PlanRunner — headless "decompose a goal into a multi-agent plan" pass.
 *
 * Headless: the project's own agent (`clitool --agent-plan`, the app's credential) runs IN ITS
 * CONTAINER (TenantHost::plan → a tmux session there); a detached session here waits for it, then
 * unpacks and ingests the plan. The planner is instructed to ground itself with the tiknix
 * MCP (codebase_map / whatprovides / describe) and then call the submit_plan
 * MCP tool, which writes <instance>/.aibuilder/plan.json. The app's
 * planingest() endpoint turns that file into a reviewable workbench task tree.
 *
 * The planner only READS the codebase and WRITES the plan file — it does not
 * build anything. Execution of the plan is a separate step (the worktree
 * orchestrator, Phase 2).
 */

namespace app;

class PlanRunner {

    private string $slug;
    private string $instanceDir;
    /** The instance row when the project lives in its own container: the planner runs there. */
    private ?object $tenant = null;
    private int $memberId;
    private int $memberLevel;
    private string $engine;
    private string $sessionName;
    /** Original task ids to remove after the produced plan is ingested (Consolidate feature). */
    private array $supersedeIds = [];
    /**
     * Straight-through mode: approve the produced plan and start building it the moment
     * it is ingested, with no human click in between. OPT-IN per decompose — never
     * remembered, never a default — because it lands agent-written code in the instance
     * without anyone having read the plan first.
     */
    private bool $autoBuild = false;
    /**
     * The prompt-log row this decompose came from, so ingest can link the plan back to the
     * goal you typed. 0 when the caller did not record one (e.g. an automatic re-plan).
     */
    private int $promptId = 0;

    private string $agent = '';

    public function __construct(string $slug, string $instanceDir, int $memberId, int $memberLevel = 50, string $engine = 'claude') {
        $this->slug        = $slug;
        $this->instanceDir = rtrim($instanceDir, '/');
        $inst = CoreDb::with(fn() => Bean::findOne('instance', 'slug = ?', [$slug]));
        if (!$inst || !$inst->id || !\Model_Instance::tenantRow($inst)) {
            throw new \RuntimeException("{$slug} is not running in its own container — plans are made in a project's container");
        }
        $this->tenant = $inst;
        $this->memberId    = $memberId;
        $this->memberLevel = $memberLevel;
        $this->engine      = $engine;
        // Distinct from task sessions (tiknix-<m>-task-<id>) so it never collides.
        $this->sessionName = "tiknix-{$memberId}-plan-{$slug}";
    }

    public function getSessionName(): string { return $this->sessionName; }

    /**
     * The app's agent to plan on — a project in its own container (the builder's agent
     * picker; its AI agents page). '' = the app's default agent. The plan's tasks inherit it
     * (PlanIngestor). A host project has no app agents: naming one there is refused.
     */
    public function useAgent(string $agent): self {
        $agent = PlanIngestor::agentName($agent);
        $this->agent = $agent;
        return $this;
    }
    private function abDir(): string { return $this->instanceDir . '/.aibuilder'; }
    public function planFile(): string { return $this->abDir() . '/plan.json'; }
    public function logFile(): string  { return $this->abDir() . '/planner.log'; }
    public function requestFile(): string { return $this->abDir() . '/plan-request.md'; }
    /**
     * The RAW goal, kept beside the built request. plan-request.md wraps the goal in the
     * planner's scaffolding, so re-planning from it would feed the scaffolding back in.
     * Remediation needs the thing the human actually asked for. See PlanRemediator.
     */
    public function goalFile(): string { return $this->abDir() . '/plan-goal.md'; }

    /** True while the planner tmux session is alive. */
    public function running(): bool { return $this->activePlanner() !== null; }

    /**
     * Any planner working THIS PROJECT, whoever started it — or null.
     *
     * The lock has to be the project, not the member. The session name is
     * "tiknix-{memberId}-plan-{slug}", but the planner's whole workspace —
     * .aibuilder/plan.json, plan-request.md, planner.log — is per INSTANCE and shared by
     * everyone with access to it. Checking only this member's session meant two people
     * decomposing the same project both saw "nothing running": the second start then
     * deleted the first's plan.json while their planner was still writing it, and the work
     * did not fail, it simply never appeared.
     *
     * @return array{session:string,member_id:int,started:int}|null
     */
    public function activePlanner(): ?array {
        foreach (TmuxManager::list('tiknix-') as $s) {
            $name = is_array($s) ? (string) ($s['name'] ?? '') : (string) $s;
            // tiknix-<memberId>-plan-<slug>. Anchored on the tail so a slug containing
            // "-plan-" cannot make one project look like another.
            if (!preg_match('/^tiknix-(\d+)-plan-(.+)$/', $name, $m)) continue;
            if ($m[2] !== $this->slug) continue;
            return [
                'session'   => $name,
                'member_id' => (int) $m[1],
                'started'   => (int) @filemtime($this->logFile()) ?: 0,
            ];
        }
        return null;
    }

    /** True once the planner has produced a plan file for ingest. */
    /** True once the planner has produced a plan for ingest (any pending file). */
    public function planReady(): bool { return PlanIngestor::pending($this->instanceDir) !== []; }

    /**
     * Is the planner actually working, and how do we know?
     *
     * planner.log is silent for the whole run: PlanRunner uses plain `claude -p`, not
     * --output-format stream-json, so nothing is written until the process exits. A live
     * planner therefore looks identical to a wedged one — the process sits at 0% CPU
     * between API turns, which is normal for an agent and indistinguishable from stuck.
     * A 17-minute decompose was reported as hung on exactly that evidence.
     *
     * The CLI does leave a trail: it appends every turn to a JSONL transcript in the
     * member's agent-state directory. Its SIZE and mtime are a real progress signal —
     * growing means turns are still happening.
     *
     * @return array{bytes:int,updated:int,age_sec:int,alive:bool}|null
     *         null when no transcript is found, which is not "stuck" — it is "cannot
     *         tell", and the caller must not render it as either.
     */
    public function activity(): ?array {
        // The planner runs in the container; its CLI transcript is in the app's agent HOME there.
        // Scoped to this run (started after planner.log was written) and to the planner (only it
        // is told to read plan-request.md); a bounded head of each candidate is read.
        $startedAt = (int) @filemtime($this->logFile());
        $php = '$best = null; $bt = 0; foreach (glob("/srv/app/.aibuilder/home/.claude/projects/*/*.jsonl") ?: [] as $f) {'
             . ' $m = (int) filemtime($f); if ($m <= $bt || $m < ' . ($startedAt - 60) . ') continue;'
             . ' if (!str_contains((string) file_get_contents($f, false, null, 0, 262144), "plan-request.md")) continue;'
             . ' $bt = $m; $best = $f; } echo $best === null ? "none" : (filesize($best) . " " . $bt);';
        try { [$c, $o] = TenantHost::ssh($this->tenant, 'app', 'php -r ' . escapeshellarg($php), null, 20); }
        catch (\RuntimeException $e) { return null; }
        if ($c !== 0 || !preg_match('/^(\d+) (\d+)$/', trim((string) $o), $m)) return null;
        $age = time() - (int) $m[2];
        return ['bytes' => (int) $m[1], 'updated' => (int) $m[2], 'age_sec' => $age, 'alive' => $age < 120];
    }

    /** Last N lines of the planner log for the UI. */
    public function logTail(int $lines = 40): string {
        $f = $this->logFile();
        if (!is_file($f)) return '';
        $all = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
        return implode("\n", array_slice($all, -$lines));
    }

    /**
     * Launch the headless planner. Writes the request brief, clears any stale
     * plan, and starts a detached tmux session running `claude -p`. Returns the
     * session name. Throws on setup failure.
     */
    public function start(string $goal, array $supersedeIds = [], bool $autoBuild = false, int $promptId = 0): string {
        $this->supersedeIds = array_values(array_filter(array_map('intval', $supersedeIds)));
        $this->autoBuild    = $autoBuild;
        $this->promptId     = max(0, $promptId);
        $ab = $this->abDir();
        if (!is_dir($ab) && !@mkdir($ab, 0775, true)) {
            throw new \Exception('Could not create .aibuilder dir.');
        }

        /* Take the start lock BEFORE deciding anything. running() is a tmux-session
           existence check, so between asking and creating the session there was a window
           in which a second request asked the same question, got the same answer, and both
           proceeded — then both cleared the plan file below. Checking and acting are one
           step now; the lock covers only the start, since the run itself is guarded by the
           live session. */
        $lockFile = $ab . '/planner.start.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) throw new \Exception('Could not open the planner start lock.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \Exception('Another planner is starting for this project right now — try again in a moment.');
        }

        try {
            $active = $this->activePlanner();
            if ($active) {
                // Name the holder. "Already running" beside a prompt marked "Never Ran"
                // reads as a contradiction; both are true, and this says whose lock it is.
                throw new \Exception(sprintf(
                    'A planner is already running for %s (started by member %d) — try again when it finishes.',
                    $this->slug, $active['member_id']
                ));
            }

            /* A plan waiting to be reviewed is finished work, and this used to delete it.
               The unlink ran before the session was even created, so a decompose that then
               failed to start destroyed the previous plan for nothing. Refuse instead: the
               plan is either ingested or discarded by a person, never by the next request. */
            $waiting = PlanIngestor::pending($this->instanceDir);
            if ($waiting) {
                throw new \Exception(sprintf(
                    'A plan for %s is already waiting to be reviewed — ingest or discard it before decomposing again.',
                    $this->slug
                ));
            }

            // Only the log is cleared, and only once nothing above objected. It is a
            // transcript, not a result. The stale completion marker goes too: this run
            // re-decides whether the goal is met, so last time's "complete" must not linger.
            @unlink($this->logFile());
            @unlink($ab . '/plan-complete.md');
            return $this->launch($goal, $ab);
        } finally {
            /* Exactly one release, on every path. This was a catch that released, plus a
               release at the end of launch() — so a launch that failed closed the handle
               and then threw into a catch that closed it again, and flock() on a closed
               stream raised a TypeError that REPLACED the real error. The queue logged
               "flock(): must be an open stream resource" and burned an attempt, with
               nothing left saying the planner had failed to start. */
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The half of start() that writes files and spawns tmux. Does not touch the lock:
     * start()'s finally owns it, and two owners is what broke this.
     */
    private function launch(string $goal, string $ab): string {

        file_put_contents($this->requestFile(), $this->buildPlanRequest($goal));
        file_put_contents($this->goalFile(), $goal);

        $script = $this->buildRunnerScript();
        $scriptFile = $ab . '/run-planner.sh';
        file_put_contents($scriptFile, $script);
        @chmod($scriptFile, 0755);

        TmuxManager::create($this->sessionName, $scriptFile, $this->instanceDir);
        usleep(400000);

        // The session is the lock from here on; start()'s finally releases the start lock
        // whether this succeeds or throws.
        if (!TmuxManager::exists($this->sessionName)) {
            throw new \Exception('Planner session failed to start (see planner.log).');
        }
        return $this->sessionName;
    }

    /** Kill the planner session (cancel). */
    public function stop(): bool {
        // A container's planner runs in its container (TenantHost::plan → tmux there); the session
        // here only waits for it. Stopping must end the agent, not just the wait.
        if ($this->tenant) TenantRun::kill($this->tenant, 'tiknix-run-' . $this->tenantPlanId());
        return TmuxManager::kill($this->sessionName);
    }

    /** The container run id of this member's planner on this project (one at a time — start() locks). */
    private function tenantPlanId(): string { return 'planner-m' . (int) $this->memberId; }

    /**
     * The detached runner script: start the planner in the container and wait for it
     * (tenant.php --plan), unpack its plan here, then ingest it into the board.
     */
    private function buildRunnerScript(): string {
        $mainProjectRoot = dirname(__DIR__);
        $ws  = $this->instanceDir;
        $log = $this->logFile();
        // Kept minimal + quote-safe: the real instructions live in plan-request.md,
        // which the planner reads with its own Read tool inside the workspace.
        $shortPrompt = 'Read the file .aibuilder/plan-request.md and follow its instructions exactly. You MUST finish by calling the submit_plan tool.';
        {
            // In the app's container, on the app's own credential (AgentTask::plan); the plan
            // (or plan-complete.md) is unpacked here, where the ingest below looks for it.
            $out = $ws . '/.aibuilder/tenant-plan.json';
            $runBlock = '{ rm -f ' . escapeshellarg($out) . ' && '
                      . TenantBuilder::tenantCommand($this->tenant, 'plan', $this->tenantPlanId(), $this->requestFile(), $out,
                                                     ['member' => $this->memberId] + ($this->agent !== '' ? ['agent' => $this->agent] : []))
                      . ' > /dev/null; php -r ' . escapeshellarg('require ' . var_export($mainProjectRoot . '/vendor/autoload.php', true)
                      . '; exit(\\app\\TenantBuilder::unpackPlan(' . var_export($out, true) . ', ' . var_export($ws . '/.aibuilder', true)
                      . ', ' . var_export($this->slug, true) . ', ' . (int) $this->memberId . ', ' . var_export($this->agent, true) . '));') . '; }';
        }

        $logArg     = escapeshellarg($log);
        $ingestArg  = escapeshellarg($mainProjectRoot . '/scripts/plan-ingest.php');
        // Match the unique names SubmitPlanTool writes, and the legacy plan.json.
        $planGlobArg = escapeshellarg($ws . '/.aibuilder');
        $slugArg    = escapeshellarg($this->slug);
        $wsArg      = escapeshellarg($ws);
        $supersedeArg = $this->supersedeIds
            ? ' --supersede=' . escapeshellarg(implode(',', $this->supersedeIds))
            : '';
        // Straight-through: tell the ingest step to approve and build immediately. The
        // member's level travels with it because the orchestrator stamps it onto the
        // endpoints the plan creates — the person who opted in is the authority for
        // what the build is allowed to expose.
        $autoBuildArg = $this->autoBuild ? ' --autobuild=1 --level=' . (int)$this->memberLevel : '';
        $promptArg    = $this->promptId > 0 ? ' --prompt=' . $this->promptId : '';
        // Only meaningful when we know which prompt to blame; otherwise the failure
        // has nowhere to be shown and the log remains the only record.
        $failedCmd    = $this->promptId > 0
            ? 'php ' . escapeshellarg($mainProjectRoot . '/scripts/plan-failed.php')
              . ' --prompt=' . $this->promptId . ' --dir=' . $wsArg
              . ' --exit="$PLANNER_RC" 2>&1 | tee -a ' . $logArg
            : 'true';
        // Sidecar workspace DB: propagate the per-instance workbench.db path (set by the AI
        // Projects sidecar via putenv) so plan-ingest.php's bootstrap writes the decomposed
        // plan to THAT db, not core's. INERT for core's own /workbench (env unset).
        $agentStateArg = "''";   // the run is in the container, on the app's own credential
        $wsDbEnv  = getenv('TIKNIX_WORKBENCH_DB');
        $wsExport = ($wsDbEnv !== false && $wsDbEnv !== '')
            ? "export TIKNIX_WORKBENCH_DB=" . escapeshellarg($wsDbEnv) . "\n" : '';
        return <<<BASH
#!/bin/bash
# Tiknix headless planner (claude -p) — instance {$this->slug}
export TIKNIX_MEMBER_ID={$this->memberId}
export TIKNIX_AGENT_STATE={$agentStateArg}
export TIKNIX_MEMBER_LEVEL={$this->memberLevel}
export TIKNIX_SESSION_NAME="{$this->sessionName}"
export TIKNIX_PROJECT_ROOT="{$mainProjectRoot}"
export TIKNIX_WORKSPACE="{$ws}"
{$wsExport}export CLAUDE_CODE_MAX_OUTPUT_TOKENS=250000

echo "[planner] instance {$this->slug} starting \$(date)" | tee {$logArg}
{$runBlock} 2>&1 | tee -a {$logArg}
# Capture the PLANNER's exit immediately. PIPESTATUS holds only the most recent
# pipeline, and several run below — reading it later reported the exit status of an
# echo, which is always 0 and told us nothing about the planner.
PLANNER_RC=\${PIPESTATUS[0]}
echo "[planner] exit=\$PLANNER_RC \$(date)" | tee -a {$logArg}
# Server-side ingest the moment the planner finishes, so the plan lands in the
# Workbench with no browser tab needing to stay open. Atomic-claim makes this
# race-safe with the AI Builder browser poll (whichever wins ingests once).
if compgen -G {$planGlobArg}"/*.plan.json" > /dev/null || [ -f {$planGlobArg}"/plan.json" ]; then
  rm -f {$planGlobArg}"/plan-complete.md"   # a real plan supersedes any stale completion marker
  echo "[planner] ingesting plan into the workbench…" | tee -a {$logArg}
  php {$ingestArg} --slug={$slugArg} --dir={$wsArg} --member={$this->memberId} --app=tiknix{$supersedeArg}{$autoBuildArg}{$promptArg} 2>&1 | tee -a {$logArg}
elif [ -f {$planGlobArg}"/plan-complete.md" ]; then
  # The planner judged the goal already built and wrote a completion marker instead of a
  # plan (see the brief's "is the goal already met?" step). NOT a failure — this is how
  # "continue to the next phase" learns there is no next phase. Leave the marker for the
  # board to read; do not run plan-failed.
  echo "[planner] GOAL COMPLETE — nothing left to plan for this goal." | tee -a {$logArg}
else
  # No plan file. That is NOT proof of failure, and treating it as one told a client
  # their decompose had failed when it had in fact succeeded: the file legitimately
  # disappears when the browser poll ingests it first, and it can be missing because it
  # was written elsewhere entirely. So hand the planner's real exit status to
  # plan-failed.php, which checks whether the prompt ended up with a plan before it
  # records anything.
  echo "[planner] no plan file after exit=\$PLANNER_RC — checking whether it really failed" | tee -a {$logArg}
  {$failedCmd}
fi
BASH;
    }

    /**
     * The decomposition brief. Strict, JSON-tool-terminated (myctobot pattern),
     * tiknix-flavored: ground first, then submit a dependency graph where
     * independent tasks can run in parallel (they will, in isolated git
     * worktrees), and file-overlapping tasks are chained via depends_on.
     */
    private function buildPlanRequest(string $goal): string {
        $goal = trim($goal);
        $digest = $this->codebaseDigest();
        return <<<MD
# AI Builder — Plan Decomposition

You are the **planning agent** for a tiknix instance. Your ONLY job is to turn the
goal below into a concrete, buildable multi-agent plan. You do NOT write code or
edit files — you produce a plan that other agents will build.

## Goal

{$goal}

## What already exists in THIS codebase — REUSE it, do not reinvent

The inventory below was auto-generated from the live instance. Treat it as ground
truth: it is what already exists right now. You do NOT need to call `codebase_map`
(it's baked in below). You MAY still call `describe("<name>")` or
`whatprovides("<concept>")` to drill into any single primitive before you commit.

{$digest}

## How to work

1. **MATCH the goal against the inventory above — this is the most important step.**
   For every capability the goal needs, classify it explicitly as ONE of:
   - **REUSE** `<existing controller/model/lib>` — it already does this; wire to it.
   - **EXTEND** `<existing>` — add a method / column / route to something of the APP's own.
     A controller marked _(runtime)_ is the platform's and cannot be extended: its URLs
     (e.g. `/admin/*`) are not the app's. A new page — even one for admins — is a controller
     of the app's own (`/cafe/customers`, not `/admin/customers`), linked from where it is needed.
   - **ADOPT** `<concept>` — it is not in this codebase, but the shared catalog has a
     ready-made, tested concept that does it. The inventory's "Available to adopt" section
     lists the catalog; `concepts_search("<the capability, in plain words>")` searches it
     and `concepts_get("<name>")` shows what one provides, requires, and which beans it
     borrows. Put the exact concept name in the task's `adopts` list: it is copied into
     that task's worktree at `concepts/<name>/` before the agent starts, so the task is to
     **adapt and wire it**, not to write it. Adopt what it `requires` too. A concept is
     COPIED in and becomes this project's own code — adopting is not a dependency.
   - **NEW** — nothing above fits and nothing in the catalog fits; you MUST justify in the
     task's description why no existing primitive and no catalog concept covers it.
   Try them in that order. Bias hard toward REUSE/EXTEND, then ADOPT. A plan that proposes
   NEW controllers, models, or services when a close match already exists above — or a
   catalog concept already does it — is a defect. If `concepts_search` reports that it
   FAILED, that is not "no match": say in the plan that the catalog could not be checked.

2. **Decompose into the smallest sensible tasks.** Each task is one focused unit
   of work a single agent can complete and commit on its own.

3. **Express dependencies as a graph.** Every task gets a stable `id` (e.g. "t1").
   List prerequisite ids in `depends_on`.
   - Tasks with **no** shared files and no ordering constraint should have an
     EMPTY `depends_on` — they will be built **in parallel, in isolated git
     worktrees**, then merged.
   - Tasks that touch the **same files**, or need another task's output, MUST be
     chained via `depends_on` so they run sequentially and don't collide on merge.

4. **Leave `engine` unset.** Every task then runs on this project's engine — the one
   you are running on now, and the one its owner chose. Set it only to move a single
   task to a different registered engine, and say why in the description.

5. **Account for data & permissions as seeds — never write the live DB directly.**
   A new route needs an `authcontrol` entry; new/seed data needs an idempotent
   `database/seeds/*.php` script (Bean wrapper: findOne/dispense/store). RedBean
   auto-creates a model's table on first store, so there is no CREATE TABLE — but the
   permission row and any starter data MUST be shipped as a seed task. Reuse an
   existing `<controller>::* = <level>` permission pattern from the inventory.

6. **Honour the owner's two answers, when the goal ends with them.**
   - `## Who it is for` — who the new pages and endpoints are for, and the authcontrol level
     to seed them at. Use that level for every route the plan adds, unless the goal itself
     names a different audience for a particular page (a public booking form with an
     admin-only list behind it is two levels: say which page gets which). State the level in
     the description of each task that adds a route. Without this section, choose the most
     restrictive level that still lets the page do its job, and say what you chose and why.
   - `## How we will know it worked` — the owner's own checks. Every one of them must be
     covered by some task: quote the check in the description of the task that satisfies it.
     A check no task covers means the plan is not finished.

## First — is the goal already met?

Before decomposing, check the goal against the inventory above (what already exists in THIS
codebase). If EVERYTHING the goal calls for is already built — there is no meaningful next
phase — do NOT invent work and do NOT call `submit_plan`. Instead:

1. Write the file `.aibuilder/plan-complete.md` — one short paragraph naming what the goal
   asked for and confirming it already exists, citing the controllers / models / routes from
   the inventory that satisfy it.
2. Reply `GOAL_COMPLETE` and stop.

Only decompose (below) when real, un-built work remains. A phase of make-work — renaming,
re-listing, or "polish" nobody asked for — is worse than reporting the goal is done: the
whole point of "continue to the next phase" is that it STOPS when the goal is realized.

## Deliverable

When (and only when) you have MATCHED against the inventory and decided real work remains,
call the **`submit_plan`** MCP tool exactly once with:

- `title` — short name for the whole plan
- `summary` — 1-3 sentences on the approach, naming the main things you REUSE
- `subtasks` — the array of tasks, each with:
  - `id`, `title`, `priority` (1 highest .. 4 lowest), `engine`
  - `description` — written in **Markdown**: lead with a one-line summary, then use
    `##` sub-headers (e.g. What to build / Steps / Notes), `-` bullet lists, and
    `` `inline code` `` for files, beans, and routes. Structure it so a builder agent
    can scan the headers first, then drill into details.
  - `files` — likely paths
  - `depends_on` — array of prerequisite ids
  - `reuses` — array of existing primitives this task builds on, as `kind/name`
    strings (e.g. `["controller/Lead","model/member","lib/Mailer"]`). Empty ONLY for
    genuinely new ground — and if it's empty, the description must say why.

Do not ask the operator questions — make reasonable assumptions and note them in
the relevant task descriptions. After `submit_plan` returns, reply `PLAN_WRITTEN`
and stop.
MD;
    }

    /**
     * Auto-generated reuse inventory for the instance, injected into the plan
     * brief so decomposition reuses existing primitives. Reuses the same
     * Introspector that backs the tiknix MCP tools, pointed at the instance
     * root. Never throws — a digest failure must not block planning.
     */
    private function codebaseDigest(): string {
        return TenantBuilder::digest($this->tenant);
    }
}
