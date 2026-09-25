<?php
/**
 * PermissionCache::seedRule — a seed sets a route's level correctly, whatever touched it first.
 *
 *   added        no row → the seeded row
 *   corrected    an auto-generated row (the default a fetch leaves behind) is overruled
 *   kept         a row somebody set is never overruled
 *   wildcard     seeding `<control>::*` removes the auto-generated METHOD rows that would
 *                shadow it (check() reads the method row first), keeps hand-set ones, and
 *                reports `corrected` when it had to
 *
 * Scratch in-memory database.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\PermissionCache;

class PermissionSeedRuleTest extends ConceptsTestCase {

    private const DB = 'seed-rule-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function row(string $method, int $level, string $description): void {
        $r = Bean::dispense('authcontrol');
        $r->control = 'wizard'; $r->method = $method; $r->level = $level; $r->description = $description; $r->validcount = 0;
        Bean::store($r);
    }

    private function levels(): array {
        $out = [];
        foreach (Bean::find('authcontrol', 'control = ? ORDER BY method', ['wizard']) as $r) $out[$r->method] = (int) $r->level;
        return $out;
    }

    public function testAddedCorrectedKept(): void {
        $this->assertSame('added', PermissionCache::seedRule('wizard', 'index', 101, 'public'));
        $this->assertSame('unchanged', PermissionCache::seedRule('wizard', 'index', 101));
        $this->row('step', 50, PermissionCache::AUTO_MARK . ' wizard::step');
        $this->assertSame('corrected', PermissionCache::seedRule('wizard', 'step', 101));
        $this->row('admin', 50, 'ROOT set this on purpose');
        $this->assertSame('kept', PermissionCache::seedRule('wizard', 'admin', 101));
        $this->assertSame(['admin' => 50, 'index' => 101, 'step' => 101], $this->levels());
    }

    public function testAWildcardSeedRemovesTheAutoRowsThatWouldShadowIt(): void {
        // What a fetch before the seed leaves behind: one ADMIN row per touched method.
        foreach (['index', 'step', 'brief'] as $m) $this->row($m, 50, PermissionCache::AUTO_MARK . " wizard::{$m}");
        $this->row('export', 1, 'ROOT only, by decision');
        $this->assertSame('corrected', PermissionCache::seedRule('wizard', '*', 101, 'public wizard'));
        $this->assertSame(['*' => 101, 'export' => 1], $this->levels(), 'auto rows gone, the hand-set exception kept');
        $this->assertSame('unchanged', PermissionCache::seedRule('wizard', '*', 101), 'nothing left to remove');
        // A touch after the seed cannot shadow it again through this path either:
        $this->row('status', 50, PermissionCache::AUTO_MARK . ' wizard::status');
        $this->assertSame('corrected', PermissionCache::seedRule('wizard', '*', 101));
        $this->assertArrayNotHasKey('status', $this->levels());
    }
}
