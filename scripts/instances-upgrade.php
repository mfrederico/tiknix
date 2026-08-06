#!/usr/bin/env php
<?php
/**
 * instances-upgrade.php — bring every instance up to core's main, and SAY whether
 * it worked.
 *
 * This loop was run by hand six times on 2026-08-06 and went wrong twice, both
 * times silently:
 *
 *   - A stale composer.lock made `composer install` skip a newly required package
 *     with a warning nobody was reading. Ten instances merged the code for the
 *     OpenAPI importer and none of them could parse YAML.
 *   - One instance reported "Already up to date" because a `git fetch` had been
 *     missed, so it looked upgraded and was a commit behind.
 *
 * Both are the same failure: a step that half-worked and reported success. So this
 * script fetches first, always, and VERIFIES after — it does not trust its own
 * commands, it re-reads the result and reports per instance.
 *
 * Dry by default.
 *
 *   php scripts/instances-upgrade.php                  # what would happen
 *   php scripts/instances-upgrade.php --apply
 *   php scripts/instances-upgrade.php --apply --only=mileage,pd
 *   php scripts/instances-upgrade.php --verify         # just report drift
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }

$opt    = getopt('', ['apply', 'verify', 'only::', 'root::']);
$apply  = isset($opt['apply']);
$verify = isset($opt['verify']);
$core   = rtrim((string) ($opt['root'] ?? dirname(__DIR__)), '/');
$only   = array_filter(array_map('trim', explode(',', (string) ($opt['only'] ?? ''))));

$base = dirname($core);   // /var/www/html/default

function run(string $dir, string $cmd, int $timeout = 300): array {
    $out = []; $code = 0;
    exec('cd ' . escapeshellarg($dir) . ' && timeout ' . $timeout . ' ' . $cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

echo $verify ? "verifying instances\n" : ($apply ? "upgrading instances\n" : "DRY RUN — nothing will change (pass --apply)\n");

// An instance is a sibling directory whose git origin is THIS core checkout. That
// is the definition used everywhere else, and it keeps unrelated clones — docs,
// sidecars, other apps — out of the sweep.
$targets = [];
foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if ($dir === $core || !is_dir("$dir/.git")) continue;
    [$c, $origin] = run($dir, 'git remote get-url origin');
    if ($c !== 0 || strpos(trim($origin), $core) === false) continue;
    $slug = basename($dir);
    if ($only && !in_array(preg_replace('/\.[^.]+$/', '', $slug), $only, true) && !in_array($slug, $only, true)) continue;
    $targets[$slug] = $dir;
}

if (!$targets) { echo "  no instances found under {$base} pointing at {$core}\n"; exit(0); }
printf("  %d instance(s)\n\n", count($targets));

$problems = 0;
foreach ($targets as $slug => $dir) {
    $notes = [];

    // ALWAYS fetch first — including on a dry run. "Already up to date" against a
    // stale remote ref is the most convincing wrong answer this script can give,
    // and the first version of it made exactly that mistake: it skipped the fetch
    // when not applying, so a dry run reported every instance current when several
    // were behind. Fetching updates remote refs and touches nothing in the working
    // tree, so there is no reason to withhold it from a dry run.
    run($dir, 'git fetch origin main -q');

    [, $behindRaw] = run($dir, 'git rev-list --count HEAD..origin/main');
    $behind = (int) trim($behindRaw);

    if ($verify || !$apply) {
        [, $dirty] = run($dir, 'git status --porcelain --untracked-files=no');
        $dirtyN = $dirty === '' ? 0 : count(explode("\n", trim($dirty)));
        printf("  %-28s behind=%-3d dirty=%d\n", $slug, $behind, $dirtyN);
        if ($behind > 0) $problems++;
        continue;
    }

    // Checkpoint whatever the running site wrote, so the merge has a clean tree.
    // The live database is tracked and reads modified perpetually; committing it is
    // the checkpoint, never a revert.
    run($dir, 'git add -A database data conf pipelines >/dev/null 2>&1');
    run($dir, 'git commit -q -m ' . escapeshellarg('Checkpoint before upgrade') . ' >/dev/null 2>&1');

    [$mc, $mout] = run($dir, 'git merge origin/main --no-edit');
    [, $conflicts] = run($dir, 'git diff --name-only --diff-filter=U');
    if (trim($conflicts) !== '') {
        printf("  %-28s CONFLICT — resolve by hand:\n", $slug);
        foreach (explode("\n", trim($conflicts)) as $f) echo "      {$f}\n";
        $problems++;
        continue;
    }
    if ($mc !== 0) {
        printf("  %-28s MERGE FAILED: %s\n", $slug, substr(trim($mout), 0, 120));
        $problems++;
        continue;
    }

    // Dependencies. The instance's lock is replaced with core's BEFORE installing:
    // a lock that predates a newly required package makes composer warn and carry
    // on, which is how ten instances ended up without symfony/yaml.
    if (is_file("$core/composer.lock")) {
        @copy("$core/composer.lock", "$dir/composer.lock");
    }
    [$cc, $cout] = run($dir, 'COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction', 400);
    if ($cc !== 0) $notes[] = 'composer install failed: ' . substr(trim($cout), -120);

    // ---- VERIFY. The point of the script: re-read, do not trust.
    [, $behindAfter] = run($dir, 'git rev-list --count HEAD..origin/main');
    if ((int) trim($behindAfter) !== 0) $notes[] = 'still ' . trim($behindAfter) . ' behind after merge';

    // Every package core requires must actually be present. This is the check that
    // would have caught the silent skip.
    $req = json_decode((string) @file_get_contents("$core/composer.json"), true)['require'] ?? [];
    foreach (array_keys($req) as $pkg) {
        if (strpos($pkg, '/') === false) continue;         // php, ext-*
        if (!is_dir("$dir/vendor/$pkg")) $notes[] = "missing vendor/{$pkg}";
    }

    // The app has to actually boot. A merge that leaves a parse error is worse than
    // one that conflicts, because nothing announces it until a visitor arrives.
    [$lc, $lout] = run($dir, 'php -r ' . escapeshellarg('require "vendor/autoload.php"; require "bootstrap.php"; new app\Bootstrap("conf/config.ini"); echo "ok";'), 60);
    if (strpos($lout, 'ok') === false) $notes[] = 'app does not boot: ' . substr(trim($lout), 0, 100);

    if ($notes) {
        $problems++;
        printf("  %-28s PROBLEMS\n", $slug);
        foreach ($notes as $n) echo "      - {$n}\n";
    } else {
        printf("  %-28s ok\n", $slug);
    }
}

echo "\n";
if (!$apply && !$verify) { echo "  dry run — pass --apply to upgrade\n"; exit(0); }
printf("  %d instance(s) need attention\n", $problems);
exit($problems > 0 ? 1 : 0);
