<?php
/**
 * Teammember FUSE Model — one person's membership of one team.
 *
 * Roles:
 *   owner   full control, can delete the team
 *   admin   can manage members, run/edit/delete tasks
 *   member  can create, edit and run tasks (default)
 *   viewer  read-only
 *
 * Associations remain automatic: team, member.
 */

class Model_Teammember extends \RedBeanPHP\SimpleModel {

    /** Per-membership capability columns, by the permission name callers use. */
    private const FLAGS = [
        'run_tasks'    => 'canRunTasks',
        'edit_tasks'   => 'canEditTasks',
        'delete_tasks' => 'canDeleteTasks',
    ];

    /** Roles that carry every capability regardless of the flag columns. */
    private const FULL_ROLES = ['owner', 'admin'];

    public function role(): string {
        return (string) ($this->bean->role ?: 'member');
    }

    /**
     * May this membership do something?
     *
     * Owners and admins are not gated by the flags: the flags exist to give a plain
     * member more than the default, not to take capability away from someone who
     * administers the team. An unknown permission is FALSE — a typo should refuse, not
     * silently allow.
     */
    public function can(string $permission): bool {
        if (in_array($this->role(), self::FULL_ROLES, true)) return true;
        if ($this->role() === 'viewer') return false;      // read-only means read-only

        // Accepts either the column name (can_run_tasks) or the bare capability
        // (run_tasks): callers pass the former, which is what the flag column is called.
        $key    = str_starts_with($permission, 'can_') ? substr($permission, 4) : $permission;
        $column = self::FLAGS[$key] ?? null;
        if ($column === null) return false;

        // NOT SET means NOT ALLOWED, matching what this replaced. Treating an unset flag
        // as permission would silently widen access for every membership row that has
        // never had one written — the wrong direction to be wrong in.
        return !empty($this->bean->$column);
    }
}
