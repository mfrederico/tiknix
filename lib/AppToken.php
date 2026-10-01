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
 * the builder (workbench2's terminal page) both sign here.
 */
namespace app;

final class AppToken {
    public static function terminal(object $inst, int $memberId, bool $resume, int $ttl = 120): string {
        return self::sign($inst, [
            'sub' => (string) $inst->slug, 'member_id' => $memberId,
            'agent' => '',                            // the app's default agent (its AI agents page)
            'resume' => $resume,
            'nonce' => bin2hex(random_bytes(8)), 'exp' => time() + $ttl,
        ]);
    }

    /** $to: a path on the app ("/agents"); the person is named by email — the app finds its own member. */
    public static function launch(object $inst, string $email, string $to, int $ttl = 60): string {
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//')) throw new \InvalidArgumentException("not a path on the app: {$to}");
        return self::sign($inst, [
            'aud' => 'launch', 'sub' => (string) $inst->slug, 'email' => strtolower(trim($email)), 'to' => $to,
            'nonce' => bin2hex(random_bytes(16)), 'exp' => time() + $ttl,
        ]);
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
