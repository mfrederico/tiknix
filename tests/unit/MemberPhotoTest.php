<?php
/**
 * app\MemberPhoto — the picture on the account pages and in the shell's chip: where it comes from
 * (the app's own provider, else the member's uploaded key, else a link from elsewhere), and that a
 * photo which cannot be shown never takes a page down.  app\Turnstile::asks — the bot check is for
 * strangers only.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\MemberPhoto;

class MemberPhotoTest extends TestCase {
    protected function tearDown(): void {
        $p = new \ReflectionProperty(MemberPhoto::class, 'provider'); $p->setAccessible(true); $p->setValue(null, null);
        $m = new \ReflectionProperty(MemberPhoto::class, 'memo'); $m->setAccessible(true); $m->setValue(null, []);
        $_SESSION = [];
    }

    public function testWithNoPhotoTheChipIsTheInitialsEscaped(): void {
        $this->assertSame('', MemberPhoto::url(['id' => 7, 'username' => 'ann']));
        $chip = MemberPhoto::chip(['id' => 7], 'A<', 30);
        $this->assertStringContainsString('A&lt;', $chip);
        $this->assertStringNotContainsString('<img', $chip);
        $this->assertSame('', MemberPhoto::url(['id' => 0]), 'nobody has no photo');
    }

    public function testALinkFromElsewhereIsShownWhenThereIsNoUploadedPhoto(): void {
        $this->assertSame('https://lh3.example/a.jpg', MemberPhoto::url(['id' => 8, 'avatar_url' => ' https://lh3.example/a.jpg ']));
        $this->assertStringContainsString('<img class="ui-avatar" src="https://lh3.example/a.jpg"', MemberPhoto::chip(['id' => 8, 'avatar_url' => 'https://lh3.example/a.jpg'], 'AB'));
    }

    public function testAnAppThatKeepsItsOwnPhotosIsAskedAndItsPageIsWhereOneIsChanged(): void {
        $asked = [];
        MemberPhoto::provide(function (int $id) use (&$asked) { $asked[] = $id; return $id === 9 ? '/photos/9.jpg' : ''; }, '/social/me');
        $this->assertTrue(MemberPhoto::provided());
        $this->assertFalse(MemberPhoto::canUpload(), 'one source at a time: no platform upload beside the app\'s own');
        $this->assertSame('/social/me', MemberPhoto::editUrl());
        $this->assertSame('/photos/9.jpg', MemberPhoto::url(['id' => 9, 'avatar_key' => 'public/x/ignored.jpg', 'avatar_url' => 'https://ignored']));
        $this->assertSame('', MemberPhoto::url(['id' => 10]));
        MemberPhoto::url(['id' => 9]);
        $this->assertSame([9, 10], $asked, 'asked once per member per request (the chip is drawn twice a page)');
    }

    public function testAPhotoThatCannotBeShownIsLoggedAndTheChipFallsToInitials(): void {
        MemberPhoto::provide(function (int $id) { throw new \RuntimeException('the profile table is gone'); }, '/social/me');
        $log = tempnam(sys_get_temp_dir(), 'mp'); $was = ini_set('error_log', $log);
        try { $this->assertSame('', MemberPhoto::url(['id' => 11])); } finally { ini_set('error_log', (string) $was); }
        $this->assertStringContainsString('ERROR MemberPhoto: member 11 has a photo that cannot be shown', (string) file_get_contents($log));
        unlink($log);
    }

    public function testThePageToChangeAPhotoIsAPathInThisApp(): void {
        foreach (['', 'social/me', '//evil.example/x', 'https://evil.example'] as $bad) {
            try { MemberPhoto::provide(fn(int $id) => '', $bad); $this->fail("accepted '{$bad}'"); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString('a path in this app', $e->getMessage()); }
        }
    }

    public function testTheBotCheckIsForStrangersOnly(): void {
        // Whatever this install has connected: a stranger is asked exactly when the check is on…
        $_SESSION = [];
        $this->assertSame(\app\Turnstile::enabled(), \app\Turnstile::asks());
        // …and a signed-in member never is, drawn no widget, and needs no token.
        $_SESSION['member'] = ['id' => 5, 'level' => 100];
        $this->assertSame('', \app\Turnstile::widget());
        $this->assertFalse(\app\Turnstile::asks(), 'a signed-in member is never asked');
        $this->assertTrue(\app\Turnstile::verify(null), 'and their post needs no token');
    }
}
