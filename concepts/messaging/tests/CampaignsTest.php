<?php
namespace app\concepts\messaging\tests;

require_once __DIR__ . '/MessagingCase.php';

use app\Bean;
use app\concepts\messaging\Campaigns;
use app\concepts\messaging\Providers;

/** Who a campaign is for, the ledger, what a message may say, texts, the cap, and what is credited to a campaign. */
class CampaignsTest extends MessagingCase {

    private function audience($c, string $channel = 'email'): array {
        return array_values(array_intersect(Campaigns::audience($c, $channel), array_values($this->ids)));
    }

    public function testAudienceIsTheAudiencesMembersMinusWhoSwitchedOffMinusWhoIsSuppressedMinusWhoAlreadyHasIt(): void {
        $c = $this->stored();
        $this->assertSame([$this->ids['ann'], $this->ids['bo']], $this->audience($c), 'cy is not in group:1');
        FakeAudiences::$switch['group:1'][$this->ids['bo']] = false;
        $this->assertSame([$this->ids['ann']], $this->audience($c));
        FakeAudiences::$switch['group:1'][$this->ids['bo']] = true;
        \Model_Msgsuppression::add('bo@example.test', 'complained');
        $this->assertSame([$this->ids['ann']], $this->audience($c));
        $this->ledger($c, $this->ids['ann'], str_repeat('a', 32));
        $this->assertSame([], $this->audience($c));
    }

    public function testACampaignWithNoAudienceGoesToEveryoneWhoCanBeWrittenTo(): void {
        $c = $this->stored(['scope' => null]);
        $this->assertSame(array_values($this->ids), $this->audience($c));
        Bean::exec("UPDATE member SET status = 'closed' WHERE id = ?", [$this->ids['cy']]);
        $this->assertSame([$this->ids['ann'], $this->ids['bo']], $this->audience($c));
    }

    public function testAnAudienceNeedsTheAudiencesProvider(): void {
        Providers::reset();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'audiences' provider is not registered");
        Campaigns::audience($this->stored());
    }

    public function testARepeatingCampaignWaitsOutItsCadenceAndCountsEachChannelOnItsOwn(): void {
        $c = $this->stored(['cadence' => 'every_n_days', 'days' => 7]);
        $s = $this->ledger($c, $this->ids['ann'], str_repeat('a', 32));
        $this->assertNotContains($this->ids['ann'], $this->audience($c));
        $s->createdAt = date('Y-m-d H:i:s', time() - 8 * 86400);
        Bean::store($s);
        $this->assertContains($this->ids['ann'], $this->audience($c));
        $this->ledger($c, $this->ids['bo'], str_repeat('d', 32), 'text');
        $this->assertContains($this->ids['bo'], $this->audience($c), 'a text row must not block the email');
    }

    public function testAReminderGoesToThoseInWhatItIsAboutWhateverTheAudiencesSwitchIs(): void {
        FakeAudiences::$members['thing:7'] = [$this->ids['bo'], $this->ids['cy']];
        FakeAudiences::$names['thing:7'] = 'Picnic';
        FakeAudiences::$switch['group:1'][$this->ids['bo']] = false;
        $r = $this->stored(['category' => $this->remindersId, 'kind' => 'gathering', 'about' => 'thing:7']);
        $this->assertSame([$this->ids['bo'], $this->ids['cy']], $this->audience($r));
        $n = $this->stored(['kind' => 'gathering', 'about' => 'thing:7']);
        $this->assertSame([$this->ids['ann']], $this->audience($n), 'an announcement in another kind still goes to the group, by its switch');
    }

    public function testTheLedgerRowIsWrittenBeforeAnythingIsSentAndStopsASecondCopy(): void {
        $c = $this->stored();
        $this->assertSame('not_sent', Campaigns::deliver($c, $this->ids['ann'], false));
        $row = Bean::findOne('msgsend', 'msgcampaign_id = ? AND member_id = ?', [(int) $c->id, $this->ids['ann']]);
        $this->assertNotNull($row, 'recorded though nothing could be sent');
        $this->assertNull($row->sentAt);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $row->token);
        $this->assertSame(['https://example.org/x'], json_decode((string) $row->links, true));
        $this->assertNotContains($this->ids['ann'], $this->audience($c));
    }

    public function testRenderFillsVariablesRewritesLinksAndRefusesAnUnknownOne(): void {
        $c = $this->campaign(['subject' => 'Hi {first_name|you} from {group.name}']);
        $r = Campaigns::render($c, $this->ids['ann'], str_repeat('c', 32));
        $this->assertSame('Hi Ann from Walkers', $r['subject']);
        $this->assertSame(['https://example.org/x'], $r['links']);
        $this->assertStringContainsString('/msgtrack/l/' . str_repeat('c', 32) . '/0', $r['html']);
        $this->assertStringContainsString('/msgtrack/o/' . str_repeat('c', 32) . '.gif', $r['html']);
        $this->assertStringContainsString($r['unsubscribe_url'], $r['html']);
        $this->assertStringContainsString('Unsubscribe: ' . $r['unsubscribe_url'], $r['text']);
        $c->subject = 'Hi {nobody}';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nobody');
        Campaigns::render($c, $this->ids['ann']);
    }

    public function testAMessageMayOnlyNameVariablesItCanFill(): void {
        $c = $this->campaign();
        $this->assertSame([], Campaigns::unknownVariables($c, 'body_email', 'Hi {first_name|there} {unsubscribe_url} {group.name}'));
        $this->assertSame(['nonsense', 'gathering.title'], Campaigns::unknownVariables($c, 'body_email', '{nonsense} {gathering.title|x} {nonsense}'));
        $this->assertSame(['content_html'], Campaigns::unknownVariables($c, 'body_email', '{content_html|}'));
        $c->kind = 'digest';
        $this->assertSame([], Campaigns::unknownVariables($c, 'body_email', '{headline}{content_html|}'));
        $this->assertSame(['content_html'], Campaigns::unknownVariables($c, 'body_text', '{content_html}'));
    }

    public function testAnActionIsCreditedToTheSendClickedInTheLast48Hours(): void {
        $c = $this->stored();
        $this->assertNull(Campaigns::acted($this->ids['ann'], 'rsvp'));
        $s = $this->ledger($c, $this->ids['ann'], str_repeat('a', 32));
        $click = \Model_Msgevent::record($s, 'click', ['link' => 0]);
        $this->assertSame('rsvp', Campaigns::acted($this->ids['ann'], 'rsvp', ['thing' => 3])->kind);
        $this->assertNotNull(Bean::load('msgsend', (int) $s->id)->actedAt);
        $click->at = date('Y-m-d H:i:s', time() - 3 * 86400);
        Bean::store($click);
        $this->assertNull(Campaigns::acted($this->ids['ann'], 'post'));
    }

    public function testTheWeeklyCapCountsAnOwnersMessagesToOneAudienceNotRemindersOrDrafts(): void {
        $this->stored(['status' => 'sent']);
        $this->stored(['status' => 'scheduled']);
        $this->stored(['status' => 'draft']);
        $this->stored(['status' => 'scheduled', 'category' => $this->remindersId, 'kind' => 'gathering', 'about' => 'thing:7']);
        $this->assertSame(2, Campaigns::weeklyCount('group:1'));
        $this->assertSame(0, Campaigns::weeklyCount('group:2'));
        $this->assertSame(Campaigns::DEFAULT_WEEKLY_CAP, Campaigns::weeklyCap());
        self::$settings[Campaigns::CAP_SETTING] = '5';
        $this->assertSame(5, Campaigns::weeklyCap());
        self::$settings[Campaigns::CAP_SETTING] = 'many';
        $this->expectException(\RuntimeException::class);
        Campaigns::weeklyCap();
    }

    public function testStatsCountWhatHappenedToEachCampaign(): void {
        $c = $this->stored();
        $s = $this->ledger($c, $this->ids['ann'], str_repeat('c', 32));
        $s->sentAt = date('Y-m-d H:i:s'); $s->deliveredAt = date('Y-m-d H:i:s');
        Bean::store($s);
        \Model_Msgevent::record($s, 'reply', ['from' => 'x']);
        $st = Campaigns::stats([(int) $c->id])[(int) $c->id];
        $this->assertSame([1, 1, 1, 0, 0, 1], [$st['recipients'], $st['sent'], $st['delivered'], $st['clicked'], $st['acted'], $st['replies']]);
    }

    // ---- texts ----

    public function testAReminderTextsOnlyThoseWhoAskedAndEveryTextEndsWithTheStopLine(): void {
        FakeAudiences::$members['thing:7'] = [$this->ids['bo']];
        FakeAudiences::$names['thing:7'] = 'Picnic';
        $c = $this->stored(['category' => $this->remindersId, 'kind' => 'gathering', 'about' => 'thing:7', 'sms' => 'Reminder: Picnic {link}', 'scope' => null]);
        $bo = $this->ids['bo'];
        $this->assertSame([], $this->audience($c, 'text'));
        FakePhone::$book[$bo] = '+15555550123';
        \Model_Msgpref::set($bo, 'reminders', 'text', true);
        $this->assertSame([$bo], $this->audience($c, 'text'));
        $this->assertSame([$bo], $this->audience($c, 'email'), 'the email list is its own');
        $r = Campaigns::renderText($c, $bo, 'abcdef012345');
        $this->assertSame(1, $r['parts'], $r['text']);
        $this->assertStringContainsString('https://example.test/msgtrack/l/abcdef012345/0', $r['text']);
        $this->assertMatchesRegularExpression('~\nReply STOP or: https://example\.test/msgpref/unsubscribe\?t=\S+$~', $r['text']);
        $this->assertSame(['https://example.test/thing/7'], $r['links']);
    }

    public function testOwnerTextsNeedThePlatformSwitchAndAnOwnText(): void {
        $c = $this->campaign(['sms' => 'Come along']);
        $this->assertFalse(Campaigns::sendsText($c), 'owners may not text until the platform says so');
        self::$settings[Campaigns::OWNERS_TEXT_SETTING] = '1';
        $this->assertTrue(Campaigns::sendsText($c));
        $c->bodySms = null;
        $this->assertFalse(Campaigns::sendsText($c), 'no text written, no text sent');
        $c->bodySms = 'Come along'; $c->kind = 'digest';
        $this->assertFalse(Campaigns::sendsText($c), 'the digest and the nudges never text');
    }

    public function testATextOverOnePartIsReportedAsSuchAndANeedlessLinkIsRefused(): void {
        $c = $this->campaign(['sms' => str_repeat('word ', 40)]);
        $this->assertGreaterThan(1, Campaigns::renderText($c, $this->ids['ann'], 'abcdef012345')['parts']);
        $c->bodySms = 'Come {link}'; $c->scopeKey = null;
        $this->expectException(\RuntimeException::class);
        Campaigns::renderText($c, $this->ids['ann'], 'abcdef012345');
    }
}
