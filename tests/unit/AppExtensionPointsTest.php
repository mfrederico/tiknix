<?php
/**
 * Extension points an app fills from its lib/app.php (RUNTIME-SPLIT-MAP.md step 4, found by
 * rebuilding cat-poo-box on the template): support-queue categories and the shell's level names.
 */

namespace tests\unit;

use app\Chrome;
use app\Contact;
use PHPUnit\Framework\TestCase;

class AppExtensionPointsTest extends TestCase {

    private array $cats;
    private array $names;

    protected function setUp(): void { $this->cats = Contact::$categories; $this->names = Chrome::$levelNames; }
    protected function tearDown(): void { Contact::$categories = $this->cats; Chrome::$levelNames = $this->names; }

    public function testTheDashboardIsASlotAndAnUnknownSlotIsRefused(): void {
        $parts = Chrome::$parts;
        try {
            Chrome::add('dashboard', 'invoicing/_dashboard');
            $this->assertSame(['invoicing/_dashboard'], Chrome::$parts['dashboard']);
            try { Chrome::add('dashbaord', 'x'); $this->fail('a misspelled slot was accepted'); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString("'dashbaord' does not exist", $e->getMessage()); }
        } finally {
            Chrome::$parts = $parts;
        }
    }

    public function testACategoryAddsItsStatusesAfterTheQueuesOwn(): void {
        $this->assertSame(Contact::STATUSES, Contact::statuses(), 'nothing registered: the queue as it always was');
        Contact::$categories = ['appointment' => ['label' => 'Appointments', 'statuses' => ['scheduled' => 'success', 'declined' => 'secondary']]];
        $this->assertSame(array_merge(Contact::STATUSES, ['scheduled' => 'success', 'declined' => 'secondary']), Contact::statuses());
    }

    public function testLevelNamesComeFromTheAppAndAnUnnamedLevelIsNotGuessed(): void {
        Chrome::$levelNames = [1 => 'Owner', 50 => 'Staff', 100 => 'Client'];
        $this->assertSame('Client', Chrome::levelName(100));
        $log = ini_set('error_log', $f = tempnam(sys_get_temp_dir(), 'lvl'));
        $this->assertSame('75', Chrome::levelName(75), 'the number, which a person will question — never "Member"');
        ini_set('error_log', $log);
        $this->assertStringContainsString('no name for permission level 75', (string) file_get_contents($f));
        unlink($f);
    }
}
