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
 * stdin: {"member_id": N | "email": "…", "name": "…", "subject": "…", "html": "…", "plain": "…", "thread_id": N, "attach": [paths]}
 *
 * With a thread, the email's Reply-To is that conversation's own address (reply-<token>@ the
 * inbound domain), and the address it went to is added to the thread's reply_allowed: a reply is
 * acted on only from an address the conversation was sent to (app\BuilderMail).
 * stdout: one line — "emailed <address>", "no email (<why>)", or "email FAILED: <why>"; exit 0 / 1.
 */
if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';
require_once \app\Paths::runtime() . '/bin/_boot.php';
new \app\Bootstrap();

$in = json_decode((string) stream_get_contents(STDIN), true);
// To a member (member_id), or to an address (email, name) — the check's report goes to a project's team.
if (!is_array($in) || ((int) ($in['member_id'] ?? 0) <= 0 && trim((string) ($in['email'] ?? '')) === '') || trim((string) ($in['subject'] ?? '')) === '') { echo "email FAILED: no member_id or email, or no subject, on stdin\n"; exit(1); }
if (!\app\Mailer::isConfigured()) {
    try { \app\Mailer::settings(); $why = 'mail is not configured'; } catch (\Throwable $e) { $why = $e->getMessage(); }
    echo "no email ({$why})\n"; exit(0);
}
if ((int) ($in['member_id'] ?? 0) > 0) {
    $member = \app\Bean::load('member', (int) $in['member_id']);
    $email = (string) ($member->email ?? ''); $toName = (string) ($member->username ?? '');
    if ($email === '') { echo "no email (member #{$in['member_id']} has no address)\n"; exit(0); }
} else {
    $email = trim((string) $in['email']); $toName = (string) ($in['name'] ?? '');
}
try {
    $mail = \app\Mailer::create()->to($email, $toName)->subject((string) $in['subject']);
    foreach ((array) ($in['attach'] ?? []) as $path) if (is_string($path) && is_file($path)) $mail->attach($path, basename($path));
    $thread = (int) ($in['thread_id'] ?? 0) > 0 ? \app\Bean::load('thread', (int) $in['thread_id']) : null;
    if ($thread && !$thread->id) { echo "email FAILED: no thread #{$in['thread_id']}\n"; exit(1); }
    $reply = $thread ? \app\BuilderMail::replyAddress($thread) : '';
    if ($reply !== '') $mail->replyTo($reply);
    $ok = $mail->send((string) ($in['html'] ?? ''), (string) ($in['plain'] ?? ''));
    if ($ok && $thread) \app\BuilderMail::allow($thread, $email);
    echo $ok ? "emailed {$email}" . ($reply !== '' ? " (replies to {$reply})" : '') . "\n" : "email FAILED: Mailgun did not accept it (see core's log)\n";
    exit($ok ? 0 : 1);
} catch (\Throwable $e) { echo 'email FAILED: ' . $e->getMessage() . "\n"; exit(1); }
