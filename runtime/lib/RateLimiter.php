<?php
/**
 * Simple Rate Limiter
 *
 * Uses session-based storage for rate limiting.
 * Prevents brute force attacks on login, forgot password, etc.
 *
 * check() is for things a BROWSER does — login, forgot-password — where a session
 * exists and is the natural bucket. It is useless against a webhook: an inbound
 * POST from Telegram or Slack carries no cookie, so every request starts a fresh
 * session, the counter is always 1, and the limit never fires. shared() below is
 * the sessionless form for exactly that case.
 */

namespace app;

class RateLimiter
{
    private const SESSION_KEY = '_rate_limits';

    /** APCu key prefix for the sessionless limiter. */
    private const SHARED_PREFIX = 'ratelimit:';

    /**
     * Rate limit something with no session behind it — a webhook, a public API.
     *
     * Backed by APCu, so the count is shared across the PHP-FPM workers that
     * actually handle concurrent webhook deliveries. It is per-host and lost on a
     * restart, which is the right trade for abuse control: forgetting resets
     * somebody to zero attempts, it never locks anyone out wrongly.
     *
     * Fails OPEN when APCu is unavailable (CLI, or an install without it) and says
     * so once. A rate limiter that silently becomes a brick wall would take
     * delivery down for every connected channel, which is a worse outage than the
     * abuse it was guarding against — but "we are not limiting anything right now"
     * must never be a thing you have to infer.
     *
     * @param  string $bucket   what is being limited, e.g. "webhook:telegram:12"
     * @return bool   true if allowed, false if over the limit
     */
    public static function shared(string $bucket, int $maxAttempts = 60, int $windowSeconds = 60): bool {
        if (!function_exists('apcu_fetch') || !apcu_enabled()) {
            static $warned = false;
            if (!$warned) {
                $warned = true;
                \Flight::get('log')?->warning(
                    'RateLimiter::shared has no APCu backend — inbound rate limiting is OFF');
            }
            return true;
        }

        // One key per bucket per window. The window is part of the key, so an
        // expiring key IS the reset and there is no list of timestamps to prune.
        $window = (int) floor(time() / max(1, $windowSeconds));
        $key    = self::SHARED_PREFIX . $bucket . ':' . $window;

        // apcu_inc with $success tells us whether the key already existed, which
        // is how the TTL gets set exactly once. apcu_add would race two workers.
        $count = apcu_inc($key, 1, $success);
        if (!$success) {
            apcu_store($key, 1, $windowSeconds + 1);
            $count = 1;
        }

        return $count <= $maxAttempts;
    }

    /** How many are left in the current window, for a Retry-After or a log line. */
    public static function sharedRemaining(string $bucket, int $maxAttempts = 60, int $windowSeconds = 60): int {
        if (!function_exists('apcu_fetch') || !apcu_enabled()) return $maxAttempts;

        $window = (int) floor(time() / max(1, $windowSeconds));
        $count  = (int) apcu_fetch(self::SHARED_PREFIX . $bucket . ':' . $window);
        return max(0, $maxAttempts - $count);
    }

    /**
     * Check if action is rate limited
     *
     * @param string $action The action name (e.g., 'login', 'forgot_password')
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param string|null $identifier Optional identifier (e.g., IP, email)
     * @return bool True if allowed, false if rate limited
     */
    public static function check(
        string $action,
        int $maxAttempts = 5,
        int $windowSeconds = 300,
        ?string $identifier = null
    ): bool {
        $key = self::getKey($action, $identifier);
        $limits = $_SESSION[self::SESSION_KEY] ?? [];

        // Clean up old entries
        $now = time();
        if (isset($limits[$key])) {
            $limits[$key] = array_filter($limits[$key], function($timestamp) use ($now, $windowSeconds) {
                return ($now - $timestamp) < $windowSeconds;
            });
        }

        // Check if rate limited
        $attempts = count($limits[$key] ?? []);

        if ($attempts >= $maxAttempts) {
            return false; // Rate limited
        }

        // Record this attempt
        $limits[$key][] = $now;
        $_SESSION[self::SESSION_KEY] = $limits;

        return true; // Allowed
    }

    /**
     * Get remaining attempts
     *
     * @param string $action The action name
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param string|null $identifier Optional identifier
     * @return int Remaining attempts
     */
    public static function remaining(
        string $action,
        int $maxAttempts = 5,
        int $windowSeconds = 300,
        ?string $identifier = null
    ): int {
        $key = self::getKey($action, $identifier);
        $limits = $_SESSION[self::SESSION_KEY] ?? [];

        // Clean up old entries
        $now = time();
        if (isset($limits[$key])) {
            $limits[$key] = array_filter($limits[$key], function($timestamp) use ($now, $windowSeconds) {
                return ($now - $timestamp) < $windowSeconds;
            });
            $_SESSION[self::SESSION_KEY] = $limits;
        }

        $attempts = count($limits[$key] ?? []);
        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Get seconds until rate limit resets
     *
     * @param string $action The action name
     * @param int $windowSeconds Time window in seconds
     * @param string|null $identifier Optional identifier
     * @return int Seconds until reset (0 if not limited)
     */
    public static function retryAfter(
        string $action,
        int $windowSeconds = 300,
        ?string $identifier = null
    ): int {
        $key = self::getKey($action, $identifier);
        $limits = $_SESSION[self::SESSION_KEY] ?? [];

        if (empty($limits[$key])) {
            return 0;
        }

        $oldestAttempt = min($limits[$key]);
        $resetTime = $oldestAttempt + $windowSeconds;
        $secondsRemaining = $resetTime - time();

        return max(0, $secondsRemaining);
    }

    /**
     * Clear rate limit for an action
     *
     * @param string $action The action name
     * @param string|null $identifier Optional identifier
     */
    public static function clear(string $action, ?string $identifier = null): void {
        $key = self::getKey($action, $identifier);

        if (isset($_SESSION[self::SESSION_KEY][$key])) {
            unset($_SESSION[self::SESSION_KEY][$key]);
        }
    }

    /**
     * Generate storage key
     */
    private static function getKey(string $action, ?string $identifier = null): string {
        $parts = [$action];

        if ($identifier !== null) {
            $parts[] = md5($identifier);
        }

        // Include IP for additional security
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $parts[] = md5($ip);

        return implode(':', $parts);
    }
}
