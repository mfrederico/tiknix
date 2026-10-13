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
        $this->assertSame(['verdict' => 'build', 'text' => '', 'note' => ''], AskTriage::parse("`VERDICT: BUILD`\nI will add it."));
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
        $this->assertTrue(AskTriage::appCanAnswer('v2.0.0-alpha.194'));
        $this->assertTrue(AskTriage::appCanAnswer('v2.0.0-alpha.200'));
        $this->assertTrue(AskTriage::appCanAnswer('v2.0.0'));
        $this->assertFalse(AskTriage::appCanAnswer('v2.0.0-alpha.193'));
        $this->assertFalse(AskTriage::appCanAnswer('v2.0.0-alpha.99'));
        $this->assertFalse(AskTriage::appCanAnswer(''));
    }

    public function testTheFirstReadingOnlySaysWhich(): void {
        $this->assertSame(['verdict' => 'answer', 'text' => '', 'note' => ''], AskTriage::parse('VERDICT: ANSWER', false));
        $b = AskTriage::classifyBrief('is it done?');
        $this->assertStringContainsString('Do NOT answer it', $b);
        $this->assertStringEndsWith('is it done?', $b);
    }

    public function testTheLineWorthKeepingIsTakenOffTheAnswer(): void {
        $r = AskTriage::parse("VERDICT: ANSWER\nEnquiries are in the inbox.\n\nNOTE: map: Enquiries are saved by controls/Enquiry.php and also recorded as leads.");
        $this->assertSame('Enquiries are in the inbox.', $r['text']);
        $this->assertSame('map: Enquiries are saved by controls/Enquiry.php and also recorded as leads.', $r['note']);
        $none = AskTriage::parse("VERDICT: ANSWER\nIt is still building.\nNOTE: none");
        $this->assertSame('It is still building.', $none['text']);
        $this->assertSame('', $none['note']);
        $this->assertSame('', AskTriage::parse("VERDICT: ANSWER\nNo note line here.")['note']);
        $this->assertStringContainsString('NOTE: none', AskTriage::brief('x?', 'answer', ''));
    }

    public function testTheConversationSoFarGoesBeforeTheNewestMessage(): void {
        $before = "**They said:** where is the headline?\n\n**Then:** You answered: at /landing.";
        foreach ([AskTriage::classifyBrief('and the footer?', $before), AskTriage::brief('and the footer?', 'answer', '', $before)] as $b) {
            $this->assertStringContainsString('## The conversation so far', $b);
            $this->assertLessThan(strpos($b, '## The message'), strpos($b, 'where is the headline?'));
            $this->assertStringEndsWith('and the footer?', $b);
        }
        $this->assertStringNotContainsString('The conversation so far', AskTriage::brief('x?', 'answer', ''));
        $this->assertStringContainsString('Be brief', AskTriage::brief('x?', 'answer', ''));
    }

    public function testOnlyAnAdminsBriefOffersTheData(): void {
        $this->assertStringContainsString('database_query', AskTriage::brief('how many leads this week?', 'answer', '', '', true));
        $member = AskTriage::brief('how many leads this week?', 'answer', '', '', false);
        $this->assertStringNotContainsString('database_query', $member);
        $this->assertStringContainsString('You cannot read rows', $member);
    }
}
