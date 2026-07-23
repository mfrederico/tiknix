# Core Primitive Plan — pipelines & durable objects as a first-class tiknix capability

**Goal (owner):** make the pipeline / durable-object / connector runtime a primitive
that *core tiknix* takes advantage of — surfaced at `https://tiknix.com/connections`,
and usable by the build agents inside the Advanced Builder (was "AI Builder") and
AI Projects (was "Workbench") when they construct software.

## Where we actually are (recon)

The runtime is **already core-native**, not just instance plumbing:
- `lib/Pipeline/*` (Runner, Executor, Dispatcher, Loader, **ObjectRunner**, **DurableObject**, Cron, ApiKey, Vars, StepRegistry, Steps/*) ships in core; `Runner::root()` = the app root the code runs in, so **in core it reads the core `pipelines/` dir + the core DB**.
- Core owns real pipeline files: `pipelines/counter.json` (a durable object) and `pipelines/demo-hello.json`.
- Core invokes it: `controls/Pipeline.php` (`api`/`trigger`/`status`/`debug`/**`object`**/**`objecttick`**/`keys`), the MCP tools `mcptools/Pipeline*Tool.php`, `controls/Mcp.php` (`Runner::list/get/run`), and `scripts/pipeline-cron.php` (which also fires one `objecttick` per instance each minute).

What's **missing** — the runtime has **no visible surface in core**:
- The only core view is `views/pipeline/keys.php` (ADMIN key mgmt). The authoring/visual editor is a **sidecar** (`pipelines.tiknix`, gated by the `pipelines` Feature flag) that edits pipelines *in instances*.
- `/connections` (`controls/Connections.php`) is today a **per-instance connector hub**: it resolves the member's selected instance and renders a `$cards` array (GitHub + one card per `ConnectorRegistry::all()`) grouped by `categoryOrder = ['Deploy','Payments','Stores','Social','Other']`. ADMIN-gated. **The cards+categories array is the natural seam to add "Pipelines" and "Durable Objects" groups.**
- The build agents (PlanRunner → PlanExecutor, the `reuse_digest`) don't yet treat pipelines/objects as *building blocks* they can emit into a generated app.

## The plan — three surfaces, phased

### Phase 1 — Surface at `/connections` (recommended first; self-contained)
Turn `/connections` from a connector hub into the instance's **Automations & Integrations** hub. Add two sections alongside the connector cards, scoped to the selected instance:
- **Pipelines** — list the instance's `pipelines/*.json` (name, steps, triggers, expose flags, last run) with quick **Run** and a deep-link to the sidecar editor to author.
- **Durable objects** — list live `dobject` rows (type/key, state summary, `wake_at`, last updated) with **Send message** / inspect / destroy.
- Reads reuse the sidecar's proven pattern (`PipeFiles`-style): core reads the instance dir's `pipelines/` (files) + the instance SQLite **read-only** for `dobject`/`piperun`. Actions call the instance's existing `/pipeline/*` endpoints (bearer = its `trigger_secret`), exactly like the sidecar.
- Keeps the editor in the sidecar (no duplication) — `/connections` is the **overview + quick actions + jump-off**, matching how it already treats connectors (see, connect, link out).
- **Open scope decision:** `/connections` is per-instance + ADMIN. Core's OWN pipelines/objects (the `tiknix` repo's `pipelines/`) are a separate ROOT concern → a later "System automations" view, or a special "core" entry in the instance picker. Phase 1 = instance-scoped.

### Phase 2 — Core uses the runtime for its own automation
Model some of core's own recurring/stateful work as pipelines/durable objects that run in the core root (`Runner::root()` = core):
- e.g. a **housekeeping durable object** (GC of dead objects — the TTL sweep the DO roadmap already wants — plus stale-run cleanup), a scheduled **digest** pipeline, etc.
- Proves "core takes advantage" and dogfoods the primitive. Also implements the DO **GC/TTL** open item as an object itself.

### Phase 3 — Advanced Builder / AI Projects compose the primitives
Let the planner/agents add pipelines, durable objects, and connector wiring as **building blocks** of a generated app:
- The `reuse_digest` already inventories existing controllers/models/services for the planner; extend it (and the MCP `pipeline_*` tools already present) so a plan can emit "add a nightly-report pipeline", "add a stateful support-agent durable object", "wire a Stripe connection step".
- The generated instance ships those as `pipelines/*.json` (files-in-repo, already the model) + seeds — no new runtime, just making the planner *aware* these are first-class outputs.

## Decisions to confirm before Phase 1 UI
1. **Scope:** instance-scoped at `/connections` (the selected instance's automations) — yes? (Core-owned automations = a later separate view.)
2. **Hub framing:** keep the page titled "Connections", or broaden to "Integrations" / "Automations" as it now hosts connectors + pipelines + objects?
3. **Level:** `/connections` is ADMIN today; pipelines/objects there stay ADMIN, or drop to MEMBER (owners of the instance)?

## Non-goals / guardrails
- Don't rebuild the editor in core — link to the `pipelines.tiknix` sidecar for authoring.
- Reuse the broker/trigger_secret custody model for any actions (no secrets in the browser).
- Files-in-repo stays the pipeline source of truth; the DB stays run/object state.

See also: PIPELINES-PLAN.md (sidecar editor), AGENT_ORCHESTRATION.md (planner/executor), and the durable-objects work in `lib/Pipeline/DurableObject.php` + `ObjectRunner.php`.
