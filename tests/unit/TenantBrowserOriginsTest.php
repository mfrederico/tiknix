<?php
/**
 * TenantBrowser::origins — an app's addresses for the build's browser: its domain, its added domains (ct_hosts, a JSON
 * list) and its aliases (ct_aliases, a comma list, as ProxmoxDeploy writes it). Reading the aliases as JSON threw for
 * every app with one, and no build could start on Serenity (2026-11-04).
 */

namespace tests\unit;

use app\TenantBrowser;
use PHPUnit\Framework\TestCase;

class TenantBrowserOriginsTest extends TestCase {

    private function inst(string $hosts, ?string $aliases): object {
        return (object) ['id' => 93, 'slug' => 'serenity-bbdc01', 'ctDomain' => 'serenity-bbdc01.tiknix.com', 'ctHosts' => $hosts, 'ctAliases' => $aliases, 'ctIp' => '10.10.10.114'];
    }

    public function testDomainAddedDomainsAndCommaAliasesAreAllAddresses(): void {
        $o = TenantBrowser::origins($this->inst('["serenitygemstones.com"]', 'serenity-denver.tiknix.com, other.example.com'));
        foreach (['https://serenity-bbdc01.tiknix.com', 'https://serenitygemstones.com', 'https://serenity-denver.tiknix.com', 'https://other.example.com'] as $want) {
            $this->assertContains($want, $o);
        }
    }

    public function testNoExtrasIsJustTheDomain(): void {
        $this->assertContains('https://serenity-bbdc01.tiknix.com', TenantBrowser::origins($this->inst('', null)));
    }

    public function testABrokenHostsListIsSaid(): void {
        $this->expectExceptionMessage('ctHosts is not a JSON list');
        TenantBrowser::origins($this->inst('serenitygemstones.com', null));
    }
}
