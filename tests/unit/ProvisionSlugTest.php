<?php
/** A project's address: the name part gives way to the unique "-<hash>", so a long name still makes a valid address (at most 40). */

namespace tests\unit;

use app\Bean;
use app\PlanHandoff;
use app\ProvisionService;
use PHPUnit\Framework\TestCase;

class ProvisionSlugTest extends TestCase {
    private ?string $was = null;

    protected function setUp(): void {
        $this->was = Bean::currentDatabaseKey();
        $db = 'slug-test-' . bin2hex(random_bytes(4));
        Bean::addDatabase($db, 'sqlite::memory:');
        Bean::selectDatabase($db);
    }

    protected function tearDown(): void { if ($this->was !== null && Bean::hasDatabase($this->was)) Bean::selectDatabase($this->was); }

    private function mint(string $base): string {
        $m = new \ReflectionMethod(ProvisionService::class, 'mintSlug'); $m->setAccessible(true);
        return $m->invoke(new ProvisionService(), $base);
    }

    public function testALongNameStillMakesAnAddressThatFits(): void {
        foreach (['builds-marketing-software-for-agencies', PlanHandoff::slugBase(str_repeat('Very long business name ', 5)), 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbb'] as $base) {
            $slug = $this->mint($base);
            $this->assertLessThanOrEqual(40, strlen($slug), $slug);
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $slug, 'no doubled or trailing hyphen where the name was cut');
            $this->assertStringStartsWith(substr($base, 0, 20), $slug);
        }
    }

    public function testAShortNameIsKeptWhole(): void {
        $this->assertMatchesRegularExpression('/^sunrise-bakery-[0-9a-f]{6}$/', $this->mint('sunrise-bakery'));
    }
}
