#!/usr/bin/env php
<?php
/**
 * provision-sweep.php — remove a project whose container setup never finished.
 *
 * ProvisionService::create writes the project's row and starts `tenant.php --up` in the
 * background; about two minutes later the row has a container and a domain. When the setup
 * ERRORs, the log says so and the project card shows it. When the setup DIES — the box
 * rebooted, the process was killed, an ssh hung for good — nothing says anything: the row
 * stays a project with no address, the member sees a card that is forever "setting up",
 * and the container, origin and workspace it got as far as making sit there.
 *
 * Such a project holds nothing of the member's — no code, no data, no plan ever ran in it
 * (none of that is possible before the domain exists). What it holds is their time and a
 * slot in their project count. So: a tenant row with no domain, older than --after
 * (default one hour; a normal setup is two minutes, the longest single step is capped at
 * fifteen) and whose setup log has not moved for Model_Instance::SETUP_STALL_SECONDS, is
 * removed — through ProvisionService::delete, the one delete there is (the same archive to
 * secure/archives, the same container/origin/workspace/proxy/row removal a person's delete
 * does), acting as the system admin. A setup process still alive at that point is hung, and
 * is ended first (its whole process group — ProvisionService::setUpContainer runs it as its
 * own session for exactly this). The owner gets a Note saying what happened and where it
 * stopped; the app log gets an ERROR line per project. Nothing here is quiet.
 *
 * Both conditions, deliberately: age alone would catch a setup that is slow but moving;
 * staleness alone would catch a container mid-creation.
 *
 *   php scripts/provision-sweep.php                  # report: what would go, and why
 *   php scripts/provision-sweep.php --apply
 *   php scripts/provision-sweep.php --apply --after=3600
 *
 * Runs every ten minutes from core's pipelines/provision-sweep.json.
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }

use app\Bean;
use app\ProvisionService;
use app\Notes;

require_once __DIR__ . '/../vendor/autoload.php';
new \app\Bootstrap();

$o     = getopt('', ['apply', 'after::']);
$apply = isset($o['apply']);
$after = isset($o['after']) ? (int) $o['after'] : 3600;
if ($after < 0) { fwrite(STDERR, "ERROR --after must be seconds >= 0\n"); exit(2); }
if (!defined('SYSTEM_ADMIN_ID') || SYSTEM_ADMIN_ID <= 0) { fwrite(STDERR, "ERROR no system admin to act as (SYSTEM_ADMIN_ID)\n"); exit(2); }

$cutoff = date('Y-m-d H:i:s', time() - $after);
$rows = Bean::find('instance',
    "ct_kind = 'tenant' AND (ct_domain IS NULL OR ct_domain = '') AND (is_default IS NULL OR is_default = 0)"
    . " AND (status IS NULL OR status != 'deleted') AND created_at < ?", [$cutoff]);

$log = \Flight::get('log');
$svc = new ProvisionService();
$removed = 0; $kept = 0; $failed = 0;

foreach ($rows as $inst) {
    $slug = (string) $inst->slug;
    $rep  = \Model_Instance::setupReport($inst);
    if ($rep['state'] === 'active') continue;   // the domain arrived between the query and now
    $why = $rep['state'] === 'failed' ? $rep['error'] : "no record of a setup — row made {$inst->createdAt}";
    if ($rep['state'] === 'pending') {
        // Older than --after but the log is still moving: slow, not stuck. Say so, leave it.
        echo "keep   {$slug}: setup still moving (idle {$rep['idle']}s, last: {$rep['last']})\n";
        $kept++;
        continue;
    }
    echo ($apply ? 'remove ' : 'would remove ') . "{$slug} (#{$inst->id}, owner #{$inst->memberId}, created {$inst->createdAt}, container " . ((int) $inst->ctVmid ?: '-') . "): {$why}\n";
    if (!$apply) continue;

    if ($rep['running'] && $rep['pid'] > 0) {
        // The hung setup, whole group (setsid made the pid the pgid). TERM, a moment, KILL.
        posix_kill(-$rep['pid'], SIGTERM);
        usleep(1500000);
        if (posix_kill($rep['pid'], 0)) posix_kill(-$rep['pid'], SIGKILL);
        echo "  ended setup process group {$rep['pid']}\n";
    }

    $name = (string) ($inst->displayName ?: $slug);
    $owner = (int) $inst->memberId;
    $res = $svc->delete((int) SYSTEM_ADMIN_ID, ['id' => (int) $inst->id, 'confirm' => $svc->confirmPhrase($slug)]);
    if (empty($res['ok'])) {
        $failed++;
        echo "  ERROR delete refused: {$res['error']}\n";
        $log->error('provision sweep: could not remove a stuck project', ['slug' => $slug, 'why' => $why, 'error' => $res['error']]);
        continue;
    }
    $removed++;
    foreach ((array) ($res['steps'] ?? []) as $s) echo "  {$s}\n";
    $log->error('provision sweep: removed a project whose container setup never finished', ['slug' => $slug, 'owner' => $owner, 'why' => $why, 'after' => $after]);

    if ($owner > 0 && Bean::count('member', 'id = ?', [$owner]) > 0) {
        $html = '<p>Setting up the container for your project <strong>' . htmlspecialchars($name) . '</strong> did not finish, so the project was removed'
              . ' (it had nothing in it yet — no code, no data).</p>'
              . '<p>Where it stopped: <code>' . htmlspecialchars($why) . '</code></p>'
              . '<p>Create it again from Projects; if it stops the same way, <a href="/helpdesk">ask support</a> and quote this note.</p>';
        try {
            Notes::system($owner, "Project {$name} could not be set up and was removed", $html);
            echo "  noted owner #{$owner}\n";
        } catch (\Throwable $e) {
            echo "  ERROR could not note owner #{$owner}: {$e->getMessage()}\n";
            $log->error('provision sweep: owner not notified', ['slug' => $slug, 'owner' => $owner, 'error' => $e->getMessage()]);
        }
    }
}

echo '[' . date('c') . "] provision sweep: " . count($rows) . " candidate(s) older than {$after}s, {$removed} removed, {$kept} still moving, {$failed} failed" . ($apply ? '' : ' (report only)') . "\n";
exit($failed ? 1 : 0);
