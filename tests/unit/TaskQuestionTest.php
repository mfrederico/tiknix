<?php
/**
 * A build task may ask its person a question (PlanExecutor::ask). What it asked and what they
 * answered goes into its next brief as settled; an unanswered question is not in it.
 */
namespace tests\unit;

use app\PlanExecutor;
use PHPUnit\Framework\TestCase;

class TaskQuestionTest extends TestCase {
    public function testAnAnsweredQuestionIsInTheNextBriefAsSettled(): void {
        $s = PlanExecutor::askedSection([['q' => "Monday to Saturday, or every day?\nI would pick every day.", 'a' => 'Monday to Saturday, 7 to 3.', 'at' => 'x', 'answered_at' => 'y']]);
        $this->assertStringContainsString('## You asked the owner, and they answered', $s);
        $this->assertStringContainsString('Monday to Saturday, 7 to 3.', $s);
        $this->assertStringContainsString('do not ask it again', $s);
    }

    public function testAnOpenQuestionAndNothingAreNoSection(): void {
        $this->assertSame('', PlanExecutor::askedSection([['q' => 'Which?', 'a' => '']]));
        $this->assertSame('', PlanExecutor::askedSection(null));
        $this->assertSame('', PlanExecutor::askedSection([]));
    }
}
