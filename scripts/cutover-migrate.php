<?php
/**
 * cutover-migrate.php — the one data step of the tiknix.com cutover (RUNTIME-SPLIT-MAP.md §8).
 *
 * tiknix.com's database stays the truth: members, keys, teams, billing, run history. What it
 * does not have is what tiknix2 learned by moving every project into its own container — the
 * container state on each `instance` row, and the one project created over here (catpoobox).
 * This copies exactly that, by slug, from tiknix2's database into this install's:
 *
 *   php scripts/cutover-migrate.php --from=/var/www/html/default/tiknix2.tiknix/database/tiknix.db          # what would change
 *   php scripts/cutover-migrate.php --from=… --apply                                                      # change it
 *
 * Run `php scripts/clitool.php --build` first: the seeds make the columns (ct_aliases TEXT,
 * ct_hosts, ct_kind, lent_connections). A column this script would write that is missing or
 * not TEXT where text goes stops it — storing text into it would be RedBean's widen-by-rebuild.
 *
 * An instance that exists only in the source keeps its id: per-project data (the project's own
 * workbench rows) is keyed by it. A slug whose id differs between the two, or a source id held
 * here by another slug, is a fault this script names and does not resolve.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';
new \app\Bootstrap();

use app\Bean;

const CARRIED = ['deploy_token', 'ct_vmid', 'ct_ip', 'ct_domain', 'ct_aliases', 'ct_hosts', 'ct_kind', 'lent_connections'];
const TEXT_COLS = ['deploy_token', 'ct_ip', 'ct_domain', 'ct_aliases', 'ct_hosts', 'ct_kind', 'lent_connections'];
const SECRET_COLS = ['deploy_token', 'app_key', 'broker_key'];

$o = getopt('', ['from:', 'apply']);
$from = (string) ($o['from'] ?? '');
$apply = isset($o['apply']);
if ($from === '' || !is_file($from)) { fwrite(STDERR, "ERROR --from=<tiknix2's database/tiknix.db> is required and must exist (got '{$from}')\n"); exit(2); }
if (realpath($from) === realpath('database/tiknix.db')) { fwrite(STDERR, "ERROR --from is this install's own database\n"); exit(2); }

// --- this install's schema must be ready -------------------------------------------------
$types = array_change_key_case(array_column(Bean::getAll('PRAGMA table_info(instance)'), 'type', 'name'));
foreach (CARRIED as $c) {
    if (!isset($types[$c])) { fwrite(STDERR, "ERROR instance.{$c} does not exist here — run php scripts/clitool.php --build first\n"); exit(1); }
    if (in_array($c, TEXT_COLS, true) && strtoupper($types[$c]) !== 'TEXT') {
        fwrite(STDERR, "ERROR instance.{$c} is {$types[$c]}, not TEXT — writing text into it rebuilds the table (seed 31_CtAliasesText / --build)\n"); exit(1);
    }
}

// --- read the source ---------------------------------------------------------------------
Bean::addDatabase('cutover-src', 'sqlite:' . $from, null, null, true, false);
Bean::selectDatabase('cutover-src');
$src = Bean::getAll('SELECT * FROM instance ORDER BY id');
Bean::selectDatabase('default');
if (!$src) { fwrite(STDERR, "ERROR {$from} has no instance rows\n"); exit(1); }

$show = static fn(string $col, $v): string => $v === null || $v === '' ? '∅'
    : (in_array($col, SECRET_COLS, true) ? '[' . strlen((string) $v) . ' chars]' : (string) $v);

$faults = []; $changes = 0; $inserts = [];
foreach ($src as $row) {
    $slug = (string) $row['slug'];
    $here = Bean::findOne('instance', 'slug = ?', [$slug]);

    if ($here === null) {
        $holder = Bean::load('instance', (int) $row['id']);
        if ($holder->id) { $faults[] = "{$slug}: id {$row['id']} is held here by '{$holder->slug}'"; continue; }
        $inserts[] = $row;
        echo "{$slug}: NEW here, keeps id {$row['id']}\n";
        continue;
    }
    if ((int) $here->id !== (int) $row['id']) { $faults[] = "{$slug}: id {$here->id} here, {$row['id']} in the source"; continue; }

    $diff = [];
    foreach (CARRIED as $c) {
        $prop = lcfirst(str_replace('_', '', ucwords($c, '_')));
        $old = $here->$prop; $new = $row[$c];
        if ((string) $old === (string) $new) continue;
        $diff[] = "{$c} " . $show($c, $old) . ' → ' . $show($c, $new);
        $here->$prop = $new;
    }
    if (!$diff) continue;
    $changes += count($diff);
    echo "{$slug}:\n    " . implode("\n    ", $diff) . "\n";
    if ($apply) Bean::store($here);
}

if ($faults) {
    fwrite(STDERR, "ERROR not resolved by this script:\n  " . implode("\n  ", $faults) . "\n");
    if ($apply) fwrite(STDERR, "(the rows above the faults were written)\n");
    exit(1);
}

// A new row keeps its id, which a bean store cannot do: one INSERT with the source's own
// columns, restricted to the columns this table has (a source-only column is a fault).
foreach ($inserts as $row) {
    $extra = array_diff(array_keys($row), array_keys($types));
    if ($extra) { fwrite(STDERR, "ERROR {$row['slug']}: the source has column(s) " . implode(', ', $extra) . " this table lacks\n"); exit(1); }
    if (!$apply) continue;
    $cols = array_keys($row);
    Bean::exec('INSERT INTO instance (' . implode(', ', array_map(fn($c) => "`{$c}`", $cols)) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
}

echo ($apply ? 'applied' : 'dry run') . ': ' . $changes . ' column change(s), ' . count($inserts) . " new instance(s)"
    . ($apply ? '' : ' — add --apply to write') . "\n";
