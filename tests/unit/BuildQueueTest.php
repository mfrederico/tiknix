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

    /** A project's board, read from outside it: queued plans count, a "building" row with no build behind it does not. */
    public function testBusyWithReadsTheBoardOfAProject(): void {
        $dir = sys_get_temp_dir() . '/bq-busy-' . bin2hex(random_bytes(4));
        $this->assertSame('', BuildQueue::busyWith($dir, 'zz-none'), 'no board: nothing was ever built');
        mkdir($dir . '/data', 0700, true);
        $pdo = new \PDO('sqlite:' . $dir . '/data/workbench.db');
        $pdo->exec('CREATE TABLE workbenchtask (id INTEGER PRIMARY KEY, plan_status TEXT, queue_pos INTEGER)');
        $pdo->exec("INSERT INTO workbenchtask (id, plan_status, queue_pos) VALUES (1, 'done', 0), (2, 'building', 0)");
        $this->assertSame('', BuildQueue::busyWith($dir, 'zz-none'), 'a row that says building with no session behind it is not a build');
        $pdo->exec("INSERT INTO workbenchtask (id, plan_status, queue_pos) VALUES (3, 'approved', 1)");
        $this->assertSame('#3 is waiting to be built', BuildQueue::busyWith($dir, 'zz-none'));
        $pdo = null;
        unlink($dir . '/data/workbench.db'); rmdir($dir . '/data'); rmdir($dir);
    }

    /** What a waiting request's planner is told: every finished task of a plan that was still building, with its files and plugins. */
    public function testBuiltSinceNamesTheFilesAndPluginsOfWhatWasBuilding(): void {
        $dir = sys_get_temp_dir() . '/bq-built-' . bin2hex(random_bytes(4));
        $this->assertSame('', BuildQueue::builtSince($dir, '2026-01-01 00:00:00'));
        mkdir($dir . '/data', 0700, true);
        $pdo = new \PDO('sqlite:' . $dir . '/data/workbench.db');
        $pdo->exec('CREATE TABLE workbenchtask (id INTEGER PRIMARY KEY, parent_task_id INTEGER, title TEXT, status TEXT, related_files TEXT, adopts TEXT, error_message TEXT, completed_at TEXT)');
        $pdo->exec("INSERT INTO workbenchtask VALUES (1, NULL, 'Old plan', 'completed', '', '', '', NULL), (2, 1, 'Old task', 'merged', '[\"views/old.php\"]', '[]', '', '2026-10-01 10:00:00'),
            (3, NULL, 'Warehouse game', 'completed', '', '', '', NULL),
            (4, 3, 'Editor', 'merged', '[\"views/warehouse/builder.php\"]', '[]', 'merged into the app as abc123', '2026-10-13 02:10:00'),
            (5, 3, 'Leads', 'merged', '[\"controls/Game.php\"]', '[\"crm\"]', '', '2026-10-13 02:30:00'),
            (6, 3, 'Not built', 'failed', '[\"x.php\"]', '[]', '', '2026-10-13 02:31:00')");
        $md = BuildQueue::builtSince($dir, '2026-10-13 02:14:00');
        $this->assertStringContainsString('### #3 Warehouse game', $md);
        $this->assertStringContainsString('views/warehouse/builder.php', $md, 'a task of that plan finished before the request was made is still part of what it builds on');
        $this->assertStringContainsString('plugins adopted: crm', $md);
        $this->assertStringNotContainsString('views/old.php', $md, 'a plan finished long before is not what the request waited for');
        $this->assertStringNotContainsString('Not built', $md);
        $pdo = null;
        unlink($dir . '/data/workbench.db'); rmdir($dir . '/data'); rmdir($dir);
    }
}
