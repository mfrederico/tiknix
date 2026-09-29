<?php
/**
 * MemberProjects — which PROJECTS (instances) a member owns, shares, may use. The control
 * plane's view of a member (RUNTIME-SPLIT-MAP.md step 2: split out of Model_Member, which is
 * the runtime's and knows nothing of projects). Owned = instance.member_id; shared = through
 * a team the project is shared with (instance_team).
 */

namespace app;

class MemberProjects {

    /** Instances this member owns outright. */
    public static function ownedIds(int $memberId): array {
        $id = $memberId;
        if ($id <= 0 || !in_array('instance', \app\Bean::inspect(), true)) return [];
        return array_values(array_map('intval', \app\Bean::getCol(
            'SELECT id FROM instance WHERE member_id = ?', [$id])));
    }

    /**
     * Instances shared with a team this member is on.
     *
     * DISTINCT deliberately: an instance shared with two teams the same person belongs to
     * is one instance. TaskAccessControl had it, ProjectContext's copy did not, and they
     * agreed only because nothing is multi-team shared yet — a duplicate id would have
     * flowed straight into an IN (?,?) binding.
     */
    public static function sharedIds(int $memberId): array {
        $teamIds = \app\Bean::load('member', $memberId)->box()->teamIds();
        if (!$teamIds || !in_array('instance_team', \app\Bean::inspect(), true)) return [];

        return array_values(array_unique(array_map('intval', \app\Bean::getCol(
            'SELECT DISTINCT instance_id FROM instance_team WHERE team_id IN ('
            . \app\Bean::genSlots($teamIds) . ')', $teamIds))));
    }

    /** Everything this member may use: owned plus shared. */
    public static function accessibleIds(int $memberId): array {
        return array_values(array_unique(array_merge(
            self::ownedIds($memberId), self::sharedIds($memberId))));
    }

    /**
     * Every project this member may work on, as beans — the source list for the picker.
     *
     * Owned first, then shared, and DELETED ones excluded. That last part is why this is
     * not just a load() over accessibleIds(): the id list deliberately includes
     * everything the member may touch, while the picker must not offer a project that has
     * been destroyed.
     */
    public static function accessible(int $memberId): array {
        $id = $memberId;
        if ($id <= 0) return [];

        $own = \app\Bean::find('instance', 'member_id = ? AND status != ?', [$id, 'deleted']);

        $shared = [];
        $ids = self::sharedIds($id);
        if ($ids) {
            $shared = \app\Bean::find('instance',
                'id IN (' . \app\Bean::genSlots($ids) . ') AND member_id != ? AND status != ?',
                array_merge($ids, [$id, 'deleted']));
        }

        // array_values because find() returns beans keyed by id — merging keyed arrays
        // would silently drop rows whose ids collide across the two result sets.
        return array_merge(array_values($own), array_values($shared));
    }
}
