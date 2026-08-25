<?php
/**
 * TaskAccessControl — Ownership & Permission Service
 *
 * TWO DIFFERENT QUESTIONS LIVE IN THIS FILE, and only one of them is still core's.
 *
 *   TEAMS (isTeamMember, getMemberTeams, getTeamRole, isTeamOwner/Admin, canManage*,
 *   canInviteToTeam, canDeleteTeam) — CORE'S, and the only implementation anywhere. Teams
 *   live in core's database. The workbench sidecar answers team questions by calling these
 *   through CoreDb::with(), because Bean:: on a sidecar's ambient connection reaches the
 *   instance's own workbench.db, which has no team table. It previously stubbed them to []
 *   and false instead, which emptied the board's team filter and made assigning a task to
 *   a team fail for everyone, members included.
 *
 *   TASKS (getVisibleTasks, getTaskCounts, canView/canEdit/canRun/canDelete/canComment,
 *   getInstanceTags, *InstanceIds) — a per-TASK model, keyed on the task row's member_id
 *   and team_id. It made sense when every task lived in one core table. Tasks are now
 *   per-project files (data/workbench.db), so the sidecar answers this question completely
 *   differently in WorkbenchAccess: the database file IS the boundary, and reaching the
 *   project is what grants access to its tasks.
 *
 * The task half is LIVE on core: mcptools/BaseTool (mayUseTask, visibleTasks) and
 * scripts/instance-oracle both use it. It is not legacy and not duplication — it is the
 * CORE-SIDE answer, and BaseTool::mayUseTask() already explains why a project cannot use
 * it: task rows carry the CONTROL PLANE's member id, so asking this class about a task
 * read from a project's own workbench.db compares an id from one database against a member
 * table in another. That is not strict, it is meaningless, and it denies every task
 * whichever way it is pointed.
 *
 * So the two classes are a BOUNDARY, not a duplication, and both are correct on their own
 * side. What was missing was anything saying so — which is why the same bug was once fixed
 * here first and changed nothing on screen, because the board never calls this class. When
 * touching task visibility, decide first WHICH DATABASE the task came from; that answers
 * which class you want.
 *
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


class TaskAccessControl {

    /**
     * Board tabs that cover more than one stored status.
     *
     * "Running" is what a person calls a task the agent is holding, whether the row says
     * running or queued — which is why the badge already counted both. The filter did not,
     * so the tab read "Running 1" and opened an empty list. Anything absent here filters on
     * itself.
     */
    public const STATUS_BUCKETS = [
        'running' => ['running', 'queued'],
    ];

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
        if (!in_array('instance_team', Bean::inspect(), true)) return false;

        return (int)Bean::getCell(
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
        return Bean::load('team', $teamId)->hasMember($memberId);
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
        return Bean::load('team', $teamId)->memberCan($memberId, $permission);
    }

    /**
     * Get member's role in a team
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return string|null Role or null if not a member
     */
    public function getTeamRole(int $teamId, int $memberId): ?string {
        return Bean::load('team', $teamId)->roleOf($memberId);
    }

    /**
     * Check if member is team owner
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function isTeamOwner(int $teamId, int $memberId): bool {
        return Bean::load('team', $teamId)->isOwner($memberId);
    }

    /**
     * Check if member is team admin (owner or admin role)
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function isTeamAdmin(int $teamId, int $memberId): bool {
        return Bean::load('team', $teamId)->isAdmin($memberId);
    }

    /**
     * Check if member can manage team (settings, invitations)
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function canManageTeam(int $teamId, int $memberId): bool {
        return Bean::load('team', $teamId)->canManage($memberId);
    }

    /**
     * Check if member can manage team members
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function canManageMembers(int $teamId, int $memberId): bool {
        return Bean::load('team', $teamId)->canManageMembers($memberId);
    }

    /**
     * Check if member can invite to team
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function canInviteToTeam(int $teamId, int $memberId): bool {
        return Bean::load('team', $teamId)->canInvite($memberId);
    }

    /**
     * Check if member can delete team
     *
     * @param int $teamId The team ID
     * @param int $memberId The member ID
     * @return bool
     */
    public function canDeleteTeam(int $teamId, int $memberId): bool {
        return Bean::load('team', $teamId)->canDelete($memberId);
    }

    /**
     * Get all team IDs for a member
     *
     * @param int $memberId The member ID
     * @return array Array of team IDs
     */
    public function getMemberTeamIds(int $memberId): array {
        // `teammember` is control-plane data, like instance_team — see registryCol().
        // Read against the project's workbench.db it returns nothing, which reads as
        // "this person is in no teams" and quietly collapses every team-based check
        // downstream.
        return $this->registryCol(
            'SELECT DISTINCT team_id FROM teammember WHERE member_id = ?',
            [$memberId],
            'teammember'
        );
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
        if (!empty($teamIds) && $this->columnExists('workbenchtask', 'instance_id')) {
            $sharedInstanceIds = $this->teamSharedInstanceIds($teamIds);
        }

        // NAME NO COLUMN THAT MAY NOT EXIST YET.
        //
        // RedBean is fluid: a column appears the first time something writes it. A project
        // whose tasks have never been assigned to a team has NO team_id column at all —
        // and "team_id IS NULL" against a missing column is an SQL error, not an empty
        // result. The whole query dies and every caller sees an empty board.
        //
        // That is not hypothetical: surgeew's first plan ingested five subtasks and then
        // NOBODY could see it, not even the member who submitted it, because its fresh
        // workbenchtask table had id/title/status/instance_id but no team_id. instance_id
        // was already guarded this way; team_id was not.
        $hasTeam = $this->columnExists('workbenchtask', 'team_id');

        $vis = [$hasTeam ? "member_id = ? AND team_id IS NULL" : "member_id = ?"];
        $params[] = $memberId;
        if ($hasTeam && !empty($teamIds)) {
            $vis[] = "team_id IN (" . implode(',', array_fill(0, count($teamIds), '?')) . ")";
            $params = array_merge($params, $teamIds);
        }
        if (!empty($sharedInstanceIds)) {
            $vis[] = "instance_id IN (" . implode(',', array_fill(0, count($sharedInstanceIds), '?')) . ")";
            $params = array_merge($params, $sharedInstanceIds);
        }
        $conditions[] = implode(' OR ', array_map(fn($c) => "($c)", $vis));

        /* Apply filters. A tab is a BUCKET, not always a single stored status: the board's
           "Running" badge counts running + queued, because from the outside both mean the
           agent has the task. Filtering on the literal string alone made the badge say 1
           and the list show nothing — the count and the filter were two different ideas of
           the same word, and only the count was right.
           Expanded here, next to the query, so the two cannot drift again. */
        if (!empty($filters['status'])) {
            $wanted = self::STATUS_BUCKETS[$filters['status']] ?? [$filters['status']];
            $conditions[] = 'status IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')';
            foreach ($wanted as $w) $params[] = $w;
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
        return Bean::load('member', $memberId)->sharedInstanceIds();
    }

    /**
     * Instance IDs the member can work in: ones they OWN plus ones shared with any
     * of their teams. This is the single source of truth for "which workspaces can
     * I see / create tasks in" (workbench tabs + the New Task instance picker).
     */
    public function getAccessibleInstanceIds(int $memberId): array {
        return Bean::load('member', $memberId)->accessibleInstanceIds();
    }

    /**
     * Instance-level access gate — the workspace analogue of canRun/canEdit for
     * tasks. True when the member OWNS the instance or it is shared with one of
     * their teams. Use for anything that operates INSIDE a workspace: creating
     * tasks, decomposing, building, polling status.
     */
    public function canAccessInstance(int $memberId, int $instanceId): bool {
        return $instanceId > 0 && Bean::load('instance', $instanceId)->accessibleBy($memberId);
    }

    /**
     * Instance ownership gate — the workspace analogue of canDelete for tasks:
     * OWNER only. Use for destructive/admin instance actions (delete, fork,
     * provision, share management, restart).
     */
    public function ownsInstance(int $memberId, int $instanceId): bool {
        return $instanceId > 0 && Bean::load('instance', $instanceId)->ownedBy($memberId);
    }

    /**
     * Workspace tabs for the workbench: one per instance the member owns OR that is
     * shared with their teams — derived from the instance table, NOT from the
     * member's own tasks, so a shared workspace shows up even when the member owns
     * no tasks in it (or it has no tasks yet). `n` counts that instance's plans.
     */
    public function getInstanceTags(int $memberId): array {
        if (!in_array('instance', Bean::inspect(), true)) return [];
        $ids = $this->getAccessibleInstanceIds($memberId);
        if (empty($ids)) return [];
        try {
            $hasIid = $this->columnExists('workbenchtask', 'instance_id');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $out = [];
            foreach (Bean::find('instance', "id IN ($ph) ORDER BY slug ASC", $ids) as $inst) {
                $tag = (string)$inst->slug . '.' . ((string)($inst->app ?: 'tiknix'));
                $n = $hasIid ? (int)Bean::getCell(
                    "SELECT COUNT(*) FROM workbenchtask WHERE instance_id = ? AND parent_task_id IS NULL",
                    [(int)$inst->id]) : 0;
                $out[$tag] = [
                    'tag'        => $tag,
                    'n'          => $n,
                    'id'         => (int)$inst->id,
                    'slug'       => (string)$inst->slug,
                    'name'       => (string)($inst->displayName ?: $inst->slug),
                    'engine'     => (string)($inst->engine ?? ''),
                    'is_default' => (bool)$inst->isDefault,
                    'owned'      => (int)$inst->memberId === $memberId,
                ];
            }
            return array_values($out);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Instances shared into any of $teamIds — READ FROM THE CONTROL PLANE.
     *
     * `instance_team` is control-plane data and exists only in core's registry. This used
     * to query it on whatever connection happened to be current and skip the lookup when
     * the table was absent:
     *
     *     if (... && in_array('instance_team', Bean::inspect(), true)) { ...lookup... }
     *
     * On the board that connection is the project's own data/workbench.db, which has no
     * such table — so the guard was always false, the clause was silently dropped, and
     * every teammate saw only their own tasks. Measured on pd, an instance shared into
     * team 2: its owner saw 9 tasks, a team admin saw 3, and another team member saw 0.
     * The guard was written to avoid an error and it removed the feature instead.
     *
     * So: use the current connection when it really is the registry, otherwise the
     * sidecar's read-only handle to core. Failing to answer is LOGGED, never returned as
     * an empty list — "nobody shared anything with you" and "I could not find out" look
     * identical on screen, and that is precisely how this hid.
     */
    private function teamSharedInstanceIds(array $teamIds): array {
        $tph = implode(',', array_fill(0, count($teamIds), '?'));
        return $this->registryCol(
            "SELECT DISTINCT instance_id FROM instance_team WHERE team_id IN ($tph)",
            $teamIds,
            'instance_team'
        );
    }

    /**
     * Run a one-column query against THE CONTROL PLANE, wherever this is running.
     *
     * Teams and instance sharing (`teammember`, `instance_team`) are control-plane data
     * and exist only in core's registry. On the board the live connection is the
     * project's own data/workbench.db, which has neither — and both lookups used to run
     * on whatever was current, returning nothing:
     *
     *   getMemberTeamIds()  -> []  ("you are in no teams")
     *   instance_team       -> []  ("nothing is shared with you")
     *
     * Two empty lists that read as facts, so every team check downstream collapsed and a
     * teammate saw only their own work. Measured on pd, shared into team 2: the owner saw
     * 9 tasks, a team admin 3, another team member 0.
     *
     * Current connection first (core, or an instance acting as its own registry), then
     * the sidecar's read-only handle to core. Never silently empty: if neither answers,
     * that is logged, because "nothing is shared with you" and "I could not find out"
     * look identical on screen and that is exactly how this hid.
     */
    private function registryCol(string $sql, array $params, string $table): array {
        try {
            if (in_array($table, Bean::inspect(), true)) {
                return array_map('intval', Bean::getCol($sql, array_values($params)));
            }
        } catch (\Throwable $e) { /* fall through to the control plane */ }

        if (class_exists('\app\Sidecar\Kernel')) {
            try {
                $pdo = \app\Sidecar\Kernel::coreDb();
                if ($pdo instanceof \PDO) {
                    $st = $pdo->prepare($sql);
                    $st->execute(array_values($params));
                    return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
                }
            } catch (\Throwable $e) { /* reported below */ }
        }

        \Flight::get('log')->warning(
            "Could not read {$table} from the control plane — team visibility will look empty",
            ['sql' => $sql]
        );
        return [];
    }

    /** True if $table has $col (fluid RedBean columns appear only once written). */
    private function columnExists(string $table, string $col): bool {
        try { return array_key_exists($col, Bean::inspect($table)); }
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
