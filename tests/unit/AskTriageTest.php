<?php
/**
 * app\AskTriage: the agent's reply is read for what it took the message for; a reply that does
 * not say is a failure, not a guess; the brief says a build is in progress when one is.
 */
namespace tests\unit;

use app\AskTriage;
use PHPUnit\Framework\TestCase;

class AskTriageTest extends TestCase {
    public function testAnAnswerKeepsItsWords(): void {
        $r = AskTriage::parse("VERDICT: ANSWER\n\nThe game page is `/game/play`.\nIt has a mute button.");
        $this->assertSame('answer', $r['verdict']);
        $this->assertSame("The game page is `/game/play`.\nIt has a mute button.", $r['text']);
    }

    public function testABuildCarriesNoWords(): void {
        $this->assertSame(['verdict' => 'build', 'text' => ''], AskTriage::parse("`VERDICT: BUILD`\nI will add it."));
    }

    public function testUnsureKeepsItsOneSentence(): void {
        $r = AskTriage::parse("Let me look.\nVERDICT: UNSURE\nI could not tell whether you want the header changed or are asking why it is dark.");
        $this->assertSame('unsure', $r['verdict']);
        $this->assertStringStartsWith('I could not tell', $r['text']);
    }

    public function testAReplyThatDoesNotSayIsNotGuessed(): void {
        $this->expectException(\RuntimeException::class);
        AskTriage::parse('The game page lets you pick and pack orders.');
    }

    public function testAQuestionWithNoAnswerIsAFailure(): void {
        $this->expectException(\RuntimeException::class);
        AskTriage::parse('VERDICT: ANSWER');
    }

    public function testTheBriefSaysWhatIsBuildingAndWhatWasTyped(): void {
        $b = AskTriage::brief('what does the game page do?', 'auto', '#18 is still being built');
        $this->assertStringContainsString('VERDICT: UNSURE', $b);
        $this->assertStringContainsString('#18 is still being built', $b);
        $this->assertStringEndsWith('what does the game page do?', $b);
        $this->assertStringNotContainsString('            - `VERDICT', $b, 'the instructions are not left indented as code');
        $forced = AskTriage::brief('x?', 'answer', '');
        $this->assertStringNotContainsString('VERDICT: BUILD', $forced);
        $this->assertStringContainsString('Nothing is being built', $forced);
    }

    public function testOnlyAnAppWithTheReadOnlyRunIsAsked(): void {
        $this->assertTrue(AskTriage::appCanAnswer('v2.0.0-alpha.189'));
        $this->assertTrue(AskTriage::appCanAnswer('v2.0.0-alpha.190'));
        $this->assertTrue(AskTriage::appCanAnswer('v2.0.0'));
        $this->assertFalse(AskTriage::appCanAnswer('v2.0.0-alpha.188'));
        $this->assertFalse(AskTriage::appCanAnswer('v2.0.0-alpha.99'));
        $this->assertFalse(AskTriage::appCanAnswer(''));
    }
}
