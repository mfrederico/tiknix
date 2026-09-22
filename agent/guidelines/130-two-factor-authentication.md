## Two-Factor Authentication (2FA)

TOTP-based 2FA for admin users (level ≤ 50) and workbench users. Whether it is
**required**, **optional**, or **off** is controlled by `conf/config.ini` `[security]`:

```ini
[security]
two_factor_enabled = true   ; master switch — false disables 2FA entirely (no setup, no verify)
two_factor_enforce = true   ; false = OPTIONAL (eligible users prompted but can "Skip for now"); true = required
```

- **enabled=false** → 2FA completely off (handy for local dev).
- **enabled=true, enforce=false** → optional: eligible users are prompted at login but may hit **Skip for now** (`/auth/twofaskip`, session-scoped); anyone who opts in still verifies each login.
- **enabled=true, enforce=true** → required for `REQUIRED_LEVELS` (default, secure).

The enforcement choke points are `TwoFactorAuth::needsSetup()` / `needsVerification()`; policy is read via `policyEnabled()` / `policyEnforced()`. Level scope in `lib/TwoFactorAuth.php`:

```php
public const TRUST_DURATION = 30 * 24 * 60 * 60;  // 30 days device trust
public const REQUIRED_LEVELS = [1, 50];            // ROOT, ADMIN in scope for 2FA
```

**Login flow for admin users (when required):**
1. Enter username/password → redirects to `/auth/twofasetup` (first time) or `/auth/twofaverify`
2. Scan QR code with authenticator app (Google Authenticator, Authy, etc.)
3. Enter 6-digit TOTP code
4. First setup shows recovery codes (10 single-use codes)
5. Device trusted for 30 days (no 2FA prompt on same device)

**Key files:**
- `lib/TwoFactorAuth.php` - Core 2FA logic
- `views/auth/2fa-setup.php` - QR code setup page
- `views/auth/2fa-verify.php` - Login verification page
- `views/auth/2fa-recovery-codes.php` - Recovery codes display
