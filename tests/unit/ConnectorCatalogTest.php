<?php
/**
 * The connector half of the catalog (CONNECTOR-CATALOG-PLAN.md §5): a manifest is linted,
 * published under a version that names one thing forever, installed to connectors/<key>.json
 * with a lock row, updated only to a newer version and never over an in-place edit; and a
 * concept's roles pull the manifests they need in with it — or refuse the concept when a
 * type exists nowhere.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\ConceptCatalog;
use app\ConceptException;
use app\ConceptLock;
use app\services\connectors\ConnectorRegistry;

class ConnectorCatalogTest extends ConceptsTestCase {

    private string $catalogDir;
    private string $srcDir;

    protected function setUp(): void {
        parent::setUp();
        $this->catalogDir = $this->root . '/_catalog';
        $this->srcDir = $this->root . '/_src';
        mkdir($this->catalogDir, 0700, true);
        mkdir($this->srcDir, 0700, true);
        ConnectorRegistry::flush();
    }

    protected function tearDown(): void {
        ConnectorRegistry::flush();
        parent::tearDown();
    }

    private function catalog(): ConceptCatalog { return new ConceptCatalog($this->catalogDir); }

    /** A publishable manifest file under a key no install has. */
    private function manifest(string $key, array $over = []): string {
        $m = array_replace_recursive([
            'key' => $key, 'version' => '1.0.0', 'label' => ucfirst($key), 'blurb' => 'A test mail provider.',
            'category' => 'Messaging', 'roles' => ['mail'], 'base_url' => 'https://api.example.com', 'test_path' => '/v1/me',
            'docs_url' => 'https://example.com/docs',
            'auth' => ['style' => 'header', 'name' => 'Authorization', 'prefix' => 'Token ', 'key_label' => 'API key'],
            'headers' => ['revision' => '2024-01-01'],
            'fields' => [['name' => 'domain', 'label' => 'Sending domain', 'required' => true]],
            'endpoints' => [['method' => 'GET', 'path' => '/v1/me', 'label' => 'Me']],
        ], $over);
        $file = "{$this->srcDir}/{$key}.json";
        file_put_contents($file, json_encode($m, JSON_PRETTY_PRINT));
        return $file;
    }

    public function testLintRefusesWhatMustNotBePublished(): void {
        $this->assertSame([], ConnectorRegistry::lint(json_decode(file_get_contents($this->manifest('zzmaila')), true), 'zzmaila.json'));
        $cases = [
            [['version' => '1.0'], 'MAJOR.MINOR.PATCH'],
            [['key' => 'other'], 'does not match the file name'],
            [['auth' => ['style' => 'magic']], "auth.style 'magic'"],
            [['auth' => ['api_key' => 'key-0123456789abcdef0123456789']], 'credentials live in Connections'],
            [['oauth' => ['authorize_url' => 'https://x/a', 'token_url' => 'https://x/t', 'client_secret' => 'shh']], 'credentials live in Connections'],
            [['fields' => [['name' => 'key']]], 'collides'],
            [['docs_url' => 'http://example.com'], 'https'],
            [['endpoints' => [['method' => 'GET', 'path' => 'v1/me']]], 'starting with /'],
            [['blurb' => 'see /var/www/html/default/serenity/lib'], 'server path'],
        ];
        foreach ($cases as [$over, $msg]) {
            $m = json_decode(file_get_contents($this->manifest('zzmailb', $over)), true);
            $errors = ConnectorRegistry::lint($m, 'zzmailb.json');
            $this->assertNotEmpty($errors, json_encode($over));
            $this->assertStringContainsString($msg, implode("\n", $errors), json_encode($over));
        }
    }

    public function testPublishInstallUpdateAndTheLock(): void {
        $cat = $this->catalog();
        $file = $this->manifest('zzmailc');
        $this->assertSame('published', $cat->publishConnector($file)['status']);
        $this->assertSame('unchanged', $cat->publishConnector($file)['status'], 'same bytes again');
        $this->assertSame(['zzmailc'], array_column($cat->connectors(), 'key'));
        $this->assertSame(['mail'], $cat->connectors()[0]['roles']);
        $this->assertSame('api_key', $cat->connectors()[0]['type']);

        // a different manifest under the same version is refused
        $this->manifest('zzmailc', ['blurb' => 'changed']);
        try { $cat->publishConnector($file); $this->fail('same version, different contents'); }
        catch (ConceptException $e) { $this->assertStringContainsString('already has version 1.0.0', $e->getMessage()); }

        // a manifest that fails the lint is refused, naming why
        try { $cat->publishConnector($this->manifest('zzmaild', ['version' => 'x'])); $this->fail('lint'); }
        catch (ConceptException $e) { $this->assertStringContainsString('NOT published', $e->getMessage()); $this->assertStringContainsString('MAJOR.MINOR.PATCH', $e->getMessage()); }

        // install: file + lock row; the registry of THIS install does not see it (it reads core's connectors/)
        $r = $cat->installConnector('zzmailc', $this->root);
        $this->assertSame('installed', $r['status']);
        $this->assertFileExists("{$this->root}/connectors/zzmailc.json");
        $row = ConceptLock::connectorEntry($this->root, 'zzmailc');
        $this->assertSame('1.0.0', $row['version']);
        $this->assertSame('control-plane catalog', $row['source']);
        $this->assertFalse(ConceptLock::connectorModified($this->root, 'zzmailc'));
        $this->assertArrayHasKey('connectors', json_decode(file_get_contents(ConceptLock::path($this->root)), true));

        // again: refused, naming the update command
        try { $cat->installConnector('zzmailc', $this->root); $this->fail('reinstall'); }
        catch (ConceptException $e) { $this->assertStringContainsString('--connector-update=zzmailc', $e->getMessage()); }
        // nothing newer: refused
        try { $cat->updateConnector('zzmailc', $this->root); $this->fail('no newer version'); }
        catch (ConceptException $e) { $this->assertStringContainsString('already at 1.0.0', $e->getMessage()); }

        // 1.0.1 published → update moves the install forward
        $cat->publishConnector($this->manifest('zzmailc', ['version' => '1.0.1', 'blurb' => 'v2']));
        $u = $cat->updateConnector('zzmailc', $this->root);
        $this->assertSame(['1.0.0', '1.0.1'], [$u['from'], $u['version']]);
        $this->assertSame('1.0.1', ConceptLock::connectorEntry($this->root, 'zzmailc')['version']);

        // edited in place → an update is refused unless forced
        file_put_contents("{$this->root}/connectors/zzmailc.json", json_encode(['key' => 'zzmailc', 'version' => '1.0.1', 'label' => 'Mine', 'base_url' => 'https://api.example.com']));
        $this->assertTrue(ConceptLock::connectorModified($this->root, 'zzmailc'));
        $cat->publishConnector($this->manifest('zzmailc', ['version' => '1.0.2']));
        try { $cat->updateConnector('zzmailc', $this->root); $this->fail('edited in place'); }
        catch (ConceptException $e) { $this->assertStringContainsString('edited in place', $e->getMessage()); }
        $this->assertSame('1.0.2', $cat->updateConnector('zzmailc', $this->root, true)['version']);

        // a manifest the install authored (no lock row) is never overwritten
        file_put_contents("{$this->root}/connectors/zzmaile.json", '{"key":"zzmaile","version":"0.1.0","label":"Own","base_url":"https://x.example.com"}');
        $cat->publishConnector($this->manifest('zzmaile'));
        try { $cat->installConnector('zzmaile', $this->root); $this->fail('own manifest'); }
        catch (ConceptException $e) { $this->assertStringContainsString('authored it', $e->getMessage()); }
        try { $cat->updateConnector('zzmaile', $this->root); $this->fail('own manifest update'); }
        catch (ConceptException $e) { $this->assertStringContainsString('authored', $e->getMessage()); }

        // sync drops the row of a manifest removed by hand
        unlink("{$this->root}/connectors/zzmailc.json");
        $s = ConceptLock::sync($this->root);
        $this->assertContains('connector zzmailc', $s['removed']);
        $this->assertNull(ConceptLock::connectorEntry($this->root, 'zzmailc'));

        // a class never gets a manifest installed over it
        try { $cat->installConnector('stripe', $this->root); $this->fail('code connector'); }
        catch (ConceptException $e) { $this->assertStringContainsString('is a class', $e->getMessage()); }
    }

    public function testAConceptBringsTheConnectorsItsRolesNeedOrIsRefused(): void {
        $cat = $this->catalog();
        $cat->publishConnector($this->manifest('zzmailf'));
        $project = $this->root . '/_project';
        mkdir($project, 0700, true);

        // publish a concept that asks for the catalog's connector and a core class
        $shop = $this->uniq('shop');
        $dir = $this->concept($shop, [
            'title' => 'Shop', 'blurb' => 'Sells things.', 'tags' => ['shop'],
            'requires' => ['connectors' => [['role' => 'payments', 'types' => ['stripe']], ['role' => 'mail', 'types' => ['zzmailf']]]],
        ], ['guidelines.md' => "### {$shop}\n\nuse it\n"]);
        $cat->publish($dir);

        $plan = $cat->installPlan($shop, $project);
        $this->assertStringContainsString('connector **zzmailf**', $plan['summary']);
        $this->assertStringNotContainsString('connector **stripe**', $plan['summary'], 'a class is satisfied, nothing to bring');

        $r = $cat->install($shop, $project);
        $this->assertSame(['zzmailf'], array_column($r['connectors'], 'key'));
        $this->assertFileExists("{$project}/connectors/zzmailf.json");
        $this->assertSame('1.0.0', ConceptLock::connectorEntry($project, 'zzmailf')['version']);
        $this->assertSame(['install' => [], 'missing' => [], 'satisfied' => ['stripe', 'zzmailf']],
            (function (array $r) { ksort($r); return $r; })($cat->resolveConnectors(['stripe', 'zzmailf'], $project)), 'now satisfied in that project');

        // a type that is nowhere refuses the concept, before anything lands
        $bad = $this->uniq('bad');
        $cat->publish($this->concept($bad, [
            'title' => 'Bad', 'blurb' => 'Needs a connector nobody has.', 'tags' => ['x'],
            'requires' => ['connectors' => [['role' => 'search', 'types' => ['zznowhere']]]],
        ], ['guidelines.md' => "### {$bad}\n\nx\n"]));
        foreach (['installPlan', 'install'] as $how) {
            try { $cat->$how($bad, $project); $this->fail($how); }
            catch (ConceptException $e) { $this->assertStringContainsString('zznowhere', $e->getMessage()); $this->assertStringContainsString('could never be bound', $e->getMessage()); }
        }
        $this->assertDirectoryDoesNotExist("{$project}/concepts/{$bad}");
    }
}
