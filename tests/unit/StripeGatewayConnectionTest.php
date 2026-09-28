<?php
/**
 * StripeGateway::forConnection — a gateway for ONE connection, the way a concept reaches Stripe
 * once it asks by role. A connection with its own key is called directly with that key; a
 * broker-custody connection (auth_type 'broker') goes through the platform; a connection whose
 * key cannot be read is an error, never a quiet fall to the broker (that would charge the wrong
 * account). Throwaway connection store.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\ConnectionStore;
use app\StripeGateway;

class StripeGatewayConnectionTest extends ConceptsTestCase {

    protected function setUp(): void { parent::setUp(); self::memoryDb(); ConnectionStore::useInstall($this->root); }
    protected function tearDown(): void { ConnectionStore::useOwnInstall(); parent::tearDown(); }

    public function testDirectBrokerAndUnreadable(): void {
        $direct = ConnectionStore::put('stripe', 'production', ['external_eid' => 'acct_d', 'external_name' => 'Denver', 'access_token' => 'sk_live_denver', 'auth_type' => 'api_key']);
        $this->assertSame('direct', StripeGateway::forConnection(ConnectionStore::byId($direct))->driver());

        $broker = ConnectionStore::put('stripe', 'production', ['external_eid' => 'platform', 'external_name' => 'Serenity main', 'access_token' => '', 'auth_type' => 'broker']);
        $this->assertSame('broker', StripeGateway::forConnection(ConnectionStore::byId($broker))->driver());

        $empty = ConnectionStore::put('stripe', 'development', ['external_eid' => 'acct_e', 'external_name' => 'Empty', 'access_token' => '', 'auth_type' => 'api_key']);
        try { StripeGateway::forConnection(ConnectionStore::byId($empty)); $this->fail('an unreadable key fell to the broker'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString("'Empty' (#{$empty}) has no readable secret key", $e->getMessage()); }
    }
}
