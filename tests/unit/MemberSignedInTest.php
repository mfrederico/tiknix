<?php
/**
 * Model_Member::signedIn — what every sign-in records (last_login, login_count), in one place, so a
 * new account's first session counts too: the register form stamped nothing, and a person who was
 * signed in and building read "Last login: Never" on /admin/members.
 */
namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;

class MemberSignedInTest extends ConceptsTestCase {
    private const DB = 'member-signed-in-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }
    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    public function testEachSignInIsRecordedAndStored(): void {
        $m = Bean::dispense('member');
        $m->username = 'new'; $m->email = 'new@example.com'; $m->status = 'active';
        $id = (int) Bean::store($m);
        $this->assertSame('', (string) Bean::load('member', $id)->lastLogin, 'nothing recorded before a sign-in');

        $m->signedIn();
        $again = Bean::load('member', $id);
        $this->assertSame(1, (int) $again->loginCount, 'the first session is a sign-in');
        $this->assertEqualsWithDelta(time(), strtotime((string) $again->lastLogin), 5, 'stamped now, and stored');

        $again->signedIn();
        $this->assertSame(2, (int) Bean::load('member', $id)->loginCount);
    }
}
