# start.tiknix: learn the business, plan a bespoke system, build it

**Status: plan for review; Track A has started — `pdf` 1.0.0 (§5.4a), `images` 1.0.0
(§5.4b) and `locale` 1.0.0 (§5.4c) are built, published and adopted by their source
projects; `requires.commands` and `requires.extensions` are in core. Nothing else is built.** Written 2026-09-24 from a survey of core, the
workbench sidecar, PartsDNA (`partsdna-74a225`), Serenity (`serenity-bbdc01`) and the concept
catalog in depth, and of what CollectIQ, Invoza, El Salón (`bookingscheduler`) and lead-machine
each built beyond core. Decisions to confirm are collected under "Decisions to confirm"; nothing below is
settled until they are.

**start.tiknix.com** is where a new customer goes when they press **Get started**. It does not
ask "what kind of app do you want?" first. It learns **their business** — what they sell, to
whom, how money comes in, what they use today, what hurts, and what the end goal is — and
from that recommends a **bespoke system** assembled from **blueprints**: modules such as
*events with tickets*, *a client portal*, *a Shopify app*, each a guided set of questions
tied to tiknix primitives. The answers become a **`PLAN.md`** (what to REUSE from core, which
concept to ADOPT from the catalog, what is genuinely NEW), committed into a freshly provisioned
project and fed, one phase at a time, to the build pipeline that already exists. The result
is their own real application, not a template and not a demo.

It lives in its own **sidecar**, `start.tiknix`, like the task board.

Underneath it, and just as important, the plan **splits the platform's proven pieces into
reusable components** (§5) — **versioned plugins** that sit beside core without changing it,
on the plugin (concepts) runtime that already exists — so that *every* tiknix task, not only start.tiknix, builds on tested parts
instead of rebuilding them. The blueprints are recipes over those components.

---

## 0. The whole thing in one paragraph

A customer presses **Get started** and is interviewed about their business, not about
software: what they do, who pays them and how, where they lose time, and what the business
should look like in a year. Anything they already have — a business plan, a price list, a
spreadsheet — can be uploaded; Serenity Gemstones started from exactly that. start.tiknix
maps what it heard onto modules ("you run classes and sell stock: *events with tickets* +
*an online shop*; your clients ask for updates by email: *a client portal*"), the customer
confirms or adjusts the mix, and each chosen module asks its own questions, each tied to a
primitive: *who signs in* → levels and
roles; *do people pay* → the Stripe connector and the storefront; *which Shopify data* →
scopes and sync pipelines. start.tiknix renders the answers, deterministically, into one
PLAN.md for the whole system — app summary, roles, data model with column types, pages with permission levels, a
primitives table, connections, pipelines, phases and acceptance checks — and shows it for
review. On **Build it**, the sidecar provisions a project, commits PLAN.md into it, and starts
Phase 1 through the workbench's existing decompose flow. The member lands on the task board
watching their app get built, and PLAN.md stays the contract every later phase is planned
from.

---

## 1. What already exists (the recon)

The good news: almost every moving part exists. Blueprints are a front door and a contract,
not a new build engine.

### 1.1 The hand-off already works

- **Goal → plan → tasks.** `POST /workbench/decompose` (workbench `controls/Workbench.php:674`)
  takes a markdown goal (`description`, ≥ 20 chars) for the selected project, checks the
  member is signed in to an engine (`:726`), and starts `PlanRunner::start()` (core
  `lib/PlanRunner.php:192`) — queued via `PromptQueue` when the project is busy. The planner
  classifies every capability **REUSE → EXTEND → ADOPT → NEW** against the project's own
  inventory (`reuse_digest`) and the concept catalog (`concepts_search`), submits tasks with
  `submit_plan`, and `PlanIngestor` builds the task tree.
- **Run it straight through.** `auto_build` (`Workbench.php:584`) approves and builds without
  a click; `PlanExecutor` runs subtasks in worktrees, three at a time; `AuditRunner` drives
  the result as ROOT / ADMIN / MEMBER afterwards.
- **Phases.** The board's "Goal → phases" card and **Continue to next phase**
  (`Workbench.php:600`) re-plan from the saved goal.

### 1.2 Provisioning already works

`ProvisionService::create()` (core `lib/ProvisionService.php:203`) mints `{base}-{6hex}`,
runs `capricorn/bin/provision-instance.sh` (a full clone on `instance/<slug>`, own vendor,
config, SQLite DB with the member as ROOT, generated CLAUDE.md, a `checkpoint-baseline` tag),
and registers the `instance` bean. Sidecars reach it through the signed server-to-server
route `controls/Provision.php:22` (`op: create`). "Can take a minute."

### 1.3 The sidecar platform already works

The Sidecar Kit (`vendor/tiknix/sidecar-kit`: `Kernel`, `Sso`, `Registry`, `Token`) plus core
`controls/Sidecar.php` (feature check, signed SSO, `?to=` deep links, iframe with core nav).
A new sidecar is a directory `<name>.tiknix/` (the nginx Lua router serves it with no vhost),
a `[sidecar.<name>]` section in core's config, and a `Feature::CATALOG` entry.
`insights.tiknix` is the smallest template to copy.

### 1.4 The primitives a blueprint can build on

| Area | Primitive | Where |
|---|---|---|
| People | auth, register, Google OAuth, 2FA, levels, profiles, close account | `controls/Auth.php`, `lib/TwoFactorAuth.php`, `models/Model_Member.php` |
| | teams, roles, invites (email-bound, quotas) | `controls/Teams.php`, `models/Model_Team.php`, `lib/Invite.php` |
| Access | route permissions, seeded safely | `lib/PermissionCache.php` (`seedRule`) |
| Generation | model / controller / views / API from a bean | `clitool --scaffold` (`lib/Scaffold/`) |
| Features | concepts: installable, per-install, with slots, seeds, pipelines, guidance | `lib/Concepts.php`, `COMPONENTS_PLAN.md` |
| Integrations | Stripe, Shopify, QuickBooks, Instagram, Telegram, Monday, database, REST; manifest connectors (GitHub, HubSpot, …) | `services/connectors/`, `connectors/*.json` |
| | per-instance credential custody | `lib/ConnectionStore.php` |
| Automation | pipelines (cron / webhook / HTTP / MCP tool), 15 step types, durable objects, `pk_` keys | `lib/Pipeline/`, `pipelines/*.json` |
| Talking to people | threads, rooms, DMs, notes (in-app + email), Mailgun, public token links | `models/Model_Thread.php`, `lib/Notes.php`, `services/NotifyService.php`, `lib/PublicLink.php` |
| | contact/lead forms, spam checks, Turnstile, rate limits | `controls/Contact.php`, `controls/Leads.php`, `lib/Turnstile.php`, `lib/RateLimiter.php` |
| | live delivery | `lib/Mqtt.php` |
| Shipping it | publish drivers (tiknix-hosted, github-pr, rsync, ssh) | `lib/Publish/` |
| Operating it | error firehose, logs, observation MCP tools | `controls/Firehose.php`, `mcptools/` |
| Money | Stripe checkout, subscriptions, billing portal (connector methods) | `StripeConnector`, `lib/StripeGateway.php` |

**Concept catalog today** (`/var/www/html/default/tiknix-concepts/`): `calendar` 1.0.1,
`pipedemo` 1.0.0, `shopifysync` 1.1.0, `pdf` 1.0.0, `images` 1.0.0 and `locale` 1.0.0 (the
proving cases below, 2026-09-25). **Designed, not extracted:** `storefront`, offer types
`physical` / `digital` / `class` / `session`, `tickets`, `availability`, `profiles`,
`vendors`, and the `events` bundle (`COMPONENTS_PLAN.md` "Build order" steps 3–5).

### 1.5 What the proving apps contribute

**PartsDNA (Shopify app)** — mostly generic plumbing, liftable:

- `lib/ShopifyEmbed.php` (581 lines): store-scoped request signature and App Bridge
  session-token verification, provisioned-shop allowlist, frame headers. Lift as-is.
- Embed-aware session handling in `bootstrap.php` (`SameSite=None`, partitioned
  `_EMBED` cookie), `lib/FlightMap.php` (session from the Shopify signature before the
  permission check), `Control.php` (frame headers, `embedded` layout,
  `refuseIfStoreBound()`). Belongs in core.
- `lib/StoreDb.php`: one SQLite database per store; pattern generic, schema parts-specific
  (needs a schema hook).
- Store accounts (`storeadd` / `storetoggle` / `shops`), session-token auth in
  `services/ApiAuthService.php`, `lib/MemberApiKey.php`.
- Pipelines: products/variants and content sync, the `-all` fan-out, store resolver
  `scripts/parts-store-resolve.php`, `store` / `stamp` on `UpsertStep` / `DbQueryStep`.
- Three-state embed shell (loading / denied / main) and the App Proxy Liquid skeleton.
- Parts-specific, stays behind: `PartFinder`, `PartsRest`, fitment, `parts-publish`,
  synonyms.

**Serenity (events with tickets)** — generic core, branded surface:

- Events are `product` rows with `offer_type='class'` (start, end, format, location,
  capacity, seats left, teacher); rules in `Model_Product`. Series = copied drafts
  (weekly / biweekly / monthly). Appointments = `offer_type='session'` +
  `sessionavailability` + `lib/SessionSlots.php`.
- Tickets: `lib/ClassTickets.php` (one per seat, idempotent), `ticket` + `ticketcheckin`,
  QR via BaconQrCode (`lib/TicketQr.php`), a hand-rolled PDF (`lib/TicketPdf.php`), emails
  with PDF + ICS, door check-in with camera scanning (`Events::checkin`, BarcodeDetector →
  jsQR), multi-day check-in, comp/door issue.
- Shop: session cart, atomic seat holds with a 60-minute release, Stripe Checkout via
  `StripeGateway::forEnv()`; orders with a status machine and manual (cash) sales.
- Teachers with public bio pages; public calendar, ICS feed.

### 1.6 Gaps the survey found (they become Phase 0 / blueprint prerequisites)

Shopify (PartsDNA):

1. **No Shopify webhooks** — no `app/uninstalled`, none of the mandatory GDPR webhooks
   (`customers/data_request`, `customers/redact`, `shop/redact`).
2. **Install is operator-driven** — someone connects the custom app at `/connections` and
   adds the store by hand; no merchant-started install.
3. **Write scope missing** by default (`metafieldsSet` needs `write_products`).
4. **`shopify-orders.json` is single-store** — a hardcoded shop, writes to the instance DB.
5. **`secure/connections.key` is tracked in PartsDNA's git** (committed by an upgrade
   checkpoint on 2026-08-04, before `secure/` was ignored). It has not left the machine —
   the only remote is the local core clone, which does not contain the commit — but it must
   be untracked before anything is templated from that tree.
6. `tk_` API keys stored in plaintext; access is per store, not per staff member.

Events (Serenity):

1. **Payment is never confirmed automatically** — no Stripe webhook or session retrieve;
   tickets wait for an admin to mark the order paid.
2. One attendee name per order, not per seat; no ticket tiers or discount codes; no
   refund/void UI; no buyer "my tickets" area.
3. Series are unlinked copies; branding hardcoded in ~21 files; USD and US timezones
   hardcoded; `event_timezone` missing from config (ICS files float).
4. `EventCalendar` duplicates the `calendar` concept.

The pipeline itself:

1. **`.aibuilder/plan-goal.md` is overwritten by every decompose.** Serenity's "business
   plan" slot now holds a one-line request for a user manual. A PLAN.md fed through the same
   slot would be lost the first time the member asks for anything else.
2. **Every instance already has a `PLAN.md`** — core's original Workbench plan, inherited by
   every clone. A generated `PLAN.md` would collide with it on every upgrade merge.
3. **Three gates a first-time member hits:** the one-free-project cap
   (`ProjectQuota::FREE_CAP = 1`), the `workbench` feature (auto-granted only to invited
   members, `Auth.php:290`), and the engine sign-in check at decompose (redirects to
   `/aibuilder` to run `/login` or add a key).
4. `Provision::secret()` trusts only the workbench's shared secret (`Provision.php:62`).

---

## 2. The shape of it

```
 core (tiknix.com)                 start.tiknix (sidecar)                   the new project
 ─────────────────                 ──────────────────────                   ───────────────
 Get started ──SSO──────────────▶  Discovery: the business, the end goal
                                   (+ uploads: business plan, price list…)
                                   Qualified concept brief → customer agrees
                                   Recommended modules → customer confirms
                                   Foundation questions
                                   Each module's questions  ◀── catalog check (concepts_search)
                                   Connections checklist ──deep link──▶ /connections
                                   PLAN.md preview + edit
                                   Build it ──signed op:create──▶ ProvisionService
                                                                 ───────────────▶  <slug>.tiknix
                                   commit PLAN.md + blueprint.json ─────────────▶  PLAN.md
                                   start Phase 1 ──decompose(auto_build)────────▶  task board
 task board (workbench) ◀─deep link─ "Watch it build"
```

Four pieces, each with one job:

0. **Discovery and qualification** (sidecar): learn the business and the end goal, play it
   back as a **qualified concept brief** the customer agrees to, and recommend a mix of
   modules (§4.0).
1. **The interview** (sidecar): each chosen module's questions, tied to primitives, answers
   saved as you go, resumable.
2. **The contract** (PLAN.md): rendered from answers by a deterministic template engine,
   reviewable and editable before anything is built, committed into the project.
3. **The hand-off** (sidecar → core → workbench): provision, commit, decompose Phase 1,
   deep-link to the board. Every later phase is planned from the same PLAN.md.

---

## 3. The sidecar: `start.tiknix`

Copied from `insights.tiknix` (Index + Sso + one app controller), on the Sidecar Kit, using
core's autoloader like every other sidecar.

| Piece | What |
|---|---|
| `controls/Start.php` | discovery, recommendation, module picker, step, save, preview, edit, build, status |
| `lib/Discovery.php` | the business interview; maps answers to recommended modules (§4.0) |
| `lib/BlueprintRepo.php` | loads and validates `blueprints/*/blueprint.json`; refuses a malformed one loudly |
| `lib/Interview.php` | step machine: next visible question given answers (`when` conditions), validation per type |
| `lib/PlanRenderer.php` | answers + section templates → PLAN.md (deterministic; no model) |
| `lib/PrimitiveCheck.php` | resolves every primitive a blueprint needs against the live platform (§6) |
| `lib/Handoff.php` | provision → commit → decompose → deep link, each step recorded and retryable |
| `blueprints/<id>/` | `blueprint.json`, `sections/*.md`, `preview.png`, `story` link |
| `data/blueprints.db` | `interview` and `handoffstep` beans (below) |

**Core changes** (small, each its own commit):

- `[sidecar.start]` in config and `start` in `Feature::CATALOG` (MEMBER, auto-granted to
  every member — Get started must never be feature-gated away). Served at
  `start.tiknix.com` by the existing Lua router; no vhost.
- `Provision::secret()` accepts the start sidecar's secret for `op: create` only.
- A **Get started** entry point: the landing hero, `/stories` ("Build something like this"
  per story → that story's blueprint), the empty-state left nav ("Build → New Project"
  becomes "Get started"), and `/projects` when the member has none.

**Data** (sidecar DB, never core's):

- `interview`: `member_ref`, `discovery_json` (TEXT: the business answers),
  `modules_json` (TEXT: chosen blueprints + versions), `answers_json` (TEXT),
  `step`, `status` (`draft` / `reviewed` / `handing_off` / `handed_off` / `failed`),
  `plan_md` (TEXT, the reviewed text), `plan_sha`, `instance_slug`, `created_at` /
  `updated_at` (sized TEXT — the widen trap). One open draft per member; resumable from
  Get started.
- `handoffstep`: `interview_ref`, `step` (`provision` / `commit` / `decompose`), `status`,
  `detail`, `at`. Makes a half-finished hand-off visible and retryable instead of silently
  stuck.

Answers never contain credentials. Anything secret (Stripe keys, a Shopify custom app) is
connected in core's `/connections` for the new project, by deep link, after provisioning.

---

## 4. The blueprint format

A blueprint is data plus markdown fragments, versioned in the sidecar repo — reviewable in a
diff, testable without a browser.

```json
{
  "id": "events-tickets",
  "version": "1.0.0",
  "title": "Events with tickets",
  "pitch": "Sell seats to classes and events, email QR tickets, check people in at the door.",
  "story": "serenity-bbdc01",
  "requires": {
    "concepts": ["storefront", "class", "tickets", "calendar", "profiles"],
    "connectors": ["stripe"],
    "core": ["auth", "mailer", "permissions"]
  },
  "steps": [
    {"id": "kinds", "title": "Your events", "questions": [
      {"id": "event_kinds", "type": "multi", "prompt": "What will people book?",
       "options": [
         {"value": "one_off",  "label": "One-off events",       "primitives": ["concept/class"]},
         {"value": "series",   "label": "Recurring classes",    "primitives": ["concept/class"]},
         {"value": "multiday", "label": "Multi-day workshops",  "primitives": ["concept/class", "concept/tickets"]},
         {"value": "sessions", "label": "1:1 appointments",     "primitives": ["concept/session", "concept/availability"]}
       ], "required": true},
      {"id": "capacity", "type": "number", "prompt": "Typical seats per event", "min": 1,
       "when": "event_kinds has one_off or event_kinds has series"}
    ]}
  ],
  "sections": ["summary", "roles", "data-model", "pages", "primitives", "connections",
               "pipelines", "phases", "acceptance", "out-of-scope"]
}
```

**Question types:** `text`, `longtext`, `choice`, `multi`, `number`, `money`, `bool`,
`list` (repeatable rows, e.g. ticket tiers: name + price + seats), `entity` (a small data
model builder: "what do you keep track of?" → a bean with typed fields, the same field types
the scaffolder knows: text, email, enum, date, datetime, number, url, checkbox, json),
`brand` (name, logo upload, colour), and `connection` (a check, not a secret: "is Stripe
connected to this project yet?").

**Rules:**

- Every option and question may name `primitives`; the renderer uses them to fill the
  primitives table and the plan's REUSE / ADOPT / NEW lines. A question with no primitive is
  a smell: the blueprint is asking for something the platform cannot help with.
- `when` is a small, closed expression language (`has`, `is`, `and`, `or`, `not`, numeric
  comparisons) — evaluated by a parser, never `eval`.
- Text answers are length-capped and appear in PLAN.md inside a fenced block labelled as the
  member's own words, so the planner reads them as requirements, not instructions.

### 4.0 Discovery: learn the business first

The first screens are about the customer's business, in their words, and they are the same
for everyone:

| Question | Why it matters |
|---|---|
| What does your business do, in a sentence or two? | the app summary; the planner's context |
| Who are your customers, and how do they find you? | public pages, sign-up policy, lead forms |
| How does money come in — products, bookings, subscriptions, invoices, commissions? | storefront / offer types, Stripe, subscriptions, QuickBooks |
| What do you use today (Shopify, spreadsheets, a booking tool, email, paper)? | connectors to import from or sync with; what the new system replaces |
| Where do you lose the most time, or money? | Phase 1 priority |
| A year from now, what does the business look like with this working? (the end goal) | the plan's north star and the done-when of the last phase |
| Anything to upload — a business plan, a price list, a menu, a spreadsheet, a site you like? | stored in the project's `secure/uploads/`, referenced from PLAN.md |

**Recommending modules.** Discovery answers are mapped to modules in two passes:

1. **Rules first** (deterministic, in each blueprint's `signals`): e.g. money = *bookings* →
   `events-tickets` or `appointments`; uses *Shopify* → `shopify-app`; *clients ask for
   updates* → `client-portal`. Testable, free, always available.
2. **Then, optionally, the customer's AI engine** reads the discovery answers and the
   uploads against the list of available modules and primitives, and proposes a mix with
   one line of reasoning per module. It may only recommend modules that exist and resolve
   (§6) — never invent one; anything it thinks is missing goes in the plan as a NEW line
   with the reason.

The customer sees the recommendation as cards ("Events with tickets — because you run
classes and workshops"), can add or remove modules, and can choose **none of these fit** —
which produces a **bespoke-only** PLAN.md: Foundation + discovery + the primitives table,
with the planner deciding the rest at decompose time. Serenity's route (a business plan in,
a bespoke SaaS out) is that path with the upload doing most of the talking.

**The qualified concept — the wizard's first real output.** Before any module question,
start.tiknix plays back what it understood as a one-page **concept brief**, in plain business
language, and the customer must agree with it before going further:

- *What your software will do* — 3–6 outcomes in their terms ("customers book and pay for a
  class online and get a ticket by email"), each traced to a discovery answer.
- *Who uses it* — the customer's customers, their staff, them.
- *What it replaces or connects to* — their current tools.
- *How well tiknix covers it* — each outcome marked **ready** (core or a catalog concept
  covers it), **adapt** (close match, will be tailored) or **new** (built from scratch), so
  the size and risk are honest before anything is promised.
- *Not included* — what was heard but left out, and why.

The brief is where the customer corrects course cheaply ("no, bookings are by phone — I only
need the ticketing"). It is saved with the interview, becomes section 1–2 of PLAN.md, and is
the thing a person on the tiknix side can read to qualify a lead that stops here: a customer
who never finishes still leaves a clear, qualified description of what they wanted built.

Modules combine: the Foundation is asked once, shared entities are declared once (a
`customer` used by both the shop and the portal), and phases from different modules are
interleaved by the renderer into one sequence (§7.2).

### 4.1 Foundation: the questions every blueprint shares

Asked first, whatever the blueprint, because every app needs them and they map to core:

| Question | Primitive |
|---|---|
| App name, one-line description, logo, colour | `[app] name`, `Flight::siteName()` / `siteLogo()` branding |
| Who uses it — the public, customers with accounts, staff, admins? | levels (PUBLIC / MEMBER / ADMIN / ROOT), `authcontrol` via `seedRule` |
| How do people get an account — open sign-up, invite only, admin-created? | `Auth::register` + Turnstile, `lib/Invite.php`, `clitool --adduser` |
| Will a team work on it with you? | Teams (a paid-project perk — say so honestly) |
| Should the app email people? | Mailgun / `lib/Notes.php` / `NotifyService` |
| Do you need a contact or enquiry form? | `controls/Contact.php`, leads, Turnstile |
| Anything you have already — a business plan, a spreadsheet, a site you like? | upload → `secure/uploads/`, referenced from PLAN.md (the Serenity path) |

---

## 5. Components: split the primitives out, for every task

start.tiknix is the most visible consumer, but not the point. The point is a **component
library** that *any* tiknix task can build on: a member typing "add invoicing" on their task
board should get the planner ADOPTing `concept/invoicing` — tested, generic, already live on
Invoza — instead of an agent writing its fifth invoice model from scratch. Blueprints (§9)
are then thin **recipes** over components, and a component shipped for one blueprint
immediately helps every project.

This is `COMPONENTS_PLAN.md`'s machinery — concepts, which are plugins (§5.2), the catalog,
ADOPT, `concepts_search` — which exists. What this section adds is what the runtime still
needs to carry shared, versioned components (§5.3), and the **extraction backlog**: what to pull out of the
live projects, in what order, and where each piece belongs. `COMPONENTS_PLAN.md` stays the
owner of how a concept works; this section is the list of what to make into one.

### 5.1 The evidence: projects keep rebuilding the same things

Surveyed 2026-09-24 across the six live projects (files each added beyond core):

| Built more than once | Where | What it should be |
|---|---|---|
| PDF rendering | Serenity `lib/TicketPdf.php` (hand-written PDF), Invoza `services/InvoicePdf.php` (headless Chrome) | a `pdf` plugin |
| Image normalisation | `lib/ImageProcessor.php` in both Serenity and CollectIQ | an `images` plugin |
| Stripe client | core `lib/StripeGateway.php`; Invoza `services/StripeDirect.php` (PaymentIntents, because broker checkout only takes Price IDs) | core's gateway, extended with PaymentIntents; webhooks through a core seam |
| Lead capture | core `controls/Leads.php`; El Salón `lib/LeadCapture.php` | a `leadcapture` plugin over core's `lead` |
| Pipeline agent credentials | core agent step credentials; lead-machine `AgentCredential`, `ClaudeCodeCredential`, `SerpApiCredential` | core (the project-owned agent credentials built 2026-09-24); retire the copies |
| Public no-login links | core `lib/PublicLink.php`; Invoza `invoice.publicToken`; lead-machine non-expiring unsubscribe tokens; Serenity ticket codes | core `PublicLink`, extended with a purpose and optional expiry |
| API keys | core `controls/Apikeys.php`; PartsDNA `lib/MemberApiKey.php` (scoped, store-bound) | core keys, extended with scopes |
| AI vision | CollectIQ `AiProviderRegistry` + Gemini / Groq gateways; core model connections | a vision capability on model connections |

And two of the reasons were the agents' working conditions, not their judgement: Invoza's
PDF goes through headless Chrome because "vendor/ is read-only … no PDF library", and
CollectIQ put `CurrencyService` in `services/` "because of the write restriction on lib/".
Agents inside a project cannot add a dependency or touch shared code, so they hand-roll. A
vetted component is the fix: the capability arrives already built, from outside the sandbox.

### 5.2 Components are plugins

A component **is a plugin**, built on the concepts runtime `COMPONENTS_PLAN.md` already laid
down (the admin page is already called Plugins). That runtime was designed for exactly this:

- **It sits beside core, never inside it.** A plugin is a flat directory
  `concepts/<name>/` in the project, namespaced `app\concepts\<name>\`, switched on per
  install. Core's own files are untouched, so a core upgrade merges into a project without
  meeting plugin code, and disabling a plugin returns the project to baseline.
- **The manifest is the registration, and it is pure data.** `concept.json` names what the
  plugin provides (controllers, routes, slots, collect points, pipelines, beans it extends,
  MCP tools) and requires; loading it executes nothing, and its callable references have
  no syntax for leaving the plugin's namespace (`lib/ConceptManifest.php`).
- **It is versioned and published.** Every manifest has a `version`; the catalog refuses to
  republish a version with different contents; install resolves `requires.concepts` from
  the catalog in dependency order (`lib/ConceptCatalog.php`).
- **It is verified before it is switched on.** `Concepts::verify()` checks every named class,
  view and requirement; enable runs its seeds and recomposes CLAUDE.md from its
  `guidelines.md`.
- **A plugin can be a library alone.** `calendar` is `kind: capability` with no database and
  no pages — so a PDF renderer or an image normaliser is a plugin too, not a core addition.

So the rule for where a piece goes becomes:

1. **A plugin**, by default — features (`tickets`, `invoicing`) and capabilities (`pdf`,
   `images`) alike.
2. **A connector** when it is a third-party service with credentials (they already have
   their own registry, manifests and `/connections`; a plugin may ship connector
   manifests).
3. **Core only as a seam** — a small, stable extension point that plugins attach to, never
   the feature itself. A seam is justified only when a plugin cannot do the job from
   outside (it would have to patch a core file). Existing core primitives may still be
   *extended additively* where they already live (e.g. `StripeGateway`, `PublicLink`).

### 5.3 What the plugin runtime needs before it can carry components

Checked against the code 2026-09-25. The runtime exists; these are the pieces missing for
versioned components that many projects share and update:

1. **Version ranges.** `requires.concepts` is a list of bare names today. It becomes a map
   with ranges — `"requires": {"concepts": {"storefront": "^1.2", "customers": "^1.0"}}` —
   checked at install, enable and update.
2. **A core compatibility line.** Core gets a plugin API version (`Concepts::API`, bumped
   when a seam or a `requires.lib` class changes shape), and a manifest declares
   `"requires": {"core": "^1"}`. `verify()` refuses a plugin built for an incompatible core,
   naming both versions — so a core upgrade can never silently break an installed plugin.
3. ~~**A lock file.**~~ **Built 2026-09-25** (§5.4d): `concepts.lock` in each project —
   name, version, source, enabled, and a hash of the installed files — is THE record of
   plugin state; the settings-table flag is gone. It makes three things visible: what is
   installed, whether someone **edited** it in place, and (next) whether it is behind the
   catalog.
4. **Extend, don't edit.** The update story hangs on this. A project adapts a plugin through
   what the plugin exposes — slots, collect points, config, its own subclassing points, and
   the project's own code around it — never by editing files under `concepts/<name>/`. Then
   an update is a clean replace of the directory. The lock file's hash catches an edited
   plugin, and updating one becomes a build task (merge upstream into the adapted copy)
   instead of a silent overwrite. This answers `COMPONENTS_PLAN.md`'s open question
   "Applying an upstream update". Agents are told the rule in the runtime's guidelines, and
   the validation hook warns on writes under `concepts/`.
5. **Updates.** An "update available" notice (catalog version > lock version, within the
   declared range), and an update action that verifies the new version, runs its new seeds,
   and records the lock — queued as a task like install, never a web action on a live site.
6. **Schema on a frozen instance** — already listed in `COMPONENTS_PLAN.md` "Gaps": enable
   and update need an explicit unfreeze → typed schema seed → refreeze step, with every
   column declared with its type (the widen trap).
7. **Settings.** A plugin's `config` rendered as a settings form (currency, timezone, brand
   for a storefront) instead of edits to config files.
8. **Dependencies on packages.** Plugins may only use libraries in core's `vendor/`
   (BaconQrCode, etc.); a plugin that needs a new package asks for it to be added to core's
   composer, reviewed once — never vendored inside the plugin.
9. **Bundles.** A recipe plugin with no code of its own that requires a set
   (`events` = `storefront` + `class` + `tickets` + `calendar` + `profiles`) — which is also
   what a start.tiknix module installs.

### 5.4 The backlog

**Core seams** — the only core changes; each small, each needed by more than one plugin:

| Seam | Needed by | What it is |
|---|---|---|
| Verified inbound webhooks | Stripe, Shopify, eBay plugins | `Webhook::<provider>` verifies the signature (the provider connector knows how) and hands the event to the enabled plugins / pipeline triggers that subscribe to it |
| Embedded-app session | `shopify-embed` | the hooks in `bootstrap.php`, `FlightMap` and `Control` a plugin needs to open a session from a verified signature and set frame headers — as registration points, not Shopify code |
| Schema step on enable / update | every plugin with beans | §5.3 item 6 |
| `StripeGateway` PaymentIntents | `invoicing`, `storefront` | additive, on the existing core client (Invoza wrote `StripeDirect` because it lacked this) |
| `PublicLink` purposes | `tickets`, `invoicing`, `outreach` | additive: a purpose and an optional expiry on the existing core links |
| `ApiKey` scopes | `shopify-embed`, any plugin with an API | additive: scoped and store-bound keys, hashed at rest |
| Translator locale | `locale` | `Control` bootstraps translatify with `member.locale`; a registration point so the enabled `locale` plugin supplies `Locale::language($memberId)` instead |

**Capability plugins** — the duplicates, consolidated as plugins:

| Plugin | Source(s) | Notes |
|---|---|---|
| ~~`pdf`~~ | Invoza `InvoicePdf` (headless Chrome), Serenity `TicketPdf` | done 2026-09-25 (§5.4a) |
| ~~`images`~~ | Serenity and CollectIQ `ImageProcessor` | done 2026-09-25 (§5.4b) |
| ~~`locale`~~ | CollectIQ `CurrencyService`, Serenity's USD / US-timezone hardcodes | done 2026-09-25 (§5.4c) |
| ~~`leadcapture`~~ | El Salón `LeadCapture` | done 2026-09-25 as a CORE seam, not a plugin (§5.4e) |
| `secretrecovery` | CollectIQ `SecretRecovery` | re-encrypt after an app-key rotation |

**Connectors:**

| Connector | Source | Notes |
|---|---|---|
| WhatsApp (Business Cloud) | El Salón `WhatsappConnector` | outbound notifications |
| Microsoft 365 mail (Graph) | lead-machine `MicrosoftConnector` | send as the customer's own mailbox, own app registration |
| eBay (browse + sell) | CollectIQ gateways and services | listing, taxonomy, aspects, notifications |
| MercadoLibre | CollectIQ | listing |
| Brave Search, SerpAPI | CollectIQ, lead-machine | web search for agents and pipelines |
| Vision providers (Gemini, Groq, Anthropic, OpenAI) | CollectIQ `AiProviderRegistry` | a `vision` capability on model connections, not a connector per vendor |

**Feature plugins** (catalog):

| Concept | Source | Used by (§9) |
|---|---|---|
| `storefront` + offer types `physical` / `digital` / `class` / `session` | Serenity (`COMPONENTS_PLAN.md` build order 3–5) | Events, Appointments, Catalogue |
| `tickets` (per seat, QR, PDF, email, door check-in) | Serenity | Events |
| `profiles` (public bio pages for hosts / staff) | Serenity teachers | Events, Appointments |
| `availability` + `appointments` (hours, services, slots, booking) | Serenity `SessionSlots`, El Salón `Appointment` | Appointments, Events |
| `calendar` | exists (1.0.1) | Events, Appointments |
| `customers` (a customer record shared by shop, invoices, portal) | Invoza `Customer`, Serenity shop customers | Client portal, Events, Invoicing |
| `invoicing` (invoices, line items, recurring schedules, public pay page, PDF, email events) | Invoza | Client portal; a blueprint of its own later |
| `shopify-embed`, `storedb` | PartsDNA | Shopify app |
| `shopifysync` | exists (1.1.0); extend with multi-store orders and content | Shopify app |
| `search-analytics` (+ suggestions, synonyms) | PartsDNA `SearchAnalytics`, `SearchRecommendations`, `Stemmer` | Shopify app, Catalogue |
| `stock-alerts` (back-in-stock sign-up and send) | PartsDNA (the sender is missing) | Shopify app, storefront |
| `photo-intake` (upload → normalise → near-duplicate check → vision identify → review) | CollectIQ `Item::identify`, `PerceptualHash` | Catalogue |
| `marketplace-listing` | CollectIQ eBay / MercadoLibre selling services | Catalogue |
| `outreach` (campaigns, prospects, scoring, unsubscribe + suppression) | lead-machine | a later blueprint |

Left where they are (too specific to generalise honestly): PartsDNA's fitment finder,
CollectIQ's grading schema and market-value lookup, Serenity's gemstone attributes, business
reports and content planner, lead-machine's prospect research pipeline.

### 5.4a Proving case: `pdf` (built 2026-09-25)

The first capability plugin went through the whole harvest in one day, and it drove what
the runtime needed rather than the other way round:

- **Built:** `pdf` 1.0.0 — `app\concepts\pdf\Pdf::fromHtml()` / `toTemp()` / `toFile()` /
  `pageCount()` / `check()`, headless Chrome run with a throwaway work dir as HOME, XDG and
  crash-dump dir, pipes for every descriptor, a 60 s kill, and a `RuntimeException` with
  Chrome's own output on every failure. Ten tests of real renders. Published to the catalog
  (`tiknix-concepts` 655082e), authored in `/var/www/html/default/concept-dev/concepts/pdf`.
- **Adopted twice:** Invoza's `InvoicePdf` keeps only its HTML template (3a11454);
  Serenity's hand-written PDF writer became `views/shop/ticket-pdf.php` + the plugin
  (8fb9460). Each adoption was pinned first by a characterization test in the source
  project (`InvoicePdfTest`, `TicketPdfTest`), green before and after the swap.
- **Proved where it failed before:** run inside each project's isolated php-fpm pool as
  its pool user (uid 30087 / 30093, via `cgi-fcgi` against the pool socket with a script
  under `/tmp`) — the environment in which Invoza's own Chrome call died with exit 133 on
  2026-09-18. Both render; nothing of the sort had been proved for the original code.

What the runtime gained or still lacks, found by doing it:

1. **Gained `requires.commands`** (core 70c193e): a manifest names the programs it shells
   out to (`"google-chrome|chromium"`), `verify()` refuses the plugin when none is
   present. The lookup asks the shell, because under `open_basedir` `is_file('/usr/bin/x')`
   is false for everything outside the install's tree while running it is allowed.
2. **`open_basedir` refuses `/dev/null`.** A `proc_open` descriptor spec of
   `['file', '/dev/null']` fails on an isolated install before the program starts. Pipes
   everywhere. Worth a line in the agent guidelines.
3. **Tests of app code that uses a plugin cannot reach it** — the concept autoloader takes
   its enabled set from the *selected* database's flags, and the test suites run on a
   scratch one. Both pins `require_once` the plugin file by hand. The lock file (§5.3 item
   3) is the right answer: a test bootstrap that loads what `concepts.lock` says is
   installed, no database needed.
4. **The query cache and `addDatabase`:** under php-fpm (APCu live), a second connection
   opened with the cache attached shares the cache namespace of every other connection
   with the same DSN (`sqlite::memory:`), so RedBean's cached table list was stale and it
   re-created a table that existed. Harmless in the proof (cache off for the scratch
   connection), but any web-context code that opens a fluid secondary connection is
   exposed. Not fixed.
5. Still to build from §5.3: version ranges, `requires.core`, the lock file, extend-don't-
   edit enforcement, updates, settings, bundles. None was needed to ship `pdf`.

### 5.4b Proving case: `images` (built 2026-09-25)

The second capability plugin, same harvest, same day:

- **Built:** `images` 1.0.0 — `Image::normalize()` (EXIF baked in, long edge capped,
  HEIC/TIFF → JPEG, in place), `fit()`, `rotate()` (atomic), `derive()` (cover around a
  focal point / contain / width-only; webp, jpg, png), `DerivedCache` (rendered once, keyed
  by source mtime and focal point; mkdir without chmod for ACL'd `cache/`), `Sizes`
  (the social presets). Imagick with GD as the alternative; **every test runs on both
  backends** (21 tests, 85 assertions) so the two cannot drift. Published (`0feb8c8`).
- **Adopted twice:** Serenity's `/img` `ImageProcessor` keeps only its whitelist, paths and
  URL shape (`9938a68`); CollectIQ's upload `ImageProcessor` keeps its "an unprocessable
  upload is still saved" contract but now logs the concept's reason as an ERROR instead of
  returning null silently (`0a8ad00`). Both pinned first (`ImageProcessorTest` in each,
  green before and after), both proved as their pool users (uid 30093 / 30079).
- **A bug found by pinning:** Serenity read the focal point with `?:`, so a photo focused
  on its left or top edge (`0`) was cropped from the centre. The concept and the adapter
  read it with `??`; the pin carries a test for it that fails on the old code.

Runtime findings, added to §5.4a's list:

6. **Gained `requires.extensions`** (core 9e545f5): `"imagick|gd"` — the same shape as
   `requires.commands`, checked with `extension_loaded()`.
7. **The test-loading gap (§5.4a item 3) bites every consumer.** Both pins again
   `require_once` the plugin's files by hand; the lock-file-driven test bootstrap is now the
   most-wanted A0 item.
8. **Fixtures need real EXIF.** Imagick's `setImageOrientation()` writes no tag; the pins
   stamp a minimal APP1/TIFF segment themselves. Worth a shared test helper once there is
   a test bootstrap to put it in.

### 5.4c Proving case: `locale` (built 2026-09-25)

- **Built:** `locale` 1.0.0 — `Currencies` (a closed ISO table: symbol, decimals, position),
  `Money` (`format`, `toMinor` for Stripe — 1200 for ¥1,200, never ×100 — `fromMinor`,
  `parse` of what a person typed), `Timezones` (validated IANA zones, a grouped picker),
  `Locale` (the install's currency / timezone / date format / language, each member's
  preferences over them on core's own `timezone` / `date_format` / `language` keys,
  configured exchange rates with member overrides, `convert`, `displayCurrency`,
  `formatDate`). **Seeded, not guessed:** enabling writes the four install settings once
  (`concept.locale.*`, timezone from `[app] timezone`); at runtime an absent one throws.
  The first plugin with a `seeds/` step. Published (`43a5b24`).
- **Adopted twice:** CollectIQ's `CurrencyService` keeps its picker list and "approximate"
  convention but rates live in the concept and an unconfigured rate is logged; Serenity's
  checkout takes its currency from `Locale::currency()` and its Stripe amounts from
  `Money::toMinor()` (the `'usd'` / `* 100` literals are gone), and its teacher-sessions
  timezone picker uses `Timezones::COMMON` instead of a US-only constant. Pinned
  (`CurrencyServiceTest`), proved as both pool users (Serenity seeded `America/Denver`,
  CollectIQ `UTC`, both `USD`).
- **Translatify.** `/var/www/html/default/translatify` (English-as-key `t()`, scanner, the
  `/translations` editor) is bootstrapped per request in core's `Control` with a locale
  read from `member.locale`. `Locale::language()` is meant to be that value's one source;
  making `Control` read it is a core seam (§5.4), since core cannot depend on a plugin —
  the next A0 candidate after the lock file.

Runtime findings:

9. **A plugin seed is just a numbered PHP file in `seeds/`** run by
   `WorkspaceSchemaBuilder::build()` on enable, with `Flight` and `Bean` in scope — nothing
   to add. It ran on both installs and reported what it kept and what it wrote.
10. **Stricter than the code it replaced, on purpose:** a corrupt stored currency list or
    an unknown code now throws where CollectIQ used to quietly return USD-only. The pin was
    changed to expect the error, and says why.

11. **The drift test is blind the same way.** `tests/unit/AgentGuidanceTest` recomposes
    CLAUDE.md on the scratch database, sees no enabled plugins, and reports the real file
    (which correctly carries their `guidelines.md` sections) as "edited by hand". It passes
    on lead-machine (no plugins) and fails on every install with one — Invoza since `pdf`,
    Serenity since `calendar`. Not a hand edit and not fixed here: the lock file (§5.3
    item 3) gives both the test bootstrap and the composer the same list without a
    database. It is now the first A0 item.

### 5.4d The lock file (built 2026-09-25)

`lib/ConceptLock.php` + `concepts.lock` at the install root. It replaced the settings-table
flag as the record of which plugins are installed and switched on, and it is a file in the
project's repository on purpose: a clone, a task worktree, a test run and the live site now
read the same plugin set, with no database.

- `ConceptCatalog::install()` records a row (switched off, with a hash of the installed
  files); `Concepts::enable()` / `disable()` flip it; `clitool --concept-lock` writes the
  file from disk — the one-time migration from the old flags, and the repair after a
  directory was added or removed by hand. `--concepts` shows `EDITED` when a plugin's
  files no longer match the hash (extend-don't-edit, §5.3 item 4, now has its evidence).
- `tests/bootstrap.php` boots the concept autoloader from the lock, so app tests that use a
  plugin no longer `require_once` its files, and the CLAUDE.md drift test composes with the
  install's real plugin set (§5.4a item 3, §5.4b item 7 and §5.4c item 11 — closed).
- A `concepts/` directory without a lock is "not migrated": an error naming the command,
  never an install that quietly believes it has no plugins.

### 5.4e Lead capture — a core seam, not a plugin (built 2026-09-25)

The backlog had this as a capability plugin; surveying it said otherwise. The `lead` bean
is core's (every install's landing form writes it), so the one place a lead is written
belongs on core's model, additively — the §5.2 rule for an existing primitive:

- **`Model_Lead::capture()`** (core `models/Model_Lead.php`, seed `19_LeadCapture.php` for
  `source`, `phone`, `updated_at`, `gate` and an email index): one lead per email,
  case-insensitively; blanks filled in on a returning lead, name / source / status never
  overwritten; `source` set on create. Core's landing form now dedupes (it never did).
  The leads list shows the source.
- **`LeadGate`** (core `lib/LeadGate.php`), required by `capture()` — the owner's ask that
  capture "doesn't randomly allow bot/spam": a public form's gate runs Turnstile, the
  honeypot, the fill time and `LeadValidator`'s content signals and FLAGS the lead
  (status `spam`, reasons kept) rather than refusing it; and **Turnstile must be
  connected** on the install, or `forPublicForm()` throws naming Connections → Security.
  Where no visitor is involved the caller says so: `LeadGate::trusted('manual booking
  entered by staff')`. No gate, no lead — a new form cannot forget.
- **El Salón adopted it:** `LeadCapture` is a thin wrapper; a visitor's booking and the
  landing form are public gates, a card-confirmed booking and a staff-entered one are
  trusted; its old `type` column was migrated into `source` (10 rows); its booking form
  now carries the Turnstile widget (renders nothing until connected). Pinned
  (`LeadCaptureTest`), proved in its pool.
- **Open on El Salón:** Turnstile is not connected there, so a visitor's booking or
  sign-up currently logs `ERROR … needs Cloudflare Turnstile` and captures no lead (the
  booking itself still saves). Adding its site + secret keys under Connections → Security
  on bookingscheduler.tiknix.com switches both public paths on. Serenity, CollectIQ and
  PartsDNA keep their own `Index::dolead` copies (they replaced core's), so they still
  write leads directly; moving them onto `capture()` is a follow-up per project.

### 5.5 How a component is harvested

Every extraction follows the same five steps, so a live client never pays for it:

1. **Pin the behaviour** — characterization tests in the source project first (Serenity has
   none today; `COMPONENTS_PLAN.md` build order step 3).
2. **Extract** — the generic part into the catalog (or core), scrubbed of client names,
   domain fields and hardcodes, with a typed schema seed, its own tests, and a
   `guidelines.md` that tells any agent how to use it.
3. **Re-adopt in the source** — the source project swaps its own copy for the component,
   with its tests still green. This is the proof that the component is real.
4. **Publish** a version to the catalog.
5. **Second consumer** — a component is not done until a second project (or a blueprint)
   adopts it without editing it.

**Ownership comes first.** Serenity, Invoza and CollectIQ are clients' apps. Their code
becoming catalog components other members adopt needs the terms in `COMPONENTS_PLAN.md`
"Ownership of extracted code" settled before step 2 — see Decisions.

### 5.6 How any task uses them

- **The planner already ADOPTs**: every decompose searches the catalog (`concepts_search`)
  after the local inventory, and an ADOPT becomes a task that installs, enables and adapts
  the component. Components make that path produce real matches instead of NEW.
- **Every project agent sees them**: an enabled component's `guidelines.md` is composed into
  the project's CLAUDE.md (`--agent-sync`), and `reuse_digest` / `whatprovides` list it.
- **Task board**: a **Components** tab listing what is installed in this project and what the
  catalog offers, with **Add to this project** queuing the same install task the Plugins
  page does today — so a member can reach for a component without writing a prompt.
- **Finding the next one**: a periodic cross-project duplicate scan
  (`check-duplicates.php` pointed across projects, plus the file-name survey used above)
  lists what is being rebuilt, and that list is the backlog's input from then on.

---

## 6. Primitives are checked live, never assumed

A blueprint that promises `ADOPT concept/tickets` when `tickets` is not in the catalog would
hand the planner a plan it cannot follow — the "plausible answer" the No Fallbacks rule
forbids. So before a blueprint is offered, and again before hand-off, `PrimitiveCheck`
resolves every `requires` and every chosen option's `primitives`:

- `core/*` against core's `reuse_digest` (what the new clone will have).
- `concept/*` against the catalog (`concepts_search` / `concepts_get`, with the version).
- `connector/*` against `ConnectorRegistry`.

A blueprint whose requirements do not all resolve is shown as **Coming soon** with the
missing pieces named — never offered with a hole in it. An option whose primitive is missing
is disabled with the reason. The catalog being unreachable is an error on the page, not an
empty gallery.

---

## 7. PLAN.md: the contract

### 7.1 Where it lives

`PLAN.md` at the project root, as asked — which means **core's own `PLAN.md` moves** (it is
the original Workbench plan, historical; → `docs/history/WORKBENCH-PLAN.md`). Otherwise every
clone inherits core's file and every upgrade merge fights over it. Beside it,
`.aibuilder/blueprint.json` (the answers + blueprint id and version) so the plan can be
re-rendered or extended later.

### 7.2 What it contains

Rendered in this order; every section is generated from answers, and the member can edit the
text before **Build it**:

1. **The business and the end goal** — from Discovery: what they do, who their customers
   are, how money comes in, what they use today, the end goal — in their own words
   (fenced). Uploads listed with their paths.
2. **What this app is** — name, one paragraph, which modules it is assembled from and why.
3. **People and roles** — each role, its level, what it may do; sign-up policy.
4. **Data model** — each bean: fields with explicit types and sizes (dates as sized TEXT),
   relations by association, `_eid` / `_ref` naming; which come from a concept
   (`extends`) and which are new. Enough for a typed schema seed.
5. **Pages and routes** — route, purpose, level; public pages first. Each becomes a
   `seedRule` row (seeded before the route is ever fetched).
6. **Built from** — the primitives table:

   | Capability | Decision | Primitive |
   |---|---|---|
   | Sign-up and login | REUSE | core `Auth` |
   | Selling seats | ADOPT | `concept/storefront` 1.x + `concept/class` |
   | Door check-in | ADOPT | `concept/tickets` |
   | Gift cards | NEW | — (not in the catalog; why) |

7. **Connections** — which connectors, why, the exact scopes, and what the member must do
   in `/connections` (with the link).
8. **Automations** — pipelines to create (trigger, steps, schedule), from the blueprint's
   pipeline templates.
9. **Phases** — ordered, each small enough for one decompose, each with a done-when line:
   `- [ ] Phase 1 — Accounts, branding and the event catalogue (done when: …)`.
10. **Acceptance checks** — what `AuditRunner` should verify per persona (ROOT / ADMIN /
   MEMBER / PUBLIC).
11. **Not in this plan** — what was deliberately left out, so the planner does not invent
    it.

### 7.3 Phases drive the build (fixing the overwritten goal)

- `PlanRunner` gains a **root goal**: when `PLAN.md` exists in the project, it is the goal
  of record. A decompose for "Phase N" sends the planner PLAN.md plus the phase's section
  (not a copy of a request that the next request overwrites).
- `plan-goal.md` keeps its current meaning (the last request); **Continue to next phase**
  reads the next unchecked phase from PLAN.md instead.
- When a phase's plan completes (all tasks merged, audit passed), the checkbox is ticked in
  a commit — so the file shows the truth and the member can see where they are.
- Ad-hoc requests on the board still work unchanged; they just no longer erase the plan.

This is a general fix — it also restores Serenity's business-plan-as-goal — so it ships
before any blueprint.

---

## 8. The hand-off, step by step

1. **Review.** The member reads PLAN.md, edits if they want (a markdown editor; the edited
   text is what gets committed), and presses **Build it**.
2. **Pre-flight** (refuses loudly, with the fix, before anything is created):
   - project cap: can this member create a project? (first one is free) — if not, the
     billing link, as `/projects` already does;
   - engine: is the member signed in to an AI engine? — if not, a one-step "Connect your AI"
     inline (login token or API key), not a bounce to `/aibuilder`;
   - primitives: re-run `PrimitiveCheck` (the catalog may have changed since the draft).
3. **Provision** — signed `op: create` through `/provision/call` (name from the Foundation
   answers, engine from the member's choice). Recorded as a `handoffstep`.
4. **Commit** — write `PLAN.md` and `.aibuilder/blueprint.json` into the new project, copy
   any uploads into `secure/uploads/`, commit as the member. (The sidecar never writes a
   project's files by path guessing: it goes through a signed core op that resolves the
   instance dir — `SWEEP-PER-INSTANCE.md` rules.)
5. **Connections** — if the plan needs Stripe / Shopify / Mailgun, the next screen is a
   checklist with deep links into the new project's `/connections`. Phases that need a
   missing connection say so; Phase 1 never does (see §9 — every Phase 1 is buildable
   offline).
6. **Decompose Phase 1** — `auto_build` on by default ("Run it straight through"), with the
   choice shown.
7. **Watch** — deep link to `/sidecar/app/workbench?to=/workbench` for the new project. The
   blueprint's own status page reads progress the way `Projects::buildState()` already does
   and shows the phases from PLAN.md with **Start phase N** when the previous one is done.

Every step is idempotent on retry (provisioned already? skip; committed already, same
`plan_sha`? skip). A failure leaves the interview at `failed` with the step and the error
shown, and **Retry** resumes at that step.

---

## 9. The blueprints

Five, in the order they can ship. Each says what it is built from, the questions that
matter beyond Foundation, the phases its PLAN.md will contain, and what has to exist first.
**Rule for every blueprint: Phase 1 uses only core primitives and needs no external
account**, so a new member sees a working app on day one even before connecting Stripe or
Shopify.

### 9.1 Client portal — "The SaaS you'd rather own" (ships first: core only)

A private portal for a small business and its clients: requests, messages, documents,
status.

- **Built from:** Auth + invites (clients get invited), Teams (staff), threads/Notes/
  NotifyService (messages in-app + email), `PublicLink` (share without login), contact form
  + Turnstile + leads (enquiries become clients), scaffolding (their records), pipelines
  (reminders), connectors HubSpot / QuickBooks / Monday (optional import/sync).
- **Questions:** what a client "has" (an `entity`: e.g. Project, Case, Order, with fields);
  the statuses it moves through; who sees what (client sees own, staff sees all); do clients
  upload files; reminders ("nudge a client after N days without a reply"); import existing
  contacts from HubSpot / Monday / a CSV.
- **Phases:** 1 accounts, roles, client records + staff admin → 2 messages and email
  threads per record → 3 public enquiry form feeding records → 4 reminders pipeline and an
  optional connector sync.
- **Prerequisites:** none beyond §7.3 and the sidecar. **This is the blueprint that proves
  the whole path end to end.**

### 9.2 Events with tickets (from Serenity Gemstones)

- **Built from:** `concept/storefront` + `concept/class` (events as offers), `concept/tickets`
  (per-seat tickets, QR, PDF, email, door check-in), `concept/calendar` (ICS, feeds,
  add-to-calendar), `concept/profiles` (hosts / teachers with bio pages),
  `concept/session` + `concept/availability` (optional 1:1 bookings), Stripe connector,
  Mailer.
- **Questions:** kinds (one-off / recurring / multi-day / appointments); in person, online or
  both; hosts with public profiles?; capacity; free, paid or both; ticket tiers (`list`:
  name, price, seats) — *needs tiers built*; names per attendee or per order; check-in at
  the door (with staff accounts); reminder emails (when); refund policy (text, shown at
  checkout); currency and timezone (not assumed US).
- **Phases:** 1 branding, event catalogue, public calendar and host pages (no payments —
  free RSVP tickets) → 2 Stripe checkout with seat holds and **webhook-confirmed**
  payment → 3 QR tickets, emails, door check-in → 4 recurring series, reminders, reports.
- **Prerequisites:** `COMPONENTS_PLAN.md` build order 3–5 (characterization tests on
  Serenity, the `OfferType` contract, extract `storefront` / `class` / `tickets` /
  `profiles` / `session` / `availability`); plus the Serenity gaps: Stripe
  `checkout.session.completed` webhook (a pipeline trigger or a `Webhook::stripe`), ticket
  tiers, attendee per seat, void/refund, buyer "my tickets", configurable currency and
  timezone, no hardcoded brand. Serenity re-adopts the extracted concepts — the proof that
  adopt-then-adapt works on a live client.

### 9.3 A Shopify app (from PartsDNA)

An embedded Shopify admin app with its own data per store, sync pipelines, and an optional
storefront widget.

- **Built from:** `concept/shopify-embed` (extracted: `ShopifyEmbed`, embedded layout, the
  three-state shell, store accounts, session-token auth), `concept/storedb` (per-store
  databases with a schema hook), `concept/shopifysync` (exists, 1.1.0; extended with
  multi-store orders and content), the Shopify connector, pipelines (cron fan-out per store),
  `MemberApiKey` / `ApiAuthService` for its API.
- **Questions:** what the app does for a merchant (pick an archetype: *enrich products with
  your own data* / *a finder or search on the storefront* / *an inventory or orders
  dashboard* / *something else* → longtext); which Shopify data (products, variants,
  inventory, orders, customers, pages/collections) → exact **scopes**, read vs write; one
  store (their own) or many (an app other merchants install); storefront surface (App Proxy
  page / embeddable snippet / none); the per-store data model (`entity`); sync cadence;
  what the merchant sees in the admin (lists, editors, analytics).
- **Phases:** 1 the app's own data model and admin screens, working standalone (no store
  yet) → 2 connect a store, embedded admin with session tokens, product sync → 3 storefront
  surface → 4 orders/content sync, analytics, multi-store fan-out.
- **Prerequisites:** the embedded-app session seam in core (§5.4); extract the plugins;
  **Shopify webhooks** (`app/uninstalled` → suspend the store; the three GDPR webhooks);
  multi-store `shopify-orders`; write scopes derived from answers, not typed by hand;
  untrack PartsDNA's `secure/connections.key`. A merchant-started install and the Billing
  API are a later step (§12) — the first version targets the member's *own* store(s) via a
  custom app, which is what PartsDNA does today.

### 9.4 Catalogue with AI photo intake (from CollectIQ)

Photograph a thing, AI identifies and categorises it, fills in details; organise into
collections; optionally list for sale.

- **Built from:** a vision step (an `agent` pipeline step or a model connection — the
  member's own key; CollectIQ uses Gemini vision), collections and categories (scaffolded
  entities), image uploads, optionally `concept/storefront` or marketplace connectors.
- **Questions:** what is being catalogued (categories); which details the AI should fill
  (fields per category — `entity`); who sees the catalogue (private, shared, public
  showcase); grading or condition fields; sell it? (own storefront / a marketplace / no).
- **Phases:** 1 collections, items, photo upload, manual entry → 2 AI identification from a
  photo with review-before-save → 3 public showcase / sharing → 4 selling.
- **Prerequisites:** a survey of CollectIQ like the two above (not done yet); extract a
  `photo-intake` concept (upload → vision → structured fields → review); eBay and
  MercadoLibre as connectors if selling is offered. The AI step bills the member's own
  model connection, never the platform's silently.

### 9.5 Appointments for a service business (from El Salón + Serenity sessions)

Services, staff, availability, online booking, deposits, reminders.

- **Built from:** `concept/session` + `concept/availability` (Serenity's `SessionSlots`),
  `concept/profiles` (staff), `concept/calendar`, Stripe (deposits), Mailer / Notes
  (reminders), pipelines (reminder schedule).
- **Questions:** services (`list`: name, duration, price); staff and who does what; opening
  hours and breaks; notice and horizon; deposits or pay-on-site; cancellation window;
  reminders.
- **Phases:** 1 services, staff, hours, public booking without payment → 2 deposits →
  3 reminders and staff calendars → 4 reports.
- **Prerequisites:** the `session` / `availability` / `profiles` extraction (shared with
  8.2), and a survey of `bookingscheduler` for what it adds.

**Later candidate — an automation hub** (from lead-machine): connectors in, transforms,
agent steps, dashboards out. Mostly pipelines; worth a blueprint once the pipeline editor
has a guided "first pipeline" path.

---

## 10. Build order

Two tracks after a shared Phase 0. **Track A (components)** makes every project better on
its own and feeds the catalog; **Track B (start.tiknix)** is the front door. A blueprint
switches from Coming soon to live the day its components are published — the gate is
`PrimitiveCheck` (§6), not a date. Every step is shippable alone and leaves the platform
better even if the next never happens.

**Phase 0 — make the path safe (core + workbench)**

1. Untrack `secure/connections.key` in PartsDNA; make the upgrade checkpoint refuse to commit
   anything under `secure/` (its `git add -A` is how the key got in).
2. Move core's `PLAN.md` → `docs/history/WORKBENCH-PLAN.md`; reserve root `PLAN.md` for the
   app's plan (a note in `agent/guidelines/` so agents know what it is).
3. Root goal in `PlanRunner` + phase-aware **Continue** reading PLAN.md (§7.3), with tests.
   Proving case: Serenity gets its business plan back as a PLAN.md.
4. The three first-run gates (see Decisions): the workbench feature, the engine step, the
   first project.
5. ~~Settle ownership of extracted client code~~ — settled 2026-09-25 (open source); only
   the scrub remains as a gate on each extraction.

**Track A — components (§5)**

- **A0 — the plugin runtime, ready for components** (§5.3): version ranges, the core
  compatibility line (`Concepts::API` + `requires.core`), `concepts.lock` with file hashes,
  the extend-don't-edit rule (guidelines + validation-hook warning), update notices and the
  update task, the schema step on enable / update, plugin settings forms, bundles. Plus the
  core seams (§5.4): verified inbound webhooks, embedded-app session hooks, and the additive
  `StripeGateway` / `PublicLink` / `ApiKey` extensions. Proved on `calendar` and
  `shopifysync`, which are already published.
- **A1 — capability plugins** (no ownership question: they replace duplicated code with one
  shared copy): `pdf`, `images`, `locale`, `leadcapture`, `secretrecovery`. Each source
  project swaps its own copy for the plugin.
- **A2 — the Serenity cluster**: characterization tests → `OfferType` contract in place →
  extract `storefront` + offer types, `tickets`, `profiles`, `availability`, `customers`;
  close the §9.2 gaps inside them (webhook-confirmed payment first — it takes money);
  Serenity re-adopts; `calendar` replaces `EventCalendar`.
- **A3 — the PartsDNA cluster**: `shopify-embed`, `storedb` (with a schema hook),
  `shopifysync` multi-store orders and content, Shopify webhooks (`app/uninstalled`, GDPR
  ×3), `search-analytics`, `stock-alerts` with a real sender; PartsDNA re-adopts.
- **A4 — Invoza and El Salón**: `invoicing` (on `customers` and the A1 Stripe work),
  `appointments` (with A2's `availability`), WhatsApp connector.
- **A5 — CollectIQ and lead-machine**: `photo-intake`, the vision capability on model
  connections, eBay / MercadoLibre connectors, `marketplace-listing`, Microsoft 365 mail,
  search connectors, `outreach`.
- **A6 — for every task**: the task board's **Components** tab with **Add to this
  project**; the cross-project duplicate scan feeding the backlog.

**Track B — start.tiknix**

- **B1 — the sidecar and discovery**: `start.tiknix` from the insights template; SSO,
  `Feature`, config, Get started entry points in core; Discovery and the qualified concept
  brief (§4.0) with rules-based recommendation and the bespoke-only path;
  `BlueprintRepo` + schema validation; `Interview` with `when`; save/resume.
- **B2 — the contract**: `PlanRenderer` with golden tests (fixed answers → byte-identical
  PLAN.md); `PrimitiveCheck` with the Coming-soon state; the Client portal blueprint (core
  only — live immediately).
- **B3 — the hand-off**: `Provision::secret()` accepts the start sidecar's secret for
  `create`; a signed core op to write PLAN.md + blueprint.json + uploads into the project and
  commit; `Handoff` with `handoffstep` records, idempotent retry and pre-flight gates; Phase 1
  decompose with `auto_build`; the status page and **Start phase N**. Done when a Playwright
  run on a disposable project goes Get started → discovery → brief → Client portal → Build it
  → PLAN.md committed → Phase 1 plan created, with guaranteed teardown.
- **B4 — richer questions**: `entity`, `list` and `brand` question types.
- **B5 — blueprints as their components land**: Events (after A2), Shopify app (after A3),
  Appointments (after A2 + A4), Catalogue (after A5).
- **B6 — make it better**: AI module recommendation and follow-up questions on the member's
  engine (answers appended to PLAN.md; the fixed questions stay the backbone); stories ↔
  blueprints ("Build something like this"); a blueprint authoring page (ROOT); funnel numbers
  — started, brief agreed, handed off, Phase 1 complete. The last is the one that matters.

Suggested order where the tracks meet: Phase 0 → A0 and B1–B3 in parallel (B only needs
core) → A1 → A2 → B5 Events → A3 → B5 Shopify → the rest.

---

## 11. Decisions to confirm

1. ~~**Name.**~~ Settled 2026-09-24: **start.tiknix.com**, nav and buttons **Get started**;
   the modules are called blueprints.
2. **Who pays for the first build?** The engine gate means a brand-new member must connect
   an AI engine before anything builds. Options: (a) they connect their own (Claude login or
   key) as part of the interview; (b) the platform funds Phase 1 of the first project; (c)
   (a) with a clear cost estimate per phase. Recommendation: (a) now, (b) as a later
   growth experiment.
3. **The workbench feature.** Grant it to every member (blueprints make it the default path),
   or only through the blueprint hand-off?
4. **PLAN.md at the root** — confirm moving core's historical `PLAN.md` out of the way.
5. **Deterministic renderer vs AI-written plan.** Recommendation: deterministic templates
   produce the contract (testable, repeatable, no cost); AI is used where judgement helps and
   nothing is committed without review — recommending modules from Discovery (§4.0) and
   asking follow-up questions (Phase 6). The planner already brings intelligence at
   decompose time. Confirm AI recommendation runs on the customer's own engine (it needs one
   to build anyway), with the rules-based recommendation as the always-available baseline.
6. **Blueprint availability.** Ship the gallery with only Client portal live and the rest
   shown as Coming soon (honest, and it shows the direction), or hide unavailable ones?
7. **Shopify scope of the first version:** the member's own store(s) via a custom app
   (what PartsDNA does), not a public App Store app with merchant install and billing —
   confirm that is acceptable for v1.
8. ~~**Who owns extracted plugins**~~ Settled 2026-09-25: every app built on tiknix is open
   source, so generic code from Serenity, Invoza, CollectIQ, PartsDNA and lead-machine may
   become catalog plugins. The scrub in `COMPONENTS_PLAN.md` "Ownership of extracted code"
   still applies in full (no client data, seeds, credentials, branding or client-specific
   business rules).
9. **"Plugin" or "concept"?** The Plugins page already says plugin; code, CLI and docs say
   concept. Recommendation: **plugin** everywhere a person reads it (UI, docs, start.tiknix,
   the task board's Components tab → "Plugins"), `concept` kept in code and the CLI so
   nothing is renamed that already works. Confirm, or rename the code too.
10. **Core API versioning.** How `Concepts::API` is numbered and who bumps it — proposed:
    an integer in core, bumped in the same commit that changes a seam or a `requires.lib`
    class's public shape, with a test that fails if a seam changes without the bump.

---

## 12. Not in this plan

- A public Shopify App Store listing, merchant self-install, and the Shopify Billing API.
- Marketplace connectors beyond what CollectIQ already has.
- Hosting and custom domains for the generated app (the existing publish drivers apply
  unchanged).
- Pricing changes. The first project stays free; teams stay a paid-project perk.
- Any change to how the planner or executor work, beyond the root-goal fix in §7.3.

---

## See also

- `COMPONENTS_PLAN.md` — concepts, the storefront extraction, ADOPT, augment-decompose
- `AGENT_ORCHESTRATION.md`, `TASK-NOTES.md` — planner, workers, engines, time budgets
- `SIDECAR-ECOSYSTEM-PLAN.md` — sidecars as features; identity and publish appendices
- `CONNECTIONS_PER_INSTANCE.md` — why credentials are connected per project, not asked for
  in a wizard
- `SWEEP-PER-INSTANCE.md` — resolving the project, never defaulting to core
