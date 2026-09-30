<?php
/**
 * AgentTask — the builder's unit of work inside an app (RUNTIME-SPLIT-MAP.md step 4), on a real
 * git repository under a temp root. The agent is a stand-in bin/claude that edits a file, and
 * the app's credential is a stand-in login file: what is under test is the worktree, commit,
 * merge and refusal mechanics, not Claude.
 */

namespace tests\unit;

use app\AgentTask;
use app\EngineRegistry;
use app\Paths;
use PHPUnit\Framework\TestCase;

class AgentTaskTest extends TestCase {

    private string $app;

    protected function setUp(): void {
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
}
