<?php
/**
 * Communications — the in-app inbox for the threaded comms subsystem.
 *
 * Role-scoped: ADMIN (level ≤ 50) sees every thread; everyone else sees only
 * threads they own (emailthread.owner_member_id = me). Reading a thread zeroes
 * its unread badge; replying sends a threaded outbound via NotifyService so the
 * conversation continues by email (or in-app only, in demo/offline mode).
 *
 * Routes (authcontrol: communications::* = 100):
 *   /communications                 → index   (thread list + search)
 *   /communications/thread/{id}      → thread  (conversation detail)
 *   /communications/reply/{id}       → reply   (POST, CSRF)
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\NotifyService;

class Communications extends BaseControls\Control {

    /** Hub: thread-list rail + empty detail pane (no thread selected). */
    public function index() {
        if (!$this->requireLogin()) return;

        $search = trim((string)$this->getParam('q', ''));

        $this->render('communications/index', [
            'title'       => 'Communications',
            'threads'     => $this->fetchThreads($search),
            'search'      => $search,
            'activeId'    => 0,
            'isAdmin'     => Flight::hasLevel(LEVELS['ADMIN']),
            'unreadTotal' => $this->unreadTotal(),
        ]);
    }

    /** Conversation detail — messages oldest→newest; clears the unread badge. */
    public function thread($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int)($this->routeId($params));
        $thread = Bean::load('emailthread', $id);

        if (!$thread->id || !$this->canView($thread)) {
            $this->flash('error', 'Conversation not found');
            Flight::redirect('/communications');
            return;
        }

        // Messages linked by the plain thread_id column (aliased relation — not
        // an ownNotifyList), so query them explicitly, oldest first.
        $messages = Bean::find('notify', 'thread_id = ? ORDER BY created_at ASC, id ASC', [$id]);

        // Attachments grouped by notify id for inline rendering.
        $attachments = [];
        foreach (Bean::find('notifyattachment', 'thread_id = ? ORDER BY id ASC', [$id]) as $att) {
            $attachments[(int)$att->notifyId][] = $att;
        }

        // Resolve the polymorphic related object natively (no join) for context.
        $related = null;
        if ($thread->relatedType && $thread->relatedId) {
            $r = $thread->poly('relatedType')->related;
            if ($r && $r->id) $related = $r;
        }

        // Reading clears the unread badge.
        if ((int)$thread->unreadCount > 0) {
            $thread->unreadCount = 0;
            $thread->updatedAt   = date('Y-m-d H:i:s');
            Bean::store($thread);
        }

        $search = trim((string)$this->getParam('q', ''));

        $this->render('communications/thread', [
            'title'       => $thread->subject ?: 'Conversation',
            'threads'     => $this->fetchThreads($search),
            'search'      => $search,
            'activeId'    => (int)$thread->id,
            'thread'      => $thread,
            'messages'    => $messages,
            'attachments' => $attachments,
            'related'     => $related,
            'isAdmin'     => Flight::hasLevel(LEVELS['ADMIN']),
            'unreadTotal' => $this->unreadTotal(),
        ]);
    }

    /** Start a new conversation (POST, CSRF) from the compose modal. */
    public function create() {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $to      = trim((string)$this->getParam('to', ''));
        $toName  = trim((string)$this->getParam('to_name', ''));
        $subject = trim((string)$this->getParam('subject', '')) ?: 'New conversation';
        $body    = $this->sanitizeReply((string)$this->getParam('body', ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'A valid recipient email is required');
            Flight::redirect('/communications');
            return;
        }
        if (trim(strip_tags($body)) === '') {
            $this->flash('error', 'Message cannot be empty');
            Flight::redirect('/communications');
            return;
        }

        // No envelope-from override: the message goes out as the verified
        // Mailgun sender so replies route back to reply-{token}@ correctly.
        $result = NotifyService::create()
            ->to($to, $toName)
            ->subject($subject)
            ->owner((int)$this->member->id)
            ->fromName($this->senderName())
            ->send($body);

        if (empty($result['thread'])) {
            $this->flash('error', 'Could not start conversation: ' . ($result['error'] ?? 'unknown'));
            Flight::redirect('/communications');
            return;
        }

        $this->flash($result['sent'] ? 'success' : 'warning',
            $result['sent'] ? 'Conversation started' : 'Conversation created but delivery failed: ' . ($result['error'] ?? 'unknown'));
        Flight::redirect('/communications/thread/' . (int)$result['thread']);
    }

    /** Send a threaded reply (POST, CSRF-protected). */
    public function reply($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $id = (int)($this->routeId($params));
        $thread = Bean::load('emailthread', $id);

        if (!$thread->id || !$this->canView($thread)) {
            $this->flash('error', 'Conversation not found');
            Flight::redirect('/communications');
            return;
        }

        $bodyHtml = $this->sanitizeReply((string)$this->getParam('body', ''));
        if (trim(strip_tags($bodyHtml)) === '') {
            $this->flash('error', 'Reply cannot be empty');
            Flight::redirect('/communications/thread/' . $id);
            return;
        }

        // Recipient: thread's stored recipient, else the first outbound notify.
        $toEmail = $thread->recipientEmail ?: '';
        $toName  = $thread->recipientName  ?: '';
        if ($toEmail === '') {
            $firstOut = Bean::findOne('notify', 'thread_id = ? AND direction = ? ORDER BY created_at ASC', [$id, 'out']);
            if ($firstOut) { $toEmail = $firstOut->toEmail; $toName = $firstOut->toName; }
        }
        if ($toEmail === '') {
            $this->flash('error', 'No recipient on this conversation');
            Flight::redirect('/communications/thread/' . $id);
            return;
        }

        // Thread the reply off the most recent message that carries a Message-ID.
        $last = Bean::findOne(
            'notify',
            "thread_id = ? AND message_id != '' ORDER BY created_at DESC, id DESC",
            [$id]
        );
        $inReplyTo = $last->messageId ?? null;
        $prevRefs  = ($last && $last->referencesList) ? preg_split('/\s+/', trim($last->referencesList)) : [];

        $subject = $this->replySubject($thread->subject ?: 'Conversation');

        // No envelope-from override — send as the verified Mailgun sender so
        // the Reply-To (reply-{token}@) keeps routing responses back in-app.
        $svc = NotifyService::create()
            ->to($toEmail, $toName)
            ->subject($subject)
            ->owner((int)$thread->ownerMemberId)
            ->fromName($this->senderName())
            ->onThread($id)
            ->inReplyTo($inReplyTo, $prevRefs);

        // Preserve the polymorphic related-entity link on the outbound row.
        if ($thread->relatedType && $thread->relatedId) {
            $svc->relatedTo((string)$thread->relatedType, (int)$thread->relatedId);
        }

        $result = $svc->send($bodyHtml);

        if ($result['sent']) {
            $this->flash('success', 'Reply sent');
        } else {
            $this->flash('error', 'Reply saved but delivery failed: ' . ($result['error'] ?? 'unknown'));
        }
        Flight::redirect('/communications/thread/' . $id);
    }

    /**
     * Live-poll (GET, JSON): messages on one thread newer than since_msg, plus
     * the viewer's scoped unread total for the nav bell. Returns only the delta
     * so the thread view appends new bubbles without re-rendering the page.
     *   /communications/poll?thread={id}&since_msg={lastId}
     */
    public function poll() {
        if (!$this->requireLogin()) { Flight::json(['new_messages' => [], 'unread_total' => 0]); return; }

        $threadId = (int)$this->getParam('thread', 0);
        $sinceId  = (int)$this->getParam('since_msg', 0);
        $thread   = Bean::load('emailthread', $threadId);

        // Unknown/forbidden thread: still hand back the bell total so the poll
        // isn't wasted, but no messages.
        if (!$thread->id || !$this->canView($thread)) {
            Flight::json(['new_messages' => [], 'unread_total' => $this->unreadTotal()]);
            return;
        }

        $new = [];
        foreach (Bean::find('notify', 'thread_id = ? AND id > ? ORDER BY id ASC', [$threadId, $sinceId]) as $m) {
            $new[] = [
                'id'          => (int)$m->id,
                'thread_id'   => (int)$m->threadId,
                'direction'   => $m->direction,
                'notify_type' => $m->notifyType,
                'from_name'   => $m->fromName ?: $m->fromEmail,
                'status'      => $m->status,
                'content'     => $m->content,   // stored already sanitized (webhook in / reply out)
                'error'       => $m->status === 'failed' ? (string)$m->errorMessage : '',
                'ts'          => $m->createdAt,
            ];
        }

        // The viewer is actively looking at this thread, so anything that just
        // arrived is "read" — clear its badge (mirrors thread() on load).
        if ($new && (int)$thread->unreadCount > 0) {
            $thread->unreadCount = 0;
            $thread->updatedAt   = date('Y-m-d H:i:s');
            Bean::store($thread);
        }

        Flight::json(['new_messages' => $new, 'unread_total' => $this->unreadTotal()]);
    }

    /**
     * Nav bell feed (GET, JSON): scoped unread total + the most recent threads
     * for the dropdown. Polled globally (every page) by the bell component.
     */
    public function unreadjson() {
        if (!$this->requireLogin()) { Flight::json(['unread' => 0, 'threads' => []]); return; }

        [$where, $params] = $this->scopeClause();
        $threads = Bean::find('emailthread', $where . ' ORDER BY last_message_at DESC, id DESC LIMIT 8', $params);

        $out = [];
        foreach ($threads as $t) {
            $out[] = [
                'id'              => (int)$t->id,
                'subject'         => (string)($t->subject ?: '(no subject)'),
                'who'             => (string)($t->recipientName ?: $t->recipientEmail ?: 'Unknown'),
                'preview'         => (string)($t->lastPreview ?? ''),
                'last_message_at' => $t->lastMessageAt,
                'last_direction'  => (string)($t->lastDirection ?? ''),
                'unread_count'    => (int)$t->unreadCount,
            ];
        }

        Flight::json(['unread' => $this->unreadTotal(), 'threads' => $out]);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Display name for outbound mail: the sending member's real name
     * (first + last), falling back to username then email. The From email
     * stays the verified Mailgun sender — only the display name changes.
     */
    private function senderName(): string {
        $first = trim((string)($this->member->firstName ?? ''));
        $last  = trim((string)($this->member->lastName  ?? ''));
        $name  = trim($first . ' ' . $last);
        if ($name === '') {
            $name = (string)($this->member->username ?: $this->member->email ?: '');
        }
        return $name;
    }

    /** Scoped, optionally-searched thread list for the sidebar rail. */
    private function fetchThreads(string $search = ''): array {
        [$where, $params] = $this->scopeClause();
        if ($search !== '') {
            $where .= ' AND (subject LIKE ? OR recipient_email LIKE ? OR recipient_name LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        return Bean::find('emailthread', $where . ' ORDER BY last_message_at DESC, id DESC', $params);
    }

    /** WHERE fragment scoping threads to the viewer's role. */
    private function scopeClause(): array {
        if (Flight::hasLevel(LEVELS['ADMIN'])) {
            return ['1=1', []];
        }
        return ['owner_member_id = ?', [(int)$this->member->id]];
    }

    private function canView($thread): bool {
        if (Flight::hasLevel(LEVELS['ADMIN'])) return true;
        return (int)$thread->ownerMemberId === (int)$this->member->id;
    }

    private function unreadTotal(): int {
        [$scopeSql, $scopeParams] = $this->scopeClause();
        return (int)Bean::count('emailthread', $scopeSql . ' AND unread_count > 0', $scopeParams);
    }

    /** Path id from /communications/{method}/{id}, falling back to ?id=. */
    private function routeId($params): int {
        if (is_array($params) && isset($params['operation']) && is_object($params['operation'])) {
            $n = $params['operation']->name ?? null;
            if ($n !== null && $n !== '') return (int)$n;
        }
        return (int)$this->getParam('id', 0);
    }

    /** Prefix Re: once (don't stack it on an already-Re: subject). */
    private function replySubject(string $subject): string {
        return preg_match('/^\s*re\s*:/i', $subject) ? $subject : 'Re: ' . $subject;
    }

    /**
     * Allowlist-sanitize a composed reply and force safe link attributes.
     * Mirrors the port spec's allowlist.
     */
    private function sanitizeReply(string $html): string {
        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><span><div><blockquote>';
        $clean = strip_tags($html, $allowed);
        $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/\bhref\s*=\s*(["\'])\s*javascript:[^"\']*\1/i', 'href="#"', $clean);
        $clean = preg_replace('/\bhref\s*=\s*javascript:[^\s>]*/i', 'href="#"', $clean);
        // Force target=_blank rel=noopener on anchors.
        $clean = preg_replace_callback('/<a\b([^>]*)>/i', function ($m) {
            $attrs = preg_replace('/\s(target|rel)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $m[1]);
            return '<a' . $attrs . ' target="_blank" rel="noopener">';
        }, $clean);
        return $clean;
    }
}
