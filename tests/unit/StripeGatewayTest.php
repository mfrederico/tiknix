<?php
namespace tests\unit;

require_once __DIR__ . "/../../lib/StripeGateway.php";

use app\StripeGateway;
use PHPUnit\Framework\TestCase;

class StripeGatewayTest extends TestCase {

    private const SECRET = 'whsec_test';
    private const PAYLOAD = '{"id":"evt_1","type":"checkout.session.completed"}';

    private function header(string $payload, int $t, string $secret = self::SECRET): string {
        return "t=$t,v1=" . hash_hmac('sha256', "$t.$payload", $secret);
    }

    public function testValidSignatureReturnsEvent(): void {
        $e = StripeGateway::verifyWebhook(self::PAYLOAD, $this->header(self::PAYLOAD, 1000), self::SECRET, 300, 1100);
        $this->assertSame('evt_1', $e['id']);
    }

    public function testTamperedPayloadRejected(): void {
        $this->expectExceptionMessage('signature mismatch');
        StripeGateway::verifyWebhook(self::PAYLOAD . ' ', $this->header(self::PAYLOAD, 1000), self::SECRET, 300, 1000);
    }

    public function testStaleRejected(): void {
        $this->expectExceptionMessage('stale');
        StripeGateway::verifyWebhook(self::PAYLOAD, $this->header(self::PAYLOAD, 1000), self::SECRET, 300, 2000);
    }

    public function testMissingHeaderRejected(): void {
        $this->expectExceptionMessage('header is missing');
        StripeGateway::verifyWebhook(self::PAYLOAD, '', self::SECRET);
    }

    public function testNoV1Rejected(): void {
        $this->expectExceptionMessage('no v1=');
        StripeGateway::verifyWebhook(self::PAYLOAD, 't=1000', self::SECRET, 300, 1000);
    }

    public function testEmptySecretNamesSetting(): void {
        $this->expectExceptionMessage('STRIPE_WEBHOOK_SECRET');
        StripeGateway::verifyWebhook(self::PAYLOAD, $this->header(self::PAYLOAD, 1000), '');
    }

    public function testBadJsonRejected(): void {
        $this->expectExceptionMessage('not valid JSON');
        StripeGateway::verifyWebhook('nope', $this->header('nope', 1000), self::SECRET, 300, 1000);
    }

    private function base(array $li): array {
        return ['success_url' => 'https://x/s', 'cancel_url' => 'https://x/c', 'line_items' => [$li]];
    }

    public function testCheckoutFieldsPrice(): void {
        $f = StripeGateway::checkoutFields($this->base(['price' => 'price_1', 'quantity' => 2]));
        $this->assertSame([['price' => 'price_1', 'quantity' => 2]], $f['line_items']);
    }

    public function testCheckoutFieldsPriceDataMetadataEmail(): void {
        $args = $this->base(['price_data' => ['currency' => 'USD', 'unit_amount' => '1250', 'product_data' => ['name' => 'Class']]]);
        $args['metadata'] = ['order' => 5];
        $args['customer_email'] = 'a@b.co';
        $f = StripeGateway::checkoutFields($args);
        $this->assertSame(['currency' => 'usd', 'unit_amount' => 1250, 'product_data' => ['name' => 'Class']], $f['line_items'][0]['price_data']);
        $this->assertSame(['order' => '5'], $f['metadata']);
        $this->assertSame('a@b.co', $f['customer_email']);
    }

    public function testCheckoutFieldsRejectsLineWithNeither(): void {
        $this->expectExceptionMessage('line_items[0] has neither');
        StripeGateway::checkoutFields($this->base(['quantity' => 1]));
    }
}
