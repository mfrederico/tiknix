<?php
/**
 * What a build task no longer works out for itself (app\SandboxCheck), and the traps closed with
 * it: which pages a change is asked for, an agenda item with no end time, a field's keyboard and
 * help link, and the two things the edit hook now refuses (a lib class named like a controller,
 * a flash message nobody is shown).
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\SandboxCheck;
use app\Ui;

class SandboxCheckTest extends TestCase {
    private string $root;
    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-sandboxcheck-' . bin2hex(random_bytes(4));
        foreach (['controls', 'views/cafe', 'lib'] as $d) mkdir("{$this->root}/{$d}", 0700, true);
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->root)); }

    private function hook(string $tool, string $path, string $code): string {
        $in = json_encode(['tool_name' => $tool, 'tool_input' => ['file_path' => $path, $tool === 'Write' ? 'content' : 'new_string' => $code]]);
        $p = proc_open([PHP_BINARY, \app\Paths::runtime() . '/bin/hooks/validate-tiknix-php.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $in); fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); proc_close($p);
        return (string) $out;
    }

    public function testThePagesAskedForAreTheOnesTheChangeCanBreak(): void {
        file_put_contents("{$this->root}/controls/Cafe.php", "<?php\nnamespace app;\nclass Cafe extends BaseControls\\Control {\n"
            . "  public function index() { \$this->render('cafe/index', []); }\n"
            . "  public function customers() { \$this->render('cafe/customers', []); }\n"
            . "  public function delete() { \$this->validateCSRF(); \\Flight::redirect('/cafe'); }\n"
            . "  private function helper() { \$this->render('x'); }\n}\n");
        file_put_contents("{$this->root}/controls/Orders.php", "<?php\nnamespace app;\nclass Orders extends BaseControls\\Control {\n  public function index() { \$this->render('orders/index', []); }\n  public function view() { \$this->render('orders/view', []); }\n}\n");
        $pages = SandboxCheck::pages($this->root, ['controls/Cafe.php', 'views/orders/view.php', 'views/orders/_row.php', 'views/layouts/header.php', 'lib/Thing.php']);
        $this->assertSame(['/', '/dashboard', '/cafe', '/cafe/customers', '/orders/view'], $pages,
            'the home page and dashboard always; a changed controller\'s pages but not its POST handler; a changed view\'s page but not a partial');
    }

    public function testATaskThatInstallsABrowserIsNamed(): void {
        mkdir("{$this->root}/scripts"); mkdir("{$this->root}/agent/guidelines", 0700, true);
        file_put_contents("{$this->root}/scripts/qa-setup.sh", "#!/bin/sh\npython3 -m venv .qa/venv\n.qa/venv/bin/pip install playwright\n.qa/venv/bin/playwright install chromium\n");
        file_put_contents("{$this->root}/package.json", '{"devDependencies": {"@playwright/test": "^1.50.0"}}');
        file_put_contents("{$this->root}/agent/guidelines/185-qa.md", "Never install Playwright here: `pip install playwright` is refused.\n");
        file_put_contents("{$this->root}/lib/Report.php", "<?php // the playwright tools are lent; nothing to install\n");
        $found = SandboxCheck::browserInstalls($this->root, ['scripts/qa-setup.sh', 'package.json', 'agent/guidelines/185-qa.md', 'lib/Report.php']);
        $this->assertCount(2, $found, 'the script and the package list — not the guideline that says never, nor code that only names the tools');
        $this->assertStringContainsString('scripts/qa-setup.sh installs a browser', $found[0]);
        $this->assertStringContainsString('package.json adds @playwright/test', $found[1]);
    }

    public function testTheCheckSaysSoWhenThereIsNoSandbox(): void {
        $this->expectExceptionMessageMatches('/no running sandbox/');
        SandboxCheck::run($this->root);
    }

    public function testAnAgendaItemWithNoEndTimeShowsItsStartAlone(): void {
        $h = Ui::agenda(['items' => [['t' => 'Open mic', 'at' => '2030-05-01 19:00', 'end' => null], ['t' => 'Class', 'at' => '2030-05-01 09:00', 'end' => '2030-05-01 09:45']],
            'at' => 'at', 'until' => 'end', 'title' => 't', 'empty' => ['title' => 'Nothing booked yet']]);
        $this->assertStringContainsString('9:45 am', $h);
        $this->assertSame(1, substr_count($h, 'ui-until'), 'one item has an end, one does not');
    }

    public function testAFieldSaysWhichKeyboardAndCarriesALinkAfterItsHelp(): void {
        $h = Ui::form(['action' => '/signin/code', 'submit' => 'Sign me in', 'fields' => [
            ['name' => 'code', 'label' => 'Your code', 'required' => true, 'inputmode' => 'numeric', 'autocomplete' => 'one-time-code',
             'help' => 'Six digits, sent a moment ago.', 'help_link' => ['label' => 'Send <another>', 'url' => '/signin?again=1&x=2']]]]);
        $this->assertStringContainsString('inputmode="numeric"', $h);
        $this->assertStringContainsString('autocomplete="one-time-code"', $h);
        $this->assertStringContainsString('<a href="/signin?again=1&amp;x=2">Send &lt;another&gt;</a>', $h);
        try { Ui::form(['action' => '/x', 'submit' => 'Save it', 'fields' => [['name' => 'a', 'label' => 'A', 'help_link' => ['label' => 'Why', 'url' => '/why']]]]); $this->fail('a link with no sentence was accepted'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString("follows a 'help' sentence", $e->getMessage()); }
    }

    public function testTheHookRefusesALibClassNamedLikeAController(): void {
        file_put_contents("{$this->root}/controls/Feed.php", "<?php\n");
        $out = $this->hook('Write', "{$this->root}/lib/Feed.php", "<?php\nnamespace app;\nclass Feed {}\n");
        $this->assertStringContainsString('"decision":"block"', $out);
        $this->assertStringContainsString('only one of them can load', $out);
        $this->assertSame('', $this->hook('Write', "{$this->root}/lib/FeedStream.php", "<?php\nnamespace app;\nclass FeedStream {}\n"));
    }

    public function testTheHookRefusesAFlashMessageNobodyIsShown(): void {
        $key = "['flash_" . "error']";   // in two pieces: this file passes through the same hook
        $out = $this->hook('Edit', "{$this->root}/controls/Cafe.php", "\$_SESSION{$key} = 'Could not save';\n\\Flight::redirect('/cafe');");
        $this->assertStringContainsString('is never shown', $out);
        $this->assertSame('', $this->hook('Edit', "{$this->root}/controls/Cafe.php", "// not \$_SESSION{$key} = …\n\$this->flash('error', 'Could not save');"),
            'a comment that names the old way is not the old way');
    }
}
