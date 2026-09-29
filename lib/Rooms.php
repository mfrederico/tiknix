<?php
/**
 * DEPRECATED — behaviour moved to Model_Team (rooms belong to a team) and Model_Thread
 * (a room IS a thread).
 *
 * Kept for callers holding ids. New code should use the beans:
 *
 *   $team->rooms()        $team->room('general')     $team->generalRoom()
 *   $team->createRoom($name, $by)
 *   $thread->syncWithTeam()   $thread->canPost($id)   $thread->post($id, $html)
 */

namespace app;

use \app\Bean;

class Rooms {

    public const GENERAL = 'general';

    public static function slugify(string $name): string {
        return \Model_Team::slugify($name);
    }

    private static function team(int $teamId): ?\Model_Team {
        if ($teamId <= 0) return null;
        $t = Bean::load('team', $teamId);
        return $t->id ? $t->box() : null;
    }

    private static function thread(int $threadId): ?\Model_Thread {
        if ($threadId <= 0) return null;
        $t = Bean::load('thread', $threadId);
        return $t->id ? $t->box() : null;
    }

    public static function forTeam(int $teamId): array {
        return self::team($teamId)?->rooms() ?? [];
    }

    public static function find(int $teamId, string $slug): ?object {
        return self::team($teamId)?->room($slug);
    }

    public static function create(int $teamId, string $name, int $byMemberId = 0): array {
        $team = self::team($teamId);
        if (!$team) return ['ok' => false, 'error' => 'No such team.'];

        $existed = $team->room(\Model_Team::slugify($name)) !== null;
        $room    = $team->createRoom($name, $byMemberId);
        if (!$room) return ['ok' => false, 'error' => 'Could not create that room.'];

        return ['ok' => true, 'thread' => (int) $room->id, 'existed' => $existed];
    }

    public static function ensureGeneral(int $teamId): ?int {
        $room = self::team($teamId)?->generalRoom();
        return $room ? (int) $room->id : null;
    }

    public static function syncMembers(int $threadId, int $teamId = 0): array {
        return self::thread($threadId)?->syncWithTeam() ?? ['added' => 0, 'removed' => 0];
    }

    /**
     * Bring every room this member should be in up to date, and make sure each of their
     * teams has a #general. Self-healing on read beats bookkeeping at every write site.
     */
    public static function syncForMember(int $memberId): void {
        $member = Bean::load('member', $memberId);
        if (!$member->id) return;

        foreach ($member->teamIds() as $teamId) {
            $team = self::team($teamId);
            if (!$team) continue;
            $team->generalRoom();
            foreach ($team->rooms() as $room) $room->box()->syncWithTeam();
        }
    }

    public static function canPost(int $threadId, int $memberId, int $level = LEVELS['MEMBER']): bool {
        return self::thread($threadId)?->canPost($memberId, $level) ?? false;
    }

    public static function post(int $threadId, int $senderMemberId, string $html): ?int {
        return self::thread($threadId)?->post($senderMemberId, $html);
    }
}
