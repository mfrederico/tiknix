<?php
/**
 * app\TaskTests: the app's own tests run before and after a task, and the task is told only what
 * fails NOW and passed BEFORE. A stand-in runner (a script named vendor/bin/phpunit) writes the
 * result file a real one would.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\TaskTests;

class TaskTestsTest extends TestCase {
    private string $app;
    protected function setUp(): void {
        $this->app = sys_get_temp_dir() . '/tiknix-tasktests-' . bin2hex(random_bytes(4));
        mkdir("{$this->app}/vendor/bin", 0700, true);
        file_put_contents("{$this->app}/phpunit.xml", '<phpunit/>');
    }
    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->app)); }

    /** A runner whose every test passes except the ones named; $exit and $writes say how it ends. */
    private function runner(array $failing, array $passing = ['A::one', 'A::two', 'B::three'], int $exit = -1, bool $writes = true, string $says = ''): void {
        $cases = '';
        foreach (array_merge($passing, $failing) as $t) {
            [$c, $n] = explode('::', $t);
            $cases .= '<testcase name="' . $n . '" class="tests\\unit\\' . $c . '">' . (in_array($t, $failing, true) ? '<failure>no</failure>' : '') . '</testcase>';
        }
        $xml = '<testsuites><testsuite>' . $cases . '</testsuite></testsuites>';
        $code = $exit >= 0 ? $exit : ($failing ? 1 : 0);
        $script = "#!/bin/sh\nlog=\"\"\nwhile [ \$# -gt 0 ]; do [ \"\$1\" = \"--log-junit\" ] && log=\"\$2\"; shift; done\n"
                . ($says !== '' ? 'echo ' . escapeshellarg($says) . "\n" : '')
                . ($writes ? 'printf %s ' . escapeshellarg($xml) . " > \"\$log\"\n" : '')
                . "exit {$code}\n";
        file_put_contents("{$this->app}/vendor/bin/phpunit", $script);
        chmod("{$this->app}/vendor/bin/phpunit", 0755);
    }

    public function testAnAppWithoutTestsIsNotReported(): void {
        unlink("{$this->app}/phpunit.xml");
        $this->assertFalse(TaskTests::has($this->app));
        $this->assertSame([], TaskTests::notes(null, null));
        $this->assertSame('', TaskTests::brief($this->app, null));
    }

    public function testEverythingPassingSaysNothing(): void {
        $this->runner([]);
        $r = TaskTests::run($this->app);
        $this->assertTrue($r['ran']); $this->assertTrue($r['ok']); $this->assertSame(3, $r['total']);
        $this->assertSame([], TaskTests::notes($r, $r));
        $this->assertStringContainsString('All 3 passed when you started', TaskTests::brief($this->app, $r));
    }

    public function testOnlyWhatThisTaskBrokeIsNamed(): void {
        $this->runner(['Old::alreadyRed']);
        $before = TaskTests::run($this->app);
        $this->runner(['Old::alreadyRed', 'Cafe::newlyBroken']);
        $after = TaskTests::run($this->app);
        $notes = TaskTests::notes($before, $after);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('1 of 5 fail after this task and passed before it: Cafe::newlyBroken.', $notes[0]);
        $this->assertStringContainsString('1 more were already failing before it', $notes[0]);
        $this->assertStringNotContainsString('Old::alreadyRed', $notes[0]);
        $this->assertSame([], TaskTests::notes($after, $after), 'nothing new: nothing said');
        $this->assertStringContainsString('1 were already failing when you started (Old::alreadyRed)', TaskTests::brief($this->app, $before));
    }

    public function testATaskThatFixesTestsSaysNothing(): void {
        $this->runner(['Old::alreadyRed']); $before = TaskTests::run($this->app);
        $this->runner([]); $after = TaskTests::run($this->app);
        $this->assertSame([], TaskTests::notes($before, $after));
    }

    public function testARunnerThatDiesIsNotReadAsPassing(): void {
        $this->runner([]); $before = TaskTests::run($this->app);
        $this->runner([], [], 255, false, 'PHP Fatal error: Class "Model_Post" not found');
        $after = TaskTests::run($this->app);
        $this->assertFalse($after['ran']); $this->assertFalse($after['ok']);
        $notes = TaskTests::notes($before, $after);
        $this->assertStringContainsString('could not be run after this task', $notes[0]);
        $this->assertStringContainsString('Class "Model_Post" not found', $notes[0]);
        $this->assertSame([], TaskTests::notes($after, $after), 'it could not run before either, for the same reason: not this task\'s doing');
    }

    public function testAResultIsKeptForTheCodeItRanOn(): void {
        $tree = str_repeat('a', 40);
        $this->assertNull(TaskTests::known($this->app, $tree));
        $this->runner(['Cafe::red']); $r = TaskTests::run($this->app);
        TaskTests::remember($this->app, $tree, $r);
        $this->assertSame(['Cafe::red'], TaskTests::known($this->app, $tree)['failing']);
        $this->assertNull(TaskTests::known($this->app, str_repeat('b', 40)), 'other code, no answer');
        $this->runner([], [], 255, false, 'boom');
        TaskTests::remember($this->app, str_repeat('c', 40), TaskTests::run($this->app));
        $this->assertNull(TaskTests::known($this->app, str_repeat('c', 40)), 'a run that could not finish is not remembered: it is tried again');
        $this->assertNull(TaskTests::known($this->app, '../../etc/passwd'));
    }

    public function testNoBaselineSaysSo(): void {
        $this->runner([], [], 255, false, 'boom'); $before = TaskTests::run($this->app);
        $this->runner(['Cafe::red']); $after = TaskTests::run($this->app);
        $note = TaskTests::notes($before, $after)[0];
        $this->assertStringContainsString('1 of 4 fail after this task: Cafe::red.', $note);
        $this->assertStringContainsString('Whether they passed before is not known', $note);
    }
}
