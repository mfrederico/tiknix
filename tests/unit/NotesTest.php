<?php
/**
 * lib/Notes.php — a note is ONE message in one conversation, delivered in the app and (when
 * mail is on) by email, signed by the person who sent it.
 *
 *   toMember   continues the two people's direct conversation (the dmThread table-name bug
 *              made every note a new one); the sender is the author; HTML is sanitized;
 *              an email that did not go out is 'off' with the reason, never 'sent'
 *   rules      a non-admin cannot write to a stranger; an empty note is refused
 *   onThread   a support ticket: the admin is seated, the ticket's sender who has an
 *              account is seated too; the sender writing on their own ticket is not
 *              emailed their own words
 *
 * Mail is forced off (demo mode) through NotifyService's config cache, so no test can
 * reach Mailgun whatever conf/mailgun.ini holds.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\Notes;
use app\services\NotifyService;

class NotesTest extends ConceptsTestCase {

    private const DB = 'notes-test';
    private ?array $savedMailConfig = null;

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        $p = new \ReflectionProperty(NotifyService::class, 'configCache');
        $this->savedMailConfig = $p->getValue();
        $p->setValue(null, ['apiKey' => '', 'domain' => 'mail.test', 'inboundDomain' => 'mail.test', 'fromEmail' => 'noreply@mail.test',
                            'fromName' => 'Test', 'endpoint' => '', 'demoMode' => true]);
    }

    protected function tearDown(): void {
        (new \ReflectionProperty(NotifyService::class, 'configCache'))->setValue(null, $this->savedMailConfig);
        Bean::selectDatabase('default');
        parent::tearDown();
    }

    private function member(string $email, int $level = 100, string $name = ''): int {
        $m = Bean::dispense('member');
        $m->email = $email; $m->level = $level; $m->status = 'active';
        $m->firstName = $name; $m->username = strtok($email, '@');
        return (int) Bean::store($m);
    }

    public function testANoteIsOneMessageInTheirConversationAndSaysWhyNoEmailWent(): void {
        $admin = $this->member('admin@x.test', 50, 'Ada');
        $fabian = $this->member('fabian@x.test');
        $r = Notes::toMember($admin, $fabian, 'Your pipelines', '<p>Hello <strong>there</strong></p><script>alert(1)</script>');
        $this->assertSame('off', $r['email'], 'mail off is reported as off, never as sent');
        $this->assertStringContainsString('demo mode', (string) $r['email_error']);

        $msg = Bean::load('message', $r['message']);
        $this->assertSame($admin, (int) $msg->senderMemberId, 'signed by the sender');
        $this->assertStringContainsString('<strong>there</strong>', (string) $msg->content);
        $this->assertStringNotContainsString('<script', (string) $msg->content, 'sanitized');
        $this->assertSame('fabian@x.test', (string) $msg->toEmail, 'the email copy is recorded on the same row');
        $this->assertSame(1, Bean::count('message'), 'one message, not an in-app row plus an email row');

        $again = Notes::toMember($admin, $fabian, 'Follow-up', 'and one more thing');
        $this->assertSame($r['thread'], $again['thread'], 'the same two people continue one conversation');
        $this->assertStringContainsString('one more thing', (string) Bean::load('message', $again['message'])->content);
    }

    public function testStrangersAndEmptyNotesAreRefused(): void {
        $a = $this->member('a@x.test');
        $b = $this->member('b@x.test');
        try { Notes::toMember($a, $b, 's', 'hi'); $this->fail('a member wrote to a stranger'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('share a team', $e->getMessage()); }

        $admin = $this->member('admin@x.test', 50);
        $this->expectException(\InvalidArgumentException::class);
        Notes::toMember($admin, $b, 's', '<p> </p>');
    }

    public function testATicketSeatsTheAdminAndTheMemberAndNeverEmailsTheAskerThemselves(): void {
        $admin = $this->member('admin@x.test', 50);
        $asker = $this->member('asker@x.test');
        $t = NotifyService::openInboundThread($admin, 'Asker@x.test', 'Asker', '[problem] It broke', '<p>It broke</p>', 'contact', 7);

        $r = Notes::onThread($admin, $t, 'Fixed, try again', 'It broke');
        $this->assertSame('off', $r['email'], 'the admin reply is emailed to the ticket (mail is off here)');
        $seated = array_map('intval', Bean::getCol('SELECT member_id FROM threadmember WHERE thread_id = ?', [$t]));
        $this->assertContains($admin, $seated);
        $this->assertContains($asker, $seated, 'the asker has an account, so the reply is in their Communications');

        $follow = Notes::onThread($asker, $t, 'Still broken');
        $this->assertSame('none', $follow['email'], 'the asker is not emailed their own follow-up');
    }
}
