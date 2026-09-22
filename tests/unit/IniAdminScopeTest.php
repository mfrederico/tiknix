<?php
/**
 * /settings for an ADMIN is config.ini narrowed by IniFileService::adminScope(). The
 * narrowing is the security boundary between "an owner can turn 2FA off" and "an owner
 * can read the app key", so its rules are pinned: the install's plumbing sections
 * (ROOT_SECTIONS, and sidecar.*) are gone while the app's own sections — ones core has
 * never heard of, like [brand] — stay; inside every section a secret key is ABSENT — not
 * masked, absent — while a policy key that merely contains "password" stays visible.
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

[brand]
instagram_url = "https://instagram.com/serenity"
tagline = "Rest, restored"
INI;
        $file = tempnam(sys_get_temp_dir(), 'ini');
        file_put_contents($file, $ini);
        try { return IniFileService::parse($file); } finally { @unlink($file); }
    }

    public function testPlumbingSectionsAreRootsAppSectionsAreNot(): void {
        $scoped = IniFileService::adminScope($this->parsed());
        $names  = array_keys($scoped['sections']);
        sort($names);
        $this->assertSame(['app', 'brand', 'features', 'mail', 'security'], $names);
        $this->assertArrayNotHasKey('database', $scoped['sections']);
        $this->assertArrayNotHasKey('sidecar.workbench', $scoped['sections'], 'sidecar.* is covered by sidecar');
        $this->assertSame(['instagram_url', 'tagline'], array_keys($scoped['sections']['brand']['keys']), 'an app-added section is the owner\'s');
    }

    public function testIsRootSectionMatchesPrefixedSubsections(): void {
        $this->assertTrue(IniFileService::isRootSection('database'));
        $this->assertTrue(IniFileService::isRootSection('sidecar'));
        $this->assertTrue(IniFileService::isRootSection('sidecar.explorer'));
        $this->assertFalse(IniFileService::isRootSection('sidecars'), 'prefix match needs the dot');
        $this->assertFalse(IniFileService::isRootSection('features'));
        $this->assertFalse(IniFileService::isRootSection('brand'));
    }

    public function testASectionOfOnlySecretsIsDropped(): void {
        $file = tempnam(sys_get_temp_dir(), 'ini');
        file_put_contents($file, "[turnstile]\nsite_key = \"a\"\nsecret_key = \"b\"\n[features]\nleads = true\n");
        try { $scoped = IniFileService::adminScope(IniFileService::parse($file)); } finally { @unlink($file); }
        $this->assertSame(['features'], array_keys($scoped['sections']));
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
