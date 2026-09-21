<?php
/**
 * The concept runtime against real files: what loads, what routes, what renders, and what
 * is refused. "Off means inert" and "broken is a fault" are the two promises under test.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ConceptException;
use RedBeanPHP\OODBBean;

class ConceptsTest extends ConceptsTestCase {

    /** A bean to hand to save(). In-memory SQLite: nothing is stored, and no install is touched. */
    private function bean(): OODBBean {
        self::memoryDb();
        return Bean::dispense('product');
    }

    /* ---- autoload: enabled only ---- */

    public function testEnabledConceptClassesLoad(): void {
        $n = $this->uniq('load');
        $this->concept($n, [], ['lib/Thing.php' => $this->cls($n, 'Thing', ['hi(): string' => 'return "hi";'])]);
        $this->on = [$n];
        $this->concepts();
        $class = "app\\concepts\\{$n}\\Thing";
        $this->assertTrue(class_exists($class));
        $this->assertSame('hi', $class::hi());
    }

    public function testDisabledConceptClassesDoNotLoad(): void {
        $n = $this->uniq('off');
        $this->concept($n, [], ['lib/Thing.php' => $this->cls($n, 'Thing', [])]);
        $this->concepts();   // installed, not enabled
        $this->assertFalse(class_exists("app\\concepts\\{$n}\\Thing"));
    }

    public function testDisabledConceptModelDoesNotLoad(): void {
        $n = $this->uniq('mdl');
        $model = 'Model_' . ucfirst($n) . 'thing';
        $this->concept($n, [], ["models/{$model}.php" => "<?php\nclass {$model} {}\n"]);
        $this->concepts();
        $this->assertFalse(class_exists($model), 'a disabled concept must not attach FUSE behaviour');
        $this->on = [$n];
        $this->assertTrue(class_exists($model));
    }

    public function testAutoloadIgnoresTraversalInClassNames(): void {
        $n = $this->uniq('trav');
        $this->concept($n, []);
        $this->put("{$this->root}/secret.php", "<?php\nthrow new \\Exception('loaded a file outside the concept');\n");
        $this->on = [$n];
        $this->concepts();
        // class_exists() hands any string to autoloaders, and class names arrive from URLs.
        $this->assertFalse(class_exists("app\\concepts\\{$n}\\..\\..\\..\\secret"));
        $this->assertFalse(class_exists("app\\concepts\\{$n}\\../../../secret"));
    }

    /* ---- routing ---- */

    public function testControllerResolvesOnlyWhileEnabled(): void {
        $n = $this->uniq('rt');
        $ctl = ucfirst($n);
        $this->concept($n, ['provides' => ['controllers' => [$ctl]]], ["controls/{$ctl}.php" => $this->cls($n, $ctl, [])]);

        $this->assertNull($this->concepts()->controllerClass($n));
        $this->assertSame([], $this->concepts()->controllerRoots());

        $this->on = [$n];
        $c = $this->concepts();
        $this->assertSame("app\\concepts\\{$n}\\{$ctl}", $c->controllerClass($n));
        $this->assertSame("app\\concepts\\{$n}\\{$ctl}", $c->controllerClass(strtoupper($n)), 'URLs are case-insensitive');
        $this->assertSame([realpath("{$this->root}/concepts/{$n}/controls")], $c->controllerRoots());
        $this->assertNull($c->controllerClass('../' . $n));
    }

    public function testEnabledButMissingConceptIsAFault(): void {
        $this->on = ['ghost'];
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage("Concept 'ghost'");
        $this->concepts()->enabled();
    }

    /* ---- slots ---- */

    private function hostAndPortlet(string $n, array $entry, array $methods = [], string $view = '<b><?= $label ?></b>'): void {
        $this->rootManifest(['slots' => ['shop.item.extras' => (object) []]]);
        $this->concept($n, ['slots' => ['shop.item.extras' => $entry]], [
            'lib/Portlets.php' => $this->cls($n, 'Portlets', $methods + ['label(array $ctx): array' => 'return ["label" => "L-" . ($ctx["id"] ?? "")];']),
            'views/part.php'   => $view,
        ]);
        $this->on = [$n];
    }

    public function testSlotRendersProviderDataThroughTheView(): void {
        $n = $this->uniq('slot');
        $this->hostAndPortlet($n, ['view' => 'part.php', 'level' => 'PUBLIC', 'provider' => 'Portlets::label']);
        $parts = $this->concepts()->parts('shop.item.extras', ['id' => 7]);
        $this->assertSame('<b>L-7</b>', $parts[0]['html']);
        $this->assertSame($n, $parts[0]['concept']);
    }

    public function testViewSeesOnlyProviderVarsAndCtx(): void {
        $n = $this->uniq('iso');
        $this->hostAndPortlet($n, ['view' => 'part.php', 'level' => 'PUBLIC', 'provider' => 'Portlets::label'], [],
            '<?= implode(",", array_keys(get_defined_vars())) ?>');
        $html = $this->concepts()->parts('shop.item.extras', [])[0]['html'];
        $this->assertSame('__file,__vars,label,ctx', $html);
    }

    public function testLevelGate(): void {
        $n = $this->uniq('lvl');
        $this->hostAndPortlet($n, ['view' => 'part.php', 'level' => 'ADMIN', 'provider' => 'Portlets::label']);
        $this->level = 100;
        $this->assertSame([], $this->concepts()->parts('shop.item.extras', []), 'a MEMBER does not see an ADMIN part');
        $this->level = 50;
        $this->assertCount(1, $this->concepts()->parts('shop.item.extras', []));
    }

    public function testMatchNarrowsByContext(): void {
        $n = $this->uniq('mat');
        $this->hostAndPortlet($n, ['view' => 'part.php', 'level' => 'PUBLIC', 'provider' => 'Portlets::label',
                                   'match' => ['offerType' => ['class', 'session']]]);
        $c = $this->concepts();
        $this->assertCount(1, $c->parts('shop.item.extras', ['offerType' => 'session']));
        $this->assertCount(0, $c->parts('shop.item.extras', ['offerType' => 'digital']));
    }

    public function testMatchKeyTheHostForgotIsAFault(): void {
        $n = $this->uniq('mfg');
        $this->hostAndPortlet($n, ['view' => 'part.php', 'level' => 'PUBLIC', 'match' => ['offerType' => ['class']]]);
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage("needs 'offerType'");
        $this->concepts()->parts('shop.item.extras', []);
    }

    public function testWhenGates(): void {
        $n = $this->uniq('whn');
        $this->hostAndPortlet($n, ['view' => 'part.php', 'level' => 'PUBLIC', 'provider' => 'Portlets::label', 'when' => 'Portlets::ok'],
            ['ok(array $ctx): bool' => 'return !empty($ctx["yes"]);']);
        $c = $this->concepts();
        $this->assertCount(1, $c->parts('shop.item.extras', ['yes' => true]));
        $this->assertCount(0, $c->parts('shop.item.extras', []));
    }

    public function testWhenMustReturnBool(): void {
        $n = $this->uniq('whb');
        $this->hostAndPortlet($n, ['view' => 'part.php', 'level' => 'PUBLIC', 'when' => 'Portlets::truthy'],
            ['truthy(array $ctx)' => 'return 1;']);
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('must return bool');
        $this->concepts()->parts('shop.item.extras', []);
    }

    public function testUndeclaredSlotIsAHostBug(): void {
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage("Slot 'shop.item.extrs' is not declared");
        $this->concepts()->parts('shop.item.extrs', []);
    }

    public function testEmptySlotRendersNothing(): void {
        $this->rootManifest(['slots' => ['shop.item.extras' => (object) []]]);
        $this->assertSame([], $this->concepts()->parts('shop.item.extras', []));
    }

    public function testBrokenViewIsAFaultNotBlank(): void {
        $n = $this->uniq('brk');
        $this->hostAndPortlet($n, ['view' => 'part.php', 'level' => 'PUBLIC'], [], '<?php throw new \RuntimeException("view blew up");');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('view blew up');
        $this->concepts()->parts('shop.item.extras', []);
    }

    public function testPartsAreOrdered(): void {
        $this->rootManifest(['slots' => ['shop.item.extras' => (object) []]]);
        $a = $this->uniq('orda'); $b = $this->uniq('ordb');
        $this->concept($a, ['slots' => ['shop.item.extras' => ['view' => 'p.php', 'level' => 'PUBLIC', 'order' => 200]]], ['views/p.php' => 'A']);
        $this->concept($b, ['slots' => ['shop.item.extras' => ['view' => 'p.php', 'level' => 'PUBLIC', 'order' => 10]]], ['views/p.php' => 'B']);
        $this->on = [$a, $b];
        $this->assertSame('BA', implode('', array_column($this->concepts()->parts('shop.item.extras', []), 'html')));
    }

    /* ---- collect + save ---- */

    public function testCollectMergesAcrossConcepts(): void {
        $this->rootManifest(['collect' => ['nav.sections']]);
        $a = $this->uniq('nava'); $b = $this->uniq('navb');
        $this->concept($a, ['collect' => ['nav.sections' => ['level' => 'PUBLIC', 'data' => ['Events' => [['label' => 'Calendar']]]]]]);
        $this->concept($b, ['collect' => ['nav.sections' => ['level' => 'ADMIN', 'data' => ['Events' => [['label' => 'Check-in']]]]]]);
        $this->on = [$a, $b];
        $this->level = 100;
        $this->assertSame(['Events' => [['label' => 'Calendar']]], $this->concepts()->gather('nav.sections', []));
        $this->level = 50;
        $this->assertCount(2, $this->concepts()->gather('nav.sections', [])['Events']);
    }

    public function testSaveCallsMatchingRegistrations(): void {
        $n = $this->uniq('sav');
        $this->rootManifest(['slots' => ['catalog.edit.fields' => ['form' => true]]]);
        $this->concept($n, ['slots' => ['catalog.edit.fields' => ['view' => 'f.php', 'level' => 'PUBLIC', 'save' => 'Portlets::keep']]], [
            'lib/Portlets.php' => $this->cls($n, 'Portlets', ['keep(\RedBeanPHP\OODBBean $b, array $in): void' => '$b->teacherId = (int) $in["teacherId"];']),
            'views/f.php' => '',
        ]);
        $this->on = [$n];
        $bean = $this->bean();
        $this->concepts()->save('catalog.edit.fields', $bean, ['teacherId' => '9'], []);
        $this->assertSame(9, $bean->teacherId);
    }

    public function testSaveOnANonFormSlotIsAFault(): void {
        $this->rootManifest(['slots' => ['shop.item.extras' => (object) []]]);
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('not a form slot');
        $this->concepts()->save('shop.item.extras', $this->bean(), [], []);
    }

    /* ---- verify / enable / disable ---- */

    public function testVerifyNamesEveryProblemAtOnce(): void {
        $n = $this->uniq('ver');
        $this->rootManifest(['slots' => ['catalog.edit.fields' => ['form' => true]]]);
        $this->concept($n, [
            'requires' => ['concepts' => ['nothere'], 'lib' => ['NoSuchCoreClass']],
            'provides' => ['controllers' => ['Ghost']],
            'slots' => [
                'catalog.edit.fields' => ['view' => 'missing.php', 'level' => 'ADMIN', 'provider' => 'Portlets::nope'],
                'not.hosted.anywhere' => ['view' => 'missing.php', 'level' => 'ADMIN'],
            ],
        ]);
        $problems = implode("\n", $this->concepts()->verify($n));
        $this->assertStringContainsString("requires concept 'nothere'", $problems);
        $this->assertStringContainsString('NoSuchCoreClass', $problems);
        $this->assertStringContainsString("'Ghost'", $problems);
        $this->assertStringContainsString('"save" is required', $problems);
        $this->assertStringContainsString('missing.php', $problems);
        $this->assertStringContainsString('Portlets', $problems);
        $this->assertStringContainsString("'not.hosted.anywhere' is not declared", $problems);
    }

    public function testControllerNameAlreadyInCoreIsRefused(): void {
        $n = $this->uniq('clash');
        // app\Feature is a real core class: /feature would resolve to it first and be refused.
        $this->concept($n, ['provides' => ['controllers' => ['Feature']]], ['controls/Feature.php' => $this->cls($n, 'Feature', [])]);
        $this->assertStringContainsString('core already has app\\Feature', implode("\n", $this->concepts()->verify($n)));
    }

    public function testBeanClaimedTwiceIsRefused(): void {
        $a = $this->uniq('bna'); $b = $this->uniq('bnb');
        $this->concept($a, ['provides' => ['beans' => ['ticket']]]);
        $this->concept($b, ['provides' => ['beans' => ['ticket']]]);
        $this->on = [$a];
        $this->assertStringContainsString("already claimed by concept '{$a}'", implode("\n", $this->concepts()->verify($b)));
    }

    public function testBeanWithACoreModelIsRefused(): void {
        $n = $this->uniq('bnc');
        $this->put("{$this->root}/models/Model_Member.php", '<?php');
        $this->concept($n, ['provides' => ['beans' => ['member']]]);
        $this->assertStringContainsString('core already has models/Model_Member.php', implode("\n", $this->concepts()->verify($n)));
    }

    public function testAVerifiedConceptHasNoProblems(): void {
        $n = $this->uniq('fine');
        $ctl = ucfirst($n);
        $this->rootManifest(['slots' => ['shop.item.extras' => (object) []]]);
        $this->concept($n, [
            'provides' => ['controllers' => [$ctl], 'beans' => [$n . 'row']],
            'slots' => ['shop.item.extras' => ['view' => 'part.php', 'level' => 'PUBLIC', 'provider' => 'Portlets::label']],
        ], [
            "controls/{$ctl}.php" => $this->cls($n, $ctl, []),
            'lib/Portlets.php'    => $this->cls($n, 'Portlets', ['label(array $ctx): array' => 'return [];']),
            'views/part.php'      => 'x',
        ]);
        $this->assertSame([], $this->concepts()->verify($n));
    }

    public function testEnableRunsSeedsThenSetsTheFlag(): void {
        $n = $this->uniq('en');
        $this->concept($n, [], ['seeds/01_schema.php' => '<?php']);
        $this->seedResult = ['01_schema.php' => 'ok'];
        $this->concepts()->enable($n);
        $this->assertSame(["{$this->root}/concepts/{$n}/seeds"], $this->seedCalls);
        $this->assertSame([$n], $this->on);
    }

    public function testFailedSeedLeavesTheConceptOff(): void {
        $n = $this->uniq('sf');
        $this->concept($n, [], ['seeds/01_schema.php' => '<?php']);
        $this->seedResult = ['01_schema.php' => 'error: no such table'];
        try {
            $this->concepts()->enable($n);
            $this->fail('enable() should have thrown');
        } catch (ConceptException $e) {
            $this->assertStringContainsString('NOT enabled', $e->getMessage());
            $this->assertStringContainsString('no such table', $e->getMessage());
        }
        $this->assertSame([], $this->on);
    }

    public function testEnableRefusesAnUnverifiedConcept(): void {
        $n = $this->uniq('bad');
        $this->concept($n, ['requires' => ['concepts' => ['nothere']]]);
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('cannot be enabled');
        $this->concepts()->enable($n);
    }

    public function testDisableRefusedWhileRequired(): void {
        $a = $this->uniq('base'); $b = $this->uniq('dep');
        $this->concept($a, []);
        $this->concept($b, ['requires' => ['concepts' => [$a]]]);
        $this->on = [$a, $b];
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage("required by {$b}");
        $this->concepts()->disable($a);
    }

    public function testDisableRefusedWhileRowsExistUnlessForced(): void {
        $n = $this->uniq('rows');
        $this->concept($n, ['provides' => ['beans' => ['ticket']]]);
        $this->on = [$n];
        $this->rows = ['ticket' => 3];
        try {
            $this->concepts()->disable($n);
            $this->fail('disable() should have thrown');
        } catch (ConceptException $e) {
            $this->assertStringContainsString('3 ticket row(s)', $e->getMessage());
        }
        $this->assertSame([$n], $this->on);
        $this->concepts()->disable($n, true);
        $this->assertSame([], $this->on);
    }

    public function testScanListsBrokenConceptsBesideWorkingOnes(): void {
        $ok = $this->uniq('good'); $bad = $this->uniq('junk');
        $this->concept($ok, []);
        $this->put("{$this->root}/concepts/{$bad}/concept.json", '{nope');
        $scan = $this->concepts()->scan();
        $this->assertNull($scan[$ok]['error']);
        $this->assertStringContainsString('not valid JSON', $scan[$bad]['error']);
    }

    public function testInstallWithNoConceptsDirIsInert(): void {
        rmdir("{$this->root}/concepts");
        $this->on = ['anything'];   // even a stale flag costs nothing without the directory
        $c = $this->concepts();
        $this->assertSame([], $c->enabled());
        $this->assertNull($c->controllerClass('anything'));
    }
}
