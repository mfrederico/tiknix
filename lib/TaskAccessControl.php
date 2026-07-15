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

        // Personal task: owner, or a teammate of the instance it lives on (team-shared instance)
        if (empty($task['team_id'])) {
            return (int)$task['member_id'] === $memberId
                || $this->isSharedInstanceTask($memberId, $task);
        }

        // Team task: any team member
        return $this->isTeamMember((int)$task['team_id'], $memberId);
    }

    /**
     * A personal task (team_id NULL) whose instance is shared into one of the
     * member's teams is visible/usable by that member under the "full use" model.
     * Instances are shared many-to-many via the instance_team link table.
     *
     * @param int $memberId
     * @param array $task  Task as array (from toArray)
     * @return bool
     */
    private function isSharedInstanceTask(int $memberId, array $task): bool {
        $instanceId = (int)($task['instance_id'] ?? 0);
        if ($instanceId <= 0) return false;
        if (!in_array('instance_team', R::inspect(), true)) return false;

        return (int)R::getCell(
            'SELECT COUNT(*) FROM instance_team it
               JOIN teammember tm ON tm.team_id = it.team_id
             WHERE it.instance_id = ? AND tm.member_id = ?', [$instanceId, $memberId]) > 0;
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

        // Personal task: owner, or a teammate of the instance it lives on
        if (empty($task['team_id'])) {
            return (int)$task['member_id'] === $memberId
                || $this->isSharedInstanceTask($memberId, $task);
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

        // Personal task: owner, or a teammate of the instance it lives on
        if (empty($task['team_id'])) {
            return (int)$task['member_id'] === $memberId
                || $this->isSharedInstanceTask($memberId, $task);
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
        // Bean::find keys results by bean id — array_values() drops those keys so the
        // list is sequentially keyed. Non-sequential integer keys are a footgun: passed
        // into an "IN (?,?)" binding, RedBean maps each key to a positional parameter
        // index, producing "column index out of range".
        return array_values(array_map(function($m) {
            return (int)$m->teamId;
        }, $memberships));
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

        // Instances shared (many-to-many) with the member's teams — their tasks are
        // visible too, even though the tasks themselves are personal (team_id NULL).
        $sharedInstanceIds = [];
        if (!empty($teamIds) && $this->columnExists('workbenchtask', 'instance_id')
            && in_array('instance_team', \RedBeanPHP\R::inspect(), true)) {
            $tph = implode(',', array_fill(0, count($teamIds), '?'));
            $sharedInstanceIds = array_map('intval', \RedBeanPHP\R::getCol(
                "SELECT DISTINCT instance_id FROM instance_team WHERE team_id IN ($tph)", $teamIds));
        }

        // Visibility: personal tasks OR team tasks OR tasks of a team-shared instance.
        $vis = ["member_id = ? AND team_id IS NULL"];
        $params[] = $memberId;
        if (!empty($teamIds)) {
            $vis[] = "team_id IN (" . implode(',', array_fill(0, count($teamIds), '?')) . ")";
            $params = array_merge($params, $teamIds);
        }
        if (!empty($sharedInstanceIds)) {
            $vis[] = "instance_id IN (" . implode(',', array_fill(0, count($sharedInstanceIds), '?')) . ")";
            $params = array_merge($params, $sharedInstanceIds);
        }
        $conditions[] = implode(' OR ', array_map(fn($c) => "($c)", $vis));

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

        // Tenant filter — plans/subtasks carry instance_tag (e.g. "jadams.tiknix").
        // Guarded because instance_tag is a fluid column, absent until the first
        // plan is ingested.
        if (!empty($filters['instance_tag']) && $this->columnExists('workbenchtask', 'instance_tag')) {
            $conditions[] = "instance_tag = ?";
            $params[] = $filters['instance_tag'];
        }

        $where = implode(' AND ', array_map(function($c) { return "($c)"; }, $conditions));
        $orderBy = $filters['order_by'] ?? 'created_at DESC';

        return Bean::find('workbenchtask', "$where ORDER BY $orderBy", $params);
    }

    /**
     * Distinct instance tags across the member's plans, with a plan count each,
     * for the Workbench tenant filter. Returns [] before any plan exists (the
     * fluid instance_tag column isn't there yet).
     */
    /**
     * Instance IDs shared (many-to-many) with any team the member belongs to.
     * array_values so the id-keyed getCol result is safe to splat into IN() bindings.
     */
    public function getSharedInstanceIds(int $memberId): array {
        $teamIds = $this->getMemberTeamIds($memberId);
        if (empty($teamIds) || !in_array('instance_team', \RedBeanPHP\R::inspect(), true)) return [];
        $tph = implode(',', array_fill(0, count($teamIds), '?'));
        return array_values(array_map('intval', \RedBeanPHP\R::getCol(
            "SELECT DISTINCT instance_id FROM instance_team WHERE team_id IN ($tph)", $teamIds)));
    }

    /**
     * Instance IDs the member can work in: ones they OWN plus ones shared with any
     * of their teams. This is the single source of truth for "which workspaces can
     * I see / create tasks in" (workbench tabs + the New Task instance picker).
     */
    public function getAccessibleInstanceIds(int $memberId): array {
        $ids = [];
        if (in_array('instance', \RedBeanPHP\R::inspect(), true)) {
            $ids = array_map('intval', \RedBeanPHP\R::getCol(
                'SELECT id FROM instance WHERE member_id = ?', [$memberId]));
        }
        return array_values(array_unique(array_merge($ids, $this->getSharedInstanceIds($memberId))));
    }

    /**
     * Workspace tabs for the workbench: one per instance the member owns OR that is
     * shared with their teams — derived from the instance table, NOT from the
     * member's own tasks, so a shared workspace shows up even when the member owns
     * no tasks in it (or it has no tasks yet). `n` counts that instance's plans.
     */
    public function getInstanceTags(int $memberId): array {
        if (!in_array('instance', \RedBeanPHP\R::inspect(), true)) return [];
        $ids = $this->getAccessibleInstanceIds($memberId);
        if (empty($ids)) return [];
        try {
            $hasIid = $this->columnExists('workbenchtask', 'instance_id');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $out = [];
            foreach (\RedBeanPHP\R::find('instance', "id IN ($ph) ORDER BY slug ASC", $ids) as $inst) {
                $tag = (string)$inst->slug . '.' . ((string)($inst->app ?: 'tiknix'));
                $n = $hasIid ? (int)\RedBeanPHP\R::getCell(
                    "SELECT COUNT(*) FROM workbenchtask WHERE instance_id = ? AND parent_task_id IS NULL",
                    [(int)$inst->id]) : 0;
                $out[$tag] = ['tag' => $tag, 'n' => $n];
            }
            return array_values($out);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** True if $table has $col (fluid RedBean columns appear only once written). */
    private function columnExists(string $table, string $col): bool {
        try { return array_key_exists($col, \RedBeanPHP\R::inspect($table)); }
        catch (\Throwable $e) { return false; }
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
     * Get task counts grouped by team
     *
     * @param int $memberId The member ID
     * @return array ['personal' => count, 'total' => count, team_id => count, ...]
     */
    public function getTeamTaskCounts(int $memberId): array {
        $teamIds = $this->getMemberTeamIds($memberId);

        $counts = [
            'personal' => 0,
            'total' => 0
        ];

        // Get personal task count
        $sql = "SELECT COUNT(*) as count FROM workbenchtask WHERE member_id = ? AND team_id IS NULL";
        $result = Bean::getAll($sql, [$memberId]);
        $counts['personal'] = (int)($result[0]['count'] ?? 0);
        $counts['total'] = $counts['personal'];

        // Get team task counts
        if (!empty($teamIds)) {
            $teamIds = array_values($teamIds); // Ensure sequential keys for SQL binding
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $sql = "SELECT team_id, COUNT(*) as count
                    FROM workbenchtask
                    WHERE team_id IN ($placeholders)
                    GROUP BY team_id";
            $results = Bean::getAll($sql, $teamIds);

            foreach ($results as $row) {
                $counts[$row['team_id']] = (int)$row['count'];
                $counts['total'] += (int)$row['count'];
            }
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
