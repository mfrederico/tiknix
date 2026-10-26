<?php
/** When a message goes out, as one choice with its follow-up: what the service is told, and what is refused by field. */

namespace app\concepts\messaging\tests;

require_once __DIR__ . '/ClientCase.php';

use app\concepts\messaging\Campaigns;

class WhenDocTest extends ClientCase {

    private function form(array $over): array {
        return $over + ['subject' => 'Hello', 'body_email' => '<p>Hi</p>', 'body_text' => '', 'on' => '0', 'scheduled_at' => '', 'cadence_days' => '', 'trigger_days' => '', 'code' => ''];
    }

    public function testACustomMessageIsATriggerWithACode(): void {
        [$doc, $problems] = Campaigns::whenDoc($this->form(['when' => 'custom', 'code' => 'welcome_1']), null);
        $this->assertSame([], $problems);
        $this->assertSame('trigger', $doc['kind']); $this->assertSame('pipeline', $doc['trigger_code']); $this->assertSame('welcome_1', $doc['code']); $this->assertSame('paused', $doc['status']);
        [, $problems] = Campaigns::whenDoc($this->form(['when' => 'custom', 'code' => 'Welcome 1']), null);
        $this->assertArrayHasKey('code', $problems);
    }

    public function testADatedMessageIsADraftUntilOnAndRefusesThePast(): void {
        [$doc, $problems] = Campaigns::whenDoc($this->form(['when' => 'date']), null);
        $this->assertSame([], $problems); $this->assertSame('draft', $doc['status']); $this->assertArrayNotHasKey('scheduled_at', $doc);
        [, $problems] = Campaigns::whenDoc($this->form(['when' => 'date', 'on' => '1', 'scheduled_at' => '2020-01-01T09:00']), null);
        $this->assertSame('That time has passed. Pick a time ahead.', $problems['scheduled_at']);
        $future = date('Y-m-d\TH:i', time() + 86400);
        [$doc, $problems] = Campaigns::whenDoc($this->form(['when' => 'date', 'on' => '1', 'scheduled_at' => $future]), null);
        $this->assertSame([], $problems); $this->assertSame('scheduled', $doc['status']); $this->assertStringStartsWith(substr($future, 0, 10), $doc['scheduled_at']);
    }

    public function testTheOthersAndTheWords(): void {
        [$doc, $problems] = Campaigns::whenDoc($this->form(['when' => 'joined', 'trigger_days' => '3', 'on' => '1']), null);
        $this->assertSame([], $problems); $this->assertSame('joined_days', $doc['trigger_code']); $this->assertSame(3, $doc['trigger_days']); $this->assertSame('active', $doc['status']);
        [, $problems] = Campaigns::whenDoc($this->form(['when' => 'repeat', 'cadence_days' => '0']), null);
        $this->assertArrayHasKey('cadence_days', $problems);
        [, $problems] = Campaigns::whenDoc($this->form(['when' => 'inactive', 'trigger_days' => '14', 'subject' => '', 'body_email' => '']), null);
        $this->assertSame(['subject', 'body_email'], array_keys($problems));
        [, $problems] = Campaigns::whenDoc($this->form(['when' => 'digest']), null);
        $this->assertArrayHasKey('when', $problems, 'the digest is declared in code');
        [, $problems] = Campaigns::whenDoc($this->form(['when' => '']), null);
        $this->assertArrayHasKey('when', $problems);
    }

    public function testTurningOnNeedsADateForADatedMessage(): void {
        $dated = ['kind' => 'message', 'cadence' => 'once', 'scheduled_at' => '', 'status' => 'draft'];
        $this->assertSame([null, 'Set the day and time it goes out on Edit first.'], Campaigns::statusFor($dated, true));
        $this->assertSame(['draft', null], Campaigns::statusFor($dated, false));
        $this->assertSame(['scheduled', null], Campaigns::statusFor(['scheduled_at' => date('Y-m-d H:i:s', time() + 3600)] + $dated, true));
        $custom = ['kind' => 'trigger', 'cadence' => 'once', 'scheduled_at' => '', 'status' => 'paused'];
        $this->assertSame(['active', null], Campaigns::statusFor($custom, true));
        $this->assertSame(['paused', null], Campaigns::statusFor($custom, false));
    }
}
