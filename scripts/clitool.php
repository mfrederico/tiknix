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

/** Resolve a member by id / email / username. */
function findMember(string $ident): ?object {
    if (ctype_digit($ident)) {
        $m = R::load('member', (int)$ident);
        if ($m && $m->id) return $m;
    }
    $m = R::findOne('member', 'email = ? OR username = ?', [$ident, $ident]);
    return ($m && $m->id) ? $m : null;
}

function levelLabel(int $level): string {
    return [1 => 'ROOT', 50 => 'ADMIN', 100 => 'MEMBER', 101 => 'PUBLIC'][$level] ?? "L{$level}";
}

// --- Boot -------------------------------------------------------------------
$app = new \app\Bootstrap(); // connects (DB_DSN-aware)
if (!R::testConnection()) bail('database connection failed');
$dbType = R::getDatabaseAdapter()->getDatabase()->getDatabaseType();
if ($VERBOSE) out("# connected ({$dbType})");

if (empty($opt) || isset($opt['help'])) { showHelp(); exit(0); }

// --- Schema: --build [--fresh] ---------------------------------------------
if (isset($opt['build'])) {
    if (isset($opt['fresh'])) {
        if (!$YES && !$DRYRUN) bail('--fresh drops ALL tables. Re-run with --yes to confirm.');
        out('# --fresh: dropping existing tables');
        if (!$DRYRUN) foreach (R::inspect() as $t) {
            try { R::exec('DROP TABLE IF EXISTS ' . $t); out("  dropped {$t}"); }
            catch (\Exception $e) { err("  warn dropping {$t}: " . $e->getMessage()); }
        }
    }
    if ($DRYRUN) { out('# dry-run: would run seeds in services/Schema/Seeds/'); exit(0); }
    out('# running seeds…');
    foreach ((new WorkspaceSchemaBuilder())->build() as $file => $status) out("  {$file}: {$status}");
    if (class_exists('\app\PermissionCache')) { \app\PermissionCache::clear(); out('# permission cache cleared'); }
    exit(0);
}

// --- Introspection: --list --------------------------------------------------
if (isset($opt['list'])) {
    $tables = R::inspect();
    if (!$tables) { out('(no tables)'); exit(0); }
    out(sprintf('%-30s %8s', 'TABLE', 'ROWS'));
    out(str_repeat('-', 39));
    foreach ($tables as $t) {
        $n = R::count($t);
        out(sprintf('%-30s %8d', $t, $n));
    }
    exit(0);
}

// --- Introspection: --describe=TABLE ---------------------------------------
if (isset($opt['describe'])) {
    $table = $opt['describe'];
    $cols = R::inspect($table);
    if (!$cols) bail("table '{$table}' not found (or empty)");
    out("TABLE {$table}");
    out(str_repeat('-', 40));
    foreach ($cols as $name => $type) out(sprintf('  %-24s %s', $name, $type));
    exit(0);
}

// --- Ad-hoc read: --sql -----------------------------------------------------
if (isset($opt['sql'])) {
    $rows = R::getAll($opt['sql']);
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
    $affected = R::exec($opt['exec']);
    out("# ok ({$affected} row(s) affected)");
    exit(0);
}

// --- Members: --list-users --------------------------------------------------
if (isset($opt['list-users'])) {
    $members = R::findAll('member', ' ORDER BY level ASC, id ASC ');
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

    $existing = R::findOne('member', 'email = ?', [$email]);
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
        if (!$DRYRUN) { R::trash($m); if (class_exists('\app\PermissionCache')) \app\PermissionCache::clear(); }
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
    out('# scaffolding ' . Bean::normalize($type) . ': ' . implode(', ', $partsList) . ($DRYRUN ? ' (dry-run)' : ''));
    $manager->runScaffold(Bean::normalize($type), $partsList);
    if (!$DRYRUN && class_exists('\app\PermissionCache')) { \app\PermissionCache::clear(); }
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
        $bean = R::findOne($type, $where, $data);
        out($bean ? json_encode($bean->export(), JSON_PRETTY_PRINT) : '(null)');
        exit(0);
    }
    // default: getall
    $where = (string)($opt['where'] ?? '1');
    $data  = isset($opt['data']) ? [$opt['data']] : [];
    $beans = R::findAll($type, $where . $order . $limit, $data);
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

BEANS (read-mostly, generic)
  --bean=TYPE --getall [--where="col = ?" --data=VAL] [--order="id DESC"] [--limit=N]
  --bean=TYPE --findone --where="email = ?" --data=me@example.com

GLOBAL
  --verbose    --dry-run    --yes    --help

Honours DB_DSN like the app (SQLite / MySQL / Postgres). Uses bean operations throughout.
HELP);
}
