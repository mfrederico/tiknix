<?php
/**
 * An email is one address whatever its case (runtime Model_Member): stored lower-case, and every
 * lookup — the password sign-in, forgot password, "is this email taken" — finds it however it
 * is typed. The password form compared byte for byte: "Mfrederico@gmail.com" was "user not found".
 */
namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;

class MemberEmailCaseTest extends ConceptsTestCase {
    private const DB = 'member-email-case-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }
    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function member(string $username, string $email, string $status = 'active'): int {
        $m = Bean::dispense('member');
        $m->username = $username; $m->email = $email; $m->status = $status;
        return (int) Bean::store($m);
    }

    public function testAnEmailIsStoredLowerCase(): void {
        $id = $this->member('matt', '  Mfrederico@Gmail.com ');
        $this->assertSame('mfrederico@gmail.com', (string) Bean::load('member', $id)->email);
    }

    public function testSignInFindsTheEmailInAnyCaseAndTheUsernameExactly(): void {
        $id = $this->member('Matt', 'mfrederico@gmail.com');
        $this->assertSame($id, (int) \Model_Member::byLogin('Mfrederico@gmail.com')?->id);
        $this->assertSame($id, (int) \Model_Member::byLogin(' MFREDERICO@GMAIL.COM ')?->id);
        $this->assertSame($id, (int) \Model_Member::byLogin('Matt')?->id);
        $this->assertNull(\Model_Member::byLogin(''));
    }

    public function testAnInactiveMemberIsFoundOnlyWhenAskedFor(): void {
        $id = $this->member('gone', 'gone@example.com', 'suspended');
        $this->assertNull(\Model_Member::byLogin('Gone@example.com'));
        $this->assertNull(\Model_Member::byEmail('Gone@example.com', true));
        $this->assertSame($id, (int) \Model_Member::byLogin('Gone@example.com', false)?->id);
        $this->assertSame($id, (int) \Model_Member::byEmail('GONE@example.com')?->id);
    }

    public function testACaseVariantIsTheSameEmailTaken(): void {
        $id = $this->member('one', 'one@example.com');
        $this->assertTrue(\Model_Member::emailTaken('ONE@example.com'));
        $this->assertFalse(\Model_Member::emailTaken('One@Example.com', $id), 'its own email is not taken from itself');
        $this->assertFalse(\Model_Member::emailTaken('two@example.com'));
    }

    public function testARowStoredBeforeTheRuleIsStillFound(): void {
        // what an app's database may already hold: an email as it was typed (written past the model,
        // into a table a store has made)
        $this->member('other', 'other@example.com');
        Bean::exec("INSERT INTO member (username, email, status) VALUES ('old', 'Old.Person@Example.com', 'active')");
        $this->assertSame('old', (string) \Model_Member::byLogin('old.person@example.com')?->username);
    }
}
