<?php
/**
 * consent-scan.php --instance=ID --site=HOST --pages=FILE — the consent plugin's page scan (started by
 * /brokerinfo/consentscan). Runs scripts/consent-scan.js in a browser on the QA host, like every browser the platform runs for
 * an app, and posts what it saw to the scanned site's own /consent/scanresult: each host of an app keeps its own database.
 */
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
require \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap('conf/config.ini');

$o = getopt('', ['instance:', 'site:', 'pages:']);
$inst = \app\Bean::load('instance', (int) ($o['instance'] ?? 0));
$host = strtolower((string) ($o['site'] ?? ''));
if (!$inst->id || !in_array($host, \app\DomainCerts::domainsOf($inst), true)) { fwrite(STDERR, "ERROR consent-scan: {$host} is not a domain of instance " . ($o['instance'] ?? '?') . "\n"); exit(1); }
$pages = json_decode((string) file_get_contents((string) $o['pages']), true);
@unlink((string) $o['pages']);
if (!is_array($pages)) { fwrite(STDERR, "ERROR consent-scan: no page list\n"); exit(1); }

$result = ['ok' => false, 'error' => 'the scan did not run'];
try {
    $qa = \app\QaHost::state();
    if (!$qa || (string) $qa['provisioned_at'] === '') throw new \RuntimeException('the QA browser host is not set up');
    // Never the owner: a plain member of its own, made by this sign-in and switched off again by the app when the result arrives.
    // An address that takes no mail (.invalid), the same as the plugin's Scan::SCAN_EMAIL.
    $token = \app\AppToken::launch($inst, 'consent-scan@scan.tiknix.invalid', '/', 100, []);
    $config = ['base' => "https://{$host}", 'token' => $token, 'maxPages' => 60, 'visitorPages' => array_values((array) ($pages['visitor'] ?? []))];
    $js = 'const CONFIG = ' . json_encode($config, JSON_UNESCAPED_SLASHES) . ";\n" . file_get_contents(__DIR__ . '/consent-scan.js');
    [$code, $out] = \app\TenantHost::ssh((object) ['slug' => \app\QaHost::HOSTNAME, 'ctIp' => $qa['ip']], 'app', 'cd /srv/qa/mcp && PLAYWRIGHT_BROWSERS_PATH=/srv/qa/browsers node --input-type=commonjs -', $js, 540);
    $json = null;
    foreach (explode("\n", (string) $out) as $line) if (str_starts_with($line, 'CONSENT-SCAN-RESULT ')) $json = substr($line, 20);
    $result = $json !== null ? json_decode($json, true) : null;
    if (!is_array($result)) throw new \RuntimeException("the browser gave no result (exit {$code}): " . substr(trim((string) $out), 0, 300));
} catch (\Throwable $e) {
    $result = ['ok' => false, 'error' => $e->getMessage()];
}
$key = \app\ConnectorPush::target((int) $inst->id)['key'];
$ch = curl_init("https://{$host}/consent/scanresult");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => json_encode($result, JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$key}"]]);
$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
if ($status !== 200) { fwrite(STDERR, "ERROR consent-scan: {$host} answered {$status}: " . substr((string) ($body ?: curl_error($ch)), 0, 300) . "\n"); exit(1); }
echo date('c') . " consent-scan {$host}: " . (($result['ok'] ?? false) ? 'done' : 'failed: ' . ($result['error'] ?? '')) . "\n";
