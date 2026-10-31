<?php
/** A Google Analytics connection is its Measurement ID: checked by shape, no secret, nothing sent anywhere. */

namespace tests\unit;

use app\ConnectorSetup;
use app\services\connectors\ConnectorRegistry;
use PHPUnit\Framework\TestCase;

class GoogleAnalyticsConnectorTest extends TestCase {

    public function testItIsAMarketingCardThatNeedsNoSecret(): void {
        $m = ConnectorRegistry::get('googleanalytics')->meta();
        $this->assertSame('Marketing', $m['category']);
        $this->assertFalse($m['key_required']);
        $this->assertSame('googleanalytics', $m['requires_plugin']);
        $this->assertSame(['key', 'asking'], array_column(ConnectorSetup::declared('googleanalytics', $m), 'id'));
    }

    public function testOnlyAMeasurementIdIsAccepted(): void {
        $c = ConnectorRegistry::get('googleanalytics');
        $p = $c->validateApiKey('', ['measurement_id' => ' g-ab12cd34ef ']);
        $this->assertSame('G-AB12CD34EF', $p['metadata']['measurement_id']);
        $this->assertSame('', $p['access_token'], 'nothing secret is stored');
        foreach (['', 'UA-1234-1', 'G-12', 'G-ABCD"><script>'] as $bad) {
            try { $c->validateApiKey('', ['measurement_id' => $bad]); $this->fail("accepted {$bad}"); }
            catch (\Exception $e) { $this->assertStringContainsString('not a Measurement ID', $e->getMessage()); }
        }
    }

    public function testAnExtensionMayKnowAStepIsDone(): void {
        $steps = [['id' => 'key', 'kind' => 'key', 'title' => 'K'], ['id' => 'asking', 'kind' => 'go', 'title' => 'A', 'go' => ['url' => '/consent']]];
        ConnectorSetup::extend('x', function ($s) { $s[1]['met'] = true; return $s; });
        $c = ConnectorSetup::checklist('x', ['setup' => $steps], ['id' => 1, 'fields' => [], 'setup' => []], 'a.example.com');
        ConnectorSetup::reset();
        $this->assertTrue($c['complete']);
        $this->assertFalse(ConnectorSetup::checklist('x', ['setup' => $steps], ['id' => 1, 'fields' => [], 'setup' => []], 'a.example.com')['complete']);
    }
}
