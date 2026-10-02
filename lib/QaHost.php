<?php
/**
 * QaHost — the platform's QA browser host: ONE container where QA Testing's headless browser
 * runs (QA-TESTING-PLAN.md), made the way a project's container is made (a linked clone of the
 * tenant template, TenantHost::cloneContainer) and set up by tenant/qa.sh.
 *
 * Why its own machine: the pages a web test opens are written by project owners. They do not
 * run in a browser on the control plane, and they do not run in the project's own container
 * (that would put the checker inside the thing it checks, and a browser in a 1 GB app
 * container). This container holds no app, no keys and no data, and its firewall lets it out
 * only to public addresses.
 *
 * It is not a project: there is no `instance` row for it (nothing that sweeps projects may
 * find it). What core knows about it is data/qa-host.json — {vmid, ip, created_at,
 * provisioned_at} — which the qa.tiknix sidecar reads to know where to send a web test.
 */

namespace app;

class QaHost {

    public const HOSTNAME  = 'tiknix-qa';
    public const MEMORY_MB = 3072;      // two browsers at once (the QA queue's cap) with room
    public const SWAP_MB   = 1024;
    public const DISK_GB   = 8;
    public const SCRIPT    = __DIR__ . '/../tenant/qa.sh';

    public static function stateFile(): string { return Paths::root() . '/data/qa-host.json'; }

    /** @return array{vmid:int,ip:string,created_at:string,provisioned_at:string}|null */
    public static function state(): ?array {
        $f = self::stateFile();
        if (!is_file($f)) return null;
        $j = json_decode((string) file_get_contents($f), true);
        if (!is_array($j) || (int) ($j['vmid'] ?? 0) <= 0 || !preg_match('/^10\.10\.10\.\d{1,3}$/', (string) ($j['ip'] ?? ''))) {
            throw new \RuntimeException("{$f} is not a QA host record ({vmid, ip, …}) — fix or remove it");
        }
        return $j;
    }

    private static function write(array $state): void {
        $f = self::stateFile();
        if (file_put_contents($f, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) throw new \RuntimeException("could not write {$f}");
    }

    /** What TenantHost::ssh needs to reach it. */
    private static function probe(array $state): object {
        return (object) ['slug' => self::HOSTNAME, 'ctIp' => $state['ip']];
    }

    /** Make the container. Refused when one is already written down. */
    public static function create(): array {
        if ($s = self::state()) return ['ok' => false, 'error' => "the QA host already exists: container {$s['vmid']} at {$s['ip']} (--status, or --destroy first)"];
        $r = TenantHost::cloneContainer(self::HOSTNAME, 'tiknix QA browser host (QaHost)',
            ['memory' => self::MEMORY_MB, 'swap' => self::SWAP_MB],
            fn(int $vmid, string $ip) => self::write(['vmid' => $vmid, 'ip' => $ip, 'created_at' => date('c'), 'provisioned_at' => '']));
        if (!$r['ok']) return $r;
        // Chromium, its libraries and node do not fit beside the template's own 4 GB.
        $pve = ProxmoxService::fromConfig();
        $node = $pve->node();
        $rs = $pve->put('/nodes/' . $node . '/lxc/' . $r['vmid'] . '/resize', ['disk' => 'rootfs', 'size' => self::DISK_GB . 'G']);
        if (($rs['error'] ?? '') !== '') return ['ok' => false, 'error' => "growing {$r['vmid']}'s disk to " . self::DISK_GB . " GB failed: {$rs['error']}"];
        if (is_string($rs['data'] ?? null) && $rs['data'] !== '') {
            $w = $pve->waitTask($node, $rs['data']);
            if (!($w['ok'] ?? false)) return ['ok' => false, 'error' => "growing {$r['vmid']}'s disk failed: " . ($w['exit'] ?? '?')];
        }
        return ['ok' => true, 'step' => $r['step'] . ', ' . self::MEMORY_MB . ' MB, ' . self::DISK_GB . ' GB'];
    }

    /** Run tenant/qa.sh in it as root. Idempotent. */
    public static function provision(): array {
        $s = self::state();
        if (!$s) return ['ok' => false, 'error' => 'there is no QA host yet (--create)'];
        [$code, $out] = TenantHost::ssh(self::probe($s), 'root', 'bash -s', (string) file_get_contents(self::SCRIPT), 1800);
        if ($code !== 0) return ['ok' => false, 'error' => "qa.sh exited {$code}", 'output' => $out];
        $s['provisioned_at'] = date('c');
        self::write($s);
        return ['ok' => true, 'output' => $out];
    }

    /** What is there now: reachable, node, the browser, the firewall, room. */
    public static function status(): array {
        $s = self::state();
        if (!$s) return ['ok' => false, 'error' => 'there is no QA host (--create, then --provision)'];
        [$code, $out] = TenantHost::ssh(self::probe($s), 'root',
            'echo "node: $(node --version 2>&1)"; echo "playwright: $(cat /srv/qa/.playwright 2>&1)"; echo "browsers: $(ls /srv/qa/browsers 2>&1 | tr "\n" " ")"; '
          . 'echo "firewall: $(iptables -S OUTPUT | grep -c REJECT) private ranges refused, unit $(systemctl is-enabled qa-firewall.service 2>&1)"; '
          . 'echo "disk: $(df -h / | awk \'NR==2 {print $4 " free of " $2}\')"; echo "memory: $(free -m | awk \'NR==2 {print $7 " MB free of " $2}\')"; '
          . 'echo "runner: $(cat /srv/qa/runner/.version 2>/dev/null || echo "not pushed yet (the sidecar pushes it on its first web test)")"', null, 30);
        if ($code !== 0) return ['ok' => false, 'error' => "container {$s['vmid']} at {$s['ip']} did not answer (exit {$code}): " . trim($out)];
        return ['ok' => true, 'state' => $s, 'output' => $out];
    }

    public static function destroy(): array {
        $s = self::state();
        if (!$s) return ['ok' => false, 'error' => 'there is no QA host'];
        $r = TenantHost::destroyContainer((int) $s['vmid'], (string) $s['ip']);
        if (!$r['ok']) return $r;
        if (!unlink(self::stateFile())) return ['ok' => false, 'error' => 'the container is gone but ' . self::stateFile() . ' could not be removed'];
        return $r;
    }
}
