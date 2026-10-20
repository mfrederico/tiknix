<?php
/**
 * git-sync.php — keep a project and its GitHub repository in step (app\GitSync), in the background.
 *
 *   --pull  --instance=ID            bring in what was pushed to the repository's branch
 *   --push  --instance=ID [--plan=N] push the app's main (with --plan: as that request's pull request)
 *   --after --instance=ID  --plan=N  a request just ended: changes waiting on GitHub come in, then it goes out
 *
 * Started by GitHub's webhook (Githook), by the end of a request (plan-orchestrate.php, AuditReporter)
 * and by the Deploy page. A pull that finds the project building waits: it is tried again every two
 * minutes for an hour, and the end of the next request brings it in regardless.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require_once \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap();

$o = getopt('', ['pull', 'push', 'after', 'instance:', 'plan::', 'try::']);
$inst = \app\Bean::load('instance', (int) ($o['instance'] ?? 0));
$say = fn(string $s) => print(date('c') . " [git-sync] {$s}\n");
if (!$inst->id) { fwrite(STDERR, date('c') . " [git-sync] ERROR no instance #" . (int) ($o['instance'] ?? 0) . "\n"); exit(2); }
$plan = (int) ($o['plan'] ?? 0);

try {
    if (isset($o['after'])) {
        foreach (\app\GitSync::afterRequest($inst, $plan) as $line) $say("{$inst->slug} request #{$plan} — {$line}");
    } elseif (isset($o['push'])) {
        $r = \app\GitSync::push($inst, $plan ?: null);
        if (!empty($r['needsPull'])) { $p = \app\GitSync::pull($inst); $say("{$inst->slug} pull — {$p['message']}"); if (!empty($p['ok']) && empty($p['deferred'])) $r = \app\GitSync::push($inst, $plan ?: null); }
        $say("{$inst->slug} push — {$r['message']}");
    } elseif (isset($o['pull'])) {
        $r = \app\GitSync::pull($inst);
        $say("{$inst->slug} pull — {$r['message']}");
        $try = (int) ($o['try'] ?? 0);
        if (!empty($r['deferred']) && $try < 30) {
            // the project is building: once more in two minutes (detached, so this run ends now)
            exec('nohup sh -c ' . escapeshellarg('sleep 120; exec env -u TIKNIX_WORKBENCH_DB php ' . escapeshellarg(__FILE__) . ' --pull --instance=' . (int) $inst->id . ' --try=' . ($try + 1))
                . ' >> ' . escapeshellarg(\Model_Instance::dirOf($inst) . '/.aibuilder/gitsync.log') . ' 2>&1 &');
        }
    } else {
        fwrite(STDERR, "usage: --pull|--push|--after --instance=ID [--plan=N]\n"); exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, date('c') . " [git-sync] ERROR {$inst->slug}: " . $e->getMessage() . "\n");
    exit(1);
}
