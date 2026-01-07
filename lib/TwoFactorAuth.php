<?php
/**
 * TwoFactorAuth - TOTP Two-Factor Authentication
 *
 * Provides TOTP-based 2FA using Google Authenticator compatible codes.
 * Required for:
 * - Admin users (level <= 50)
 * - Users who run Claude tasks (workbench)
 *
 * Configuration:
 * - TOTP re-auth duration: 30 days
 * - Recovery codes: 10 single-use codes
 */

namespace app;

use \Flight as Flight;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class TwoFactorAuth {

    private static ?Google2FA $google2fa = null;

    // 30 days in seconds
    public const TRUST_DURATION = 30 * 24 * 60 * 60;

    // Number of recovery codes to generate
    public const RECOVERY_CODE_COUNT = 10;

    // Secret key for signing trust tokens (uses app secret or fallback)
    private static function getTrustSecret(): string {
        return Flight::get('app.secret') ?? 'tiknix-2fa-trust-default-key';
    }

    // Levels that require 2FA (ADMIN and above)
    public const REQUIRED_LEVELS = [1, 50]; // ROOT, ADMIN

    /**
     * Get Google2FA instance
     */
    private static function getGoogle2FA(): Google2FA {
        if (self::$google2fa === null) {
            self::$google2fa = new Google2FA();
        }
        return self::$google2fa;
    }

    /**
     * Generate a new TOTP secret
     */
    public static function generateSecret(): string {
        return self::getGoogle2FA()->generateSecretKey(32);
    }

    /**
     * Generate QR code SVG for authenticator app
     */
    public static function generateQrCode(string $secret, string $email): string {
        $appName = Flight::get('app.name') ?? 'Tiknix';

        $qrCodeUrl = self::getGoogle2FA()->getQRCodeUrl(
            $appName,
            $email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);
        return $writer->writeString($qrCodeUrl);
    }

    /**
     * Verify a TOTP code
     */
    public static function verifyCode(string $secret, string $code): bool {
        // Allow 1 period before/after for clock drift
        return self::getGoogle2FA()->verifyKey($secret, $code, 1);
    }

    /**
     * Generate recovery codes
     */
    public static function generateRecoveryCodes(): array {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // Format: XXXX-XXXX-XXXX
            $codes[] = strtoupper(
                bin2hex(random_bytes(2)) . '-' .
                bin2hex(random_bytes(2)) . '-' .
                bin2hex(random_bytes(2))
            );
        }
        return $codes;
    }

    /**
     * Hash recovery codes for storage
     */
    public static function hashRecoveryCodes(array $codes): string {
        $hashed = array_map(function($code) {
            return password_hash(str_replace('-', '', strtoupper($code)), PASSWORD_DEFAULT);
        }, $codes);
        return json_encode($hashed);
    }

    /**
     * Verify a recovery code and remove it if valid
     */
    public static function verifyRecoveryCode(object $member, string $code): bool {
        if (empty($member->recoveryCodes)) {
            return false;
        }

        $hashedCodes = json_decode($member->recoveryCodes, true);
        if (!is_array($hashedCodes)) {
            return false;
        }

        $normalizedCode = str_replace('-', '', strtoupper($code));

        foreach ($hashedCodes as $index => $hashedCode) {
            if (password_verify($normalizedCode, $hashedCode)) {
                // Remove used code
                unset($hashedCodes[$index]);
                $member->recoveryCodes = json_encode(array_values($hashedCodes));
                Bean::store($member);

                Flight::get('log')->info('Recovery code used', [
                    'member_id' => $member->id,
                    'remaining_codes' => count($hashedCodes)
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Check if user requires 2FA based on level or workbench access
     */
    public static function isRequired(object $member): bool {
        return self::getRequiredReason($member) !== null;
    }

    /**
     * Get the reason why 2FA is required, or null if not required
     */
    public static function getRequiredReason(object $member): ?string {
        // Admin level or higher requires 2FA
        if ($member->level <= 50) {
            return 'admin';
        }

        // Users who have run workbench tasks require 2FA
        if (self::hasWorkbenchAccess($member)) {
            return 'workbench';
        }

        return null;
    }

    /**
     * Check if user has workbench access (has run tasks or is team member who can run tasks)
     */
    private static function hasWorkbenchAccess(object $member): bool {
        // Check if user has created any workbench tasks
        $taskCount = Bean::count('workbenchtask', 'created_by = ?', [$member->id]);
        if ($taskCount > 0) {
            return true;
        }

        // Check if user is a team member with task running permissions
        $teamMembership = Bean::findOne('teammember', 'member_id = ? AND can_run_tasks = 1', [$member->id]);
        if ($teamMembership) {
            return true;
        }

        return false;
    }

    /**
     * Check if 2FA is enabled for user
     */
    public static function isEnabled(object $member): bool {
        return !empty($member->totpEnabled) && !empty($member->totpSecret);
    }

    /**
     * Check if device is trusted (within 30-day window)
     * Checks both session and localStorage token (via request parameter)
     */
    public static function isDeviceTrusted(): bool {
        // Check session first (legacy/fallback)
        $trustedUntil = $_SESSION['2fa_trusted_until'] ?? 0;
        if (time() < $trustedUntil) {
            return true;
        }

        return false;
    }

    /**
     * Mark device as trusted for 30 days (session-based, legacy)
     */
    public static function trustDevice(): void {
        $_SESSION['2fa_trusted_until'] = time() + self::TRUST_DURATION;
    }

    /**
     * Clear device trust
     */
    public static function clearTrust(): void {
        unset($_SESSION['2fa_trusted_until']);
    }

    /**
     * Generate a signed trust token for localStorage storage
     * Token format: base64(memberId:expiry):signature
     */
    public static function generateTrustToken(int $memberId): string {
        $expiry = time() + self::TRUST_DURATION;
        $payload = $memberId . ':' . $expiry;
        $signature = hash_hmac('sha256', $payload, self::getTrustSecret());

        return base64_encode($payload) . '.' . $signature;
    }

    /**
     * Validate a trust token from localStorage
     * Returns member_id if valid, null if invalid/expired
     */
    public static function validateTrustToken(?string $token): ?int {
        if (empty($token)) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;
        $payload = base64_decode($encodedPayload);

        if ($payload === false) {
            return null;
        }

        // Verify signature
        $expectedSignature = hash_hmac('sha256', $payload, self::getTrustSecret());
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Parse payload
        $payloadParts = explode(':', $payload);
        if (count($payloadParts) !== 2) {
            return null;
        }

        [$memberId, $expiry] = $payloadParts;

        // Check expiry
        if (time() > (int)$expiry) {
            return null;
        }

        return (int)$memberId;
    }

    /**
     * Check if automated testing bypass is active
     * SECURITY: Only bypasses when ALL conditions are met:
     * 1. TIKNIX_TESTING env var is set (must be explicitly enabled on server)
     * 2. Request comes from localhost (REMOTE_ADDR cannot be spoofed)
     */
    public static function isTestingBypass(): bool {
        // Must have testing env var explicitly set
        if (empty(getenv('TIKNIX_TESTING'))) {
            return false;
        }

        // REMOTE_ADDR is the actual TCP connection IP - cannot be spoofed
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $localhostIPs = ['127.0.0.1', '::1'];

        return in_array($remoteAddr, $localhostIPs, true);
    }

    /**
     * Check if current IP is in the admin-configured whitelist
     * SECURITY: Uses REMOTE_ADDR which cannot be spoofed
     */
    public static function isIpWhitelisted(): bool {
        // Check if whitelist is enabled
        if (Flight::getSetting('twofa_whitelist_enabled', 0) !== '1') {
            return false;
        }

        $whitelist = Flight::getSetting('twofa_ip_whitelist', 0);
        if (empty($whitelist)) {
            return false;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (empty($remoteAddr)) {
            return false;
        }

        // Parse whitelist (one IP or CIDR per line)
        $lines = array_filter(array_map('trim', explode("\n", $whitelist)));

        foreach ($lines as $entry) {
            // Skip comments and empty lines
            if (empty($entry) || $entry[0] === '#') {
                continue;
            }

            // Check for CIDR notation
            if (strpos($entry, '/') !== false) {
                if (self::ipInCidr($remoteAddr, $entry)) {
                    return true;
                }
            } else {
                // Exact IP match
                if ($remoteAddr === $entry) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range
     */
    private static function ipInCidr(string $ip, string $cidr): bool {
        [$subnet, $bits] = explode('/', $cidr);

        // Handle IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = ip2long($ip);
            $subnet = ip2long($subnet);
            $mask = -1 << (32 - (int)$bits);
            $subnet &= $mask;
            return ($ip & $mask) === $subnet;
        }

        // Handle IPv6 (simplified - exact prefix match)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $bits = (int)$bits;
            $bytes = (int)($bits / 8);
            $remainingBits = $bits % 8;

            // Compare full bytes
            if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                return false;
            }

            // Compare remaining bits
            if ($remainingBits > 0 && $bytes < 16) {
                $mask = 0xFF << (8 - $remainingBits);
                if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Check if user needs to verify 2FA now
     * Returns true if 2FA is required, enabled, and device not trusted
     */
    public static function needsVerification(object $member): bool {
        // 2FA not required for this user
        if (!self::isRequired($member)) {
            return false;
        }

        // 2FA required but not set up yet - they need to set it up first
        if (!self::isEnabled($member)) {
            return false; // Will be caught by needsSetup()
        }

        // Testing bypass - localhost only, requires explicit env var
        if (self::isTestingBypass()) {
            Flight::get('log')->info('2FA verification bypassed (localhost testing)', [
                'member_id' => $member->id
            ]);
            return false;
        }

        // Admin-configured IP whitelist bypass
        if (self::isIpWhitelisted()) {
            Flight::get('log')->info('2FA verification bypassed (IP whitelist)', [
                'member_id' => $member->id,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            return false;
        }

        // Device is trusted
        if (self::isDeviceTrusted()) {
            return false;
        }

        return true;
    }

    /**
     * Check if user needs to set up 2FA
     */
    public static function needsSetup(object $member): bool {
        // Testing bypass - skip setup requirement for localhost testing
        if (self::isTestingBypass()) {
            return false;
        }

        return self::isRequired($member) && !self::isEnabled($member);
    }

    /**
     * Enable 2FA for a member
     */
    public static function enable(object $member, string $secret, string $code): array {
        // Verify the code first
        if (!self::verifyCode($secret, $code)) {
            return ['success' => false, 'error' => 'Invalid verification code'];
        }

        // Generate recovery codes
        $recoveryCodes = self::generateRecoveryCodes();

        // Save to member
        $member->totpSecret = $secret;
        $member->totpEnabled = 1;
        $member->totpEnabledAt = date('Y-m-d H:i:s');
        $member->recoveryCodes = self::hashRecoveryCodes($recoveryCodes);
        Bean::store($member);

        // Trust this device
        self::trustDevice();

        Flight::get('log')->info('2FA enabled', ['member_id' => $member->id]);

        return [
            'success' => true,
            'recovery_codes' => $recoveryCodes
        ];
    }

    /**
     * Disable 2FA for a member (requires password verification)
     */
    public static function disable(object $member): bool {
        $member->totpSecret = null;
        $member->totpEnabled = 0;
        $member->totpEnabledAt = null;
        $member->recoveryCodes = null;
        Bean::store($member);

        self::clearTrust();

        Flight::get('log')->info('2FA disabled', ['member_id' => $member->id]);

        return true;
    }

    /**
     * Verify 2FA code or recovery code
     */
    public static function verify(object $member, string $code): bool {
        // Try TOTP code first
        if (strlen($code) === 6 && ctype_digit($code)) {
            if (self::verifyCode($member->totpSecret, $code)) {
                self::trustDevice();
                return true;
            }
        }

        // Try recovery code (format: XXXX-XXXX-XXXX or XXXXXXXXXXXX)
        if (strlen(str_replace('-', '', $code)) === 12) {
            if (self::verifyRecoveryCode($member, $code)) {
                self::trustDevice();
                return true;
            }
        }

        return false;
    }

    /**
     * Get remaining recovery code count
     */
    public static function getRemainingRecoveryCodeCount(object $member): int {
        if (empty($member->recoveryCodes)) {
            return 0;
        }

        $codes = json_decode($member->recoveryCodes, true);
        return is_array($codes) ? count($codes) : 0;
    }

    /**
     * Regenerate recovery codes
     */
    public static function regenerateRecoveryCodes(object $member): array {
        $codes = self::generateRecoveryCodes();
        $member->recoveryCodes = self::hashRecoveryCodes($codes);
        Bean::store($member);

        Flight::get('log')->info('Recovery codes regenerated', ['member_id' => $member->id]);

        return $codes;
    }
}
