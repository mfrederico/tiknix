<?php
/**
 * Legal — /terms and /privacy speak for the site in its own config ([legal]), never for Tiknix
 * or another site: the name from [legal] name else [app] name, and no page at all (an ERROR,
 * a "not published" notice) without an operator and an email.
 */

namespace tests\unit;

use app\Legal;
use PHPUnit\Framework\TestCase;

class LegalTest extends TestCase {

    private array $saved = [];
    private const KEYS = ['legal.name', 'legal.operator', 'legal.email', 'legal.governing_law', 'legal.updated', 'app.name'];

    protected function setUp(): void {
        foreach (self::KEYS as $k) { $this->saved[$k] = \Flight::get($k); \Flight::set($k, null); }
    }

    protected function tearDown(): void {
        foreach ($this->saved as $k => $v) \Flight::set($k, $v);
    }

    public function testNamedByTheSiteTitleAndOperatedByWhoItSays(): void {
        \Flight::set('app.name', 'Collectiq');
        \Flight::set('legal.operator', 'Example LLC');
        \Flight::set('legal.email', 'legal@example.com');
        $d = Legal::details();
        $this->assertSame('Collectiq', $d['name']);
        $this->assertSame('Example LLC', $d['operator']);
        $this->assertSame([], $d['missing']);
        $this->assertSame('', $d['governing_law'], 'optional, and not invented');
    }

    public function testLegalNameOverridesTheTitle(): void {
        \Flight::set('app.name', 'collectiq');
        \Flight::set('legal.name', 'CollectIQ.com');
        \Flight::set('legal.operator', 'Example LLC');
        \Flight::set('legal.email', 'legal@example.com');
        $this->assertSame('CollectIQ.com', Legal::details()['name']);
    }

    public function testNoOperatorOrEmailIsNotPublished(): void {
        \Flight::set('app.name', 'Collectiq');
        $log = ini_set('error_log', '/dev/null');
        try { $d = Legal::details(); } finally { ini_set('error_log', (string) $log); }
        $this->assertSame(['[legal] operator', '[legal] email'], $d['missing']);
        $this->assertSame('', $d['operator'], 'never someone else\'s entity in its place');
    }
}
