<?php
/**
 * Simple Rate Limiter
 *
 * Uses session-based storage for rate limiting.
 * Prevents brute force attacks on login, forgot password, etc.
 */

namespace app;

class RateLimiter
{
    private const SESSION_KEY = '_rate_limits';

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
