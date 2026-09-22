<?php
/**
 * Named agents (COMPONENTS_PLAN.md): the bean's rules, the key round-trip, the composed
 * system prompt, the CLI flag, and the openai path end-to-end against a local fake endpoint.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\EngineRegistry;
use app\Pipeline\OpenAiChat;
use app\Pipeline\Steps\AgentStep;

class AgentsTest extends ConceptsTestCase {

    private const DB = 'agents-test';
    private static $server = null;
    private static string $port = '8897';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    public static function tearDownAfterClass(): void {
        if (self::$server) { proc_terminate(self::$server); proc_close(self::$server); self::$server = null; }
    }

    /** A fake OpenAI-compatible endpoint: echoes the system + user messages back as the answer. */
    private function fakeEndpoint(): string {
        if (self::$server === null) {
            $router = sys_get_temp_dir() . '/tiknix-fake-openai-' . getmypid() . '.php';
            file_put_contents($router, <<<'PHP'
<?php
$in = json_decode(file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-key-123') { http_response_code(401); echo json_encode(['error' => ['message' => 'bad key']]); exit; }
if (($in['model'] ?? '') === 'explode') { http_response_code(500); echo "<html>oops</html>"; exit; }
$sys = ''; $user = '';
foreach ($in['messages'] ?? [] as $m) { if ($m['role'] === 'system') $sys = $m['content']; if ($m['role'] === 'user') $user = $m['content']; }
echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => "SYS[{$sys}] USER[{$user}] MODEL[{$in['model']}]"]]], 'usage' => ['total_tokens' => 7]]);
PHP);
            self::$server = proc_open('php -S 127.0.0.1:' . self::$port . ' ' . escapeshellarg($router), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            usleep(400000);
        }
        return 'http://127.0.0.1:' . self::$port . '/v1';
    }

    private function agent(array $in, string $key = ''): \RedBeanPHP\OODBBean {
        $a = Bean::dispense('agent');
        $a->box()->fill($in + ['kind' => 'cli', 'timeout' => 60]);
        if ($key !== '') $a->box()->setApiKey($key);
        $a->box()->setDefault(!empty($in['is_default']));
        Bean::store($a);
        return $a;
    }

    /* ---- the bean ---- */

    public function testRulesNameKindEndpointTimeout(): void {
        $this->assertSame([], \Model_Agent::problems(['name' => 'data-append', 'kind' => 'cli', 'timeout' => 60]));
        $p = \Model_Agent::problems(['name' => 'Bad Name', 'kind' => 'openai', 'endpoint' => 'nope', 'model' => '', 'timeout' => 2]);
        $this->assertCount(4, $p, implode(' | ', $p));
        $this->agent(['name' => 'taken']);
        $this->assertNotEmpty(preg_grep("/already exists/", \Model_Agent::problems(['name' => 'taken', 'kind' => 'cli', 'timeout' => 60])));
    }

    public function testTheKeyRoundTripsEncryptedAndIsNeverInTheSummary(): void {
        $a = $this->agent(['name' => 'keyed'], 'sk-secret-value');
        $this->assertNotSame('sk-secret-value', (string) $a->apiKeyEnc, 'stored encrypted');
        $this->assertSame('sk-secret-value', $a->box()->apiKey());
        $this->assertSame('set', $a->box()->keyStatus());
        $this->assertStringNotContainsString('secret', json_encode($a->box()->summary()));
        $a->apiKeyEnc = 'not-a-ciphertext'; Bean::store($a);
        $this->assertSame('unreadable', $a->box()->keyStatus(), 'a key that will not decrypt is a fault, not unset');
    }

    public function testOnlyOneDefault(): void {
        $a = $this->agent(['name' => 'one', 'is_default' => true]);
        $b = $this->agent(['name' => 'two', 'is_default' => true]);
        $this->assertSame('two', (string) \Model_Agent::defaultAgent()->name);
        $this->assertSame(0, (int) Bean::load('agent', $a->id)->isDefault);
    }

    /* ---- composition ---- */

    public function testTheSystemPromptIsAgentThenStep(): void {
        $this->assertSame("be terse\n\nreply in french", AgentStep::composeSystem("be terse\n", "  reply in french "));
        $this->assertSame('', AgentStep::composeSystem('', ''));
        $cmd = EngineRegistry::agentCommand('claude', 'hello', 'sonnet', ['bin' => '/x/claude', 'system' => 'be terse']);
        $this->assertStringContainsString("--append-system-prompt 'be terse'", $cmd);
        $this->assertStringNotContainsString('--append-system-prompt', EngineRegistry::agentCommand('claude', 'hello', 'sonnet', ['bin' => '/x/claude']));
    }

    /* ---- the openai path, for real, against a fake endpoint ---- */

    public function testAnOpenaiAgentRunsWithItsKeyPrePromptAndModel(): void {
        $ep = $this->fakeEndpoint();
        $this->agent(['name' => 'writer', 'kind' => 'openai', 'endpoint' => $ep, 'model' => 'fake-1', 'pre_prompt' => 'Sound human.', 'is_default' => true], 'test-key-123');
        $r = (new AgentStep())->run(['agent' => 'writer', 'prompt' => 'Write a post.', 'system' => 'Max 3 lines.'], ['root' => $this->root]);
        $this->assertTrue($r['ok'], $r['stderr']);
        $this->assertSame("SYS[Sound human.\n\nMax 3 lines.] USER[Write a post.] MODEL[fake-1]", $r['output']);
        $this->assertSame(['agent' => 'writer', 'kind' => 'openai', 'model' => 'fake-1', 'usage' => ['total_tokens' => 7]], $r['meta']);

        // blank agent = the default; the step's model overrides the agent's
        $r = (new AgentStep())->run(['prompt' => 'hi', 'model' => 'fake-2'], ['root' => $this->root]);
        $this->assertTrue($r['ok']); $this->assertStringContainsString('MODEL[fake-2]', $r['output']);
    }

    public function testOpenaiFailuresCarryTheEndpointsAnswer(): void {
        $ep = $this->fakeEndpoint();
        $this->agent(['name' => 'wrongkey', 'kind' => 'openai', 'endpoint' => $ep, 'model' => 'fake-1'], 'nope');
        $r = (new AgentStep())->run(['agent' => 'wrongkey', 'prompt' => 'hi'], ['root' => $this->root]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString("agent 'wrongkey': HTTP 401 from {$ep}/chat/completions: bad key", $r['stderr']);

        $this->agent(['name' => 'boom', 'kind' => 'openai', 'endpoint' => $ep, 'model' => 'explode'], 'test-key-123');
        $r = (new AgentStep())->run(['agent' => 'boom', 'prompt' => 'hi'], ['root' => $this->root]);
        $this->assertStringContainsString('HTTP 500', $r['stderr']);
        $this->assertStringContainsString('<html>oops</html>', $r['stderr']);

        $r = (new AgentStep())->run(['agent' => 'ghost', 'prompt' => 'hi'], ['root' => $this->root]);
        $this->assertStringContainsString("agent 'ghost' is not configured on this app", $r['stderr']);
    }

    public function testParseRules(): void {
        $this->assertFalse(OpenAiChat::parse('{"choices":[]}', 200, 'u')['ok']);
        $this->assertFalse(OpenAiChat::parse('not json', 200, 'u')['ok']);
        $this->assertSame('hi', OpenAiChat::parse('{"choices":[{"message":{"content":"hi"}}]}', 200, 'u')['text']);
    }
}
