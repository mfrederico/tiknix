<?php
/**
 * pipeline-cron.php — the fake-cron TICK. One system crontab entry:
 *
 *   * * * * *  php /var/www/html/default/tiknix/scripts/pipeline-cron.php >> /var/log/tiknix-pipecron.log 2>&1
 *
 * It never executes a pipeline — it only TRIGGERS. Every minute it globs every
 * instance's `pipelines/*.json`, finds pipelines whose `trigger.cron` is due now,
 * and curls that instance's own `POST /pipeline/trigger/<slug>` (bearer = the
 * instance's [pipeline] trigger_secret). The trigger endpoint dispatches the jailed
 * background run. No jail/DB access here — just HTTP. A per-pipeline last-fired
 * marker prevents a double-fire within the same minute.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/../lib/Pipeline/Cron.php';

use app\Pipeline\Cron;

$BASE = '/var/www/html/default';
$now  = time();
$minute = date('Y-m-d H:i', $now);
$fired = 0; $checked = 0;

foreach (glob($BASE . '/*/pipelines/*.json') ?: [] as $file) {
    $instanceDir = dirname(dirname($file));           // …/<slug>.<app>
    $def = json_decode((string) @file_get_contents($file), true);
    if (!is_array($def)) continue;
    $cron = (string) ($def['trigger']['cron'] ?? '');
    if ($cron === '') continue;
    $checked++;
    if (!Cron::due($cron, $now)) continue;

    $slug = (string) ($def['slug'] ?? basename($file, '.json'));

    // Per-pipeline last-fired guard (one fire per matching minute).
    $markDir = $instanceDir . '/cache';
    @mkdir($markDir, 0775, true);
    $mark = $markDir . '/pipecron-' . preg_replace('/[^a-z0-9_-]/i', '', $slug) . '.last';
    if (trim((string) @file_get_contents($mark)) === $minute) continue;

    // The instance's own base URL + trigger secret.
    $ini = @parse_ini_file($instanceDir . '/conf/config.ini', true) ?: [];
    $base   = rtrim((string) ($ini['app']['baseurl'] ?? ''), '/');
    $secret = (string) ($ini['pipeline']['trigger_secret'] ?? '');
    if ($base === '' || $secret === '') { echo "[skip] {$slug}: no baseurl/trigger_secret\n"; continue; }

    $ch = curl_init($base . '/pipeline/trigger/' . rawurlencode($slug));
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $secret],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @file_put_contents($mark, $minute);
    $fired++;
    echo '[' . date('c', $now) . "] fired {$slug} @ {$base} -> HTTP {$code}\n";
}

echo '[' . date('c', $now) . "] tick: {$checked} cron pipeline(s) checked, {$fired} fired\n";
