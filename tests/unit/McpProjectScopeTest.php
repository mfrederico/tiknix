<?php
/**
 * X-Tiknix-Project — a project scope over core's MCP gateway (Mcp::resolveProjectScope).
 *
 *   accessible    the owner (or a teammate) of an active project with a directory → {id, slug, dir}
 *   refusals      no API key; an unknown or inactive slug; a member who cannot access it;
 *                 a slug that is not a slug; a project whose directory is missing
 *
 * The task tools then select that project's data/workbench.db (BaseTool::selectWorkbenchDb),
 * which is how a project with no MCP server of its own — a sidecar — gets a task board.
 * Scratch in-memory database.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\Mcp;

class McpProjectScopeTest extends ConceptsTestCase {

    private const DB = 'mcp-scope-test';
    private string $projectDir;

    protected function setUp(): void {
        parent::setUp();
        if (!\is_core_install()) $this->markTestSkipped('only the control plane takes a project scope');
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        // A real directory the slug resolves to (Model_Instance::dirFrom → ROOT/<slug>.tiknix):
        // the test slug is unique to this run so no live project is named.
        $this->projectDir = \Model_Instance::dirFrom($this->slug(), 'tiknix');
        @mkdir($this->projectDir, 0700, true);
    }

    protected function tearDown(): void {
        @rmdir($this->projectDir);
        Bean::selectDatabase('default');
        parent::tearDown();
    }

    private function slug(): string { return 'scopetest-' . substr(md5((string) getmypid()), 0, 6); }

    private function project(int $ownerId, string $status = 'active'): \RedBeanPHP\OODBBean {
        $m = Bean::dispense('member'); $m->email = "o{$ownerId}@example.com"; $m->username = "o{$ownerId}"; $m->level = 100; $m->status = 'active'; Bean::store($m);
        $inst = Bean::dispense('instance'); $inst->slug = $this->slug(); $inst->app = 'tiknix'; $inst->displayName = 'Scope'; $inst->status = $status;
        $m->ownInstanceList[] = $inst; Bean::store($m);
        return $inst;
    }

    public function testTheOwnerOfAnActiveProjectGetsItsDirectory(): void {
        $inst = $this->project(1);
        $scope = Mcp::resolveProjectScope($this->slug(), (int) $inst->memberId, true);
        $this->assertSame([(int) $inst->id, $this->slug(), $this->projectDir], [$scope['id'], $scope['slug'], $scope['dir']]);
    }

    public function testRefusals(): void {
        $inst = $this->project(1);
        $owner = (int) $inst->memberId;
        foreach ([
            [$this->slug(), $owner, false, 'needs an API-key caller'],
            ['../etc', $owner, true, 'not a project slug'],
            ['no-such-project', $owner, true, "no active project"],
            [$this->slug(), $owner + 99, true, 'cannot access'],
        ] as [$slug, $member, $key, $msg]) {
            try { Mcp::resolveProjectScope($slug, $member, $key); $this->fail("accepted {$slug} for member {$member}"); }
            catch (\RuntimeException $e) { $this->assertStringContainsString($msg, $e->getMessage()); }
        }
        $inst->status = 'deleted'; Bean::store($inst);
        $this->expectExceptionMessage('no active project');
        Mcp::resolveProjectScope($this->slug(), $owner, true);
    }

    public function testAProjectWithoutADirectoryIsRefused(): void {
        $inst = $this->project(1);
        rmdir($this->projectDir);
        $this->expectExceptionMessage('has no directory');
        Mcp::resolveProjectScope($this->slug(), (int) $inst->memberId, true);
    }
}
