<?php
/**
 * BillingLifecycle — keep a member's billing in step with their account status.
 *
 * Without this, suspending or deleting someone in the admin left their billing tenant
 * `active`, so the nightly run kept invoicing and autopay kept charging a card for an
 * account that no longer works. Billing somebody after you switched them off is the worst
 * kind of billing bug: they cannot use the product, and the charge looks deliberate.
 *
 * The mapping, using statuses the billing service already has rather than inventing one:
 *
 *   member active            -> tenant active     (resurrected if it was cancelled)
 *   member suspended/other   -> tenant cancelled   (billing stops; invoices are kept)
 *   member deleted           -> tenant cancelled   (same; history is not destroyed)
 *
 * "Pause" is `cancelled`. The billing service's handshake AUTO-RESURRECTS a cancelled
 * tenant back to active on re-registration, so cancel/reactivate round-trips without a
 * separate paused state to keep in sync. A state that exists in one system and not the
 * other is a state that eventually disagrees.
 *
 * DELETION KEEPS THE INVOICES. A tenant is cancelled, never removed: its invoices are the
 * record of money that actually moved, and destroying them to tidy up a member row would
 * make a refund or a tax question unanswerable.
 *
 * NON-FATAL BY DESIGN. An admin suspending an account must not be blocked because a
 * billing service is unreachable — but the failure is logged as an ERROR naming the member
 * and the intended action, so a member whose billing did not follow them is discoverable
 * rather than silently wrong. Those are the rows to replay after an outage.
 *
 * @package app
 */

namespace app;

use \Flight as Flight;

class BillingLifecycle {

    /** Member statuses that mean "this account works". Anything else stops billing. */
    private const ACTIVE_STATUSES = ['active'];

    /**
     * Bring billing into line with this member's current status.
     *
     * Safe to call after any change to a member, including one that did not touch status.
     * A no-op when the member has no billing tenant.
     *
     * @return array{ok:bool, action:string, error:string}
     */
    public static function syncFor(int $memberId): array {
        $member = Bean::load('member', $memberId);
        if (!$member->id) return ['ok' => false, 'action' => 'none', 'error' => 'no such member'];

        $slug = trim((string) ($member->billingTenantEid ?? ''));
        if ($slug === '') return ['ok' => true, 'action' => 'none', 'error' => ''];

        $status = strtolower(trim((string) ($member->status ?? '')));
        return in_array($status, self::ACTIVE_STATUSES, true)
            ? self::resume($memberId, $slug)
            : self::pause($memberId, $slug, 'member status: ' . ($status ?: 'unset'));
    }

    /**
     * Called when a member is about to be deleted.
     *
     * MUST RUN BEFORE the row goes, because the tenant slug lives on it — afterwards there
     * is nothing left to say which tenant to stop, and the billing run would go on
     * invoicing a member who no longer exists.
     *
     * @return array{ok:bool, action:string, error:string}
     */
    public static function onDelete(int $memberId): array {
        $member = Bean::load('member', $memberId);
        if (!$member->id) return ['ok' => false, 'action' => 'none', 'error' => 'no such member'];

        $slug = trim((string) ($member->billingTenantEid ?? ''));
        if ($slug === '') return ['ok' => true, 'action' => 'none', 'error' => ''];

        return self::pause($memberId, $slug, 'member deleted');
    }

    /** Stop billing this tenant. */
    private static function pause(int $memberId, string $slug, string $reason): array {
        $cfg = self::config();
        if (!$cfg['ok']) return ['ok' => false, 'action' => 'pause', 'error' => $cfg['error']];

        $r = self::post($cfg, '/api/v1/registration/deactivate', [
            'app_slug'    => $cfg['app_slug'],
            'tenant_slug' => $slug,
            'reason'      => $reason,
        ]);

        if (!$r['ok']) {
            Flight::get('log')->error('BillingLifecycle: could not stop billing for a member', [
                'member' => $memberId, 'tenant' => $slug, 'reason' => $reason, 'error' => $r['error'],
            ]);
            return ['ok' => false, 'action' => 'pause', 'error' => $r['error']];
        }

        Flight::get('log')->info('BillingLifecycle: billing stopped', [
            'member' => $memberId, 'tenant' => $slug, 'reason' => $reason,
        ]);
        return ['ok' => true, 'action' => 'pause', 'error' => ''];
    }

    /**
     * Resume billing. Re-registering is what resurrects a cancelled tenant, so this is the
     * same handshake used at signup — idempotent, and a no-op for a tenant already active.
     */
    private static function resume(int $memberId, string $slug): array {
        $cfg = self::config();
        if (!$cfg['ok']) return ['ok' => false, 'action' => 'resume', 'error' => $cfg['error']];

        $member = Bean::load('member', $memberId);
        $name = trim(((string) $member->firstName) . ' ' . ((string) $member->lastName))
                ?: (string) ($member->displayName ?: $member->username ?: $member->email);

        $r = self::post($cfg, '/api/v1/registration/handshake', [
            'app_slug'      => $cfg['app_slug'],
            'tenant_slug'   => $slug,
            'tenant_name'   => $name,
            'billing_email' => (string) $member->email,
            'callback_url'  => rtrim((string) Flight::get('app.baseurl'), '/') . '/billing/usage/' . $slug,
            'callback_key'  => $cfg['callback_key'],
        ]);

        if (!$r['ok']) {
            Flight::get('log')->error('BillingLifecycle: could not resume billing for a member', [
                'member' => $memberId, 'tenant' => $slug, 'error' => $r['error'],
            ]);
            return ['ok' => false, 'action' => 'resume', 'error' => $r['error']];
        }
        return ['ok' => true, 'action' => 'resume', 'error' => ''];
    }

    /** The [billing] settings, or a loud failure naming what is missing. */
    private static function config(): array {
        $out = ['ok' => true, 'error' => ''];
        $missing = [];
        foreach (['service_url', 'app_slug', 'app_secret', 'callback_key'] as $k) {
            $v = trim((string) Flight::get('billing.' . $k));
            if ($v === '') $missing[] = $k;
            $out[$k] = $k === 'service_url' ? rtrim($v, '/') : $v;
        }
        if ($missing) {
            $msg = '[billing] ' . implode(', ', $missing) . ' missing from conf/config.ini';
            Flight::get('log')->error('BillingLifecycle: ' . $msg);
            return ['ok' => false, 'error' => $msg];
        }
        return $out;
    }

    /** POST JSON with the app secret as a Bearer token, which is where that API reads it. */
    private static function post(array $cfg, string $path, array $body): array {
        $ch = curl_init($cfg['service_url'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $cfg['app_secret'],
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        // No curl_close(): deprecated since 8.0 and a no-op; on 8.5 it raises a deprecation.

        if ($err !== '') return ['ok' => false, 'error' => $err];
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'HTTP ' . $code . ' — ' . substr((string) $raw, 0, 180)];
        }
        return ['ok' => true, 'error' => ''];
    }
}
