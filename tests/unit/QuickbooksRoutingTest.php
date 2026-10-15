<?php
/**
 * QuickBooks through the platform: which project connected which company (ConnectorRoutes), what a
 * renewal is allowed, how Intuit's notices are read in both its formats (QuickbooksNotice), and how
 * they are routed — each company's changes to the projects that connected it, and a project that no
 * longer has the company forgotten (QuickbooksWebhook::forward).
 */
namespace tests\unit;

use app\ConnectorRoutes;
use app\QuickbooksNotice;
use app\QuickbooksWebhook;

class QuickbooksRoutingTest extends ConceptsTestCase {
    protected function setUp(): void { parent::setUp(); self::memoryDb(); }

    public function testARenewalIsForACompanyTheProjectConnectedAndOnlySoOften(): void {
        ConnectorRoutes::record(137, 'quickbooks', 'development', '4620', 1);
        $this->assertTrue(ConnectorRoutes::allows(137, 'quickbooks', '4620'));
        $this->assertFalse(ConnectorRoutes::allows(138, 'quickbooks', '4620'), 'another project: not its company');
        $this->assertFalse(ConnectorRoutes::allows(137, 'quickbooks', ''), 'no company named: refused');
        for ($i = 0; $i < ConnectorRoutes::RENEWALS_PER_HOUR; $i++) $this->assertTrue(ConnectorRoutes::countRenewal(137, 'quickbooks', '4620'));
        $this->assertFalse(ConnectorRoutes::countRenewal(137, 'quickbooks', '4620'), 'past the hour\'s limit');
        ConnectorRoutes::record(137, 'shopify', 'production', 'x', 2);
        $this->assertFalse(ConnectorRoutes::allows(137, 'shopify', 'x'), 'only the platform-app providers are routed');
    }

    public function testTheSignatureIsIntuitsHmacOfTheBody(): void {
        $body = '{"eventNotifications":[]}';
        $sig = base64_encode(hash_hmac('sha256', $body, 'verifier', true));
        $this->assertTrue(QuickbooksNotice::verify($body, $sig, 'verifier'));
        $this->assertFalse(QuickbooksNotice::verify($body . ' ', $sig, 'verifier'));
        $this->assertFalse(QuickbooksNotice::verify($body, $sig, ''), 'no verifier set: nothing is accepted');
    }

    public function testBothOfIntuitsFormatsReadAsOneList(): void {
        $classic = json_encode(['eventNotifications' => [['realmId' => '4620', 'dataChangeEvent' => ['entities' => [
            ['name' => 'Invoice', 'id' => '42', 'operation' => 'Update', 'lastUpdated' => '2026-10-15T10:14:00Z']]]]]]);
        $this->assertSame([['company' => '4620', 'entity' => 'Invoice', 'id' => '42', 'operation' => 'Update', 'at' => '2026-10-15T10:14:00Z']], QuickbooksNotice::parse($classic));
        $cloud = json_encode([['specversion' => '1.0', 'type' => 'qbo.journalentry.created.v1', 'intuitaccountid' => '9130', 'intuitentityid' => '7', 'time' => '2026-10-15T10:15:00Z']]);
        $this->assertSame([['company' => '9130', 'entity' => 'JournalEntry', 'id' => '7', 'operation' => 'Create', 'at' => '2026-10-15T10:15:00Z']], QuickbooksNotice::parse($cloud));
    }

    public function testEachCompanysChangesGoToTheProjectsThatConnectedIt(): void {
        ConnectorRoutes::record(137, 'quickbooks', 'development', '4620', 1);   // the bakery
        ConnectorRoutes::record(93, 'quickbooks', 'production', '9130', 1);     // serenity
        ConnectorRoutes::record(200, 'quickbooks', 'production', '9130', 4);    // the same company in a second project
        $sent = [];
        $ask = function (int $iid, string $path, array $body) use (&$sent) { $sent[] = [$iid, $body['company'], count($body['changes'])]; return ['data' => ['known' => $iid !== 200, 'fired' => 1]]; };
        $lines = QuickbooksWebhook::forward([
            ['company' => '4620', 'entity' => 'Invoice', 'id' => '1', 'operation' => 'Create', 'at' => ''],
            ['company' => '9130', 'entity' => 'Payment', 'id' => '2', 'operation' => 'Create', 'at' => ''],
            ['company' => '4620', 'entity' => 'Customer', 'id' => '3', 'operation' => 'Update', 'at' => ''],
            ['company' => '5555', 'entity' => 'Invoice', 'id' => '4', 'operation' => 'Create', 'at' => ''],
        ], $ask);
        $this->assertSame([[137, '4620', 2], [93, '9130', 1], [200, '9130', 1]], $sent, 'the bakery gets both of its changes, serenity\'s company goes to both its projects');
        $this->assertSame([93], ConnectorRoutes::forCompany('quickbooks', '9130'), 'project 200 said it no longer has that company: forgotten');
        $this->assertStringContainsString('company 5555: no project connected it', implode("\n", $lines));
    }
}
