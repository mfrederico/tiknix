<?php
/**
 * Agent types: an agent's kind says how it is called, so what it can be picked for — Build,
 * Chat, Decision, Embeddings. One default per type; a caller gets the named agent or its type's
 * default, and an agent of the wrong type is refused by name, never called the wrong way.
 * The decision and embedding calls run for real against a local fake endpoint.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\Pipeline\Decision;
use app\Pipeline\Embedding;
use app\Pipeline\StepRegistry;
use app\Pipeline\Steps\AgentStep;
use app\Pipeline\Steps\DecideStep;
use app\Pipeline\Steps\EmbedStep;

class AgentTypesTest extends ConceptsTestCase {

    private const DB = 'agent-types-test';
    private static $server = null;
    private static string $port = '8896';

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

    /** A fake server: OpenAI-shaped /v1/embeddings (optionally demanding input_type) and Ollama's /v1/systemone. */
    private function fake(): string {
        if (self::$server === null) {
            $router = sys_get_temp_dir() . '/tiknix-fake-types-' . getmypid() . '.php';
            file_put_contents($router, <<<'PHP'
<?php
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (str_ends_with($path, '/embeddings')) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer k-1') { http_response_code(401); echo json_encode(['error' => ['message' => 'bad key']]); exit; }
    if (($in['model'] ?? '') === 'strict' && !in_array($in['input_type'] ?? null, ['query', 'passage'], true)) { http_response_code(400); echo json_encode(['detail' => "'input_type' parameter is required"]); exit; }
    if (($in['model'] ?? '') === 'chatty') { echo json_encode(['msg' => 'Unknown Model, please check the model code.']); exit; }
    echo json_encode(['data' => [['embedding' => [strlen($in['input']) / 100, ($in['input_type'] ?? '') === 'query' ? 1 : 0, 0.5]]]]); exit;
}
if (str_ends_with($path, '/v1/systemone')) {
    if (($in['model'] ?? '') === 'absent') { http_response_code(404); echo json_encode(['error' => "model 'absent' not found"]); exit; }
    $a = [];
    foreach ($in['questions'] as $n => $q) {
        if ($n === 'skipme') continue;
        if ($q['type'] === 'choice') { $k = array_key_first($q['criteria']); $a[$n] = ['type' => 'choice', 'choice' => $k, 'probabilities' => [$k => 0.9], 'confidence' => 0.8]; }
        if ($q['type'] === 'noul')   $a[$n] = ['type' => 'noul', 'noul' => str_contains(json_encode($in['state']), 'refund') ? 0.97 : 0.03];
        if ($q['type'] === 'score')  $a[$n] = ['type' => 'score', 'score' => 1.5, 'legend' => $q['criteria'], 'confidence' => 0.4];
    }
    echo json_encode(['model' => $in['model'], 'answers' => $a, 'usage' => ['input_tokens' => 12, 'output_tokens' => 4]]); exit;
}
http_response_code(404); echo '404 page not found';
PHP);
            self::$server = proc_open('php -S 127.0.0.1:' . self::$port . ' ' . escapeshellarg($router), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            usleep(400000);
        }
        return 'http://127.0.0.1:' . self::$port;
    }

    private function agent(array $in, string $key = ''): \RedBeanPHP\OODBBean {
        $a = Bean::dispense('agent');
        $a->box()->fill($in + ['kind' => 'cli', 'timeout' => 60]);
        if ($key !== '') $a->box()->setApiKey($key);
        $a->box()->setDefault(!empty($in['is_default']));
        Bean::store($a);
        return $a;
    }

    /* ---- types and defaults ---- */

    public function testEveryKindHasATypeAndEveryTypeALabel(): void {
        $this->assertSame(\Model_Agent::KINDS, array_keys(\Model_Agent::TYPES));
        foreach (\Model_Agent::TYPES as $kind => $type) $this->assertArrayHasKey($type, \Model_Agent::TYPE_LABELS, $kind);
        foreach (\Model_Agent::PRESETS as $k => $p) $this->assertContains($p['kind'], \Model_Agent::KINDS, "preset {$k}");
        $this->expectException(\InvalidArgumentException::class);
        \Model_Agent::kindsOf('rerank');   // coming soon is not a type yet
    }

    public function testOneDefaultPerTypeAndTheBuildOneIsTheBuilder(): void {
        $ep = 'https://x.example/v1';
        $this->agent(['name' => 'zai', 'is_default' => true]);
        $this->agent(['name' => 'chatty', 'kind' => 'openai', 'endpoint' => $ep, 'model' => 'm', 'is_default' => true]);
        $this->agent(['name' => 'vec', 'kind' => 'embeddings', 'endpoint' => $ep, 'model' => 'e', 'is_default' => true]);
        $this->assertSame('zai', (string) \Model_Agent::defaultAgent()->name, 'a default of another type does not take the builder');
        $this->assertSame('chatty', (string) \Model_Agent::defaultAgent('chat')->name);
        $this->assertSame('vec', (string) \Model_Agent::defaultAgent('embeddings')->name);
        $this->assertNull(\Model_Agent::defaultAgent('decision'));
        $this->agent(['name' => 'kimi', 'is_default' => true]);
        $this->assertSame('kimi', (string) \Model_Agent::defaultAgent()->name);
        $this->assertSame(['chatty', 'kimi', 'vec'], Bean::getCol('SELECT name FROM agent WHERE is_default = 1 ORDER BY name'), 'the new builder replaced only the old builder');
    }

    public function testPickIsTheNamedAgentOrItsTypesDefaultAndRefusesTheWrongType(): void {
        $this->agent(['name' => 'zai', 'is_default' => true]);
        $this->agent(['name' => 'vec', 'kind' => 'embeddings', 'endpoint' => 'https://x.example/v1', 'model' => 'e']);
        $this->assertSame('vec', (string) \Model_Agent::pick('vec', 'embeddings')->name);
        foreach ([['zai', 'embeddings', "'zai' is a Build agent; this needs a Embeddings agent"], ['nobody', 'embeddings', "no agent named 'nobody'"], ['', 'embeddings', 'no default Embeddings agent'], ['', 'decision', 'no default Decision agent']] as [$name, $type, $says]) {
            try { \Model_Agent::pick($name, $type); $this->fail("picked for {$name}/{$type}"); }
            catch (\RuntimeException $e) { $this->assertStringContainsString($says, $e->getMessage()); }
        }
    }

    public function testRulesForTheNewKinds(): void {
        $this->assertSame([], \Model_Agent::problems(['name' => 'vec', 'kind' => 'embeddings', 'endpoint' => 'https://api.openai.com/v1', 'model' => 'text-embedding-3-small', 'timeout' => 60]));
        $this->assertSame([], \Model_Agent::problems(['name' => 'judge', 'kind' => 'decision', 'endpoint' => 'http://10.0.0.5:11434', 'model' => 'tev1', 'timeout' => 60]));
        $this->assertCount(2, \Model_Agent::problems(['name' => 'vec', 'kind' => 'embeddings', 'endpoint' => '', 'model' => '', 'timeout' => 60]));
        $this->assertCount(2, \Model_Agent::problems(['name' => 'judge', 'kind' => 'decision', 'endpoint' => 'nope', 'model' => '', 'timeout' => 60]));
        $a = $this->agent(['name' => 'nv', 'kind' => 'embeddings', 'endpoint' => 'https://integrate.api.nvidia.com/v1/', 'model' => 'm', 'input_type' => '1']);
        $this->assertSame(['https://integrate.api.nvidia.com/v1', 1, 'embeddings'], [(string) $a->endpoint, (int) $a->inputType, $a->box()->summary()['type']]);
        $this->assertSame(0, (int) $this->agent(['name' => 'chat', 'kind' => 'openai', 'endpoint' => 'https://x.example/v1', 'model' => 'm', 'input_type' => '1'])->inputType, 'only an embeddings agent carries it');
    }

    public function testTheProviderCardsAndTheirCompanions(): void {
        $nv = \Model_Agent::PRESETS['nvidia'];
        $this->assertSame(['openai', 'embeddings'], [$nv['kind'], $nv['also']['kind']], 'NVIDIA is chat + embeddings: it has no /v1/messages, so it cannot build');
        foreach (\app\Agents::PROVIDER_CARDS as $k) $this->assertArrayHasKey($k, \Model_Agent::PRESETS);
        $this->assertSame(['Rerank', 'Speech', 'Image, audio & video', 'Safety'], array_keys(\app\Agents::COMING_SOON));
    }

    /* ---- a prompt goes to an agent that takes prompts ---- */

    public function testAnAgentStepRefusesADecisionOrEmbeddingsAgentAndBlankNeverPicksOne(): void {
        $this->agent(['name' => 'vec', 'kind' => 'embeddings', 'endpoint' => $this->fake() . '/v1', 'model' => 'e', 'is_default' => true], 'k-1');
        $r = (new AgentStep())->run(['agent' => 'vec', 'prompt' => 'hi'], ['root' => $this->root]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString("'vec' is a Embeddings agent: use a 'embed' step", $r['stderr']);
        $this->assertNull(\Model_Agent::defaultAgent('build') ?? \Model_Agent::defaultAgent('chat'), 'what a blank agent step resolves: an embeddings default is not it');
    }

    /* ---- embeddings ---- */

    public function testEmbedOnTheDefaultOrNamedAgentWithAndWithoutInputType(): void {
        $ep = $this->fake() . '/v1';
        $this->agent(['name' => 'plain', 'kind' => 'embeddings', 'endpoint' => $ep, 'model' => 'small', 'is_default' => true], 'k-1');
        $this->agent(['name' => 'nv', 'kind' => 'embeddings', 'endpoint' => $ep, 'model' => 'strict', 'input_type' => '1'], 'k-1');
        $r = Embedding::forAgent('', 'hello there', 'query');
        $this->assertSame(['plain', 'small', [0.11, 0.0, 0.5]], [$r['agent'], $r['model'], $r['vector']], 'no input_type sent by an agent that does not ask for it');
        $this->assertSame(1.0, Embedding::forAgent('nv', 'hello there', 'query')['vector'][1], 'the query marker reached the endpoint');

        $r = (new EmbedStep())->run(['agent' => 'nv', 'text' => 'hello there'], ['root' => $this->root]);
        $this->assertTrue($r['ok'], $r['stderr']);
        $this->assertSame([3, 'strict', 0.0], [$r['output']['dims'], $r['output']['model'], $r['output']['vector'][1]], 'passage is the default purpose');
    }

    public function testEmbeddingFailuresSayWhatTheEndpointSaid(): void {
        $ep = $this->fake() . '/v1';
        $this->agent(['name' => 'strict', 'kind' => 'embeddings', 'endpoint' => $ep, 'model' => 'strict'], 'k-1');
        $this->agent(['name' => 'wrongkey', 'kind' => 'embeddings', 'endpoint' => $ep, 'model' => 'small'], 'nope');
        $this->agent(['name' => 'chatty', 'kind' => 'embeddings', 'endpoint' => $ep, 'model' => 'chatty'], 'k-1');
        foreach ([['strict', "HTTP 400 from {$ep}/embeddings: 'input_type' parameter is required"], ['wrongkey', 'HTTP 401'], ['chatty', 'answered without data[0].embedding: Unknown Model']] as [$name, $says]) {
            $r = (new EmbedStep())->run(['agent' => $name, 'text' => 'x'], ['root' => $this->root]);
            $this->assertFalse($r['ok']); $this->assertStringContainsString($says, $r['stderr']);
        }
        $this->assertStringContainsString('nothing to embed', (new EmbedStep())->run(['agent' => 'strict', 'text' => '  '], ['root' => $this->root])['stderr']);
        try { Embedding::parse('{"data":[{"embedding":[0.1,"x"]}]}', 200, 'u'); $this->fail('a non-number passed'); } catch (\RuntimeException $e) { $this->assertStringContainsString('element #1', $e->getMessage()); }
    }

    /* ---- decisions ---- */

    public function testDecideAnswersEveryQuestionAndTheOutputIsWhatABranchReads(): void {
        $this->agent(['name' => 'judge', 'kind' => 'decision', 'endpoint' => $this->fake(), 'model' => 'tev1', 'is_default' => true]);
        $q = ['team' => ['type' => 'choice', 'instructions' => 'Which team?', 'criteria' => ['billing' => 'Payments', 'technical' => 'Bugs']],
              'refund' => ['type' => 'noul', 'instructions' => 'Asks for a refund?'],
              'urgency' => ['type' => 'score', 'instructions' => 'How urgent?', 'criteria' => ['Routine', 'Soon', 'Urgent']]];
        // as the editor stores it: the state as text, the questions as a JSON string
        $r = (new DecideStep())->run(['state' => 'Please refund me.', 'questions' => json_encode($q)], ['root' => $this->root]);
        $this->assertTrue($r['ok'], $r['stderr']);
        $this->assertSame(['billing', 0.97, 1.5], [$r['output']['team'], $r['output']['refund'], $r['output']['urgency']]);
        $this->assertSame(0.8, $r['output']['_detail']['team']['confidence']);
        $this->assertSame(['agent' => 'judge', 'kind' => 'decision', 'model' => 'tev1', 'usage' => ['input_tokens' => 12, 'output_tokens' => 4]], $r['meta']);
        // as a pipeline file has it: both as objects
        $r = (new DecideStep())->run(['agent' => 'judge', 'state' => ['email' => 'hello'], 'questions' => ['refund' => $q['refund']]], ['root' => $this->root]);
        $this->assertSame(0.03, $r['output']['refund']);
    }

    public function testDecisionRequestsAreCheckedBeforeTheyAreSentAndFailuresAreNamed(): void {
        $this->agent(['name' => 'judge', 'kind' => 'decision', 'endpoint' => $this->fake() . '/v1/', 'model' => 'tev1']);
        $this->agent(['name' => 'nomodel', 'kind' => 'decision', 'endpoint' => $this->fake(), 'model' => 'absent']);
        $ok = ['type' => 'noul', 'instructions' => 'x?'];
        foreach ([
            [['state' => '', 'questions' => '{}'], 'no state'],
            [['state' => '{bad', 'questions' => '{}'], 'not valid JSON'],
            [['state' => 's', 'questions' => 'nope'], 'questions are not valid JSON'],
            [['state' => 's', 'questions' => []], 'at least one question'],
            [['state' => 's', 'questions' => ['bad name' => $ok]], "question's name"],
            [['state' => 's', 'questions' => ['q' => ['type' => 'maybe', 'instructions' => 'x']]], 'type must be choice, noul or score'],
            [['state' => 's', 'questions' => ['q' => ['type' => 'noul']]], 'needs instructions'],
            [['state' => 's', 'questions' => ['q' => ['type' => 'choice', 'instructions' => 'x', 'criteria' => ['a', 'b']]]], 'a choice needs criteria as an object'],
            [['state' => 's', 'questions' => ['q' => ['type' => 'score', 'instructions' => 'x', 'criteria' => ['only']]]], 'a score needs criteria as a list'],
            [['state' => 's', 'questions' => ['skipme' => $ok]], "did not answer the question 'skipme'"],
            [['state' => 's', 'questions' => ['q' => $ok], 'agent' => 'nomodel'], "HTTP 404", 'is this an Ollama server'],
            [['state' => 's', 'questions' => ['q' => $ok], 'agent' => ''], 'no default Decision agent'],
        ] as $case) {
            $r = (new DecideStep())->run($case[0] + ['agent' => 'judge'], ['root' => $this->root]);
            $this->assertFalse($r['ok'], json_encode($case[0]));
            foreach (array_slice($case, 1) as $says) $this->assertStringContainsString($says, $r['stderr']);
        }
        $this->assertSame('http://h:11434/v1/systemone', Decision::url('http://h:11434'));
        $this->assertSame('http://h:11434/v1/systemone', Decision::url('http://h:11434/v1/'));
    }

    public function testTheStepsAreRegisteredWithTypedAgentFields(): void {
        foreach (['agent' => ['cli', 'openai', 'member'], 'embed' => ['embeddings'], 'decide' => ['decision']] as $type => $kinds) {
            $step = StepRegistry::get($type);
            $this->assertNotNull($step, $type);
            $field = array_values(array_filter($step::schema()['fields'], fn($f) => $f['name'] === 'agent'))[0];
            $this->assertSame(['agents', $kinds], [$field['dynamic'], $field['kinds']], "{$type}: the editor offers these kinds and no others");
            foreach ($kinds as $k) $this->assertContains($k, \Model_Agent::KINDS);
        }
    }
}
