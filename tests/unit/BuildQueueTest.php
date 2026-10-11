<?php
/**
 * app\BuildQueue: a plan whose files another building plan is still changing waits for it, and
 * says for which plan and which files; one that shares nothing builds beside it; the queue is
 * kept in order, can be reordered, and a plan can be taken out of it.
 */
namespace tests\unit;

use app\Bean;
use app\BuildQueue;

require_once __DIR__ . '/ConceptsTestCase.php';

class BuildQueueTest extends ConceptsTestCase {
    private const DB = 'build-queue-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    /** A plan with one task per file list; returns the plan's row. */
    private function plan(string $status, array $tasks, int $queuePos = 0): object {
        $p = Bean::dispense('workbenchtask');
        $p->title = 'plan'; $p->planStatus = $status; $p->status = 'pending'; $p->queuePos = $queuePos; $p->queueLevel = 0; $p->progressMessage = '';
        $id = (int) Bean::store($p);
        foreach ($tasks as $t) {
            $c = Bean::dispense('workbenchtask');
            $c->title = 'task'; $c->parentTaskId = $id; $c->status = $t['status'] ?? 'pending'; $c->relatedFiles = json_encode($t['files']);
            Bean::store($c);
        }
        return Bean::load('workbenchtask', $id);
    }

    public function testSharedIsTheSamePathOrAFolderTheOtherIsIn(): void {
        $this->assertSame(['controls/Me.php'], BuildQueue::shared(['controls/Me.php', 'views/a.php'], ['controls/Me.php', 'views/b.php']));
        $this->assertSame(['views/posts/_post.php'], BuildQueue::shared(['views/posts'], ['views/posts/_post.php']));
        $this->assertSame(['views/posts/_post.php'], BuildQueue::shared(['views/posts/_post.php'], ['views/posts']));
        $this->assertSame([], BuildQueue::shared(['views/post.php'], ['views/posts.php']));
    }

    public function testAPlanWaitsForABuildingPlanStillChangingItsFiles(): void {
        $a = $this->plan('building', [['files' => ['controls/Me.php', 'views/me/index.php']]]);
        $b = $this->plan('approved', [['files' => ['views/me/index.php']]]);
        $why = BuildQueue::waitsFor($b, 'zz', 0, [$a]);
        $this->assertStringContainsString("starts after #{$a->id}", $why);
        $this->assertStringContainsString('views/me/index.php', $why);
    }

    public function testAFileTheBuildingPlanHasAlreadyMergedIsNotWaitedFor(): void {
        $a = $this->plan('building', [['files' => ['controls/Me.php'], 'status' => 'merged'], ['files' => ['lib/Other.php']]]);
        $b = $this->plan('approved', [['files' => ['controls/Me.php']]]);
        $this->assertSame('', BuildQueue::waitsFor($b, 'zz', 0, [$a]));
    }

    public function testAPlanThatSharesNothingBuildsBesideItUntilTheProjectIsFull(): void {
        $a = $this->plan('building', [['files' => ['a.php']]]);
        $c = $this->plan('building', [['files' => ['c.php']]]);
        $b = $this->plan('approved', [['files' => ['b.php']]]);
        $this->assertSame('', BuildQueue::waitsFor($b, 'zz', 0, [$a]));
        $this->assertStringContainsString('builds ' . BuildQueue::MAX_BUILDING . ' plans at a time', BuildQueue::waitsFor($b, 'zz', 0, [$a, $c]));
    }

    public function testItDoesNotJumpAQueuedPlanAheadOfItThatChangesTheSameFiles(): void {
        $first  = $this->plan('approved', [['files' => ['x.php']]], 1);
        $second = $this->plan('approved', [['files' => ['x.php']]], 2);
        $this->assertStringContainsString("after #{$first->id}", BuildQueue::waitsFor($second, 'zz', 0, []));
        $this->assertSame('', BuildQueue::waitsFor($first, 'zz', 0, []), 'the one ahead waits for nobody');
    }

    public function testBuildQueuesItWithItsPlaceAndItsReason(): void {
        $a = $this->plan('building', [['files' => ['x.php']]]);
        $b = $this->plan('approved', [['files' => ['x.php']]]);
        $c = $this->plan('stalled',  [['files' => ['x.php']]]);
        $r = BuildQueue::start($b, 'zz', '/nowhere', 50, null, 0, [$a]);
        $this->assertSame('queued', $r['state']);
        BuildQueue::start($c, 'zz', '/nowhere', 50, null, 0, [$a]);
        $b = Bean::load('workbenchtask', (int) $b->id); $c = Bean::load('workbenchtask', (int) $c->id);
        $this->assertSame([1, 2], [(int) $b->queuePos, (int) $c->queuePos]);
        $this->assertSame(['approved', 'stalled'], [(string) $b->planStatus, (string) $c->planStatus], 'a queued plan keeps its state');
        $this->assertSame(50, (int) $b->queueLevel);
        $this->assertStringStartsWith("Queued: starts after #{$a->id}", (string) $b->progressMessage);
        // pressed again while it waits: same place
        BuildQueue::start($b, 'zz', '/nowhere', 50, null, 0, [$a]);
        $this->assertSame(1, (int) Bean::load('workbenchtask', (int) $b->id)->queuePos);
    }

    public function testTheQueueIsReorderedAndLeft(): void {
        $p = [$this->plan('approved', [], 1), $this->plan('approved', [], 2), $this->plan('approved', [], 3)];
        $order = fn() => array_map(fn($q) => (int) $q->id, BuildQueue::queued());
        $this->assertTrue(BuildQueue::move(Bean::load('workbenchtask', (int) $p[2]->id), 'up'));
        $this->assertSame([(int) $p[0]->id, (int) $p[2]->id, (int) $p[1]->id], $order());
        $this->assertFalse(BuildQueue::move(Bean::load('workbenchtask', (int) $p[0]->id), 'up'), 'already next');
        $this->assertFalse(BuildQueue::move(Bean::load('workbenchtask', (int) $p[1]->id), 'down'), 'already last');
        BuildQueue::leave(Bean::load('workbenchtask', (int) $p[2]->id));
        $this->assertSame([(int) $p[0]->id, (int) $p[1]->id], $order());
        $this->assertSame([1, 2], array_map(fn($q) => (int) $q->queuePos, BuildQueue::queued()), 'places close up');
    }
}
