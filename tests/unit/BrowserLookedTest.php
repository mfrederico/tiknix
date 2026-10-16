<?php
/**
 * SandboxCheck::browserMissed — a task that changed what a person sees while a browser was lent
 * is not merged until that browser (any address but this machine's) opened each page whose view
 * changed, or the agent said on a `Browser:` line why it could not. What the sandbox served is the
 * evidence; curl from this machine does not count.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\SandboxCheck;

class BrowserLookedTest extends TestCase {
    private string $wt;
    private const HOST = '10.10.10.116';

    protected function setUp(): void {
        $this->wt = sys_get_temp_dir() . '/tiknix-browserlooked-' . bin2hex(random_bytes(4));
        foreach (['controls', 'views/cafe', 'public/css', '.aibuilder'] as $d) mkdir("{$this->wt}/{$d}", 0700, true);
        file_put_contents("{$this->wt}/controls/Cafe.php", "<?php\nnamespace app;\nclass Cafe {\n  public function customers() { \$this->render('cafe/customers'); }\n  public function orders() { \$this->render('cafe/orders'); }\n}\n");
        $this->git('init -q -b main'); $this->git('add -A'); $this->git('-c user.email=t@t -c user.name=t commit -q -m base');
        $this->facts('http://' . self::HOST . ':41500');
    }
    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->wt)); }

    private function git(string $args): void { exec('cd ' . escapeshellarg($this->wt) . ' && env -u GIT_DIR -u GIT_INDEX_FILE git ' . $args . ' 2>&1'); }
    private function facts(string $url): void {
        exec('cd ' . escapeshellarg($this->wt) . ' && env -u GIT_DIR -u GIT_INDEX_FILE git rev-parse HEAD', $o);
        file_put_contents("{$this->wt}/.aibuilder/sandbox.json", json_encode(['url' => $url, 'base' => trim($o[0]), 'people' => []]));
    }
    private function served(string $ip, string $path): void { file_put_contents("{$this->wt}/.aibuilder/" . SandboxCheck::REQUESTS, "{$ip}\tGET\t{$path}\n", FILE_APPEND); }

    public function testAChangedPageMustBeOpenedByTheLentBrowser(): void {
        file_put_contents("{$this->wt}/views/cafe/customers.php", '<p>new</p>');
        $why = SandboxCheck::browserMissed($this->wt, 'done');
        $this->assertStringContainsString('never opened /cafe/customers', $why);
        $this->assertStringNotContainsString('/cafe/orders', $why, 'only the page whose view changed');
        $this->served('127.0.0.1', '/cafe/customers');
        $this->served(self::HOST, '/cafe/customers');
        $this->assertNotSame('', SandboxCheck::browserMissed($this->wt, 'done'), 'curl from this machine is not the browser');
        $this->served('10.10.10.118', '/cafe/customers/');
        $this->assertSame('', SandboxCheck::browserMissed($this->wt, 'done'));
    }

    public function testAStylesheetNeedsSomePageOpenedAndABrowserLineIsTheWayOut(): void {
        file_put_contents("{$this->wt}/public/css/app.css", 'body{}');
        $this->assertStringContainsString('any page that shows the change', SandboxCheck::browserMissed($this->wt, 'done'));
        $this->assertSame('', SandboxCheck::browserMissed($this->wt, "## Handoff\n- Browser: the playwright tools were not offered to this run"));
        $this->assertNotSame('', SandboxCheck::browserMissed($this->wt, "Browser: ok"), 'a reason, not a word');
        $this->served('10.10.10.118', '/');
        $this->assertSame('', SandboxCheck::browserMissed($this->wt, 'done'));
    }

    public function testNoBrowserLentOrNothingVisibleChangedAsksNothing(): void {
        file_put_contents("{$this->wt}/controls/Cafe.php", "<?php\n// logic only\n", FILE_APPEND);
        $this->assertSame('', SandboxCheck::browserMissed($this->wt, 'done'), 'a controller is not what a person sees');
        file_put_contents("{$this->wt}/views/cafe/customers.php", '<p>new</p>');
        $this->facts('http://127.0.0.1:39000');
        $this->assertSame('', SandboxCheck::browserMissed($this->wt, 'done'), 'no browser was lent: curl and "Not checked" are all there is');
    }
}
