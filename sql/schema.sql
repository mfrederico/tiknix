-- Tiknix Framework - Complete Database Schema
-- Compatible with SQLite and MySQL/MariaDB
-- Run this file to initialize a fresh database
--
-- Usage:
--   SQLite:  sqlite3 database/tiknix.db < sql/schema.sql
--   MySQL:   mysql -u user -p database < sql/schema.sql

-- ============================================
-- CORE TABLES
-- ============================================

-- Members table (users)
CREATE TABLE IF NOT EXISTS member (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    username TEXT UNIQUE,
    password TEXT,
    level INTEGER DEFAULT 100,
    status TEXT DEFAULT 'active',
    first_name TEXT,
    last_name TEXT,
    bio TEXT,
    avatar_url TEXT,
    google_id TEXT UNIQUE,
    reset_token TEXT,
    reset_expires TEXT,
    last_login TEXT,
    login_count INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- Permissions table (authcontrol)
CREATE TABLE IF NOT EXISTS authcontrol (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    control TEXT NOT NULL,
    method TEXT NOT NULL,
    level INTEGER DEFAULT 100,
    description TEXT,
    validcount INTEGER DEFAULT 0,
    linkorder INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(control, method)
);

-- Settings table (key-value store per member)
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER REFERENCES member(id) ON DELETE SET NULL,
    setting_key TEXT NOT NULL,
    setting_value TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(member_id, setting_key)
);

-- ============================================
-- CONTACT SYSTEM
-- ============================================

-- Contact messages
CREATE TABLE IF NOT EXISTS contact (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    subject TEXT,
    message TEXT NOT NULL,
    category TEXT DEFAULT 'general',
    status TEXT DEFAULT 'new',
    ip_address TEXT,
    user_agent TEXT,
    member_id INTEGER REFERENCES member(id) ON DELETE SET NULL,
    read_at TEXT,
    responded_at TEXT,
    responded_by INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- Contact responses
CREATE TABLE IF NOT EXISTS contactresponse (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL REFERENCES contact(id) ON DELETE CASCADE,
    admin_id INTEGER REFERENCES member(id) ON DELETE SET NULL,
    response TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TEAMS & WORKBENCH SYSTEM
-- ============================================

-- Teams
CREATE TABLE IF NOT EXISTS team (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    owner_id INTEGER NOT NULL REFERENCES member(id),
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- Team members (many-to-many)
CREATE TABLE IF NOT EXISTS teammember (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL REFERENCES team(id) ON DELETE CASCADE,
    member_id INTEGER NOT NULL REFERENCES member(id) ON DELETE CASCADE,
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
    team_id INTEGER NOT NULL REFERENCES team(id) ON DELETE CASCADE,
    email TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,
    role TEXT DEFAULT 'member',
    invited_by INTEGER NOT NULL REFERENCES member(id),
    expires_at TEXT,
    accepted_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Workbench tasks
CREATE TABLE IF NOT EXISTS workbenchtask (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    task_type TEXT DEFAULT 'feature',
    priority INTEGER DEFAULT 3,
    status TEXT DEFAULT 'pending',

    -- Ownership
    member_id INTEGER NOT NULL REFERENCES member(id),
    team_id INTEGER REFERENCES team(id) ON DELETE SET NULL,
    assigned_to INTEGER REFERENCES member(id) ON DELETE SET NULL,

    -- Claude execution tracking
    tmux_session TEXT,
    current_run_id TEXT,
    run_count INTEGER DEFAULT 0,
    last_runner_member_id INTEGER,
    progress_message TEXT,
    results_json TEXT,

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
    parent_task_id INTEGER REFERENCES workbenchtask(id) ON DELETE SET NULL
);

-- Task logs (execution history)
CREATE TABLE IF NOT EXISTS tasklog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL REFERENCES workbenchtask(id) ON DELETE CASCADE,
    member_id INTEGER REFERENCES member(id) ON DELETE SET NULL,
    log_level TEXT DEFAULT 'info',
    log_type TEXT DEFAULT 'system',
    message TEXT NOT NULL,
    context_json TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Task snapshots (Claude output captures)
CREATE TABLE IF NOT EXISTS tasksnapshot (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL REFERENCES workbenchtask(id) ON DELETE CASCADE,
    snapshot_type TEXT,
    content TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Task comments (conversation between user and Claude)
CREATE TABLE IF NOT EXISTS taskcomment (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL REFERENCES workbenchtask(id) ON DELETE CASCADE,
    member_id INTEGER NOT NULL REFERENCES member(id) ON DELETE CASCADE,
    content TEXT NOT NULL,
    is_internal INTEGER DEFAULT 0,
    is_from_claude INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- Workbench task logs (alternative log table)
CREATE TABLE IF NOT EXISTS workbenchtasklog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workbenchtask_id INTEGER REFERENCES workbenchtask(id) ON DELETE CASCADE,
    level TEXT,
    message TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Workbench task comments (alternative comment table)
CREATE TABLE IF NOT EXISTS workbenchtaskcomment (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workbenchtask_id INTEGER REFERENCES workbenchtask(id) ON DELETE CASCADE,
    member_id INTEGER REFERENCES member(id) ON DELETE SET NULL,
    is_from_claude INTEGER DEFAULT 0,
    content TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- MCP SYSTEM (Model Context Protocol)
-- ============================================

-- MCP Server Registry
CREATE TABLE IF NOT EXISTS mcpserver (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    endpoint_url TEXT NOT NULL,
    version TEXT DEFAULT '1.0.0',
    status TEXT DEFAULT 'active',
    author TEXT,
    author_url TEXT,
    tools TEXT DEFAULT '[]',
    auth_type TEXT DEFAULT 'none',
    documentation_url TEXT,
    icon_url TEXT,
    tags TEXT DEFAULT '[]',
    featured INTEGER DEFAULT 0,
    sort_order INTEGER DEFAULT 0,
    -- Gateway/Proxy fields
    backend_auth_token TEXT,
    backend_auth_header TEXT DEFAULT 'Authorization',
    is_proxy_enabled INTEGER DEFAULT 1,
    -- Startup configuration (for local servers)
    startup_command TEXT,
    startup_args TEXT,
    startup_working_dir TEXT,
    startup_port INTEGER,
    -- Tools cache
    tools_cache TEXT,
    tools_cached_at TEXT,
    -- Registry sync
    registry_source TEXT DEFAULT 'local',
    -- Timestamps
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER REFERENCES member(id),
    updated_at TEXT
);

-- API Keys (scoped access tokens)
CREATE TABLE IF NOT EXISTS apikey (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL REFERENCES member(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,
    scopes TEXT DEFAULT '[]',
    allowed_servers TEXT DEFAULT '[]',
    expires_at TEXT,
    last_used_at TEXT,
    last_used_ip TEXT,
    usage_count INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- MCP Usage Logging
CREATE TABLE IF NOT EXISTS mcpusage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    apikey_id INTEGER REFERENCES apikey(id) ON DELETE SET NULL,
    member_id INTEGER REFERENCES member(id) ON DELETE SET NULL,
    server_slug TEXT NOT NULL,
    tool_name TEXT NOT NULL,
    request_data TEXT,
    response_status TEXT,
    response_time_ms INTEGER,
    error_message TEXT,
    ip_address TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- MCP Sessions
CREATE TABLE IF NOT EXISTS mcpsession (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    apikey_id INTEGER REFERENCES apikey(id) ON DELETE SET NULL,
    session_id TEXT UNIQUE,
    server_slug TEXT,
    expires_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- MCP Request Logs (detailed logging)
CREATE TABLE IF NOT EXISTS mcplog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER REFERENCES member(id) ON DELETE SET NULL,
    apikey_id INTEGER REFERENCES apikey(id) ON DELETE SET NULL,
    server_slug TEXT,
    session_id TEXT,
    method TEXT,
    tool_name TEXT,
    arguments TEXT,
    request_body TEXT,
    response TEXT,
    response_body TEXT,
    result TEXT,
    success INTEGER,
    http_code INTEGER,
    duration INTEGER,
    error TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- GROCERY LIST (Example Feature)
-- ============================================

-- Grocery lists (saved shopping trips)
CREATE TABLE IF NOT EXISTS grocerylist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER REFERENCES member(id) ON DELETE CASCADE,
    list_date TEXT,
    store_name TEXT,
    total_cost REAL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- Grocery items
CREATE TABLE IF NOT EXISTS groceryitem (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER REFERENCES member(id) ON DELETE CASCADE,
    grocerylist_id INTEGER REFERENCES grocerylist(id) ON DELETE SET NULL,
    name TEXT NOT NULL,
    quantity INTEGER DEFAULT 1,
    is_checked INTEGER DEFAULT 0,
    sort_order INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- ============================================
-- INDEXES
-- ============================================

-- Member indexes
CREATE INDEX IF NOT EXISTS idx_member_email ON member(email);
CREATE INDEX IF NOT EXISTS idx_member_username ON member(username);
CREATE INDEX IF NOT EXISTS idx_member_level ON member(level);
CREATE INDEX IF NOT EXISTS idx_member_status ON member(status);
CREATE INDEX IF NOT EXISTS idx_member_google_id ON member(google_id);

-- Authcontrol indexes
CREATE INDEX IF NOT EXISTS idx_authcontrol_control ON authcontrol(control);
CREATE INDEX IF NOT EXISTS idx_authcontrol_level ON authcontrol(level);

-- Settings indexes
CREATE INDEX IF NOT EXISTS idx_settings_member ON settings(member_id);

-- Contact indexes
CREATE INDEX IF NOT EXISTS idx_contact_status ON contact(status);
CREATE INDEX IF NOT EXISTS idx_contact_member ON contact(member_id);
CREATE INDEX IF NOT EXISTS idx_contact_created ON contact(created_at);
CREATE INDEX IF NOT EXISTS idx_contactresponse_contact ON contactresponse(contact_id);

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

-- MCP indexes
CREATE INDEX IF NOT EXISTS idx_mcpserver_slug ON mcpserver(slug);
CREATE INDEX IF NOT EXISTS idx_mcpserver_status ON mcpserver(status);
CREATE INDEX IF NOT EXISTS idx_apikey_token ON apikey(token);
CREATE INDEX IF NOT EXISTS idx_apikey_member ON apikey(member_id);
CREATE INDEX IF NOT EXISTS idx_apikey_active ON apikey(is_active);
CREATE INDEX IF NOT EXISTS idx_mcpusage_apikey ON mcpusage(apikey_id);
CREATE INDEX IF NOT EXISTS idx_mcpusage_member ON mcpusage(member_id);
CREATE INDEX IF NOT EXISTS idx_mcpusage_server ON mcpusage(server_slug);
CREATE INDEX IF NOT EXISTS idx_mcpusage_created ON mcpusage(created_at);
CREATE INDEX IF NOT EXISTS idx_mcpsession_apikey ON mcpsession(apikey_id);
CREATE INDEX IF NOT EXISTS idx_mcpsession_session ON mcpsession(session_id);
CREATE INDEX IF NOT EXISTS idx_mcplog_member ON mcplog(member_id);
CREATE INDEX IF NOT EXISTS idx_mcplog_apikey ON mcplog(apikey_id);
CREATE INDEX IF NOT EXISTS idx_mcplog_session ON mcplog(session_id);

-- Grocery indexes
CREATE INDEX IF NOT EXISTS idx_grocerylist_member ON grocerylist(member_id);
CREATE INDEX IF NOT EXISTS idx_groceryitem_member ON groceryitem(member_id);
CREATE INDEX IF NOT EXISTS idx_groceryitem_list ON groceryitem(grocerylist_id);

-- ============================================
-- DEFAULT DATA
-- ============================================

-- Default admin user (password: admin123)
-- IMPORTANT: Change this password immediately after first login!
-- Hash generated with: password_hash('admin123', PASSWORD_DEFAULT)
INSERT OR IGNORE INTO member (email, username, password, level, status, created_at) VALUES
    ('admin@example.com', 'admin', '$2y$10$jVz654DI7bX8e1Dh32O9suFcMW4x1V.0SrniJNpDyknwkzc6gM20a', 1, 'active', datetime('now'));

-- Public user entity (system user for unauthenticated requests)
INSERT OR IGNORE INTO member (email, username, password, level, status, created_at) VALUES
    ('public@localhost', 'public-user-entity', '', 101, 'system', datetime('now'));

-- Default permissions
INSERT OR IGNORE INTO authcontrol (control, method, level, description, created_at) VALUES
    -- Public routes (level 101)
    ('index', 'index', 101, 'Home page', datetime('now')),
    ('index', '*', 101, 'All index methods', datetime('now')),
    ('auth', 'login', 101, 'Login page', datetime('now')),
    ('auth', 'dologin', 101, 'Process login', datetime('now')),
    ('auth', 'register', 101, 'Registration page', datetime('now')),
    ('auth', 'doregister', 101, 'Process registration', datetime('now')),
    ('auth', 'forgot', 101, 'Forgot password page', datetime('now')),
    ('auth', 'doforgot', 101, 'Process forgot password', datetime('now')),
    ('auth', 'reset', 101, 'Reset password page', datetime('now')),
    ('auth', 'doreset', 101, 'Process reset password', datetime('now')),
    ('auth', 'google', 101, 'Google OAuth login', datetime('now')),
    ('auth', 'googlecallback', 101, 'Google OAuth callback', datetime('now')),
    ('auth', 'verify', 101, 'Email verification', datetime('now')),
    ('docs', '*', 101, 'Documentation', datetime('now')),
    ('help', '*', 101, 'Help pages', datetime('now')),
    ('contact', 'index', 101, 'Contact form', datetime('now')),
    ('contact', 'submit', 101, 'Submit contact form', datetime('now')),
    ('terms', 'index', 101, 'Terms of service', datetime('now')),
    ('privacy', 'index', 101, 'Privacy policy', datetime('now')),

    -- Member routes (level 100)
    ('auth', 'logout', 100, 'Logout', datetime('now')),
    ('member', '*', 100, 'All member methods', datetime('now')),
    ('dashboard', '*', 100, 'Dashboard access', datetime('now')),
    ('apikeys', '*', 100, 'API key management', datetime('now')),
    ('grocery', '*', 100, 'Grocery list management', datetime('now')),
    ('workbench', '*', 100, 'Workbench access', datetime('now')),
    ('teams', '*', 100, 'Teams management', datetime('now')),

    -- Admin routes (level 50)
    ('admin', '*', 50, 'Admin panel access', datetime('now')),
    ('permissions', '*', 50, 'Permission management', datetime('now')),
    ('contact', 'admin', 50, 'View contact messages', datetime('now')),
    ('contact', 'view', 50, 'View single message', datetime('now')),
    ('contact', 'respond', 50, 'Respond to message', datetime('now')),
    ('mcpregistry', '*', 50, 'MCP Server Registry management', datetime('now')),

    -- Public MCP endpoints (level 101) - auth handled by controller
    ('mcp', '*', 101, 'MCP server endpoints', datetime('now')),
    ('mcp', 'message', 101, 'MCP JSON-RPC endpoint', datetime('now')),
    ('mcp', 'health', 101, 'MCP health check', datetime('now')),
    ('mcpregistry', 'testConnection', 101, 'Test MCP server connection', datetime('now')),

    -- Root only (level 1)
    ('permissions', 'build', 1, 'Build mode - scan controllers', datetime('now')),
    ('permissions', 'scan', 1, 'Scan for new permissions', datetime('now'));

-- ============================================
-- NOTES
-- ============================================
--
-- Permission Levels:
--   1   = ROOT (super admin)
--   50  = ADMIN (administrator)
--   100 = MEMBER (logged in user)
--   101 = PUBLIC (anyone, including guests)
--
-- To reset the database:
--   rm database/tiknix.db && sqlite3 database/tiknix.db < sql/schema.sql
--
-- Default login:
--   Username: admin
--   Password: admin123
--   ** CHANGE THIS PASSWORD IMMEDIATELY **
--
-- MCP Security Note:
--   The mcp::message endpoint is PUBLIC (101) intentionally.
--   Authentication is handled at the controller level using API keys.
--   See CLAUDE.md for full security documentation.
