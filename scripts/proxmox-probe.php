<?php
/**
 * proxmox-probe.php — prove what the tiknix control-plane token can actually DO on the
 * Proxmox node, so the hosted-deploy design rests on measurements instead of forum posts.
 *
 *   php scripts/proxmox-probe.php                 # read-only: identity, perms, storage, capability discovery
 *   php scripts/proxmox-probe.php --write         # + pull an OCI image, create a CT, probe env + bind mount, destroy
 *   php scripts/proxmox-probe.php --write --keep  # leave the test CT running for manual poking
 *
 * Options:
 *   --vmid=N     test container id (default: /cluster/nextid)
 *   --image=REF  OCI reference to pull (default: docker://ghcr.io/mfrederico/tiknix-base:8.3)
 *   --storage=S  template storage   (default: first storage advertising vztmpl)
 *   --rootfs=S   rootfs storage     (default: first storage advertising rootdir)
 *
 * The three questions --write exists to answer:
 *   Q1  Can a non-root API token pull an OCI image into template storage?
 *   Q2  Are env vars token-settable, or root-only like bind mounts? (decides whether the
 *       deploy path can carry BASE_URL/APP_KEY at all, or must stamp conf/config.ini)
 *   Q3  Does a bind mount really 403 for a token? (decides push-vs-pull deploy outright)
 *
 * SAFETY: refuses to touch PROTECTED or any pre-existing vmid. 101 is the container this
 * control plane is itself running inside — destroying it would take the node's tenant with it.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/../lib/ProxmoxService.php';

use app\ProxmoxService;

/** Container ids this script must never create, modify, or destroy. */
const PROTECTED_VMIDS = [101];

$opt   = getopt('', ['write', 'keep', 'vmid::', 'image::', 'storage::', 'rootfs::']);
$write = isset($opt['write']);
$keep  = isset($opt['keep']);
$image = (string) ($opt['image'] ?? 'docker://ghcr.io/mfrederico/tiknix-base:8.3');

$pass = $fail = $warn = 0;
function h(string $s): void { echo "\n\033[1m== $s\033[0m\n"; }
function ok(string $s): void   { global $pass; $pass++; echo "  \033[32mPASS\033[0m  $s\n"; }
function no(string $s): void   { global $fail; $fail++; echo "  \033[31mFAIL\033[0m  $s\n"; }
function meh(string $s): void  { global $warn; $warn++; echo "  \033[33mWARN\033[0m  $s\n"; }
function info(string $s): void { echo "        $s\n"; }

$pve = ProxmoxService::fromConfig();
if (!$pve) exit("conf/proxmox.ini missing host/tokenid/secret (values must be QUOTED — '!' is reserved in INI)\n");

// ---------------------------------------------------------------- read-only

h('Identity');
$ver = $pve->version();
if (!empty($ver['version'])) ok('PVE ' . $ver['version'] . ' as ' . $pve->tokenId());
else exit("  FAIL  cannot reach the API — check host/token\n");
$node = $pve->node();
$node !== '' ? ok('node: ' . $node) : no('no node visible to this token');
// OCI-as-LXC landed in 9.1; on anything older the --write phase cannot work at all.
version_compare((string) $ver['version'], '9.1', '>=') ? ok('version supports OCI images in LXC (>= 9.1)')
                                                       : no('OCI in LXC needs PVE >= 9.1');

h('Permissions');
$perms = $pve->permissions();
if ($perms === []) {
    no('token has NO effective permissions');
    info('privsep tokens intersect USER acls with TOKEN acls — grant BOTH:');
    info("  pveum acl modify /vms -user 'tiknix@pve' -role TiknixDeploy");
    info("  pveum acl modify /vms -token 'tiknix@pve!deploy' -role TiknixDeploy");
} else {
    foreach ($perms as $path => $privs) info($path . ': ' . implode(', ', array_keys((array) $privs)));
    $need = ['VM.Allocate', 'VM.Config.Disk', 'VM.Config.Network', 'VM.Config.Options', 'VM.PowerMgmt', 'Datastore.AllocateSpace'];
    $have = array_keys((array) ($perms['/vms'] ?? []));
    $miss = array_diff($need, $have);
    $miss === [] ? ok('/vms has every privilege the deploy path needs')
                 : no('/vms missing: ' . implode(', ', $miss));

    // Pulling an image is the NODE reaching out to a registry, so it is gated on
    // Sys.AccessNetwork at /nodes/<node> — not on any Datastore privilege.
    if (isset($perms['/nodes/' . $node]['Sys.AccessNetwork'])) {
        ok('/nodes/' . $node . ' has Sys.AccessNetwork (image pulls allowed)');
    } else {
        no('/nodes/' . $node . ' missing Sys.AccessNetwork — every image pull will 403');
        info('pveum role add TiknixPull -privs "Sys.AccessNetwork,Sys.Audit"');
        info("pveum acl modify /nodes/$node -user 'tiknix@pve' -role TiknixPull");
        info("pveum acl modify /nodes/$node -token 'tiknix@pve!deploy' -role TiknixPull");
    }

    // Attaching a bridge to net0 is checked against the SDN zone, not /vms — a create
    // with every VM.Config.* privilege still 403s without it.
    if (isset($perms['/sdn/zones/localnetwork']['SDN.Use'])) {
        ok('/sdn/zones/localnetwork has SDN.Use (net0 can attach a bridge)');
    } else {
        no('/sdn/zones/localnetwork missing SDN.Use — container create will 403 on net0');
        info('pveum role add TiknixNet -privs "SDN.Use"');
        info("pveum acl modify /sdn/zones/localnetwork -user 'tiknix@pve' -role TiknixNet");
        info("pveum acl modify /sdn/zones/localnetwork -token 'tiknix@pve!deploy' -role TiknixNet");
    }
}

h('Storage');
$tmplStores = $pve->storagesFor($node, 'vztmpl');
$rootStores = $pve->storagesFor($node, 'rootdir');
foreach ($pve->storages($node) as $s) {
    info(sprintf('%-12s %-9s %-24s %s free', $s['storage'], $s['type'], $s['content'],
        isset($s['avail']) ? round($s['avail'] / 1073741824) . ' GB' : '?'));
}
$storage = (string) ($opt['storage'] ?? ($tmplStores[0]['storage'] ?? ''));
$rootfs  = (string) ($opt['rootfs']  ?? ($rootStores[0]['storage'] ?? ''));
$storage !== '' ? ok('template storage: ' . $storage)   : no('no storage advertises vztmpl — OCI images have nowhere to land');
$rootfs  !== '' ? ok('rootfs storage: ' . $rootfs)      : no('no storage advertises rootdir — containers have nowhere to live');

// Capacity is the quiet failure mode: rootfs fills, every later tenant create fails.
foreach ($rootStores as $s) {
    if ((string) $s['storage'] !== $rootfs) continue;
    $freeGb = (int) round(($s['avail'] ?? 0) / 1073741824);
    $freeGb < 32 ? meh('rootfs has ' . $freeGb . ' GB free — only ~' . intdiv($freeGb, 8) . ' more tenants at 8 GB each')
                 : ok('rootfs has ' . $freeGb . ' GB free (~' . intdiv($freeGb, 8) . ' tenants at 8 GB)');
}

h('Capability discovery');
// PVE index responses enumerate child endpoints — the honest way to learn what this
// build supports, rather than inferring from release notes.
$se = $pve->endpoints('/nodes/' . $node . '/storage/' . $storage);
info('storage endpoints: ' . (implode(', ', $se) ?: '(none visible)'));
$hasOci = in_array('oci-registry-pull', $se, true);
$hasDl  = in_array('download-url', $se, true);
if ($hasOci)     ok('oci-registry-pull present — dedicated OCI puller (PVE 9.2+)');
elseif ($hasDl)  meh('no oci-registry-pull; falling back to download-url with a docker:// reference');
else             no('neither oci-registry-pull nor download-url — images must be placed on the node by hand');

// Parameter names for the OCI puller differ across builds; ask the API instead of guessing.
if ($hasOci) {
    $sig = $pve->paramSchema('POST', '/nodes/' . $node . '/storage/' . $storage . '/oci-registry-pull');
    $sig ? info('oci-registry-pull requires: ' . implode(', ', $sig))
         : info('oci-registry-pull signature not enumerable (permission denied before validation)');
}

h('Containers');
$existing = [];
foreach ($pve->containers($node) as $c) {
    $existing[] = (int) $c['vmid'];
    info(sprintf('%-5d %-26s %-8s %s GB', $c['vmid'], $c['name'] ?? '?', $c['status'] ?? '?',
        round(($c['maxdisk'] ?? 0) / 1073741824)));
}
$vmid = (int) ($opt['vmid'] ?? $pve->nextId());
info('test vmid: ' . $vmid);

if (!$write) {
    echo "\nread-only probe complete — $pass passed, $fail failed, $warn warned\n";
    echo "re-run with --write to answer Q1 (OCI pull), Q2 (env settable), Q3 (bind mount 403).\n";
    exit($fail > 0 ? 1 : 0);
}

// ------------------------------------------------------------------- write

h('Safety');
if (in_array($vmid, PROTECTED_VMIDS, true)) exit("  ABORT  vmid $vmid is protected (this control plane runs in it)\n");
if (in_array($vmid, $existing, true))       exit("  ABORT  vmid $vmid already exists — pick an unused --vmid\n");
ok('vmid ' . $vmid . ' is free and unprotected');

h('Q1 — can the token pull an OCI image?');
$ref      = preg_replace('#^docker://#', '', $image);            // oci-registry-pull wants a bare reference
$filename = preg_replace('/[^a-z0-9._-]+/i', '-', $ref) . '.tar';
info('pulling ' . $ref);
info('     as ' . $storage . ':vztmpl/' . $filename);

// The puller refuses to overwrite, so a re-run would "fail" on an image that is
// already there. Existing volume = the pull worked; that is a pass, not a failure.
[$repo, $tag] = array_pad(explode(':', $ref, 2), 2, 'latest');
$expect       = basename($repo) . '_' . $tag . '.tar';
$already      = null;
foreach ($pve->content($node, $storage, 'vztmpl') as $t) {
    if (basename((string) $t['volid']) === $expect) { $already = $t; break; }
}

if ($already) {
    $r = ['ok' => true, 'exit' => 'OK', 'log' => ''];
    info('already present from an earlier pull — not re-fetching');
} elseif ($hasOci) {
    // Map the discovered signature onto what we have, so a rename between builds
    // surfaces as "unmapped params" instead of a silent 400.
    $sig    = $pve->paramSchema('POST', '/nodes/' . $node . '/storage/' . $storage . '/oci-registry-pull');
    $supply = ['reference' => $ref, 'image' => $ref, 'url' => $image, 'filename' => $filename, 'content' => 'vztmpl'];
    $params = array_intersect_key($supply, array_flip($sig ?: ['reference']));
    if ($unmapped = array_diff($sig, array_keys($supply))) {
        meh('oci-registry-pull wants params this probe cannot supply: ' . implode(', ', $unmapped));
    }
    info('POST oci-registry-pull ' . json_encode($params));
    $r = $pve->ociRegistryPull($node, $storage, $params);
} else {
    $r = $pve->downloadUrl($node, $storage, $image, $filename);
}

$ostemplate = $storage . ':vztmpl/' . $filename;
if ($r['ok']) {
    ok('Q1 YES — a non-root token can pull an OCI image');
    // oci-registry-pull names the volume ITSELF, by its own convention
    // (ghcr.io/mfrederico/tiknix-base:8.3 -> tiknix-base_8.3.tar), so never assume
    // the filename we passed. Resolve it: preferred name first, newest vztmpl second.
    $newest = null;
    foreach ($pve->content($node, $storage, 'vztmpl') as $t) {
        if (basename((string) $t['volid']) === $expect) { $newest = $t; break; }
        if ($newest === null || ($t['ctime'] ?? 0) > ($newest['ctime'] ?? 0)) $newest = $t;
    }
    if ($newest) {
        $ostemplate = (string) $newest['volid'];
        info('landed as ' . $ostemplate . ' (' . round(($newest['size'] ?? 0) / 1048576) . ' MB)');
    } else {
        meh('pull reported success but no vztmpl volume is listed');
    }
} else {
    no('Q1 NO — pull failed: ' . $r['exit']);
    if ($r['log'] !== '') info(str_replace("\n", "\n        ", $r['log']));
    info('if this is an auth error, the GHCR package must be public — see the Dockerfile header');
    // Fall back to any template already on the node so Q2/Q3 can still be answered.
    foreach ($pve->content($node, $storage, 'vztmpl') as $t) { $ostemplate = (string) $t['volid']; break; }
    info('falling back to ' . $ostemplate . ' so Q2/Q3 can still be measured');
}

h('Q2/Q3 — create the test container');
$created = $pve->createCt($node, $vmid, $ostemplate, [
    'hostname'     => 'tiknix-probe',
    'cores'        => 1,
    'memory'       => 512,
    'swap'         => 0,
    'rootfs'       => $rootfs . ':8',
    // Storage-backed mount point — the tenant-data shape the deploy path would use.
    'mp0'          => $rootfs . ':1,mp=/var/www/html/database',
    'net0'         => 'name=eth0,bridge=vmbr0,ip=dhcp',
    'unprivileged' => 1,
    'onboot'       => 0,
    'start'        => 0,
]);
if (!$created['ok']) {
    no('create failed: ' . $created['exit']);
    if ($created['log'] !== '') info(str_replace("\n", "\n        ", $created['log']));
    echo "\ncannot continue without a container — $pass passed, $fail failed, $warn warned\n";
    exit(1);
}
ok('created CT ' . $vmid . ' (rootfs ' . $rootfs . ':8 + storage-backed mp0)');

h('Q2 — are env vars token-settable?');
// The key is `env` (singular, space-separated KEY=VALUE pairs) — NOT env0/envN. The OCI
// import pre-populates it from the image's ENV, so APPEND rather than replace or the
// image's own PATH/PHP_VERSION/APACHE_DOCUMENT_ROOT are lost.
$before = (string) ($pve->ctConfig($node, $vmid)['env'] ?? '');
info('image supplied ' . count(array_filter(explode(' ', $before))) . ' env vars of its own');
$env = $pve->setCtConfig($node, $vmid, ['env' => trim($before . ' BASE_URL=https://probe.invalid')]);
if ($env['error'] === '') {
    $after = (string) ($pve->ctConfig($node, $vmid)['env'] ?? '');
    if (str_contains($after, 'BASE_URL=https://probe.invalid')) {
        ok('Q2 YES — `env` is token-settable, no root needed');
        str_contains($after, 'PHP_VERSION=') ? info("the image's own ENV survived the append")
                                             : meh("the image's ENV was clobbered — append, do not replace");
    } else {
        meh('accepted but absent on readback — this build may ignore `env`');
    }
} else {
    no('Q2 NO — ' . $env['error']);
    info(str_contains(strtolower($env['error']), 'root')
        ? 'root-only, like bind mounts: design env OUT and stamp conf/config.ini instead'
        : 'not root-gated — likely a key name this build does not accept');
}

h('Q3 — does a bind mount really 403?');
$bind = $pve->setCtConfig($node, $vmid, ['mp1' => '/var/www/html/hosted,mp=/var/www/html/hosted']);
if ($bind['error'] !== '') {
    ok('Q3 CONFIRMED — bind mount rejected: ' . $bind['error']);
    info('=> no push-from-control-plane deploy; the in-CT git puller is the required design');
} else {
    meh('Q3 UNEXPECTED — the token set a bind mount; push-based deploy may be viable after all');
    $pve->setCtConfig($node, $vmid, ['delete' => 'mp1']);
}

h('Cleanup');
if ($keep) {
    meh('--keep set: CT ' . $vmid . ' left in place. Remove with:');
    info('php scripts/proxmox-probe.php --destroy-vmid=' . $vmid . '   (or pct destroy ' . $vmid . ' --purge on the node)');
} else {
    $d = $pve->destroyCt($node, $vmid, true);
    $d['ok'] ? ok('destroyed CT ' . $vmid) : no('destroy failed: ' . $d['exit'] . ' — clean up by hand');
}

echo "\nprobe complete — $pass passed, $fail failed, $warn warned\n";
exit($fail > 0 ? 1 : 0);
