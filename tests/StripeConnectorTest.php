<?php
/**
 * StripeConnector unit tests — pure/URL-building/validation paths only.
 *
 * No live network calls: exchangeCode(), and the happy paths of callBrokerTool(),
 * are network-bound, so HTTP is captured via a test subclass that overrides the
 * inherited http() helper and returns canned responses.
 *
 * Run: vendor/bin/phpunit --bootstrap vendor/autoload.php tests/StripeConnectorTest.php
 */

namespace Tests;

use app\services\connectors\StripeConnector;
use PHPUnit\Framework\TestCase;

/** Captures http() calls instead of hitting the network. */
class CapturingStripeConnector extends StripeConnector {
    /** @var array<int,array{method:string,url:string,opts:array}> */
    public array $requests = [];
    public int $respStatus = 200;
    public string $respBody = '{}';

    protected function http(string $method, string $url, array $opts = []): array {
        $this->requests[] = ['method' => $method, 'url' => $url, 'opts' => $opts];
        return [$this->respStatus, $this->respBody];
    }
}

class StripeConnectorTest extends TestCase {

    private StripeConnector $connector;

    protected function setUp(): void {
        $this->connector = new StripeConnector();
    }

    public function testKeyAndMeta(): void {
        $this->assertSame('stripe', $this->connector->key());
        $meta = $this->connector->meta();
        $this->assertSame('api_key', $meta['auth_type']);
        $this->assertSame('Stripe', $meta['label']);
    }

    public function testIsConfiguredIsAlwaysTrue(): void {
        // api_key mode needs no platform credentials — usable with no conf/stripe.ini.
        $this->assertFileDoesNotExist(dirname(__DIR__) . '/conf/stripe.ini');
        $this->assertTrue($this->connector->isConfigured());
    }

    // --- validateApiKey (the paste flow) -------------------------------------

    public function testValidateApiKeyEmptyThrows(): void {
        $this->expectExceptionMessage('A Stripe secret or restricted key is required.');
        $this->connector->validateApiKey('   ');
    }

    public function testValidateApiKeyRejectedNeverEchoesKey(): void {
        $c = new CapturingStripeConnector();
        $c->respStatus = 401;
        $c->respBody = '{"error":{"message":"Invalid API Key provided"}}';
        try {
            $c->validateApiKey('sk_live_pastedsecret');
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertSame('Stripe rejected that key — check it is a valid secret (sk_) or restricted (rk_) key.', $e->getMessage());
            $this->assertStringNotContainsString('sk_live_pastedsecret', $e->getMessage());
        }
    }

    public function testValidateApiKeyNormalizesAccountPayload(): void {
        $c = new CapturingStripeConnector();
        $c->respBody = json_encode([
            'id' => 'acct_1ABC',
            'business_profile' => ['name' => 'Widgets Inc'],
            'email' => 'owner@widgets.test',
        ]);
        $p = $c->validateApiKey('rk_live_abc123');
        $this->assertSame('rk_live_abc123', $p['access_token']);
        $this->assertSame('Bearer', $p['token_type']);
        $this->assertSame('acct_1ABC', $p['external_eid']);
        $this->assertSame('Widgets Inc', $p['external_name']);
        $this->assertSame('https://dashboard.stripe.com/acct_1ABC', $p['external_url']);
        $this->assertSame('', $p['metadata']['publishable_key']);
        $this->assertTrue($p['metadata']['livemode']);
        // The account endpoint was hit with the pasted key as Bearer.
        $this->assertSame('https://api.stripe.com/v1/account', $c->requests[0]['url']);
    }

    public function testAuthorizeUrlBuildsConnectUrlWithoutRequiringShop(): void {
        // Dormant OAuth path (kept for the future): no 'shop' in ctx — must not throw.
        $url = $this->connector->authorizeUrl([
            'state'        => 'STATE123',
            'redirect_uri' => 'https://tiknix.com/connections/callback/stripe',
        ]);
        $this->assertStringStartsWith('https://connect.stripe.com/oauth/authorize?', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=STATE123', $url);
        $this->assertStringContainsString(
            'redirect_uri=' . urlencode('https://tiknix.com/connections/callback/stripe'), $url);
        $this->assertStringContainsString('scope=read_write', $url);
    }

    public function testBrokerToolsExposesExpectedNames(): void {
        $names = array_column($this->connector->brokerTools(), 'name');
        $this->assertSame([
            'get_account',
            'list_products',
            'list_prices',
            'list_customers',
            'create_customer',
            'create_checkout_session',
            'list_subscriptions',
        ], $names);
    }

    public function testCallBrokerToolThrowsOnUnknownTool(): void {
        $this->expectExceptionMessage('Unknown Stripe broker tool: bogus');
        $this->connector->callBrokerTool('bogus', new \stdClass(), 'sk_test_x', []);
    }

    public function testCheckoutSessionRequiresSuccessUrl(): void {
        $c = new CapturingStripeConnector();
        $this->expectExceptionMessage('requires a success_url');
        $c->callBrokerTool('create_checkout_session', new \stdClass(), 'sk_test_x', [
            'cancel_url' => 'https://x.test/cancel',
            'price'      => 'price_123',
        ]);
    }

    public function testCheckoutSessionRequiresCancelUrl(): void {
        $c = new CapturingStripeConnector();
        $this->expectExceptionMessage('requires a cancel_url');
        $c->callBrokerTool('create_checkout_session', new \stdClass(), 'sk_test_x', [
            'success_url' => 'https://x.test/success',
            'price'       => 'price_123',
        ]);
    }

    public function testCheckoutSessionInvalidModeDefaultsToPayment(): void {
        $c = new CapturingStripeConnector();
        $c->callBrokerTool('create_checkout_session', new \stdClass(), 'sk_test_x', [
            'mode'        => 'bananas',
            'success_url' => 'https://x.test/success',
            'cancel_url'  => 'https://x.test/cancel',
            'line_items'  => [['price' => 'price_123', 'quantity' => 2]],
        ]);
        $this->assertCount(1, $c->requests);
        $req = $c->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $req['url']);
        parse_str((string)$req['opts']['body'], $form);
        $this->assertSame('payment', $form['mode']);
        // Stripe bracket syntax: line_items[0][price] / line_items[0][quantity]
        $this->assertSame('price_123', $form['line_items'][0]['price']);
        $this->assertSame('2', $form['line_items'][0]['quantity']);
        // Every create carries an Idempotency-Key header.
        $idem = array_filter($req['opts']['headers'], fn($h) => str_starts_with($h, 'Idempotency-Key: '));
        $this->assertCount(1, $idem);
    }

    public function testCheckoutSessionSubscriptionModeAndSinglePrice(): void {
        $c = new CapturingStripeConnector();
        $c->callBrokerTool('create_checkout_session', new \stdClass(), 'sk_test_x', [
            'mode'        => 'subscription',
            'success_url' => 'https://x.test/success',
            'cancel_url'  => 'https://x.test/cancel',
            'price'       => 'price_sub',
        ]);
        parse_str((string)$c->requests[0]['opts']['body'], $form);
        $this->assertSame('subscription', $form['mode']);
        $this->assertSame('price_sub', $form['line_items'][0]['price']);
        $this->assertSame('1', $form['line_items'][0]['quantity']);
    }

    public function testCheckoutSessionThrowsWithoutAnyLineItems(): void {
        $c = new CapturingStripeConnector();
        $this->expectExceptionMessage('requires line_items');
        $c->callBrokerTool('create_checkout_session', new \stdClass(), 'sk_test_x', [
            'success_url' => 'https://x.test/success',
            'cancel_url'  => 'https://x.test/cancel',
        ]);
    }

    public function testRejectedCredentialsErrorNeverEchoesToken(): void {
        $c = new CapturingStripeConnector();
        $c->respStatus = 401;
        $c->respBody = '{"error":{"message":"Invalid API Key provided"}}';
        try {
            $c->callBrokerTool('get_account', new \stdClass(), 'sk_live_supersecret', []);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertSame('Stripe rejected the credentials (HTTP 401) — reconnect the account.', $e->getMessage());
            $this->assertStringNotContainsString('sk_live_supersecret', $e->getMessage());
        }
    }

    public function testListSubscriptionsClampsLimitAndStatus(): void {
        $c = new CapturingStripeConnector();
        $c->respBody = '{"object":"list","data":[]}';
        $c->callBrokerTool('list_subscriptions', new \stdClass(), 'sk_test_x', [
            'limit' => 9999, 'status' => 'not-a-status',
        ]);
        $url = $c->requests[0]['url'];
        $this->assertStringContainsString('limit=100', $url);
        $this->assertStringContainsString('status=all', $url);
    }
}
