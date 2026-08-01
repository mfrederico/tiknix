<?php
/**
 * Who is in a conversation, and how much of it each of them has read.
 *
 * PHASE 1 of turning Communications from an email client into a messenger. Today a thread
 * has exactly ONE owner (emailthread.owner_member_id) and its unread state is a single
 * integer on the thread row — which works precisely because there is one person to be
 * unread. A room has many, so "unread" stops being a property of the conversation and
 * becomes a property of the pair (conversation, person). That is this class.
 *
 * Deliberately additive. emailthread.unread_count keeps being written by the existing
 * paths (NotifyService, Webhook, PlanNotifier) and keeps being correct for single-owner
 * threads; nothing here breaks if a caller has not been moved over yet. The read side
 * moves first, the write side follows in Phase 2, and the column goes away only once
 * nothing reads it.
 *
 * Unread is derived from lastReadId rather than counted into a column, because a counter
 * has to be right at every write site forever and a high-water mark only has to be right
 * when someone actually reads something.
 */

namespace app;

use \app\Bean;
use \RedBeanPHP\R as R;

class ThreadMembers {

    /** Role of a participant in a thread. 'owner' is whoever the thread belongs to. */
    public const ROLE_OWNER  = 'owner';
    public const ROLE_MEMBER = 'member';

    /**
     * Put people in a thread, without disturbing anyone already in it.
     *
     * Idempotent: safe to call on every send. Returns how many rows were created, so a
     * backfill can report real work rather than claiming success for a no-op.
     */
    public static function ensure(int $threadId, array $memberIds, string $role = self::ROLE_MEMBER): int {
        if ($threadId <= 0) return 0;
        $added = 0;
        foreach (array_unique(array_map('intval', $memberIds)) as $mid) {
            if ($mid <= 0) continue;
            $row = Bean::findOne('threadmember', 'thread_id = ? AND member_id = ?', [$threadId, $mid]);
            if ($row && $row->id) continue;

            $row = Bean::dispense('threadmember');
            $row->threadId   = $threadId;
            $row->memberId   = $mid;
            $row->role       = $role;
            $row->lastReadId = 0;      // nothing read yet
            $row->muted      = 0;
            $row->joinedAt   = date('Y-m-d H:i:s');
            Bean::store($row);
            $added++;
        }
        return $added;
    }

    /** Member ids in a thread. */
    public static function participants(int $threadId): array {
        if ($threadId <= 0) return [];
        // array_values because find() returns id-KEYED rows, and an id-keyed array flowing
        // into an IN (?,?) binding maps keys to parameter POSITIONS. See CLAUDE.md.
        return array_values(array_map(
            fn($r) => (int) $r->memberId,
            Bean::find('threadmember', 'thread_id = ?', [$threadId])
        ));
    }

    /** Is this person in this thread? */
    public static function isMember(int $threadId, int $memberId): bool {
        if ($threadId <= 0 || $memberId <= 0) return false;
        $row = Bean::findOne('threadmember', 'thread_id = ? AND member_id = ?', [$threadId, $memberId]);
        return (bool) ($row && $row->id);
    }

    /**
     * How many messages in this thread this person has not seen.
     *
     * Counts messages ABOVE their high-water mark. Their own messages count as read,
     * because nobody needs telling about what they just sent — but only once
     * notify.sender_member_id is being written; until then the column is null and the
     * clause is simply never true, which is the right behaviour for email-shaped threads
     * where the "sender" is an address rather than an account.
     */
    public static function unreadFor(int $threadId, int $memberId): int {
        if ($threadId <= 0 || $memberId <= 0) return 0;
        $row = Bean::findOne('threadmember', 'thread_id = ? AND member_id = ?', [$threadId, $memberId]);
        if (!$row || !$row->id) return 0;

        return (int) R::getCell(
            'SELECT COUNT(*) FROM notify WHERE thread_id = ? AND id > ? '
          . 'AND (sender_member_id IS NULL OR sender_member_id != ?)',
            [$threadId, (int) $row->lastReadId, $memberId]
        );
    }

    /**
     * Number of threads with something unread for this person — the bell figure.
     *
     * One query rather than one per thread: the bell is polled, and a query per thread
     * is how a poll becomes a load problem the moment anyone has a lot of conversations.
     */
    public static function unreadThreadCount(int $memberId): int {
        if ($memberId <= 0) return 0;
        return (int) R::getCell(
            'SELECT COUNT(*) FROM threadmember tm WHERE tm.member_id = ? AND EXISTS ('
          . '  SELECT 1 FROM notify n WHERE n.thread_id = tm.thread_id AND n.id > tm.last_read_id'
          . '    AND (n.sender_member_id IS NULL OR n.sender_member_id != ?)'
          . ')',
            [$memberId, $memberId]
        );
    }

    /** Thread ids this person participates in, newest activity first. */
    public static function threadIdsFor(int $memberId): array {
        if ($memberId <= 0) return [];
        return array_values(array_map('intval', R::getCol(
            'SELECT tm.thread_id FROM threadmember tm '
          . 'JOIN emailthread t ON t.id = tm.thread_id '
          . 'WHERE tm.member_id = ? ORDER BY t.last_message_at DESC, t.id DESC',
            [$memberId]
        )));
    }

    /**
     * Mark everything currently in the thread as read for this person.
     *
     * Moves the high-water mark to the newest message id rather than to "now": a message
     * that arrives mid-read is then still unread, which is the honest answer.
     */
    public static function markRead(int $threadId, int $memberId): bool {
        $row = Bean::findOne('threadmember', 'thread_id = ? AND member_id = ?', [$threadId, $memberId]);
        if (!$row || !$row->id) return false;

        $newest = (int) R::getCell('SELECT MAX(id) FROM notify WHERE thread_id = ?', [$threadId]);
        $row->lastReadId = $newest;
        $row->readAt     = date('Y-m-d H:i:s');
        Bean::store($row);
        return true;
    }

    /**
     * Mark a thread unread for this person: rewind to just before the newest message, so
     * exactly one thing is waiting. Mirrors what "mark unread" means to a person.
     */
    public static function markUnread(int $threadId, int $memberId): bool {
        $row = Bean::findOne('threadmember', 'thread_id = ? AND member_id = ?', [$threadId, $memberId]);
        if (!$row || !$row->id) return false;

        $newest = (int) R::getCell('SELECT MAX(id) FROM notify WHERE thread_id = ?', [$threadId]);
        $prev   = (int) R::getCell('SELECT MAX(id) FROM notify WHERE thread_id = ? AND id < ?', [$threadId, $newest]);
        $row->lastReadId = $prev;
        Bean::store($row);
        return true;
    }

    /** Remove a person from a thread (used when a room's team membership changes). */
    public static function remove(int $threadId, int $memberId): bool {
        $row = Bean::findOne('threadmember', 'thread_id = ? AND member_id = ?', [$threadId, $memberId]);
        if (!$row || !$row->id) return false;
        Bean::trash($row);
        return true;
    }
}
