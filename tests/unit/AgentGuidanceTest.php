<?php
/**
 * CLAUDE.md is generated (COMPONENTS_PLAN.md, "Concept guidance"): core's agent/guidelines/
 * sections plus each enabled concept's guidelines.md, in one managed block, under whatever
 * preamble the install keeps above it.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\AgentGuidance as G;

class AgentGuidanceTest extends ConceptsTestCase {

    private function core(array $sections = ['010-a.md' => "## A\n\nalpha\n", '020-b.md' => "## B\n\nbeta\n"]): void {
        foreach ($sections as $f => $body) $this->put("{$this->root}/agent/guidelines/{$f}", $body);
    }

    private function claude(): string { return (string) file_get_contents("{$this->root}/CLAUDE.md"); }

    private function concept_(string $name, ?string $guidelines = '', string $version = '1.2.3'): array {
        $dir = $this->concept($name);
        if ($guidelines === '') $guidelines = "### Using {$name}\n\nDo the thing.";
        if ($guidelines !== null) $this->put("{$dir}/guidelines.md", $guidelines);
        return [$name => ['version' => $version, 'dir' => $dir]];
    }

    /* ---- the drift guard: core's real file IS the generated file ---- */

    public function testCoresClaudeMdIsExactlyWhatComposeProduces(): void {
        $core = dirname(__DIR__, 2);
        $c = G::compose($core, []);
        $this->assertFalse($c['migrated'], 'core carries the markers');
        $this->assertSame(file_get_contents("{$core}/CLAUDE.md"), $c['text'],
            'CLAUDE.md was edited by hand. Edit agent/guidelines/ and run: php scripts/clitool.php --agent-sync');
    }

    /* ---- composing ---- */

    public function testSectionsComeInFilenameOrderAndConceptsFollow(): void {
        $this->core(['020-b.md' => "## B\n\nbeta\n", '010-a.md' => "## A\n\nalpha\n", '100-c.md' => "## C\n\ngamma\n"]);
        $r = G::sync($this->root, $this->concept_('cal'));
        $this->assertTrue($r['changed']);
        $expected = G::START . "\n## A\n\nalpha\n\n## B\n\nbeta\n\n## C\n\ngamma\n\n"
                  . "## Concept: cal (1.2.3)\n\n### Using cal\n\nDo the thing.\n\n" . G::END . "\n";
        $this->assertSame($expected, $this->claude(), '100- sorts after 020-: three-digit prefixes');
    }

    public function testThePreambleAboveTheBlockIsKeptByteForByte(): void {
        $this->core();
        $pre = "# my instance\n\nNotes the operator wrote.\n\n";
        $this->put("{$this->root}/CLAUDE.md", $pre . G::START . "\nstale\n" . G::END . "\nafter the end\n");
        G::sync($this->root, []);
        $this->assertStringStartsWith($pre . G::START, $this->claude());
        $this->assertStringNotContainsString('stale', $this->claude());
        $this->assertStringNotContainsString('after the end', $this->claude(), 'text after END is regenerated away; notes go above START');
        $this->assertStringEndsWith(G::END . "\n", $this->claude());
    }

    public function testEnableAddsASectionDisableRemovesItAndSyncIsIdempotent(): void {
        $this->core();
        $cal = $this->concept_('cal');
        G::sync($this->root, $cal);
        $this->assertStringContainsString('## Concept: cal (1.2.3)', $this->claude());
        $this->assertFalse(G::sync($this->root, $cal)['changed'], 'nothing to do the second time');
        $this->assertTrue(G::sync($this->root, [])['changed']);
        $this->assertStringNotContainsString('Concept: cal', $this->claude());
    }

    public function testAConceptWithoutGuidelinesIsNotedNotSectioned(): void {
        $this->core();
        $r = G::sync($this->root, $this->concept_('bare', null));
        $this->assertStringNotContainsString('Concept: bare', $this->claude());
        $this->assertCount(1, $r['notes']);
        $this->assertStringContainsString("'bare' is enabled but has no guidelines.md", $r['notes'][0]);
    }

    /* ---- limits ---- */

    public function testGuidelinesOverTheLineLimitAreRefused(): void {
        $this->core();
        $this->expectExceptionMessage('is 81 lines; the limit is 80');
        G::sync($this->root, $this->concept_('big', implode("\n", array_fill(0, 81, 'line'))));
    }

    public function testGuidelinesMayNotOpenTheirOwnH2(): void {
        $this->core();
        $this->expectExceptionMessage("contains a '## ' heading");
        G::sync($this->root, $this->concept_('h2', "## I am a section\n\nno"));
    }

    public function testASectionFileMustStartWithItsHeading(): void {
        $this->core(['010-a.md' => "alpha without a heading\n"]);
        $this->expectExceptionMessage("010-a.md must start with its '## Heading' line");
        G::sync($this->root, []);
    }

    public function testNoGuidelinesDirectoryIsAFault(): void {
        $this->expectExceptionMessage('agent/guidelines does not exist');
        G::sync($this->root, []);
    }

    /* ---- one-time migration of files from before sync ---- */

    public function testCapricornsLayoutMigratesKeepingTheInstancePreamble(): void {
        $this->core();
        $pre = "# AI Builder — your private instance of tiknix (`x`)\n\nYou are running inside…\n";
        $this->put("{$this->root}/CLAUDE.md", $pre . "\n---\n## App technical notes (from the source app)\n\n# Tiknix Development Standards\n\n## Old\n\nold core body\n");
        $r = G::sync($this->root, []);
        $this->assertTrue($r['migrated']);
        $this->assertStringStartsWith(rtrim($pre) . "\n\n" . G::START, $this->claude());
        $this->assertStringNotContainsString('old core body', $this->claude());
        $this->assertStringNotContainsString('App technical notes', $this->claude());
        $this->assertFalse(G::sync($this->root, [])['migrated'], 'migration happens once');
    }

    public function testCoresOldLayoutMigratesKeepingTheH1(): void {
        $this->core();
        $this->put("{$this->root}/CLAUDE.md", "# Tiknix Development Standards\n\n## Old\n\nold body\n");
        G::sync($this->root, []);
        $this->assertStringStartsWith("# Tiknix Development Standards\n\n" . G::START, $this->claude());
        $this->assertStringNotContainsString('old body', $this->claude());
    }

    public function testAnUnknownLayoutIsRefusedWithTheMarkersToAdd(): void {
        $this->core();
        $this->put("{$this->root}/CLAUDE.md", "# Something else entirely\n\nhand-written\n");
        try {
            G::sync($this->root, []);
            $this->fail('should refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not a layout this can migrate', $e->getMessage());
            $this->assertStringContainsString(G::START, $e->getMessage());
            $this->assertStringContainsString(G::END, $e->getMessage());
        }
        $this->assertSame("# Something else entirely\n\nhand-written\n", $this->claude(), 'untouched');
    }

    public function testAStartMarkerWithoutAnEndIsRefused(): void {
        $this->core();
        $this->put("{$this->root}/CLAUDE.md", "pre\n" . G::START . "\nno end\n");
        $this->expectExceptionMessage('start marker but no end marker');
        G::sync($this->root, []);
    }

    public function testNoFileAtAllBecomesJustTheBlock(): void {
        $this->core();
        G::sync($this->root, []);
        $this->assertStringStartsWith(G::START, $this->claude());
        $this->assertTrue(G::isManaged($this->root));
    }
}
