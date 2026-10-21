<?php
/**
 * app\AppWatch::decide — the watcher's whole policy: a quiet app whose container stopped by itself is
 * started again; one somebody stopped is left alone; one that keeps stopping is left and reported;
 * a running but quiet one is reported; each told once, and its recovery said once.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\AppWatch;

class AppWatchTest extends TestCase {
    private const QUIET = AppWatch::QUIET + 60;

    public function testAReportingAppIsLeftAlone(): void {
        $this->assertSame('ok', AppWatch::decide(['quiet' => 600, 'state' => 'ok']));
        $this->assertSame('recovered', AppWatch::decide(['quiet' => 600, 'state' => 'silent']), 'back after trouble: said once');
    }

    public function testAContainerThatStoppedByItselfIsStartedAgain(): void {
        $this->assertSame('restart', AppWatch::decide(['quiet' => self::QUIET, 'state' => 'ok', 'ct' => 'stopped', 'stoppedBy' => '', 'restarts' => 0]));
        $this->assertSame('restart', AppWatch::decide(['quiet' => null, 'state' => 'ok', 'ct' => 'stopped', 'stoppedBy' => '', 'restarts' => 1]), 'one earlier restart: try again');
    }

    public function testOneSomebodyStoppedIsLeftStoppedAndToldOnce(): void {
        $f = ['quiet' => self::QUIET, 'ct' => 'stopped', 'stoppedBy' => 'root@pam at 2026-10-21 02:00 UTC', 'restarts' => 0];
        $this->assertSame('stopped_on_purpose', AppWatch::decide($f + ['state' => 'ok']));
        $this->assertSame('wait', AppWatch::decide($f + ['state' => 'stopped_on_purpose']));
    }

    public function testOneThatKeepsStoppingIsLeftAndToldOnce(): void {
        $f = ['quiet' => self::QUIET, 'ct' => 'stopped', 'stoppedBy' => '', 'restarts' => AppWatch::MAX_RESTARTS];
        $this->assertSame('wont_stay_up', AppWatch::decide($f + ['state' => 'restarted']));
        $this->assertSame('wait', AppWatch::decide($f + ['state' => 'wont_stay_up']));
    }

    public function testRunningButQuietIsToldOnceAndARestartGetsItsTime(): void {
        $this->assertSame('silent', AppWatch::decide(['quiet' => self::QUIET, 'state' => 'ok', 'ct' => 'running']));
        $this->assertSame('wait', AppWatch::decide(['quiet' => self::QUIET, 'state' => 'silent', 'ct' => 'running']));
        $this->assertSame('wait', AppWatch::decide(['quiet' => self::QUIET, 'state' => 'restarted', 'ct' => 'running', 'sinceRestart' => 600]), 'just started: give it a report\'s time');
        $this->assertSame('silent', AppWatch::decide(['quiet' => self::QUIET, 'state' => 'restarted', 'ct' => 'running', 'sinceRestart' => self::QUIET]), 'started long ago and still quiet');
    }

    public function testAMissingContainerIsToldOnce(): void {
        $this->assertSame('missing', AppWatch::decide(['quiet' => self::QUIET, 'state' => 'ok', 'ct' => 'missing']));
        $this->assertSame('wait', AppWatch::decide(['quiet' => self::QUIET, 'state' => 'missing', 'ct' => 'missing']));
    }
}
