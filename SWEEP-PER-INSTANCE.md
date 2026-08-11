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

### Open — ranked

**1. `is_control_plane()` fails open to core, and now gates a database choice.**
`lib/functions.php:36`. An empty `app.baseurl` returns `true`, so a project with a
missing or malformed baseurl identifies as the control plane. `BaseTool.php:86`
early-returns on that, meaning such a project reads and writes **core's** task data.
The fail-safe was written when this only gated tool availability; it now decides which
database you are in. Hits all three hardness properties.

**2. `ClaudeRunner::getHookUrl()` falls back to `http://localhost:8080/mcp/message`.**
`lib/ClaudeRunner.php:128`. On this box that is core. Reached whenever
`TIKNIX_WORKBENCH_DB` is unset and no `.mcp_url` exists — the same silent core
redirection just fixed for `.mcp.json`, on the progress-hook path.

**3. `GitService::copyClaudeFolder()` copies CORE's `.claude`.**
`lib/GitService.php:145` — `dirname(__DIR__) . '/.claude'`. Agent worktrees therefore
lose the project's own `guard.php` (which protects billing, vendor, `.claude` itself)
and get core's hook set instead. Confirmed on mtmoses's worktree.

**4. Agent worktrees have no `database/security.db`.**
`scripts/hooks/security-sandbox.php` exits 0 when the file is missing — "allow on error,
fail open" — so in a worktree every path rule is off. That is how `lib/Mailer.php` and
`conf/config.example.ini` were editable there.

**5. `workbench-response-capture.php` may not be installed.**
Its own header says provisioning does not register it; only `cli/setup-hooks.sh` does.
Correct about the database, possibly never invoked. Verify per project.

### Blocked on write access

Findings 1, 2 and 3 all live under `lib/`, which security rule 14 (`Protect core libs`,
`protect`, level 50) makes read-only for this session — it runs at the default level 100.
The fixes are specified above and ready; they need `TIKNIX_MEMBER_LEVEL=50` in the
environment (via `.claude/settings.json` `env`, or one session launched with it).

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
