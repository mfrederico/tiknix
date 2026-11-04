<?php
/**
 * PayPal (runtime services/connectors/PaypalConnector.php, lib/PaypalGateway.php): the connector is found and asks for what
 * it needs before calling PayPal; the gateway refuses a connection it cannot use, naming the fix. The calls to PayPal itself
 * need a real sandbox app and are proven on an app, not here.
 */

namespace tests\unit;

use app\Bean;
use app\PaypalGateway;
use app\services\connectors\ConnectorRegistry;
use PHPUnit\Framework\TestCase;

class PaypalTest extends TestCase {

    private const DB = 'paypal-test';
    private ?string $prev = null;

    protected function setUp(): void {
        $this->prev = Bean::currentDatabaseKey();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
    }

    protected function tearDown(): void { if ($this->prev !== null && Bean::hasDatabase($this->prev)) Bean::selectDatabase($this->prev); }

    public function testTheConnectorIsAKeyConnectorWhoseSandboxOrLiveFollowsTheEnvironment(): void {
        $c = ConnectorRegistry::get('paypal');
        $this->assertNotNull($c, 'PayPal is in the registry');
        $m = $c->meta();
        $this->assertSame('api_key', $m['auth_type']);
        $this->assertSame('Payments', $m['category']);
        $this->assertSame(['client_id'], array_column($m['fields'], 'name'), 'no separate Sandbox/Live choice: the environment is it');
    }

    public function testMissingPiecesAreRefusedBeforePayPalIsAsked(): void {
        $c = ConnectorRegistry::get('paypal');
        foreach ([[['client_id' => 'x'], 'secret', 'live site\'s PayPal or a testing one'],
                  [['environment' => 'development', 'client_id' => ''], 'secret', 'Client ID is required'],
                  [['environment' => 'production', 'client_id' => 'x'], '  ', 'Secret is required']] as [$opts, $key, $said]) {
            try { $c->validateApiKey($key, $opts); $this->fail('accepted ' . json_encode($opts)); }
            catch (\Exception $e) { $this->assertStringContainsString($said, $e->getMessage()); }
        }
    }

    private function conn(string $type, ?string $meta): \RedBeanPHP\OODBBean {
        $b = Bean::dispense('connections');
        $b->connectorType = $type; $b->metadataJson = $meta;
        return $b;
    }

    public function testTheGatewayRefusesAConnectionItCannotUse(): void {
        foreach ([[$this->conn('stripe', '{}'), 'not PayPal'],
                  [$this->conn('paypal', null), 'no readable settings'],
                  [$this->conn('paypal', '{"mode":"sandbox"}'), 'no mode or client ID'],
                  [$this->conn('paypal', '{"mode":"test","client_id":"x"}'), 'no mode or client ID']] as [$conn, $said]) {
            try { PaypalGateway::forConnection($conn); $this->fail('used ' . $conn->metadataJson); }
            catch (\RuntimeException $e) { $this->assertStringContainsString($said, $e->getMessage()); }
        }
    }
}
