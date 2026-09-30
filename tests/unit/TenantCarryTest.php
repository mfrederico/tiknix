<?php
/**
 * TenantCarry's config merge (RUNTIME-SPLIT-MAP.md step 5): a project carried from a host clone
 * keeps its own settings on the template's config.ini — but not what belonged to the host or the
 * control plane, and never a path into the host.
 */

namespace tests\unit;

use app\TenantCarry;
use PHPUnit\Framework\TestCase;

class TenantCarryTest extends TestCase {

    private const TENANT = <<<'INI'
[app]
name = "pd"
baseurl = "https://example.invalid"
environment = "production"

[database]
type = "sqlite"
path = "database/app.db"

[cache]
version_store = "apcu"  ; apcu | valkey
redis_host = "127.0.0.1"

[security]
app_key = "tenant-key"
two_factor_enforce = false
INI;

    private function old(string $extra = ''): string {
        return <<<INI
[app]
name = "Headwaters"
baseurl = "https://pd.tiknix.com"
environment = "development"

[database]
path = database/pd.db

[cache]
version_store = "valkey"
redis_host = "127.0.0.1"
redis_port = 6379

[security]
csrf_enabled = true

[firehose]
api_key = "core-reporting-key"

[mail]
smtp_password = "x"

[maintenance]
enabled = false
{$extra}
INI;
    }

    public function testTheProjectsSettingsWinExceptTheHostsAndTheControlPlanes(): void {
        $ini = TenantCarry::mergeConfig(self::TENANT, $this->old(), '/var/www/html/default/pd.tiknix', 'pd-next.tiknix.com');
        $c = parse_ini_string($ini, true, INI_SCANNER_RAW);
        $this->assertSame('Headwaters', $c['app']['name'], "the project's own name");
        $this->assertSame('development', $c['app']['environment'], "the project's own choice");
        $this->assertSame('https://pd-next.tiknix.com', $c['app']['baseurl'], 'the base URL is the host it is served at');
        $this->assertSame('database/pd.db', $c['database']['path'], 'the carried database');
        $this->assertSame('sqlite', $c['database']['type'], "the template's key the project lacked");
        $this->assertSame('apcu', $c['cache']['version_store'], 'no valkey in a tenant');
        $this->assertArrayNotHasKey('redis_port', $c['cache']);
        $this->assertSame('tenant-key', $c['security']['app_key'], 'no app_key in the project: nothing was encrypted with one');
        $this->assertSame('true', $c['security']['csrf_enabled'], "the project's extra key, kept in its section");
        $this->assertArrayNotHasKey('firehose', $c, "reporting to core is the control plane's");
        $this->assertArrayNotHasKey('mail', $c, 'mail is a connection, never config');
        $this->assertSame('false', $c['maintenance']['enabled'], 'a section the template lacks comes along');
    }

    public function testTheProjectsAppKeyWinsWhenItHasOne(): void {
        $old = str_replace("csrf_enabled = true", "csrf_enabled = true\napp_key = \"project-key\"", $this->old());
        $c = parse_ini_string(TenantCarry::mergeConfig(self::TENANT, $old, '/x', 'h.tiknix.com'), true, INI_SCANNER_RAW);
        $this->assertSame('project-key', $c['security']['app_key'], 'its encrypted data needs its own key');
    }

    public function testAHostPathIsRewrittenOrRefused(): void {
        $dir = '/var/www/html/default/pd.tiknix';
        $ini = TenantCarry::mergeConfig(self::TENANT, $this->old("\n[uploads]\npath = \"{$dir}/uploads\""), $dir, 'h.tiknix.com');
        $this->assertStringContainsString('path = "/srv/app/uploads"', $ini, "a path inside the project becomes the tenant's");
        try {
            TenantCarry::mergeConfig(self::TENANT, $this->old("\n[uploads]\npath = \"/var/www/html/default/other/uploads\""), $dir, 'h.tiknix.com');
            $this->fail('a path into another directory of the host was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('names host paths the tenant cannot reach', $e->getMessage());
        }
    }
}
