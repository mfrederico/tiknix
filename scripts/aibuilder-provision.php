<?php
/**
 * aibuilder-provision.php — seed a freshly cloned tiknix instance.
 *
 * Called by capricorn's provision-instance.sh after it clones tiknix into
 * /var/www/html/default/<sub>.tiknix and symlinks vendor/. Our job:
 *
 *   1. Write conf/config.<sub>.ini (the file provision-instance.sh tracks for
 *      rollback) AND conf/config.ini (so the instance app boots on its OWN
 *      sqlite db, not the source tiknix.db), both pointing at database/<sub>.db.
 *   2. Boot the app against that config and run sql/schema.sql (+ workbench)
 *      exactly like database/init.php, producing a fresh, loginable database.
 *   3. Point the seeded admin at the instance owner's email and seed the
 *      aibuilder permission so the in-instance builder is reachable.
 *
 * Usage (from inside the instance dir):
 *   php scripts/aibuilder-provision.php --tenant=<sub> [--admin=email] [--name="Display Name"]
 *
 * --from-mysql is accepted but ignored (tiknix instances are sqlite-only).
 */

$ROOT = dirname(__DIR__);                 // the instance root (cwd is the instance)

// SAFETY: this seeder rewrites conf/config.ini, so it must ONLY run inside a
// provisioned "<sub>.<app>" instance clone — never a source app dir (which would
// clobber the live site's config/db). Instance dirs always contain a dot.
if (strpos(basename($ROOT), '.') === false) {
    fwrite(STDERR, "aibuilder-provision: refusing to run in source app dir '" . basename($ROOT) . "' — run inside a <sub>.<app> instance only\n");
    exit(1);
}

// --- args -------------------------------------------------------------------
$opts = getopt('', ['tenant:', 'admin::', 'name::', 'from-mysql::']);
$sub  = strtolower(trim($opts['tenant'] ?? ''));
$admin = trim($opts['admin'] ?? '');
$name  = trim($opts['name'] ?? '');

if ($sub === '' || !preg_match('/^[a-z][a-z0-9]{1,49}$/', $sub)) {
    fwrite(STDERR, "aibuilder-provision: invalid or missing --tenant slug\n");
    exit(1);
}
if ($admin === '') $admin = "$sub@tiknix.local";
if ($name === '')  $name  = ucfirst($sub);

$dbRel  = "database/$sub.db";
$dbPath = "$ROOT/$dbRel";
$baseUrl = "https://$sub.tiknix.com";

echo "aibuilder-provision: seeding instance '$sub'\n";

// --- 1) write instance config ----------------------------------------------
// Template from the cloned conf/config.ini (provision-instance.sh seeded it from
// the source app). Patch the db path + baseurl + app name to this instance.
$tplPath = "$ROOT/conf/config.ini";
if (!is_file($tplPath)) $tplPath = "$ROOT/conf/config.example.ini";
if (!is_file($tplPath)) {
    fwrite(STDERR, "aibuilder-provision: no conf/config.ini template to seed from\n");
    exit(1);
}
$ini = file_get_contents($tplPath);
// Unquoted on purpose: capricorn's provision-instance.sh greps this value with a
// crude sed that doesn't strip quotes; parse_ini_file reads it fine unquoted.
$ini = preg_replace('/^\s*path\s*=.*$/m',    "path = $dbRel", $ini, 1);
$ini = preg_replace('/^\s*baseurl\s*=.*$/m', "baseurl = \"$baseUrl\"", $ini, 1);
$ini = preg_replace('/^\s*name\s*=\s*".*?"/m', 'name = "' . addslashes($name) . '"', $ini, 1);

$cfgRel = "conf/config.$sub.ini";
file_put_contents("$ROOT/$cfgRel", $ini);   // tracked by provision-instance.sh for rollback
file_put_contents("$ROOT/conf/config.ini", $ini); // what the instance app actually boots on
echo "  wrote $cfgRel + conf/config.ini (db: $dbRel)\n";

// --- 1c) runtime dirs -------------------------------------------------------
// Create the dirs the app writes to at runtime, upfront and owned by whoever
// runs this provisioner (the instance owner). bootstrap.php auto-creates the log
// dir on first request, but if php-fpm gets there first the dir lands with the
// wrong owner and the jailed agent (or vice-versa) can't write. Seeding them here
// with 0775 keeps both writers happy. database/log = where [logging] file points;
// storage = installed.lock + logs; uploads/{secure,public} = the two upload buckets.
foreach (['database/log', 'storage/logs', 'cache', 'log', 'uploads/secure', 'uploads/public'] as $rel) {
    $dir = "$ROOT/$rel";
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @chmod($dir, 0775);
}
echo "  ensured runtime dirs (database/log, storage/logs, cache, log, uploads/{secure,public})\n";

// --- 1b) real per-instance vendor (composer install) ------------------------
// provision-instance.sh symlinks vendor/ to the SOURCE app, but that makes
// composer's __DIR__-relative files-autoload resolve back into the source tree
// (double-including lib/functions.php -> "Cannot redeclare" fatal). Each tenant
// needs its OWN vendor so autoload paths resolve inside the instance, and so the
// tenant can manage its own dependencies. This is the tenant's deploy step.
if (is_link("$ROOT/vendor")) { @unlink("$ROOT/vendor"); }
if (!is_file("$ROOT/vendor/autoload.php")) {
    $composer = trim((string)shell_exec('command -v composer 2>/dev/null'));
    if ($composer === '') { fwrite(STDERR, "aibuilder-provision: composer not found on PATH\n"); exit(1); }
    // Seed the source lock (gitignored, so the clone lacks it) for a reproducible install.
    $srcApp = dirname($ROOT) . '/' . substr(basename($ROOT), strpos(basename($ROOT), '.') + 1);
    if (!is_file("$ROOT/composer.lock") && is_file("$srcApp/composer.lock")) {
        @copy("$srcApp/composer.lock", "$ROOT/composer.lock");
    }
    echo "  composer install…\n";
    $cmd = escapeshellarg($composer) . ' install --no-interaction --no-progress -d ' . escapeshellarg($ROOT) . ' 2>&1';
    $out = []; $code = 0; exec($cmd, $out, $code);
    if ($code !== 0 || !is_file("$ROOT/vendor/autoload.php")) {
        fwrite(STDERR, "aibuilder-provision: composer install failed:\n" . implode("\n", array_slice($out, -15)) . "\n");
        exit(1);
    }
}

// --- 2) set up RedBean + run schema -----------------------------------------
// Standalone RedBean setup (we don't boot the full app — a seeder doesn't need
// the framework, and this keeps the seed independent of app bootstrap order).
require_once "$ROOT/vendor/autoload.php";

use RedBeanPHP\R;

R::setup("sqlite:$dbPath");
R::freeze(false);
R::testConnection() or die("aibuilder-provision: db connection failed\n");

$runSqlFile = function (string $file) {
    if (!is_file($file)) return;
    $sql = file_get_contents($file);
    // Strip full-line "--" comments FIRST. Otherwise a chunk like
    // "-- header\nCREATE TABLE member (...)" would be dropped by a naive
    // leading-"--" skip, silently losing that table.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        try { R::exec($stmt); }
        catch (\Exception $e) {
            if (strpos($e->getMessage(), 'already exists') === false &&
                strpos($e->getMessage(), 'UNIQUE constraint') === false) {
                fwrite(STDERR, "  warn: " . $e->getMessage() . "\n");
            }
        }
    }
};

echo "  running sql/schema.sql…\n";
$runSqlFile("$ROOT/sql/schema.sql");
$runSqlFile("$ROOT/sql/workbench_schema.sql");

// --- 3) point admin at the owner + seed aibuilder permission ----------------
// Raw SQL on purpose: this is a standalone seeder (the app is NOT booted), so
// RedBean FUSE hooks like Model_Authcontrol::after_update() can't run — they
// reach for the app logger/cache and fatal. schema.sql seeds its data the same
// raw way. The in-instance app rebuilds its permission cache on first request.
R::exec('UPDATE member SET email = ?, updated_at = ? WHERE username = ?',
        [$admin, date('Y-m-d H:i:s'), 'admin']);
echo "  admin email → $admin (password: admin123 — change after first login)\n";

// The AI Builder lives inside every instance too; make it reachable at ADMIN.
R::exec('INSERT OR IGNORE INTO authcontrol (control, method, level, description, created_at)
         VALUES (?, ?, ?, ?, ?)',
        ['aibuilder', '*', 50, 'AI Builder', date('Y-m-d H:i:s')]);

R::close();

// --- 4) wire the in-jail MCP servers for the agent --------------------------
// stdio (not HTTP): the jail blocks loopback, so the agent launches these as
// subprocesses. .mcp.json is gitignored, so we write it per provision.
//   - tiknix:     codebase introspection (codebase_map / whatprovides / describe)
//   - playwright: drive a headless browser to test its own layout/design work
@file_put_contents("$ROOT/.mcp.json", json_encode([
    'mcpServers' => [
        'tiknix'     => ['command' => 'php', 'args' => ['mcptools/mcp-stdio.php']],
        'playwright' => ['command' => 'npx', 'args' => ['-y', '@playwright/mcp@latest', '--headless', '--isolated']],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "  wrote .mcp.json (tiknix introspection + playwright browser MCP)\n";

// --- 5) seed Hyperlift deploy files (Dockerfile + entrypoint) ---------------
// So a freshly provisioned instance is publishable to Spaceship Hyperlift (or any
// Dockerfile-based host) with no backfill. These are instance-agnostic; copy them
// from the source app so instances track whatever the core ships. .dockerignore
// keeps secrets (conf/config*.ini) out of the build context; GitHubPublisher also
// strips them from the published snapshot.
$srcApp = dirname($ROOT) . '/' . substr(basename($ROOT), strpos(basename($ROOT), '.') + 1);
foreach (['Dockerfile', '.dockerignore', 'docker/entrypoint.sh'] as $rel) {
    $from = "$srcApp/$rel";
    $to   = "$ROOT/$rel";
    if (!is_file($from)) { fwrite(STDERR, "  warn: source missing $rel — skipped\n"); continue; }
    if (!is_dir(dirname($to))) @mkdir(dirname($to), 0755, true);
    if (@copy($from, $to)) {
        if (substr($rel, -3) === '.sh') @chmod($to, 0755);
    } else {
        fwrite(STDERR, "  warn: failed to copy $rel\n");
    }
}
echo "  seeded Dockerfile + docker/entrypoint.sh + .dockerignore (Hyperlift-ready)\n";

echo "aibuilder-provision: done ($dbRel ready)\n";
