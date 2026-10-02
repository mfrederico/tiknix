<?php
/**
 * lib/ProjectNav — what the sidebar's project panel offers one member on one project.
 *
 *   role      the owner and a team admin are offered every page of the project's app; a team
 *             member only its dashboard (they are signed in there at MEMBER); no role, none
 *   scope     [sidecar.<name>] scope: project unless it says platform; a typo is refused
 *   premium   a premium plugin with no [<feature>] available setting is a fault, not "soon"
 */

namespace tests\unit;

use app\ProjectNav;
use PHPUnit\Framework\TestCase;

class ProjectNavTest extends TestCase {

    protected function tearDown(): void {
        foreach (['sidecar.t1.scope', 'sidecar.t1.premium'] as $k) \Flight::set($k, null);
    }

    public function testAppPagesFollowTheRoleOnTheProject(): void {
        $paths = fn(?int $level) => array_column(ProjectNav::appPages($level), 0);
        $all = array_column(ProjectNav::APP_PAGES, 0);
        $this->assertSame($all, $paths(1), 'the owner');
        $this->assertSame($all, $paths(50), 'a team owner/admin');
        $this->assertSame(['/dashboard'], $paths(100), 'a team member is signed in at MEMBER: the admin pages would 403');
        $this->assertSame([], $paths(null), 'no role on the project: no page of its app');
        $this->assertNotContains('/connections', $all, 'Connections is one link: Tiknix asks the app');
    }

    public function testRoleNames(): void {
        $this->assertSame('Owner', ProjectNav::roleName(1));
        $this->assertSame('Team admin', ProjectNav::roleName(50));
        $this->assertSame('Team member', ProjectNav::roleName(100));
        // Every level AppAccess can vouch for has a name.
        $levels = (new \ReflectionClassConstant(\app\AppAccess::class, 'TEAM_LEVEL'))->getValue();
        foreach ($levels as $role => $level) $this->assertArrayHasKey($level, ProjectNav::ROLES, "team role '{$role}'");
    }

    public function testScopeIsProjectUnlessItSaysPlatformAndATypoIsRefused(): void {
        $this->assertSame('project', ProjectNav::scope('t1'));
        \Flight::set('sidecar.t1.scope', 'platform');
        $this->assertSame('platform', ProjectNav::scope('t1'));
        \Flight::set('sidecar.t1.scope', 'plaform');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[sidecar.t1] scope');
        ProjectNav::scope('t1');
    }

    public function testPremiumIsOptIn(): void {
        $this->assertFalse(ProjectNav::premium('t1'));
        \Flight::set('sidecar.t1.premium', '1');
        $this->assertTrue(ProjectNav::premium('t1'));
    }

    /** The live config: QA Testing is premium and says whether it is on offer; Insights is the platform's. */
    public function testTheInstalledPluginsAreDeclared(): void {
        $ini = parse_ini_file(\app\Paths::root() . '/conf/config.example.ini', true);
        $this->assertTrue(filter_var($ini['sidecar.qa']['premium'] ?? false, FILTER_VALIDATE_BOOLEAN));
        $this->assertArrayHasKey('available', $ini['qa'] ?? [], 'a premium plugin must say whether it is on offer');
        $this->assertSame('platform', $ini['sidecar.insights']['scope'] ?? null);
    }
}
