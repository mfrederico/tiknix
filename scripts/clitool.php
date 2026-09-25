#!/usr/bin/env php
<?php
/**
 * clitool.php — tiknix operations & introspection CLI.
 *
 * One getopt-driven tool for the things you otherwise poke at the database for:
 * schema seeding, table/bean introspection, ad-hoc queries, and member/2FA
 * management. Boots through Bootstrap, so it honours DB_DSN exactly like the app
 * (SQLite locally, MySQL/Postgres on a deploy) and uses RedBean bean operations
 * throughout — no raw CRUD SQL.
 *
 * Modelled on ../dealeryes/scripts/clitool.php, adapted to tiknix conventions:
 *   - `password` column (not password_hash)
 *   - levels ROOT=1, ADMIN=50, MEMBER=100, PUBLIC=101
 *   - services/Schema/Seeds via WorkspaceSchemaBuilder for --build
 *
 * Usage:
 *   php scripts/clitool.php --help
 *
 * Schema / DB:
 *   php scripts/clitool.php --build [--fresh]
 *   php scripts/clitool.php --list
 *   php scripts/clitool.php --describe=member
 *   php scripts/clitool.php --sql="SELECT id,email,level FROM member"
 *   php scripts/clitool.php --exec="UPDATE member SET status='active' WHERE id=1" --yes
 *
 * Members:
 *   php scripts/clitool.php --list-users
 *   php scripts/clitool.php --adduser=me@example.com --password=secret --level=1 [--username=me]
 *   php scripts/clitool.php --user=me@example.com --set-password=newsecret
 *   php scripts/clitool.php --user=me@example.com --set-level=50
 *   php scripts/clitool.php --user=me@example.com --reset-2fa
 *   php scripts/clitool.php --user=me@example.com --status=active
 *
 * Beans (generic, read-mostly):
 *   php scripts/clitool.php --bean=member --getall [--limit=20] [--order="id DESC"]
 *   php scripts/clitool.php --bean=member --findone --where="email = ?" --data=me@example.com
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

chdir(dirname(__DIR__));
require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;
use app\Bean;
use app\services\Schema\WorkspaceSchemaBuilder;

$longopts = [
    'help', 'verbose', 'yes', 'dry-run',
    // schema / db
    'build', 'fresh', 'list', 'describe:', 'sql:', 'exec:',
    // scaffold (code generation)
    'wizard', 'scaffold:',
    // i18n
    'i18n-scan',
    // concepts (COMPONENTS_PLAN.md)
    'concepts', 'concept-verify:', 'concept-enable:', 'concept-disable:', 'concept-lock', 'rehash', 'force', 'agent-sync',
    'concept-search::', 'concept-lint:', 'concept-publish:', 'concept-install:', 'from:', 'origin:', 'forbid:',
    // members
    'list-users', 'user:', 'adduser:', 'username:', 'password:', 'level:',
    'status:', 'set-password:', 'set-level:', 'reset-2fa', 'delete-user',
    // beans
    'bean:', 'getall', 'findone', 'find', 'where:', 'data:', 'limit:', 'order:',
];
$opt = getopt('', $longopts);

$VERBOSE = isset($opt['verbose']);
$DRYRUN  = isset($opt['dry-run']);
$YES     = isset($opt['yes']);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function err(string $s): void { fwrite(STDERR, $s . "\n"); }
function bail(string $s): void { err("error: $s"); exit(1); }

/**
 * The live PDO handle behind RedBean's current connection.
 *
 * Ad-hoc SQL must NOT go through Bean::getAll/exec. In fluid mode RedBean swallows
 * "no such column" and returns an empty result, because it assumes it is mid-schema-build
 * and the column will exist shortly. For a schema builder that is right. For a query you
 * typed, it means a wrong column name reports "(0 rows)" and exit 0 — indistinguishable
 * from a table that is genuinely empty, so a broken query reads as evidence about the data.
 * (Cost so far: two wrong conclusions about a permission seed that had been working.)
 *
 * Freezing the ORM would also surface the error, but it is the wrong lever: freeze state is
 * global and set from config, so toggling it here would clobber whatever the environment
 * chose. Going straight to PDO changes nothing outside this one statement, and PDO is
 * already in ERRMODE_EXCEPTION.
 *
 * Bean:: remains correct for every other command in this file: those go through the ORM
 * ON PURPOSE, so FUSE models and hooks run.
 */
function rawPdo(): \PDO {
    return Bean::getDatabaseAdapter()->getDatabase()->getPDO();
}

/**
 * A SQL failure, plus the columns that DO exist when the driver names a missing one.
 *
 * "no such column: controller" is already better than a silent (0 rows), but the next
 * thing anyone does is run --describe to find the real name. Print it here instead: the
 * answer is one PRAGMA away, and guessing a second time costs another round trip.
 *
 * Best-effort by design — this runs while reporting an error, so if the table name cannot
 * be parsed out or the PRAGMA fails, return the driver's message alone rather than
 * replacing a real error with a failure to explain it.
 */
function sqlErrorHint(\Throwable $e, string $sql): string {
    $msg = $e->getMessage();
    if (!preg_match('/no such column:\s*([A-Za-z0-9_.]+)/i', $msg, $col)) return $msg;

    // FROM/UPDATE/INTO <table> — the first table named is the one worth describing.
    if (!preg_match('/\b(?:from|update|into)\s+["`\[]?([A-Za-z0-9_]+)/i', $sql, $tbl)) return $msg;

    try {
        $cols = Bean::getCol('SELECT name FROM pragma_table_info(?)', [$tbl[1]]);
    } catch (\Throwable $ignored) {
        return $msg;
    }
    if (!$cols) return $msg;

    $wanted = strtolower($col[1]);
    $near   = array_values(array_filter($cols, function ($c) use ($wanted) {
        $c = strtolower($c);
        return str_contains($c, $wanted) || str_contains($wanted, $c);
    }));

    return $msg . "\n"
        . '  ' . $tbl[1] . ' has: ' . implode(', ', $cols)
        . ($near ? "\n  did you mean: " . implode(', ', $near) : '');
}

/** Resolve a member by id / email / username. */
function findMember(string $ident): ?object {
    if (ctype_digit($ident)) {
        $m = Bean::load('member', (int)$ident);
        if ($m && $m->id) return $m;
    }
    $m = Bean::findOne('member', 'email = ? OR username = ?', [$ident, $ident]);
    return ($m && $m->id) ? $m : null;
}

function levelLabel(int $level): string {
    return [1 => 'ROOT', 50 => 'ADMIN', 100 => 'MEMBER', 101 => 'PUBLIC'][$level] ?? "L{$level}";
}

// --- Boot -------------------------------------------------------------------
$app = new \app\Bootstrap(); // connects (DB_DSN-aware)
if (!R::testConnection()) bail('database connection failed');
$dbType = Bean::getDatabaseAdapter()->getDatabase()->getDatabaseType();
if ($VERBOSE) out("# connected ({$dbType})");

if (empty($opt) || isset($opt['help'])) { showHelp(); exit(0); }

// --- Schema: --build [--fresh] ---------------------------------------------
if (isset($opt['build'])) {
    if (isset($opt['fresh'])) {
        if (!$YES && !$DRYRUN) bail('--fresh drops ALL tables. Re-run with --yes to confirm.');
        out('# --fresh: dropping existing tables');
        if (!$DRYRUN) foreach (Bean::inspect() as $t) {
            try { Bean::exec('DROP TABLE IF EXISTS ' . $t); out("  dropped {$t}"); }
            catch (\Exception $e) { err("  warn dropping {$t}: " . $e->getMessage()); }
        }
    }
    if ($DRYRUN) { out('# dry-run: would run seeds in services/Schema/Seeds/'); exit(0); }
    out('# running seeds…');
    foreach ((new WorkspaceSchemaBuilder())->build() as $file => $status) out("  {$file}: {$status}");
    if (class_exists('\app\PermissionCache')) { \app\PermissionCache::clear(); out('# permission cache cleared'); }
    exit(0);
}

// --- Agent guidance: --agent-sync (also run by --concept-enable / --concept-disable) ---
// CLAUDE.md is generated: core's agent/guidelines/ sections plus each enabled concept's
// guidelines.md, in one managed block. Whatever is above the block is left alone.
function agentSync(): void {
    $r = \app\Concepts::instance()->syncGuidance();
    foreach ($r['notes'] as $n) err("warning: {$n}");
    out('# ' . \app\AgentGuidance::FILE . ': ' . ($r['changed'] ? ($r['migrated'] ? 'migrated to the managed block' : 'regenerated') : 'unchanged')
        . ' (' . count(\app\Concepts::instance()->enabled()) . ' enabled concept(s))');
    foreach ($r['skills']['installed'] as $k) out("# skill installed: " . \app\AgentGuidance::SKILLS_DIR . "/{$k}");
    foreach ($r['skills']['removed'] as $k)   out("# skill removed: " . \app\AgentGuidance::SKILLS_DIR . "/{$k}");
}
if (isset($opt['agent-sync'])) {
    if ($DRYRUN) {
        $enabled = [];
        foreach (\app\Concepts::instance()->enabled() as $name => $m) $enabled[$name] = ['version' => $m->version, 'dir' => $m->dir];
        $c = \app\AgentGuidance::compose(dirname(__DIR__), $enabled);
        $cur = (string) @file_get_contents(dirname(__DIR__) . '/' . \app\AgentGuidance::FILE);
        out('# dry-run: ' . \app\AgentGuidance::FILE . ' would be ' . ($cur === $c['text'] ? 'unchanged' : 'rewritten') . ' (' . count($enabled) . ' enabled concept(s))');
        exit(0);
    }
    try { agentSync(); } catch (\RuntimeException $e) { bail($e->getMessage()); }
    exit(0);
}

// --- Concepts: --concepts | --concept-verify | --concept-enable | --concept-disable ---
// Switching a concept on or off is an operator action, from here. INSTALLING one (putting
// its directory in concepts/) is a build task — worktree, review, merge — never a web
// action: the instance pool user can write controls/, so a web "install" would be the web
// process writing executable PHP into its own tree.
if (isset($opt['concepts'])) {
    $scan = \app\Concepts::instance()->scan();
    if (!$scan) { out('(no concepts installed — ' . \app\Concepts::DIR . '/ is empty or absent)'); exit(0); }
    out(sprintf('%-20s %-9s %-10s %-7s %s', 'CONCEPT', 'STATE', 'VERSION', 'FILES', 'TITLE / ERROR'));
    out(str_repeat('-', 86));
    foreach ($scan as $name => $row) {
        $m = $row['manifest'];
        out(sprintf('%-20s %-9s %-10s %-7s %s', $name,
            $m === null ? 'BROKEN' : ($row['enabled'] ? 'enabled' : 'disabled'),
            $m->version ?? '-', $row['modified'] ? 'EDITED' : 'as-is',
            $m === null ? $row['error'] : ($m->title !== '' ? $m->title : $m->blurb)));
    }
    exit(0);
}
// --concept-lock: write concepts.lock from what is on disk. The one-time migration from the
// settings-table flags (which this file replaces) and the repair when a directory was added
// or removed by hand. --rehash accepts the current files as each concept's baseline.
if (isset($opt['concept-lock'])) {
    $flags = \app\ConceptLock::exists(dirname(__DIR__)) ? [] : \app\Feature::installKeys(\app\Concepts::FLAG_PREFIX);
    try {
        $r = \app\ConceptLock::sync(dirname(__DIR__), $flags, isset($opt['rehash']));
    } catch (\app\ConceptException $e) {
        bail($e->getMessage());
    }
    out("# {$r['file']}");
    foreach ($r['added'] as $n) out("  + {$n}" . (in_array($n, $flags, true) ? ' (enabled, from the settings flag)' : ' (disabled)'));
    foreach ($r['kept'] as $n) out("  · {$n}");
    foreach ($r['removed'] as $n) out("  - {$n} (directory gone)");
    exit(0);
}
if (isset($opt['concept-verify'])) {
    $name = (string) $opt['concept-verify'];
    $problems = \app\Concepts::instance()->verify($name);
    if (!$problems) { out("# concept '{$name}': ready to enable"); exit(0); }
    err("concept '{$name}' has " . count($problems) . ' problem(s):');
    foreach ($problems as $p) err("  - {$p}");
    exit(1);
}
if (isset($opt['concept-enable'])) {
    $name = (string) $opt['concept-enable'];
    if ($DRYRUN) {
        $problems = \app\Concepts::instance()->verify($name);
        out($problems ? "# dry-run: would REFUSE —\n  - " . implode("\n  - ", $problems)
                      : "# dry-run: would run concepts/{$name}/seeds, then enable '{$name}'");
        exit($problems ? 1 : 0);
    }
    try {
        foreach (\app\Concepts::instance()->enable($name) as $file => $status) out("  {$file}: {$status}");
    } catch (\app\ConceptException $e) {
        bail($e->getMessage());
    }
    if (class_exists('\app\PermissionCache')) { \app\PermissionCache::clear(); out('# permission cache cleared'); }
    try { agentSync(); } catch (\RuntimeException $e) { err("warning: agent guidance not synced — " . $e->getMessage()); }
    out("# concept '{$name}' enabled");
    exit(0);
}
if (isset($opt['concept-disable'])) {
    $name = (string) $opt['concept-disable'];
    if ($DRYRUN) { out("# dry-run: would disable '{$name}'" . (isset($opt['force']) ? ' (forced)' : '')); exit(0); }
    try {
        \app\Concepts::instance()->disable($name, isset($opt['force']));
    } catch (\app\ConceptException $e) {
        bail($e->getMessage());
    }
    try { agentSync(); } catch (\RuntimeException $e) { err("warning: agent guidance not synced — " . $e->getMessage()); }
    out("# concept '{$name}' disabled — its tables and rows are kept");
    exit(0);
}

// --- Concept catalog: --concept-search | --concept-lint | --concept-publish | --concept-install
/**
 * Strings that identify the install a concept was extracted FROM, so the lint can refuse a
 * concept that still carries them: the origin's slug, its [app] name, its hostname, plus
 * anything passed as --forbid=a,b.
 *
 * --origin=INSTALL_ROOT names that install. It is separate from --from because extraction
 * happens in a worktree, whose directory is a task id and says nothing about the client; the
 * origin is the INSTANCE the code came out of. With no --origin, --from is assumed to be it.
 * A concept authored here, with neither, has no foreign origin to leak.
 *
 * @return string[]
 */
function conceptOriginStrings(?string $fromRoot, array $opt): array {
    $forbid = isset($opt['forbid']) ? array_map('trim', explode(',', (string) $opt['forbid'])) : [];
    $origin = $fromRoot;
    if (isset($opt['origin'])) {
        $origin = realpath((string) $opt['origin']);
        if ($origin === false || !is_file("{$origin}/conf/config.ini")) {
            bail("--origin='{$opt['origin']}' is not an install root (no conf/config.ini there).");
        }
    }
    if ($origin !== null) {
        $forbid[] = preg_replace('/\.tiknix$/', '', basename($origin));
        $ini = is_file("{$origin}/conf/config.ini") ? (parse_ini_file("{$origin}/conf/config.ini", true) ?: []) : [];
        $forbid[] = (string) ($ini['app']['name'] ?? '');
        $forbid[] = (string) (parse_url((string) ($ini['app']['baseurl'] ?? ''), PHP_URL_HOST) ?: '');
    }
    return array_values(array_unique(array_filter($forbid, fn($s) => strlen($s) >= 3)));
}
/** --from=DIR is an install root (the directory that HOLDS concepts/). Absent = this install. */
function conceptFromRoot(array $opt): ?string {
    if (!isset($opt['from'])) return null;
    $root = realpath((string) $opt['from']);
    if ($root === false || !is_dir($root)) bail("--from='{$opt['from']}' is not a directory.");
    return $root;
}
/**
 * Where NAME's source is under a --from root (or this install): ROOT/concepts/NAME, or — for an
 * authoring tree like concepts-src/ — ROOT/NAME when it holds a concept.json.
 */
function conceptSourceDir(?string $from, string $name): string {
    $root = $from ?? dirname(__DIR__);
    $installed = $root . '/' . \app\Concepts::DIR . '/' . $name;
    if (!is_dir($installed) && is_file($root . '/' . $name . '/' . \app\ConceptManifest::FILE)) {
        return $root . '/' . $name;
    }
    return $installed;
}
function printConceptFindings(array $findings): void {
    foreach ($findings as $f) {
        $at = $f['file'] . ($f['line'] ? ':' . $f['line'] : '');
        out(sprintf('  %-5s %s — %s', strtoupper($f['severity']), $at, $f['message']));
    }
}
if (isset($opt['concept-search'])) {
    try {
        $found = \app\ConceptCatalog::forInstall()->search((string) ($opt['concept-search'] ?: ''), (int) ($opt['limit'] ?? 10));
    } catch (\app\ConceptException $e) {
        bail($e->getMessage());
    }
    out("# catalog: {$found['source']}");
    if (!$found['results']) out('(no concept matched — the catalog was reached)');
    foreach ($found['results'] as $r) {
        out(sprintf('%4d  %-16s v%-8s %s', $r['score'], $r['name'], $r['version'], $r['title'] !== '' ? $r['title'] : $r['blurb']));
    }
    foreach ($found['broken'] as $name => $why) err("BROKEN catalog entry {$name}: {$why}");
    exit(0);
}
if (isset($opt['concept-lint'])) {
    $name = (string) $opt['concept-lint'];
    $from = conceptFromRoot($opt);
    $dir = conceptSourceDir($from, $name);
    $forbid = conceptOriginStrings($from, $opt);
    if ($forbid) out('# origin strings that must not appear: ' . implode(', ', $forbid));
    $findings = \app\ConceptLint::check($dir, $forbid);
    if (!$findings) { out("# concept '{$name}': clean"); exit(0); }
    printConceptFindings($findings);
    exit(\app\ConceptLint::hasErrors($findings) ? 1 : 0);
}
if (isset($opt['concept-publish'])) {
    $name = (string) $opt['concept-publish'];
    $from = conceptFromRoot($opt);
    $dir = conceptSourceDir($from, $name);
    $forbid = conceptOriginStrings($from, $opt);
    if ($DRYRUN) {
        $findings = \app\ConceptLint::check($dir, $forbid);
        printConceptFindings($findings);
        out(\app\ConceptLint::hasErrors($findings) ? '# dry-run: would REFUSE to publish' : "# dry-run: would publish '{$name}'");
        exit(\app\ConceptLint::hasErrors($findings) ? 1 : 0);
    }
    try {
        $r = \app\ConceptCatalog::forInstall()->publish($dir, $forbid);
    } catch (\app\ConceptException $e) {
        bail($e->getMessage());
    }
    printConceptFindings($r['findings']);
    out("# concept '{$r['name']}' v{$r['version']}: {$r['status']} ({$r['files']} files)");
    exit(0);
}
if (isset($opt['concept-install'])) {
    $name = (string) $opt['concept-install'];
    if ($DRYRUN) { out("# dry-run: would copy '{$name}' from the catalog into " . \app\Concepts::DIR . "/{$name}/ (it would NOT be enabled)"); exit(0); }
    try {
        $r = \app\ConceptCatalog::forInstall()->install($name, dirname(__DIR__));
    } catch (\app\ConceptException $e) {
        bail($e->getMessage());
    }
    out("# installed '{$r['name']}' v{$r['version']} → {$r['dir']} ({$r['files']} files)");
    out("# it is NOT enabled. Next: --concept-verify={$name}, then --concept-enable={$name}");
    exit(0);
}

// --- Introspection: --list --------------------------------------------------
if (isset($opt['list'])) {
    $tables = Bean::inspect();
    if (!$tables) { out('(no tables)'); exit(0); }
    out(sprintf('%-30s %8s', 'TABLE', 'ROWS'));
    out(str_repeat('-', 39));
    foreach ($tables as $t) {
        $n = Bean::count($t);
        out(sprintf('%-30s %8d', $t, $n));
    }
    exit(0);
}

// --- Introspection: --describe=TABLE ---------------------------------------
if (isset($opt['describe'])) {
    $table = $opt['describe'];
    $cols = Bean::inspect($table);
    if (!$cols) bail("table '{$table}' not found (or empty)");
    out("TABLE {$table}");
    out(str_repeat('-', 40));
    foreach ($cols as $name => $type) out(sprintf('  %-24s %s', $name, $type));
    exit(0);
}

// --- Ad-hoc read: --sql -----------------------------------------------------
if (isset($opt['sql'])) {
    // Raw PDO, deliberately not Bean::getAll — see rawPdo().
    try {
        $rows = rawPdo()->query($opt['sql'])->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        bail(sqlErrorHint($e, $opt['sql']));
    }
    if (!$rows) { out('(0 rows)'); exit(0); }
    $headers = array_keys($rows[0]);
    out(implode("\t", $headers));
    foreach ($rows as $r) out(implode("\t", array_map(fn($v) => (string)$v, $r)));
    out('# ' . count($rows) . ' row(s)');
    exit(0);
}

// --- Ad-hoc write: --exec (guarded) ----------------------------------------
if (isset($opt['exec'])) {
    if (!$YES && !$DRYRUN) bail('--exec runs a write query. Re-run with --yes to confirm.');
    if ($DRYRUN) { out('# dry-run: would exec: ' . $opt['exec']); exit(0); }
    // Same reasoning as --sql, and it matters more here: an UPDATE naming a column that
    // does not exist reported "ok (0 row(s) affected)", which reads as "nothing matched
    // the WHERE clause" rather than "this statement was never valid".
    try {
        $affected = rawPdo()->exec($opt['exec']);
    } catch (\Throwable $e) {
        bail(sqlErrorHint($e, $opt['exec']));
    }
    out("# ok ({$affected} row(s) affected)");
    exit(0);
}

// --- Members: --list-users --------------------------------------------------
if (isset($opt['list-users'])) {
    $members = Bean::findAll('member', ' ORDER BY level ASC, id ASC ');
    if (!$members) { out('(no members)'); exit(0); }
    out(sprintf('%-4s %-24s %-16s %-8s %-8s %-4s', 'ID', 'EMAIL', 'USERNAME', 'LEVEL', 'STATUS', '2FA'));
    out(str_repeat('-', 70));
    foreach ($members as $m) {
        out(sprintf('%-4d %-24s %-16s %-8s %-8s %-4s',
            $m->id, (string)$m->email, (string)$m->username,
            levelLabel((int)$m->level), (string)$m->status,
            !empty($m->totpEnabled) ? 'on' : '-'));
    }
    exit(0);
}

// --- Members: --adduser=EMAIL ----------------------------------------------
if (isset($opt['adduser'])) {
    $email = trim($opt['adduser']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) bail("invalid email: {$email}");
    $pass  = (string)($opt['password'] ?? '');
    if (strlen($pass) < 8) bail('--password is required and must be at least 8 characters');
    $level = isset($opt['level']) ? (int)$opt['level'] : 100;
    $username = trim((string)($opt['username'] ?? explode('@', $email)[0]));

    $existing = Bean::findOne('member', 'email = ?', [$email]);
    if ($existing && $existing->id) bail("member already exists (id {$existing->id}); use --user={$email} --set-password / --set-level");

    if ($DRYRUN) { out("# dry-run: would create {$email} ({$username}) level " . levelLabel($level)); exit(0); }

    $m = Bean::dispense('member');
    $m->email      = $email;
    $m->username   = $username;
    $m->password   = password_hash($pass, PASSWORD_DEFAULT);
    $m->level      = $level;
    $m->status     = 'active';
    $m->loginCount = 0;
    $m->createdAt  = date('Y-m-d H:i:s');
    $m->updatedAt  = date('Y-m-d H:i:s');
    Bean::store($m);
    if (class_exists('\app\PermissionCache')) \app\PermissionCache::clear();
    out("# created member id {$m->id}: {$email} ({$username}) level " . levelLabel($level));
    exit(0);
}

// --- Members: --user=IDENT with an action -----------------------------------
if (isset($opt['user'])) {
    $m = findMember($opt['user']);
    if (!$m) bail("member not found: {$opt['user']}");
    $changed = false;

    if (isset($opt['set-password'])) {
        $pass = (string)$opt['set-password'];
        if (strlen($pass) < 8) bail('--set-password must be at least 8 characters');
        if (!$DRYRUN) { $m->password = password_hash($pass, PASSWORD_DEFAULT); }
        out("# " . ($DRYRUN ? 'would set' : 'set') . " password for {$m->email}");
        $changed = true;
    }
    if (isset($opt['set-level'])) {
        $level = (int)$opt['set-level'];
        if (!$DRYRUN) $m->level = $level;
        out("# " . ($DRYRUN ? 'would set' : 'set') . " level for {$m->email} -> " . levelLabel($level));
        $changed = true;
    }
    if (isset($opt['status'])) {
        if (!$DRYRUN) $m->status = $opt['status'];
        out("# " . ($DRYRUN ? 'would set' : 'set') . " status for {$m->email} -> {$opt['status']}");
        $changed = true;
    }
    if (isset($opt['reset-2fa'])) {
        // Mirror TwoFactorAuth::disable() column-wise (CLI-safe: no cookie/session).
        if (!$DRYRUN) { $m->totpSecret = null; $m->totpEnabled = 0; $m->totpEnabledAt = null; $m->recoveryCodes = null; }
        out("# " . ($DRYRUN ? 'would reset' : 'reset') . " 2FA for {$m->email}");
        $changed = true;
    }
    if (isset($opt['delete-user'])) {
        if (!$YES && !$DRYRUN) bail("deleting member {$m->email}. Re-run with --yes to confirm.");
        if (!$DRYRUN) { Bean::trash($m); if (class_exists('\app\PermissionCache')) \app\PermissionCache::clear(); }
        out("# " . ($DRYRUN ? 'would delete' : 'deleted') . " member {$m->email} (id {$m->id})");
        exit(0);
    }

    if (!$changed) { bail('no action given for --user. Try --set-password / --set-level / --status / --reset-2fa / --delete-user'); }
    if (!$DRYRUN) { $m->updatedAt = date('Y-m-d H:i:s'); Bean::store($m); }
    exit(0);
}

// --- Scaffold: --wizard (interactive) --------------------------------------
if (isset($opt['wizard'])) {
    $manager = new \app\Scaffold\ScaffoldManager(dirname(__DIR__));
    $manager->setVerbose($VERBOSE)->setDryRun($DRYRUN);
    $manager->runWizard();
    exit(0);
}

// --- Scaffold: --scaffold=PARTS --bean=TYPE --------------------------------
// PARTS is a comma list of model,controller,view,api (or 'all'). Generates a
// working CRUD stack for an existing/spec'd bean.
if (isset($opt['scaffold'])) {
    $type = trim((string)($opt['bean'] ?? ''));
    if ($type === '') bail('--scaffold requires --bean=TYPE (the bean to generate for)');
    $parts = trim((string)$opt['scaffold']);
    if ($parts === '' || $parts === 'all') $parts = 'model,controller,view';
    $partsList = array_values(array_filter(array_map('trim', explode(',', $parts))));
    $manager = new \app\Scaffold\ScaffoldManager(dirname(__DIR__));
    $manager->setVerbose($VERBOSE)->setDryRun($DRYRUN);
    $bt = Bean::normalize($type);
    out('# scaffolding ' . $bt . ': ' . implode(', ', $partsList) . ($DRYRUN ? ' (dry-run)' : ''));
    $manager->runScaffold($bt, $partsList);

    // Seed an authcontrol row so the generated controller's routes are reachable.
    // Default level 100 (MEMBER — matches the requireLogin() the CRUD template emits);
    // override with --level. Idempotent: leaves an existing control/* row as-is.
    if (!$DRYRUN && array_intersect(['controller', 'crud', 'api'], $partsList)) {
        $lvl = isset($opt['level']) ? (int)$opt['level'] : 100;
        $existing = Bean::findOne('authcontrol', 'control = ? AND method = ?', [$bt, '*']);
        if ($existing && $existing->id) {
            out("# authcontrol {$bt}/* already exists (level {$existing->level})");
        } else {
            $ac = Bean::dispense('authcontrol');
            $ac->control = $bt;
            $ac->method  = '*';
            $ac->level   = $lvl;
            Bean::store($ac);
            out("# seeded authcontrol: {$bt}/* level {$lvl}");
        }
    }
    if (!$DRYRUN && class_exists('\app\PermissionCache')) { \app\PermissionCache::clear(); out('# permission cache cleared'); }
    exit(0);
}

// --- i18n: --i18n-scan (harvest t() strings into lang/en.json) --------------
if (isset($opt['i18n-scan'])) {
    if (!class_exists('\Translatify\Scanner')) bail('translatify package not installed (composer require translatify/translatify)');
    $root = dirname(__DIR__);
    $langDir = $root . '/lang';
    if (!is_dir($langDir)) @mkdir($langDir, 0755, true);
    $roots = ["$root/views", "$root/controls", "$root/services", "$root/lib"];
    if ($DRYRUN) { out('# dry-run: would scan ' . implode(', ', $roots)); exit(0); }
    $result = (new \Translatify\Scanner())->syncToLocale(new \Translatify\Editor($langDir), $roots, 'en');
    out("# i18n-scan: {$result['total']} unique strings, " . count($result['added']) . ' new key(s) added to en.json');
    exit(0);
}

// --- Beans: generic read ops ------------------------------------------------
if (isset($opt['bean'])) {
    $type = Bean::normalize($opt['bean']);
    $limit = isset($opt['limit']) ? ' LIMIT ' . (int)$opt['limit'] : '';
    $order = isset($opt['order']) ? ' ORDER BY ' . $opt['order'] : '';

    if (isset($opt['findone'])) {
        $where = (string)($opt['where'] ?? '1');
        $data  = isset($opt['data']) ? [$opt['data']] : [];
        $bean = Bean::findOne($type, $where, $data);
        out($bean ? json_encode($bean->export(), JSON_PRETTY_PRINT) : '(null)');
        exit(0);
    }
    // default: getall
    $where = (string)($opt['where'] ?? '1');
    $data  = isset($opt['data']) ? [$opt['data']] : [];
    $beans = Bean::findAll($type, $where . $order . $limit, $data);
    $rows = array_map(fn($b) => $b->export(), $beans);
    out(json_encode(array_values($rows), JSON_PRETTY_PRINT));
    out('# ' . count($rows) . ' bean(s)');
    exit(0);
}

bail('no recognized command. Run with --help.');

// --- Help -------------------------------------------------------------------
function showHelp(): void {
    out(<<<HELP
tiknix clitool — operations & introspection

SCHEMA / DB
  --build [--fresh --yes]        Run services/Schema/Seeds (--fresh drops all tables first)
  --list                         List tables with row counts
  --describe=TABLE               Show a table's columns and types
  --sql="SELECT ..."             Run a read query, print rows (TSV)
  --exec="UPDATE ..." --yes      Run a write query (guarded)

MEMBERS
  --list-users                   List members (id, email, username, level, status, 2fa)
  --adduser=EMAIL --password=PW [--level=N] [--username=NAME]
                                 Create a member (default level 100 MEMBER)
  --user=IDENT --set-password=PW Change a member's password (IDENT = id|email|username)
  --user=IDENT --set-level=N     Change a member's level (1 ROOT, 50 ADMIN, 100 MEMBER, 101 PUBLIC)
  --user=IDENT --status=active   Change a member's status
  --user=IDENT --reset-2fa       Clear 2FA (totp secret, recovery codes) — the lockout fix
  --user=IDENT --delete-user --yes   Delete a member

SCAFFOLD (code generation)
  --wizard                       Interactive model/CRUD wizard
  --scaffold=PARTS --bean=TYPE   Generate PARTS (model,controller,view,api | all) for a bean
                                 e.g. --scaffold=all --bean=product

CONCEPTS (pluggable features — see COMPONENTS_PLAN.md)
  --concepts                     List installed concepts: enabled / disabled / BROKEN
  --concept-verify=NAME          Everything that would stop NAME being enabled
  --concept-enable=NAME          Verify, run concepts/NAME/seeds (thawed), then switch on
  --concept-disable=NAME [--force]
  --agent-sync                   Regenerate CLAUDE.md's managed block (core agent/guidelines/ +
                                 enabled concepts' guidelines.md); enable/disable run it too
                                 Switch off. Refused while another concept requires it, or
                                 while its beans hold rows (--force overrides the rows check;
                                 data is always kept)
  --concept-search[=WORDS]       Search the shared catalog (no words = list everything)
  --concept-install=NAME         Copy NAME from the catalog into concepts/NAME/. Never
                                 overwrites, never enables
  --concept-lint=NAME [--from=ROOT] [--origin=INSTALL_ROOT] [--forbid=a,b]
                                 Is it fit to publish? Origin leakage, secrets, absolute
                                 paths, R::, undeclared core classes and beans.
                                 --from   where ROOT/concepts/NAME is (default: this install);
                                          an authoring tree works too: ROOT/NAME/concept.json
                                          (e.g. --from=concepts-src)
                                 --origin the instance it was extracted from — its slug,
                                          [app] name and hostname must not appear anywhere
  --concept-publish=NAME [--from=ROOT] [--origin=INSTALL_ROOT] [--forbid=a,b]
                                 Lint, then copy into the catalog (control plane only).
                                 A version already published cannot change — bump it

I18N
  --i18n-scan                    Harvest t('…') strings from source into lang/en.json

BEANS (read-mostly, generic)
  --bean=TYPE --getall [--where="col = ?" --data=VAL] [--order="id DESC"] [--limit=N]
  --bean=TYPE --findone --where="email = ?" --data=me@example.com

GLOBAL
  --verbose    --dry-run    --yes    --help

Honours DB_DSN like the app (SQLite / MySQL / Postgres). Uses bean operations throughout.
HELP);
}
