<?php
/**
 * Thread FUSE Model — one conversation, of any kind.
 *
 * kind = 'email' | 'dm' | 'room'. Transport is a property of a MESSAGE, not of the
 * conversation, which is what lets one inbox hold all three.
 *
 * message + messageattachment link via the plain `thread_id` column (an aliased relation
 * set by NotifyService), so they are NOT reachable through a conventional ownMessageList.
 * Cascade delete is handled in the delete() hook below, using bean operations rather than
 * raw SQL, so child model hooks still fire.
 *
 * Participants and per-person unread were lib/ThreadMembers; the room half was lib/Rooms.
 * Both took `(int $threadId, …)` as their first argument, which is the tell for thread
 * behaviour living outside the thread.
 */

class Model_Thread extends \RedBeanPHP\SimpleModel {

    public const ROLE_OWNER  = 'owner';
    public const ROLE_MEMBER = 'member';

    // ---- identity --------------------------------------------------------------------

    public function isRoom(): bool  { return (string) $this->bean->kind === 'room'; }
    public function isDm(): bool    { return (string) $this->bean->kind === 'dm'; }
    public function isEmail(): bool { return !$this->isRoom() && !$this->isDm(); }

    /** The team a room belongs to, or null. */
    public function team(): ?\RedBeanPHP\OODBBean {
        if (!$this->bean->teamId) return null;
        $t = \app\Bean::load('team', (int) $this->bean->teamId);
        return $t->id ? $t : null;
    }

    /**
     * A name that means something out of context — in a tab title, or a notification.
     *
     * This existed in three places: Communications::threadTitle and near-identical logic
     * in two views. A room needs its team ("#general" is every team's) and a DM has no
     * subject at all, because it is named by who is in it.
     */
    public function title(int $viewerId = 0): string {
        if ($this->isRoom()) {
            $name = '#' . ($this->bean->slug ?: 'room');
            $team = $this->team();
            return $team ? $name . ' · ' . $team->name : $name;
        }
        if ($this->isDm()) {
            $other = $this->otherParticipant($viewerId);
            return $other ? $other->displayName('Conversation') : 'Conversation';
        }
        return (string) ($this->bean->subject ?: 'Conversation');
    }

    /** Who this conversation is WITH, from a given reader's point of view. */
    public function counterpart(int $viewerId = 0): string {
        if ($this->isRoom()) {
            $team = $this->team();
            return $team ? (string) $team->name : 'Team';
        }
        if ($this->isDm()) {
            $other = $this->otherParticipant($viewerId);
            return $other ? $other->displayName('Someone') : 'Just you';
        }
        return (string) ($this->bean->recipientName ?: $this->bean->recipientEmail ?: 'Unknown');
    }

    /** The first participant who is not the viewer — the other side of a DM. */
    public function otherParticipant(int $viewerId): ?\RedBeanPHP\OODBBean {
        $others = array_values(array_filter($this->participantIds(), fn($id) => $id !== $viewerId));
        if (!$others) return null;
        $m = \app\Bean::load('member', $others[0]);
        return $m->id ? $m : null;
    }

    // ---- participants ----------------------------------------------------------------

    /** Member ids in this conversation. */
    public function participantIds(): array {
        $id = (int) $this->bean->id;
        if ($id <= 0) return [];
        // array_values: find() returns id-KEYED rows; an id-keyed array in an IN (?,?)
        // binding maps its KEYS to parameter positions. See CLAUDE.md.
        return array_values(array_map(
            fn($r) => (int) $r->memberId,
            \app\Bean::find('threadmember', 'thread_id = ?', [$id])
        ));
    }

    public function isParticipant(int $memberId): bool {
        return $this->membershipOf($memberId) !== null;
    }

    private function membershipOf(int $memberId): ?\RedBeanPHP\OODBBean {
        $id = (int) $this->bean->id;
        if ($id <= 0 || $memberId <= 0) return null;
        $row = \app\Bean::findOne('threadmember', 'thread_id = ? AND member_id = ?', [$id, $memberId]);
        return ($row && $row->id) ? $row : null;
    }

    /** Add people, without disturbing anyone already here. Returns how many were added. */
    public function addParticipants(array $memberIds, string $role = self::ROLE_MEMBER): int {
        $id = (int) $this->bean->id;
        if ($id <= 0) return 0;

        $added = 0;
        foreach (array_unique(array_map('intval', $memberIds)) as $mid) {
            if ($mid <= 0 || $this->membershipOf($mid)) continue;
            $row = \app\Bean::dispense('threadmember');
            $row->threadId   = $id;
            $row->memberId   = $mid;
            $row->role       = $role;
            $row->lastReadId = 0;
            $row->muted      = 0;
            $row->joinedAt   = date('Y-m-d H:i:s');
            \app\Bean::store($row);
            $added++;
        }
        return $added;
    }

    public function removeParticipant(int $memberId): bool {
        $row = $this->membershipOf($memberId);
        if (!$row) return false;
        \app\Bean::trash($row);
        return true;
    }

    /**
     * Make a room's participants match its team's members exactly.
     *
     * Both directions. Removing matters: leaving a team would otherwise leave you reading
     * its room, which is the sort of access nobody remembers granting.
     */
    public function syncWithTeam(): array {
        $team = $this->team();
        if (!$team) return ['added' => 0, 'removed' => 0];

        $want = $team->box()->memberIds();
        $have = $this->participantIds();

        $added   = $this->addParticipants(array_diff($want, $have));
        $removed = 0;
        foreach (array_diff($have, $want) as $gone) {
            if ($this->removeParticipant((int) $gone)) $removed++;
        }
        return ['added' => $added, 'removed' => $removed];
    }

    // ---- unread ----------------------------------------------------------------------
    // Derived from a per-person high-water mark rather than counted into a column: a
    // counter must be right at every write site forever, a mark only when someone reads.

    public function unreadFor(int $memberId): int {
        $row = $this->membershipOf($memberId);
        if (!$row) return 0;

        return (int) \app\Bean::getCell(
            'SELECT COUNT(*) FROM message WHERE thread_id = ? AND id > ? '
          . 'AND (sender_member_id IS NULL OR sender_member_id != ?)',
            [(int) $this->bean->id, (int) $row->lastReadId, $memberId]
        );
    }

    /** Mark everything currently here as read for this person. */
    public function markRead(int $memberId): bool {
        $row = $this->membershipOf($memberId);
        if (!$row) return false;

        // The newest message id, not "now" — a message arriving mid-read stays unread,
        // which is the honest answer.
        $row->lastReadId = (int) \app\Bean::getCell(
            'SELECT MAX(id) FROM message WHERE thread_id = ?', [(int) $this->bean->id]);
        $row->readAt = date('Y-m-d H:i:s');
        \app\Bean::store($row);
        return true;
    }

    /** Rewind to just before the newest message, so exactly one thing is waiting. */
    public function markUnread(int $memberId): bool {
        $row = $this->membershipOf($memberId);
        if (!$row) return false;

        $id     = (int) $this->bean->id;
        $newest = (int) \app\Bean::getCell('SELECT MAX(id) FROM message WHERE thread_id = ?', [$id]);
        $row->lastReadId = (int) \app\Bean::getCell(
            'SELECT MAX(id) FROM message WHERE thread_id = ? AND id < ?', [$id, $newest]);
        \app\Bean::store($row);
        return true;
    }

    // ---- posting ---------------------------------------------------------------------

    /**
     * May this person post here?
     *
     * For a room the answer is derived from the TEAM, freshly — a participant row is a
     * copy and can be stale, so somebody removed from a team could otherwise keep talking
     * in its room until the next time anyone opened the inbox.
     */
    public function canPost(int $memberId, int $level = 100): bool {
        if ($level <= 1) return true;                        // ROOT
        if ($this->isRoom()) $this->syncWithTeam();
        return $this->isParticipant($memberId);
    }

    /** Post an in-app message. Never touches email — see NotifyService::postInApp. */
    public function post(int $senderMemberId, string $html): ?int {
        return \app\services\NotifyService::postInApp((int) $this->bean->id, $senderMemberId, $html);
    }

    /** Messages, oldest first. */
    public function messages(): array {
        return array_values(\app\Bean::find('message',
            'thread_id = ? ORDER BY created_at ASC, id ASC', [(int) $this->bean->id]));
    }

    /**
     * FUSE hook: trashing a thread takes its messages, attachments, participants and
     * mentions with it.
     */
    public function delete() {
        $id = (int) $this->bean->id;
        if ($id <= 0) return;

        \app\Bean::trashAll(\app\Bean::find('messageattachment', 'thread_id = ?', [$id]));
        \app\Bean::trashAll(\app\Bean::find('message', 'thread_id = ?', [$id]));
        \app\Bean::trashAll(\app\Bean::find('threadmember', 'thread_id = ?', [$id]));
        \app\Bean::trashAll(\app\Bean::find('mention', 'thread_ref = ?', [$id]));
    }
}
