<?php
/**
 * ConnectionBindings::for() — a concept asks by role; the install answers per site
 * (CONNECTOR-CATALOG-PLAN.md §4). The order, and nothing else:
 *
 *   bound        the current site's binding wins, and a binding to a dead connection is an
 *                error naming it — never a fall-through to another connection
 *   inherit      a site with no binding of its own takes the install-wide one only when the
 *                role inherits: mail does, payments never
 *   one          exactly one live candidate on the install binds itself (and is recorded)
 *   two          two candidates with no binding: UnboundRoleException naming both and the page
 *   none         MissingConnectorException naming what to connect, or what to install
 *   entity       an entity binding (a campaign's mailbox) sits above the site's
 *   unbound()    the Plugins page's list; bind() refuses a wrong type
 *
 * Scratch in-memory app database (sites, bindings) + a throwaway connection store.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ConceptManifest;
use app\ConnectionBindings;
use app\ConnectionStore;
use app\MissingConnectorException;
use app\Sites;
use app\UnboundRoleException;

class ConnectionsResolveTest extends ConceptsTestCase {

    private string $concept;

    protected function setUp(): void {
        parent::setUp();
        // The app database must be RedBean's 'default': the connection store restores
        // 'default' after every call, exactly as it does on a live install.
        self::memoryDb();
        Bean::selectDatabase('default');
        \RedBeanPHP\R::nuke();
        \Flight::set('app.baseurl', 'https://serenity.example.com');
        $_SESSION = [];
        Sites::reset();
        Sites::create('main', 'Serenity');
        ConnectionStore::useInstall($this->root);
        $this->concept = $this->uniq('shop');
        $dir = $this->concept($this->concept, ['requires' => ['connectors' => [
            ['role' => 'payments', 'types' => ['stripe']],
            ['role' => 'mail', 'types' => ['microsoft', 'mailgun'], 'optional' => true],
            ['role' => 'sender', 'types' => ['microsoft'], 'scope' => 'entity', 'entity' => 'campaign'],
            ['role' => 'search', 'types' => ['nosuchconnector']],
        ]]]);
        $m = ConceptManifest::load($dir, $this->concept);
        ConnectionBindings::useManifests(fn(string $c) => $m);
    }

    protected function tearDown(): void {
        ConnectionBindings::useManifests(null);
        ConnectionStore::useOwnInstall();
        Sites::reset(); $_SESSION = [];
        Bean::selectDatabase('default');
        parent::tearDown();
    }

    private function stripe(string $eid, string $name): int {
        return ConnectionStore::put('stripe', 'production', ['external_eid' => $eid, 'external_name' => $name, 'access_token' => 'sk_' . $eid, 'auth_type' => 'api_key']);
    }

    public function testNoneOneTwo(): void {
        try { ConnectionBindings::for($this->concept, 'payments'); $this->fail(); }
        catch (MissingConnectorException $e) { $this->assertStringContainsString('connect one under Connections → Stripe', $e->getMessage()); }
        try { ConnectionBindings::for($this->concept, 'search'); $this->fail(); }
        catch (MissingConnectorException $e) { $this->assertStringContainsString('--connector-install=nosuchconnector', $e->getMessage()); }

        $a = $this->stripe('acct_a', 'Serenity main');
        $this->assertSame($a, (int) ConnectionBindings::for($this->concept, 'payments')->id, 'one candidate: bound automatically');
        $b = ConnectionBindings::bindings($this->concept);
        $this->assertSame(['payments', 'auto', 'Serenity main', $a], [$b[0]['role'], $b[0]['bound_by'], $b[0]['alias_snapshot'], (int) $b[0]['connection_ref']]);
        $this->assertSame('sk_acct_a', ConnectionBindings::token($this->concept, 'payments'));

        $d = $this->stripe('acct_d', 'Serenity Denver');
        $this->assertSame($a, (int) ConnectionBindings::for($this->concept, 'payments')->id, 'already bound: a second candidate changes nothing here');
        ConnectionBindings::unbind($this->concept, 'payments');
        try { ConnectionBindings::for($this->concept, 'payments'); $this->fail(); }
        catch (UnboundRoleException $e) {
            $this->assertStringContainsString('2 candidates', $e->getMessage());
            $this->assertStringContainsString('Serenity main (stripe), Serenity Denver (stripe)', $e->getMessage());
            $this->assertStringContainsString('Plugins →', $e->getMessage());
        }
        ConnectionBindings::bind($this->concept, 'payments', $d, 'member:1');
        $this->assertSame($d, (int) ConnectionBindings::for($this->concept, 'payments')->id);
        $this->assertSame([$this->concept], array_column(ConnectionBindings::usedBy($d), 'concept'));
    }

    public function testSitesAndInheritance(): void {
        $main = $this->stripe('acct_main', 'Serenity main');
        $denver = Sites::create('denver', 'Denver');
        ConnectionBindings::bind($this->concept, 'payments', $main, 'member:1', 0);     // install-wide
        Sites::switchTo((int) $denver->id); Sites::reset();
        try { ConnectionBindings::for($this->concept, 'payments'); $this->fail('Denver took the install-wide Stripe'); }
        catch (UnboundRoleException $e) { $this->assertStringContainsString("for site 'denver'", $e->getMessage()); }
        $dstripe = $this->stripe('acct_den', 'Serenity Denver');
        ConnectionBindings::bind($this->concept, 'payments', $dstripe, 'member:1', (int) $denver->id);
        $this->assertSame($dstripe, (int) ConnectionBindings::for($this->concept, 'payments')->id, "Denver's own Stripe");

        $mail = ConnectionStore::put('mailgun', 'production', ['external_eid' => 'mg.example.com', 'external_name' => 'Mailgun', 'access_token' => 'key-x', 'auth_type' => 'api_key']);
        ConnectionBindings::bind($this->concept, 'mail', $mail, 'member:1', 0);
        $this->assertSame($mail, (int) ConnectionBindings::for($this->concept, 'mail')->id, 'mail inherits the install-wide binding in Denver');
    }

    public function testADeadBindingIsNamedNotSkipped(): void {
        $a = $this->stripe('acct_a', 'Serenity main');
        $b = $this->stripe('acct_b', 'Serenity backup');
        ConnectionBindings::bind($this->concept, 'payments', $a, 'member:1');
        ConnectionStore::withOwnDb(function () use ($a) { $c = Bean::load('connections', $a); $c->enabled = 0; Bean::store($c); return true; }, null);
        try { ConnectionBindings::for($this->concept, 'payments'); $this->fail('fell through to the backup'); }
        catch (UnboundRoleException $e) { $this->assertStringContainsString("bound to 'Serenity main' (#{$a}), which is no longer usable", $e->getMessage()); }
        $u = ConnectionBindings::unbound($this->concept);
        $this->assertSame(['payments', 'error'], [$u[0]['role'], $u[0]['level']]);
        $this->assertSame(['mail', 'notice'], [$u[1]['role'], $u[1]['level']], 'optional role: a notice');
        $this->assertSame('search', $u[2]['role']);
        $this->assertCount(3, $u, 'entity roles are judged on their entity, not here');
    }

    public function testEntityBindingsAndTypeRefusals(): void {
        $ms1 = ConnectionStore::put('microsoft', 'production', ['external_eid' => 'sales@x', 'external_name' => 'sales@', 'access_token' => 't1', 'auth_type' => 'oauth']);
        $ms2 = ConnectionStore::put('microsoft', 'production', ['external_eid' => 'ops@x', 'external_name' => 'ops@', 'access_token' => 't2', 'auth_type' => 'oauth']);
        $campaign = ['type' => 'campaign', 'id' => 7];
        try { ConnectionBindings::for($this->concept, 'sender', $campaign); $this->fail(); }
        catch (UnboundRoleException $e) { $this->assertStringContainsString('2 candidates', $e->getMessage()); }
        ConnectionBindings::bind($this->concept, 'sender', $ms2, 'member:1', null, $campaign);
        $this->assertSame($ms2, (int) ConnectionBindings::for($this->concept, 'sender', $campaign)->id);
        try { ConnectionBindings::for($this->concept, 'sender', ['type' => 'campaign', 'id' => 8]); $this->fail(); }
        catch (UnboundRoleException $e) { $this->assertStringContainsString('2 candidates', $e->getMessage()); }
        try { ConnectionBindings::bind($this->concept, 'payments', $ms1, 'member:1'); $this->fail('a mailbox bound as payments'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString("is microsoft; role 'payments'", $e->getMessage()); }
        try { ConnectionBindings::for($this->concept, 'payments', $campaign); $this->fail(); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('bound per install, not per entity', $e->getMessage()); }
        try { ConnectionBindings::role($this->concept, 'shipping'); $this->fail(); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString("no connector role 'shipping'", $e->getMessage()); }
    }
}
