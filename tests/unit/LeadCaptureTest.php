<?php
/**
 * Model_Lead::capture() — the one way a lead row is written.
 *
 *   one per email     case-insensitive; the stored email is lower-cased
 *   fill, never overwrite   blanks filled on a returning lead; name, source and status kept
 *   source            set on create only; 'website' when the caller does not say
 *   status            'new' or 'spam' on create from the caller's checks; never downgraded later
 *   refusals          a non-address; an unknown status
 *   splitName         "Ana María López" → ['Ana', 'María López']
 *
 * Scratch in-memory database (Model_Lead's hooks are exercised through Bean::store).
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;

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
        $a = \Model_Lead::capture('Zoe@Example.com', 'Zoe', '', ['source' => 'website', 'ip' => '203.0.113.9', 'userAgent' => 'UA']);
        $this->assertSame(['zoe@example.com', 'Zoe', '', 'website', 'new', '203.0.113.9'], [$a->email, $a->firstName, $a->lastName, $a->source, $a->status, $a->ipAddress]);
        $b = \Model_Lead::capture('ZOE@example.com', 'Zoë', 'Quinn', ['source' => 'appointment', 'phone' => '555-0100', 'status' => 'spam', 'spamReason' => 'turnstile']);
        $this->assertSame((int) $a->id, (int) $b->id);
        $this->assertSame(['Zoe', 'Quinn', '555-0100', 'website', 'new', ''], [$b->firstName, $b->lastName, $b->phone, $b->source, $b->status, (string) $b->spamReason], 'blanks filled; name, source and status kept');
        $this->assertSame(1, Bean::count('lead'));
        $this->assertSame('Zoe Quinn', Bean::load('lead', $a->id)->box()->fullName());
    }

    public function testSpamIsRecordedOnCreateNotRefused(): void {
        $l = \Model_Lead::capture('bot@example.com', 'Xqz', 'Wvb', ['status' => 'spam', 'spamReason' => 'submitted in 1s, generated name']);
        $this->assertTrue($l->box()->isSpam());
        $this->assertSame('submitted in 1s, generated name', $l->spamReason);
        $this->assertSame('website', $l->source, 'the default source');
    }

    public function testRefusals(): void {
        try { \Model_Lead::capture('not-an-email', 'A', 'B'); $this->fail('accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('not an email address', $e->getMessage()); }
        try { \Model_Lead::capture('a@example.com', 'A', 'B', ['status' => 'hot']); $this->fail('accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString("'new' or 'spam'", $e->getMessage()); }
        $this->assertSame(0, Bean::count('lead'));
    }

    public function testSplitName(): void {
        $this->assertSame(['Ana', 'María López'], \Model_Lead::splitName("  Ana  María López "));
        $this->assertSame(['Cher', ''], \Model_Lead::splitName('Cher'));
        $this->assertSame(['', ''], \Model_Lead::splitName('   '));
    }
}
