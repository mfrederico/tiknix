<?php
/**
 * project-value.php — what each project would have cost as a custom build, from its lines of
 * code (lib/ProjectValue.php), cached for the Projects page.
 *
 *   php scripts/project-value.php            every project in a container (cron, nightly)
 *   php scripts/project-value.php --slug=X   one project
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }
require dirname(__DIR__) . '/vendor/autoload.php';
new \app\Bootstrap();

use app\Bean;
use app\ProjectValue;

$o = getopt('', ['slug:']);
try { $baseline = ProjectValue::baseline(); }
catch (\Throwable $e) { fwrite(STDERR, 'ERROR ' . $e->getMessage() . "\n"); exit(1); }
echo 'template baseline: ' . array_sum($baseline) . " lines\n";

$rows = isset($o['slug']) ? Bean::find('instance', 'slug = ?', [(string) $o['slug']]) : Bean::find('instance', "ct_kind = 'tenant' AND status = 'active' ORDER BY slug");
$failed = 0;
foreach ($rows as $inst) {
    if (!\Model_Instance::tenantRow($inst)) { echo "{$inst->slug}: not in a container, skipped\n"; continue; }
    try {
        $v = ProjectValue::compute($inst, $baseline);
        echo sprintf("%-28s %6d lines  %6.1f h  $%s%s\n", $inst->slug, $v['lines'], $v['hours'], number_format($v['dollars']),
            $v['tiknix'] ? '   (tiknix: ' . $v['tiknix']['tasks'] . ' tasks, ' . $v['tiknix']['minutes'] . ' min)' : '');
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "ERROR {$inst->slug}: " . $e->getMessage() . "\n");
    }
}
exit($failed ? 1 : 0);
