<?php
/**
 * Model_Instance::isolationLive / isolationStateFor — isolation is read from the pool, not
 * from a column nobody updates.
 *
 *   live       marker "SOCK=<path>" at the instance root and that path exists → true, and the
 *              recorded state (pending, failed, '') is corrected to 'active'
 *   not live   no marker, a marker without SOCK=, or a socket path that does not exist → the
 *              recorded state stands ('pending' stays "finishing setup", 'failed' stays delayed)
 *
 * Throwaway directories; the "socket" is an ordinary file for the existence check. The bean
 * is a scratch row so the correction can be observed.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;

class InstanceIsolationStateTest extends ConceptsTestCase {

    private const DB = 'isolation-state-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    public function testLiveIsTheMarkerPlusTheSocket(): void {
        $dir = $this->root . '/inst'; mkdir($dir);
        $this->assertFalse(\Model_Instance::isolationLive($dir), 'no marker');
        file_put_contents($dir . '/.fpm-isolated', "SOCK=" . $this->root . "/i99.sock\n");
        $this->assertFalse(\Model_Instance::isolationLive($dir), 'marker but the socket is not there');
        touch($this->root . '/i99.sock');
        $this->assertTrue(\Model_Instance::isolationLive($dir));
        file_put_contents($dir . '/.fpm-isolated', "something else\n");
        $this->assertFalse(\Model_Instance::isolationLive($dir), 'a marker that names no socket');
    }

    public function testTheRecordedStateFollowsThePool(): void {
        // dirFrom() resolves ROOT/<slug>.<app>; a slug nothing is provisioned under has no pool.
        $inst = Bean::dispense('instance'); $inst->slug = 'nosuch-zz9'; $inst->app = 'tiknix'; $inst->status = 'active';
        $inst->isolationState = 'pending'; Bean::store($inst);
        $this->assertSame('pending', \Model_Instance::isolationStateFor($inst), 'no pool: the queue state stands');
        $inst->isolationState = 'failed'; Bean::store($inst);
        $this->assertSame('failed', \Model_Instance::isolationStateFor($inst));
    }
}
