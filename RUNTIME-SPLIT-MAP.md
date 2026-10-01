# Runtime split map

Where core's code goes when tiknix is split into a **runtime** every app runs on and a
**control plane** only tiknix.com runs. Measured 2026-09-28 against core `98535dd`; the
analysis script is reproducible (token-level references between classes, bean/SQL table
names, dual-mode helper calls) and its output is summarised here, then corrected by hand
where the heuristic was wrong. Context: the owner's call to rebuild into per-project
containers (`no-live-users` — nobody uses tiknix yet, so this is a rebuild, not a migration).

## 1. Verdict

The boundary is **cleaner than it feels**. Of 351 PHP files (~79k lines):

| | files | lines |
|---|---:|---:|
| Runtime, no control-plane reference at all | 203 | 36,000 |
| Control plane by purpose (planner, provisioning, billing, catalog server, git server, marketing) | ~95 | ~27,000 |
| Runtime files with a control-plane piece inside (**the tangles**, §4) | ~38 | ~16,000 |

The tangles are concentrated: two dozen controllers and libraries, almost all with the same
shape — a runtime feature plus one control-plane branch bolted on (login + tiknix's own
invite funnel, the Contact form + routing a ticket to a project, Connections + the builder's
hub for other projects). Each splits cleanly into a runtime file and a control-plane
extension. Nothing found needs redesign.

**The real risk is not in core — it is in how apps are built.** The builder edits core's own
files to customise an app: Serenity has modified 14 core files (Admin, Contact, Dashboard,
Error, Help, Index controllers; layouts…), cat-poo-box 5 (Contact, PermissionCache,
functions.php, header, footer). (Older instances show 38–54, inflated because they are
behind core.) In a package world those edits are impossible by design, so the split only
works if §5's override rules exist and the agent guidance teaches them **before** the next
app is built.

## 2. The three parts

1. **`tiknix/runtime`** — a Composer package in its own repository, versioned (the release
   tags already exist: v1.6.x). Everything an app needs to run and to be built inside its
   container: framework glue, primitives, the MCP server, pipelines, connectors, plugins
   runtime, the agent runner.
2. **`tiknix-app`** — the template repository a project starts from: `public/index.php`, a
   thin `bootstrap.php`, `conf/*.example.ini`, `composer.json` requiring `tiknix/runtime`,
   empty `controls/ views/ models/ concepts/ connectors/`, its own `CLAUDE.md`. A project is
   a fork of this, never a clone of core.
3. **`tiknix` (control plane)** — this repository, slimmed: it also requires
   `tiknix/runtime` (it is an app too — login, teams, communications) and adds the planner,
   provisioning, billing, catalog server, git server, broker, firehose, marketing site.

Updating an app becomes `composer update tiknix/runtime`, not a merge of core's history —
which retires the recurring upgrade conflicts outright.

## 3. File assignment

### Runtime package (as is)
- **lib (49):** AgentGuidance, ApcuVersionStore, Bean, CacheVersionStore(Factory),
  CachedDatabaseAdapter, CliHandler, ConceptException, ConceptLock, ConceptManifest,
  Concepts, ConnectionBindings, CreditAlert, DataTableResponse, EncryptionService,
  ErrorReporter, Feature, GitHubService, HtmlSanitizer, InstanceAutomations, LeadGate,
  LeadSpamCheck, LeadValidator, LogReader, Mailer, MarkdownParser, Mentions, Mqtt, Notes,
  OAuthStateService, PermissionCache, PhpValidator, PublicLink, RateLimiter,
  RedisVersionStore, Rooms, SchemaAuditWriter, ShopifyGateway, SimpleCsrf, SiteScoped,
  Sites, StripeGateway, Support, Teammates, ThreadMembers, Turnstile, TwoFactorAuth,
  ValidationService, fatal-handler.
- **lib/Pipeline (28 incl. Steps), lib/Scaffold (11)** — whole.
- **services:** ApiAuthService, NotifyService, Config/*, Schema/WorkspaceSchemaBuilder,
  connectors/* (13).
- **Seeds (14):** 01_Member, 02_AuthControl, 03_EmailThread, 04_ExternalIdentity,
  05_SecurityControl, 07_Billing, 10_AccountClosure, 11_MemberFreeProjects,
  17_SupportTicketSource, 18_MemberAudit, 19_LeadCapture, 21_Sites, 22_ConnectionBindings,
  23_MailConnection. (07 and 11 look like control-plane seeds — re-check when moving.)
- **controls (22):** Api, Apikeys, Communications, Connectorapi, Demo, Docs, Error, Help,
  Install, Lead, Map, Mcptools, Permissions, Pipeline, Privacy, Security, Settings, Site,
  Terms, Test, Translations, Webhook.
- **models (10):** Authcontrol, Contact, Externalidentity, Lead, Mcplog, Message, Team,
  Teaminvitation, Teammember, Thread.
- **mcptools (33):** the app's own tools (introspection, database, logs, pipelines,
  validators, concepts search/get, the local MCP server, ToolLoader).

### Runtime package — the agent runner (reclassified by hand)
The heuristic filed these as control plane; they are what runs an agent INSIDE a project —
the builder over SSH (§ the container plan) and a pipeline's agent step alike:
ClaudeBinary, AgentState, AgentContext, EngineRegistry, MemberEnginePrefs, Model_Agent,
Model_Modelconnection, seeds 12_Agent / 13_ModelConnection / 14_ModelCall /
15_MigrateEngineTokens, Pipeline/Steps/AgentStep.

### Control plane (as is)
- **lib:** AuditReporter, AuditRunner, BillingLifecycle, BrokerService (server side),
  ClaudeRunner, ConceptLint, ConnectorPush, GitHttp, GitHubPublisher, HostedDeploy,
  InstanceRepo, Invite, MondayImport, Plan{Executor,Handoff,Ingestor,Notifier,Orchestrator,
  Remediator,Runner}, PortManager, ProjectContext, ProjectQuota, PromptBuilder, PromptLog,
  PromptQueue, ProvisionService, ProxmoxDeploy, ProxmoxService, SignupFlow,
  TaskAccessControl, TmuxManager (its commands run in the container over SSH),
  WorkspaceManager, Publish/* (9).
- **controls:** About, Agentsetup (configures another project), Billing, Brokerinfo,
  Concepthub, Firehose, Git, Handoff, Invites, Neosaas, Pricing, Projects, Provision,
  Publish, Sidecar, Social, Stories.
- **models:** Instance, Showcase, Taskcomment, Tasklog, Tasksnapshot, Workbenchtask.
- **mcptools/workbench/*** (task tools — the builder's), SendNoteTool, SendToTiknixSupportTool.
- **scripts:** plan-*, reap-stale-tasks, explain-stall, prompt-*, instance-*, proxmox-*,
  capture/seed-showcase, sync-social-feeds, concept-install, grandfather-billing,
  aibuilder-provision, add-playwright-mcp, repair-selfauth-permissions.
- **Seeds:** 06_InstanceAudit, 08_PendingSignup, 16_PlanThreadsPerProject, 19_InstancePlan,
  20_PlanHandoff.

### Deleted by the container move
IsolatedPool, CoreDb, `scripts/instance-acl.php`, `scripts/trim-instance.php`,
`scripts/upgrade-instances.php`, seed 09_InstanceIsolation, capricorn
`isolate-instance.sh` / `jail-run.sh`, `ConnectionStore::forInstall/withInstall`
(core can no longer reach a project's store — `ConnectorPush` over HTTP is the only path),
every `is_core_install / builder_tools_enabled / is_control_plane` branch (18 files).

## 4. The tangles, one line each

| File | Runtime part | Control-plane part → moves to |
|---|---|---|
| controls/Auth | login, register, 2FA, reset | `/auth/invite` + tiknix signup gating (Invite, SignupFlow, GoogleAuth plugin) → CP `Signup` controller |
| controls/Leads | the app's lead list | invite-state column (tiknix's own funnel) → CP extension of the list |
| controls/Contact | contact form, admin replies | routing a ticket to a project (`Bean::load('instance')`) → CP support |
| controls/Index | the app's home | showcase landing / stories → CP `Index` override |
| controls/Teams | app users' teams | project sharing (`instance_team`), task counts → CP |
| controls/Member | profile, settings | engine prefs + project provisioning → split: engine prefs runtime (runner), provisioning CP |
| controls/Dashboard | app dashboard | project quota tiles → CP override |
| controls/Admin | members, settings, cache | instances page + audit trail → CP |
| controls/Connections, Integrations | the app's own page (`instanceConnections` branch) | the builder's hub for other projects → CP `Hub` controller |
| controls/Hooks | edits the app's own hooks | `ProjectTarget` (edit another project) → gone: the project edits itself |
| controls/Pipelines | pipelines + named agents | nothing (agent table is runtime) |
| controls/Mcp | the app's MCP server | gateway lookup by project slug (`X-Tiknix-Project`) → CP gateway |
| controls/BaseControls/Control | base controller | dual-mode nav/branding helpers → CP layout adds its own nav |
| lib/functions | helpers | `is_core_install`, `builder_tools_enabled`, `is_control_plane` → deleted |
| lib/FlightMap | routing, maps | system-admin lookup against core's DB (`CoreDb`) → a config value |
| lib/ConnectionStore | the app's own store | `forInstall/withInstall/putForInstall` → deleted |
| lib/ConceptCatalog | client: search, bundle, install, connectors (remote) | server: local dir, publish, queueInstall → CP `CatalogServer` |
| lib/Redact | redaction | uses ConceptLint's patterns → patterns move into Redact; ConceptLint (CP) uses them |
| lib/GitService | (check what stays) | task worktrees, `addTaskWorktree` → CP |
| lib/InstanceUpdate | the app's own update | `InstanceRepo::syncLive` → plain `git pull` in the container; `tagRelease` → CP |
| lib/Pipeline/Dispatcher | dispatch | isolated-pool branch → deleted |
| models/Model_Member | the member | projects/instances methods → CP `MemberProjects` |
| mcptools/BaseTool | tool base | workbench-task helpers → CP subclass |
| scripts/clitool | the app's CLI | `--release`, catalog publish, pool dispatch → CP CLI |

## 5. What the package needs that does not exist yet

1. **Override rule for controllers.** The app's `composer.json` maps `app\\` to
   `["controls/", "vendor/tiknix/runtime/controls/"]` in that order: an app file
   `controls/Contact.php` wins over the package's. Only the app's composer.json declares
   the mapping, so the order is unambiguous.
2. **View resolution with fallback.** `render()` looks in the app's `views/` first, then the
   package's. Same override rule, per file.
3. **Extension points instead of edits.** The layout, dashboard, admin and home page get
   slots (the concepts runtime already has slots) so an app adds to them without copying
   them. The 14 files Serenity edited are the test list: each must be expressible as an
   override, a slot, or config.
4. **Agent guidance** (runtime `CLAUDE.md`): "never edit `vendor/tiknix/runtime`; override a
   file by creating it in your app, or fill a slot". The validation hook enforces it.
5. **Schema:** a fresh app runs runtime seeds only — today every instance carries the
   control plane's tables (`instance`, `workbenchtask`, `plan`, …) because it was cloned
   from core's database.

## 5a. Step 1 status (2026-09-29, branch `runtime-split`, served at tiknix2.tiknix.com)

Built on its own branch in a worktree at `/var/www/html/default/tiknix2.tiknix`, which serves
**tiknix2.tiknix.com** with its own copies of core's config, databases and keys (owner's
suggestion: keep tiknix.com untouched while the split is proven). Done:

- 216 runtime files and 17 view directories moved into `runtime/` with `git mv` (history
  follows): lib (incl. Pipeline, Scaffold), controls, models, services (connectors, Config,
  the schema builder, 18 runtime seeds), mcptools, views.
- **Override rule, implemented:** composer's psr-4 and classmap list the app's directories
  before `runtime/`; `app\LayeredView` resolves views app-first; the seed builder runs both
  seed directories as one numbered sequence (an app seed of the same name wins); the MCP
  tool loader discovers both (the app's tool of the same name wins); the router accepts
  controllers from both `controls/` directories.
- **Owner's rule on overrides:** `app\Overrides` + `overrides.lock` — an app file at a runtime
  path replaces it and is never upgraded again; `--update` still moves the runtime and names
  each override that fell behind as STALE (not a failure); `clitool --overrides`,
  `--override=PATH` (copy + record), `--override-record=PATH` (re-baseline after reconciling).
- `app\Paths::root()` (Composer's root package) replaced 41 location-relative root lookups;
  `Paths::runtime()` is the runtime's own tree.
- Guidance: "never edit runtime/; extend, else override the smallest file" (100-file-structure).
- `tests/run.sh` clears git's environment: under the commit hook in a worktree, GIT_DIR is
  absolute and tests that build temp repositories wrote into this one (on main it is the
  relative `.git` and works by luck — the same class as the 2026-09-28 incident).

Proved: suite 426 green (3 skips are `is_core_install()` deciding by the directory name
"tiknix2.tiknix" — that helper is deleted in step 2); tiknix2 serves `/`, `/auth/login`,
`/help`, `/docs`, `/privacy`, `/terms`, `/site/status` from runtime controllers and views; 19
signed-in pages render as the owner. Found, pre-existing on tiknix.com too: `/permissions`
has no views at all, `/mcp/registry` 500s (`app\Mcpregistry` missing).

Step-2 debt (moved files that still reference classes left behind): Control (base
controller), BaseTool, ConnectionStore, ConceptCatalog, Dispatcher, InstanceUpdate, Redact,
CoreDb, Model_Instance, Model_Member — each is a §4 tangle. Not yet on main, not released, no
instance has it.

## 5b. Step 2 status (2026-09-29, same branch)

The tangles are resolved: **no file under `runtime/` names a control-plane class** (checked
by a scan of every class reference in `runtime/`). The pattern throughout is one of three:

- **An extension point in the runtime, filled by the control plane.** The runtime class
  exposes a public static slot (or an interface); `lib/controlplane.php`, loaded only by
  core's composer `files`, fills it. An app fills none of them and gets the plain behaviour.
  Slots: `AgentState::$otherProjectDirs`, `ProjectTarget::$resolver`, `Member::$onClose`,
  `Dashboard::$billingCard`, `Contact::$memberDesk`, `Mcp::$projectScope`,
  `Mcp::$brokerConnection`, `Leads::$inviteStates`, `Auth::$afterLogin`,
  `Auth::$registerVia`, `GoogleAuth::$onNewMember`, `Admin::$memberExtension`
  (`MemberAdminExtension`, implemented by `PlatformMemberAdmin`), and `app\Chrome` — the page
  shell's four slots (prepare, nav, bar, account) that carry Projects, the project bar,
  Workspace/Build, Teams, Billing, Help and Docs.
- **A split**: the platform half of a class became its own control-plane class —
  `InstanceConnections` (other projects' connection stores), `MemberProjects`,
  `WorkbenchTool` (task-tool base), `InstanceUpdate::pullBuilds` (shared by
  `InstanceRepo::syncLive`), and the controllers `Helpdesk` (from Contact), `Signup` (from
  Auth; `/auth/invite` is now `/signup/invite`), `Fleet` (from `Admin::instances`), and
  `Invites::lead` (from Leads). Seed 24 gives their routes permission rows.
- **A role-shaped controller carved in two**: the runtime has an app version of
  `Connections` (29 of 68 methods), `Index` (install check + coming-soon + lead form) and
  `Integrations`; core keeps its full copies as **recorded overrides** — the only three, and
  `OverridesTest` pins that.

Moved back to the control plane because they describe the platform: `Help`, `Docs`,
`Hooks` (with Agent Setup) and their views. Teams stays control plane; its models stay in
the runtime. The layouts, partials and components moved into the runtime (two runtime views
included them by relative path and would have failed in an app); every such include now goes
through the view resolver, so an app copy wins.

One explicit setting replaces three heuristics: `[app] platform_role = "control-plane"` in
core's `conf/config.ini`; absent means an app. `is_core_install()`, `builder_tools_enabled()`
and `control_plane_state()` all derive from it.

Proved: suite 427 green; tiknix2 serves the public pages and redirects the gated ones to
login; 25 signed-in pages render as the owner; rendered as an app (role off, slots empty) the
dashboard, Communications, Admin, Contact, MCP and API-keys pages carry no link to a
control-plane route. Fixed on the way: a guest at `/contact` got the member support page
(the public-user bean has an id — also true on tiknix.com main); Docs used a constant only the
web entry point defines. Still pre-existing: `/permissions` has no views.

## 5c. Step 3 status (2026-09-30)

Three repositories now, all on this host:

| repo | what it is |
|---|---|
| `/var/www/html/default/tiknix-runtime` | **tiknix/runtime**, the package. History from the move onward (`git subtree split`). Releases are tags; `v2.0.0-alpha.7` is current |
| `/var/www/html/default/tiknix-app` | **the template** an app starts from: composer.json requiring `tiknix/runtime ^2.0@alpha` (VCS repository = the runtime repo), `public/index.php`, `public/rt` → the runtime's assets, `scripts/*.php` one-line doors to the runtime's commands, the code-standards hook, a trimmed config example, empty app directories, generated CLAUDE.md. Tracks composer.lock (the lock IS the record of the runtime it runs) |
| this repository (branch `runtime-split`) | the control plane, requiring the runtime through a **path repository** (`../tiknix-runtime`, symlinked) because the two are developed together |

What the package needed, and got:

- **Everything an app runs is in the package**: `Bootstrap` (autoloaded), the front controller
  (`app\Front::serve()`; an app's `public/index.php` is the fatal handler + autoloader + that
  call), route files (`Paths::route()`: app first), the CLI and runtime scripts (`bin/`, run
  from the app root — `bin/_boot.php` refuses anything else), assets (`public/`, served at
  `/rt/`), agent guidance sections (runtime's, app's of the same name replace them), the
  code-standards hook (`bin/hooks/`, which now also BLOCKS any edit under
  `vendor/tiknix/runtime/` with the override command in its message).
- **`--update` is a package update**: optional pull from an `origin`, the newest (or named)
  release Composer can see, refuse uncommitted edits, LOCAL checkpoint, `composer update
  tiknix/runtime --with=…` (the app's own constraint still governs — a new major is a
  deliberate edit), restore composer.json/lock/vendor on failure, commit the lock, seeds,
  guidance, cache, smoke test, overrides (STALE named), pin `.release`. A path-repository
  checkout refuses ("developed, not updated"); `--release` tags that checkout's main after
  the suite passes; `--releases` lists what Composer can install.
- **App-shaped seeds**: the 20 control-plane permission rows left the runtime's seed for core's
  `25_ControlPlaneRoutes` (and the runtime removes the copies its older versions wrote on apps
  without those controllers); an app's Connections/Integrations are admin pages in the table as
  in the controller (core moves its rows back to member level for its hub, only when they still
  say what the runtime's seed wrote).

Proved, by Playwright as ROOT, ADMIN and MEMBER (tiknix-e2e `08-roles`, `01`, `02`, `09`):

- core (tiknix2.tiknix.com) on the package: 51/51.
- a fresh app built from the template (`rtdemo.tiknix.com`): the setup wizard, the owner's
  first sign-in, a shell with no control-plane link, assets from `/rt/`, the wizard locked
  after (`09`); the role sweep 6/6 — control-plane pages absent (404), everything the table
  grants works, everything it refuses is refused.
- the app **updated itself three times** (alpha.4 → 5 → 6 → 7) with `--update`; on the last one
  it had overridden `views/dashboard/index.php`, the release changed that view, and the update
  left the app's copy alone and named it STALE — the owner's rule, end to end.

Fixed on the way, each found by the role sweep or the fresh app: moved files that found the
app by their own location (a second, empty connection store inside `runtime/`); unknown routes
answering login instead of 404 (and build mode writing a row per scanner path); a 403 sent
with status 200; `/permissions`, `/mcp/registry`, the maintenance page and `Test` — dead code
with missing views; control-plane links and rules on app pages; form `pattern`s invalid in the
browser's v-mode; seed schema changes printed as ERRORs; a missing connection store and an
unreadable one both answering "nothing connected" (`ConnectionStore::readOwn`).

Still open: the runtime package has no test suite of its own yet (core's suite exercises it
through the path repository); the admin's `maintenance_mode` setting is stored but enforced
nowhere; the runtime repo is local to this host — tenants will fetch releases from core's git
endpoint (step 4). Not merged to main; nothing released to the existing instances.

## 5d. Step 4 status (2026-09-30)

**The tenant "image" is a script, not an image.** Publishing an OCI image needed a registry
off the `.tiknix` domains, and there is no docker on this host. A system container needs
neither: `lib/TenantHost.php` creates one from Proxmox's stock `ubuntu-24.04-standard`
template (already on the node) with core's tenant SSH key for root, and
`tenant/provision.sh` — versioned here, idempotent — installs PHP 8.5 from the same PPA core
uses, nginx, a PHP-FPM pool running as the `app` user, Composer, the app's own Claude Code
(linked as `bin/claude`), read-only credentials for core's git endpoint, then clones and
builds the app. SSH is the exec channel the Proxmox API never had, so the old design's
self-bootstrapping entrypoint is unnecessary.

**Apps come from the template; their runtime comes from core.** `lib/TenantApp.php` registers
the app (instance row, deploy token) and makes its origin from `tiknix-app`, with the runtime
repository pointed at this control plane's `/git/runtime.git` (new: served to any active
instance by its slug + deploy token, like `core.git`) and the lock re-resolved there. The git
endpoint now answers a credential-less first request with the Basic challenge — before, git
never retried with its password and every fetch of `core.git`/`runtime.git` failed. After the
first clone the tenant's repository is the app's home (`origin` renamed `seed`).

**The builder works in the container.** `clitool --agent-task=<id>` (runtime `AgentTask`,
prompt on stdin) makes a worktree on `task/<id>`, runs the app's own agent there with the
app's own credential chain (the pipeline agent step's: login → key file → `anthropic`
connection — never anyone else's) and commits; `--agent-merge` / `--agent-discard` finish it.
Core drives it over SSH: `TenantHost::task/mergeTask/discardTask`, `scripts/tenant.php
--task/--merge/--discard`, plus `--ssh` and `--clitool`.

**cat-poo-box, rebuilt** as `catpoobox` (container 104, `https://catpoobox.tiknix.com`), its
code carried into the container over SSH and committed there. Everything it had done by
editing core became an extension point in the runtime instead — none is an override:

| cat-poo-box edited core's… | now |
|---|---|
| `Contact::book` + contact admin/view views | its own `Book` controller (`/book`); requests are a registered support-queue category (`Contact::$categories`: tab, statuses, summary/details partials) |
| header nav, guest button, footer link | `Chrome` slots `prepare`, `actions`, `footer` (the last two new) |
| a `roleLabel()` in functions.php | `Chrome::$levelNames` (an unnamed level logs, never "Member") |
| `PermissionCache::seedRule` | fixed upstream: an auto row at the seeded level is claimed |
| `views/index/index.php` | an app's home page IS its `views/index/index.php` (the runtime ships none) |

Wired in the app's `lib/app.php` (new in the template: Composer `files`), with a test harness
(`phpunit.xml`, `tests/bootstrap.php`) — its 8 tests pass inside the container.

**Proved** (Playwright, root/admin/member):

| target | result |
|---|---|
| catpoobox in its container: 09 fresh app (wizard, REQUIRED 2FA enrolled with a computed TOTP, app-only shell, `/rt/` assets, wizard locked) | pass |
| catpoobox: 08 roles (its own nav — My orders, All orders — per level; control-plane pages absent) | 6/6 |
| core on alpha.11: 01 + 02 + 08 | 51/51 |
| rtdemo, updated alpha.7 → alpha.11 in one `--update`: 08 | 6/6 |

The container updated ITSELF four times (`--update`, runtime fetched from core's endpoint), the
last with the smoke test passing through its public host.

**Found and fixed in capricorn** (committed there; they take effect when openresty reloads):
static files (css/js/images/fonts, favicon, robots) of every PROXIED host were answered from
core's disk and 404'd; and the proxy cache stored responses for a minute ignoring the app's
`Cache-Control`, never recognised a tiknix session cookie, and stored bypassed and
cookie-setting responses — a proxied app's page (CSRF token included) was served to the next
visitor. Until the reload, the container was proved through an SSH tunnel to it.

**Open**: the builder's agent needs the app's own credential (a Claude login or an Anthropic
key/connection in the app) — deliberately not copied from anyone; the mechanics are proved by
`AgentTaskTest` with a stand-in agent. The workbench/plan executor still dispatch to host
clones — wiring them to `TenantHost::task` is step 5 (§5e).
Old cat-poo-box (`cleans-cat-poo-boxes-937cab`) is untouched.

## 5e. Step 5 status (2026-09-30)

**Ten projects now run in their own containers, on their own domains.** `scripts/tenant.php
--inventory / --carry / --carry-data / --cutover / --rollback` (`lib/TenantCarry.php`) move a
host clone into a tenant under its own slug and instance row:

| step | what it does |
|---|---|
| inventory | the clone's WORKING TREE (uncommitted work included), by content hash against every blob core ever had: its own files, and the core files it edited |
| seed | the template made into the app (`TenantApp::prepareRepo`), its own files and `concepts.lock`, edited core files kept under `.carry/edited/` with `CARRY.md` as the porting list, host paths retargeted (`bootstrap.php` → the autoloader, the clone's dir → `/srv/app`; anything else refused) — pushed as origin branch `app`; `main` stays the clone's |
| provision | `tenant/app.sh` from branch `app`, the app's `system-packages`, heartbeat OFF (a staging copy must not run the live app's schedule) |
| data | consistent SQLite backups, the connections store and key, `secure/`, uploads, site configs, its own `conf/*.ini` (never one core has or ships an example of: those are core's credentials), `config.ini` merged onto the tenant's minus host/control-plane settings, its own Claude login (read through the pool on an isolated clone), seeds + plugin seeds, orphaned permission rows pruned, the regenerated CLAUDE.md committed |
| cutover | databases once more, the real base URL, the domain(s) proxied, heartbeat ON, the staging host retired, `carried-to-tenant.json` beside the origin — core's builder (main ff45e72) and heartbeat (main) skip the clone from then on |
| rollback | the proxy files removed, heartbeat off, the marker removed |

| project | container | core edits → |
|---|---|---|
| pd | 100 | home page via `Index::$home`; Earlywater's own view |
| invoza | 102 | menu (`prepare`) + dashboard panel (`dashboard` slot) |
| blower4free | 103 | rebate routes seed; Shopify orders via `ConnectionBindings::call('root','store','create_order')` |
| mileage | 106 | trip-calculator home; team trips (`team` slot); trip rooms via `Model_Thread::$related`; Google-connect experiment dropped (never used) |
| bookingscheduler | 107 | salon home + theming (`Home`); lead CRM as recorded overrides; Stripe reconciliation as a `/webhook/stripe` handler |
| lead-machine | 108 | outreach menu + dashboard; 15 pipelines retargeted; its GC pipeline |
| start, discotuba, surgeew, quickticket | 110–113 | none (the shared uncommitted platform sweep dropped); discotuba declares `ffmpeg` |

Paused on the host by the owner's call: **collectiq, partsdna, Serenity** (they rewrote core's
dashboard, admin, auth and layout); collectiq's staging container 109 is kept, not served.
leadmachine-harvest was deleted (archive + origin kept). This branch cannot land on main
until those three move: the host builder and host isolation still serve them there.

**What the runtime gained** (alpha.13–26): Turnstile optional with an admin notice;
`Index::$home`; Chrome slots `dashboard`, `team`, `Chrome::$links/$omit/$copyright/$mark`;
Teams for an app's users (leaving a team leaves its rooms — fixed on main too);
`Model_Thread::$related`; `ConnectionBindings::call`; Shopify `create_order`; Stripe setup /
off-session charge / refund / verified events and `/webhook/stripe` with app handlers;
Microsoft `find_conversation_message` and the reply fix; upsert may write a declared `id`;
`Mailer::brand()`; the menu lights its most specific item; `--update` commits CLAUDE.md;
every template app ships `pipelines/garbagecollector.json`; tenants tick themselves.

**Deleted by the container move** (§3): from the runtime, `IsolatedPool`, `CoreDb`, the
pool-user seeds, the pipeline jail, the connections store's ACL special case, and
`is_core_install` / `builder_tools_enabled` / `is_control_plane` / `platform_role` — the
platform now says what it adds (`lib/controlplane.php`). From core, `scripts/instance-acl.php`,
`trim-instance.php`, `upgrade-instances.php` and seed 09. `IsolatedPool` and `CoreDb` live in
core until the last host clone moves (the carry reads through the pool; the host builder).

**Open**: the builder in containers — the workbench and plan executor still drive host
clones (`TenantHost::task` exists and is proved; the async tmux wrapper, the planner and the
audit verbs are the work), and with it the host builder's jail and pool code goes; capricorn's
`isolate-instance.sh` / `jail-run.sh` go when main no longer needs them.

## 6. Order of work

1. Move the files in §3 into a `runtime/` directory inside this repo first, with the
   autoload and view fallback of §5 — the suite proves nothing broke before any repo split.
2. Resolve §4's tangles one by one (each is small).
3. Cut `runtime/` into the `tiknix/runtime` package repository; create `tiknix-app`.
4. Build the tenant image from a runtime release; rebuild cat-poo-box on `tiknix-app` in a
   container with the builder over SSH.
5. Rebuild the other projects; delete §3's "deleted" list.

Rough size: steps 1–3 about three days; 4 two days; 5 a day per project that has its own
code (Serenity, lead-machine, partsdna, collectiq, invoza), minutes for empty ones.

## 7. Migration to tiknix.com (written 2026-09-30)

What it takes for tiknix.com to run this branch, from where things stand tonight.

### Where things stand

| | tiknix.com (`main`, live) | tiknix2.tiknix.com (`runtime-split`) |
|---|---|---|
| code | host clones, host builder, Publisher | runtime package + app template, containers; 48 commits ahead of `main` (`main` has 3 not here) |
| database | the real members, billing, teams | a copy of tiknix.com's from 2026-09-29, plus container state since: `ct_*` columns, `ct_hosts`, `deploytarget`, the `catpoobox` row |
| projects | registry for everyone; the carried ones marked `carried-to-tenant.json` and skipped | drives the 11 containers (`tenant.php`, workbench2, Deploy) |

Since §5e, this branch gained: the builder in containers (planner, tasks, audit routes;
workbench2 at workbench2.tiknix.com with an agent picker over the app's own agents), the
app's AI agents page (`/agents`: Sign in with Claude, provider presets), domains per app
(`app\Host`, `conf/hosts/<host>.ini`; `tenant.php --domain-add`, certificates renewed daily
from this machine's crontab), and the Deploy page (`/deploy`: Domains and Export — rsync
and SSH command, keys sealed on core, the container's HEAD shipped).

Still on the host: **collectiq, partsdna, Serenity** (paused — they rewrote core's dashboard,
admin, auth and layout), **testfv**, and the original **cleans-cat-poo-boxes** clone (rebuilt
as `catpoobox`; retire it).

Every container fetches the runtime from `https://tiknix2.tiknix.com/git/runtime.git`, so
tiknix2's address is in every app's `composer.json`.

### Phase A — finish what containers need

1. **The last host projects.** collectiq, partsdna and Serenity each port their core edits to
   runtime extension points (about a day each, §6). Retire testfv and the old cat-poo-box
   clone, or carry them if they matter. `main` cannot drop host isolation until this is done.
2. **The builder in containers, complete.** Plans work in workbench2; the Terminal tab and
   single (non-plan) tasks still assume a host folder, and the post-plan audit runs on core's
   credentials, not the app's agent. Then workbench2's branch becomes the workbench.
3. **The other sidecars, each decided.**
   - Publisher: retired — Deploy replaces it. (GitHub pull-request export for container
     projects is not built; decide whether it is wanted.)
   - Explorer, Insights: they read host folders — adapt to containers, or retire.

### Phase B — make this branch releasable

4. **Real dependencies.** The runtime and the sidecar kit are path repos here (local
   checkouts); `main` takes tagged releases. The apps' runtime URL moves to tiknix.com — an
   update can rewrite each app's `composer.json`.
5. **Crons into the main tree:** the heartbeat, the daily certificate renewal (it points at
   `tiknix2.tiknix` today), screenshot capture.
6. **A dress rehearsal, repeated until it is clean.**
   - Copy tiknix.com's current database into tiknix2 and bring the container state across.
   - Run the seeds, then the whole Playwright suite, including 03/04 (project lifecycle),
     which are not run against tiknix2 today.
   - Confirm `[security] app_key` is tiknix.com's, or 2FA secrets and encrypted settings
     break.

### Phase C — cutover, one short maintenance window

7. tiknix.com into maintenance; a final database backup.
8. Merge `runtime-split` into `main`; deploy it in the tiknix folder (composer install, seeds,
   cache reset); apply the container state from tiknix2's database.
9. Point the workbench sidecar at `main` (workbench2's branch merged); switch the apps'
   runtime URL; run the suite.
10. **Rollback:** a tag on the old tree plus the database backup — one command back, kept
    until the new one has proven itself.

### Phase D — cleanup

11. Delete the host machinery:
    - `IsolatedPool`, `CoreDb`, the host builder's jail and pool code, Tiknix-Hosted publish,
      ProxmoxDeploy's OCI path.
    - capricorn's `isolate-instance.sh` / `jail-run.sh`.
    - The old host clone folders, after archiving them.
12. Tell the other project owners (members 9, 12, 24) their projects moved; where the app's
    agent login has expired (pd, blower4free, bookingscheduler), they sign in again on the
    app's AI agents page.

### Decisions open

| decision | options | recommendation |
|---|---|---|
| collectiq, partsdna, Serenity | port now, or keep them frozen on the host and delay removing the host machinery | port — it is most of the remaining work, and nothing else can be deleted until it is done |
| cutover style | merge into `main` and deploy in place, or swap folders so tiknix.com points at this tree | merge in place — git stays the record; swapping is quicker to flip, messier to keep |
| GitHub pull-request export for containers | build it, or rsync/SSH are enough | the owner's call |

## 8. Cutover runbook — tiknix.com onto this branch (written 2026-10-01)

§7 Phase A is done: **every project is in its own container** (14, incl. collectiq, partsdna,
Serenity), the host clones are archived to `arc/<slug>.tiknix.zip` and their php-fpm pools
deprovisioned, `testfv` and the old cat-poo-box clone are out of the registry. What is left is
§7 Phases B–D. This section is the exact recipe, from measuring both sides.

### What actually differs (measured 2026-10-01)

| | tiknix.com (`main`) | tiknix2 (`runtime-split`) | cutover takes |
|---|---|---|---|
| commits | 4 not in `runtime-split` (incl. the GET-delete hotfix, `7608ae1`) | 59 not in `main` | merge `main` → `runtime-split`, then `runtime-split` → `main` (a PR: `main` is protected) |
| schema | — | `deploytarget` table; `agent.preset/auth/fast_model`; `instance.ct_hosts/ct_kind/lent_connections` | tiknix2's seeds, run on the live database |
| data | **the truth**: members, keys, teams, billing, run history, logs | a 2026-09-28 copy + container state | only the container state (below); everything else stays tiknix.com's |
| container state | none | 13 instances' `deploy_token, ct_vmid, ct_ip, ct_domain, ct_aliases, ct_hosts, ct_kind, lent_connections`; instance #101 `catpoobox` exists only here | `scripts/cutover-migrate.php --from=<tiknix2 db>` |
| config | — | only `[app] baseurl` and `[sidecar.workbench] url/sso_secret` differ | keep tiknix.com's; switch `[sidecar.workbench]` to workbench2 |
| own connections | `data/connections.db`: turnstile #4, mailgun #5 | identical, same `secure/connections.key` | nothing |
| e2e noise | — | settings rows with no member (feature grants of deleted e2e operators), thread members | not carried |

### Dependencies that pin names and paths

- **The broker is tiknix.com.** Every app's `conf/broker.ini` (and tiknix2's own) points at
  `https://tiknix.com/mcp/message`; its keys and the stores' tokens are tiknix.com's data. So
  tiknix.com's database must be the one that survives.
- **The containers fetch from `tiknix2.tiknix.com/git/`** — the runtime
  (`composer.json` → `https://tiknix2.tiknix.com/git/runtime.git`) and their own seed remote.
  After the cutover that name must keep answering: keep `tiknix2.tiknix` as a symlink to the
  `tiknix` tree (the sweeps already refuse symlinked dirs), or rewrite each app's URL with a
  runtime update. The symlink first; the rewrite at leisure.
- **`/var/www/html/default/tiknix` is the git repository**; tiknix2 is a worktree of it. It is
  not moved or archived — `main` is updated in place.
- Paths on `tiknix/`: 4 cron lines, the old workbench and explorer sidecars (`core_root`),
  `TenantCarry::CORE`, capricorn. workbench2's `core_root` is `tiknix2.tiknix` today → `tiknix`.
- `[security] app_key` is the same in both (2FA secrets and encrypted settings keep working).

### The steps

1. **Rehearse** (below) until clean.
2. Merge the open hotfix PR (`hotfix/get-deletes`) so `main` is current; merge `main` into
   `runtime-split`; open the PR `runtime-split` → `main`.
3. Maintenance window: sqlite `.backup` of `database/*.db` and `data/connections.db`; tag the
   old `main` (`pre-runtime-split`).
4. Merge the PR; in `tiknix/`: `composer install`, `php scripts/clitool.php --build`,
   `php scripts/cutover-migrate.php --from=../tiknix2.tiknix/database/tiknix.db --apply`,
   `php scripts/resetcache.php`.
5. Config: `[sidecar.workbench]` → workbench2's url and secret; workbench2's `core_root` →
   `/var/www/html/default/tiknix`, `core_url` → `https://tiknix.com`.
6. Cron: move `tenant.php --renew-certs` to `tiknix/`. The other four lines stay:
   `reap-stale-tasks`, `prompt-queue-drain`, `pipeline-cron` and `capture-showcase` are
   control-plane jobs, and the builder in containers still uses them.
7. Replace the `tiknix2.tiknix` worktree with a symlink to `tiknix` (keeps `/git/` answering
   for the containers); remove the worktree with `git worktree remove`.
8. Verify: e2e 01, 02, 08 on tiknix.com; every app's `--update` reaches the runtime; one
   `tenant.php --share` and one `--status`.
9. **Rollback:** `git reset --hard pre-runtime-split` in `tiknix/`, restore the backups, restore
   the crontab and `[sidecar.workbench]`, undo the symlink.

### Rehearsal

A third worktree, `tiknix-next.tiknix` (served as tiknix-next.tiknix.com), on branch
`cutover-rehearsal` = `runtime-split` + `main`, with a fresh snapshot of tiknix.com's database,
secure files and config (`baseurl` → tiknix-next), then steps 4 and 8 against it. It runs no
cron and is not the broker, so it touches nothing live. Results below, per run.

**Run 1 — 2026-10-01, clean.** `cutover-rehearsal` at tiknix-next.tiknix.com, on a sqlite
`.backup` of tiknix.com's live databases taken at 22:40.

- *Merge:* `main` into `runtime-split` conflicts in 10 files. Each one resolves to
  runtime-split's side, because main's 4 commits are already here in another form: Team rooms
  (0b43732), the carried-project refusal (601b594), and the GET deletes (in the runtime since
  alpha.59). main's pipeline-cron change (f93dea0) is replaced by the runtime's command. That
  merge commit is reusable as-is for step 2.
- *Found:* `instance.ct_aliases` on tiknix.com is INTEGER, born from a null. The first domain
  the migration wrote into it would have rebuilt the table. Seed 31 now makes it TEXT, and
  refuses if the old column holds values. While looking, ProxmoxDeploy turned out to write the
  column as JSON while TenantCarry reads a comma list. ProxmoxDeploy now uses the comma list.
- *Build:* every seed ok. Afterwards the schema equals tiknix2's, table for table and column
  for column.
- *Migrate:* 70 column changes over 14 projects. Every one filled an empty field; nothing
  tiknix.com held was overwritten. catpoobox was inserted, keeping id 101. A second run
  reports 0 changes. 15 instances, and integrity_check is ok.
- *Verify:* e2e 08 + 01 + 02 passed 52/52, as root, admin and member. 0 ERROR lines in the
  log, no mail sent, no e2e accounts left over. `tenant.php --status` from the rehearsal tree
  reaches pd, serenity, partsdna, catpoobox and start.
- *Not covered by the rehearsal (it cannot be, while tiknix2 exists):* the `tiknix2.tiknix` →
  `tiknix` symlink that keeps `/git/` answering for the containers; workbench2's SSO against
  tiknix.com; the crontab swap. The real window covers these (steps 5–8). Do them first, and
  prove them with one `tenant.php --update` and a workbench sign-in.

**Done — 2026-10-01, 02:51 UTC.** tiknix.com runs this branch: `main` = 9b4fb3d (fast-forwarded
from 7608ae1, tagged `pre-runtime-split`). Steps 3–8 went as rehearsed:

- vendor was swapped in pre-built from tiknix2 (the old vendor is `vendor.pre-cutover`).
- `--build` was clean, and the migration made 70 changes plus catpoobox (#101).
- workbench2 uses `core_root` = `tiknix`; tiknix.com's `[sidecar.workbench]` points at
  workbench2.
- The renew-certs cron runs from `tiknix/`.
- `tiknix2.tiknix` is a symlink to `tiknix`, and the worktree is gone. Its databases, conf, log
  and secrets are in `arc/tiknix2.tiknix.zip`; its `secure/archives` were checksum-identical to
  tiknix's.
- Before-images are in `arc/cutover-20261001/`: databases, config.ini, workbench2's config.ini
  and the crontab.

Verified on the live site:

- e2e 08 + 01 + 02 passed 52/52.
- No ERROR lines since the switch.
- `tenant.php --status` reaches all 14 containers (all on alpha.59).
- From inside catpoobox, the seed remote and `runtime.git` fetch through
  `tiknix2.tiknix.com/git/`, authenticated against tiknix.com's database.
- workbench2 sign-in goes to tiknix.com.

Found afterwards: the runtime heartbeat reported each container project as "no directory"
every minute. It now skips anything with `ct_kind` (tiknix-runtime b8c6150), since those
projects tick from their own crontab.

Rollback, if ever needed:

- `git reset --hard pre-runtime-split`, then `mv vendor.pre-cutover vendor`.
- Restore `arc/cutover-20261001/tiknix/*`, the crontab, and workbench2's config.
- Remove the symlink and unzip `arc/tiknix2.tiknix.zip` into a fresh `runtime-split`
  worktree.

Left to do: GitHub's `main` still needs the PR (`runtime-split` → `main`; `main` is
PR-protected). Once nothing references them, drop `vendor.pre-cutover` and
`composer.lock.pre-cutover`.
