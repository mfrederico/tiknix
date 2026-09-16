<?php
/**
 * ProjectQuota::canUseTeams() — the team-collaboration gate.
 *
 * Collaboration is a paid-project perk: the free tier is a solo workspace. This locks that
 * rule down without standing up the whole controller — an in-memory RedBean holds one member
 * per tier and we assert the exact predicate controls/Teams.php::invite() calls.
 *
 * Run: vendor/bin/phpunit --bootstrap vendor/autoload.php tests/ProjectQuotaTeamsTest.php
 */

namespace Tests;

use app\Bean;
use app\ProjectQuota;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

class ProjectQuotaTeamsTest extends TestCase {

    public static function setUpBeforeClass(): void {
        // Connection lifecycle — the one place raw R:: is expected (as in bootstrap.php).
        if (!Bean::hasDatabase('default')) {
            R::setup('sqlite::memory:');   // fluid mode: tables/columns appear on first store
        }
    }

    public static function tearDownAfterClass(): void {
        R::nuke();
    }

    protected function setUp(): void {
        // Every test states the enforcement flag it depends on; default it ON here so a test
        // that forgets is testing the enforced path, not a silently-disabled one.
        \Flight::set('billing.enforce_project_cap', true);
    }

    /** A member on the given tier; '' means a brand-new account with no tier set yet. */
    private function member(string $tier): int {
        $m = Bean::dispense('member');
        $m->email = $tier . '-' . uniqid() . '@example.com';
        if ($tier !== '') $m->planTier = $tier;
        return (int) Bean::store($m);
    }

    public function testFreeTierOwnerIsBlocked(): void {
        $this->assertFalse(ProjectQuota::canUseTeams($this->member('free')),
            'a free-tier owner cannot add collaborators');
        $this->assertFalse(ProjectQuota::canUseTeams($this->member('')),
            'a brand-new (untiered) account resolves to free → solo');
    }

    public function testPaidAndLegacyOwnersAreAllowed(): void {
        $this->assertTrue(ProjectQuota::canUseTeams($this->member('pro')),
            'a paid account gets collaboration');
        $this->assertTrue(ProjectQuota::canUseTeams($this->member('legacy')),
            'grandfathered/legacy accounts keep collaboration');
    }

    public function testEnforcementOffAllowsEveryone(): void {
        \Flight::set('billing.enforce_project_cap', false);
        $this->assertTrue(ProjectQuota::canUseTeams($this->member('free')),
            'billing enforcement off → behave as before, no team restriction');
    }
}
