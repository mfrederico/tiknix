<?php
/**
 * concepts.lock — the file that says which plugins an install has and which are on.
 *
 *   absent, no concepts/      nothing enabled, no error
 *   absent, concepts/ present not migrated: an error naming --concept-lock
 *   sync                      rows from disk; enabled taken from the old flags once; gone dirs dropped
 *   record / setEnabled       what install and enable/disable write
 *   hash / modified           an in-place edit is visible; sync keeps the evidence unless rehash
 *   malformed                 a fault, never an empty set
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\ConceptException;
use app\ConceptLock;
use app\Concepts;

class ConceptLockTest extends ConceptsTestCase {

    public function testNoConceptsMeansNothingEnabledAndNoFile(): void {
        $root = $this->root . '/empty';
        mkdir($root);
        $this->assertSame([], ConceptLock::enabledNames($root));
        $this->assertFalse(ConceptLock::exists($root));
    }

    public function testConceptsWithoutALockIsNotMigratedYet(): void {
        $this->concept('pdfx');
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('--concept-lock');
        ConceptLock::enabledNames($this->root);
    }

    public function testSyncMigratesFromTheOldFlagsOnceThenKeepsItsOwnState(): void {
        $this->concept('aaa'); $this->concept('bbb');
        $this->put($this->root . '/concepts/bbb/' . \app\ConceptCatalog::PROVENANCE_FILE, json_encode(['source' => 'control-plane catalog', 'installed_at' => '2026-09-01T00:00:00+00:00']));
        $r = ConceptLock::sync($this->root, ['bbb']);
        $this->assertSame(['aaa', 'bbb'], $r['added']);
        $this->assertSame(['bbb'], ConceptLock::enabledNames($this->root));
        $lock = ConceptLock::read($this->root)['concepts'];
        $this->assertSame(['1.0.0', 'control-plane catalog', '2026-09-01T00:00:00+00:00'], [$lock['bbb']['version'], $lock['bbb']['source'], $lock['bbb']['installed_at']]);
        $this->assertSame('authored here', $lock['aaa']['source']);
        $this->assertStringStartsWith('sha256:', $lock['aaa']['hash']);

        // A second sync with different flags changes nothing: the lock is the record now.
        ConceptLock::sync($this->root, ['aaa']);
        $this->assertSame(['bbb'], ConceptLock::enabledNames($this->root));

        // A directory that disappears is dropped; a new one is added switched off.
        $this->rm($this->root . '/concepts/aaa');
        $this->concept('ccc');
        $r = ConceptLock::sync($this->root);
        $this->assertSame([['ccc'], ['aaa'], ['bbb']], [$r['added'], $r['removed'], $r['kept']]);
        $this->assertNull(ConceptLock::entry($this->root, 'aaa'));
        $this->assertFalse(ConceptLock::entry($this->root, 'ccc')['enabled']);
    }

    public function testRecordAndSetEnabledAreWhatInstallAndEnableWrite(): void {
        $this->concept('tkt');
        ConceptLock::record($this->root, 'tkt', '1.0.0', 'control-plane catalog');
        $this->assertFalse(ConceptLock::entry($this->root, 'tkt')['enabled']);
        ConceptLock::setEnabled($this->root, 'tkt', true);
        $this->assertSame(['tkt'], ConceptLock::enabledNames($this->root));
        $this->assertArrayHasKey('enabled_at', ConceptLock::entry($this->root, 'tkt'));
        ConceptLock::setEnabled($this->root, 'tkt', false);
        $this->assertSame([], ConceptLock::enabledNames($this->root));
        // enabling something the lock never saw (authored in place) records it from its directory
        $this->concept('own');
        ConceptLock::setEnabled($this->root, 'own', true);
        $this->assertSame(['1.0.0', 'authored here'], [ConceptLock::entry($this->root, 'own')['version'], ConceptLock::entry($this->root, 'own')['source']]);
    }

    public function testAnInPlaceEditIsVisibleAndSyncKeepsTheEvidence(): void {
        $dir = $this->concept('img', [], ['lib/Image.php' => "<?php\nnamespace app\\concepts\\img;\nclass Image {}\n"]);
        ConceptLock::record($this->root, 'img', '1.0.0', 'control-plane catalog');
        $this->assertFalse(ConceptLock::modified($this->root, 'img'));
        $this->put("{$dir}/lib/Image.php", "<?php\nnamespace app\\concepts\\img;\nclass Image { public \$edited = true; }\n");
        $this->assertTrue(ConceptLock::modified($this->root, 'img'), 'the hash no longer matches');
        ConceptLock::sync($this->root);
        $this->assertTrue(ConceptLock::modified($this->root, 'img'), 'a plain sync does not paper over the edit');
        ConceptLock::sync($this->root, [], true);
        $this->assertFalse(ConceptLock::modified($this->root, 'img'), 'rehash accepts the current files as the baseline');
        $this->put("{$dir}/" . \app\ConceptCatalog::PROVENANCE_FILE, '{"source":"x"}');
        $this->assertFalse(ConceptLock::modified($this->root, 'img'), 'the provenance note is not part of the hash');
    }

    public function testAMalformedLockIsAFault(): void {
        $this->concept('zzz');
        $this->put(ConceptLock::path($this->root), '{"version": 1}');
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('not a valid lock file');
        ConceptLock::enabledNames($this->root);
    }

    public function testTheRegistryReadsTheLockNotTheDatabase(): void {
        $this->concept('reg', [], ['lib/Thing.php' => "<?php\nnamespace app\\concepts\\reg;\nclass Thing {}\n"]);
        ConceptLock::sync($this->root, ['reg']);
        $registry = new Concepts($this->root, [
            'enabled' => fn(): array => ConceptLock::enabledNames($this->root),
            'level'   => fn(): int => 101,
            'setFlag' => fn(string $n, bool $on) => ConceptLock::setEnabled($this->root, $n, $on),
            'seed'    => fn(string $d): array => [],
            'rows'    => fn(string $b): int => 0,
        ]);
        $this->assertArrayHasKey('reg', $registry->enabled());
        $this->assertTrue($registry->scan()['reg']['enabled']);
        $registry->disable('reg');
        $this->assertSame([], ConceptLock::enabledNames($this->root));
    }
}
