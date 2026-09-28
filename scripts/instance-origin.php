<?php
/**
 * instance-origin.php — give an instance its ORIGIN: the bare repository builds land on and
 * the live tree (and a tenant container) pull from. CONNECTOR-CATALOG-PLAN.md §13 cutover C1;
 * lib/InstanceRepo.php has the shape.
 *
 *   php scripts/instance-origin.php --slug=serenity-bbdc01      one instance
 *   php scripts/instance-origin.php --all                        every provisioned instance without one
 *   php scripts/instance-origin.php --all --dry-run              say what would be done
 *
 * Idempotent: an instance that already has an origin is reported and left alone. What it does
 * per instance: bare clone of the live tree → _origins/<slug>.git (HEAD on instance/<slug>),
 * a merge worktree, the pool user's ACL on both, and the live tree's remotes re-pointed
 * (`origin` → the bare repository; what was origin — the control plane — becomes `core`).
 * Nothing in the live tree's history changes.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap.php';
new \app\Bootstrap();

use app\Bean;
use app\InstanceRepo;
use app\IsolatedPool;

$opt = getopt('', ['slug:', 'all', 'dry-run', 'help']);
if (isset($opt['help']) || (!isset($opt['slug']) && !isset($opt['all']))) {
    fwrite(STDERR, "usage: php scripts/instance-origin.php --slug=SLUG | --all [--dry-run]\n");
    exit(2);
}
$dry = isset($opt['dry-run']);

$targets = [];
if (isset($opt['slug'])) {
    $inst = Bean::findOne('instance', 'slug = ?', [(string) $opt['slug']]);
    if (!$inst || !$inst->id) { fwrite(STDERR, "no instance row for slug '{$opt['slug']}'\n"); exit(1); }
    $targets[] = $inst;
} else {
    foreach (Bean::find('instance', "status = 'active' ORDER BY slug") as $inst) $targets[] = $inst;
}

$failed = 0;
foreach ($targets as $inst) {
    $slug = (string) $inst->slug;
    if (!empty($inst->isDefault)) { echo "  {$slug}: the control plane itself — skipped\n"; continue; }
    try {
        $dir = \Model_Instance::dirFrom($slug, (string) ($inst->app ?? ''));
    } catch (\Throwable $e) {
        echo "  {$slug}: " . $e->getMessage() . "\n"; $failed++; continue;
    }
    if (!\Model_Instance::isProvisionedInstance($dir)) { echo "  {$slug}: {$dir} is not a provisioned instance tree — skipped\n"; continue; }
    if (InstanceRepo::hasOrigin($slug)) { echo "  {$slug}: already has " . InstanceRepo::originPath($slug) . "\n"; continue; }
    $pool = '';
    try { $pool = IsolatedPool::user($dir); } catch (\Throwable $e) { echo "  {$slug}: " . $e->getMessage() . "\n"; $failed++; continue; }
    if ($dry) { echo "  {$slug}: would create " . InstanceRepo::originPath($slug) . " from {$dir}" . ($pool !== '' ? " (ACL for {$pool})" : '') . "\n"; continue; }
    try {
        foreach (InstanceRepo::createOrigin($slug, $dir, $pool !== '' ? $pool : null) as $step) echo "  {$slug}: {$step}\n";
    } catch (\Throwable $e) {
        echo "  {$slug}: FAILED — " . $e->getMessage() . "\n"; $failed++;
    }
}
exit($failed ? 1 : 0);
