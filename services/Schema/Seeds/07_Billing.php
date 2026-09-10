<?php
/**
 * 07_Billing.php — the columns that tie a member to its billing tenant.
 *
 * Phase 1 of BILLING_PLAN.md. These columns are READ by ProjectQuota and the usage
 * endpoint; nothing enforces a cap and nothing charges anybody yet.
 *
 * Declared with a padded GHOST bean, the same way 03_EmailThread declares chat-era
 * columns against the live `member` table. The ghost is stored so fluid mode emits the
 * columns, then handed to $_defer so the builder trashes it — the seed leaves no member
 * behind. 01_Member cannot do this job: it is guarded by !$_tableCheck('member'), so on
 * every install that already has members it never runs at all.
 *
 * EVERY TEXT COLUMN IS PADDED, INCLUDING THE DATE. RedBean types a column from the first
 * value it is handed; a 'Y-m-d H:i:s' string is typed NUMERIC, and NUMERIC still has room
 * to widen. SQLite cannot ALTER a column type, so a widen REBUILDS the table — which is
 * what once emptied `member` on mileage. Padded text is the widest type the writer has,
 * so nothing written later can force one. See 01_Member.php for the full account.
 *
 * Backfill is `free`, which is the honest current state: nobody is paying yet. The
 * grandfather migration (phase 4) moves existing members to `legacy` with a cap matching
 * what they already hold, and it runs BEFORE any gate is switched on.
 */

use \RedBeanPHP\R;

// ---- billing columns on member ---------------------------------------------------

$billingGhost = R::dispense('member');
$billingGhost->email = 'schema-ghost-billing@example.invalid';

// What this member is called in the billing service. A string id issued by another
// system, so `_eid` — `_id` is reserved for RedBean's integer foreign keys, and
// `billing_tenant_id` would have RedBean hunting for a bean type `billing_tenant`.
$billingGhost->billing_tenant_eid = str_repeat('x', 64);

// 'free' | 'pro' | 'legacy'
$billingGhost->plan_tier = str_repeat('x', 32);

// Set for grandfathered accounts; 0/NULL means "derive the cap from the tier".
$billingGhost->plan_project_cap = 0;

// When Stripe confirmed a usable card, via SetupIntent. Padded, not dated — see above.
$billingGhost->card_validated_at = str_repeat('x', 40);

R::store($billingGhost);
$_defer($billingGhost);

// ---- backfill --------------------------------------------------------------------
//
// A bulk UPDATE over every row is one of the few things beans genuinely cannot express
// without loading the table. It touches only rows with no value, so an account that
// phase 4 has already moved to 'legacy' is left exactly as it was: a re-run of the
// builder must never quietly hand somebody's grandfathered account back to the free tier.
R::exec("UPDATE member SET plan_tier = 'free' WHERE plan_tier IS NULL OR plan_tier = ''");

unset($billingGhost);
