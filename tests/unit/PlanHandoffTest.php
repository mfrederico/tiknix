<?php
/**
 * PlanHandoff — a plan from the Get-started wizard becomes a project here.
 *
 *   offer       a well-formed package is kept behind a 48-hex token, status 'offered', with the
 *               plan's sha; each malformed field is refused by name
 *   token       byToken finds it; a non-token or an unknown one is null
 *   state       what the wizard's status page may know — no member, no project until claimed
 *   commit      PLAN.md and .aibuilder/blueprint.json land in a project and are committed as
 *               the member (blueprint.json force-added past .gitignore)
 *   claimed     a second create on a claimed offer is told which project it became
 *   slugBase    "My Shop Portal!" → my-shop-portal; a name with nothing usable is ''
 *   afterLogin  a token waiting in the session is consumed once
 *
 * Scratch in-memory database; a throwaway git repository for the commit.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\PlanHandoff;

class PlanHandoffTest extends ConceptsTestCase {

    private const DB = 'plan-handoff-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        \Flight::set('app.baseurl', 'https://core.example.com');
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function package(array $over = []): array {
        return $over + [
            'name' => 'Shop Portal', 'plan_md' => "# PLAN\n\nPhase 1 …\n",
            'blueprint_json' => json_encode(['id' => 'client-portal', 'modules' => []]),
            'brief_json' => json_encode(['business' => 'A shop', 'goal' => 'Orders online']),
            'resume_url' => 'https://start.example.com/start/resume/abc',
        ];
    }

    public function testOfferKeepsThePackageBehindAToken(): void {
        $h = PlanHandoff::offer(7, $this->package());
        $this->assertMatchesRegularExpression(PlanHandoff::TOKEN_RE, (string) $h->token);
        $this->assertSame(['offered', 'Shop Portal', 7, hash('sha256', "# PLAN\n\nPhase 1 …\n")], [(string) $h->status, (string) $h->name, (int) $h->sourceInstanceRef, (string) $h->planSha]);
        $this->assertSame('https://core.example.com/handoff/claim/' . $h->token, PlanHandoff::claimUrl($h));
        $this->assertSame('https://core.example.com/handoff/state?token=' . $h->token, PlanHandoff::stateUrl($h));
        $this->assertSame((int) $h->id, (int) PlanHandoff::byToken((string) $h->token)->id);
        $this->assertNull(PlanHandoff::byToken('not-a-token'));
        $this->assertNull(PlanHandoff::byToken(str_repeat('0', 48)));
        $this->assertSame(['status' => 'offered', 'name' => 'Shop Portal', 'project_slug' => '', 'project_url' => ''], PlanHandoff::state($h));
    }

    public function testEachMalformedFieldIsRefusedByName(): void {
        foreach ([
            [['name' => ''], 'name'],
            [['name' => str_repeat('n', 61)], 'name'],
            [['plan_md' => '  '], 'plan_md'],
            [['blueprint_json' => 'nope'], 'blueprint_json'],
            [['blueprint_json' => '"a string"'], 'blueprint_json'],
            [['brief_json' => '[1,'], 'brief_json'],
            [['resume_url' => 'http://insecure.example.com/x'], 'resume_url'],
        ] as [$over, $field]) {
            try { PlanHandoff::offer(7, $this->package($over)); $this->fail("accepted bad {$field}"); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString($field, $e->getMessage()); }
        }
        try { PlanHandoff::offer(0, $this->package()); $this->fail('accepted an unbound key'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not bound', $e->getMessage()); }
        $this->assertSame(0, Bean::count('planhandoff'));
    }

    public function testCommitPlanWritesAndCommitsAsTheMember(): void {
        $repo = $this->root . '/proj.tiknix';
        mkdir($repo, 0700, true);
        exec('git -C ' . escapeshellarg($repo) . ' init -q && git -C ' . escapeshellarg($repo) . ' -c user.name=t -c user.email=t@example.com commit -q --allow-empty -m init');
        file_put_contents($repo . '/.gitignore', ".aibuilder/\n");
        PlanHandoff::commitPlan($repo, "# PLAN\n", '{"id":"client-portal"}', 'Ana López', 'ana@example.com', hash('sha256', "# PLAN\n"));
        $this->assertSame("# PLAN\n", file_get_contents($repo . '/PLAN.md'));
        $this->assertSame('{"id":"client-portal"}', file_get_contents($repo . '/.aibuilder/blueprint.json'));
        $log = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' log -1 --format="%an <%ae> %s" 2>&1'));
        $this->assertStringStartsWith('Ana López <ana@example.com> PLAN.md from the Get-started wizard (plan ', $log);
        $tracked = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' ls-files PLAN.md .aibuilder/blueprint.json'));
        $this->assertSame(".aibuilder/blueprint.json\nPLAN.md", $tracked, 'blueprint.json force-added past .gitignore');
        $this->expectExceptionMessage('not a git repository');
        PlanHandoff::commitPlan($this->root . '/nowhere', '#', '{}', 'x', 'x@example.com');
    }

    public function testAClaimedOfferIsNotCreatedTwice(): void {
        $h = PlanHandoff::offer(7, $this->package());
        $inst = Bean::dispense('instance'); $inst->slug = 'shop-portal-ab12cd'; $inst->app = 'tiknix'; $inst->status = 'active'; Bean::store($inst);
        $h->status = 'claimed'; $h->instanceRef = (int) $inst->id; $h->memberRef = 3; Bean::store($h);
        $res = PlanHandoff::create(3, $h, 'Shop Portal', 'claude');
        $this->assertFalse($res['ok']);
        $this->assertSame([409, 'shop-portal-ab12cd', 'https://shop-portal-ab12cd.tiknix.com'], [$res['code'], $res['project_slug'], $res['project_url']]);
        $this->assertSame('shop-portal-ab12cd', PlanHandoff::state($h)['project_slug']);
    }

    public function testSlugBaseAndAfterLoginTarget(): void {
        $this->assertSame('my-shop-portal', PlanHandoff::slugBase('  My Shop Portal! '));
        $this->assertSame('shop-2024', PlanHandoff::slugBase('2024 Shop 2024'));
        $this->assertSame('', PlanHandoff::slugBase('42'));
        $this->assertSame('', PlanHandoff::slugBase('!!!'));
        $_SESSION['handoff_token'] = str_repeat('a', 48);
        $this->assertSame('/handoff/claim/' . str_repeat('a', 48), PlanHandoff::afterLoginTarget());
        $this->assertNull(PlanHandoff::afterLoginTarget(), 'consumed once');
        $_SESSION['handoff_token'] = 'garbage';
        $this->assertNull(PlanHandoff::afterLoginTarget());
    }
}
