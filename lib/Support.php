<?php
/**
 * Support — a support ticket, however it arrives: the public form, a signed-in member's
 * Support page, or an agent escalating a problem the member agreed to send.
 *
 * A ticket is a `contact` row plus a conversation in Communications that the whole support
 * team is seated in (owned by the most senior admin), and an alert email. A member who has
 * an account is seated too, so the answer reaches them in the app as well as by email.
 * The answer itself goes through lib/Notes.php (Contact::respond, Communications replies).
 *
 *   Support::open($memberId, $subject, $message, $category, $project, $source)
 *       a member's ticket — from the app ('app') or an agent ('agent'); agents are limited
 *       to AGENT_LIMIT tickets per member per hour, so a loop cannot flood the queue
 *   Support::announce(...)   the thread + seats + alert email, for any stored ticket
 */

namespace app;

use \Flight as Flight;

class Support {

    public const CATEGORIES  = ['general', 'problem', 'billing', 'feature'];
    public const AGENT_LIMIT = 5;

    /**
     * Open a ticket for a member. $project (an instance bean) is named at the top of the
     * message, so whoever answers does not have to ask which app it is about.
     *
     * @return array{contact:int, thread:?int}
     * @throws \RuntimeException naming what is wrong (no such member, agent limit reached)
     */
    public static function open(int $memberId, string $subject, string $message, string $category, ?object $project, string $source): array {
        $member = Bean::load('member', $memberId);
        if (!$member->id) throw new \RuntimeException("No member #{$memberId} to open a support ticket for.");
        $subject = trim($subject);
        $message = trim($message);
        if ($subject === '' || $message === '') throw new \InvalidArgumentException('A support ticket needs a subject and a message.');
        if (!in_array($category, self::CATEGORIES, true)) $category = 'general';
        if ($source === 'agent') {
            $recent = Bean::count('contact', "member_id = ? AND source = 'agent' AND created_at > ?", [$memberId, date('Y-m-d H:i:s', time() - 3600)]);
            if ($recent >= self::AGENT_LIMIT) {
                throw new \RuntimeException('This account has sent ' . self::AGENT_LIMIT . ' support tickets from an agent in the last hour; that is the limit. Add to an existing ticket from Communications instead.');
            }
        }
        if ($project && !empty($project->slug)) {
            $message = 'Project: ' . ($project->displayName ?: $project->slug) . " ({$project->slug})\n"
                     . ($source === 'agent' ? "Sent by the project's AI agent, with the member's agreement.\n" : '')
                     . "\n" . $message;
        }

        $c = Bean::dispense('contact');
        $c->name        = $member->displayName('Member #' . $memberId);
        $c->email       = (string) $member->email;
        $c->subject     = mb_substr($subject, 0, 200);
        $c->message     = $message;
        $c->category    = $category;
        $c->status      = 'new';
        $c->memberId    = $memberId;
        $c->source      = $source;
        $c->projectSlug = $project ? (string) $project->slug : '';
        $c->ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '';
        $c->userAgent   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $c->createdAt   = date('Y-m-d H:i:s');
        $contactId = (int) Bean::store($c);
        self::log('info', 'Support ticket opened', ['contact_id' => $contactId, 'member' => $memberId, 'source' => $source, 'project' => $c->projectSlug]);

        $threadId = self::announce($contactId, (string) $c->name, (string) $c->email, (string) $c->subject, $message, $category, true);
        if ($threadId) {
            ThreadMembers::ensure($threadId, [$memberId]);
            if ($source === 'app') ThreadMembers::markRead($threadId, $memberId);   // they just wrote it
        }
        return ['contact' => $contactId, 'thread' => $threadId];
    }

    /**
     * Which operator's inbox support threads land in: the most senior active admin.
     *
     * Deliberately the same person the alert email goes to when [mail] support_email is
     * unset, so the mail and the thread do not end up with different owners.
     */
    public static function ownerId(): int {
        $admin = Bean::findOne('member',
            'level <= ? AND status = ? ORDER BY level ASC, id ASC',
            [LEVELS['ADMIN'], 'active']);
        return (int) ($admin->id ?? 0);
    }

    /**
     * Where support mail should land: an explicitly configured address, or failing that
     * the most senior active admin. Returns '' when there is nobody to tell.
     */
    public static function address(): string {
        $configured = trim((string) (Flight::get('mail.support_email') ?? ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) return $configured;

        $admin = Bean::findOne('member',
            'level <= ? AND status = ? ORDER BY level ASC, id ASC',
            [LEVELS['ADMIN'], 'active']);
        $email = trim((string) ($admin->email ?? ''));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Announce a new support message. Never throws and never blocks the submission — the
     * message is already stored, so the visitor is done either way — but a failure here is
     * logged at ERROR, because "we could not tell anyone" is exactly the kind of quiet
     * breakage that let this table fill up unread in the first place.
     *
     * @return int|null the support conversation's id, null when none could be opened
     */
    public static function announce(
        int $contactId, string $name, string $email, string $subject,
        string $message, string $category, bool $fromMember
    ): ?int {
        $threadId = null;
        // First, into the inbox. A support message IS a message, and Communications is
        // where messages live — an emailed alert about a row in a table is a notification
        // ABOUT the thing rather than the thing itself. The thread is owned by the
        // operator and addressed to the sender, so replying to it answers them through
        // the ordinary reply path.
        try {
            $owner = self::ownerId();
            if ($owner > 0) {
                $threadId = \app\services\NotifyService::openInboundThread(
                    $owner, $email, $name,
                    '[' . $category . '] ' . $subject,
                    nl2br(htmlspecialchars($message, ENT_QUOTES)),
                    'contact', $contactId
                );
                if ($threadId) {
                    $threadId = (int) $threadId;
                    // Seat the whole support team, not just one operator. Support is a
                    // queue somebody answers, not one person's mail — and per-person
                    // unread means each of them tracks their own reading of it without
                    // clearing anybody else's.
                    $admins = array_values(array_map(
                        fn($a) => (int) $a->id,
                        Bean::find('member', 'level <= ? AND status = ?', [LEVELS['ADMIN'], 'active'])
                    ));
                    \app\ThreadMembers::ensure($threadId, $admins);

                    self::log('info', 'Support message threaded into Communications', [
                        'contact_id' => $contactId, 'thread' => $threadId, 'owner' => $owner,
                        'team' => count($admins),
                    ]);
                } else {
                    self::log('error', 'Support message could not open a thread', [
                        'contact_id' => $contactId, 'owner' => $owner,
                    ]);
                }
            } else {
                self::log('error', 'Support message saved but there is no operator to own it', [
                    'contact_id' => $contactId,
                ]);
            }
        } catch (\Throwable $e) {
            self::log('error', 'Support message threading threw', [
                'contact_id' => $contactId, 'error' => $e->getMessage(),
            ]);
        }

        // Then the email, because nobody watches an inbox they are not signed into.
        try {
            $to = self::address();
            if ($to === '') {
                self::log('error', 'Support message saved but nobody to notify', [
                    'contact_id' => $contactId,
                    'hint' => 'set [mail] support_email, or give an admin account a valid email',
                ]);
                return $threadId ?: null;
            }
            if (!Mailer::isConfigured()) {
                self::log('error', 'Support message saved but mail is not configured', [
                    'contact_id' => $contactId, 'would_have_told' => $to,
                ]);
                return $threadId ?: null;
            }

            $sent = Mailer::sendContactAlert($to, $name, $email, $category, $subject,
                                             $message, $contactId, $fromMember);
            if ($sent) {
                self::log('info', 'Support message notification sent', [
                    'contact_id' => $contactId, 'to' => $to,
                ]);
            } else {
                self::log('error', 'Support message notification FAILED to send', [
                    'contact_id' => $contactId, 'to' => $to,
                ]);
            }
        } catch (\Throwable $e) {
            self::log('error', 'Support message notification threw', [
                'contact_id' => $contactId, 'error' => $e->getMessage(),
            ]);
        }
        return $threadId ?: null;
    }

    /** Log through the app's logger; with none (a CLI worker, a unit test), PHP's error log — never nowhere. */
    private static function log(string $level, string $msg, array $ctx = []): void {
        $l = Flight::get('log');
        if ($l) { $l->$level($msg, $ctx); return; }
        error_log(strtoupper($level) . " Support: {$msg} " . json_encode($ctx));
    }
}
