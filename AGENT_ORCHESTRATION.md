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
- **Planning defaults to a frontier tier, but the planner engine is SELECTABLE**, not
  hardcoded. Each engine declares its own `planner_model` tier in the registry
  (`[engine.<name>]`), and `PlanRunner` resolves the planner model from the instance's
  engine — claude's planner tier is `opus` (the sensible default), but an instance on a
  different engine plans on *that* engine's declared planner tier. Policy lives in the
  registry, not in code.
- Workers run **the cheapest engine that can follow the spec**: claude/sonnet for
  judgement, `qwen` (or other registered engines) for mechanical edits. The planner
  assigns this per task via the subtask **`engine`** field; the executor **honors it**
  through the registry, falling back to claude + a warning when an engine has no proven
  headless launcher yet (see §7 and *Status* below).
- Why: Cursor measured ~8× cost at equal quality between frontier-only and hybrid
  plan/execute. tiknix tiers *models* per engine (planner/worker/auditor/resolver) AND
  honors per-task *engine* choice — both levers, resolved in one place.
- **Members may override the model tiers** for the runs they trigger, from their settings
  page (`lib/MemberEnginePrefs`, stored as `settings` rows `engine.<engine>.<tier>_model`).
  An override only fills in over the registry default — it never beats a more explicit
  choice (a planner-assigned per-task engine still wins). A member with no override
  inherits the current system default, so nobody has to think about it per instance.
- **Escalation rule:** a task that fails auto-retry on a cheap engine may be re-queued
  **once** on a higher tier (its engine's frontier model, or the frontier engine) before
  it is marked failed.

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
  nothing else — on a **different tier than the task's author**.
- Mechanism: `Workbench::resolveconflict`. It spawns a fresh `ClaudeRunner` session and
  now runs it on the author engine's **`resolver_model` tier** (defaults to the
  frontier/planner model, e.g. `opus`) via `ClaudeRunner::setModelOverride` — a
  genuinely different model from the worker that built the branch, resolved through the
  registry (§7). A different *engine* awaits non-claude interactive dispatch (Phase A);
  until then the model tier is the honest decorrelation lever, and the resolver note is
  written to the task log.

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

## Status — Phase R is built; per-task `engine` is now honored

The `engine` field used to be **fiction on the execution path** — it flowed through
schema → ingest → bean → UI but `PlanExecutor` hardcoded the `claude` CLI and only
*logged* the field. **Phase R closed that.** The registry (`lib/EngineRegistry.php` +
`conf/aibuilder.ini [engine.*]`) is now the single resolution point, and every spawn
site resolves through it.

**Done (each landed independently):**
1. ✅ This doc + CLAUDE.md pointer + brief-builder pointers. *(docs)*
2. ✅ `lib/EngineRegistry.php` + `[engine.claude]` / `[engine.qwen]` ini sections with
   per-tier models (`planner_model` / `worker_model` / `auditor_model`) and a
   `headless_ready` flag. Validation in `PlanIngestor` (`EngineRegistry::coerce`) and
   `Aibuilder::create` now derive from the registry — no more `['claude','qwen']`
   literals.
3. ✅ **`PlanExecutor::launchTask`** resolves `$t->engine` via
   `EngineRegistry::agentCommand($engine, $prompt, $model, ['stream'=>true])`. Safe
   interim in force: an engine with no proven headless launcher (`headless_ready`
   unset, e.g. qwen today) falls back to claude + the worker model and logs
   `warning: engine '<x>' has no headless launcher — ran on claude`. Nothing breaks;
   the field is best-effort real, and the started-on log reports the engine actually used.
4. ✅ **`PlanRunner`** resolves the planner model from the engine's `planner_model` tier
   (planner is now *selectable*, not hardcoded opus — §2). **`AuditRunner`** resolves its
   `auditor_model` tier the same way.

**Remaining (owner / later phases):**
5. ✅ `resolveconflict` runs the resolver on the `resolver_model` tier
   (`ClaudeRunner::setModelOverride`), decorrelated from the worker (§5). A different
   *engine* (not just model) awaits non-claude interactive dispatch (Phase A).
6. *(with the owner, outside this repo)* wire `jail-run.sh` headless dispatch for
   qwen/hermes and flip their `headless_ready = true` — the fallback stops firing and
   non-claude workers run natively. Then the ACP phases.

**This is the same registry the ACP branch resolves through** (`acp.tiknix/ACP-SCAFFOLD.md`):
the interim headless path and the future ACP sidecar share one set of `[engine.*]` rows,
so kimi/gemini/qwen/goose/hermes are **first-class rows, not a claude-plus-an-afterthought**.
Flipping an engine from fallback to native is a config change (`headless_ready = true` +
a launcher), not a code fork.
