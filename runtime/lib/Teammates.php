<?php
/**
 * Who a person may message — a thin facade over Model_Member.
 *
 * The rules live on the member (models/Model_Member.php), because they are things a
 * person IS allowed to do rather than facts about a helper class. This stays because
 * plenty of callers hold an id rather than a bean, and loading it in one place beats
 * every call site doing it.
 */

namespace app;

use \app\Bean;

class Teammates {

    /** The member's model, or null when the id names nobody. */
    private static function model(int $memberId): ?\Model_Member {
        if ($memberId <= 0) return null;
        $bean = Bean::load('member', $memberId);
        return $bean->id ? $bean->box() : null;
    }

    /** Team ids this member belongs to. */
    public static function teamIds(int $memberId): array {
        return self::model($memberId)?->teamIds() ?? [];
    }

    /** Everyone who shares at least one team with this member, excluding themselves. */
    public static function of(int $memberId): array {
        return self::model($memberId)?->teammates() ?? [];
    }

    /**
     * May $from open a conversation with $to?
     *
     * $fromLevel is accepted for call-site compatibility but no longer consulted: the
     * member's own level is on the member, and taking it as an argument meant a caller
     * could pass a level that was not theirs.
     */
    public static function canMessage(int $from, int $to, int $fromLevel = 100): bool {
        return self::model($from)?->canMessage($to) ?? false;
    }

    /** Whether this member may send outbound EMAIL from Communications. */
    public static function canSendEmail(int $memberId, int $level): bool {
        return self::model($memberId)?->canSendEmail() ?? false;
    }
}
