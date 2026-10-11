<?php
/**
 * app\AgentTask::catchUpWith — a finished task whose merge failed because the app changed under
 * it: the app's code as it is now is merged into the task's own copy. Real git, in a scratch repo.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\AgentTask;

class TaskCatchUpTest extends TestCase {
    private string $root;
    private string $wt;

    private function git(string $dir, string $cmd): string {
        exec('cd ' . escapeshellarg($dir) . ' && env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE GIT_AUTHOR_NAME=T GIT_AUTHOR_EMAIL=t@example.test GIT_COMMITTER_NAME=T GIT_COMMITTER_EMAIL=t@example.test git ' . $cmd . ' 2>&1', $out, $code);
        return trim(implode("\n", $out));
    }

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-catchup-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        $this->git($this->root, 'init -q -b main');
        file_put_contents("{$this->root}/page.php", "one\ntwo\nthree\n");
        file_put_contents("{$this->root}/other.php", "other\n");
        $this->git($this->root, 'add -A'); $this->git($this->root, 'commit -q -m base');
        $this->wt = "{$this->root}/.aibuilder/wt/t1";
        $this->git($this->root, 'worktree add -q -b task/t1 ' . escapeshellarg($this->wt) . ' HEAD');
        foreach (['GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@example.test', 'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@example.test'] as $k => $v) putenv("{$k}={$v}");
    }

    protected function tearDown(): void {
        foreach (['GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL'] as $k) putenv($k);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function commit(string $dir, string $file, string $body, string $msg): void {
        file_put_contents("{$dir}/{$file}", $body);
        $this->git($dir, 'add -A'); $this->git($dir, 'commit -q -m ' . escapeshellarg($msg));
    }

    public function testChangesOnDifferentLinesAreSimplyMergedIn(): void {
        $this->commit($this->wt, 'other.php', "other, by the task\n", 'the task');
        $this->commit($this->root, 'page.php', "one\ntwo\nthree, by the app\n", 'the app moved on');
        $c = AgentTask::catchUpWith($this->root, $this->wt, 't1');
        $this->assertSame('', $c['error']);
        $this->assertSame([], $c['files']);
        $this->assertStringContainsString('the app moved on', $c['theirs']);
        $this->assertStringContainsString('by the app', (string) file_get_contents("{$this->wt}/page.php"));
        $this->assertSame([], AgentTask::unsettled($this->wt, $c['files']));
    }

    public function testTheSameLinesStopOnTheFileAndItIsUnsettledUntilTheMarkersGo(): void {
        $this->commit($this->wt, 'page.php', "one\ntwo, by the task\nthree\n", 'the task');
        $this->commit($this->root, 'page.php', "one\ntwo, by the app\nthree\n", 'the app moved on');
        $c = AgentTask::catchUpWith($this->root, $this->wt, 't1');
        $this->assertSame('', $c['error']);
        $this->assertSame(['page.php'], $c['files']);
        $this->assertSame(['page.php'], AgentTask::unsettled($this->wt, $c['files']));
        $brief = AgentTask::catchUpBrief($c);
        $this->assertStringContainsString('`page.php`', $brief);
        $this->assertStringContainsString('Keep BOTH', $brief);

        // staged with the markers still in it: not settled
        $this->git($this->wt, 'add page.php');
        $this->assertSame(['page.php'], AgentTask::unsettled($this->wt, $c['files']));
        // brought together and committed: settled, and the app's commit is now part of the task's branch
        file_put_contents("{$this->wt}/page.php", "one\ntwo, by the task and by the app\nthree\n");
        $this->git($this->wt, 'add page.php'); $this->git($this->wt, 'commit -q --no-edit');
        $this->assertSame([], AgentTask::unsettled($this->wt, $c['files']));
        $this->assertSame('', $this->git($this->root, 'merge -q --no-ff -m merge task/t1'), 'the task now merges into the app');
        $this->assertStringContainsString('by the task and by the app', (string) file_get_contents("{$this->root}/page.php"));
    }

    /** Work done by hand in the app and never committed no longer stops a merge: it is saved, and the task merged onto it. */
    public function testUncommittedWorkInTheAppIsSavedAndTheTaskMergedOntoIt(): void {
        $this->commit($this->wt, 'other.php', "other, by the task\n", 'the task');
        file_put_contents("{$this->root}/page.php", "one\ntwo\nthree, typed in the terminal\n");
        file_put_contents("{$this->root}/new.php", "made in the terminal\n");
        file_put_contents("{$this->root}/.git/info/exclude", ".aibuilder/\n");   // as every app's .gitignore has it
        $was = AgentTask::$platformOnly; AgentTask::$platformOnly = null;        // this install refuses to build; an app does not
        try { AgentTask::merge($this->root, 't1'); }   // (its seed step has no app to run in here; the merge itself is what is looked at)
        finally { AgentTask::$platformOnly = $was; }
        $log = $this->git($this->root, 'log --format=%s -4');
        $this->assertStringContainsString('Work in the app, saved before merging task t1: new.php, page.php', $log);
        $this->assertStringContainsString('merge task t1', $log);
        $this->assertSame('', $this->git($this->root, 'status --porcelain --untracked-files=no'));
        $this->assertStringContainsString('by the task', (string) file_get_contents("{$this->root}/other.php"));
        $this->assertStringContainsString('typed in the terminal', (string) file_get_contents("{$this->root}/page.php"));
    }

    public function testAKeptCopyWithUncommittedChangesIsRefused(): void {
        file_put_contents("{$this->wt}/page.php", "edited and not committed\n");
        $c = AgentTask::catchUpWith($this->root, $this->wt, 't1');
        $this->assertStringContainsString('uncommitted changes', $c['error']);
    }
}
