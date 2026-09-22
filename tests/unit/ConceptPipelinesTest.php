<?php
/**
 * Pipeline definitions as a concept part (COMPONENTS_PLAN.md). The runtime stays core; a
 * concept ships pipelines/<slug>.json declared in provides.pipelines. The install's own
 * pipelines/ is read first and wins, and a Loader built the old way — one argument — reads
 * exactly what it always did (lead-machine must not notice).
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\ConceptLint;
use app\ConceptManifest;
use app\Concepts;
use app\Pipeline\Loader;

class ConceptPipelinesTest extends ConceptsTestCase {

    private function def(string $slug, string $msg = 'hi'): string {
        return json_encode(['slug' => $slug, 'name' => $slug, 'steps' => [
            ['name' => 'say', 'type' => 'transform', 'config' => ['mode' => 'template', 'input' => $msg], 'on_success' => 'exit'],
        ]], JSON_PRETTY_PRINT) . "\n";
    }

    private function ownPipeline(string $slug, string $msg = 'own'): void {
        $this->put("{$this->root}/pipelines/{$slug}.json", $this->def($slug, $msg));
    }

    /** A concept shipping one pipeline "<name>-hello"; enabled. Returns [Concepts, name, slug]. */
    private function conceptWithPipeline(?string $slug = null, ?string $body = null): array {
        $n = $this->uniq('pk');
        $slug ??= "{$n}-hello";
        $this->concept($n, ['provides' => ['pipelines' => [$slug]]], ["pipelines/{$slug}.json" => $body ?? $this->def($slug, 'from concept')]);
        $this->on = [$n];
        return [$this->concepts(), $n, $slug];
    }

    /* ---- manifest ---- */

    public function testASlugMustCarryTheConceptName(): void {
        $m = ConceptManifest::fromArray(['name' => 'pk', 'version' => '1', 'provides' => ['pipelines' => ['pk-discovery']]], '/x', 'pk');
        $this->assertSame(['pk-discovery'], $m->pipelines);
        $this->expectExceptionMessage("must be 'pk-<something>'");
        ConceptManifest::fromArray(['name' => 'pk', 'version' => '1', 'provides' => ['pipelines' => ['discovery']]], '/x', 'pk');
    }

    public function testTheRootManifestMayNotDeclarePipelines(): void {
        $this->expectExceptionMessage('may only declare "hosts"');
        ConceptManifest::fromArray(['name' => 'root', 'version' => '1', 'provides' => ['pipelines' => ['root-x']]], '/x', 'root');
    }

    /* ---- the Loader: the old way is unchanged; concepts are appended ---- */

    public function testAOneArgumentLoaderReadsExactlyWhatItAlwaysDid(): void {
        $this->ownPipeline('alpha'); $this->ownPipeline('beta');
        [$c] = $this->conceptWithPipeline();
        $plain = new Loader($this->root);
        $aware = new Loader($this->root, []);
        $this->assertSame(['alpha', 'beta'], array_keys($plain->all()));
        $this->assertSame($plain->all(), $aware->all(), 'no sources = identical output');
        $this->assertNull($plain->originOf('alpha'));
        $this->assertNotEmpty($c->pipelineSources(), 'the concept does ship one; the plain loader simply was not told');
    }

    public function testEnabledConceptPipelinesAppearAfterTheInstancesOwnAndAreTraceable(): void {
        $this->ownPipeline('alpha');
        [$c, $n, $slug] = $this->conceptWithPipeline();
        $l = new Loader($this->root, $c->pipelineSources());
        $this->assertSame(['alpha', $slug], array_keys($l->all()));
        $this->assertSame('from concept', $l->get($slug)['steps'][0]['config']['input']);
        $this->assertSame($n, $l->originOf($slug));
        $this->assertNull($l->originOf('alpha'));

        $this->on = [];
        $l2 = new Loader($this->root, $this->concepts()->pipelineSources());
        $this->assertSame(['alpha'], array_keys($l2->all()), 'disabled: absent');
        $this->assertNull($l2->get($slug));
    }

    public function testTheInstancesOwnFileWinsOverAConceptsSameSlug(): void {
        [$c, $n, $slug] = $this->conceptWithPipeline();
        $this->ownPipeline($slug, 'own wins');
        $l = new Loader($this->root, $c->pipelineSources());
        $this->assertSame('own wins', $l->get($slug)['steps'][0]['config']['input']);
        $this->assertNull($l->originOf($slug), 'it is the instance\'s now');
        $this->assertNotEmpty(preg_grep('/already has pipelines\//', $c->verify($n)), 'and verify() names the clash');
    }

    public function testSaveGoesToTheConceptsFileAndDeleteIsRefused(): void {
        [$c, $n, $slug] = $this->conceptWithPipeline();
        $l = new Loader($this->root, $c->pipelineSources());
        $def = $l->get($slug); $def['name'] = 'edited';
        $file = $l->save($def);
        $this->assertSame("{$this->root}/concepts/{$n}/pipelines/{$slug}.json", $file, 'the project\'s own copy, not a shadowing instance file');
        $this->assertFileDoesNotExist("{$this->root}/pipelines/{$slug}.json");
        $this->assertSame('edited', $l->get($slug)['name']);
        $this->expectExceptionMessage("belongs to concept '{$n}'");
        $l->delete($slug);
    }

    public function testABadSourceIsRefusedLoudly(): void {
        $this->expectException(\InvalidArgumentException::class);
        new Loader($this->root, ['x-y' => ['concept' => 'x']]);
    }

    /* ---- verify ---- */

    public function testVerifyAcceptsAGoodPipelineAndNamesEveryFault(): void {
        [$c, $n] = $this->conceptWithPipeline();
        $this->assertSame([], $c->verify($n));

        $n2 = $this->uniq('pk');
        $this->concept($n2, ['provides' => ['pipelines' => ["{$n2}-gone", "{$n2}-json", "{$n2}-slug", "{$n2}-step"]]], [
            "pipelines/{$n2}-json.json" => '{nope',
            "pipelines/{$n2}-slug.json" => $this->def("{$n2}-other"),
            "pipelines/{$n2}-step.json" => json_encode(['slug' => "{$n2}-step", 'steps' => [['name' => 'x', 'type' => 'nosuchstep', 'config' => []]]]),
        ]);
        $this->on = [$n, $n2];
        $p = $this->concepts()->verify($n2);
        $this->assertNotEmpty(preg_grep("/{$n2}-gone', but .* does not exist/", $p));
        $this->assertNotEmpty(preg_grep('/is not valid JSON/', $p));
        $this->assertNotEmpty(preg_grep("/has slug '{$n2}-other'/", $p));
        $this->assertNotEmpty(preg_grep("/unknown type 'nosuchstep'/", $p));
    }

    public function testTwoConceptsCannotClaimTheSameSlug(): void {
        [$c, $n, $slug] = $this->conceptWithPipeline();
        $n2 = $this->uniq('pk');
        // the second concept's slug must carry ITS name, so the clash can only come through requires/rename games;
        // simulate the registry seeing the same slug twice by declaring the first's slug under a bad prefix is refused by the manifest itself
        $this->expectExceptionMessage("must be '{$n2}-<something>'");
        $this->concept($n2, ['provides' => ['pipelines' => [$slug]]]);
        ConceptManifest::load("{$this->root}/concepts/{$n2}", $n2);
    }

    /* ---- readers of another install ---- */

    public function testSourcesForAnotherInstallComeFromItsFlags(): void {
        [$c, $n, $slug] = $this->conceptWithPipeline();
        $src = Concepts::pipelineSourcesFor("{$this->root}/concepts", [$n]);
        $this->assertSame([$slug], array_keys($src));
        $this->assertSame($n, $src[$slug]['concept']);
        $this->assertSame([], Concepts::pipelineSourcesFor("{$this->root}/concepts", []));
        $this->assertSame([], Concepts::pipelineSourcesFor("{$this->root}/concepts", ['ghost']), 'an enabled-but-absent concept contributes nothing here');
    }

    /* ---- lint + catalog ---- */

    public function testLintChecksDeclarationJsonSlugAndSteps(): void {
        $n = $this->uniq('pk');
        $this->concept($n, ['provides' => ['pipelines' => ["{$n}-ok", "{$n}-missing"]]], [
            'guidelines.md' => "### {$n}\n\nRun it.\n",
            "pipelines/{$n}-ok.json" => $this->def("{$n}-ok"),
            "pipelines/{$n}-stray.json" => $this->def("{$n}-stray"),
            "pipelines/{$n}-bad.json" => '{nope',
        ]);
        $msgs = array_map(fn($f) => $f['file'] . ': ' . $f['message'], ConceptLint::check("{$this->root}/concepts/{$n}", []));
        $this->assertSame([], preg_grep("/{$n}-ok\\.json/", $msgs), implode(' | ', $msgs));
        $this->assertNotEmpty(preg_grep("/{$n}-stray\\.json: is in pipelines\\/ but provides.pipelines does not declare/", $msgs));
        $this->assertNotEmpty(preg_grep("/{$n}-missing\\.json: is declared in provides.pipelines but missing/", $msgs));
        $this->assertNotEmpty(preg_grep("/{$n}-bad\\.json: is in pipelines/", $msgs), 'undeclared first; its JSON is not even read');
        $this->assertContains("pipelines/{$n}-ok.json", \app\ConceptCatalog::collect("{$this->root}/concepts/{$n}"));
    }
}
