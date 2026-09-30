<?php
/**
 * AgentTask — the builder's unit of work inside an app (RUNTIME-SPLIT-MAP.md step 4), on a real
 * git repository under a temp root. The agent is a stand-in bin/claude that edits a file, and
 * the app's credential is a stand-in login file: what is under test is the worktree, commit,
 * merge and refusal mechanics, not Claude.
 */

namespace tests\unit;

use app\AgentTask;
use app\Bean;
use app\EngineRegistry;
use app\Paths;
use PHPUnit\Framework\TestCase;

class AgentTaskTest extends TestCase {

    private string $app;
    private const DB = 'agenttask-test';
    private ?string $prevDb = null;

    protected function setUp(): void {
        // The app's agents (AgentTask::runsOn reads the default one): none here, so a run goes
        // on the app's Claude account — the stand-in login below.
        $this->prevDb = Bean::currentDatabaseKey();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        $this->app = sys_get_temp_dir() . '/tiknix-agenttask-' . getmypid() . '-' . bin2hex(random_bytes(3));
        foreach (['conf', 'bin', 'scripts', '.aibuilder/state/claude'] as $d) mkdir("{$this->app}/{$d}", 0700, true);
        file_put_contents("{$this->app}/composer.json", "{}\n");
        file_put_contents("{$this->app}/README.md", "an app\n");
        file_put_contents("{$this->app}/.gitignore", ".aibuilder/\n/bin/claude\nvendor/\ncomposer.lock\n");
        file_put_contents("{$this->app}/scripts/clitool.php", "<?php echo \"seeds ran\\n\";\n");
        file_put_contents("{$this->app}/conf/aibuilder.ini", "[engine]\ndefault = claude\n[engine.claude]\nlabel = Claude Code\ntransport = cli-headless\ncommand = claude\ncli_flavor = claude\nheadless_ready = true\nworker_model = sonnet\nplanner_model = sonnet\nauditor_model = sonnet\nresolver_model = sonnet\n");
        // The stand-in agent: appends to README.md in the directory it is run in.
        file_put_contents("{$this->app}/bin/claude", "#!/bin/sh\necho 'the agent was here' >> README.md\necho done\n");
        chmod("{$this->app}/bin/claude", 0755);
        file_put_contents("{$this->app}/.aibuilder/state/claude/.credentials.json", json_encode(['claudeAiOauth' => ['subscriptionType' => 'test']]));
        $this->git('init -q -b main');
        $this->git('-c user.email=t@e -c user.name=t add -A');
        $this->git('-c user.email=t@e -c user.name=t commit -q -m app');
        Paths::useRoot($this->app);
        EngineRegistry::flush();
    }

    protected function tearDown(): void {
        if ($this->prevDb !== null && Bean::hasDatabase($this->prevDb)) Bean::selectDatabase($this->prevDb);
        Paths::useRoot(null);
        EngineRegistry::flush();
        exec('rm -rf ' . escapeshellarg($this->app));
    }

    private function git(string $args): string {
        exec('env -u GIT_DIR -u GIT_INDEX_FILE -u GIT_WORK_TREE git -C ' . escapeshellarg($this->app) . ' ' . $args . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, "git {$args}: " . implode("\n", $out));
        return trim(implode("\n", $out));
    }

    public function testRefusalsTouchNothing(): void {
        $this->assertStringContainsString('not a task id', AgentTask::start($this->app, 'Bad Id', 'x')['error']);
        $this->assertStringContainsString('no prompt', AgentTask::start($this->app, 't1', '  ')['error']);
        unlink("{$this->app}/.aibuilder/state/claude/.credentials.json");
        $r = AgentTask::start($this->app, 't1', 'do it');
        $this->assertSame('refused', $r['status']);
        $this->assertStringContainsString('no claude credential', $r['error'], "the app's own chain, never anyone else's");
        $this->assertDirectoryDoesNotExist("{$this->app}/.aibuilder/wt/t1", 'refused before a worktree existed');
        $this->assertSame('main', $this->git('branch --show-current'));
    }

    public function testATaskCommitsOnItsBranchAndMergeBringsItIn(): void {
        $r = AgentTask::start($this->app, 't1', "Add a line to the README\nplease");
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('changed', $r['status']);
        $this->assertSame('claude login (test)', $r['credential']);
        $this->assertStringContainsString('README.md', $r['diffstat']);
        $this->assertStringContainsString('done', $r['output']);
        $this->assertSame("an app\n", file_get_contents("{$this->app}/README.md"), 'the app is untouched until the task is merged');
        $this->assertSame('task t1: Add a line to the README', $this->git('log -1 --format=%s task/t1'));
        $this->assertStringContainsString('already has a worktree', AgentTask::start($this->app, 't1', 'again')['error']);

        $m = AgentTask::merge($this->app, 't1');
        $this->assertTrue($m['ok'], json_encode($m));
        $this->assertStringContainsString('the agent was here', (string) file_get_contents("{$this->app}/README.md"));
        $this->assertDirectoryDoesNotExist("{$this->app}/.aibuilder/wt/t1");
        $this->assertSame('', $this->git('branch --list task/t1'), 'the task branch is gone');
    }

    public function testDiscardLeavesTheAppAsItWas(): void {
        $head = $this->git('rev-parse HEAD');
        $this->assertTrue(AgentTask::start($this->app, 't2', 'change something')['ok']);
        $this->assertTrue(AgentTask::discard($this->app, 't2')['ok']);
        $this->assertSame($head, $this->git('rev-parse HEAD'));
        $this->assertSame("an app\n", file_get_contents("{$this->app}/README.md"));
        $this->assertDirectoryDoesNotExist("{$this->app}/.aibuilder/wt/t2");
    }

    public function testAPlanComesBackFromSubmitPlanAndLeavesNothingBehind(): void {
        $head = $this->git('rev-parse HEAD');
        // The stand-in planner does what submit_plan does: writes a plan into its workspace.
        file_put_contents("{$this->app}/bin/claude", "#!/bin/sh\nmkdir -p \"\$TIKNIX_WORKSPACE/.aibuilder\"\n"
            . "echo '{\"title\":\"Add a README line\",\"subtasks\":[{\"title\":\"edit README\"}],\"member_id\":'\"\$TIKNIX_MEMBER_ID\"'}' > \"\$TIKNIX_WORKSPACE/.aibuilder/7-x.plan.json\"\n"
            . "echo 'this edit is thrown away' >> README.md\necho done\n");
        $r = AgentTask::plan($this->app, 'p1', "# Plan request\nAdd a line to the README", 7);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('planned', $r['status']);
        $plan = json_decode($r['plan'], true);
        $this->assertSame('Add a README line', $plan['title']);
        $this->assertSame(7, $plan['member_id'], 'the planner knew who asked');
        $this->assertDirectoryDoesNotExist("{$this->app}/.aibuilder/wt/p1", 'the worktree is gone');
        $this->assertSame($head, $this->git('rev-parse HEAD'), 'nothing was committed');
        $this->assertSame("an app\n", file_get_contents("{$this->app}/README.md"), 'what the planner touched stayed in its worktree');

        file_put_contents("{$this->app}/bin/claude", "#!/bin/sh\necho 'I forgot to submit'\n");
        $r = AgentTask::plan($this->app, 'p2', 'plan something', 7);
        $this->assertSame('no-plan', $r['status']);
        $this->assertStringContainsString('without calling submit_plan', $r['error']);
        $this->assertDirectoryDoesNotExist("{$this->app}/.aibuilder/wt/p2");
    }
}
