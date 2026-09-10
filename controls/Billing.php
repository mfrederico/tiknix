<?php
/**
 * Billing — the seam between tiknix and the ClickSimple billing service.
 *
 * The billing service PULLS: once per cycle it calls `usage` for each tenant and prices
 * whatever comes back against conf/rates/tiknix.php on that side. tiknix never pushes a
 * charge and never names a price here.
 *
 * SECURITY MODEL — the same shape as Mcp: the route is reachable (level 101) and the
 * controller does its own auth. It has to be, because the caller is the billing server,
 * which has no tiknix session. Authorisation is a Bearer token compared against
 * `[billing] callback_key`, so PUBLIC here means *reachable*, not *unprotected*.
 *
 * Phase 1 of BILLING_PLAN.md: this endpoint reports, and nothing else happens. No cap is
 * enforced, no tenant is registered, and no invoice exists.
 *
 * @package app
 */

namespace app;

use \Flight as Flight;

class Billing extends BaseControls\Control {

    /**
     * GET /billing/usage/<tenant>?period_start=Y-m-d&period_end=Y-m-d
     *
     * The tenant arrives as a PATH segment, not a query parameter, and that is forced by
     * the caller: UsageFetcher appends its period params with a bare `?`, so a callback
     * URL that already carried a query string would be mangled into `...?tenant=x?period_start=…`.
     * The app-level `{tenant}` pattern substitutes into the path, which stays valid.
     *
     * Reports ONE number — whether this account needs the paid plan. The stairstep ($0 for
     * one project, a flat fee for two through ten) is not a per-unit rate, and tiknix has
     * to know where the line is anyway in order to gate. Sending the conclusion keeps that
     * rule in one place instead of half-expressing it in a rate table on another server.
     */
    public function usage($params = []) {
        if (!$this->billingCallerAuthorised()) {
            Flight::json(['success' => false, 'error' => 'Unauthorized'], 401);
            return;
        }

        $slug = trim((string) ($params['operation']->name ?? ''));
        if ($slug === '') {
            Flight::json(['success' => false, 'error' => 'No tenant in the request path'], 400);
            return;
        }

        $member = Bean::findOne('member', 'billing_tenant_eid = ?', [$slug]);
        if (!$member || !$member->id) {
            // A tenant the billing service knows and tiknix does not is a real fault: it
            // would otherwise be billed on whatever this endpoint happened to return.
            Flight::get('log')->error('Billing: usage requested for an unknown tenant', ['tenant' => $slug]);
            Flight::json(['success' => false, 'error' => 'Unknown tenant'], 404);
            return;
        }

        try {
            $snapshot = ProjectQuota::snapshot((int) $member->id);
        } catch (\Throwable $e) {
            // Never answer a billing question with a guess. A 500 makes the run report the
            // failure and leave the cycle alone; a cheerful `pro_plan: 0` would silently
            // hand out the paid plan for free, and nothing downstream would ever notice.
            Flight::get('log')->error('Billing: refusing to report usage — count failed', [
                'tenant' => $slug, 'member' => (int) $member->id, 'error' => $e->getMessage(),
            ]);
            Flight::json(['success' => false, 'error' => 'Usage unavailable'], 500);
            return;
        }

        $periodStart = (string) (Flight::request()->query->period_start ?? date('Y-m-01'));
        $periodEnd   = (string) (Flight::request()->query->period_end   ?? date('Y-m-t'));

        Flight::json([
            'success' => true,
            'data'    => [
                'app'          => 'tiknix',
                'tenant'       => $slug,
                'period_start' => $periodStart,
                'period_end'   => $periodEnd,
                'usage'        => [
                    // Maps to the 'pro' rate in conf/rates/tiknix.php on the billing side.
                    'pro_plan' => $snapshot['needs_paid'] ? 1 : 0,
                ],
                // Not priced — carried so an invoice can be explained without re-deriving
                // it here weeks later, and so a surprised customer can be answered.
                'meta' => [
                    'projects' => $snapshot['count'],
                    'cap'      => $snapshot['cap'],
                    'tier'     => $snapshot['tier'],
                ],
            ],
        ]);
    }

    /**
     * Bearer token from the billing server, compared against `[billing] callback_key`.
     *
     * A missing or blank key is a HARD failure, never an open door. The tempting version
     * of this — "no key configured, so skip the check" — turns a misconfigured install
     * into a public endpoint that reports every tenant's plan to anyone who asks.
     */
    private function billingCallerAuthorised(): bool {
        $expected = trim((string) Flight::get('billing.callback_key'));
        if ($expected === '') {
            Flight::get('log')->error(
                'Billing: [billing] callback_key is not set in conf/config.ini — refusing every usage request'
            );
            return false;
        }

        $header  = '';
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $header = (string) $v; break; }
        }
        if ($header === '') $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        $token = stripos($header, 'bearer ') === 0 ? trim(substr($header, 7)) : '';
        if ($token === '') return false;

        return hash_equals($expected, $token);
    }
}
