# Paid Release: card-on-file signup, 1 free project, $499/mo for 10

Status: **plan for review — nothing built, nothing enabled.**

The goal, as specified: a visitor creates an account only after a card is on file and
validated; they land in the builder; their first project is free; a second project
requires the $499/month plan; that plan covers 10 projects **including projects shared
to them**, so accounts cannot swap projects to stay free.

---

## 1. What exists today, and what doesn't

Grounded in the current tree, not assumed:

| Piece | State |
|---|---|
| `lib/StripeGateway.php` | **Not reusable for this.** It is the *instance-side* client for a *customer's own* connected Stripe account (BYO-Stripe storefronts). Nothing calls it yet. |
| Stripe SDK | **Absent.** Not in `composer.json`. `symfony/http-client` is available. |
| `[stripe]` config | **Absent.** Zero mentions in `conf/config.ini`. |
| Platform billing | **Does not exist.** No subscription, invoice, or payment tables for tiknix's own customers. `shopsubscription` belongs to the BYO-Stripe storefront feature. |
| Signup | `Auth::register` / `Auth::doregister`, gated by the `registration_enabled` setting, rate-limited 5/hour/IP. Creates the member immediately. |
| Project creation | `ProvisionService::create`, `::fork` |
| Project sharing | `ProvisionService::share` — an `instance_team` m2m; every member of a shared team reaches the project |
| Teams | `team.owner_id` exists, `teammember` carries per-member roles |
| Quotas | **None anywhere.** The nearest prior art is `lib/Invite.php`, whose quota is worth copying — including its refusal to assume zero when the count query fails |

**Everything in this plan is new build.** There is no half-finished billing layer to
resume.

---

## 2. Decisions I need from you before building

These change the shape of the work. My recommendation is first in each.

### D1. Who is the billing subject when a team is involved? ⚠️ **the big one**

The rule as you stated it — a shared project counts against everyone who can see it —
is airtight against the abuse you described. It also produces this:

> A legitimate 5-person team with **2** shared projects puts all 5 members over the
> free limit. That is **5 × $499 = $2,495/month** for two projects.

That is the difference between anti-abuse and punishing the exact collaboration the
$499/10-project tier appears designed to sell.

- **(a) Recommended — count against the *account that owns the team*.** A project shared
  into a team counts once, against the team owner's cap of 10. Invited collaborators
  don't each need a subscription. Sharing into a team owned by a **free** account still
  counts against every member individually — so the two-free-accounts-swapping case is
  still blocked, because neither of them owns a paid team.
- **(b) As specified — count against every member who can see it.** Maximum abuse
  resistance. Charges legitimate teams per head.

The abuse case you're defending against is *separate free accounts trading projects*.
Option (a) blocks that and still sells seats to real teams. But it's your commercial
call, and (b) is a one-line difference in the counting query.

**I ran the (b) query against the live database.** This is not hypothetical:

| Member | Owns | **Counts** | At a free cap of 1 |
|---|---|---|---|
| `mfrederico@gmail.com` | 5 | **13** | blocked |
| `fabianduarte09@gmail.com` | 5 | **6** | blocked |
| `pd@earlywater.com` | 3 | **3** | blocked |
| `testinvite@clicksimple.com` | **0** | **4** | **blocked** |
| `robflanagan@gmail.com` | 1 | 1 | ok |
| `m.fred@clicksimple.com` | 0 | 1 | ok |

Read the fourth row carefully. `testinvite@clicksimple.com` **owns nothing**. It was
invited to a team, and rule (b) hands it a bill for $499/month for projects that belong
to someone else. Under (b), *accepting an invitation* is what costs money — which also
means anyone can raise a stranger's bill by inviting them to a team.

That is the strongest argument for (a), and it came out of real rows, not a thought
experiment.

### D2. What happens at project 11?

Undefined in the brief. Recommend: **hard block with an upgrade path** ("contact us" or
a second seat), never a silent extra charge. A plan that auto-bills for the 11th project
is the kind of surprise that produces chargebacks.

### D3. What does non-payment do to existing projects?

Recommend: 7-day grace, then the builder goes **read-only** — projects keep running,
data is never deleted, exports stay available. Deleting or suspending a paying-then-lapsed
customer's work is unrecoverable and the reputational cost far exceeds the $499.

### D4. Is the $499 cliff intended?

Project 1 is free, project 2 is $499/month. There is no middle. That's a defensible
"prosumer to business" jump, but it means a hobbyist's second project costs the same as
a company's tenth. Flagging it once; if it's intended, it's intended.

### D5. Grandfathering — this is not optional

Live data right now:

| Member | Owned projects |
|---|---|
| `mfrederico@gmail.com` (level 1) | 5 |
| `fabianduarte09@gmail.com` (level 100) | 5 |
| `pd@earlywater.com` (level 100) | 3 |
| `robflanagan@gmail.com` (level 100) | 1 |

Plus **10 existing `instance_team` share links.** Switching enforcement on without a
grandfather rule locks real users out of their own work on day one.

Recommend: stamp every existing member with `plan_tier = 'legacy'` and
`plan_project_cap = max(3, their current count)` in the enabling migration. They keep
what they have; the new rules apply to signups from that day forward.

---

## 3. Data model

Additions only — no changes to existing columns.

```
member
  + stripe_customer_eid   TEXT     -- external string id, _eid per the naming rule
  + plan_tier             TEXT     -- 'free' | 'pro' | 'legacy'
  + plan_status           TEXT     -- 'active' | 'past_due' | 'canceled'
  + plan_project_cap      INTEGER  -- 1 free, 10 pro, legacy = grandfathered count
  + card_validated_at     NUMERIC

pendingsignup              -- signup in flight, before the member exists
  email, password_hash, first_name, last_name
  token TEXT               -- opaque, what the browser carries
  stripe_customer_eid TEXT
  status TEXT              -- 'awaiting_card' | 'completed' | 'expired'
  created_at, expires_at   -- 24h

subscription
  member_id INTEGER
  stripe_subscription_eid TEXT
  status TEXT, current_period_end NUMERIC, cancel_at_period_end INTEGER

billingevent               -- every webhook, for idempotency and audit
  stripe_event_eid TEXT UNIQUE
  type TEXT, payload TEXT, processed_at NUMERIC
```

Ships as `services/Schema/Seeds/NN_Billing.php` (idempotent, run by
`clitool.php --build`). RedBean creates tables on first store, so no `CREATE TABLE`.
New routes get `authcontrol` rows via `PermissionCache::seedRule()` **before** anything
fetches them.

---

## 4. Signup with card-on-file

**Stripe Checkout in `setup` mode.** Hosted by Stripe: card data never touches our
servers, SCA/3DS is handled, and it is the shortest path to a release we can defend.

```
1. POST /auth/doregister
     → validate; create `pendingsignup` (NO member yet)
     → create Stripe Customer
     → create Checkout Session (mode=setup, client_reference_id=token)
     → 303 to Stripe

2. Visitor enters card at Stripe; 3DS if the bank asks.

3. Stripe → POST /billing/webhook   ← THE ACCOUNT IS CREATED HERE
     verify signature
     on checkout.session.completed / setup_intent.succeeded:
       create member (plan_tier='free', cap=1, card_validated_at=now)
       mark pendingsignup completed
       record billingevent for idempotency

4. Visitor returns to /auth/complete?token=…
     polls for the member; on success logs in → builder
```

**The account is created by the webhook, not the browser return.** The return URL is a
navigation event the user's browser controls — it can be skipped, replayed, or hand-typed.
Stripe's signed webhook is the only statement about that card we should trust. Building it
the other way produces accounts with no validated card, which is precisely the thing this
feature exists to prevent.

Details that bite:

- **Idempotency.** Stripe retries. Unique index on `billingevent.stripe_event_eid`;
  ignore duplicates.
- **Slow webhooks.** The return page must tolerate the webhook not having landed yet —
  poll with a spinner, don't 404.
- **Abandonment.** `pendingsignup` expires at 24h. Reserve the email while pending so two
  signups can't race, without leaking whether an address is already registered.
- **Card validated ≠ card chargeable.** A SetupIntent proves the card authenticates and
  attaches. It does not guarantee funds later. Don't let the copy overpromise.
- **No $1 pre-auth.** It adds friction and confuses people, and `setup_intent.succeeded`
  already gives us what "validated" should mean here.
- **Failure must be loud.** A webhook that can't verify, a Customer that won't create — log
  an ERROR naming the setting or call, and show the visitor a real message. Never
  half-create the account.

---

## 5. Where the quota is enforced

Four choke points, all in `ProvisionService`, plus team joins:

| Entry point | Why it counts |
|---|---|
| `create()` | New project |
| `fork()` | **Also a new project.** Easy to miss; a fork gate is the first thing anyone routes around |
| `share()` | Checked on the **recipient** side — sharing can push someone else over |
| Team invite accept (`lib/Invite.php` / `Teams`) | Joining a team with shared projects can push the joiner over |
| `delete()` | Frees a slot |

The count, under **D1(b) as specified**:

```sql
SELECT COUNT(DISTINCT i.id)
FROM instance i
LEFT JOIN instance_team it ON it.instance_id = i.id
LEFT JOIN teammember   tm ON tm.team_id = it.team_id AND tm.member_id = ?
WHERE i.status != 'deleted'
  AND (i.member_id = ? OR tm.member_id IS NOT NULL)
```

Under **D1(a) recommended**, the shared leg only counts when the team's owner is not on a
paid plan.

Two rules for the gate itself:

- **One function, called by all five.** Not five copies of the rule — the first
  divergence between them is a free tier that leaks.
- **A failed count blocks, it does not pass.** `lib/Invite.php` already learned this: a
  quota check that treats an error as "0 used" is not a quota. Log the error, refuse the
  action, name the reason.

---

## 6. Subscription lifecycle

- Upgrade on the 2nd project: Checkout in `subscription` mode against a `price_pro`
  ($499/mo), or reuse the saved card with a direct Subscription create — the card is
  already on file, so the second is one click instead of a form.
- Webhooks handled: `customer.subscription.updated`, `.deleted`,
  `invoice.paid`, `invoice.payment_failed`.
- Dunning per **D3**: `past_due` → 7-day grace → read-only. Stripe Smart Retries do the
  chasing.
- Downgrade below 2 projects does **not** auto-cancel; the customer cancels deliberately.
  Auto-cancelling on project deletion is a trapdoor.

---

## 7. Config

```ini
[stripe]
secret_key      = "sk_live_…"
publishable_key = "pk_live_…"
webhook_secret  = "whsec_…"
price_pro       = "price_…"     ; $499/mo
```

Per the no-fallbacks rule: a missing key **throws and names the file and setting**. There
is no test-mode default, and no "unknown" price id — a billing path that silently falls
back is how you charge the wrong amount or, worse, charge nobody and believe you did.

`conf/config.ini` is gitignored but loaded; `conf/config.<slug>.ini` is tracked but not
loaded — the live keys go in the former, on the box, never in git.

Recommend adding `stripe/stripe-php` rather than hand-rolling on `symfony/http-client`:
webhook signature verification alone justifies it, and it is the difference between a
billing integration we can audit and one we hope about.

---

## 8. Phases

Each phase is reviewable and independently revertable. **Nothing is enforced until 5.**

| # | Phase | Contents |
|---|---|---|
| 1 | Foundations | SDK, `[stripe]` config, schema seeder, `billingevent` + idempotency, webhook endpoint with signature verification. No behavior change. |
| 2 | Card-on-file signup | `pendingsignup`, Checkout setup mode, webhook-creates-member, return/poll page. Behind a `billing_signup_enabled` flag, **off**. |
| 3 | Counting, read-only | The counter function + a `/billing` page showing each member their count and cap. **Nothing blocked yet** — this is where we find out what the query says about real accounts before it can hurt anyone. |
| 4 | Upgrade path | Subscription checkout, lifecycle webhooks, dunning, `/billing` self-serve. |
| 5 | Enforcement | Turn on the five gates. Grandfather migration runs **first**. |
| 6 | Release | Pricing page rewrite (it currently advertises **$10/instance** — that has to change and it is a public promise), terms, tax, then `registration_enabled` on. |

Phase 3 is the one I'd insist on: it lets us run the real counting query against real
accounts and see who it would have blocked, while it still costs nothing to be wrong.

---

## 9. Risks worth naming now

- **Charging is not reversible in reputation.** Every gate should fail toward *letting a
  paying person work*, and every refusal should say exactly what to do next.
- **The pricing page contradicts this today** ($10/instance). Until phase 6 it's a public
  statement we'd be breaking.
- **Tax/VAT.** Selling to the EU/UK means Stripe Tax or an accountant, not a TODO.
- **Terms of service** need to exist before the first card. There's no `/index/terms`
  content backing this pricing.
- **Free-tier abuse will move, not stop.** Blocking project-sharing pushes it to multiple
  accounts per person. Signup already rate-limits per IP; email verification is the next
  cheap lever, not more quota complexity.
- **`fork()` and team-invite are the leaks.** If enforcement is ever incomplete, it will be
  one of those two.
- **Under rule (b), an invitation is an attack.** Adding someone to a team raises their
  counted total, so a stranger can push another account over its cap. If (b) is chosen,
  team joins must require the *joiner's* consent against a quota they can see first.

---

## 10. How to reproduce the numbers above

Read-only, safe to run any time:

```bash
php -r '$p=new PDO("sqlite:database/tiknix.db");
$sql="SELECT COUNT(DISTINCT i.id) FROM instance i
      LEFT JOIN instance_team it ON it.instance_id = i.id
      LEFT JOIN teammember tm ON tm.team_id = it.team_id AND tm.member_id = :m
      WHERE i.status != \"deleted\" AND (i.member_id = :m OR tm.member_id IS NOT NULL)";
$st=$p->prepare($sql);
foreach($p->query("SELECT id,email FROM member ORDER BY id") as $m){
  $st->execute([":m"=>$m["id"]]);
  printf("%-34s counted=%d\n",$m["email"],(int)$st->fetchColumn()); }'
```

Note the database is `database/tiknix.db`. Pointing sqlite at `tiknix.db` in the repo root
silently creates an empty file and reports zero rows for every member — an empty result
that looks exactly like "nobody is over quota".
