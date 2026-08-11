<?php
/**
 * repair-selfauth-permissions.php — fix instances whose self-authenticating routes were
 * auto-pinned to ADMIN.
 *
 *   php scripts/repair-selfauth-permissions.php            # report only
 *   php scripts/repair-selfauth-permissions.php --fix      # apply
 *   php scripts/repair-selfauth-permissions.php --fix --slug=test1-ec34d9
 *
 * WHY THIS EXISTS. PermissionCache auto-generates a missing authcontrol row on FIRST
 * REQUEST, defaulting to ADMIN. That is right for a UI route and wrong for one that
 * carries its own credential: /pipeline/trigger, /pipeline/api and friends verify a
 * bearer token in the controller, so they must be REACHABLE for that check to run. Pinned
 * to ADMIN they answer a bearer-token client with a 303 to /auth/login — which reads as a
 * broken endpoint, not a permission problem, and cost real debugging time.
 *
 * defaultLevelFor() now knows about these routes, so newly provisioned instances are
 * correct. This repairs the ones that already served a request and wrote the bad row.
 *
 * Idempotent, and it only ever LOWERS these specific routes to PUBLIC — it will not touch
 * any other control, and it never raises a level.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/../vendor/autoload.php';   // Model_Instance (classmapped)

/** control => methods that authenticate themselves and must be reachable. */
const SELF_AUTHED = [
    'pipeline' => ['trigger', 'api', 'status', 'debug', 'debugstep', 'object', 'objecttick', 'mintkey'],
    'mcp'      => ['message', 'health', 'registry'],
];
const PUBLIC_LEVEL = 101;

$opt  = getopt('', ['fix', 'slug::']);
$fix  = isset($opt['fix']);
$only = (string) ($opt['slug'] ?? '');

$dirs = glob('/var/www/html/default/*.tiknix') ?: [];
$totalBad = 0; $totalFixed = 0; $scanned = 0;
// Instance directories that actually had a row changed — the ones whose permission
// cache is now stale and has to be rebuilt below.
$repaired = [];

foreach ($dirs as $dir) {
    // core.tiknix is a SYMLINK to the control plane, and the glob above matches it —
    // so this would rewrite permission rows in CORE's database as if it were a customer
    // project. Structure, not name: see Model_Instance::isProvisionedInstance().
    if (!\Model_Instance::isProvisionedInstance($dir)) continue;

    $slug = preg_replace('/\.tiknix$/', '', basename($dir));
    if ($only !== '' && $slug !== $only) continue;

    $dbs = glob($dir . '/database/*.db') ?: [];
    foreach ($dbs as $db) {
        // Skip the sidecar/security databases — only the app DB carries authcontrol.
        if (preg_match('/(security|workbench)\.db$/', $db)) continue;
        try {
            $pdo = new PDO('sqlite:' . $db);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='authcontrol'")->fetchColumn();
            if (!$has) continue;
        } catch (Throwable $e) { continue; }

        $scanned++;
        $bad = [];
        foreach (SELF_AUTHED as $control => $methods) {
            $ph = implode(',', array_fill(0, count($methods), '?'));
            $st = $pdo->prepare("SELECT id, method, level FROM authcontrol
                                 WHERE LOWER(control) = ? AND LOWER(method) IN ($ph) AND level < ?");
            $st->execute(array_merge([$control], $methods, [PUBLIC_LEVEL]));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bad[] = ['id' => (int) $r['id'], 'control' => $control, 'method' => $r['method'], 'level' => (int) $r['level']];
            }
        }
        if (!$bad) continue;

        echo "\n" . $slug . '  (' . basename($db) . ")\n";
        foreach ($bad as $b) {
            echo sprintf("  %-10s %-12s %d -> %d%s\n", $b['control'], $b['method'], $b['level'], PUBLIC_LEVEL, $fix ? '' : '   [dry run]');
            $totalBad++;
            if ($fix) {
                $up = $pdo->prepare('UPDATE authcontrol SET level = ? WHERE id = ?');
                $up->execute([PUBLIC_LEVEL, $b['id']]);
                $totalFixed++;
                $repaired[$dir] = true;
            }
        }
    }
}

echo "\nscanned {$scanned} instance database(s); {$totalBad} bad row(s)"
   . ($fix ? ", {$totalFixed} fixed.\n" : ". Re-run with --fix to apply.\n");

// RESET THE CACHE OURSELVES, rather than printing a reminder.
//
// These rows are written with raw SQL on purpose: the FUSE hook that would
// normally invalidate the cache (Model_Authcontrol::after_update) reaches for the
// APP's logger and cache, and this script is core acting on another install's
// database — the wrong app entirely. So the hook cannot run here, and the cache
// has to be rebuilt some other way.
//
// It used to print "now run resetcache.php" and stop. Until somebody did, the
// repaired instance carried on enforcing the OLD level while the script reported
// rows fixed — a repair that says it worked and changes nothing a visitor can see
// is worse than one that fails. Each instance's own resetcache.php is run, in that
// instance, which is where its cache lives.
if ($fix && $repaired) {
    echo "\nrebuilding permission caches:\n";
    foreach (array_keys($repaired) as $dir) {
        $script = $dir . '/scripts/resetcache.php';
        if (!is_file($script)) {
            printf("  %-28s ! no resetcache.php — reset it by hand\n", basename($dir));
            continue;
        }
        exec('cd ' . escapeshellarg($dir) . ' && php ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        printf("  %-28s %s\n", basename($dir), $code === 0 ? 'cache rebuilt' : 'FAILED: ' . trim(implode(' ', array_slice($out, -2))));
        $out = [];
    }
}
