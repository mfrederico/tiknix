<?php
/**
 * mcptools/Architecture + CallGraph — an app described to the Architecture Explorer.
 *
 * An app is its own tree plus the runtime's (vendor/tiknix/runtime): both are read, each node
 * and edge says whose it is, and only the app's own are counted as its problems.
 *
 *   layers     a platform route has its views and libraries attached, not shown as an orphan
 *   override   the app's copy of a runtime controller is the one read; the runtime's is not
 *   views      a view resolves the way the app resolves it: the app's, else the runtime's
 *   problems   orphans and broken links are counted for the app; the platform's separately
 *   rows       credential columns are withheld
 *   hash       changes when the code or the permission table does
 */

namespace tests\unit;

use app\mcptools\Architecture;
use app\mcptools\CallGraph;
use app\mcptools\Introspector;
use PHPUnit\Framework\TestCase;

class ArchitectureTest extends TestCase {

    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/arch-test-' . bin2hex(random_bytes(4));
        $rt = 'vendor/tiknix/runtime';
        $files = [
            'conf/config.ini' => "[database]\ntype = sqlite\npath = database/app.db\n",
            // The app's own.
            'controls/Cafe.php' => <<<'PHP'
<?php
namespace app;
class Cafe extends BaseControls\Control {
    public function index($params = []) {
        $cats = Bean::find('cat', 'is_in = 1');
        Mailer::send('hello');
        $this->render('cafe/index', ['cats' => $cats]);
    }
    public function lonely($params = []) {
        Flight::redirect('/admin');
    }
}
PHP,
            // An override of the runtime's Admin: the one that answers /admin.
            'controls/Admin.php' => <<<'PHP'
<?php
namespace app;
class Admin extends BaseControls\Control {
    public function index($params = []) { $this->render('admin/index', [], false); }
}
PHP,
            'views/cafe/index.php' => '<a href="/cafe/index">Cats</a> <a href="/cafe/nosuchpage">Gone</a> <a href="/auth/login">Sign in</a>',
            // The platform's.
            "{$rt}/controls/Admin.php" => <<<'PHP'
<?php
namespace app;
class Admin extends BaseControls\Control {
    public function index($params = []) { $g = Bean::find('ghost'); $this->render('admin/index'); }
    public function members($params = []) { $this->render('admin/members'); }
}
PHP,
            "{$rt}/controls/Auth.php" => <<<'PHP'
<?php
namespace app;
class Auth extends BaseControls\Control {
    public function login($params = []) {
        $m = Bean::findOne('member', 'email = ?', [$e]);
        Mailer::send('welcome');
        $this->render('auth/login');
    }
    public function unreached($params = []) { }
}
PHP,
            "{$rt}/lib/Mailer.php" => "<?php\nnamespace app;\nclass Mailer {\n    public static function send(\$s) { }\n}\n",
            "{$rt}/views/admin/index.php" => '<h1>Admin</h1>',
            "{$rt}/views/auth/login.php" => '<form action="/auth/nosuchaction"></form>',
            "{$rt}/views/layouts/header.php" => '<nav><a href="/auth/login">Login</a></nav>',
        ];
        foreach ($files as $rel => $body) {
            @mkdir(dirname("{$this->root}/{$rel}"), 0777, true);
            file_put_contents("{$this->root}/{$rel}", $body);
        }
        mkdir("{$this->root}/database");
        $db = new \PDO("sqlite:{$this->root}/database/app.db");
        $db->exec('CREATE TABLE authcontrol (id INTEGER PRIMARY KEY, control TEXT, method TEXT, level INTEGER)');
        $db->exec("INSERT INTO authcontrol (control, method, level) VALUES ('cafe','*',100), ('admin','*',50), ('auth','login',101), ('ghosts','*',1)");
        $db->exec('CREATE TABLE member (id INTEGER PRIMARY KEY, email TEXT, password TEXT)');
        $db->exec("INSERT INTO member (email, password) VALUES ('a@b.test', '\$2y\$10\$abcdefghijklmnopqrstuv')");
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function graph(): array {
        $g = (new CallGraph(new Introspector($this->root)))->build();
        $g['byId'] = array_column($g['nodes'], null, 'id');
        return $g;
    }

    private function edges(array $g, string $from, string $to): array {
        return array_values(array_filter($g['edges'], fn($e) => $e['from'] === $from && $e['to'] === $to));
    }

    public function testEveryNodeSaysWhoseCodeItIs(): void {
        $g = $this->graph();
        $this->assertSame('app', $g['byId']['route:cafe::index']['origin']);
        $this->assertSame('platform', $g['byId']['route:auth::login']['origin']);
        $this->assertSame('platform', $g['byId']['lib:Mailer']['origin']);
        $this->assertSame('platform', $g['byId']['libmethod:Mailer::send']['origin']);
        $this->assertSame('app', $g['byId']['view:views/cafe/index.php']['origin']);
        $this->assertSame(['app' => 5, 'platform' => 8, 'shared' => 2], $g['meta']['origins']);
    }

    public function testAPlatformRouteHasItsViewsAndLibrariesAttached(): void {
        $g = $this->graph();
        $renders = $this->edges($g, 'route:auth::login', 'view:views/auth/login.php');
        $this->assertCount(1, $renders, 'the runtime controller\'s body was read');
        $this->assertSame('platform', $renders[0]['origin']);
        $this->assertSame('vendor/tiknix/runtime/controls/Auth.php:7', $renders[0]['evidence']);
        $this->assertSame('vendor/tiknix/runtime/views/auth/login.php', $g['byId']['view:views/auth/login.php']['path']);
        $this->assertCount(1, $this->edges($g, 'route:auth::login', 'libmethod:Mailer::send'));
        $this->assertCount(1, $this->edges($g, 'route:auth::login', 'bean:member'));
        // …and its layout, which the runtime provides.
        $this->assertCount(1, $this->edges($g, 'route:auth::login', 'view:views/layouts/header.php'));
        // The app's code calling into the platform is the app's edge.
        $this->assertSame('app', $this->edges($g, 'route:cafe::index', 'libmethod:Mailer::send')[0]['origin']);
    }

    public function testAnOverrideIsReadOnceTheAppsCopy(): void {
        $intro = new Introspector($this->root);
        $admin = $intro->controllerList()['admin'];
        $this->assertSame('controls/Admin.php', $admin['path']);
        $this->assertSame('app', $admin['origin']);
        $this->assertSame(['index'], array_keys($admin['methods']), 'the runtime copy\'s methods are not routes of this app');

        $g = $this->graph();
        $this->assertArrayNotHasKey('bean:ghost', $g['byId'], 'the overridden runtime file was read');
        $this->assertArrayNotHasKey('route:admin::members', $g['byId']);
        // The app's controller renders a view only the runtime has: it resolves, to the runtime's file.
        $view = $g['byId']['view:views/admin/index.php'];
        $this->assertSame('view', $view['kind']);
        $this->assertSame('platform', $view['origin']);
        $this->assertSame('vendor/tiknix/runtime/views/admin/index.php', $view['path']);
    }

    public function testOnlyTheAppsOwnProblemsAreCountedAsItsProblems(): void {
        $g = $this->graph();
        // Nothing points at cafe::lonely (the app's) or auth::unreached (the platform's).
        $this->assertSame(['route:cafe::lonely'], $g['meta']['orphans']);
        $this->assertSame(1, $g['meta']['platformOrphans']);
        $this->assertSame('orphan', $g['byId']['route:auth::unreached']['reach']);
        // /cafe/nosuchpage is linked from the app's view; /auth/nosuchaction from the runtime's.
        $this->assertSame(1, $g['meta']['broken']);
        $this->assertSame(1, $g['meta']['platformBroken']);
        $this->assertSame('app', $g['byId']['broken:/cafe/nosuchpage']['origin']);
        $this->assertSame('platform', $g['byId']['broken:/auth/nosuchaction']['origin']);
    }

    public function testTheModelListsTheAppsControllersFirstAndSaysWhatItCouldNotRead(): void {
        $m = (new Architecture($this->root))->model();
        $this->assertSame([['Admin', 'app'], ['Cafe', 'app'], ['Auth', 'platform'], ['ghosts', 'none']],
            array_map(fn($c) => [$c['control'], $c['origin']], $m['controls']));
        $this->assertFalse($m['controls'][3]['hasController'], 'permission rows with no controller behind them');
        $this->assertSame(['authcontrol', 'member'], $m['tables']);
        // No settings table means no plugin was ever switched on — not a fault.
        $this->assertSame([], $m['problems']);
        $this->assertSame(1, $m['meta']['orphanCount']);
        $this->assertSame(1, $m['meta']['platformOrphans']);
        $this->assertSame(Architecture::VERSION, $m['meta']['version']);
    }

    public function testAControllersMethodsComeFromItsCodeWithTheLevelThatApplies(): void {
        $by = array_column((new Architecture($this->root))->model()['controls'], null, 'control');
        // cafe::* = 100 is one rule; the controller has two methods, both covered by it.
        $this->assertSame([
            ['method' => 'index', 'level' => 100, 'rule' => 'wildcard', 'exists' => true],
            ['method' => 'lonely', 'level' => 100, 'rule' => 'wildcard', 'exists' => true],
        ], $by['Cafe']['methods']);
        $this->assertSame('MEMBER', $by['Cafe']['levelLabel']);
        // auth::login has its own row; auth::unreached has no rule at all.
        $this->assertSame([
            ['method' => 'unreached', 'level' => null, 'rule' => 'none', 'exists' => true],
            ['method' => 'login', 'level' => 101, 'rule' => 'own', 'exists' => true],
        ], $by['Auth']['methods']);
        $this->assertSame(2, $by['Auth']['routeCount']);
        // A rule for a controller that is not there keeps its rows, marked.
        $this->assertSame([], $by['ghosts']['methods']);
        $this->assertSame(1, $by['ghosts']['wildcard']);
    }

    public function testRowsWithholdCredentialColumns(): void {
        $r = (new Architecture($this->root))->rows('member');
        $this->assertSame(['password'], $r['redacted']);
        $this->assertSame(1, $r['total']);
        $this->assertSame('a@b.test', $r['rows'][0]['email']);
        $this->assertSame(\app\Redact::MARK, $r['rows'][0]['password']);
        $this->expectException(\InvalidArgumentException::class);
        (new Architecture($this->root))->rows('sqlite_master');
    }

    public function testTheHashFollowsTheCodeAndThePermissionTable(): void {
        $h = (new Architecture($this->root))->hash();
        $this->assertSame($h, (new Architecture($this->root))->hash());

        touch("{$this->root}/vendor/tiknix/runtime/lib/Mailer.php", time() + 60);   // a runtime update
        clearstatcache();
        $h2 = (new Architecture($this->root))->hash();
        $this->assertNotSame($h, $h2);

        (new \PDO("sqlite:{$this->root}/database/app.db"))->exec("UPDATE authcontrol SET level = 101 WHERE control = 'cafe'");
        $this->assertNotSame($h2, (new Architecture($this->root))->hash());
    }
}
