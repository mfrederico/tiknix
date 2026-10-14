<?php
/**
 * app\TaskRace (Plan and Fan, step five): which tasks are built twice, who wins without a judge,
 * how the judge's answer is read, and what the second attempt is told.
 */
namespace tests\unit;

use app\TaskRace;
use PHPUnit\Framework\TestCase;

class TaskRaceTest extends TestCase {
    public function testOnlyACriticalFirstAttemptOfSomeSizeRacesAndOnlyWithinTheBudget(): void {
        $crit = ['risk' => 5, 'effort' => 3];
        $this->assertSame('', TaskRace::why($crit, false, false, true, 0, 0, null), 'no limit set: it races');
        $this->assertSame('off', TaskRace::why($crit, false, false, false, 0, 0, null));
        $this->assertSame('not a first attempt', TaskRace::why($crit, true, false, true, 0, 0, null));
        $this->assertSame('not a first attempt', TaskRace::why($crit, false, true, true, 0, 0, null), 'a review fix is not raced');
        $this->assertStringStartsWith('not Critical', TaskRace::why(['risk' => 4, 'effort' => 5], false, false, true, 0, 0, null));
        $this->assertStringStartsWith('not Critical', TaskRace::why(['risk' => 5, 'effort' => 2], false, false, true, 0, 0, null));
        $this->assertSame('', TaskRace::why($crit, false, false, true, 10000000, 2000000, 1000000), '2M spent + two 1M attempts fits 10M');
        $this->assertStringContainsString('would not fit', TaskRace::why($crit, false, false, true, 3000000, 2000000, 1000000));
        $this->assertStringContainsString('no record yet', TaskRace::why($crit, false, false, true, 3000000, 0, null), 'a limit and no estimate: not raced blind');
    }

    public function testWhoWinsWithoutAJudge(): void {
        $this->assertSame('judge', TaskRace::outcome(true, true));
        $this->assertSame('A', TaskRace::outcome(true, false));
        $this->assertSame('B', TaskRace::outcome(false, true));
        $this->assertSame('none', TaskRace::outcome(false, false));
    }

    public function testTheJudgeAnswersAOrBOrItIsAFault(): void {
        $this->assertSame(['pick' => 'B', 'why' => 'B restores stock on delete; A does not.'], TaskRace::parseJudge("PICK: B\nB restores stock on delete; A does not."));
        $this->expectException(\UnexpectedValueException::class);
        TaskRace::parseJudge('Both are fine.');
    }

    public function testTheBriefsSayWhatTheyAreFor(): void {
        $this->assertStringContainsString('SECOND attempt', TaskRace::rivalNote());
        $b = TaskRace::judgeBrief('Sales', 'Stock goes down', ['selling 2 deducts 2'], ['a.php | 3 +', '+a'], ['b.php | 4 +', '+b']);
        $this->assertStringContainsString('never combined', $b);
        $this->assertStringContainsString('- selling 2 deducts 2', $b);
        $this->assertStringContainsString('PICK: A', $b);
        $f = TaskRace::testsFix(33, 'Sales', "FAILURES!\nTests: 3, Failures: 1");
        $this->assertStringContainsString('.aibuilder/rival-tests/', $f['description']);
    }
}
