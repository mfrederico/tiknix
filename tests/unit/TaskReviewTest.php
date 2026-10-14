<?php
/**
 * app\TaskReview (Plan and Fan, step three): which merged tasks are read a second time, what the
 * reviewer is handed, how its answer is read, and the fix task a finding becomes. And the browser
 * check's size following the request's class (AuditRunner::sizeSection).
 */
namespace tests\unit;

use app\AuditRunner;
use app\TaskReview;
use PHPUnit\Framework\TestCase;

class TaskReviewTest extends TestCase {
    public function testOnlyMediumRiskAndAboveIsReviewedAndAFixIsNotReviewedAgain(): void {
        $this->assertTrue(TaskReview::wanted(['risk' => 3], false));
        $this->assertTrue(TaskReview::wanted(['risk' => 5], false));
        $this->assertFalse(TaskReview::wanted(['risk' => 2], false));
        $this->assertFalse(TaskReview::wanted([], false), 'an unscored task is not called risky');
        $this->assertFalse(TaskReview::wanted(['risk' => 5], true), 'one round: a review fix is checked by the browser, not reviewed again');
        $this->assertTrue(TaskReview::enabled([]));
        $this->assertFalse(TaskReview::enabled(['plan' => ['review_risky' => 'false']]));
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]{0,40}$/', TaskReview::runId(123456, 7654321), 'a TenantRun/ask id');
    }

    public function testTheBriefCarriesTheChecksTheRiskAndTheChangeCutToSize(): void {
        $b = TaskReview::brief('Sales take stock', 'Recording a sale deducts each material.', ['Selling 2 loaves deducts 2× the recipe'],
                               ['risk' => 5, 'why' => ['stock arithmetic']], ' controls/Bakery.php | 40 +', str_repeat('+x', TaskReview::DIFF_CHARS));
        $this->assertStringContainsString('- Selling 2 loaves deducts 2× the recipe', $b);
        $this->assertStringContainsString('scored Critical risk because: stock arithmetic', $b);
        $this->assertStringContainsString('the rest is in the files themselves', $b);
        $this->assertStringContainsString('REVIEW: PASS', $b);
        $this->assertStringContainsString('(the task named no checks', TaskReview::brief('t', 'd', [], ['risk' => 3], '', 'diff'));
    }

    public function testTheAnswerIsReadOrRefused(): void {
        $this->assertSame(['verdict' => 'pass', 'problems' => []], TaskReview::parse("REVIEW: PASS\n"));
        $v = TaskReview::parse("Read it.\nREVIEW: PROBLEMS\n- controls/Bakery.php:88 — a deleted sale restores stock twice\n- views/x.php:3 — y\n");
        $this->assertSame('problems', $v['verdict']);
        $this->assertSame(['controls/Bakery.php:88 — a deleted sale restores stock twice', 'views/x.php:3 — y'], $v['problems']);
        foreach (["Looks fine to me.", "REVIEW: PROBLEMS\nnothing listed"] as $bad) {
            try { TaskReview::parse($bad); $this->fail('accepted: ' . $bad); } catch (\UnexpectedValueException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }

    public function testAFindingBecomesOneFixTaskThatKeepsTheOriginalChecks(): void {
        $f = TaskReview::fixTask(33, 'Sales', ['a.php:1 — wrong'], ['stock goes down']);
        $this->assertSame('Fix what the review of #33 found', $f['title']);
        $this->assertStringContainsString('- a.php:1 — wrong', $f['description']);
        $this->assertSame(['Fixed: a.php:1 — wrong', 'stock goes down'], $f['verify']);
    }

    public function testALargeCheckFansOutOnePerLevelWhenTheAgentMay(): void {
        $this->assertSame(3, AuditRunner::fanWidth('large', 3, 0, true));
        $this->assertSame(3, AuditRunner::fanWidth('large', 3, 4, true));
        $this->assertSame(1, AuditRunner::fanWidth('large', 3, 2, true), 'two at once cannot cover three levels one each: one checker does them all');
        $this->assertSame(1, AuditRunner::fanWidth('large', 3, 1, true), 'z.ai: one request at a time');
        $this->assertSame(1, AuditRunner::fanWidth('medium', 3, 0, true));
        $this->assertSame(1, AuditRunner::fanWidth('large', 3, 0, false));
    }

    public function testTheChecksAtOnceMergeIntoOneAndANullPartFailsIt(): void {
        $ok = fn($lv, $n) => ['passed' => true, 'summary' => "{$n} checks", 'levels' => [$lv => ['login_ok' => true]], 'checks' => array_fill(0, $n, ['label' => 'x']), 'failures' => [], 'design' => []];
        $m = AuditRunner::mergeManifests(['root' => $ok('root', 2), 'admin' => $ok('admin', 3)], 9, 'bakery', 'https://b');
        $this->assertTrue($m['passed']);
        $this->assertCount(5, $m['checks']);
        $this->assertSame(['root', 'admin'], array_keys($m['levels']));
        $m = AuditRunner::mergeManifests(['root' => $ok('root', 2), 'member' => null], 9, 'bakery', 'https://b');
        $this->assertFalse($m['passed'], 'a level nobody checked is not a pass');
        $this->assertSame('The member check produced no result', $m['failures'][0]['label']);
    }

    public function testTheCheckIsSizedByTheRequestsClass(): void {
        $this->assertStringContainsString('as the ONE level each is', AuditRunner::sizeSection('small'));
        $this->assertStringContainsString('as EACH level that can reach', AuditRunner::sizeSection('medium'));
        $this->assertStringContainsString('the full pass', AuditRunner::sizeSection('large'));
        $this->expectException(\InvalidArgumentException::class);
        AuditRunner::sizeSection('');
    }
}
