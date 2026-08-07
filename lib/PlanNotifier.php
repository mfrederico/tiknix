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
     * @param array  $p        [plan_id, title, slug, member_id, status, counts[], base_url,
     *                          failures[] => ['title'=>..,'why'=>..], board_url]
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
        $resolved = (int) ($counts['resolved'] ?? 0);
        $failed   = (int) ($counts['failed'] ?? 0);
        $conflict = (int) ($counts['conflict'] ?? 0);
        $ok       = $failed === 0 && $conflict === 0 && $status === 'done';

        $failures = array_values((array) ($p['failures'] ?? []));
        $boardUrl = rtrim((string) ($p['board_url'] ?? ''), '/');

        $subject = ($ok ? 'Build finished: ' : 'Build needs attention: ') . $title;
        $summary = $merged . ' merged'
                 . ($resolved ? ', ' . $resolved . ' already satisfied' : '')
                 . ($failed   ? ', ' . $failed   . ' failed'   : '')
                 . ($conflict ? ', ' . $conflict . ' with conflicts' : '');

        // Written for a person reading a notification, not for a log parser.
        $lines = [
            '<p><strong>' . htmlspecialchars($title, ENT_QUOTES) . '</strong> finished on '
                . '<code>' . htmlspecialchars($slug, ENT_QUOTES) . '</code>.</p>',
            '<p>' . htmlspecialchars($summary, ENT_QUOTES) . '.</p>',
        ];
        if (!$ok) {
            // Name what failed, here, in the notification. "Some tasks did not land" sends
            // someone hunting through a log to find out what this message already knows.
            $lines[] = '<p><strong>These did not land:</strong></p><ul>';
            foreach ($failures as $f) {
                $why = trim((string) ($f['why'] ?? ''));
                $lines[] = '<li><strong>' . htmlspecialchars((string) ($f['title'] ?? 'untitled task'), ENT_QUOTES) . '</strong>'
                    . ($why !== '' ? '<br><code>' . htmlspecialchars(mb_substr($why, 0, 300), ENT_QUOTES) . '</code>' : '')
                    . '</li>';
            }
            if (!$failures) $lines[] = '<li>(the executor did not record which)</li>';
            $lines[] = '</ul>';

            // If remediation already acted, say so FIRST — otherwise this reads as a
            // request for a decision that has, in fact, already been taken for you.
            $remedy = (array) ($p['remedy'] ?? []);
            if (($remedy['action'] ?? '') === 'replan') {
                $lines[] = '<p><strong>A re-plan has already been started automatically.</strong> '
                    . htmlspecialchars((string) ($remedy['why'] ?? ''), ENT_QUOTES)
                    . ' The goal is being decomposed again with those failures as context. '
                    . 'Nothing is needed from you yet — the new plan will arrive as a draft to approve. '
                    . 'This happens once: if the second attempt also fails, you decide.</p>';
            } elseif (($remedy['action'] ?? '') === 'escalate') {
                $lines[] = '<p><strong>Not re-planned automatically:</strong> '
                    . htmlspecialchars((string) ($remedy['why'] ?? ''), ENT_QUOTES) . '</p>';
            }

            // And say what the choices are. A notification that reports a problem without
            // saying what can be done about it just moves the problem to the reader.
            $lines[] = '<p><strong>What you can do:</strong></p><ul>'
                . '<li><em>Build again</em> — re-runs only what did not land, for a failure that was transient.</li>'
                . '<li><em>Re-plan</em> — decompose the goal again, when the spec was wrong rather than the run.</li>'
                . '<li><em>Take it yourself</em> — open the task and finish it by hand.</li>'
                . '</ul>';
        }
        $links = [];
        if ($boardUrl !== '') $links[] = '<a href="' . htmlspecialchars($boardUrl, ENT_QUOTES) . '">Open the plan</a>';
        if ($baseUrl  !== '') $links[] = '<a href="' . htmlspecialchars($baseUrl,  ENT_QUOTES) . '">Open the app</a>';
        if ($links) $lines[] = '<p>' . implode(' | ', $links) . '</p>';
        $content = implode("\n", $lines);
        $preview = $title . ' — ' . $summary;

        // ---- core's db, as a second connection ---------------------------------
        $restore = Bean::hasDatabase('default') ? 'default' : null;
        if (!Bean::hasDatabase('plannotify')) Bean::addDatabase('plannotify', 'sqlite:' . $coreDb);
        Bean::selectDatabase('plannotify');

        try {
            $now = date('Y-m-d H:i:s');

            // One thread per plan: a rebuild of the same plan continues the conversation
            // rather than starting a new one beside it.
            $thread = Bean::findOne('thread', 'related_type = ? AND related_id = ?', ['plan', $planId]);
            if (!$thread || !$thread->id) {
                $thread = Bean::dispense('thread');
                $thread->subject       = $subject;
                $thread->relatedType   = 'plan';
                $thread->relatedId     = $planId;
                $thread->ownerMemberId = $memberId;
                $thread->messageCount  = 0;
                $thread->status        = 'open';
                $thread->createdAt     = $now;
            }
            $thread->lastDirection = 'in';
            $thread->lastPreview   = mb_substr($preview, 0, 200);
            $thread->lastMessageAt = $now;
            $thread->messageCount  = (int) $thread->messageCount + 1;
            // The bell is DERIVED now: everyone whose read mark is behind this message
            // is unread, so there is no counter to bump. See Model_Thread::unreadFor().
            $thread->updatedAt     = $now;
            Bean::store($thread);

            $msg = Bean::dispense('message');
            $msg->threadId   = (int) $thread->id;
            $msg->direction  = 'in';
            $msg->notifyType = 'system';
            $msg->fromName   = 'AI Builder';
            $msg->subject    = $subject;
            $msg->content    = $content;
            $msg->bodyPlain  = trim(html_entity_decode(strip_tags(
                str_replace(['</p>', '</li>', '<br>'], "\n", $content)
            ), ENT_QUOTES));
            $msg->status     = 'received';
            $msg->createdAt  = $now;
            $msg->sentAt     = $now;
            Bean::store($msg);

            // These threads are opened by machinery and usually have an owner and no
            // participant rows, which is exactly the case wakeParticipants() falls back
            // on. Running against the 'plannotify' connection is fine: MQTT is a socket,
            // not a query, and the participant lookup reads the core db we just selected.
            $thread->wakeParticipants((int) $msg->id);

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
            if ($restore) Bean::selectDatabase($restore);
        }
    }
}
