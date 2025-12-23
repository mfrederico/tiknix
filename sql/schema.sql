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
    email TEXT NOT NULL UNIQUE,
    username TEXT UNIQUE,
    password TEXT,
    level INTEGER DEFAULT 100,
    status TEXT DEFAULT 'pending',
    first_name TEXT,
    last_name TEXT,
    display_name TEXT,
    avatar TEXT,
    avatar_url TEXT,
    google_id TEXT UNIQUE,
    bio TEXT,
    city TEXT,
    state TEXT,
    country TEXT,
    timezone TEXT DEFAULT 'UTC',
    api_token TEXT,
    last_login TEXT,
    login_count INTEGER DEFAULT 0,
    verification_token TEXT,
    verified_at TEXT,
    reset_token TEXT,
    reset_expires TEXT,
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
    member_id INTEGER,
    setting_key TEXT NOT NULL,
    setting_value TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(member_id, setting_key)
);

-- Sessions table
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    member_id INTEGER,
    ip_address TEXT,
    user_agent TEXT,
    payload TEXT NOT NULL,
    last_activity INTEGER NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Activity log table
CREATE TABLE IF NOT EXISTS activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER,
    action TEXT NOT NULL,
    controller TEXT,
    method TEXT,
    description TEXT,
    ip_address TEXT,
    user_agent TEXT,
    data TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
    member_id INTEGER,
    read_at TEXT,
    responded_at TEXT,
    responded_by INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

-- Contact responses
CREATE TABLE IF NOT EXISTS contactresponse (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    admin_id INTEGER NOT NULL,
    response TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- MCP SYSTEM
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
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER,
    updated_at TEXT
);

-- API Keys (scoped access tokens)
CREATE TABLE IF NOT EXISTS apikey (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
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

-- ============================================
-- INDEXES
-- ============================================

CREATE INDEX IF NOT EXISTS idx_member_email ON member(email);
CREATE INDEX IF NOT EXISTS idx_member_username ON member(username);
CREATE INDEX IF NOT EXISTS idx_member_level ON member(level);
CREATE INDEX IF NOT EXISTS idx_member_status ON member(status);
CREATE INDEX IF NOT EXISTS idx_member_google_id ON member(google_id);
CREATE INDEX IF NOT EXISTS idx_member_api_token ON member(api_token);

CREATE INDEX IF NOT EXISTS idx_authcontrol_control ON authcontrol(control);
CREATE INDEX IF NOT EXISTS idx_authcontrol_level ON authcontrol(level);

CREATE INDEX IF NOT EXISTS idx_settings_member ON settings(member_id);

CREATE INDEX IF NOT EXISTS idx_sessions_member ON sessions(member_id);
CREATE INDEX IF NOT EXISTS idx_sessions_activity ON sessions(last_activity);

CREATE INDEX IF NOT EXISTS idx_activity_member ON activity_log(member_id);
CREATE INDEX IF NOT EXISTS idx_activity_action ON activity_log(action);
CREATE INDEX IF NOT EXISTS idx_activity_created ON activity_log(created_at);

CREATE INDEX IF NOT EXISTS idx_contact_status ON contact(status);
CREATE INDEX IF NOT EXISTS idx_contact_member ON contact(member_id);
CREATE INDEX IF NOT EXISTS idx_contact_created ON contact(created_at);

CREATE INDEX IF NOT EXISTS idx_contactresponse_contact ON contactresponse(contact_id);

CREATE INDEX IF NOT EXISTS idx_mcpserver_slug ON mcpserver(slug);
CREATE INDEX IF NOT EXISTS idx_mcpserver_status ON mcpserver(status);

CREATE INDEX IF NOT EXISTS idx_apikey_token ON apikey(token);
CREATE INDEX IF NOT EXISTS idx_apikey_member ON apikey(member_id);
CREATE INDEX IF NOT EXISTS idx_apikey_active ON apikey(is_active);

-- ============================================
-- DEFAULT DATA
-- ============================================

-- Default admin user (password: admin123)
-- IMPORTANT: Change this password immediately after first login!
INSERT OR IGNORE INTO member (email, username, password, level, status, created_at) VALUES
    ('admin@example.com', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 'active', datetime('now'));

-- Public user entity (system user for unauthenticated requests)
INSERT OR IGNORE INTO member (email, username, password, level, status, created_at) VALUES
    ('public@localhost', 'public-user-entity', '', 101, 'active', datetime('now'));

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

    -- Member routes (level 100)
    ('auth', 'logout', 100, 'Logout', datetime('now')),
    ('member', '*', 100, 'All member methods', datetime('now')),
    ('dashboard', '*', 100, 'Dashboard access', datetime('now')),
    ('apikeys', '*', 100, 'API key management', datetime('now')),
    ('apikeys', 'index', 100, 'List own API keys', datetime('now')),
    ('apikeys', 'add', 100, 'Create new API key', datetime('now')),
    ('apikeys', 'edit', 100, 'Edit own API key', datetime('now')),
    ('apikeys', 'delete', 100, 'Delete own API key', datetime('now')),
    ('apikeys', 'regenerate', 100, 'Regenerate API key token', datetime('now')),

    -- Admin routes (level 50)
    ('admin', '*', 50, 'Admin panel access', datetime('now')),
    ('permissions', '*', 50, 'Permission management', datetime('now')),
    ('contact', 'admin', 50, 'View contact messages', datetime('now')),
    ('contact', 'view', 50, 'View single message', datetime('now')),
    ('contact', 'respond', 50, 'Respond to message', datetime('now')),
    ('mcpregistry', '*', 50, 'MCP Server Registry management', datetime('now')),
    ('mcpregistry', 'index', 50, 'List all MCP servers', datetime('now')),
    ('mcpregistry', 'add', 50, 'Add new MCP server', datetime('now')),
    ('mcpregistry', 'edit', 50, 'Edit MCP server', datetime('now')),
    ('mcpregistry', 'fetchTools', 50, 'Fetch tools from remote server', datetime('now')),

    -- Public API endpoints (level 101)
    ('mcp', '*', 101, 'MCP server endpoints', datetime('now')),
    ('mcp', 'message', 101, 'MCP JSON-RPC endpoint', datetime('now')),
    ('mcp', 'health', 101, 'MCP health check', datetime('now')),
    ('mcp', 'config', 101, 'MCP auto-config', datetime('now')),
    ('mcpregistry', 'api', 101, 'Public JSON API for MCP servers', datetime('now')),

    -- Root only (level 1)
    ('permissions', 'build', 1, 'Build mode - scan controllers', datetime('now')),
    ('permissions', 'scan', 1, 'Scan for new permissions', datetime('now'));

-- Default MCP server (Tiknix built-in)
INSERT OR IGNORE INTO mcpserver (name, slug, description, endpoint_url, version, status, author, tools, auth_type, documentation_url, tags, featured, sort_order, created_at, created_by) VALUES
    ('Tiknix MCP Server', 'tiknix-mcp', 'Built-in Tiknix MCP server with demo tools for AI integration.', '/mcp/message', '1.0.0', 'active', 'Tiknix',
     '[{"name":"hello","description":"Returns a friendly greeting"},{"name":"echo","description":"Echoes back the provided message"},{"name":"get_time","description":"Returns current server date/time"},{"name":"add_numbers","description":"Adds two numbers together"},{"name":"list_users","description":"Lists system users (admin only)"},{"name":"list_mcp_servers","description":"Lists registered MCP servers"}]',
     'bearer', '/mcp', '["tiknix","built-in","demo"]', 1, 0, datetime('now'), 1);

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
