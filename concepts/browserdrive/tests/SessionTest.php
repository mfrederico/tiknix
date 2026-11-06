<?php
use PHPUnit\Framework\TestCase;
use app\concepts\browserdrive\Session;

/** What can be checked without a database: which paths a session may open, and which commands reach a browser. */
class SessionTest extends TestCase {
    private array $allow = ['/demo', '/sandbox/*'];

    public function testAnAllowedPathPasses(): void {
        $this->assertSame('/demo', Session::matchPath('/demo', $this->allow));
        $this->assertSame('/sandbox/floor/2?x=1', Session::matchPath('/sandbox/floor/2?x=1', $this->allow));
        $this->assertSame('/sandbox', Session::matchPath('/sandbox', $this->allow));
    }
    public function testExactPatternsAreExact(): void {
        $this->expectException(\InvalidArgumentException::class); Session::matchPath('/demo/other', $this->allow);
    }
    public function testOtherSitesAndTricksAreRefused(): void {
        foreach (['https://evil.example/', '//evil.example/x', '/sandbox/../admin', 'demo', '/sandbox\\..\\x', '', '/sandboxevil', '/sandbox-x/y'] as $p) {
            try { Session::matchPath($p, $this->allow); $this->fail("{$p} passed"); } catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }
    public function testNothingAllowedIsAnErrorThatSaysWhatToDo(): void {
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('/browserdrive'); Session::matchPath('/demo', []);
    }
    public function testCommandsAreChecked(): void {
        $this->assertSame(['do' => 'click', 'target' => 'text=Save'], Session::clean(['do' => 'click', 'target' => 'text=Save']));
        $this->assertSame(['do' => 'press', 'key' => 'Enter', 'hold' => 50], Session::clean(['do' => 'press', 'key' => 'Enter', 'hold' => 50]));
        foreach ([['do' => 'shell'], ['do' => 'click', 'targe' => '#x'], ['do' => 'evaluate'], ['do' => 'evaluate', 'js' => '']] as $bad) {
            try { Session::clean($bad); $this->fail(json_encode($bad) . ' passed'); } catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }
    public function testScriptIsOffUntilAnAdminSwitchesItOn(): void {
        foreach ([['do' => 'evaluate', 'js' => 'document.title'], ['do' => 'waitFor', 'js' => 'window.x']] as $cmd) {
            try { Session::checkScript($cmd, false); $this->fail(json_encode($cmd) . ' ran with script off'); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString('/browserdrive', $e->getMessage()); }
            Session::checkScript($cmd, true);
        }
        Session::checkScript(['do' => 'click', 'target' => '#x'], false);
        Session::checkScript(['do' => 'waitFor', 'text' => 'Saved'], false);
        $this->addToAssertionCount(2);
    }
    public function testASessionPlaysOnlyInTheTabOfWhoOpenedItAndOnlyTheyCanDriveIt(): void {
        $was = \app\Bean::currentDatabaseKey();
        $db = 'bd-test-' . bin2hex(random_bytes(4));
        \app\Bean::addDatabase($db, 'sqlite::memory:');
        \app\Bean::selectDatabase($db);
        try {
            $s = \app\Bean::dispense('drivesession'); $s->path = '/demo'; $s->status = 'open'; $s->openedBy = 7; $s->runner = 0; $s->openedAt = date('Y-m-d H:i:s'); $s->seenAt = date('Y-m-d H:i:s'); $s->claimedAt = date('Y-m-d H:i:s'); $s->steps = 0; $id = (int) \app\Bean::store($s);
            $root = (object) ['id' => 1, 'level' => 1]; $admin = (object) ['id' => 7, 'level' => 50];
            $this->assertNull(Session::claim(1), "a ROOT tab must not play another admin's session");
            $this->assertSame('open', (string) \app\Bean::load('drivesession', $id)->status);
            try { Session::mine($id, $root); $this->fail('a ROOT key drove another admin\'s session'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not yours', $e->getMessage()); }
            $this->assertSame($id, (int) Session::mine($id, $admin)->id);
            $claimed = Session::claim(7);
            $this->assertSame($id, (int) $claimed->id);
            $this->assertSame(7, (int) \app\Bean::load('drivesession', $id)->runner);
            try { Session::mine($id, $root); $this->fail('a ROOT key sent steps into a claimed session'); } catch (\InvalidArgumentException $e) { $this->addToAssertionCount(1); }
        } finally { if ($was !== null && \app\Bean::hasDatabase($was)) \app\Bean::selectDatabase($was); }
    }
    public function testABrandNewAddressGetsItsTablesOnFirstUse(): void {
        $was = \app\Bean::currentDatabaseKey();
        $db = 'bd-fresh-' . bin2hex(random_bytes(4));
        \app\Bean::addDatabase($db, 'sqlite::memory:');
        \app\Bean::selectDatabase($db);
        $ready = new \ReflectionProperty(Session::class, 'ready'); $ready->setAccessible(true); $ready->setValue(null, false);
        try {
            $this->assertNull(Session::claim(1), 'no tables yet: it sets them up and finds nothing to play, it does not fail');
            foreach (['runner', 'claimed_at', 'seen_at', 'opened_by'] as $c) $this->assertArrayHasKey($c, \app\Bean::inspect('drivesession'));
            foreach (['result', 'sent_at', 'done_at', 'drivesession_id'] as $c) $this->assertArrayHasKey($c, \app\Bean::inspect('drivestep'));
            $this->assertSame('TEXT', strtoupper((string) \app\Bean::inspect('drivesession')['seen_at']), 'typed by the seed, never guessed from a first value');
        } finally { $ready->setValue(null, false); if ($was !== null && \app\Bean::hasDatabase($was)) \app\Bean::selectDatabase($was); }
    }
    public function testADragMeasuresItsEndsAfterTheLastScroll(): void {
        $runner = file_get_contents(dirname(__DIR__) . '/assets/runner.js');
        $this->assertStringContainsString('await dragPoints(c.from, c.to)', $runner);
        $this->assertStringNotContainsString('await point(c.from)', $runner, 'measuring one end, then scrolling to the other, presses the wrong thing');
    }
    public function testEveryCommandTheRunnerPlaysIsKnown(): void {
        $runner = file_get_contents(dirname(__DIR__) . '/assets/runner.js');
        foreach (array_keys(Session::COMMANDS) as $c) $this->assertStringContainsString("case '{$c}'", $runner, "the runner does not play {$c}");
    }
}
