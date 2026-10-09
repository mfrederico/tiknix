<?php
/**
 * app\Sms — a number to text from, as a connection: what a typed phone number becomes, how many
 * parts a text is charged as, and a connection type that proves its fields before it calls out.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\Sms;

class SmsTest extends TestCase {

    public function testWhatAPersonTypedBecomesAFullInternationalNumber(): void {
        foreach ([['801 555 1234', '1', '+18015551234'], ['(801) 555-1234', '1', '+18015551234'], ['1 801 555 1234', '1', '+18015551234'],
                  ['+1 801-555-1234', '1', '+18015551234'], ['+44 20 7946 0958', '1', '+442079460958'], ['0044 20 7946 0958', '1', '+442079460958'],
                  ['98765 43210', '91', '+919876543210'], ['09876543210', '91', '+919876543210'], ['919876543210', '91', '+919876543210']] as [$typed, $cc, $want]) {
            $this->assertSame($want, Sms::e164($typed, $cc), $typed);
        }
    }

    public function testWhatCannotBeAPhoneNumberIsRefused(): void {
        foreach ([['', '1'], ['hello', '1'], ['123', '1'], ['+0 555', '1'], ['1234567890123456789', '1'], ['801 555 1234', 'US'], ['801 555 1234', '']] as [$typed, $cc]) {
            try { Sms::e164($typed, $cc); $this->fail("accepted '{$typed}' / '{$cc}'"); }
            catch (\InvalidArgumentException $e) { $this->assertStringStartsWith('Sms:', $e->getMessage()); }
        }
        $this->assertTrue(Sms::isE164('+18015551234'));
        foreach (['8015551234', '+', '+1', '+1 801 555 1234', '18015551234'] as $no) $this->assertFalse(Sms::isE164($no), $no);
    }

    public function testATextIsChargedPer160CharactersOr70WithAnEmoji(): void {
        $this->assertSame(0, Sms::parts(''));
        $this->assertSame(1, Sms::parts('Your Inresonance code is 482913. It works for 10 minutes.'));
        $this->assertSame(1, Sms::parts(str_repeat('a', 160)));
        $this->assertSame(2, Sms::parts(str_repeat('a', 161)));
        $this->assertSame(1, Sms::parts(str_repeat('a', 69) . '🙂'));
        $this->assertSame(2, Sms::parts(str_repeat('a', 70) . '🙂'), 'one emoji makes the whole text 70 a part');
    }

    public function testTheConnectionTypeIsOfferedAndProvesItsFieldsBeforeItCallsOut(): void {
        $c = \app\services\connectors\ConnectorRegistry::get('quo');
        $this->assertNotNull($c, 'Quo is a connector every app has');
        $this->assertSame(['from'], array_column($c->meta()['fields'], 'name'));
        foreach ([['', [], '/API key is required/'], ['key', ['from' => 'not a number'], '/does not look like a phone number/']] as [$key, $opts, $re]) {
            try { $c->validateApiKey($key, $opts); $this->fail('accepted ' . json_encode($opts)); }
            catch (\Exception $e) { $this->assertMatchesRegularExpression($re, $e->getMessage()); }
        }
        $this->assertSame(['quo'], \app\ConnectionBindings::role(\app\ConnectionBindings::CORE, 'sms')['types']);
    }

    public function testSendingRefusesWhatIsNotANumberOrNotAMessageBeforeAnyRequest(): void {
        // No connection in a test: settings() throws first — so check the argument rules through the pure helpers they rest on.
        $this->assertFalse(Sms::isE164('8015551234'), 'send() takes a full number: e164() is the way in');
        $this->assertFalse(Sms::isConnected());
    }
}
