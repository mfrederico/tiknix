#!/usr/bin/env php
<?php
/**
 * mail-member.php — email one member of Tiknix, from a process that may not have booted core.
 *
 * The build's own processes (plan-orchestrate.php, plan-audit.php, the planner's scripts) open a
 * project's board as their database and never boot core, so core's mail connection — a CONNECTION
 * (Connections → Mailgun), decrypted with core's own key — is out of their reach: every owner
 * email from them read "no Mailgun connection bound to mail" while core's mail worked. This boots
 * core and sends. (app\PlanNotifier::mailMember runs it.)
 *
 * stdin: {"member_id": N, "subject": "…", "html": "…", "plain": "…"}
 * stdout: one line — "emailed <address>", "no email (<why>)", or "email FAILED: <why>"; exit 0 / 1.
 */
if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';
require_once \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap();

$in = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($in) || (int) ($in['member_id'] ?? 0) <= 0 || trim((string) ($in['subject'] ?? '')) === '') { echo "email FAILED: no member_id or subject on stdin\n"; exit(1); }
if (!\app\Mailer::isConfigured()) {
    try { \app\Mailer::settings(); $why = 'mail is not configured'; } catch (\Throwable $e) { $why = $e->getMessage(); }
    echo "no email ({$why})\n"; exit(0);
}
$member = \app\Bean::load('member', (int) $in['member_id']);
$email = (string) ($member->email ?? '');
if ($email === '') { echo "no email (member #{$in['member_id']} has no address)\n"; exit(0); }
try {
    $ok = \app\Mailer::create()->to($email, (string) ($member->username ?? ''))->subject((string) $in['subject'])->send((string) ($in['html'] ?? ''), (string) ($in['plain'] ?? ''));
    echo $ok ? "emailed {$email}\n" : "email FAILED: Mailgun did not accept it (see core's log)\n";
    exit($ok ? 0 : 1);
} catch (\Throwable $e) { echo 'email FAILED: ' . $e->getMessage() . "\n"; exit(1); }
