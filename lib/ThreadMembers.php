<?php
/**
 * DEPRECATED — behaviour moved to Model_Thread.
 *
 * Kept only so callers holding a thread ID rather than a bean keep working. Every method
 * loads the thread and asks it. New code should use the bean:
 *
 *   $thread->participantIds()   $thread->isParticipant($id)   $thread->unreadFor($id)
 *   $thread->markRead($id)      $thread->markUnread($id)      $thread->addParticipants([…])
 */

namespace app;

use \app\Bean;

class ThreadMembers {

    public const ROLE_OWNER  = \Model_Thread::ROLE_OWNER;
    public const ROLE_MEMBER = \Model_Thread::ROLE_MEMBER;

    private static function thread(int $threadId): ?\Model_Thread {
        if ($threadId <= 0) return null;
        $t = Bean::load('thread', $threadId);
        return $t->id ? $t->box() : null;
    }

    public static function ensure(int $threadId, array $memberIds, string $role = self::ROLE_MEMBER): int {
        return self::thread($threadId)?->addParticipants($memberIds, $role) ?? 0;
    }

    /**
     * Support threads follow the support team, at read time (Model_Thread::syncWithSupportTeam).
     * For an ADMIN+ caller: seat them on every support thread they are not yet on. Returns
     * how many threads gained them. Anyone else: nothing to do.
     */
    public static function syncSupportFor(int $memberId): int {
        $m = Bean::load('member', $memberId);
        if (!$m->id || (int) $m->level > LEVELS['ADMIN'] || !$m->canAuthenticate()) return 0;
        // array_values: getCol is positional already, but the IN() trap is worth not risking.
        $ids = array_values(Bean::getCol(
            "SELECT id FROM thread WHERE related_type = 'contact' "
          . 'AND id NOT IN (SELECT thread_id FROM threadmember WHERE member_id = ?)', [$memberId]));
        $n = 0;
        foreach ($ids as $t) $n += self::thread((int) $t)?->addParticipants([$memberId]) ?? 0;
        return $n;
    }

    public static function participants(int $threadId): array {
        return self::thread($threadId)?->participantIds() ?? [];
    }

    public static function isMember(int $threadId, int $memberId): bool {
        return self::thread($threadId)?->isParticipant($memberId) ?? false;
    }

    public static function unreadFor(int $threadId, int $memberId): int {
        return self::thread($threadId)?->unreadFor($memberId) ?? 0;
    }

    public static function markRead(int $threadId, int $memberId): bool {
        return self::thread($threadId)?->markRead($memberId) ?? false;
    }

    public static function markUnread(int $threadId, int $memberId): bool {
        return self::thread($threadId)?->markUnread($memberId) ?? false;
    }

    public static function remove(int $threadId, int $memberId): bool {
        return self::thread($threadId)?->removeParticipant($memberId) ?? false;
    }

    /**
     * Threads with something unread for this person — the bell figure.
     * One query, not one per thread: the bell is polled.
     */
    public static function unreadThreadCount(int $memberId): int {
        if ($memberId <= 0) return 0;
        return (int) Bean::getCell(
            'SELECT COUNT(*) FROM threadmember tm WHERE tm.member_id = ? AND EXISTS ('
          . '  SELECT 1 FROM message m WHERE m.thread_id = tm.thread_id AND m.id > tm.last_read_id'
          . '    AND (m.sender_member_id IS NULL OR m.sender_member_id != ?)'
          . ')',
            [$memberId, $memberId]
        );
    }

    public static function threadIdsFor(int $memberId): array {
        if ($memberId <= 0) return [];
        return array_values(array_map('intval', Bean::getCol(
            'SELECT tm.thread_id FROM threadmember tm JOIN thread t ON t.id = tm.thread_id '
          . 'WHERE tm.member_id = ? ORDER BY t.last_message_at DESC, t.id DESC',
            [$memberId]
        )));
    }
}
