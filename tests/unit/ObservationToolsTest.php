<?php
/**
 * Observation tools (COMPONENTS_PLAN.md): the parts that decide what may leave the install.
 *
 *   LogReader     — entries not lines, level and text filters, the newest error with its
 *                   context, a file argument that can never leave log/, secrets scrubbed
 *   Redact        — credential shapes replaced visibly; credential columns withheld by NAME
 *   database_query guard — one read-only statement, row cap by wrapping, every refusal named
 *   StdioAllowList — the four read-only tools are offered to the jailed agent; database_query is not
 */

namespace tests\unit;

use app\LogReader;
use app\Redact;
use app\mcptools\DatabaseQueryTool;
use app\mcptools\StdioAllowList;
use PHPUnit\Framework\TestCase;

class ObservationToolsTest extends TestCase {

    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/tiknix-logtest-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/app-2026-09-21.log',
            "[2026-09-21 09:00:00] app.INFO: Calling: \\app\\Home->index [] []\n"
          . "[2026-09-21 09:00:01] app.ERROR: old failure {\"where\":\"yesterday\"} []\n");
        file_put_contents($this->dir . '/app-2026-09-22.log',
            "[2026-09-22 10:00:00] app.INFO: Calling: \\app\\Auth->login [] []\n"
          . "[2026-09-22 10:00:01] app.DEBUG: session started [] []\n"
          . "[2026-09-22 10:00:02] app.WARNING: slow query {\"ms\":900} []\n"
          . "[2026-09-22 10:00:03] app.INFO: Stripe call with sk_live_ABCDEFGH12345678 in it [] []\n"
          . "[2026-09-22 10:00:04] app.ERROR: Exception: boom {\"file\":\"controls/X.php\",\"line\":7}\n"
          . "#0 /var/www/x.php(1): a()\n"
          . "#1 {main}\n"
          . "[2026-09-22 10:00:05] app.INFO: Calling: \\app\\Home->index [] []\n");
        // not a log file: must never be listed or opened
        file_put_contents($this->dir . '/config.ini', "[security]\napp_key = \"deadbeef\"\n");
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    public function testFilesAreTheRotatingPatternNewestFirst(): void {
        $r = new LogReader($this->dir);
        $this->assertSame(['app-2026-09-22.log', 'app-2026-09-21.log'], $r->files());
    }

    public function testEntriesKeepStackTracesWithTheirEntry(): void {
        $r = new LogReader($this->dir);
        $all = $r->parse('app-2026-09-22.log');
        $this->assertCount(6, $all, 'continuation lines are not entries');
        $err = $all[4];
        $this->assertSame('ERROR', $err['level']);
        $this->assertSame('Exception: boom', $err['message']);
        $this->assertStringContainsString('"line":7', $err['extra']);
        $this->assertStringContainsString('#1 {main}', $err['extra']);
    }

    public function testLevelAndTextFilters(): void {
        $r = new LogReader($this->dir);
        $warnUp = $r->entries('app-2026-09-22.log', 50, 'WARNING');
        $this->assertSame(['WARNING', 'ERROR'], array_column($warnUp, 'level'));
        $calls = $r->entries('app-2026-09-22.log', 50, null, 'calling:');
        $this->assertCount(2, $calls, 'contains is case-insensitive');
        $last1 = $r->entries('app-2026-09-22.log', 1);
        $this->assertSame('10:00:05', substr($last1[0]['time'], 11), 'count takes from the end');
        $this->expectException(\InvalidArgumentException::class);
        $r->entries('app-2026-09-22.log', 5, 'LOUD');
    }

    public function testLastErrorIsTheNewestWithItsContext(): void {
        $r = new LogReader($this->dir);
        $found = $r->lastError(2);
        $this->assertSame('app-2026-09-22.log', $found['file']);
        $this->assertSame('Exception: boom', $found['entry']['message']);
        $this->assertSame(['WARNING', 'INFO'], array_column($found['before'], 'level'), 'the two entries before it, in order');
    }

    public function testLastErrorFallsBackToOlderFiles(): void {
        unlink($this->dir . '/app-2026-09-22.log');
        file_put_contents($this->dir . '/app-2026-09-22.log', "[2026-09-22 10:00:00] app.INFO: quiet day [] []\n");
        $found = (new LogReader($this->dir))->lastError();
        $this->assertSame('app-2026-09-21.log', $found['file']);
        $this->assertSame('old failure', $found['entry']['message']);
    }

    public function testSecretsAreScrubbedFromEntries(): void {
        $r = new LogReader($this->dir);
        $e = $r->entries('app-2026-09-22.log', 50, null, 'Stripe');
        $this->assertCount(1, $e);
        $this->assertStringNotContainsString('sk_live_', $e[0]['message']);
        $this->assertStringContainsString('[redacted Stripe key]', $e[0]['message']);
    }

    public function testAFileArgumentCanNeverLeaveTheLogDirectory(): void {
        $r = new LogReader($this->dir);
        foreach (['../conf/config.ini', 'config.ini', '/etc/passwd', 'app-2026-09-22.log/../config.ini'] as $bad) {
            try {
                $r->resolve($bad);
                $this->fail("resolve('{$bad}') should have refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not a log file name', $e->getMessage());
            }
        }
        $this->assertNull($r->resolve('app-2000-01-01.log'), 'a well-formed name for a file that does not exist is null, not an error');
        $this->assertSame('app-2026-09-22.log', $r->resolve(null), 'no name = newest');
    }

    public function testRedactWithholdsCredentialColumnsByName(): void {
        $row = ['id' => 3, 'email' => 'a@b.c', 'password_hash' => '$2y$abc', 'token' => 'tk_1234567890abcdef12', 'api_key_enc' => 'zzz', 'note' => 'key brk_ABCDEFGHIJKLMNOP here', 'totp_secret' => '', 'recovery_codes' => null];
        $out = Redact::row($row);
        $this->assertSame(3, $out['id']);
        $this->assertSame('a@b.c', $out['email']);
        $this->assertSame(Redact::MARK, $out['password_hash']);
        $this->assertSame(Redact::MARK, $out['token']);
        $this->assertSame(Redact::MARK, $out['api_key_enc']);
        $this->assertSame('', $out['totp_secret'], 'an empty secret stays empty: absent is information');
        $this->assertNull($out['recovery_codes']);
        $this->assertStringContainsString('[redacted broker key]', $out['note'], 'a credential shape in an ordinary column is scrubbed');
    }

    /** @dataProvider refusedStatements */
    public function testTheQueryGuardRefusesAndSaysWhy(string $sql, string $why): void {
        try {
            DatabaseQueryTool::guard($sql);
            $this->fail("guard should have refused: {$sql}");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }
    }

    public function refusedStatements(): array {
        return [
            ['DELETE FROM member', 'DELETE'],
            ['SELECT 1; DROP TABLE member', 'One statement'],
            ['SELECT 1 -- x', 'Comments'],
            ['SELECT /* x */ 1', 'Comments'],
            ['PRAGMA journal_mode = wal', 'read-only PRAGMAs'],
            ['PRAGMA foreign_keys(1)', 'read-only PRAGMAs'],
            ['WITH t AS (SELECT 1) INSERT INTO member (email) SELECT 1', 'INSERT'],
            ['UPDATE member SET level = 1', 'UPDATE'],
            ['ATTACH DATABASE \'/x\' AS y', 'ATTACH'],
            ['SHOW TABLES', 'must start with'],
            ['', 'Empty'],
        ];
    }

    public function testTheQueryGuardWrapsSelectsForTheRowCap(): void {
        [$sql, $wrapped] = DatabaseQueryTool::guard('SELECT id FROM member ORDER BY id LIMIT 5000;');
        $this->assertTrue($wrapped);
        $this->assertSame('SELECT * FROM (SELECT id FROM member ORDER BY id LIMIT 5000) LIMIT ' . (DatabaseQueryTool::MAX_ROWS + 1), $sql);
        [$sql, $wrapped] = DatabaseQueryTool::guard('PRAGMA table_info(member)');
        $this->assertFalse($wrapped);
        [$sql, $wrapped] = DatabaseQueryTool::guard('EXPLAIN SELECT 1');
        $this->assertFalse($wrapped);
        [$sql] = DatabaseQueryTool::guard('WITH t AS (SELECT 1 AS n) SELECT n FROM t');
        $this->assertStringStartsWith('SELECT * FROM (WITH', $sql);
    }

    public function testStdioOffersTheReadOnlyFourAndNotTheQuery(): void {
        $names = StdioAllowList::names();
        foreach (['last_error', 'read_log_entries', 'database_schema', 'application_info'] as $n) $this->assertContains($n, $names);
        $this->assertNotContains('database_query', $names, 'rows need an identity: HTTP + ADMIN only');
    }
}
