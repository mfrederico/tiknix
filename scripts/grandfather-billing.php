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
 * Grandfathering needs --before=DATE and only touches accounts created before it. Without
 * it the run only normalises blank tiers, so a re-run cannot grandfather a new signup that
 * drifted over the cap while enforcement was off.
 *
 *   php scripts/grandfather-billing.php --dry-run --before=2026-09-11
 *   php scripts/grandfather-billing.php --yes --before=2026-09-11
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

// Only accounts created before this date can be grandfathered. Omit it and the run just
// normalises blank tiers — grandfathering is a one-time act at launch, not something a
// re-run should keep doing to whoever is over the cap that day.
$cutoff = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--before=')) $cutoff = substr($arg, 9);
}
if ($cutoff !== '' && !strtotime($cutoff)) {
    fwrite(STDERR, "--before must be a date, e.g. --before=2026-09-11\n");
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

// ---- pass 1: grandfather only accounts that need it -------------------------------
//
// Grandfathering protects accounts that ALREADY EXISTED and already hold more than the
// new free tier allows. Both halves matter: without the cutoff a re-run grandfathers a
// signup from yesterday that got over the cap while enforcement was off, handing a brand
// new account free projects for good.
$moved = 0;
$normalised = 0;
foreach ($members as $m) {
    $tier = (string) ($m->planTier ?: 'free');
    if ($tier !== 'free') continue;             // already legacy or pro — leave it

    try {
        $count = ProjectQuota::countFor((int) $m->id);
    } catch (\Throwable $e) {
        printf("  %-32s SKIPPED — count failed\n", substr((string) $m->email, 0, 31));
        continue;
    }

    $existedAtCutoff = $cutoff !== '' && strtotime((string) $m->createdAt) < strtotime($cutoff);

    if ($count > ProjectQuota::FREE_CAP && $existedAtCutoff) {
        if (!$dryRun) { $m->planTier = 'legacy'; Bean::store($m); }
        $moved++;
    } elseif ($m->planTier === null || $m->planTier === '') {
        // Blank columns behave as free but read as "unset"; make it explicit.
        if (!$dryRun) {
            $m->planTier = 'free';
            $m->planProjectCap = ProjectQuota::FREE_CAP;
            Bean::store($m);
        }
        $normalised++;
    }
}
printf("Pass 1: %d account(s) grandfathered to legacy, %d normalised to free%s\n",
    $moved, $normalised, $dryRun ? ' (would be)' : '');
if ($cutoff === '') {
    echo "        (no --before=DATE, so nothing was grandfathered — normalise only)\n";
}
echo "\n";

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
    // Caps are a grandfathering concept; a free account uses the tier default.
    if (ProjectQuota::tierOf($id) === 'free') continue;
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
