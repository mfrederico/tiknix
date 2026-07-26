<?php
/**
 * ProjectContext — the project a member is currently working on, across the whole
 * surface: core, the workbench sidecar, the pipeline editor, the store.
 *
 * WHY IT IS NOT A SESSION. Each surface is a separate app on its own host with its own
 * session, so a session-scoped selection is invisible to the others — which is exactly
 * the flip/flop: pick an instance in AI Projects, move to AI Builder, and it has no idea
 * what you were doing. The selection therefore lives on the MEMBER ROW in core's
 * database, which every surface can already read (sidecars run on core_root and share
 * its classes and DB). One value, one source of truth, survives logout and host hops.
 *
 * AFFINITY, NOT A FILTER. Once a project is selected it is the ONLY project in play
 * until the member goes back to /projects and picks another. Nothing else should offer
 * an instance switcher — that duplication is what makes the current UI feel like it is
 * arguing with itself.
 *
 * Access is re-checked on every read: a project can be shared, unshared or deleted
 * between requests, and a stale id must fall back to "no project" rather than silently
 * granting access to something the member no longer holds.
 */
namespace app;

use RedBeanPHP\R;

class ProjectContext {

    /** The member's currently selected project, or null if none / no longer accessible. */
    public static function current(int $memberId): ?object {
        if ($memberId <= 0) return null;
        $member = R::load('member', $memberId);
        $id     = (int) ($member->activeInstanceId ?? 0);
        if ($id <= 0) return null;

        $inst = R::load('instance', $id);
        if (!$inst->id || !self::canAccess($memberId, $inst)) {
            // Selection has gone stale (unshared, deleted, archived). Forget it rather
            // than leaving a dangling id that every surface has to defend against.
            self::clear($memberId);
            return null;
        }
        return $inst;
    }

    /** Select a project. Returns false if the member cannot access it. */
    public static function set(int $memberId, int $instanceId): bool {
        $inst = R::load('instance', $instanceId);
        if (!$inst->id || !self::canAccess($memberId, $inst)) return false;

        $member = R::load('member', $memberId);
        if (!$member->id) return false;
        $member->activeInstanceId = (int) $inst->id;
        R::store($member);
        return true;
    }

    public static function clear(int $memberId): void {
        $member = R::load('member', $memberId);
        if (!$member->id) return;
        $member->activeInstanceId = null;
        R::store($member);
    }

    /** Owner, or a member of a team the instance is shared with. */
    public static function canAccess(int $memberId, object $inst): bool {
        if (!$inst->id) return false;
        if ((int) $inst->memberId === $memberId) return true;
        return in_array((int) $inst->id, self::sharedInstanceIds($memberId), true);
    }

    /**
     * Every project the member may work on, newest-owned first — the source list for
     * the picker.
     * @return object[]
     */
    public static function accessible(int $memberId): array {
        $own = R::find('instance', 'member_id = ? AND status != ?', [$memberId, 'deleted']);

        $shared = [];
        $ids    = self::sharedInstanceIds($memberId);
        if ($ids) {
            $shared = R::find('instance',
                'id IN (' . R::genSlots($ids) . ') AND member_id != ? AND status != ?',
                array_merge($ids, [$memberId, 'deleted']));
        }

        // array_values because find() returns beans keyed by id — merging keyed arrays
        // would silently drop rows whose ids collide across the two result sets.
        return array_merge(array_values($own), array_values($shared));
    }

    /**
     * Instance ids shared with this member through team membership.
     *
     * array_values() is load-bearing: find() returns id-KEYED arrays and array_map over
     * one preserves those keys, so passing the result straight into an IN(?,?) binding
     * makes RedBean map integer KEYS to positional parameters — "column index out of
     * range" rather than anything that reads like the real problem.
     *
     * @return int[]
     */
    private static function sharedInstanceIds(int $memberId): array {
        $teamIds = array_values(array_map(
            fn($tm) => (int) $tm->teamId,
            R::find('teammember', 'member_id = ?', [$memberId])
        ));
        if (!$teamIds) return [];

        return array_values(array_map(
            fn($it) => (int) $it->instanceId,
            R::find('instance_team', 'team_id IN (' . R::genSlots($teamIds) . ')', $teamIds)
        ));
    }
}
