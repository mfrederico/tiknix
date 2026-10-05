<?php
/**
 * PageLinks: a page of the app that nothing links to is found, and a linked or deliberately
 * unlinked one is not. (Run against the sixteen apps on 2026-10-05 it found eight — six of them
 * features nobody could reach by clicking.)
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\PageLinks;
use app\ValidationService;

class PageLinksTest extends TestCase {
    private string $root;
    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-pagelinks-' . bin2hex(random_bytes(4));
        foreach (['controls', 'views/cafe', 'views/orders', 'views/app', 'lib'] as $d) mkdir("{$this->root}/{$d}", 0700, true);
    }
    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->root)); }
    private function put(string $rel, string $body): void { file_put_contents("{$this->root}/{$rel}", $body); }
    private function ctl(string $name, string $extra = ''): void {
        $this->put("controls/{$name}.php", "<?php\nnamespace app;\n{$extra}\nclass {$name} extends BaseControls\\Control {\n    public function index() { \$this->render('" . strtolower($name) . "/index'); }\n}\n");
    }
    private function routes(?array $only = null): array { return array_column(PageLinks::unlinked($this->root, $only), 'route'); }

    public function testAPageLinkedOnlyFromItsOwnViewsIsUnlinked(): void {
        $this->ctl('Cafe');
        $this->put('views/cafe/index.php', '<a href="/cafe/create">New</a> <a href="/cafe">Back to the list</a>');
        $this->assertSame(['/cafe'], $this->routes(), 'its own views linking to each other is how an unreachable page looks from inside');
        $msg = PageLinks::unlinked($this->root)[0]['message'];
        $this->assertStringContainsString('controls/Cafe.php serves /cafe', $msg);
        $this->assertStringContainsString('nav: none', $msg, 'the message names the way out for a deliberate one');
    }

    public function testTheSidebarAnotherPageOrAppPhpLinkIt(): void {
        $this->ctl('Cafe'); $this->ctl('Orders');
        $this->put('views/app/nav.php', "<?php \$__sections['Main'][] = ['url' => '/cafe', 'label' => 'Cafe', 'icon' => 'cup'];");
        $this->assertSame(['/orders'], $this->routes());
        $this->put('views/cafe/index.php', '<a href="/orders?cafe=1">Orders</a>');
        $this->assertSame([], $this->routes(), 'linked from the page it belongs under');
        $this->assertSame([], $this->routes(['controls/Orders.php']));
    }

    public function testAnAddressIsMatchedAsAnAddress(): void {
        $this->assertTrue(PageLinks::mentions('<a href="/cafe">', '/cafe'));
        $this->assertTrue(PageLinks::mentions("['url' => '/cafe/customers']", '/cafe'));
        $this->assertTrue(PageLinks::mentions('Flight::redirect("/cafe?x=1")', '/cafe'));
        $this->assertFalse(PageLinks::mentions('<a href="/cafeteria">', '/cafe'), 'a longer name is another page');
        $this->assertFalse(PageLinks::mentions('<a href="/admin/cafe">', '/cafe'), 'under another controller is another page');
        $this->assertFalse(PageLinks::mentions('img src="x/cafe.png"', '/cafe'));
    }

    public function testDeliberatelyUnlinkedTheHomePageAndPagelessControllersPass(): void {
        $this->ctl('Hook', '// nav: none — a payment provider posts here');
        $this->ctl('Home');
        $this->put('lib/app.php', "<?php\n\\app\\Index::\$home = [\\app\\Home::class, 'index'];\n");
        $this->put('controls/Api.php', "<?php\nnamespace app;\nclass Api { public function list() {} }\n");
        $this->assertSame([], $this->routes());
    }

    public function testValidatingAControllerByNameFailsItAndASweepWarns(): void {
        $this->ctl('Cafe');
        $v = new ValidationService($this->root);
        $one = $v->fullValidation("{$this->root}/controls/Cafe.php");
        $this->assertFalse($one['valid']);
        $this->assertNotEmpty(preg_grep('#serves /cafe and nothing links to it#', $one['errors']));
        $all = $v->fullValidation("{$this->root}/controls");
        $this->assertNotEmpty(preg_grep('#serves /cafe and nothing links to it#', $all['warnings']), 'found by a sweep: said, not failed');
        $this->assertEmpty(preg_grep('#nothing links to it#', $all['errors']));
    }
}
