<?php
/**
 * WorkspaceSchemaBuilder's `$_dispensePadding` — a seed sizes a table by storing a probe row
 * of str_repeat('x', N) values, so that a real value never widens a column later (a widen
 * rebuilds the SQLite table and drops every row). A FUSE model that validates on store
 * rejects such a probe, and until 2026-09-25 the seed then failed and the table was never
 * built (start.tiknix's interview / handoffstep, found by the audit → fix task).
 *
 *   probe       the padded row stores with the model's hooks off; the table and its sized
 *               columns exist afterwards and the probe itself is gone (deferred trash)
 *   intact      a bean dispensed the normal way still runs the model's validation
 *   restored    the builder's helper swap leaves RedBean's bean helper as it found it
 *
 * Scratch in-memory database; a throwaway seed directory under the system temp dir.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';
require_once __DIR__ . '/fixtures/Model_Paddingprobe.php';

use app\Bean;

class SchemaPaddingTest extends ConceptsTestCase {

    private const DB = 'schema-padding-test';
    private string $seedDir;

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        $this->seedDir = $this->root . '/seeds';
        mkdir($this->seedDir, 0700, true);
        file_put_contents($this->seedDir . '/01_Probe.php', <<<'PHP'
<?php
use \RedBeanPHP\R;
if (!$_tableCheck('paddingprobe')) {
    $p = $_dispensePadding('paddingprobe');
    $p->phase = str_repeat('x', 16);
    $p->notes = str_repeat('x', 4000);
    R::store($p);
    $_defer($p);
}
PHP);
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    public function testAValidatedTableIsBuiltFromItsProbeAndTheModelStaysStrict(): void {
        $helperBefore = \RedBeanPHP\R::getRedBean()->getBeanHelper();

        $results = (new \app\services\Schema\WorkspaceSchemaBuilder())->build($this->seedDir);
        $this->assertSame('ok', $results['01_Probe.php'] ?? json_encode($results), 'the probe got past the model');
        $this->assertArrayHasKey('phase', Bean::inspect('paddingprobe'));
        $this->assertArrayHasKey('notes', Bean::inspect('paddingprobe'));
        $this->assertSame(0, Bean::count('paddingprobe'), 'the probe row was trashed after the build');

        $this->assertSame($helperBefore, \RedBeanPHP\R::getRedBean()->getBeanHelper(), 'bean helper restored');

        // The model is as strict as before for anything dispensed the normal way.
        $bad = Bean::dispense('paddingprobe'); $bad->phase = 'xxxxxxxxxxxxxxxx';
        try { Bean::store($bad); $this->fail('the model accepted padding from a normal dispense'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('unknown phase', $e->getMessage()); }
        $good = Bean::dispense('paddingprobe'); $good->phase = 'brief';
        $this->assertGreaterThan(0, Bean::store($good));
    }
}
