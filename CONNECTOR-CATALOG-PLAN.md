# Connector catalog, aliases, roles and bindings

*Written 2026-09-28. Companion to COMPONENTS_PLAN.md (plugins) and CONNECTIONS_PER_INSTANCE.md
(where credentials live). Owner's framing, which this plan follows: connectors are not plugins;
a connector holds an alias, a connection, a type (oauth / api / mcp …), an endpoint, docs and
a way to bring a concept to life. Concepts ask for what they need by role; an install binds a
role to one of its connections; two Stripe accounts are two bindings, never two copies of code.*

## 0. The whole thing in one paragraph

Three things, kept apart. A **connector** is a provider definition — key, type, endpoint,
docs, auth shape, what it exposes — and is data (`connectors/<key>.json`) except for the few
providers whose behaviour is bespoke (Shopify, Stripe, QuickBooks, Microsoft 365), which are
core classes with the same meta. A **connection** is one credentialed use of a connector on one
install, created by a person, encrypted in that install's own store, and now carrying a
human **alias** ("Serenity main", "EU store"). A **concept** never names a provider in code: its
manifest declares **roles** (`payments`, `mail`, `search`) and which connector types may fill
each; enabling the concept **binds** each role to a connection alias — at install level when
there is one of a thing, at entity level when the concept models several (a campaign's sending
mailbox, a storefront's shop). Resolution is strict: one candidate binds itself; two candidates
with no binding is an error that names the page to fix it; a missing connector type names the
catalog entry to install. Connector definitions ship through a **connector catalog** beside the
concept catalog, so `--concept-install=prospects` can bring the `serpapi` definition with it,
and a connector may *suggest* the concepts it powers. Credentials never move; bindings hold ids.

## 1. What exists (the recon — read these before touching anything)

| Piece | Where | What it does today |
|---|---|---|
| Connection store | `lib/ConnectionStore.php` | per-install `data/connections.db`, secrets encrypted with `secure/connections.key`; columns: `connector_type, environment, external_eid, external_name, external_url, token_type, scopes, auth_type, access_token, refresh_token, app_key, app_secret, app_scopes, metadata_json, enabled, revoked_at, last_error`; `for($type, $env, $account)` returns the ONE live connection of a type, throws when several and no `account` names one (multi-account ambiguity is an error — kept) |
| Connector registry | `services/connectors/ConnectorRegistry.php` | classes by glob `services/connectors/*Connector.php` + manifests `connectors/*.json` at the app root; a class wins over a manifest of the same key |
| Manifest connector | `services/connectors/ManifestConnector.php` | a connector as data: key, label, blurb, category, icon, base_url, test_path, account_url, auth {style, name, key_label, hint}, endpoints[] |
| Connector interface | `services/connectors/ConnectorInterface.php` | `key, meta, isConfigured, authorizeUrl, exchangeCode, validateApiKey, brokerTools, callBrokerTool, createCheckout, webhookOrder, subscriptionFromEvent, billingPortalUrl, fetchFeed, refreshToken` |
| Concept manifest | `lib/ConceptManifest.php` | `requires.connectors` is a list of connector KEYS (strings); `Concepts::verify()` reports a missing one as a notice, never blocks |
| Pipelines | `lib/Pipeline/Steps/ConnectionStep.php` | `connection` step calls `<connector>:<tool>` through core's broker; auth injected server-side; addresses the connector by type only |
| Core → instance | `lib/ConnectorPush.php` | core hands a finished OAuth credential to the instance's own store (the one door) |
| Plugins declaring connectors | catalog | `storefront: [stripe]`, `outreach: [microsoft]`, `prospects: [serpapi]`; the rest none |
| Entity-level binding, by hand | lead-machine `emailaccount` (now in the `outreach` plugin) | "use this already-connected mailbox as a sending identity, with a daily cap" — a row that points at a connection; exactly the pattern to generalise |
| Fleet today | `data/connections.db` per install | partsdna: monday, shopify, rest, quickbooks (sandbox), database; invoza: stripe (sandbox); core: turnstile; serenity: none (its storefront reaches Stripe through the broker driver) |
| Task workspaces | `lib/WorkspaceManager.php` | copy the app DB and config, never the connection store — previews have no connections (the `workspace_share` proposal, folded in here as §8) |

Callers of `ConnectionStore::for()` in core name a type directly (`stripe`, `github`,
`database`, `anthropic`); they are core's own use of core's connections and are not concepts —
they keep working unchanged and migrate to roles only where it buys something (§10).

## 2. The model

```
connector   provider definition      key, type, endpoint, docs, auth shape, tools   data (manifest) or a core class
connection  one credentialed use     alias, connector key, environment, tokens      per install, encrypted, person-made
concept     a feature                roles → acceptable connector types             never names a provider in code
binding     role → connection        install-level or entity-level                  ids only, no secrets
```

Invariants, each of which the code enforces rather than assumes:

1. **A concept asks by role.** `Connections::for('payments')` inside a concept, never
   `ConnectionStore::for('stripe')`. The lint (§5) flags a concept that names a type.
2. **Ambiguity is an error.** One candidate with no binding binds itself and says so in the
   log; two candidates with no binding throws `UnboundRoleException` naming the concept, the
   role, the candidates and the page that binds them. Nothing is picked "for now".
3. **Core wins on keys.** A catalog manifest never overrides a core class of the same key; a
   concept's code never registers a connector (connectors are not plugins).
4. **Credentials never move.** A binding holds a connection id and its alias snapshot; the
   secret stays in `connections.db`, decrypted only by `ConnectionStore` on the install (or by
   the broker on core, for the instance's own connection).
5. **An alias is a name, not a key.** Renaming an alias re-labels bindings; it never re-points
   them (they hold the id). Two connections of one connector on one install cannot share an
   alias.

## 3. Data model

### 3.1 `connections` (each install's store) — one new column

- `alias TEXT` — unique per `connector_type` per install. Migration derives it:
  `external_name`, else `external_eid`, else `<Label> (<environment>)`; collisions get `#2`.
  The Connections hub shows it everywhere the card is named, and lets it be renamed.
- `workspace_share INTEGER DEFAULT 0` — §8.

### 3.2 Connector manifest, v2 (`connectors/<key>.json`; a core class returns the same from `meta()`)

```json
{
  "key": "serpapi", "version": "1.0.0",
  "label": "SerpAPI", "blurb": "…", "category": "Data", "icon": "search",
  "type": "api_key",                    // oauth | api_key | basic | mcp | none
  "base_url": "https://serpapi.com", "docs_url": "https://serpapi.com/search-api",
  "account_url": "https://serpapi.com/manage-api-key",
  "auth": { "style": "query", "name": "api_key", "key_label": "API key", "key_hint": "…" },
  "endpoints": [ { "method": "GET", "path": "/search.json", "label": "Search", "params": ["engine","q","num","location","gl"] } ],
  "tools": [],                          // what the broker exposes as <key>:<tool>; derived from endpoints when absent
  "suggests": { "concepts": ["prospects"] },
  "multiple": true                      // may an install hold several connections of this? (Turnstile: false)
}
```

`type: "mcp"` is a connector whose endpoint is an MCP server (URL + auth); its `tools` come
from `tools/list` at connect time and are cached on the connection's `metadata_json`. That is
how a third-party MCP becomes a connection a concept can bind to, with no PHP.

### 3.3 Concept manifest — `requires.connectors` becomes roles

```json
"requires": {
  "connectors": [
    { "role": "payments", "types": ["stripe"],               "label": "Takes payment" },
    { "role": "mail",     "types": ["microsoft", "gmail"],   "label": "Sends and reads mail", "scope": "entity", "entity": "emailaccount" },
    { "role": "search",   "types": ["serpapi"],              "optional": true }
  ]
}
```

- `scope`: `install` (default — one binding for the whole install) or `entity` (each row of
  `entity` bean carries its own binding; the install-level binding, if any, is the default
  for new rows).
- `optional`: the concept works without it and says what is missing where it matters.
- A bare string `"stripe"` keeps meaning `{role: "stripe", types: ["stripe"]}` so every
  published manifest stays valid; the lint warns and the next version names a role.

### 3.4 Bindings — a bean in the APP database (config, not secret)

`connectionbinding`: `concept, role, scope ('install'|'entity'), entity_type, entity_ref,
connection_ref (id in connections.db), alias_snapshot, bound_by ('auto'|'member:<id>'), created_at`.
Unique on `(concept, role, scope, entity_type, entity_ref)`. The `connection_ref` is a plain
pointer (`_ref`, not `_id` — it points into another database). `alias_snapshot` is only for
display when the connection is gone; resolution always re-reads the store.

## 4. Resolution — `lib/Connections.php` (new; one class, used by concepts, pipelines and the broker)

```php
Connections::for(string $concept, string $role, ?array $entity = null): OODBBean   // the connection bean (read-only)
Connections::token(string $concept, string $role, ?array $entity = null): string   // decrypted secret, this install only
Connections::candidates(string $concept, string $role): array                      // [{id, alias, type, environment}] for pickers
Connections::bind(string $concept, string $role, int $connectionId, ?array $entity, string $by): void
Connections::unbound(string $concept): array                                       // roles with no usable binding (for the Plugins page and verify())
```

Order in `for()`: entity binding → install binding → exactly one candidate (auto-bind, logged
`INFO Connections: auto-bound <concept>.<role> to <alias>`) → `UnboundRoleException`
("storefront needs a payments connection and this install has two Stripe connections
(Serenity main, EU store) — choose one under Plugins → Storefront → Payments") → no candidate:
`MissingConnectorException` ("…has no stripe connection; connect one under Connections → Stripe"
or, when the connector definition itself is absent, "…install the `serpapi` connector:
`clitool --connector-install=serpapi`"). A binding whose connection was deleted or disabled
resolves as unbound, with the alias snapshot in the message.

Where each consumer plugs in:

- **Concept code**: `Connections::for('payments')` inside `app\concepts\storefront\…`; the
  concept name is inferred from the calling namespace so code does not repeat it.
- **Pipelines** (`ConnectionStep`): a pipeline shipped by a concept says `"connection": {"role": "search"}`;
  an app's own pipeline may say `"connection": {"alias": "Stripe · EU store"}` or keep the
  legacy `"connector": "stripe"` (single connection only; ambiguity errors the run with the
  same message). The broker receives the connection id, never the alias.
- **Broker / MCP** (`Mcp` connection tools): tool names stay `<key>:<tool>`; the call carries
  `connection_id`, validated to belong to the calling instance; the tool listing groups by
  alias so an agent sees "stripe (Serenity main)" and "stripe (EU store)".
- **`Concepts::verify()`**: an unbound *required* role is reported as a notice at enable
  (the concept enables; its pages say what is unbound) and as an ERROR on the Plugins page
  until bound; a missing connector *type* is a notice pointing at the catalog.

## 5. The connector catalog

- **One catalog repository, two kinds.** `tiknix-concepts/` gains a top-level `connectors/`
  holding `<key>.json` manifests (a manifest is one file; no directory, no code). Published,
  versioned and served by the same `ConceptCatalog` machinery (`bundle` for a connector is
  the one file). Code connectors are core-only and never in the catalog; a manifest can
  graduate to a core class without renaming (class wins).
- **Commands** (`scripts/clitool.php`): `--connectors` (installed: core classes, app-root
  manifests, catalog-installed, each with version and how many connections use it);
  `--connector-publish=KEY --from=DIR`; `--connector-install=KEY` (→ `connectors/<key>.json`,
  recorded in the lock); `--connector-update=KEY`; `--connector-lint=FILE` (schema, no secrets,
  no absolute paths, `type` from the allowed set).
- **Lock**: a `connectors` section in `concepts.lock` (one lock file, one record of what an
  install has; `--concept-lock` rehashes both).
- **Dependency resolution**: `--concept-install=NAME` resolves every role's `types`: a type
  present as a core class or an installed manifest is satisfied; one the catalog holds is
  installed alongside (and listed in the install plan the board shows); one nowhere is an
  error naming it — a concept that could never be bound is not installed.
- **`suggests.concepts`**: after a successful connect, the hub offers "This connection can
  power: Prospects — install?" linking to the concept's install plan. Soft, never automatic.
- **Lint for concepts**: naming a connector type in code (`ConnectionStore::for('stripe')`,
  `'stripe'` in a `connection` step) is an ERROR for a concept that declares roles, a WARNING
  for one still on bare-string requires.

## 6. UI

- **Connections hub** (`/connections`): alias on the connect form (prefilled from the
  provider's account name), rename on the card, "Used by" (bindings across concepts and
  entities) on the card, and a refusal to delete a connection that is bound — "unbind first,
  or bind those to another connection" with the list.
- **Plugins page** (each enabled concept): its roles, each with a dropdown of candidates
  filtered by `types`, the auto-bound ones marked "chosen automatically (only one)", unbound
  required roles as an error with the same wording the exception uses. Saving writes a
  `connectionbinding` with `bound_by = member:<id>`.
- **Entity picker widget** (`views/connections/_picker.php`, a slot the concept renders into
  its form): "Sending mailbox: [Microsoft · sales@ ▾]" — candidates by role, default from the
  install binding; stores `connection_ref` on the entity row.
- **Terminology on every page**: connector = provider; connection = your account with it;
  alias = its name here.

## 7. Migration (idempotent seeds, per install, run by `--build`)

1. `connections.alias` populated as in §3.1; `workspace_share` = 0 except `turnstile` = 1.
2. Manifests already published get roles in their next version: storefront `payments:[stripe]`;
   outreach `mail:[microsoft]` (scope entity, entity `emailaccount`) — the plugin's own
   `emailaccount` becomes the entity that carries the binding; prospects `search:[serpapi]`
   (optional — discovery without SerpAPI falls to the agent's own search, which the plugin
   already supports and says so). Bare-string manifests keep working meanwhile.
3. Existing single connections auto-bind on first use (invariant 2), so nothing a customer
   has connected stops working the day this ships.
4. Serenity's storefront today reaches Stripe through the broker driver with no local
   connection: it gets a `payments` binding to a Stripe connection the owner creates (or the
   broker-backed connection is registered as one — decision 3 below).

## 8. Task workspaces (the earlier `workspace_share` proposal, placed here)

A connection flagged `workspace_share` is copied — row and key — into a task workspace at
init; default on for Turnstile and for any connection whose environment is not production;
off for production. Bindings copy with the app DB as they always did; a binding to an unshared
connection resolves in the workspace as unbound with the message "not shared with task
workspaces — flag it under Connections". Previews report to the Firehose as `role=workspace`
already, so a shared key's mistakes open no fix tasks.

## 9. Tests (each phase ships its own; the suite runs on every install)

- `ConnectionsResolveTest`: entity → install → single candidate auto-bind → two candidates
  throw with the page named → missing type names the catalog; a deleted bound connection
  reports its alias snapshot.
- `ConnectionAliasTest`: uniqueness per type, migration derivation, rename keeps the id.
- `ConceptManifestRolesTest`: bare strings still parse, roles validate (`types` non-empty,
  `scope` in set, `entity` named when scope is entity).
- `ConnectorCatalogTest`: publish/install/update/lint of a manifest; concept install pulls the
  connector it requires; refuses one nowhere.
- `ConnectionStepBindingTest`: a pipeline step by role/alias/legacy type; ambiguity fails
  the run with the same message.
- `WorkspaceShareTest`: flagged rows and the key travel; unflagged do not; the binding to an
  unshared connection resolves unbound in the workspace.
- Characterization first, as always: what `ConnectionStore::for()` does today is pinned
  before `Connections::for()` is layered on it.

## 10. Build order — every step shippable alone

- **P1 — roles, aliases, bindings, resolver (core).** `connections.alias` + migration;
  `connectionbinding` bean + seed; `Connections` class; `ConceptManifest` roles (bare strings
  kept); `Concepts::verify()` unbound reporting; `--connectors` listing. Proving case:
  **storefront** declares `payments`, Invoza's Stripe sandbox auto-binds, a second Stripe
  connection makes the page say which to choose and the Plugins page binds it. Tests above.
- **P2 — connector catalog.** `tiknix-concepts/connectors/`, publish/install/update/lint,
  lock section, `--concept-install` resolution, `suggests`. Proving case: **serpapi** published,
  `prospects` installed on a fresh project brings it, the hub offers Prospects after a SerpAPI
  connect.
- **P3 — UI.** Hub alias/rename/used-by/refuse-delete; Plugins page bindings; entity picker
  widget; `outreach` moves `emailaccount` onto the widget (its own plan on the harvest fork).
- **P4 — pipelines and the broker by binding.** `ConnectionStep` role/alias; MCP tool listing
  grouped by alias; `connection_id` validated per instance.
- **P5 — task workspaces share flagged connections.**
- **P6 — core's own consumers.** `github`, `anthropic`, `database` callers move to aliases where
  an install can hold several (GitHub already can); the rest stay.

Roughly: P1 two days, P2 one, P3 two, P4 one, P5 half, P6 as met. P1 and P2 are the critical
piece; everything after is surface.

## 11. Decisions to confirm

1. **Alias uniqueness per connector, not per install** — "Main" may be both a Stripe and a
   Shopify alias. (Chosen: per connector; pickers are already filtered by type.)
2. **Bindings live in the app database**, not in `connections.db`: they are configuration a
   plan may seed and a checkpoint should carry; the store stays secrets-only. (Chosen.)
3. **Serenity's broker-driven Stripe**: register the broker-backed connection as a real row
   with `auth_type = broker` so it can be bound like any other, or require a local Stripe
   connection? (Proposed: the former — one model, and the broker driver becomes an
   `auth_type`, not a special case.)
4. **`type: "mcp"` connectors** in the first cut or later? (Proposed: the manifest field and
   the `tools/list` cache in P2; the broker's MCP proxying in P4.)
5. **Optional roles** default to `false`. A concept that can run without a connector must
   say so.
6. **Suggests are soft** — never install on connect.
7. **Renaming an alias** re-labels; deleting a bound connection is refused. (Chosen.)

## 12. Not in this plan

Per-role permission scopes inside a connection (which tools a concept may call); marketplace
listing of connectors; OAuth apps of core's own (the "shared app" handoff) beyond what exists;
moving core's own consumers wholesale (P6 is opportunistic).
