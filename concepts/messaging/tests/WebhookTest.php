<?php
namespace app\concepts\messaging\tests;

require_once __DIR__ . '/MessagingCase.php';

use app\Bean;
use app\concepts\messaging\Webhook;

/** Who may tell us about a campaign's mail (the key AND Mailgun's signature), and what a reply is attributed to. */
class WebhookTest extends MessagingCase {

    private function event(string $eid, int $ts, string $sig, string $recipient): array {
        return ['timestamp' => (string) $ts, 'token' => 'tk', 'signature' => $sig, 'event' => 'failed', 'severity' => 'permanent', 'eid' => $eid,
            'recipient' => $recipient, 'inbound' => false, 'from' => '', 'subject' => '', 'text' => '', 'message_id' => ''];
    }

    public function testTheWebhookNeedsBothTheKeyAndTheSignature(): void {
        $c = $this->stored();
        $s = $this->ledger($c, $this->ids['ann'], str_repeat('a', 32));
        $s->providerEid = 'abc@mg'; Bean::store($s);
        $ts = time();
        $good = hash_hmac('sha256', $ts . 'tk', 'signing');
        $in = fn(string $sig) => $this->event('abc@mg', $ts, $sig, 'ann@example.test');
        $this->assertSame(403, Webhook::process($in('bad'), 'k', 'k', 'signing')[0]);
        $this->assertSame(403, Webhook::process($in($good), 'wrong', 'k', 'signing')[0]);
        $this->assertSame(503, Webhook::process($in($good), 'k', '', 'signing')[0], 'no key set is an error, not a pass');
        $this->assertSame(503, Webhook::process($in($good), 'k', 'k', '')[0], 'no signing key is an error, not a pass');
        $this->assertNull(Bean::load('msgsend', (int) $s->id)->bouncedAt);
        $this->assertFalse(\Model_Msgsuppression::has('ann@example.test'));

        $this->assertSame(200, Webhook::process($in($good), 'k', 'k', 'signing')[0]);
        $this->assertNotNull(Bean::load('msgsend', (int) $s->id)->bouncedAt);
        $this->assertTrue(\Model_Msgsuppression::has('ann@example.test'));
    }

    public function testAStaleSignatureIsRefused(): void {
        $ts = time() - 3600;
        $this->assertSame(403, Webhook::process($this->event('x', $ts, hash_hmac('sha256', $ts . 'tk', 'signing'), 'a@b.test'), 'k', 'k', 'signing')[0]);
    }

    public function testAVerifiedRequestAboutNothingOfOursIsLeftToTheRuntime(): void {
        $ts = time();
        $in = $this->event('nobody@mg', $ts, hash_hmac('sha256', $ts . 'tk', 'signing'), 'x@y.test');
        $this->assertNull(Webhook::process($in, 'k', 'k', 'signing')[0]);
    }

    public function testAReplyIsRecordedOnTheSendItsTokenNames(): void {
        $c = $this->stored();
        $mine = $this->ledger($c, $this->ids['ann'], str_repeat('a', 32));
        $other = $this->ledger($c, $this->ids['bo'], str_repeat('b', 32));
        $ts = time();
        $in = ['timestamp' => (string) $ts, 'token' => 'tk', 'signature' => hash_hmac('sha256', $ts . 'tk', 'signing'), 'event' => '', 'severity' => '', 'eid' => '',
            'recipient' => 'reply-' . str_repeat('b', 32) . '@reply.example.test', 'inbound' => true, 'from' => 'bo@example.test', 'subject' => 'Re: hi', 'text' => 'See you there', 'message_id' => 'm1@mail'];
        Webhook::process($in, 'k', 'k', 'signing');   // forwarding to the sender needs mail, which is not connected here; the reply is recorded first
        $this->assertSame(0, Bean::count('msgevent', "msgsend_id = ? AND kind = 'reply'", [(int) $mine->id]));
        $this->assertSame(1, Bean::count('msgevent', "msgsend_id = ? AND kind = 'reply'", [(int) $other->id]));
        Webhook::process($in, 'k', 'k', 'signing');   // Mailgun retries a failed forward: the reply is not recorded twice
        $this->assertSame(1, Bean::count('msgevent', "msgsend_id = ? AND kind = 'reply'", [(int) $other->id]));
    }
}
