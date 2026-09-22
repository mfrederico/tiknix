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

        // The same minute again: claimed already, nothing fires twice.
        $r2 = Scheduler::tick($this->root, $noon + 20);
        $this->assertSame([], $r2['fired']);
        $this->assertContains(['slug' => 'every', 'why' => 'already fired this minute'], $r2['skipped']);
        $this->assertSame(1, (int) Bean::count('piperun', 'slug = ?', ['every']));

        // The next minute fires again; six o'clock fires the daily one too.
        $this->assertSame(['every'], array_column(Scheduler::tick($this->root, $noon + 60)['fired'], 'slug'));
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
