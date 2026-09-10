# Paid Release: card-on-file signup, 1 free project, $499/mo for 10

Status: **plan for review — nothing built, nothing enabled.**
Revision 2 — rewritten after finding `billing-service`.

A visitor creates an account only after a card is on file and validated; they land in
the builder; their first project is free; a second requires the $499/month plan, which
covers 10 projects **including projects shared to them**.

---

## 1. Verdict: reuse `billing-service`. Don't build a billing layer.

`/var/www/html/default/billing-service` (symlinked as `billing.clicksimple`) is a
production multi-tenant billing service that already bills real money for a live
customer. It covers roughly **four fifths of this project**, including the two parts
that are easiest to get dangerously wrong — Stripe idempotency and invoice math.

| Need | Status in `billing-service` |
|---|---|
| Stripe customers | `StripeService::createCustomer` / `ensureCustomer` |
| **Card on file** | **`createSetupIntent`, `getPaymentMethods`, `detachPaymentMethod` — already built** |
| Invoices + line items | `BillingService`, `BillingRuleEngine` (pure, tested) |
| Charging on anniversary | `bin/billing.php autopay` |
| Webhooks | `services/Stripe/WebhookHandler.php`, `Api/V1/WebhookController` |
| Idempotency | Unique `(tenant_id, period_start)`, Stripe idempotency keys, exactly-once cycle advance, cron `flock` |
| **Discounts** | Per-tenant JSON: `flat` or `percent`, whole-invoice or per line type |
| Plans / pricing | Per-app rate schedules in `conf/rates/<app>.php`, tier support |
| Usage reporting | `UsageFetcher` calls the consumer app's `callback_url` |
| Client library | `packages/billing-client` — `register`, `check`, `getInvoices`, `generateSsoUrl`, … |
| Hosted billing portal | `generateSsoUrl(slug, email, name)` |

What that deletes from revision 1: the Stripe SDK work, the `[stripe]` plumbing, the
`subscription` and `billingevent` tables, webhook signature verification, dunning, and
the whole "phase 4 upgrade path". Those exist.

**The billing entity is ClickSimple LLC** — confirmed, and correct, since that is the
operating company. `StripeService` reads one global `stripe.secret_key`, so everything
bills through ClickSimple's Stripe account. No per-app credentials are needed.

> **Small but real:** customers buy "Tiknix" and will see **CLICKSIMPLE** on their card
> statement. That mismatch is a routine chargeback trigger. Set a statement descriptor
> like `CLICKSIMPLE* TIKNIX` on the tiknix invoices.

---

## 2. Decisions

### ✅ D1 — Resolved: count against the account that owns the team

Agreed. A project shared into a team counts once, against the **team owner's** cap of
10. Collaborators don't each need a subscription. Sharing into a team owned by a *free*
account still counts against every member individually, which is what keeps the
free-accounts-trading-projects abuse closed.

Run against live data, rule (a) versus the originally specified rule (b):

| Member | Owns | Rule (b) | **Rule (a)** | Under (a), free cap 1 |
|---|---:|---:|---:|---|
| `mfrederico@gmail.com` | 5 | 13 | **10** | pays |
| `fabianduarte09@gmail.com` | 5 | 6 | **5** | pays |
| `pd@earlywater.com` | 3 | 3 | **3** | pays |
| `testinvite@clicksimple.com` | 0 | 4 | **0** | **free** |
| `m.fred@clicksimple.com` | 0 | 1 | **0** | **free** |
| `robflanagan@gmail.com` | 1 | 1 | **1** | free |

Rule (a) drops exactly the accounts that own nothing and keeps every account genuinely
holding projects. Nobody gets a bill for someone else's work.

*(The third clause — projects shared by a free account count for everyone — could not be
simulated, because `plan_tier` does not exist yet. It is unreachable on today's data
since nobody has a tier at all.)*

### ⚠️ D2 — Grandfathering by discount: yes, but $0 currently means *no invoice*

Your instinct is right, and there is live precedent: tenant `ltz2` carries a flat
`-$550` discount against a $500 base and legitimately nets $0 in low-usage months. A
grandfathered tiknix member is the same shape — full price on the invoice, a
`Grandfathered` discount line cancelling it.

The gap is the second half of the idea. `bin/billing.php:562`:

```php
if ($subtotal <= 0) {
    cliOutput("  Nothing to bill.", 'info');
    // advance the cycle so it can't freeze …
    continue;               // ← no invoice is created
}
```

**A 100% discount produces no invoice at all today.** Three ways to get what you asked for:

- **(a) Recommended — accept it, and show the value in the portal instead.** Grandfathered
  members are real tenants with a real discount; `getUsagePreview` and the SSO billing
  portal both show "$499 − $499 = $0". They see what they're getting free, we touch no
  money path, and revoking the discount later needs no code.
- **(b) Emit a $0 invoice, opt-in per app or tenant** (`invoice_at_zero`), defaulting off.
  Gets you the literal invoice. Costs a change to a money path, gated by that repo's
  invariant 6 (prove byte-identical output on the money-path tests).
- **(c) Change the branch globally.** ❌ **No.** `ltz2` is a live paying customer that
  nets $0 some months. A global change starts sending them $0 invoices they have never
  received — someone else's production billing altered as a side effect of a tiknix
  feature.

### D3 — Shared deployment, or a second one for tiknix?

Recommend **shared**: add tiknix as a new `app` row with its own rate schedule. App-scoped
config doesn't touch `cannonwms`. It keeps one codebase and one set of money-path tests.

The tradeoff to accept knowingly: tiknix changes land in a service that bills real money
for a live customer. The discipline that follows is non-negotiable — copy the DB, point
`BILLING_CONFIG` at the copy, never run a non-dry `run`/`autopay` against
`database/billing.db`.

### D4 — What happens at project 11?

Recommend a hard block with an upgrade path, never a silent extra charge.

### D5 — What does non-payment do?

Recommend 7 days' grace, then the builder goes read-only. Projects keep running, data is
never deleted, exports stay available.

### D6 — Is the $499 cliff intended?

Project 1 free, project 2 $499, nothing between. Flagging once.

---

## 3. How tiknix maps onto the service

```
billing-service `app`     → slug 'tiknix', rate schedule conf/rates/tiknix.php
billing-service `tenant`  → ONE PER TIKNIX BILLING ACCOUNT (the member who pays)
tenant.callback_url       → https://tiknix.com/api/billing/usage  (project count)
tenant.discounts          → [{"name":"Grandfathered","type":"percent","amount":100}]
```

The tenant *is* the account that owns teams under D1 — the two models line up without a
translation layer.

**Pricing shape.** The rate engine prices per unit, but our plan is a stairstep ($0 for
one project, $499 flat for two through ten). So tiknix does its own tier arithmetic —
which it must anyway, to gate — and reports the *conclusion*:

```php
// conf/rates/tiknix.php
return [
    'name' => 'Tiknix', 'currency' => 'usd',
    'rates' => [
        'pro' => ['description' => 'Tiknix Pro — up to 10 projects', 'unit_price' => 499.00],
    ],
    'usage_mapping' => ['pro_plan' => 'pro'],
];
```

`/api/billing/usage` returns `{"pro_plan": 0}` for a free account and `{"pro_plan": 1}`
for a paying one. No rate-engine changes, and the quota rule stays in one place in tiknix
rather than being half-expressed in a rate table.

---

## 4. Signup with card-on-file

The card step is `StripeService::createSetupIntent` against a customer the service
already knows how to create — so this is wiring, not new payment code.

```
1. POST /auth/doregister
     → validate; create `pendingsignup` (NO member yet)
     → billing-service: register tenant + Stripe customer
     → SetupIntent → collect card (Stripe Elements or Checkout setup mode)

2. Card confirmed with Stripe; 3DS if the bank asks.

3. Stripe webhook → billing-service → tiknix callback
     ← THE ACCOUNT IS CREATED HERE
     create member (plan_tier='free', cap=1, card_validated_at=now)
     mark pendingsignup completed

4. Visitor returns to /auth/complete?token=…
     polls for the member; on success logs in → builder
```

**The account is created from the webhook, not the browser return.** The return URL is a
navigation the user's browser controls — skippable, replayable, typeable. The signed
webhook is the only trustworthy statement that the card was validated. Building it the
other way produces accounts with no validated card, which is the exact thing this feature
exists to prevent.

Details that bite: `pendingsignup` expires at 24h; the return page must tolerate a
webhook that hasn't landed yet; reserve the email while pending without leaking whether
it's registered; a SetupIntent proves the card *authenticates*, not that it will have
funds later — don't let the copy overpromise. No $1 pre-auth: friction for nothing.

---

## 5. Where the quota is enforced

| Entry point | Why |
|---|---|
| `ProvisionService::create` | New project |
| `ProvisionService::fork` | **Also a new project** — easy to miss, first thing anyone routes around |
| `ProvisionService::share` | Checked on the **recipient** side |
| Team invite accept | Joining a team can change a count |
| `ProvisionService::delete` | Frees a slot |

The count under D1(a):

```sql
SELECT COUNT(DISTINCT i.id)
FROM instance i
LEFT JOIN instance_team it ON it.instance_id = i.id
LEFT JOIN team        t    ON t.id = it.team_id
LEFT JOIN member      owner ON owner.id = t.owner_id
LEFT JOIN teammember  tm   ON tm.team_id = t.id AND tm.member_id = :m
WHERE i.status != 'deleted' AND (
      i.member_id  = :m                                 -- you own it
   OR t.owner_id   = :m                                 -- shared into a team you own
   OR (tm.member_id = :m AND owner.plan_tier = 'free')  -- shared by a free account
)
```

- **One function, called by all five.** Not five copies — the first divergence is a free
  tier that leaks.
- **A failed count blocks, it does not pass.** `lib/Invite.php` already learned this: a
  quota check that treats an error as "0 used" is not a quota.

---

## 6. What tiknix still has to build

1. `member` columns: `billing_tenant_slug`, `plan_tier`, `plan_project_cap`, `card_validated_at`
2. `pendingsignup` table + the signup flow above
3. `ProjectQuota` — the counter, and the five gates
4. `/api/billing/usage` callback (returns `pro_plan`) with `callback_key` auth
5. `conf/rates/tiknix.php` + a `tiknix` app row
6. `/billing` page — count, cap, invoices, SSO link to the portal
7. Grandfather migration: register existing members as tenants with a 100% discount

---

## 7. Phases

Nothing is enforced until phase 4.

| # | Phase | Contents |
|---|---|---|
| 1 | Wire up | `billing-client` into tiknix, `tiknix` app row, rate schedule, usage callback. Read-only; nothing bills. |
| 2 | Counting, visible | `ProjectQuota` + `/billing` showing count vs cap. **Nothing blocked.** Verify the query against real accounts while being wrong is still free. |
| 3 | Signup with card | `pendingsignup`, SetupIntent, webhook-creates-member. Behind a flag, off. |
| 4 | Enforcement | The five gates. **Grandfather migration runs first.** |
| 5 | Release | Pricing page rewrite (it advertises **$10/instance** today), terms, tax, statement descriptor, then `registration_enabled` on. |

---

## 8. Risks

- **The service bills real money for someone else.** `ltz2`/cannonwms is live. Every
  tiknix change must be app-scoped, and the zero-subtotal branch must never change
  globally.
- **Charging is not reversible in reputation.** Gates should fail toward letting a paying
  person work; every refusal should say exactly what to do next.
- **The pricing page contradicts this today** ($10/instance) — a public promise.
- **Statement descriptor.** `CLICKSIMPLE` on a card statement for a `Tiknix` purchase.
- **Tax/VAT.** Selling into the EU/UK means Stripe Tax or an accountant, not a TODO.
- **`fork()` and team-invite are the leaks.** If enforcement is ever incomplete, it will
  be one of those two.
- **Free-tier abuse will move, not stop.** Blocking sharing pushes it to multiple accounts
  per person; email verification is the next cheap lever, not more quota complexity.

---

## 9. Reproducing the numbers

Read-only, safe any time. Note the database is `database/tiknix.db` — pointing sqlite at
`tiknix.db` in the repo root silently creates an empty file and reports zero for every
member, which looks exactly like "nobody is over quota".

```bash
php -r '$p=new PDO("sqlite:file:database/tiknix.db?mode=ro");
$q=$p->prepare("SELECT COUNT(DISTINCT i.id) FROM instance i
  LEFT JOIN instance_team it ON it.instance_id = i.id
  LEFT JOIN team t ON t.id = it.team_id
  WHERE i.status != \"deleted\" AND (i.member_id = :m OR t.owner_id = :m)");
foreach($p->query("SELECT id,email FROM member ORDER BY id") as $m){
  $q->execute([":m"=>$m["id"]]);
  printf("%-34s %d\n",$m["email"],(int)$q->fetchColumn()); }'
```
