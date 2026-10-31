<?php
/**
 * SupportDraft — an agent's investigation of a support ticket, waiting for a person. The agent leaves two things on the ticket: its
 * findings (internal: paths and log lines a customer should not see) and a draft reply. The reply fills the ticket page's own reply
 * box, so the only way it reaches anyone is a signed-in admin pressing Send Response there. Nothing here sends. If the conversation
 * moves on after the draft was written, the page says so. One live draft per ticket: a new one replaces it.
 */

namespace app;

class SupportDraft implements TicketExtension {

    /** Leave a draft on a ticket. @return int its id @throws \InvalidArgumentException with the reason */
    public static function save(int $ticketId, string $reply, string $findings, string $author): int {
        $ticket = Bean::load('contact', $ticketId);
        if (!$ticket->id) throw new \InvalidArgumentException("There is no support ticket #{$ticketId}.");
        $reply = trim($reply);
        if ($reply === '') throw new \InvalidArgumentException('A draft needs the reply a person would send.');
        $d = self::active($ticketId) ?? Bean::dispense('supportdraft');
        $d->contactRef = $ticketId;
        $d->reply = $reply;
        $d->findings = trim($findings);
        $d->author = mb_substr(trim($author), 0, 120);
        $d->status = 'draft';
        $d->lastMessageRef = self::lastMessage($ticketId);
        $d->createdAt = date('Y-m-d H:i:s');
        return (int) Bean::store($d);
    }

    /** The draft waiting on this ticket, or null. */
    public static function active(int $ticketId): ?object {
        return Bean::findOne('supportdraft', "contact_ref = ? AND status = 'draft' ORDER BY id DESC", [$ticketId]) ?: null;
    }

    public static function dismiss(int $ticketId): bool {
        $d = self::active($ticketId);
        if (!$d) return false;
        $d->status = 'dismissed';
        Bean::store($d);
        return true;
    }

    /** The newest message in the ticket's conversation. 0: none yet. */
    private static function lastMessage(int $ticketId): int {
        return (int) Bean::getCell("SELECT MAX(m.id) FROM message m JOIN thread t ON t.id = m.thread_id WHERE t.related_type = 'contact' AND t.related_id = ?", [$ticketId]);
    }

    /** Has anything been said on the ticket since this draft was written? */
    public static function outOfDate(object $draft): bool {
        return self::lastMessage((int) $draft->contactRef) > (int) $draft->lastMessageRef;
    }

    // ---- the ticket pages (Contact::$extension) ---------------------------------------------------------------------

    public function panel(object $ticket): string {
        $d = self::active((int) $ticket->id);
        if (!$d) return '';
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $h = self::outOfDate($d)
            ? Ui::notice(['tone' => 'warning', 'title' => 'This draft is out of date.', 'text' => 'Someone wrote on this ticket after the draft was made. Read the conversation before you send it.'])
            : '';
        $h .= '<section class="ui-panel mb-3"><div class="ui-panel-header"><h3><i class="bi bi-robot" aria-hidden="true"></i> A draft reply is in the box below</h3></div><div class="ui-panel-body">'
            . '<p class="mb-2">Left by ' . $e($d->author ?: 'an agent') . ' on ' . $e($d->createdAt) . '. Nothing has been sent. Edit it if you like, then press Send Response.</p>';
        if ((string) $d->findings !== '') {
            $h .= '<details class="mb-2"><summary class="fw-semibold" style="cursor:pointer">What it found (only admins see this)</summary><div class="mt-2 small" style="white-space:pre-wrap;overflow-wrap:anywhere">' . $e($d->findings) . '</div></details>';
        }
        $h .= '<form method="post" action="/draftreply/dismiss" class="m-0">' . csrf_field() . '<input type="hidden" name="ticket" value="' . (int) $ticket->id . '"><button type="submit" class="btn btn-sm btn-outline-secondary">Throw this draft away</button></form>';
        return $h . '</div></section>';
    }

    public function prefill(object $ticket): string {
        $d = self::active((int) $ticket->id);
        return $d ? (string) $d->reply : '';
    }

    public function responded(int $ticketId): void {
        $d = self::active($ticketId);
        if (!$d) return;
        $d->status = 'sent';
        $d->sentAt = date('Y-m-d H:i:s');
        Bean::store($d);
    }

    public function mark(int $ticketId): string {
        return self::active($ticketId) ? ' <span class="badge text-bg-warning">Draft ready</span>' : '';
    }
}
