# Sidecar Ecosystem & Thin-Instance Architecture

Status: **spec** (2026-07-24). Supersedes nothing; extends the Sidecar Kit
(`lib/Sidecar/*`), the connector custody model ([Tier-3 broker](#6-connector-custody--the-eject-path)),
and the provisioning secret-scrub in `scripts/aibuilder-provision.php`.

---

## 0. The shift in one paragraph

Today a customer instance is a **clone of core**, so every published repo ships all of
tiknix's tooling — AI Builder, Workspace, MCP admin, the connectors UI — even though
most of it only ever *runs* on the control plane. We move that tooling **out of the
clone** and behind the **sidecar + broker** boundary: authoring/orchestration tooling
becomes independently-repo'd, independently-deployed **sidecars** reached over SSO; only
the thin **execution runtime** stays in the instance. The result: a customer's repo is
*just their app*, tiknix tooling stays proprietary and updatable outside the "tiknix PHP"
runtime, and — via an **audited eject** — a customer can take their credentials and
self-host with zero tiknix dependency.

Three moves, in dependency order:

1. **Formalize the sidecar contract** (repo-per-sidecar, deploy, SSO handshake) — §4.
2. **Provisioning trim** — stop cloning control-plane-only code into instances — §5.
3. **Eject / dual-driver** — the walk-away path — §6.

---

## 1. Principles

- **P1 — Custody lives in core.** A connector token NEVER lives in an instance while
  on-platform. The instance runtime is the adversary (its `app_key` is in its own
  config, and its repo is published to GitHub). The instance reaches stores through the
  **broker**, hard-scoped to its `instance_id` at `Mcp::brokerToolCall`. *(Built.)*
- **P2 — Authoring is a sidecar; execution is a thin lib.** Anything that *edits/plans/
  orchestrates* (AI Builder, Workspace, the pipeline editor) is a sidecar on core.
  Anything that *executes against the instance's own data* (the pipeline runtime, the
  connection step) is a minimal lib shipped in the instance.
- **P3 — A sidecar is a separate product.** Its own repo, its own deploy, its own
  release cadence, reached only through the stable **Sidecar Kit** contract (SSO + iframe
  embed + feature flag). It may be written in anything; it is not "tiknix PHP".
- **P4 — Nothing tiknix-proprietary lands in a customer repo.** The provisioning trim is
  the enforcement point, the same way the secret-scrub enforces P1.
- **P5 — The customer is never a hostage.** Eject turns "you depend on tiknix" into "you
  *choose* to, until you don't."

---

## 2. The three layers

```
┌─────────────────────────── CORE (tiknix.com) ───────────────────────────┐
│  Custody + control plane                                                  │
│   • connections table (encrypted tokens)  • MCP broker (/mcp/message)     │
│   • OAuth client secrets (github/shopify/stripe.ini)                       │
│   • Sidecar Kit: registry, SSO mint (/sidecar/launch, /sidecar/app)       │
│   • Provisioning (capricorn + aibuilder-provision.php)                    │
└───────────────┬───────────────────────────────────────┬──────────────────┘
                │ SSO (Sidecar Kit)                       │ broker (brk_ key)
   ┌────────────▼─────────────┐               ┌───────────▼──────────────────┐
   │  SIDECARS (own repos)     │               │  INSTANCE (customer's repo)   │
   │   • ai-builder.tiknix     │  edits/plans  │   • THEIR app (controls/views │
   │   • workspace.tiknix      │  ───────────► │     /models)                  │
   │   • pipelines.tiknix (ed.)│               │   • lib/Pipeline/* runtime    │
   │   • explorer.tiknix       │               │   • broker client (brk_)      │
   │   • shop.tiknix           │               │   • conf/*.ini (own secrets)  │
   └───────────────────────────┘               └───────────────────────────────┘
```

- **Core** = custody + the SSO/broker gateways + provisioning. Never shipped to a customer.
- **Sidecars** = the build ecosystem, each a standalone app SSO'd into the shell.
- **Instance** = the customer's app + the thin runtime it actually executes.

---

## 3. What moves where (the boundary)

The rule: **authoring → sidecar; execution → thin lib in the instance.**

| Capability | Today (in every clone) | Target | Why |
|---|---|---|---|
| **AI Builder** (`controls/Aibuilder.php`, jailed terminal, plan pipeline) | in clone, control-plane-only | **sidecar** `ai-builder.tiknix` | pure control-plane; never runs on the instance |
| **Workspace / AI Projects** (`controls/Workbench.php`, task board) | in clone, control-plane-only | **sidecar** `workspace.tiknix` | pure control-plane |
| **Pipeline editor** | already sidecar | **sidecar** `pipelines.tiknix` | ✅ already done |
| **Architecture Explorer** | already sidecar | **sidecar** `explorer.tiknix` | ✅ |
| **Store** | already sidecar | **sidecar** `shop.tiknix` | ✅ |
| **MCP admin / registry** (`controls/Mcpconfig.php`, `Mcptools.php`) | in clone | **sidecar** (or core-only) | management UI, not runtime |
| **Connections UI** (`controls/Connections.php` control-plane branch) | in clone | **core-only** (already gated) | management UI |
| **Pipeline runtime** (`lib/Pipeline/{Loader,Executor,Runner,Steps,DurableObject}`) | in clone | **STAYS (thin lib)** | executes against the instance's own DB/data |
| **Connection step** (`lib/Pipeline/Steps/ConnectionStep.php`) | in clone | **STAYS (thin)** | runs in-instance, but holds nothing — only calls the broker |
| **Broker client** (reads `conf/broker.ini`, calls `/mcp/message`) | in clone | **STAYS (thin)** | the instance's handle to its own stores |
| **`/pipeline/*` endpoints** (`controls/Pipeline.php`) | in clone | **STAYS** | the instance serves its own pipeline API/triggers |
| **The customer's app** | in clone | **STAYS** | it's theirs |

Net: a trimmed instance keeps `lib/Pipeline/*` + `controls/Pipeline.php` + the broker
client + its own app. Everything in the "sidecar" rows leaves the clone.

---

## 4. Sidecar-per-repo contract

Each sidecar becomes a first-class product with its **own GitHub repo** (the current
`pipelines.tiknix` / `shop.tiknix` / `explorer.tiknix` trees are local-only, no remote —
that changes here).

### 4.1 Repos to create

| Sidecar | Repo | Vhost | Feature flag |
|---|---|---|---|
| Pipeline editor | `tiknix-sidecar-pipelines` | `pipelines.tiknix.com` | `pipelines` |
| Architecture Explorer | `tiknix-sidecar-explorer` | `explorer.tiknix.com` | `explorer` |
| Store | `tiknix-sidecar-shop` | `shop.tiknix.com` | `shop` |
| **AI Builder** (new) | `tiknix-sidecar-aibuilder` | `builder.tiknix.com` | `aibuilder` |
| **Workspace** (new) | `tiknix-sidecar-workspace` | `workspace.tiknix.com` | `workspace` |

(Plus a shared `tiknix-sidecar-kit` package — the SSO consume + shell chrome — so a new
sidecar starts from a template instead of copy-paste.)

### 4.2 The stable contract (already built — `lib/Sidecar/*`)

A sidecar only has to honor:
1. **Registration** — one `[sidecar.<name>]` in core's `config.ini`
   (`url`, `sso_secret`, `feature`, `label`, `icon`). `Registry::all()` discovers it.
2. **SSO consume** — accept the token minted at `/sidecar/launch/<name>` and start a
   session (`Sso::session()` on the sidecar side). Same-site `*.tiknix.com` so the
   `SameSite=Lax` cookie survives the iframe.
3. **Embed** — render inside the shell iframe at `/sidecar/app/<name>` (no
   `X-Frame-Options`; may `postMessage({tiknixHeight})`).
4. **Instance access** — reach an instance ONLY via the documented server-to-server
   paths: `trigger_secret` for the instance's own `/pipeline/*`, `brk_` for the broker.
   A sidecar has no filesystem access to instance repos unless it is co-located (today
   they are; long-term they talk over HTTP).

Anything satisfying that is a sidecar — **in any language**. That's P3.

### 4.3 Deploy

Per-repo CI → its vhost. Version independently. The Sidecar Kit contract is the ABI, so
a sidecar can ship on its own cadence without a core release. Core only needs the
`[sidecar.<name>]` row + the feature flag.

### 4.4 The co-location seam (important)

Today sidecars share the box and read instance dirs on the local filesystem
(`PipeFiles::instanceDir`). That's fine for now but it's the one thing that keeps them
from being *truly* separate deploys. The long-term target: sidecars reach instances only
over HTTP (`trigger_secret`/`brk_`), so a sidecar can run anywhere. Track this as the
"de-co-locate" milestone; it's what unlocks non-PHP sidecars and independent hosting.

---

## 5. Provisioning trim (the code scrub)

The enforcement point for P4, mirroring the secret-scrub already in
`scripts/aibuilder-provision.php`.

### 5.1 Mechanism

A **keep-manifest** (allow-list, not a drop-list — new core files default to *not*
shipped): `scripts/instance-manifest.php` lists exactly what a customer instance keeps:

- their app (`controls/*` minus the control-plane set, `views/*`, `models/*`, `routes/*`)
- `lib/Pipeline/*` + `controls/Pipeline.php` (runtime)
- broker client + `lib/ConnectionStore`? **no** — that's core-side; the instance only
  needs the broker *caller* (`ConnectionStep` + `conf/broker.ini`)
- framework (`vendor/`, `bootstrap.php`, `conf/*.example.ini`, `lib/functions.php`, the
  base `Control`, auth, sessions)

After capricorn clones + `aibuilder-provision.php` seeds, a new **trim step** removes
everything not in the keep-manifest: `controls/Aibuilder.php`, `Workbench.php`,
`Mcpconfig.php`, `Mcptools.php`, their views, and the control-plane-only libs.

### 5.2 Guardrails

- **Reversible & idempotent** (like the secret-scrub) — trims a working copy, never the
  source.
- **CI check**: a test asserts a trimmed instance still boots, serves its app, runs a
  pipeline, and reaches the broker — so the keep-manifest can't drift into removing
  something the runtime needs.
- **Existing instances**: the trim is forward-only (new provisions). Existing instances
  keep their bloat until they re-provision or opt into a `scripts/trim-instance.php`
  one-shot (with the same backup discipline as the upgrade process).

### 5.3 Payoff, measured

Before/after `git ls-files | wc -l` on a fresh clone, and a diff of `controls/` — the
customer's repo should shrink to *their* files + the runtime, and contain **zero**
AI-Builder/Workspace/MCP-admin code.

---

## 6. Connector custody & the eject path

### 6.1 On-platform (built)

Token encrypted in core's `connections` table; instance reaches it via the broker
(`brk_` key), scoped at `brokerToolCall`. This is **P1** and it's live.

### 6.2 Eject / dual-driver (designed; `exportedAt` is the seed)

A connector gains **two drivers**:

- **broker driver** (default, on-platform): token in core, reached via `/mcp/message`.
- **direct driver** (post-eject, off-platform): token in the **instance's own encrypted
  keystore** (`conf/keystore.db` or `secure/keystore.db`, sealed with the *instance's*
  own key — NOT core's `app_key`), connector calls the store's API directly.

**Eject** is a deliberate, audited, one-time action on the control plane:

1. Owner clicks "Export & self-host" for a connection.
2. Core decrypts the token **once**, writes it into the instance's keystore, flips that
   connection's driver to `direct`, stamps `connections.exported_at`, and logs an audit
   event.
3. From then on the instance's `ConnectionStep` uses the direct driver — **no broker, no
   core dependency.** The instance is now self-hostable: point DNS elsewhere, `git push`
   the repo to any host, done.

**The tradeoff is the feature, not a bug:** eject **moves custody to the customer**.
Pre-eject the token isn't on their box (safe to publish to GitHub); post-eject it is
(their responsibility, sealed with their key). That's what "walk away" means.

### 6.3 Uptime framing (for docs)

While on-platform, core is a dependency **only for store calls** (broker = decrypt +
proxy), not for the instance's own pages/pipelines/forms. It's one hop, HA-able, and
never a lock-in because of §6.2.

---

## 7. Rollout (phased, each shippable)

- **Phase A — Sidecar contract hardening.** Extract `tiknix-sidecar-kit` (SSO consume +
  shell); create the 3 existing sidecars' GitHub repos + CI/deploy; document the ABI.
  *No instance change.* Low risk.
- **Phase B — Provisioning trim.** `instance-manifest.php` (keep-list) + trim step in
  provisioning + the CI boot/run/broker smoke test. Ship on new provisions; add the
  opt-in `trim-instance.php` for existing ones. *This is the immediately-visible win.*
- **Phase C — AI Builder + Workspace sidecars.** Stand up `builder.tiknix` /
  `workspace.tiknix` from the extracted controllers; register `[sidecar.*]`; nav links to
  `/sidecar/app/*`; remove the controllers from the keep-manifest. *Biggest structural
  change; do after B proves the trim.*
- **Phase D — De-co-locate.** Move sidecar→instance access fully onto HTTP
  (`trigger_secret`/`brk_`), dropping the shared-filesystem assumption. Unlocks non-PHP
  sidecars + independent hosting.
- **Phase E — Eject / dual-driver.** Direct driver + instance keystore + the audited
  export action. The trust capstone.

---

## 8. Open questions / risks

1. **Runtime updates after trim.** If the pipeline runtime stays in the instance, how do
   runtime *bug fixes* reach trimmed instances? (Today: `git merge origin/main`.) Option:
   keep `lib/Pipeline/*` on the core-managed upgrade path even in trimmed repos, or
   package the runtime as a `composer` dependency the instance pins. **Decide in Phase B.**
2. **Sidecar ↔ instance auth at a distance.** Once de-co-located, the sidecar needs the
   instance's `trigger_secret`/`brk_` without filesystem access — where do those live?
   (A control-plane lookup keyed by the SSO'd member + selected instance.)
3. **Instance keystore sealing key.** For eject, the instance's own key must NOT be
   derivable from the published repo (else GitHub leaks it). Likely an env var /
   host-provided secret the customer sets at self-host time — document clearly.
4. **Feature-flag sprawl.** Five+ sidecars × per-member feature flags. Consider a
   "builder bundle" flag.
5. **Non-PHP sidecars vs the shared Sidecar Kit.** The kit is PHP today; a Node/Go
   sidecar needs the SSO-consume spec as a language-agnostic doc, not a PHP lib.

---

## See also

- `lib/Sidecar/*` (the Kit), `views/sidecar/app.php` (embed) — the built contract.
- `core-primitive-and-nav-rename` / `sidecar-plugins` memories — the plugin model + the
  three existing sidecars + the "core rip-out" note this plan formalizes.
- `connector-integrations-architecture` memory — Tier-3 custody + the broker.
- `scripts/aibuilder-provision.php` — where the secret-scrub lives; where the code-trim
  goes.
