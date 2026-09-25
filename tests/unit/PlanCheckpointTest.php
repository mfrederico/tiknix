<?php
/**
 * PlanExecutor::checkpointBeforeRun — a plan run takes its own rollback point first.
 *
 *   taken    no checkpoint yet → snapshot-instance.sh runs for <app> <slug> plan-<id>, the tag
 *            it prints lands on the plan (plan_checkpoint) with an info line on its log
 *   kept     a plan that already has one is not re-snapshotted (a retry keeps the
 *            before-the-plan point)
 *   refused  a failing snapshot, or a missing script, is ok=false with the reason logged as
 *            an error and NO tag on the plan — the orchestrator then does not run it
 *
 * Scratch in-memory database; a fake snapshot script in a throwaway <slug>.<app> directory.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\PlanExecutor;

class PlanCheckpointTest extends ConceptsTestCase {

    private const DB = 'plan-checkpoint-test';
    private string $inst;

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        $this->inst = $this->root . '/demo-abc123.tiknix';
        mkdir($this->inst, 0700, true);
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function plan(): \RedBeanPHP\OODBBean {
        $p = Bean::dispense('workbenchtask');
        $p->title = 'A plan'; $p->status = 'pending'; $p->planStatus = 'approved'; $p->memberId = 1; $p->planCheckpoint = '';
        Bean::store($p);
        return $p;
    }

    private function script(string $body): string {
        $s = $this->root . '/snapshot-instance.sh';
        file_put_contents($s, "#!/bin/sh\n" . $body);
        chmod($s, 0700);
        return $s;
    }

    private function logs(int $planId): array {
        return array_values(array_map(fn($l) => [$l->logLevel, $l->message], Bean::find('tasklog', 'task_id = ? ORDER BY id', [$planId])));
    }

    public function testTakenOnceThenKept(): void {
        $p  = $this->plan();
        $ex = new PlanExecutor((int) $p->id, 'demo-abc123', $this->inst, 50);
        $script = $this->script('echo "args: $1 $2 $3" > "' . $this->root . '/called"; echo "log line"; echo "checkpoint-$3"');
        $r = $ex->checkpointBeforeRun($script);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('checkpoint-plan-' . $p->id, $r['tag']);
        $this->assertSame("args: tiknix demo-abc123 plan-{$p->id}\n", file_get_contents($this->root . '/called'), 'app from the dir suffix, the slug, the plan label');
        $this->assertSame('checkpoint-plan-' . $p->id, Bean::load('workbenchtask', $p->id)->planCheckpoint);
        $this->assertSame('info', $this->logs((int) $p->id)[0][0]);

        unlink($this->root . '/called');
        $again = $ex->checkpointBeforeRun($script);
        $this->assertTrue($again['ok']);
        $this->assertStringContainsString('kept', $again['message']);
        $this->assertFileDoesNotExist($this->root . '/called', 'no second snapshot');
    }

    public function testAFailedSnapshotRefusesTheRun(): void {
        $p  = $this->plan();
        $ex = new PlanExecutor((int) $p->id, 'demo-abc123', $this->inst, 50);
        $r = $ex->checkpointBeforeRun($this->script('echo "not an instance: nope" >&2; exit 3'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('exited 3', $r['message']);
        $this->assertStringContainsString('not an instance: nope', $r['message']);
        $this->assertSame('', (string) Bean::load('workbenchtask', $p->id)->planCheckpoint, 'nothing recorded');
        $this->assertSame('error', $this->logs((int) $p->id)[0][0]);

        $missing = $ex->checkpointBeforeRun($this->root . '/no-such-script.sh');
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('snapshot script missing', $missing['message']);
    }
}
