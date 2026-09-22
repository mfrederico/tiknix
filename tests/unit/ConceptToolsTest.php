<?php
/**
 * MCP tools as a concept part (COMPONENTS_PLAN.md, "Concept MCP tools").
 *
 *   declared, not discovered · named <concept>_<x> · absent (not denied) when the concept
 *   is disabled or the caller is below the tool's level · nobody without a member sees one
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\ConceptCatalog;
use app\ConceptLint;
use app\ConceptManifest;
use app\Concepts;
use app\mcptools\ToolLoader;

class ConceptToolsTest extends ConceptsTestCase {

    private function toolSource(string $concept, string $class, string $toolName, string $ns = null): string {
        $ns ??= "app\\concepts\\{$concept}\\mcptools";
        return "<?php\nnamespace {$ns};\nclass {$class} extends \\app\\mcptools\\BaseTool {\n"
             . "    public static string \$name = '{$toolName}';\n"
             . "    public static string \$description = 'echoes';\n"
             . "    public static array \$inputSchema = ['type' => 'object', 'properties' => ['s' => ['type' => 'string']]];\n"
             . "    public function execute(array \$args): string { return 'echo:' . (\$args['s'] ?? ''); }\n}\n";
    }

    /** A concept with one tool at $level, declared, enabled. Returns [Concepts, tool name]. */
    private function conceptWithTool(string $level = 'MEMBER', ?string $toolName = null, bool $declare = true): array {
        $n = $this->uniq('tk');
        $toolName ??= "{$n}_echo";
        $this->concept($n, $declare ? ['provides' => ['tools' => [['class' => 'EchoTool', 'level' => $level]]]] : [],
            ['mcptools/EchoTool.php' => $this->toolSource($n, 'EchoTool', $toolName)]);
        $this->on = [$n];
        return [$this->concepts(), $n, $toolName];
    }

    /** A loader over an EMPTY core dir (no core tools), with this concept's tools registered. */
    private function loader(Concepts $c): ToolLoader {
        mkdir("{$this->root}/mcptools-empty");
        $l = new ToolLoader("{$this->root}/mcptools-empty");
        foreach ($c->tools() as $t) $l->register($t['class'], ['level' => $t['level'], 'concept' => $t['concept']]);
        return $l;
    }

    private function member(int $level): object { return (object) ['id' => 7, 'level' => $level]; }

    /* ---- manifest ---- */

    public function testToolsAreDeclaredWithClassAndLevel(): void {
        $m = ConceptManifest::fromArray(['name' => 'tk', 'version' => '1', 'provides' => ['tools' => [['class' => 'ATool', 'level' => 'ADMIN']]]], '/x', 'tk');
        $this->assertSame([['concept' => 'tk', 'where' => 'provides.tools[0]', 'class' => 'ATool', 'level' => 50]], $m->tools);
    }

    public function testAToolWithoutALevelIsRefused(): void {
        $this->expectExceptionMessage('provides.tools[0].level is required');
        ConceptManifest::fromArray(['name' => 'tk', 'version' => '1', 'provides' => ['tools' => [['class' => 'ATool']]]], '/x', 'tk');
    }

    public function testTheRootManifestMayNotDeclareTools(): void {
        $this->expectExceptionMessage('may only declare "hosts"');
        ConceptManifest::fromArray(['name' => 'root', 'version' => '1', 'provides' => ['tools' => [['class' => 'ATool', 'level' => 'ROOT']]]], '/x', 'root');
    }

    /* ---- registry ---- */

    public function testAnEnabledConceptOffersItsDeclaredTool(): void {
        [$c, $n, $tool] = $this->conceptWithTool();
        $this->assertSame([['class' => "app\\concepts\\{$n}\\mcptools\\EchoTool", 'level' => 100, 'concept' => $n]], $c->tools());
        $this->assertTrue(class_exists("app\\concepts\\{$n}\\mcptools\\EchoTool"), 'autoloaded from concepts/<n>/mcptools/');
        $this->assertSame([], $c->verify($n));
    }

    public function testADisabledConceptOffersNothing(): void {
        [$c, $n] = $this->conceptWithTool();
        $this->on = [];
        $this->assertSame([], $c->tools());
    }

    public function testAnUndeclaredFileIsNotATool(): void {
        [$c, $n] = $this->conceptWithTool('MEMBER', null, false);
        $this->assertSame([], $c->tools(), 'a file in mcptools/ that provides.tools does not name is nothing');
    }

    public function testVerifyRejectsAWrongName(): void {
        [$c, $n] = $this->conceptWithTool('MEMBER', 'echo');
        $problems = $c->verify($n);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString("must be named '{$n}_<something>'", $problems[0]);
    }

    public function testVerifyRejectsAMissingFileAndAWrongBaseClass(): void {
        $n = $this->uniq('tk');
        $this->concept($n, ['provides' => ['tools' => [['class' => 'GoneTool', 'level' => 'MEMBER'], ['class' => 'PlainTool', 'level' => 'MEMBER']]]],
            ['mcptools/PlainTool.php' => "<?php\nnamespace app\\concepts\\{$n}\\mcptools;\nclass PlainTool { public static string \$name = '{$n}_plain'; }\n"]);
        $this->on = [$n];
        $problems = $this->concepts()->verify($n);
        $this->assertCount(2, $problems);
        $this->assertStringContainsString('GoneTool.php does not exist', $problems[0]);
        $this->assertStringContainsString('must extend app\\mcptools\\BaseTool', $problems[1]);
    }

    /* ---- loader: absent, not denied ---- */

    public function testAMemberAtLevelSeesAndRunsTheTool(): void {
        [$c, $n, $tool] = $this->conceptWithTool('MEMBER');
        $l = $this->loader($c)->setAuth($this->member(100), null);
        $this->assertSame([$tool], $l->getNames());
        $this->assertSame($tool, $l->getDefinitions()[0]['name']);
        $this->assertTrue($l->has($tool));
        $this->assertSame('echo:hi', $l->execute($tool, ['s' => 'hi']));
        $this->assertSame($n, $l->conceptOf($tool));
    }

    public function testACallerBelowTheLevelCannotSeeIt(): void {
        [$c, $n, $tool] = $this->conceptWithTool('ADMIN');
        $l = $this->loader($c)->setAuth($this->member(100), null);
        $this->assertSame([], $l->getNames());
        $this->assertSame([], $l->getDefinitions());
        $this->assertFalse($l->has($tool));
        $this->assertNull($l->getDefinition($tool));
        $this->expectExceptionMessage("Unknown tool: {$tool}");
        $l->execute($tool, []);
    }

    public function testNoMemberMeansNoConceptTools(): void {
        // A broker key, or an unauthenticated tools/list: there is no level to check.
        [$c, $n, $tool] = $this->conceptWithTool('PUBLIC');
        $l = $this->loader($c);
        $this->assertSame([], $l->getNames());
        $l->setAuth(null, (object) ['keyClass' => 'broker']);
        $this->assertSame([], $l->getNames());
    }

    public function testCoreToolsStayVisibleToEveryone(): void {
        $l = new ToolLoader(dirname(__DIR__, 2) . '/mcptools');
        $this->assertContains('reuse_digest', $l->getNames(), 'no auth set: core tools are ungated by the loader');
    }

    public function testRegisteringTheSameNameTwiceIsAFault(): void {
        [$c, $n, $tool] = $this->conceptWithTool();
        $l = $this->loader($c);
        $this->put("{$this->root}/concepts/{$n}/mcptools/OtherTool.php", $this->toolSource($n, 'OtherTool', $tool));
        $this->expectExceptionMessage("tool name '{$tool}' is already taken");
        $l->register("app\\concepts\\{$n}\\mcptools\\OtherTool", ['level' => 100, 'concept' => $n]);
    }

    /* ---- lint + catalog ---- */

    public function testLintChecksNamespaceNameAndDeclaration(): void {
        $n = $this->uniq('tk');
        $this->concept($n, ['provides' => ['tools' => [['class' => 'EchoTool', 'level' => 'MEMBER']]]], [
            'mcptools/EchoTool.php'  => $this->toolSource($n, 'EchoTool', "{$n}_echo"),
            'mcptools/BadTool.php'   => $this->toolSource($n, 'BadTool', 'echo', 'app\\mcptools'),
        ]);
        $msgs = array_map(fn($f) => $f['file'] . ': ' . $f['message'], ConceptLint::check("{$this->root}/concepts/{$n}", []));
        $this->assertSame([], preg_grep('/EchoTool/', $msgs), 'the declared, well-named tool is clean: ' . implode(' | ', $msgs));
        $bad = preg_grep('/BadTool/', $msgs);
        $this->assertCount(3, $bad, implode(' | ', $msgs));
        $this->assertNotEmpty(preg_grep('/must declare namespace/', $bad));
        $this->assertNotEmpty(preg_grep("/tool name 'echo' must be/", $bad));
        $this->assertNotEmpty(preg_grep('/provides.tools does not declare/', $bad));
    }

    public function testTheCatalogAcceptsMcptools(): void {
        $n = $this->uniq('tk');
        $dir = $this->concept($n, ['provides' => ['tools' => [['class' => 'EchoTool', 'level' => 'MEMBER']]]],
            ['mcptools/EchoTool.php' => $this->toolSource($n, 'EchoTool', "{$n}_echo")]);
        $files = ConceptCatalog::collect($dir);
        $this->assertContains('mcptools/EchoTool.php', $files);
    }
}
