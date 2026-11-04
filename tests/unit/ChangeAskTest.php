<?php
/**
 * Runtime lib/ChangeAsk.php and the grants' places (lib/Feature.php): asking for changes is a per-person grant on an
 * app — admins included, ROOT always — and is never offered on the platform; the platform's own grants are not
 * offered on an app; and what is not a request is refused before anything is kept or sent.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\ChangeAsk;
use app\Feature;

class ChangeAskTest extends ConceptsTestCase {

    private const DB = 'changeask-test';
    private bool $wasPlatform = true;

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        $this->wasPlatform = Feature::$platform;
    }

    protected function tearDown(): void {
        Feature::$platform = $this->wasPlatform;
        Bean::selectDatabase('default');
        parent::tearDown();
    }

    private function member(int $level, string $email = 'kent@example.com'): object {
        $m = Bean::dispense('member');
        $m->email = $email; $m->level = $level; $m->status = 'active'; $m->username = strtok($email, '@');
        Bean::store($m);
        return $m;
    }

    public function testItIsAPerPersonGrantOnAnAppAndNeverOnThePlatform(): void {
        Feature::$platform = false;
        $admin = $this->member(50); $member = $this->member(100, 'm@example.com'); $root = $this->member(1, 'r@example.com');
        $this->assertFalse(ChangeAsk::allowed($admin), 'an admin is not given it by their level');
        $this->assertFalse(ChangeAsk::allowed($member));
        $this->assertTrue(ChangeAsk::allowed($root), 'ROOT always');
        Feature::setEnabled('changes', true, (int) $admin->id);
        Feature::setEnabled('changes', true, (int) $member->id);
        $this->assertTrue(ChangeAsk::allowed($admin));
        $this->assertTrue(ChangeAsk::allowed($member));
        $this->assertFalse(ChangeAsk::allowed(null));

        Feature::$platform = true;
        $this->assertFalse(ChangeAsk::allowed($root), 'there is no team to ask on the platform itself');
        $this->assertFalse(ChangeAsk::allowed($member));
    }

    public function testEachInstallIsOfferedOnlyTheGrantsThatMeanSomethingThere(): void {
        Feature::$platform = false;
        $app = array_keys(Feature::catalogForLevel(1));
        $this->assertContains('changes', $app);
        $this->assertContains('mcp', $app, 'both: an app\'s API keys');
        $this->assertNotContains('workbench', $app, 'the platform\'s Builder means nothing on a client\'s site');
        Feature::$platform = true;
        $core = array_keys(Feature::catalogForLevel(1));
        $this->assertNotContains('changes', $core);
        $this->assertContains('workbench', $core);
        $this->assertContains('mcp', $core);
    }

    public function testWhatIsNotARequestIsRefusedBeforeAnythingIsKept(): void {
        Feature::$platform = false;
        $m = $this->member(1);
        $noEmail = $this->member(1, 'x@example.com'); $noEmail->email = '';
        $txt = tempnam(sys_get_temp_dir(), 'chg'); file_put_contents($txt, 'not a picture at all');
        $cases = [
            [$m, 'short', []],
            [$noEmail, 'Make the photos bigger please', []],
            [$m, 'Make the photos bigger please', [['tmp' => $txt, 'name' => 'x.png', 'size' => 20, 'error' => UPLOAD_ERR_OK]]],
            [$m, 'Make the photos bigger please', array_fill(0, ChangeAsk::MAX_SHOTS + 1, ['tmp' => $txt, 'name' => 'x.png', 'size' => 20, 'error' => UPLOAD_ERR_OK])],
            [$m, 'Make the photos bigger please', [['tmp' => '', 'name' => 'big.png', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]]],
        ];
        try {
            foreach ($cases as $i => [$who, $said, $files]) {
                try { ChangeAsk::send($who, $said, '/shop', 'test', $files); $this->fail("case {$i} was taken"); }
                catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
            }
        } finally { @unlink($txt); }
        $this->assertSame(0, in_array('changeask', Bean::inspect(), true) ? Bean::count('changeask') : 0, 'nothing kept');
    }
}
