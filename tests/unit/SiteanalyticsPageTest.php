<?php
/** tiknix.com's own Google Analytics page, in each state it can be in. */

namespace tests\unit;

use PHPUnit\Framework\TestCase;

class SiteanalyticsPageTest extends TestCase {

    private function page(array $vars): string {
        extract($vars + ['id' => '', 'connected' => false, 'problems' => [], 'asking' => false, 'plugin' => true]);
        ob_start();
        include \app\Paths::root() . '/views/siteanalytics/index.php';
        return (string) ob_get_clean();
    }

    public function testEachStateSaysWhatIsTrue(): void {
        $none = $this->page([]);
        $this->assertStringNotContainsString('Not running yet', $none);
        $this->assertStringNotContainsString('Turn it off', $none, 'nothing to turn off');
        $this->assertStringContainsString('Not running yet', $this->page(['connected' => true, 'id' => 'G-ABCD1234']));
        $running = $this->page(['connected' => true, 'id' => 'G-ABCD1234', 'asking' => true]);
        $this->assertStringContainsString('Running.', $running);
        $this->assertStringContainsString('G-ABCD1234', $running);
        $this->assertStringContainsString('/siteanalytics/off', $running);
        $this->assertStringContainsString('plugin is off', $this->page(['plugin' => false, 'connected' => true]), 'never looks connected while doing nothing');
        $this->assertStringContainsString('not a Measurement ID', $this->page(['problems' => ['measurement_id' => 'That is not a Measurement ID.']]));
    }
}
