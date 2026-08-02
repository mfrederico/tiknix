<?php
/**
 * Communications — the in-app inbox for the threaded comms subsystem.
 *
 * Role-scoped: ROOT (level 1) sees every thread; everyone else — INCLUDING admins
 * (level 50) — sees only threads they own (emailthread.owner_member_id = me).
 * Communications are private per member; only ROOT has the cross-member view.
 * Reading a thread zeroes
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

        // Rooms follow team membership, so bring them into step before listing. Doing it
        // on read means a room is correct after ANY change to a team — including changes
        // made by code that has never heard of rooms.
        \app\Rooms::syncForMember((int)$this->member->id);

        $search = trim((string)$this->getParam('q', ''));

        $this->render('communications/index', [
            'title'       => 'Communications',
            'threads'     => $this->fetchThreads($search),
            'search'      => $search,
            'activeId'    => 0,
            'isAdmin'     => Flight::hasLevel(LEVELS['ROOT']),   // "sees all" is ROOT-only
            'unreadTotal' => $this->unreadTotal(),
        ]);
    }

    /** Conversation detail — messages oldest→newest; clears the unread badge. */
    public function thread($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int)($this->routeId($params));
        $thread = Bean::load('thread', $id);

        if (!$thread->id || !$this->canView($thread)) {
            $this->flash('error', 'Conversation not found');
            Flight::redirect('/communications');
            return;
        }

        // Messages linked by the plain thread_id column (aliased relation — not
        // an ownNotifyList), so query them explicitly, oldest first.
        $messages = Bean::find('message', 'thread_id = ? ORDER BY created_at ASC, id ASC', [$id]);

        // Attachments grouped by notify id for inline rendering.
        $attachments = [];
        foreach (Bean::find('messageattachment', 'thread_id = ? ORDER BY id ASC', [$id]) as $att) {
            $attachments[(int)$att->notifyId][] = $att;
        }

        // Resolve the polymorphic related object natively (no join) for context.
        $related = null;
        if ($thread->relatedType && $thread->relatedId) {
            $r = $thread->poly('relatedType')->related;
            if ($r && $r->id) $related = $r;
        }

        // Reading clears the unread badge — for THIS reader. The thread-level counter is
        // still written so anything not yet moved across stays consistent, but the figure
        // that now drives the bell is the per-person read mark.
        $thread->markRead((int)$this->member->id);
        \app\Mentions::markRead((int)$thread->id, (int)$this->member->id);

        $search = trim((string)$this->getParam('q', ''));

        $this->render('communications/thread', [
            // Browser-tab title. "#general" alone is ambiguous with several teams, and a
            // tab title is exactly where you are choosing between open windows.
            'title'       => $thread->title((int)$this->member->id),
            'threads'     => $this->fetchThreads($search),
            'search'      => $search,
            'activeId'    => (int)$thread->id,
            'thread'      => $thread,
            'messages'    => $messages,
            'attachments' => $attachments,
            'related'     => $related,
            'isAdmin'     => Flight::hasLevel(LEVELS['ROOT']),   // "sees all" is ROOT-only
            'unreadTotal' => $this->unreadTotal(),
        ]);
    }

    /** Start a new conversation (POST, CSRF) from the compose modal. */
    public function create() {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $to       = trim((string)$this->getParam('to', ''));
        $toName   = trim((string)$this->getParam('to_name', ''));
        $toMember = (int)$this->getParam('to_member', 0);
        $subject  = trim((string)$this->getParam('subject', '')) ?: 'New conversation';
        $body     = $this->sanitizeReply((string)$this->getParam('body', ''));

        $toRoom  = (int)$this->getParam('to_room', 0);
        $mid     = (int)$this->member->id;
        $level   = (int)$this->member->level;

        // Posting in a team's room IS broadcasting to that team — there is no separate
        // broadcast, because a message everyone on the team can see and reply to in one
        // place is what "tell the team" should mean.
        if ($toRoom > 0) {
            if (trim(strip_tags($body)) === '') {
                $this->flash('error', 'Message cannot be empty');
                Flight::redirect('/communications');
                return;
            }
            $room = Bean::load('thread', $toRoom);
            if (!$room->id || (string)$room->kind !== 'room') {
                $this->flash('error', 'No such room.');
                Flight::redirect('/communications');
                return;
            }
            // Sync first: membership is derived from the team, so the answer to "may I
            // post here" must be computed from the team as it is NOW, not as it was when
            // the participant rows were last written.
            \app\Rooms::syncMembers($toRoom, (int)$room->teamId);
            if (!\app\Rooms::canPost($toRoom, $mid, $level)) {
                $this->logger->warning('Blocked room post from a non-member', [
                    'thread' => $toRoom, 'team' => (int)$room->teamId, 'member' => $mid,
                ]);
                $this->flash('error', 'That room belongs to a team you are not on.');
                Flight::redirect('/communications');
                return;
            }
            if (!\app\Rooms::post($toRoom, $mid, $body)) {
                $this->logger->error('Could not post to room', ['thread' => $toRoom, 'member' => $mid]);
                $this->flash('error', 'Could not post that message.');
                Flight::redirect('/communications');
                return;
            }
            // Posting from a team page should leave you on that team page. Only an
            // in-app path is accepted — an open redirect is an open redirect even when
            // the form that feeds it is ours.
            $back = (string)$this->getParam('redirect', '');
            $safe = (str_starts_with($back, '/') && !str_starts_with($back, '//'));
            Flight::redirect($safe && $back !== '' ? $back : '/communications/thread/' . $toRoom);
            return;
        }

        // A message to a PERSON stays in the application. This is the default path and
        // needs no grant, because both ends already share a team.
        if ($toMember > 0) {
            if (trim(strip_tags($body)) === '') {
                $this->flash('error', 'Message cannot be empty');
                Flight::redirect('/communications');
                return;
            }
            if (!\app\Teammates::canMessage($mid, $toMember, $level)) {
                // Says the rule, not just "no". Someone who cannot find a colleague here
                // needs to know the answer is "share a team with them".
                $this->logger->warning('Blocked DM outside shared teams', [
                    'from' => $mid, 'to' => $toMember,
                ]);
                $this->flash('error', 'You can only message people you share a team with.');
                Flight::redirect('/communications');
                return;
            }

            $threadId = \app\services\NotifyService::dmThread($mid, $toMember);
            if (!$threadId) {
                $this->logger->error('Could not open a direct conversation', ['from' => $mid, 'to' => $toMember]);
                $this->flash('error', 'Could not open that conversation.');
                Flight::redirect('/communications');
                return;
            }
            if (!\app\services\NotifyService::postInApp($threadId, $mid, $body)) {
                $this->logger->error('Could not post an in-app message', ['thread' => $threadId, 'from' => $mid]);
                $this->flash('error', 'Could not send that message.');
                Flight::redirect('/communications');
                return;
            }
            Flight::redirect('/communications/thread/' . $threadId);
            return;
        }

        // Everything below leaves the building.
        if (!\app\Teammates::canSendEmail($mid, $level)) {
            // The compose form does not offer email to anyone without the grant, so
            // arriving here means the field was supplied by hand. Refuse and say so.
            $this->logger->warning('Blocked outbound email from Communications', [
                'member' => $mid, 'level' => $level, 'to' => $to,
            ]);
            $this->flash('error', 'Sending email is not enabled for your account. You can message people you share a team with.');
            Flight::redirect('/communications');
            return;
        }

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

    /**
     * Mark a thread read or unread (POST, CSRF).
     *
     * The same three controls the support queue has had all along — read, open, delete —
     * because a thread list you can only ever open is a list you cannot keep tidy.
     *
     * Per person: one operator marking a shared support thread unread must not put a
     * badge on it for the whole team. markUnread() rewinds the read mark to just before
     * the newest message, so exactly one thing is waiting.
     */
    public function markread($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::csrf()->validateRequest()) { Flight::jsonError('Invalid CSRF token', 403); return; }

        $thread = Bean::load('thread', (int)$this->getParam('id', 0));
        if (!$thread->id)        { Flight::jsonError('No such conversation.', 404); return; }
        if (!$this->canView($thread)) { Flight::jsonError('That is not your conversation.', 403); return; }

        $read = (int)$this->getParam('read', 1) === 1;
        // Per-person, so one operator marking a shared support thread unread does not
        // put a badge on it for the whole team.
        $mid = (int)$this->member->id;
        $read ? $thread->markRead($mid) : $thread->markUnread($mid);

        Flight::jsonSuccess(
            ['id' => (int)$thread->id, 'unread' => $thread->unreadFor($mid), 'unread_total' => $this->unreadTotal()],
            $read ? 'Marked as read' : 'Marked as unread'
        );
    }

    /**
     * Delete a thread and its messages (POST, CSRF).
     *
     * The notify rows are removed explicitly rather than by cascade: they hang off
     * thread_id, but RedBean's xown cascade keys off the PARENT BEAN TYPE, and the parent
     * here is 'thread' while the column says 'thread'. Relying on a cascade that does
     * not fire would leave orphaned messages behind and nothing would say so.
     */
    public function remove($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::csrf()->validateRequest()) { Flight::jsonError('Invalid CSRF token', 403); return; }

        $thread = Bean::load('thread', (int)$this->getParam('id', 0));
        if (!$thread->id)        { Flight::jsonError('No such conversation.', 404); return; }
        if (!$this->canView($thread)) { Flight::jsonError('That is not your conversation.', 403); return; }

        $id = (int)$thread->id;
        $messages = Bean::find('message', 'thread_id = ?', [$id]);
        foreach ($messages as $m) Bean::trash($m);
        Bean::trash($thread);

        $this->logger->info('Conversation deleted', [
            'thread' => $id, 'messages' => count($messages), 'by' => (int)$this->member->id,
        ]);

        Flight::jsonSuccess(
            ['id' => $id, 'messages' => count($messages), 'unread_total' => $this->unreadTotal()],
            'Conversation deleted'
        );
    }

    /** Send a threaded reply (POST, CSRF-protected). */
    public function reply($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $id = (int)($this->routeId($params));
        $thread = Bean::load('thread', $id);

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

        // An in-app conversation is answered in-app. Falling through to the email path
        // would try to post a DM to an empty address, and the only reason it would not
        // send something absurd is that it fails the recipient check below by accident.
        if (in_array((string)$thread->kind, ['dm', 'room'], true)) {
            $mid = (int)$this->member->id;

            // For a room, canView() above passed on a participant row — which is a COPY
            // of team membership and can be stale. Re-derive from the team before
            // accepting a post, so somebody removed from a team cannot keep talking in
            // its room just because nobody has opened the inbox since.
            if ((string)$thread->kind === 'room') {
                \app\Rooms::syncMembers($id, (int)$thread->teamId);
                if (!\app\Rooms::canPost($id, $mid, (int)$this->member->level)) {
                    $this->logger->warning('Blocked room reply from a non-member', [
                        'thread' => $id, 'team' => (int)$thread->teamId, 'member' => $mid,
                    ]);
                    $this->flash('error', 'That room belongs to a team you are not on.');
                    Flight::redirect('/communications');
                    return;
                }
            }
            if (!\app\services\NotifyService::postInApp($id, $mid, $bodyHtml)) {
                $this->logger->error('In-app reply failed', ['thread' => $id, 'from' => $mid]);
                $this->flash('error', 'Could not send that message.');
            }
            Flight::redirect('/communications/thread/' . $id);
            return;
        }

        // Recipient: thread's stored recipient, else the first outbound notify.
        $toEmail = $thread->recipientEmail ?: '';
        $toName  = $thread->recipientName  ?: '';
        if ($toEmail === '') {
            $firstOut = Bean::findOne('message', 'thread_id = ? AND direction = ? ORDER BY created_at ASC', [$id, 'out']);
            if ($firstOut) { $toEmail = $firstOut->toEmail; $toName = $firstOut->toName; }
        }
        if ($toEmail === '') {
            $this->flash('error', 'No recipient on this conversation');
            Flight::redirect('/communications/thread/' . $id);
            return;
        }

        // Thread the reply off the most recent message that carries a Message-ID.
        $last = Bean::findOne(
            'message',
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
        $thread   = Bean::load('thread', $threadId);

        // Unknown/forbidden thread: still hand back the bell total so the poll
        // isn't wasted, but no messages.
        if (!$thread->id || !$this->canView($thread)) {
            Flight::json(['new_messages' => [], 'unread_total' => $this->unreadTotal()]);
            return;
        }

        $new = [];
        foreach (Bean::find('message', 'thread_id = ? AND id > ? ORDER BY id ASC', [$threadId, $sinceId]) as $m) {
            $new[] = [
                'id'          => (int)$m->id,
                'thread_id'   => (int)$m->threadId,
                'direction'   => $m->direction,
                // Which side of the feed it belongs on. direction only answers that for
                // email; an in-app message is always direction='out' whoever sent it.
                //
                // 0 is a real answer for EMAIL — it came from an address, not an account.
                // For an in-app message it is not: postInApp() always records a sender, so
                // a missing one is a corrupt row. Say so rather than quietly rendering it
                // as somebody else's message.
                'sender_member_id' => $this->senderIdOf($m),
                'is_mine'          => $m->isMine((int)$this->member->id),
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
        if ($new) $thread->markRead((int)$this->member->id);

        Flight::json(['new_messages' => $new, 'unread_total' => $this->unreadTotal(),
                      'mentions' => \app\Mentions::unreadCount((int)$this->member->id)]);
    }

    /**
     * Nav bell feed (GET, JSON): scoped unread total + the most recent threads
     * for the dropdown. Polled globally (every page) by the bell component.
     */
    /**
     * Everyone this member could @ in this thread — for the composer's autocomplete.
     * Participants only: you cannot mention someone who is not in the conversation, so
     * offering them would be offering something that silently does nothing.
     */
    public function roster($params = []) {
        if (!$this->requireLogin()) { Flight::json([]); return; }

        $thread = Bean::load('thread', (int)$this->getParam('thread', 0));
        if (!$thread->id || !$this->canView($thread)) { Flight::json([]); return; }

        $me  = (int)$this->member->id;
        $out = [];
        foreach (\app\ThreadMembers::participants((int)$thread->id) as $pid) {
            if ($pid === $me) continue;
            $m = Bean::load('member', $pid);
            if (!$m->id) continue;
            $out[] = [
                'id'     => (int)$m->id,
                'handle' => (string)$m->username,
                'name'   => $m->displayName((string)$m->username),
            ];
        }
        Flight::json($out);
    }

    public function unreadjson() {
        if (!$this->requireLogin()) { Flight::json(['unread' => 0, 'threads' => []]); return; }

        [$where, $params] = $this->scopeClause();
        $threads = Bean::find('thread', $where . ' ORDER BY last_message_at DESC, id DESC LIMIT 8', $params);

        $out = [];
        foreach ($threads as $t) {
            $out[] = [
                'id'              => (int)$t->id,
                // Ask the thread what it is called. The raw column only answers for email:
                // it showed "(no subject)" for a DM, which is named by who is in it, and
                // dropped the team from a room, where "#general" identifies nothing.
                'subject'         => $t->title((int)$this->member->id),
                'who'             => $t->counterpart((int)$this->member->id),
                'preview'         => (string)($t->lastPreview ?? ''),
                'last_message_at' => $t->lastMessageAt,
                'last_direction'  => (string)($t->lastDirection ?? ''),
                'unread_count'    => $t->unreadFor((int)$this->member->id),
                'mentions'        => \app\Mentions::unreadInThread((int)$t->id, (int)$this->member->id),
            ];
        }

        Flight::json([
            'unread'   => $this->unreadTotal(),
            'mentions' => \app\Mentions::unreadCount((int)$this->member->id),
            'threads'  => $out,
        ]);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Display name for outbound mail: the sending member's real name
     * (first + last), falling back to username then email. The From email
     * stays the verified Mailgun sender — only the display name changes.
     */
    private function senderName(): string {
        return $this->member->displayName((string) $this->member->email);
    }

    /** Scoped, optionally-searched thread list for the sidebar rail. */
    private function fetchThreads(string $search = ''): array {
        [$where, $params] = $this->scopeClause();
        if ($search !== '') {
            $where .= ' AND (subject LIKE ? OR recipient_email LIKE ? OR recipient_name LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        return Bean::find('thread', $where . ' ORDER BY last_message_at DESC, id DESC', $params);
    }

    /** WHERE fragment scoping threads to the viewer's role. */
    private function scopeClause(): array {
        // Only ROOT sees every member's threads.
        if (Flight::hasLevel(LEVELS['ROOT'])) {
            return ['1=1', []];
        }
        // PARTICIPATION, not ownership. A DM has two people in it and a room has many;
        // owner_member_id can only ever name one of them, so scoping by it would hide a
        // conversation from everyone in it but its creator. Threads that predate
        // participants are covered by the owner clause until the backfill has run.
        $mid = (int)$this->member->id;
        return ['(id IN (SELECT thread_id FROM threadmember WHERE member_id = ?) OR owner_member_id = ?)',
                [$mid, $mid]];
    }

    /**
     * A name for a conversation that is meaningful out of context — in a browser tab, or
     * a notification. A room needs its team ("#general" is every team's); a DM has no
     * subject at all and is named by the other person.
     */
    private function threadTitle($thread): string {
        $kind = (string) $thread->kind;

        if ($kind === 'room') {
            $team = $thread->teamId ? Bean::load('team', (int) $thread->teamId) : null;
            $name = '#' . ($thread->slug ?: 'room');
            return ($team && $team->id) ? $name . ' · ' . $team->name : $name;
        }

        if ($kind === 'dm') {
            $me     = (int) $this->member->id;
            $others = array_values(array_filter(
                \app\ThreadMembers::participants((int) $thread->id), fn($id) => $id !== $me));
            return $others
                ? Bean::load('member', $others[0])->displayName('Conversation')
                : 'Conversation';
        }

        return (string) ($thread->subject ?: 'Conversation');
    }

    /**
     * The account that sent a message, or 0 when it genuinely came from none.
     *
     * "None" is correct for email — an address is not an account. It is never correct for
     * an in-app message, so that case is logged rather than defaulted into: it would
     * otherwise render as another person's message, which looks like a UI quirk and is
     * actually a corrupt row.
     */
    private function senderIdOf($m): int {
        $id = (int) ($m->senderMemberId ?? 0);
        if ($id <= 0 && (string) $m->transport === 'inapp') {
            $this->logger->error('In-app message has no sender account', [
                'message' => (int) $m->id, 'thread' => (int) $m->threadId,
            ]);
        }
        return $id;
    }

    private function canView($thread): bool {
        if (Flight::hasLevel(LEVELS['ROOT'])) return true;   // only ROOT sees others' threads
        $mid = (int)$this->member->id;
        if ((int)$thread->ownerMemberId === $mid) return true;
        return \app\ThreadMembers::isMember((int)$thread->id, $mid);
    }

    private function unreadTotal(): int {
        // Per-person now: with more than one participant, "has this been read" has no
        // single answer, and the thread-level counter cannot represent one.
        // ROOT keeps the everything-view, which is a different question.
        // Per person, always. The thread-level counter is gone: with more than one
        // participant it could not represent an answer, and keeping it as a fallback
        // meant two sources of truth for one badge.
        return \app\ThreadMembers::unreadThreadCount((int)$this->member->id);
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
