<?php
/** Google's tag: what a page gets once a visitor said yes, and that the ID can only be an ID. */

namespace app\concepts\googleanalytics\tests;


use app\concepts\googleanalytics\Tag;
use PHPUnit\Framework\TestCase;

class TagTest extends TestCase {

    public function testOnlyAMeasurementIdIsAccepted(): void {
        $this->assertTrue(Tag::isId('G-AB12CD34EF'));
        foreach (['', 'UA-1234-1', 'g-ab12', 'G-12', "G-ABCD');alert(1)//", 'G-ABCD"><script>'] as $bad) $this->assertFalse(Tag::isId($bad), $bad);
        $this->expectException(\InvalidArgumentException::class);
        Tag::snippet('G-ABCD"><script>', false);
    }

    public function testAnEventGoesOutOnlyAfterAYes(): void {
        $q = [['sign_up', ['method' => 'form']]];
        $this->assertSame([$q, []], Tag::settle($q, true, true, true, false), 'a yes: it goes');
        $this->assertSame([[], []], Tag::settle($q, true, true, false, false), 'a no: it is gone');
        $this->assertSame([[], $q], Tag::settle($q, true, false, false, false), 'no answer yet: it waits');
        $this->assertSame([[], $q], Tag::settle($q, false, false, false, false), 'not asking yet: it waits');
        $this->assertSame([[], []], Tag::settle($q, true, true, true, true), 'an admin is not counted');
    }

    public function testAnEventIsWrittenSafelyIntoThePage(): void {
        $s = Tag::snippet('G-AB12CD34EF', false, [['sign_up', ['method' => '</script><b>']], ['project_created', []]]);
        $this->assertStringContainsString('gtag(\'event\',"project_created",{});', $s);
        $this->assertStringNotContainsString('</script><b>', $s);
        $this->assertLessThan(strpos($s, "gtag('event'"), strpos($s, "gtag('config'"));
        foreach (['Sign Up', '1x', ''] as $bad) { try { Tag::event($bad); $this->fail("accepted {$bad}"); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not an event name', $e->getMessage()); } }
    }

    public function testConsentComesBeforeTheTagAndAdsStayDeniedWithoutAYes(): void {
        $s = Tag::snippet('G-AB12CD34EF', false);
        $this->assertLessThan(strpos($s, "gtag('config'"), strpos($s, "gtag('consent','default'"));
        $this->assertLessThan(strpos($s, 'googletagmanager'), strpos($s, "gtag('consent','update'"));
        $this->assertStringContainsString("analytics_storage:'granted',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'", $s);
        $this->assertStringContainsString("ad_storage:'granted'", Tag::snippet('G-AB12CD34EF', true));
    }
}
