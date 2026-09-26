<?php
/**
 * PlanExecutor::applySeeds — the one post-merge step that puts merged seeds onto a LIVE
 * instance, shared by a finished plan (finalize) and a standalone task merged from the
 * board. Found missing for the latter on start.tiknix, 2026-09-25.
 *
 *   nothing      an instance with no seeds of either kind: says so, runs nothing
 *   schema       services/Schema/Seeds/ present → the project's clitool --build runs (its
 *                last lines in the log line), then resetcache
 *   legacy       database/seeds/*.php run once each, ledgered — a second call applies none
 *   failure      a non-zero exit is a FAILED line, never silence
 *
 * Throwaway instance directories with stub scripts under the system temp dir.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\PlanExecutor;

class PlanExecutorApplySeedsTest extends ConceptsTestCase {

    private string $inst;
    private string $ledger;

    protected function setUp(): void {
        parent::setUp();
        $this->inst = $this->root . '/inst.tiknix';
        $this->ledger = $this->inst . '/.aibuilder/task-7-seeds.txt';
        mkdir($this->inst . '/scripts', 0700, true);
    }

    private function stub(string $rel, string $body): void {
        $path = $this->inst . '/' . $rel;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, "<?php\n" . $body);
    }

    public function testAnInstanceWithoutSeedsRunsNothing(): void {
        $log = PlanExecutor::applySeeds($this->inst, $this->ledger);
        $this->assertSame(['no database/seeds/ — nothing to apply'], $log);
        $this->assertFileDoesNotExist($this->ledger);
    }

    public function testSchemaSeedsRunThroughTheProjectsBuilderThenTheCacheResets(): void {
        mkdir($this->inst . '/services/Schema/Seeds', 0700, true);
        $this->stub('scripts/clitool.php', 'echo "  20_X.php: ok\n  21_Y.php: ok\n"; file_put_contents(__DIR__ . "/../built.txt", implode(" ", $argv) . "\n", FILE_APPEND);');
        $this->stub('scripts/resetcache.php', 'echo "cache reset\n";');
        $log = PlanExecutor::applySeeds($this->inst, $this->ledger);
        $this->assertSame([
            'no database/seeds/ — nothing to apply',
            'schema seeds (clitool --build): ok — 20_X.php: ok 21_Y.php: ok',
            'resetcache: ok',
        ], $log, 'no concepts.lock: no plugin seeds step');
        $this->assertStringContainsString('--build', (string) file_get_contents($this->inst . '/built.txt'), 'ran with the build flag, in the instance');

        // With a lock file, every enabled plugin's seeds run on the live database too.
        file_put_contents($this->inst . '/concepts.lock', "{}\n");
        $log = PlanExecutor::applySeeds($this->inst, $this->ledger);
        $this->assertSame('plugin seeds (clitool --concept-seeds=all): ok — 20_X.php: ok 21_Y.php: ok', $log[2]);
        $this->assertStringContainsString('--concept-seeds=all', (string) file_get_contents($this->inst . '/built.txt'));
    }

    public function testLegacySeedsApplyOnceAndAFailureIsSaid(): void {
        $this->stub('database/seeds/a_public_route.php', 'file_put_contents(__DIR__ . "/../../a.count", (int) @file_get_contents(__DIR__ . "/../../a.count") + 1); echo "route seeded\n";');
        $this->stub('database/seeds/b_broken.php', 'fwrite(STDERR, "boom\n"); exit(3);');
        $log = PlanExecutor::applySeeds($this->inst, $this->ledger);
        $this->assertSame('seed a_public_route.php: ok — route seeded', $log[0]);
        $this->assertSame('seed b_broken.php: FAILED — boom', $log[1]);
        $this->assertSame("a_public_route.php\n", file_get_contents($this->ledger), 'only the one that ran is ledgered');
        $again = PlanExecutor::applySeeds($this->inst, $this->ledger);
        $this->assertSame('seed a_public_route.php: already applied', $again[0]);
        $this->assertSame('seed b_broken.php: FAILED — boom', $again[1], 'a failed seed is retried, and fails loudly again');
        $this->assertSame('1', file_get_contents($this->inst . '/a.count'), 'applied exactly once');
    }
}
