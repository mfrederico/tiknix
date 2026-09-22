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
        $this->assertSame(['slug', 'context', 'mode'], array_column($c['schema']['fields'] ?? $c['fields'], 'name'));
    }
}
