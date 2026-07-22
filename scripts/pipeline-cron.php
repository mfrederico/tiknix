<?php
/**
 * pipeline-cron.php — the fake-cron TICK. One system crontab entry:
 *
 *   * * * * *  php /var/www/html/default/tiknix/scripts/pipeline-cron.php >> /var/log/tiknix-pipecron.log 2>&1
 *
 * It never executes a pipeline — it only TRIGGERS. Every minute it globs every
 * instance's `pipelines/*.json`, finds pipelines whose `trigger.cron` is due now, and
 * fires that instance's own `POST /pipeline/trigger/<slug>` (bearer = the instance's
 * [pipeline] trigger_secret). The trigger endpoint dispatches the jailed background
 * run. Fires in PARALLEL via curl_multi (CONCURRENCY at a time), fire-and-forget —
 * the response is ignored. A per-pipeline last-fired marker (written BEFORE firing)
 * prevents a double-fire within the same minute.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/../lib/Pipeline/Cron.php';

use app\Pipeline\Cron;

const CONCURRENCY = 5;

$BASE = '/var/www/html/default';
$now  = time();
$minute = date('Y-m-d H:i', $now);

// --- 1) collect the due jobs (and claim them with the last-fired marker) --------
$jobs = []; $checked = 0;
foreach (glob($BASE . '/*/pipelines/*.json') ?: [] as $file) {
    $instanceDir = dirname(dirname($file));
    $def = json_decode((string) @file_get_contents($file), true);
    if (!is_array($def)) continue;
    $cron = (string) ($def['trigger']['cron'] ?? '');
    if ($cron === '') continue;
    $checked++;
    if (!Cron::due($cron, $now)) continue;

    $slug = (string) ($def['slug'] ?? basename($file, '.json'));
    $markDir = $instanceDir . '/cache';
    @mkdir($markDir, 0775, true);
    $mark = $markDir . '/pipecron-' . preg_replace('/[^a-z0-9_-]/i', '', $slug) . '.last';
    if (trim((string) @file_get_contents($mark)) === $minute) continue;   // already fired this minute

    $ini = @parse_ini_file($instanceDir . '/conf/config.ini', true) ?: [];
    $base   = rtrim((string) ($ini['app']['baseurl'] ?? ''), '/');
    $secret = (string) ($ini['pipeline']['trigger_secret'] ?? '');
    if ($base === '' || $secret === '') { echo "[skip] {$slug}: no baseurl/trigger_secret\n"; continue; }

    @file_put_contents($mark, $minute);   // claim BEFORE firing so a slow tick can't double-fire
    $jobs[] = ['url' => $base . '/pipeline/trigger/' . rawurlencode($slug), 'secret' => $secret, 'slug' => $slug];
}

// --- 2) fire in parallel batches, fire-and-forget (response ignored) -------------
$fired = 0;
foreach (array_chunk($jobs, CONCURRENCY) as $batch) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($batch as $j) {
        $ch = curl_init($j['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $j['secret']],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
        $fired++;
    }
    // Drive the batch to completion (the trigger returns 'queued' fast); ignore bodies.
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $st === CURLM_OK);
    foreach ($handles as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
}

echo '[' . date('c', $now) . "] tick: {$checked} cron pipeline(s) checked, {$fired} fired ("
   . ceil(count($jobs) / CONCURRENCY) . " batch(es) of " . CONCURRENCY . ")\n";
