<?php
/**
 * lib/Support.php + send_to_tiknix_support — a member's ticket, however it arrives.
 *
 *   open()     a contact row marked with its source and project, a conversation the support
 *              team AND the member are in, the project named at the top of the message
 *   limit      an agent may open AGENT_LIMIT tickets per member per hour, then is refused
 *   the tool   does nothing unless the agent says it asked the user
 *
 * Mail is forced off (an unconfigured Mailer singleton, NotifyService in demo mode): no test
 * can reach Mailgun whatever conf/mailgun.ini holds.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\Support;
use app\services\NotifyService;

class SupportTest extends ConceptsTestCase {

    private const DB = 'support-test';
    private $savedMailer = null;
    private ?array $savedNotify = null;

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();

        $inst = new \ReflectionProperty(\app\Mailer::class, 'instance');
        $this->savedMailer = $inst->getValue();
        $off = (new \ReflectionClass(\app\Mailer::class))->newInstanceWithoutConstructor();   // configured = false
        $inst->setValue(null, $off);
        $n = new \ReflectionProperty(NotifyService::class, 'configCache');
        $this->savedNotify = $n->getValue();
        $n->setValue(null, ['apiKey' => '', 'domain' => '', 'inboundDomain' => '', 'fromEmail' => '', 'fromName' => 'Test', 'endpoint' => '', 'demoMode' => true]);
    }

    protected function tearDown(): void {
        (new \ReflectionProperty(\app\Mailer::class, 'instance'))->setValue(null, $this->savedMailer);
        (new \ReflectionProperty(NotifyService::class, 'configCache'))->setValue(null, $this->savedNotify);
        Bean::selectDatabase('default');
        parent::tearDown();
    }

    private function member(string $email, int $level): int {
        $m = Bean::dispense('member');
        $m->email = $email; $m->level = $level; $m->status = 'active'; $m->username = strtok($email, '@');
        return (int) Bean::store($m);
    }

    private function project(int $owner): \RedBeanPHP\OODBBean {
        $i = Bean::dispense('instance');
        $i->slug = 'lead-machine-1639fe'; $i->displayName = 'Lead-machine'; $i->memberId = $owner;
        Bean::store($i);
        return $i;
    }

    public function testATicketIsInTheQueueAndInTheMembersCommunications(): void {
        $admin  = $this->member('ops@x.test', 1);
        $fabian = $this->member('fabian@x.test', 100);
        $r = Support::open($fabian, 'Pipelines stuck', 'The agent step exits 1.', 'problem', $this->project($fabian), 'agent');

        $c = Bean::load('contact', $r['contact']);
        $this->assertSame(['new', 'agent', 'lead-machine-1639fe', 'problem', $fabian],
            [(string) $c->status, (string) $c->source, (string) $c->projectSlug, (string) $c->category, (int) $c->memberId]);
        $this->assertStringStartsWith("Project: Lead-machine (lead-machine-1639fe)\nSent by the project's AI agent", (string) $c->message);

        $this->assertNotNull($r['thread']);
        $seated = array_map('intval', Bean::getCol('SELECT member_id FROM threadmember WHERE thread_id = ?', [$r['thread']]));
        $this->assertContains($admin, $seated, 'the support team is in it');
        $this->assertContains($fabian, $seated, 'so is the member: the answer reaches their Communications');
    }

    public function testAnAgentIsLimitedPerHour(): void {
        $this->member('ops@x.test', 1);
        $fabian = $this->member('fabian@x.test', 100);
        for ($i = 0; $i < Support::AGENT_LIMIT; $i++) Support::open($fabian, "s{$i}", 'm', 'problem', null, 'agent');
        Support::open($fabian, 'from the page', 'm', 'general', null, 'app');   // the member's own page is not limited
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('support tickets from an agent in the last hour');
        Support::open($fabian, 'one too many', 'm', 'problem', null, 'agent');
    }

    public function testTheToolSendsNothingUnlessTheUserAgreed(): void {
        $out = (new \app\mcptools\SendToTiknixSupportTool())->execute(['user_agreed' => false, 'subject' => 's', 'message' => 'm']);
        $this->assertStringContainsString('NOT SENT', $out);
        $this->assertStringContainsString('Should I escalate this to Tiknix support?', $out);
        $this->assertSame(0, Bean::count('contact'));
    }
}
