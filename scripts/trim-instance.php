<?php
/**
 * trim-instance.php — Phase B of SIDECAR-ECOSYSTEM-PLAN: strip control-plane-only tooling
 * from a customer instance so its published repo is just their app + the thin runtime.
 *
 *   php scripts/trim-instance.php <instance-dir>            # dry-run (default): show what'd go
 *   php scripts/trim-instance.php <instance-dir> --apply    # back up + remove
 *
 * SAFE by design: dry-run unless --apply; --apply backs each path up under
 * _upgrade-backups/<slug>.trim.<ts>/ before removing; idempotent (skips what's absent).
 * The DROP list is an explicit allow-to-remove — everything not listed is kept. (The
 * plan's end-state is a keep-manifest; this conservative drop-list is the safe first cut.)
 *
 * NOTE on git: these paths are tracked, so on an EXISTING instance a later `git merge
 * origin/main` would try to re-add them. The intended hook is provisioning-time (a fresh
 * clone, before its first commit) — see aibuilder-provision.php. Running --apply on a live
 * instance is fine for measuring, but pair it with the manifest work before relying on it.
 */

$dir = rtrim((string) ($argv[1] ?? ''), '/');
$apply = in_array('--apply', $argv, true);
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php scripts/trim-instance.php <instance-dir> [--apply]\n");
    exit(1);
}
$slug = preg_replace('/\..*/', '', basename($dir));

// Control-plane-ONLY surfaces an instance never routes to (builder_tools_enabled()=false
// on an instance; the router only instantiates a controller when its route is hit, and
// nav links to these are gated). KEEP: the app, lib/Pipeline/* runtime, controls/Pipeline.php,
// controls/Mcp.php (the instance serves its own /mcp), ConnectionStep + conf/broker.ini.
$DROP = [
    'controls/Aibuilder.php',   'views/aibuilder',      // AI Builder (jailed terminal, plan pipeline)
    'controls/Workbench.php',   'views/workbench',      // Workspace / AI Projects (task board)
    'controls/Mcpconfig.php',                           // MCP registry admin
    'controls/Mcptools.php',                            // MCP registry admin
    'controls/Agentsetup.php',  'views/agentsetup',     // agent config (control-plane)
];
// Owner-confirmed KEEP (never drop): Teams, Firehose, Leads, Security — wanted per-instance.

// PROTECTED — the jailed build agent's SCOPE + the base runtime. The trim REFUSES to remove
// anything under these, even if a DROP entry is mis-edited to overlap. `.mcp.json` +
// `mcptools/` are how the bwrap agent runs the tiknix MCP (introspection/scaffolding);
// lib/Pipeline + controls/Pipeline + controls/Mcp are the execution runtime; broker.ini is
// the store handle; .aibuilder holds the engine config. Removing any of these would kneecap
// the instance or the agent building it — structurally disallowed here.
$PROTECTED = [
    '.mcp.json', 'mcptools', 'lib/Pipeline', 'controls/Pipeline.php', 'controls/Mcp.php',
    'conf/broker.ini', '.aibuilder',
];
$isProtected = function (string $rel) use ($PROTECTED): bool {
    foreach ($PROTECTED as $p) {
        if ($rel === $p || strncmp($rel, rtrim($p, '/') . '/', strlen($p) + 1) === 0) return true;
    }
    return false;
};
// Fail fast if the DROP list ever overlaps PROTECTED (guards against future edits).
foreach ($DROP as $rel) {
    if ($isProtected($rel)) {
        fwrite(STDERR, "REFUSING: drop path '$rel' is PROTECTED (base runtime / bwrap agent scope). Fix the DROP list.\n");
        exit(2);
    }
}

function dirSize(string $p): array {
    if (is_file($p)) return [filesize($p) ?: 0, 1];
    $bytes = 0; $files = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) { $bytes += $f->getSize(); $files++; }
    }
    return [$bytes, $files];
}
function human(int $b): string { return $b >= 1048576 ? round($b/1048576,1).'M' : ($b >= 1024 ? round($b/1024).'K' : $b.'B'); }

echo ($apply ? "TRIM (apply)" : "TRIM (dry-run)") . " — $dir\n";
$ts = date('Ymd-His');
$backupRoot = "/var/www/html/default/_upgrade-backups/$slug.trim.$ts";
$totalB = 0; $totalF = 0; $hit = 0;
foreach ($DROP as $rel) {
    $p = "$dir/$rel";
    if (!file_exists($p)) { continue; }
    [$b, $f] = dirSize($p);
    $totalB += $b; $totalF += $f; $hit++;
    printf("  %-26s %6s  %3d file(s)%s\n", $rel, human($b), $f, $apply ? '  → removing' : '');
    if ($apply) {
        @mkdir(dirname("$backupRoot/$rel"), 0775, true);
        rename($p, "$backupRoot/$rel");
    }
}
echo "  " . str_repeat('-', 44) . "\n";
printf("  %-26s %6s  %3d file(s) across %d path(s)\n", ($apply ? 'removed' : 'would remove'), human($totalB), $totalF, $hit);
if ($apply && $hit) echo "  backup: $backupRoot\n";
if (!$apply) echo "  (dry-run — re-run with --apply to remove)\n";
