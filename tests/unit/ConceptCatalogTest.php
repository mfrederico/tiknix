<?php
/**
 * The catalog end to end on disk: lint gate → publish → search → bundle → install, and the
 * two ways it must refuse to lie — a hostile bundle, and an outage dressed up as "no results".
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\ConceptCatalog;
use app\ConceptException;
use app\ConceptLint;

class ConceptCatalogTest extends ConceptsTestCase {

    private string $catalogDir;

    protected function setUp(): void {
        parent::setUp();
        $this->catalogDir = $this->root . '/_catalog';
        mkdir($this->catalogDir, 0700, true);
    }

    private function local(): ConceptCatalog {
        return new ConceptCatalog($this->catalogDir);
    }

    /** A clean, publishable concept. */
    private function calendar(string $name = 'calendar', array $manifest = [], array $files = []): string {
        return $this->concept($name, $manifest + [
            'title' => 'Calendar feeds', 'blurb' => 'ICS feeds and add-to-calendar links for anything with a date.',
            'tags' => ['calendar', 'ics', 'events'], 'provides' => ['capabilities' => ['add-to-calendar', 'ics']],
        ], $files + [
            'lib/Calendar.php' => "<?php\nnamespace app\\concepts\\{$name};\nclass Calendar { public static function ics(array \$e): string { return 'BEGIN:VCALENDAR'; } }\n",
            'tests/CalendarTest.php' => "<?php\n",
            'guidelines.md' => "### {$name}\n\nCall Calendar::ics() with a neutral event array.\n",
        ]);
    }

    private function errors(string $dir, array $forbid = []): string {
        $msgs = [];
        foreach (ConceptLint::check($dir, $forbid) as $f) {
            if ($f['severity'] === ConceptLint::ERROR) $msgs[] = "{$f['file']}:{$f['line']} {$f['message']}";
        }
        return implode("\n", $msgs);
    }

    /* ---- lint: the scrub gate ---- */

    public function testCleanConceptHasNoErrors(): void {
        $this->assertSame('', $this->errors($this->calendar()));
    }

    public function testOriginNameIsCaughtEvenInAComment(): void {
        $dir = $this->calendar('calendar', [], ['lib/Feed.php' =>
            "<?php\nnamespace app\\concepts\\calendar;\n// built for Serenity Gemstones\nclass Feed { const PRODID = '-//Serenity Gemstones and More//EN'; }\n"]);
        $errors = $this->errors($dir, ['Serenity Gemstones', 'serenity-bbdc01']);
        $this->assertStringContainsString('lib/Feed.php:3', $errors);
        $this->assertStringContainsString('lib/Feed.php:4', $errors);
        $this->assertStringContainsString('names the origin install', $errors);
    }

    public function testSecretsAndAbsolutePathsAreCaught(): void {
        // Assembled at runtime so this test file itself never contains a secret-shaped literal.
        $stripe = 'sk_' . 'live_' . str_repeat('a1B2', 5);
        $dir = $this->calendar('calendar', [], ['lib/Cfg.php' =>
            "<?php\nnamespace app\\concepts\\calendar;\nclass Cfg { const K = '{$stripe}'; const P = '/var/www/html/default/x'; }\n"]);
        $errors = $this->errors($dir);
        $this->assertStringContainsString('Stripe key', $errors);
        $this->assertStringContainsString('absolute server path', $errors);
    }

    public function testUndeclaredCoreClassIsCaughtButACommentIsNot(): void {
        $dir = $this->calendar('calendar', [], ['lib/Send.php' =>
            "<?php\nnamespace app\\concepts\\calendar;\n/** talks to \\app\\Turnstile in the docs only */\nclass Send { public static function go() { \\app\\Mailer::send([]); return \\app\\Bean::count('x'); } }\n"]);
        $errors = $this->errors($dir);
        $this->assertStringContainsString('uses app\\Mailer', $errors);
        $this->assertStringNotContainsString('Turnstile', $errors, 'a docblock mention is not a dependency');
        $this->assertStringNotContainsString('uses app\\Bean', $errors, 'Bean is always available');
    }

    public function testDeclaredCoreClassPasses(): void {
        $dir = $this->calendar('calendar', ['requires' => ['lib' => ['Mailer']]], ['lib/Send.php' =>
            "<?php\nnamespace app\\concepts\\calendar;\nclass Send { public static function go() { \\app\\Mailer::send([]); } }\n"]);
        $this->assertStringNotContainsString('Mailer', $this->errors($dir));
    }

    public function testBeansMustBeOwnedOrDeclared(): void {
        $src = "<?php\nnamespace app\\concepts\\calendar;\nuse app\\Bean;\nclass Repo { public static function all() { Bean::find('product'); Bean::dispense('calendar_feed'); } }\n";
        $dir = $this->calendar('calendar', [], ['lib/Repo.php' => $src]);
        $errors = $this->errors($dir);
        $this->assertStringContainsString("touches bean 'product'", $errors);
        $this->assertStringContainsString("touches bean 'calendarfeed'", $errors, 'bean names are normalised like Bean:: does');

        $dir = $this->calendar('calendar', ['provides' => ['beans' => ['calendarfeed']], 'uses' => ['beans' => ['product']]], ['lib/Repo.php' => $src]);
        $this->assertStringNotContainsString('touches bean', $this->errors($dir));
    }

    public function testWrongNamespaceAndRawRedBeanAreCaught(): void {
        // The fixture is assembled so this file holds no raw RedBean call of its own.
        $raw = 'R' . '::count';
        $dir = $this->calendar('calendar', [], ['lib/Bad.php' =>
            "<?php\nnamespace app;\nclass Bad { public static function go() { return {$raw}('x'); } }\n"]);
        $errors = $this->errors($dir);
        $this->assertStringContainsString('must declare namespace app\\concepts\\calendar', $errors);
        $this->assertStringContainsString('directly', $errors);
    }

    public function testReachingIntoAnUndeclaredConceptIsCaught(): void {
        $dir = $this->calendar('calendar', [], ['lib/Peek.php' =>
            "<?php\nnamespace app\\concepts\\calendar;\nclass Peek { public static function go() { return \\app\\concepts\\tickets\\Ticket::all(); } }\n"]);
        $this->assertStringContainsString("reaches into concept 'tickets'", $this->errors($dir));
    }

    public function testLintReportsASymlinkWithoutReadingThroughIt(): void {
        $dir = $this->calendar();
        $this->put("{$this->root}/outside.php", "<?php\n// Serenity Gemstones\n");
        symlink("{$this->root}/outside.php", "{$dir}/lib/Link.php");
        $errors = $this->errors($dir, ['Serenity Gemstones']);
        $this->assertStringContainsString('lib/Link.php:0 is a symlink', $errors);
        $this->assertStringNotContainsString('names the origin install', $errors, 'the target was never opened');
    }

    /* ---- publish → search → install ---- */

    public function testPublishRefusesLintErrors(): void {
        $dir = $this->calendar('calendar', [], ['lib/Feed.php' => "<?php\nnamespace app\\concepts\\calendar;\nclass Feed { const N = 'Serenity Gemstones'; }\n"]);
        try {
            $this->local()->publish($dir, ['Serenity Gemstones']);
            $this->fail('publish() should have refused');
        } catch (ConceptException $e) {
            $this->assertStringContainsString('NOT published', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist("{$this->catalogDir}/calendar");
    }

    public function testPublishSearchGetInstall(): void {
        $r = $this->local()->publish($this->calendar());
        $this->assertSame('published', $r['status']);

        $found = $this->local()->search('add to calendar');
        $this->assertSame('calendar', $found['results'][0]['name']);

        $got = $this->local()->get('calendar');
        $this->assertContains('lib/Calendar.php', array_column($got['files'], 'path'));

        $other = $this->root . '/_otherinstall';
        mkdir($other);
        $in = $this->local()->install('calendar', $other);
        $this->assertFileExists("{$other}/concepts/calendar/lib/Calendar.php");
        $this->assertSame('1.0.0', $in['version']);
        $prov = json_decode(file_get_contents("{$other}/concepts/calendar/.installed.json"), true);
        $this->assertSame('calendar', $prov['name']);
    }

    public function testInstallNeverOverwrites(): void {
        $this->local()->publish($this->calendar());
        $other = $this->root . '/_otherinstall';
        mkdir($other);
        $this->local()->install('calendar', $other);
        file_put_contents("{$other}/concepts/calendar/lib/Calendar.php", '<?php // adapted for this client');
        try {
            $this->local()->install('calendar', $other);
            $this->fail('install() should have refused');
        } catch (ConceptException $e) {
            $this->assertStringContainsString('never overwritten', $e->getMessage());
        }
        $this->assertStringContainsString('adapted for this client', file_get_contents("{$other}/concepts/calendar/lib/Calendar.php"));
    }

    /**
     * Found by running the real executor: .installed.json recorded the local catalog's
     * absolute path, so every installed concept failed the lint it is checked with — and the
     * file is committed into the adopting project's repository.
     */
    public function testAnInstalledConceptLintsCleanAndRecordsNoServerPath(): void {
        $this->local()->publish($this->calendar());
        $other = $this->root . '/_otherinstall';
        mkdir($other);
        $this->local()->install('calendar', $other);

        $this->assertSame('', $this->errors("{$other}/concepts/calendar"));
        $provenance = file_get_contents("{$other}/concepts/calendar/.installed.json");
        $this->assertStringNotContainsString($this->catalogDir, $provenance);
        $this->assertStringNotContainsString('/tmp/', $provenance);
        $this->assertSame('control-plane catalog', json_decode($provenance, true)['source']);
    }

    public function testProvenanceIsNotPublishedBack(): void {
        $this->local()->publish($this->calendar());
        $other = $this->root . '/_otherinstall';
        mkdir($other);
        $this->local()->install('calendar', $other);
        $this->assertNotContains('.installed.json', ConceptCatalog::collect("{$other}/concepts/calendar"));
    }

    public function testAPublishedVersionCannotChange(): void {
        $dir = $this->calendar();
        $this->local()->publish($dir);
        $this->assertSame('unchanged', $this->local()->publish($dir)['status']);

        file_put_contents("{$dir}/lib/Calendar.php", "<?php\nnamespace app\\concepts\\calendar;\nclass Calendar { const CHANGED = 1; }\n");
        try {
            $this->local()->publish($dir);
            $this->fail('publish() should have refused a changed 1.0.0');
        } catch (ConceptException $e) {
            $this->assertStringContainsString('Bump "version"', $e->getMessage());
        }

        $manifest = json_decode(file_get_contents("{$dir}/concept.json"), true);
        $manifest['version'] = '1.1.0';
        file_put_contents("{$dir}/concept.json", json_encode($manifest));
        $this->assertSame('updated', $this->local()->publish($dir)['status']);
        $this->assertSame('1.1.0', $this->local()->get('calendar')['version']);
    }

    /** @dataProvider strayFiles */
    public function testAStrayFileRefusesToPublishAndIsNamed(string $rel): void {
        $dir = $this->calendar('calendar', [], [$rel => 'x']);
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage("'{$rel}' is not something a concept may contain");
        $this->local()->publish($dir);
    }

    public function strayFiles(): array {
        return [['deploy.sh'], ['lib/tool.exe'], ['secrets/key.php'], ['.env'], ['lib/.hidden.php']];
    }

    public function testASymlinkRefusesToPublish(): void {
        $dir = $this->calendar();
        symlink('/etc/hostname', "{$dir}/lib/Host.php");
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('is a symlink');
        $this->local()->publish($dir);
    }

    public function testSearchRanksAndReportsBrokenEntries(): void {
        $this->local()->publish($this->calendar());
        $this->local()->publish($this->concept('tickets', [
            'title' => 'QR tickets', 'blurb' => 'Issue QR tickets on payment and check people in at the door.',
            'tags' => ['tickets', 'qr', 'check-in'],
        ], ['tests/T.php' => '<?php', 'guidelines.md' => "### tickets\n\nIssue on paid, void on cancel.\n"]));
        $this->put("{$this->catalogDir}/junk/concept.json", '{nope');

        $found = $this->local()->search('qr ticket check-in at the door');
        $this->assertSame('tickets', $found['results'][0]['name']);
        $this->assertArrayHasKey('junk', $found['broken']);

        $this->assertSame([], $this->local()->search('blockchain')['results']);
        $this->assertCount(2, $this->local()->search('')['results'], 'an empty query lists everything');
    }

    /* ---- remote: an instance asking the control plane ---- */

    /** A transport that answers from the local catalog, as controls/Concepthub.php does. */
    private function remoteBackedByLocal(?callable $tamper = null): ConceptCatalog {
        $local = $this->local();
        return new ConceptCatalog(null, ['base' => 'https://core.test', 'key' => 'brk_test'],
            function (string $url, array $headers) use ($local, $tamper) {
                $this->assertContains('Authorization: Bearer brk_test', $headers);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $action = basename((string) parse_url($url, PHP_URL_PATH));
                $data = match ($action) {
                    'search' => $local->search($q['q'] ?? '', (int) ($q['limit'] ?? 10)),
                    'get'    => $local->get($q['name']),
                    'bundle' => $local->bundle($q['name']),
                };
                if ($tamper) $data = $tamper($data);
                return [200, json_encode($data)];
            });
    }

    public function testAnInstanceSearchesAndInstallsOverHttp(): void {
        $this->local()->publish($this->calendar());
        $remote = $this->remoteBackedByLocal();
        $this->assertFalse($remote->isLocal());
        $this->assertSame('calendar', $remote->search('ics feed')['results'][0]['name']);

        $other = $this->root . '/_instance';
        mkdir($other);
        $remote->install('calendar', $other);
        $this->assertFileExists("{$other}/concepts/calendar/concept.json");
    }

    /** @dataProvider hostilePaths */
    public function testAHostileBundleWritesNothing(string $evilPath): void {
        $this->local()->publish($this->calendar());
        $remote = $this->remoteBackedByLocal(function (array $data) use ($evilPath) {
            // The content is irrelevant — what is under test is WHERE a bundle may write.
            if (isset($data['files'])) $data['files'][$evilPath] = base64_encode('<?php // planted by a hostile catalog');
            return $data;
        });
        $other = $this->root . '/_instance';
        mkdir($other);
        try {
            $remote->install('calendar', $other);
            $this->fail('install() should have refused the bundle');
        } catch (ConceptException $e) {
            $this->assertStringContainsString('not something a concept may contain', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist("{$other}/concepts/calendar");
        $this->assertSame([], glob("{$other}/concepts/.calendar.installing-*") ?: [], 'no half-written install left behind');
        $this->assertFileDoesNotExist("{$this->root}/evil.php");
    }

    public function hostilePaths(): array {
        return [['../evil.php'], ['../../evil.php'], ['/etc/cron.d/evil'], ['lib/../../evil.php'], ['lib/shell.phtml'], ['.htaccess']];
    }

    public function testAnInstanceAsksThePlatformToInstallWithAPost(): void {
        $seen = [];
        $remote = new ConceptCatalog(null, ['base' => 'https://core.test', 'key' => 'brk_test'],
            function (string $url, array $headers, bool $post = false) use (&$seen) {
                $seen = ['url' => $url, 'post' => $post, 'headers' => $headers];
                return [200, json_encode(['queued' => true, 'message' => "Installing 'calendar' — a build with no agent."])];
            });
        $r = $remote->requestInstall('calendar');
        $this->assertTrue($r['queued']);
        $this->assertStringContainsString('build', $r['message']);
        $this->assertTrue($seen['post'], 'an install is a state change: POST, never GET');
        $this->assertStringStartsWith('https://core.test/concepthub/install?name=calendar', $seen['url']);
        $this->assertContains('Authorization: Bearer brk_test', $seen['headers']);
    }

    public function testANotQueuedInstallIsReportedNotThrown(): void {
        // The platform reached and answered "no" (a project with no owner, a runner that
        // exited non-zero): that is an answer for the person, distinct from an outage.
        $remote = new ConceptCatalog(null, ['base' => 'https://core.test', 'key' => 'brk_test'],
            fn() => [200, json_encode(['queued' => false, 'message' => "Could not queue 'calendar': the runner exited 1"])]);
        $r = $remote->requestInstall('calendar');
        $this->assertFalse($r['queued']);
        $this->assertStringContainsString('exited 1', $r['message']);
    }

    public function testAnInstallAnswerWithoutAVerdictIsAFailure(): void {
        $remote = new ConceptCatalog(null, ['base' => 'https://core.test', 'key' => 'brk_test'],
            fn() => [200, json_encode(['ok' => true])]);
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('without a queued flag');
        $remote->requestInstall('calendar');
    }

    public function testTheCatalogServerItselfCannotRequestAnInstall(): void {
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('queueInstall');
        $this->local()->requestInstall('calendar');
    }

    public function testAnOutageIsAFailureNotAnEmptyResult(): void {
        $down = new ConceptCatalog(null, ['base' => 'https://core.test', 'key' => 'brk_test'],
            fn() => [0, json_encode(['message' => 'connection failed: timed out'])]);
        try {
            $down->search('calendar');
            $this->fail('search() should have thrown');
        } catch (ConceptException $e) {
            $this->assertStringContainsString('NOT an empty result', $e->getMessage());
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function testARefusedKeyIsAFailure(): void {
        $refused = new ConceptCatalog(null, ['base' => 'https://core.test', 'key' => 'brk_bad'],
            fn() => [403, json_encode(['success' => false, 'message' => 'Forbidden.'])]);
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('Forbidden.');
        $refused->search('calendar');
    }

    public function testAnInstanceCannotPublish(): void {
        $this->expectException(ConceptException::class);
        $this->expectExceptionMessage('control plane');
        $this->remoteBackedByLocal()->publish($this->calendar());
    }

    public function testNoCatalogConfiguredNamesBothSettings(): void {
        try {
            new ConceptCatalog(null, null);
            $this->fail('should have thrown');
        } catch (ConceptException $e) {
            $this->assertStringContainsString('catalog_dir', $e->getMessage());
            $this->assertStringContainsString('broker.ini', $e->getMessage());
        }
    }
}
