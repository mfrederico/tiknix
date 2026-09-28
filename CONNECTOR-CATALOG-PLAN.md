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
each; enabling the concept **binds** each role to a connection alias — per **site** (a franchise,
location, warehouse or client: Serenity's Los Angeles and Denver each with their own Stripe,
§2c), at install level when there is one of a thing, at entity level when the concept models
several (a campaign's sending mailbox, a storefront's shop). Resolution is strict: one candidate binds itself; two candidates
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

1. **A concept asks by role.** `ConnectionBindings::for('payments')` inside a concept, never
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

## 2a. Channels — what srklr got right, carried over

srklr (`srklr.arc.tiknix`, `srklr.tiknix/views/channel/`) ran for years on a pattern the owner
later reused in cannonwms and myctobot, and it is the entity-level binding above with the
rest of its anatomy attached. Read it before building P1/P3:

| srklr | What it is | In this plan |
|---|---|---|
| `channel` bean, one row per configured integration; several rows of one platform (two Shopify stores) | the bound instance, with `getSetting/setSetting`, **switches** (per-channel feature toggles) and **cursors** (`lastchecked` = the last ORDER date seen, "not system date!") | a **channel** = an entity-level binding + its own settings, switches and cursors, stored on the channel row — never on the connection, which is only credentials |
| `views/channel/form-<platform>.php` (shopify, bigcommerce, miva, woocommerce, amazon) | a setup wizard: collect credentials, *validate by calling the API*, then save | the connector's connect form (hub) + the concept's channel wizard (its entity form with the picker), and `validateApiKey()` / `test_path` doing the live check before anything is stored |
| `views/channel/plugin-<service>.php` (klaviyo, mailchimp, quickbooks, shipstation, slack, webhook, datamerge, posystem, pptr) | services attached to a channel, each with its settings card | connectors of other roles (`mail`, `accounting`, `shipping`, `notify`, `webhook`) bound to the same concept — the two kinds are one mechanism, distinguished by role |
| `BaseControls\ChannelContext` — `getApi`, `initiateBatch`, `get`, `hook`, `streamOut`; the Klaviyo channel's verbs: order, setOrderStatus, updateStock, skulist, emailzip, test | one adapter per platform behind a fixed interface: an API client, a batch entry point, a fetch, a **webhook receiver**, streaming output | the concept's **adapter per connector type** (§2 invariant 4) is exactly this interface, declared by the concept for each role: `adapters: {payments: {stripe: "PaymentsStripe"}, mail: {microsoft: "MailGraph", mailgun: "MailMailgun", klaviyo: "MailKlaviyo"}}`; `hook` becomes the concept's per-channel webhook route (`/<concept>/hook/<channel>`), verified by the connector |
| `cron/download<platform>.php`, `<platform>variance.php`, `update<platform>orderstatus.php` — "below line 73 is unique, above is bootstrap" | per-channel scheduled jobs sharing one bootstrap; a change ledger (`queuestock`) | the concept's pipelines, run **per channel** (`ConnectionStep` by role resolves the channel's binding; the cursor is read and advanced on the channel row); the ledger is the concept's own bean |

Two consequences for the plan as written:

- **"Channel" is the word.** Entity-level bindings are channels in the UI and the code:
  `channel` is the generic bean concepts use unless they already have a better-named one
  (outreach's `emailaccount` is a channel of role `mail`). It carries `concept, role,
  connection_ref, alias_snapshot, name, settings_json, switches_json, cursors_json, enabled`.
  A concept with `scope: entity` roles gets the channel list/wizard pages for free from a
  core view (`views/channels/`), the way the Plugins page is shared.
- **The adapter contract is declared, not discovered.** `adapters` in the concept manifest
  maps role × connector type → class; the lint checks each class implements the role's
  interface (`ChannelAdapter`: `client()`, `test()`, `fetch()`, `batch()`, `hook()`), so a
  concept that claims `mail: [microsoft, mailgun]` and ships only the Graph adapter fails
  lint, not a customer.

### 2b. cannonwms — the same pattern, one generation later, plus scopes

cannonwms (`cannonwms/services/Channels/`, `controls/Web/`) is srklr's channel rebuilt with the
pieces named, and it adds the one thing this plan was missing:

| cannonwms | In this plan |
|---|---|
| `ChannelService::getSupportedTypes()` — a registry of types (`shopify`, `woocommerce`, `miva`, … 17 of them) each with `name, auth_type, icon, description`, and `status: coming_soon` for the unbuilt | the **connector catalog**: the same registry as data, per key, with `type` for `auth_type`; `coming_soon` is a manifest with no adapter yet, which the wizard already knows how to show |
| `channel` row: `type, name, slug, auth_type, has_credentials, settings, status, last_sync, last_error, fulfillment_strategy, extra_data, is_active`; credentials stored and fetched separately (`storeOAuthCredentials / getCredentials`) | the **channel** bean of §2a, credentials in `connections.db` behind `connection_ref` — cannonwms already keeps them apart; `last_sync`/`last_error` are the cursor and health that live on the channel |
| one `controls/Web/<Platform>.php` per platform doing only `connect()` + `callback()` (the OAuth wiring), then `ChannelService::create()` | code connectors keep their own connect flow (`authorizeUrl / exchangeCode` on the connector class — Shopify already); manifest connectors use the hub's generic one; either way the result is a connection row and a channel row |
| one generic `Channels` control — `index, edit, sync, test, locations, syncinventory, pushinventory, warehouses, linkwarehouse, catalog…` — every action taking the channel **id** (`opId()`) and dispatching through `SyncService` to the adapter for that channel's type | the shared channel pages (`views/channels/`) plus a generic per-channel action route: a role declares its **actions** (`sync`, `test`, `push`, …), the concept's adapter implements them, and `/channels/<action>/<id-or-alias>` runs the right adapter — "hit the internal endpoint with the id/alias and that connection happens" |
| `ChannelAdapterInterface` — `fetchOrders(since), updateStock(sku, qty, locationId), updateTracking, testConnection, getOrder, getProducts, fetchRefunds, createRefund, cancelOrder, registerWebhooks` — and **`getCapabilities()`** with `hasCapability()`, an `AbstractChannelAdapter` giving safe defaults, `StubAdapter` for the coming-soon types, `LocationAwareAdapterInterface` for platforms with locations | the role's adapter interface is exactly this shape, and **capabilities are declared by the adapter**, not inferred from the type: UI and services branch on `hasCapability('refunds')`, never on `type === 'shopify'`. A role interface names its required methods and its optional capabilities. |
| **Warehouse** — a physical *and* data warehouse; channels link to warehouses many-to-many with a **priority** (`channelwarehouse: warehouse_id, priority, is_active`) and a per-channel **fulfillment strategy** (`split`, `hold_until_complete`); locations map between warehouse and platform | the missing piece: a **scope**. A channel belongs to a scope entity the concept names — a warehouse for a WMS, a client for an agency, a brand or site for a multi-store shop — and a scope holds many channels with priority and a strategy among them. |

So the `channel` bean of §3.4 gains `scope_type, scope_ref, priority` (a channel with no scope
is install-wide), and a role may declare `scope: "warehouse"` meaning "channels of this role
hang off that concept's warehouse rows". "Multiple connections per warehouse" is then the
normal case, not a special one, and the concept decides the strategy across them (cannonwms's
`fulfillment_strategy` is the concept's own setting on the channel or the scope).

Two more things cannonwms settles:
- **Sync state is per channel, and the cursor is the last record's timestamp** (`last_sync`
  is read as `since`; a forced full sync ignores it) — same rule as srklr's `lastchecked`,
  and it goes in `cursors_json` with the record's own clock, never the system's.
- **Coming soon is a first-class state**: a connector in the catalog with a stub adapter shows
  in the wizard and the hub as coming soon rather than being absent — which is also what the
  Get-started wizard already does for modules.

### 2c. Sites — franchises, locations, warehouses (the owner's "supercritical" layer)

Serenity with a Los Angeles and a Denver location needs a Stripe per location, for accounting;
a WMS needs a warehouse per building; an agency has a client per engagement. cannonwms calls
this a warehouse and resolves it once per request in its base control — *"single source of
truth: 1) the sidebar switcher session, 2) `member.warehouse_id`, 3) first assigned"* — with
members assigned to warehouses and channels linked to warehouses with priority. That is the
scope of §2b made first-class, and it is where bindings actually live. This plan calls it a
**site**; a concept may call it what its domain does (warehouse, location, franchise, client,
brand) — the mechanism is one.

- **A site** is a row of `site`: `slug, name, domain (optional), status, settings_json,
  branding_json, address_json, parent_ref (a franchise may sit under a region), created_at`.
  An install always has at least one (the default site, created by the install seed, slug
  `main`), so single-location apps pay nothing and multi-location apps add rows.
- **The current site is resolved once per request** (`Sites::current()`), in this order and
  no other: the request's **host** when it matches a site's `domain`
  (`serenity-denver.tiknix.com` → Denver — the Lua router already sends every `*.tiknix.com`
  host to the install; the install maps host → site); else the **session switcher** (the
  sidebar's "Denver ▾", cannonwms's pattern); else the **member's default site**
  (`member.site_ref`); else the install's default site. A host that matches no site is not
  "main" — it is a 404 with the site list in the log, because a franchise link that quietly
  showed another franchise's data is the worst outcome.
- **Members belong to sites** (`membersite`: member, site, role — a manager of Denver is not a
  manager of Los Angeles), and a site-scoped controller checks it the way `TaskAccessControl`
  checks a team.
- **Bindings hang off sites.** `connectionbinding` and `channel` rows carry `site_ref`; the
  resolver (§4) reads the current site first. So `ConnectionBindings::for('payments')` in Denver
  is Denver's Stripe with no code knowing Denver exists, and a site with no binding of its
  own falls to the install-level binding only when the role says `inherit: true` (payments
  does not: money must never fall through to another franchise's account; mail may).
- **Data scoping is the concept's declaration**, not magic: a concept marks which of its
  beans are per-site (`scoped: ["shoporder", "product"]`), those beans get `site_ref` by
  seed, and `Bean::` reads through a site filter the concept opts into per query
  (`Sites::where('shoporder')`). Unscoped beans are shared (a catalogue can be shared across
  franchises while orders are not — Serenity's call per bean).
- **Two deployment shapes, one code path.** A franchise can be a site inside one install
  (rows), or its own instance (`serenity-denver.tiknix.com` provisioned as a project of its
  own, the way every project is today). The concept code is identical because it only ever
  asks for the current site's bindings; a site inside an install can later be **promoted** to
  an instance (export its scoped rows, its channels and its bindings; the domain moves with
  it) and an instance can be **adopted** as a site. Which shape a customer wants is about
  accounting, staff and isolation, not code, and the wizard asks it as a question.
- **The default site is invisible** in single-site installs: no switcher, no site column in
  lists, no host mapping — until a second site exists.

Mail, concretely — the roles the owner named: `mail: [microsoft, gmail, mailgun, klaviyo]`.
Mailgun is already in core as `lib/Mailer.php` reading `conf/mailgun.ini`; it becomes a
connector manifest (`api_key` + domain, `test_path` `/v3/domains/<domain>`) and `Mailer` binds
a `mail` role for core's own transactional mail in P6, config kept until then. Klaviyo comes
from srklr's controller as a manifest connector (public key / site id + private key, `test`
against `/api/accounts`) with the tools its verbs imply (profiles, events, lists, orders);
its `hook` is the webhook receiver. Both are published in P2 (§10).

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
    { "role": "mail",     "types": ["microsoft", "gmail", "mailgun", "klaviyo"], "label": "Sends and reads mail", "scope": "entity", "entity": "emailaccount" },
    { "role": "search",   "types": ["serpapi"],              "optional": true }
  ]
},
"adapters": {
  "payments": { "stripe": "PaymentsStripe" },
  "mail":     { "microsoft": "MailGraph", "gmail": "MailGmail", "mailgun": "MailMailgun", "klaviyo": "MailKlaviyo" }
}
```

`adapters` (§2a) names, per role and connector type, the class (relative to the concept's
namespace) implementing that role's `ChannelAdapter` interface; the lint refuses a manifest
that lists a type in `types` without an adapter for it.

- `scope`: `install` (default — one binding for the whole install) or `entity` (each row of
  `entity` bean carries its own binding; the install-level binding, if any, is the default
  for new rows).
- `optional`: the concept works without it and says what is missing where it matters.
- A bare string `"stripe"` keeps meaning `{role: "stripe", types: ["stripe"]}` so every
  published manifest stays valid; the lint warns and the next version names a role.

### 3.4 Bindings — a bean in the APP database (config, not secret)

`connectionbinding`: `concept, role, site_ref, scope ('install'|'entity'), entity_type, entity_ref,
connection_ref (id in connections.db), alias_snapshot, bound_by ('auto'|'member:<id>'), created_at`.
Unique on `(concept, role, site_ref, scope, entity_type, entity_ref)`; `site_ref = 0` is the
install-wide binding a site may inherit when the role allows (§2c). The `connection_ref` is a plain
pointer (`_ref`, not `_id` — it points into another database). `alias_snapshot` is only for
display when the connection is gone; resolution always re-reads the store.

Entity-scoped roles use the **channel** bean (§2a) as the entity unless the concept names its
own: `channel`: `concept, role, name, connection_ref, alias_snapshot, scope_type, scope_ref,
priority, settings_json, switches_json, cursors_json, status, last_sync, last_error, enabled,
created_at, updated_at` (§2b for scope and priority). A channel row IS the binding
for its role (no separate `connectionbinding` row); install-scoped roles use
`connectionbinding`. Cursors (`lastchecked` and friends) live on the channel, so two
Shopify stores advance independently and a re-bound connection keeps its place.

## 4. Resolution — `lib/ConnectionBindings.php` (new; one class, used by concepts, pipelines and the broker — not `Connections`, which is the hub controller's name)

```php
ConnectionBindings::for(string $concept, string $role, ?array $entity = null): OODBBean   // the connection bean (read-only)
ConnectionBindings::token(string $concept, string $role, ?array $entity = null): string   // decrypted secret, this install only
ConnectionBindings::candidates(string $concept, string $role): array                      // [{id, alias, type, environment}] for pickers
ConnectionBindings::bind(string $concept, string $role, int $connectionId, ?array $entity, string $by): void
ConnectionBindings::unbound(string $concept): array                                       // roles with no usable binding (for the Plugins page and verify())
```

Order in `for()`: the current site's entity binding → the current site's binding → the
install-wide binding *if the role inherits* (§2c; `payments` never does) → exactly one
candidate on this install (auto-bind, logged
`INFO Connections: auto-bound <concept>.<role> to <alias>`) → `UnboundRoleException`
("storefront needs a payments connection and this install has two Stripe connections
(Serenity main, EU store) — choose one under Plugins → Storefront → Payments") → no candidate:
`MissingConnectorException` ("…has no stripe connection; connect one under Connections → Stripe"
or, when the connector definition itself is absent, "…install the `serpapi` connector:
`clitool --connector-install=serpapi`"). A binding whose connection was deleted or disabled
resolves as unbound, with the alias snapshot in the message.

Where each consumer plugs in:

- **Concept code**: `ConnectionBindings::for('payments')` inside `app\concepts\storefront\…`; the
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
   connection: the broker-backed Stripe becomes a real connection row (`auth_type = broker`,
   alias "Serenity main") so it binds like any other (decision 3). Then the proving case for
   sites: two sites, **Los Angeles** and **Denver**, each with its own Stripe connection and
   its own `payments` binding; orders and tickets scoped per site, the catalogue shared;
   `serenity-denver.tiknix.com` mapped to Denver by host. Checkout in Denver charges Denver's
   Stripe; nothing in the storefront plugin names a site.
5. Every install gets its default site (`main`) by seed; `member.site_ref` defaults to it;
   scoped beans of enabled concepts get `site_ref = main` for existing rows.

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
  before `ConnectionBindings::for()` is layered on it.

## 10. Build order — every step shippable alone

- **P1 — sites, roles, aliases, bindings, resolver (core).** `site` bean + default site by
  seed + `Sites::current()` (host → switcher → member default → default site) + the sidebar
  switcher once a second site exists; `connections.alias` + migration; `connectionbinding`
  bean + seed (with `site_ref`); `Connections` class; `ConceptManifest` roles (bare strings
  kept) with `inherit`; `Concepts::verify()` unbound reporting; `--connectors` listing.
  Proving case — **Serenity**: the broker-backed Stripe becomes a connection row; `storefront`
  declares `payments` (no inherit); Los Angeles and Denver as sites with a Stripe each;
  Denver by host; checkout in Denver charges Denver's Stripe; a site with no Stripe of its
  own refuses checkout naming the Plugins page rather than charging another franchise.
  Tests above plus `SitesResolveTest` (host, switcher, member default, unknown host = 404).
- **P2 — connector catalog.** `tiknix-concepts/connectors/`, publish/install/update/lint,
  lock section, `--concept-install` resolution, `suggests`; the `adapters` map and the
  `ChannelAdapter` interface with its lint. Proving cases: **serpapi** published, `prospects`
  installed on a fresh project brings it, the hub offers Prospects after a SerpAPI connect;
  **mailgun** and **klaviyo** published as manifests (§2a), `outreach` declares
  `mail: [microsoft, mailgun, klaviyo]` with an adapter each.
- **P3 — UI and channels.** Hub alias/rename/used-by/refuse-delete; Plugins page bindings;
  the shared channel pages (`views/channels/`: list, wizard with the connection picker,
  switches, cursors) that any concept with an entity-scoped role gets; `outreach` moves
  `emailaccount` onto them (its own plan on the harvest fork); per-channel webhook route.
- **P4 — pipelines and the broker by binding.** `ConnectionStep` role/alias; MCP tool listing
  grouped by alias; `connection_id` validated per instance.
- **P5 — task workspaces share flagged connections.**
- **P6 — core's own consumers.** `github`, `anthropic`, `database` callers move to aliases where
  an install can hold several (GitHub already can); the rest stay.

Roughly: P1 two days, P2 one, P3 two, P4 one, P5 half, P6 as met. P1 and P2 are the critical
piece; everything after is surface.

## 10a. Status

- **P1 built and proved on Serenity (2026-09-28, core ab53e74; storefront 1.0.2 168ea66).**
  `lib/Sites.php` + seed 21 (default site `main` on every install), `lib/ConnectionBindings.php`
  + seed 22, aliases and candidates on `ConnectionStore`, roles in `ConceptManifest`,
  `StripeGateway::forConnection`, `clitool --sites/--connectors/--alias/--bind/--unbind`; 13
  tests. On Serenity: the platform-custody Stripe is connection #1 "Serenity main"
  (`auth_type = broker`), sites `la` and `denver` exist, `serenity-denver.tiknix.com` reaches
  the install and resolves to Denver; `storefront.payments` is bound for `main` and resolves
  to "Serenity main via broker"; on the Denver host the same call refuses: *"Storefront needs
  a payments connection for site 'denver' and none is bound to it. The install's only
  candidate, 'Serenity main', is not assumed to be Serenity Denver's — bind it under Plugins
  → Storefront → Takes payment if it is."* An unknown host names the sites that exist.
  Not yet: Denver's and LA's own Stripe connections (the owner's keys), per-site data scoping
  (§2c — orders are still one table), the sidebar switcher and Plugins-page bindings (P3),
  `ConnectionBindings` in pipelines and the broker (P4). The rule "a name that resolves to
  the hub controller" cost one rename: the resolver is `ConnectionBindings`, not `Connections`.
- **P1b — per-site data, per-site config, the status endpoint (2026-09-28, core 11eeb7a;
  storefront 1.0.3 c394a23).** The concept declares which of its beans are per-site
  (`"scoped": ["shoporder","shoporderitem"]`, checked ⊆ `provides.beans`); the runtime does
  the rest: `Sites::scopeBean()` adds `site_ref` + index and backfills existing rows to `main`
  (run after the concept's own seeds by `runSeeds`, so `--concept-update`/`--concept-seeds`
  report `per-site shoporder: added, backfilled:15`), `Sites::stamp()` on create (the
  `SiteScoped` model trait), `Sites::filter()`/`where()` on every admin read. Cron sweeps
  (stale holds) stay install-wide on purpose: cron has no host. **Every request resolves its
  site** in the base controller (`Sites::current()` from the host; `SiteNotFoundException` →
  a real 404 in `FlightMap`), then **`conf/sites/<slug>.ini`** is merged over the install's
  config for that request — only `[app] [brand] [mail] [features] [locale] [shop] [seo]`,
  credential keys refused by name, other sections refused; with no file a second site still
  gets its own `app.name`. The file is tracked (no secrets by rule), so publishing carries it.
  `/site/status` (public JSON: host, site, multi, applied config keys; roles per concept when
  logged in) and `/site/switch` (POST, member) exist. Proved on Serenity after the core roll:
  `serenity-bbdc01.tiknix.com/site/status` → `main`; `serenity-denver.tiknix.com/site/status`
  → `denver` with `["app.name","app.timezone"]` from `conf/sites/denver.ini`; by script the
  same code sees 15 orders / 3 customers on main and 0 / 0 on Denver. Two facts learned:
  nginx's dynamic host map only routes a host that has a symlinked instance dir, so a truly
  unknown host lands on core's vhost and never reaches the install (the 404 path is proved by
  test, not by curl); and Serenity's own `views/layouts/_site.php` hard-codes its title, so
  the overlay changes what `Flight::get('app.name')` answers, not that page — the app's code
  has to read config for a franchise to look different. Instance tests that exercise a scoped
  model must create a site first (`Sites::create('main', …)` in the test case); the storefront
  test base does.
- **P2 — connector catalog, first cut (2026-09-28): Mailgun and Klaviyo.** `tiknix-concepts/
  connectors/<key>.json` (catalog 4decf25), served by `/concepthub/connectors` and
  `/concepthub/connector?key=`; `ConceptCatalog::publishConnector / installConnector /
  updateConnector / resolveConnectors`; `ConnectorRegistry::lint()` (key = file name, semver,
  valid auth, no credential literal, no server path; publishing refuses on any error);
  `concepts.lock` gains a `connectors` section (version, source, file hash; absent until the
  first catalog install so existing locks stay byte-identical); `--connectors` now lists the
  DEFINITIONS (class / own manifest / catalog manifest + version, EDITED) above the
  connections; `--connector-lint/-publish/-install/-update`. **Dependency resolution**:
  `--concept-install` (and the build-based install plan) resolve every role type first — a
  class or a manifest in THAT project is satisfied, one the catalog holds is installed
  alongside and reported, one nowhere refuses the concept before a byte lands. Manifests
  learned three things Mailgun and Klaviyo needed: `auth.username` (basic with a fixed user,
  Mailgun's `api`), `auth.prefix` (`Authorization: Klaviyo-API-Key <key>`), fixed `headers`
  on every call (Klaviyo's `revision`), plus `fields` kept on the connection
  (`metadata.fields`, Mailgun's sending domain; `account_field` names which one is the
  account) and catalog fields `version`, `roles`, `docs_url`, `suggests`, `multiple` exposed
  by `meta()`. Core SHIPS both manifests in `connectors/` (the hub on core is where an
  instance's owner connects them) and publishes them from there; a self-hosted install takes
  them from the catalog. **Decision 9 done — core's mail is a binding**: `Mailer::settings()`
  resolves `ConnectionBindings::for('core', 'mail')` (`ConnectionBindings::CORE_ROLES`; an app's
  own roles ride on its root concept.json as 'root') and is the one answer `lib/Mailer`,
  `NotifyService` and `/webhook/mailgun` read; key, domain, region endpoint, from-address,
  inbound domain and the webhook signing key (the connection's webhook secret) all live on
  the connection; a site's `[mail] from_email` overlay wins over the connection's field;
  config.ini's `[mail]` block is never read. Seed `23_MailConnection` migrated
  `conf/mailgun.ini` into a connection once (core: #5 notify.tiknix.com; Mailgun accepted the
  migrated key on a read-only `/v3/domains` probe) and keeps whatever exists afterwards.
  Tests: `ConnectorCatalogTest` (lint, publish/install/update/lock, roles pulling manifests
  in or refusing), `MailSettingsTest` (none → fix named; one binds itself; site from wins;
  broken names the connection; the seed migrates once then keeps). Not yet in P2: serpapi
  published + `prospects` proving it on a fresh project; the hub's "this connection can
  power: …" offer (`suggests` is in `meta()`, the card does not show it yet — P3 with the
  bindings UI); `outreach` declaring `mail: [microsoft, mailgun, klaviyo]` with an adapter
  each (that is outreach's code, on the harvest fork); `type: "mcp"` connectors; the
  `adapters` map / `ChannelAdapter` lint.

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
8. **"Channel" is the customer-facing word** for an entity-scoped binding (§2a) — the term
   srklr, cannonwms and myctobot users already know; "binding" stays internal. (Proposed.)
9. **Core's own mail** (`lib/Mailer.php`, `conf/mailgun.ini`) moves onto a `mail` binding in
   P2, with the Mailgun manifest — the owner's call (2026-09-28): few enough clients that
   transactional mail may depend on the new machinery while it is built, and breakage there
   is acceptable. (Decided.)
10. **"Site" is the generic word** in core; a concept may present it as warehouse, location,
    franchise or client. (Proposed — the owner's examples were franchises and warehouses;
    one bean, many labels.)
11. **Payments never inherit.** A site with no `payments` binding refuses to take money rather
    than charging the install-wide account; `mail` and `search` inherit by default. Each role
    says which. (Chosen — accounting is the whole reason sites exist.)
12. **Host mapping lives on the site row**, and an unmatched host is a 404, never the default
    site. Instance-per-franchise stays available and a site can be promoted to one; the
    wizard asks which shape the customer wants. (Proposed.)

## 12. Not in this plan

Per-role permission scopes inside a connection (which tools a concept may call); marketplace
listing of connectors; OAuth apps of core's own (the "shared app" handoff) beyond what exists;
moving core's own consumers wholesale (P6 is opportunistic).

## 13. Every instance is a tenant — the installation vector (owner's call, 2026-09-28)

**The rule.** An instance is treated as if it were a self-hosted LXC tenant even when it sits
on core's disk: **the instance asks, then pulls, with its own identity, and applies with its
own user.** Core never reaches into an instance's tree. Core's only outbound acts are (a) the
credential landing — an OAuth exchange that had to start on core because the provider app
is core's — delivered to the instance's receive door, and (b) a "there is something to pull"
signal, with polling as the guarantee (the MQTT rule). One transport for code: **git, with
tags as versions.** A zip is an export, never an installation vector (§13.6).

**Why now.** The mail migration (P2) ran as `ubuntu` into Serenity's connection store and left
the pool user read-only on its own file — the third incident of the same shape (ACL mask,
2026-09-17; sqlite3 probe, 2026-09-25; this). Each was a write that only works because the
disk is shared. The guard (`ConnectionStore::assertOwnerMayWrite`, `IsolatedPool::runAsPool`)
closes the store; the shared-disk pushes below are the same defect waiting elsewhere.

### 13.1 Where each path stands

| Path | Initiates | Writes, where | Today |
|---|---|---|---|
| `--concept-install`, `--connector-install` on an instance | the instance, with its broker key (`/concepthub/*`) | the CLI user, into the instance tree | **pull** ✓ |
| LXC tenant bootstrap | the tenant, with its deploy token (`/git/<slug>.git`, read-only smart HTTP, `lib/GitHttp.php`) | the tenant's user | **pull** ✓ |
| Credential after OAuth | core → `/connectorapi/receive` with the instance's key (`lib/ConnectorPush.php`) | the instance's pool, its own store | **push over HTTP** ✓ (tenant-safe) |
| Plugins page **Install** on an instance | the instance asks (`requestInstall`), core queues a build **on core** (`queueInstall` → `scripts/concept-install.php` → plan-ingest) | ubuntu on core, into a worktree of the instance's clone, merged onto `instance/<slug>` | push over shared disk ✗ |
| Agent builds (plans, standalone tasks) | core's executor (`PlanOrchestrator` / `PlanExecutor`) | ubuntu on core, same worktree + merge | push over shared disk ✗ |
| Core upgrades (the rollout loop) | an operator on core (`upgrade-pre.sh` / `upgrade-post.sh`) | ubuntu merging `origin/main` into the live clone, then `--build`, `--agent-sync`, `resetcache` | push over shared disk ✗ |
| Seeds / migrations | whoever runs `--build` | ubuntu (seed 23 re-runs itself as the pool) | mixed |

Three pushes, one mechanism dressed three ways: ubuntu writes git history into the instance's
clone because it can reach the directory.

**C1 status (2026-09-28): built and proved on Serenity.** `lib/InstanceRepo.php`: the bare
origin `_origins/<slug>.git` (HEAD on `instance/<slug>`, `receive.denyCurrentBranch =
updateInstead`), its merge worktree `_origins/<slug>.merge`, the pool's ACL on both;
`createOrigin` (bare clone of the live tree, remotes re-pointed: `origin` → the bare repo,
the control plane kept as `core`), `addWorktree`/`removeWorktree` (a worktree's `.git`
pointer says which repository it belongs to — pre-C1 worktrees keep working), `merge` (in
the merge worktree, under a per-instance lock; a conflict aborts and names the files),
`syncLive` (fetch; fast-forward the live tree, push its own commits, or merge-and-push when
both moved; the modified runtime database never blocks it). `PlanExecutor` cuts from and
merges on the origin, then syncs the live tree; `GitService::addTaskWorktree` and the
workbench's merge-back do the same (a branch the origin lacks is fetched from the workspace);
`GitHttp` serves the origin; provisioning and forks create one; `jail-run.sh` binds the
repository the worktree's pointer names. `scripts/instance-origin.php --slug|--all` migrates
an instance once. Proved: a worktree on Serenity points at the origin, a proof commit merged
on the origin was absent from the live tree until `syncLive` pulled it, the git endpoint
answers a deploy-token fetch with the origin's branch tip, and the pool user reads and
fetches from the origin. The rollout scratch scripts now merge `core/main`. All 15
provisioned instances were migrated the same day. Two facts for C2: git refuses a repository
owned by another user ("dubious ownership"), and the pool user runs with HOME=/home/ubuntu
and no `safe.directory` — so the pool cannot run git in the live tree or the origin unless
each call names them (`InstanceRepo::git` does, per call, never `*`). A fetch over a LOCAL
PATH cannot carry that to the `upload-pack` child git spawns (it scrubs config from the
environment), so the pool cannot fetch the origin by path at all — `update` as the pool
fetches over HTTP (`/git/<slug>.git` with the deploy token: the tenant path, one transport)
and names the live tree for its local commands. Pushing live commits (checkpoints) from the
pool has no door (`GitHttp` is read-only by design): C2 decides whether checkpoints stay
local to the instance (a tag; the tracked runtime database stops travelling through the
origin) — the likely answer. And `Model_Instance::isProvisionedInstance` keyed on
"origin is the control plane" — after C1 the control plane is `core`; it accepts either now.

### 13.2 The four cutovers, in order

Each is shippable alone; each ends with core holding one less write path into instances.

**C1 — Builds and plugin installs land on the origin; the instance pulls.**
Core keeps a bare (or non-checked-out) copy of every instance repository — the thing
`GitHttp` already serves — and that is the ORIGIN. The executor's worktree is cut from the
origin, the finished task is committed and merged onto `instance/<slug>` **there**, never in
the live tree. The live tree becomes a plain clone whose only writer is its own `update`
(C2). On this box the pull is a local fetch over the same endpoint a tenant uses, so nothing
gets slower and there is exactly one code path. The Plugins-page install is then "a build
whose one task is an install", unchanged in shape, landing on the origin like any build.
- Changes: `PlanOrchestrator::launch` / `PlanExecutor` worktree base and merge target
  (origin, not the live clone); `Concepthub::install` unchanged (it queues the same build);
  a `repo_path` per instance row (bare origin) beside `dirFrom()`; `GitHttp` today serves
  the LIVE clone's `.git` (`lib/GitHttp.php:52`) and moves to the origin — a tenant then
  fetches what was merged on the origin, not whatever state the live tree happens to be in.
- Proof: a task merged on Serenity's origin is invisible on serenity-bbdc01.tiknix.com until
  `update` (C2) pulls it; the checkpoint/rollback story (`checkpointBeforeRun`) moves with it.
- Risk: sidecars (workbench.tiknix) that read the live tree keep working — they read, they
  do not merge. The `.aibuilder/wt/` worktree convention moves under the origin.

**C2 status (2026-09-28): built.** `lib/InstanceUpdate.php` + `clitool --update
[--release=vX.Y.Z] [--dry-run]` inside an instance; `--release[=vX.Y.Z] [--notes]` and
`--releases` on the control plane (the suite must be green; main; next patch by default;
tags are `vMAJOR.MINOR.PATCH`, the convention the repo already had). The update: builds from
the origin first (`syncLive`), fetch core's tags, target = named or newest, already-merged =
up-to-date, uncommitted CODE edits refuse (the runtime database is churn, not an edit), a
LOCAL checkpoint commit+tag `checkpoint-update-<ts>` (database included; never pushed —
**owner's decision: checkpoints stay local to the instance, especially in development /
the builder**, so `syncLive` no longer pushes anything: the origin holds code only), merge
the tag (conflict → abort, files named, exit 1, checkpoint kept), then concepts.lock sync,
composer only when composer.json changed (the control plane's lock when it is on this disk,
`composer update` on a tenant), `--build`, `--concept-seeds=all`, `--agent-sync`,
`resetcache`, `claude-link`, smoke `/` + `/auth/login` = 200, and `.release` pinned (read by
`/site/status`). Identity: the instance's user — on the control plane's disk the tree owner
for code while data writes already run as the pool (seed 23); one user on a tenant.
`/git/core.git` now serves the control plane's own repository to any active instance
(Basic auth: its slug + its deploy token) — a tenant's `core` remote. Not in C2: the
conflict landing on the board as a task (it is an ERROR in the log and a non-zero exit);
the fleet page reading `.release`; the pull ping. The rollout scratch scripts are retired.

**C2 — `update` is the instance's own command.** `php scripts/clitool.php --update`
(and the same code on a cron/fake-cron tick when a pull signal arrived): `git fetch origin`,
merge `origin/instance/<slug>` (builds) and the pinned core **tag** (releases; §13.3) into
the working tree, keep the identity files, run `--build`, `--concept-seeds=all`,
`--agent-sync`, `resetcache`, smoke `/` and `/auth/login`, report — i.e. today's
`upgrade-pre.sh` + `upgrade-post.sh`, moved to the other side of the door and run **as the
instance's user** (the pool, via `IsolatedPool::runAsPool`, or the tenant's own user on an
LXC). Conflicts stop the update and are reported on the board as a task, not resolved by an
operator's hand in the tree. Core's side of the loop shrinks to "tag a release, ping the
fleet, read the reports".
- Changes: `scripts/clitool.php --update`; a `release` tag scheme on core (`v2026.09.28`, or
  semver); an instance pin (`conf/release` or the lock file) so an instance updates to a
  version, not to whatever main is; a report row (`instance.last_update_at`, result) that the
  fleet page reads; the upgrade scratch scripts retired.
- Proof: Serenity updates itself from a tag with no operator write into its tree; the
  Denver host is still Denver afterwards; the fleet page shows the version per instance.

**C3 status (2026-09-28): built.** On an isolated instance `--build` and `--concept-seeds`
run the WHOLE seed set as the pool user through its php-fpm socket
(`IsolatedPool::runAsPoolBooted`: chdir, bootstrap, run the builder / the concept seeds, print
what they printed, exit non-zero on a FAILED/Fatal line) whenever the tree owner invokes
them — which is what `--update`'s post steps do — so every file a seed creates (a store, a
journal, a per-site column's rebuild) belongs to the pool. Seed 23's own re-dispatch is
gone: the mechanism is the command's, not one seed's. The store guard stays as the tripwire.

**C3 — Seeds and migrations run inside `update`, as the instance's user.** No seed writes
instance data as ubuntu: `--build` invoked by C2 already runs as the right user, and a seed
that must write the store on an isolated instance goes through `runAsPool` (seed 23 is the
shape). The guard from P2 stays as the tripwire. The mail migration, the per-site backfills
(`Sites::scopeBean`) and every future data migration are C3 by construction.
- Proof: the guard never fires during an `update`; `getfacl data/connections.db` is
  `mask::rwx` on every isolated instance after a fleet update (the survey in
  `acl-mask-chmod-trap`).

**C4 — Core needs no write access to instance trees at all.** With C1–C3 done, core's
processes read instance trees (sidecars, the fleet page, `GitHttp`) and never write. Then the
POSIX ACL that today grants the pool `rwx` on the WHOLE tree (Serenity: `lib/`, `concepts/`,
`connectors/` all writable by the pool — checked 2026-09-28) is narrowed to what the app
must write: `data/`, `secure/`, `log/`, `public/uploads/`, `state/`, `.aibuilder/`. Code is
read-only to the app that runs it, which is what isolation was for. **Decision 13** (below)
says whether to narrow; C4 is when it is safe to.
- Proof: a pool request that tries to write `lib/` fails; every page and pipeline still
  works; `update` (the instance's user) still writes code.

### 13.3 Core releases as tags

Core's `main` stops being what instances receive. A **release** is a tag on core; an instance
pins one and `update` moves it to the next. What an instance carries besides core — its own
app code on `instance/<slug>`, its plugins under `concepts/` (copies, per COMPONENTS_PLAN),
its manifests under `connectors/`, its lock — merges with the tag exactly as it merges with
main today, so the known hand-merge cases (partsdna `Settings.php`, Mailer on serenity…)
become C2 conflict reports instead of operator afternoons. Plugins keep their own versions
in the catalog; a release may bump the minimum plugin versions it expects and `update` says
which plugins need `--concept-update` before it will proceed.

### 13.4 Credentials and OAuth on a tenant's own domain

Already correct in shape (`controls/Connections.php::instanceconnect`): a connection made
with the customer's **own** provider app runs the whole OAuth locally on the instance's
domain — authorize, callback, seal — and core is not involved. A connection made with
**tiknix's shared** app must call back to tiknix.com, because that is the URI registered on
the app and the secret behind it is ours: core is the landing pad and pushes the credential
to the instance's receive door (HTTP, the instance's key). Both work off-box today.
Consequences for the tenant model:
- **Ejecting a connection means re-registering, not copying.** Leaving development = create
  your own app per provider and reconnect; the shared-app token stays valid until then.
- **A manifest says which model it needs:** `requires_own_app: true` for providers whose
  callback must be the app owner's (Shopify, Stripe Connect); plain OAuth (GitHub, Google)
  works either way; API-key connectors (Mailgun, Klaviyo) have no redirect and are
  domain-free from day one. Surfaced on the card and in `--connectors`.
- **Sites** do not change this: with the shared app every site's connect lands on tiknix.com
  and is pushed to the one install, then bound per site (§2c); with an own app the franchise
  registers its own domain as the callback.
- **The scrub must hold across updates:** `conf/<connector>.ini` is emptied at provision so a
  project never holds tiknix's shared secret; `conf/*.ini` is gitignored (only
  `*.example.ini` is tracked), so a tag pulled by C2 cannot reintroduce one. C2's proof
  includes `test -s conf/github.ini` (and each provider ini) being empty on a tenant after
  an update.

### 13.5 What stays on core, by design

The catalog (served, versioned), the origins and the git endpoint, the broker (tokens for
shared-app connections, the receive push), the fleet page, billing, the planner/executor
(it runs on core and lands on the origin), the shared OAuth apps, the release tags, the ping.

### 13.6 Decisions this section adds

13. **Narrow the pool's ACL to data directories** once C4 is reached (proposed: yes — an app
    that can rewrite its own code is not isolated). Until then the wide ACL stays; the store
    guard covers the case that actually bit.
14. **Git with tags is the one transport**; a zip/tar is an export of a tag (self-hosting
    hand-off), never something an endpoint unpacks. No "receive an archive" door is built.
15. **The instance user runs every write into the instance** — the pool (via `runAsPool`) on
    core's disk, the tenant's user on an LXC. ubuntu on core reads instance trees and never
    writes them after C1–C3. The rollout scratch scripts are retired at C2.
16. **`requires_own_app` on connector manifests** (13.4), set for Shopify and Stripe Connect.

### 13.7 Order against the rest of the plan

C1 and C2 before P3's bindings UI, so nothing new is built on the push paths; C3 is P2's
guard made total and can ride with C2; C4 after a fleet update proves C1–C3. P4 (pipelines
and the broker by binding) is unaffected. Roughly: C1 one day, C2 one day, C3 half, C4 half
plus a fleet pass.
