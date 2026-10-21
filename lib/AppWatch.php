<?php
/**
 * AppWatch — notices an app that has gone quiet, and starts it again when its container stopped by
 * itself.
 *
 * Every app reports in hourly (its status-report pipeline → instance.last_reported_at). On 2026-10-17
 * start-201e11's container stopped when systemd inside it crashed; nothing noticed for three and a
 * half days. Every ten minutes (scripts/app-watch.php, cron) each active app that has not reported for
 * QUIET seconds is looked at in Proxmox:
 *
 *   stopped, and nobody stopped it      started again; the platform's admins are told
 *   stopped by someone (a stop task)    left stopped; they are told, once
 *   stopped again soon after a restart  left stopped (it will not stay up); they are told, once
 *   running but still quiet             they are told, once — something inside it is wrong
 *
 * and when it reports again they are told it is back. One email per change, never one per run.
 * decide() is the whole policy, with no Proxmox or mail in it, so it can be tested.
 */
namespace app;

class AppWatch {

    /** An app silent longer than this (it reports every hour) is looked at. */
    public const QUIET = 2 * 3600 + 15 * 60;
    /** Restarts within this window that make a stop "will not stay up" rather than a one-off. */
    public const RESTART_WINDOW = 3 * 3600;
    public const MAX_RESTARTS = 2;

    /**
     * What to do about one app. $f:
     *   quiet     seconds since it last reported (null: it never has)
     *   ct        'running' | 'stopped' | other Proxmox status | 'missing'
     *   stoppedBy who stopped it through Proxmox since it last reported, '' when nobody did
     *   restarts  how many times this watcher started it within RESTART_WINDOW
     *   sinceRestart  seconds since this watcher last started it (null: it has not)
     *   state     what was last decided: ok | restarted | stopped_on_purpose | wont_stay_up | silent | missing
     * @return string ok | recovered | restart | stopped_on_purpose | wont_stay_up | silent | missing | wait
     *         (wait: still the same trouble, already told)
     */
    public static function decide(array $f): string {
        $state = (string) ($f['state'] ?? 'ok');
        $quiet = $f['quiet'] ?? null;
        if ($quiet !== null && $quiet <= self::QUIET) return $state === 'ok' ? 'ok' : 'recovered';
        $ct = (string) ($f['ct'] ?? '');
        if ($ct === 'missing') return $state === 'missing' ? 'wait' : 'missing';
        if ($ct === 'stopped') {
            if ((string) ($f['stoppedBy'] ?? '') !== '') return $state === 'stopped_on_purpose' ? 'wait' : 'stopped_on_purpose';
            if ((int) ($f['restarts'] ?? 0) >= self::MAX_RESTARTS) return $state === 'wont_stay_up' ? 'wait' : 'wont_stay_up';
            return 'restart';
        }
        // running (or starting) and still quiet: just after a restart, give it a report's time first
        if ($state === 'restarted' && ($f['sinceRestart'] ?? null) !== null && $f['sinceRestart'] < self::QUIET) return 'wait';
        return $state === 'silent' ? 'wait' : 'silent';
    }

    /** Look at every active app once. @return string[] what was done, one line each */
    public static function run(): array {
        $said = [];
        $pve = ProxmoxService::fromConfig();
        if (!$pve) return ['ERROR app-watch: Proxmox is not configured (conf/proxmox.ini) — no app was looked at'];
        $node = $pve->node();
        foreach (Bean::find('instance', "ct_kind = 'tenant' AND ct_domain <> '' AND status = 'active' AND ct_vmid > 0 ORDER BY slug") as $inst) {
            try { $line = self::one($pve, $node, $inst); if ($line !== '') $said[] = $line; }
            catch (\Throwable $e) { $said[] = "ERROR app-watch {$inst->slug}: " . $e->getMessage(); }
        }
        return $said;
    }

    private static function one(ProxmoxService $pve, string $node, object $inst): string {
        $w = Bean::findOne('appwatch', 'instance_ref = ?', [(int) $inst->id]) ?: Bean::dispense('appwatch');
        if (!$w->id) { $w->instanceRef = (int) $inst->id; $w->state = 'ok'; $w->restarts = '[]'; }
        $last = (string) ($inst->lastReportedAt ?? '');
        $lastTs = $last !== '' ? strtotime($last . ' UTC') : false;
        $quiet = $lastTs ? time() - $lastTs : null;
        $restarts = array_values(array_filter((array) (json_decode((string) $w->restarts, true) ?: []), fn($t) => (int) $t > time() - self::RESTART_WINDOW));

        $f = ['quiet' => $quiet, 'state' => (string) $w->state, 'restarts' => count($restarts), 'ct' => '', 'stoppedBy' => '',
              'sinceRestart' => $restarts ? time() - (int) max($restarts) : null];
        if ($quiet === null || $quiet > self::QUIET) {
            $f['ct'] = $pve->ctExists($node, (int) $inst->ctVmid) ? (string) ($pve->ctStatus($node, (int) $inst->ctVmid)['status'] ?? '') : 'missing';
            if ($f['ct'] === 'stopped') $f['stoppedBy'] = self::stoppedBy($pve, $node, (int) $inst->ctVmid, $lastTs ?: time() - 7 * 86400);
        }
        $act = self::decide($f);
        $name = $inst->displayName ?: $inst->slug;
        $since = $last !== '' ? $last . ' UTC' : 'never';
        $msg = '';
        switch ($act) {
            case 'ok': case 'wait': break;
            case 'recovered':
                $msg = "{$name} is reporting again (last report {$since}).";
                // after a restart the restart email already said so: no second one
                if ((string) $w->state !== 'restarted') self::tell("{$name} is back", $msg);
                $w->state = 'ok'; $w->since = ''; $w->toldAt = date('Y-m-d H:i:s');
                break;
            case 'restart':
                $r = $pve->startCt($node, (int) $inst->ctVmid);
                $ok = self::waitRunning($pve, $node, (int) $inst->ctVmid, 90);
                $restarts[] = time();
                $w->restarts = json_encode($restarts);
                if (!$ok) {
                    $msg = "{$name}'s container (#{$inst->ctVmid}) was stopped — nobody stopped it through Proxmox — and did not come back when started. Its last report was {$since}.";
                    self::tell("{$name} is down and did not start", $msg . ' Look at it on the Proxmox node.');
                    $w->state = 'wont_stay_up';
                } else {
                    $report = self::reportNow($inst);
                    $msg = "{$name}'s container (#{$inst->ctVmid}) had stopped by itself — nobody stopped it through Proxmox — and has been started again. Its last report before that was {$since}. {$report}";
                    self::tell("{$name} had stopped and was started again", $msg . ' Why it stopped is in the container\'s own journal (journalctl -b -1).');
                    $w->state = 'restarted';
                }
                $w->since = $w->since ?: date('Y-m-d H:i:s'); $w->toldAt = date('Y-m-d H:i:s');
                break;
            case 'stopped_on_purpose':
                $msg = "{$name}'s container (#{$inst->ctVmid}) is stopped — {$f['stoppedBy']} stopped it through Proxmox — so it was left stopped. Its last report was {$since}.";
                self::tell("{$name} is stopped", $msg . ' Start it on the Proxmox node when it should be up again.');
                $w->state = 'stopped_on_purpose'; $w->since = date('Y-m-d H:i:s'); $w->toldAt = date('Y-m-d H:i:s');
                break;
            case 'wont_stay_up':
                $msg = "{$name}'s container (#{$inst->ctVmid}) has stopped again after being started " . count($restarts) . ' times in the last ' . (self::RESTART_WINDOW / 3600) . ' hours, so it was left stopped.';
                self::tell("{$name} will not stay up", $msg . ' Its journal (journalctl -b -1, once started) says why it stops.');
                $w->state = 'wont_stay_up'; $w->toldAt = date('Y-m-d H:i:s');
                break;
            case 'silent':
                $msg = "{$name} has not reported since {$since}, though its container (#{$inst->ctVmid}) is " . ($f['ct'] ?: 'unknown') . '. Something inside it is wrong: its web server, its scheduled jobs, or its link to Tiknix.';
                self::tell("{$name} has gone quiet", $msg);
                $w->state = 'silent'; $w->since = date('Y-m-d H:i:s'); $w->toldAt = date('Y-m-d H:i:s');
                break;
            case 'missing':
                $msg = "{$name}'s container #{$inst->ctVmid} does not exist on {$node}, though the app is active. Its last report was {$since}.";
                self::tell("{$name} has no container", $msg);
                $w->state = 'missing'; $w->since = date('Y-m-d H:i:s'); $w->toldAt = date('Y-m-d H:i:s');
                break;
        }
        $w->note = mb_substr($msg !== '' ? $msg : (string) $w->note, 0, 1000);
        $w->updatedAt = date('Y-m-d H:i:s');
        Bean::store($w);
        return in_array($act, ['ok', 'wait'], true) ? '' : "{$inst->slug}: {$act} — {$msg}";
    }

    /** Who stopped the container through Proxmox since $sinceTs (a vzstop or vzshutdown task), or ''. */
    private static function stoppedBy(ProxmoxService $pve, string $node, int $vmid, int $sinceTs): string {
        foreach ((array) ($pve->get("/nodes/{$node}/tasks", ['vmid' => $vmid, 'since' => $sinceTs, 'limit' => 50])['data'] ?? []) as $t) {
            if (in_array((string) ($t['type'] ?? ''), ['vzstop', 'vzshutdown'], true)) return (string) ($t['user'] ?? 'someone') . ' at ' . date('Y-m-d H:i', (int) ($t['starttime'] ?? 0)) . ' UTC';
        }
        return '';
    }

    private static function waitRunning(ProxmoxService $pve, string $node, int $vmid, int $timeout): bool {
        $until = time() + $timeout;
        do {
            if ((string) ($pve->ctStatus($node, $vmid)['status'] ?? '') === 'running') return true;
            sleep(3);
        } while (time() < $until);
        return false;
    }

    /** Ask the app to report now, once it answers SSH; what happened, in one sentence. */
    private static function reportNow(object $inst): string {
        for ($i = 0; $i < 20; $i++) {
            try {
                [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --status-report --send --why=' . escapeshellarg('started again by the app watcher') . ' 2>&1 | tail -1', null, 60);
                if ($c === 0) return 'It has reported in again: ' . trim((string) $o);
            } catch (\Throwable $e) { /* not answering SSH yet */ }
            sleep(5);
        }
        return 'It has not reported in yet; the next hourly report will say whether it is well.';
    }

    /** Email every platform admin (ROOT). The watcher's whole point is that someone hears. */
    private static function tell(string $subject, string $text): void {
        $sent = 0;
        foreach (Bean::find('member', 'level = ?', [LEVELS['ROOT']]) as $m) {
            if (!filter_var((string) $m->email, FILTER_VALIDATE_EMAIL)) continue;
            $line = PlanNotifier::mailAddress((string) $m->email, (string) ($m->username ?? ''), 'Tiknix: ' . $subject, '<p>' . nl2br(htmlspecialchars($text)) . '</p>');
            if (str_starts_with($line, 'email FAILED')) error_log("ERROR AppWatch: {$subject} — {$line}"); else $sent++;
        }
        if ($sent === 0) error_log("ERROR AppWatch: nobody could be told: {$subject} — {$text}");
    }
}
