<?php
/**
 * app\Host — which domain a process serves, and where that domain's config and data are.
 * The owner's rules (2026-09-30): each other domain has a FULL config at conf/hosts/<host>.ini;
 * a web request for a host with no file sees the main site (and says so); the CLI naming a
 * host with no file is refused; a domain's data lives under hosts/<host>/.
 */

namespace tests\unit;

use app\Host;
use PHPUnit\Framework\TestCase;

class HostTest extends TestCase {

    private string $app;
    private array $server;
    private array $argv;

    protected function setUp(): void {
        $this->app = sys_get_temp_dir() . '/tiknix-host-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->app . '/conf/hosts', 0700, true);
        file_put_contents($this->app . '/conf/config.ini', "[app]\nbaseurl = \"https://main.example.com\"\n");
        file_put_contents($this->app . '/conf/hosts/client1.example.com.ini', "[app]\nbaseurl = \"https://client1.example.com\"\n");
        $this->server = $_SERVER;
        $this->argv = $GLOBALS['argv'] ?? [];
        Host::useHost(null);
        putenv('TIKNIX_HOST');
    }

    protected function tearDown(): void {
        $_SERVER = $this->server;
        $GLOBALS['argv'] = $this->argv;
        Host::useHost(null);
        Host::forceWeb(false);
        putenv('TIKNIX_HOST');
        exec('rm -rf ' . escapeshellarg($this->app));
    }

    public function testNoHostIsTheMainSite(): void {
        $GLOBALS['argv'] = ['clitool.php', '--list'];
        $this->assertSame($this->app . '/conf/config.ini', Host::configFile($this->app));
        $this->assertSame('', Host::current());
        $this->assertSame($this->app, Host::dataRoot($this->app));
    }

    public function testTheCliNamesADomainAndGetsItsOwnConfigAndData(): void {
        $GLOBALS['argv'] = ['clitool.php', '--build', '--host=Client1.Example.com'];
        $this->assertSame($this->app . '/conf/hosts/client1.example.com.ini', Host::configFile($this->app));
        $this->assertSame('client1.example.com', Host::current());
        $this->assertSame($this->app . '/hosts/client1.example.com', Host::dataRoot($this->app));
        $this->assertSame(['TIKNIX_HOST' => 'client1.example.com'], Host::env());
    }

    public function testAHostPastTheSeparatorIsAnotherProgramsArgument(): void {
        // tenant.php --clitool=<app> -- --host=… : the --host is for the app's clitool, not core's boot.
        $GLOBALS['argv'] = ['tenant.php', '--clitool=mileage', '--', '--host=client1.example.com', '--list'];
        $this->assertSame($this->app . '/conf/config.ini', Host::configFile($this->app));
    }

    public function testAWorkerGetsItsHostFromTheEnvironment(): void {
        $GLOBALS['argv'] = ['pipeline-run.php', '--run=5'];
        putenv('TIKNIX_HOST=client1.example.com');
        $this->assertSame($this->app . '/conf/hosts/client1.example.com.ini', Host::configFile($this->app));
    }

    public function testTheCliNamingAnUnknownDomainIsRefused(): void {
        $GLOBALS['argv'] = ['clitool.php', '--build', '--host=nobody.example.com'];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("no domain 'nobody.example.com'");
        Host::configFile($this->app);
    }

    public function testBehindTheProxyTheForwardedHostNamesTheDomain(): void {
        $GLOBALS['argv'] = [];
        $this->asWeb(['HTTP_HOST' => '10.10.10.106', 'HTTP_X_FORWARDED_HOST' => 'client1.example.com', 'REMOTE_ADDR' => '10.10.10.1']);
        $this->assertSame($this->app . '/conf/hosts/client1.example.com.ini', Host::configFile($this->app));
    }

    public function testAVisitorCannotClaimAHostWithTheHeader(): void {
        // From a public address the header is the visitor's own words: the Host header decides.
        $this->asWeb(['HTTP_HOST' => 'main.example.com', 'HTTP_X_FORWARDED_HOST' => 'client1.example.com', 'REMOTE_ADDR' => '203.0.113.9']);
        $this->assertSame($this->app . '/conf/config.ini', Host::configFile($this->app));
    }

    public function testAnUnknownHostOnTheWebIsTheMainSiteAndSaysSo(): void {
        $this->asWeb(['HTTP_HOST' => 'stranger.example.com', 'REMOTE_ADDR' => '203.0.113.9']);
        $this->assertSame($this->app . '/conf/config.ini', Host::configFile($this->app));
        $this->assertSame('', Host::current());
        $this->assertStringContainsString('stranger.example.com.ini', Host::fellBack());
    }

    /** Host reads PHP_SAPI for web vs CLI; this seam runs its web branch. */
    private function asWeb(array $server): void {
        $_SERVER = array_merge($this->server, $server);
        Host::useHost(null);
        Host::forceWeb(true);
    }

    public function testDomainsAreListedByTheirFiles(): void {
        $this->assertSame(['client1.example.com'], Host::all($this->app));
        $this->assertFalse(Host::valid('../etc'), 'a path is not a host');
        $this->assertFalse(Host::valid('localhost'), 'a domain has a dot');
        $this->assertSame('a.example.com', Host::normalize('A.Example.com:443.'));
    }
}
