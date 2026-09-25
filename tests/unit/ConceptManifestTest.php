<?php
/**
 * The manifest's shape rules. The ones that matter most are the callable names: a manifest
 * is data that later selects code to run, so there must be no way to write a name that
 * leaves the concept's own namespace.
 */

namespace tests\unit;

use app\ConceptException;
use app\ConceptManifest;
use PHPUnit\Framework\TestCase;

class ConceptManifestTest extends TestCase {

    private function make(array $extra, string $name = 'tickets'): ConceptManifest {
        return ConceptManifest::fromArray(['name' => $name, 'version' => '1.0.0'] + $extra, "/x/{$name}", $name);
    }

    private function slot(array $entry): array {
        return ['slots' => ['shop.item.extras' => $entry]];
    }

    public function testMinimalManifestLoads(): void {
        $m = $this->make([]);
        $this->assertSame('tickets', $m->name);
        $this->assertSame([], $m->slots);
    }

    public function testNameMustMatchDirectory(): void {
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('must match');
        ConceptManifest::fromArray(['name' => 'other', 'version' => '1'], '/x/tickets', 'tickets');
    }

    /** @dataProvider badNames */
    public function testNameShape(string $name): void {
        $this->expectException(ConceptException::class);
        ConceptManifest::fromArray(['name' => $name, 'version' => '1'], "/x/{$name}", $name);
    }

    public function badNames(): array {
        // "tickets\n": PHP's $ matches before a trailing newline unless the pattern has D.
        return [['Tickets'], ['event-tickets'], ['1tickets'], ['tick_ets'], ['../etc'], ['a\\b'], ["tickets\n"]];
    }

    /** requires.commands: program names, alternatives with '|' — never a path, an argument or a shell. */
    public function testRequiresCommandsAreBareProgramNames(): void {
        $m = $this->make(['requires' => ['commands' => ['google-chrome|chromium', 'ffmpeg']]]);
        $this->assertSame(['google-chrome|chromium', 'ffmpeg'], $m->requiresCommands);
        foreach (['/usr/bin/chrome', 'chrome --headless', 'a;b', 'rm -rf', '|chrome', ''] as $bad) {
            try {
                $this->make(['requires' => ['commands' => [$bad]]]);
                $this->fail("accepted requires.commands entry '{$bad}'");
            } catch (ConceptException $e) {
                $this->assertStringContainsString('requires.commands', $e->getMessage());
            }
        }
    }

    public function testVersionIsRequired(): void {
        $this->expectException(ConceptException::class);
        ConceptManifest::fromArray(['name' => 'tickets'], '/x/tickets', 'tickets');
    }

    /* ---- callable names: the reason the manifest is safe to load ---- */

    /** @dataProvider escapingCallables */
    public function testCallableCannotLeaveTheConcept(string $ref): void {
        $this->expectException(ConceptException::class);
        $this->make($this->slot(['view' => 'a.php', 'level' => 'PUBLIC', 'provider' => $ref]));
    }

    public function escapingCallables(): array {
        return [
            'bare function'        => ['system'],
            'bare function 2'      => ['phpinfo'],
            'global static'        => ['\\app\\Bean::exec'],
            'namespaced'           => ['app\\Bean::exec'],
            'relative namespace'   => ['lib\\Portlets::x'],
            'instance arrow'       => ['Portlets->x'],
            'expression'           => ['Portlets::x()'],
            'lowercase class'      => ['portlets::x'],
            'uppercase method'     => ['Portlets::X'],
            'traversal'            => ['../Portlets::x'],
            'empty'                => [''],
            'whitespace'           => ['Portlets::x '],
            'two colons in prefix' => ['a:b:Portlets::x'],
            'trailing newline'     => ["Portlets::x\n"],
        ];
    }

    public function testRelativeCallableResolvesIntoOwnConcept(): void {
        $m = $this->make($this->slot(['view' => 'a.php', 'level' => 'PUBLIC', 'provider' => 'Portlets::teacherSelect']));
        $ref = $m->slots['shop.item.extras'][0]['provider'];
        $this->assertSame(['tickets', 'Portlets', 'teacherSelect'], [$ref['concept'], $ref['class'], $ref['method']]);
    }

    public function testQualifiedCallableNeedsTheConceptInRequires(): void {
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage("not in requires.concepts");
        $this->make($this->slot(['view' => 'a.php', 'level' => 'MEMBER', 'when' => 'vendors:Access::isVendorCtx']));
    }

    public function testQualifiedCallableResolvesIntoRequiredConcept(): void {
        $m = $this->make(['requires' => ['concepts' => ['vendors']]]
            + $this->slot(['view' => 'a.php', 'level' => 'MEMBER', 'when' => 'vendors:Access::isVendorCtx']));
        $this->assertSame('vendors', $m->slots['shop.item.extras'][0]['when']['concept']);
    }

    /* ---- level, view, match, unknown keys ---- */

    public function testLevelIsRequired(): void {
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('level is required');
        $this->make($this->slot(['view' => 'a.php']));
    }

    public function testLevelMustBeAKnownName(): void {
        $this->expectException(ConceptException::class);
        $this->make($this->slot(['view' => 'a.php', 'level' => '50']));
    }

    public function testLevelNamesResolve(): void {
        $m = $this->make($this->slot(['view' => 'a.php', 'level' => 'ADMIN']));
        $this->assertSame(50, $m->slots['shop.item.extras'][0]['level']);
    }

    /** @dataProvider badViews */
    public function testViewCannotEscapeViewsDir(string $view): void {
        $this->expectException(ConceptException::class);
        $this->make($this->slot(['view' => $view, 'level' => 'PUBLIC']));
    }

    public function badViews(): array {
        return [['../x.php'], ['/etc/passwd'], ['a/../../x.php'], ['x.phtml'], ['x'], ['.hidden.php'], ['a//b.php']];
    }

    public function testNestedViewPathIsAllowed(): void {
        $m = $this->make($this->slot(['view' => 'portlets/teacher-select.php', 'level' => 'PUBLIC']));
        $this->assertSame('portlets/teacher-select.php', $m->slots['shop.item.extras'][0]['view']);
    }

    public function testUnknownEntryKeyIsRefused(): void {
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('unknown key');
        $this->make($this->slot(['view' => 'a.php', 'level' => 'PUBLIC', 'provder' => 'Portlets::x']));
    }

    public function testEmptyMatchListIsRefused(): void {
        $this->expectException(ConceptException::class);
        $this->make($this->slot(['view' => 'a.php', 'level' => 'PUBLIC', 'match' => ['offerType' => []]]));
    }

    public function testOneEntryOrAListOfEntries(): void {
        $one  = $this->make($this->slot(['view' => 'a.php', 'level' => 'PUBLIC']));
        $many = $this->make($this->slot([['view' => 'a.php', 'level' => 'PUBLIC'], ['view' => 'b.php', 'level' => 'ADMIN']]));
        $this->assertCount(1, $one->slots['shop.item.extras']);
        $this->assertCount(2, $many->slots['shop.item.extras']);
    }

    public function testCollectDataMustBeAnObject(): void {
        $this->expectException(ConceptException::class);
        $this->make(['collect' => ['nav.sections' => ['level' => 'MEMBER', 'data' => ['a', 'b']]]]);
    }

    public function testSlotNamesAreDotted(): void {
        $this->expectException(ConceptException::class);
        $this->make(['slots' => ['NavSections' => ['view' => 'a.php', 'level' => 'PUBLIC']]]);
    }

    /* ---- the root manifest hosts; it registers nothing ---- */

    public function testRootMayHost(): void {
        $m = ConceptManifest::fromArray(
            ['name' => 'root', 'version' => '1', 'hosts' => ['slots' => ['catalog.edit.fields' => ['form' => true]], 'collect' => ['nav.sections']]],
            '/x', 'root');
        $this->assertTrue($m->hostsSlots['catalog.edit.fields']['form']);
        $this->assertSame(['nav.sections'], $m->hostsCollect);
    }

    public function testRootMayNotRegister(): void {
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('may only declare "hosts"');
        ConceptManifest::fromArray(
            ['name' => 'root', 'version' => '1', 'slots' => ['a.b' => ['view' => 'a.php', 'level' => 'PUBLIC', 'provider' => 'Bean::exec']]],
            '/x', 'root');
    }

    public function testInvalidJsonNamesTheFile(): void {
        $dir = sys_get_temp_dir() . '/tiknix-manifest-' . getmypid();
        @mkdir($dir);
        file_put_contents("{$dir}/concept.json", '{not json');
        try {
            $this->expectException(ConceptException::class);
            $this->expectExceptionMessage('not valid JSON');
            ConceptManifest::load($dir, 'tickets');
        } finally {
            unlink("{$dir}/concept.json");
            rmdir($dir);
        }
    }
}
