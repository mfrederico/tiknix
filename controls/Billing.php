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
     * GET /billing — what this account holds, and what that would cost.
     *
     * Phase 2 of BILLING_PLAN.md: this page REPORTS. Nothing here blocks a project, and
     * no member is registered for billing yet. It exists so the counting rule can be
     * checked against real accounts while being wrong is still free — which is the whole
     * reason this phase is separate from enforcement.
     */
    public function index() {
        if (!$this->requireLevel(LEVELS['MEMBER'])) return;

        $memberId = (int) $this->member->id;

        try {
            $snapshot = ProjectQuota::snapshot($memberId);
        } catch (\Throwable $e) {
            // Show the failure rather than a zero. A billing page that renders "0 projects"
            // when the query broke is worse than an error page: it is a wrong answer about
            // money, delivered confidently.
            Flight::get('log')->error('Billing page: could not count projects', [
                'member' => $memberId, 'error' => $e->getMessage(),
            ]);
            $this->render('billing/index', [
                'title' => 'Billing',
                'error' => 'We could not work out your project count just now. Nothing has been '
                         . 'charged, and this has been logged for us to look at.',
            ]);
            return;
        }

        /* Lazy catch-all. Six code paths create a member and only some register a tenant,
           so anyone who arrives here without one gets it now rather than being told there
           is nothing to see. Idempotent, and non-fatal: if the billing service is down the
           page still renders, just without a portal link. */
        $tenantSlug = trim((string) ($this->member->billingTenantEid ?? ''));
        if ($tenantSlug === '' && SignupFlow::enabled()) {
            $ensured = SignupFlow::ensureTenantFor($memberId);
            if ($ensured['ok']) $tenantSlug = $ensured['slug'];
        }

        $this->render('billing/index', [
            'title'        => 'Billing',
            'error'        => '',
            'snapshot'     => $snapshot,
            'freeCap'      => ProjectQuota::FREE_CAP,
            'perProject'   => ProjectQuota::PRICE_PER_PROJECT,
            'projects'     => $this->projectBreakdown($memberId),
            // Empty until a member is registered with the billing service, which does not
            // happen until phase 3. The view says so plainly rather than showing a dead link.
            'portalUrl'    => $tenantSlug !== '' ? $this->portalUrl($tenantSlug) : '',
            'tenantSlug'   => $tenantSlug,
        ]);
    }

    /**
     * Which projects are being counted, and why — owned, or reached through a team.
     *
     * The "why" is the point. "You have 13 projects" invites an argument; naming the six
     * that arrived through somebody else's team ends it, and it is the same question
     * support would otherwise have to answer by hand.
     */
    private function projectBreakdown(int $memberId): array {
        $sql = "SELECT i.id,
                       i.display_name,
                       i.slug,
                       CASE WHEN i.member_id = ? THEN 'owned' ELSE 'shared' END AS via,
                       t.name AS team_name
                FROM instance i
                LEFT JOIN instance_team it  ON it.instance_id = i.id
                LEFT JOIN team        t     ON t.id = it.team_id
                LEFT JOIN member      owner ON owner.id = t.owner_id
                LEFT JOIN teammember  tm    ON tm.team_id = t.id AND tm.member_id = ?
                WHERE (i.status IS NULL OR i.status != 'deleted')
                  AND (    i.member_id = ?
                        OR t.owner_id  = ?
                        OR (tm.member_id = ? AND COALESCE(owner.plan_tier, 'free') = 'free') )
                GROUP BY i.id
                ORDER BY via, i.display_name";
        try {
            return Bean::getAll($sql, array_fill(0, 5, $memberId));
        } catch (\Throwable $e) {
            // The count above already succeeded, so the page is still truthful without
            // this. Log it and show the total alone rather than failing the whole page.
            Flight::get('log')->error('Billing page: project breakdown failed', [
                'member' => $memberId, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** Signed SSO link into the billing portal, or '' when it cannot be built. */
    private function portalUrl(string $tenantSlug): string {
        $serviceUrl = trim((string) Flight::get('billing.service_url'));
        $appSlug    = trim((string) Flight::get('billing.app_slug'));
        $appSecret  = trim((string) Flight::get('billing.app_secret'));
        if ($serviceUrl === '' || $appSlug === '' || $appSecret === '') {
            Flight::get('log')->error(
                'Billing: [billing] service_url/app_slug/app_secret missing in conf/config.ini — no portal link'
            );
            return '';
        }

        // Mirrors BillingClient::generateSsoUrl — same params, same ksort, same HMAC.
        $params = [
            'app'    => $appSlug,
            'tenant' => $tenantSlug,
            'email'  => (string) $this->member->email,
            'name'   => (string) ($this->member->displayName ?: $this->member->username),
            'ts'     => (string) time(),
        ];
        ksort($params);
        $params['sig'] = hash_hmac('sha256', http_build_query($params), $appSecret);

        return rtrim($serviceUrl, '/') . '/auth/sso?' . http_build_query($params);
    }

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
                    // Maps to the 'project' rate in conf/rates/tiknix.php. A COUNT, not a
                    // flag: pricing is per project now, so the rate engine multiplies this
                    // by the unit price and there is no ceiling to encode anywhere.
                    'billable_projects' => $snapshot['billable'],
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
