#!/usr/bin/env php
<?php
/**
 * connector-forward.php — forward one accepted batch of a provider's change notices to the projects
 * that connected each company (app\QuickbooksWebhook::forward), then remove the batch.
 *
 *   php scripts/connector-forward.php data/connector-events/<batch>.json
 *
 * Run detached by the webhook, which has already answered Intuit. A batch that fails to forward to
 * a project is logged with the reason (log/connector-forward.log); it is not retried.
 */
if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';
require_once \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap();

$file = (string) ($argv[1] ?? '');
$real = realpath($file);
if ($real === false || !str_starts_with($real, realpath(dirname(__DIR__) . '/data/connector-events') . '/')) { fwrite(STDERR, "[forward] ERROR not a batch in data/connector-events: {$file}\n"); exit(1); }
$batch = json_decode((string) file_get_contents($real), true);
if (!is_array($batch) || ($batch['connector'] ?? '') !== \app\QuickbooksWebhook::CONNECTOR) { fwrite(STDERR, "[forward] ERROR unreadable batch {$real}\n"); exit(1); }
foreach (\app\QuickbooksWebhook::forward((array) $batch['changes'], [\app\ConnectorPush::class, 'ask']) as $line) echo '[' . date('c') . "] {$line}\n";
unlink($real);
