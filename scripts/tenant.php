<?php
/**
 * tenant.php — make an app and give it its own container (RUNTIME-SPLIT-MAP.md step 4).
 *
 *   php scripts/tenant.php --new-app=SLUG --name="Display Name" [--member=1]
 *   php scripts/tenant.php --build-template               the tenant image: base.sh + seal.sh → a template
 *   php scripts/tenant.php --create=SLUG                  a linked clone of the newest template
 *   php scripts/tenant.php --provision=SLUG --domain=HOST the app on it (tenant/app.sh)
 *   php scripts/tenant.php --publish=SLUG --domain=HOST   serve HOST from the container
 *   php scripts/tenant.php --up=SLUG --domain=HOST        create + provision + publish
 *   php scripts/tenant.php --ssh=SLUG [--root] -- CMD…    run a command in the tenant (as app)
 *   php scripts/tenant.php --clitool=SLUG -- ARGS…        the app's own clitool, in /srv/app (as app)
 *   php scripts/tenant.php --task=SLUG --id=ID [--agent=NAME] < prompt   the builder: an agent task in the tenant
 *   php scripts/tenant.php --merge=SLUG --id=ID           merge a task branch (then the seeds)
 *   php scripts/tenant.php --discard=SLUG --id=ID         throw a task away
 *   php scripts/tenant.php --plan=SLUG --id=ID --member=N [--agent=NAME] < request   the builder's planner in the tenant
 *        (--task and --plan also take --out=FILE: the JSON result written there as well)
 *   php scripts/tenant.php --workspace=SLUG               the builder's records on core (_workspaces/<slug>),
 *                                                         adopting a host clone's history once
 *   php scripts/tenant.php --domain-add=SLUG --domain=HOST     another domain as its own site in the
 *                                                         container: DNS checked, TLS, the app's
 *                                                         conf/hosts/<HOST>.ini + database, routing
 *   php scripts/tenant.php --domain-remove=SLUG --domain=HOST  stop serving it (its data stays in the app)
 *   php scripts/tenant.php --domains=SLUG
 *   php scripts/tenant.php --renew-certs                  renew custom domains' certificates due within
 *                                                         30 days (this machine's crontab, daily)
 *   php scripts/tenant.php --status=SLUG
 *   php scripts/tenant.php --destroy=SLUG --yes           delete the container, stop serving
 *
 * Carrying a project that lives as a host clone of core (step 5; lib/TenantCarry.php):
 *   php scripts/tenant.php --inventory=SLUG               its own files and the core files it edited
 *   php scripts/tenant.php --carry=SLUG --domain=HOST     seed branch `app`, a container, provision it
 *                                                         from `app`, its data, serve it at HOST (a
 *                                                         staging host: the real domain stays on the clone)
 *   php scripts/tenant.php --carry-data=SLUG --domain=HOST   the data again (config.ini gets HOST)
 *   php scripts/tenant.php --cutover=SLUG --domain=HOST [--aliases=A,B]
 *                                                         databases once more, base URL HOST, HOST
 *                                                         (and aliases) proxied to the tenant
 *   php scripts/tenant.php --rollback=SLUG                the clone serves the domain(s) again
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';
new \app\Bootstrap();

use app\TenantApp;
use app\TenantCarry;
use app\TenantHost;

$argvRest = [];
$dd = array_search('--', $argv, true);
if ($dd !== false) { $argvRest = array_slice($argv, $dd + 1); $argv = array_slice($argv, 0, $dd); $_SERVER['argv'] = $argv; }
$o = getopt('', ['build-template', 'new-app:', 'name:', 'member:', 'create:', 'provision:', 'publish:', 'up:', 'ssh:', 'clitool:', 'task:', 'merge:', 'discard:', 'id:', 'root', 'status:', 'destroy:', 'yes', 'domain:', 'inventory:', 'carry:', 'carry-data:', 'cutover:', 'aliases:', 'rollback:', 'plan:', 'member:', 'out:', 'workspace:', 'agent:', 'domain-add:', 'domain-remove:', 'domains:', 'renew-certs']);

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

if (isset($o['build-template'])) { done(TenantHost::buildTemplate(), 'tenant template'); exit(0); }
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
    // Forward our stdin when something is piped in (a file, a prompt); a terminal is not read.
    $stdin = posix_isatty(STDIN) ? null : (string) stream_get_contents(STDIN);
    [$code, $out] = TenantHost::ssh(inst($o['ssh']), isset($o['root']) ? 'root' : 'app', implode(' ', array_map('escapeshellarg', $argvRest)), $stdin);
    echo $out;
    exit($code);
}
if (isset($o['clitool'])) {
    // The app's CLI in its own home: e2e suites and operators reach a tenant's clitool through this.
    $cmd = 'cd /srv/app && php scripts/clitool.php ' . implode(' ', array_map('escapeshellarg', $argvRest));
    [$code, $out] = TenantHost::ssh(inst($o['clitool']), 'app', $cmd);
    echo $out;
    exit($code);
}
if (isset($o['task']) || isset($o['plan']) || isset($o['merge']) || isset($o['discard'])) {
    $id = (string) ($o['id'] ?? '');
    if ($id === '') { fwrite(STDERR, "ERROR --id=TASK is required\n"); exit(2); }
    if (isset($o['task'])) {
        $prompt = posix_isatty(STDIN) ? '' : (string) stream_get_contents(STDIN);
        $r = TenantHost::task(inst($o['task']), $id, $prompt, 1800, (string) ($o['agent'] ?? ''));
    } elseif (isset($o['plan'])) {
        $request = posix_isatty(STDIN) ? '' : (string) stream_get_contents(STDIN);
        $r = TenantHost::plan(inst($o['plan']), $id, $request, (int) ($o['member'] ?? 0), 1800, (string) ($o['agent'] ?? ''));
    } elseif (isset($o['merge'])) {
        $r = TenantHost::mergeTask(inst($o['merge']), $id);
    } else {
        $r = TenantHost::discardTask(inst($o['discard']), $id);
    }
    $json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    // --out: the result as a file too — a builder session (TenantBuilder::launch) reads it
    // when the session has ended. Written whole, then renamed: a reader never sees half.
    if (isset($o['out'])) {
        $out = (string) $o['out'];
        if (file_put_contents($out . '.tmp', $json) === false || !rename($out . '.tmp', $out)) { fwrite(STDERR, "ERROR could not write {$out}\n"); exit(1); }
    }
    echo $json;
    exit(!empty($r['ok']) ? 0 : 1);
}
if (isset($o['workspace'])) {
    $i = inst($o['workspace']);
    $r = \app\TenantBuilder::adoptHistory($i);
    done($r, 'workspace ' . $i->slug . ' (' . \Model_Instance::dirOf($i) . ')');
    exit(0);
}
// Other domains, each its own site in the project's container (lib/TenantDomains.php).
if (isset($o['domain-add']))    { done(\app\TenantDomains::add(inst($o['domain-add']), domain($o)), 'domain ' . domain($o) . ' for ' . $o['domain-add']); exit(0); }
if (isset($o['domain-remove'])) { done(\app\TenantDomains::remove(inst($o['domain-remove']), domain($o)), 'domain ' . domain($o) . ' removed from ' . $o['domain-remove']); exit(0); }
if (isset($o['renew-certs'])) { echo '[' . date('c') . "]\n"; done(\app\TenantDomains::renewAll(), 'certificate renewal'); exit(0); }
if (isset($o['domains'])) {
    $i = inst($o['domains']);
    echo "main site: {$i->ctDomain}\n";
    foreach (\app\TenantDomains::of($i) as $d) echo "own site:  {$d}\n";
    exit(0);
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
if (isset($o['inventory'])) {
    $inv = TenantCarry::inventory((string) $o['inventory']);
    echo "{$inv['dir']} at {$inv['head']}: " . count($inv['own']) . " own file(s), " . count($inv['edited']) . " edited core file(s), {$inv['core']} core file(s)\n";
    echo "edited core files (to port):\n" . ($inv['edited'] ? '  ' . implode("\n  ", $inv['edited']) : '  (none)') . "\n";
    echo "own files:\n" . ($inv['own'] ? '  ' . implode("\n  ", $inv['own']) : '  (none)') . "\n";
    exit(0);
}
if (isset($o['carry'])) {
    $i = inst($o['carry']); $d = domain($o);
    $origin = \app\InstanceRepo::originPath($i->slug);
    if (TenantApp::sh('git -C ' . escapeshellarg($origin) . ' rev-parse -q --verify refs/heads/' . TenantCarry::BRANCH)[0] !== 0) done(TenantCarry::seed($i), 'seed');
    else echo "  {$origin} already has branch " . TenantCarry::BRANCH . ": not seeded again\n";
    if ((int) $i->ctVmid <= 0) done(TenantHost::create($i), 'container');
    // Staging until the cutover: the host clone is still the app, and its builder works there.
    if ((string) $i->ctKind !== 'tenant') { $i->ctKind = 'staging'; \app\Bean::store($i); }
    done(TenantHost::provision($i, $d, TenantCarry::BRANCH), 'provision');
    done(TenantCarry::data($i, $d), 'data');
    done(TenantHost::publish($i, $d), 'publish');
    exit(0);
}
if (isset($o['carry-data'])) { done(TenantCarry::data(inst($o['carry-data']), domain($o)), 'data'); exit(0); }
if (isset($o['cutover'])) {
    $aliases = array_values(array_filter(array_map('trim', explode(',', (string) ($o['aliases'] ?? '')))));
    foreach ($aliases as $a) if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $a)) { fwrite(STDERR, "ERROR alias '{$a}' is not a host name\n"); exit(2); }
    done(TenantCarry::cutover(inst($o['cutover']), domain($o), $aliases), 'cutover ' . $o['cutover']);
    exit(0);
}
if (isset($o['rollback'])) { done(TenantCarry::rollback(inst($o['rollback'])), 'rollback ' . $o['rollback']); exit(0); }
if (isset($o['destroy'])) {
    if (!isset($o['yes'])) { fwrite(STDERR, "ERROR destroying a tenant deletes its container and everything in it; add --yes\n"); exit(2); }
    done(TenantHost::destroy(inst($o['destroy'])), 'destroy ' . $o['destroy']);
    exit(0);
}
fwrite(STDERR, "usage: see the header of scripts/tenant.php\n");
exit(2);
