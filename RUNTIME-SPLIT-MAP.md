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
