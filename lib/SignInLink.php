<?php
/**
 * SignInLink — passwordless sign-in: a link emailed to the account's address that works once, for 15 minutes.
 *
 *   request()   someone asks for a link. Every request is a row (so asking is limited per address and per network, in the
 *               database, not in a browser that can be cleared); only an active account gets a link. The answer never says
 *               which: "if that address has an account, a link is on its way".
 *   peek()      the link's page, opened by the person (or by a mail scanner or a phone's preview: they fetch links), shows a
 *               Sign in button and changes nothing.
 *   consume()   the button: one atomic UPDATE marks the link used, so it signs in once.
 *   createAccount()  an account with no password (the start site proved the visitor owns the address), for the hand-off.
 *
 * Only a hash of each token is stored. An admin signing in by link still passes their second step (TwoFactorAuth).
 */

namespace app;

class SignInLink {

    public const TTL = 900;            // seconds a link works
    public const PER_ADDRESS = 3;      // links sent to one address an hour
    public const PER_NETWORK = 10;     // requests from one IP an hour

    private static function hash(string $s): string { return hash('sha256', $s); }

    /**
     * Ask for a link. Throws when this network has asked too often, or when an email that should go could not be sent (said, so
     * nobody waits for nothing). @param string $to where to land after signing in: a path of this site, or ''
     */
    public static function request(string $email, string $ip, string $to = ''): void {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('That is not an email address.');
        $since = date('Y-m-d H:i:s', time() - 3600);
        if ((int) Bean::count('signinlink', 'ip_hash = ? AND created_at > ?', [self::hash($ip), $since]) >= self::PER_NETWORK) {
            throw new \RuntimeException('Too many sign-in emails were asked for from here. Try again in an hour, or sign in with your password.');
        }
        $member = Bean::findOne('member', "LOWER(email) = ? AND status = 'active'", [$email]);
        $row = Bean::dispense('signinlink');
        $row->emailHash = self::hash($email); $row->ipHash = self::hash($ip); $row->memberRef = $member ? (int) $member->id : 0;
        $row->tokenHash = ''; $row->landing = self::safePath($to); $row->createdAt = date('Y-m-d H:i:s');
        $row->expiresAt = date('Y-m-d H:i:s', time() + self::TTL); $row->usedAt = '';
        if (!$member || (int) Bean::count('signinlink', "member_ref = ? AND token_hash <> '' AND created_at > ?", [(int) $member->id, $since]) >= self::PER_ADDRESS) {
            Bean::store($row);   // counted, nothing sent
            if ($member) \Flight::get('log')->warning('SignInLink: an address asked for more links than allowed this hour', ['member' => (int) $member->id]);
            return;
        }
        $token = bin2hex(random_bytes(32));
        $row->tokenHash = self::hash($token);
        Bean::store($row);
        $link = rtrim((string) \Flight::get('app.baseurl'), '/') . '/signin/link/' . $token;
        $site = (string) \Flight::siteName();
        $html = '<p>Hello' . ($member->username ? ' ' . htmlspecialchars((string) $member->username) : '') . ',</p>'
              . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:10px 18px;background:#2f66df;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Sign in to ' . htmlspecialchars($site) . '</a></p>'
              . '<p>This link works once, for 15 minutes. If you did not ask for it, ignore this email: nothing happens without it.</p>';
        $ok = Mailer::create()->to($email)->subject("Your sign-in link for {$site}")->send($html, "Sign in to {$site}: {$link}\n\nThis link works once, for 15 minutes. If you did not ask for it, ignore this email.");
        if (!$ok) {
            \Flight::get('log')->error('SignInLink: the sign-in email could not be sent', ['member' => (int) $member->id]);
            throw new \RuntimeException('We could not send the email just now. Try again in a few minutes, or sign in with your password.');
        }
        \Flight::get('log')->info('SignInLink: a sign-in link was sent', ['member' => (int) $member->id]);
    }

    /** The link's row while it can still be used, or null. Changes nothing (a mail scanner may be the one opening it). */
    public static function peek(string $token): ?object {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
        $row = Bean::findOne('signinlink', "token_hash = ? AND used_at = '' AND expires_at > ?", [self::hash($token), date('Y-m-d H:i:s')]);
        return $row && (int) $row->memberRef > 0 ? $row : null;
    }

    /** Use the link: once. @return array{member: object, to: string}|null null when it is used, expired or unknown */
    public static function consume(string $token): ?array {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
        $now = date('Y-m-d H:i:s');
        $n = Bean::exec("UPDATE signinlink SET used_at = ? WHERE token_hash = ? AND used_at = '' AND expires_at > ? AND member_ref > 0", [$now, self::hash($token), $now]);
        if ($n !== 1) return null;
        $row = Bean::findOne('signinlink', 'token_hash = ?', [self::hash($token)]);
        $member = Bean::load('member', (int) $row->memberRef);
        if (!$member->id || (string) $member->status !== 'active') return null;
        return ['member' => $member, 'to' => (string) $row->landing];
    }

    /**
     * An account with no password for an address the start site proved the visitor owns. They sign in with emailed links, and may
     * set a password later on Edit profile. @throws \InvalidArgumentException when the address is not usable or already has an account
     */
    public static function createAccount(string $email): object {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('That is not an email address.');
        if (\Model_Member::emailTaken($email)) throw new \InvalidArgumentException('That address already has an account: sign in instead.');
        $m = Bean::dispense('member');
        $m->email = $email; $m->username = Auth::usernameFrom($email); $m->password = '';
        $m->level = LEVELS['MEMBER']; $m->status = 'active'; $m->planTier = 'free'; $m->createdAt = date('Y-m-d H:i:s');
        $id = (int) Bean::store($m);
        \Flight::get('log')->info('New member, signed up without a password from a plan hand-off', ['id' => $id]);
        if (Auth::$onNewMember !== null) (Auth::$onNewMember)($id);
        return Bean::load('member', $id);
    }

    /**
     * Sign this browser in as $member, as the password form does: a fresh session id, and an admin's second step first.
     * @return string where to go next (the two-factor page, or $to, or the dashboard)
     */
    public static function startSession(object $member, string $to): string {
        session_regenerate_id(true);
        unset($_SESSION['member']);
        $member->signedIn();
        $to = self::safePath($to) ?: (PlanHandoff::afterLoginTarget() ?? '/dashboard');
        if (TwoFactorAuth::needsSetup($member) || TwoFactorAuth::needsVerification($member)) {
            $_SESSION['2fa_pending_member_id'] = $member->id;
            $_SESSION['2fa_pending_redirect'] = $to;
            return TwoFactorAuth::needsSetup($member) ? '/auth/twofasetup' : '/auth/twofaverify';
        }
        $_SESSION['member'] = $member->export();
        return $to;
    }

    /** A path of this site to land on, or ''. */
    public static function safePath(string $to): string {
        $to = trim($to);
        return preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%]*$#', $to) ? $to : '';
    }
}
