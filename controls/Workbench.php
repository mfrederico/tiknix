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
use \app\SimpleCsrf;
use \app\ClaudeRunner;
use \app\PromptBuilder;
use \app\GitService;
use \app\PortManager;
use \app\TmuxManager;
use \app\WorkspaceManager;
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
            'order_by' => $this->getParam('order_by', 'updated_at DESC')
        ];

        // Get visible tasks
        $tasks = $this->access->getVisibleTasks($this->member->id, $filters);

        // Get task counts
        $counts = $this->access->getTaskCounts($this->member->id);

        // Get user's teams for filter dropdown
        $teams = $this->access->getMemberTeams($this->member->id);

        // Get task counts per team for tab badges
        $teamCounts = $this->access->getTeamTaskCounts($this->member->id);

        $this->viewData['tasks'] = $tasks;
        $this->viewData['counts'] = $counts;
        $this->viewData['teams'] = $teams;
        $this->viewData['teamCounts'] = $teamCounts;
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

        // Get available branches from git (only remote branches - local-only won't work for cloning)
        $gitService = new GitService();
        $branchData = $gitService->getBranches();
        $currentBranch = $gitService->getCurrentBranch();

        // Use remote branches only - local-only branches can't be used as base for new workspaces
        $remoteBranches = $branchData['remote'];
        if (empty($remoteBranches)) {
            $remoteBranches = ['main']; // Fallback
        }

        $this->viewData['teams'] = $teams;
        $this->viewData['preselectedTeamId'] = $preselectedTeamId;
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();
        $this->viewData['authcontrolLevels'] = $this->getAuthcontrolLevels();
        $this->viewData['memberLevel'] = $this->member->level;
        $this->viewData['branches'] = $remoteBranches;
        $this->viewData['currentBranch'] = in_array($currentBranch, $remoteBranches) ? $currentBranch : 'main';

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

        // Validate authcontrol level (must be >= member's level)
        $authcontrolLevel = (int)$this->getParam('authcontrol_level', $this->member->level);
        if ($authcontrolLevel < $this->member->level) {
            $authcontrolLevel = $this->member->level; // Can't assign higher privilege than you have
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
            $task->authcontrolLevel = $authcontrolLevel;
            $task->acceptanceCriteria = trim($this->getParam('acceptance_criteria', ''));
            $task->relatedFiles = json_encode(array_filter(explode("\n", $this->getParam('related_files', ''))));
            $task->tags = json_encode(array_filter(array_map('trim', explode(',', $this->getParam('tags', '')))));
            $task->baseBranch = trim($this->getParam('base_branch', 'main'));
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

        // Sync tmux status to database for running tasks
        if ($task->status === 'running') {
            $workspacePath = $task->projectPath ?: Flight::get('project_root');
            $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);

            if ($runner->exists()) {
                $tmuxStatus = $runner->detectStatus();

                // Map detected status to display message
                $statusMessages = [
                    'determining' => 'Determining next action...',
                    'thinking' => 'Thinking...',
                    'processing' => 'Processing...',
                    'analyzing' => 'Analyzing code...',
                    'exploring' => 'Exploring codebase...',
                    'searching' => 'Searching...',
                    'reading' => 'Reading files...',
                    'writing' => 'Writing code...',
                    'executing' => 'Executing tools...',
                    'working' => 'Working...',
                    'waiting' => 'Waiting for user input',
                ];

                if ($tmuxStatus === 'waiting') {
                    // User's turn
                    $task->status = 'awaiting';
                    $task->progressMessage = 'Waiting for user input';
                    $task->updatedAt = date('Y-m-d H:i:s');
                    Bean::store($task);
                } elseif (isset($statusMessages[$tmuxStatus])) {
                    // Update progress message but keep status as running
                    $task->progressMessage = $statusMessages[$tmuxStatus];
                    $task->updatedAt = date('Y-m-d H:i:s');
                    Bean::store($task);
                }
            } else {
                // Session ended but status not updated - check if completed or failed
                $task->status = 'failed';
                $task->progressMessage = 'Session ended unexpectedly';
                $task->updatedAt = date('Y-m-d H:i:s');
                Bean::store($task);
            }
        }

        // Get task logs
        $logs = Bean::find('tasklog', 'task_id = ? ORDER BY created_at DESC LIMIT 50', [$taskId]);

        // Get task comments (including image_path for attached images)
        $comments = Bean::getAll(
            "SELECT tc.*, tc.image_path, m.first_name, m.last_name, m.username, m.email, m.avatar_url
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

        // Get available branches from git (only if task hasn't been run yet)
        // Only show remote branches - local-only branches can't be used as base for new workspaces
        $branches = [];
        $currentBranch = 'main';
        if (empty($task->branchName)) {
            $gitService = new GitService();
            $branchData = $gitService->getBranches();
            $branches = $branchData['remote'];
            if (empty($branches)) {
                $branches = ['main']; // Fallback
            }
            $currentBranch = $gitService->getCurrentBranch();
            if (!in_array($currentBranch, $branches)) {
                $currentBranch = 'main';
            }
        }

        $this->viewData['title'] = 'Edit Task';
        $this->viewData['task'] = $task;
        $this->viewData['teams'] = $teams;
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();
        $this->viewData['authcontrolLevels'] = $this->getAuthcontrolLevels();
        $this->viewData['memberLevel'] = $this->member->level;
        $this->viewData['branches'] = $branches;
        $this->viewData['currentBranch'] = $currentBranch;

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

        // Validate authcontrol level (must be >= member's level)
        $authcontrolLevel = (int)$this->getParam('authcontrol_level', $task->authcontrolLevel ?? $this->member->level);
        if ($authcontrolLevel < $this->member->level) {
            $authcontrolLevel = $this->member->level;
        }

        try {
            $task->title = $title;
            $task->description = trim($this->getParam('description', ''));
            $task->taskType = $this->getParam('task_type', 'feature');
            $task->priority = (int)$this->getParam('priority', 3);
            $task->authcontrolLevel = $authcontrolLevel;
            $task->acceptanceCriteria = trim($this->getParam('acceptance_criteria', ''));
            $task->relatedFiles = json_encode(array_filter(explode("\n", $this->getParam('related_files', ''))));
            $task->tags = json_encode(array_filter(array_map('trim', explode(',', $this->getParam('tags', '')))));

            // Only allow changing base branch if task hasn't been run yet
            if (empty($task->branchName)) {
                $task->baseBranch = trim($this->getParam('base_branch', $task->baseBranch ?? 'main'));
            }

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
            // Kill any running sessions
            if ($task->tmuxSession) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $runner->kill();
                }
            }
            if ($task->testServerSession) {
                TmuxManager::kill($task->testServerSession);
            }

            // Delete proxy file for nginx subdomain routing
            if (!empty($task->proxyFile) && file_exists($task->proxyFile)) {
                unlink($task->proxyFile);
            }

            // Clean up workspace directory
            if (!empty($task->projectPath) && is_dir($task->projectPath)) {
                try {
                    $wsManager = new WorkspaceManager();
                    $wsManager->destroy($task->projectPath);
                    $this->logger->info('Workspace deleted', ['path' => $task->projectPath]);
                } catch (Exception $e) {
                    $this->logger->warning('Failed to delete workspace', ['error' => $e->getMessage()]);
                }
            }

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

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
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

        // Assign port for this member
        $portInfo = PortManager::getTaskPortInfo($this->member->id);
        $assignedPort = $portInfo['port'];
        if (!$portInfo['available'] && $portInfo['fallback']) {
            $assignedPort = $portInfo['fallback'];
        }
        $task->assignedPort = $assignedPort;

        // Always create isolated workspace for tasks (safer for testing)
        $workspacePath = null;

        if (empty($task->branchName)) {
            try {
                // Determine base branch before cloning
                $baseBranch = $task->baseBranch ?: 'main';
                if (empty($task->baseBranch) && $task->teamId) {
                    $team = Bean::load('team', $task->teamId);
                    if ($team->defaultBranch) {
                        $baseBranch = $team->defaultBranch;
                    }
                }

                // Clone repository into isolated workspace (clones the base branch)
                $mainGit = new GitService();
                $workspacePath = $mainGit->cloneToWorkspace($this->member->id, $task->id, null, $baseBranch);
                $task->projectPath = $workspacePath;

                $this->logTaskEvent($taskId, 'info', 'system', "Created workspace: {$workspacePath} (from {$baseBranch})");

                // Create GitService for the workspace
                $gitService = new GitService($workspacePath);

                $branchName = GitService::generateBranchName(
                    $this->member->username ?? $this->member->email,
                    $task->id,
                    $task->title
                );

                // Create new branch from the cloned base branch
                $gitService->createBranch($branchName, $baseBranch);
                $task->branchName = $branchName;
                $task->baseBranch = $baseBranch; // Store the actual base branch used

                $this->logTaskEvent($taskId, 'info', 'system', "Created branch: {$branchName} from {$baseBranch}");

            } catch (Exception $e) {
                $this->logger->error('Failed to create workspace/branch', ['error' => $e->getMessage()]);
                Flight::jsonError('Failed to create workspace: ' . $e->getMessage(), 500);
                return;
            }
        } else if (!empty($task->projectPath)) {
            // Re-running a task - use existing workspace
            $workspacePath = $task->projectPath;
        }

        // Generate proxy hash if not exists (for subdomain routing)
        if (empty($task->proxyHash)) {
            $task->proxyHash = bin2hex(random_bytes(6)); // 12-char hex
            Bean::store($task);
            $this->logTaskEvent($taskId, 'info', 'system', "Generated proxy hash: {$task->proxyHash}");
        }

        // Initialize workspace with isolated database, config, and vendor
        if ($workspacePath && is_dir($workspacePath)) {
            try {
                $wsManager = new WorkspaceManager();
                $wsInfo = $wsManager->initialize($workspacePath, $task->proxyHash);
                $this->logTaskEvent($taskId, 'info', 'system', "Initialized workspace: {$wsInfo['baseurl']}");
            } catch (Exception $e) {
                $this->logger->warning('Workspace initialization warning', ['error' => $e->getMessage()]);
                // Continue - workspace may still work without full initialization
            }

        }

        // Always regenerate .mcp.json at run time with current config
        // This ensures correct baseurl from config.ini and fresh API key
        if ($workspacePath && is_dir($workspacePath)) {
            try {
                $apiKey = $this->getOrCreateWorkbenchApiKey($this->member->id);
                $baseUrl = Flight::get('app.baseurl') ?? 'https://dev.tiknix.com';
                $this->generateWorkspaceMcpConfig($workspacePath, $apiKey, $baseUrl);
                $this->logTaskEvent($taskId, 'info', 'system', "Generated .mcp.json with baseurl: {$baseUrl}");
            } catch (Exception $e) {
                $this->logger->warning('Failed to generate workspace MCP config', ['error' => $e->getMessage()]);
            }
        }

        try {
            // Create Claude runner with workspace path (null = use main project)
            // Pass member level for security sandbox hook
            $runner = new ClaudeRunner($taskId, $this->member->id, $task->teamId, $workspacePath, $this->member->level);

            // Check if session already exists
            if ($runner->exists()) {
                Flight::jsonError('A session for this task is already active', 400);
                return;
            }

            // Spawn Claude interactively in tmux
            $success = $runner->spawn();

            if (!$success) {
                Flight::jsonError('Failed to start Claude session', 500);
                return;
            }

            // Update task status to queued
            $task->status = 'queued';
            $task->tmuxSession = $runner->getSessionName();
            $task->currentRunId = bin2hex(random_bytes(16));
            $task->runCount = ($task->runCount ?? 0) + 1;
            $task->lastRunnerMemberId = $this->member->id;
            $task->startedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Wait for Claude to initialize (give it time to start up)
            usleep(2000000); // 2 seconds

            // Build the prompt using PromptBuilder
            $prompt = PromptBuilder::build([
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'task_type' => $task->taskType,
                'acceptance_criteria' => $task->acceptanceCriteria,
                'related_files' => json_decode($task->relatedFiles, true) ?: [],
                'tags' => json_decode($task->tags, true) ?: [],
                'authcontrol_level' => $task->authcontrolLevel,
                'branch_name' => $task->branchName,
                'assigned_port' => $task->assignedPort,
                'project_path' => $task->projectPath,
                'proxy_hash' => $task->proxyHash,
            ]);

            // Send the prompt to Claude
            $promptSent = $runner->sendPrompt($prompt);

            if (!$promptSent) {
                $this->logger->warning('Failed to send prompt to Claude session', ['task_id' => $taskId]);
            }

            // Update status to running
            $task->status = 'running';
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'system', 'Claude session started by ' . ($this->member->displayName ?? $this->member->email));

            $this->logger->info('Claude session started', [
                'task_id' => $taskId,
                'session' => $runner->getSessionName(),
                'member_id' => $this->member->id,
                'prompt_sent' => $promptSent
            ]);

            // Auto-start test server if task has branch and port
            $serverInfo = $this->autoStartTestServer($task, $this->member->id);

            $response = [
                'success' => true,
                'message' => 'Claude session started',
                'session' => $runner->getSessionName()
            ];

            if ($serverInfo) {
                $response['test_server'] = $serverInfo;
                $response['message'] .= " (test server on port {$serverInfo['port']})";
            }

            Flight::json($response);

        } catch (Exception $e) {
            $this->logger->error('Failed to start Claude session', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to start session: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Re-run a completed or failed task
     */
    public function rerun($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
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

        // Only allow re-run on completed or failed tasks
        if (!in_array($task->status, ['completed', 'failed'])) {
            Flight::jsonError('Can only re-run completed or failed tasks', 400);
            return;
        }

        // Use existing workspace path if available
        $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;

        // Kill any existing session for this task
        // Pass member level for security sandbox hook
        $runner = new ClaudeRunner($taskId, $this->member->id, $task->teamId, $workspacePath, $this->member->level);
        if ($runner->exists()) {
            $runner->kill();
            usleep(500000); // Wait 500ms
        }

        // Reset task to pending
        $task->status = 'pending';
        $task->errorMessage = null;
        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);

        $this->logTaskEvent($taskId, 'info', 'system', 'Task reset for re-run by ' . ($this->member->displayName ?? $this->member->email));

        // Regenerate .mcp.json with current config before running
        if ($workspacePath && is_dir($workspacePath)) {
            try {
                $apiKey = $this->getOrCreateWorkbenchApiKey($this->member->id);
                $baseUrl = Flight::get('app.baseurl') ?? 'https://dev.tiknix.com';
                $this->generateWorkspaceMcpConfig($workspacePath, $apiKey, $baseUrl);
                $this->logTaskEvent($taskId, 'info', 'system', "Regenerated .mcp.json for re-run");
            } catch (Exception $e) {
                $this->logger->warning('Failed to regenerate workspace MCP config', ['error' => $e->getMessage()]);
            }
        }

        // Now run the task (reuse run logic)
        try {
            // Spawn Claude interactively in tmux (runner already has workspace path)
            $success = $runner->spawn();

            if (!$success) {
                Flight::jsonError('Failed to start Claude session', 500);
                return;
            }

            // Update task status to queued
            $task->status = 'queued';
            $task->tmuxSession = $runner->getSessionName();
            $task->currentRunId = bin2hex(random_bytes(16));
            $task->runCount = ($task->runCount ?? 0) + 1;
            $task->lastRunnerMemberId = $this->member->id;
            $task->startedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Wait for Claude to initialize
            usleep(2000000); // 2 seconds

            // Build the prompt
            $prompt = PromptBuilder::build([
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'task_type' => $task->taskType,
                'acceptance_criteria' => $task->acceptanceCriteria,
                'related_files' => json_decode($task->relatedFiles, true) ?: [],
                'tags' => json_decode($task->tags, true) ?: [],
                'authcontrol_level' => $task->authcontrolLevel,
                'branch_name' => $task->branchName,
                'assigned_port' => $task->assignedPort,
                'project_path' => $task->projectPath,
                'proxy_hash' => $task->proxyHash,
            ]);

            // Send the prompt
            $runner->sendPrompt($prompt);

            // Update status to running
            $task->status = 'running';
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'system', 'Task re-run started');

            // Auto-start test server if task has branch and port
            $serverInfo = $this->autoStartTestServer($task, $this->member->id);

            $response = [
                'success' => true,
                'message' => 'Task re-run started',
                'session' => $runner->getSessionName()
            ];

            if ($serverInfo) {
                $response['test_server'] = $serverInfo;
                $response['message'] .= " (test server on port {$serverInfo['port']})";
            }

            Flight::json($response);

        } catch (Exception $e) {
            $this->logger->error('Failed to re-run task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to re-run: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Force reset a stuck queued/running task
     */
    public function forcereset($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
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

        // Only allow force reset on queued/running tasks
        if (!in_array($task->status, ['queued', 'running'])) {
            Flight::jsonError('Can only force reset queued or running tasks', 400);
            return;
        }

        try {
            // Kill any existing tmux session
            if ($task->tmuxSession) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $runner->kill();
                    usleep(500000); // Wait 500ms for cleanup
                }
            }

            // Reset task to pending
            $task->status = 'pending';
            $task->tmuxSession = null;
            $task->errorMessage = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'warning', 'system', 'Task force reset by ' . ($this->member->displayName ?? $this->member->email));

            $this->logger->info('Task force reset', [
                'task_id' => $taskId,
                'member_id' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'message' => 'Task has been reset to pending'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to force reset task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to reset: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Mark task as complete (user action)
     */
    public function complete($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
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

        try {
            // Kill any existing tmux session
            if ($task->tmuxSession) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $runner->kill();
                }
            }

            // Kill test server if running
            if ($task->testServerSession) {
                TmuxManager::kill($task->testServerSession);
            }

            // Delete proxy file for nginx subdomain routing
            if (!empty($task->proxyFile) && file_exists($task->proxyFile)) {
                unlink($task->proxyFile);
                $this->logTaskEvent($taskId, 'info', 'system', "Deleted proxy file: {$task->proxyFile}");
            }

            // Create PR if task has a branch, workspace, and no PR yet
            $prUrl = null;
            $prError = null;
            if (!empty($task->branchName) && !empty($task->projectPath) && empty($task->prUrl)) {
                $prResult = $this->createPRViaCli($task);
                $prUrl = $prResult['url'] ?? null;
                $prError = $prResult['error'] ?? null;

                if ($prUrl) {
                    $task->prUrl = $prUrl;
                }
            }

            // Mark as completed and clear session fields
            $task->status = 'completed';
            $task->completedAt = date('Y-m-d H:i:s');
            $task->tmuxSession = null;
            $task->testServerSession = null;
            $task->proxyFile = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'user', 'Task marked complete by ' . ($this->member->displayName ?? $this->member->email));

            $response = [
                'success' => true,
                'message' => 'Task completed'
            ];
            if ($prUrl) {
                $response['pr_url'] = $prUrl;
                $response['message'] = 'Task completed - PR created';
            } elseif ($prError) {
                $response['pr_error'] = $prError;
            }

            Flight::json($response);

        } catch (Exception $e) {
            $this->logger->error('Failed to complete task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to complete: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Approve task - merge PR and mark complete
     * Only admins can approve non-admin member tasks
     */
    public function approve($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id) {
            Flight::jsonError('Task not found', 404);
            return;
        }

        // Only admins can approve
        if ($this->member->level > LEVELS['ADMIN']) {
            Flight::jsonError('Only admins can approve tasks', 403);
            return;
        }

        // Task must be in awaiting or completed status
        if (!in_array($task->status, ['awaiting', 'completed'])) {
            Flight::jsonError('Task is not ready for approval', 400);
            return;
        }

        // Get options from request
        $createPr = $this->getParam('create_pr') === '1';
        $mergePr = $this->getParam('merge_pr') === '1';
        $stopSession = $this->getParam('stop_session') === '1';
        $stopServer = $this->getParam('stop_server') === '1';
        $deleteWorkspace = $this->getParam('delete_workspace') === '1';
        $notes = $this->sanitize($this->getParam('notes', ''));

        try {
            $prCreated = false;
            $prMerged = false;
            $mergeError = null;
            $workspaceDeleted = false;
            $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;

            // Create PR if requested and doesn't exist
            if ($createPr && empty($task->prUrl) && !empty($task->branchName) && $workspacePath) {
                $prResult = $this->createPRViaCli($task);
                if (!empty($prResult['url'])) {
                    $task->prUrl = $prResult['url'];
                    $prCreated = true;
                    $this->logTaskEvent($taskId, 'info', 'system', "Created PR: {$prResult['url']}");
                } elseif (!empty($prResult['error'])) {
                    $this->logTaskEvent($taskId, 'warning', 'system', "PR creation failed: {$prResult['error']}");
                }
            }

            // Merge PR if requested and exists
            if ($mergePr && !empty($task->prUrl) && !empty($task->prNumber)) {
                try {
                    $github = $this->getGitHubService($task);
                    if ($github) {
                        $mergeResult = $github->mergePullRequest(
                            (int)$task->prNumber,
                            "Merge: {$task->title}",
                            "Approved via Tiknix Workbench\n\nTask #{$task->id}",
                            'squash'
                        );
                        $prMerged = !empty($mergeResult['merged']);
                    }
                } catch (Exception $e) {
                    $mergeError = $e->getMessage();
                    $this->logger->error('Failed to merge PR', [
                        'task_id' => $taskId,
                        'pr_number' => $task->prNumber,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Stop tmux session if requested
            if ($stopSession && $task->tmuxSession) {
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $runner->kill();
                    $this->logTaskEvent($taskId, 'info', 'system', 'Stopped Claude session');
                }
                $task->tmuxSession = null;
            }

            // Stop test server if requested
            if ($stopServer && $task->testServerSession) {
                TmuxManager::kill($task->testServerSession);
                $this->logTaskEvent($taskId, 'info', 'system', 'Stopped test server');
                $task->testServerSession = null;
            }

            // Delete proxy file
            if (!empty($task->proxyFile) && file_exists($task->proxyFile)) {
                unlink($task->proxyFile);
                $task->proxyFile = null;
            }

            // Delete workspace if requested
            if ($deleteWorkspace && $workspacePath && is_dir($workspacePath)) {
                $this->recursiveDelete($workspacePath);
                $this->logTaskEvent($taskId, 'info', 'system', "Deleted workspace: {$workspacePath}");
                $task->projectPath = null;
                $workspaceDeleted = true;
            }

            // Mark task as completed
            $task->status = 'completed';
            $task->completedAt = date('Y-m-d H:i:s');
            $task->reviewedBy = $this->member->id;
            $task->reviewedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Build log message
            $message = 'Task approved by ' . ($this->member->displayName ?? $this->member->email);
            $actions = [];
            if ($prCreated) $actions[] = 'PR created';
            if ($prMerged) $actions[] = 'PR merged';
            if ($mergeError) $actions[] = "PR merge failed: {$mergeError}";
            if ($stopSession) $actions[] = 'session stopped';
            if ($stopServer) $actions[] = 'server stopped';
            if ($workspaceDeleted) $actions[] = 'workspace deleted';
            if (!empty($actions)) {
                $message .= ' (' . implode(', ', $actions) . ')';
            }
            if (!empty($notes)) {
                $message .= "\nNotes: {$notes}";
            }

            $this->logTaskEvent($taskId, 'info', 'review', $message);

            Flight::json([
                'success' => true,
                'message' => 'Task approved',
                'pr_created' => $prCreated,
                'pr_merged' => $prMerged,
                'merge_error' => $mergeError,
                'workspace_deleted' => $workspaceDeleted
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to approve task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to approve: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Decline task - close PR and send back for revision
     */
    public function decline($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id) {
            Flight::jsonError('Task not found', 404);
            return;
        }

        // Only admins can decline
        if ($this->member->level > LEVELS['ADMIN']) {
            Flight::jsonError('Only admins can decline tasks', 403);
            return;
        }

        // Task must be in awaiting or completed status
        if (!in_array($task->status, ['awaiting', 'completed'])) {
            Flight::jsonError('Task is not ready for review', 400);
            return;
        }

        $reason = trim($this->getParam('reason', ''));

        try {
            // Close PR if exists
            if (!empty($task->prUrl) && !empty($task->prNumber)) {
                try {
                    $github = $this->getGitHubService($task);
                    if ($github) {
                        // Add decline comment
                        if ($reason) {
                            $github->addComment(
                                (int)$task->prNumber,
                                "**Changes requested**\n\n{$reason}\n\n_Declined via Tiknix Workbench_"
                            );
                        }
                        // Close the PR
                        $github->closePullRequest((int)$task->prNumber);
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Failed to close PR', [
                        'task_id' => $taskId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Reset task to pending for revision
            $task->status = 'pending';
            $task->prUrl = null;
            $task->prNumber = null;
            $task->reviewedBy = $this->member->id;
            $task->reviewedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Add decline reason as comment
            if ($reason) {
                $comment = Bean::dispense('taskcomment');
                $comment->taskId = $taskId;
                $comment->memberId = $this->member->id;
                $comment->content = "**Changes Requested:**\n\n{$reason}";
                $comment->createdAt = date('Y-m-d H:i:s');
                Bean::store($comment);
            }

            $this->logTaskEvent($taskId, 'warning', 'review',
                'Task declined by ' . ($this->member->displayName ?? $this->member->email) .
                ($reason ? ": {$reason}" : '')
            );

            Flight::json([
                'success' => true,
                'message' => 'Task declined and sent back for revision'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to decline task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to decline: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get GitHub service for a task
     *
     * @param object $task Task bean
     * @return GitHubService|null
     */
    private function getGitHubService(object $task): ?GitHubService {
        if ($task->teamId) {
            $team = Bean::load('team', $task->teamId);
            $github = GitHubService::fromTeam($team);
            if ($github) {
                return $github;
            }
        }

        return GitHubService::fromConfig();
    }

    /**
     * Pause running task
     */
    public function pause($params = []) {
        if (!$this->requireLogin()) return;

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

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

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

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

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

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
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
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
     * Start test server for a task's branch
     * Creates a tmux session running server.php on the assigned port
     * Initializes workspace environment with fresh database for testing
     */
    public function startserver($params = []) {
        if (!$this->requireLogin()) return;

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        // Must have a branch and port assigned
        if (empty($task->branchName)) {
            Flight::jsonError('No branch assigned to this task', 400);
            return;
        }

        if (empty($task->assignedPort)) {
            Flight::jsonError('No port assigned to this task', 400);
            return;
        }

        // Check if test server session already exists
        if (!empty($task->testServerSession)) {
            if (TmuxManager::exists($task->testServerSession)) {
                Flight::jsonError('Test server is already running', 400);
                return;
            }
            $task->testServerSession = null;
        }

        // Check if port is available
        if (!PortManager::isPortAvailable($task->assignedPort)) {
            Flight::jsonError("Port {$task->assignedPort} is already in use", 400);
            return;
        }

        try {
            $sessionName = TmuxManager::buildServerSessionName($this->member->id, $task->id);
            $initMessages = [];

            // Use workspace path if available, otherwise default to main project
            $projectPath = !empty($task->projectPath) ? $task->projectPath : dirname(__DIR__);

            // Generate proxy hash if not exists
            if (empty($task->proxyHash)) {
                $task->proxyHash = bin2hex(random_bytes(6));
                $initMessages[] = "Generated proxy hash: {$task->proxyHash}";
            }

            // Initialize or refresh workspace environment
            if (!empty($task->projectPath) && is_dir($task->projectPath)) {
                // Pull latest changes from branch
                $pullCmd = sprintf(
                    'cd %s && git pull origin %s 2>&1',
                    escapeshellarg($task->projectPath),
                    escapeshellarg($task->branchName)
                );
                exec($pullCmd, $pullOutput, $pullCode);
                if ($pullCode === 0) {
                    $initMessages[] = "Pulled latest changes from {$task->branchName}";
                } else {
                    // Not fatal - might be a local-only branch
                    $this->logger->info('Git pull skipped (local branch)', ['output' => implode("\n", $pullOutput)]);
                }

                // Initialize workspace with fresh database and config
                $wsManager = new WorkspaceManager();
                $wsInfo = $wsManager->initialize($task->projectPath, $task->proxyHash);
                $initMessages[] = "Initialized workspace: {$wsInfo['baseurl']}";
                $initMessages[] = "Fresh database created with admin/admin1234";

                // Workspace mode - already in isolated clone on correct branch
                $serverCmd = sprintf(
                    'cd %s && php -S 0.0.0.0:%d server.php; echo "Server stopped. Press Enter to close..."; read',
                    escapeshellarg($projectPath),
                    $task->assignedPort
                );
            } else {
                // Main project mode - need to checkout branch
                $serverCmd = sprintf(
                    'cd %s && git checkout %s && php -S 0.0.0.0:%d server.php; echo "Server stopped. Press Enter to close..."; read',
                    escapeshellarg($projectPath),
                    escapeshellarg($task->branchName),
                    $task->assignedPort
                );
            }

            // Use TmuxManager to create the session
            TmuxManager::create($sessionName, $serverCmd, $projectPath);

            $task->testServerSession = $sessionName;
            $task->updatedAt = date('Y-m-d H:i:s');

            // Create .proxy file for nginx subdomain routing (if proxyHash exists)
            // File format: proxyhost=X\nproxyport=Y (lua loadEnvFile expects key=value)
            // Filename: .proxy.{hash}.{domain} (no TLD - nginx lua strips it)
            if (!empty($task->proxyHash)) {
                $baseDomain = preg_replace('#^https?://#', '', Flight::get('baseurl') ?? 'https://localhost');
                // Strip TLD (e.g., .com, .net) - nginx lua expects domain without TLD
                $baseDomain = preg_replace('/\.[a-z]{2,}$/i', '', $baseDomain);
                $proxyFile = "/var/www/html/.proxy.{$task->proxyHash}.{$baseDomain}";
                $proxyContent = "proxyhost=127.0.0.1\nproxyport={$task->assignedPort}";
                if (file_put_contents($proxyFile, $proxyContent) !== false) {
                    $task->proxyFile = $proxyFile;
                    $this->logTaskEvent($taskId, 'info', 'system', "Created proxy file: {$proxyFile}");
                } else {
                    $this->logger->warning("Failed to create proxy file: {$proxyFile}");
                }
            }

            Bean::store($task);

            // Log initialization messages
            foreach ($initMessages as $msg) {
                $this->logTaskEvent($taskId, 'info', 'system', $msg);
            }
            $this->logTaskEvent($taskId, 'info', 'system', "Test server started on port {$task->assignedPort}");

            // Build response with subdomain URL if available
            $baseDomain = preg_replace('#^https?://#', '', Flight::get('baseurl') ?? 'https://localhost');
            $testUrl = "http://localhost:{$task->assignedPort}";
            if (!empty($task->proxyHash)) {
                $testUrl = "https://{$task->proxyHash}.{$baseDomain}";
            }

            $message = "Test server started on port {$task->assignedPort}";
            if (!empty($initMessages)) {
                $message .= " (" . implode(", ", $initMessages) . ")";
            }

            Flight::json([
                'success' => true,
                'message' => $message,
                'session' => $sessionName,
                'port' => $task->assignedPort,
                'url' => $testUrl,
                'subdomain' => !empty($task->proxyHash) ? "{$task->proxyHash}.{$baseDomain}" : null,
                'init_details' => $initMessages
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to start test server', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to start test server: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Stop test server for a task
     *
     */
    public function stopserver($params = []) {
        if (!$this->requireLogin()) return;

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        if (empty($task->testServerSession)) {
            Flight::jsonError('No test server is running', 400);
            return;
        }

        try {
            TmuxManager::kill($task->testServerSession);

            // Delete .proxy file for nginx subdomain routing
            if (!empty($task->proxyFile) && file_exists($task->proxyFile)) {
                if (unlink($task->proxyFile)) {
                    $this->logTaskEvent($taskId, 'info', 'system', "Deleted proxy file: {$task->proxyFile}");
                } else {
                    $this->logger->warning("Failed to delete proxy file: {$task->proxyFile}");
                }
            }

            $task->testServerSession = null;
            $task->proxyFile = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'system', 'Test server stopped');

            Flight::json([
                'success' => true,
                'message' => 'Test server stopped'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to stop test server', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to stop test server: ' . $e->getMessage(), 500);
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
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
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

        // Get recent comments for live updates
        $comments = Bean::getAll(
            "SELECT tc.id, tc.content, tc.image_path, tc.is_from_claude, tc.created_at,
                    m.first_name, m.last_name, m.username
             FROM taskcomment tc
             JOIN member m ON tc.member_id = m.id
             WHERE tc.task_id = ?
             ORDER BY tc.created_at ASC",
            [$taskId]
        );
        $progress['comments'] = array_map(function($c) {
            $author = $c['is_from_claude'] ? 'Claude' :
                      (trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?:
                      ($c['username'] ?? 'Unknown'));
            return [
                'id' => $c['id'],
                'author' => $author,
                'is_from_claude' => (bool)$c['is_from_claude'],
                'content' => $c['content'],
                'image_path' => $c['image_path'] ?? null,
                'created_at' => $c['created_at']
            ];
        }, $comments);

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

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
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

            $sentToSession = false;

            // If not an internal comment, try to send to Claude session if it exists
            if (!$comment->isInternal) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    // Append reminder about tiknix MCP tools
                    $messageWithReminder = $content . "\n\n[REMINDER: Use the tiknix MCP tools to update the project status]";
                    $sentToSession = $runner->sendPrompt($messageWithReminder);
                    if ($sentToSession) {
                        $this->logTaskEvent($taskId, 'info', 'user', 'Message sent to Claude: ' . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''));

                        // If task was awaiting/completed/failed but session is still active, mark as running
                        if (in_array($task->status, ['awaiting', 'completed', 'failed'])) {
                            $task->status = 'running';
                            $task->updatedAt = date('Y-m-d H:i:s');
                            Bean::store($task);
                        }
                    }
                }
            }

            Flight::json([
                'success' => true,
                'sent_to_session' => $sentToSession,
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
     * Upload an image to a task comment
     * Supports both standalone image uploads and image+text comments
     */
    public function uploadimage($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canComment($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        // Check for uploaded file
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds max upload size',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form max size',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
            ];
            $error = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
            Flight::jsonError($errorMessages[$error] ?? 'File upload failed', 400);
            return;
        }

        $file = $_FILES['image'];

        // Validate file type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        $allowedTypes = [
            'image/png' => 'png',
            'image/jpeg' => 'jpeg',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];

        if (!isset($allowedTypes[$mimeType])) {
            Flight::jsonError('Invalid image type. Allowed: PNG, JPEG, GIF, WEBP', 400);
            return;
        }

        // Validate file size (max 10MB)
        $maxSize = 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            Flight::jsonError('Image too large. Max size: 10MB', 400);
            return;
        }

        try {
            // Public path is where index.php lives (DOCUMENT_ROOT from nginx)
            $publicRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__) . '/public', '/');

            // Create upload directory
            $uploadsDir = $publicRoot . '/uploads/workbench/' . $taskId;
            if (!is_dir($uploadsDir)) {
                if (!mkdir($uploadsDir, 0755, true)) {
                    throw new Exception("Failed to create uploads directory");
                }
            }

            // Generate unique filename
            $extension = $allowedTypes[$mimeType];
            $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $savePath = $uploadsDir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $savePath)) {
                throw new Exception("Failed to save uploaded file");
            }

            // Relative path for database
            $relativePath = 'uploads/workbench/' . $taskId . '/' . $filename;

            // Get optional caption/content
            $content = trim($this->getParam('content', ''));

            // Create comment with image
            $comment = Bean::dispense('taskcomment');
            $comment->taskId = $taskId;
            $comment->memberId = $this->member->id;
            $comment->content = $content ?: null;
            $comment->imagePath = $relativePath;
            $comment->isFromClaude = 0;
            $comment->isInternal = 0;
            $comment->createdAt = date('Y-m-d H:i:s');
            Bean::store($comment);

            $this->logTaskEvent($taskId, 'info', 'user', 'Image uploaded' . ($content ? " with caption" : ''));

            // Try to send notification to Claude session if running
            $sentToSession = false;
            if (!empty($task->tmuxSession)) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $message = "[User uploaded an image";
                    if ($content) {
                        $message .= " with message: {$content}";
                    }
                    $message .= ". View it in the task UI.]\n\n[REMINDER: Use the tiknix MCP tools to update the project status]";
                    $sentToSession = $runner->sendPrompt($message);

                    // If task was awaiting, mark as running
                    if ($sentToSession && $task->status === 'awaiting') {
                        $task->status = 'running';
                        $task->updatedAt = date('Y-m-d H:i:s');
                        Bean::store($task);
                    }
                }
            }

            Flight::json([
                'success' => true,
                'sent_to_session' => $sentToSession,
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'image_path' => $relativePath,
                    'image_url' => '/' . $relativePath,
                    'author' => $this->member->displayName ?? $this->member->email,
                    'created_at' => $comment->createdAt
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to upload image', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to upload image: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a comment from a task
     */
    public function deletecomment($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $commentId = (int)$this->getParam('comment_id');

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id || !$this->access->canEdit($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $comment = Bean::load('taskcomment', $commentId);
        if (!$comment->id || $comment->taskId != $taskId) {
            Flight::jsonError('Comment not found', 404);
            return;
        }

        try {
            Bean::trash($comment);
            $this->logTaskEvent($taskId, 'info', 'user', 'Comment deleted');

            Flight::json([
                'success' => true,
                'message' => 'Comment deleted'
            ]);
        } catch (Exception $e) {
            Flight::jsonError('Failed to delete comment', 500);
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
     * Create a PR using the gh CLI
     *
     * @param object $task The task bean
     * @return array ['url' => string|null, 'error' => string|null]
     */
    private function createPRViaCli(object $task): array {
        $workspacePath = $task->projectPath;

        if (!is_dir($workspacePath)) {
            return ['url' => null, 'error' => 'Workspace not found'];
        }

        // Build PR title based on task type
        $typePrefix = match($task->taskType) {
            'bugfix' => 'fix',
            'feature' => 'feat',
            'refactor' => 'refactor',
            'security' => 'security',
            'docs' => 'docs',
            'test' => 'test',
            default => 'task'
        };
        $title = "{$typePrefix}: {$task->title}";

        // Build PR body
        $body = "## Task #{$task->id}\n\n";
        if (!empty($task->description)) {
            $body .= "{$task->description}\n\n";
        }
        if (!empty($task->acceptanceCriteria)) {
            $body .= "## Acceptance Criteria\n{$task->acceptanceCriteria}\n\n";
        }
        $body .= "---\n*Created via Tiknix Workbench*";

        // Escape for shell
        $escapedTitle = escapeshellarg($title);
        $escapedBody = escapeshellarg($body);

        // Target base branch (for PR to merge into)
        $baseBranch = $task->baseBranch ?: 'main';
        $escapedBase = escapeshellarg($baseBranch);

        // Run gh pr create with base branch
        $cmd = "cd " . escapeshellarg($workspacePath) . " && gh pr create --title {$escapedTitle} --body {$escapedBody} --base {$escapedBase} 2>&1";

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        $outputStr = implode("\n", $output);

        if ($returnCode === 0) {
            // gh pr create outputs the PR URL on success
            $prUrl = trim($outputStr);
            if (filter_var($prUrl, FILTER_VALIDATE_URL)) {
                $this->logTaskEvent($task->id, 'info', 'github', "PR created: {$prUrl}");
                return ['url' => $prUrl, 'error' => null];
            }
        }

        // Check for common errors
        if (strpos($outputStr, 'already exists') !== false) {
            // PR already exists - try to get its URL
            $prUrl = $this->getExistingPrUrl($workspacePath, $task->branchName);
            if ($prUrl) {
                $this->logTaskEvent($task->id, 'info', 'github', "PR already exists: {$prUrl}");
                return ['url' => $prUrl, 'error' => null];
            }
            return ['url' => null, 'error' => 'PR already exists'];
        }

        $this->logger->warning('gh pr create failed', [
            'task_id' => $task->id,
            'output' => $outputStr,
            'return_code' => $returnCode
        ]);

        return ['url' => null, 'error' => $outputStr ?: 'Failed to create PR'];
    }

    /**
     * Get existing PR URL for a branch
     */
    private function getExistingPrUrl(string $workspacePath, string $branchName): ?string {
        $cmd = "cd " . escapeshellarg($workspacePath) . " && gh pr view " . escapeshellarg($branchName) . " --json url -q .url 2>&1";
        $output = trim(shell_exec($cmd) ?? '');

        if (filter_var($output, FILTER_VALIDATE_URL)) {
            return $output;
        }

        return null;
    }

    /**
     * Auto-start test server for a task (non-blocking)
     * Called automatically when a task starts running
     *
     * @param object $task The task bean
     * @param int $memberId The member starting the task
     * @return array|null Server info if started, null if skipped/failed
     */
    private function autoStartTestServer($task, int $memberId): ?array {
        // Skip if no branch or port assigned
        if (empty($task->branchName) || empty($task->assignedPort)) {
            return null;
        }

        // Skip if test server already running
        if (!empty($task->testServerSession) && TmuxManager::exists($task->testServerSession)) {
            return null;
        }

        // Skip if port is not available
        if (!PortManager::isPortAvailable($task->assignedPort)) {
            $this->logger->warning('Auto-start skipped: port in use', [
                'task_id' => $task->id,
                'port' => $task->assignedPort
            ]);
            return null;
        }

        try {
            $sessionName = TmuxManager::buildServerSessionName($memberId, $task->id);
            $projectPath = !empty($task->projectPath) ? $task->projectPath : dirname(__DIR__);

            // Build the server command
            if (!empty($task->projectPath)) {
                // Workspace mode - already on correct branch
                $serverCmd = sprintf(
                    'cd %s && php -S 0.0.0.0:%d server.php; echo "Server stopped. Press Enter to close..."; read',
                    escapeshellarg($projectPath),
                    $task->assignedPort
                );
            } else {
                // Main project mode - checkout branch first
                $serverCmd = sprintf(
                    'cd %s && git checkout %s && php -S 0.0.0.0:%d server.php; echo "Server stopped. Press Enter to close..."; read',
                    escapeshellarg($projectPath),
                    escapeshellarg($task->branchName),
                    $task->assignedPort
                );
            }

            TmuxManager::create($sessionName, $serverCmd, $projectPath);

            $task->testServerSession = $sessionName;

            // Create .proxy file for subdomain routing
            // File format: proxyhost=X\nproxyport=Y (lua loadEnvFile expects key=value)
            // Filename: .proxy.{hash}.{domain} (no TLD - nginx lua strips it)
            if (!empty($task->proxyHash)) {
                $baseDomain = preg_replace('#^https?://#', '', Flight::get('baseurl') ?? 'https://localhost');
                // Strip TLD (e.g., .com, .net) - nginx lua expects domain without TLD
                $baseDomain = preg_replace('/\.[a-z]{2,}$/i', '', $baseDomain);
                $proxyFile = "/var/www/html/.proxy.{$task->proxyHash}.{$baseDomain}";
                $proxyContent = "proxyhost=127.0.0.1\nproxyport={$task->assignedPort}";
                if (file_put_contents($proxyFile, $proxyContent) !== false) {
                    $task->proxyFile = $proxyFile;
                }
            }

            Bean::store($task);

            $baseDomain = $baseDomain ?? preg_replace('#^https?://#', '', Flight::get('baseurl') ?? 'https://localhost');
            $testUrl = !empty($task->proxyHash)
                ? "https://{$task->proxyHash}.{$baseDomain}"
                : "http://localhost:{$task->assignedPort}";

            $this->logTaskEvent($task->id, 'info', 'system', "Test server auto-started on port {$task->assignedPort}");

            return [
                'session' => $sessionName,
                'port' => $task->assignedPort,
                'url' => $testUrl
            ];

        } catch (Exception $e) {
            $this->logger->warning('Auto-start test server failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage()
            ]);
            return null;
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

    /**
     * Get authcontrol levels available to current member
     * Members can only assign levels >= their own level (lower privilege or equal)
     *
     * @return array Levels the member can assign
     */
    private function getAuthcontrolLevels(): array {
        $memberLevel = $this->member->level ?? LEVELS['PUBLIC'];

        $availableLevels = [];
        foreach (LEVELS as $name => $value) {
            if ($value >= $memberLevel) {
                $availableLevels[$value] = [
                    'label' => ucfirst(strtolower($name)),
                    'value' => $value
                ];
            }
        }

        ksort($availableLevels);
        return $availableLevels;
    }

    /**
     * Recursively delete a directory
     *
     * @param string $dir Directory path to delete
     */
    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Generate .mcp.json for a workspace at run time
     *
     * Called every time a task is run to ensure fresh config with
     * correct baseurl from config.ini and valid API key.
     *
     * @param string $workspacePath Path to the workspace
     * @param string|null $apiKey API key for tiknix MCP auth
     * @param string $baseUrl Base URL from config.ini
     */
    private function generateWorkspaceMcpConfig(string $workspacePath, ?string $apiKey, string $baseUrl): void {
        $mcpConfig = [
            'mcpServers' => [
                'playwright' => [
                    'command' => 'npx',
                    'args' => ['@playwright/mcp@latest', '--headless']
                ]
            ]
        ];

        // Add tiknix MCP if we have an API key
        // Use HTTP transport with /mcp/message endpoint (matches working config)
        if ($apiKey) {
            $mcpConfig['mcpServers']['tiknix'] = [
                'type' => 'http',
                'url' => rtrim($baseUrl, '/') . '/mcp/message',
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey
                ]
            ];
        }

        $mcpJsonPath = rtrim($workspacePath, '/') . '/.mcp.json';
        file_put_contents(
            $mcpJsonPath,
            json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Get or create a workbench API key for the member
     *
     * Creates an API key specifically for Claude workspace workers to access
     * tiknix MCP tools (check_flightphp, check_redbean, etc.)
     *
     * @param int $memberId Member ID
     * @return string|null API key token or null if creation failed
     */
    private function getOrCreateWorkbenchApiKey(int $memberId): ?string {
        $keyName = 'Workbench Auto-Key';

        // Check for existing workbench key
        $existingKey = Bean::findOne('apikey',
            'member_id = ? AND name = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > ?)',
            [$memberId, $keyName, date('Y-m-d H:i:s')]
        );

        if ($existingKey) {
            return $existingKey->token;
        }

        // Create new workbench API key
        try {
            $key = Bean::dispense('apikey');
            $key->memberId = $memberId;
            $key->name = $keyName;
            $key->token = 'tk_' . bin2hex(random_bytes(32));
            $key->scopes = json_encode(['mcp:tools']); // Limited to MCP tools only
            $key->allowedServers = json_encode([]); // All servers
            $key->isActive = 1;
            $key->expiresAt = date('Y-m-d H:i:s', strtotime('+1 year')); // 1 year expiry
            $key->createdAt = date('Y-m-d H:i:s');
            $key->usageCount = 0;
            Bean::store($key);

            $this->logger->info('Created workbench API key', [
                'member_id' => $memberId,
                'key_id' => $key->id
            ]);

            return $key->token;
        } catch (Exception $e) {
            $this->logger->error('Failed to create workbench API key', [
                'member_id' => $memberId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
