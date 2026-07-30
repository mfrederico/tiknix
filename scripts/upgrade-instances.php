#!/usr/bin/env php
<?php
/**
 * upgrade-instances.php — merge core's main into each instance's own branch.
 *
 * An instance is a git clone of core on its own `instance/<slug>` branch, carrying the
 * app its agents built on top of the platform. Upgrading one means merging core forward
 * WITHOUT touching the two things that make it that instance: its live database and its
 * own commits.
 *
 * The order here is the whole design, and each step exists because skipping it broke
 * something real:
 *
 *   1. REFUSE if the instance is busy. Merging the working tree out from under a running
 *      build agent corrupts a worktree that is mid-commit.
 *   2. SNAPSHOT first (capricorn), so every instance has a rollback point that predates
 *      anything this script did.
 *   3. Move the live DB aside. It is force-tracked (so checkpoint/rollback can capture
 *      it) yet written on every request, so it is permanently "modified" and would either
 *      block the merge or be clobbered by it. It is restored byte-for-byte afterwards —
 *      the merge must never decide what a customer's data looks like.
 *   4. Merge. On conflict, ABORT and leave the instance exactly as it was. This script
 *      does not resolve conflicts; a human who knows the app does.
 *   5. composer dump-autoload. Deleting a controller from core leaves a classmap entry
 *      pointing at a file that no longer exists, and class_exists() then FATALS instead of
 *      returning false — which is how a removed controller turned into a 500.
 *   6. Rebuild the permission cache, then fetch the site and check it actually answers.
 *      An upgrade that reports success without loading the page is a guess.
 *
 * Usage:
 *   php scripts/upgrade-instances.php --dry-run          # what would happen, touching nothing
 *   php scripts/upgrade-instances.php --only=<slug>      # one instance
 *   php scripts/upgrade-instances.php                    # all eligible instances
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }

$opt     = getopt('', ['dry-run', 'only::', 'skip-snapshot', 'pick::']);
$dryRun  = isset($opt['dry-run']);
$only    = trim((string) ($opt['only'] ?? ''));
$noSnap  = isset($opt['skip-snapshot']);
/**
 * --pick=<sha> — apply ONE commit instead of merging core forward.
 *
 * Instances carry a deliberate "trim" commit that removes the control-plane tooling now
 * living in the workbench sidecar. Merging core into a trimmed instance therefore
 * conflicts on every file the trim deleted — structurally, every time, forever — and
 * those conflicts have nothing to do with the change you are trying to ship. For an
 * instance that is hundreds of commits behind, cherry-picking the fix applies exactly
 * what you meant and nothing else.
 */
$pick    = trim((string) ($opt['pick'] ?? ''));

$root    = '/var/www/html/default';
$coreDir = $root . '/tiknix';
$snapBin = '/home/ubuntu/capricorn/bin/snapshot-instance.sh';

function sh(string $cmd, ?string $cwd = null): array {
    $full = ($cwd ? 'cd ' . escapeshellarg($cwd) . ' && ' : '') . $cmd . ' 2>&1';
    exec($full, $out, $code);
    return ['ok' => $code === 0, 'out' => trim(implode("\n", $out)), 'code' => $code];
}
function say(string $s): void { echo $s . "\n"; }

// ---- Which directories are actually instances -------------------------------
// An instance clones CORE and sits on instance/<slug>. The sidecars (workbench,
// pipelines, publisher, …) are clones of their OWN repos on main — upgrading those with
// core's history would be nonsense, so origin has to point at core AND the branch has to
// be an instance branch. Both, not either.
$instances = [];
foreach (glob($root . '/*.tiknix') ?: [] as $dir) {
    if (!is_dir($dir . '/.git')) continue;
    $origin = sh('git remote get-url origin', $dir);
    $branch = sh('git rev-parse --abbrev-ref HEAD', $dir);
    if (!$origin['ok'] || !$branch['ok']) continue;
    if (realpath($origin['out']) !== realpath($coreDir)) continue;
    if (strpos($branch['out'], 'instance/') !== 0) continue;
    $slug = substr($branch['out'], strlen('instance/'));
    if ($only !== '' && $slug !== $only) continue;
    $instances[$slug] = ['dir' => $dir, 'branch' => $branch['out']];
}

if (!$instances) { say($only !== '' ? "no instance '$only'" : 'no instances found'); exit(1); }

say(count($instances) . ' instance(s)' . ($dryRun ? '   [DRY RUN — nothing will be changed]' : '') . "\n");

$done = $skipped = $failed = 0;

foreach ($instances as $slug => $meta) {
    $dir = $meta['dir'];
    say("── $slug ──────────────────────────────────────────");

    sh('git fetch origin --quiet', $dir);
    $behind = sh('git rev-list --count HEAD..origin/main', $dir);
    $n = (int) $behind['out'];

    if ($pick !== '') {
        // Already has it? `git log --grep` on the cherry-pick's subject is unreliable, so
        // ask git directly whether the patch is already an ancestor by content.
        $has = sh('git cherry HEAD ' . escapeshellarg($pick) . ' | grep -c "^+"', $dir);
        if ((int) $has['out'] === 0) { say("   already has $pick"); $skipped++; say(''); continue; }
        say("   cherry-picking $pick (instance is $n commit(s) behind; not merging)");
    } else {
        if ($n === 0) { say("   already current"); $skipped++; say(''); continue; }
        say("   $n commit(s) behind core");
    }

    // 1. Busy? Merging under a running agent corrupts whatever it is mid-way through.
    $busy = sh('tmux ls 2>/dev/null | grep -c ' . escapeshellarg($slug));
    if ((int) $busy['out'] > 0) {
        say("   ! SKIPPED — tmux sessions for this instance are running (a build or terminal is live)");
        $skipped++; say(''); continue;
    }

    // Only the live DB and untracked junk may differ. Anything else is someone's
    // uncommitted work and this script will not merge over it.
    // `git diff --name-only HEAD` gives PATHS ONLY, for tracked files only — no status
    // column to parse and no untracked noise. Parsing --porcelain's two-char status field
    // by offset is what silently ate the first character of every path here ('.gitignore'
    // reported as 'gitignore'), because trimming the command output removes the leading
    // space that the format's first column depends on.
    $changed = sh('git diff --name-only HEAD', $dir);
    $blocking = [];
    foreach (explode("\n", $changed['out']) as $path) {
        $path = trim($path);
        if ($path === '') continue;
        if (preg_match('#\.(db|sqlite)$#', $path)) continue;             // the live DB, handled below
        $blocking[] = $path;
    }
    if ($blocking) {
        say("   ! SKIPPED — uncommitted tracked changes: " . implode(', ', array_slice($blocking, 0, 5))
            . (count($blocking) > 5 ? ' +' . (count($blocking) - 5) : ''));
        $skipped++; say(''); continue;
    }

    if ($dryRun) {
        say('   would snapshot, ' . ($pick !== '' ? "cherry-pick $pick" : "merge $n commit(s)")
            . ', restore the db, dump-autoload, verify');
        $done++; say(''); continue;
    }

    // 2. Rollback point BEFORE anything is touched.
    if (!$noSnap && is_file($snapBin)) {
        $snap = sh(escapeshellarg($snapBin) . ' tiknix ' . escapeshellarg($slug) . ' pre-upgrade');
        say('   snapshot: ' . ($snap['ok'] ? 'ok' : 'FAILED — ' . substr($snap['out'], 0, 120)));
        if (!$snap['ok']) { say("   ! SKIPPED — refusing to upgrade without a rollback point"); $skipped++; say(''); continue; }
    }

    // 3. The live database steps aside, byte for byte.
    $dbs = [];
    foreach (glob($dir . '/database/*.db') ?: [] as $db) {
        $bak = $db . '.preupgrade';
        if (!@copy($db, $bak)) { say("   ! SKIPPED — could not back up " . basename($db)); continue 2; }
        $dbs[$db] = $bak;
    }
    // Hand the merge a clean tree for those paths so it cannot object to them.
    if ($dbs) sh('git checkout -- ' . implode(' ', array_map(fn($f) => escapeshellarg(str_replace($dir . '/', '', $f)), array_keys($dbs))), $dir);

    // 4. Apply. Conflicts are a human's job — abort and leave the instance untouched.
    $ident = 'git -c user.email=upgrade@tiknix.local -c user.name=upgrade ';
    if ($pick !== '') {
        $apply = sh($ident . 'cherry-pick -x ' . escapeshellarg($pick), $dir);
        $undo  = 'cherry-pick --abort';
        $verb  = 'cherry-picked';
    } else {
        $apply = sh($ident . 'merge origin/main --no-edit', $dir);
        $undo  = 'merge --abort';
        $verb  = 'merged';
    }
    if (!$apply['ok']) {
        // An EMPTY cherry-pick is success, not failure: every hunk was already present, so
        // the instance has the change by some other route (an earlier merge, or a hand
        // applied fix). git exits non-zero and says "the previous cherry-pick is now
        // empty", which is easy to read as a conflict and is the opposite of one.
        // `git cherry` misses this case when the existing commit's patch-id differs
        // slightly, so it is caught here rather than only up front.
        if ($pick !== '' && stripos($apply['out'], 'is now empty') !== false) {
            sh('git ' . $undo, $dir);
            foreach ($dbs as $db => $bak) { @copy($bak, $db); @unlink($bak); }
            say('   already had every hunk — nothing to apply');
            $skipped++; say(''); continue;
        }
        sh('git ' . $undo, $dir);
        foreach ($dbs as $db => $bak) { @copy($bak, $db); @unlink($bak); }
        say("   ! FAILED — conflict, aborted and left untouched:");
        foreach (array_slice(explode("\n", $apply['out']), 0, 6) as $l) say('     ' . $l);
        $failed++; say(''); continue;
    }

    // Restore the live data over whatever the merge brought in.
    foreach ($dbs as $db => $bak) { @copy($bak, $db); @unlink($bak); }
    say('   ' . $verb);

    // 5. The classmap MUST be regenerated — see the header.
    $ca = sh('composer dump-autoload -q --no-interaction', $dir);
    say('   autoload: ' . ($ca['ok'] ? 'regenerated' : 'FAILED — ' . substr($ca['out'], 0, 120)));

    // 6a. Drop permission rows for controllers this upgrade REMOVED.
    //
    // Deliberately a named list, not a sweep of "every row whose controller file is
    // missing": a route can legitimately be served from somewhere other than controls/,
    // and deleting a live permission row would lock people out of a working page. Only
    // things core has actually deleted go here. A leftover row is not dangerous, but it
    // keeps the route gated — /grocery answered 303-to-login instead of 404 — and it
    // lingers in the permissions UI and the planner's authcontrol inventory.
    $removedControllers = ['grocery'];
    foreach (glob($dir . '/database/*.db') ?: [] as $db) {
        if (preg_match('/security\.db$/', $db)) continue;
        try {
            $pdo = new PDO('sqlite:' . $db);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $has = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='authcontrol'")->fetchColumn();
            if (!$has) continue;
            $in = implode(',', array_fill(0, count($removedControllers), '?'));
            $st = $pdo->prepare("DELETE FROM authcontrol WHERE LOWER(control) IN ($in)");
            $st->execute($removedControllers);
            if ($st->rowCount() > 0) say('   permissions: dropped ' . $st->rowCount() . ' row(s) for removed controller(s)');
        } catch (Throwable $e) {
            say('   ! permission cleanup skipped on ' . basename($db) . ': ' . $e->getMessage());
        }
    }

    // 6b. Rebuild the permission cache, then make a real request.
    if (is_file($dir . '/scripts/resetcache.php')) sh('php scripts/resetcache.php', $dir);
    $url  = 'https://' . $slug . '.tiknix.com/';
    $code = sh('curl -s -o /dev/null -w "%{http_code}" --max-time 20 ' . escapeshellarg($url));
    $http = (int) $code['out'];
    if ($http >= 200 && $http < 400) {
        say("   verified: $url -> $http");
        $done++;
    } else {
        say("   ! upgraded but the site answered $http — check it before trusting this one");
        $failed++;
    }
    say('');
}

say("upgraded: $done   skipped: $skipped   failed: $failed");
exit($failed > 0 ? 1 : 0);
