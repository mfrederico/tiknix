<?php
/**
 * Model connections (MODEL_CONNECTIONS_PLAN.md) — the parts that decide what a build runs on
 * and whose key it spends:
 *
 *   problems()        a form that could never run is refused, with the reason; localhost/LAN
 *                     endpoints are ROOT-only; model ids that could break a shell are refused
 *   key custody       round-trips encrypted; unreadable is a fault (keyStatus + runProblems),
 *                     never "no key"
 *   engineDef()       anthropic protocol = headless claude; openai = chat only (no launcher)
 *   test()            lists models, then makes the one call a builder makes first; reports
 *                     the endpoint's own words on failure
 *   materialize()     endpoint.env + auth-token in the state dir, 0600, no key in endpoint.env
 *   directEnvShell()  only for mc-<id>; bearer endpoints get ANTHROPIC_API_KEY set EMPTY
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\AgentContext;
use app\Bean;

class ModelConnectionTest extends ConceptsTestCase {

    private const DB = 'mc-test';

    protected function setUp(): void {
        parent::setUp();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function form(array $over = []): array {
        return array_merge([
            'name' => 'Ollama Cloud', 'preset' => 'ollama', 'protocol' => 'anthropic',
            'base_url' => 'https://ollama.com', 'auth' => 'bearer',
            'planner_model' => 'qwen3.5:397b', 'worker_model' => 'qwen3.5:397b',
            'auditor_model' => 'qwen3.5:397b', 'resolver_model' => 'qwen3.5:397b', 'haiku_model' => '',
        ], $over);
    }

    private function conn(array $over = [], string $key = 'sk-test-123'): \Model_Modelconnection {
        $b = Bean::dispense('modelconnection');
        $m = $b->box();
        $m->fill($this->form($over), 7);
        $m->setKey($key);
        Bean::store($b);
        return $m;
    }

    public function testAGoodFormHasNoProblems(): void {
        $this->assertSame([], \Model_Modelconnection::problems($this->form(), false));
    }

    public function testFormRules(): void {
        $p = implode(' | ', \Model_Modelconnection::problems($this->form([
            'name' => '', 'protocol' => 'grpc', 'auth' => 'magic', 'base_url' => 'ftp://x', 'worker_model' => "qwen'; rm -rf /",
        ]), false));
        foreach (['name is required', 'protocol must be', 'auth must be', 'base URL must be', "worker model 'qwen'; rm -rf /'"] as $needle) {
            $this->assertStringContainsString($needle, $p);
        }
    }

    public function testLocalEndpointsAreRootOnly(): void {
        $local = $this->form(['base_url' => 'http://127.0.0.1:11434', 'auth' => 'none']);
        $member = implode(' ', \Model_Modelconnection::problems($local, false));
        $this->assertStringContainsString('root-only', $member);
        $this->assertSame([], \Model_Modelconnection::problems($local, true), 'ROOT may point at this server');
    }

    public function testTheKeyRoundTripsAndIsNeverInTheDefinition(): void {
        $m = $this->conn();
        $this->assertSame('sk-test-123', $m->apiKey());
        $this->assertSame('set', $m->keyStatus());
        $this->assertStringNotContainsString('sk-test-123', json_encode($m->engineDef()));
        $this->assertStringNotContainsString('sk-test-123', (string) $m->unbox()->keyEnc, 'stored encrypted');
    }

    public function testAnUnreadableKeyIsAFaultNotAbsent(): void {
        $m = $this->conn();
        $m->unbox()->keyEnc = 'not-a-ciphertext';
        $this->assertSame('unreadable', $m->keyStatus());
        $this->assertStringContainsString('cannot be decrypted', implode(' ', $m->runProblems()));
    }

    public function testAMissingKeyBlocksBearerButNotNone(): void {
        $this->assertNotSame([], $this->conn([], '')->runProblems());
        $this->assertSame([], $this->conn(['auth' => 'none'], '')->runProblems());
    }

    public function testProtocolDecidesWhetherItCanBuild(): void {
        $a = $this->conn()->engineDef();
        $this->assertTrue($a['headless_ready']);
        $this->assertSame('claude', $a['cli_flavor']);
        $this->assertSame('qwen3.5:397b', $a['planner_model']);
        $o = $this->conn(['protocol' => 'openai', 'base_url' => 'https://api.example.com/v1'])->engineDef();
        $this->assertFalse($o['headless_ready'], 'an OpenAI-only endpoint never runs a build agent');
        $this->assertStringContainsString('chat only', implode(' ', $this->conn(['protocol' => 'openai'])->runProblems()));
    }

    public function testEngineNames(): void {
        $this->assertSame(12, \Model_Modelconnection::idFromEngine('mc-12'));
        foreach (['claude', 'mc-', 'mc-0', 'mc-12x', 'xmc-12'] as $bad) $this->assertNull(\Model_Modelconnection::idFromEngine($bad), $bad);
    }

    public function testTestListsModelsThenMakesTheBuildersFirstCall(): void {
        $m = $this->conn();
        $calls = [];
        $http = function ($method, $url, $headers, $body) use (&$calls) {
            $calls[] = [$method, $url, $headers, $body];
            if ($method === 'GET') return [200, json_encode(['data' => [['id' => 'qwen3.5:397b'], ['id' => 'glm-5.3']]])];
            return [200, json_encode(['content' => [['type' => 'text', 'text' => 'p']]])];
        };
        $r = $m->test($http);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(['glm-5.3', 'qwen3.5:397b'], $r['models']);
        $this->assertSame('https://ollama.com/v1/models', $calls[0][1]);
        $this->assertSame('https://ollama.com/v1/messages', $calls[1][1]);
        $this->assertContains('Authorization: Bearer sk-test-123', $calls[1][2]);
        $this->assertSame('qwen3.5:397b', json_decode($calls[1][3], true)['model']);
        $this->assertSame(1, (int) $m->unbox()->lastTestOk);
    }

    public function testAFailedTestCarriesTheEndpointsWords(): void {
        $m = $this->conn();
        $r = $m->test(fn($method) => $method === 'GET'
            ? [200, json_encode(['data' => []])]
            : [402, json_encode(['error' => ['message' => 'this model is not included in your free usage']])]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('HTTP 402', $r['message']);
        $this->assertStringContainsString('not included in your free usage', $r['message']);
        $this->assertSame(0, (int) $m->unbox()->lastTestOk);
    }

    public function testAnthropicsOwnKeyGoesInXApiKey(): void {
        $h = $this->conn(['base_url' => 'https://api.anthropic.com', 'auth' => 'api_key'], 'sk-ant-xyz')->headers();
        $this->assertContains('x-api-key: sk-ant-xyz', $h);
        $this->assertEmpty(array_filter($h, fn($x) => str_starts_with($x, 'Authorization:')));
    }

    public function testMaterializeWritesTheEndpointAndThePrivateKeyFile(): void {
        $dir = sys_get_temp_dir() . '/mc-state-' . bin2hex(random_bytes(4));
        try {
            $this->conn(['haiku_model' => ''])->materialize($dir);
            $env = file_get_contents("{$dir}/endpoint.env");
            $this->assertStringContainsString("ANTHROPIC_BASE_URL=https://ollama.com\n", $env);
            $this->assertStringContainsString("TIKNIX_MC_AUTH=bearer\n", $env);
            $this->assertStringContainsString("ANTHROPIC_DEFAULT_HAIKU_MODEL=qwen3.5:397b\n", $env, 'no fast model = the worker model, stated');
            $this->assertStringNotContainsString('sk-test-123', $env, 'the key is not in endpoint.env');
            $this->assertSame("sk-test-123\n", file_get_contents("{$dir}/auth-token"));
            $this->assertSame('0600', substr(sprintf('%o', fileperms("{$dir}/auth-token")), -4));
        } finally {
            @unlink("{$dir}/endpoint.env"); @unlink("{$dir}/auth-token"); @rmdir($dir);
        }
    }

    public function testMaterializeRefusesAConnectionThatCannotRun(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no key');
        $this->conn([], '')->materialize(sys_get_temp_dir() . '/mc-never');
    }

    public function testDirectRunsSourceTheFilesAndBlankTheAnthropicKey(): void {
        $this->assertSame('', AgentContext::directEnvShell('claude', '/x'));
        $sh = AgentContext::directEnvShell('mc-3', '/state/3');
        $this->assertStringContainsString(". '/state/3/endpoint.env'", $sh);
        $this->assertStringContainsString('export ANTHROPIC_AUTH_TOKEN="$(cat \'/state/3/auth-token\')"; export ANTHROPIC_API_KEY=;', $sh);
        $this->assertStringNotContainsString('sk-', $sh, 'no key in the script');
    }
}
