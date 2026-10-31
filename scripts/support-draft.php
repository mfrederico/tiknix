<?php
/**
 * support-draft.php --ticket=N --reply=FILE [--findings=FILE] [--author=WORDS] — leave a draft reply on a support ticket from a
 * terminal session (lib/SupportDraft.php). It sends nothing: a person reads it at /contact/view?id=N and presses Send Response.
 */
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
require \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap('conf/config.ini');

$o = getopt('', ['ticket:', 'reply:', 'findings:', 'author:']);
if (empty($o['ticket']) || empty($o['reply'])) { fwrite(STDERR, "usage: support-draft.php --ticket=N --reply=FILE [--findings=FILE] [--author=WORDS]\n"); exit(2); }
foreach (['reply', 'findings'] as $k) if (isset($o[$k]) && !is_readable((string) $o[$k])) { fwrite(STDERR, "error: cannot read --{$k}={$o[$k]}\n"); exit(2); }
try {
    $id = \app\SupportDraft::save((int) $o['ticket'], (string) file_get_contents((string) $o['reply']), isset($o['findings']) ? (string) file_get_contents((string) $o['findings']) : '', (string) ($o['author'] ?? 'agent (terminal)'));
} catch (\InvalidArgumentException $e) { fwrite(STDERR, 'error: ' . $e->getMessage() . "\n"); exit(1); }
echo "draft #{$id} saved. Nothing was sent. It waits on /contact/view?id={$o['ticket']}\n";
