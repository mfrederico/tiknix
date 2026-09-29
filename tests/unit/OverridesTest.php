<?php
/**
 * The runtime split's override rule (RUNTIME-SPLIT-MAP.md; the owner's rule 2026-09-28): an
 * app file at a runtime file's path REPLACES it — the autoloader, the view resolver and the
 * seed builder look in the app first — and from then on the runtime cannot upgrade it; the
 * app's owner does. Overrides makes that visible: current, STALE, unrecorded, orphaned.
 */

namespace tests\unit;

use app\LayeredView;
use app\Overrides;
use PHPUnit\Framework\TestCase;

class OverridesTest extends TestCase {

    private string $app;
    private string $rt;

    protected function setUp(): void {
        $base = sys_get_temp_dir() . '/tiknix-ovr-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->app = $base . '/app';
        $this->rt  = $base . '/app/runtime';
        foreach (['controls', 'views/help', 'lib'] as $d) mkdir("{$this->rt}/{$d}", 0700, true);
        file_put_contents("{$this->rt}/controls/Help.php", "<?php // runtime v1\n");
        file_put_contents("{$this->rt}/views/help/index.php", "runtime help page");
        file_put_contents("{$this->rt}/lib/Gone.php", "<?php\n");
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg(dirname($this->app)));
    }

    public function testTakeRecordReportAndStale(): void {
        $this->assertSame([], Overrides::report($this->app, $this->rt), 'nothing overridden');

        $copy = Overrides::take('controls/Help.php', 'v1.6.2', $this->app, $this->rt);
        $this->assertFileEquals("{$this->rt}/controls/Help.php", $copy);
        $r = Overrides::report($this->app, $this->rt);
        $this->assertSame('current', $r['controls/Help.php']['status']);
        $this->assertSame('v1.6.2', $r['controls/Help.php']['release']);

        // the owner edits their copy: still current — only a RUNTIME change makes it stale
        file_put_contents("{$this->app}/controls/Help.php", "<?php // my help\n");
        $this->assertSame('current', Overrides::report($this->app, $this->rt)['controls/Help.php']['status']);

        // a runtime update changes the file: the override is STALE, the app's copy untouched
        file_put_contents("{$this->rt}/controls/Help.php", "<?php // runtime v2\n");
        $this->assertSame('STALE', Overrides::report($this->app, $this->rt)['controls/Help.php']['status']);
        $this->assertSame("<?php // my help\n", file_get_contents("{$this->app}/controls/Help.php"));

        // the owner reconciles and re-records: current again
        Overrides::record('controls/Help.php', 'v1.6.3', $this->app, $this->rt);
        $this->assertSame('current', Overrides::report($this->app, $this->rt)['controls/Help.php']['status']);

        // an app file shadowing a runtime file with no row: unrecorded
        mkdir("{$this->app}/views/help", 0700, true);
        file_put_contents("{$this->app}/views/help/index.php", "my page");
        $this->assertSame('unrecorded', Overrides::report($this->app, $this->rt)['views/help/index.php']['status']);

        // the runtime drops a file the app overrides: orphaned
        Overrides::take('lib/Gone.php', 'v1.6.3', $this->app, $this->rt);
        unlink("{$this->rt}/lib/Gone.php");
        $this->assertSame('orphaned', Overrides::report($this->app, $this->rt)['lib/Gone.php']['status']);

        // refusals
        foreach (['controls/Nope.php' => 'no controls/Nope.php', 'controls/Help.php' => 'already exists', '../etc/passwd' => 'not a runtime path', 'conf/config.ini' => 'not a runtime path'] as $rel => $msg) {
            try { Overrides::take($rel, '', $this->app, $this->rt); $this->fail($rel); }
            catch (\RuntimeException | \InvalidArgumentException $e) { $this->assertStringContainsString($msg, $e->getMessage(), $rel); }
        }
    }

    public function testViewsResolveAppFirstThenRuntime(): void {
        mkdir("{$this->app}/views", 0700, true);
        $v = new LayeredView("{$this->app}/views");
        $v->fallbacks = ["{$this->rt}/views"];
        $this->assertSame("{$this->rt}/views/help/index.php", $v->getTemplate('help/index'), 'no app copy: the runtime page');
        mkdir("{$this->app}/views/help", 0700, true);
        file_put_contents("{$this->app}/views/help/index.php", "mine");
        $this->assertSame("{$this->app}/views/help/index.php", $v->getTemplate('help/index'), 'an app copy wins');
        $this->assertSame("{$this->app}/views/none/x.php", $v->getTemplate('none/x'), 'missing everywhere: the app path, so the error names it');
        $this->assertSame('/abs/concept/view.php', $v->getTemplate('/abs/concept/view.php'), 'absolute paths untouched');
    }

    /** In this repository: runtime classes come from the package, control-plane classes from core, and core overrides exactly three files. */
    public function testTheRepositoryLayersResolve(): void {
        $this->assertSame(realpath(dirname(__DIR__, 2)), \app\Paths::root());
        // The runtime is the tiknix/runtime package (vendor/tiknix/runtime; a symlink to its
        // checkout while core develops it through a path repository).
        $this->assertSame(realpath(dirname(__DIR__, 2) . '/vendor/tiknix/runtime'), \app\Paths::runtime());
        $fromRuntime = fn(string $c) => str_starts_with((string) realpath((new \ReflectionClass($c))->getFileName()), \app\Paths::runtime() . '/');
        foreach (['app\\Bean', 'app\\Sites', 'app\\Communications', 'app\\Chrome', 'app\\Settings', 'app\\mcptools\\ToolLoader', 'app\\services\\connectors\\ConnectorRegistry', 'app\\Paths', 'Model_Lead'] as $c) {
            $this->assertTrue(class_exists($c), "{$c} autoloads");
            $this->assertTrue($fromRuntime($c), "{$c} comes from the runtime");
        }
        foreach (['app\\PlanExecutor', 'app\\Help', 'app\\Docs', 'app\\Hooks', 'app\\Teams'] as $c) {
            $this->assertFalse($fromRuntime($c), "{$c} is the control plane's");
        }
        // Core overrides exactly the three role-shaped controllers (RUNTIME-SPLIT-MAP.md step
        // 2): its marketing home, its builder hub for other projects, and the hub's
        // integrations — each recorded, so a runtime change to the app version shows STALE.
        $this->assertSame(['controls/Connections.php', 'controls/Index.php', 'controls/Integrations.php'],
            Overrides::shadowing(\app\Paths::root(), \app\Paths::runtime()));
        foreach (Overrides::report() as $rel => $r) $this->assertSame('current', $r['status'], $rel);
    }

    /** The page shell's extension points: the control plane fills them; a slot name that does not exist is refused. */
    public function testChromeSlots(): void {
        foreach (['prepare', 'nav', 'bar', 'account'] as $slot) {
            $this->assertSame(["platform/chrome_{$slot}"], \app\Chrome::$parts[$slot] ?? null, "lib/controlplane.php fills '{$slot}'");
            foreach (\app\Chrome::files($slot) as $f) $this->assertStringStartsWith(\app\Paths::root() . '/views/', (string) realpath($f));
        }
        $this->expectException(\InvalidArgumentException::class);
        \app\Chrome::add('footer', 'x');
    }
}
