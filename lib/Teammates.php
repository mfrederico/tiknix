<?php
/**
 * Who a person is allowed to message in-app.
 *
 * The rule, decided for Phase 2: you may message people you share a team with. Not
 * everyone with an account — an invite-only product still has strangers in it, and a
 * messenger where anyone can open a conversation with anyone is a spam surface with a
 * nicer name.
 *
 * Admins are exempt: they can already read and act on every account, so pretending they
 * cannot send someone a message would be a fence with no field behind it.
 */

namespace app;

use \app\Bean;
use \RedBeanPHP\R as R;

class Teammates {

    /** Team ids this member belongs to. */
    public static function teamIds(int $memberId): array {
        if ($memberId <= 0) return [];
        // array_values: find() returns id-KEYED rows, and an id-keyed array passed into an
        // IN (?,?) binding maps its KEYS to parameter positions. See CLAUDE.md.
        return array_values(array_unique(array_map(
            fn($r) => (int) $r->teamId,
            Bean::find('teammember', 'member_id = ?', [$memberId])
        )));
    }

    /**
     * Everyone who shares at least one team with this member, excluding themselves.
     *
     * Returns member beans, so a caller can render a name without a second query.
     */
    public static function of(int $memberId): array {
        $teamIds = self::teamIds($memberId);
        if (!$teamIds) return [];

        $in  = implode(',', array_fill(0, count($teamIds), '?'));
        $ids = array_values(array_unique(array_map('intval', R::getCol(
            "SELECT DISTINCT member_id FROM teammember WHERE team_id IN ($in)", $teamIds
        ))));
        $ids = array_values(array_filter($ids, fn($id) => $id !== $memberId));
        if (!$ids) return [];

        $in2 = implode(',', array_fill(0, count($ids), '?'));
        return array_values(Bean::find('member',
            "id IN ($in2) AND status = ? ORDER BY username", array_merge($ids, ['active'])));
    }

    /**
     * May $from open a conversation with $to?
     *
     * Admins may message anyone. Everyone else needs a shared team.
     */
    public static function canMessage(int $from, int $to, int $fromLevel = 100): bool {
        if ($from <= 0 || $to <= 0 || $from === $to) return false;
        if ($fromLevel <= 50) return true;                     // ADMIN and above

        $mine   = self::teamIds($from);
        $theirs = self::teamIds($to);
        return (bool) array_intersect($mine, $theirs);
    }

    /**
     * Whether this member may send OUTBOUND EMAIL from Communications.
     *
     * In-app messaging is the default and needs no grant. Email leaves the building under
     * the product's name and reaches people who never agreed to hear from it, so it is a
     * capability an admin hands out, exactly like invitations.
     */
    public static function canSendEmail(int $memberId, int $level): bool {
        return Feature::allows('email_out', $memberId, $level);
    }
}
