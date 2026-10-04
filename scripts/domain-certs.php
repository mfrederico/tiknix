#!/usr/bin/env php
<?php
/**
 * domain-certs.php — probe the TLS every project's domains are served with (lib/DomainCerts)
 * and keep the answer per domain. Run hourly by core's pipelines/domain-certs.json; by hand:
 *
 *   php scripts/domain-certs.php
 */
if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require_once __DIR__ . '/../vendor/autoload.php';
new \app\Bootstrap();
$r = \app\DomainCerts::refresh();
foreach ($r['failing'] as $f) echo "  FAILING {$f}\n";
echo '[' . date('c') . "] domain certs: {$r['checked']} domain(s) checked, " . count($r['failing']) . " failing, {$r['removed']} stale row(s) removed\n";
exit($r['failing'] ? 1 : 0);
