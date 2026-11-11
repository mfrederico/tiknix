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

    public function testItIsAPerPersonGrantOnAnAppAndTheROOTsOnThePlatform(): void {
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

        // On the platform itself: its ROOT notes changes to Tiknix (answered here, PlatformChanges), nobody else does.
        Feature::$platform = true;
        $local = ChangeAsk::$local;
        try {
            ChangeAsk::$local = fn() => [];
            $this->assertTrue(ChangeAsk::allowed($root), 'the platform\'s ROOT notes changes to Tiknix');
            $this->assertFalse(ChangeAsk::allowed($admin), 'an admin\'s grant is an app\'s, not the platform\'s');
            $this->assertFalse(ChangeAsk::allowed($member));
            ChangeAsk::$local = null;
            $this->assertFalse(ChangeAsk::allowed($root), 'with nothing to answer the calls, not even ROOT');
        } finally { ChangeAsk::$local = $local; }
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

    public function testNotesAreKeptWithinBoundsAndEachPointsAtARealPicture(): void {
        $el = ['label' => 'Button “Save”', 'code' => 'button.btn.btn-primary', 'selector' => '#form > button', 'tag' => 'button', 'text' => 'Save', 'html' => str_repeat('<b>x</b>', 400), 'size' => '80×36'];
        $ok = ChangeAsk::checkNotes([['page' => '/leads', 'text' => 'Make it green', 'element' => $el, 'shot' => 0, 'screen' => '1440x900'],
                                     ['page' => '/leads?q=a', 'text' => 'Whole page is slow', 'element' => null, 'shot' => null]], 1);
        $this->assertCount(2, $ok);
        $this->assertSame('#form > button', $ok[0]['element']['selector']);
        $this->assertSame(1300, mb_strlen($ok[0]['element']['html']), 'a long element is cut, not refused');
        $this->assertNull($ok[1]['element'], 'a note about the whole page');
        foreach ([[['page' => '/x', 'text' => 'hi']], [['page' => 'http://evil/x', 'text' => 'Make it green']], [['page' => '/x', 'text' => 'Make it green', 'shot' => 3]],
                  array_fill(0, ChangeAsk::MAX_SHOTS + 1, ['page' => '/x', 'text' => 'Make it green'])] as $i => $bad) {
            try { ChangeAsk::checkNotes($bad, 1); $this->fail("case {$i} was taken"); }
            catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }

    public function testAttachedFilesMustBeWhatTheirNamesSayAndWithinBounds(): void {
        $dir = sys_get_temp_dir() . '/chgdocs-' . getmypid(); @mkdir($dir);
        file_put_contents("$dir/rules.md", "# Pick list\n- sort by bin\n");
        file_put_contents("$dir/sheet.csv", "sku,qty\nA1,3\n");
        file_put_contents("$dir/fake.xlsx", "<?php echo 'not a spreadsheet';");
        file_put_contents("$dir/run.sh", "#!/bin/sh\n");
        $f = fn($n) => ['tmp' => "$dir/$n", 'name' => $n, 'size' => filesize("$dir/$n"), 'error' => UPLOAD_ERR_OK];
        try {
            $ok = ChangeAsk::checkDocs([$f('rules.md'), $f('sheet.csv')]);
            $this->assertSame(['rules.md', 'sheet.csv'], array_column($ok, 'safe'));
            foreach (['fake.xlsx' => 'not what its name says', 'run.sh' => 'attach pictures'] as $bad => $why) {
                try { ChangeAsk::checkDocs([$f($bad)]); $this->fail("{$bad} was taken"); }
                catch (\InvalidArgumentException $e) { $this->assertStringContainsString($why, $e->getMessage()); }
            }
            try { ChangeAsk::checkDocs(array_fill(0, ChangeAsk::MAX_DOCS + 1, $f('rules.md'))); $this->fail('too many were taken'); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString('At most', $e->getMessage()); }
            // a note names its files by index, and only files that came
            $this->assertSame([0], ChangeAsk::checkNotes([['page' => '/x', 'text' => 'Work like my sheet', 'files' => [0]]], 0, 1)[0]['files']);
            $this->expectException(\InvalidArgumentException::class);
            ChangeAsk::checkNotes([['page' => '/x', 'text' => 'Work like my sheet', 'files' => [1]]], 0, 1);
        } finally { array_map('unlink', glob("$dir/*")); @rmdir($dir); }
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
