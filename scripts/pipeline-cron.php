<?php
/**
 * pipeline-cron.php — the minute HEARTBEAT. One system crontab entry on core:
 *
 *   * * * * *  php /var/www/html/default/tiknix/scripts/pipeline-cron.php >> /var/www/html/default/tiknix/log/tiknix-pipecron.log 2>&1
 *
 * It decides nothing and executes nothing. Every minute it POSTs `/pipeline/tick` to
 * every co-located install (bearer = that install's own [pipeline] trigger_secret) and
 * ticks core itself in-process; each install's Pipeline\Scheduler then reads ITS OWN
 * definitions — its pipelines/ and its enabled concepts' — fires the cron triggers due
 * this minute and wakes its durable objects. The app schedules itself; the platform only
 * knocks (COMPONENTS_PLAN.md, "Every app has its own /pipelines", section 4).
 *
 * It used to glob every install's pipelines/*.json, open their databases for concept
 * flags and POST /pipeline/trigger/<slug> per pipeline plus /pipeline/objecttick: the
 * platform reading other installs' files to make a decision only the app can make, and
 * no scheduling at all for an install on another host. A tenant elsewhere now runs the
 * same one-liner against its own /pipeline/tick from its own crontab.
 *
 * Which installs: the `instance` table (status active), plus core. An install whose
 * tree has no /pipeline/tick yet (not merged) is reported by name every minute rather
 * than quietly left unscheduled; the fix is to upgrade it. Fires in PARALLEL via
 * curl_multi; each answer is one log line, so a tick that fails says so.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

define('BASE_PATH', dirname(__DIR__));
chdir(BASE_PATH);
require_once BASE_PATH . '/bootstrap.php';
new \app\Bootstrap('conf/config.ini');

use app\Bean;
use app\Pipeline\Scheduler;

const CONCURRENCY = 5;
$now = time();

// --- 1) core itself, in-process: it is an install too --------------------------------
try {
    $r = Scheduler::tick(BASE_PATH, $now);
    echo '[' . date('c', $now) . "] core: {$r['checked']} cron pipeline(s), " . count($r['fired']) . ' fired, '
       . (int) ($r['objects']['fired'] ?? 0) . " object(s)\n";
} catch (\Throwable $e) {
    echo '[' . date('c', $now) . '] core: TICK FAILED — ' . $e->getMessage() . "\n";
}

// --- 2) every co-located instance, over HTTP with its own secret ----------------------
$targets = []; $skips = [];
foreach (Bean::find('instance', "status = 'active' ORDER BY slug") as $inst) {
    $slug = (string) $inst->slug;
    $dir  = \Model_Instance::dirFrom($slug, (string) ($inst->app ?: ''));
    if (!is_dir($dir)) { $skips[] = "{$slug}: no directory at {$dir}"; continue; }
    if (realpath($dir) === realpath(BASE_PATH)) continue;   // core, ticked above
    $ini = @parse_ini_file($dir . '/conf/config.ini', true);
    if (!is_array($ini)) { $skips[] = "{$slug}: conf/config.ini could not be parsed"; continue; }
    $base   = rtrim((string) ($ini['app']['baseurl'] ?? ''), '/');
    $secret = (string) ($ini['pipeline']['trigger_secret'] ?? '');
    if ($base === '' || $secret === '') { $skips[] = "{$slug}: no [app] baseurl or [pipeline] trigger_secret"; continue; }
    // A tree without the endpoint answers 404 to every knock; say why, by name.
    $ctrl = @file_get_contents($dir . '/controls/Pipeline.php');
    if ($ctrl === false || strpos($ctrl, 'function tick(') === false) { $skips[] = "{$slug}: no /pipeline/tick yet — merge core into it"; continue; }
    $targets[] = ['slug' => $slug, 'url' => $base . '/pipeline/tick', 'secret' => $secret];
}

$results = [];
foreach (array_chunk($targets, CONCURRENCY) as $batch) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($batch as $t) {
        $ch = curl_init($t['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 50, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $t['secret']],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = [$t, $ch];
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    foreach ($handles as [$t, $ch]) {
        $body = (string) curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $d = json_decode($body, true);
        if ($code === 200 && is_array($d)) {
            $results[] = "{$t['slug']}: {$d['checked']} cron, " . count($d['fired'] ?? []) . ' fired, ' . (int) ($d['objects']['fired'] ?? 0) . ' object(s)'
                       . (($d['fired'] ?? []) ? ' [' . implode(', ', array_column($d['fired'], 'slug')) . ']' : '');
        } else {
            $results[] = "{$t['slug']}: TICK FAILED HTTP {$code} " . substr(trim($body) ?: curl_error($ch), 0, 140);
        }
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
}

foreach ($results as $line) echo "  {$line}\n";
foreach ($skips as $line)   echo "  [skip] {$line}\n";
echo '[' . date('c') . '] heartbeat: ' . count($targets) . ' instance(s) ticked, ' . count($skips) . " skipped\n";
