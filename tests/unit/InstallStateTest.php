<?php
/**
 * Install::isInstalled() has three "no real admin" situations that must not share an
 * answer, and the seeded password must never be a credential.
 *
 *   seeded ROOT           → not installed, the wizard runs, nothing is logged
 *   no ROOT, no history   → not installed (a new database)
 *   no ROOT, but history  → "installed" (wizard refused) + CRITICAL naming the real state
 *
 * The first case fell through to the third for a month (a provisioned instance already has
 * seeded authcontrol rows), which is how serenity ran live with ROOT = admin123.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\Install;

class InstallStateTest extends ConceptsTestCase {

    private const DB = 'install-state-test';
    /** @var array<int,array{0:string,1:array}> critical() calls */
    private array $critical = [];

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        $this->critical = [];
        $t = $this;
        \Flight::set('log', new class($t) {
            public function __construct(private InstallStateTest $t) {}
            public function critical(string $m, array $ctx = []): void { $this->t->logged($m, $ctx); }
            public function __call(string $level, array $args): void {}   // info/warning: not under test
        });
    }

    protected function tearDown(): void {
        \Flight::set('log', null);
        Bean::selectDatabase('default');
        parent::tearDown();
    }

    public function logged(string $m, array $ctx): void { $this->critical[] = [$m, $ctx]; }

    private function member(int $level, string $password, string $email = 'admin@example.com'): void {
        $m = Bean::dispense('member');
        $m->username = 'u' . $this->uniq('m');
        $m->email    = $email;
        $m->password = $password;
        $m->level    = $level;
        $m->status   = 'active';
        Bean::store($m);
    }

    /** A sign of prior life that is not the member table (settings: no FUSE hooks to appease). */
    private function history(): void {
        $s = Bean::dispense('settings');
        $s->key = 'site_name'; $s->value = 'lived in';
        Bean::store($s);
    }

    /* ---- the three situations ---- */

    public function testARealRootMeansInstalled(): void {
        $this->member(1, password_hash('a real one', PASSWORD_DEFAULT));
        $this->history();
        $this->assertTrue(Install::isInstalled());
        $this->assertSame([], $this->critical);
    }

    public function testASeededRootIsTheWizardsOwnCaseEvenWithHistory(): void {
        // Exactly what provisioning leaves behind: the seed, plus seeded permission rows.
        $this->member(1, Install::DEFAULT_HASH, 'owner@example.org');
        $this->member(100, password_hash('x', PASSWORD_DEFAULT));
        $this->history();
        $this->assertFalse(Install::isInstalled(), 'the wizard must run so the password gets set');
        $this->assertSame([], $this->critical, 'a fresh instance is not a disaster');
    }

    public function testNoRootAndNoHistoryIsANewDatabase(): void {
        $this->assertFalse(Install::isInstalled());
        $this->assertSame([], $this->critical);
    }

    public function testNoRootButHistoryRefusesTheWizardAndSaysTheTableIsEmpty(): void {
        $this->history();
        $this->assertTrue(Install::isInstalled());
        $this->assertCount(1, $this->critical);
        $this->assertStringContainsString('member table is empty', $this->critical[0][0]);
        $this->assertSame(0, $this->critical[0][1]['members']);
    }

    public function testNoRootButOtherMembersSaysSoInsteadOfClaimingAnEmptyTable(): void {
        // serenity's message said "missing or empty" about a table with four rows in it.
        $this->member(100, password_hash('x', PASSWORD_DEFAULT));
        $this->member(50, password_hash('y', PASSWORD_DEFAULT));
        $this->history();
        $this->assertTrue(Install::isInstalled());
        $this->assertCount(1, $this->critical);
        $this->assertStringContainsString('2 rows and none is level 1', $this->critical[0][0]);
        $this->assertStringContainsString('--set-level=1', $this->critical[0][0]);
        $this->assertStringNotContainsString('empty', $this->critical[0][0]);
    }

    public function testAnEmptyPasswordRootDoesNotCount(): void {
        $this->member(1, '');
        $this->assertFalse(Install::isInstalled());
    }

    /* ---- the seed is a marker, not a credential ---- */

    public function testTheSeededHashIsRecognisedExactly(): void {
        $seeded = Bean::dispense('member');
        $seeded->password = \Model_Member::SEEDED_PASSWORD_HASH;
        $this->assertTrue($seeded->passwordIsSeeded());
        $this->assertTrue(password_verify('admin123', $seeded->password), 'this is why the check must come before password_verify');

        $chosen = Bean::dispense('member');
        $chosen->password = password_hash('admin123', PASSWORD_DEFAULT);
        $this->assertFalse($chosen->passwordIsSeeded(), 'a fresh salt is a password somebody set, not the seed');

        $none = Bean::dispense('member');
        $none->password = '';
        $this->assertFalse($none->passwordIsSeeded());
    }

    public function testTheLoginsRefuseTheSeedBeforeVerifying(): void {
        // Both password logins call passwordIsSeeded() ahead of password_verify().
        foreach (['controls/Auth.php', 'controls/Mcp.php'] as $f) {
            $src = file_get_contents(dirname(__DIR__, 2) . '/' . $f);
            $seed = strpos($src, 'passwordIsSeeded()');
            $verify = strpos($src, 'password_verify($password, $member->password)');
            $this->assertNotFalse($seed, "$f does not refuse the seeded password");
            $this->assertNotFalse($verify);
            $this->assertLessThan($verify, $seed, "$f verifies before it refuses the seed");
        }
    }
}
