<?php
/**
 * tenant.php — make an app and give it its own container (RUNTIME-SPLIT-MAP.md step 4).
 *
 *   php scripts/tenant.php --new-app=SLUG --name="Display Name" [--member=1]
 *   php scripts/tenant.php --create=SLUG                  container + sshd
 *   php scripts/tenant.php --provision=SLUG --domain=HOST PHP, nginx, the app (tenant/provision.sh)
 *   php scripts/tenant.php --publish=SLUG --domain=HOST   serve HOST from the container
 *   php scripts/tenant.php --up=SLUG --domain=HOST        create + provision + publish
 *   php scripts/tenant.php --ssh=SLUG [--root] -- CMD…    run a command in the tenant (as app)
 *   php scripts/tenant.php --status=SLUG
 *   php scripts/tenant.php --destroy=SLUG --yes           delete the container, stop serving
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';
new \app\Bootstrap();

use app\TenantApp;
use app\TenantHost;

$argvRest = [];
$dd = array_search('--', $argv, true);
if ($dd !== false) { $argvRest = array_slice($argv, $dd + 1); $argv = array_slice($argv, 0, $dd); $_SERVER['argv'] = $argv; }
$o = getopt('', ['new-app:', 'name:', 'member:', 'create:', 'provision:', 'publish:', 'up:', 'ssh:', 'root', 'status:', 'destroy:', 'yes', 'domain:']);

function done(array $r, string $what): void {
    if (!empty($r['steps'])) foreach ($r['steps'] as $s) echo "  {$s}\n";
    if (!empty($r['step'])) echo "  {$r['step']}\n";
    if (!empty($r['output'])) echo rtrim($r['output']) . "\n";
    if (!$r['ok']) { fwrite(STDERR, "ERROR {$what}: " . ($r['error'] ?? 'failed') . "\n"); exit(1); }
    echo "# {$what}: ok\n";
}
function inst(string $slug): object {
    $r = TenantHost::instance($slug);
    if (!$r['ok']) { fwrite(STDERR, "ERROR {$r['error']}\n"); exit(1); }
    return $r['inst'];
}
function domain(array $o): string {
    $d = strtolower(trim((string) ($o['domain'] ?? '')));
    if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)) { fwrite(STDERR, "ERROR --domain=HOST is required (e.g. myapp.tiknix.com)\n"); exit(2); }
    return $d;
}

if (isset($o['new-app'])) {
    $name = trim((string) ($o['name'] ?? ''));
    if ($name === '') { fwrite(STDERR, "ERROR --name is required\n"); exit(2); }
    done(TenantApp::create((string) $o['new-app'], $name, (int) ($o['member'] ?? 1)), 'new app ' . $o['new-app']);
    exit(0);
}
if (isset($o['create']))    { done(TenantHost::create(inst($o['create'])), 'container for ' . $o['create']); exit(0); }
if (isset($o['provision'])) { done(TenantHost::provision(inst($o['provision']), domain($o)), 'provision ' . $o['provision']); exit(0); }
if (isset($o['publish']))   { done(TenantHost::publish(inst($o['publish']), domain($o)), 'publish ' . $o['publish']); exit(0); }
if (isset($o['up'])) {
    $i = inst($o['up']); $d = domain($o);
    if ((int) $i->ctVmid <= 0) done(TenantHost::create($i), 'container');
    done(TenantHost::provision($i, $d), 'provision');
    done(TenantHost::publish($i, $d), 'publish');
    exit(0);
}
if (isset($o['ssh'])) {
    if (!$argvRest) { fwrite(STDERR, "ERROR give the command after --\n"); exit(2); }
    [$code, $out] = TenantHost::ssh(inst($o['ssh']), isset($o['root']) ? 'root' : 'app', implode(' ', array_map('escapeshellarg', $argvRest)));
    echo $out;
    exit($code);
}
if (isset($o['status'])) {
    $i = inst($o['status']);
    echo "slug {$i->slug}  container " . ((int) $i->ctVmid ?: '-') . "  ip " . ($i->ctIp ?: '-') . "  domain " . ($i->ctDomain ?: '-') . "\n";
    if ((int) $i->ctVmid > 0) {
        [$code, $out] = TenantHost::ssh($i, 'app', 'cd /srv/app && git log --oneline -1 && cat .release 2>/dev/null; php -r \'require "vendor/autoload.php"; echo "runtime ", \app\InstanceUpdate::installedRuntime(getcwd())["version"] ?? "?", "\n";\'', null, 30);
        echo $code === 0 ? $out : "  ssh failed ({$code}): {$out}";
    }
    exit(0);
}
if (isset($o['destroy'])) {
    if (!isset($o['yes'])) { fwrite(STDERR, "ERROR destroying a tenant deletes its container and everything in it; add --yes\n"); exit(2); }
    done(TenantHost::destroy(inst($o['destroy'])), 'destroy ' . $o['destroy']);
    exit(0);
}
fwrite(STDERR, "usage: see the header of scripts/tenant.php\n");
exit(2);
