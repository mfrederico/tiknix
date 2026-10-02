<?php
/**
 * qa-host.php — the platform's QA browser host (lib/QaHost.php): one container where QA
 * Testing's headless browser runs, apart from the control plane and from every project.
 *
 *   php scripts/qa-host.php --create       a container cloned from the tenant template
 *   php scripts/qa-host.php --provision    node, Playwright + Chromium, the firewall (tenant/qa.sh); run again to update
 *   php scripts/qa-host.php --up           create + provision
 *   php scripts/qa-host.php --status
 *   php scripts/qa-host.php --destroy --yes
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }
require dirname(__DIR__) . '/vendor/autoload.php';
new \app\Bootstrap();

use app\QaHost;

$o = getopt('', ['create', 'provision', 'up', 'status', 'destroy', 'yes']);
$show = function (array $r): void {
    if (isset($r['step'])) echo $r['step'], "\n";
    if (isset($r['output'])) echo rtrim((string) $r['output']), "\n";
    if (!$r['ok']) { fwrite(STDERR, 'ERROR ' . $r['error'] . "\n"); exit(1); }
};
try {
    if (isset($o['create']) || isset($o['up'])) $show(QaHost::create());
    if (isset($o['provision']) || isset($o['up'])) $show(QaHost::provision());
    if (isset($o['status'])) { $r = QaHost::status(); if ($r['ok']) echo "container {$r['state']['vmid']} at {$r['state']['ip']}, created {$r['state']['created_at']}, provisioned " . ($r['state']['provisioned_at'] ?: 'never') . "\n"; $show($r); }
    if (isset($o['destroy'])) {
        if (!isset($o['yes'])) { fwrite(STDERR, "ERROR --destroy needs --yes\n"); exit(2); }
        $show(QaHost::destroy());
    }
    if (!$o) { fwrite(STDERR, "usage: --create | --provision | --up | --status | --destroy --yes\n"); exit(2); }
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR ' . $e->getMessage() . "\n");
    exit(1);
}
