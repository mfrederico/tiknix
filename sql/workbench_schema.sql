-- Tiknix Workbench & Teams Schema
-- Migration file for the integrated Claude Code development platform
--
-- Run this file to add workbench tables to existing database:
--   sqlite3 database/tiknix.db < sql/workbench_schema.sql

-- ============================================
-- TEAMS SYSTEM
-- ============================================

-- Teams table
CREATE TABLE IF NOT EXISTS team (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    owner_id INTEGER NOT NULL,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- Team membership with roles
CREATE TABLE IF NOT EXISTS teammember (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    role TEXT DEFAULT 'member',
    can_run_tasks INTEGER DEFAULT 1,
    can_edit_tasks INTEGER DEFAULT 1,
    can_delete_tasks INTEGER DEFAULT 0,
    joined_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(team_id, member_id)
);

-- Team invitations
CREATE TABLE IF NOT EXISTS teaminvitation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,
    role TEXT DEFAULT 'member',
    invited_by INTEGER NOT NULL,
    expires_at TEXT,
    accepted_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- WORKBENCH TASK SYSTEM
-- ============================================

-- Main task table with ownership model
CREATE TABLE IF NOT EXISTS workbenchtask (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    task_type TEXT DEFAULT 'feature',
    priority INTEGER DEFAULT 3,
    status TEXT DEFAULT 'pending',

    -- Ownership
    member_id INTEGER NOT NULL,
    team_id INTEGER,
    assigned_to INTEGER,

    -- Claude execution tracking
    tmux_session TEXT,
    current_run_id TEXT,
    run_count INTEGER DEFAULT 0,
    last_runner_member_id INTEGER,

    -- Results
    branch_name TEXT,
    pr_url TEXT,
    last_output TEXT,
    error_message TEXT,

    -- Context for Claude
    acceptance_criteria TEXT,
    related_files TEXT,
    tags TEXT,

    -- Timestamps
    started_at TEXT,
    completed_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,

    -- Subtasks
    parent_task_id INTEGER
);

-- Task execution logs
CREATE TABLE IF NOT EXISTS tasklog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    member_id INTEGER,
    log_level TEXT DEFAULT 'info',
    log_type TEXT DEFAULT 'system',
    message TEXT NOT NULL,
    context_json TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Progress snapshots for real-time UI updates
CREATE TABLE IF NOT EXISTS tasksnapshot (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    snapshot_type TEXT,
    content TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Task comments for team collaboration
CREATE TABLE IF NOT EXISTS taskcomment (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    is_internal INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- ============================================
-- INDEXES
-- ============================================

-- Team indexes
CREATE INDEX IF NOT EXISTS idx_team_owner ON team(owner_id);
CREATE INDEX IF NOT EXISTS idx_team_slug ON team(slug);
CREATE INDEX IF NOT EXISTS idx_teammember_team ON teammember(team_id);
CREATE INDEX IF NOT EXISTS idx_teammember_member ON teammember(member_id);
CREATE INDEX IF NOT EXISTS idx_teaminvitation_token ON teaminvitation(token);
CREATE INDEX IF NOT EXISTS idx_teaminvitation_email ON teaminvitation(email);

-- Workbench indexes
CREATE INDEX IF NOT EXISTS idx_workbenchtask_status ON workbenchtask(status);
CREATE INDEX IF NOT EXISTS idx_workbenchtask_member ON workbenchtask(member_id);
CREATE INDEX IF NOT EXISTS idx_workbenchtask_team ON workbenchtask(team_id);
CREATE INDEX IF NOT EXISTS idx_workbenchtask_assigned ON workbenchtask(assigned_to);
CREATE INDEX IF NOT EXISTS idx_workbenchtask_type ON workbenchtask(task_type);
CREATE INDEX IF NOT EXISTS idx_workbenchtask_priority ON workbenchtask(priority);
CREATE INDEX IF NOT EXISTS idx_workbenchtask_parent ON workbenchtask(parent_task_id);
CREATE INDEX IF NOT EXISTS idx_tasklog_task ON tasklog(task_id);
CREATE INDEX IF NOT EXISTS idx_tasklog_level ON tasklog(log_level);
CREATE INDEX IF NOT EXISTS idx_tasksnapshot_task ON tasksnapshot(task_id);
CREATE INDEX IF NOT EXISTS idx_taskcomment_task ON taskcomment(task_id);

-- ============================================
-- DEFAULT PERMISSIONS
-- ============================================

-- Teams routes (level 100 = MEMBER)
INSERT OR IGNORE INTO authcontrol (control, method, level, description, created_at) VALUES
    ('teams', 'index', 100, 'List my teams', datetime('now')),
    ('teams', 'create', 100, 'Create team form', datetime('now')),
    ('teams', 'store', 100, 'Save new team', datetime('now')),
    ('teams', 'view', 100, 'View team dashboard', datetime('now')),
    ('teams', 'settings', 100, 'Team settings', datetime('now')),
    ('teams', 'update', 100, 'Update team settings', datetime('now')),
    ('teams', 'members', 100, 'Manage team members', datetime('now')),
    ('teams', 'invite', 100, 'Send team invitation', datetime('now')),
    ('teams', 'join', 101, 'Accept invitation (public token)', datetime('now')),
    ('teams', 'leave', 100, 'Leave team', datetime('now')),
    ('teams', 'removemember', 100, 'Remove team member', datetime('now')),
    ('teams', 'updaterole', 100, 'Update member role', datetime('now')),
    ('teams', 'delete', 100, 'Delete team', datetime('now'));

-- Workbench routes (level 100 = MEMBER)
INSERT OR IGNORE INTO authcontrol (control, method, level, description, created_at) VALUES
    ('workbench', 'index', 100, 'Workbench dashboard', datetime('now')),
    ('workbench', 'create', 100, 'Create task form', datetime('now')),
    ('workbench', 'store', 100, 'Save new task', datetime('now')),
    ('workbench', 'view', 100, 'View task details', datetime('now')),
    ('workbench', 'edit', 100, 'Edit task form', datetime('now')),
    ('workbench', 'update', 100, 'Update task', datetime('now')),
    ('workbench', 'delete', 100, 'Delete task', datetime('now')),
    ('workbench', 'run', 100, 'Start Claude runner', datetime('now')),
    ('workbench', 'pause', 100, 'Pause running task', datetime('now')),
    ('workbench', 'resume', 100, 'Resume paused task', datetime('now')),
    ('workbench', 'stop', 100, 'Stop running task', datetime('now')),
    ('workbench', 'progress', 100, 'Get task progress (AJAX)', datetime('now')),
    ('workbench', 'output', 100, 'View full task output', datetime('now')),
    ('workbench', 'comment', 100, 'Add task comment', datetime('now')),
    ('workbench', 'logs', 100, 'View task logs', datetime('now'));

-- ============================================
-- NOTES
-- ============================================
--
-- Team Roles:
--   owner  - Full control, can delete team
--   admin  - Can manage members, run/edit/delete tasks
--   member - Can create, edit, run tasks (default)
--   viewer - Read-only access to team tasks
--
-- Task Types:
--   feature  - New functionality
--   bugfix   - Fix existing bug
--   refactor - Code improvement
--   security - Security fix
--   docs     - Documentation
--   test     - Add/fix tests
--
-- Task Statuses:
--   pending   - Not yet started
--   queued    - Waiting for runner
--   running   - Claude executing
--   completed - Successfully finished
--   failed    - Error occurred
--   paused    - Waiting for input
--
-- Task Priorities:
--   1 = Critical
--   2 = High
--   3 = Medium (default)
--   4 = Low
--
