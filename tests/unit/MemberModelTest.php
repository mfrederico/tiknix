<?php
/**
 * Pipeline agent steps on the project owner's model connection (MODEL_CONNECTIONS_PLAN.md
 * phase 4): the app never holds the key — it POSTs the call to core and polls.
 *
 *   MemberModel     start → poll → result; core's own words on refusal; a job that never
 *                   finishes is reported by id, not waited on forever; an unknown status is
 *                   a fault
 *   Model_Agent     the 'member' kind needs a connection; other kinds carry no connection_ref
 *   call()          anthropic Messages (system split out, text blocks joined) and the
 *                   max_tokens-before-any-text case said plainly
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\Pipeline\MemberModel;

class MemberModelTest extends ConceptsTestCase {

    private const DB = 'member-model-test';

    protected function setUp(): void {
        parent::setUp();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    /** A fake core: scripted answers per path, and a record of what was asked. */
    private function core(array $script, array &$seen): MemberModel {
        $http = function ($method, $url, $headers, $body, $timeout) use (&$script, &$seen) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $seen[] = [$method, $path, $headers, $body];
            $next = array_shift($script[$path]);
            return [$next[0], json_encode($next[1])];
        };
        return new MemberModel('https://core.test', 'brk_test', $http, fn($s) => null);
    }

    public function testACallStartsThenPollsUntilDone(): void {
        $seen = [];
        $mm = $this->core([
            '/brokerinfo/modelcall'   => [[200, ['job' => 41, 'status' => 'running']]],
            '/brokerinfo/modelresult' => [[200, ['status' => 'running']], [200, ['status' => 'done', 'text' => 'hello', 'model' => 'qwen3.5:397b', 'usage' => ['output_tokens' => 2]]]],
        ], $seen);
        $r = $mm->call(7, '', 'be brief', 'say hello', 120);
        $this->assertTrue($r['ok'], $r['error']);
        $this->assertSame('hello', $r['text']);
        $this->assertSame(41, $r['job']);
        $this->assertSame('qwen3.5:397b', $r['model'], 'the model core actually used');
        $this->assertSame(['POST', '/brokerinfo/modelcall'], [$seen[0][0], $seen[0][1]]);
        $this->assertContains('Authorization: Bearer brk_test', $seen[0][2]);
        $sent = json_decode($seen[0][3], true);
        $this->assertSame([7, 'be brief', 'say hello'], [$sent['connection'], $sent['system'], $sent['prompt']]);
        $this->assertCount(3, $seen, 'one start, two polls');
    }

    public function testCoresRefusalIsReportedInItsOwnWords(): void {
        $seen = [];
        $mm = $this->core(['/brokerinfo/modelcall' => [[403, ['success' => false, 'message' => "Model connection #7 is not available to this project's pipelines"]]]], $seen);
        $r = $mm->call(7, '', '', 'x', 60);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('HTTP 403', $r['error']);
        $this->assertStringContainsString('not available to this project', $r['error']);
    }

    public function testAFailedCallCarriesTheEndpointsError(): void {
        $seen = [];
        $mm = $this->core([
            '/brokerinfo/modelcall'   => [[200, ['job' => 5]]],
            '/brokerinfo/modelresult' => [[200, ['status' => 'failed', 'error' => 'POST … answered HTTP 402: not included in your free usage']]],
        ], $seen);
        $r = $mm->call(7, '', '', 'x', 60);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('402', $r['error']);
    }

    public function testAJobThatNeverFinishesIsNamedNotAwaitedForever(): void {
        $clock = 1000; $polls = 0;
        $http = function ($method, $url) use (&$polls) {
            if (str_contains($url, 'modelcall')) return [200, json_encode(['job' => 9])];
            $polls++;
            return [200, json_encode(['status' => 'running'])];
        };
        // Each "sleep" advances the fake clock; the loop must stop at timeout + 30 s grace.
        $mm = new MemberModel('https://core.test', 'brk_test', $http, function ($s) use (&$clock) { $clock += $s; }, function () use (&$clock) { return $clock; });
        $r = $mm->call(1, '', '', 'x', 60);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no result for job 9 after 60s', $r['error']);
        $this->assertGreaterThan(5, $polls);
        $this->assertLessThan(40, $polls, 'bounded by the deadline, backing off to 5 s');
    }

    public function testAnUnknownStatusIsAFault(): void {
        $seen = [];
        $mm = $this->core([
            '/brokerinfo/modelcall'   => [[200, ['job' => 3]]],
            '/brokerinfo/modelresult' => [[200, ['status' => 'queued-somewhere']]],
        ], $seen);
        $r = $mm->call(7, '', '', 'x', 60);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString("unknown status 'queued-somewhere'", $r['error']);
    }

    public function testMissingBrokerConfigNamesTheFile(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conf/broker.ini');
        MemberModel::forInstall(sys_get_temp_dir() . '/no-such-install-' . bin2hex(random_bytes(3)));
    }

    public function testTheMemberKindNeedsAConnection(): void {
        $base = ['name' => 'writer', 'kind' => 'member', 'timeout' => 600];
        $this->assertStringContainsString("owner's model connections", implode(' ', \Model_Agent::problems($base)));
        $this->assertSame([], \Model_Agent::problems($base + ['connection_ref' => 3]));
        $a = Bean::dispense('agent');
        $a->box()->fill(['name' => 'x', 'kind' => 'cli', 'connection_ref' => 3, 'timeout' => 600]);
        $this->assertSame(0, (int) $a->connectionRef, 'only the member kind carries a connection');
    }

    public function testAnthropicCallSendsSystemSeparatelyAndJoinsText(): void {
        $c = Bean::dispense('modelconnection');
        $c->box()->fill(['name' => 'x', 'protocol' => 'anthropic', 'base_url' => 'https://api.example.com', 'auth' => 'bearer',
                         'planner_model' => 'm', 'worker_model' => 'worker-m', 'auditor_model' => 'm', 'resolver_model' => 'm'], 1);
        $c->box()->setKey('k');
        $sent = null;
        $r = $c->box()->call('SYS', 'hi', '', 100, 30, function ($m, $url, $h, $body) use (&$sent) {
            $sent = json_decode($body, true);
            return [200, json_encode(['content' => [['type' => 'thinking', 'thinking' => '…'], ['type' => 'text', 'text' => 'he'], ['type' => 'text', 'text' => 'llo']], 'usage' => ['output_tokens' => 2]])];
        });
        $this->assertTrue($r['ok'], $r['error']);
        $this->assertSame('hello', $r['text']);
        $this->assertSame('worker-m', $sent['model'], "blank model = the connection's build model");
        $this->assertSame('SYS', $sent['system']);
        $this->assertSame([['role' => 'user', 'content' => 'hi']], $sent['messages']);

        $r2 = $c->box()->call('', 'hi', 'm', 16, 30, fn() => [200, json_encode(['content' => [['type' => 'thinking', 'thinking' => '…']], 'stop_reason' => 'max_tokens'])]);
        $this->assertFalse($r2['ok']);
        $this->assertStringContainsString('before writing any text', $r2['error']);
    }
}
