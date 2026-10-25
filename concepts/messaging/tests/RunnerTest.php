<?php
namespace app\concepts\messaging\tests;

require_once __DIR__ . '/MessagingCase.php';

use app\Bean;
use app\concepts\messaging\Campaigns;
use app\concepts\messaging\Digest;
use app\concepts\messaging\Providers;
use app\concepts\messaging\Runner;

/** The hourly run: what it leaves alone, and that a period's message goes once. */
class RunnerTest extends MessagingCase {

    private function sends($c): int { return Bean::count('msgsend', 'msgcampaign_id = ?', [(int) $c->id]); }

    public function testAPausedCampaignSendsNothing(): void {
        $paused = $this->stored(['kind' => 'message', 'status' => 'paused', 'cadence' => 'every_n_days', 'days' => 7]);
        $draft = $this->stored(['status' => 'draft']);
        $this->assertSame(['nothing due'], Runner::hourly());
        $this->assertSame(0, $this->sends($paused));
        $this->assertSame(0, $this->sends($draft));
    }

    public function testAScheduledMessageGoesOutWhenItsTimeComesAndOnlyOnce(): void {
        $later = $this->stored(['status' => 'scheduled']);
        $later->scheduledAt = date('Y-m-d H:i:s', time() + 3600); Bean::store($later);
        $due = $this->stored(['status' => 'scheduled']);
        $due->scheduledAt = date('Y-m-d H:i:s', time() - 60); Bean::store($due);
        Runner::hourly();
        $this->assertSame(0, $this->sends($later));
        $this->assertSame(2, $this->sends($due), 'ann and bo are recorded (nothing can be mailed in a test, so the campaign stays "sending")');
        Runner::hourly();
        $this->assertSame(2, $this->sends($due), 'a second run adds nobody');
    }

    public function testARepeatingMessageGoesOncePerPeriod(): void {
        $c = $this->stored(['status' => 'active', 'cadence' => 'every_n_days', 'days' => 7]);
        $delivered = function () use ($c) {   // what a send that reached everyone leaves behind (no mail can go out in a test)
            Bean::exec('UPDATE msgsend SET sent_at = ? WHERE msgcampaign_id = ?', [date('Y-m-d H:i:s'), (int) $c->id]);
            Bean::exec("UPDATE msgcampaign SET status = 'active' WHERE id = ?", [(int) $c->id]);
        };
        Runner::hourly();
        $this->assertSame(2, $this->sends($c));
        $delivered();
        Runner::hourly();
        $delivered();
        Runner::hourly();
        $this->assertSame(2, $this->sends($c), 'once per period, however often the run comes');
        Bean::exec('UPDATE msgsend SET created_at = ? WHERE msgcampaign_id = ?', [date('Y-m-d H:i:s', time() - 8 * 86400), (int) $c->id]);
        $delivered();
        Runner::hourly();
        $this->assertSame(4, $this->sends($c), 'a new period, a new copy');
    }

    public function testTheDigestIsUnavailableWithoutAProviderAndNeverSends(): void {
        $d = $this->stored(['kind' => 'digest', 'status' => 'active', 'scope' => null, 'cadence' => 'every_n_days', 'days' => 7, 'subject' => '{headline}']);
        $this->assertFalse(Digest::available());
        $this->assertArrayHasKey('skipped', Digest::run($d));
        Runner::hourly();
        $this->assertSame(0, $this->sends($d));
        $this->assertStringContainsString('unavailable', implode("\n", Runner::dryRun()));
    }

    public function testANudgeGoesToThoseWhoReachedItsTriggerWithTheAppsContent(): void {
        Providers::register(['audiences' => FakeAudiences::class, 'triggers' => ['lonely_days' => FakeTrigger::class]]);
        $n = $this->stored(['kind' => 'trigger', 'status' => 'active', 'scope' => null, 'trigger' => 'lonely_days', 'triggerDays' => 3, 'subject' => '{headline}', 'cadence' => 'once']);
        FakeTrigger::$who = [$this->ids['cy']];
        $this->assertSame('Days alone', Digest::triggerLabels()['lonely_days']);
        $this->assertArrayHasKey(Digest::CODE_INACTIVE, Digest::triggerLabels());
        $plan = Digest::plan($n, false);
        $this->assertSame([$this->ids['cy']], array_column($plan, 'member'));
        $this->assertSame('Come back', $plan[0]['headline']);
        Runner::hourly();
        $this->assertSame(1, $this->sends($n));
    }

    public function testTheBuiltInInactivityTriggerReadsTheMembersLastSignIn(): void {
        $n = $this->stored(['kind' => 'trigger', 'status' => 'active', 'scope' => null, 'trigger' => Digest::CODE_INACTIVE, 'triggerDays' => 14, 'cadence' => 'once']);
        foreach (['bo' => time() - 30 * 86400, 'ann' => time()] as $who => $at) {
            $m = Bean::load('member', $this->ids[$who]);
            $m->lastLogin = date('Y-m-d H:i:s', $at);
            Bean::store($m);
        }
        $this->assertSame([$this->ids['bo']], array_values(array_intersect(array_column(Digest::plan($n, false), 'member'), array_values($this->ids))));
    }

    public function testASendTimeIsTheMembersLocalSundayFromNine(): void {
        self::$settings['timezone'] = 'Asia/Tokyo';
        $sundayEightJst = strtotime('2026-10-25 08:00:00 Asia/Tokyo');
        $this->assertFalse(Digest::isSendTime(1, $sundayEightJst));
        $this->assertTrue(Digest::isSendTime(1, $sundayEightJst + 3600));
        $this->assertFalse(Digest::isSendTime(1, $sundayEightJst + 86400 + 3600), 'Monday is not digest day');
    }

    public function testAProviderThatIsMissingAMethodIsRefusedAtBootNamingIt(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FakePhone) has no static method scopes()');
        Providers::register(['audiences' => FakePhone::class]);
    }
}

class FakeTrigger {
    public static array $who = [];
    public static function label(): string { return 'Days alone'; }
    public static function qualifies(int $memberId, int $days): bool { return in_array($memberId, self::$who, true); }
    public static function content(int $memberId): array { return ['headline' => 'Come back', 'html' => '<p>hi</p>', 'text' => 'hi']; }
}
