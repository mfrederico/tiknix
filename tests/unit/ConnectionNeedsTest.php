<?php
/**
 * ConnectionNeeds (lib/ConnectionNeeds.php): connecting a connector marks it connected on every task of the project's board
 * that asked for it, once, and touches nothing else.
 */

namespace tests\unit;

use app\Bean;
use app\ConnectionNeeds;
use PHPUnit\Framework\TestCase;

class ConnectionNeedsTest extends TestCase {

    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/tiknix-needs-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir . '/data', 0700, true);
        touch($this->dir . '/data/workbench.db');
        if (!Bean::hasDatabase('default')) Bean::addDatabase('default', 'sqlite::memory:');   // inBoard goes back to it
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); exec('rm -rf ' . escapeshellarg($this->dir)); }

    private function task(array $needs): int {
        return (int) \app\BuilderMail::inBoard($this->dir, function () use ($needs) {
            $t = Bean::dispense('workbenchtask'); $t->title = 'a task'; $t->needsJson = json_encode($needs);
            return Bean::store($t);
        });
    }

    private function needs(int $id): array {
        return \app\BuilderMail::inBoard($this->dir, fn() => json_decode((string) Bean::load('workbenchtask', $id)->needsJson, true));
    }

    public function testANeedTheAppAlreadyHasIsConnectedFromTheStart(): void {
        $m = \app\PlanExecutor::mergeNeeds([], [['connector' => 'mailgun', 'why' => 'invites'], ['connector' => 's3', 'why' => '']], ['mailgun' => true]);
        $this->assertNotSame('', $m['mailgun']['connected_at'], 'already connected: no Connect card');
        $this->assertSame('', $m['s3']['connected_at']);
        $again = \app\PlanExecutor::mergeNeeds($m, [['connector' => 'mailgun', 'why' => 'receipts']], []);
        $this->assertSame($m['mailgun']['connected_at'], $again['mailgun']['connected_at'], 'once connected, a later run that cannot ask does not undo it');
        $this->assertSame('receipts', $again['mailgun']['why']);
        $this->assertSame($m['mailgun']['at'], $again['mailgun']['at']);
    }

    public function testABoardWithNoTasksIsNothingToDo(): void {
        $this->assertSame(0, ConnectionNeeds::metIn($this->dir, 'mailgun'));
        $this->assertSame(0, ConnectionNeeds::metIn($this->dir . '/nowhere', 'mailgun'), 'a project with no board');
    }

    public function testConnectingMarksTheTasksThatAskedOnce(): void {
        $a = $this->task(['mailgun' => ['connector' => 'mailgun', 'why' => 'invites', 'at' => '2026-11-01 10:00:00', 'connected_at' => ''],
                          's3' => ['connector' => 's3', 'why' => '', 'at' => '2026-11-01 10:00:00', 'connected_at' => '']]);
        $b = $this->task(['mailgun' => ['connector' => 'mailgun', 'why' => 'receipts', 'at' => '2026-11-01 10:00:00', 'connected_at' => '']]);
        $c = $this->task(['mailgun_eu' => ['connector' => 'mailgun_eu', 'why' => '', 'at' => '2026-11-01 10:00:00', 'connected_at' => '']]);
        $this->assertSame(2, ConnectionNeeds::metIn($this->dir, 'mailgun'));
        $this->assertNotSame('', $this->needs($a)['mailgun']['connected_at']);
        $this->assertSame('', $this->needs($a)['s3']['connected_at'], 'another connection the task asked for is still asked for');
        $this->assertNotSame('', $this->needs($b)['mailgun']['connected_at']);
        $this->assertSame('', $this->needs($c)['mailgun_eu']['connected_at'], 'a key that only starts the same is not it');
        $this->assertSame(0, ConnectionNeeds::metIn($this->dir, 'mailgun'), 'once');
    }
}
