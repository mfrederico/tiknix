# Tiknix Agent Orchestration Principles

How the AI Builder plan pipeline (`PlanRunner` → `PlanExecutor` → `AuditRunner`)
coordinates agents. Inspired by Cursor's "agent swarm / model economics" findings,
but **adapted to tiknix's scale** — a handful of subtasks per plan, git worktrees,
one instance — not a thousand-agent swarm. As the plan pipeline is wired to these
rules, its brief builders (`PlanRunner::buildPlanRequest`,
`PlanExecutor::buildTaskBrief`, `AuditRunner::buildAuditRequest`) should cite the
section numbers below (inlining the rule text, since this file won't exist in older
instance clones until they sync from core).

---

## 1. The planner owns design; workers follow spec
- The **planner** (`PlanRunner`, frontier model) is the ONLY agent that makes design
  decisions. It grounds on the baked reuse inventory (`Introspector` digest) and MUST
  classify every capability **REUSE / EXTEND / NEW** before proposing work.
- A subtask description is a **spec, not a suggestion**. Workers implement it; they do
  **not** re-decide architecture. If a worker finds the spec wrong, it **stops and
  reports** — it does not redesign. (Mechanism: `task.md` brief + the deterministic
  `reuseBrief()` expansion of the planner's `reuses` into exact routes/columns/methods.)
- **Spec clarity is the bottleneck, not execution.** A subtask a cheap model can't
  execute without a judgement call is an under-specified subtask — fix the *plan*, not
  the worker.

## 2. Frontier plans, cheap executes — the cost lever
- The planner runs the **frontier tier** (opus). Workers run **the cheapest engine that
  can follow the spec**: claude/sonnet for judgement, `qwen` (or other registered
  engines) for mechanical edits. The planner assigns this per task via the subtask
  **`engine`** field; the executor **MUST honor it** (see §7 and *Status* below).
- Why: Cursor measured ~8× cost at equal quality between frontier-only and hybrid
  plan/execute. tiknix already tiers *models* (opus planner / sonnet workers); the
  remaining lever is honoring per-task *engine* choice.
- **Escalation rule:** a task that fails auto-retry on a cheap engine may be re-queued
  **once** on the frontier engine before it is marked failed.

## 3. Coordination beats throughput
- `MAX_CONCURRENT` stays small (3). Fewer, better merges: the **orchestrator** commits
  and merges; **workers never run git**.
- File-overlapping tasks MUST be chained via `depends_on` (a planner rule).
- **Megafile rule:** the planner flags any task whose `files` include a file over ~2k
  lines (e.g. `controls/Workbench.php`) and either splits the work or serializes every
  task touching it.
- **License intentional breakage:** only the *plan* (not a worker) may declare "this
  task breaks X until task Y lands," recorded in the task description.

## 4. Decorrelated, stacked review
- **Lens 1 (exists):** `AuditRunner` — an *output-only* lens that drives the live public
  site as ROOT/ADMIN/MEMBER with Playwright, screenshots, and writes `audit.json`.
- **Rule: the review model MUST differ from the worker model.** Same-model review
  catches correlated errors poorly (Cursor's finding). Minimum: workers=sonnet →
  auditor=opus; ideally a *different engine* once one is registered.
- **Lens 2 (planned):** a *codebase-only diff* lens — pre-merge validation of the task
  branch diff via `lib/PhpValidator.php` / `ValidationService`, no transcript access.
- **Lens 3 (optional, cheap):** a *transcript* lens over the agent's `stream-json` log.

## 5. Neutral third-party conflict resolution
- A merge conflict is resolved by a **fresh agent that authored neither side**: new
  session, resolution-only brief, keeps **both** intents, commits the merge, changes
  nothing else — ideally a different engine/model than the task's author.
- Mechanism: `Workbench::resolveconflict`. Today it reuses the author's `ClaudeRunner`
  (fresh session, but same engine + task identity); this rule licenses making the
  resolver a genuinely different runner.

## 6. The field guide travels with every agent
- Every run gets: **CLAUDE.md** conventions, the tiknix **MCP reuse tools**
  (`reuse_digest` / `codebase_map` / `whatprovides` / `describe`) via the per-workspace
  `.mcp.json`, and (workers) the deterministic `reuseBrief()` expansion.
- **Codify surprises:** when an audit failure or a repeated auto-retry reveals a
  convention gap, the fix lands in CLAUDE.md or the brief builders — **not** in a
  one-off prompt. The guide is how run N+1 learns from run N.

## 7. Engines are first-class — the registry
- **One registry** (`conf/aibuilder.ini [engine.*]` + a small `lib/EngineRegistry`)
  maps each engine → launch command, **transport** (PTY / headless CLI / ACP), auth,
  model tiers (planner|worker|auditor), and capability quirks. **Everything that spawns
  an agent resolves through it**: the terminal bridge (`ENGINE` env), `PlanRunner`,
  `PlanExecutor`, `AuditRunner`, `resolveconflict`.
- Engines are **rows in the registry, not a `claude` + `if qwen` branch** —
  kimi / gemini / qwen / goose / hermes are first-class. The engine-name lists that are
  today duplicated three ways (`aibuilder-terminal/server.js`, `controls/Aibuilder.php`,
  `lib/PlanIngestor.php`) derive from the registry.
- **Unknown/unregistered engine → fall back to the instance default (claude) and log a
  warning; never fail a task over engine choice.**

## 8. What we deliberately DON'T copy from Cursor
- **No custom VCS.** Cursor's 1,000-commits/sec engine solved a ~100-agent-per-task
  problem. tiknix runs ≤3 concurrent workers per plan; **git worktrees + orchestrator
  merges are correct at this scale.** Building merge infrastructure beyond worktrees is
  an over-engineering trap — revisit only if concurrency grows ~10×.
- **No planner hierarchy** (planners spawning planners). One planner per plan is the
  right depth for a handful of subtasks.

---

## Status — the one real gap: per-task `engine` is not yet honored

The `engine` field is currently **fiction on the execution path**. It flows
schema (`mcptools/SubmitPlanTool.php:33`) → ingest (`lib/PlanIngestor.php:85`) → the
`workbenchtask` bean → the UI, but **`lib/PlanExecutor.php:407-410` hardcodes the
`claude` CLI**, and the "engine" mention at `PlanExecutor.php:178` is only a *log line*
that reports the field while ignoring it. `PlanRunner` (`:121-141`) and `AuditRunner`
(`:118-133`) are claude/model-hardcoded the same way.

**The change (sequenced, each independently landable):**
1. This doc + a CLAUDE.md pointer + one-line pointers from the three brief builders. *(docs, zero risk)*
2. `lib/EngineRegistry.php` + a `[engine.claude]` ini section; derive the validation
   lists in `PlanIngestor.php:85` and `Aibuilder.php:311` from it.
3. **`PlanExecutor.php:407-410`** — resolve `$t->engine` through the registry:
   `EngineRegistry::headlessCmd($engine, $prompt, $model)` with a **safe interim** —
   if the engine has no registered headless launcher, run claude + `$engineModel` and
   log `warning: engine '<x>' has no headless launcher — ran on claude` (the honest
   version of today's `:178` line). Nothing breaks; the field becomes best-effort real.
4. `PlanRunner` / `AuditRunner` resolve model tiers via the registry; apply the §4
   auditor-decorrelation rule.
5. `resolveconflict` gains a fresh-runner engine override (§5).
6. *(with the owner, outside this repo)* confirm/extend `jail-run.sh` headless dispatch
   for qwen/hermes so the fallback stops firing — then the ACP phases.

**This is the same work as the ACP branch** (`acp.tiknix/ACP-SCAFFOLD.md`): making the
per-task engine real is ACP Phase B, and the registry is what both the interim
headless path and the ACP sidecar resolve through — so the registry lands **first**.
The point of ACP is precisely to get **other CLI systems** (kimi/gemini/qwen/goose)
into play as first-class engines — do not implement the registry as claude-plus-an-
afterthought.
