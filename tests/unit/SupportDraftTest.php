<?php
/** An agent's draft on a support ticket: it waits for a person, fills the reply box, warns when out of date, and sends nothing. */

namespace tests\unit;

use app\Bean;
use app\SupportDraft;
use PHPUnit\Framework\TestCase;

class SupportDraftTest extends TestCase {
    private ?string $was = null;
    private int $ticket;
    private int $thread;

    protected function setUp(): void {
        $this->was = Bean::currentDatabaseKey();
        $db = 'draft-test-' . bin2hex(random_bytes(4));
        Bean::addDatabase($db, 'sqlite::memory:');
        Bean::selectDatabase($db);
        $c = Bean::dispense('contact'); $c->subject = 'Help'; $c->email = 'a@example.com'; $this->ticket = (int) Bean::store($c);
        $t = Bean::dispense('thread'); $t->relatedType = 'contact'; $t->relatedId = $this->ticket; $this->thread = (int) Bean::store($t);
        $this->say();
    }

    protected function tearDown(): void { if ($this->was !== null && Bean::hasDatabase($this->was)) Bean::selectDatabase($this->was); }

    private function say(): void { $m = Bean::dispense('message'); $m->threadId = $this->thread; $m->content = 'x'; Bean::store($m); }
    private function ticket(): object { return Bean::load('contact', $this->ticket); }

    public function testADraftNeedsATicketAndAReply(): void {
        foreach ([[999, 'hi', 'no support ticket'], [$this->ticket, '   ', 'needs the reply']] as [$id, $reply, $says]) {
            try { SupportDraft::save($id, $reply, '', 'a'); $this->fail("accepted {$says}"); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString($says, $e->getMessage()); }
        }
    }

    public function testItFillsTheReplyBoxAndAnewOneReplacesIt(): void {
        $x = new SupportDraft();
        $this->assertSame('', $x->prefill($this->ticket()));
        $this->assertSame('', $x->panel($this->ticket()));
        $this->assertSame('', $x->mark($this->ticket));
        SupportDraft::save($this->ticket, "It's fixed.", 'log line', 'agent');
        $this->assertSame("It's fixed.", $x->prefill($this->ticket()), 'as written: the page escapes it where it shows it');
        SupportDraft::save($this->ticket, 'Better.', '', 'agent');
        $this->assertSame('Better.', $x->prefill($this->ticket()));
        $this->assertSame(1, (int) Bean::count('supportdraft'));
        $this->assertStringContainsString('Draft ready', $x->mark($this->ticket));
    }

    public function testThePanelEscapesAndSaysNothingWasSent(): void {
        SupportDraft::save($this->ticket, 'Hi', '<script>x</script> /var/log/app.log', '<b>agent</b>');
        $h = (new SupportDraft())->panel($this->ticket());
        $this->assertStringContainsString('Nothing has been sent', $h);
        $this->assertStringContainsString('/var/log/app.log', $h);
        $this->assertStringNotContainsString('<script>x', $h);
        $this->assertStringNotContainsString('<b>agent', $h);
        $this->assertStringNotContainsString('out of date', $h);
    }

    public function testItWarnsWhenTheTicketMovedOn(): void {
        SupportDraft::save($this->ticket, 'Hi', '', 'agent');
        $x = new SupportDraft();
        $this->say();
        $this->assertStringContainsString('out of date', $x->panel($this->ticket()));
        SupportDraft::save($this->ticket, 'Hi again', '', 'agent');
        $this->assertStringNotContainsString('out of date', $x->panel($this->ticket()), 'a new draft has read the newest message');
    }

    public function testAReplyOrADiscardEndsIt(): void {
        $x = new SupportDraft();
        SupportDraft::save($this->ticket, 'Hi', '', 'agent');
        $x->responded($this->ticket);
        $this->assertSame('', $x->prefill($this->ticket()), 'the box starts empty again');
        $this->assertSame('sent', (string) Bean::findOne('supportdraft', 'contact_ref = ?', [$this->ticket])->status);
        SupportDraft::save($this->ticket, 'Hi', '', 'agent');
        $this->assertTrue(SupportDraft::dismiss($this->ticket));
        $this->assertSame('', $x->panel($this->ticket()));
        $this->assertFalse(SupportDraft::dismiss($this->ticket));
    }

    public function testTheRealTicketPageShowsThePanelAndFillsTheBox(): void {
        SupportDraft::save($this->ticket, "It's </textarea><b>fixed</b>", 'checked the log', 'agent');
        $was = \app\Contact::$extension;
        \app\Contact::$extension = new SupportDraft();
        try {
            $message = $this->ticket(); $message->status = 'new'; $message->name = 'A'; $message->message = 'Help'; $message->createdAt = '2026-10-31 10:00:00'; $message->category = 'general';
            $responses = []; $categories = []; $statuses = \app\Contact::STATUSES; $contactMember = null; $csrf = [];
            ob_start();
            include \app\Paths::runtime() . '/views/contact/view.php';
            $h = (string) ob_get_clean();
        } finally { \app\Contact::$extension = $was; }
        $this->assertStringContainsString('A draft reply is in the box below', $h);
        $this->assertStringContainsString('checked the log', $h);
        $this->assertLessThan(strpos($h, 'name="response"'), strpos($h, 'A draft reply is in the box below'), 'the panel sits above the box');
        $this->assertMatchesRegularExpression('#placeholder="[^"]*">It&\#0?39;s &lt;/textarea&gt;&lt;b&gt;fixed#', $h, 'the box starts with the draft, escaped once');
    }

    public function testTheToolRefusesAnyoneButAnAdminAndSavesForAnAdmin(): void {
        $args = ['ticket' => $this->ticket, 'reply' => 'Hi', 'findings' => 'f'];
        $t = new \app\mcptools\SupportDraftTool();
        try { $t->execute($args); $this->fail('no member: it must refuse'); }
        catch (\Exception $e) { $this->assertStringContainsString('administrator', $e->getMessage()); }
        $this->assertSame(0, (int) Bean::count('supportdraft'));
        $m = Bean::dispense('member'); $m->level = 100; $m->email = 'm@example.com';
        try { (new \app\mcptools\SupportDraftTool(null, $m))->execute($args); $this->fail('a plain member: it must refuse'); }
        catch (\Exception $e) { $this->assertStringContainsString('administrator', $e->getMessage()); }
        $m->level = 50;
        $this->assertStringContainsString('Nothing was sent', (new \app\mcptools\SupportDraftTool(null, $m))->execute($args));
        $this->assertSame('Hi', (new SupportDraft())->prefill($this->ticket()));
    }

    public function testSendingTheReplyKeepsTheWordsAsWrittenAndEndsTheDraft(): void {
        if (!defined('LEVELS')) define('LEVELS', ['ROOT' => 1, 'ADMIN' => 50, 'MEMBER' => 100, 'PUBLIC' => 101]);
        SupportDraft::save($this->ticket, "It's fixed", '', 'agent');
        $admin = Bean::dispense('member'); $admin->level = 50; $admin->email = 'admin@example.com'; $adminId = (int) Bean::store($admin);
        $was = \app\Contact::$extension; $session = $_SESSION ?? [];
        $maps = [];
        foreach (['getMember' => fn() => $admin, 'hasLevel' => fn() => true, 'isLoggedIn' => fn() => true, 'loadMenu' => fn() => [], 'siteName' => fn() => 'Test', 'siteLogo' => fn() => '', 'redirect' => function ($u) use (&$maps) { $maps['to'] = $u; }] as $k => $fn) \Flight::map($k, $fn);
        $log = \Flight::get('log');
        \Flight::set('log', new \Monolog\Logger('test', [new \Monolog\Handler\NullHandler()]));
        \app\Contact::$extension = new SupportDraft();
        $_SESSION['member'] = ['id' => $adminId];
        \Flight::request()->data->message_id = $this->ticket;
        \Flight::request()->data->response = "It's <b>fixed</b>";
        \Flight::request()->data->status = 'responded';
        $c = new class extends \app\Contact {
            public function __construct() { parent::__construct(); }
            protected function requireLevel($level): bool { return true; }
            protected function requirePost(): bool { return true; }
        };
        try { $c->respond(); }
        finally { \app\Contact::$extension = $was; $_SESSION = $session; \Flight::set('log', $log); }
        $saved = Bean::findOne('contactresponse', 'contact_id = ?', [$this->ticket]);
        $this->assertSame("It's <b>fixed</b>", (string) $saved->response, 'not escaped on the way in: the page escapes it where it shows it');
        $thread = Bean::findOne('message', 'thread_id = ? ORDER BY id DESC', [$this->thread]);
        $this->assertStringContainsString("It's &lt;b&gt;fixed", (string) $thread->content, 'what the customer reads: the words as written, and no live markup');
        $this->assertStringNotContainsString('&amp;', (string) $thread->content, 'escaped once, never twice');
        $this->assertSame('', (new SupportDraft())->prefill($this->ticket()));
    }

    public function testTheToolCannotSend(): void {
        $src = (string) file_get_contents(\app\Paths::root() . '/mcptools/SupportDraftTool.php') . file_get_contents(\app\Paths::root() . '/scripts/support-draft.php') . file_get_contents(\app\Paths::root() . '/lib/SupportDraft.php');
        foreach (['Notes::', 'Mailer', 'Support::'] as $sends) $this->assertStringNotContainsString($sends, $src);
    }
}
