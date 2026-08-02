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
use app\Bean;

class ProjectContext {

    /** Where THIS browser's choice lives. See current(). */
    private const SESSION_KEY = 'active_instance_id';

    /**
     * The member's currently selected project, or null if none / no longer accessible.
     *
     * SESSION FIRST, database second. The stored column is account-wide, so every browser,
     * tab and headless process signed in as the same member shared one selection — and a
     * background run picking a project silently moved someone working in another
     * window onto it, mid-decompose. A choice made in one browser is now scoped to it.
     *
     * The column is kept as the REMEMBERED DEFAULT: a fresh session with no choice yet
     * adopts it, so coming back tomorrow still lands you where you left off. That is the
     * part worth keeping from the old behaviour; sharing a live cursor between sessions
     * was not.
     */
    public static function current(int $memberId): ?object {
        if ($memberId <= 0) return null;

        $id = (int) ($_SESSION[self::SESSION_KEY] ?? 0);
        if ($id <= 0) {
            $member = R::load('member', $memberId);
            $id     = (int) ($member->activeInstanceId ?? 0);
            if ($id > 0) $_SESSION[self::SESSION_KEY] = $id;   // adopt the remembered default
        }
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

    /**
     * Select a project: authoritative for THIS session, and remembered for the next one.
     */
    public static function set(int $memberId, int $instanceId): bool {
        $inst = R::load('instance', $instanceId);
        if (!$inst->id || !self::canAccess($memberId, $inst)) return false;

        $member = R::load('member', $memberId);
        if (!$member->id) return false;

        $_SESSION[self::SESSION_KEY] = (int) $inst->id;
        $member->activeInstanceId    = (int) $inst->id;   // the default a NEW session adopts
        R::store($member);
        return true;
    }

    public static function clear(int $memberId): void {
        unset($_SESSION[self::SESSION_KEY]);
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
    /**
     * THIRD copy of this query, now delegated. It differed from the others in one way:
     * no DISTINCT, so an instance shared with two teams the same member belongs to came
     * back twice — and a duplicate id flows straight into an IN (?,?) binding. Nothing is
     * multi-team shared yet, which is the only reason it never showed.
     */
    private static function sharedInstanceIds(int $memberId): array {
        return Bean::load('member', $memberId)->sharedInstanceIds();
    }
}
