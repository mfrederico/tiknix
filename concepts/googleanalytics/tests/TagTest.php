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

    public function testConsentComesBeforeTheTagAndAdsStayDeniedWithoutAYes(): void {
        $s = Tag::snippet('G-AB12CD34EF', false);
        $this->assertLessThan(strpos($s, "gtag('config'"), strpos($s, "gtag('consent','default'"));
        $this->assertLessThan(strpos($s, 'googletagmanager'), strpos($s, "gtag('consent','update'"));
        $this->assertStringContainsString("analytics_storage:'granted',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'", $s);
        $this->assertStringContainsString("ad_storage:'granted'", Tag::snippet('G-AB12CD34EF', true));
    }
}
