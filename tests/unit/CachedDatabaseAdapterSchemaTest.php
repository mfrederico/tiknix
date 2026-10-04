<?php
/**
 * The query cache must never answer "which tables exist?" from memory, on ANY read path.
 *
 * RedBean's getTables() runs through getCol(). get() excluded schema queries; getCol()
 * did not, so a table fluid-created by one request stayed absent for every later one and
 * the next store ran CREATE TABLE into "table `launchnonce` already exists" — a 500 on the
 * second /auth/launch of every new project. One test (cacheable()) now serves all four
 * entry points; this pins it.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;

class CachedDatabaseAdapterSchemaTest extends TestCase {

    private function adapter(): \app\CachedDatabaseAdapter {
        $driver = new \RedBeanPHP\Driver\RPDO('sqlite::memory:');
        $a = new \app\CachedDatabaseAdapter($driver);
        $a->enableCache();
        return $a;
    }

    private function cacheable(\app\CachedDatabaseAdapter $a, string $sql): bool {
        $m = new \ReflectionMethod($a, 'cacheable');
        $m->setAccessible(true);
        return (bool) $m->invoke($a, $sql);
    }

    public function testTheTableListIsNeverCached(): void {
        $a = $this->adapter();
        $this->assertFalse($this->cacheable($a, "SELECT name FROM sqlite_master WHERE type='table' AND name!='sqlite_sequence';"),
            "RedBean's getTables() — the query behind createTableIfNotExists — must hit the database every time");
        $this->assertFalse($this->cacheable($a, 'PRAGMA table_info(member)'));
        $this->assertFalse($this->cacheable($a, 'SHOW TABLES'));
    }

    public function testOrdinaryReadsAreCached(): void {
        $a = $this->adapter();
        $this->assertTrue($this->cacheable($a, 'SELECT * FROM member WHERE id = ?'));
    }

    public function testEveryReadPathUsesTheOneTest(): void {
        // The bug was a fix made in one of four copies. Each read path must consult cacheable().
        $src = file_get_contents((new \ReflectionClass(\app\CachedDatabaseAdapter::class))->getFileName());
        foreach (['get', 'getCell', 'getCol', 'getRow'] as $fn) {
            $pos = strpos($src, "public function {$fn}(");
            $this->assertNotFalse($pos, "{$fn}() exists");
            $body = substr($src, $pos, 400);
            $this->assertStringContainsString('$this->cacheable($sql)', $body, "{$fn}() decides with cacheable()");
        }
    }
}
