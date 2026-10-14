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
 * ends. Email is an EXTRA, sent only when a Mailgun connection is bound to core's mail role (Mailer::isConfigured).
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

            // One thread per plan OF ONE PROJECT: a rebuild of the same plan continues the
            // conversation rather than starting a new one beside it. Plan ids are per
            // project (each has its own workbench.db), so the id alone is not a key: keyed
            // on it, every project's plan #32 shared one conversation, owned by whichever
            // finished first — Serenity's notices landed in fabian's thread and his in yours.
            $thread = Bean::findOne('thread', 'related_type = ? AND related_id = ? AND project_slug = ?', ['plan', $planId, $slug]);
            if (!$thread || !$thread->id) {
                $thread = Bean::dispense('thread');
                $thread->subject       = $subject;
                $thread->relatedType   = 'plan';
                $thread->relatedId     = $planId;
                $thread->projectSlug   = $slug;
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
            // The owner is IN the conversation, not just named on it: participation is what
            // Communications scopes by, and what the unread bell is derived from.
            ThreadMembers::ensure((int) $thread->id, [$memberId], ThreadMembers::ROLE_OWNER);

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

            // Email is the EXTRA. No mail connection is a normal, silent state — the in-app
            // notice above has already done the job.
            $sent .= ', ' . self::mailMember($memberId, $subject, $content, (string) $msg->bodyPlain, (int) $thread->id);

            return $sent;
        } catch (\Throwable $e) {
            return 'notify: FAILED — ' . $e->getMessage();
        } finally {
            if ($restore) Bean::selectDatabase($restore);
        }
    }

    /**
     * A build has stopped for its owner's ANSWER: a task asked a question (PlanExecutor::ask) and
     * everything that could be built without it has been. Said in the plan's own conversation —
     * in-app first, email as the extra — because a question nobody sees is a build that silently
     * stopped. The answer is given in the Builder (Ask shows the question, in yellow).
     *
     * @param array $p [plan_id, slug, member_id, title, task, question, count, base_url]
     */
    public static function planAsks(string $coreDb, array $p): string {
        $planId = (int) ($p['plan_id'] ?? 0); $memberId = (int) ($p['member_id'] ?? 0); $slug = (string) ($p['slug'] ?? '');
        if ($planId <= 0 || $memberId <= 0) return 'notify: skipped (no plan/member)';
        if (!is_file($coreDb)) return 'notify: skipped (no core db at ' . $coreDb . ')';
        $title = trim((string) ($p['title'] ?? 'Untitled plan'));
        $n = max(1, (int) ($p['count'] ?? 1));
        $subject = 'A question for you: ' . $title;
        $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES);
        $content = '<p>The build of <strong>' . $e($title) . '</strong> has stopped for your answer' . ($n > 1 ? " ({$n} tasks are asking)" : '') . '.</p>'
                 . (trim((string) ($p['task'] ?? '')) !== '' ? '<p><strong>' . $e((string) $p['task']) . '</strong> asks:</p>' : '')
                 . '<blockquote>' . nl2br($e((string) ($p['question'] ?? ''))) . '</blockquote>'
                 . '<p>Answer it in the Builder, on the Ask page of this project: the question is there, and the build goes on by itself once you answer.</p>';
        return self::tellOwner($coreDb, $planId, $memberId, $slug, $subject, $content, $title . ' — a task has a question for you');
    }

    /**
     * A request and its agent's Spend per request (Plan and Fan, step five), told to its owner in the
     * plan's conversation and by email. $p['kind']:
     *   estimate  it has not started: what tasks like its ones cost here is more than the limit
     *   near      it is building and has used most of the limit (PlanExecutor::NEAR_BUDGET)
     *   paused    it has used the limit and started nothing more
     * $p: plan_id, member_id, slug, title, spent, limit (tokens), estimate (tokens, for 'estimate')
     */
    public static function planBudget(string $coreDb, array $p): string {
        $planId = (int) ($p['plan_id'] ?? 0); $memberId = (int) ($p['member_id'] ?? 0); $slug = (string) ($p['slug'] ?? '');
        if ($planId <= 0 || $memberId <= 0) return 'notify: skipped (no plan/member)';
        if (!is_file($coreDb)) return 'notify: skipped (no core db at ' . $coreDb . ')';
        $title = trim((string) ($p['title'] ?? 'Untitled request'));
        $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES);
        $tok = fn($n) => RunStats::tokens((int) $n);
        $limit = $tok($p['limit'] ?? 0);
        $where = '<p>Its limit is the agent\'s <em>Spend per request</em>, on the project\'s AI agents page.</p>';
        switch ((string) ($p['kind'] ?? '')) {
            case 'estimate':
                $subject = 'Before it starts: ' . $title;
                $content = '<p><strong>' . $e($title) . '</strong> is planned, and has not started. Tasks like its ones have used about <strong>' . $tok($p['estimate'] ?? 0)
                         . ' tokens</strong> in this project; its agent\'s limit for one request is ' . $limit . '.</p>'
                         . '<p>Build it anyway from the Ask page, or raise the limit first.</p>' . $where;
                $preview = $title . ' — likely over its spending limit; waiting for you';
                break;
            case 'near':
                $subject = 'Nearing its spending limit: ' . $title;
                $content = '<p><strong>' . $e($title) . '</strong> has used <strong>' . $tok($p['spent'] ?? 0) . '</strong> of its ' . $limit . ' token limit and is still building.</p>'
                         . '<p>Nothing is needed from you now. If it reaches the limit, it stops starting tasks and waits for you.</p>' . $where;
                $preview = $title . ' — nearing its spending limit';
                break;
            case 'paused':
                $subject = 'Paused at its spending limit: ' . $title;
                $content = '<p><strong>' . $e($title) . '</strong> has used <strong>' . $tok($p['spent'] ?? 0) . ' tokens</strong>, past its ' . $limit . ' limit, and has stopped starting tasks. What it built so far is in the app.</p>'
                         . '<p>Press <em>Go on</em> on the Ask page to finish it, or raise the limit first.</p>' . $where;
                $preview = $title . ' — paused at its spending limit';
                break;
            default:
                throw new \InvalidArgumentException("planBudget: kind must be estimate, near or paused, not '" . (string) ($p['kind'] ?? '') . "'");
        }
        return self::tellOwner($coreDb, $planId, $memberId, $slug, $subject, $content, $preview);
    }

    /**
     * The email, sent by scripts/mail-member.php: core's mail is a connection only a booted core can
     * open, and the build's processes that call this have not booted it. Its one line says what happened.
     */
    private static function mailMember(int $memberId, string $subject, string $html, string $plain, int $threadId): string {
        $cmd = 'env -u TIKNIX_WORKBENCH_DB php ' . escapeshellarg(dirname(__DIR__) . '/scripts/mail-member.php');
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        if (!is_resource($p)) return 'email FAILED: could not start scripts/mail-member.php';
        fwrite($pipes[0], (string) json_encode(['member_id' => $memberId, 'subject' => $subject, 'html' => $html, 'plain' => $plain, 'thread_id' => $threadId]));
        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1])); $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        $line = trim((string) (array_slice(explode("\n", $out), -1)[0] ?? ''));
        if ($line === '') return 'email FAILED: mail-member.php said nothing (exit ' . $code . ')' . ($err !== '' ? ': ' . mb_substr($err, -300) : '');
        if ($code !== 0 || str_starts_with($line, 'email FAILED')) error_log('ERROR PlanNotifier: ' . $line . ' — subject "' . $subject . '", member #' . $memberId);
        return $line;
    }

    /**
     * One message on an existing conversation (an answer to an emailed Ask, an acknowledgement of a
     * reply), and the same by email with the conversation's reply address. Core's database must be
     * the one selected or reachable as $coreDb.
     */
    public static function onThread(string $coreDb, int $threadId, int $memberId, string $subject, string $content): string {
        $restore = Bean::hasDatabase('default') ? 'default' : null;
        if (!Bean::hasDatabase('plannotify')) Bean::addDatabase('plannotify', 'sqlite:' . $coreDb);
        Bean::selectDatabase('plannotify');
        try {
            $thread = Bean::load('thread', $threadId);
            if (!$thread->id) return 'FAILED — no thread #' . $threadId;
            $now = date('Y-m-d H:i:s');
            $msg = Bean::dispense('message');
            $msg->threadId = (int) $thread->id; $msg->direction = 'in'; $msg->notifyType = 'system'; $msg->fromName = 'AI Builder';
            $msg->subject = $subject; $msg->content = $content;
            $msg->bodyPlain = trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>', '</blockquote>'], "\n", $content)), ENT_QUOTES));
            $msg->status = 'received'; $msg->createdAt = $now; $msg->sentAt = $now;
            Bean::store($msg);
            $thread->lastDirection = 'in'; $thread->lastPreview = mb_substr($msg->bodyPlain, 0, 200); $thread->lastMessageAt = $now;
            $thread->messageCount = (int) $thread->messageCount + 1; $thread->updatedAt = $now;
            Bean::store($thread);
            try { $thread->wakeParticipants((int) $msg->id); } catch (\Throwable $e) { /* the message is stored; the badge comes on the next poll */ }
            return 'thread #' . (int) $thread->id . ', ' . self::mailMember($memberId, $subject, $content, (string) $msg->bodyPlain, (int) $thread->id);
        } catch (\Throwable $e) {
            return 'FAILED — ' . $e->getMessage();
        } finally {
            if ($restore) Bean::selectDatabase($restore);
        }
    }

    /** One message in the plan's own conversation, and the same by email when mail is connected. */
    private static function tellOwner(string $coreDb, int $planId, int $memberId, string $slug, string $subject, string $content, string $preview): string {
        $restore = Bean::hasDatabase('default') ? 'default' : null;
        if (!Bean::hasDatabase('plannotify')) Bean::addDatabase('plannotify', 'sqlite:' . $coreDb);
        Bean::selectDatabase('plannotify');
        try {
            $now = date('Y-m-d H:i:s');
            // the plan's own conversation, as planFinished keys it (per project: plan ids are not unique across projects)
            $thread = Bean::findOne('thread', 'related_type = ? AND related_id = ? AND project_slug = ?', ['plan', $planId, $slug]);
            if (!$thread || !$thread->id) {
                $thread = Bean::dispense('thread');
                $thread->subject = $subject; $thread->relatedType = 'plan'; $thread->relatedId = $planId; $thread->projectSlug = $slug;
                $thread->ownerMemberId = $memberId; $thread->messageCount = 0; $thread->status = 'open'; $thread->createdAt = $now;
            }
            $thread->lastDirection = 'in';
            $thread->lastPreview   = mb_substr($preview, 0, 200);
            $thread->lastMessageAt = $now;
            $thread->messageCount  = (int) $thread->messageCount + 1;
            $thread->updatedAt     = $now;
            Bean::store($thread);
            ThreadMembers::ensure((int) $thread->id, [$memberId], ThreadMembers::ROLE_OWNER);

            $msg = Bean::dispense('message');
            $msg->threadId = (int) $thread->id; $msg->direction = 'in'; $msg->notifyType = 'system'; $msg->fromName = 'AI Builder';
            $msg->subject = $subject; $msg->content = $content;
            $msg->bodyPlain = trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>', '</blockquote>'], "\n", $content)), ENT_QUOTES));
            $msg->status = 'received'; $msg->createdAt = $now; $msg->sentAt = $now;
            Bean::store($msg);
            $thread->wakeParticipants((int) $msg->id);

            $sent = 'thread #' . (int) $thread->id . ' for member ' . $memberId;
            $sent .= ', ' . self::mailMember($memberId, $subject, $content, (string) $msg->bodyPlain, (int) $thread->id);
            return $sent;
        } catch (\Throwable $e3) {
            return 'FAILED — ' . $e3->getMessage();
        } finally {
            if ($restore) Bean::selectDatabase($restore);
        }
    }

    /**
     * Announce that the PLANNER failed before any plan existed.
     *
     * planFinished() is for a plan that RAN; this is the earlier, quieter failure — the
     * decompose itself died (a session limit, a bad credential, an engine that would not
     * start), so there is no plan to review and the prompt sits at "never ran" with nothing
     * telling the owner why. Same delivery as planFinished — in-app first (the bell +
     * Communications, no mail config needed), email as an extra — keyed on the prompt so a
     * retry of the same request continues one thread.
     *
     * @param string $coreDb path to core's sqlite db (members + threads live there)
     * @param array  $p      [member_id, slug, prompt_id, why, base_url]
     */
    public static function planningFailed(string $coreDb, array $p): string {
        $memberId = (int) ($p['member_id'] ?? 0);
        $promptId = (int) ($p['prompt_id'] ?? 0);
        $slug     = (string) ($p['slug'] ?? '');
        $why      = trim((string) ($p['why'] ?? ''));
        $baseUrl  = rtrim((string) ($p['base_url'] ?? ''), '/');

        if ($memberId <= 0) return 'notify: skipped (no member)';
        if (!is_file($coreDb)) return 'notify: skipped (no core db at ' . $coreDb . ')';

        $subject = 'Build plan failed' . ($slug !== '' ? ' on ' . $slug : '');
        $lines = ['<p>The planner for <code>' . htmlspecialchars($slug, ENT_QUOTES)
            . '</code> could not produce a plan, so nothing was built.</p>'];
        if ($why !== '') {
            $lines[] = '<p><strong>Why:</strong><br><code>'
                . htmlspecialchars(mb_substr($why, 0, 400), ENT_QUOTES) . '</code></p>';
        }
        $lines[] = '<p><strong>What you can do:</strong> open Create Task and run the same request '
            . 'again — a transient failure (a session limit, a hiccup) usually clears on a retry. '
            . 'If it keeps failing, the reason above says what to fix.</p>';
        if ($baseUrl !== '') $lines[] = '<p><a href="' . htmlspecialchars($baseUrl, ENT_QUOTES) . '">Open the app</a></p>';
        $content = implode("\n", $lines);
        $preview = $subject . ($why !== '' ? ' — ' . mb_substr($why, 0, 120) : '');

        $restore = Bean::hasDatabase('default') ? 'default' : null;
        if (!Bean::hasDatabase('plannotify')) Bean::addDatabase('plannotify', 'sqlite:' . $coreDb);
        Bean::selectDatabase('plannotify');

        try {
            $now = date('Y-m-d H:i:s');

            // One thread per prompt: a retry of the same request continues it rather than
            // stacking a new failure notice beside the last.
            // Prompt ids are per project too — keyed with the project, as plans are.
            $thread = $promptId > 0
                ? Bean::findOne('thread', 'related_type = ? AND related_id = ? AND project_slug = ?', ['promptfail', $promptId, $slug])
                : null;
            if (!$thread || !$thread->id) {
                $thread = Bean::dispense('thread');
                $thread->subject       = $subject;
                $thread->relatedType   = 'promptfail';
                $thread->relatedId     = $promptId;
                $thread->projectSlug   = $slug;
                $thread->ownerMemberId = $memberId;
                $thread->messageCount  = 0;
                $thread->status        = 'open';
                $thread->createdAt     = $now;
            }
            $thread->lastDirection = 'in';
            $thread->lastPreview   = mb_substr($preview, 0, 200);
            $thread->lastMessageAt = $now;
            $thread->messageCount  = (int) $thread->messageCount + 1;
            $thread->updatedAt     = $now;
            Bean::store($thread);
            // The owner is IN the conversation, not just named on it: participation is what
            // Communications scopes by, and what the unread bell is derived from.
            ThreadMembers::ensure((int) $thread->id, [$memberId], ThreadMembers::ROLE_OWNER);

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

            // Live-push is best effort; the bell is DERIVED from read marks, so the notice
            // still appears on the next poll even if this throws (MQTT down, no participants).
            try { $thread->wakeParticipants((int) $msg->id); } catch (\Throwable $e) { /* non-fatal */ }

            $sent = 'notify: fail-thread #' . (int) $thread->id . ' for member ' . $memberId;

            $sent .= ', ' . self::mailMember($memberId, $subject, $content, (string) $msg->bodyPlain, (int) $thread->id);

            return $sent;
        } catch (\Throwable $e) {
            return 'notify: FAILED — ' . $e->getMessage();
        } finally {
            if ($restore) Bean::selectDatabase($restore);
        }
    }
}
