# Concepts: pluggable features, one storefront, augment-decompose

**Designed, not built.** Written 2026-09-21, revised the same day after surveying serenity
and the shop sidecar. Four related pieces:

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

So a slot registration can narrow itself two ways, both pure data: an `offerTypes` list, and
the `when` predicate. `profiles` registers the teacher select once, with
`"offerTypes": ["class", "session"]`; `calendar` registers add-to-calendar with
`"when": "app\\EventCalendar::isEligibleCtx"`. An offer type registers its own field block the
same way — there is one way to put something on a page.

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

- **Files install into the normal directories** — `controls/`, `lib/`, `models/`, `views/` —
  plus its manifest at `concepts/<name>/concept.json`. Flight's auto-routing and the
  autoloader are untouched.
- **Registration is data, never code.** The manifest *is* the registration. The loader reads
  JSON and executes nothing a concept supplies — see "Registration is data" below.
- **Off means inert.** A disabled concept's manifest is never read into the registry, so it
  contributes no offer type, no partials, no handlers.
- **Controllers declare their concept**: `const CONCEPT = 'tickets';`. Base `Control` refuses
  the route while the flag is off — auto-routing makes any controller file reachable the
  moment it exists, so the gate has to be in the base class, not left to each controller.
- **Permissions follow the flag.** A concept's authcontrol seeds run on enable, via
  `PermissionCache::seedRule()`.

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

Registered in the concept's `concept.json`:

```json
"slots": {
  "shop.item.extras": {
    "view": "views/concepts/session/slot-picker.php",
    "level": "PUBLIC",
    "offerTypes": ["session"]
  },
  "catalog.edit.fields": {
    "view": "views/concepts/profiles/teacher-select.php",
    "level": "ADMIN",
    "offerTypes": ["class", "session"],
    "save": "app\\Profiles::applyCatalogForm"
  },
  "member.profile.panels": {
    "view": "views/concepts/profiles/edit-bio.php",
    "level": "MEMBER",
    "when": "app\\EventAccess::isVendorCtx"
  }
},
"collect": {
  "nav.sections": {
    "level": "MEMBER",
    "when": "app\\EventAccess::isVendorCtx",
    "data": { "Events & Classes": [{ "label": "Calendar", "href": "/events" }] }
  }
},
"offerTypes": { "class": "app\\concepts\\ClassOffer" }
```

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

- **`when` is a name, never an expression.** It must match `Class::method` exactly, the class
  must be in the `app\` namespace **and** be listed in `provides.lib` by this concept or one
  it `requires`, and the method takes `(array $ctx): bool`. A bare function name fails the
  shape check and is never called. Nothing is ever passed to `eval`, and nothing from a
  request ever selects the callable.
- **`save` follows the same rules as `when`** — same shape, same namespace, same
  `provides.lib` allowlist — with the signature `(OODBBean $bean, array $input): void`.
- **The top-level `offerTypes` map's values are class names**, checked with
  `is_subclass_of(…, OfferType::class)`. A slot's `offerTypes` *filter* is a plain list of
  type keys, each of which must be a type some enabled concept provides.
- **`level` is a name** (`"MEMBER"`), resolved through `LEVELS`. An unknown name is an error.
- **`collect` data is static JSON** — no closure builds it.
- **All of this is verified at enable time.** A concept with an unresolvable or disallowed
  name refuses to enable, naming the entry. It is not re-discovered per request.

This also enforces a rule that was previously only stated: `when` can only point at a named,
reviewed method in `lib/` — the same one the controller calls — so the view's gate and the
route's gate cannot drift into two implementations.

What this does **not** fix: a concept is still copied code, some of it agent-written, running
with the instance's privileges. Data-only registration shrinks what runs *at load*; it does
not vet what the concept's own controllers do. That protection is the blank-instance install
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
  host calls the `save` of every registration whose `offerTypes`/`when` matched, inside its
  own save transaction, so a plugin's bad input rolls the whole save back. A form-slot
  registration with no `save` refuses to enable. It is not on the `OfferType` contract
  because the owner is often not an offer type — `profiles` saves `teacherId`.
- **Hosts declare their slots; an unknown slot is an error.** Each host lists its slots in
  its manifest (`"slots": ["catalog.edit.fields", "shop.item.extras", …]`). A concept
  registering against a slot no installed host declares refuses to enable, naming the slot —
  a typo cannot produce a plugin that silently renders nowhere.
- **Partials get only the vars passed in** — no leaking of the host view's local scope. That
  leak is exactly what makes extraction hard today.
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

```
concepts/<name>/
  concept.json        manifest
  screenshot.jpg      captured by the Playwright job that feeds the landing showcase
  files/              laid out as they install
  seeds/              numbered seeders: authcontrol rows + starter data
```

```json
{
  "name": "class",
  "kind": "offer-type",
  "title": "Classes & dated events",
  "blurb": "Sell seats in dated classes: capacity, recurring series, tickets on payment.",
  "tags": ["events", "classes", "booking", "capacity"],
  "requires": { "concepts": ["storefront", "tickets", "calendar"], "lib": ["Mailer"] },
  "provides": {
    "controllers": ["Events"],
    "lib": ["app\\concepts\\ClassOffer"],
    "routes": [["events", "*", "MEMBER"]]
  },
  "offerTypes": { "class": "app\\concepts\\ClassOffer" },
  "slots": { "catalog.edit.fields": { "view": "views/concepts/class/catalog-fields.php", "level": "ADMIN", "offerTypes": ["class"], "save": "app\\concepts\\ClassOffer::applyCatalogForm" } },
  "extends": { "product": ["offerType", "classStartsAt", "classCapacity"] },
  "config": [["app", "event_timezone"]],
  "source": { "instance": "serenity-bbdc01", "commit": "<sha>" }
}
```

`provides.lib` is not descriptive — it is the allowlist. A `when`, `save` or `offerTypes` name
may only resolve into a class listed there, by this concept or one it `requires`. So what the
catalog says a concept contains and what the concept is permitted to call cannot drift apart.

### Copy, don't link

Installing a concept **copies** its files in and records provenance. From then on it is the
instance's own code. That keeps the code-sovereignty promise (the client walks away with
everything), leaves no upgrade pipeline through which a catalog change can break a tenant,
and lets an adopted concept be adapted per client without fighting a link.

### A concept must prove it installs

The gate for entering the catalog: install onto a **blank instance**, run the seeds, enable
the flag, load its routes, and pass. It installs clean or it stays out.

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

### Retiring it

Remove together, so nothing is left pointing at a store that no longer exists:
`shop.tiknix/`, the empty `store.tiknix/`, `controls/Storebroker.php` + its `storebroker::*`
authcontrol rows, the `shop` entry in `Feature::CATALOG`, `[sidecar.shop]` in config, the nav
entry in `views/layouts/header.php`, and the references in `Brokerinfo.php`,
`Connections.php`, `InstanceAutomations.php`. `ShopifyGateway.php` also matched the search
and needs reading before it is touched — it may be unrelated.

### Generifying serenity's storefront

It was generated for one client, so unlike the Events cluster it carries domain in its data
model: a `stoneType` filter (`Shop.php:118`), `collection_category`, a wellness disclaimer
partial, packing-slip wording. The work:

- Gemstone attributes → a generic product-attributes mechanism (or stay serenity-only).
- The `offerType` branches → the `OfferType` contract above. This is most of the diff.
- Branding → `Flight::siteName()` / `siteLogo()`.
- Serenity then re-adopts the generic storefront and keeps its gemstone pieces as its own
  code — the proof that adopt-then-adapt works on a real client.

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

Matching starts as keyword/tag search over blurbs. The `gpt-oss:120b-cloud` semantic pass
(already wired into `check-duplicates.php --ollama`) can rank later if needed.

A catalog that cannot be reached is an **error the planner reports**, not an empty result —
otherwise an outage silently turns every ADOPT into a NEW and nobody notices.

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

## Build order

1. **Concept runtime** — `concepts/` registration, install-scoped flags in `Feature`, the
   `CONCEPT` gate in base `Control`, the manifest, and the blank-instance install gate.
2. **First capability: `calendar`.** The most self-contained piece found: `EventCalendar`
   makes zero `Bean::` calls and touches no other app class. It is coupled by *shape*, not by
   calls — it reads product fields directly (`classStartsAt`, `classLocation`, `classFormat`,
   `sessionMinutes`, `teacherId`), so nothing but a serenity product can be handed to it. The
   extraction is to give it a neutral input (title, start, end, location, description, url)
   and let each caller map its own bean onto that. That also collapses its two parallel API
   families (`window`/`vevent`/`links` and `bookingWindow`/`bookingVevent`/`bookingLinks`)
   into one. Small, real, and it proves the runtime before the storefront.
3. **`OfferType` contract inside serenity.** Refactor the `offerType` branches behind the
   interface *in place*, with serenity still live. Behaviour-preserving; this is the risky
   step and it happens where there is a real client to catch regressions.
4. **Extract `storefront`**, generified. Then `digital`, `class`, `session`, and the
   remaining capabilities (`tickets`, `availability`, `profiles`, `vendors`).
5. **Retire the sidecar** — once `storefront` installs clean on a blank instance.
6. **`concepts_search` / `concepts_get`** + ADOPT in the planner prompt.
7. **Browsable catalog page** with screenshots.

Augment-decompose slots in anywhere.

## Open questions

- **Product attributes.** Does the generic storefront get an attributes mechanism, or do
  domain fields like `stoneType` always stay instance-side?
- **Concept versions.** Copy-install means no upgrades. Is provenance (source commit) enough,
  or do we want a "this concept has changed upstream" notice — inform only, never auto-apply?
- **Who may enable a concept** — instance owner, or admin only?
