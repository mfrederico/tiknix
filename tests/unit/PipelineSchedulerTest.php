<?php
/**
 * Pipeline\Scheduler — the app schedules itself. A tick reads THIS install's own
 * definitions, fires the cron triggers due that minute (once per minute, claimed before
 * firing), wakes durable objects, and reports what it did.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\Pipeline\Scheduler;

class PipelineSchedulerTest extends ConceptsTestCase {

    private const DB = 'pipeline-scheduler-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        mkdir("{$this->root}/pipelines"); mkdir("{$this->root}/data", 0700, true); mkdir("{$this->root}/scripts");
        // Dispatcher launches scripts/pipeline-run.php in the background; the fixture has a
        // harmless stand-in so the queued run is created and nothing else happens.
        file_put_contents("{$this->root}/scripts/pipeline-run.php", "<?php exit(0);\n");
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function pipe(string $slug, ?string $cron): void {
        $def = ['slug' => $slug, 'name' => $slug, 'steps' => [
            ['name' => 'say', 'type' => 'transform', 'config' => ['mode' => 'template', 'input' => 'hi'], 'on_success' => 'exit']]];
        if ($cron !== null) $def['trigger'] = ['cron' => $cron];
        file_put_contents("{$this->root}/pipelines/{$slug}.json", json_encode($def));
    }

    /** A wait until "2 hours": the run pauses with a wake time; the tick after that time wakes it, and it goes on from the next step. */
    public function testATimedWaitPausesTheRunAndTheTickWakesIt(): void {
        $def = ['slug' => 'later', 'name' => 'later', 'steps' => [
            ['name' => 'nap', 'type' => 'wait', 'config' => ['mode' => 'until', 'for' => '2 hours'], 'on_success' => 'next'],
            ['name' => 'say', 'type' => 'transform', 'config' => ['mode' => 'template', 'input' => 'woke {nap.woke_at}'], 'on_success' => 'exit']]];
        file_put_contents("{$this->root}/pipelines/later.json", json_encode($def));
        $this->assertSame([], \app\Pipeline\Loader::validate($def));
        $bad = $def; $bad['steps'][0]['config']['for'] = 'soonish';
        $this->assertStringContainsString("not a duration", implode('; ', \app\Pipeline\Loader::validate($bad)));
        $this->assertSame(3600 * 24 * 3 + 100, \app\Pipeline\Steps\WaitStep::wakeTime('3 days', 100));
        $this->assertNull(\app\Pipeline\Steps\WaitStep::wakeTime('0 days'));

        $r = (new \app\Pipeline\Executor($this->root))->run($def, [], 'test');
        $this->assertSame('paused', $r['status']);
        $this->assertSame('nap', $r['awaiting']);
        $run = Bean::load('piperun', (int) $r['run_id']);
        $wake = strtotime((string) $run->wakeAt);
        $this->assertEqualsWithDelta(time() + 7200, $wake, 5, 'the wake time is two hours out');

        $early = Scheduler::tick($this->root, $wake - 60);
        $this->assertSame([], $early['woken'], 'not yet');
        $this->assertSame('paused', (string) Bean::load('piperun', (int) $run->id)->status);

        $due = Scheduler::tick($this->root, $wake + 1);
        $this->assertSame([(int) $run->id], array_column($due['woken'], 'run_id'));
        $this->assertSame('queued', (string) Bean::load('piperun', (int) $run->id)->status, 'claimed before the worker starts');
        $this->assertSame([], Scheduler::tick($this->root, $wake + 61)['woken'], 'a queued run is not woken twice');

        // what the worker the tick spawned does
        $done = (new \app\Pipeline\Executor($this->root))->wake((int) $run->id);
        $this->assertSame('completed', $done['status']);
        $this->assertSame(2, $done['steps_done']);
        $final = Bean::load('piperun', (int) $run->id);
        $this->assertNull($final->wakeAt);
        $out = json_decode((string) $final->outputJson, true);
        $this->assertStringContainsString('woke 20', is_array($out) ? json_encode($out) : (string) $out, 'the step after the wait read {nap.woke_at}');
    }

    public function testATickFiresWhatIsDueOnceAndReportsTheRest(): void {
        $this->pipe('every',   '* * * * *');
        $this->pipe('at-six',  '0 6 * * *');
        $this->pipe('manual',  null);
        $this->pipe('broken',  'not a cron');
        $noon = mktime(12, 30, 0, 9, 22, 2026);

        $r = Scheduler::tick($this->root, $noon);
        $this->assertSame('2026-09-22 12:30', $r['minute']);
        $this->assertSame(3, $r['checked'], 'three carry a trigger; the manual one is not counted');
        $this->assertSame(['every'], array_column($r['fired'], 'slug'));
        $this->assertSame([['slug' => 'broken', 'why' => "invalid cron expression 'not a cron'"]], $r['skipped']);
        $run = Bean::findOne('piperun', 'slug = ?', ['every']);
        $this->assertSame('queued', (string) $run->status);
        $this->assertSame('cron', (string) $run->source);

        // The same minute again, its run finished: claimed already, nothing fires twice.
        $finish = function (string $slug): void { foreach (Bean::find('piperun', "slug = ? AND status IN ('queued','running')", [$slug]) as $x) { $x->status = 'completed'; Bean::store($x); } };
        $finish('every');
        $r2 = Scheduler::tick($this->root, $noon + 20);
        $this->assertSame([], $r2['fired']);
        $this->assertContains(['slug' => 'every', 'why' => 'already fired this minute'], $r2['skipped']);
        $this->assertSame(1, (int) Bean::count('piperun', 'slug = ?', ['every']));

        // The next minute fires again…
        $this->assertSame(['every'], array_column(Scheduler::tick($this->root, $noon + 60)['fired'], 'slug'));
        // …but never on top of itself: while that run is unfinished, the minute after does not start another.
        $r3 = Scheduler::tick($this->root, $noon + 120);
        $this->assertSame([], $r3['fired']);
        $this->assertStringContainsString('has not finished', implode(' | ', array_column(array_filter($r3['skipped'], fn($x) => $x['slug'] === 'every'), 'why')));
        $this->assertSame(2, (int) Bean::count('piperun', 'slug = ?', ['every']));
        // A run unfinished for hours has lost its worker: it does not stop the schedule for good.
        $stuck = Bean::findOne('piperun', "slug = ? AND status = 'queued'", ['every']);
        $stuck->createdAt = date('Y-m-d H:i:s', $noon + 180 - Scheduler::RUN_PRESUMED_DEAD - 60); Bean::store($stuck);
        $this->assertSame(['every'], array_column(Scheduler::tick($this->root, $noon + 180)['fired'], 'slug'));
        $finish('every');
        $six = mktime(6, 0, 0, 9, 23, 2026);
        $this->assertEqualsCanonicalizing(['every', 'at-six'], array_column(Scheduler::tick($this->root, $six)['fired'], 'slug'));
        $this->assertFileExists("{$this->root}/cache/pipecron-at-six.last");
    }

    public function testAnEnabledConceptsCronPipelineIsScheduledAndTraceable(): void {
        $n = $this->uniq('sk');
        $def = ['slug' => "{$n}-nightly", 'name' => 'n', 'trigger' => ['cron' => '15 2 * * *'], 'steps' => [
            ['name' => 'say', 'type' => 'transform', 'config' => ['mode' => 'template', 'input' => 'hi'], 'on_success' => 'exit']]];
        $this->concept($n, ['provides' => ['pipelines' => ["{$n}-nightly"]]], ["pipelines/{$n}-nightly.json" => json_encode($def)]);
        $this->on = [$n];
        // Scheduler::tick uses Loader::forInstall(), which knows only the RUNNING install's
        // concepts; here the fixture root is not it, so drive the Loader path the same way
        // the running install would by asking with explicit sources.
        $loader = new \app\Pipeline\Loader($this->root, $this->concepts()->pipelineSources());
        $this->assertSame(["{$n}-nightly"], array_keys($loader->all()));
        $this->assertSame($n, $loader->originOf("{$n}-nightly"));
    }
}
