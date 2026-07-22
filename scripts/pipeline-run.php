<?php
/**
 * pipeline-run.php — the background worker the Dispatcher spawns (jailed on capricorn
 * instances). Loads a queued piperun and executes it to completion, updating the DB.
 *
 *   php scripts/pipeline-run.php --run=<id>
 *
 * The Dispatcher runs this detached so a long pipeline (esp. `agent` steps) never
 * blocks the web/MCP request that started it.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use app\Pipeline\Executor;

$runId = 0;
foreach ($argv as $a) { if (preg_match('/^--run=(\d+)$/', $a, $m)) $runId = (int) $m[1]; }
if ($runId <= 0) { fwrite(STDERR, "usage: pipeline-run.php --run=<id>\n"); exit(2); }

$root = dirname(__DIR__);   // the app/instance root this worker runs in
try {
    $r = (new Executor($root))->resume($runId);
    echo '[' . date('c') . "] run {$runId}: {$r['status']} ({$r['steps_done']} steps)\n";
    exit($r['status'] === 'completed' || $r['status'] === 'paused' ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('c') . "] run {$runId} error: " . $e->getMessage() . "\n");
    exit(1);
}
