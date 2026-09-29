<?php
/**
 * Notes — a person writes to someone, and it reaches them in the app AND by email.
 *
 * One message row in one conversation, delivered twice: in-app (Communications, unread
 * badge, live alert) and as an email whose Reply-To carries the conversation's token, so
 * an answer from their mail client lands back in the same conversation
 * (controls/Webhook::mailgun). The note is signed by the person who sent it — replies go
 * to them, not to a shared inbox.
 *
 *   Notes::toMember($from, $to, $subject, $html)   a direct conversation between two people
 *   Notes::onThread($from, $threadId, $html)       an existing conversation, e.g. a support
 *                                                  ticket; the email goes to its recipient
 *
 * Callers: Communications → note (the admin "Message owner" button), the send_note MCP
 * tool, and support replies (Contact::respond). A note that could not be written throws;
 * an email that did not go out is reported in the result, never as sent.
 */

namespace app;

use app\services\NotifyService;

class Notes {

    /**
     * @return array{thread:int,message:int,email:string,email_error:?string}
     *         email: sent | failed | off (mail not configured) | none (no address)
     */
    public static function toMember(int $fromMemberId, int $toMemberId, string $subject, string $html): array {
        $from = self::member($fromMemberId, 'sender');
        $to   = self::member($toMemberId, 'recipient');
        if (!Teammates::canMessage($fromMemberId, $toMemberId, (int) $from->level)) {
            throw new \RuntimeException("{$from->displayName('member #' . $fromMemberId)} cannot message member #{$toMemberId}: you can only message people you share a team with.");
        }
        $threadId = NotifyService::dmThread($fromMemberId, $toMemberId);
        if (!$threadId) throw new \RuntimeException("Could not open the conversation between member #{$fromMemberId} and member #{$toMemberId}.");
        return self::post($from, $threadId, $subject, $html, (string) $to->email, $to->displayName(''));
    }

    /**
     * A note on an existing conversation. The email copy goes to the conversation's outside
     * recipient (a support ticket's sender); a recipient who has an account is seated in the
     * conversation, so the reply is in their Communications too.
     *
     * @return array{thread:int,message:int,email:string,email_error:?string}
     */
    public static function onThread(int $fromMemberId, int $threadId, string $html, string $subject = ''): array {
        $from = self::member($fromMemberId, 'sender');
        $thread = Bean::load('thread', $threadId);
        if (!$thread->id) throw new \RuntimeException("No conversation #{$threadId}.");
        ThreadMembers::ensure($threadId, [$fromMemberId]);
        $email = (string) $thread->recipientEmail;
        if ($email !== '') {
            $account = Bean::findOne('member', 'LOWER(email) = ? AND status = ?', [strtolower($email), 'active']);
            if ($account && $account->id) ThreadMembers::ensure($threadId, [(int) $account->id]);
        }
        // The recipient writing on their own conversation (a member following up on their
        // ticket) is not emailed their own words: the support team is seated and sees it.
        if (strcasecmp($email, (string) $from->email) === 0) $email = '';
        // Thread the email off the newest message that went out by email, so mail clients
        // keep the conversation together.
        $last = Bean::findOne('message', "thread_id = ? AND message_eid != '' ORDER BY id DESC", [$threadId]);
        $refs = ($last && $last->referencesList) ? preg_split('/\s+/', trim((string) $last->referencesList)) : [];
        $subject = $subject !== '' ? $subject : (string) $thread->subject;
        if ($last && $subject !== '' && !preg_match('/^re:/i', $subject)) $subject = 'Re: ' . $subject;
        return self::post($from, $threadId, $subject, $html, $email, (string) $thread->recipientName, $last ? (string) $last->messageEid : null, $refs);
    }

    private static function post(\RedBeanPHP\OODBBean $from, int $threadId, string $subject, string $html, string $toEmail, string $toName, ?string $inReplyTo = null, array $refs = []): array {
        $html = HtmlSanitizer::clean($html);
        if (trim(strip_tags($html)) === '') throw new \InvalidArgumentException('A note cannot be empty.');
        $messageId = NotifyService::postInApp($threadId, (int) $from->id, $html);
        if (!$messageId) throw new \RuntimeException("Could not write the note into conversation #{$threadId}.");

        $out = ['thread' => $threadId, 'message' => $messageId, 'email' => 'none', 'email_error' => null];
        if ($toEmail === '') return $out;

        $r = NotifyService::create()
            ->to($toEmail, $toName)
            ->subject($subject !== '' ? $subject : 'A note from ' . $from->displayName('member #' . $from->id))
            ->fromName($from->displayName('member #' . $from->id))
            ->inReplyTo($inReplyTo, $refs)
            ->emailCopy($messageId);
        $out['email'] = $r['status'];
        $out['email_error'] = $r['error'];
        if ($r['status'] !== 'sent') {
            error_log("ERROR Notes: note #{$messageId} in conversation #{$threadId} is in the app but its email to {$toEmail} was not sent ({$r['status']}): {$r['error']}");
        }
        return $out;
    }

    private static function member(int $id, string $role): \RedBeanPHP\OODBBean {
        $m = Bean::load('member', $id);
        if (!$m->id) throw new \RuntimeException("No {$role}: member #{$id} does not exist.");
        return $m;
    }
}
