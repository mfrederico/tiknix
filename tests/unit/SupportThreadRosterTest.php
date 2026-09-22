<?php
/**
 * A support thread (related_type 'contact') is addressed to the site, so its roster is the
 * support team — every active ADMIN+ — derived at read time, like a room's is its team.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ThreadMembers;

class SupportThreadRosterTest extends ConceptsTestCase {

    private const DB = 'support-roster-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function member(int $level, string $status = 'active'): int {
        $m = Bean::dispense('member');
        $m->username = 'u' . $this->uniq('m'); $m->email = $m->username . '@x'; $m->password = 'h';
        $m->level = $level; $m->status = $status;
        return (int) Bean::store($m);
    }

    private function thread(?string $relatedType, int $owner): int {
        $t = Bean::dispense('thread');
        $t->kind = 'email'; $t->subject = 's'; $t->relatedType = $relatedType; $t->relatedId = $relatedType ? 1 : null;
        $t->ownerMemberId = $owner; $t->status = 'open';
        $id = (int) Bean::store($t);
        ThreadMembers::ensure($id, [$owner], ThreadMembers::ROLE_OWNER);
        return $id;
    }

    public function testALaterAdminIsSeatedOnEverySupportThreadAndNothingElse(): void {
        $root = $this->member(1);
        $support = $this->thread('contact', $root);
        $support2 = $this->thread('contact', $root);
        $dm = $this->thread(null, $root);

        $admin  = $this->member(50);      // created AFTER the threads
        $member = $this->member(100);
        $gone   = $this->member(50, 'suspended');

        $this->assertSame(2, ThreadMembers::syncSupportFor($admin));
        $this->assertTrue(ThreadMembers::isMember($support, $admin));
        $this->assertTrue(ThreadMembers::isMember($support2, $admin));
        $this->assertFalse(ThreadMembers::isMember($dm, $admin), 'a non-support thread is not the team\'s');

        $this->assertSame(0, ThreadMembers::syncSupportFor($member), 'MEMBER is not the support team');
        $this->assertSame(0, ThreadMembers::syncSupportFor($gone), 'a suspended admin is not seated');
        $this->assertSame(0, ThreadMembers::syncSupportFor($admin), 'idempotent');
    }

    public function testTheThreadSideSeatsAllCurrentAdmins(): void {
        $root = $this->member(1);
        $support = $this->thread('contact', $root);
        $a = $this->member(50); $b = $this->member(50); $this->member(100);
        $t = Bean::load('thread', $support)->box();
        $this->assertTrue($t->isSupport());
        $this->assertSame(2, $t->syncWithSupportTeam());
        $this->assertEqualsCanonicalizing([$root, $a, $b], $t->participantIds());
        $this->assertSame(0, Bean::load('thread', $this->thread(null, $root))->box()->syncWithSupportTeam());
    }
}
