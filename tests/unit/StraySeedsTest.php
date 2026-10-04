<?php
/**
 * A seed is applied from services/Schema/Seeds/ and nowhere else. A task that adds one under
 * database/seeds/ is failed rather than merged (PlanExecutor::straySeeds): on holistica five such
 * seeds merged, and two features went live without their permission rows.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\PlanExecutor;

class StraySeedsTest extends TestCase {
    public function testSeedsAddedOutsideTheSchemaFolderAreNamed(): void {
        $diff = " controls/Vault.php           | 175 ++++++++++++++++\n database/seeds/10_Vault.php  | 158 ++++++++++++++\n lib/Vaults.php               | 475 +++++++++++++++++++++++\n";
        $this->assertSame(['database/seeds/10_Vault.php'], PlanExecutor::straySeeds($diff));
    }
    public function testTheRightFolderAMoveAwayAndOtherFilesPass(): void {
        $this->assertSame([], PlanExecutor::straySeeds(" services/Schema/Seeds/50_Vault.php | 158 ++++++\n docs/database/seeds/notes.md | 3 +++\n"));
        $this->assertSame([], PlanExecutor::straySeeds(" database/seeds/10_Vault.php | 158 ----------\n"), 'deleting one is the fix, not the fault');
        $this->assertSame([], PlanExecutor::straySeeds(''));
    }
    public function testThePlannerAndTheAgentAreToldTheOneFolder(): void {
        foreach (['lib/PlanRunner.php', 'lib/PlanExecutor.php'] as $f) {
            $src = file_get_contents(dirname(__DIR__, 2) . '/' . $f);
            $this->assertStringContainsString('services/Schema/Seeds/', $src, $f);
            $this->assertStringNotContainsString('is also applied', $src, "{$f} must not promise that database/seeds is applied");
        }
    }
}
