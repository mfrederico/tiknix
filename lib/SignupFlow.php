<?php
/**
 * SignupFlow — signup that requires a card before the account exists.
 *
 * Phase 3 of BILLING_PLAN.md, behind `[billing] signup_card_required`, default OFF.
 *
 * The ordering is the entire point:
 *
 *   1. start()    — writes a `pendingsignup`. NO member is created.
 *                   Registers a billing tenant, which creates the Stripe customer.
 *   2. the visitor enters a card on the billing portal's hosted form (Stripe Payment
 *      Element — card details never touch tiknix).
 *   3. complete() — ASKS THE BILLING SERVICE, server to server, whether a default payment
 *                   method now exists. Only then is the member created.
 *
 * Step 3 never trusts the browser. The visitor comes back on a URL they control, which
 * can be skipped, replayed, or simply typed; if that return were the thing that decided,
 * the feature would produce exactly what it exists to prevent — accounts with no card
 * that believe they have one. The return only TRIGGERS the check; the billing service
 * gives the answer.
 *
 * @package app
 */

namespace app;

use \Flight as Flight;

class SignupFlow {

    /** How long an unfinished signup holds its email address. */
    public const EXPIRES_HOURS = 24;

    /** Seconds to wait on the billing service before giving up on a call. */
    private const HTTP_TIMEOUT = 10;

    /**
     * Is card-on-file signup switched on? Default OFF — an install that says nothing
     * about this keeps the ordinary signup it already had.
     */
    public static function enabled(): bool {
        $v = Flight::get('billing.signup_card_required');
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    /**
     * Mint a tenant slug.
     *
     * RANDOM AND OPAQUE, not derived from the member. Three reasons, in order of weight:
     *
     *  - There is no member to derive from. The tenant is registered while the account is
     *    still a `pendingsignup`, so `tiknix-{member_id}` cannot be minted at the moment
     *    it is needed. Renaming it afterwards is worse: this slug becomes the identity of
     *    a row that owns invoices, and identities in a money system do not get renamed.
     *  - `tiknix-1`, `tiknix-2` … publishes how many customers exist and in what order,
     *    and invites guessing at the neighbours.
     *  - Anything derived from the email moves when the email does, and puts a personal
     *    detail into an identifier that appears in URLs and on invoices.
     *
     * Same shape as ProvisionService::mintSlug, which is how every instance slug is
     * already minted (`partsdna-74a225`). The readable label lives in the tenant NAME,
     * which is what a person actually reads in the portal.
     *
     * SIXTEEN hex, not the six an instance slug uses, and COLLISIONS are the reason rather
     * than guessing. Six hex is 16.7M values, which sounds ample until the birthday bound:
     * a 50% chance of one collision arrives at roughly 4,800 tenants. The retry below
     * absorbs that, but a mint that starts colliding as the business grows is a strange
     * thing to build on purpose. Sixteen hex is 1.8e19 — collision-free in practice.
     *
     * Brute force is the weaker argument, because the slug is an IDENTIFIER and not a
     * credential: /billing/usage checks the Bearer token before it looks at the slug, so an
     * unauthenticated request answers 401 whether the slug is real or invented, and there
     * is no oracle to enumerate against. Length is still worth having — the day some future
     * endpoint does distinguish the two, six hex is a 4.7-hour sweep and sixteen is not.
     */
    public static function mintTenantSlug(): string {
        for ($i = 0; $i < 8; $i++) {
            $slug = 'tiknix-' . bin2hex(random_bytes(8));   // 16 hex chars: DNS and path safe
            $takenByMember  = Bean::count('member', 'billing_tenant_eid = ?', [$slug]) > 0;
            $takenByPending = Bean::count('pendingsignup', 'billing_tenant_eid = ?', [$slug]) > 0;
            if (!$takenByMember && !$takenByPending) return $slug;
        }
        // Eight collisions on 16.7M values is not bad luck, it is a broken RNG or a broken
        // query. Either way, inventing a ninth slug and hoping is not the answer.
        throw new \RuntimeException('SignupFlow: could not mint a free tenant slug after 8 attempts');
    }

    /**
     * Begin a signup. Creates a pendingsignup and a billing tenant; creates NO member.
     *
     * @return array{ok:bool, token:string, slug:string, error:string}
     */
    public static function start(string $email, string $plainPassword, string $firstName,
                                 string $lastName, string $username): array {
        $email = strtolower(trim($email));
        $fail  = fn(string $msg) => ['ok' => false, 'token' => '', 'slug' => '', 'error' => $msg];

        if ($email === '' || $plainPassword === '') return $fail('An email address and password are required.');

        // An address that already has an account, or a signup already in flight, gets the
        // SAME answer as a fresh one further down: this endpoint must not become a way to
        // ask whether somebody is a customer. The difference is only in what we do.
        if (Bean::count('member', 'email = ?', [$email]) > 0) {
            Flight::get('log')->info('SignupFlow: signup attempted for an existing account', ['email' => $email]);
            return $fail('If that address can be used, we have sent you the next step.');
        }
        self::expireStale();
        if (Bean::count('pendingsignup', 'email = ? AND status = ?', [$email, 'awaiting_card']) > 0) {
            return $fail('There is already a signup in progress for that address. '
                       . 'Check your browser history, or try again in ' . self::EXPIRES_HOURS . ' hours.');
        }

        $token = bin2hex(random_bytes(32));
        $slug  = self::mintTenantSlug();

        $pending = Bean::dispense('pendingsignup');
        $pending->token             = $token;
        $pending->email             = $email;
        // Hashed here, never stored in the clear. This row is not an account, but it is
        // one verification away from being one.
        $pending->passwordHash      = password_hash($plainPassword, PASSWORD_DEFAULT);
        $pending->firstName         = $firstName;
        $pending->lastName          = $lastName;
        $pending->username          = $username;
        $pending->billingTenantEid  = $slug;
        $pending->status            = 'awaiting_card';
        $pending->note              = '';
        $pending->createdAt         = date('Y-m-d H:i:s');
        $pending->expiresAt         = date('Y-m-d H:i:s', time() + self::EXPIRES_HOURS * 3600);
        Bean::store($pending);

        $registered = self::registerTenant($slug, $email, trim($firstName . ' ' . $lastName) ?: $email);
        if (!$registered['ok']) {
            $pending->status = 'expired';
            $pending->note   = 'billing registration failed: ' . $registered['error'];
            Bean::store($pending);
            Flight::get('log')->error('SignupFlow: could not register a billing tenant', [
                'slug' => $slug, 'error' => $registered['error'],
            ]);
            // Say that it failed. A signup that silently proceeds without a billing tenant
            // reaches the card step with nothing to attach a card to.
            return $fail('We could not start your account just now. Nothing has been charged '
                       . 'and no account was created. Please try again shortly.');
        }

        return ['ok' => true, 'token' => $token, 'slug' => $slug, 'error' => ''];
    }

    /**
     * Finish a signup, but only if the billing service says a card is really on file.
     *
     * @return array{ok:bool, pending:bool, member_id:int, error:string}
     */
    public static function complete(string $token): array {
        $out = fn(bool $ok, bool $pending, int $id, string $err) =>
            ['ok' => $ok, 'pending' => $pending, 'member_id' => $id, 'error' => $err];

        $token = trim($token);
        if ($token === '') return $out(false, false, 0, 'That signup link is not valid.');

        $pending = Bean::findOne('pendingsignup', 'token = ?', [$token]);
        if (!$pending || !$pending->id) return $out(false, false, 0, 'That signup link is not valid.');

        if ($pending->status === 'completed') {
            // Idempotent: a refresh, a double-click, or a back button must not create a
            // second account or report an error for something that already worked.
            $member = Bean::findOne('member', 'email = ?', [(string) $pending->email]);
            return $member && $member->id
                ? $out(true, false, (int) $member->id, '')
                : $out(false, false, 0, 'That signup has already been completed.');
        }
        if ($pending->status !== 'awaiting_card') {
            return $out(false, false, 0, 'That signup has expired. Please start again.');
        }
        if (strtotime((string) $pending->expiresAt) < time()) {
            $pending->status = 'expired';
            $pending->note   = 'not completed within ' . self::EXPIRES_HOURS . ' hours';
            Bean::store($pending);
            return $out(false, false, 0, 'That signup has expired. Please start again.');
        }

        // THE decision. Asked of the billing service, not of the browser that arrived here.
        $card = self::cardOnFile((string) $pending->billingTenantEid);
        if (!$card['ok']) {
            // Could not ask — NOT the same as "no card". Saying "no card" here would show
            // someone who just entered a valid one a failure they cannot act on.
            Flight::get('log')->error('SignupFlow: could not verify the card', [
                'slug' => (string) $pending->billingTenantEid, 'error' => $card['error'],
            ]);
            return $out(false, false, 0, 'We could not confirm your card just now. '
                                       . 'Nothing has been charged — please try again in a moment.');
        }
        if (!$card['has_method']) {
            return $out(false, true, 0, '');   // genuinely still waiting
        }

        $member = Bean::dispense('member');
        $member->email            = (string) $pending->email;
        $member->username         = (string) ($pending->username ?: $pending->email);
        $member->password         = (string) $pending->passwordHash;   // already hashed in start()
        $member->firstName        = (string) $pending->firstName;
        $member->lastName         = (string) $pending->lastName;
        $member->level            = LEVELS['MEMBER'];
        $member->status           = 'active';
        $member->isActive         = 1;
        $member->emailVerified    = 0;
        $member->createdAt        = date('Y-m-d H:i:s');
        $member->updatedAt        = date('Y-m-d H:i:s');
        $member->billingTenantEid = (string) $pending->billingTenantEid;
        $member->planTier         = 'free';
        $member->cardValidatedAt  = date('Y-m-d H:i:s');
        $memberId = (int) Bean::store($member);

        $pending->status      = 'completed';
        $pending->completedAt = date('Y-m-d H:i:s');
        $pending->note        = 'card confirmed: ' . trim(($card['card']['brand'] ?? '') . ' ****' . ($card['card']['last4'] ?? ''));
        Bean::store($pending);

        Flight::get('log')->info('SignupFlow: account created after card confirmation', [
            'member' => $memberId, 'slug' => (string) $pending->billingTenantEid,
        ]);

        return $out(true, false, $memberId, '');
    }

    /**
     * A signed SSO link into the billing portal for an in-flight signup, or '' if one
     * cannot be built.
     *
     * Same HMAC as BillingClient::generateSsoUrl. Returns '' rather than an unsigned URL
     * when the config is incomplete: a link that lands on a rejected signature is a worse
     * dead end than no link, because the person cannot tell it apart from a declined card.
     */
    public static function portalUrlForToken(string $token): string {
        $pending = Bean::findOne('pendingsignup', 'token = ?', [trim($token)]);
        if (!$pending || !$pending->id) return '';

        $cfg = self::billingConfig();
        if (!$cfg['ok']) return '';

        $params = [
            'app'    => $cfg['app_slug'],
            'tenant' => (string) $pending->billingTenantEid,
            'email'  => (string) $pending->email,
            'name'   => trim(((string) $pending->firstName) . ' ' . ((string) $pending->lastName))
                        ?: (string) $pending->email,
            'ts'     => (string) time(),
        ];
        ksort($params);
        $params['sig'] = hash_hmac('sha256', http_build_query($params), $cfg['app_secret']);

        return $cfg['service_url'] . '/auth/sso?' . http_build_query($params);
    }

    /**
     * Make sure this member has a billing tenant, registering one if not. Idempotent.
     *
     * Six different paths create a member — ordinary signup, an invite, a team invite, an
     * admin, Google, and start()/complete() above — and only the last set a tenant. Rather
     * than patch all six and miss the seventh when somebody adds it, this is safe to call
     * from anywhere, as often as you like, and is also called lazily by the billing page so
     * an account that arrives by any route still ends up correct.
     *
     * A FAILURE HERE MUST NOT BLOCK THE CALLER. Somebody accepting an invitation is not
     * going to be turned away because a billing service is unreachable; the account is
     * perfectly valid without a tenant and one can be attached later. That is a documented
     * decision rather than a swallowed error: it returns the failure and logs it as an
     * ERROR naming the member, so an account without a tenant is discoverable rather than
     * quietly normal.
     *
     * @return array{ok:bool, slug:string, created:bool, error:string}
     */
    public static function ensureTenantFor(int $memberId): array {
        $member = Bean::load('member', $memberId);
        if (!$member->id) return ['ok' => false, 'slug' => '', 'created' => false, 'error' => 'no such member'];

        $existing = trim((string) ($member->billingTenantEid ?? ''));
        if ($existing !== '') return ['ok' => true, 'slug' => $existing, 'created' => false, 'error' => ''];

        $slug = self::mintTenantSlug();
        $name = trim(((string) $member->firstName) . ' ' . ((string) $member->lastName))
                ?: (string) ($member->displayName ?: $member->username ?: $member->email);

        $registered = self::registerTenant($slug, (string) $member->email, $name);
        if (!$registered['ok']) {
            \Flight::get('log')->error('SignupFlow: could not register a billing tenant for an existing member', [
                'member' => $memberId, 'error' => $registered['error'],
            ]);
            return ['ok' => false, 'slug' => '', 'created' => false, 'error' => $registered['error']];
        }

        // Only stamped once the far side confirmed. Writing the slug first would leave a
        // member pointing at a tenant that does not exist — which is exactly the state that
        // produced an SSO link the billing service refused.
        $member->billingTenantEid = $slug;
        Bean::store($member);

        \Flight::get('log')->info('SignupFlow: billing tenant registered for member', [
            'member' => $memberId, 'slug' => $slug,
        ]);
        return ['ok' => true, 'slug' => $slug, 'created' => true, 'error' => ''];
    }

    /**
     * A signed SSO link into the billing portal for an existing member, or '' if one
     * cannot be built.
     *
     * Registers a tenant first if the member has none, because a link is only worth
     * offering if it lands somewhere: an SSO for an unknown tenant is refused by the
     * billing service, which is exactly the dead end this replaces.
     */
    public static function portalUrlForMember(int $memberId): string {
        $member = Bean::load('member', $memberId);
        if (!$member->id) return '';

        $slug = trim((string) ($member->billingTenantEid ?? ''));
        if ($slug === '') {
            $ensured = self::ensureTenantFor($memberId);
            if (!$ensured['ok']) return '';
            $slug = $ensured['slug'];
        }

        $cfg = self::billingConfig();
        if (!$cfg['ok']) return '';

        $params = [
            'app'    => $cfg['app_slug'],
            'tenant' => $slug,
            'email'  => (string) $member->email,
            'name'   => trim(((string) $member->firstName) . ' ' . ((string) $member->lastName))
                        ?: (string) ($member->displayName ?: $member->username ?: $member->email),
            'ts'     => (string) time(),
        ];
        ksort($params);
        $params['sig'] = hash_hmac('sha256', http_build_query($params), $cfg['app_secret']);

        return $cfg['service_url'] . '/auth/sso?' . http_build_query($params);
    }

    /**
     * Bring a member's plan tier into line with whether they actually have a card.
     *
     * This is the join between "card on file" and "uncapped": a card that is saved but
     * never promotes the account leaves someone paying attention to a form that changes
     * nothing, still blocked at one project and never billable.
     *
     *   card present, tier free  -> pro   (uncapped; projects past the first are billed)
     *   no card,      tier pro   -> free  (back to the free allowance)
     *   legacy                   -> untouched, always. Grandfathered accounts are covered
     *                               whatever their card says, and promoting one to `pro`
     *                               would start billing somebody we promised not to.
     *
     * AN UNREACHABLE BILLING SERVICE CHANGES NOTHING. Demoting on a failed check would
     * lock a paying customer out over an outage on our side, and promoting on one would
     * hand out uncapped projects to anybody who caught us at a bad moment. The error is
     * logged and the tier is left exactly as it was.
     *
     * Synced at most once per member per request — the check is an HTTP round trip and
     * the quota gate can ask about several members at once.
     *
     * @return array{ok:bool, tier:string, changed:bool, error:string}
     */
    public static function syncPlanTier(int $memberId): array {
        static $seen = [];

        $member = Bean::load('member', $memberId);
        if (!$member->id) return ['ok' => false, 'tier' => 'free', 'changed' => false, 'error' => 'no such member'];

        $tier = trim((string) ($member->planTier ?: 'free'));
        if ($tier === 'legacy') return ['ok' => true, 'tier' => 'legacy', 'changed' => false, 'error' => ''];
        if (isset($seen[$memberId]))  return ['ok' => true, 'tier' => $tier, 'changed' => false, 'error' => ''];
        $seen[$memberId] = true;

        $slug = trim((string) ($member->billingTenantEid ?? ''));
        if ($slug === '') return ['ok' => true, 'tier' => $tier, 'changed' => false, 'error' => ''];

        $card = self::cardOnFile($slug);
        if (!$card['ok']) {
            \Flight::get('log')->error('SignupFlow: card check failed — leaving the plan tier alone', [
                'member' => $memberId, 'tenant' => $slug, 'error' => $card['error'],
            ]);
            return ['ok' => false, 'tier' => $tier, 'changed' => false, 'error' => $card['error']];
        }

        $want = $card['has_method'] ? 'pro' : 'free';
        // Agency is chosen, not implied by a card: a card on file keeps it; no card still
        // drops it to free, because there is nothing to bill the plan to.
        if ($tier === 'agency' && $card['has_method']) $want = 'agency';
        if ($want === $tier) return ['ok' => true, 'tier' => $tier, 'changed' => false, 'error' => ''];

        $member->planTier = $want;
        // The cap comes from the tier for free/pro; a stale number here would outrank it.
        $member->planProjectCap = 0;
        Bean::store($member);

        \Flight::get('log')->info('SignupFlow: plan tier synced to the card on file', [
            'member' => $memberId, 'from' => $tier, 'to' => $want,
        ]);
        return ['ok' => true, 'tier' => $want, 'changed' => true, 'error' => ''];
    }

    /** Mark abandoned signups expired so they stop holding their email address. */
    public static function expireStale(): int {
        $stale = Bean::find('pendingsignup', 'status = ? AND expires_at < ?',
                            ['awaiting_card', date('Y-m-d H:i:s')]);
        foreach ($stale as $p) {
            $p->status = 'expired';
            $p->note   = 'not completed within ' . self::EXPIRES_HOURS . ' hours';
            Bean::store($p);
        }
        return count($stale);
    }

    // ---- billing service calls -------------------------------------------------------

    /** Register the tenant (and its Stripe customer) with the billing service. */
    private static function registerTenant(string $slug, string $email, string $name): array {
        $cfg = self::billingConfig();
        if (!$cfg['ok']) return $cfg;

        return self::post($cfg['service_url'] . '/api/v1/registration/handshake', [
            'app_slug'      => $cfg['app_slug'],
            'tenant_slug'   => $slug,
            'tenant_name'   => $name,
            'billing_email' => $email,
            // Path-based, and deliberately carrying no query string: UsageFetcher appends
            // its period params with a bare '?', which would mangle a URL that had one.
            'callback_url'  => rtrim((string) Flight::get('app.baseurl'), '/') . '/billing/usage/' . $slug,
            'callback_key'  => $cfg['callback_key'],
        ], $cfg['app_secret']);
    }

    /**
     * Ask the billing service whether this tenant has a usable card.
     *
     * @return array{ok:bool, has_method:bool, card:array, error:string}
     */
    public static function cardOnFile(string $slug): array {
        $cfg = self::billingConfig();
        if (!$cfg['ok']) return ['ok' => false, 'has_method' => false, 'card' => [], 'error' => $cfg['error']];

        $r = self::post($cfg['service_url'] . '/api/v1/tenant/paymentmethod', [
            'app_slug'    => $cfg['app_slug'],
            'tenant_slug' => $slug,
        ], $cfg['app_secret']);
        if (!$r['ok']) return ['ok' => false, 'has_method' => false, 'card' => [], 'error' => $r['error']];

        $data = $r['data']['data'] ?? $r['data'];
        return [
            'ok'         => true,
            'has_method' => !empty($data['has_method']),
            'card'       => is_array($data['card'] ?? null) ? $data['card'] : [],
            'error'      => '',
        ];
    }

    /** The [billing] settings, or a loud failure naming what is missing. */
    private static function billingConfig(): array {
        $keys = ['service_url', 'app_slug', 'app_secret', 'callback_key'];
        $cfg  = ['ok' => true, 'error' => ''];
        $missing = [];
        foreach ($keys as $k) {
            $v = trim((string) Flight::get('billing.' . $k));
            if ($v === '') $missing[] = $k;
            $cfg[$k] = $k === 'service_url' ? rtrim($v, '/') : $v;
        }
        if ($missing) {
            $msg = '[billing] ' . implode(', ', $missing) . ' missing from conf/config.ini';
            Flight::get('log')->error('SignupFlow: ' . $msg);
            return ['ok' => false, 'error' => $msg];
        }
        return $cfg;
    }

    /**
     * POST JSON to the billing service.
     *
     * The app secret goes in an `Authorization: Bearer` header, which is where that API
     * reads it (lib/FlightMap.php) — putting it in the body authenticates nothing and
     * earns a 401 that looks like a wrong secret rather than a wrong request.
     */
    private static function post(string $url, array $body, string $bearer): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $bearer,
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        // No curl_close(): deprecated since PHP 8.0 and a no-op, and calling it on 8.5
        // raises a deprecation into whatever handler is listening.

        if ($err !== '') return ['ok' => false, 'data' => [], 'error' => $err];
        $data = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'data' => is_array($data) ? $data : [],
                    'error' => 'HTTP ' . $code . ' from ' . $url . ' — ' . substr((string) $raw, 0, 200)];
        }
        if (!is_array($data)) return ['ok' => false, 'data' => [], 'error' => 'non-JSON reply from ' . $url];

        return ['ok' => true, 'data' => $data, 'error' => ''];
    }
}
