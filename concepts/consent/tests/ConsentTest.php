<?php
/**
 * What a visitor's answer means, the header a browser gets, what a report adds to the list, and where the question goes.
 * The list over an in-memory database built by the plugin's own schema seed.
 */

namespace app\concepts\consent\tests;


use app\Bean;
use app\concepts\consent\{Banner, Choice, Groups, Guard, Inventory, Known, Policy, Scan};
use PHPUnit\Framework\TestCase;

class ConsentTest extends TestCase {
    private ?string $was = null;
    private string $db;

    private const SERVICES = [
        ['host' => 's3.wasabisys.com', 'group' => 'necessary', 'name' => 'File storage', 'purpose' => '', 'from' => 'connection', 'hits' => 0, 'first_seen' => '', 'last_seen' => '', 'page' => '', 'advice' => ''],
        ['host' => 'widgets.example.net', 'group' => 'media', 'name' => 'A widget', 'purpose' => '', 'from' => 'owner', 'hits' => 2, 'first_seen' => '', 'last_seen' => '', 'page' => '/', 'advice' => ''],
        ['host' => 'strange.example.org', 'group' => '', 'name' => 'strange.example.org', 'purpose' => '', 'from' => 'seen', 'hits' => 1, 'first_seen' => '', 'last_seen' => '', 'page' => '/', 'advice' => ''],
    ];

    protected function setUp(): void {
        $this->db = 'consent-test-' . bin2hex(random_bytes(4));
        $this->was = Bean::currentDatabaseKey();
        Bean::addDatabase($this->db, 'sqlite::memory:');
        Bean::selectDatabase($this->db);
        ob_start(); include dirname(__DIR__) . '/seeds/01_ConsentSchema.php'; ob_end_clean();
    }

    protected function tearDown(): void { if ($this->was !== null && Bean::hasDatabase($this->was)) Bean::selectDatabase($this->was); }

    public function testAnAnswerToAnOlderListIsNoAnswer(): void {
        $this->assertSame(['analytics', 'media'], Choice::current('3.0123456789abcdef.analytics-media', 3)['groups']);
        $this->assertNull(Choice::current('2.0123456789abcdef.analytics-media', 3), 'the list changed since');
        $this->assertSame([], Choice::current('3.0123456789abcdef.', 3)['groups'], 'a no is an answer');
        $this->assertNull(Choice::parse('garbage'));
        $this->assertSame(['analytics'], Choice::parse('3.0123456789abcdef.analytics-necessary-madeup')['groups'], 'only groups a visitor is asked about');
    }

    public function testBeforeAnyYesOnlyWhatIsNeededMayLoad(): void {
        [$name, $value] = Policy::header('enforce', [], self::SERVICES, 'app.example.com');
        $this->assertSame('Content-Security-Policy', $name);
        $this->assertStringContainsString('https://s3.wasabisys.com', $value);
        $this->assertStringContainsString('https://challenges.cloudflare.com', $value);
        foreach (['widgets.example.net', 'strange.example.org', 'google-analytics', 'youtube', 'facebook'] as $no) $this->assertStringNotContainsString($no, $value);
        $this->assertStringContainsString('report-uri /consent/report', $value);
        $this->assertStringContainsString(Policy::FRAME_ANCESTORS, $value, 'our header stops the front server sending its own');
    }

    public function testAYesOpensThatGroupOnly(): void {
        [, $value] = Policy::header('enforce', ['media'], self::SERVICES, 'app.example.com');
        $this->assertStringContainsString('https://widgets.example.net', $value);
        $this->assertStringContainsString('https://www.youtube.com', $value, 'a known service of the group, seen or not');
        $this->assertStringNotContainsString('google-analytics', $value);
        $this->assertStringNotContainsString('strange.example.org', $value, 'unsorted stays out whatever they said');
        [, $value] = Policy::header('enforce', ['analytics'], self::SERVICES, 'app.example.com');
        $this->assertStringContainsString('https://www.google-analytics.com', $value);
    }

    public function testWatchingBlocksNothingAndReportsOnlyWhatIsNew(): void {
        [$name, $value] = Policy::header('watch', [], self::SERVICES, 'app.example.com');
        $this->assertSame('Content-Security-Policy-Report-Only', $name);
        $this->assertStringContainsString('https://widgets.example.net', $value);
        $this->assertStringNotContainsString('strange.example.org', $value, 'not yet sorted: it keeps reporting');
        $this->assertStringNotContainsString('frame-ancestors', $value, 'a report-only policy cannot carry it, and the front server still sends its own');
    }

    public function testTheStrictestPolicyNamesNoOutsideService(): void {
        [$name, $value] = Policy::strict('app.example.com');
        $this->assertSame('Content-Security-Policy', $name);
        $this->assertStringNotContainsString('https://', str_replace(['https://tiknix.com', 'https://*.tiknix.com'], '', $value));
    }

    public function testAReportAddsAHostOnce(): void {
        $doc = ['csp-report' => ['blocked-uri' => 'https://Cdn.Example.net/x.js?t=secret', 'document-uri' => 'https://app.example.com/page?token=abc', 'effective-directive' => 'script-src']];
        $r = Policy::fromReport($doc, 'app.example.com');
        $this->assertSame(['host' => 'cdn.example.net', 'page' => '/page', 'directive' => 'script-src'], $r, 'never the query: it can hold a token');
        foreach (['inline', 'eval', 'data', 'https://app.example.com/x.png', 'https://nodot/x'] as $not) {
            $this->assertNull(Policy::fromReport(['csp-report' => ['blocked-uri' => $not]], 'app.example.com'), $not);
        }
        $this->assertNull(Policy::fromReport(['other' => 1], 'app.example.com'));
        $this->assertTrue(Inventory::record('cdn.example.net', '/page', 'script-src', '2030-01-01 10:00:00'));
        $this->assertTrue(Inventory::record('cdn.example.net', '/page', 'script-src', '2030-01-01 10:00:30'));
        $this->assertSame(1, (int) Bean::count('consenthost'));
        $this->assertSame(1, (int) Bean::findOne('consenthost', 'host = ?', ['cdn.example.net'])->hits, 'one write a minute');
        Inventory::record('cdn.example.net', '/page', 'script-src', '2030-01-01 10:02:00');
        $this->assertSame(2, (int) Bean::findOne('consenthost', 'host = ?', ['cdn.example.net'])->hits);
        $this->assertFalse(Inventory::record('not a host', '/', 'img-src'));
    }

    public function testKnownServicesSortThemselves(): void {
        $this->assertSame('analytics', Known::of('region1.google-analytics.com')[0]);
        $this->assertSame('media', Known::of('a.tile.openstreetmap.org')[0]);
        $this->assertSame('necessary', Known::of('vz-123.b-cdn.net')[0]);
        $this->assertNull(Known::of('notgoogle-analytics.com'), 'a look-alike is not the service');
        $this->assertTrue(Known::selfHost('fonts.googleapis.com'));
    }

    public function testTheBannerGivesNoAndYesTheSameWeightAndOnlyNamesWhatIsUsed(): void {
        $html = Banner::html(self::SERVICES, 'My <Site>', []);
        foreach (['data-consent-do="all"', 'data-consent-do="none"', 'data-consent-do="choose"'] as $b) $this->assertStringContainsString($b, $html);
        $this->assertStringNotContainsString('<Site>', $html);
        $this->assertStringContainsString('data-consent-group="media"', $html);
        $this->assertStringNotContainsString('data-consent-group="analytics"', $html, 'the site uses none');
        $this->assertStringNotContainsString(' checked', $html, 'nothing is ticked in advance');
        $this->assertTrue(Inventory::needsAsking(self::SERVICES));
        $this->assertFalse(Inventory::needsAsking([self::SERVICES[0]]), 'only what is needed: no question at all');
    }

    public function testTheQuestionGoesIntoHtmlOnly(): void {
        $this->assertSame('<body>a<i></i></body>', Guard::inject('<body>a</body>', '<i></i>', ['Content-Type: text/html; charset=utf-8']));
        $this->assertSame('{"a":1}', Guard::inject('{"a":1}', '<i></i>', ['Content-Type: application/json']));
        $this->assertSame('no body tag', Guard::inject('no body tag', '<i></i>', []));
        $this->assertSame('<body></body>', Guard::inject('<body></body>', '<i></i>', ['content-type: application/json']));
    }

    public function testAPluginDeclaresWhatWaitsForAYesSoTheQuestionIsAsked(): void {
        $list = array_values(Inventory::declared(['services' => ['www.googletagmanager.com' => ['group' => 'analytics', 'name' => 'Google Analytics', 'purpose' => 'Counts visits.']]]));
        $this->assertSame('analytics', $list[0]['group']);
        $this->assertTrue(Inventory::needsAsking($list), 'it has never loaded, and the banner still shows');
        $this->assertSame([], Inventory::declared([]));
        $this->expectExceptionMessage("names the group 'nope'");
        Inventory::declared(['services' => ['a.example.com' => ['group' => 'nope']]]);
    }

    public function testTheScanVisitsPagesPeopleOpenNotDoors(): void {
        $p = Scan::pages([['control' => 'index', 'method' => 'pricing', 'level' => 101], ['control' => 'shop', 'method' => 'index', 'level' => 100],
            ['control' => 'shop', 'method' => '*', 'level' => 100], ['control' => 'admin', 'method' => 'users', 'level' => 50], ['control' => 'mcp', 'method' => 'message', 'level' => 101],
            ['control' => 'consent', 'method' => 'cookies', 'level' => 101], ['control' => 'bad name', 'method' => 'x', 'level' => 101]]);
        $this->assertSame(['/index/pricing'], $p, 'the route list is for what a stranger may already ask for: signed in, the scan only follows links');
    }

    public function testTheScanSaysWhatRanBeforeAnAnswerAndWhatDidNot(): void {
        $walk = ['visitor' => [
            'pages' => [['path' => '/', 'status' => 200], ['path' => '/boom', 'status' => 500], ['path' => '/gone', 'status' => 404], ['path' => '/slow', 'status' => 'slow']],
            'hosts' => ['s3.wasabisys.com' => ['types' => ['image'], 'pages' => ['/']], 'www.google-analytics.com' => ['pages' => ['/']], 'strange.example.org' => ['pages' => ['/x']], 'js.stripe.com' => ['pages' => ['/pay']]],
            'cookies' => ['APP_SESSION' => ['domain' => 'app.example.com', 'page' => '/'], 'tiknix_consent' => ['domain' => 'app.example.com', 'page' => '/'], 'tracker' => ['domain' => 'app.example.com', 'page' => '/x']],
            'storage' => ['local:theme' => '/'], 'stopped' => 2],
            'member' => ['pages' => [['path' => '/me', 'status' => 200]], 'hosts' => ['www.google-analytics.com' => ['pages' => ['/me']]], 'cookies' => [], 'storage' => []]];
        $j = Scan::judge($walk, self::SERVICES, 'APP_SESSION');
        $this->assertSame(5, $j['pages']);
        $this->assertSame(['www.google-analytics.com', 'strange.example.org'], array_column($j['waiting'], 'host'), 'needed services are not findings, and a service counts once');
        $this->assertSame('analytics', $j['waiting'][0]['group']);
        $this->assertSame('', $j['waiting'][1]['group']);
        $this->assertSame(['tracker'], array_column($j['cookies'], 'name'), 'the two cookies the site needs are not findings');
        $this->assertSame(['/boom'], array_column($j['broken'], 'path'), 'a missing page is normal; a server error is not');
        $this->assertSame('theme', $j['storage'][0]['key']);
        $this->assertSame(2, $j['stopped']);
    }

    public function testAResultIsStoredTheListGrowsAndOnlyAFewAreKept(): void {
        $ok = ['ok' => true, 'visitor' => ['pages' => [['path' => '/', 'status' => 200]], 'hosts' => ['new.example.net' => ['types' => ['script'], 'pages' => ['/shop']]], 'cookies' => [], 'storage' => []]];
        $a = Bean::dispense('member'); $a->email = Scan::SCAN_EMAIL; $a->status = 'active'; Bean::store($a);
        for ($i = 0; $i < 7; $i++) Scan::receive($ok, []);
        $this->assertSame('disabled', (string) Bean::findOne('member', 'email = ?', [Scan::SCAN_EMAIL])->status, 'off between scans');
        $this->assertSame(5, (int) Bean::count('consentscan'));
        $this->assertSame('/shop', (string) Bean::findOne('consenthost', 'host = ?', ['new.example.net'])->page);
        $s = Scan::latest([], 'APP_SESSION');
        $this->assertSame('done', $s['state']);
        $this->assertSame('new.example.net', $s['waiting'][0]['host']);
        Scan::receive(['ok' => false, 'error' => 'the QA browser host is not set up'], []);
        $s = Scan::latest([], 'APP_SESSION');
        $this->assertSame('failed', $s['state']);
        $this->assertSame('the QA browser host is not set up', $s['error']);
        $r = Bean::dispense('consentscan'); $r->status = 'running'; $r->startedAt = date('Y-m-d H:i:s', time() - 3600); $r->finishedAt = ''; $r->error = ''; $r->result = ''; Bean::store($r);
        $this->assertSame('stale', Scan::latest([], 'APP_SESSION')['state'], 'a scan that never came back is said to have failed');
    }

    public function testANewKindOfServiceAsksEveryoneAgain(): void {
        $this->assertTrue(\app\concepts\consent\Settings::grew([], ['analytics']));
        $this->assertTrue(\app\concepts\consent\Settings::grew(['media'], ['analytics', 'media']));
        $this->assertFalse(\app\concepts\consent\Settings::grew(['analytics', 'media'], ['media']), 'dropping one needs no new answer');
    }

    public function testRefusingAGroupClearsWhatItLeft(): void {
        $cookies = ['_ga' => '1', '_ga_ABC' => '1', 'APP_SESSION' => 'x', '_fbp' => '1', 'tiknix_consent' => 'y'];
        $this->assertSame(['_ga', '_ga_ABC', '_fbp'], Choice::leftovers([], $cookies));
        $this->assertSame(['_fbp'], Choice::leftovers(['analytics'], $cookies));
        $this->assertSame(['analytics', 'media', 'marketing', 'preferences'], array_values(array_intersect(['analytics', 'media', 'marketing', 'preferences'], Groups::asked())));
    }
}
