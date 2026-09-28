<?php
/**
 * Mail rides on a connection (CONNECTOR-CATALOG-PLAN.md decision 9): Mailer::settings() is the
 * install's bound `mail` connection, or an exception that names the fix. Nothing is read from
 * conf/mailgun.ini any more.
 *
 *   none        MissingConnectorException naming Connections → Mailgun
 *   one         binds itself (install-wide) and answers key, domain, endpoint, from, inbound
 *   from        a site's own [mail] from_email wins; config.ini's [mail] block never does
 *   broken      a connection with no sending domain is an error naming the connection
 *   seed        23_MailConnection turns an ini into that connection once, then keeps it
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ConnectionBindings;
use app\ConnectionStore;
use app\Mailer;
use app\MissingConnectorException;
use app\Sites;

class MailSettingsTest extends ConceptsTestCase {

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        Bean::selectDatabase('default');
        \RedBeanPHP\R::nuke();
        \Flight::set('app.baseurl', 'https://serenity.example.com');
        \Flight::set('app.name', 'Serenity');
        \Flight::set('mail.from_email', 'noreply@example.com');   // config.ini's [mail] example block
        \Flight::set('site.config_applied', []);
        // Mailer logs and names the site; unit tests boot no FlightMap and no logger.
        $this->savedLog = \Flight::get('log');
        $quiet = new \Monolog\Logger('test');
        $quiet->pushHandler(new \Monolog\Handler\NullHandler());
        \Flight::set('log', $quiet);
        try { \Flight::siteName(); } catch (\Throwable $e) { \Flight::map('siteName', fn() => (string) \Flight::get('app.name')); }
        $_SESSION = [];
        Sites::reset();
        Sites::create('main', 'Serenity');
        ConnectionStore::useInstall($this->root);
    }

    private $savedLog = null;

    protected function tearDown(): void {
        ConnectionStore::useOwnInstall();
        \Flight::set('site.config_applied', null);
        \Flight::set('log', $this->savedLog);
        Sites::reset(); $_SESSION = [];
        parent::tearDown();
    }

    private function mailgun(string $domain, array $fields = [], string $key = 'key-secret'): int {
        return ConnectionStore::put('mailgun', 'production', [
            'access_token' => $key, 'auth_type' => 'api_key', 'external_eid' => $domain, 'external_name' => $domain,
            'metadata' => ['base_url' => 'https://api.eu.mailgun.net', 'auth' => 'basic', 'username' => 'api', 'fields' => ['domain' => $domain] + $fields],
            'webhook_secret' => 'whsec-1',
        ]);
    }

    public function testNoConnectionNamesTheFix(): void {
        try { Mailer::settings(); $this->fail('no mail connection'); }
        catch (MissingConnectorException $e) { $this->assertStringContainsString('Connections → Mailgun', $e->getMessage()); }
        $this->assertFalse((new Mailer())->send('hello'), 'send refuses, and does not throw');
    }

    public function testOneConnectionBindsItselfAndAnswersEverything(): void {
        $id = $this->mailgun('mail.serenity.example.com', ['inbound_domain' => 'in.serenity.example.com']);
        $s = Mailer::settings();
        $this->assertSame($id, (int) $s['connection']->id);
        $this->assertSame('key-secret', $s['key']);
        $this->assertSame('mail.serenity.example.com', $s['domain']);
        $this->assertSame('https://api.eu.mailgun.net', $s['endpoint'], 'a non-US base URL is the SDK endpoint');
        $this->assertSame('noreply@mail.serenity.example.com', $s['from_email'], 'not config.ini\'s noreply@example.com');
        $this->assertSame('in.serenity.example.com', $s['inbound_domain']);
        $this->assertSame('whsec-1', $s['signing_key']);
        $this->assertSame('Serenity', $s['from_name']);
        $b = ConnectionBindings::bindings(ConnectionBindings::CORE);
        $this->assertCount(1, $b);
        $this->assertSame(['mail', 0, $id, 'auto'], [$b[0]['role'], (int) $b[0]['site_ref'], (int) $b[0]['connection_ref'], $b[0]['bound_by']], 'mail inherits: bound install-wide, by itself');
        $this->assertTrue(Mailer::isConfigured());
    }

    public function testASitesOwnFromAddressWins(): void {
        $this->mailgun('mail.serenity.example.com', ['from_email' => 'hello@serenity.example.com']);
        $this->assertSame('hello@serenity.example.com', Mailer::settings()['from_email'], 'the connection\'s from field');
        \Flight::set('mail.from_email', 'denver@serenity.example.com');
        \Flight::set('site.config_applied', ['app.name', 'mail.from_email']);   // what Sites::applyConfig records
        $this->assertSame('denver@serenity.example.com', Mailer::settings()['from_email']);
    }

    public function testAConnectionWithoutADomainIsAnErrorNamingIt(): void {
        $id = ConnectionStore::put('mailgun', 'production', ['access_token' => 'k', 'auth_type' => 'api_key', 'external_eid' => 'x', 'metadata' => ['base_url' => 'https://api.mailgun.net']]);
        try { Mailer::settings(); $this->fail('no domain'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString("#{$id}", $e->getMessage()); $this->assertStringContainsString('no sending domain', $e->getMessage()); }
    }

    public function testTheSeedMigratesAnIniOnceAndThenKeeps(): void {
        $seed = dirname(__DIR__, 2) . '/services/Schema/Seeds/23_MailConnection.php';
        // The seed reads <root>/conf/mailgun.ini of the CODE's install; run it against a copy
        // that points at this test's root by rewriting its root line.
        $code = str_replace('$root = dirname(__DIR__, 3);', '$root = ' . var_export($this->root, true) . ';', (string) file_get_contents($seed));
        $this->assertNotSame((string) file_get_contents($seed), $code, 'the root line is what the seed anchors on');
        $file = "{$this->root}/_seed23.php";
        file_put_contents($file, $code);

        ob_start(); include $file; $out = ob_get_clean();
        $this->assertStringContainsString('nothing to migrate', $out);

        mkdir("{$this->root}/conf", 0700, true);
        file_put_contents("{$this->root}/conf/mailgun.ini", "key=\"key-from-ini\"\ndomain=\"notify.example.com\"\nfromEmail=\"noreply@notify.example.com\"\nsigningKey=\"sign-1\"\nendpoint=\"https://api.eu.mailgun.net\"\n");
        ob_start(); include $file; $out = ob_get_clean();
        $this->assertStringContainsString('→ mailgun connection #', $out);
        $s = Mailer::settings();
        $this->assertSame(['key-from-ini', 'notify.example.com', 'https://api.eu.mailgun.net', 'noreply@notify.example.com', 'sign-1'],
            [$s['key'], $s['domain'], $s['endpoint'], $s['from_email'], $s['signing_key']]);

        ob_start(); include $file; $out = ob_get_clean();
        $this->assertStringContainsString('kept', $out);
        $this->assertCount(1, ConnectionStore::candidates(['mailgun']));
    }
}
