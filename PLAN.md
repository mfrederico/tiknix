# Tiknix Integrated Claude Code Development Platform

## Overview

Transform Tiknix into a self-contained AI development platform with:
1. **Workbench** - A micro-Jira task system for describing features
2. **Claude Runner** - Tmux-based autonomous Claude Code execution
3. **Validation Agents** - Comprehensive PHP, security, and convention checking
4. **Playwright MCP Proxy** - UI/UX testing via external Playwright server
5. **Multi-User & Teams** - Isolated personal tasks with optional team collaboration

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                        TIKNIX PLATFORM                               │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌──────────────┐     ┌──────────────────┐     ┌─────────────────┐  │
│  │  WORKBENCH   │────▶│   CLAUDE RUNNER  │────▶│  TMUX SESSIONS  │  │
│  │  (UI/Tasks)  │     │   (Orchestrator) │     │  (Per-User)     │  │
│  └──────────────┘     └──────────────────┘     └─────────────────┘  │
│         │                      │                       │             │
│         │                      ▼                       │             │
│         │             ┌──────────────────┐             │             │
│  ┌──────▼──────┐      │   MCP GATEWAY    │◀────────────┘             │
│  │   TEAMS     │      │  (Existing)      │                           │
│  │ (Optional)  │      └──────────────────┘                           │
│  └─────────────┘               │                                     │
│         │                      ▼                                     │
│         ▼             ┌──────────────────┐     ┌─────────────────┐  │
│  ┌──────────────┐     │ VALIDATION TOOLS │     │ PLAYWRIGHT MCP  │  │
│  │  DATABASE    │     │ (MCP + Hooks)    │     │ (External Proxy)│  │
│  │  (SQLite)    │     └──────────────────┘     └─────────────────┘  │
│  └──────────────┘                                                    │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Phase 1: Multi-User & Team Foundation

### 1.1 Database Schema - Teams

```sql
-- Teams table
CREATE TABLE team (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    owner_id INTEGER NOT NULL,             -- Member who created the team
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Team membership with roles
CREATE TABLE teammember (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    role VARCHAR(50) DEFAULT 'member',     -- owner, admin, member, viewer
    can_run_tasks INTEGER DEFAULT 1,       -- Can trigger Claude runs
    can_edit_tasks INTEGER DEFAULT 1,      -- Can modify tasks
    can_delete_tasks INTEGER DEFAULT 0,    -- Can delete tasks
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(team_id, member_id)
);

-- Team invitations
CREATE TABLE teaminvitation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    role VARCHAR(50) DEFAULT 'member',
    invited_by INTEGER NOT NULL,
    expires_at DATETIME,
    accepted_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_team_owner ON team(owner_id);
CREATE INDEX idx_teammember_team ON teammember(team_id);
CREATE INDEX idx_teammember_member ON teammember(member_id);
CREATE INDEX idx_teaminvitation_token ON teaminvitation(token);
```

### 1.2 Team Roles & Permissions

| Role | View Tasks | Create Tasks | Edit Tasks | Run Tasks | Delete Tasks | Manage Team |
|------|------------|--------------|------------|-----------|--------------|-------------|
| owner | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| admin | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| member | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| viewer | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

### 1.3 FUSE Models for Teams

**File:** `models/Model_Team.php`
```php
class Model_Team extends \RedBeanPHP\SimpleModel {
    // Enables: ownTeammemberList, ownTeaminvitationList, ownWorkbenchtaskList
}
```

**File:** `models/Model_Teammember.php`
```php
class Model_Teammember extends \RedBeanPHP\SimpleModel {
    // Relations: team, member
}
```

---

## Phase 2: Workbench Task System

### 2.1 Database Schema - Tasks

```sql
-- Main task table with ownership model
CREATE TABLE workbenchtask (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    task_type VARCHAR(50) DEFAULT 'feature',  -- feature, bugfix, refactor, security, docs
    priority INTEGER DEFAULT 3,                -- 1=critical, 2=high, 3=medium, 4=low
    status VARCHAR(50) DEFAULT 'pending',      -- pending, queued, running, completed, failed, paused

    -- OWNERSHIP MODEL
    member_id INTEGER NOT NULL,                -- Creator/owner
    team_id INTEGER,                           -- NULL = personal task, set = team task
    assigned_to INTEGER,                       -- Optional assignee within team

    -- Claude execution tracking
    tmux_session VARCHAR(100),
    current_run_id VARCHAR(36),
    run_count INTEGER DEFAULT 0,
    last_runner_member_id INTEGER,             -- Who triggered the last run

    -- Results
    branch_name VARCHAR(100),
    pr_url VARCHAR(255),
    last_output TEXT,
    error_message TEXT,

    -- Context for Claude
    acceptance_criteria TEXT,
    related_files TEXT,                        -- JSON array of file paths
    tags TEXT,                                 -- JSON array of tags

    -- Timestamps
    started_at DATETIME,
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- For subtasks
    parent_task_id INTEGER
);

-- Task execution logs (includes who did what)
CREATE TABLE tasklog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    member_id INTEGER,                         -- Who/what created this log
    log_level VARCHAR(20) DEFAULT 'info',      -- debug, info, warning, error
    log_type VARCHAR(50) DEFAULT 'system',     -- system, claude, user, validation
    message TEXT NOT NULL,
    context_json TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Progress snapshots (for real-time UI updates)
CREATE TABLE tasksnapshot (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    snapshot_type VARCHAR(50),                 -- output, files_changed, current_action
    content TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Task comments for team collaboration
CREATE TABLE taskcomment (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    is_internal INTEGER DEFAULT 0,             -- Internal notes vs public comments
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Indexes
CREATE INDEX idx_workbenchtask_status ON workbenchtask(status);
CREATE INDEX idx_workbenchtask_member ON workbenchtask(member_id);
CREATE INDEX idx_workbenchtask_team ON workbenchtask(team_id);
CREATE INDEX idx_workbenchtask_assigned ON workbenchtask(assigned_to);
CREATE INDEX idx_tasklog_task ON tasklog(task_id);
CREATE INDEX idx_tasksnapshot_task ON tasksnapshot(task_id);
CREATE INDEX idx_taskcomment_task ON taskcomment(task_id);
```

### 2.2 Ownership Model

```
┌─────────────────────────────────────────────────────────────┐
│                     TASK OWNERSHIP                           │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  PERSONAL TASK (team_id = NULL)                             │
│  ├── Only visible to member_id                              │
│  ├── Only member_id can run/edit/delete                     │
│  └── Tmux session: tiknix-{member_id}-task-{task_id}       │
│                                                              │
│  TEAM TASK (team_id = X)                                    │
│  ├── Visible to all team members                            │
│  ├── Editable by members with can_edit_tasks=1              │
│  ├── Runnable by members with can_run_tasks=1               │
│  ├── Deletable by members with can_delete_tasks=1           │
│  └── Tmux session: tiknix-team-{team_id}-task-{task_id}    │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### 2.3 FUSE Model

**File:** `models/Model_Workbenchtask.php`

```php
class Model_Workbenchtask extends \RedBeanPHP\SimpleModel {
    // Enables: ownTasklogList, ownTasksnapshotList, ownTaskcommentList
    // Relations: member (owner), team, assignedTo (member)
}
```

### 2.4 Access Control Service

**File:** `lib/TaskAccessControl.php`

```php
class TaskAccessControl {
    /**
     * Check if member can view task
     */
    public function canView(int $memberId, $task): bool {
        // Personal task: only owner
        if (empty($task->teamId)) {
            return $task->memberId == $memberId;
        }
        // Team task: any team member
        return $this->isTeamMember($task->teamId, $memberId);
    }

    /**
     * Check if member can edit task
     */
    public function canEdit(int $memberId, $task): bool {
        if (empty($task->teamId)) {
            return $task->memberId == $memberId;
        }
        return $this->hasTeamPermission($task->teamId, $memberId, 'can_edit_tasks');
    }

    /**
     * Check if member can run task (trigger Claude)
     */
    public function canRun(int $memberId, $task): bool {
        if (empty($task->teamId)) {
            return $task->memberId == $memberId;
        }
        return $this->hasTeamPermission($task->teamId, $memberId, 'can_run_tasks');
    }

    /**
     * Check if member can delete task
     */
    public function canDelete(int $memberId, $task): bool {
        if (empty($task->teamId)) {
            return $task->memberId == $memberId;
        }
        // Owner can always delete
        if ($task->memberId == $memberId) return true;
        return $this->hasTeamPermission($task->teamId, $memberId, 'can_delete_tasks');
    }

    /**
     * Get all tasks visible to member
     */
    public function getVisibleTasks(int $memberId, array $filters = []): array {
        // Personal tasks + team tasks
        $teamIds = $this->getMemberTeamIds($memberId);
        // Query with filters...
    }
}
```

### 2.5 Controller

**File:** `controls/Workbench.php`

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| `index()` | GET /workbench | MEMBER | Dashboard with personal + team tasks |
| `create()` | GET /workbench/create | MEMBER | New task form (select personal/team) |
| `store()` | POST /workbench/store | MEMBER | Save new task |
| `view($id)` | GET /workbench/view/{id} | MEMBER | Task detail (access controlled) |
| `edit($id)` | GET /workbench/edit/{id} | MEMBER | Edit form (access controlled) |
| `update($id)` | POST /workbench/update/{id} | MEMBER | Update task |
| `delete($id)` | POST /workbench/delete/{id} | MEMBER | Delete task |
| `run($id)` | POST /workbench/run/{id} | MEMBER | Start Claude runner |
| `pause($id)` | POST /workbench/pause/{id} | MEMBER | Pause running task |
| `resume($id)` | POST /workbench/resume/{id} | MEMBER | Resume paused task |
| `progress($id)` | GET /workbench/progress/{id} | MEMBER | AJAX progress poll |
| `output($id)` | GET /workbench/output/{id} | MEMBER | Full output log |
| `comment($id)` | POST /workbench/comment/{id} | MEMBER | Add comment |

**File:** `controls/Teams.php`

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| `index()` | GET /teams | MEMBER | List my teams |
| `create()` | GET /teams/create | MEMBER | Create team form |
| `store()` | POST /teams/store | MEMBER | Save new team |
| `view($id)` | GET /teams/view/{id} | MEMBER | Team dashboard |
| `settings($id)` | GET /teams/settings/{id} | MEMBER | Team settings (owner/admin) |
| `members($id)` | GET /teams/members/{id} | MEMBER | Manage members |
| `invite($id)` | POST /teams/invite/{id} | MEMBER | Send invitation |
| `acceptInvite($token)` | GET /teams/join/{token} | MEMBER | Accept invitation |
| `leave($id)` | POST /teams/leave/{id} | MEMBER | Leave team |
| `removeMember($id)` | POST /teams/remove-member/{id} | MEMBER | Remove member (admin) |
| `updateRole($id)` | POST /teams/update-role/{id} | MEMBER | Change member role |

### 2.6 Views

```
views/workbench/
├── index.php              # Task dashboard (tabs: Personal | Team | All)
├── create.php             # New task form with team selector
├── edit.php               # Edit task form
├── view.php               # Task detail with live progress
└── _partials/
    ├── task_card.php      # Task card with ownership badge
    ├── progress.php       # Live progress widget
    ├── log_viewer.php     # Task log display
    └── comments.php       # Comment thread

views/teams/
├── index.php              # My teams list
├── create.php             # Create team form
├── view.php               # Team dashboard with tasks
├── settings.php           # Team settings
├── members.php            # Member management
└── invite.php             # Invitation form
```

### 2.7 Task Types

| Type | Icon | Description | Default Prompt Context |
|------|------|-------------|------------------------|
| `feature` | ➕ | New functionality | "Implement a new feature..." |
| `bugfix` | 🐛 | Fix existing bug | "Fix the bug where..." |
| `refactor` | ♻️ | Code improvement | "Refactor the code to..." |
| `security` | 🔒 | Security fix | "Address the security issue..." |
| `docs` | 📝 | Documentation | "Document the..." |
| `test` | 🧪 | Add/fix tests | "Write tests for..." |

---

## Phase 3: Claude Runner (Tmux-Based)

### 3.1 Session Naming Convention

```
Personal Task:  tiknix-{member_id}-task-{task_id}
Team Task:      tiknix-team-{team_id}-task-{task_id}

Examples:
  tiknix-5-task-123        (User #5's personal task #123)
  tiknix-team-2-task-456   (Team #2's task #456)
```

### 3.2 Service Class

**File:** `lib/ClaudeRunner.php`

```php
class ClaudeRunner {
    private int $taskId;
    private ?int $teamId;
    private int $memberId;
    private string $sessionName;
    private string $workDir;

    public function __construct(int $taskId, int $memberId, ?int $teamId = null) {
        $this->taskId = $taskId;
        $this->memberId = $memberId;
        $this->teamId = $teamId;

        // Session naming based on ownership
        if ($teamId) {
            $this->sessionName = "tiknix-team-{$teamId}-task-{$taskId}";
            $this->workDir = "/tmp/tiknix-team-{$teamId}-task-{$taskId}";
        } else {
            $this->sessionName = "tiknix-{$memberId}-task-{$taskId}";
            $this->workDir = "/tmp/tiknix-{$memberId}-task-{$taskId}";
        }
    }

    // Core methods
    public function spawn(): bool;              // Start tmux session with Claude
    public function kill(): bool;               // Terminate session
    public function isRunning(): bool;          // Check if session exists
    public function sendMessage(string $msg);   // Send input to Claude
    public function captureSnapshot(): string;  // Get current output
    public function getProgress(): array;       // Parse progress info

    // Static helpers
    public static function listAllSessions(): array;
    public static function listMemberSessions(int $memberId): array;
    public static function listTeamSessions(int $teamId): array;
    public static function findByTaskId(int $taskId): ?ClaudeRunner;
}
```

### 3.3 Runner Script

**File:** `cli/claude-worker.php`

```php
#!/usr/bin/env php
<?php
/**
 * Claude Worker - Executes Claude Code for a workbench task
 *
 * Usage: php cli/claude-worker.php --task=123 --member=5 [--team=2]
 *
 * The --member flag identifies who triggered this run (for audit logging)
 * The --team flag indicates team ownership (affects work directory isolation)
 */

// 1. Load task from database
// 2. Verify access permissions
// 3. Build prompt from task description + acceptance criteria
// 4. Configure MCP servers (include tiknix validation tools)
// 5. Execute: claude --mcp-config {workdir}/claude.json -p "{prompt}"
// 6. Capture output and update task status
// 7. Parse for PR URL, files changed, etc.
// 8. Log completion with runner member_id
```

### 3.4 Prompt Builder

**File:** `lib/PromptBuilder.php`

```php
class PromptBuilder {
    public static function build(array $task): string {
        $prompt = match($task['task_type']) {
            'feature' => self::buildFeaturePrompt($task),
            'bugfix' => self::buildBugfixPrompt($task),
            'refactor' => self::buildRefactorPrompt($task),
            'security' => self::buildSecurityPrompt($task),
            'docs' => self::buildDocsPrompt($task),
            'test' => self::buildTestPrompt($task),
            default => self::buildGenericPrompt($task),
        };

        // Append validation requirements
        $prompt .= self::getValidationInstructions();

        // Append task update instructions
        $prompt .= self::getTaskUpdateInstructions($task['id']);

        return $prompt;
    }
}
```

### 3.5 Progress Tracking

The runner captures progress by parsing tmux output for patterns:

| Pattern | Status | Description |
|---------|--------|-------------|
| `Reading file` | analyzing | Reading codebase |
| `Searching for` | analyzing | Grep/glob operations |
| `Writing to` | implementing | Creating/editing files |
| `git commit` | committing | Making commit |
| `git push` | pushing | Pushing to remote |
| `gh pr create` | pr_creating | Opening PR |
| `waiting for` | paused | Needs clarification |
| `Error:` | error | Something failed |

---

## Phase 4: Validation Agents

### 4.1 MCP Validation Tools

Add to existing Tiknix MCP server (`controls/Mcp.php`):

| Tool | Description | Returns |
|------|-------------|---------|
| `tiknix:validate_php` | PHP syntax check | `{valid: bool, errors: [...]}` |
| `tiknix:security_scan` | OWASP security scan | `{issues: [...], severity: str}` |
| `tiknix:check_redbean` | RedBeanPHP conventions | `{violations: [...]}` |
| `tiknix:check_flightphp` | FlightPHP patterns | `{violations: [...]}` |
| `tiknix:full_validation` | Run all validators | Combined results |

### 4.2 Validation Service

**File:** `lib/ValidationService.php`

```php
class ValidationService {
    // PHP Syntax
    public function validatePhpSyntax(string $file): array;
    public function validatePhpSyntaxBulk(array $files): array;

    // Security (OWASP Top 10)
    public function scanSqlInjection(string $code): array;
    public function scanXss(string $code): array;
    public function scanCsrf(string $code): array;
    public function scanCommandInjection(string $code): array;
    public function scanInsecureDeserialization(string $code): array;
    public function scanHardcodedSecrets(string $code): array;

    // RedBeanPHP
    public function checkBeanNaming(string $code): array;
    public function checkAssociationUsage(string $code): array;
    public function checkExecUsage(string $code): array;

    // FlightPHP
    public function checkControllerPatterns(string $code): array;
    public function checkRouteDefinitions(string $code): array;
    public function checkResponseMethods(string $code): array;

    // Combined
    public function fullValidation(string $file): array;
}
```

### 4.3 Security Checks (OWASP Top 10)

| Check | Pattern | Severity |
|-------|---------|----------|
| SQL Injection | Raw SQL with variables, no prepared statements | Critical |
| XSS | `echo $_GET`, unescaped output | High |
| CSRF | POST without token validation | High |
| Command Injection | `exec()`, `shell_exec()` with user input | Critical |
| Path Traversal | File ops with unsanitized paths | High |
| Hardcoded Secrets | API keys, passwords in code | Medium |
| Insecure Crypto | MD5/SHA1 for passwords | Medium |
| Open Redirect | Redirect with user-controlled URL | Medium |

### 4.4 Enhanced Claude Hooks

**File:** `.claude/hooks/validate-tiknix-php.py` (Enhanced)

```python
# Existing checks + new additions:
# - Security scanning before file writes
# - FlightPHP controller pattern validation
# - Automatic CSRF warning on POST handlers
# - Check for missing permission declarations
```

**New Hook:** `.claude/hooks/pre-commit-validation.py`

```python
# Runs before git commits:
# - Full PHP syntax check on changed files
# - Security scan on changed files
# - Convention checks
# - Block commit if critical issues found
```

---

## Phase 5: Playwright MCP Proxy

### 5.1 External Server Configuration

The Playwright MCP server runs externally. Tiknix proxies requests through its gateway.

**MCP Registry Entry:**

```php
// In mcpserver table
[
    'name' => 'Playwright Browser',
    'slug' => 'playwright',
    'description' => 'Headless browser automation for UI testing',
    'server_url' => 'http://localhost:3001',  // External Playwright MCP
    'server_type' => 'http',
    'is_proxy_enabled' => true,
    'auth_type' => 'none',  // Or 'bearer' if secured
    'tags' => '["browser", "testing", "ui", "screenshots"]'
]
```

### 5.2 Expected Playwright MCP Tools

These tools will be available via proxy:

| Tool | Description |
|------|-------------|
| `playwright:navigate` | Navigate to URL |
| `playwright:screenshot` | Take screenshot |
| `playwright:click` | Click element |
| `playwright:fill` | Fill input field |
| `playwright:evaluate` | Run JavaScript |
| `playwright:wait` | Wait for selector |
| `playwright:get_content` | Get page HTML |

### 5.3 Setup Script

**File:** `scripts/setup-playwright-mcp.sh`

```bash
#!/bin/bash
# Downloads and configures the Playwright MCP server
# Installs browsers, starts service on port 3001

npm install -g @anthropic/playwright-mcp
playwright install chromium
playwright-mcp --port 3001 &
```

### 5.4 UI Testing Integration

**File:** `lib/UITestRunner.php`

```php
class UITestRunner {
    // Convenience methods that use Playwright MCP via gateway
    public function screenshotPage(string $url): string;  // Returns base64
    public function testLoginFlow(): array;
    public function testNavigation(): array;
    public function compareScreenshots(string $before, string $after): array;
}
```

---

## Phase 6: MCP Tools for Workbench

### 6.1 New Tiknix MCP Tools

Add to `controls/Mcp.php`:

| Tool | Description | Auth Required |
|------|-------------|---------------|
| `tiknix:list_tasks` | List workbench tasks (respects ownership) | Yes |
| `tiknix:get_task` | Get task details by ID | Yes |
| `tiknix:create_task` | Create new task (personal or team) | Yes |
| `tiknix:update_task` | Update task fields | Yes |
| `tiknix:complete_task` | Mark task complete with results | Yes |
| `tiknix:fail_task` | Mark task failed with error | Yes |
| `tiknix:add_task_log` | Add log entry to task | Yes |
| `tiknix:get_task_progress` | Get current progress snapshot | Yes |
| `tiknix:list_my_teams` | List teams user belongs to | Yes |

### 6.2 Tool Definitions

```php
[
    'name' => 'tiknix:update_task',
    'description' => 'Update a workbench task. Use to report progress, set status, or record results.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
            'status' => ['type' => 'string', 'enum' => ['running', 'completed', 'failed', 'paused']],
            'branch_name' => ['type' => 'string', 'description' => 'Git branch name'],
            'pr_url' => ['type' => 'string', 'description' => 'Pull request URL'],
            'progress_message' => ['type' => 'string', 'description' => 'Progress update message'],
            'files_changed' => ['type' => 'array', 'items' => ['type' => 'string']]
        ],
        'required' => ['task_id']
    ]
]
```

---

## Phase 7: Integration & Workflow

### 7.1 Complete Workflow

```
1. User creates task in Workbench UI
   └─▶ Selects: Personal OR Team (if team member)
   └─▶ Task saved with status='pending'

2. User (with permission) clicks "Run" button
   └─▶ Access check: canRun(memberId, task)
   └─▶ ClaudeRunner::spawn() creates tmux session
   └─▶ Task status='queued', last_runner_member_id set

3. claude-worker.php executes in tmux
   └─▶ Builds prompt from task
   └─▶ Loads MCP config (tiknix gateway + playwright)
   └─▶ Task status='running'

4. Claude Code runs with:
   └─▶ tiknix:validate_* tools for validation
   └─▶ tiknix:update_task for progress reporting
   └─▶ playwright:* for UI testing (if needed)
   └─▶ Standard file/git operations

5. Progress captured via:
   └─▶ tmux capture-pane snapshots (every 5s)
   └─▶ MCP tool calls (tiknix:add_task_log)
   └─▶ Stored in tasksnapshot table

6. Team members (if team task) can monitor
   └─▶ Real-time view of progress
   └─▶ Can add comments
   └─▶ Can pause/resume (if permitted)

7. Task completes
   └─▶ PR URL extracted and saved
   └─▶ Task status='completed'
   └─▶ All team members notified (if team task)
```

### 7.2 MCP Configuration for Runner

**Generated:** `{workdir}/claude.json`

```json
{
  "mcpServers": {
    "tiknix": {
      "command": "curl",
      "args": ["-X", "POST", "http://localhost:8000/mcp/message"],
      "env": {
        "TIKNIX_API_KEY": "{{generated_key}}"
      }
    }
  }
}
```

---

## File Summary

### New Files to Create

```
controls/
├── Workbench.php              # Workbench controller
└── Teams.php                  # Teams controller

lib/
├── ClaudeRunner.php           # Tmux session management
├── PromptBuilder.php          # Task-to-prompt conversion
├── ValidationService.php      # All validation logic
├── TaskAccessControl.php      # Ownership & permissions
└── UITestRunner.php           # Playwright convenience wrapper

cli/
└── claude-worker.php          # Tmux worker script

models/
├── Model_Workbenchtask.php    # Task FUSE model
├── Model_Team.php             # Team FUSE model
└── Model_Teammember.php       # Team member FUSE model

views/workbench/
├── index.php                  # Task dashboard
├── create.php                 # New task form
├── edit.php                   # Edit task form
├── view.php                   # Task detail + progress
└── _partials/
    ├── task_card.php
    ├── progress.php
    ├── log_viewer.php
    └── comments.php

views/teams/
├── index.php                  # My teams
├── create.php                 # Create team
├── view.php                   # Team dashboard
├── settings.php               # Team settings
└── members.php                # Member management

.claude/hooks/
└── pre-commit-validation.py   # Pre-commit security checks

scripts/
└── setup-playwright-mcp.sh    # Playwright setup script

sql/
└── workbench_schema.sql       # Database migrations
```

### Files to Modify

```
controls/Mcp.php               # Add validation + workbench tools
.claude/hooks/validate-tiknix-php.py  # Enhance with security checks
sql/schema.sql                 # Add workbench + team tables
views/layouts/header.php       # Add Workbench + Teams nav links
```

---

## Implementation Order

1. **Database Schema** - Create team + workbench tables
2. **FUSE Models** - Team, Teammember, Workbenchtask
3. **TaskAccessControl** - Ownership & permission logic
4. **Teams Controller** - Basic team CRUD
5. **Teams Views** - UI for team management
6. **Workbench Controller** - Basic CRUD with access control
7. **Workbench Views** - UI for task management
8. **ClaudeRunner** - Tmux session management
9. **claude-worker.php** - Worker script
10. **PromptBuilder** - Task-to-prompt logic
11. **ValidationService** - All validation methods
12. **MCP Tools** - Add validation + workbench tools to Mcp.php
13. **Enhanced Hooks** - Security scanning hooks
14. **Playwright Proxy** - Configure external server
15. **Integration Testing** - End-to-end workflow testing

---

## Success Criteria

- [ ] Create personal task via Workbench UI
- [ ] Create team and invite members
- [ ] Create team task visible to all team members
- [ ] Access controls prevent unauthorized actions
- [ ] Run task triggers Claude Code in isolated tmux
- [ ] Team tasks use team-scoped tmux sessions
- [ ] Real-time progress visible in UI
- [ ] Claude can use tiknix:validate_* tools
- [ ] Claude can update task via tiknix:update_task
- [ ] Validation hooks block bad code patterns
- [ ] Playwright screenshots work via proxy
- [ ] PR URL captured when task completes
- [ ] Multiple tasks can run concurrently (different tmux sessions)
- [ ] Task logs show who triggered each run
- [ ] Team members can comment on team tasks
