<?php
/**
 * BuilderMail — the Builder by email: a reply to one of its notices, or a message to a project's
 * own Ask address, is acted on as if it were typed into that project's Ask.
 *
 * Who may: only an address the conversation was SENT to. Every Builder email goes out with its
 * conversation's own reply address (reply-<token>@ the inbound domain) and, once sent, the address it
 * went to is added to the conversation's reply_allowed (allow()). A reply is acted on only when:
 *   - Mailgun's signature was checked (the webhook had a signing key),
 *   - the sender's domain passed DKIM or SPF as Mailgun saw it (a forged From fails these),
 *   - it is not an out-of-office or other machine reply,
 *   - its From is in reply_allowed, and
 *   - that member can still open the project.
 * Anything else stays on its conversation in Communications for a person to read; nothing is done.
 *
 * Which project: the conversation says it. A plan's notices are its plan's conversation (related_type
 * 'plan', project_slug); a project's Ask address is a conversation of its own per member and project
 * (related_type 'ask', related_id = the instance).
 *
 * What a reply does (on a plan's conversation):
 *   - a task is waiting for an answer from its person → it is the answer, and the build goes on;
 *   - the request is held at its spending limit, and the reply says yes → it goes on;
 *   - anything else → a new message in the project's Ask, answered by email on this conversation.
 */

namespace app;

class BuilderMail {
    /** The conversation types the Builder acts on; others (support, team chat) are left alone. */
    public const TYPES = ['plan', 'ask', 'askq'];

    /** The reply address of a conversation, minting its token the first time. */
    public static function replyAddress($thread): string {
        if (trim((string) ($thread->replyToken ?? '')) === '') {
            do { $t = bin2hex(random_bytes(16)); } while (Bean::count('thread', 'reply_token = ?', [$t]) > 0);
            $thread->replyToken = $t;
            Bean::store($thread);
        }
        $domain = (string) Mailer::settings()['inbound_domain'];
        if ($domain === '') throw new \RuntimeException('Mail: the Mailgun connection has no inbound domain, so a reply could not come back');
        return 'reply-' . $thread->replyToken . '@' . $domain;
    }

    /** An address this conversation was sent to: a reply from it may be acted on. */
    public static function allow($thread, string $email): void {
        $list = self::allowed($thread);
        $email = strtolower(trim($email));
        if ($email === '' || in_array($email, $list, true)) return;
        $list[] = $email;
        $thread->replyAllowed = json_encode($list);
        Bean::store($thread);
    }

    /**
     * The mailbox an address delivers to: name+tag@domain and name@domain are one mailbox (sub-addressing),
     * so only its owner can send as either. Nothing else is folded together.
     */
    public static function mailbox(string $email): string {
        $email = strtolower(trim($email));
        return (string) preg_replace('/^([^+@]+)\+[^@]*@/', '$1@', $email);
    }

    /** @return string[] */
    public static function allowed($thread): array {
        return array_values(array_filter(array_map('strval', (array) (json_decode((string) ($thread->replyAllowed ?? ''), true) ?: []))));
    }

    /**
     * Whether a reply may be acted on, and if not, why (a sentence for the conversation).
     * $ctx as app\Webhook::$onReply gives it.
     */
    public static function refusal($thread, array $ctx): string {
        if (empty($ctx['verified'])) return 'Mailgun\'s signature could not be checked (the mail connection has no signing key)';
        if (!empty($ctx['automatic'])) return 'it is an automatic reply';
        if (empty($ctx['authenticated'])) return 'the sender\'s address could not be confirmed (it passed neither DKIM nor SPF)';
        if (!in_array(self::mailbox((string) $ctx['from']), array_map([self::class, 'mailbox'], self::allowed($thread)), true)) return 'it came from ' . $ctx['from'] . ', which this conversation was never sent to';
        if (trim((string) $ctx['text']) === '') return 'it has no text of its own';
        return '';
    }

    /** app\Webhook::$onReply — registered in lib/controlplane.php. */
    public static function reply(array $ctx): void {
        $thread = $ctx['thread'];
        if (!in_array((string) $thread->relatedType, self::TYPES, true)) return;
        $memberId = (int) $thread->ownerMemberId;
        if (($why = self::refusal($thread, $ctx)) !== '') { self::note($thread, 'Not acted on: ' . $why . '. It is kept here.'); return; }
        $inst = Bean::findOne('instance', 'slug = ?', [(string) $thread->projectSlug]);
        if (!$inst || !$inst->id || !$inst->accessibleBy($memberId)) { self::note($thread, 'Not acted on: the project ' . $thread->projectSlug . ' is gone, or no longer yours to build in.'); return; }
        $text = mb_substr(trim((string) $ctx['text']), 0, 20000);
        $dir = self::dirOf((string) $inst->slug, (string) ($inst->app ?? ''));

        // The planner's question (one conversation per question): a reply is its answer, and planning starts again.
        if ((string) $thread->relatedType === 'askq') {
            if (AskTriage::answered((int) $thread->relatedId, $memberId, $text)) {
                self::triage((int) $thread->relatedId, 'build');
                self::say($thread, $memberId, 'Re: ' . self::subjectOf($ctx), '<p>Got your answer. It is being planned with it now; you will hear from its plan when it is built, or if it needs you.</p>');
                return;
            }
            // already answered (or no longer waiting): taken as a new message, as anything else is
        }
        if ((string) $thread->relatedType === 'plan') {
            $done = self::onPlan((int) $thread->relatedId, $inst, $dir, $memberId, $text);
            if ($done !== '') { self::say($thread, $memberId, 'Re: ' . self::subjectOf($ctx), '<p>' . htmlspecialchars($done) . '</p>'); return; }
        }
        $promptId = self::ask($inst, $memberId, $text, (int) $thread->id);
        self::note($thread, "Sent to {$inst->slug}'s Ask as message #{$promptId}: the answer comes here.");
    }

    /**
     * A reply about a plan, when it is one of the two things a plan waits on its person for. Returns
     * what was done, in a sentence — or '' when the reply is not one of those (it then goes to Ask).
     */
    public static function onPlan(int $planId, $inst, string $dir, int $memberId, string $text): string {
        return self::inBoard($dir, function () use ($planId, $inst, $dir, $memberId, $text): string {
            $plan = Bean::load('workbenchtask', $planId);
            if (!$plan->id) return '';
            $waiting = Bean::findOne('workbenchtask', "parent_task_id = ? AND status = 'awaiting' AND asked_json IS NOT NULL AND asked_json <> '' ORDER BY id", [$planId]);
            $asked = $waiting ? (json_decode((string) $waiting->askedJson, true) ?: []) : [];
            $last = $asked ? array_key_last($asked) : null;
            if ($waiting && $last !== null && trim((string) ($asked[$last]['a'] ?? '')) === '') {
                // The answer, as taskretry records one typed on the board: beside the question, and the task goes again.
                $asked[$last]['a'] = mb_substr($text, 0, 2000);
                $asked[$last]['answered_at'] = date('Y-m-d H:i:s');
                $waiting->askedJson = json_encode($asked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (trim((string) $waiting->errorMessage) !== '') $waiting->lastFailReason = mb_substr((string) $waiting->errorMessage, 0, 1000);
                $waiting->progressMessage = ''; $waiting->errorMessage = ''; $waiting->status = 'pending'; $waiting->retryCount = 0; $waiting->catchUps = 0;
                $waiting->updatedAt = date('Y-m-d H:i:s');
                Bean::store($waiting);
                $c = Bean::dispense('taskcomment');
                $c->taskId = (int) $waiting->id; $c->memberId = $memberId; $c->content = $text; $c->isFromClaude = 0; $c->createdAt = date('Y-m-d H:i:s');
                Bean::store($c);
                self::go($plan, $inst, $dir, $memberId);
                return 'Got your answer. "' . $waiting->title . '" goes on with it.';
            }
            if (!empty($plan->budgetHold) && self::saysYes($text)) {
                self::go($plan, $inst, $dir, $memberId);
                return (int) $plan->budgetHold === 2 ? 'Building it anyway, as you said.' : 'Going on past its spending limit, as you said.';
            }
            return '';
        });
    }

    /** A plain yes: the first words agree and nothing in the first line says no. */
    public static function saysYes(string $text): bool {
        $first = mb_strtolower(trim((string) strtok(trim($text), "\n")));
        if ($first === '' || preg_match('/\b(no|not|don\'t|dont|stop|wait|cancel|hold)\b/u', $first)) return false;
        return preg_match('/^(yes|yep|yeah|y|ok|okay|sure|go|go on|go ahead|continue|build|build it|build it anyway|do it|please do)\b/u', $first) === 1;
    }

    /** The plan goes on: now, or queued behind another changing the same files (the board's Build). */
    private static function go($plan, $inst, string $dir, int $memberId): void {
        $level = (int) (Bean::load('member', $memberId)->level ?? 100);
        if (PlanOrchestrator::running((int) $plan->id, (string) $inst->slug)) return;
        $r = BuildQueue::start($plan, (string) $inst->slug, $dir, $level);
        if ($r['state'] === 'failed') throw new \RuntimeException("plan #{$plan->id} of {$inst->slug} could not be started again");
    }

    /**
     * A message for the project's Ask, from a member, by email: recorded as one typed there would be,
     * and read (scripts/ask-triage.php). Its answer is emailed back on $threadId (afterTriage()).
     */
    public static function ask($inst, int $memberId, string $text, int $threadId): int {
        $app = (string) ($inst->app ?: 'tiknix');
        $promptId = PromptLog::record(['member_id' => $memberId, 'source' => PromptLog::SOURCE_DECOMPOSE, 'title' => mb_substr(trim((string) strtok($text, "\n")), 0, 90),
            'body' => GoalBrief::compose($text, 'none', ''), 'instance_id' => (int) $inst->id, 'instance_tag' => $inst->slug . '.' . $app, 'auto_build' => true]);
        if ($promptId <= 0) throw new \RuntimeException('the emailed message could not be recorded');
        AskTriage::mark($promptId, 'thinking');   // makes its item in the project's Ask, which emailed() marks
        AskStore::emailed(self::dirOf((string) $inst->slug, $app), $promptId, $threadId);
        self::triage($promptId, 'auto');
        return $promptId;
    }

    /** Read a message, detached (scripts/ask-triage.php), as the Ask page does. */
    private static function triage(int $promptId, string $mode): void {
        AskTriage::mark($promptId, $mode === 'build' ? 'build' : 'thinking');
        $core = dirname(__DIR__);
        $cmd = 'env -u TIKNIX_WORKBENCH_DB php ' . escapeshellarg($core . '/scripts/ask-triage.php') . ' --prompt=' . $promptId . ' --mode=' . escapeshellarg($mode) . ' >> ' . escapeshellarg($core . '/log/tiknix-ask.log') . ' 2>&1';
        TmuxManager::create('tiknix-ask-' . $promptId . '-' . substr(bin2hex(random_bytes(3)), 0, 4), $cmd, $core);
    }

    /**
     * After a message has been read (scripts/ask-triage.php): one that came by email is answered on
     * its conversation — the answer itself, or what happened to it. '' when it did not come by email.
     */
    public static function afterTriage(int $promptId, string $coreDb): string {
        $p = Bean::load('promptlog', $promptId);
        if (!$p->id) return '';
        $slug = (string) (strstr((string) $p->instanceTag, '.', true) ?: $p->instanceTag);
        $app = (string) substr((string) $p->instanceTag, strlen($slug) + 1);
        $dir = self::dirOf($slug, $app);
        $m = AskStore::get($dir, $promptId);
        if (!$m || (int) ($m['email_thread'] ?? 0) <= 0) return '';
        $ask = 'the Ask page of ' . $slug;
        $html = match ((string) $m['state']) {
            'answer'          => '<p>' . nl2br(htmlspecialchars((string) $m['answer'])) . '</p>',
            'build', 'planned' => '<p>That reads as something to build, so it is being planned now. You will get an email from its plan when it is built, or if it needs you.</p>',
            'asking'          => '<p>Before it can be planned, there is a question for you:</p><blockquote>' . nl2br(htmlspecialchars((string) $m['answer'])) . '</blockquote><p>Answer it on ' . $ask . '.</p>',
            'failed'          => '<p>It could not be read: ' . htmlspecialchars((string) $m['answer']) . '</p>',
            default           => '<p>' . nl2br(htmlspecialchars((string) $m['answer'])) . '</p><p>Choose what happens next on ' . $ask . '.</p>',
        };
        return PlanNotifier::onThread($coreDb, (int) $m['email_thread'], (int) $p->memberId, 'Re: ' . mb_substr((string) $p->title, 0, 120), $html);
    }

    /**
     * A member's own address for one project's Ask: a conversation of its own, introduced by an email
     * to them — which is what lets a message from their address to it be acted on. Asked again, the
     * same conversation is introduced again (a lost email). Returns the address.
     */
    public static function askAddress(string $coreDb, $inst, int $memberId): string {
        $restore = Bean::hasDatabase('default') ? 'default' : null;
        if (!Bean::hasDatabase('plannotify')) Bean::addDatabase('plannotify', 'sqlite:' . $coreDb);
        Bean::selectDatabase('plannotify');
        try {
            $t = Bean::findOne('thread', 'related_type = ? AND related_id = ? AND owner_member_id = ?', ['ask', (int) $inst->id, $memberId]);
            if (!$t || !$t->id) {
                $t = Bean::dispense('thread');
                $t->subject = 'Ask ' . ((string) ($inst->name ?? '') ?: $inst->slug) . ' by email'; $t->relatedType = 'ask'; $t->relatedId = (int) $inst->id;
                $t->projectSlug = (string) $inst->slug; $t->ownerMemberId = $memberId; $t->messageCount = 0; $t->status = 'open';
                $t->createdAt = date('Y-m-d H:i:s'); $t->updatedAt = $t->createdAt;
                Bean::store($t);
                ThreadMembers::ensure((int) $t->id, [$memberId], ThreadMembers::ROLE_OWNER);
            }
            $threadId = (int) $t->id; $subject = (string) $t->subject;
        } finally { if ($restore) Bean::selectDatabase($restore); }
        $name = htmlspecialchars((string) ($inst->name ?? '') ?: (string) $inst->slug);
        $r = PlanNotifier::onThread($coreDb, $threadId, $memberId, $subject,
            "<p>This is your address for <strong>{$name}</strong>'s Ask. Reply to this email, or write to its reply address any time, from this address: "
            . 'a question is answered by email, and a request to build is planned and built like one typed into Ask.</p>'
            . '<p>Only this email address is listened to. Mail to it from anywhere else is kept, and not acted on.</p>');
        if (!preg_match('/replies to (\S+)\)/', $r, $m)) throw new \RuntimeException('the introduction was not sent: ' . $r);
        return $m[1];
    }

    /** A project's builder folder on core (its board, its Ask) — core's registry says where. */
    private static function dirOf(string $slug, string $app): string {
        return \Model_Instance::dirForSlug($slug, $app !== '' ? $app : \Model_Instance::DEFAULT_APP);
    }

    /** What the reply was about, for the subject of the answer. */
    private static function subjectOf(array $ctx): string {
        return (string) preg_replace('/^(re|fwd?):\s*/i', '', (string) ($ctx['subject'] ?? 'your reply'));
    }

    /** A line on the conversation for whoever reads it in Communications (no email). */
    private static function note($thread, string $text): void {
        $m = Bean::dispense('message');
        $m->threadId = (int) $thread->id; $m->direction = 'in'; $m->notifyType = 'system'; $m->fromName = 'AI Builder';
        $m->subject = 'Builder'; $m->content = '<p>' . htmlspecialchars($text) . '</p>'; $m->bodyPlain = $text;
        $m->status = 'received'; $m->createdAt = date('Y-m-d H:i:s'); $m->sentAt = $m->createdAt;
        Bean::store($m);
        error_log('BuilderMail thread #' . (int) $thread->id . ': ' . $text);
    }

    /** Said on the conversation AND emailed back (with the reply address, so the person can answer again). */
    private static function say($thread, int $memberId, string $subject, string $html): void {
        $r = PlanNotifier::onThread(dirname(__DIR__) . '/database/tiknix.db', (int) $thread->id, $memberId, $subject, $html);
        if (str_contains($r, 'FAILED')) error_log('ERROR BuilderMail: ' . $r);
    }

    /** Work in a project's board database, then go back to core's. */
    private static function inBoard(string $dir, callable $fn) {
        $key = 'board-' . md5($dir);
        if (!Bean::hasDatabase($key)) Bean::addDatabase($key, 'sqlite:' . rtrim($dir, '/') . '/data/workbench.db');
        Bean::selectDatabase($key);
        try { return $fn(); } finally { Bean::selectDatabase('default'); }
    }
}
