<?php
namespace app\concepts\messaging\tests;

require_once __DIR__ . '/MessagingCase.php';

use app\Bean;
use app\concepts\messaging\Reach;

/** May a message reach a member: the kind's default, their choice, the audience's own switch, suppression, a proved phone — and the signed link. */
class ReachTest extends MessagingCase {

    public function testNoChoiceMeansTheKindsDefaultAndATextIsNeverOnByDefault(): void {
        $this->assertTrue(Reach::allows($this->ids['ann'], 'reminders', 'email'));
        $this->assertFalse(Reach::allows($this->ids['ann'], 'reminders', 'text'));
    }

    public function testASwitchedOffChannelStopsOnlyThatChannel(): void {
        \Model_Msgpref::set($this->ids['ann'], 'reminders', 'email', false);
        $this->assertFalse(Reach::allows($this->ids['ann'], 'reminders', 'email'));
        $this->assertTrue(Reach::allows($this->ids['ann'], 'news', 'email'), 'another kind is untouched');
    }

    public function testTransactionalIsAlwaysAllowedAndCannotBeSwitchedOff(): void {
        $this->assertTrue(Reach::allows($this->ids['ann'], 'transactional', 'email'));
        $this->expectException(\InvalidArgumentException::class);
        \Model_Msgpref::set($this->ids['ann'], 'transactional', 'email', false);
    }

    public function testTheAudiencesOwnSwitchAndMembershipDecide(): void {
        $ann = $this->ids['ann']; $bo = $this->ids['bo']; $cy = $this->ids['cy'];
        $this->assertTrue(Reach::allows($ann, 'news', 'email', 'group:1'));
        FakeAudiences::$switch['group:1'][$bo] = false;
        $this->assertFalse(Reach::allows($bo, 'news', 'email', 'group:1'), 'they switched this group off');
        $this->assertTrue(Reach::allows($bo, 'news', 'email'), 'but not the kind');
        $this->assertFalse(Reach::allows($cy, 'news', 'email', 'group:1'), 'not in the group');
        $this->assertTrue(Reach::allows($cy, 'news', 'email', 'group:1', true), 'unless the caller already listed the group and says so');
    }

    public function testASuppressedAddressGetsOnlyTransactionalEmail(): void {
        \Model_Msgsuppression::add('ann@example.test', 'bounced');
        $this->assertFalse(Reach::allows($this->ids['ann'], 'news', 'email'));
        $this->assertFalse(Reach::allows($this->ids['ann'], 'reminders', 'email'));
        $this->assertTrue(Reach::allows($this->ids['ann'], 'transactional', 'email'));
        $this->assertTrue(Reach::allows($this->ids['bo'], 'news', 'email'), 'someone else\'s address is fine');
    }

    public function testATextNeedsTheSwitchTheOptInAndAProvedPhone(): void {
        $ann = $this->ids['ann'];
        \Model_Msgpref::set($ann, 'reminders', 'text', true);
        $this->assertFalse(Reach::allows($ann, 'reminders', 'text'), 'switched on, but no phone was proved');
        $this->assertNotEmpty(Bean::getCell('SELECT consented_at FROM msgpref WHERE member_id = ? AND channel = ?', [$ann, 'text']), 'when they asked is on the record');
        FakePhone::$book[$ann] = '+15555550123';
        $this->assertTrue(Reach::allows($ann, 'reminders', 'text'));
        \app\concepts\messaging\Providers::register(['audiences' => FakeAudiences::class]);
        $this->assertFalse(Reach::allows($ann, 'reminders', 'text'), 'no phone provider: texts are off');
    }

    public function testTheLinkNamesTheSwitchAndATamperedOneIsRefused(): void {
        $url = Reach::unsubscribeUrl($this->ids['ann'], 'reminders');
        $this->assertStringStartsWith('https://example.test/msgpref/unsubscribe?t=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $t = Reach::readToken($q['t']);
        $this->assertSame(['member' => $this->ids['ann'], 'category' => 'reminders', 'scope' => null, 'channel' => 'email'], $t);
        $this->assertNull(Reach::readToken(substr($q['t'], 0, -1) . (substr($q['t'], -1) === 'a' ? 'b' : 'a')));
        Reach::tokenSwitchOff($t);
        $this->assertFalse(Reach::tokenSwitchOn($t));
    }

    public function testAnAudienceLinkSwitchesOffThatAudienceOnly(): void {
        $url = Reach::unsubscribeUrl($this->ids['bo'], 'news', 'group:1');
        $t = Reach::readToken(substr($url, strpos($url, 't=') + 2));
        $this->assertSame('group:1', $t['scope']);
        $this->assertTrue(Reach::tokenSwitchOn($t));
        Reach::tokenSwitchOff($t);
        $this->assertFalse(FakeAudiences::$switch['group:1'][$this->ids['bo']]);
        $this->assertTrue(Reach::allows($this->ids['bo'], 'news', 'email'), 'the kind itself stays on');
    }

    public function testALinkIssuedBeforeAudiencesWereKeysStillReads(): void {
        // member.category.communityId, signed with the same key, as the app made them before the plugin
        $body = rtrim(strtr(base64_encode($this->ids['bo'] . '.' . $this->newsId . '.5'), '+/', '-_'), '=');
        $sig = substr(hash_hmac('sha256', $body, \app\EncryptionService::deriveKey('unsubscribe')), 0, 32);
        $this->assertSame('community:5', Reach::readToken("$body.$sig")['scope']);
    }

    public function testTheTextLinkHasTheShortSignatureAndCannotStandInForAnEmailLink(): void {
        $g = $this->ids['ann'];
        FakePhone::$book[$g] = '+15555550123';
        \Model_Msgpref::set($g, 'reminders', 'text', true);
        $text = Reach::unsubscribeUrl($g, 'reminders', null, 'text');
        [$body, $sig] = explode('.', substr($text, strpos($text, 't=') + 2));
        $t = Reach::readToken("$body.$sig");
        $this->assertSame('text', $t['channel']);
        $this->assertNull(Reach::readToken("$body." . substr($sig, 0, 8)), 'a signature of the wrong length');
        Reach::tokenSwitchOff($t);
        $this->assertFalse(Reach::allows($g, 'reminders', 'text'));
        $this->assertTrue(Reach::allows($g, 'reminders', 'email'), 'the email stays on');
        $email = Reach::unsubscribeUrl($g, 'reminders');
        $this->assertSame('email', Reach::readToken(substr($email, strpos($email, 't=') + 2))['channel']);
    }
}
