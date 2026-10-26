<?php
/**
 * The `pipeline` step — chaining. A child is a run of its own (parent_run_id set), its
 * output flows back as {step.output.result} in sync mode, async yields a run id, and a
 * self-call, a cycle, a runaway depth and a missing child all fail loudly by name.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\Pipeline\Executor;
use app\Pipeline\Loader;
use app\Pipeline\Runner;

class PipelineStepTest extends ConceptsTestCase {

    private const DB = 'pipeline-step-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        mkdir("{$this->root}/pipelines");
        mkdir("{$this->root}/data", 0700, true);
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function pipe(string $slug, array $steps): void {
        file_put_contents("{$this->root}/pipelines/{$slug}.json", json_encode(['slug' => $slug, 'name' => $slug, 'steps' => $steps]));
    }

    private function say(string $name, string $text, string $next = 'exit'): array {
        return ['name' => $name, 'type' => 'transform', 'config' => ['mode' => 'template', 'input' => $text], 'on_success' => $next];
    }

    private function call(string $name, string $slug, array $context = [], string $mode = 'sync', string $next = 'next'): array {
        return ['name' => $name, 'type' => 'pipeline', 'config' => ['slug' => $slug, 'context' => $context, 'mode' => $mode], 'on_success' => $next, 'on_fail' => 'exit'];
    }

    private function runPipe(string $slug, array $ctx = []): array {
        $def = (new Loader($this->root))->get($slug);
        $this->assertNotNull($def, "fixture pipeline {$slug}");
        return (new Executor($this->root))->run($def, $ctx, 'test');
    }

    /* ---- one run per row, once per key ---- */

    public function testEachStartsOneRunPerRowOncePerKey(): void {
        mkdir("{$this->root}/scripts"); file_put_contents("{$this->root}/scripts/pipeline-run.php", "<?php exit(0);\n");   // the worker: a stand-in
        $this->pipe('journey', [$this->say('hi', 'hello {context.member_id}')]);
        $rows = json_encode([['member_id' => 7, 'name' => 'Ann'], ['member_id' => 9, 'name' => 'Bob']]);
        $this->pipe('fan', [['name' => 'start', 'type' => 'pipeline', 'config' => ['slug' => 'journey', 'each' => $rows, 'key' => '{item.member_id}', 'context' => ['member_id' => '{item.member_id}', 'who' => '{item.name}']], 'on_success' => 'exit', 'on_fail' => 'exit']]);

        $r = $this->runPipe('fan');
        $this->assertSame('completed', $r['status']);
        $out = $r['output'];
        $this->assertSame(2, $out['started']); $this->assertSame(0, $out['skipped']);
        $runs = Bean::find('piperun', 'slug = ? ORDER BY id', ['journey']);
        $this->assertCount(2, $runs);
        $this->assertSame(['7', '9'], array_values(array_map(fn($x) => (string) $x->flowKey, $runs)));
        $this->assertSame(['member_id' => '7', 'who' => 'Ann'], json_decode((string) array_values($runs)[0]->contextJson, true));
        $this->assertTrue(\app\Pipeline\FlowKeys::exists('journey', '9'));

        $again = $this->runPipe('fan');
        $this->assertSame(0, $again['output']['started'], 'the same members do not start again');
        $this->assertSame(2, $again['output']['skipped']);
        $this->assertSame(['7', '9'], $again['output']['skipped_keys']);
        $this->assertCount(2, Bean::find('piperun', 'slug = ?', ['journey']));

        // a plain list, and an empty key is refused
        $this->pipe('fan2', [['name' => 'start', 'type' => 'pipeline', 'config' => ['slug' => 'journey', 'each' => '11, 12', 'context' => ['member_id' => '{item}']], 'on_success' => 'exit', 'on_fail' => 'exit']]);
        $this->assertSame(2, $this->runPipe('fan2')['output']['started']);
        $this->pipe('fan3', [['name' => 'start', 'type' => 'pipeline', 'config' => ['slug' => 'journey', 'each' => $rows, 'key' => '{item.nope}'], 'on_success' => 'exit', 'on_fail' => 'exit']]);
        $this->assertSame('failed', $this->runPipe('fan3')['status']);
    }

    public function testEveryStarterFlowIsValid(): void {
        $all = \app\Pipeline\Templates::all();
        $this->assertGreaterThanOrEqual(4, count($all));
        foreach ($all as $t) {
            $this->assertSame([], Loader::validate($t['def']), "starter flow {$t['id']}");
            $this->assertNotSame('', $t['setup']);
        }
    }

    /* ---- sync: the child's output comes back ---- */

    public function testSyncChildOutputFlowsBackAndTheChildIsItsOwnRun(): void {
        $this->pipe('child', [$this->say('greet', 'hello {context.who}')]);
        $this->pipe('parent', [
            $this->call('kid', 'child', ['who' => '{context.name}']),
            $this->say('done', 'child said: {kid.output.result} (run {kid.output.run_id}, {kid.output.status})'),
        ]);
        $r = $this->runPipe('parent', ['name' => 'Ada']);
        $this->assertSame('completed', $r['status'], $r['error'] ?? '');
        $this->assertMatchesRegularExpression('/^child said: hello Ada \(run \d+, completed\)$/', $r['output']);

        $child = Bean::findOne('piperun', 'slug = ?', ['child']);
        $this->assertSame((int) $r['run_id'], (int) $child->parentRunId, 'parent_run_id points at the parent');
        $this->assertSame('pipeline:parent', (string) $child->source);
        $this->assertSame(1, (int) Bean::count('pipesteprun', 'run_id = ?', [$child->id]), 'the child has its own trace');
        $this->assertSame(2, (int) Bean::count('pipesteprun', 'run_id = ?', [$r['run_id']]), 'the parent shows one row for the call');
    }

    public function testAFailingChildFailsTheStepWithTheChildsError(): void {
        $this->pipe('bad', [['name' => 'boom', 'type' => 'shell', 'config' => ['command' => 'exit 3'], 'on_fail' => 'exit']]);
        $this->pipe('parent', [$this->call('kid', 'bad'), $this->say('never', 'unreachable')]);
        $r = $this->runPipe('parent');
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString("pipeline 'bad' run", $r['error']);
        $this->assertStringContainsString('failed', $r['error']);
    }

    /* ---- async ---- */

    public function testAsyncYieldsARunIdAndDoesNotWait(): void {
        $this->pipe('child', [$this->say('greet', 'hi')]);
        $this->pipe('parent', [$this->call('kid', 'child', [], 'async'), $this->say('done', 'queued {kid.output.run_id} {kid.output.status}')]);
        $r = $this->runPipe('parent');
        $this->assertSame('completed', $r['status'], $r['error'] ?? '');
        $this->assertMatchesRegularExpression('/^queued \d+ queued$/', $r['output']);
        $this->assertSame('queued', (string) Bean::findOne('piperun', 'slug = ?', ['child'])->status, 'left for the background worker');
    }

    /* ---- refusals ---- */

    public function testASelfCallIsRefusedAtValidationAndACycleAtRunTime(): void {
        $this->pipe('loop', [$this->call('again', 'loop')]);
        $this->assertContains("step 'again': a pipeline may not call itself", Loader::validate((new Loader($this->root))->get('loop')));

        $this->pipe('a', [$this->call('tob', 'b')]);
        $this->pipe('b', [$this->call('toa', 'a')]);
        $r = $this->runPipe('a');
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString("pipeline 'a' is already running above this step (a → b → a)", $r['error']);
    }

    public function testARunawayDepthIsRefused(): void {
        for ($i = 0; $i < 12; $i++) $this->pipe("d{$i}", [$this->call('next', 'd' . ($i + 1))]);
        $this->pipe('d12', [$this->say('end', 'bottom')]);
        $r = $this->runPipe('d0');
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('the limit is 8', $r['error']);
    }

    public function testAMissingChildFailsByName(): void {
        $this->pipe('parent', [$this->call('kid', 'nosuch')]);
        $r = $this->runPipe('parent');
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString("pipeline 'nosuch' not found on this install", $r['error']);
    }

    public function testTheStepIsRegisteredWithItsFields(): void {
        $c = \app\Pipeline\StepRegistry::components()['pipeline'];
        $this->assertSame(['slug', 'context', 'mode', 'each', 'key'], array_column($c['schema']['fields'] ?? $c['fields'], 'name'));
    }
}
