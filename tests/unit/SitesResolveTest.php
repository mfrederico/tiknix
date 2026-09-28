<?php
/**
 * Sites::current() — the franchise / location an install is acting for, resolved once per
 * request in a fixed order (CONNECTOR-CATALOG-PLAN.md §2c):
 *
 *   host      a host that names a site's domain wins; the install's own host falls through;
 *             a host that names no site is SiteNotFoundException, never the default site
 *   switcher  the session's chosen site
 *   member    the member's default site
 *   default   the seeded 'main' site; missing = a loud error, not a stand-in
 *   multi     the multi-site UI switches on at the second site
 *
 * Scratch in-memory database.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\SiteNotFoundException;
use app\Sites;

class SitesResolveTest extends ConceptsTestCase {

    private const DB = 'sites-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        \Flight::set('app.baseurl', 'https://serenity.example.com');
        $_SESSION = [];
        Sites::reset();
    }

    protected function tearDown(): void { Sites::reset(); $_SESSION = []; Bean::selectDatabase('default'); parent::tearDown(); }

    public function testNoDefaultSiteIsAnError(): void {
        $this->expectExceptionMessage("no default site 'main'");
        Sites::current('serenity.example.com', 0);
    }

    public function testResolutionOrder(): void {
        $main   = Sites::create('main', 'Serenity');
        $this->assertFalse(Sites::multi());
        $this->assertSame((int) $main->id, (int) Sites::current('serenity.example.com', 0)->id, 'default');
        Sites::reset();

        $denver = Sites::create('denver', 'Serenity Denver', 'serenity-denver.example.com');
        $this->assertTrue(Sites::multi());
        $this->assertSame('denver', Sites::current('serenity-denver.example.com', 0)->slug, 'host wins');
        Sites::reset();
        $this->assertSame('denver', Sites::current('SERENITY-DENVER.example.com:8443', 0)->slug, 'host is case-insensitive, port ignored');
        Sites::reset();
        $this->assertSame('main', Sites::current('serenity.example.com', 0)->slug, "the install's own host falls through");
        Sites::reset();

        try { Sites::current('serenity-boston.example.com', 0); $this->fail('an unknown host must not resolve'); }
        catch (SiteNotFoundException $e) { $this->assertStringContainsString('serenity-boston.example.com', $e->getMessage()); $this->assertStringContainsString('denver', $e->getMessage()); }
        Sites::reset();

        // The switcher, then the member's default, on the install's own host.
        Sites::switchTo((int) $denver->id);
        Sites::reset();
        $this->assertSame('denver', Sites::current('serenity.example.com', 0)->slug, 'switcher');
        $_SESSION = []; Sites::reset();
        $m = Bean::dispense('member'); $m->email = 'la@example.com'; $m->siteRef = (int) Sites::create('la', 'Serenity LA')->id; Bean::store($m);
        $this->assertSame('la', Sites::current('serenity.example.com', (int) $m->id)->slug, "member's default site");
        Sites::reset();
        $this->assertSame('main', Sites::current('serenity.example.com', 0)->slug, 'nobody: the default');
    }

    public function testSwitchingToAMissingSiteIsRefusedAndAStaleSwitchIsDropped(): void {
        Sites::create('main', 'Serenity');
        try { Sites::switchTo(999); $this->fail(); } catch (SiteNotFoundException $e) { $this->assertStringContainsString('#999', $e->getMessage()); }
        $_SESSION['tiknix.site'] = 999;
        $this->assertSame('main', Sites::current('serenity.example.com', 0)->slug);
        $this->assertArrayNotHasKey('tiknix.site', $_SESSION, 'the void switch is cleared');
    }

    public function testCreateValidatesAndWhereScopes(): void {
        $main = Sites::create('main', 'Serenity');
        foreach (['9la', 'x', '-la', 'los angeles'] as $bad) {
            try { Sites::create($bad, 'x'); $this->fail("accepted slug '{$bad}'"); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('slug', $e->getMessage()); }
        }
        try { Sites::create('main', 'again'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already exists', $e->getMessage()); }
        Sites::create('denver', 'Denver', 'serenity-denver.example.com');
        try { Sites::create('den2', 'Denver 2', 'serenity-denver.example.com'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already names a site', $e->getMessage()); }
        $this->assertSame(['site_ref = ?', [(int) $main->id]], Sites::where());
        $this->assertSame(['o.site_ref = ?', [(int) $main->id]], Sites::where('o'));
    }
}
