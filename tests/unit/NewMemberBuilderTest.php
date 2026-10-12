<?php
/**
 * Everyone who signs up on Tiknix gets the Builder (app\SignupFlow::welcome) — the sign-up form,
 * card-on-file, Google and an invitation all go through it. Only invited members used to.
 */
namespace tests\unit;

use app\Bean;
use app\Feature;
use app\SignupFlow;

require_once __DIR__ . '/ConceptsTestCase.php';

class NewMemberBuilderTest extends ConceptsTestCase {
    protected function setUp(): void { parent::setUp(); self::memoryDb(); }

    private function member(): int {
        $m = Bean::dispense('member');
        $m->email = 'new-' . bin2hex(random_bytes(3)) . '@example.test'; $m->username = 'new' . bin2hex(random_bytes(3));
        $m->level = 100; $m->status = 'active';
        return (int) Bean::store($m);
    }

    public function testANewMemberHasNoBuilderUntilWelcomed(): void {
        $id = $this->member();
        $this->assertFalse(Feature::allows('workbench', $id, 100), 'the grant is what gives it, not the level');
        SignupFlow::welcome($id);
        $this->assertTrue(Feature::allows('workbench', $id, 100));
        $this->assertFalse(Feature::allows('qa', $id, 100), 'only the Builder: the rest stays an admin\'s to grant');
    }

    public function testWelcomingTwiceIsTheSameAsOnce(): void {
        $id = $this->member();
        SignupFlow::welcome($id); SignupFlow::welcome($id);
        $this->assertTrue(Feature::allows('workbench', $id, 100));
        $this->assertSame(1, (int) Bean::count('settings', 'member_id = ? AND setting_key = ?', [$id, 'feature.workbench']));
    }

    public function testEveryWayInGoesThroughIt(): void {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString('SignupFlow::welcome', (string) file_get_contents("{$root}/controls/Signup.php"), 'an invitation');
        $wiring = (string) file_get_contents("{$root}/lib/controlplane.php");
        $this->assertMatchesRegularExpression('/Auth::\$onNewMember\s*=.*SignupFlow::welcome/s', $wiring, 'the sign-up form');
        $this->assertMatchesRegularExpression('/GoogleAuth::\$onNewMember\s*=.*SignupFlow::welcome/s', $wiring, 'Google');
        $this->assertStringContainsString('self::welcome($memberId)', (string) file_get_contents("{$root}/lib/SignupFlow.php"), 'card-on-file');
    }
}
