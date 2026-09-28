<?php
/**
 * ConnectionStore aliases and candidates (CONNECTOR-CATALOG-PLAN.md §3.1):
 *
 *   alias       a person's name for the connection (connection_name), else the provider's
 *               account name, else #id
 *   setAlias    unique per connector type on the install — two Stripes may not both be "Main",
 *               a Stripe and a Shopify may; empty or overlong refused
 *   candidates  live connections of the given types, with alias/environment/account, for pickers
 *
 * A throwaway install root (its own connections.db and key) under the system temp dir.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\ConnectionStore;

class ConnectionAliasTest extends ConceptsTestCase {

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        ConnectionStore::useInstall($this->root);
    }

    protected function tearDown(): void { ConnectionStore::useOwnInstall(); parent::tearDown(); }

    private function stripe(string $eid, string $name, string $env = 'production'): int {
        return ConnectionStore::put('stripe', $env, ['external_eid' => $eid, 'external_name' => $name, 'access_token' => 'sk_test_' . $eid, 'auth_type' => 'api_key']);
    }

    public function testAliasDerivesThenIsNamed(): void {
        $a = $this->stripe('acct_1', 'Serenity Gemstones');
        $b = $this->stripe('acct_2', '');
        $this->assertSame('Serenity Gemstones', ConnectionStore::alias(ConnectionStore::byId($a)));
        $this->assertSame('acct_2', ConnectionStore::alias(ConnectionStore::byId($b)), 'no name: the account id');
        ConnectionStore::setAlias($a, 'Serenity main');
        $this->assertSame('Serenity main', ConnectionStore::alias(ConnectionStore::byId($a)));
        try { ConnectionStore::setAlias($b, 'serenity MAIN'); $this->fail('a second Stripe took the same alias'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString("already names stripe connection #{$a}", $e->getMessage()); }
        $shop = ConnectionStore::put('shopify', 'production', ['external_eid' => 'x.myshopify.com', 'access_token' => 'shpat_x', 'auth_type' => 'oauth']);
        ConnectionStore::setAlias($shop, 'Serenity main');   // a different connector may reuse the name
        $this->assertSame('Serenity main', ConnectionStore::alias(ConnectionStore::byId($shop)));
        foreach (['', '   ', str_repeat('a', 121)] as $bad) {
            try { ConnectionStore::setAlias($a, $bad); $this->fail('accepted a bad alias'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('1–120', $e->getMessage()); }
        }
        try { ConnectionStore::setAlias(999, 'Nope'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('#999', $e->getMessage()); }
    }

    public function testCandidatesByTypeAndEnvironment(): void {
        $a = $this->stripe('acct_1', 'Serenity main');
        $b = $this->stripe('acct_2', 'Serenity Denver');
        $c = $this->stripe('acct_3', 'Sandbox', 'development');
        ConnectionStore::put('shopify', 'production', ['external_eid' => 'x.myshopify.com', 'access_token' => 'shpat_x', 'auth_type' => 'oauth']);
        $all = ConnectionStore::candidates(['stripe']);
        $this->assertSame([$a, $b, $c], array_column($all, 'id'));
        $this->assertSame(['Serenity main', 'Serenity Denver', 'Sandbox'], array_column($all, 'alias'));
        $this->assertSame([$a, $b], array_column(ConnectionStore::candidates(['stripe'], 'production'), 'id'));
        $this->assertCount(4, ConnectionStore::candidates(['stripe', 'shopify']));
        $this->assertSame([], ConnectionStore::candidates(['square']));
        $this->assertSame([], ConnectionStore::candidates([]));
        $this->assertNull(ConnectionStore::byId(0));
        $this->assertNull(ConnectionStore::byId(999));
    }
}
