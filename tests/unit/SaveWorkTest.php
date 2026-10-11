<?php
/**
 * Work done in an app by hand saves itself: app\TenantHost::saveScript commits what is
 * uncommitted as one commit by the member (the Terminal page calls it when the files go quiet).
 * Real git, in a scratch repository.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\TenantHost;

class SaveWorkTest extends TestCase {
    private string $root;

    private function sh(string $cmd): array {
        exec('env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE bash -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-savework-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        $this->sh('cd ' . escapeshellarg($this->root) . ' && git init -q -b main && echo one > a.php && echo "secret.ini" > .gitignore && git add -A && git -c user.name=T -c user.email=t@example.test commit -q -m base');
    }

    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->root)); }

    private function save(): array {
        $git = TenantHost::gitAs(['name' => 'Ana Ruiz', 'email' => 'ana@example.test']);
        $this->assertNotNull($git);
        return $this->sh(TenantHost::saveScript($git, $this->root));
    }

    public function testNothingChangedSavesNothing(): void {
        [$code, $out] = $this->save();
        $this->assertSame([0, 'FILES=0'], [$code, trim($out)]);
    }

    public function testChangedAndNewFilesBecomeOneCommitByTheMember(): void {
        file_put_contents("{$this->root}/a.php", "two\n");
        foreach (['b.php', 'c.php', 'd.php', 'e.php'] as $f) file_put_contents("{$this->root}/{$f}", "new\n");
        file_put_contents("{$this->root}/secret.ini", "key=1\n");   // ignored: never saved
        [$code, $out] = $this->save();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('FILES=5', $out);
        $this->assertMatchesRegularExpression('/^COMMIT=[0-9a-f]{7,}$/m', $out);
        [, $log] = $this->sh('cd ' . escapeshellarg($this->root) . ' && git log -1 --format="%an <%ae> | %s" && git status --porcelain && git ls-files | tr "\n" " "');
        $this->assertStringContainsString('Ana Ruiz <ana@example.test> | Work in the terminal: a.php, b.php, c.php and 2 more', $log);
        $this->assertStringNotContainsString('secret.ini', $log);
        [, $again] = $this->save();
        $this->assertSame('FILES=0', trim($again), 'saved once: nothing is left');
    }

    public function testAMergeLeftOpenByHandIsNotTouched(): void {
        $r = escapeshellarg($this->root);
        $this->sh("cd {$r} && git checkout -q -b other && echo theirs > a.php && git -c user.name=T -c user.email=t@example.test commit -qam other && git checkout -q main && echo ours > a.php && git -c user.name=T -c user.email=t@example.test commit -qam ours && (git -c user.name=T -c user.email=t@example.test merge other || true)");
        $this->assertFileExists("{$this->root}/.git/MERGE_HEAD");
        [$code, $out] = $this->save();
        $this->assertSame(4, $code);
        $this->assertStringContainsString('left alone', $out);
        $this->assertFileExists("{$this->root}/.git/MERGE_HEAD");
    }
}
