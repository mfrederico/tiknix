<?php
/**
 * Sites::applyConfig — a site with its own domain has its own .ini (conf/sites/<slug>.ini),
 * merged over the install's config for the request:
 *
 *   overlay     allowed sections' keys land in Flight config; the list of keys is returned
 *   name        with no overlay, a second site's name still replaces the install's app.name;
 *               a single-site install is left alone
 *   refusals    a section a site may not own ([database], [security]), a credential-shaped key,
 *               a malformed file — each an error naming the file, never a half-applied site
 *
 * The overlay path is under core's conf/sites/, so the test writes and removes its own file
 * with a slug no site has.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\Sites;

class SiteConfigOverlayTest extends ConceptsTestCase {

    private array $files = [];

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        Bean::selectDatabase('default');
        \RedBeanPHP\R::nuke();
        \Flight::set('app.baseurl', 'https://serenity.example.com');
        \Flight::set('app.name', 'Serenity');
        \Flight::set('mail.from_email', 'hello@serenity.example.com');
        $_SESSION = []; Sites::reset();
        Sites::create('main', 'Serenity');
        if (!is_dir(dirname(__DIR__, 2) . '/conf/sites')) mkdir(dirname(__DIR__, 2) . '/conf/sites', 0775, true);
    }

    protected function tearDown(): void {
        foreach ($this->files as $f) @unlink($f);
        \Flight::set('app.name', 'Serenity');
        Sites::reset(); $_SESSION = [];
        parent::tearDown();
    }

    private function overlay(\RedBeanPHP\OODBBean $site, string $ini): void {
        $f = Sites::configPath($site);
        file_put_contents($f, $ini);
        $this->files[] = $f;
    }

    public function testOverlayAndNameRules(): void {
        $main = Sites::default();
        $this->assertSame([], Sites::applyConfig($main), 'single site, no overlay: nothing changes');
        $this->assertSame('Serenity', \Flight::get('app.name'));

        $denver = Sites::create('zztestdenver', 'Serenity Denver', 'denver.serenity.example.com');
        $this->assertSame(['app.name'], Sites::applyConfig($denver), 'multi-site, no overlay: the site name applies');
        $this->assertSame('Serenity Denver', \Flight::get('app.name'));

        $this->overlay($denver, "[app]\nname = \"Serenity — Denver\"\ntimezone = \"America/Denver\"\n[mail]\nfrom_email = \"denver@serenity.example.com\"\n[brand]\nprimary = \"#336699\"\n");
        $applied = Sites::applyConfig($denver);
        $this->assertSame(['app.name', 'app.timezone', 'mail.from_email', 'brand.primary'], $applied);
        $this->assertSame('Serenity — Denver', \Flight::get('app.name'));
        $this->assertSame('denver@serenity.example.com', \Flight::get('mail.from_email'));
        $this->assertSame('#336699', \Flight::get('brand.primary'));
    }

    public function testRefusals(): void {
        $site = Sites::create('zztestrefuse', 'Refuse', 'refuse.serenity.example.com');
        foreach ([
            ["[database]\npath = other.db\n", 'may not own'],
            ["[security]\napp_key = x\n", 'may not own'],
            ["[mail]\nmailgun_api_key = key-123\n", 'credentials live in Connections'],
            ["[app]\nsecret = x\n", 'credentials live in Connections'],
            ["name = orphan\n[app]\nname = x\n", 'outside any section'],
            ["[app\nname = \"broken\n", 'not a valid ini'],
        ] as [$ini, $msg]) {
            $this->overlay($site, $ini);
            try { Sites::applyConfig($site); $this->fail("applied: {$ini}"); }
            catch (\RuntimeException $e) { $this->assertStringContainsString($msg, $e->getMessage(), $ini); $this->assertStringContainsString('zztestrefuse.ini', $e->getMessage()); }
        }
    }
}
