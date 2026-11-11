<?php
/**
 * lib/SiteChanges.php — a change asked for on a project's site reaches the project's team as a conversation
 * addressed to the asker; the app reads back where it is and what was said; a decision needs its reason; only the
 * project's people may see it; and what the Builder is asked carries the page, the screenshots' paths and the errors.
 *
 * Mail is forced off (demo mode) and team notices go to PlanNotifier::$sendForTests, so nothing leaves the test.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\SiteChanges;
use app\PlanNotifier;
use app\services\NotifyService;

class SiteChangesTest extends ConceptsTestCase {

    private const DB = 'changerequests-test';
    private ?array $savedMailConfig = null;
    private $savedSend = null;
    private array $mailed = [];

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
        $p = new \ReflectionProperty(NotifyService::class, 'configCache');
        $this->savedMailConfig = $p->getValue();
        $p->setValue(null, ['apiKey' => '', 'domain' => 'mail.test', 'inboundDomain' => 'mail.test', 'fromEmail' => 'noreply@mail.test',
                            'fromName' => 'Test', 'endpoint' => '', 'demoMode' => true]);
        $this->savedSend = PlanNotifier::$sendForTests;
        $this->mailed = [];
        PlanNotifier::$sendForTests = function (array $payload): string { $this->mailed[] = $payload; return 'sent (test)'; };
    }

    protected function tearDown(): void {
        (new \ReflectionProperty(NotifyService::class, 'configCache'))->setValue(null, $this->savedMailConfig);
        PlanNotifier::$sendForTests = $this->savedSend;
        Bean::selectDatabase('default');
        parent::tearDown();
    }

    private function member(string $email, int $level = 100): int {
        $m = Bean::dispense('member');
        $m->email = $email; $m->level = $level; $m->status = 'active'; $m->username = strtok($email, '@');
        return (int) Bean::store($m);
    }

    /** A project owned by $owner, shared with a team that has $teammate in it. */
    private function project(int $owner, int $teammate): object {
        $team = Bean::dispense('team'); $team->name = 'Builders'; Bean::store($team);
        $tm = Bean::dispense('teammember'); $tm->teamId = (int) $team->id; $tm->memberId = $teammate; Bean::store($tm);
        $inst = Bean::dispense('instance'); $inst->slug = 'serenity-x'; $inst->displayName = 'Serenity'; $inst->memberId = $owner;
        $inst->sharedTeamList[] = $team;
        Bean::store($inst);
        return Bean::load('instance', (int) $inst->id);
    }

    private function ask(object $inst, int $ref = 1, array $extra = []): int {
        return SiteChanges::receive($inst, $extra + ['site' => 'serenitygemstones.com', 'app_ref' => $ref, 'from_name' => 'Kent', 'from_email' => 'kent@example.com',
            'level' => 'Admin', 'said' => "Make the shop photos bigger\nThey are tiny on a phone.", 'page' => '/shop/item/P-0001', 'browser' => 'Firefox',
            'errors' => ['[2026-11-04 10:00:00] ERROR: boom'], 'shots' => []]);
    }

    public function testARequestIsAConversationWithTheAskerSeatedWithTheTeamAndTheTeamIsTold(): void {
        $owner = $this->member('owner@x.test'); $mate = $this->member('mate@x.test'); $this->member('stranger@x.test');
        $inst = $this->project($owner, $mate);
        $id = $this->ask($inst);
        $cr = Bean::load('changerequest', $id);
        $this->assertSame('new', (string) $cr->status);
        $thread = Bean::load('thread', (int) $cr->threadRef);
        $this->assertSame('kent@example.com', (string) $thread->recipientEmail, 'a reply on it is emailed to the asker');
        $this->assertSame('changerequest', (string) $thread->relatedType);
        $seated = array_map('intval', Bean::getCol('SELECT member_id FROM threadmember WHERE thread_id = ?', [(int) $thread->id]));
        sort($seated);
        $this->assertSame([$owner, $mate], $seated, 'the owner and the team, nobody else');
        $this->assertSame(['owner@x.test', 'mate@x.test'], array_column($this->mailed, 'email'), 'each of the team is emailed');
        $this->assertSame($id, $this->ask($inst), 'the same request sent twice is one request');
        $this->assertSame(1, Bean::count('changerequest'));
    }

    public function testTheAppReadsWhereItIsAndWhatWasSaidAfterTheRequest(): void {
        $owner = $this->member('owner@x.test'); $mate = $this->member('mate@x.test');
        $inst = $this->project($owner, $mate);
        $id = $this->ask($inst, 7);
        SiteChanges::addFromApp($inst, 'serenitygemstones.com', 7, 'Also the prices.');
        \app\Notes::onThread($mate, (int) Bean::load('changerequest', $id)->threadRef, '<p>On it.</p>');
        $r = SiteChanges::forApp($inst, 'serenitygemstones.com', [7, 99], true);
        $this->assertCount(1, $r, 'only requests of this site and project');
        $this->assertSame('new', $r[0]['status']);
        $this->assertSame(1, $r[0]['answers'], 'the team\'s reply is an answer; the asker\'s own addition is not');
        $this->assertSame([['You', true, 'Also the prices.'], ['mate', false, 'On it.']],
            array_map(fn($m) => [$m['who'], $m['mine'], $m['text']], $r[0]['messages']), 'the request itself is not repeated');
        $this->assertSame([], SiteChanges::forApp($inst, 'another.example', [7], false), 'another site of the app sees none of it');
    }

    public function testNotGoingAheadNeedsTheReasonAndOnlyTheProjectsPeopleSeeIt(): void {
        $owner = $this->member('owner@x.test'); $mate = $this->member('mate@x.test'); $stranger = $this->member('stranger@x.test');
        $inst = $this->project($owner, $mate);
        $cr = Bean::load('changerequest', $this->ask($inst));
        $this->assertTrue(SiteChanges::may($cr, $mate, 100));
        $this->assertFalse(SiteChanges::may($cr, $stranger, 100));
        $this->assertTrue(SiteChanges::may($cr, $stranger, 1), 'ROOT sees every project\'s');
        $this->assertCount(1, SiteChanges::forMember($mate, 100));
        $this->assertSame([], SiteChanges::forMember($stranger, 100));
        $this->assertSame(1, SiteChanges::waiting($owner));
        try { SiteChanges::decline($cr, $owner, '  '); $this->fail('declined without a reason'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Say why', $e->getMessage()); }
        SiteChanges::decline($cr, $owner, 'That needs a new theme; next month.');
        $cr = Bean::load('changerequest', (int) $cr->id);
        $this->assertSame('declined', (string) $cr->status);
        $this->assertSame($owner, (int) $cr->decidedRef);
        $last = Bean::findOne('message', 'thread_id = ? ORDER BY id DESC', [(int) $cr->threadRef]);
        $this->assertStringContainsString('next month', (string) $last->content, 'the reason is said to the asker on their conversation');
        $this->assertSame(0, SiteChanges::waiting($owner));
    }

    public function testTheBuilderIsToldThePageTheScreenshotsAndTheErrors(): void {
        $owner = $this->member('owner@x.test');
        $cr = Bean::load('changerequest', $this->ask($this->project($owner, $owner)));
        $cr->shotsJson = json_encode([['name' => 'a.png', 'type' => 'image/png', 'disk' => 'x', 'app_path' => '/srv/app/hosts/serenitygemstones.com/data/changes/1/1.png']]);
        $brief = SiteChanges::brief($cr);
        $this->assertStringContainsString('Make the shop photos bigger', $brief);
        $this->assertStringContainsString('https://serenitygemstones.com/shop/item/P-0001', $brief);
        $this->assertStringContainsString('- /srv/app/hosts/serenitygemstones.com/data/changes/1/1.png', $brief);
        $this->assertStringContainsString('ERROR: boom', $brief);
    }

    public function testNotesReachTheBuilderWithWhatTheyPointAt(): void {
        $owner = $this->member('owner@x.test');
        $cr = Bean::load('changerequest', $this->ask($this->project($owner, $owner), 9, ['notes' => [
            ['page' => '/shop', 'text' => 'Bigger photo please', 'screen' => '1440x900', 'shot' => null,
             'element' => ['label' => 'Picture “Tiffany Stone”', 'code' => 'img.card-img', 'selector' => 'main > div:nth-of-type(2) > img', 'tag' => 'img', 'text' => '', 'html' => '<img class="card-img" src="/a.webp">', 'size' => '300×200']]]]));
        $notes = json_decode((string) $cr->notesJson, true);
        $this->assertSame('Bigger photo please', $notes[0]['text']);
        $brief = SiteChanges::brief($cr);
        $this->assertStringContainsString('1. On /shop, Picture “Tiffany Stone”: Bigger photo please', $brief);
        $this->assertStringContainsString('Selector: main > div:nth-of-type(2) > img', $brief);
        $this->assertStringContainsString('<img class="card-img" src="/a.webp">', $brief);
        try { $this->ask($this->project($owner, $owner), 10, ['notes' => [['page' => '/shop', 'text' => 'x', 'shot' => 2]]]); $this->fail('a note whose picture is not among the shots was taken'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('notes[0]', $e->getMessage()); }
    }

    public function testFilesAreListedWithTheirNoteAndOnlyTheSitesRequestFilesCanBeOpened(): void {
        $owner = $this->member('owner@x.test');
        $path = '/srv/app/hosts/floorplan.example.com/data/changes/3/files/1-picklist.xlsx';
        $cr = Bean::load('changerequest', $this->ask($this->project($owner, $owner), 11, [
            'files' => [['name' => 'picklist.xlsx', 'type' => 'application/zip', 'size' => 9000, 'path' => $path]],
            'notes' => [['page' => '/picks', 'text' => 'Make this list work like my sheet', 'files' => [0], 'shot' => null]]]));
        $files = json_decode((string) $cr->filesJson, true);
        $this->assertSame(0, $files[0]['note'], 'a file knows the note it came with');
        $brief = SiteChanges::brief($cr);
        $this->assertStringContainsString($path . ' (picklist.xlsx, with note 1): a spreadsheet', $brief);
        $this->assertStringContainsString('deterministically', $brief);
        foreach (['/srv/app/data/changes/3/files/1-a.md' => true, '/etc/passwd' => false, '/srv/app/data/changes/3/files/../../../conf/config.ini' => false,
                  '/srv/app/data/changes/3/1.webp' => false, '/srv/app/secure/connections.db' => false] as $p => $ok) {
            $this->assertSame($ok, SiteChanges::appPathOk($p), $p);
        }
        try { $this->ask($this->project($owner, $owner), 12, ['files' => [['name' => 'x', 'path' => '/srv/app/conf/config.ini']]]); $this->fail('a file outside the request folder was taken'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('files[0]', $e->getMessage()); }
    }

    public function testNotesOnTiknixItselfAreItsOwnProjectsQuietlyAndNeverBuiltByItsBuilder(): void {
        $owner = $this->member('owner@x.test');
        $core = $this->project($owner, $owner); $core->isDefault = 1; Bean::store($core);
        $root = rtrim(\app\Paths::root(), '/');
        $path = $root . '/data/changes/4/files/1-rules.md';
        $r = \app\PlatformChanges::call('POST', '/brokerinfo/change', ['site' => 'tiknix.com', 'app_ref' => 4, 'from_name' => 'Owner', 'from_email' => 'owner@x.test',
            'said' => 'The carousel arrows are off-centre', 'build' => true, 'files' => [['name' => 'rules.md', 'type' => 'text/plain', 'size' => 10, 'path' => $path]], 'notes' => [], 'shots' => []]);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['built'], 'asking to build it straight away is answered, not done');
        $this->assertStringContainsString('terminal', $r['why']);
        $this->assertSame([], $this->mailed, 'the platform\'s ROOT is not emailed about their own note');
        $cr = Bean::load('changerequest', $r['ref']);
        $this->assertSame((int) $core->id, (int) $cr->instanceId);
        foreach (['build', 'ask'] as $fn) {
            try { SiteChanges::$fn($cr, $owner); $this->fail("{$fn} was taken for Tiknix itself"); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('terminal', $e->getMessage()); }
        }
        $this->assertFalse(SiteChanges::canBuild($core)['ready']);
        $this->assertTrue(SiteChanges::appPathOk($path, true), 'the platform keeps its notes\' files under its own root');
        $this->assertFalse(SiteChanges::appPathOk($path), 'an app may not name a path of the platform');
        $this->assertFalse(SiteChanges::appPathOk($root . '/conf/config.ini', true));
        $this->assertSame('tiknix.com', \app\PlatformChanges::call('GET', '/brokerinfo/changes?site=tiknix.com&refs=4', null)['requests'][0]['ref'] === 4 ? 'tiknix.com' : 'missing');
    }

    public function testWhatIsNotARequestIsRefusedBeforeAnythingIsKept(): void {
        $inst = $this->project($o = $this->member('owner@x.test'), $o);
        foreach ([['said' => 'short'], ['from_email' => 'nope'], ['site' => 'not a host'], ['shots' => [['name' => 'x.png', 'data' => base64_encode('<?php echo 1;')]]]] as $bad) {
            try { $this->ask($inst, 1, $bad); $this->fail('took ' . json_encode($bad)); }
            catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
        $this->assertSame(0, Bean::count('changerequest'));
        $this->assertSame(0, Bean::count('thread'));
    }
}
