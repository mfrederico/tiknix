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
| S7 | Agent worktrees had no `database/security.db`, so the sandbox hook took its "not found → exit 0" path with every path rule off. Now resolves via `TIKNIX_PROJECT_ROOT` → the script's own install → cwd, and FAILS CLOSED when it finds none. | `e86e3f8` |
| S7 | Two path guards (`.claude/guard.php` hardcoded and level-blind; `security-sandbox.php` DB-driven). Folded into one — the four patterns unique to guard.php are now seeded rules at level 50. | `bd24bc1` |
| S5 | Every `allow` rule was shadowed by the general `/home` block at priority 1, so the capricorn, production and memory-dir exceptions had never once fired. Allows now evaluate before blocks. | `650e26e` |
| S10 | Five copies of the `<slug>.<app>` rule across the sidecars → `Model_Instance::dirFrom`, which refuses an empty slug rather than naming `ROOT/.tiknix`. | `e86e3f8` + per-sidecar |
| S1 | Provisioning (`capricorn f822367`) overwrote core's tracked `.claude/settings.json` with a reduced per-instance variant and force-added it — the origin of the whole divergence. Removed; `.claude` is inherited. | capricorn `f822367` |

`workbench-response-capture.php` **is** registered — core's `.claude/settings.json` carries
it as the `Stop` hook, so it reaches every project. Its header claimed otherwise; corrected.

### Open

Nothing from this sweep. All eleven projects carry the canonical `.claude`, the seeded
rules, and one instance-path rule; bookingscheduler's Leads conflict is resolved and it is
current with core.

Remaining surfaces are cheap to re-check whenever something moves: rerun the greps in the
table above and confirm no new `?? 'tiknix.com'`, `?: 'localhost'`, `dirname(__DIR__)`
path resolution, or second copy of a rule has appeared.

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
