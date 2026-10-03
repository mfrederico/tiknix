#!/usr/bin/env php
<?php
/**
 * claude-link.php — install or refresh this install's own <root>/bin/claude.
 *
 * bin/claude is a HARD LINK to the operator's claude binary (app\ClaudeBinary explains why it
 * is not a symlink). Run this as the operator, on the host, for core's own agents (the
 * platform's tooling: QA, the planner's helpers). An app in its container gets its own
 * claude from provisioning (tenant/*.sh), never from here.
 *
 *   php scripts/claude-link.php --root=/var/www/html/default/tiknix
 *   php scripts/claude-link.php --root=… --check        report only; change nothing
 *
 * Idempotent. Re-run it after claude updates itself to move onto the new version — until
 * then the linked version keeps running, which still works.
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

use app\ClaudeBinary;

$o = getopt('', ['root:', 'check']);
$check = isset($o['check']);

$roots = [];
if (isset($o['root'])) {
    $r = realpath((string) $o['root']);
    if ($r === false || !is_dir($r)) { fwrite(STDERR, "claude-link: --root='{$o['root']}' is not a directory\n"); exit(2); }
    $roots[] = $r;
} else {
    fwrite(STDERR, "usage: --root=<install root>   [--check]\n");
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
    if ($check) {
        echo $line . ' ' . $before['detail'] . ($stale ? '  [not the current host version]' : '') . "\n";
        if ($before['state'] === 'dangling' || $before['state'] === 'missing') $failed++;
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
