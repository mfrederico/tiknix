<?php
/**
 * /settings for an ADMIN is config.ini narrowed by IniFileService::adminScope(). The
 * narrowing is the security boundary between "an owner can turn 2FA off" and "an owner
 * can read the app key", so its two rules are pinned: only allowlisted sections survive,
 * and inside them a secret key is ABSENT — not masked, absent — while a policy key that
 * merely contains the word "password" stays visible.
 */

namespace tests\unit;

use app\services\Config\IniFileService;
use PHPUnit\Framework\TestCase;

class IniAdminScopeTest extends TestCase {

    private function parsed(): array {
        $ini = <<<INI
[app]
site_name = "Tiknix"
debug = false

[database]
path = "database/tiknix.db"

[security]
app_key = "0123456789abcdef0123456789abcdef"
two_factor_enabled = true
two_factor_enforce = false
password_min_length = 12

[features]
communications = true
leads = false

[mail]
mailgun_api_key = "key-abc"
from_email = "noreply@example.com"

[sidecar.workbench]
url = "https://workbench.example.com"
sso_secret = "shh"
INI;
        $file = tempnam(sys_get_temp_dir(), 'ini');
        file_put_contents($file, $ini);
        try { return IniFileService::parse($file); } finally { @unlink($file); }
    }

    public function testOnlyAllowlistedSectionsSurvive(): void {
        $scoped = IniFileService::adminScope($this->parsed());
        $names  = array_keys($scoped['sections']);
        sort($names);
        $this->assertSame(['app', 'features', 'mail', 'security'], $names);
        $this->assertArrayNotHasKey('database', $scoped['sections']);
        $this->assertArrayNotHasKey('sidecar.workbench', $scoped['sections']);
    }

    public function testFeaturesSectionIsEditableWithItsKeys(): void {
        $scoped = IniFileService::adminScope($this->parsed());
        $this->assertSame(['communications', 'leads'], array_keys($scoped['sections']['features']['keys']));
        $this->assertSame('true', (string) $scoped['sections']['features']['keys']['communications']['value']);
    }

    public function testSecretKeysAreAbsentNotMasked(): void {
        $scoped = IniFileService::adminScope($this->parsed());
        $sec  = array_keys($scoped['sections']['security']['keys']);
        $mail = array_keys($scoped['sections']['mail']['keys']);
        $this->assertNotContains('app_key', $sec);
        $this->assertNotContains('mailgun_api_key', $mail);
        $this->assertContains('from_email', $mail);
        // The whole scoped structure must not carry the secret's value anywhere.
        $this->assertStringNotContainsString('0123456789abcdef', json_encode($scoped['sections']));
        $this->assertStringNotContainsString('key-abc', json_encode($scoped['sections']));
    }

    public function testPasswordPolicyKeysStayVisible(): void {
        $scoped = IniFileService::adminScope($this->parsed());
        $sec = array_keys($scoped['sections']['security']['keys']);
        $this->assertContains('password_min_length', $sec, 'a length rule is policy, not a credential');
        $this->assertContains('two_factor_enabled', $sec);
        $this->assertContains('two_factor_enforce', $sec);
    }
}
