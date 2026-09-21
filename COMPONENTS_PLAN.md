# Concepts: pluggable features, one storefront, augment-decompose

**The runtime, the catalog and the first concept are built and tested. The storefront work,
ADOPT in the planner prompt, and augment-decompose are still design.**
Written 2026-09-21, revised the same day after surveying serenity and the shop sidecar.

What exists:

- **Runtime** — `lib/ConceptManifest.php` (shape rules), `lib/Concepts.php` (autoload, routing,
  slots, collect, save, verify / enable / disable), install-scoped flags in `lib/Feature.php`,
  the enabled-concept roots in `defaultRoute`, concept views in `Control::render()`,
  `WorkspaceSchemaBuilder::build($seedDir)`, concept awareness in `mcptools/Introspector` and
  `check-duplicates.php`.
- **Catalog** — `lib/ConceptCatalog.php` (local on the control plane, remote over HTTP from an
  instance), `lib/ConceptLint.php` (the scrub gate), `controls/Concepthub.php` (served to
  instances, broker-key authenticated), MCP tools `concepts_search` / `concepts_get`. The
  catalog itself is its own repository beside core: `[concepts] catalog_dir`.
- **CLI** — `clitool --concepts | --concept-verify | --concept-enable | --concept-disable |
  --concept-search | --concept-lint | --concept-publish | --concept-install`.
- **Screen** — `/admin/concepts` ("Plugins" in the left nav, under Admin): what is installed,
  whether each verifies, Enable / Disable, where it came from, and what the catalog offers.
  ROOT only — by `admin::concept*` rows at level 1 *and* a check in each method — because
  enabling makes new code routable and runs seeds. It switches plugins; it cannot install
  one (that stays a build task), and it never forces a disable (that stays a CLI action).
- **First concept** — `calendar` 1.0.0, extracted from serenity's `EventCalendar`, published.
- **Tests** — `vendor/bin/phpunit` (`tests/unit/`); each concept ships its own under `tests/`.

Not yet: a root manifest and slots in core's views, a settings form for concepts, bundles,
update notices, ADOPT in the planner prompt, and how a build agent in a worktree installs a concept
(see "The catalog as built").

Four related pieces:

1. **Concepts** — features built on one instance, parcelled into small installable units that
   switch on per install. Not "the whole Events module" — the ideas it is made of.
2. **One storefront** — serenity's in-instance store becomes the storefront; the
   `shop.tiknix` sidecar retires.
3. **ADOPT in decompose** — the planner searches the concept catalog over MCP and adopts the
   closest match instead of building from scratch.
4. **Augment-decompose** — add detail to a main task; the planner appends new tasks.

## Where decompose stands today

`PlanRunner::buildPlanRequest()` already bakes a reuse inventory into the planner prompt, from
`mcptools/Introspector::digest()` — the same data the `reuse_digest` MCP tool returns. The
planner must classify every capability **REUSE / EXTEND / NEW**, justify any NEW, and may call
`describe()` / `whatprovides()`. Each ingested task records what it builds on in `reuses`
(`PlanIngestor.php:125`).

The limit: the inventory covers **the planning instance only**, and it is **pointers, not
code**. A fresh instance asked for "classes with teachers" sees an empty inventory and
correctly answers NEW. It cannot know serenity built exactly that. The catalog closes that gap.

## What the survey found

Surveyed 2026-09-21 on `serenity-bbdc01`. The first idea — lift "Events" out as one component
— does not survive contact with the code. The Events cluster is ~5,400 lines (`Events.php`
1,841; six libs 1,531; four models 275; seven views 1,772), and:

- **There is no `event` bean.** The controller header says so: *"the data is still `product`
  with offerType='class' (REUSE, not a parallel table)"*. A booking is a `shoporder` +
  `shoporderitem` with `channel='event'`. A vendor is `member.isVendor`.
- **The storefront knows about events.** Event-aware references outside the cluster:
  `Shop.php` 37, `Orders.php` 34, `Catalog.php` 25, `Reports.php` 17, `Model_Product.php` 6,
  plus ~20 views.

But reading those seams shows the coupling is not about *events*. It is about one column.

### The coupling axis is `offerType`

Serenity's store branches on `offerType` everywhere: `class` ×25, `digital` ×16, `session`
×12. `digital` has nothing to do with events, and it is woven through `Shop.php` and
`Orders.php` in exactly the same way. Every branch is the shop asking one of the same seven
questions:

| The shop asks | `class` | `session` | `digital` |
|---|---|---|---|
| Should it be listed? | hide past dates (`Shop.php:77`) | | |
| Can this line be added? | capacity | `SessionSlots::isSlotOpen()` | |
| Item page extras | add-to-calendar | slot picker | |
| On paid | issue tickets | reserve slot | grant download |
| On cancelled | void tickets | release slot | |
| Line label in orders/emails | | `bookedSessionDisplay()` | |
| Success page extras | ticket, calendar | | download link |

That table **is** the extension point.

### What was not a problem

Branding leakage in the Events cluster is six lines, all mechanical: a hardcoded
`'Serenity Gemstones and More'` in `EventCalendar.php:22,105` and `views/shop/calendar.php:26`
(→ `Flight::siteName()`), an error message naming `conf/config.serenity-bbdc01.ini`
(`EventCalendar.php:406` → the loaded `conf/config.ini`), a "Certified Reiki Master"
placeholder, and one comment. Permissions already ship as seeds.

The storefront is a different story — see "Generifying" below.

## Concepts

Two kinds.

### Offer types — plug into the storefront

The storefront sells plain physical goods and knows one contract. Each offer type is a concept
that implements it. The contract is **behaviour only** — everything an offer type shows goes
through view slots (next section), not through this interface:

```php
interface OfferType {
    /** [sqlFragment, params] narrowing the public listing for THIS type, or null for no rule. */
    public function listingCondition(): ?array;
    public function validateProduct(OODBBean $product): void;            // called by Model_Product::update(); throws
    public function validateLine(OODBBean $product, array $line): void;  // throws, naming the reason
    public function onPaid(OODBBean $order, OODBBean $item): array;      // fulfilment records for the confirmation email
    public function onCancelled(OODBBean $order, OODBBean $item): void;
    public function lineLabel(OODBBean $item): ?string;                  // null = use the product name
}
```

The scattered `if ((string) $product->offerType === 'class')` checks collapse into
`OfferTypes::for($product)->onPaid($order, $item)`.

Two details the seams forced:

- **`listingCondition()`, not `listable($bean)`.** `Shop.php:77` is a SQL `WHERE` clause
  (`offer_type != 'class' OR class_starts_at IS NULL OR class_starts_at > ?`). A per-bean
  check would mean loading every product and filtering in PHP, which breaks pagination. The
  host wraps each fragment as `(offer_type != '<type>' OR (<fragment>))`. The fragment comes
  from the concept's lib class — code with the controller's trust — never from the manifest
  and never from a request.
- **`onPaid()` returns.** `Orders.php` keeps `$classTickets` / `$digitalItems` and hands them
  to the confirmation email (`notifyClassTickets()`), so fulfilment has to report what it made.

First three: **`digital`** (downloads), **`class`** (dated, capacity, tickets),
**`session`** (slot booking).

### Dispatch for behaviour, broadcast for presentation

Offer types and view slots are deliberately two mechanisms. The test:

> **If zero contributors is fine, it is a slot. If zero handlers is a bug, it is the contract.**

**Behaviour is a dispatch with exactly one owner.** `validateLine` runs inside
`Bean::begin()`/`rollback()`, the `session` and `class` branches are mutually exclusive, and a
failure aborts the checkout. Broadcast that as an `order.paid` event and a missing handler
means money taken and no ticket, with nothing failing. `OfferTypes::for($product)` throws when
the type has no enabled handler.

**Presentation is a broadcast, because the owner of a partial is often not the offer type:**

- The teacher select (`catalog/edit.php:161`) shows for `class` **and** `session`, but it
  belongs to `profiles` — it queries teachers and links to `/events/teachers`. As an interface
  method, `ClassOffer` and `SessionOffer` would each have to return another concept's view
  path, and each would have to know whether `profiles` is enabled.
- Add-to-calendar belongs to `calendar`, and its real condition is
  `EventCalendar::isEligible()` — "has a date window" — not `offerType === 'class'`.

So a slot registration can narrow itself two ways, both pure data: a `match` on the context
the host passes, and the `when` predicate. `profiles` registers the teacher select once, with
`"match": {"offerType": ["class", "session"]}`; `calendar` registers add-to-calendar with
`"when": "Portlets::isEligibleCtx"`. An offer type registers its own field block the same way
— there is one way to put something on a page.

`match` is generic on purpose: the runtime in core knows nothing about offer types. It compares
a scalar in the host's `$ctx` against a list, so the storefront passes `offerType` and some
other host can pass whatever it narrows by. A `match` key the host did not pass is an error,
not a non-match — otherwise a host that forgets it produces a slot that silently never renders.

### Capabilities — need no shop at all

Offer types compose them; other apps can use them alone.

| Concept | From serenity | Useful beyond events |
|---|---|---|
| `tickets` | `ClassTickets`, `TicketPdf`, `TicketQr`, `ticket`, `ticketcheckin`, check-in view | any admission, voucher, pickup slip |
| `calendar` | `EventCalendar` (ICS feeds, add-to-calendar links) | anything dated |
| `availability` | `SessionSlots`, `sessionavailability` | appointments, shift scheduling, room booking |
| `profiles` | `teacher`, bio + photo, public pages | staff, practitioners, speakers |
| `vendors` | `EventAccess` (`member.isVendor` + per-row ownership) | any multi-vendor catalog |

"Events" is then not a component. It is `class` + `tickets` + `calendar` + `profiles`, switched
on together. A shift-scheduling app takes `availability` + `profiles` + `calendar` and never
installs a storefront.

## How a concept plugs in

A concept is a **self-contained directory**, a portlet-style mini-app that shares the
instance's one runtime:

```
concepts/tickets/
  concept.json      the manifest — and the whole registration
  controls/         app\concepts\tickets\…   (routable only while enabled)
  lib/              app\concepts\tickets\…   incl. Portlets.php
  models/
  views/
  seeds/
  screenshot.jpg
```

- **Install is copying a directory; uninstall is deleting one.** Nothing is scattered into
  the instance's own `controls/` or `lib/`, so provenance is visible from the path and
  removal never depends on a file list being right.
- **Registration is data, never code.** The manifest *is* the registration. The loader reads
  JSON and executes nothing a concept supplies — see "Registration is data" below.
- **Off means unroutable, by construction.** `defaultRoute` already refuses any class whose
  file is outside `controls/` — that check exists so a URL cannot instantiate `Bean` or
  `PermissionCache`. The allowed roots become `controls/` **plus `concepts/<name>/controls/`
  for each enabled concept**. A disabled concept's controllers are refused by the mechanism
  that was already there; no controller has to remember to declare anything.
- **Autoload and views.** One autoloader maps `app\concepts\<name>\` onto the directories of
  *enabled* concepts (composer's static map only knows `controls/` and `lib/`). Views need no
  path juggling: Flight's `View::getTemplate()` returns an absolute path untouched, so the
  loader renders `<root>/concepts/<name>/views/<file>`, after a `realpath` check that the
  file is inside that concept's `views/`.
- **Controller names are claimed.** `/tickets/…` resolves through the registry to
  `app\concepts\tickets\Tickets`. A concept claiming a controller name that core or another
  enabled concept already owns refuses to enable, naming both.
- **Permissions follow the flag.** A concept's authcontrol seeds run on enable, via
  `PermissionCache::seedRule()`.

### Shared runtime — not a mini-Flight per plugin

A plugin with its own bootstrap, its own Flight engine and its own database already exists
here: it is a **sidecar**, and it remains the right tool for untrusted or heavy work. Inside
an instance, a concept shares the one runtime, for three reasons:

- **Flight and RedBean are static singletons.** `Bean::selectDatabase()` switches the
  database for the whole process. A portlet that selects its own database inside a host page
  and throws before switching back sends every later host query to the wrong database, with
  no error — the "wrong query reports zero rows" failure `CLAUDE.md` already records.
- **The data is shared on purpose.** A class *is* a `product`; a booking *is* a
  `shoporderitem`; `validateLine` decrements seats inside the host's checkout transaction;
  `Orders.php:304` joins `shoporderitem` to `product`. Separate databases break the
  transaction and the join.
- **A per-plugin bootstrap is code that runs at load**, which data-only registration exists
  to avoid.

### View slots

Nothing like this exists today — views pull partials with raw `include __DIR__ . '/…'`.
Serenity's views show four kinds of seam.

| Seam | Where it is today | Shape |
|---|---|---|
| Nav entries | `header.php:104` — `$__sections['Events & Classes']`, guarded by `isVendor()` | data |
| Form field blocks | `catalog/edit.php` — class / session / digital groups, JS-toggled | markup **+ save handling** |
| Panels and links on a host page | "Edit bio" on profile, "Classes" on dashboard, vendor radio in `edit_member` | markup |
| Page extras | slot picker, add-to-calendar, ticket on shop pages | markup |

Two primitives cover all four:

```php
// markup — every partial enabled concepts registered for this slot, in order
<?= Concepts::slot('catalog.edit.fields', ['product' => $product]) ?>

// data — merge what enabled concepts contribute (nav is an array, not HTML)
$__sections += Concepts::collect('nav.sections', ['member' => $__me]);
```

Registered in the concept's `concept.json` — this one is `concepts/profiles/concept.json`:

```json
"slots": {
  "catalog.edit.fields": {
    "provider": "Portlets::teacherSelect",
    "view": "teacher-select.php",
    "level": "ADMIN",
    "match": { "offerType": ["class", "session"] },
    "save": "Portlets::saveTeacher"
  },
  "member.profile.panels": {
    "provider": "Portlets::editBio",
    "view": "edit-bio.php",
    "level": "MEMBER",
    "when": "vendors:Access::isVendorCtx"
  }
},
"collect": {
  "nav.sections": {
    "level": "MEMBER",
    "when": "vendors:Access::isVendorCtx",
    "data": { "Teachers": [{ "label": "Teachers", "href": "/teachers" }] }
  }
}
```

#### A slot entry is a portlet

Each entry is a miniature controller-plus-view: a **provider** that fetches its own data, and
a **view** that renders it. The teacher select needs the list of teachers; today the host's
`Catalog` controller queries them, but once `profiles` is a plugin the host cannot know to.
Without a provider the partial would end up querying the database from inside the view.

- `provider` takes `(array $ctx): array` and returns the view's variables. The view sees
  **only** what the provider returned plus the `$ctx` the host passed — never the host view's
  local scope. That leak is exactly what makes extraction hard today.
- `view` is a filename relative to the concept's own `views/`.
- By convention providers and `save` handlers live in the concept's `Portlets` class, so the
  whole manifest-reachable surface of a concept is one file a reviewer can read top to bottom.

#### Registration is data

An earlier sketch put closures in a PHP registration file (`'when' => fn($ctx) => …`). That
is not `eval` — it is a `require`d file with the same trust as the controllers the concept
ships — but it is the wrong shape, for three reasons:

1. **It runs on every request.** A controller runs when its route is hit; a registration
   file loads at bootstrap for every page and every visitor, PUBLIC included. One exception
   or syntax error in it takes the whole site down, not one route.
2. **It cannot be audited.** "Who can see what on this install?" is unanswerable without
   executing closures. The catalog cannot show it, the install gate cannot check it, the
   planner cannot reason about it.
3. **The obvious fix is the real hole.** Move `when` into JSON as a string and call it, and
   `"when": "system"` is `call_user_func('system', $ctx)`. A careless string form is an
   arbitrary-function-call vector.

So the manifest is pure data, and every name in it that resolves to code is constrained:

- **Names are relative to the concept's own namespace.** The manifest lives in
  `concepts/profiles/`, so the loader builds the class name itself:
  `app\concepts\profiles\` + what the manifest says. A name must match
  `^[A-Z]\w*::[a-z]\w*$` — **no backslashes**. There is no syntax for reaching outside the
  concept: `"system"` and `"\\app\\Bean::exec"` both fail the shape check before anything is
  resolved. No allowlist is needed, so there is no allowlist to get wrong.
- **One qualified form, for a required concept:** `"vendors:Access::isVendorCtx"`. The prefix
  must name a concept in this manifest's `requires.concepts`; it resolves into *that*
  concept's namespace under the same shape rule. This is how `when` points at the predicate
  the owning concept's controllers actually call, rather than a copy — and it makes the
  dependency visible in the manifest.
- **`when`** takes `(array $ctx): bool`. **`provider`** takes `(array $ctx): array`.
  **`save`** takes `(OODBBean $bean, array $input): void` and throws on invalid input.
  Nothing is ever passed to `eval`, and nothing from a request ever selects the callable.
- **The top-level `offerTypes` map's values are relative class names** (`"ClassOffer"`),
  checked with `is_subclass_of(…, OfferType::class)`. That map belongs to the storefront, not
  to the core runtime, which keeps the raw manifest available for it to read.
- **`view` is a filename**, resolved under the concept's `views/` and `realpath`-checked to
  stay there. No `../`.
- **`level` is a name** (`"MEMBER"`), resolved through `LEVELS`. An unknown name is an error.
- **`collect` data is static JSON** — no closure builds it.
- **All of this is verified at enable time.** A concept with an unresolvable or disallowed
  name refuses to enable, naming the entry. It is not re-discovered per request.

This also enforces a rule that was previously only stated: `when` can only point at a named
method in a concept's own `lib/` — the same one that concept's controllers call — so the
view's gate and the route's gate cannot drift into two implementations.

What this does **not** fix: a concept is still copied code, some of it agent-written, running
with the instance's privileges. Data-only registration shrinks what runs *at load*; it does
not vet what the concept's own controllers do. That protection is the install
gate plus a human reading the concept before it enters the catalog.

#### Who sees a slot

Serenity gates these by hand today, inconsistently: nav checks
`$__isAdmin || EventAccess::isVendor($__me)`, "Edit bio" is vendor-only, and the slot picker
must reach logged-out guests. There is no sensible default audience. Three layers, all of
which must pass:

| Layer | Question | How |
|---|---|---|
| Install flag | Does this install have the concept? | concept enabled |
| `level` | Is this member privileged enough? | declared per registration, `Flight::hasLevel()` |
| `when` | Right kind of member / right row? | predicate — `isVendor`, "owns this event" |

- **`level` is required.** A default of PUBLIC leaks an admin panel the first time someone
  forgets; a default of ADMIN silently hides a guest feature. A registration with no `level`
  refuses to enable.
- **The gate is presentation, not access control.** Hiding a button protects nothing. The
  route behind it enforces the same rule — serenity already does: `Events` calls
  `requireVendor()` in every method and scopes rows with `canManageEvent()`.
- **`when` calls the controller's own predicate** (`EventAccess::isVendor()`), never a
  re-implementation. Two copies of an access rule drift, and then a button shows for someone
  the route refuses — or the reverse.
- Slot output varies per member, so rendered slots are never cached across users.

The rules:

- **A form slot is half a feature.** Fields a plugin renders are dropped on save unless the
  controller reads them. A host marks a slot as a *form* slot, and every registration in a
  form slot **must** carry `save` — a constrained `Class::method` name under the same rules
  as `when`, taking `(OODBBean $bean, array $input): void` and throwing on invalid input. The
  host calls the `save` of every registration whose `match`/`when` passed, inside its
  own save transaction, so a plugin's bad input rolls the whole save back. A form-slot
  registration with no `save` refuses to enable. It is not on the `OfferType` contract
  because the owner is often not an offer type — `profiles` saves `teacherId`.
- **Hosts declare their slots; an unknown slot is an error.** Each host lists what it hosts
  under `"hosts"` — `{"slots": {"catalog.edit.fields": {"form": true}, "shop.item.extras": {}},
  "collect": ["nav.sections"]}` — kept apart from `"slots"`, which is what a concept
  *registers*. A name has exactly one host. A concept
  registering against a slot no installed host declares refuses to enable, naming the slot —
  a typo cannot produce a plugin that silently renders nowhere.
- **The host owns the wrapper.** `catalog/edit.php` toggles hard-coded
  `data-catalog-class-field` attributes per type. The host instead wraps each offer type's
  partial in `data-offer-type="<type>"`, and one generic toggle replaces the per-type JS.
- An empty slot renders nothing (absent — legitimate). A registered partial that fails to
  render is broken, and errors.

### Flags are per install

`lib/Feature.php` today is **per member** with a `min_level` — right for "may this person
reach the Explorer sidecar", wrong for "does this install have tickets". EXTEND it with an
install scope (stored as a system setting, the way `site_name` is) rather than writing a
second flag class. One catalog, two scopes.

### No fallbacks

- A product whose `offerType` has no enabled handler **throws**. It is never quietly sold as a
  physical item — that would take money for a class and issue no ticket.
- A concept cannot be disabled while live rows still use it. The refusal names the rows.
- A concept whose `requires` is not enabled refuses to enable, naming what is missing.
- A slot with no registrants renders nothing — that is *absent*, which is legitimate. A
  registered partial that fails to render is *broken*, and errors.

### The manifest

The directory layout is under "How a concept plugs in". The catalog stores that same
directory, so what is browsed is byte-for-byte what installs. `concepts/class/concept.json`:

```json
{
  "name": "class",
  "version": "1.0.0",
  "kind": "offer-type",
  "title": "Classes & dated events",
  "blurb": "Sell seats in dated classes: capacity, recurring series, tickets on payment.",
  "tags": ["events", "classes", "booking", "capacity"],
  "requires": { "concepts": ["storefront", "tickets", "calendar", "vendors"], "lib": ["Mailer"] },
  "provides": { "controllers": ["Events"], "routes": [["events", "*", "MEMBER"]] },
  "offerTypes": { "class": "ClassOffer" },
  "slots": {
    "catalog.edit.fields": {
      "provider": "Portlets::catalogFields",
      "view": "catalog-fields.php",
      "level": "ADMIN",
      "match": { "offerType": ["class"] },
      "save": "Portlets::saveCatalogFields"
    }
  },
  "collect": {
    "nav.sections": {
      "level": "MEMBER",
      "when": "vendors:Access::isVendorCtx",
      "data": { "Events & Classes": [{ "label": "Calendar", "href": "/events" }] }
    }
  },
  "extends": { "product": ["offerType", "classStartsAt", "classCapacity"] },
  "config": [["app", "event_timezone"]],
  "source": { "instance": "serenity-bbdc01", "commit": "<sha>" }
}
```

`requires.lib` names **core** classes the concept calls (`Mailer`), checked to exist at enable
time. There is no `provides.lib`: every name in the manifest resolves inside the concept's own
namespace by construction, so there is nothing to allowlist.

### Copy, don't link

Installing a concept **copies** its files in and records provenance. From then on it is the
instance's own code. That keeps the code-sovereignty promise (the client walks away with
everything), leaves no upgrade pipeline through which a catalog change can break a tenant,
and lets an adopted concept be adapted per client without fighting a link.

### Flat install, bundles, and the root concept

Concepts install **flat** — siblings under `concepts/`, one copy of each per instance. A
concept never carries a private copy of another concept.

Only PHP class names would nest. Everything else a concept owns is global to the instance: a
bean type is one table and RedBean allows one `Model_*` per type; auto-routing is a flat
`/controller/method`; `authcontrol` is keyed by controller and method; slot names and offer
type keys are install-wide. Two private copies of `tickets` would write one `ticket` table
with two models, and claim one `/tickets` URL. Composer — which tiknix already uses — has the
same constraint and the same answer: one flat `vendor/`, one version of each package, and a
conflict refuses to install.

What nesting was reaching for is met flat:

- **Ship together → a bundle.** A manifest with no code:
  `{"kind": "bundle", "name": "events", "includes": ["class", "tickets", "calendar", "profiles"]}`.
  Installing it resolves the list as siblings. The planner ADOPTs `events` as one thing, the
  instance gets one `tickets`, and two bundles that include the same concept share it.
- **Split a large concept → sub-namespaces.** `app\concepts\storefront\checkout\…` is an
  ordinary folder. A part with no table, route or flag of its own needs no manifest. A part
  that *does* need its own flag is a sibling concept that `requires` its parent.
- **Claimed names are install-wide.** Controller names, bean types, slot names it hosts, and
  offer type keys. A concept claiming one that core or another enabled concept owns refuses
  to enable, naming both.

**The instance is the root concept.** A concept directory already has the same layout as an
instance root, and names already resolve relative to the concept. So the instance's own
`controls/`, `lib/` and `views/` are the root concept, with a root manifest declaring the
slots core hosts (`nav.sections`, `member.profile.panels`, …). That is where "hosts declare
their slots" lives for core; `storefront` declares its own in its own manifest.

### A concept must prove it installs

The gate for entering the catalog: install onto an instance **that already has data**, run
the schema and permission seeds, enable the flag, run the concept's own tests, and pass. It
installs clean or it stays out. A blank instance is not enough — see "Schema on a frozen
instance" below.

## One storefront

| | `shop.tiknix` sidecar | serenity, in-instance |
|---|---|---|
| Size | 669 lines | 4,369 lines |
| Beans | `storeproduct`, `storeorder`, `storeorderitem` | `product`, `shoporder`, `shoporderitem` |
| Offer types | none | physical, digital, class, session |
| Charging | hop to core's `Storebroker`, which decrypts the Stripe key | `StripeGateway::forEnv()` in the instance |
| Leaves with the client | no — runs on the platform | yes |
| In use | **no** | one live client |

**Serenity's in-instance storefront becomes the storefront. The sidecar retires.**

Verified 2026-09-21: `shop.tiknix/data/shop.db` holds **zero rows** in all three tables, and
the only `feature.shop` grant is member 1 (root). Retirement is a deletion, not a migration.

The reasons, in order:

1. **Sovereignty.** A sidecar store stays on the platform when the client leaves. "Take your
   app and go" cannot exclude the part that takes the money.
2. **The custody problem it solved is gone.** `Storebroker` exists because a sidecar must not
   hold a Stripe secret. Connections are per-instance now (`CONNECTIONS_PER_INSTANCE.md`), so
   an in-instance store reads its own key. No broker, no HMAC hop, no shared `sso_secret`.
3. It is 6× richer and has a real client on it.

### What to keep from the sidecar

Ideas, not code:

- **Prices are set server-side, never trusted from the browser.** The sidecar enforces this
  structurally (it signs the priced line items). The in-instance store must keep the same
  rule: reprice every line from the database at checkout.
- **Upload paths are built from the resolved instance dir, never from client input**, with a
  size cap (`ShopUpload`). Check serenity's upload path against this when generifying.
- **"Is Stripe usable?" as one status call** (`ShopStripe::status()`) — the storefront should
  render a clear "connect Stripe" state rather than failing at checkout.

### Retired (2026-09-21)

Done ahead of the storefront extraction, because it was unused and its nav entry led nowhere
anyone had a store. Re-verified empty immediately before: zero rows in `storeproduct`,
`storeorder`, `storeorderitem`; the only `feature.shop` grant was member 1.

Removed together: `controls/Storebroker.php`, the `storebroker::*` authcontrol row (seed and
live), the `shop` entry in `Feature::CATALOG`, the `feature.shop` grant, `[sidecar.shop]` in
the live and example config (the nav entry came from that section via the sidecar registry),
and the directories `shop.tiknix/` and the empty `store.tiknix/`. `shop.tiknix` was clean and
fully pushed, so `github.com/mfrederico/store.tiknix` still holds it; the remote was left alone.

What the first search matched and was **not** touched: `ShopifyGateway`, `ShopifyConnector`,
`Connections`, `InstanceAutomations`, the connect-intent code in `Brokerinfo`, and the Shopify
pipelines. Their `'shop'` is the Shopify connector's shop-domain parameter — nothing to do
with the sidecar. Reading before deleting is what told them apart.

Deleting a controller needs `composer dump-autoload -o`: `optimize-autoloader` is on, so the
generated classmap kept `app\Storebroker` and the URL answered 500 (include of a missing
file) until it was regenerated. `vendor/` is per install, so an instance that loses a
controller in an upgrade needs the same.

### Generifying serenity's storefront

It was generated for one client, so unlike the Events cluster it carries domain in its data
model: a `stoneType` filter (`Shop.php:118`), `collection_category`, a wellness disclaimer
partial, packing-slip wording. The work:

- Gemstone attributes → a generic product-attributes mechanism (or stay serenity-only).
- The `offerType` branches → the `OfferType` contract above. This is most of the diff.
- Branding → `Flight::siteName()` / `siteLogo()`.
- Serenity then re-adopts the generic storefront and keeps its gemstone pieces as its own
  code — the proof that adopt-then-adapt works on a real client.

## The catalog — port it from myctobot

`/var/www/html/default/myctobot` already has a plugin system (~5,000 lines, GitHub issue #104),
and it is the half this plan is thin on. Its `PluginManager` never executes plugin code — no
`require`, no boot, no hooks; a plugin there is an ordinary class in `lib/plugins/` with a
sibling JSON manifest. The whole system is **discovery, registry, search and versioning**.
Today's design is all runtime. They meet at the manifest and barely overlap, so this is a
port (as pipelines were), not a second design.

| myctobot | Becomes |
|---|---|
| `PluginScannerService` — finds plugins by a `plugin.json` marker across configured **sources** (GitHub / GitLab repos or orgs, via the install's own `github` connection), exponential backoff on 403/429 | Discovery by a `concept.json` marker. The catalog is a **set of sources**, not one repo: a shared source for generic concepts, and a private source per client for concepts that are theirs alone — which is also how "Ownership of extracted code" stays enforceable. |
| `PluginSearchService` — relevance scoring: exact name 100, name contains 80, description 50, tags 30 | `concepts_search` v1, as is. |
| `PluginVersionService` + `Model_Pluginversion` — `update_available`, `update_type` (major / minor / patch), version history; unit-tested | The manifest gains `version`. Installed concepts get an **"update available" notice, inform only** — copy-install never auto-applies. |
| `"provides": {"auth": ["google"]}` | Capability-keyed `provides` — a vocabulary the planner matches on, aligned with the existing `whatprovides("<concept>")`. |
| `"config": {"key": "Required: …"}` | The settings declaration ("Smaller holes → Settings"). |
| `PluginRegistryCache` — per-workspace keys, TTL refresh, keeps the last good data when a source fails and records the error with a timestamp | The registry cache, with the rule below. |
| `Pluginsources` / `Pluginregistry` / `Plugins` controllers + views | The admin screens and the browsable catalog page. |

Carry over carefully:

- **Stale is allowed only when it is said.** `concepts_search` returns source errors and their
  timestamps *with* the results, so the planner can report "results as of 14:02; source X
  failing since". No reachable source **and** no cache is an error — never an empty result,
  which would silently turn every ADOPT into a NEW.
- **Not APCu from cron.** myctobot's cron refreshes APCu from the CLI. APCu is per-SAPI — the
  CLI can never invalidate the web's copy (see the query-cache notes). Use the valkey version
  store tiknix already has.
- **`Bean::`, not `R::`.** `PluginManager` uses `R::` directly; the port goes through the
  wrapper, and the validation hook will insist on it anyway.

### The catalog as built (v1)

Narrower than the port above, on purpose: the spine first — extract, scrub, publish, find,
install — with one source. What exists:

```
extract   author concepts/<name>/ in a worktree of the origin instance
lint      clitool --concept-lint=<name> --from=<worktree> --origin=<instance root>
publish   clitool --concept-publish=<name> --from=… --origin=…      (control plane)
find      MCP concepts_search / concepts_get        (any install; planner-facing)
install   clitool --concept-install=<name>          (any install; copies, never enables)
enable    clitool --concept-verify=<name> → --concept-enable=<name>
```

- **One source: a directory.** `[concepts] catalog_dir` on the control plane, its own git
  repository beside core. Inside core's repository every instance clone would inherit the
  whole catalog. myctobot's multi-source scanner (repos, orgs, a private source per client)
  is still the plan; it slots in behind `ConceptCatalog` without changing a caller.
- **An instance asks over HTTP** with its own broker key — `GET /concepthub/{search,get,bundle}`.
  No new credential. `BrokerService::keyFromRequest()` is now the one implementation of that
  check; it had been written out twice (`Brokerinfo`, `Publish`) and the copies had drifted.
- **A concept travels as data**, `{name, version, files: {path: base64}}`, not an archive, so
  every path is validated on the way out and again on the way in. The installer never trusts
  the sender: a bundle carrying `../evil.php` writes nothing at all (tested).
- **The scrub gate is `ConceptLint`.** Errors block publishing: the origin's slug, app name
  or hostname anywhere (comments included), secret-shaped strings, absolute server paths, raw
  `R::`, the wrong namespace, a core class not in `requires.lib`, a bean neither owned
  (`provides.beans`) nor declared (`uses.beans`), another concept not in `requires.concepts`,
  a symlink. Run against serenity's untouched `EventCalendar.php` it flags lines 22, 105 and
  406 — the three found by hand in the survey.
  `--origin` is separate from `--from` because extraction happens in a worktree, whose
  directory is a task id and says nothing about the client.
- **A published version is immutable.** Same version, different contents → refused; bump it.
  Otherwise "which calendar 1.0.0 did that instance install?" has no answer. That is the
  whole of versioning so far — no update notices yet.
- **Install copies and records provenance** in `.installed.json` (never published back),
  builds beside the target and renames, refuses to overwrite, never enables, never chmods.
- **Search** is myctobot's weights (name 100/80, title 50, tags, blurb 30) applied per query
  word and summed, because a planner asks in phrases. "let staff put their work shifts in
  their phone calendar" finds `calendar`; "accept bitcoin payments" is a real no-match.
- **Unreachable is never empty.** An outage or a refused key throws, and `concepts_search`
  says `FAILED — this is NOT an empty result`.

A naming trap found the hard way: PHP class names are case-insensitive and `app\` maps to both
`controls/` and `lib/`, so `controls/Conceptcatalog.php` and `lib/ConceptCatalog.php` were one
class — the controller called itself and every authenticated request answered 500. Hence
`Concepthub`, and `tests/unit/CoreNamingTest.php` to keep core from doing it again.

**Open: how a build agent installs.** `--concept-install` needs the instance's
`conf/broker.ini`, which is gitignored and so absent from a build worktree. Either the
executor installs into the worktree before the agent starts (it runs with the instance's
config), or an MCP tool does it on the agent's behalf. Decide this with ADOPT, below.

**Open: concept settings.** `calendar` needs one system setting
(`concept.calendar.timezone`) and there is no screen to set it — it is documented as a seed
one-liner. The generated settings form ("Smaller holes") is the fix.

## ADOPT in decompose

Two MCP tools, served by **core** (the catalog crosses instances; instances already reach
core over HTTP with a pre-minted key):

- `concepts_search("<capability>")` → ranked matches: name, kind, blurb, tags, screenshot
  URL, provides, requires.
- `concepts_get("<name>")` → the full manifest + file list.

The planner's classification gains a fourth option, tried after the local inventory:

> **REUSE** (already here) → **EXTEND** (already here, add to it) → **ADOPT** `<concept>`
> (in the catalog) → **NEW** (justify why none of the above fit)

An ADOPT becomes a task: *install `<concept>`, enable it, adapt it for X.* Required concepts
are emitted first and chained via `depends_on`. `reuses` records `concept/<name>`.

Small concepts make this work. A planner asked for "staff shift scheduling" matches
`availability` + `profiles` + `calendar`; it would never have matched a 5,400-line "Events".

Matching starts as myctobot's scored keyword search (above). The `gpt-oss:120b-cloud`
semantic pass (already wired into `check-duplicates.php --ollama`) can rank later if needed.

Failure handling is the rule under "The catalog": stale results are returned **with** their
source errors, and no source plus no cache is an error the planner reports — never an empty
result.

## Augment-decompose

Most of the plumbing exists: tasks hang off a parent (`parentTaskId`), consolidate already
re-plans and replaces a set (`$supersedeIds`), `PlanRemediator` links a re-plan to its
original (`replanOf`). What is missing is an **additive** mode:

1. The main task gets an **Augment** action with a box for extra detail.
2. The planner runs with the original goal, the existing task tree with statuses, and the
   new detail.
3. It submits **only new tasks**; `depends_on` may point at existing task ids.
4. `PlanIngestor` appends them under the same parent rather than creating a new plan.

The existing "is the goal already met?" check applies unchanged, so augmenting with nothing
new invents no work. Augment is independent of everything above and can ship any time.

## Gaps the runtime has to close

Checked against the code 2026-09-21. The first five break a real install if ignored.

### Schema on a frozen instance

`bootstrap.php:330` calls `R::freeze()` in production. "RedBean auto-creates a model's table
on first store" is true only unfrozen — enabling a concept on a live instance creates **no
tables**, and the columns it `extends` onto `product` never appear; the first store throws.

So enable has an explicit schema step: unfreeze, run the concept's schema seed, refreeze. The
seed declares every column **with its type**. RedBean widening a column rebuilds the SQLite
table and drops the rows (dates must be sized as text — see the widen trap in memory), and a
concept adding columns to a populated `product` table is exactly where that bites. This is why
the install gate needs an instance with data.

Data outlives the code: disabling or deleting a concept never drops its tables or columns.

### One model per bean

`Model_Product` validates per offer type on `update()` — `digital` at line 78, `class` at 89,
`session` at 123. RedBean allows one model class per bean type, so three concepts cannot each
contribute theirs. The `OfferType` contract gains:

```php
public function validateProduct(OODBBean $product): void;   // throws, naming the reason
```

and `Model_Product::update()` dispatches to it. Bean types join the install-wide claimed
names. Concept models are global-namespace `Model_*` classes, so the concept autoloader has to
resolve those from enabled concepts' `models/` as well as the `app\concepts\…` namespace.

### Tooling that cannot see `concepts/`

`mcptools/Introspector` globs `controls/*.php`, `models/Model_*.php` and `lib/*.php`. Once a
concept is installed, the planner's own inventory does not list it — so the planner classifies
the capability NEW and builds it again, beside the concept. Same blind spot in
`check-duplicates.php` (`controls, services, lib, models`), the `R::` validation hook, and
`whatprovides` / `describe`. Each must walk enabled concepts' directories, and `reuse_digest`
should list installed concepts as a section of their own.

### Core files a concept currently patches

Serenity added `sendClassTickets()` to `lib/Mailer.php`. A concept owns its email templates
and calls a generic send; it never edits core. The same goes for `views/admin/edit_member.php`
and `views/member/profile.php` (the `isVendor` fields) — those become slots in the root
manifest.

### No tests on the client that takes money

Serenity has no `tests/` directory. Build step 3 refactors checkout — seat decrements inside a
transaction, ticket issue on paid, void on cancel — on a live client, and calls it
behaviour-preserving with nothing that would notice otherwise. Characterization tests for
checkout, paid, cancel and return come **before** the refactor. Concepts ship their tests;
the install gate runs them.

### Install is a build task, never a web action

The instance pool user (`tiknix-i<id>`) holds `rwx` on `controls/`. A web "Install" button
would be the web process writing executable PHP into its own tree. Install always goes through
a build task — worktree, review, merge — which is what ADOPT already produces. The web UI only
flips the flag, and only for a concept whose directory is already present and committed.

### Smaller holes

- **Assets.** `concepts/<name>/` is outside `public/`, so its JS and CSS are not served.
  Either inline in the view, or the install task copies `assets/` to
  `public/concepts/<name>/`. Undecided.
- **Settings.** The manifest asks for `[app] event_timezone`, but `config.ini` is gitignored,
  holds secrets, and editing it raises the config-drift warning. Concept settings are system
  settings (as `site_name` is), declared in the manifest with a type, rendered as a generated
  settings form. A required setting that is unset is an error naming the setting, as
  `EventCalendar` already does.
- **Non-web entry points.** Webhooks, scheduled jobs, MCP tools and pipeline steps have no
  place in the manifest yet, and the loader must run for CLI and cron, not only the web
  bootstrap. Not urgent: serenity's one cleanup (`releaseStaleSeatHolds()`) runs lazily inside
  requests.
- **Slot order and slot stability.** Two concepts in one slot need a declared `order`. Slot
  names and `$ctx` shapes are a public interface once concepts depend on them, so `requires`
  carries a contract version (`"storefront": 1`), not only a name.

### Ownership of extracted code

The landing page promises the client full source in hand, and serenity's code was generated
for that client. Before it enters a catalog other clients install from:

- the terms say tiknix retains the right to reuse **generic** components, and
- extraction includes a scrub — no client data, seed rows, credentials, branding, or
  business rules particular to that client.

Settle this before the first extraction, not after.

## Build order

1. **Concept runtime** — the manifest loader and its name-shape checks, install-scoped flags
   in `Feature`, the enabled-concepts roots in `defaultRoute`'s containment check, the
   `app\concepts\<name>\` autoloader, `Concepts::slot()` / `collect()` with providers, and
   the install gate (against an instance with data).
2. **First capability: `calendar`.** The most self-contained piece found: `EventCalendar`
   makes zero `Bean::` calls and touches no other app class. It is coupled by *shape*, not by
   calls — it reads product fields directly (`classStartsAt`, `classLocation`, `classFormat`,
   `sessionMinutes`, `teacherId`), so nothing but a serenity product can be handed to it. The
   extraction is to give it a neutral input (title, start, end, location, description, url)
   and let each caller map its own bean onto that. That also collapses its two parallel API
   families (`window`/`vevent`/`links` and `bookingWindow`/`bookingVevent`/`bookingLinks`)
   into one. Small, real, and it proves the runtime before the storefront.
3. **Characterization tests on serenity** — checkout, paid, cancel, return, ticket issue and
   void, slot hold and release. Serenity has none today, and step 4 is unsafe without them.
4. **`OfferType` contract inside serenity.** Refactor the `offerType` branches behind the
   interface *in place*, with serenity still live and step 3's tests green before and after.
   This is the risky step: it touches the code that takes money.
5. **Extract `storefront`**, generified and scrubbed. Then `digital`, `class`, `session`, and
   the remaining capabilities (`tickets`, `availability`, `profiles`, `vendors`), and the
   `events` bundle over them.
6. ~~Retire the sidecar~~ — done 2026-09-21, early: it was unused (see "Retired").
7. **Port the catalog from myctobot** — sources, scanner, scored search, versions, registry
   cache — then `concepts_search` / `concepts_get` over it, and ADOPT in the planner prompt.
8. **Browsable catalog page** with screenshots (myctobot's registry views as the start).

Step 1 includes teaching `Introspector`, `check-duplicates.php` and the validation hook to
walk enabled concepts — otherwise the first installed concept is invisible to the planner.

Before step 5: the ownership terms and the extraction scrub ("Ownership of extracted code").

Augment-decompose slots in anywhere.

## Open questions

- **Product attributes.** Does the generic storefront get an attributes mechanism, or do
  domain fields like `stoneType` always stay instance-side?
- **Applying an upstream update.** Versions and the inform-only "update available" notice are
  settled (ported from myctobot). Open: once a client has adapted a copied concept, what does
  "take the update" mean — a build task that merges upstream into the adapted copy?
- **Who may enable a concept** — instance owner, or admin only?
