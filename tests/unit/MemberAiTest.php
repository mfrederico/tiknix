<?php
/**
 * Your AI: a member's key, recognised, tested and chosen only when it answers (lib/MemberAi.php); and the app side, where the key
 * becomes a provider exactly as the AI agents page makes one (runtime lib/AgentProviders.php).
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\AgentProviders;
use app\Bean;
use app\MemberAi;

class MemberAiTest extends ConceptsTestCase {

    private const DB = 'yourai-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    /** A provider that answers (or not), with what it was sent recorded. */
    private function http(bool $answers, array &$seen = []): callable {
        return function (string $method, string $url, array $headers, ?string $body = null) use ($answers, &$seen) {
            $seen[] = [$method, $url, $headers];
            if (!$answers) return [401, json_encode(['error' => ['message' => 'invalid x-api-key']])];
            return str_ends_with($url, '/v1/models') ? [200, json_encode(['data' => [['id' => 'claude-sonnet-5-5']]])] : [200, json_encode(['content' => [['type' => 'text', 'text' => 'p']]])];
        };
    }

    public function testAKeySaysWhoItIsFromOnlyWhenItsLettersMeanOneProvider(): void {
        $this->assertSame('anthropic', MemberAi::detect(' sk-ant-api03-abc'));
        $this->assertSame('openrouter', MemberAi::detect('sk-or-v1-abc'));
        $this->assertSame('', MemberAi::detect('sk-abc123'), 'sk- alone is several providers');
        $this->assertSame('', MemberAi::detect('0123456789abcdef.ABCDEF'));
    }

    public function testAKeyThatAnswersBecomesYourAiAndIsStoredEncrypted(): void {
        $seen = [];
        $r = MemberAi::save(7, 'anthropic', 'sk-ant-test-123', '', '', false, $this->http(true, $seen));
        $this->assertTrue($r['ok']); $this->assertTrue($r['chosen']);
        $c = MemberAi::chosen(7);
        $this->assertSame($r['id'], (int) $c->id);
        $this->assertSame('anthropic', (string) $c->preset);
        $this->assertSame('claude-sonnet-5-5', (string) $c->workerModel, "the app's model for it, not a tier alias the API refuses");
        $this->assertStringNotContainsString('sk-ant-test-123', (string) $c->keyEnc);
        $this->assertSame('sk-ant-test-123', $c->box()->apiKey());
        $this->assertContains('https://api.anthropic.com/v1/messages', array_column($seen, 1), 'it was actually asked');
    }

    public function testAKeyThatDoesNotAnswerIsKeptButNeverChosen(): void {
        $r = MemberAi::save(7, 'openrouter', 'sk-or-bad', '', '', false, $this->http(false));
        $this->assertFalse($r['ok']); $this->assertFalse($r['chosen']);
        $this->assertStringContainsString('invalid x-api-key', $r['message'], "the provider's own reason, said");
        $this->assertStringContainsString('refused this key', $r['message'], 'and in plain words first');
        $this->assertNull(MemberAi::chosen(7));
        $this->assertSame(1, (int) Bean::count('modelconnection', 'member_id = 7'));
    }

    public function testANewKeyForTheSameProviderReplacesTheOld(): void {
        MemberAi::save(7, 'anthropic', 'sk-ant-one', '', '', false, $this->http(true));
        MemberAi::save(7, 'anthropic', 'sk-ant-two', '', '', false, $this->http(true));
        $this->assertSame(1, (int) Bean::count('modelconnection', 'member_id = 7'));
        $this->assertSame('sk-ant-two', MemberAi::chosen(7)->box()->apiKey());
    }

    public function testWhatIsRefusedBeforeAnythingIsStored(): void {
        foreach ([['nvidia', 'nvapi-x', '', '', 'Choose who provides'], ['anthropic', '  ', '', '', 'Paste your API key'],
                  ['custom', 'k', 'https://models.example.com', '', 'name the model'], ['custom', 'k', 'http://127.0.0.1:11434', 'm', 'root-only']] as [$p, $k, $e, $m, $says]) {
            try { MemberAi::save(7, $p, $k, $e, $m, false, $this->http(true)); $this->fail("accepted {$p}"); }
            catch (\InvalidArgumentException $ex) { $this->assertStringContainsString($says, $ex->getMessage()); }
        }
        $this->assertSame(0, (int) Bean::count('modelconnection'));
    }

    public function testAProjectThatIsNotInAContainerIsRefusedBeforeAnyKeyIsRead(): void {
        MemberAi::save(7, 'anthropic', 'sk-ant-x', '', '', false, $this->http(true));
        $inst = Bean::dispense('instance'); $inst->slug = 'p'; $inst->ctKind = ''; $inst->ctDomain = '';
        $r = MemberAi::share($inst, MemberAi::chosen(7));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('container', $r['message']);
    }

    // ---- the app side: the key becomes a provider, and the builder ----

    public function testTheAppConnectsItAsTheBuilderEvenOverAnotherDefault(): void {
        $first = AgentProviders::connect('openrouter', 'sk-or-1');
        $this->assertTrue($first['builder'], 'the first Build provider is the builder');
        $mine = AgentProviders::connect('anthropic', 'sk-ant-9', '', '', true, 'Your AI, from Tiknix account #7');
        $this->assertTrue($mine['builder'], 'asked for: it replaces the builder there was');
        $this->assertSame('anthropic', (string) \Model_Agent::defaultAgent('build')->name);
        $this->assertSame('Your AI, from Tiknix account #7', (string) \Model_Agent::byName('anthropic')->description);
        $again = AgentProviders::connect('openrouter', 'sk-or-2');
        $this->assertFalse($again['builder'], 'a later provider not asked for leaves the builder alone');
    }

    public function testTheAppRefusesWhatTheAgentsPageWouldRefuse(): void {
        foreach ([['claude', 'k', 'Unknown provider'], ['anthropic', '', 'paste its API key'], ['custom', 'k', 'give its URL']] as [$p, $k, $says]) {
            try { AgentProviders::connect($p, $k); $this->fail("connected {$p}"); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString($says, $e->getMessage()); }
        }
        $this->assertSame(0, (int) Bean::count('agent'));
    }
}
