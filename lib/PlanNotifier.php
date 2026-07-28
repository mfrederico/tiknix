<?php
/**
 * PlanNotifier — tell the owner their build finished.
 *
 * A plan would run for half an hour and end by writing "status=done" to a log file in a
 * tmux session nobody has open. The only way to learn a build had finished was to go and
 * look, which is not a notification system, it is a habit.
 *
 * Two deliberate choices:
 *
 * IN-APP FIRST. The notice is written as an `emailthread` + `notify` row, which is what
 * the bell in the shell already counts and what Communications already renders. That
 * needs no mail configuration at all, so it works on every install the moment a build
 * ends. Email is an EXTRA, sent only when core's conf/mailgun.ini is actually set up.
 *
 * CORE'S DATABASE, EXPLICITLY. Task data lives in the instance's own workbench.db, but a
 * notification belongs to a MEMBER, and members, threads and the bell all live in core.
 * The orchestrator runs with RedBean pointed at the tasks db, so this opens core as a
 * second connection and puts it back afterwards — the same discipline plan-ingest uses,
 * and the absence of which is what put plans in the wrong database twice.
 */
namespace app;

use \Flight as Flight;
use RedBeanPHP\R;

class PlanNotifier {

    /**
     * Mailer logs through Flight's logger on every path, including its success path, so
     * without one it dies with "Call to a member function info() on null" — in a CLI that
     * never booted the framework, which is exactly where the orchestrator lives. Give it
     * somewhere to write rather than leaving mail permanently broken for builds.
     */
    private static function ensureLogger(): void {
        if (Flight::get('log')) return;
        try {
            $log = new \Monolog\Logger('plan');
            $log->pushHandler(new \Monolog\Handler\StreamHandler(
                dirname(__DIR__) . '/log/app-' . date('Y-m-d') . '.log', \Monolog\Level::Info));
            Flight::set('log', $log);
        } catch (\Throwable $e) { /* no logger available; Mailer will report its own failure */ }
    }

    /**
     * Announce a finished plan.
     *
     * @param string $coreDb   path to core's sqlite db (where members and threads live)
     * @param array  $p        [plan_id, title, slug, member_id, status, counts[], base_url]
     * @return string          a line describing what was sent, for the orchestrator log
     */
    public static function planFinished(string $coreDb, array $p): string {
        $planId   = (int) ($p['plan_id'] ?? 0);
        $memberId = (int) ($p['member_id'] ?? 0);
        $title    = trim((string) ($p['title'] ?? 'Untitled plan'));
        $slug     = (string) ($p['slug'] ?? '');
        $status   = (string) ($p['status'] ?? 'done');
        $counts   = (array)  ($p['counts'] ?? []);
        $baseUrl  = rtrim((string) ($p['base_url'] ?? ''), '/');

        if ($planId <= 0 || $memberId <= 0) return 'notify: skipped (no plan/member)';
        if (!is_file($coreDb)) return 'notify: skipped (no core db at ' . $coreDb . ')';

        $merged   = (int) ($counts['merged'] ?? 0);
        $failed   = (int) ($counts['failed'] ?? 0);
        $conflict = (int) ($counts['conflict'] ?? 0);
        $ok       = $failed === 0 && $conflict === 0 && $status === 'done';

        $subject = ($ok ? 'Build finished: ' : 'Build needs attention: ') . $title;
        $summary = $merged . ' merged'
                 . ($failed   ? ', ' . $failed   . ' failed'   : '')
                 . ($conflict ? ', ' . $conflict . ' with conflicts' : '');

        // Written for a person reading a notification, not for a log parser.
        $lines = [
            '<p><strong>' . htmlspecialchars($title, ENT_QUOTES) . '</strong> finished on '
                . '<code>' . htmlspecialchars($slug, ENT_QUOTES) . '</code>.</p>',
            '<p>' . htmlspecialchars($summary, ENT_QUOTES) . '.</p>',
        ];
        if (!$ok) {
            $lines[] = '<p>Some tasks did not land. Open the plan to see which, and why.</p>';
        }
        if ($baseUrl !== '') {
            $lines[] = '<p><a href="' . htmlspecialchars($baseUrl, ENT_QUOTES) . '">Open the app</a></p>';
        }
        $content = implode("\n", $lines);
        $preview = $title . ' — ' . $summary;

        // ---- core's db, as a second connection ---------------------------------
        $restore = R::hasDatabase('default') ? 'default' : null;
        if (!R::hasDatabase('plannotify')) R::addDatabase('plannotify', 'sqlite:' . $coreDb);
        R::selectDatabase('plannotify');

        try {
            $now = date('Y-m-d H:i:s');

            // One thread per plan: a rebuild of the same plan continues the conversation
            // rather than starting a new one beside it.
            $thread = Bean::findOne('emailthread', 'related_type = ? AND related_id = ?', ['plan', $planId]);
            if (!$thread || !$thread->id) {
                $thread = Bean::dispense('emailthread');
                $thread->subject       = $subject;
                $thread->relatedType   = 'plan';
                $thread->relatedId     = $planId;
                $thread->ownerMemberId = $memberId;
                $thread->messageCount  = 0;
                $thread->unreadCount   = 0;
                $thread->status        = 'open';
                $thread->createdAt     = $now;
            }
            $thread->lastDirection = 'in';
            $thread->lastPreview   = mb_substr($preview, 0, 200);
            $thread->lastMessageAt = $now;
            $thread->messageCount  = (int) $thread->messageCount + 1;
            $thread->unreadCount   = (int) $thread->unreadCount + 1;   // this is the bell
            $thread->updatedAt     = $now;
            Bean::store($thread);

            $msg = Bean::dispense('notify');
            $msg->threadId   = (int) $thread->id;
            $msg->direction  = 'in';
            $msg->notifyType = 'system';
            $msg->fromName   = 'AI Builder';
            $msg->subject    = $subject;
            $msg->content    = $content;
            $msg->bodyPlain  = strip_tags(str_replace('</p>', "\n", $content));
            $msg->status     = 'received';
            $msg->createdAt  = $now;
            $msg->sentAt     = $now;
            Bean::store($msg);

            $sent = 'notify: thread #' . (int) $thread->id . ' for member ' . $memberId;

            // Email is the EXTRA. No mailgun.ini is a normal, silent state — the in-app
            // notice above has already done the job.
            self::ensureLogger();
            if (class_exists('\\app\\Mailer') && Mailer::isConfigured()) {
                $member = Bean::load('member', $memberId);
                $email  = (string) ($member->email ?? '');
                if ($email !== '') {
                    try {
                        $okMail = Mailer::create()
                            ->to($email, (string) ($member->username ?? ''))
                            ->subject($subject)
                            ->send($content, $msg->bodyPlain);
                        $sent .= $okMail ? ', emailed ' . $email : ', email FAILED';
                    } catch (\Throwable $e) {
                        $sent .= ', email error: ' . $e->getMessage();
                    }
                }
            } else {
                $sent .= ', no email (conf/mailgun.ini not configured)';
            }

            return $sent;
        } catch (\Throwable $e) {
            return 'notify: FAILED — ' . $e->getMessage();
        } finally {
            if ($restore) R::selectDatabase($restore);
        }
    }
}
