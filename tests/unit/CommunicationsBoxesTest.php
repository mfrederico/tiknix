<?php
/**
 * Runtime lib/ThreadMembers.php: Communications has two boxes, People and Builder. What the platform writes about
 * building (BUILDER_KINDS) is counted and listed apart from people's conversations, so a person's message (a change
 * request, a support ticket) is not buried under build notices, and the bell counts people's alone.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ThreadMembers;

class CommunicationsBoxesTest extends ConceptsTestCase {

    private const DB = 'commsboxes-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void {
        Bean::selectDatabase('default');
        parent::tearDown();
    }

    /** A conversation of $type for $member with one message nobody has read. */
    private function thread(int $member, ?string $type): int {
        $t = Bean::dispense('thread'); $t->subject = 'x'; $t->relatedType = $type; $t->ownerMemberId = $member; $t->lastMessageAt = date('Y-m-d H:i:s');
        $id = (int) Bean::store($t);
        ThreadMembers::ensure($id, [$member]);
        $m = Bean::dispense('message'); $m->threadId = $id; $m->direction = 'in'; $m->content = 'hello'; $m->senderMemberId = 0; $m->createdAt = date('Y-m-d H:i:s');
        Bean::store($m);
        return $id;
    }

    public function testBuildNoticesAreCountedApartFromPeoplesConversations(): void {
        $me = 7;
        foreach (['plan', 'plan', 'plan', 'askq', 'promptfail'] as $k) $this->thread($me, $k);
        foreach (['changerequest', 'contact', null] as $k) $this->thread($me, $k);
        $this->assertSame(3, ThreadMembers::unreadThreadCount($me, false), 'people: a change request, a support ticket, a direct message');
        $this->assertSame(5, ThreadMembers::unreadThreadCount($me, true), 'builder: plans, a question before planning, a failed plan');
        $this->assertSame(8, ThreadMembers::unreadThreadCount($me), 'all of them');
        $people = Bean::count('thread', ThreadMembers::boxSql(false, 'thread'));
        $builder = Bean::count('thread', ThreadMembers::boxSql(true, 'thread'));
        $this->assertSame([3, 5], [$people, $builder], 'every conversation is in exactly one box');
    }
}
