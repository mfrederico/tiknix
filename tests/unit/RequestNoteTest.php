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

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/reqnote-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/data', 0700, true);
        $pdo = new \PDO('sqlite:' . $this->dir . '/data/workbench.db');
        $pdo->exec('CREATE TABLE workbenchtask (id INTEGER PRIMARY KEY, parent_task_id INTEGER, title TEXT, status TEXT, plan_goal TEXT, related_files TEXT, asked_json TEXT, plan_checkpoint TEXT)');
        $pdo->exec('CREATE TABLE askmessage (prompt_ref INTEGER, plan_ref INTEGER, follows_ref INTEGER)');
        $goal = "List our wholesale prices on the home page.\n\n" . \app\AskTriage::QA_HEAD . "\n\n**You asked:** What are the tiers?\n\n**They answered:** Cafe \$18, Market \$16.\n";
        $q = $pdo->prepare('INSERT INTO workbenchtask VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $q->execute([20, null, 'Wholesale prices', 'completed', $goal, '', '', 'checkpoint-plan-20']);
        $q->execute([21, 20, 'Add editable price tiers', 'merged', '', '["views/index/index.php","models/Model_Landingcopy.php"]', '[{"q":"Per dozen or per case?","a":"Per dozen."}]', '']);
        $q->execute([22, 20, 'A task that failed', 'failed', '', '["x.php"]', '', '']);
        $q->execute([30, null, 'Bigger heading', 'completed', 'make that heading bigger', '', '', 'checkpoint-plan-30']);
        $q->execute([31, 30, 'Make the heading bigger', 'merged', '', '["views/index/index.php"]', '', '']);
        $pdo->exec('INSERT INTO askmessage VALUES (7, 30, 20)');
    }

    protected function tearDown(): void { @unlink($this->dir . '/data/workbench.db'); @rmdir($this->dir . '/data'); @rmdir($this->dir); }

    public function testAFinishedRequestSaysWhatWasAskedBuiltWhereAndDecided(): void {
        $s = RequestNote::built($this->dir, 20);
        $this->assertStringContainsString('“List our wholesale prices on the home page.”', $s);
        $this->assertStringContainsString('built: Add editable price tiers.', $s);
        $this->assertStringNotContainsString('A task that failed', $s);
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
}
