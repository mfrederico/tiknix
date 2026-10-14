<?php
/**
 * app\BuilderMail: a reply to a Builder email is acted on only from an address the conversation
 * was sent to, signed by Mailgun, authenticated (DKIM or SPF), and written by a person; "yes" is
 * read plainly and a "no" anywhere in its first line is never a yes.
 */
namespace tests\unit;

use app\Bean;
use app\BuilderMail;

class BuilderMailTest extends ConceptsTestCase {
    protected function setUp(): void { parent::setUp(); self::memoryDb(); }

    private function thread() {
        $t = Bean::dispense('thread'); $t->subject = 'plan'; $t->relatedType = 'plan'; $t->relatedId = 5; $t->projectSlug = 'bakery'; $t->ownerMemberId = 2;
        Bean::store($t);
        return $t;
    }

    private function ctx(array $over = []): array {
        return $over + ['from' => 'owner@example.com', 'text' => 'yes', 'verified' => true, 'authenticated' => true, 'automatic' => false];
    }

    public function testOnlyAnAddressTheConversationWasSentToIsListenedTo(): void {
        $t = $this->thread();
        $this->assertStringContainsString('never sent to', BuilderMail::refusal($t, $this->ctx()));
        BuilderMail::allow($t, 'Owner@Example.com ');
        BuilderMail::allow($t, 'owner@example.com');
        $this->assertSame(['owner@example.com'], BuilderMail::allowed($t), 'kept once, lowercased');
        $this->assertSame('', BuilderMail::refusal($t, $this->ctx()));
        $this->assertStringContainsString('never sent to', BuilderMail::refusal($t, $this->ctx(['from' => 'someone@else.com'])));
        BuilderMail::allow($t, 'sam+demo@example.com');
        $this->assertSame('', BuilderMail::refusal($t, $this->ctx(['from' => 'sam@example.com'])), 'one mailbox: sent to its +demo address, answered from the plain one');
        $this->assertStringContainsString('never sent to', BuilderMail::refusal($t, $this->ctx(['from' => 'sam@other.com'])), 'the domain still has to match');
    }

    public function testUnsignedForgedOrAutomaticRepliesAreNotActedOn(): void {
        $t = $this->thread(); BuilderMail::allow($t, 'owner@example.com');
        $this->assertStringContainsString('signature', BuilderMail::refusal($t, $this->ctx(['verified' => false])));
        $this->assertStringContainsString('DKIM nor SPF', BuilderMail::refusal($t, $this->ctx(['authenticated' => false])), 'a forged From fails these');
        $this->assertStringContainsString('automatic', BuilderMail::refusal($t, $this->ctx(['automatic' => true])));
        $this->assertStringContainsString('no text', BuilderMail::refusal($t, $this->ctx(['text' => '  '])));
    }

    public function testYesIsReadPlainly(): void {
        foreach (['yes', 'Yes please', 'OK', 'go on', 'Build it anyway', "sure\n\n> On Tue…"] as $y) $this->assertTrue(BuilderMail::saysYes($y), $y);
        foreach (['no', 'yes but not now', "don't", 'Wait — what does it cost?', 'how much is left?', ''] as $n) $this->assertFalse(BuilderMail::saysYes($n), $n);
    }
}
