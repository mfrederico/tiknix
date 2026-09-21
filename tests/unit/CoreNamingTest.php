<?php
/**
 * PHP class names are case-insensitive, and controls/ and lib/ share the `app\` namespace.
 *
 * So controls/Conceptcatalog.php and lib/ConceptCatalog.php are ONE class name. It shipped
 * that way for a few minutes: inside the controller, `ConceptCatalog::forInstall()` resolved
 * to the controller itself, and every authenticated request answered 500. Nothing warns about
 * it — whichever file the autoloader reaches first simply wins.
 *
 * The concept runtime already refuses this for concepts (Concepts::verify). This holds core
 * to the same rule.
 */

namespace tests\unit;

use PHPUnit\Framework\TestCase;

class CoreNamingTest extends TestCase {

    public function testNoTwoClassesInTheAppNamespaceDifferOnlyByCase(): void {
        $root = dirname(__DIR__, 2);
        $seen = [];
        $clashes = [];
        foreach (['controls', 'lib'] as $dir) {
            foreach (glob("{$root}/{$dir}/*.php") ?: [] as $file) {
                $key = strtolower(basename($file, '.php'));
                if (isset($seen[$key])) {
                    $clashes[] = "{$seen[$key]} and {$dir}/" . basename($file);
                }
                $seen[$key] = "{$dir}/" . basename($file);
            }
        }
        $this->assertSame([], $clashes,
            "These files declare the same class name as far as PHP is concerned (app\\ maps to both controls/ and lib/):\n  "
          . implode("\n  ", $clashes));
    }
}
