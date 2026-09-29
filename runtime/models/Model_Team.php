<?php
/**
 * Team FUSE Model — who is on a team and what they may do with it.
 *
 * These were nine methods on TaskAccessControl with the signature
 * `(int $teamId, int $memberId)`, which is the tell that they were team behaviour living
 * somewhere else. Asked of the team now: `$team->roleOf($id)`, `$team->canManage($id)`.
 *
 * Reached through the bean — RedBean returns an OODBBean and FUSE dispatches undefined
 * methods here, so no call site changes type.
 *
 * Associations remain automatic: ownTeammemberList, ownTeaminvitationList,
 * ownWorkbenchtaskList (xown* for cascade).
 */

class Model_Team extends \RedBeanPHP\SimpleModel {

    /** Roles that may administer the team, in addition to its owner. */
    private const MANAGING_ROLES = ['owner', 'admin'];

    /** Membership row for a member, or null. */
    public function membershipOf(int $memberId): ?\RedBeanPHP\OODBBean {
        $teamId = (int) $this->bean->id;
        if ($teamId <= 0 || $memberId <= 0) return null;
        $row = \app\Bean::findOne('teammember', 'team_id = ? AND member_id = ?', [$teamId, $memberId]);
        return ($row && $row->id) ? $row : null;
    }

    public function hasMember(int $memberId): bool {
        return $this->membershipOf($memberId) !== null;
    }

    /** Their role on this team, or null if they are not on it. */
    public function roleOf(int $memberId): ?string {
        $m = $this->membershipOf($memberId);
        return $m ? (string) $m->role : null;
    }

    public function isOwner(int $memberId): bool {
        return (int) $this->bean->ownerId === $memberId || $this->roleOf($memberId) === 'owner';
    }

    /** Owner or admin — the people who can change the team itself. */
    public function isAdmin(int $memberId): bool {
        return $this->isOwner($memberId)
            || in_array((string) $this->roleOf($memberId), self::MANAGING_ROLES, true);
    }

    /**
     * A per-membership capability flag (can_run_tasks, can_edit_tasks, can_delete_tasks).
     * Delegates to the membership row, which is what actually carries the flag.
     */
    public function memberCan(int $memberId, string $permission): bool {
        $m = $this->membershipOf($memberId);
        return $m ? $m->box()->can($permission) : false;
    }

    public function canManage(int $memberId): bool        { return $this->isAdmin($memberId); }
    public function canManageMembers(int $memberId): bool { return $this->isAdmin($memberId); }
    public function canInvite(int $memberId): bool        { return $this->isAdmin($memberId); }
    /** Only the owner may delete a team — an admin can manage it, not end it. */
    public function canDelete(int $memberId): bool        { return $this->isOwner($memberId); }

    /** Member ids on this team. */
    public function memberIds(): array {
        $teamId = (int) $this->bean->id;
        if ($teamId <= 0) return [];
        // array_values: find() returns id-KEYED rows, and an id-keyed array in an IN (?,?)
        // binding maps its KEYS to parameter positions. See CLAUDE.md.
        return array_values(array_unique(array_map(
            fn($r) => (int) $r->memberId,
            \app\Bean::find('teammember', 'team_id = ?', [$teamId])
        )));
    }

    // ---- rooms -----------------------------------------------------------------------
    // A room belongs to a team, so the team is what you ask for one.

    /** Room threads for this team, #general first. */
    public function rooms(): array {
        $teamId = (int) $this->bean->id;
        if ($teamId <= 0) return [];
        return array_values(\app\Bean::find('thread',
            "kind = 'room' AND team_id = ? ORDER BY (slug = ?) DESC, slug ASC",
            [$teamId, 'general']));
    }

    public function room(string $slug): ?\RedBeanPHP\OODBBean {
        $teamId = (int) $this->bean->id;
        if ($teamId <= 0) return null;
        $row = \app\Bean::findOne('thread',
            "kind = 'room' AND team_id = ? AND slug = ?", [$teamId, $slug]);
        return ($row && $row->id) ? $row : null;
    }

    /** Room names are used as #handles, so they are constrained like handles. */
    public static function slugify(string $name): string {
        $s = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name)));
        $s = trim((string) $s, '-');
        return $s !== '' ? substr($s, 0, 40) : '';
    }

    /**
     * Create a room, or return the existing one with that slug.
     *
     * Two people naming the same room is a race, not a mistake, and both wanted the same
     * outcome — so an existing slug is success, not an error.
     */
    public function createRoom(string $name, int $byMemberId = 0): ?\RedBeanPHP\OODBBean {
        $teamId = (int) $this->bean->id;
        $slug   = self::slugify($name);
        if ($teamId <= 0 || $slug === '') return null;

        $existing = $this->room($slug);
        if ($existing) { $existing->box()->syncWithTeam(); return $existing; }

        $now = date('Y-m-d H:i:s');
        $t = \app\Bean::dispense('thread');
        $t->subject        = '#' . $slug;
        $t->kind           = 'room';
        $t->teamId         = $teamId;
        $t->slug           = $slug;
        $t->createdBy      = $byMemberId;
        $t->replyToken     = bin2hex(random_bytes(16));
        $t->ownerMemberId  = (int) ($this->bean->ownerId ?: $byMemberId);
        $t->recipientEmail = '';
        $t->recipientName  = '';
        $t->messageCount   = 0;
        $t->lastDirection  = 'out';
        $t->lastPreview    = '';
        $t->lastMessageAt  = $now;
        $t->status         = 'open';
        $t->createdAt      = $now;
        $t->updatedAt      = $now;
        if (!\app\Bean::store($t)) return null;

        $t->box()->syncWithTeam();
        return $t;
    }

    /** Every team has a #general; make sure of it. */
    public function generalRoom(): ?\RedBeanPHP\OODBBean {
        return $this->room('general') ?? $this->createRoom('general');
    }

    /**
     * FUSE hook: deleting a team takes its rooms with it.
     * A room whose team is gone is a conversation nobody can reach and nobody owns.
     */
    public function delete() {
        foreach ($this->rooms() as $room) {
            \app\Bean::trash($room);     // Model_Thread::delete() cascades its messages
        }
    }
}
