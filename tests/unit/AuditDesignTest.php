<?php
/**
 * The audit also LOOKS at each page as its user (the manifest's `design` list). What it saw is
 * said on the plan — and is never a failure: a page that works but reads badly passes.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\AuditReporter;

class AuditDesignTest extends TestCase {
    public function testWhatTheAuditSawIsSaidAndPagesThatReadFineAreOnlyCounted(): void {
        $web = fn(string $p) => '/web/' . $p;
        $this->assertSame([], AuditReporter::designLines(['passed' => true], $web), 'an audit from before the design pass says nothing');
        $fine = implode("\n", AuditReporter::designLines(['design' => [['page' => '/a', 'verdict' => 'ok', 'notes' => []]]], $web));
        $this->assertStringContainsString('nothing to note', $fine);
        $md = implode("\n", AuditReporter::designLines(['design' => [
            ['page' => '/leads', 'level' => 'admin', 'verdict' => 'needs work', 'notes' => ['three filled buttons in the header', 'the table scrolls sideways on a phone'], 'screens' => ['public/uploads/audit/9/x.png']],
            ['page' => '/leads/edit', 'verdict' => 'ok', 'notes' => []],
            ['page' => '/vague', 'verdict' => 'needs work', 'notes' => []],
        ]], $web));
        $this->assertStringContainsString('Looked at 3 pages', $md);
        $this->assertStringContainsString('**`/leads`** (admin) — three filled buttons in the header; the table scrolls sideways on a phone', $md);
        $this->assertStringContainsString('![screenshot](/web/public/uploads/audit/9/x.png)', $md);
        $this->assertStringNotContainsString('/leads/edit', $md);
        $this->assertStringNotContainsString('/vague', $md, 'a verdict with nothing seen is not reported');
    }
}
