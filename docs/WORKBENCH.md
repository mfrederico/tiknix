# Tiknix Workbench

The Workbench is an AI-powered task management system that integrates Claude Code for automated development workflows. It provides a web-based interface for creating, monitoring, and managing tasks that Claude can execute autonomously.

## Features

- **Task Management**: Create, edit, and track development tasks
- **Claude Integration**: Tasks are executed by Claude Code in isolated tmux sessions
- **Team Collaboration**: Share tasks with team members using role-based access
- **Live Progress**: Real-time turn-based progress tracking with snapshots
- **MCP Tools**: Custom tools for task status updates and queries

---

## Quick Start

### 1. Create a Task

Navigate to `/workbench` and click **Create Task**:

- **Title**: Brief description of the task
- **Description**: Detailed requirements and context
- **Task Type**: `feature`, `bugfix`, `refactor`, `research`, or `other`
- **Priority**: `low`, `medium`, `high`, or `critical`

### 2. Run the Task

Click **Run with Claude** to execute. Claude will:
1. Read the task description
2. Plan the implementation
3. Execute using available MCP tools
4. Update progress in real-time

### 3. Monitor Progress

The task view shows:
- **Status**: `pending`, `in_progress`, `waiting`, `completed`, `failed`
- **Turn Counter**: Which iteration Claude is on
- **Live Snapshots**: Screenshots of current work
- **Task Logs**: Detailed execution history

---

## Installation

### Prerequisites

- PHP 8.1+ with PHP-FPM
- Nginx with SSE support
- tmux for session management
- Claude Code CLI installed
- SQLite or MySQL database

### Step 1: Database Setup

Run the schema to create workbench tables:

```sql
-- From sql/schema.sql, the workbench tables include:
-- workbenchtask, tasklog, tasksnapshot, taskcomment
-- team, teammember, teaminvitation

sqlite3 database/tiknix.db < sql/schema.sql
```

### Step 2: Nginx Configuration for SSE

The MCP server uses Server-Sent Events for streaming. Configure Nginx to disable buffering:

```nginx
# /etc/nginx/sites-available/tiknix.conf

server {
    listen 443 ssl;
    http2 on;
    server_name your-domain.com;

    ssl_certificate /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    root /path/to/tiknix/public;
    index index.php;

    # MCP endpoints - SSE requires no buffering
    location /mcp {
        fastcgi_pass 127.0.0.1:9083;
        include /etc/nginx/fastcgi.conf;

        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param REQUEST_URI $request_uri;

        # CRITICAL: Disable buffering for SSE
        fastcgi_buffering off;
        fastcgi_read_timeout 300s;
        gzip off;
    }

    # Static files
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # PHP-FPM for everything else
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9083;
        fastcgi_index index.php;
        include /etc/nginx/fastcgi.conf;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Security
    location ~ /\.(git|env|htaccess) {
        deny all;
    }
}
```

Key SSE settings:
- `fastcgi_buffering off` - Streams responses immediately
- `fastcgi_read_timeout 300s` - Long timeout for streaming
- `gzip off` - Compression breaks SSE

### Step 3: tmux Setup

tmux is used to run Claude Code sessions in the background. Install it:

```bash
# Ubuntu/Debian
sudo apt install tmux

# macOS
brew install tmux
```

The Workbench creates sessions named:
- `tiknix-{username}-task-{id}` for personal tasks
- `tiknix-team-{team}-task-{id}` for team tasks

### Step 4: Claude Code Installation

Install Claude Code CLI:

```bash
# Install via npm
npm install -g @anthropic-ai/claude-code

# Or download directly
curl -fsSL https://claude.ai/install.sh | sh

# Verify installation
claude --version
```

Configure your API key:

```bash
# Set your Anthropic API key
export ANTHROPIC_API_KEY="sk-ant-..."

# Or add to ~/.bashrc / ~/.zshrc
echo 'export ANTHROPIC_API_KEY="sk-ant-..."' >> ~/.bashrc
```

### Step 5: Configure the MCP Server

Register your Tiknix MCP server in Claude's config:

```bash
# Add the MCP server
claude mcp add tiknix https://your-domain.com/mcp/message --transport sse
```

Or edit `~/.claude.json` directly:

```json
{
  "mcpServers": {
    "tiknix": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://your-domain.com/mcp/message"],
      "transport": "sse"
    }
  }
}
```

---

## Architecture

### Task Execution Flow

```
1. User creates task in Workbench UI
                |
2. Click "Run with Claude"
                |
3. ClaudeRunner spawns tmux session
                |
4. claude-worker.php executes Claude Code CLI
                |
5. Claude reads task via MCP tools
                |
6. Claude executes, updates progress
                |
7. Workbench UI polls for updates
                |
8. Task completes, session ends
```

### Key Components

| Component | Location | Purpose |
|-----------|----------|---------|
| `Workbench` controller | `controls/Workbench.php` | UI routes and actions |
| `ClaudeRunner` | `lib/ClaudeRunner.php` | Manages tmux sessions |
| `PromptBuilder` | `lib/PromptBuilder.php` | Generates task prompts |
| `TaskAccessControl` | `lib/TaskAccessControl.php` | Permission handling |
| `claude-worker.php` | `cli/claude-worker.php` | CLI execution script |

### MCP Tools

The workbench provides these MCP tools:

| Tool | Purpose |
|------|---------|
| `list_tasks` | List available tasks |
| `get_task` | Get task details |
| `update_task` | Update task status/progress |
| `complete_task` | Mark task as completed |
| `add_task_log` | Add log entry |
| `ask_question` | Request user input |

---

## Teams

### Creating a Team

1. Navigate to `/teams`
2. Click **Create Team**
3. Add team members with roles:
   - **Owner**: Full control, can delete team
   - **Admin**: Manage members and tasks
   - **Member**: Create and run tasks
   - **Viewer**: Read-only access

### Team Tasks

Tasks can be assigned to teams for collaborative work:

1. Create task in Workbench
2. Select team from dropdown
3. Team members can view/run based on role

---

## Troubleshooting

### Claude session not starting

Check tmux is installed and accessible:

```bash
which tmux
tmux -V
```

Check the claude-worker log:

```bash
tail -f /path/to/tiknix/log/claude-worker.log
```

### SSE not streaming

Verify Nginx config has buffering disabled:

```bash
nginx -t
sudo nginx -s reload
```

Test SSE endpoint directly:

```bash
curl -N https://your-domain.com/mcp/sse
```

### MCP tools not appearing

Check tool registration:

```bash
curl -X POST https://your-domain.com/mcp/message \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

### Task stuck in "running"

Kill orphaned tmux sessions:

```bash
# List sessions
tmux list-sessions | grep tiknix

# Kill specific session
tmux kill-session -t tiknix-username-task-123
```

---

## API Reference

### Task Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/workbench` | List tasks |
| GET | `/workbench/view?id={id}` | View task |
| GET | `/workbench/create` | Create form |
| POST | `/workbench/create` | Save task |
| GET | `/workbench/edit?id={id}` | Edit form |
| POST | `/workbench/edit` | Update task |
| POST | `/workbench/run` | Run with Claude |
| POST | `/workbench/stop` | Stop execution |
| DELETE | `/workbench/delete?id={id}` | Delete task |

### Task Status Values

| Status | Description |
|--------|-------------|
| `pending` | Task created, not started |
| `in_progress` | Claude actively working |
| `waiting` | Waiting for user input |
| `completed` | Successfully finished |
| `failed` | Execution failed |
| `cancelled` | Manually stopped |

---

## Version History

- **v1.5** - Initial workbench release with Claude Code integration
- Removed Swoole dependency (pure PHP-FPM)
- Added team collaboration features
- Added live snapshot capture
