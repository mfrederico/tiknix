#!/usr/bin/env php
<?php
/**
 * message.message_id -> message.message_eid
 *
 * The column holds the RFC822 Message-ID: a STRING minted by whoever sent the
 * mail, like "<tk.42.a1b2@example.com>". The _id suffix is reserved for
 * RedBeanPHP's integer foreign keys (CLAUDE.md), and this was only ever harmless
 * by accident — under the old bean name `notify` there was no table called
 * `message` for RedBean to point it at.
 *
 * Renaming the bean to `message` made it self-referential. Fluid mode emits
 *
 *     FOREIGN KEY (message_id) REFERENCES message (id)
 *
 * and then storing a Message-ID string into it fails the constraint. On core the
 * table predated the rename so the FK was never added and nothing broke; on an
 * instance whose table was rebuilt afterwards, the comms seed failed outright.
 * That is the worst shape for a bug — invisible where it was written, fatal
 * somewhere else later.
 *
 * Idempotent, and safe on a database that never had messaging.
 *
 *   php database/migrate-message-eid.php            # show what would change
 *   php database/migrate-message-eid.php --confirm  # do it
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }

require_once __DIR__ . '/../bootstrap.php';
$app = new app\Bootstrap('conf/config.ini');

use app\Bean;

$confirm = in_array('--confirm', $argv, true);

if (!in_array('message', Bean::inspect(), true)) {
    echo "  no message table — nothing to do.\n";
    exit(0);
}

$cols = array_column(Bean::getAll('PRAGMA table_info(message)'), 'name');
$hasOld = in_array('message_id', $cols, true);
$hasNew = in_array('message_eid', $cols, true);

if ($hasNew && !$hasOld) { echo "  already message_eid — nothing to do.\n"; exit(0); }
if (!$hasOld)            { echo "  no message_id column — nothing to do.\n"; exit(0); }

// A self-referencing FK cannot be dropped by RENAME COLUMN; SQLite carries the
// constraint over. Say so rather than producing a renamed column that still has it.
$selfFk = false;
foreach (Bean::getAll('PRAGMA foreign_key_list(message)') as $fk) {
    if ($fk['from'] === 'message_id' && $fk['table'] === 'message') $selfFk = true;
}

$rows = (int) Bean::getCell('SELECT COUNT(*) FROM message');
printf("  message_id -> message_eid   (%d row%s)%s\n", $rows, $rows === 1 ? '' : 's',
    $selfFk ? '   [carries a self-FK — table will be rebuilt]' : '');

if ($hasNew && $hasOld) {
    echo "  WARNING: both columns exist. Refusing to guess which is current.\n";
    echo "  Inspect them and drop the stale one by hand.\n";
    exit(1);
}

if (!$confirm) { echo "\n  DRY RUN — re-run with --confirm to apply.\n"; exit(0); }

try {
    if (!$selfFk) {
        Bean::exec('ALTER TABLE message RENAME COLUMN message_id TO message_eid');
        echo "  renamed.\n";
    } else {
        // Rebuild without the constraint. RENAME COLUMN would keep the FK pointing
        // at the renamed column, which is the thing being fixed.
        Bean::begin();
        Bean::exec('ALTER TABLE message RENAME TO message_old_eid_migration');
        Bean::exec('CREATE TABLE message AS SELECT * FROM message_old_eid_migration WHERE 0');
        Bean::exec('ALTER TABLE message RENAME COLUMN message_id TO message_eid');
        Bean::exec('INSERT INTO message SELECT * FROM message_old_eid_migration');
        Bean::exec('DROP TABLE message_old_eid_migration');
        Bean::commit();
        echo "  rebuilt without the self-FK, " . (int) Bean::getCell('SELECT COUNT(*) FROM message') . " row(s) carried over.\n";
    }
} catch (\Throwable $e) {
    try { Bean::rollback(); } catch (\Throwable $ignore) {}
    fwrite(STDERR, "  FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

echo "  done.\n";
