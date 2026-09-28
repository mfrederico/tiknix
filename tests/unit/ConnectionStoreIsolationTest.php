<?php
/**
 * The connection store belongs to the instance's pool user. On an isolated instance (the
 * .fpm-isolated marker) a write by the TREE OWNER — the provisioning user on the CLI — is
 * refused before it can create a store the pool cannot write (Serenity, 2026-09-28); reads
 * still answer, a non-isolated install writes as before, and a pool request (open_basedir
 * set) is never mistaken for the owner.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ConnectionStore;
use app\IsolatedPool;

class ConnectionStoreIsolationTest extends ConceptsTestCase {

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        Bean::selectDatabase('default');
        ConnectionStore::useInstall($this->root);
    }

    protected function tearDown(): void {
        ConnectionStore::useOwnInstall();
        parent::tearDown();
    }

    public function testTheTreeOwnerMayNotWriteAnIsolatedInstancesStore(): void {
        $this->assertFalse(IsolatedPool::ownerOnIsolated($this->root), 'no marker: an ordinary install');
        $id = ConnectionStore::put('mailgun', 'production', ['access_token' => 'k', 'auth_type' => 'api_key', 'external_eid' => 'a.example.com']);
        $this->assertGreaterThan(0, $id);

        file_put_contents("{$this->root}/.fpm-isolated", "SOCK=/run/php/tiknix-i0.sock\n");
        $this->assertTrue(IsolatedPool::ownerOnIsolated($this->root), 'the test process owns the tree it made');
        $this->assertFalse(IsolatedPool::ownerOnIsolated($this->root, fileowner($this->root) + 1), 'somebody else running here is the pool');

        foreach ([
            fn() => ConnectionStore::put('mailgun', 'production', ['access_token' => 'k2', 'auth_type' => 'api_key', 'external_eid' => 'b.example.com']),
            fn() => ConnectionStore::setAlias($id, 'Main'),
        ] as $write) {
            try { $write(); $this->fail('a write by the tree owner went through'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('runAsPool', $e->getMessage()); $this->assertStringContainsString('tree owner', $e->getMessage()); }
        }
        $this->assertCount(1, ConnectionStore::candidates(['mailgun']), 'reads still answer, and nothing was written');
        $this->assertSame('a.example.com', ConnectionStore::alias(ConnectionStore::byId($id)));

        // creating a store that does not exist yet is the write that does the damage
        $fresh = $this->root . '/_fresh';
        mkdir($fresh, 0700, true);
        file_put_contents("{$fresh}/.fpm-isolated", "SOCK=/run/php/tiknix-i0.sock\n");
        ConnectionStore::useInstall($fresh);
        $this->assertNull(ConnectionStore::byId(1), 'a read never creates the file');
        $this->assertFileDoesNotExist("{$fresh}/data/connections.db");
        try { ConnectionStore::withOwnDb(fn() => true, false, true); $this->fail('created a store as the owner'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('locked out', $e->getMessage()); }
        $this->assertFileDoesNotExist("{$fresh}/data/connections.db");

        $this->assertSame('/run/php/tiknix-i0.sock', IsolatedPool::socket($fresh));
        file_put_contents("{$fresh}/.fpm-isolated", "isolated\n");
        try { IsolatedPool::socket($fresh); $this->fail('no SOCK line'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('SOCK=', $e->getMessage()); }
    }
}
