<?php
/**
 * requires.system — the system software a plugin asks the platform to install where it is enabled
 * (core TenantHost::SYSTEM_RECIPES, run by tenant.php --system).
 */

namespace tests\unit;

use app\ConceptManifest;
use app\ConceptException;
use app\TenantHost;
use PHPUnit\Framework\TestCase;

class ConceptRequiresSystemTest extends TestCase {

    public function testAPluginNamesTheSystemSoftwareItNeeds(): void {
        $m = ConceptManifest::fromArray(['name' => 'iot', 'version' => '1', 'requires' => ['system' => ['mosquitto'], 'commands' => ['mosquitto']]], '/x', 'iot');
        $this->assertSame(['mosquitto'], $m->requiresSystem);
        $this->assertSame([], ConceptManifest::fromArray(['name' => 'plain', 'version' => '1'], '/x', 'plain')->requiresSystem);
    }

    public function testANameThatIsNotOneIsRefused(): void {
        $this->expectException(ConceptException::class);
        ConceptManifest::fromArray(['name' => 'iot', 'version' => '1', 'requires' => ['system' => ['mosquitto; rm -rf /']]], '/x', 'iot');
    }

    public function testThePlatformHasARecipeForWhatItsPluginsAskFor(): void {
        foreach (['google-chrome', 'mosquitto'] as $need) {
            $this->assertArrayHasKey($need, TenantHost::SYSTEM_RECIPES);
            $this->assertNotSame('', TenantHost::SYSTEM_RECIPES[$need]['check']);
            $this->assertNotSame('', TenantHost::SYSTEM_RECIPES[$need]['install']);
        }
    }
}
