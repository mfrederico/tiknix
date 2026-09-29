<?php
/**
 * WorkbenchTool — the base of the builder's TASK tools (get_task, update_task, complete_task…),
 * split out of BaseTool in RUNTIME-SPLIT-MAP.md step 2: task data, its per-project
 * workbench.db and TaskAccessControl are the builder's, not the runtime's. A task tool
 * extends this instead of BaseTool.
 */

namespace app\mcptools\workbench;

use app\Bean;
use app\mcptools\BaseTool;

abstract class WorkbenchTool extends BaseTool {

    /**
     * Point RedBean at this instance's per-instance workbench.db before touching workbench
     * task beans. Task data is owned by the AI Projects sidecar and lives in the instance's
     * data/workbench.db, so when a build agent reports progress to THIS instance's own MCP
     * the write must land there — not the instance's app db.
     *
     * INERT on the control plane (core keeps its tasks in the ambient core db) and when no
     * workbench.db exists yet. Call at the TOP of execute() in any workbench task tool.
     *
     * @return bool TRUE when this process is answering for ONE project's own workbench.db.
     */
    protected function selectWorkbenchDb(): bool {
        // REFUSE when this install cannot say who it is.
        //
        // is_control_plane() answers "unknown" as "core" so the root is never locked out
        // of its tools. Applied here that fail-safe picks a DATABASE: an install with an
        // empty or unparseable app.baseurl would read and write the CONTROL PLANE's tasks
        // while believing they were its own. Task ids are per-project, so that is not an
        // error anyone would see — it is a plausible answer about the wrong project, which
        // is exactly how the .mcp.json bug survived. Stop instead.
        $state = \control_plane_state();
        if ($state === 'unknown') {
            $msg = 'This install cannot identify itself ([app] baseurl is empty or '
                 . 'unparseable), so it cannot say whether workbench tasks belong to it or '
                 . 'to the control plane. Refusing rather than reading another install\'s tasks.';
            \Flight::get('log')->error($msg, ['baseurl' => (string) \Flight::get('app.baseurl')]);
            throw new \RuntimeException($msg);
        }
        if ($state === 'core') {
            // A scope the gateway resolved from X-Tiknix-Project (Mcp::applyProjectScope):
            // THAT project's own workbench.db, created if it is the project's first task.
            $scope = \Flight::get('mcp.project');
            if (is_array($scope) && !empty($scope['dir'])) {
                $db = rtrim((string) $scope['dir'], '/') . '/data/workbench.db';
                if (!is_dir(dirname($db)) && !@mkdir(dirname($db), 0775, true)) {
                    throw new \RuntimeException("Cannot create " . dirname($db) . " for project '{$scope['slug']}'.");
                }
                $key = 'ws:' . $scope['slug'];
                if (!Bean::hasDatabase($key)) Bean::addDatabase($key, 'sqlite:' . $db);
                Bean::selectDatabase($key);
                Bean::freeze(false);
                return true;
            }
            return false;                                       // core: ambient core db (unchanged)
        }

        $db = \app\Paths::root() . '/data/workbench.db';         // {instanceRoot}/data/workbench.db
        if (!is_file($db)) return false;                       // no sidecar-owned tasks here
        if (!Bean::hasDatabase('ws')) Bean::addDatabase('ws', 'sqlite:' . $db);
        Bean::selectDatabase('ws');
        Bean::freeze(false);
        return true;
    }

    /**
     * May this caller act on $task? Answered differently on a project than on core.
     *
     * ON CORE the question is "which of the many members' tasks is this one" — one database
     * holds every project's tasks, so ownership is the only thing separating them, and
     * TaskAccessControl decides.
     *
     * ON A PROJECT the question is already answered before it is asked. The database this
     * task came from holds THAT project's tasks and nothing else, and the API key that
     * opened the door exists only in THAT project's apikey table. Both are per-project, so
     * arriving here with the task in hand IS the authorisation.
     *
     * Asking TaskAccessControl anyway compares a member id from one database against a
     * member table in another. Task rows are stamped with the CONTROL PLANE's member id
     * (mtmoses is 25 on tiknix.com), while an agent authenticating to the project resolves
     * against the project's own members (ids 1 and 2). Those numbers mean different people,
     * so the comparison is not strict — it is meaningless, and it fails whichever way you
     * point it: core's id against the project's members denies every task, and the project's
     * agent key against a core id denies them too.
     */
    protected function mayUseTask(bool $projectScoped, $task, string $mode = 'view'): bool {
        if ($projectScoped) return true;
        $ac = new \app\TaskAccessControl();
        return $mode === 'edit'
            ? $ac->canEdit((int) $this->member->id, $task)
            : $ac->canView((int) $this->member->id, $task);
    }

    /**
     * The tasks this caller may list. On a project that is every task in its own
     * workbench.db — see mayUseTask() for why ownership cannot be asked there.
     */
    protected function visibleTasks(bool $projectScoped, array $filters): array {
        $ac = new \app\TaskAccessControl();
        if (!$projectScoped) return $ac->getVisibleTasks((int) $this->member->id, $filters);

        $where = []; $params = [];
        foreach (['status' => 'status', 'team_id' => 'team_id'] as $k => $col) {
            if (!isset($filters[$k]) || $filters[$k] === null) continue;
            $where[] = "{$col} = ?"; $params[] = $filters[$k];
        }
        $sql = ($where ? implode(' AND ', $where) . ' ' : '') . 'ORDER BY id DESC';
        return array_values(Bean::find('workbenchtask', $sql, $params));
    }

}
