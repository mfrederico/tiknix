<?php
/**
 * A Stripe webhook's event is believed only when its signature checks out against the
 * connection's webhook secret (StripeConnector::verifiedEvent, used by /webhook/stripe before
 * any app handler runs) — RUNTIME-SPLIT-MAP.md step 5, carrying bookingscheduler.
 */

namespace tests\unit;

use app\services\connectors\StripeConnector;
use PHPUnit\Framework\TestCase;

class StripeWebhookTest extends TestCase {

    private function signed(string $body, string $secret, ?int $t = null): array {
        $t ??= time();
        return ['Stripe-Signature' => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", $secret)];
    }

    public function testOnlyASignedEventIsBelieved(): void {
        $c = new StripeConnector();
        $body = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1']]]);
        $this->assertSame('payment_intent.succeeded', $c->verifiedEvent($body, $this->signed($body, 'whsec_a'), 'whsec_a')['type']);

        foreach ([
            'another secret'   => [$this->signed($body, 'whsec_b'), 'whsec_a', $body],
            'no signature'     => [['Stripe-Signature' => ''], 'whsec_a', $body],
            'a replayed event' => [$this->signed($body, 'whsec_a', time() - 3600), 'whsec_a', $body],
            'not an event'     => [$this->signed('[]', 'whsec_a'), 'whsec_a', '[]'],
        ] as $why => [$headers, $secret, $raw]) {
            try { $c->verifiedEvent($raw, $headers, $secret); $this->fail("accepted {$why}"); }
            catch (\RuntimeException $e) { $this->assertMatchesRegularExpression('/did not verify|not an event/', $e->getMessage(), $why); }
        }
    }
}
