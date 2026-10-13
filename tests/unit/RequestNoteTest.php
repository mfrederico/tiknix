<?php
/**
 * app\RequestNote: the line a finished request leaves in its project's notebook says what was
 * asked, what was built, where, and what was decided; an undone request leaves a line saying it
 * is no longer there.
 */
namespace tests\unit;

use app\RequestNote;
use PHPUnit\Framework\TestCase;

class RequestNoteTest extends TestCase {
    private string $dir;
    private \PDO $pdo;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/reqnote-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/data', 0700, true);
        $pdo = new \PDO('sqlite:' . $this->dir . '/data/workbench.db');
        $pdo->exec('CREATE TABLE workbenchtask (id INTEGER PRIMARY KEY, parent_task_id INTEGER, title TEXT, status TEXT, plan_goal TEXT, related_files TEXT, asked_json TEXT, plan_checkpoint TEXT,
            description TEXT, plan_status TEXT, audit_status TEXT, audit_failures INTEGER, audit_found_json TEXT, error_message TEXT)');
        $pdo->exec('CREATE TABLE askmessage (prompt_ref INTEGER, plan_ref INTEGER, follows_ref INTEGER)');
        $goal = "List our wholesale prices on the home page.\n\n" . \app\AskTriage::QA_HEAD . "\n\n**You asked:** What are the tiers?\n\n**They answered:** Cafe \$18, Market \$16.\n";
        $q = $pdo->prepare('INSERT INTO workbenchtask (id, parent_task_id, title, status, plan_goal, related_files, asked_json, plan_checkpoint) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $this->pdo = $pdo;
        $q->execute([20, null, 'Wholesale prices', 'completed', $goal, '', '', 'checkpoint-plan-20']);
        $pdo->exec("UPDATE workbenchtask SET plan_status = 'done' WHERE id = 20");
        $q->execute([21, 20, 'Add editable price tiers', 'merged', '', '["views/index/index.php","models/Model_Landingcopy.php"]', '[{"q":"Per dozen or per case?","a":"Per dozen."}]', '']);
        $q->execute([22, 20, 'A task that failed', 'failed', '', '["x.php"]', '', '']);
        $q->execute([30, null, 'Bigger heading', 'completed', 'make that heading bigger', '', '', 'checkpoint-plan-30']);
        $pdo->exec("UPDATE workbenchtask SET plan_status = 'done' WHERE id = 30");
        $q->execute([31, 30, 'Make the heading bigger', 'merged', '', '["views/index/index.php"]', '', '']);
        $pdo->exec('INSERT INTO askmessage VALUES (7, 30, 20)');
    }

    protected function tearDown(): void { @unlink($this->dir . '/data/workbench.db'); @rmdir($this->dir . '/data'); @rmdir($this->dir); }

    public function testAFinishedRequestSaysWhatWasAskedBuiltWhereAndDecided(): void {
        $s = RequestNote::built($this->dir, 20);
        $this->assertStringContainsString('“List our wholesale prices on the home page.”', $s);
        $this->assertStringContainsString('built: Add editable price tiers.', $s);
        $this->assertStringStartsWith('PARTLY BUILT — ', $s, 'one of its tasks did not land');
        $this->assertStringContainsString('NOT in the app: A task that failed.', $s);
        $this->assertStringContainsString('views/index/index.php, models/Model_Landingcopy.php', $s);
        $this->assertStringContainsString('Decided: What are the tiers? → Cafe $18, Market $16.', $s);
        $this->assertStringContainsString('Decided: Per dozen or per case? → Per dozen.', $s);
        $this->assertStringNotContainsString('You asked', $s, 'the question section of the goal is not part of what was asked');
    }

    public function testAFollowUpNamesWhatItContinuesAndAPlanThatBuiltNothingLeavesNoLine(): void {
        $this->assertStringContainsString('(a follow-up to request #20)', RequestNote::built($this->dir, 30));
        $this->assertSame('', RequestNote::built($this->dir, 999));
    }

    public function testAnUndoneRequestSaysItIsNoLongerThere(): void {
        $s = RequestNote::undone($this->dir, 'checkpoint-plan-30');
        $this->assertStringStartsWith('UNDONE — “make that heading bigger”', $s);
        $this->assertStringContainsString('none of it is in the app now', $s);
        $this->assertSame('', RequestNote::undone($this->dir, 'checkpoint-nobody'));
    }

    public function testTheLineSaysWhatTheCheckMadeOfIt(): void {
        $this->pdo->exec("UPDATE workbenchtask SET audit_status = 'passed' WHERE id = 30");
        $this->assertStringContainsString('Checked in a browser afterwards: passed.', RequestNote::built($this->dir, 30));
        $this->pdo->exec("UPDATE workbenchtask SET audit_status = 'failed', audit_failures = 2, audit_found_json = '[\"The heading overlaps the photo on a phone\",\"Save shows a 500\"]' WHERE id = 30");
        $s = RequestNote::built($this->dir, 30);
        $this->assertStringContainsString('2 problems found — The heading overlaps the photo on a phone; Save shows a 500.', $s);
    }

    public function testARequestThatStoppedPartWaySaysWhatIsAndIsNotInTheApp(): void {
        $this->pdo->exec("UPDATE workbenchtask SET error_message = 'the import could not reach the warehouse API' WHERE id = 22");
        $s = RequestNote::built($this->dir, 20);
        $this->assertStringContainsString('built: Add editable price tiers.', $s);
        $this->assertStringContainsString('It stopped on: the import could not reach the warehouse API.', $s);
        $this->pdo->exec("UPDATE workbenchtask SET status = 'failed' WHERE id = 21");
        $this->assertStringStartsWith('NOT BUILT — ', RequestNote::built($this->dir, 20));
    }

    public function testATaskMadeOnItsOwnLeavesALineOfItsOwn(): void {
        $this->pdo->exec("INSERT INTO workbenchtask (id, parent_task_id, title, status, description, related_files, plan_status) VALUES (40, NULL, 'Fix the footer link', 'merged', 'The footer link goes to the wrong page.', '[\"views/app/footer.php\"]', '')");
        $s = RequestNote::built($this->dir, 40);
        $this->assertStringContainsString('“The footer link goes to the wrong page.” — built: Fix the footer link. In views/app/footer.php.', $s);
    }
}
