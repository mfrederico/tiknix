# Task Notes — how agents actually get run

Concepts a session needs before touching the plan/build pipeline. Each entry exists because
getting it wrong produced a real, quiet failure — the kind that looks like success.

See also: `AGENT_ORCHESTRATION.md` (planner/worker/audit rules), `CLAUDE.md` (standards,
including **Rule 6: No Fallbacks**).

---

## 1. One resolver decides engine, model and credentials

`lib/AgentContext.php`:

```php
AgentContext::for($memberId, $tier, $instanceDir, ?$engineHint, ?$modelHint)
  → ['engine' => …, 'model' => …, 'stateDir' => …]
```

Engine resolves **hint → the project's `.aibuilder/engine` → registry default**, each
validated. Model is an explicit override, else the registry's tier for *that* engine.
The credential store follows the same engine.

**These three must agree.** When they didn't, the failures were silent: a task dispatched
on one provider while bound to another's credential store reads as an auth error from the
wrong vendor; a model tier resolved from the *default* engine sends `sonnet` to a provider
that has never heard of it.

Callers: `PlanRunner` (planner), `PlanExecutor` (worker), `ClaudeRunner` (worker),
`AuditRunner` (auditor). They legitimately differ in **where the hint comes from** and
**which tier** they want — nothing else.

> **Never read `.aibuilder/engine` from a workspace.** A per-task workspace is a git clone
> and `.aibuilder/` is not in it, so the read always misses and falls through to claude.
> Read it from the *instance*. Four separate runners had this bug.

`tests/agent/resolve-probe.php` prints `(engine, model, stateDir)` for every runner that
emits a launch script, read from the generated bash rather than recomputed. Run it before
and after any change here and diff.

---

## 2. Two dispatch paths, on purpose

|  | standalone task (`ClaudeRunner`) | plan subtask (`PlanExecutor`) |
|---|---|---|
| launch | `claude --debug` — interactive TUI | `claude -p 'BRIEF' --output-format stream-json` |
| brief | **pasted** into the input box via tmux | passed as an **argument** |
| where | one attachable session | a git worktree per task, N in parallel |
| output | a screen for a human | JSON for machines |

Consequences worth remembering:

- The **paste can time out** — only in the standalone path. A subtask has no input box to
  miss. `pasteUntilLanded()` uses a *deadline* (120s), not an attempt count, because a
  jailed session on a slow provider needs longer than claude outside a jail.
- A task stuck at `queued` with a live session means the paste never landed. The brief is
  on disk in `/tmp/tiknix-<member>-task-<id>/prompt.txt` — but note that file is
  **overwritten by every message sent**, so it holds the latest hint, not the original brief.

---

## 3. Providers limit concurrency per MODEL

`conf/aibuilder.ini`:

```ini
concurrency[glm-5.3]       = 1
concurrency[glm-5.3-flash] = 50
max_concurrency = 1     ; for any model not listed
```

z.ai serves GLM-5.3 one at a time. Running the orchestrator's default three agents against
it puts two of them in `529 overloaded` retry loops — spending wall-clock and quota to
accomplish nothing. **Launching fewer is faster.**

Two caps, protecting different things: `PlanExecutor::MAX_CONCURRENT` is *ours* (this
machine, the operator's quota); the registry's is the *provider's*. Counted per
`engine:model`, so a plan mixing tiers is not throttled by its slowest one.

This is also the lever for a fast-worker split, the GLM equivalent of sonnet/haiku: point
`worker_model` at `glm-5.3-flash` and builds run the full width while the planner keeps the
frontier model it can only have one of. **Untested for build quality** — every successful
build so far was `glm-5.3` doing the work.

---

## 4. Time budgets are derived, not chosen

`PlanExecutor::timeBudgetTicks()`:

```
per-model:  waves = ceil(remaining_tasks ÷ min(MAX_CONCURRENT, provider_cap))
            ticks = waves × measured_per_task_budget
total = sum over models, capped at 12h
```

The per-task figure is **measured from finished subtasks on this instance** — p95 doubled,
clamped to 15–90 min, with a documented default below 5 samples (with four data points a
p95 is just the maximum).

Observed across 387 finished subtasks: claude p95 **13 min**, z.ai p95 **35 min**, qwen p95
**1.5 min**. One constant could never have fit all three.

**A build that runs out of time is not a build that finished.** Four outcomes, not three:
done, stalled, finished-with-failures, and **ran-out-of-time** — which finalizes nothing and
audits nothing. Plan #111 hit a fixed 2h ceiling with one subtask running and two pending,
and was finalized, emailed and audited as complete; seeds from a partial build were applied
to the live instance.

---

## 5. The planner is silent while it works

`PlanRunner` runs plain `claude -p` (**not** `stream-json`), so `planner.log` stays empty
until the process exits, and the process sits at 0% CPU between API turns. A working
decompose is indistinguishable from a wedged one by any obvious signal.

`PlanRunner::activity()` reads the CLI's JSONL transcript in the member's agent-state
directory — size and mtime are the progress signal. Scoped to the planner by two filters:
started no earlier than this run, and contains `plan-request.md` (only the planner is told
to read it). The terminal writes transcripts to the same folder.

Returns `null` for "cannot tell" — which is not "stuck", and must not render as either.

---

## 6. Config does not reach task workspaces by itself

`conf/*.ini` is **gitignored**, so a per-task clone never contains it, and `jail-run.sh`
reads `[engine.<name>]` from `$INSTANCE/conf/aibuilder.ini` where `$INSTANCE` *is* that
workspace. Result: `engine 'zai': no anthropic_base_url in [engine.zai]`.

`GitService::ensureEngineConfig()` copies it, called on **every run** (not at clone time):
`cloneToWorkspace` runs once, on a task's first run, and every retry reuses the workspace.
Fixing only the clone path left 47 of 48 existing workspaces broken.

Only `aibuilder.ini` is copied — `config.ini` carries the database path and would point a
throwaway workspace at the live instance's database.

**An agent cannot change runtime config from a worktree, in either direction:**

| file | tracked? | loaded by the app? |
|---|---|---|
| `conf/config.ini` | gitignored | **yes** |
| `conf/config.<slug>.ini` | **tracked** | no |

Edit the file the app reads and it never merges; edit the file that merges and nothing
reads it. A task that wrote `version = "1.0.0"` to the tracked file shipped an endpoint
reporting `"version":"unknown"` — the edit merged cleanly and did nothing. A task needing a
new setting must name it in its summary for a person to add. Core's config is outside the
jail entirely.

---

## 7. Standing traps

- **Fluid mode invents columns.** An unknown bean property silently becomes a real
  always-NULL column, which makes a *wrong query valid* — `SELECT controller FROM authcontrol`
  returned 0 rows for a column that should never have existed. `lib/SchemaAuditWriter.php`
  logs every automatic schema change with its call site. Freezing is **not** an option:
  features create tables lazily (`threadmember` had no seed at all), and 36 of core's 46
  tables have never been seeded.
- **`clitool --sql/--exec` bypass the ORM on purpose.** Fluid mode swallows
  `no such column`/`no such table` and returns an empty result, so a typo'd column reported
  `(0 rows)` and exit 0 — indistinguishable from an empty table.
- **WAL is on for `data/workbench.db` and core's `tiknix.db` only.** App databases
  (`database/<slug>.db`) stay in delete mode because `snapshot-instance.sh` commits the
  `.db` into git, and a commit without its `-wal` loses recent transactions.
- **Instances are clones.** Editing core does nothing for a running instance until it takes
  an upgrade. Verify *which copy* the failing code is reading before concluding a fix
  didn't work.

---

## 8. Known gaps

- The **audit pass has never succeeded on z.ai** — it launches and exits without a manifest,
  and the log gives no reason.
- **`ClaudeRunner` and `PlanExecutor` remain separate dispatchers.** Only *resolution* is
  unified; a change to how agents are launched still has two homes.
- The **cap-cycle model escalation was removed** (it passed a plan-wide model that no longer
  exists). Restoring it means setting a model on the *tasks*, by engine tier — `'opus'` is
  meaningless to a provider that has no opus.
- **Standalone tasks have no Retry.** `taskretry` refuses anything without a parent plan;
  Run works instead.
