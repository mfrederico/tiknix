<?php
/**
 * SimpleCsrf - Lightweight CSRF Protection
 *
 * Uses a single token per session that works for all forms.
 * No URI locking, no per-form tokens - just simple, secure protection.
 */

namespace app;

class SimpleCsrf
{
    private const SESSION_KEY = '_csrf_token';
    private const FORM_FIELD = '_csrf_token';

    /**
     * Get or generate the CSRF token for this session
     */
    public static function getToken(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Get token as array for view compatibility
     * Returns ['_csrf_token' => 'value'] for use with foreach in views
     */
    public static function getTokenArray(): array
    {
        return [self::FORM_FIELD => self::getToken()];
    }

    /**
     * Validate CSRF token from POST data or X-CSRF-TOKEN header
     */
    public static function validate(): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';
        if (empty($sessionToken)) {
            return false;
        }

        // Check POST data first, then header (for AJAX)
        $submittedToken = $_POST[self::FORM_FIELD]
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';

        if (empty($submittedToken)) {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }

    /**
     * Validate and throw-friendly version for use in controllers
     */
    public static function validateRequest(): bool
    {
        // Only validate on state-changing methods
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return true;
        }

        return self::validate();
    }

    /**
     * Get HTML input field for forms
     */
    public static function field(): string
    {
        $token = htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . self::FORM_FIELD . '" value="' . $token . '">';
    }

    /**
     * Regenerate token (call after login for extra security)
     */
    public static function regenerate(): string
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::SESSION_KEY];
    }
}
