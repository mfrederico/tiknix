#!/usr/bin/env php
<?php
/**
 * Bring a database in line with the messaging rename.
 *
 *   emailthread      -> thread
 *   notify           -> message
 *   notifyattachment -> messageattachment
 *   thread.unread_count dropped (per-person unread lives on threadmember.last_read_id)
 *
 * The code queries the new names, so an instance that merges it without running this
 * cannot read its own conversations. Run once per instance after the upgrade.
 *
 * Idempotent, and safe on a database that never had messaging — a missing table is
 * skipped, not an error. It reports what it did rather than exiting quietly, because
 * "0 tables renamed" is a fact worth seeing when you expected some.
 *
 * The ALTER statements are written out in full rather than built from a loop variable:
 * a table name cannot be a bound parameter, and three literal statements are both safer
 * and clearer than string-building SQL.
 *
 *   php scripts/migrate-comms-rename.php            # show what would change
 *   php scripts/migrate-comms-rename.php --confirm  # do it
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use app\Bean;

$confirm = in_array('--confirm', $argv, true);

$tables = array_map(fn($r) => $r['name'],
    Bean::getAll("SELECT name FROM sqlite_master WHERE type = 'table'"));

/** @return string one of: rename | done | absent | conflict */
$state = function (string $from, string $to) use ($tables): string {
    $hasFrom = in_array($from, $tables, true);
    $hasTo   = in_array($to, $tables, true);
    if ($hasFrom && $hasTo)  return 'conflict';
    if ($hasFrom)            return 'rename';
    if ($hasTo)              return 'done';
    return 'absent';
};

$pairs = [
    ['emailthread', 'thread'],
    ['notify', 'message'],
    ['notifyattachment', 'messageattachment'],
];

$conflict = false;
foreach ($pairs as [$from, $to]) {
    $s = $state($from, $to);
    if ($s === 'conflict') $conflict = true;
    $note = match ($s) {
        'rename'   => 'will rename',
        'done'     => 'already renamed',
        'absent'   => 'no such table',
        'conflict' => 'BOTH EXIST — needs a human',
    };
    printf("  %-18s -> %-18s %s\n", $from, $to, $note);
}

if ($conflict) {
    echo "\nRefusing to continue: a source and its target both exist.\n";
    exit(1);
}
if (!$confirm) {
    echo "\nDRY RUN — re-run with --confirm to apply.\n";
    exit(0);
}

// Literal statements, each guarded by its own check.
if ($state('emailthread', 'thread') === 'rename') {
    Bean::exec('ALTER TABLE `emailthread` RENAME TO `thread`');
    echo "  renamed emailthread -> thread\n";
}
if ($state('notify', 'message') === 'rename') {
    Bean::exec('ALTER TABLE `notify` RENAME TO `message`');
    echo "  renamed notify -> message\n";
}
if ($state('notifyattachment', 'messageattachment') === 'rename') {
    Bean::exec('ALTER TABLE `notifyattachment` RENAME TO `messageattachment`');
    echo "  renamed notifyattachment -> messageattachment\n";
}

// The counter column, if this database still has it. Dropping it matters: RedBean's fluid
// mode recreates a column on write, so leaving it invites a stale second source of truth
// for a badge that is now derived from threadmember.last_read_id.
$after = array_map(fn($r) => $r['name'],
    Bean::getAll("SELECT name FROM sqlite_master WHERE type = 'table'"));
if (in_array('thread', $after, true)) {
    $cols = array_map(fn($c) => $c['name'], Bean::getAll('PRAGMA table_info(thread)'));
    if (in_array('unread_count', $cols, true)) {
        Bean::exec('ALTER TABLE `thread` DROP COLUMN `unread_count`');
        echo "  dropped thread.unread_count\n";
    }
}

$count = function (string $type): int {
    try { return (int) Bean::count($type); } catch (\Throwable $e) { return 0; }
};
printf("\nDone. thread=%d message=%d threadmember=%d\n",
    $count('thread'), $count('message'), $count('threadmember'));
