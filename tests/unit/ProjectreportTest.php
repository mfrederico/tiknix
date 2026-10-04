<?php
/**
 * Model_Projectreport::headline — the columns a report becomes. A number the app could not
 * measure stays null (never 0: "nothing used" is a different fact from "not measured"); the
 * disk breakdown separates what the project pays for in code from the agent's binary + state.
 */
namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

class ProjectreportTest extends ConceptsTestCase {

    private function report(array $over = []): array {
        return array_replace_recursive([
            'version' => 1, 'at' => '2026-10-04T15:00:00+00:00', 'why' => 'scheduled',
            'app' => ['runtime' => 'v2.0.0-alpha.103', 'commit' => 'abc1234', 'uncommitted' => 0],
            'readiness' => ['agent_ready' => true, 'agent_problem' => '', 'anthropic' => false,
                'providers' => [['name' => 'deepseek', 'preset' => 'deepseek', 'ready' => true], ['name' => 'zai', 'preset' => 'zai', 'ready' => false]],
                'default_agent' => 'deepseek', 'concepts' => ['crm', 'esign'], 'pipelines' => 4, 'members' => 3],
            'resources' => [
                'disk' => ['app_bytes' => 600 * 1048576, 'files' => 7000,
                           'parts' => ['code' => 40 * 1048576, 'vendor' => 20 * 1048576, 'bin' => 240 * 1048576, '.aibuilder' => 290 * 1048576, 'public/uploads' => 8 * 1048576, 'log' => 2 * 1048576],
                           'databases' => ['database/app.db' => 1638400, 'database/security.db' => 12288]],
                'memory' => ['total' => 1024 * 1048576, 'used' => 180 * 1048576, 'peak' => 1016 * 1048576],
                'cpu' => ['pct_of_core' => 3.25, 'load1' => 0.24],
                'last_hour' => ['requests' => 120, 'errors' => 1],
            ],
        ], $over);
    }

    public function testHeadlineColumns(): void {
        $h = \Model_Projectreport::headline($this->report());
        $this->assertSame('v2.0.0-alpha.103', $h['runtime']);
        $this->assertTrue($h['agent_ready']);
        $this->assertSame(['deepseek'], $h['providers'], 'only READY providers are listed; anthropic only when its account works');
        $this->assertSame(600.0, $h['disk_mb']);
        $this->assertSame(60.0, $h['disk_code_mb'], 'code + vendor');
        $this->assertSame(530.0, $h['disk_agent_mb'], 'bin + .aibuilder — the agent, not the project');
        $this->assertSame(8.0, $h['disk_uploads_mb']);
        $this->assertSame(1.6, $h['db_mb']);
        $this->assertSame(180.0, $h['mem_mb']);
        $this->assertSame(3.25, $h['cpu_pct']);
        $this->assertSame(120, $h['requests_h']);
        $this->assertSame(['crm', 'esign'], $h['concepts']);
    }

    public function testUnmeasuredStaysNull(): void {
        $h = \Model_Projectreport::headline($this->report(['resources' => ['cpu' => ['pct_of_core' => null, 'note' => 'first sample'], 'last_hour' => ['requests' => null, 'errors' => null], 'memory' => ['used' => null, 'total' => null, 'peak' => null]]]));
        $this->assertNull($h['cpu_pct']);
        $this->assertNull($h['requests_h']);
        $this->assertNull($h['mem_mb']);
    }

    public function testAnthropicCountsAsAProviderOnlyWhenItWorks(): void {
        $rep = $this->report(['readiness' => ['anthropic' => true]]);
        $rep['readiness']['providers'] = [];
        $h = \Model_Projectreport::headline($rep);
        $this->assertSame(['anthropic'], $h['providers']);
    }

    public function testRowFromReport(): void {
        self::memoryDb();
        $row = \Model_Projectreport::fromReport((object) ['id' => 42, 'slug' => 'demo'], $this->report());
        $this->assertSame(42, $row->instanceRef, 'a _ref, not an _id: the instance is hard-deleted');
        $this->assertSame('demo', $row->slug);
        $this->assertSame(1, $row->agentReady);
        $this->assertSame('deepseek', $row->providers);
        $this->assertSame(600.0, $row->diskMb);
        $this->assertSame('abc1234', $row->appCommit);
        $this->assertIsString($row->reportJson);
        $this->assertSame(1, json_decode($row->reportJson, true)['version']);
    }
}
