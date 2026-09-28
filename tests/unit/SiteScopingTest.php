<?php
/**
 * Per-site data (CONNECTOR-CATALOG-PLAN.md §2c) — the concept declares which of its beans are
 * per-site; the runtime does the rest:
 *
 *   scopeBean   adds site_ref + index once, backfills existing rows with the default site,
 *               is a no-op on a table that does not exist yet, refuses a non-bean name
 *   filter      prefixes the current site to a where clause (or to an ORDER BY alone)
 *   stamp       a new row gets the current site; a row that has one keeps it
 *   manifest    `scoped` must name the concept's own beans
 *
 * Scratch in-memory database (RedBean's default, as on a live install).
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ConceptException;
use app\ConceptManifest;
use app\Sites;

class SiteScopingTest extends ConceptsTestCase {

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        Bean::selectDatabase('default');
        \RedBeanPHP\R::nuke();
        \Flight::set('app.baseurl', 'https://serenity.example.com');
        $_SESSION = []; Sites::reset();
        Sites::create('main', 'Serenity');
    }

    protected function tearDown(): void { Sites::reset(); $_SESSION = []; parent::tearDown(); }

    public function testScopeBeanAddsBackfillsAndIsIdempotent(): void {
        $this->assertSame('unchanged', Sites::scopeBean('shoporder'), 'no table yet: nothing to do');
        for ($i = 0; $i < 3; $i++) { $o = Bean::dispense('shoporder'); $o->total = 10 + $i; Bean::store($o); }
        $this->assertSame('added, backfilled:3', Sites::scopeBean('shoporder'));
        $main = (int) Sites::default()->id;
        $this->assertSame(3, Bean::count('shoporder', 'site_ref = ?', [$main]));
        $this->assertSame('unchanged', Sites::scopeBean('shoporder'));
        $o = Bean::dispense('shoporder'); $o->total = 99; Bean::store($o);   // no stamp: an unowned row
        $this->assertSame('backfilled:1', Sites::scopeBean('shoporder'), 'a later unowned row is claimed by main');
        try { Sites::scopeBean('shop order; DROP'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not a bean name', $e->getMessage()); }
    }

    public function testFilterAndStampFollowTheCurrentSite(): void {
        $main = (int) Sites::default()->id;
        $denver = Sites::create('denver', 'Denver');
        $this->assertSame(['site_ref = ?', [$main]], Sites::filter());
        $this->assertSame(['site_ref = ? AND (status = ?)', [$main, 'paid']], Sites::filter('status = ?', ['paid']));
        $this->assertSame(['site_ref = ? ORDER BY id DESC', [$main]], Sites::filter('ORDER BY id DESC'));
        $this->assertSame(['o.site_ref = ? AND (o.status = ?)', [$main, 'paid']], Sites::filter('o.status = ?', ['paid'], 'o'));

        $o = Bean::dispense('shoporder'); Sites::stamp($o); Bean::store($o);
        $this->assertSame($main, (int) $o->siteRef);
        Sites::switchTo((int) $denver->id); Sites::reset();
        $d = Bean::dispense('shoporder'); Sites::stamp($d); Bean::store($d);
        $this->assertSame((int) $denver->id, (int) $d->siteRef);
        Sites::stamp($o);
        $this->assertSame($main, (int) $o->siteRef, 'a row that has a site keeps it');
        [$w, $p] = Sites::filter();
        $this->assertSame([(int) $d->id], array_map('intval', array_keys(Bean::find('shoporder', $w, $p))), 'Denver sees only its own row');
    }

    /** A sidecar has no site table and never will: that is "no sites", not a broken install. */
    public function testInstalledSaysWhetherTheInstallHasSitesAtAll(): void {
        $this->assertTrue(Sites::installed());
        \RedBeanPHP\R::nuke();
        Sites::reset();
        $this->assertFalse(Sites::installed(), 'no site table');
        try { Sites::default(); $this->fail('a table with no main row, or no table, is asked for its default'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('seed 21_Sites', $e->getMessage()); }
    }

    public function testManifestScopedMustBeOwnBeans(): void {
        $n = $this->uniq('sc');
        $m = ConceptManifest::load($this->concept($n, ['provides' => ['beans' => ['shoporder', 'product']], 'scoped' => ['shoporder']]), $n);
        $this->assertSame(['shoporder'], $m->scoped);
        try {
            $n2 = $this->uniq('sc');
            ConceptManifest::load($this->concept($n2, ['provides' => ['beans' => ['product']], 'scoped' => ['shoporder']]), $n2);
            $this->fail('scoped a bean it does not provide');
        } catch (ConceptException $e) { $this->assertStringContainsString("scopes only its own beans", $e->getMessage()); }
    }
}
