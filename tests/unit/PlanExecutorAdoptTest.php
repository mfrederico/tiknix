<?php
/**
 * A build task's `adopts` list is installed into its worktree by the executor, before the
 * agent starts. Exercised against a real catalog directory and a real target directory; the
 * task beans live in an in-memory SQLite, so no install is touched.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ConceptCatalog;
use app\PlanExecutor;

class PlanExecutorAdoptTest extends ConceptsTestCase {

    private string $catalogDir;
    private string $worktree;

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        $this->catalogDir = $this->root . '/_catalog';
        $this->worktree   = $this->root . '/_worktree';
        mkdir($this->catalogDir, 0700, true);
        mkdir($this->worktree, 0700, true);
    }

    private function publish(string $name, array $manifest = []): void {
        $dir = $this->concept($name, $manifest + ['title' => ucfirst($name), 'blurb' => "The {$name} concept.", 'tags' => [$name]], [
            'lib/Thing.php'  => "<?php\nnamespace app\\concepts\\{$name};\nclass Thing {}\n",
            'tests/T.php'    => "<?php\n",
        ]);
        (new ConceptCatalog($this->catalogDir))->publish($dir);
    }

    private function task(array $adopts) {
        $t = Bean::dispense('workbenchtask');
        $t->title  = 'Add class calendar';
        $t->adopts = json_encode($adopts);
        $t->status = 'pending';
        Bean::store($t);
        return $t;
    }

    /** installAdopted() with the test's catalog in place of the install's. */
    private function install($task): ?array {
        $catalogDir = $this->catalogDir;
        $executor = new class(1, 'demo', $this->root, 50, $catalogDir) extends PlanExecutor {
            private string $catalogDir;
            public function __construct(int $planId, string $slug, string $dir, int $level, string $catalogDir) {
                parent::__construct($planId, $slug, $dir, $level);
                $this->catalogDir = $catalogDir;
            }
            protected function catalog(): ConceptCatalog { return new ConceptCatalog($this->catalogDir); }
        };
        $m = new \ReflectionMethod(PlanExecutor::class, 'installAdopted');
        $m->setAccessible(true);
        return $m->invoke($executor, $task, $this->worktree);
    }

    public function testNoAdoptsInstallsNothing(): void {
        $this->assertSame([], $this->install($this->task([])));
        $this->assertDirectoryDoesNotExist("{$this->worktree}/concepts");
    }

    public function testAdoptedConceptLandsInTheWorktreeWithProvenance(): void {
        $this->publish('calendar');
        $out = $this->install($this->task(['calendar']));
        $this->assertSame('calendar', $out[0]['name']);
        $this->assertSame('installed from the catalog', $out[0]['status']);
        $this->assertFileExists("{$this->worktree}/concepts/calendar/lib/Thing.php");
        $this->assertFileExists("{$this->worktree}/concepts/calendar/.installed.json");
    }

    public function testAConceptAlreadyInTheProjectIsLeftAlone(): void {
        $this->publish('calendar');
        $this->install($this->task(['calendar']));
        file_put_contents("{$this->worktree}/concepts/calendar/lib/Thing.php", '<?php // adapted by an earlier task');

        $out = $this->install($this->task(['calendar']));
        $this->assertSame('already in this project', $out[0]['status']);
        $this->assertStringContainsString('adapted by an earlier task', file_get_contents("{$this->worktree}/concepts/calendar/lib/Thing.php"));
    }

    public function testAnUnknownConceptFailsTheTaskByName(): void {
        $task = $this->task(['nosuchconcept']);
        $this->assertNull($this->install($task));
        $this->assertSame('failed', $task->status);
        $this->assertStringContainsString("could not adopt concept 'nosuchconcept'", (string) $task->errorMessage);
    }

    public function testAMissingRequirementFailsTheTaskByName(): void {
        $this->publish('tickets');
        $this->publish('classes', ['requires' => ['concepts' => ['tickets']]]);
        $task = $this->task(['classes']);
        $this->assertNull($this->install($task));
        $this->assertStringContainsString("requires concept 'tickets'", (string) $task->errorMessage);
    }

    public function testARequirementAdoptedAlongsideIsSatisfied(): void {
        $this->publish('tickets');
        $this->publish('classes', ['requires' => ['concepts' => ['tickets']]]);
        $out = $this->install($this->task(['classes', 'tickets']));
        $this->assertCount(2, $out);
    }

    /* ---- the install runner: a plan nobody had to write ---- */

    public function testInstallPlanBringsRequirementsFirstAndSkipsWhatTheProjectHas(): void {
        $this->publish('tickets');
        $this->publish('calendar');
        $this->publish('classes', ['requires' => ['concepts' => ['tickets', 'calendar']]]);
        $catalog = new ConceptCatalog($this->catalogDir);

        $plan = $catalog->installPlan('classes', $this->worktree);
        $this->assertTrue(\app\PlanIngestor::isValidPlan($plan), 'it must be a plan the ingestor accepts');
        $task = $plan['subtasks'][0];
        $this->assertSame('install', $task['task_type']);
        $this->assertSame(['tickets', 'calendar', 'classes'], $task['adopts'], 'requirements come first');

        mkdir("{$this->worktree}/concepts/tickets", 0700, true);   // the project already has this one
        $this->assertSame(['calendar', 'classes'], $catalog->installPlan('classes', $this->worktree)['subtasks'][0]['adopts']);
    }

    public function testInstallPlanRefusesWhatCannotBeSatisfied(): void {
        $this->publish('classes', ['requires' => ['concepts' => ['tickets']]]);   // tickets was never published
        try {
            (new ConceptCatalog($this->catalogDir))->installPlan('classes', $this->worktree);
            $this->fail('installPlan() should have refused');
        } catch (\app\ConceptException $e) {
            $this->assertStringContainsString("'tickets' is required by 'classes'", $e->getMessage());
        }
    }

    public function testInstallPlanRefusesAnAlreadyInstalledConcept(): void {
        $this->publish('calendar');
        mkdir("{$this->worktree}/concepts/calendar", 0700, true);
        $this->expectException(\app\ConceptException::class);
        $this->expectExceptionMessage('already in this project');
        (new ConceptCatalog($this->catalogDir))->installPlan('calendar', $this->worktree);
    }

    public function testAnInstallTaskIsLeftForTheReaperWithNoAgent(): void {
        $this->publish('calendar');
        $task = $this->task(['calendar']);
        $task->taskType = 'install';
        $adopted = $this->install($task);

        $m = new \ReflectionMethod(PlanExecutor::class, 'finishInstallTask');
        $m->setAccessible(true);
        $ok = $m->invoke(new PlanExecutor(1, 'demo', $this->root), $task, $this->worktree, 'plan-1/task-1', $adopted);

        $this->assertTrue($ok);
        $this->assertSame('running', $task->status, 'running + no session is what reapTask() picks up');
        $this->assertSame('', (string) $task->agentSession);
        $this->assertSame('plan-1/task-1', $task->worktreeBranch);
    }

    public function testAnInstallTaskWithNothingToInstallFails(): void {
        $task = $this->task([]);
        $task->taskType = 'install';
        $m = new \ReflectionMethod(PlanExecutor::class, 'finishInstallTask');
        $m->setAccessible(true);
        $this->assertFalse($m->invoke(new PlanExecutor(1, 'demo', $this->root), $task, $this->worktree, 'b', []));
        $this->assertStringContainsString('nothing to install', (string) $task->errorMessage);
    }

    public function testTheBriefTellsTheAgentWhatWasInstalled(): void {
        $this->publish('calendar');
        $task = $this->task(['calendar']);
        $adopted = $this->install($task);
        $m = new \ReflectionMethod(PlanExecutor::class, 'adoptedBrief');
        $m->setAccessible(true);
        $brief = $m->invoke(new PlanExecutor(1, 'demo', $this->root), $adopted);
        $this->assertStringContainsString('concepts/calendar/', $brief);
        $this->assertStringContainsString('do not rewrite them', $brief);
        $this->assertStringContainsString('Plugins page', $brief);
    }
}
