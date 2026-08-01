<?php
/**
 * Invite — the only door into a closed Tiknix.
 *
 * Public registration is off (`registration_enabled = 0`), and it stays off: an invite
 * does not open the front door, it carries its own key. The token is bound to ONE email
 * address, and the account created from it uses that address — so an invite forwarded to
 * a friend still only ever creates the account it was addressed to.
 *
 * Sending is a per-member GRANT (the `invites` feature flag), and a granted member gets
 * MAX_PER_WINDOW invites per rolling 30 days. Rolling, not calendar: a calendar month
 * refills at midnight on the 1st, so three on the 31st and three on the 1st is six invites
 * in 48 hours from someone the limit was meant to pace. Admins are not counted at all —
 * they can already create members outright, so a quota on them would be theatre.
 */

namespace app;

class Invite {

    /** How long an unaccepted invite stays usable. */
    public const TTL_DAYS = 15;

    /** Per non-admin member, within WINDOW_DAYS. */
    public const MAX_PER_WINDOW = 3;
    public const WINDOW_DAYS    = 30;

    /** The feature flag an admin switches on to let someone invite. */
    public const FLAG = 'invites';

    /** Admin and above are unmetered. Mirrors Feature::allows()'s own boundary. */
    private const ADMIN_LEVEL = 50;

    public static function isAdmin(int $level): bool { return $level > 0 && $level <= self::ADMIN_LEVEL; }

    /** May this member send invites at all? Admins always; everyone else needs the grant. */
    public static function canSend(int $memberId, int $level): bool {
        return Feature::allows(self::FLAG, $memberId, $level);
    }

    /**
     * Invites this member has sent inside the rolling window that still count against them.
     *
     * A REVOKED invite does not count — revoking is how you correct a typo, and charging
     * for a mistake you immediately undid would make the quota punish carefulness. An
     * expired-unaccepted one DOES count: it was sent, it consumed a slot, and refunding it
     * would let someone drip-feed invites indefinitely by never having them accepted.
     */
    public static function usedInWindow(int $memberId): int {
        $since = date('Y-m-d H:i:s', strtotime('-' . self::WINDOW_DAYS . ' days'));
        try {
            // The WHERE names ONLY columns that always exist. RedBean's fluid mode creates
            // a column the first time something writes it, so `revoked_at IS NULL` threw
            // until an invite had actually been revoked — and the catch below turned that
            // into "0 used", which silently made the quota unenforceable. Revoked ones are
            // filtered in PHP, where a missing property is simply null.
            $rows = Bean::find('invite', 'invited_by = ? AND created_at >= ?', [$memberId, $since]);
            $n = 0;
            foreach ($rows as $r) if (empty($r->revokedAt)) $n++;
            return $n;
        } catch (\Throwable $e) {
            // Only a genuinely absent table should reach here. Anything else is a real
            // fault, and answering "0 used" would hand out unlimited invites — so say so.
            if (!preg_match('/no such table/i', $e->getMessage())) {
                \Flight::get('log') && \Flight::get('log')->error(
                    'Invite quota check failed — refusing to assume zero', ['error' => $e->getMessage()]
                );
                return PHP_INT_MAX;   // fail CLOSED: no allowance rather than infinite
            }
            return 0;
        }
    }

    /** Remaining allowance; PHP_INT_MAX for an admin. */
    public static function remaining(int $memberId, int $level): int {
        if (self::isAdmin($level)) return PHP_INT_MAX;
        return max(0, self::MAX_PER_WINDOW - self::usedInWindow($memberId));
    }

    /** When the oldest counted invite ages out, so the UI can say when the next slot frees. */
    public static function nextSlotAt(int $memberId): ?string {
        $since = date('Y-m-d H:i:s', strtotime('-' . self::WINDOW_DAYS . ' days'));
        // Same rule as usedInWindow: only always-present columns in the WHERE.
        $rows = Bean::find('invite', 'invited_by = ? AND created_at >= ? ORDER BY created_at ASC',
            [$memberId, $since]);
        foreach ($rows as $row) {
            if (!empty($row->revokedAt)) continue;
            return date('Y-m-d H:i:s', strtotime((string) $row->createdAt) + self::WINDOW_DAYS * 86400);
        }
        return null;
    }

    /**
     * Create an invite. Returns ['ok'=>bool, 'invite'=>bean|null, 'error'=>string].
     *
     * Every refusal is NAMED. "Could not send" tells someone nothing about whether to fix
     * the address, wait for a slot, or ask an admin — and those are the only three things
     * they can do about it.
     */
    public static function create(string $email, int $memberId, int $level): array {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That is not a valid email address.'];
        }
        if (!self::canSend($memberId, $level)) {
            return ['ok' => false, 'error' => 'You have not been given permission to send invites.'];
        }

        // Already a member: an invite would create a second account on the same address,
        // or fail confusingly at accept time. Say so now.
        if (Bean::findOne('member', 'LOWER(email) = ?', [$email])) {
            return ['ok' => false, 'error' => $email . ' already has a Tiknix account.'];
        }

        // An outstanding invite to the same address — resend rather than stack duplicates,
        // so a second click does not spend a second slot.
        // Matched on email alone — accepted_at/revoked_at do not exist as columns until
        // something writes them, and naming them here threw, so this check silently never
        // fired and a second click created a duplicate that spent a second slot.
        foreach (Bean::find('invite', 'LOWER(email) = ?', [$email]) as $existing) {
            if (!empty($existing->acceptedAt) || !empty($existing->revokedAt)) continue;
            if (strtotime((string) $existing->expiresAt) <= time()) continue;
            return ['ok' => true, 'invite' => $existing, 'resend' => true];
        }

        if (!self::isAdmin($level) && self::remaining($memberId, $level) <= 0) {
            $next = self::nextSlotAt($memberId);
            return ['ok' => false, 'error' => 'You have used all ' . self::MAX_PER_WINDOW
                . ' invites for the last ' . self::WINDOW_DAYS . ' days.'
                . ($next ? ' The next one frees up on ' . date('j M Y', strtotime($next)) . '.' : '')];
        }

        $inv = Bean::dispense('invite');
        $inv->email     = $email;
        $inv->token     = bin2hex(random_bytes(32));
        $inv->invitedBy = $memberId;
        $inv->expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TTL_DAYS . ' days'));
        $inv->createdAt = date('Y-m-d H:i:s');
        $inv->emailSent = 0;
        Bean::store($inv);

        return ['ok' => true, 'invite' => $inv];
    }

    /**
     * The invite a token opens, or null with a reason.
     * @return array{invite:?object, error:string}
     */
    public static function resolve(string $token): array {
        $token = trim($token);
        if ($token === '' || !ctype_xdigit($token)) return ['invite' => null, 'error' => 'That invitation link is not valid.'];

        $inv = Bean::findOne('invite', 'token = ?', [$token]);
        if (!$inv || !$inv->id)    return ['invite' => null, 'error' => 'That invitation could not be found.'];
        if ($inv->revokedAt)       return ['invite' => null, 'error' => 'That invitation was withdrawn.'];
        if ($inv->acceptedAt)      return ['invite' => null, 'error' => 'That invitation has already been used.'];
        if (strtotime((string) $inv->expiresAt) < time()) {
            return ['invite' => null, 'error' => 'That invitation expired on '
                . date('j M Y', strtotime((string) $inv->expiresAt)) . '. Ask whoever invited you for a new one.'];
        }
        // A member may have signed up another way since.
        if (Bean::findOne('member', 'LOWER(email) = ?', [strtolower((string) $inv->email)])) {
            return ['invite' => null, 'error' => 'There is already an account for ' . $inv->email . ' — try signing in.'];
        }
        return ['invite' => $inv, 'error' => ''];
    }

    /** Mark an invite used, recording which member it produced. */
    public static function markAccepted(object $inv, int $newMemberId): void {
        $inv->acceptedAt      = date('Y-m-d H:i:s');
        $inv->acceptedMemberId = $newMemberId;
        Bean::store($inv);
    }

    /** The public link for an invite. */
    public static function url(object $inv): string {
        $base = rtrim((string) (\Flight::get('app.baseurl') ?: 'https://tiknix.com'), '/');
        return $base . '/auth/invite?token=' . rawurlencode((string) $inv->token);
    }
}
