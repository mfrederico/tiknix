<?php
/**
 * app\PlanShape: a task that is only a permission row, a menu link or a look-over is named, with
 * where its work goes; a task that also does something, or leaves something behind, is not.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\PlanShape;

class PlanShapeTest extends TestCase {
    private const PAGE = ['id' => 't1', 'title' => 'Customers list and form', 'files' => ['controls/Cafe.php', 'views/cafe/customers.php']];

    private function kinds(array ...$tasks): array { return array_column(PlanShape::errands(array_merge([self::PAGE], $tasks)), 'kind', 'id'); }

    public function testAPermissionRowOnItsOwnIsAnErrand(): void {
        $this->assertSame(['t2' => 'permission'], $this->kinds(['id' => 't2', 'title' => 'Seed: authcontrol rows for the new cafe routes', 'files' => ['services/Schema/Seeds/52_CafePermissions.php']]));
    }

    public function testASeedThatAlsoMakesSchemaOrDataOrFixesSomethingIsWork(): void {
        foreach (['Schema + permission seed for the cafe', 'Seed: permissions and starter menu data', 'Fix the permission rows members get a 403 from'] as $title) {
            $this->assertSame([], $this->kinds(['id' => 't2', 'title' => $title, 'files' => ['services/Schema/Seeds/52_Cafe.php']]), $title);
        }
        $this->assertSame([], $this->kinds(['id' => 't2', 'title' => 'Client model and permission seed', 'files' => ['models/Model_Client.php', 'services/Schema/Seeds/52_Client.php']]));
    }

    public function testAddingALinkIsAnErrandButReshapingTheMenuIsNot(): void {
        $this->assertSame(['t2' => 'link'], $this->kinds(['id' => 't2', 'title' => "Add a 'Customers' link to the sidebar", 'files' => ['views/app/nav.php'], 'depends_on' => ['t1']]));
        $this->assertSame([], $this->kinds(['id' => 't2', 'title' => 'Design: regroup the member sidebar', 'files' => ['views/app/nav.php']]));
        $this->assertSame([], $this->kinds(['id' => 't2', 'title' => 'Move payout admin links into the Admin group', 'files' => ['views/app/nav.php']]));
    }

    public function testALookOverIsAnErrandUnlessItLeavesSomethingBehind(): void {
        $this->assertSame(['t2' => 'verify'], $this->kinds(['id' => 't2', 'title' => 'Verify the customer flow end to end', 'files' => ['controls/Cafe.php'], 'depends_on' => ['t1']]));
        $this->assertSame(['t2' => 'verify'], $this->kinds(['id' => 't2', 'title' => 'End-to-end verification of the cafe', 'files' => []]));
        $this->assertSame([], $this->kinds(['id' => 't2', 'title' => 'End-to-end smoke test of the cafe API', 'files' => ['scripts/cafe-smoke.sh']]));
        $this->assertSame([], $this->kinds(['id' => 't2', 'title' => 'Tests: cafe customers', 'files' => ['tests/unit/CafeTest.php']]));
    }

    public function testTwoUnchainedTasksOnOneFileAreNamed(): void {
        $a = ['id' => 't1', 'title' => 'The Messages tab', 'files' => ['views/chats/index.php', 'tests/qa/messages.md']];
        $b = ['id' => 't2', 'title' => 'A drawer per conversation', 'files' => ['public/chat.js', 'tests/qa/messages.md']];
        $c = ['id' => 't3', 'title' => 'The composer', 'files' => ['public/chat.js'], 'depends_on' => ['t2']];
        $d = ['id' => 't4', 'title' => 'Design', 'files' => ['tests/qa/messages.md'], 'depends_on' => ['t1', 't3']];
        $this->assertSame([['a' => 't1', 'b' => 't2', 'files' => ['tests/qa/messages.md']]], PlanShape::collisions([$a, $b, $c, $d]),
            't3 waits for t2, and t4 for everyone (through t3 too); only t1 and t2 are side by side');
        $this->assertStringContainsString('t1 and t2 both name `tests/qa/messages.md`', PlanShape::refusal([$a, $b, $c, $d]));
        $b['depends_on'] = ['t1'];
        $this->assertSame('', PlanShape::refusal([$a, $b, $c, $d]));
    }

    public function testAOneTaskPlanIsItsTask(): void {
        $this->assertSame([], PlanShape::errands([['id' => 't1', 'title' => 'Seed PUBLIC permission for the /pets route', 'files' => ['services/Schema/Seeds/51_Pets.php']]]));
    }

    public function testSubmitPlanRefusesAndSaysWhereTheWorkGoes(): void {
        $out = (new \app\mcptools\SubmitPlanTool())->execute(['title' => 'Cafe', 'subtasks' => [self::PAGE,
            ['id' => 't2', 'title' => 'Permission seed for the cafe routes', 'files' => ['services/Schema/Seeds/52_CafePermissions.php']]]]);
        $this->assertStringStartsWith('Plan NOT received: 1 of its tasks is an errand', $out);
        $this->assertStringContainsString('t2 "Permission seed for the cafe routes"', $out);
        $this->assertStringContainsString("the task that adds the route", $out);
    }
}
