# Sweep: things that should resolve per project, but default to core

## The invariant

> Code that runs in BOTH places — the control plane (tiknix.com) and a project clone
> (`<slug>.tiknix.com`) — must resolve *which project* from something that differs
> between them. Anything that resolves it from its own install root, its own config,
> or a hardcoded default is right on core by accident and wrong on a project silently.

The MCP bug was one instance of this. It cost a new user his first build and hid for
two weeks because every symptom landed somewhere nobody reads.

## Why these are hard to see

Three properties, all present in the original bug. Use them to rank findings:

1. **Wrong-but-plausible** — core answers the question, just about the wrong project.
   No exception, no 500. Only a human comparing two databases notices.
2. **Fails open, toward core** — the fallback is core, so a misconfigured project
   quietly becomes core rather than refusing.
3. **Evidence is off-screen** — the failure is visible only in an agent transcript,
   a log, or a column nobody renders.

## Surfaces to check

| # | Surface | Detection |
|---|---|---|
| S1 | MCP endpoint + key per worktree | `.mcp.json` writers |
| S2 | Which `workbench.db` a task tool reads | `selectWorkbenchDb` call sites |
| S3 | Authorization across the id boundary | `canView`/`canEdit`/`getVisibleTasks` |
| S4 | Path resolution from install root | `dirname(__DIR__)`, `__DIR__ . '/..'` |
| S5 | Host/URL defaults | `?? 'tiknix.com'`, `?: 'https://tiknix.com'`, `localhost:8080` |
| S6 | Control-plane detection | `is_control_plane()` and everything gated on it |
| S7 | Agent jail config (`.claude/`, `security.db`) | worktree setup |
| S8 | Hook callback URLs | `getHookUrl`, `.mcp_url`, progress hooks |
| S9 | Cron/CLI scripts that loop over projects | slug derivation, db selection |
| S10 | Sidecars (workbench/pipelines/publisher/explorer/shop) | same questions, per sidecar |

## Findings

### Fixed

| Surface | Finding | Commit |
|---|---|---|
| S1 | Worktree `.mcp.json` addressed core, not the project | wb `66b1451` |
| S2 | `get_task`/`list_tasks` never called `selectWorkbenchDb()` | `95b64c7` |
| S3 | `canView` compared a core member id against a project member table | `fa6dfe0` |
| S1 | Unresolvable MCP target logged a warning and ran anyway | wb `e1d30b8` |
| S9 | Reaper derived the slug from the directory (`mileage.tiknix` ≠ `mileage`) | `4c8fac1` |
| S5 | `Connections::brokerkey` minted a credential against `?? 'tiknix.com'` | (this pass) |
| — | `Auth::invite()` never stamped `lastLogin`; `editMember` never called `hasBuilt()` | `1e8556d` |

### S10 — sidecars: swept, clean on this bug class

workbench, pipelines, publisher, explorer, shop. All five resolve the project from
`sidecar.core_root` + `slug` + `app` and pass the directory down; none falls back to core,
and none reads core's database for project-owned data. `PipeFiles::post()` refuses on an
empty baseurl or trigger_secret rather than inventing a host — the behaviour the rest of
this sweep is trying to reach.

Two things to keep an eye on, neither a live defect:

- **Five identical copies of `instanceDir()`**, one per sidecar. They agree today. Five
  copies of one rule that must never disagree is exactly the shape that let the reaper
  drift, and there is no test that would notice if one changed.
- `explorer` reads `$inst['app']` without `?? ''` where the others guard it — a notice,
  not a wrong project.
- `PipeFiles::post()`'s refusal message names only `trigger_secret` when a missing
  `baseurl` triggers it equally. Right behaviour, misleading text.

### Also fixed

| Surface | Finding | Commit |
|---|---|---|
| S6 | `is_control_plane()` answered "unknown" as "core", and that gated a DATABASE choice — a project with an empty `app.baseurl` read and wrote the control plane's tasks. Split into `control_plane_state()` (`core\|project\|unknown`); the bool keeps its fail-safe for tool gating, `selectWorkbenchDb()` refuses on `unknown`. | `96d3d6e` |
| S8 | `ClaudeRunner::getHookUrl()` fell past the project branch to `.mcp_url` then `localhost:8080` — core, on a co-located box — whenever a project-owned task had no baseurl. Refuses instead. | `96d3d6e` |
| S7 | `GitService::copyClaudeFolder()` read whichever install ran the code (always core, since sidecars load core's autoloader). | `96d3d6e`, then `bd24bc1` |
| S7 | Agent worktrees had no `database/security.db`, so the sandbox hook took its "not found → exit 0" path with every path rule off. Now resolves via `TIKNIX_PROJECT_ROOT` → the script's own install → cwd; a missing DB is allowed **inside the jail** (bwrap is the boundary there) and blocks outside it. | `e86e3f8`, `cc38208` |
| S7 | Two path guards (`.claude/guard.php` hardcoded and level-blind; `security-sandbox.php` DB-driven). Folded into one — the four patterns unique to guard.php are now seeded rules at level 50. | `bd24bc1` |
| S5 | Every `allow` rule was shadowed by the general `/home` block at priority 1, so the capricorn, production and memory-dir exceptions had never once fired. Allows now evaluate before blocks. | `650e26e` |
| S10 | Five copies of the `<slug>.<app>` rule across the sidecars → `Model_Instance::dirFrom`, which refuses an empty slug rather than naming `ROOT/.tiknix`. | `e86e3f8` + per-sidecar |
| S1 | Provisioning (`capricorn f822367`) overwrote core's tracked `.claude/settings.json` with a reduced per-instance variant and force-added it — the origin of the whole divergence. Removed; `.claude` is inherited. | capricorn `f822367` |

`workbench-response-capture.php` **is** registered — core's `.claude/settings.json` carries
it as the `Stop` hook, so it reaches every project. Its header claimed otherwise; corrected.

### A second pass, found by driving the real UI

Greps found the first eight. These four only surfaced when a task was created, run,
approved and merged through the browser — which is the argument for doing that at the end
of a sweep rather than trusting the code read.

| Finding | Commit |
|---|---|
| **Task agents ran unjailed.** `jailFor()` required a dot in the workspace's leaf name, and a task workspace is `…/<sub>.<app>/<taskId>` — so every board-run agent ran as a real uid on the host with permission prompts off, while the wrapper it generated printed "jailed via bwrap" in both branches. jail-run.sh now reads the identity from the parent, and the comment states the branch taken. | `cc38208`, capricorn `36d842e` |
| **"Approve & Merge" could not merge an instance task.** The merge checkbox rendered only when a `prUrl` existed, and `approve()` deliberately skips PR creation for instance tasks. So the box never appeared, the JS sent `merge_pr=0`, `localMergeBack()` never ran, and the task was marked completed with its code left in a workspace the same dialog offers to delete. | workbench `d9d5138` |
| **A conflicted subtask had no recovery.** The failure card rendered only for `failed`, hiding both the merge output and the plan-aware Fix & retry; the Run button also excluded `conflict`. Plan 72 sat stalled behind task 76 for a day with Edit as the only action. | workbench `e25e2b1` |
| **The prompt was pasted after a guessed 500ms.** `spawn()` slept a flat half-second "for Claude to initialize" then pasted the task prompt into the pane. Under bwrap the UI is not up by then, the paste goes nowhere, and the task reads `running` while nothing runs. Now polls for the interactive footer. | `cc38208` |

### S4 and S9 — the two surfaces the first pass never actually ran

Both had findings fixed by accident earlier; neither had been swept.

**S9 (scripts that loop over projects) — a real bug.** `core.tiknix` is a SYMLINK to the
control plane and has a `data/workbench.db` of its own, so `glob('default/*.tiknix')`
matches it. All three loopers included it: the reaper would release and reap CORE's tasks,
`repair-selfauth-permissions` would rewrite permission rows in CORE's database, and
`add-playwright-mcp --all` would overwrite core's `.mcp.json`. None excluded it, and none
could by name. All three now call `Model_Instance::isProvisionedInstance()`. `478f1f0`

**S4 (path resolution from the install root) — clean.** 69 sites; `dirname(__DIR__)` is
correct wherever it means "this install", which is nearly all of them. The cross-project
classes (`PlanExecutor`, `PlanRunner`, `PlanOrchestrator`, `Introspector`) all take an
explicit directory. One blemish, not live: `AgentState.php:99` hardcodes
`database/tiknix.db` under its own root, so on an instance the file is absent, the PDO
throws into a `catch`, and credential adoption silently returns nothing. Only core
launches agents today, so nothing depends on it.

### Open

Not defects, but the same family — worth a decision rather than a fix:

- **Jailing made `pkill -f` lethal to the agent that runs it.** `jail-run.sh` binds
  `--ro-bind /opt/google/chrome /opt/google/chrome`, so the string `chrome` is in the
  jail's argv. Task 76's agent ran `pkill -9 -f chrome` to tidy up browsers, matched the
  bwrap process itself, and SIGKILLed its own jail mid-work (exit 137) — losing nothing
  only because the work was recoverable from the worktree. Before jailing, the runner was
  a bare `claude` with no `chrome` in its command line. Durable fix is `bwrap --args FD`,
  which keeps bind paths out of argv entirely.

- **`plan_status` and `status` are two columns for one concept.** `Model_Workbenchtask::isPlan()`/`displayStatus()` now answer "which lifecycle is this row on" in one place, but every reader still has to ask. Collapsing to one column touches the orchestrator, the reaper, the Build gate and the board.
- **`securitycontrol.scope` could take an `agent` value.** With agent-level = operator-level, a path rule cannot separate the two. An `agent` scope keyed on `TIKNIX_TASK_ID` would, without level games.
- **`services/NotifyService.php:340`** still ends `?: 'localhost'`.
- **Instances with `query_cache = true` and no `version_store`** default to APCu, which is per-SAPI — so a CLI write is invisible to php-fpm until the TTL expires. Observed live: a password set from the CLI failed to log in for 60s. `version_store = valkey` fixes it.

Re-check is cheap: rerun the greps above and confirm no new `?? 'tiknix.com'`, `?: 'localhost'`,
`dirname(__DIR__)` path resolution, or second copy of a rule has appeared — then drive one
task through the UI end to end, because that is what found the second pass.

### Not defects (checked, deliberate)

- `TiknixHostedDriver`, `ProxmoxDeploy`, `BrokerService` resolve core on purpose —
  hosted publishing and provisioning genuinely address the control plane.
- `PlanExecutor` copies the project's own `.mcp.json` already, and takes an explicit
  `--db`. This is the path that worked.

## Method

For each surface: locate every writer/reader, prove which one a project actually gets
(read the artifact on disk or call the endpoint — do not infer), fix, then verify by
the same observation. Prefer refusing over defaulting: a wrong project is worse than
a stopped run.
