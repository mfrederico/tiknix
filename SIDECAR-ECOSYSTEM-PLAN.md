# Tiknix Architecture — the Recursive "Instance + Sidecars" Model

Status: **north-star spec** (2026-07-24). This is the destination the sidecar work walks
toward. It supersedes the earlier "trim the monolith" framing: we don't subtract from a
monolith, we **compose up from a clean base**.

---

## 0. The whole thing in one paragraph

There is exactly **one artifact — a tiknix instance** — and every capability is a
**sidecar** composed onto it via a **feature flag**. A customer is `base + their sidecars`.
**Core is the same base instance + the control-plane sidecar + every feature sidecar.**
The control plane is not special code; it is the **root sidecar** — the one everything
else depends on — hosted co-located on the base instance that we call "core," reachable at
`control.tiknix.com` (and regional nodes as load demands). Because it's a sidecar, a
customer can **run their own** — which *is* self-hosting/eject, custody and all. No
keep-list, no trim, no protect-list as ongoing machinery: they collapse into "**what the
base is**" + "**which sidecars are flagged on**."

```
core.tiknix   =  BASE  +  [control-plane root sidecar]  +  [ALL feature sidecars]
customer      =  BASE  +  [their flagged feature sidecars]     (broker → a control plane)
self-hoster   =  BASE  +  [their sidecars]  +  [their OWN control-plane root sidecar]   ← eject
```

---

## 1. The three roles

| Role | What it is | Where it lives | Billing |
|---|---|---|---|
| **BASE** (clean-room instance) | the customer's app + the **thin runtime** (`lib/Pipeline/*`, `controls/Pipeline`, `controls/Mcp` + `mcptools/`, the broker *client*, `.mcp.json`, framework/auth) | **in** the instance repo | the product |
| **FEATURE SIDECAR** | authoring/orchestration UI — AI Builder, Workspace, Pipeline editor, Explorer, Store | own repo, own vhost, SSO'd in, **flag-gated** | **per-sidecar SKU** |
| **CONTROL-PLANE ROOT SIDECAR** | custody vault, broker (`/mcp/message`), SSO mint (`/sidecar/launch`), provisioning, instance registry | own repo/deploy; **co-hosted** on core; other instances **call it over HTTP** | platform / tiered |

The base is the same everywhere. Feature sidecars are à la carte. The control-plane is the
one sidecar that boots co-located (it's the root), and "running your own" = self-hosting.

---

## 2. The irreducible root of trust

Even as a sidecar, the control plane contains a root that **cannot bootstrap itself via the
sidecar system it provides** (you can't SSO into the thing that mints SSO). Name it precisely:

- **identity / auth** (the member authority)
- the **custody sealing key** (what encrypts connector tokens)
- the **SSO secret** (what every sidecar trusts)
- the **instance registry** (who owns what)

Everything else the control plane does today — provisioning UI, connections management, the
broker's decrypt-proxy *logic*, AI Builder orchestration — is a **feature on top of that
root** and can be its own sidecar/service. Pin the root; the rest composes.

**Consequence for eject:** when a customer runs their own control-plane sidecar, **they
become their own root** (their key, their vault, their SSO secret). The "catch" (there's
always a root) and the "feature" (self-host = own the root) are the same thing.

---

## 3. Composition & billing = feature flags

Sidecars are already flag-gated (`Registry` reads `[sidecar.<name>].feature`;
`Feature::isEnabled` gates launchability; the shell shows `/sidecar/app/<name>`). So:

- **Add a capability** = flip a flag → nav link + SSO access appear. The code was never in
  the instance.
- **Bill per capability** = the flag is the SKU. Upkeep cost (a sidecar we maintain) ↔ price,
  1:1. Tiers (bronze/enterprise control-plane) are just flag bundles.

This is why the trim/keep/protect machinery **evaporates**: there's nothing to remove from a
minimal base, and capabilities are additions, not subtractions.

---

## 4. Deployment topology — `control.tiknix.com` and regional shards

**The routing primitive already exists.** Each instance's `broker.ini` has
`endpoint = https://<host>/mcp/message`, and `BrokerService::endpoint()` reads
`app.control_plane_host`. "Which control plane does this instance use" is **already a
per-instance config value** — no new plumbing.

Two shapes, both served by that one knob:

1. **Stateless LB** — `control.tiknix.com` → LB → N interchangeable app nodes, shared/
   replicated custody DB. Scales the app tier. Start here.
2. **Regional shards** — `control-<dc>-<region>-<nn>.tiknix.com` (e.g.
   `control-dnvr-uswest-01`) is a control-plane deploy with its **own custody DB**; a
   customer-instance's `broker.ini` points at **its assigned shard**. The clean property:
   **an instance only ever calls its own control node** → **no cross-shard coordination on
   the hot path** (1:1, not a mesh). Great for residency (EU → `control-fra-eu-01`) + latency.

Run both: a stable `control.tiknix.com` (anycast/LB) + concrete regional nodes, `broker.ini`
pinned to a node for residency or to the LB name otherwise.

**The one decision sharding forces — where the root lives:**
- **Global identity + regional custody** (recommended): one identity/registry authority; custody+broker sharded regionally. Residency + latency without fragmenting identity.
- **Fully independent regional roots**: max isolation, region-of-one — which is exactly the **white-label / self-host** shape.

Custody is **state**: sharding is a data-partition decision; moving a customer between regions
is a custody migration (decrypt → re-encrypt under the new shard → repoint `broker.ini`) — an
occasional batch job, not a runtime concern (because the hot path is 1:1).

---

## 5. The clean-room base — the positive manifest

The old "keep-list" doesn't disappear; it **becomes the base repo's contents**, stated
positively. Classification (the audit that defines the base):

| Surface | Role | In base? |
|---|---|---|
| the customer's app (`controls/*`, `views/*`, `models/*`, `routes/*`) | BASE | ✅ |
| `lib/Pipeline/*` (Loader/Executor/Runner/Steps/DurableObject) | BASE runtime | ✅ |
| `controls/Pipeline.php` (the instance's own `/pipeline/*` API) | BASE runtime | ✅ |
| `controls/Mcp.php` + `mcptools/*` + `.mcp.json` | BASE runtime (**the jailed agent's scope — never remove**) | ✅ |
| broker **client** (`ConnectionStep` + `conf/broker.ini`) | BASE runtime (holds nothing) | ✅ |
| framework/auth/session/`lib/functions.php` + `conf/*.example.ini` | BASE | ✅ |
| `controls/Teams,Firehose,Leads,Security` | BASE (owner-confirmed: keep per-instance) | ✅ |
| `controls/Aibuilder` + `views/aibuilder` | FEATURE SIDECAR (`aibuilder.tiknix`) | ❌ |
| `controls/Workbench` + `views/workbench` | FEATURE SIDECAR (`workspace.tiknix`) | ❌ |
| `controls/Mcpconfig`, `controls/Mcptools` | admin UI (control-plane / sidecar) | ❌ |
| pipeline editor, explorer, store | FEATURE SIDECARS (already extracted) | ❌ |
| broker/custody/SSO-mint/provisioning/registry | CONTROL-PLANE ROOT SIDECAR | ❌ (core-only) |

**The PROTECTED invariant** (from the bwrap-scope question): the base composition must
*never* omit `.mcp.json`, `mcptools/`, `lib/Pipeline/`, `controls/Mcp.php`, `conf/broker.ini`,
`.aibuilder/` engine config — those *are* the jailed build agent's hands. Encoded as a
refusal in the tooling.

---

## 6. Custody & eject

- **On-platform**: token encrypted in the control plane's `connections` vault; instance
  reaches it via the broker, scoped at `brokerToolCall`. (Built.)
- **Eject (the recursive way)**: instead of a bespoke token export, a customer **stands up
  their own control-plane root sidecar** — their vault, their broker, their SSO. Point their
  `broker.ini` at `control.<their-domain>` and they're fully self-hosting, custody included.
  The `exported_at`/dual-driver hook remains as the *lightweight* path (one connector, direct
  token) for customers who don't want to run a whole control plane.

---

## 7. Rollout (revised for the recursive destination)

- **A — Sidecar contract + repos.** ✅ mostly done: Sidecar Kit is its own repo
  (`sidecar-kit.tiknix`, core consumes it via composer); `pipelines`/`store`/`explorer` repos
  pushed. *Remaining:* extract `tiknix-sidecar-kit` template polish; de-co-locate seam (Phase E).
- **B — Define + carve the clean-room base.** The §5 classification becomes a manifest; the
  base runtime (`lib/Pipeline/*` at least) ships as a **composer-pinned package** so runtime
  bugfixes reach instances via `composer update`, not per-instance `git merge`. (`trim-instance.php`
  demotes to a **one-time migration** for the legacy full-clone instances.)
- **C — Extract feature sidecars.** AI Builder → `aibuilder.tiknix`, Workspace →
  `workspace.tiknix` (+ MCP admin). Same move as the kit. Nav → `/sidecar/app/*`; flags gate + bill.
- **D — Extract the control-plane as the root sidecar.** broker/custody/SSO-mint/provisioning
  become their own deploy at `control.tiknix.com`; core = base + that + all flags.
- **E — De-co-locate + shards.** Sidecar↔instance over HTTP only (`trigger_secret`/`brk_`),
  no shared filesystem → non-PHP sidecars + `control-<region>-<nn>` nodes.
- **F — Self-host eject.** A customer runs their own control-plane root sidecar.

Order is forced: you can't fresh-repo the base until the feature + control-plane sidecars are
out (B/C/D); the clean-room base repo + monolith archive is the **payoff at the end**, not the
start.

---

## 8. Open decisions

1. **Base as a composer package** (the runtime especially) — so upgrades flow via `composer
   update`, not per-instance merges. Lean: yes. Settle in Phase B.
2. **Root-of-trust boundary** (§2) — exact contents; global-identity-vs-regional-root (§4).
3. **Custody partitioning** for shards + the cross-region migration job.
4. **Sidecar↔instance auth at a distance** (post de-co-locate) — where `trigger_secret`/`brk_`
   are looked up when the sidecar has no filesystem access (a control-plane lookup keyed by
   SSO'd member + selected instance).
5. **Existing full-clone instances** — migrate via `trim-instance.php` (one-time) or
   re-provision from the base.

---

## Status / progress (2026-07-24)

- Sidecar repos pushed (SSH, secret-audited): `pipelines.tiknix`, `store.tiknix` (dir
  `shop.tiknix`), `explorer.tiknix`, `sidecar-kit.tiknix` (tagged `v0.1.0`).
- **Kit flip done** (core `8da1857`): core consumes `tiknix/sidecar-kit ^0.1.0` (composer VCS),
  `core/lib/Sidecar` deleted; sidecar front-controllers boot from `vendor/autoload`. Live-verified.
- **`scripts/trim-instance.php`** (`53caea3`) — migration tool; dry-run on bidsurge = 489K/14
  files. To harden with the §5 PROTECTED invariant + owner's keep-calls.
- Empty until Phase C: `aibuilder.tiknix` (extract AI Builder). Same for a `workspace.tiknix`.

## See also
`lib/Sidecar/*` (now `tiknix/sidecar-kit`), `views/sidecar/app.php`; memories
`connector-integrations-architecture` (Tier-3 custody/broker), `core-primitive-and-nav-rename`,
`sidecar-plugins`, `sidecar-ecosystem-plan`.
