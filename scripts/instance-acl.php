<?php
/**
 * instance-acl.php — narrow an isolated instance's pool ACL to what the app writes
 * (CONNECTOR-CATALOG-PLAN.md §13 cutover C4, decision 13).
 *
 *   php scripts/instance-acl.php --slug=serenity-bbdc01           one instance
 *   php scripts/instance-acl.php --all                            every isolated instance
 *   php scripts/instance-acl.php --slug=… --check                 report only: where can the pool write?
 *   php scripts/instance-acl.php --all --dry-run
 *
 * Provisioning (capricorn isolate-instance.sh) gave the pool user rwX on the WHOLE tree, so
 * the app could rewrite its own code. Now: the pool READS the tree (r-X, and r-X by default
 * for whatever an update adds) and WRITES only the paths the app is known to write —
 * ownership stays ubuntu's, so git-as-ubuntu (the executor, --update) is untouched, and the
 * core reader keeps rwX. setfacl, never chmod (the mask trap, CLAUDE.md).
 *
 * WHAT STAYS WRITABLE, and why (from what pools have actually written, surveyed 2026-09-28):
 *   data/ database/ secure/       the databases, the connection store, the keys, uploads of digital goods
 *   log/ logs/ cache/ backups/    runtime output
 *   storage/ public/uploads/      files the app stores and serves
 *   .aibuilder/                   task worktrees, agent state, the saved Claude login
 *   conf/                         the settings editor (config.ini + .bak), conf/sites/<slug>.ini, broker.ini
 *   pipelines/ mcptools/          the app's OWN editable definitions: pipeline JSON, MCP tool
 *   scripts/hooks/ .claude/       classes, hook scripts and skills (Pipelines, Mcptools, Hooks,
 *                                 Agentsetup, AgentGuidance controllers write them by design)
 *   root files                    concepts.lock (enable/disable), CLAUDE.md (agent guidance), .mcp.json
 *
 * KNOWN LIMIT: the root directory itself must be writable so those files can be written
 * atomically (temp + rename), and write on a directory allows rename over ANY file in it —
 * bootstrap.php and server.php included. Files INSIDE lib/ controls/ models/ services/
 * scripts/ views/ routes/ vendor/ public/ (bar uploads) and the rest are read-only to the
 * pool: no create, no rename, no edit.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap.php';
new \app\Bootstrap();

use app\Bean;
use app\IsolatedPool;

const WRITABLE_DIRS  = ['data', 'database', 'secure', 'log', 'logs', 'cache', 'backups', 'storage', 'public/uploads', '.aibuilder', 'conf', 'pipelines', 'mcptools', 'scripts/hooks', '.claude'];
const WRITABLE_FILES = ['concepts.lock', 'CLAUDE.md', '.mcp.json'];
const CODE_DIRS      = ['lib', 'controls', 'models', 'services', 'scripts', 'views', 'routes', 'vendor', 'bin', 'tests', 'concepts', 'connectors', 'agent'];

$opt = getopt('', ['slug:', 'all', 'dry-run', 'check', 'help']);
if (isset($opt['help']) || (!isset($opt['slug']) && !isset($opt['all']))) {
    fwrite(STDERR, "usage: php scripts/instance-acl.php --slug=SLUG | --all [--dry-run] [--check]\n");
    exit(2);
}
$dry = isset($opt['dry-run']); $check = isset($opt['check']);

$targets = [];
if (isset($opt['slug'])) {
    $inst = Bean::findOne('instance', 'slug = ?', [(string) $opt['slug']]);
    if (!$inst || !$inst->id) { fwrite(STDERR, "no instance row for slug '{$opt['slug']}'\n"); exit(1); }
    $targets[] = $inst;
} else {
    foreach (Bean::find('instance', "status = 'active' ORDER BY slug") as $inst) $targets[] = $inst;
}

function sh(string $cmd): array { exec($cmd . ' 2>&1', $out, $code); return [(int) $code, $out]; }
/**
 * setfacl over a tree that holds files the POOL created (its logs, its database journals):
 * only a file's owner may change its ACL, so those refuse ubuntu with "Operation not
 * permitted" — and they need no entry, the pool owns them. Any other refusal is a failure.
 * @return string '' when fine, else the first real error
 */
$SKIPPED = [];   // paths whose ACL this user may not change (not the tree owner's): reported, and scanned afterwards
function setfaclTree(string $flags, string $entry, string $path, string $pool): string {
    global $SKIPPED;
    [$c, $o] = sh("setfacl {$flags} " . escapeshellarg($entry) . ' ' . escapeshellarg($path));
    if ($c === 0) return '';
    $me = posix_geteuid();
    foreach ($o as $line) {
        if (!preg_match('/^setfacl: (.+?): (.+)$/', $line, $m)) continue;
        // Only a file's owner may change its ACL. A file the pool or root created (its logs,
        // data/ itself on older provisions) keeps the ACL it has; that is fine under a data
        // directory and is reported under a code directory (see the scan below).
        // "Permission denied": a pool-owned directory closed to others (secure/uploads/…) that this user cannot even enter — the same case.
        if ((str_contains($m[2], 'Operation not permitted') || str_contains($m[2], 'Permission denied')) && @fileowner($m[1]) !== $me) { $SKIPPED[] = $m[1]; continue; }
        return $line;
    }
    return '';
}
function acl(string $path, string $user): string {
    [$c, $o] = sh('getfacl -p ' . escapeshellarg($path));
    foreach ($o as $l) if (preg_match('/^user:' . preg_quote($user, '/') . ':([rwx-]{3})(?:\s+#effective:([rwx-]{3}))?/', $l, $m)) return $m[2] ?? $m[1];
    return '(none)';
}

$failed = 0;
foreach ($targets as $inst) {
    $slug = (string) $inst->slug;
    if (!empty($inst->isDefault)) continue;
    try { $dir = \Model_Instance::dirFrom($slug, (string) ($inst->app ?? '')); } catch (\Throwable $e) { echo "  {$slug}: " . $e->getMessage() . "\n"; $failed++; continue; }
    if (!\Model_Instance::isProvisionedInstance($dir)) { echo "  {$slug}: not a provisioned instance tree — skipped\n"; continue; }
    $pool = IsolatedPool::user($dir);
    if ($pool === '') { echo "  {$slug}: not isolated (no pool user) — nothing to narrow\n"; continue; }

    if ($check) {
        $w = []; $ro = [];
        foreach (CODE_DIRS as $d) {
            if (!is_dir("{$dir}/{$d}")) continue;
            if (str_contains(acl("{$dir}/{$d}", $pool), 'w')) $w[] = $d; else $ro[] = $d;
        }
        $bs = acl("{$dir}/bootstrap.php", $pool);
        echo "  {$slug} ({$pool}): code dirs writable: " . ($w ? implode(', ', $w) : 'none') . "; read-only: " . implode(', ', $ro) . "; bootstrap.php {$bs}; data " . acl("{$dir}/data", $pool) . "\n";
        continue;
    }
    if ($dry) { echo "  {$slug}: would set {$pool} to r-X on {$dir} and rwX on " . implode(' ', WRITABLE_DIRS) . " + " . implode(' ', WRITABLE_FILES) . "\n"; continue; }

    $steps = [];
    // 1. the whole tree readable, and readable-by-default for anything an update adds.
    // A failure here is REMEMBERED, not acted on yet: the grants below must be applied
    // whatever happened, because the pass may already have taken write off the data
    // directories — the first run on Serenity stopped here and left its pool read-only on
    // its own database until the grants were made by hand.
    $treeError = '';
    foreach (['-R -m', '-R -d -m'] as $flags) {
        $err = setfaclTree($flags, "u:{$pool}:r-X", $dir, $pool);
        if ($err !== '' && $treeError === '') $treeError = "setfacl {$flags} — {$err}";
    }
    $steps[] = 'tree r-X' . ($treeError !== '' ? ' (INCOMPLETE)' : '');
    // 2. the app's own directories: create the ones the app expects, then rwX + default rwX
    $made = [];
    foreach (WRITABLE_DIRS as $d) {
        $p = "{$dir}/{$d}";
        if (!is_dir($p)) { if (in_array($d, ['logs', 'backups', 'storage', 'mcptools', '.claude', 'pipelines'], true)) continue; @mkdir($p, 0775, true); $made[] = $d; }
        foreach (['-R -m', '-R -d -m'] as $flags) {
            $err = setfaclTree($flags, "u:{$pool}:rwX", $p, $pool);
            if ($err !== '') { echo "  {$slug}: FAILED setfacl on {$d} — {$err} (continuing with the other directories)\n"; $treeError = $treeError ?: "grant on {$d}: {$err}"; }
        }
    }
    if ($treeError !== '') $failed++;
    $steps[] = 'rwX on ' . implode(' ', WRITABLE_DIRS) . ($made ? ' (created ' . implode(' ', $made) . ')' : '');
    // 3. the root directory (to write the files below atomically) and those files
    sh('setfacl -m ' . escapeshellarg("u:{$pool}:rwx") . ' ' . escapeshellarg($dir));
    foreach (WRITABLE_FILES as $f) if (is_file("{$dir}/{$f}")) sh('setfacl -m ' . escapeshellarg("u:{$pool}:rw-") . ' ' . escapeshellarg("{$dir}/{$f}"));
    $steps[] = 'root dir rwx, ' . implode(' ', WRITABLE_FILES) . ' rw';
    // 4. credentials stay closed to other users (as provisioning left them)
    foreach (['secure', '.aibuilder/state'] as $sub) if (is_dir("{$dir}/{$sub}")) { sh('setfacl -R -m o::--- ' . escapeshellarg("{$dir}/{$sub}")); sh('find ' . escapeshellarg("{$dir}/{$sub}") . ' -type d -exec setfacl -d -m o::--- {} +'); }
    // 5. anything under a CODE directory that this user could not narrow (not its file) and the pool can still write — said, not hidden
    $left = [];
    foreach ($SKIPPED as $p) {
        $rel = substr($p, strlen($dir) + 1);
        foreach (CODE_DIRS as $cd) if (str_starts_with($rel, $cd . '/') && str_contains(acl($p, $pool), 'w')) { $left[] = $rel; break; }
    }
    $SKIPPED = [];
    echo "  {$slug} ({$pool}): " . implode('; ', $steps) . "; now lib " . acl("{$dir}/lib", $pool) . ", data " . acl("{$dir}/data", $pool) . ", bootstrap.php " . acl("{$dir}/bootstrap.php", $pool)
        . ($treeError !== '' ? "\n  {$slug}: FAILED — {$treeError}" : '')
        . ($left ? "\n  {$slug}: STILL WRITABLE under code dirs (not owned by " . posix_getpwuid(posix_geteuid())['name'] . ", cannot narrow from here): " . implode(', ', array_slice($left, 0, 10)) . (count($left) > 10 ? ' …' : '') : '') . "\n";
}
exit($failed ? 1 : 0);
