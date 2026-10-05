<?php
/**
 * A build task's brief opens with what the tasks it depends on built (PlanExecutor::priorWorkSection):
 * their files and their agent's closing summary — so it builds on them instead of rediscovering them.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\PlanExecutor;

class PriorWorkBriefTest extends TestCase {
    public function testNothingFinishedBeforeItMeansNoSection(): void {
        $this->assertSame('', PlanExecutor::priorWorkSection([]));
    }
    public function testEachDependencyIsNamedWithItsFilesAndTheEndOfItsSummary(): void {
        $long = str_repeat('early detail. ', 200) . 'FINAL: Vaults::open($member) returns a VaultHandle; call it before any read.';
        $md = PlanExecutor::priorWorkSection([
            ['id' => 2, 'title' => 'Vault core', 'files' => ['lib/Vaults.php', 'controls/Vault.php'], 'summary' => $long],
            ['id' => 4, 'title' => 'Profiles', 'files' => [], 'summary' => ''],
        ]);
        $this->assertStringContainsString('## Already built — the tasks this one depends on', $md);
        $this->assertStringContainsString('### #2 Vault core', $md);
        $this->assertStringContainsString('Files: `lib/Vaults.php`, `controls/Vault.php`', $md);
        $this->assertStringContainsString('FINAL: Vaults::open($member) returns a VaultHandle', $md, 'the end of a summary is where it says what it made');
        $this->assertStringContainsString('### #4 Profiles', $md, 'a dependency with no log is still named');
        $this->assertLessThan(3200, mb_strlen($md), 'a long summary is cut to its end');
    }
    public function testItIsBounded(): void {
        $many = [];
        for ($i = 1; $i <= 30; $i++) $many[] = ['id' => $i, 'title' => "Task {$i}", 'files' => ['a.php'], 'summary' => str_repeat('x ', 800)];
        $md = PlanExecutor::priorWorkSection($many);
        $this->assertLessThan(8000, mb_strlen($md));
        $this->assertStringContainsString('the rest are in the code', $md);
    }
    public function testTheHandoffSectionIsWhatIsHandedOn(): void {
        $out = "## Done\nBuilt the vault.\n\n## Not checked\n- mail\n\n## Handoff\n- `Vaults::open(\$member)` returns a VaultHandle\n- link to `/vault`\n(Not an error: Claude Code has no catalog entry for this model, so it assumes a 200k-token context window.)";
        $this->assertSame("- `Vaults::open(\$member)` returns a VaultHandle\n- link to `/vault`", PlanExecutor::handoffOf($out));
        $this->assertSame("Built it.\n\nNothing unchecked.", PlanExecutor::handoffOf("Built it.\n\nNothing unchecked.\n"), 'no Handoff section: the message itself');
        $src = file_get_contents(dirname(__DIR__, 2) . '/lib/PlanExecutor.php');
        $this->assertStringContainsString('section headed exactly `## Handoff`', $src, 'the brief asks for what priorWork reads');
    }
}
