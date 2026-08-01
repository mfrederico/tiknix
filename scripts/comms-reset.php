#!/usr/bin/env php
<?php
/**
 * Wipe messaging back to a clean, seeded state.
 *
 * For build-testing: it removes every conversation, message, participant row, mention and
 * support ticket, then re-seeds each team's #general so the system is empty but usable
 * rather than empty and broken.
 *
 * It backs up first, always, even though the whole point is to throw things away — a
 * backup nobody needs costs a few kilobytes, and the one time it is needed there is no
 * other copy.
 *
 * Refuses to run without --confirm. Anything that deletes every message in the system
 * should not be one typo away from happening.
 *
 *   php scripts/comms-reset.php              # show what would go
 *   php scripts/comms-reset.php --confirm    # do it
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use \RedBeanPHP\R as R;
use \app\Rooms;
use \app\ThreadMembers;

$confirm = in_array('--confirm', $argv, true);

/** The bean types messaging owns. */
$types = ['mention', 'threadmember', 'notify', 'emailthread', 'contactresponse', 'contact'];

echo "Current state\n";
$counts = [];
foreach ($types as $t) {
    // count() on a type whose table does not exist yet is 0, not an error.
    try { $counts[$t] = (int) R::count($t); } catch (\Throwable $e) { $counts[$t] = 0; }
    printf("  %-16s %d\n", $t, $counts[$t]);
}
$total = array_sum($counts);

if (!$confirm) {
    echo "\nDRY RUN — $total rows would be deleted.\n";
    echo "Re-run with --confirm to actually do it.\n";
    exit(0);
}

// --- backup ------------------------------------------------------------------------
@mkdir(__DIR__ . '/../data/backups', 0775, true);
$dump = ['exported_at' => date('c')];
foreach ($types as $t) {
    $rows = [];
    try {
        foreach (R::findAll($t) as $bean) $rows[] = $bean->export();
    } catch (\Throwable $e) { /* no such table yet */ }
    $dump[$t] = $rows;
}
$file = 'data/backups/comms-reset-' . date('Ymd-His') . '.json';
file_put_contents(__DIR__ . '/../' . $file,
    json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
printf("\nBacked up %d rows -> %s\n", $total, $file);

// --- wipe --------------------------------------------------------------------------
// R::wipe() is RedBean's own "empty this type", which keeps table names out of hand-built
// SQL entirely — the thing the standards hook exists to stop.
echo "\nDeleting\n";
foreach ($types as $t) {
    try {
        R::wipe($t);
        // Start ids at 1 again so a fresh system's ids read sensibly during a test.
        // The table name is a VALUE here, so it binds; harmless outside SQLite.
        try { R::exec('DELETE FROM sqlite_sequence WHERE name = ?', [$t]); } catch (\Throwable $e) {}
        printf("  %-16s cleared\n", $t);
    } catch (\Throwable $e) {
        printf("  %-16s FAILED: %s\n", $t, $e->getMessage());
    }
}

// --- reseed ------------------------------------------------------------------------
echo "\nSeeding\n";
$teams = R::findAll('team', 'ORDER BY id');
if (!$teams) {
    echo "  no teams — nothing to seed. Create a team and every member of it gets a #general.\n";
}
foreach ($teams as $team) {
    $id = Rooms::ensureGeneral((int) $team->id);
    if (!$id) {
        printf("  team %-3d %-20s FAILED to create #general\n", $team->id, $team->name);
        continue;
    }
    printf("  team %-3d %-20s #general = thread %-3d members [%s]\n",
        $team->id, mb_substr((string) $team->name, 0, 20), $id,
        implode(',', ThreadMembers::participants($id)));
}

echo "\nDone.\n";
printf("  threads %d · messages %d · participants %d · mentions %d · support %d\n",
    R::count('emailthread'), R::count('notify'), R::count('threadmember'),
    R::count('mention'), R::count('contact'));
