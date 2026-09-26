<?php
/**
 * ClaudeRunner::idleReasonFromPane — what an agent terminal that took the brief and did not
 * start is saying, as one actionable sentence; '' for anything it does not recognise.
 *
 * The panes are shortened captures of real Claude Code output.
 */

namespace tests\unit;

use app\ClaudeRunner;
use PHPUnit\Framework\TestCase;

class AgentIdleReasonTest extends TestCase {

    public function testKnownCausesAreNamed(): void {
        $expired = "● Login expired · Please run /login\n✻ Churned for 0s · done 10:12 AM\n   Not logged in · Run /login\n❯ ";
        $this->assertStringContainsString('login has expired', ClaudeRunner::idleReasonFromPane($expired));
        $this->assertStringContainsString('/login', ClaudeRunner::idleReasonFromPane($expired));
        $this->assertStringContainsString('401', ClaudeRunner::idleReasonFromPane("API Error: 401 {\"type\":\"error\",\"error\":{\"type\":\"authentication_error\"}}"));
        $this->assertStringContainsString('rate-limiting', ClaudeRunner::idleReasonFromPane("API Error: 429 rate limit exceeded"));
        $this->assertStringContainsString('server error', ClaudeRunner::idleReasonFromPane("API Error: 529 overloaded_error"));
        $this->assertStringContainsString('network', ClaudeRunner::idleReasonFromPane("Could not connect to api.anthropic.com (ENOTFOUND)"));
    }

    public function testAnIdlePromptWithNoKnownCauseIsSilent(): void {
        $this->assertSame('', ClaudeRunner::idleReasonFromPane("❯ \n  ⏵⏵ bypass permissions on (shift+tab to cycle)"));
        $this->assertSame('', ClaudeRunner::idleReasonFromPane(''));
    }
}
