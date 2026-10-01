<?php
use PHPUnit\Framework\TestCase;

/**
 * The builder terminal sends terminal output as WebSocket TEXT frames, which must be valid UTF-8:
 * a read from the PTY that ends inside a multibyte character keeps those bytes for the next frame.
 */
class TerminalBridgeTest extends TestCase {
    public function testAsciiIsSentWhole(): void {
        $this->assertSame(5, \app\TerminalBridge::utf8Boundary('hello'));
        $this->assertSame(0, \app\TerminalBridge::utf8Boundary(''));
    }

    public function testCompleteMultibyteCharactersAreSentWhole(): void {
        foreach (['é', '─', '🙂', "box ─│ ok"] as $s) {
            $this->assertSame(strlen($s), \app\TerminalBridge::utf8Boundary($s), bin2hex($s));
        }
    }

    public function testAnIncompleteTrailingCharacterWaitsForTheNextRead(): void {
        foreach (['─', '🙂', 'é'] as $ch) {
            for ($cut = 1; $cut < strlen($ch); $cut++) {
                $s = 'ab' . substr($ch, 0, $cut);
                $this->assertSame(2, \app\TerminalBridge::utf8Boundary($s), bin2hex($ch) . " cut at {$cut}");
            }
        }
    }
}
