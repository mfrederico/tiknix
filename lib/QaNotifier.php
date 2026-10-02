<?php
/**
 * QaNotifier — QA Testing telling a project's owner something: a scheduled suite went red, or
 * is green again. Delivered the way a build's result is (lib/PlanNotifier.php): a message in
 * Communications, in ONE conversation per project ("QA Testing: <project>"), and an email
 * when mail is connected.
 *
 * Not Notes::system — that is a note from the system admin to a person, and cannot be sent to
 * the system admin themselves; a project's owner often is that person.
 */

namespace app;

class QaNotifier {

    /** @return array{ok:bool,thread:int,email:string} email: sent | failed | off | none */
    public static function tell(object $inst, string $subject, string $html): array {
        $memberId = (int) $inst->memberId;
        $slug = (string) $inst->slug;
        $now = date('Y-m-d H:i:s');
        $plain = trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>'], "\n", $html)), ENT_QUOTES));

        $thread = Bean::findOne('thread', 'related_type = ? AND related_id = ? AND project_slug = ?', ['qa', (int) $inst->id, $slug]);
        if (!$thread || !$thread->id) {
            $thread = Bean::dispense('thread');
            $thread->subject       = 'QA Testing: ' . ($inst->displayName ?: $slug);
            $thread->relatedType   = 'qa';
            $thread->relatedId     = (int) $inst->id;
            $thread->projectSlug   = $slug;
            $thread->ownerMemberId = $memberId;
            $thread->messageCount  = 0;
            $thread->status        = 'open';
            $thread->createdAt     = $now;
        }
        $thread->lastDirection = 'in';
        $thread->lastPreview   = mb_substr($subject, 0, 200);
        $thread->lastMessageAt = $now;
        $thread->messageCount  = (int) $thread->messageCount + 1;
        $thread->updatedAt     = $now;
        Bean::store($thread);
        ThreadMembers::ensure((int) $thread->id, [$memberId], ThreadMembers::ROLE_OWNER);

        $msg = Bean::dispense('message');
        $msg->threadId   = (int) $thread->id;
        $msg->direction  = 'in';
        $msg->notifyType = 'system';
        $msg->fromName   = 'QA Testing';
        $msg->subject    = $subject;
        $msg->content    = $html;
        $msg->bodyPlain  = $plain;
        $msg->status     = 'received';
        $msg->createdAt  = $now;
        $msg->sentAt     = $now;
        Bean::store($msg);
        $thread->wakeParticipants((int) $msg->id);

        // Email is the extra: no mail connected is a normal state, and the note is in the app.
        $email = 'off';
        if (Mailer::isConfigured()) {
            $member = Bean::load('member', $memberId);
            $to = (string) ($member->email ?? '');
            if ($to === '') $email = 'none';
            else {
                try { $email = Mailer::create()->to($to, (string) ($member->username ?? ''))->subject($subject)->send($html, $plain) ? 'sent' : 'failed'; }
                catch (\Throwable $e) { $email = 'failed'; error_log('ERROR QaNotifier: the email to the owner of ' . $slug . ' did not go: ' . $e->getMessage()); }
            }
        }
        return ['ok' => true, 'thread' => (int) $thread->id, 'email' => $email];
    }
}
