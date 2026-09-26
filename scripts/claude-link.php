#!/usr/bin/env php
<?php
/**
 * claude-link.php — install or refresh an instance's own <root>/bin/claude.
 *
 * bin/claude is a HARD LINK to the operator's claude binary (app\ClaudeBinary explains why it
 * is not a symlink). Run this as the operator, on the host:
 *
 *   php scripts/claude-link.php --root=/var/www/html/default/<slug>.tiknix   one instance
 *   php scripts/claude-link.php --all                                        every *.tiknix instance
 *   php scripts/claude-link.php --all --check                                report only; change nothing
 *
 * Idempotent. Re-run it after claude updates itself to move instances onto the new version —
 * until then they keep running the version they were linked to, which still works.
 * --all touches only directories named <something>.tiknix beside this install.
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

use app\ClaudeBinary;

$o = getopt('', ['root:', 'all', 'check']);
$check = isset($o['check']);

$roots = [];
if (isset($o['root'])) {
    $r = realpath((string) $o['root']);
    if ($r === false || !is_dir($r)) { fwrite(STDERR, "claude-link: --root='{$o['root']}' is not a directory\n"); exit(2); }
    $roots[] = $r;
} elseif (isset($o['all'])) {
    foreach (glob(dirname(__DIR__, 2) . '/*.tiknix', GLOB_ONLYDIR) ?: [] as $d) {
        if (is_link($d)) continue;        // an alias of an instance; the target is in the list by its own name
        if (is_file($d . '/public/index.php')) $roots[] = $d;
    }
} else {
    fwrite(STDERR, "usage: --root=<instance root> | --all   [--check]\n");
    exit(2);
}

$host = ClaudeBinary::hostBinary();
echo 'host claude: ' . ($host ?? 'NOT FOUND') . ($host ? ' (inode ' . fileinode($host) . ")\n" : "\n");
if ($host === null && !$check) { fwrite(STDERR, "claude-link: no claude binary on this host — nothing to link to\n"); exit(1); }

$failed = 0;
foreach ($roots as $root) {
    $before = ClaudeBinary::status($root);
    $stale = $host !== null && $before['inode'] !== null && $before['inode'] !== fileinode($host);
    $line = sprintf('%-52s %-9s', basename($root), $before['state'] . ($stale && $before['state'] === 'hardlink' ? '*' : ''));
    // --all REFRESHES; it does not hand a claude to things that never had one. *.tiknix also
    // matches the sidecars (explorer, workbench, …), which run no agent steps. An instance
    // gets its first bin/claude from provisioning, or from an explicit --root.
    if (isset($o['all']) && $before['state'] === 'missing') {
        echo $line . " skipped — never provisioned with one (use --root to install)\n";
        continue;
    }
    if ($check) {
        echo $line . ' ' . $before['detail'] . ($stale ? '  [not the current host version]' : '') . "\n";
        if ($before['state'] === 'dangling' || (isset($o['root']) && $before['state'] === 'missing')) $failed++;
        continue;
    }
    try {
        $r = ClaudeBinary::link($root, $host);
        echo $line . ' -> ' . $r['action'] . "\n";
    } catch (\RuntimeException $e) {
        echo $line . ' -> FAILED: ' . $e->getMessage() . "\n";
        $failed++;
    }
}
exit($failed > 0 ? 1 : 0);
