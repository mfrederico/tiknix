<?php
/**
 * Team rooms — the group half of Communications.
 *
 * A room is a thread with kind='room' that belongs to a team. Its membership IS the
 * team's membership: there is nothing to join and nothing to leave, because a room you
 * can be in without being on the team, or on the team without being in, is a second
 * access-control system to keep in step with the first.
 *
 * That decision has one consequence worth stating: participant rows still have to EXIST,
 * because per-person unread lives on them (threadmember.last_read_id). So membership is
 * derived but materialised, and syncMembers() is what keeps the copy honest. It is cheap,
 * idempotent, and called on read — a room repairs itself the next time anyone looks at
 * it, rather than depending on every future caller that touches team membership
 * remembering to tell us.
 *
 * Broadcasting to a team is not a separate feature: it is posting in the team's room.
 */

namespace app;

use \app\Bean;
use \app\services\NotifyService;
use \RedBeanPHP\R as R;

class Rooms {

    /** Every team gets this one, and it cannot be removed. */
    public const GENERAL = 'general';

    /** Room names are used as #handles, so they are constrained like handles. */
    public static function slugify(string $name): string {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');
        return $s !== '' ? substr($s, 0, 40) : '';
    }

    /** Rooms belonging to a team, #general first. */
    public static function forTeam(int $teamId): array {
        if ($teamId <= 0) return [];
        return array_values(Bean::find('emailthread',
            "kind = 'room' AND team_id = ? ORDER BY (slug = ?) DESC, slug ASC",
            [$teamId, self::GENERAL]));
    }

    /** Find a team's room by slug. */
    public static function find(int $teamId, string $slug): ?object {
        $row = Bean::findOne('emailthread',
            "kind = 'room' AND team_id = ? AND slug = ?", [$teamId, $slug]);
        return ($row && $row->id) ? $row : null;
    }

    /**
     * Create a room for a team, or return the existing one with that slug.
     *
     * @return array{ok:bool, thread?:int, error?:string, existed?:bool}
     */
    public static function create(int $teamId, string $name, int $byMemberId = 0): array {
        $team = Bean::load('team', $teamId);
        if (!$team->id) return ['ok' => false, 'error' => 'No such team.'];

        $slug = self::slugify($name);
        if ($slug === '') {
            return ['ok' => false, 'error' => 'A room name needs at least one letter or number.'];
        }

        $existing = self::find($teamId, $slug);
        if ($existing) {
            // Not an error. Two people naming the same room is a race, not a mistake, and
            // both of them wanted the same outcome.
            self::syncMembers((int) $existing->id, $teamId);
            return ['ok' => true, 'thread' => (int) $existing->id, 'existed' => true];
        }

        $now = date('Y-m-d H:i:s');
        $t = Bean::dispense('emailthread');
        $t->subject        = '#' . $slug;
        $t->kind           = 'room';
        $t->teamId         = $teamId;
        $t->slug           = $slug;
        $t->createdBy      = $byMemberId;
        $t->replyToken     = bin2hex(random_bytes(16));
        $t->ownerMemberId  = (int) ($team->ownerId ?: $byMemberId);
        $t->recipientEmail = '';
        $t->recipientName  = '';
        $t->messageCount   = 0;
        $t->unreadCount    = 0;
        $t->lastDirection  = 'out';
        $t->lastPreview    = '';
        $t->lastMessageAt  = $now;
        $t->status         = 'open';
        $t->createdAt      = $now;
        $t->updatedAt      = $now;
        $id = (int) Bean::store($t);
        if (!$id) return ['ok' => false, 'error' => 'Could not create that room.'];

        self::syncMembers($id, $teamId);
        return ['ok' => true, 'thread' => $id, 'existed' => false];
    }

    /** Every team has a #general; make sure of it. */
    public static function ensureGeneral(int $teamId): ?int {
        $existing = self::find($teamId, self::GENERAL);
        if ($existing) return (int) $existing->id;

        $r = self::create($teamId, self::GENERAL);
        return !empty($r['ok']) ? (int) $r['thread'] : null;
    }

    /**
     * Make a room's participants match its team's members exactly.
     *
     * Both directions: people who joined the team are added, people who left are removed.
     * Removing matters — otherwise leaving a team leaves you reading its room, which is
     * the sort of access nobody remembers granting.
     *
     * @return array{added:int, removed:int}
     */
    public static function syncMembers(int $threadId, int $teamId): array {
        $want = array_values(array_unique(array_map('intval', R::getCol(
            'SELECT member_id FROM teammember WHERE team_id = ?', [$teamId]
        ))));
        $have = ThreadMembers::participants($threadId);

        $added = ThreadMembers::ensure($threadId, array_diff($want, $have));

        $removed = 0;
        foreach (array_diff($have, $want) as $gone) {
            if (ThreadMembers::remove($threadId, (int) $gone)) $removed++;
        }
        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Bring every room this member should be in up to date, and make sure each of their
     * teams has a #general.
     *
     * Called when the inbox is rendered. Self-healing beats bookkeeping: it means a room
     * is correct after any change to team membership, including ones made by code that
     * has never heard of rooms.
     */
    public static function syncForMember(int $memberId): void {
        foreach (Teammates::teamIds($memberId) as $teamId) {
            self::ensureGeneral($teamId);
            foreach (self::forTeam($teamId) as $room) {
                self::syncMembers((int) $room->id, $teamId);
            }
        }
    }

    /**
     * May this person post here? Room posting follows team membership, and participants
     * were just synced from it, so being a participant IS the answer.
     */
    public static function canPost(int $threadId, int $memberId, int $level = 100): bool {
        if ($level <= 1) return true;                     // ROOT
        return ThreadMembers::isMember($threadId, $memberId);
    }

    /** Post to a room. Thin wrapper so callers do not have to know it is a thread. */
    public static function post(int $threadId, int $senderMemberId, string $html): ?int {
        return NotifyService::postInApp($threadId, $senderMemberId, $html);
    }
}
