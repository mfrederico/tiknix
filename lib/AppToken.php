<?php
/**
 * AppToken — what the control plane signs for an app in its own container, with THAT app's
 * key (instance.terminal_key; the app holds the same as secure/terminal.key — TenantHost::terminal).
 *
 *   terminal()  the builder terminal (runtime TerminalBridge): no audience claim
 *   launch()    a sign-in hand-off to one of the app's pages (runtime LaunchToken, POST /auth/launch)
 *
 * base64url(JSON) "." hex(HMAC-SHA256(base64url, key)), single-use (nonce) and short-lived (exp).
 * Each verifier refuses the other kind. The one implementation: core's nav (Projects::open) and
 * the builder (workbench.tiknix's terminal page) both sign here.
 */
namespace app;

final class AppToken {
    /**
     * The terminal token names the member ('author': TenantHost::author) — the app makes them the
     * repository's git identity while they are at the terminal, so what its agent commits is theirs.
     */
    /**
     * $agent: one of the app's agents (its AI agents page) by name, '' = its default. The session is
     * the member's own on that agent (aib-<agent>-m<member>) — see terminalSession().
     */
    public static function terminal(object $inst, int $memberId, bool $resume, string $agent, int $ttl = 120): string {
        if ($memberId <= 0) throw new \InvalidArgumentException('a terminal token names its member');
        if ($agent !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,40}$/', $agent)) throw new \InvalidArgumentException("'{$agent}' is not an agent name");
        return self::sign($inst, [
            'sub' => (string) $inst->slug, 'member_id' => $memberId, 'author' => TenantHost::author($memberId),
            'agent' => $agent,
            'resume' => $resume,
            'nonce' => bin2hex(random_bytes(8)), 'exp' => time() + $ttl,
        ]);
    }

    /**
     * $to: a path on the app ("/agents"); the person is named by email — the app finds its own member.
     * $level: what Tiknix vouches for (AppAccess::level) — the app creates a MISSING account at it,
     * never changes an existing one; null = no account is created.
     */
    public static function launch(object $inst, string $email, string $to, ?int $level = null, array $grants = [], int $ttl = 60): string {
        if ($level !== null && !in_array($level, [1, 50, 100], true)) throw new \InvalidArgumentException("not a level an app account can be made at: {$level}");
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//')) throw new \InvalidArgumentException("not a path on the app: {$to}");
        return self::sign($inst, [
            'aud' => 'launch', 'sub' => (string) $inst->slug, 'email' => strtolower(trim($email)), 'to' => $to, 'level' => $level,
            'grants' => array_values($grants),   // Tiknix's feature grants for this person (runtime LaunchToken::GRANTS): the app sets and clears them
            'nonce' => bin2hex(random_bytes(16)), 'exp' => time() + $ttl,
        ]);
    }

    /** The tmux session a member's terminal on $agent runs in — the runtime bridge's name for it. */
    public static function terminalSession(int $memberId, string $agent): string {
        return 'aib-' . ($agent !== '' ? $agent : 'default') . '-m' . $memberId;
    }

    private static function sign(object $inst, array $claims): string {
        $key = (string) ($inst->terminalKey ?? '');
        if (strlen($key) < 64) {
            throw new \RuntimeException("{$inst->slug} has no control-plane key yet — on core: php scripts/tenant.php --terminal={$inst->slug}");
        }
        $b64 = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        return $b64 . '.' . hash_hmac('sha256', $b64, $key);
    }
}
