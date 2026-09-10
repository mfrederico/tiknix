<?php
/**
 * grandfather-billing.php — put every existing account on the `legacy` tier with a cap
 * that fits what it already holds.
 *
 * MUST RUN BEFORE ENFORCEMENT IS SWITCHED ON. Two members currently hold five projects
 * each and one holds three; turning the gates on without this locks real people out of
 * their own work on the day it ships.
 *
 * TWO PASSES, and the ordering is not cosmetic. The counting rule says a project shared
 * into a team whose owner is on the FREE tier counts against every member of that team
 * (the anti-abuse clause). While everyone is still `free` that clause fires everywhere,
 * so counting first would hand people caps inflated by projects that will stop counting
 * the moment their team owner is no longer free. Pass 1 moves everyone off `free`; pass 2
 * counts the world that then exists.
 *
 * Idempotent. An account already on `legacy` keeps the cap it has unless it now legitimately
 * holds MORE, in which case the cap is raised — never lowered. Re-running must not take
 * away headroom somebody has been using.
 *
 *   php scripts/grandfather-billing.php --dry-run     # show what would change
 *   php scripts/grandfather-billing.php --yes         # apply
 */

require __DIR__ . '/../vendor/autoload.php';

use app\Bean;
use app\ProjectQuota;

$dryRun = in_array('--dry-run', $argv, true);
$apply  = in_array('--yes', $argv, true);
if (!$dryRun && !$apply) {
    fwrite(STDERR, "Refusing to guess. Pass --dry-run to preview or --yes to apply.\n");
    exit(1);
}

/** Floor for a grandfathered cap: nobody who was here early ends up with less than this. */
const FLOOR_CAP = 3;

$cfg = parse_ini_file(__DIR__ . '/../conf/config.ini', true);
foreach ($cfg as $section => $vals) {
    foreach ((array) $vals as $k => $v) Flight::set("$section.$k", $v);
}
Flight::set('log', new class {
    public function error($m, $c = []) { fwrite(STDERR, "  ERROR: $m\n"); }
    public function __call($m, $a) {}
});
// An absolute path in config is used as-is; a relative one resolves against the install
// root. Prepending unconditionally turned "/tmp/x.db" into ".../scripts/../tmp/x.db" and
// produced a connect error that arrived AFTER this script had announced it was applying.
$configured = (string) ($cfg['database']['path'] ?? 'database/tiknix.db');
$dbPath = str_starts_with($configured, '/') ? $configured : __DIR__ . '/../' . $configured;

// Stop before announcing anything if the target is not there. A migration that says
// "applying" and then dies is a moment where nobody knows what was written.
if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found at {$dbPath} (config says '{$configured}') — refusing to run.\n");
    exit(1);
}

\RedBeanPHP\R::setup('sqlite:' . $dbPath);

printf("Database: %s\n%s\n\n", realpath($dbPath), $dryRun ? '(dry run — nothing will be written)' : '(applying)');

$members = Bean::findAll('member', 'ORDER BY id');

// ---- pass 1: everyone off the free tier ------------------------------------------
$moved = 0;
foreach ($members as $m) {
    $tier = (string) ($m->planTier ?: 'free');
    if ($tier !== 'free') continue;             // already legacy or pro — leave it
    if (!$dryRun) { $m->planTier = 'legacy'; Bean::store($m); }
    $moved++;
}
printf("Pass 1: %d account(s) moved from free to legacy%s\n\n", $moved, $dryRun ? ' (would be)' : '');

// In a dry run nothing was written, so pass 2 would still see the pre-migration world and
// report caps nobody will actually get. Say so rather than print a number that is wrong.
if ($dryRun) {
    echo "Pass 2 preview uses PRE-migration counts, which are inflated by the free-owner\n"
       . "clause. The applied run will produce lower, correct caps.\n\n";
}

// ---- pass 2: cap each account to what it now holds --------------------------------
printf("  %-32s %8s %8s %8s   %s\n", 'member', 'counts', 'old cap', 'new cap', 'action');
printf("  %s\n", str_repeat('-', 78));

$changed = 0;
foreach ($members as $m) {
    $id = (int) $m->id;
    try {
        $count = ProjectQuota::countFor($id);
    } catch (\Throwable $e) {
        // A member whose count cannot be established must not be given a cap invented on
        // the spot — that is how somebody ends up locked out or handed the world.
        printf("  %-32s %8s %8s %8s   SKIPPED — count failed\n", substr((string) $m->email, 0, 31), '?', '?', '?');
        continue;
    }

    $oldCap = (int) ($m->planProjectCap ?? 0);
    $newCap = max(FLOOR_CAP, $count, $oldCap);   // never lower an existing cap
    $action = $newCap === $oldCap ? 'unchanged' : ($oldCap === 0 ? 'set' : 'raised');

    if ($action !== 'unchanged' && !$dryRun) {
        $m->planProjectCap = $newCap;
        Bean::store($m);
        $changed++;
    } elseif ($action !== 'unchanged') {
        $changed++;
    }

    printf("  %-32s %8d %8s %8d   %s\n",
        substr((string) $m->email, 0, 31), $count, $oldCap ?: '—', $newCap, $action);
}

printf("\nPass 2: %d cap(s) %s\n", $changed, $dryRun ? 'would change' : 'written');
echo $dryRun
    ? "\nNothing was written. Re-run with --yes to apply.\n"
    : "\nDone. Enforcement can now be switched on ([billing] enforce_project_cap).\n";
