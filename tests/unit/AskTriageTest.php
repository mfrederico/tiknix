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
        $this->assertSame(['verdict' => 'build', 'text' => '', 'note' => '', 'next' => ''], AskTriage::parse("`VERDICT: BUILD`\nI will add it."));
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
        $this->assertSame(['verdict' => 'answer', 'text' => '', 'note' => '', 'next' => ''], AskTriage::parse('VERDICT: ANSWER', false));
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

    public function testAPageAddressInAnAnswerBecomesALinkToThatPageOfTheApp(): void {
        $html = '<p>Change it at <code>/landing</code> (see <code>views/landing/index.php</code>, <code>/srv/app/x</code> and <code>/crm/contact</code>).</p>';
        $out = AskTriage::linkPages($html, 'https://tiknix.com/projects/open?id=137&to=');
        $this->assertStringContainsString('<a href="https://tiknix.com/projects/open?id=137&amp;to=%2Flanding"', $out);
        $this->assertStringContainsString('><code>/landing</code></a>', $out, 'the short text stays');
        $this->assertStringContainsString('to=%2Fcrm%2Fcontact"', $out);
        $this->assertStringContainsString('<code>views/landing/index.php</code>', $out);
        $this->assertStringNotContainsString('to=%2Fsrv', $out, 'a path on the machine is not a page');
        $this->assertSame($html, AskTriage::linkPages($html, ''));
    }

    public function testOnlyWhatReadsLikeAQuestionIsAnsweredAhead(): void {
        foreach (['What does the headline read now?', 'where are leads kept', 'Is it done?', 'how many enquiries are spam?'] as $q) $this->assertTrue(AskTriage::readsAsQuestion($q), $q);
        foreach (['Can you add a login page?', 'Could we make the header darker?', 'Add a phone number under the headline', 'please change the footer?', 'the footer'] as $q) $this->assertFalse(AskTriage::readsAsQuestion($q), $q);
    }

    public function testWhatThePlannerAskedIsReadApartFromWhatWasTyped(): void {
        $goal = "Put our phone number in the footer.\n\n" . AskTriage::QA_HEAD . "\n\n**You asked:** What is the number?\n  Include the country.\n\n**They answered:** +1 555 010 2030\n\n**You asked:** Bold or plain?\n\n**They answered:** Plain.\n";
        $p = AskTriage::splitAsked($goal);
        $this->assertSame('Put our phone number in the footer.', $p['said']);
        $this->assertSame([['q' => "What is the number?\nInclude the country.", 'a' => '+1 555 010 2030'], ['q' => 'Bold or plain?', 'a' => 'Plain.']], $p['qa']);
        $this->assertSame(['said' => 'No question here.', 'qa' => []], AskTriage::splitAsked('No question here.'));
    }

    public function testAMessageAboutSomethingOnTheListIsReadAsThatActionAndItsTarget(): void {
        $this->assertSame(['verdict' => 'followup', 'text' => '', 'note' => '', 'target' => 20], AskTriage::parse('VERDICT: FOLLOWUP #20', false));
        $this->assertSame(21, AskTriage::parse("`VERDICT: RETRY 21`", false)['target']);
        $this->assertSame(['undo', 0], [AskTriage::parse('VERDICT: UNDO', false)['verdict'], AskTriage::parse('VERDICT: UNDO', false)['target']]);
        $list = \app\BoardDigest::targetsText(['plans' => [20 => 'Wholesale prices'], 'retry' => [9 => 'Import products'], 'fix' => [42 => 'Import page 500'], 'undo' => ['id' => 20, 'title' => 'Wholesale prices', 'checkpoint' => 'c'], ], true);
        $this->assertStringContainsString('request #20: Wholesale prices', $list);
        $this->assertStringContainsString('task #9 STOPPED', $list);
        $this->assertStringContainsString('problem #42', $list);
        $this->assertStringContainsString('can be undone: #20', $list);
        $b = AskTriage::classifyBrief('make that bold', '', $list);
        $this->assertStringContainsString('VERDICT: FOLLOWUP #N', $b);
        $this->assertStringNotContainsString('FOLLOWUP', AskTriage::classifyBrief('make that bold'), 'with nothing on the list there is nothing to be about');
    }

    public function testTheAnswerersGuessAtTheNextMessageIsTakenOffTheAnswer(): void {
        $r = AskTriage::parse("VERDICT: ANSWER\nThe form saves name and email.\nNOTE: none\nNEXT: Add a phone number field to the enquiry form");
        $this->assertSame('The form saves name and email.', $r['text'], 'neither line is shown in the answer');
        $this->assertSame('Add a phone number field to the enquiry form', $r['next']);
        $this->assertSame('', AskTriage::parse("VERDICT: ANSWER\nIt does.\nNEXT: none")['next']);
        $this->assertSame('', AskTriage::parse("VERDICT: BUILD\nNEXT: Add more")['next'], 'only an answer offers one');
    }

    public function testAfterABuildTheNextMessageIsWhatItsCheckFound(): void {
        $this->assertSame('Fix: MEMBER should not see Delete (/leads)', AskTriage::nextAfterCheck(['failures' => [['label' => 'x', 'message' => 'MEMBER should not see Delete', 'url' => 'https://a.tiknix.com/leads']]]));
        $this->assertSame('On /bakery/sales: on a phone the table scrolls sideways — fix that',
            AskTriage::nextAfterCheck(['failures' => [], 'design' => [['page' => '/x', 'verdict' => 'ok', 'notes' => []], ['page' => '/bakery/sales', 'verdict' => 'needs work', 'notes' => ['on a phone the table scrolls sideways.']]]]));
        $this->assertSame('', AskTriage::nextAfterCheck(['failures' => [], 'design' => [['page' => '/x', 'verdict' => 'ok', 'notes' => []]]]), 'nothing found: nothing suggested');
    }
}
