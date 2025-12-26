<?php
/**
 * TaskAccessControl - Ownership & Permission Service
 *
 * Handles access control for workbench tasks and teams.
 * Enforces the ownership model:
 * - Personal tasks (team_id = null): only visible/editable by owner
 * - Team tasks (team_id set): visible/editable by team members based on role
 *
 * Team Roles:
 * - owner: Full control, can delete team
 * - admin: Can manage members, run/edit/delete tasks
 * - member: Can create, edit, run tasks (default)
 * - viewer: Read-only access to team tasks
 */

namespace app;

use \RedBeanPHP\R as R;

class TaskAccessControl {

    /**
     * Check if member can view a task
     *
     * @param int $memberId The member attempting access
     * @param object|array $task The task bean or array
     * @return bool
     */
    public function canView(int $memberId, $task): bool {
        $task = $this->toArray($task);

        // Personal task: only owner
        if (empty($task['team_id'])) {
            return (int)$task['member_id'] === $memberId;
        }

        // Team task: any team member
        return $this->isTeamMember((int)$task['team_id'], $memberId);
    }

    /**
     * Check if member can edit a task
     *
     * @param int $memberId The member attempting access
     * @param object|array $task The task bean or array
     * @return bool
     */
    public function canEdit(int $memberId, $task): bool {
        $task = $this->toArray($task);

        // Personal task: only owner
        if (empty($task['team_id'])) {
            return (int)$task['member_id'] === $memberId;
        }

        // Task owner can always edit
        if ((int)$task['member_id'] === $memberId) {
            return true;
        }

        // Check team permission
        return $this->hasTeamPermission((int)$task['team_id'], $memberId, 'can_edit_tasks');
    }

    /**
     * Check if member can run a task (trigger Claude)
     *
     * @param int $memberId The member attempting access
     * @param object|array $task The task bean or array
     * @return bool
     */
    public function canRun(int $memberId, $task): bool {
        $task = $this->toArray($task);

        // Personal task: only owner
        if (empty($task['team_id'])) {
            return (int)$task['member_id'] === $memberId;
        }

        // Task owner can always run
        if ((int)$task['member_id'] === $memberId) {
            return true;
        }

        // Check team permission
        return $this->hasTeamPermission((int)$task['team_id'], $memberId, 'can_run_tasks');
    }

    /**
     * Check if member can delete a task
     *
     * @param int $memberId The member attempting access
     * @param object|array $task The task bean or array
     * @return bool
     */
    public function canDelete(int $memberId, $task): bool {
        $task = $this->toArray($task);

        // Personal task: only owner
        if (empty($task['team_id'])) {
            return (int)$task['member_id'] === $memberId;
        }

        // Task creator can always delete their own task
        if ((int)$task['member_id'] === $memberId) {
            return true;
        }

        // Check team permission
        return $this->hasTeamPermission((int)$task['team_id'], $memberId, 'can_delete_tasks');
    }

    /**
     * Check if member can comment on a task
     *
     * @param int $memberId The member attempting access
     * @param object|array $task The task bean or array
     * @return bool
     */
    public function canComment(int $memberId, $task): bool {
        // Anyone who can view can comment
        return $this->canView($memberId, $task);
    }

    /**
     * Check if member is a member of a team
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function isTeamMember(int $teamId, int $memberId): bool {
        $membership = Bean::findOne('teammember', 'team_id = ? AND member_id = ?', [$teamId, $memberId]);
        return $membership !== null;
    }

    /**
     * Check if member has a specific team permission
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @param string $permission The permission field to check
     * @return bool
     */
    public function hasTeamPermission(int $teamId, int $memberId, string $permission): bool {
        $membership = Bean::findOne('teammember', 'team_id = ? AND member_id = ?', [$teamId, $memberId]);

        if (!$membership) {
            return false;
        }

        // Owner and admin have all permissions
        if (in_array($membership->role, ['owner', 'admin'])) {
            return true;
        }

        // Check specific permission flag
        $permField = $permission;
        return !empty($membership->$permField);
    }

    /**
     * Get member's role in a team
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return string|null Role or null if not a member
     */
    public function getTeamRole(int $teamId, int $memberId): ?string {
        $membership = Bean::findOne('teammember', 'team_id = ? AND member_id = ?', [$teamId, $memberId]);
        return $membership ? $membership->role : null;
    }

    /**
     * Check if member is team owner
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function isTeamOwner(int $teamId, int $memberId): bool {
        $team = Bean::load('team', $teamId);
        return $team && (int)$team->ownerId === $memberId;
    }

    /**
     * Check if member is team admin (owner or admin role)
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function isTeamAdmin(int $teamId, int $memberId): bool {
        // Check if owner
        if ($this->isTeamOwner($teamId, $memberId)) {
            return true;
        }

        // Check role
        $role = $this->getTeamRole($teamId, $memberId);
        return in_array($role, ['owner', 'admin']);
    }

    /**
     * Check if member can manage team (settings, invitations)
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function canManageTeam(int $teamId, int $memberId): bool {
        return $this->isTeamOwner($teamId, $memberId);
    }

    /**
     * Check if member can manage team members
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function canManageMembers(int $teamId, int $memberId): bool {
        return $this->isTeamAdmin($teamId, $memberId);
    }

    /**
     * Check if member can invite to team
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function canInviteToTeam(int $teamId, int $memberId): bool {
        return $this->isTeamAdmin($teamId, $memberId);
    }

    /**
     * Check if member can delete team
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function canDeleteTeam(int $teamId, int $memberId): bool {
        return $this->isTeamOwner($teamId, $memberId);
    }

    /**
     * Get all team IDs for a member
     *
     * @param int $memberId The member ID
     * @return array Array of team IDs
     */
    public function getMemberTeamIds(int $memberId): array {
        $memberships = Bean::find('teammember', 'member_id = ?', [$memberId]);
        return array_map(function($m) {
            return (int)$m->teamId;
        }, $memberships);
    }

    /**
     * Get all teams for a member with their roles
     *
     * @param int $memberId The member ID
     * @return array Array of team info with roles
     */
    public function getMemberTeams(int $memberId): array {
        $sql = "SELECT t.*, tm.role, tm.can_run_tasks, tm.can_edit_tasks, tm.can_delete_tasks
                FROM team t
                JOIN teammember tm ON t.id = tm.team_id
                WHERE tm.member_id = ? AND t.is_active = 1
                ORDER BY t.name ASC";
        return Bean::getAll($sql, [$memberId]);
    }

    /**
     * Get all visible tasks for a member
     *
     * @param int $memberId The member ID
     * @param array $filters Optional filters (status, type, team_id, etc.)
     * @return array Array of tasks
     */
    public function getVisibleTasks(int $memberId, array $filters = []): array {
        $teamIds = $this->getMemberTeamIds($memberId);

        // Build query for personal + team tasks
        $conditions = [];
        $params = [];

        // Personal tasks OR team tasks
        if (empty($teamIds)) {
            // No teams - only personal tasks
            $conditions[] = "(member_id = ? AND team_id IS NULL)";
            $params[] = $memberId;
        } else {
            // Personal tasks + team tasks
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $conditions[] = "(member_id = ? AND team_id IS NULL) OR (team_id IN ($placeholders))";
            $params[] = $memberId;
            $params = array_merge($params, $teamIds);
        }

        // Apply filters
        if (!empty($filters['status'])) {
            $conditions[] = "status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['task_type'])) {
            $conditions[] = "task_type = ?";
            $params[] = $filters['task_type'];
        }

        if (!empty($filters['team_id'])) {
            if ($filters['team_id'] === 'personal') {
                $conditions[] = "team_id IS NULL";
            } else {
                $conditions[] = "team_id = ?";
                $params[] = (int)$filters['team_id'];
            }
        }

        if (!empty($filters['assigned_to'])) {
            $conditions[] = "assigned_to = ?";
            $params[] = (int)$filters['assigned_to'];
        }

        if (!empty($filters['priority'])) {
            $conditions[] = "priority = ?";
            $params[] = (int)$filters['priority'];
        }

        $where = implode(' AND ', array_map(function($c) { return "($c)"; }, $conditions));
        $orderBy = $filters['order_by'] ?? 'created_at DESC';

        return Bean::find('workbenchtask', "$where ORDER BY $orderBy", $params);
    }

    /**
     * Get task counts by status for a member
     *
     * @param int $memberId The member ID
     * @return array Counts by status
     */
    public function getTaskCounts(int $memberId): array {
        $teamIds = $this->getMemberTeamIds($memberId);

        if (empty($teamIds)) {
            $sql = "SELECT status, COUNT(*) as count
                    FROM workbenchtask
                    WHERE member_id = ? AND team_id IS NULL
                    GROUP BY status";
            $results = Bean::getAll($sql, [$memberId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $sql = "SELECT status, COUNT(*) as count
                    FROM workbenchtask
                    WHERE (member_id = ? AND team_id IS NULL) OR (team_id IN ($placeholders))
                    GROUP BY status";
            $results = Bean::getAll($sql, array_merge([$memberId], $teamIds));
        }

        $counts = [
            'pending' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'paused' => 0,
            'total' => 0
        ];

        foreach ($results as $row) {
            $counts[$row['status']] = (int)$row['count'];
            $counts['total'] += (int)$row['count'];
        }

        return $counts;
    }

    /**
     * Convert bean to array if needed
     *
     * @param object|array $item Bean or array
     * @return array
     */
    private function toArray($item): array {
        if (is_object($item)) {
            // Convert bean to array with snake_case keys
            return $item->export();
        }
        return $item;
    }
}
