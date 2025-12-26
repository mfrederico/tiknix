<?php
/**
 * Workbench Controller
 *
 * Manages workbench tasks - a micro-Jira for AI-assisted development.
 * Tasks can be personal or team-based with access controls.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\TaskAccessControl;
use \Exception as Exception;
use app\BaseControls\Control;

class Workbench extends Control {

    private TaskAccessControl $access;

    public function __construct() {
        parent::__construct();
        $this->access = new TaskAccessControl();
    }

    /**
     * Task dashboard
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Workbench';

        // Get filter parameters
        $filters = [
            'status' => $this->getParam('status'),
            'task_type' => $this->getParam('type'),
            'team_id' => $this->getParam('team_id'),
            'priority' => $this->getParam('priority'),
            'order_by' => $this->getParam('order_by', 'created_at DESC')
        ];

        // Get visible tasks
        $tasks = $this->access->getVisibleTasks($this->member->id, $filters);

        // Get task counts
        $counts = $this->access->getTaskCounts($this->member->id);

        // Get user's teams for filter dropdown
        $teams = $this->access->getMemberTeams($this->member->id);

        $this->viewData['tasks'] = $tasks;
        $this->viewData['counts'] = $counts;
        $this->viewData['teams'] = $teams;
        $this->viewData['filters'] = $filters;
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();

        $this->render('workbench/index', $this->viewData);
    }

    /**
     * Create task form
     */
    public function create($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Create Task';

        // Pre-select team if specified
        $preselectedTeamId = $this->getParam('team_id');

        // Get user's teams
        $teams = $this->access->getMemberTeams($this->member->id);

        $this->viewData['teams'] = $teams;
        $this->viewData['preselectedTeamId'] = $preselectedTeamId;
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();

        $this->render('workbench/create', $this->viewData);
    }

    /**
     * Store new task
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/workbench/create');
            return;
        }

        // Validate required fields
        $title = trim($this->getParam('title', ''));
        if (empty($title)) {
            $this->flash('error', 'Task title is required');
            Flight::redirect('/workbench/create');
            return;
        }

        // Get team ID (null = personal task)
        $teamId = $this->getParam('team_id');
        if ($teamId === '' || $teamId === 'personal') {
            $teamId = null;
        } else {
            $teamId = (int)$teamId;
            // Verify membership
            if (!$this->access->isTeamMember($teamId, $this->member->id)) {
                $this->flash('error', 'You are not a member of this team');
                Flight::redirect('/workbench/create');
                return;
            }
        }

        try {
            $task = Bean::dispense('workbenchtask');
            $task->title = $title;
            $task->description = trim($this->getParam('description', ''));
            $task->taskType = $this->getParam('task_type', 'feature');
            $task->priority = (int)$this->getParam('priority', 3);
            $task->status = 'pending';
            $task->memberId = $this->member->id;
            $task->teamId = $teamId;
            $task->acceptanceCriteria = trim($this->getParam('acceptance_criteria', ''));
            $task->relatedFiles = json_encode(array_filter(explode("\n", $this->getParam('related_files', ''))));
            $task->tags = json_encode(array_filter(array_map('trim', explode(',', $this->getParam('tags', '')))));
            $task->runCount = 0;
            $task->createdAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Log task creation
            $this->logTaskEvent($task->id, 'info', 'user', 'Task created');

            $this->logger->info('Task created', [
                'task_id' => $task->id,
                'title' => $title,
                'team_id' => $teamId,
                'member_id' => $this->member->id
            ]);

            $this->flash('success', 'Task created successfully');
            Flight::redirect('/workbench/view?id=' . $task->id);

        } catch (Exception $e) {
            $this->logger->error('Failed to create task', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to create task');
            Flight::redirect('/workbench/create');
        }
    }

    /**
     * View task details
     */
    public function view($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::redirect('/workbench');
            return;
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            $this->flash('error', 'Task not found');
            Flight::redirect('/workbench');
            return;
        }

        // Check access
        if (!$this->access->canView($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench');
            return;
        }

        // Get task logs
        $logs = $task->with(' ORDER BY created_at DESC LIMIT 50 ')->ownTasklogList;

        // Get task comments
        $comments = Bean::getAll(
            "SELECT tc.*, m.display_name, m.username, m.email, m.avatar_url
             FROM taskcomment tc
             JOIN member m ON tc.member_id = m.id
             WHERE tc.task_id = ?
             ORDER BY tc.created_at ASC",
            [$taskId]
        );

        // Get latest snapshot
        $latestSnapshot = Bean::findOne('tasksnapshot', 'task_id = ? ORDER BY created_at DESC', [$taskId]);

        // Get team info if team task
        $team = null;
        if ($task->teamId) {
            $team = Bean::load('team', $task->teamId);
        }

        // Get creator info
        $creator = Bean::load('member', $task->memberId);

        $this->viewData['title'] = $task->title;
        $this->viewData['task'] = $task;
        $this->viewData['logs'] = $logs;
        $this->viewData['comments'] = $comments;
        $this->viewData['latestSnapshot'] = $latestSnapshot;
        $this->viewData['team'] = $team;
        $this->viewData['creator'] = $creator;
        $this->viewData['canEdit'] = $this->access->canEdit($this->member->id, $task);
        $this->viewData['canRun'] = $this->access->canRun($this->member->id, $task);
        $this->viewData['canDelete'] = $this->access->canDelete($this->member->id, $task);
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();

        $this->render('workbench/view', $this->viewData);
    }

    /**
     * Edit task form
     */
    public function edit($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::redirect('/workbench');
            return;
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            $this->flash('error', 'Task not found');
            Flight::redirect('/workbench');
            return;
        }

        if (!$this->access->canEdit($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench/view?id=' . $taskId);
            return;
        }

        // Get user's teams
        $teams = $this->access->getMemberTeams($this->member->id);

        $this->viewData['title'] = 'Edit Task';
        $this->viewData['task'] = $task;
        $this->viewData['teams'] = $teams;
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();

        $this->render('workbench/edit', $this->viewData);
    }

    /**
     * Update task
     */
    public function update($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::redirect('/workbench');
            return;
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            $this->flash('error', 'Task not found');
            Flight::redirect('/workbench');
            return;
        }

        if (!$this->access->canEdit($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench/view?id=' . $taskId);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/workbench/edit?id=' . $taskId);
            return;
        }

        $title = trim($this->getParam('title', ''));
        if (empty($title)) {
            $this->flash('error', 'Task title is required');
            Flight::redirect('/workbench/edit?id=' . $taskId);
            return;
        }

        try {
            $task->title = $title;
            $task->description = trim($this->getParam('description', ''));
            $task->taskType = $this->getParam('task_type', 'feature');
            $task->priority = (int)$this->getParam('priority', 3);
            $task->acceptanceCriteria = trim($this->getParam('acceptance_criteria', ''));
            $task->relatedFiles = json_encode(array_filter(explode("\n", $this->getParam('related_files', ''))));
            $task->tags = json_encode(array_filter(array_map('trim', explode(',', $this->getParam('tags', '')))));
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'user', 'Task updated');

            $this->flash('success', 'Task updated');
            Flight::redirect('/workbench/view?id=' . $taskId);

        } catch (Exception $e) {
            $this->logger->error('Failed to update task', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to update task');
            Flight::redirect('/workbench/edit?id=' . $taskId);
        }
    }

    /**
     * Delete task
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::redirect('/workbench');
            return;
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            $this->flash('error', 'Task not found');
            Flight::redirect('/workbench');
            return;
        }

        if (!$this->access->canDelete($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench/view?id=' . $taskId);
            return;
        }

        try {
            // Delete related records with cascade
            $logs = $task->xownTasklogList;
            $snapshots = $task->xownTasksnapshotList;
            $comments = $task->xownTaskcommentList;

            Bean::trash($task);

            $this->logger->info('Task deleted', ['task_id' => $taskId, 'member_id' => $this->member->id]);

            $this->flash('success', 'Task deleted');
            Flight::redirect('/workbench');

        } catch (Exception $e) {
            $this->logger->error('Failed to delete task', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to delete task');
            Flight::redirect('/workbench/view?id=' . $taskId);
        }
    }

    /**
     * Run task - start Claude runner
     */
    public function run($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::jsonError('Task ID required', 400);
            return;
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            Flight::jsonError('Task not found', 404);
            return;
        }

        if (!$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        // Check if already running
        if ($task->status === 'running') {
            Flight::jsonError('Task is already running', 400);
            return;
        }

        try {
            // Create Claude runner
            $runner = new ClaudeRunner($taskId, $this->member->id, $task->teamId);

            // Check if session already exists
            if ($runner->isRunning()) {
                Flight::jsonError('A session for this task is already active', 400);
                return;
            }

            // Spawn the runner
            $success = $runner->spawn();

            if (!$success) {
                Flight::jsonError('Failed to start Claude runner', 500);
                return;
            }

            // Update task status
            $task->status = 'queued';
            $task->tmuxSession = $runner->getSessionName();
            $task->currentRunId = bin2hex(random_bytes(16));
            $task->runCount = ($task->runCount ?? 0) + 1;
            $task->lastRunnerMemberId = $this->member->id;
            $task->startedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'system', 'Claude runner started by ' . ($this->member->displayName ?? $this->member->email));

            $this->logger->info('Claude runner started', [
                'task_id' => $taskId,
                'session' => $runner->getSessionName(),
                'member_id' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'message' => 'Claude runner started',
                'session' => $runner->getSessionName()
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to start Claude runner', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to start runner: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Pause running task
     */
    public function pause($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        if ($task->status !== 'running') {
            Flight::jsonError('Task is not running', 400);
            return;
        }

        $task->status = 'paused';
        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);

        $this->logTaskEvent($taskId, 'info', 'system', 'Task paused');

        Flight::json(['success' => true, 'message' => 'Task paused']);
    }

    /**
     * Resume paused task
     */
    public function resume($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        if ($task->status !== 'paused') {
            Flight::jsonError('Task is not paused', 400);
            return;
        }

        $task->status = 'running';
        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);

        $this->logTaskEvent($taskId, 'info', 'system', 'Task resumed');

        Flight::json(['success' => true, 'message' => 'Task resumed']);
    }

    /**
     * Stop running task
     */
    public function stop($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        if (!in_array($task->status, ['running', 'queued', 'paused'])) {
            Flight::jsonError('Task is not active', 400);
            return;
        }

        try {
            // Kill tmux session if exists
            if ($task->tmuxSession) {
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId);
                $runner->kill();
            }

            $task->status = 'pending';
            $task->tmuxSession = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'warning', 'system', 'Task stopped by user');

            Flight::json(['success' => true, 'message' => 'Task stopped']);

        } catch (Exception $e) {
            Flight::jsonError('Failed to stop task: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get task progress (AJAX polling)
     */
    public function progress($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canView($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $progress = [
            'status' => $task->status,
            'run_count' => $task->runCount,
            'started_at' => $task->startedAt,
            'completed_at' => $task->completedAt,
            'branch_name' => $task->branchName,
            'pr_url' => $task->prUrl,
            'error_message' => $task->errorMessage
        ];

        // If running, get live progress from tmux
        if (in_array($task->status, ['running', 'queued']) && $task->tmuxSession) {
            try {
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId);
                if ($runner->isRunning()) {
                    $progress['live'] = $runner->getProgress();
                } else {
                    // Session ended - check if completed or failed
                    $progress['session_ended'] = true;
                }
            } catch (Exception $e) {
                $progress['runner_error'] = $e->getMessage();
            }
        }

        // Get latest snapshot
        $snapshot = Bean::findOne('tasksnapshot', 'task_id = ? ORDER BY created_at DESC', [$taskId]);
        if ($snapshot) {
            $progress['snapshot'] = [
                'type' => $snapshot->snapshotType,
                'content' => $snapshot->content,
                'timestamp' => $snapshot->createdAt
            ];
        }

        // Get recent logs
        $logs = Bean::find('tasklog', 'task_id = ? ORDER BY created_at DESC LIMIT 10', [$taskId]);
        $progress['recent_logs'] = array_map(function($log) {
            return [
                'level' => $log->logLevel,
                'type' => $log->logType,
                'message' => $log->message,
                'timestamp' => $log->createdAt
            ];
        }, $logs);

        Flight::json($progress);
    }

    /**
     * View full task output
     */
    public function output($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canView($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench');
            return;
        }

        $this->viewData['title'] = 'Task Output - ' . $task->title;
        $this->viewData['task'] = $task;
        $this->viewData['output'] = $task->lastOutput;

        $this->render('workbench/output', $this->viewData);
    }

    /**
     * Add comment to task
     */
    public function comment($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canComment($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $content = trim($this->getParam('content', ''));
        if (empty($content)) {
            Flight::jsonError('Comment content required', 400);
            return;
        }

        try {
            $comment = Bean::dispense('taskcomment');
            $comment->taskId = $taskId;
            $comment->memberId = $this->member->id;
            $comment->content = $content;
            $comment->isInternal = (int)$this->getParam('is_internal', 0);
            $comment->createdAt = date('Y-m-d H:i:s');
            Bean::store($comment);

            Flight::json([
                'success' => true,
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'author' => $this->member->displayName ?? $this->member->email,
                    'avatar_url' => $this->member->avatarUrl,
                    'created_at' => $comment->createdAt
                ]
            ]);

        } catch (Exception $e) {
            Flight::jsonError('Failed to add comment', 500);
        }
    }

    /**
     * View task logs
     */
    public function logs($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canView($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench');
            return;
        }

        $level = $this->getParam('level');
        $type = $this->getParam('type');

        $sql = 'task_id = ?';
        $params = [$taskId];

        if ($level) {
            $sql .= ' AND log_level = ?';
            $params[] = $level;
        }

        if ($type) {
            $sql .= ' AND log_type = ?';
            $params[] = $type;
        }

        $sql .= ' ORDER BY created_at DESC';

        $logs = Bean::find('tasklog', $sql, $params);

        $this->viewData['title'] = 'Task Logs - ' . $task->title;
        $this->viewData['task'] = $task;
        $this->viewData['logs'] = $logs;
        $this->viewData['filterLevel'] = $level;
        $this->viewData['filterType'] = $type;

        $this->render('workbench/logs', $this->viewData);
    }

    /**
     * Log a task event
     */
    private function logTaskEvent(int $taskId, string $level, string $type, string $message, array $context = []): void {
        try {
            $log = Bean::dispense('tasklog');
            $log->taskId = $taskId;
            $log->memberId = $this->member->id ?? null;
            $log->logLevel = $level;
            $log->logType = $type;
            $log->message = $message;
            $log->contextJson = !empty($context) ? json_encode($context) : null;
            $log->createdAt = date('Y-m-d H:i:s');
            Bean::store($log);
        } catch (Exception $e) {
            $this->logger->error('Failed to log task event', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get task types
     */
    private function getTaskTypes(): array {
        return [
            'feature' => ['label' => 'Feature', 'icon' => 'plus-lg', 'color' => 'primary'],
            'bugfix' => ['label' => 'Bug Fix', 'icon' => 'bug', 'color' => 'danger'],
            'refactor' => ['label' => 'Refactor', 'icon' => 'arrow-repeat', 'color' => 'info'],
            'security' => ['label' => 'Security', 'icon' => 'shield-lock', 'color' => 'warning'],
            'docs' => ['label' => 'Documentation', 'icon' => 'file-text', 'color' => 'secondary'],
            'test' => ['label' => 'Test', 'icon' => 'check2-square', 'color' => 'success']
        ];
    }

    /**
     * Get priority levels
     */
    private function getPriorities(): array {
        return [
            1 => ['label' => 'Critical', 'color' => 'danger'],
            2 => ['label' => 'High', 'color' => 'warning'],
            3 => ['label' => 'Medium', 'color' => 'info'],
            4 => ['label' => 'Low', 'color' => 'secondary']
        ];
    }
}
