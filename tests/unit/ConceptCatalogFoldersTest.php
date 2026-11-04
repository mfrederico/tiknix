<?php
/**
 * The plugin catalog's own folders (connectors/, docs/) are not plugins and are not reported as broken ones; a folder that
 * means to be a plugin and has no concept.json still is (the Plugins page showed both as "broken", 2026-11-04).
 */

namespace tests\unit;

use app\ConceptCatalog;
use PHPUnit\Framework\TestCase;

class ConceptCatalogFoldersTest extends TestCase {

    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/tiknix-catalog-' . getmypid() . '-' . bin2hex(random_bytes(3));
        foreach (['connectors', 'docs/adoption', 'halfmade'] as $d) mkdir("{$this->dir}/{$d}", 0700, true);
        file_put_contents("{$this->dir}/connectors/mailgun.json", '{}');
    }

    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->dir)); }

    public function testTheCatalogsOwnFoldersAreNotBrokenPlugins(): void {
        $r = (new ConceptCatalog($this->dir))->search('', 50);
        $this->assertSame(['halfmade'], array_keys($r['broken']), 'connectors/ and docs/ are skipped; a plugin folder with no manifest is still said');
    }
}
