<?php
/**
 * app\AskStore: what is said in a project's Ask is kept in the project's own board database —
 * made once per message, moved through its states, linked to the plan it became, read back in
 * order for one member or for everyone.
 */
namespace tests\unit;

use app\AskStore;
use PHPUnit\Framework\TestCase;

class AskStoreTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/askstore-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/data', 0700, true);
        AskStore::forget();
    }

    protected function tearDown(): void {
        AskStore::forget();
        @unlink($this->dir . '/data/workbench.db'); @rmdir($this->dir . '/data'); @rmdir($this->dir);
    }

    public function testAMessageIsMadeOnceAndMovesThroughItsStates(): void {
        AskStore::ensure($this->dir, 41, 7, 'Where are leads kept?', 'thinking', '2026-10-13 10:00:00');
        AskStore::ensure($this->dir, 41, 7, 'typed again', 'thinking');   // the same prompt: nothing changes
        $this->assertSame('Where are leads kept?', AskStore::get($this->dir, 41)['said']);
        AskStore::mark($this->dir, 41, 'answer', 'In `/lead`.', 'map: leads are at /lead');
        $r = AskStore::get($this->dir, 41);
        $this->assertSame(['answer', 'In `/lead`.', 'map: leads are at /lead'], [$r['state'], $r['answer'], $r['note']]);
        AskStore::mark($this->dir, 41, 'build');   // "I wanted this built": the answer it had is kept
        $this->assertSame('In `/lead`.', AskStore::get($this->dir, 41)['answer']);
        AskStore::planned($this->dir, 41, 18);
        $r = AskStore::get($this->dir, 41);
        $this->assertSame(['planned', 18], [$r['state'], (int) $r['plan_ref']]);
    }

    public function testThePlannersQuestionAndItsAnswerStayWithTheMessage(): void {
        AskStore::ensure($this->dir, 50, 7, 'List our wholesale prices.');
        AskStore::mark($this->dir, 50, 'asking', 'What are the three tiers?');
        AskStore::addQa($this->dir, 50, 'What are the three tiers?', 'Cafe $18, Market $16, Distributor $14.');
        $r = AskStore::get($this->dir, 50);
        $this->assertSame('build', $r['state']);
        $qa = json_decode($r['qa_json'], true);
        $this->assertSame(['What are the three tiers?', 'Cafe $18, Market $16, Distributor $14.'], [$qa[0]['q'], $qa[0]['a']]);
    }

    public function testOneMembersMessagesComeBackOldestFirstAndByState(): void {
        AskStore::ensure($this->dir, 1, 7, 'first'); AskStore::ensure($this->dir, 2, 9, 'someone else'); AskStore::ensure($this->dir, 3, 7, 'third');
        AskStore::mark($this->dir, 3, 'answer', 'yes');
        $this->assertSame(['first', 'third'], array_column(AskStore::of($this->dir, 7), 'said'));
        $this->assertSame(['third'], array_column(AskStore::of($this->dir, 7, ['answer']), 'said'));
        $this->assertSame(['first'], array_column(AskStore::of($this->dir, 7, [], 20, 3), 'said'), 'before a given message: the conversation so far');
        $this->assertCount(3, AskStore::all($this->dir));
    }

    public function testMarkingAMessageThatIsNotThereIsAnError(): void {
        $this->expectException(\RuntimeException::class);
        AskStore::mark($this->dir, 999, 'answer', 'x');
    }
}
