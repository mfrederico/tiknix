<?php
/**
 * Model_Lead::capture() — the one way a lead row is written.
 *
 *   one per email     case-insensitive; the stored email is lower-cased
 *   fill, never overwrite   blanks filled on a returning lead; name, source and status kept
 *   source            set on create only; 'website' when the caller does not say
 *   gate              required: a public gate's verdict sets status/spam_reason; a trusted gate says why
 *   turnstile         optional: without it a public form still takes leads (recorded as not
 *                     checked), the other checks still flag spam, and the admins are told once
 *   refusals          no gate; a non-address
 *   splitName         "Ana María López" → ['Ana', 'María López']
 *
 * Scratch in-memory database (Model_Lead's hooks are exercised through Bean::store).
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\LeadGate;

class LeadCaptureTest extends ConceptsTestCase {

    private const DB = 'lead-capture-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    public function testOneLeadPerEmailFilledInOverTime(): void {
        $g = LeadGate::trusted('test');
        $a = \Model_Lead::capture('Zoe@Example.com', 'Zoe', '', ['gate' => $g, 'source' => 'website', 'ip' => '203.0.113.9', 'userAgent' => 'UA']);
        $this->assertSame(['zoe@example.com', 'Zoe', '', 'website', 'new', '203.0.113.9', 'trusted: test'], [$a->email, $a->firstName, $a->lastName, $a->source, $a->status, $a->ipAddress, $a->gate]);
        $b = \Model_Lead::capture('ZOE@example.com', 'Zoë', 'Quinn', ['gate' => $g, 'source' => 'appointment', 'phone' => '555-0100']);
        $this->assertSame((int) $a->id, (int) $b->id);
        $this->assertSame(['Zoe', 'Quinn', '555-0100', 'website', 'new', ''], [$b->firstName, $b->lastName, $b->phone, $b->source, $b->status, (string) $b->spamReason], 'blanks filled; name, source and status kept');
        $this->assertSame(1, Bean::count('lead'));
        $this->assertSame('Zoe Quinn', Bean::load('lead', $a->id)->box()->fullName());
    }

    public function testAPublicGateFlagsSpamRatherThanRefusing(): void {
        // A public gate's verdict, built the way forPublicForm() builds it (Turnstile itself is
        // not connected in the test install — see testAPublicFormNeedsTurnstile).
        $g = LeadGate::trusted('x'); $g->kind = 'public'; $g->why = ''; $g->reasons = ['turnstile', 'submitted in 1s'];
        $this->assertSame('spam', $g->status());
        $l = \Model_Lead::capture('bot@example.com', 'Xqz', 'Wvb', ['gate' => $g]);
        $this->assertTrue($l->box()->isSpam());
        $this->assertSame(['turnstile, submitted in 1s', 'public', 'website'], [$l->spamReason, $l->gate, $l->source]);
        $g->reasons = [];
        $this->assertSame('new', $g->status(), 'a clean public gate');
    }

    public function testNoGateNoLead(): void {
        try { \Model_Lead::capture('a@example.com', 'A', 'B'); $this->fail('accepted without a gate'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('needs a gate', $e->getMessage()); }
        try { \Model_Lead::capture('a@example.com', 'A', 'B', ['gate' => 'public']); $this->fail('accepted a string'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('needs a gate', $e->getMessage()); }
        try { LeadGate::trusted('  '); $this->fail('trusted without a reason'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('needs a reason', $e->getMessage()); }
        $this->assertSame(0, Bean::count('lead'));
    }

    public function testWithoutTurnstileAPublicFormStillTakesLeadsAndTheAdminsAreTold(): void {
        // An install with no connections at all (an empty store): Turnstile is not connected.
        $empty = sys_get_temp_dir() . '/tiknix-noturnstile-' . getmypid();
        @mkdir($empty . '/data', 0700, true);
        \app\ConnectionStore::useInstall($empty);
        \app\Turnstile::flush();
        try {
            $this->runWithoutTurnstile();
        } finally {
            \app\ConnectionStore::useOwnInstall();
            \app\Turnstile::flush();
            exec('rm -rf ' . escapeshellarg($empty));
        }
    }

    private function runWithoutTurnstile(): void {
        // The notice keeps when it last went out in a system setting; outside a booted app, an
        // in-memory one. Mapped only when missing: a Flight mapping outlives this test.
        $settings = [];
        try { \Flight::getSetting('probe'); } catch (\Throwable $e) {
            \Flight::map('getSetting', function ($k) use (&$settings) { return $settings[$k] ?? null; });
            \Flight::map('setSetting', function ($k, $v) use (&$settings) { $settings[$k] = $v; });
        }
        try { \Flight::siteName(); } catch (\Throwable $e) { \Flight::map('siteName', fn() => (string) \Flight::get('app.name')); }
        $this->assertFalse(\app\Turnstile::enabled());
        $opts = ['name' => ['Ann', 'Lee'], 'email' => 'ann@example.com', 'honeypot' => 'company_website'];
        $admin = Bean::dispense('member');
        $admin->email = 'owner@example.com'; $admin->username = 'owner'; $admin->level = 1; $admin->status = 'active';
        Bean::store($admin);

        // A clean submission is a lead, recorded as not bot-checked.
        $g = LeadGate::forPublicForm([], '203.0.113.1', $opts);
        $this->assertSame(['turnstile'], $g->unchecked);
        $this->assertSame([], $g->reasons);
        $this->assertSame('new', $g->status());
        \Model_Lead::capture('ann@example.com', 'Ann', 'Lee', ['gate' => $g]);
        $this->assertSame('public (not checked: turnstile)', (string) Bean::findOne('lead', 'email = ?', ['ann@example.com'])->gate);

        // The other checks still flag spam.
        $spam = LeadGate::forPublicForm(['company_website' => 'http://spam'], '203.0.113.1', $opts + ['shown_at' => time()]);
        $this->assertContains('honeypot', $spam->reasons);
        $this->assertSame('spam', $spam->status());

        // The admins were told once, in Communications — not once per submission.
        $threads = Bean::find('thread', 'related_type = ?', ['notice']);
        $this->assertCount(1, $threads, 'one notice thread for the two submissions');
        $this->assertStringContainsString('Turnstile', (string) reset($threads)->subject);
    }

    public function testRefusals(): void {
        try { \Model_Lead::capture('not-an-email', 'A', 'B', ['gate' => LeadGate::trusted('t')]); $this->fail('accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('not an email address', $e->getMessage()); }
        $this->assertSame(0, Bean::count('lead'));
    }

    public function testSplitName(): void {
        $this->assertSame(['Ana', 'María López'], \Model_Lead::splitName("  Ana  María López "));
        $this->assertSame(['Cher', ''], \Model_Lead::splitName('Cher'));
        $this->assertSame(['', ''], \Model_Lead::splitName('   '));
    }
}
