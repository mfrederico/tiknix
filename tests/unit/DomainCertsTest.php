<?php
/**
 * DomainCerts::summary — a project's domains and the TLS they were last seen served with.
 * "Not checked" and "broken" must not look alike; the worst domain sets the state; the
 * soonest expiry is the number shown. Rows come from core's database, passed in as a PDO
 * when the caller's default connection is some other database (a sidecar's).
 */
namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\DomainCerts;

class DomainCertsTest extends ConceptsTestCase {

    private function core(array $rows): \PDO {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE domaincert (id INTEGER PRIMARY KEY, domain TEXT, instance_ref INTEGER, slug TEXT, ok INTEGER, expires_at TEXT, days_left INTEGER, subject TEXT, issuer TEXT, error TEXT, checked_at TEXT)');
        $st = $db->prepare('INSERT INTO domaincert (domain, instance_ref, ok, expires_at, days_left, error, checked_at) VALUES (?, 7, ?, ?, ?, ?, ?)');
        foreach ($rows as $r) $st->execute($r);
        return $db;
    }

    private function inst(array $hosts = []): object {
        return (object) ['id' => 7, 'slug' => 'shop', 'ctDomain' => 'shop.tiknix.com', 'ctHosts' => json_encode($hosts)];
    }

    public function testDomainsAreTheHostedNameThenItsOwn(): void {
        $this->assertSame(['shop.tiknix.com', 'shop.example.com'], DomainCerts::domainsOf($this->inst(['shop.example.com', 'shop.tiknix.com'])));
    }

    public function testUncheckedIsNotFailing(): void {
        $s = DomainCerts::summary($this->inst(), $this->core([]));
        $this->assertSame(['count' => 1, 'state' => 'unchecked', 'days_left' => null], ['count' => $s['count'], 'state' => $s['state'], 'days_left' => $s['days_left']]);
    }

    public function testTheWorstDomainSetsTheStateAndTheSoonestExpiryTheNumber(): void {
        $db = $this->core([
            ['shop.tiknix.com', 1, '2026-11-13 22:16:44', 40, '', '2026-10-04 16:00:00'],
            ['shop.example.com', 1, '2026-10-12 00:00:00', 8, '', '2026-10-04 16:00:00'],
        ]);
        $s = DomainCerts::summary($this->inst(['shop.example.com']), $db);
        $this->assertSame('expiring', $s['state'], 'within WARN_DAYS');
        $this->assertSame(8, $s['days_left']);
        $this->assertSame(['ok', 'expiring'], array_column($s['domains'], 'state'));
    }

    public function testAFailingProbeOutranksEverything(): void {
        $db = $this->core([
            ['shop.tiknix.com', 1, '2026-11-13 22:16:44', 40, '', '2026-10-04 16:00:00'],
            ['shop.example.com', 0, '', null, 'the edge served no certificate for this name (TLS handshake failed)', '2026-10-04 16:00:00'],
        ]);
        $s = DomainCerts::summary($this->inst(['shop.example.com']), $db);
        $this->assertSame('failing', $s['state']);
        $this->assertStringContainsString('no certificate', $s['domains'][1]['error']);
        $this->assertSame(40, $s['days_left'], 'the soonest KNOWN expiry; a failing domain has none');
    }
}
