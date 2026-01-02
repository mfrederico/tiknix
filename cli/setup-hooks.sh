#!/bin/bash
#
# Setup Claude Code Hooks for Tiknix
#
# This script configures Claude Code hooks for task activity logging.
# It can be called from install.sh, serve.sh, or run standalone.
#
# Usage:
#   ./cli/setup-hooks.sh [--force]
#
# Options:
#   --force    Recreate token even if it exists
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

FORCE=false
QUIET=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --force) FORCE=true ;;
        --quiet|-q) QUIET=true ;;
    esac
done

log() {
    if [ "$QUIET" = false ]; then
        echo -e "$1"
    fi
}

error() {
    echo -e "${RED}ERROR: $1${NC}" >&2
}

success() {
    log "${GREEN}✓${NC} $1"
}

info() {
    log "${CYAN}→${NC} $1"
}

warn() {
    log "${YELLOW}!${NC} $1"
}

# Get or create API token
setup_token() {
    local token_file="$PROJECT_DIR/.mcp_token"
    local db_file="$PROJECT_DIR/database/tiknix.db"

    # Check if token already exists and is valid
    if [ -f "$token_file" ] && [ "$FORCE" = false ]; then
        local existing_token=$(cat "$token_file" 2>/dev/null | tr -d '\n')
        if [ -n "$existing_token" ]; then
            # Verify token exists in database
            if command -v sqlite3 &> /dev/null && [ -f "$db_file" ]; then
                local token_valid=$(sqlite3 "$db_file" "SELECT COUNT(*) FROM apikey WHERE token='$existing_token' AND is_active=1;" 2>/dev/null)
                if [ "$token_valid" = "1" ]; then
                    success "API token already configured"
                    return 0
                fi
            else
                success "API token file exists"
                return 0
            fi
        fi
    fi

    # Need to create or refresh token
    if ! command -v sqlite3 &> /dev/null; then
        error "sqlite3 not found - cannot create API token"
        return 1
    fi

    if [ ! -f "$db_file" ]; then
        error "Database not found: $db_file"
        return 1
    fi

    # Get admin member ID
    local admin_id=$(sqlite3 "$db_file" "SELECT id FROM member WHERE level=1 OR username='admin' LIMIT 1;" 2>/dev/null)
    if [ -z "$admin_id" ]; then
        error "No admin user found in database"
        return 1
    fi

    # Check for existing hook token
    local existing_id=$(sqlite3 "$db_file" "SELECT id FROM apikey WHERE name='Tiknix Hook' AND member_id=$admin_id LIMIT 1;" 2>/dev/null)

    # Generate new token
    local api_token="tk_$(openssl rand -hex 32 2>/dev/null || php -r "echo bin2hex(random_bytes(32));")"

    if [ -n "$existing_id" ]; then
        # Update existing token
        sqlite3 "$db_file" "UPDATE apikey SET token='$api_token', updated_at=datetime('now') WHERE id=$existing_id;"
        info "Updated existing API token"
    else
        # Create new token
        sqlite3 "$db_file" "INSERT INTO apikey (member_id, name, token, scopes, allowed_servers, is_active, created_at) VALUES ($admin_id, 'Tiknix Hook', '$api_token', '[\"mcp:*\"]', '[]', 1, datetime('now'));"
        info "Created new API token"
    fi

    # Save token to file
    echo -n "$api_token" > "$token_file"
    chmod 600 "$token_file"
    success "API token saved to .mcp_token"
}

# Configure Claude settings with hooks
setup_claude_settings() {
    local settings_dir="$PROJECT_DIR/.claude"
    local settings_file="$settings_dir/settings.json"
    local hook_path="$PROJECT_DIR/cli/log-activity.sh"

    # Ensure .claude directory exists
    mkdir -p "$settings_dir"

    # Make hook script executable
    if [ -f "$hook_path" ]; then
        chmod +x "$hook_path"
    else
        warn "Hook script not found: $hook_path"
    fi

    # Check if settings.json exists and has PostToolUse configured
    if [ -f "$settings_file" ]; then
        if command -v jq &> /dev/null; then
            # Check if PostToolUse hook for log-activity already exists
            local has_hook=$(jq -r '.hooks.PostToolUse // [] | map(select(.hooks[]?.command | contains("log-activity"))) | length' "$settings_file" 2>/dev/null)

            if [ "$has_hook" != "0" ] && [ "$FORCE" = false ]; then
                success "Claude hooks already configured"
                return 0
            fi

            # Backup and update settings
            cp "$settings_file" "$settings_file.backup.$(date +%Y%m%d_%H%M%S)"

            # Add/update PostToolUse hook
            local temp_file=$(mktemp)
            jq --arg hookPath "$hook_path" '
                .hooks.PostToolUse = (
                    (.hooks.PostToolUse // []) |
                    map(select(.hooks[]?.command | contains("log-activity") | not)) +
                    [{
                        "matcher": "Edit|Write",
                        "hooks": [{
                            "type": "command",
                            "command": $hookPath,
                            "timeout": 10
                        }]
                    }]
                )
            ' "$settings_file" > "$temp_file" 2>/dev/null

            if [ $? -eq 0 ]; then
                mv "$temp_file" "$settings_file"
                success "Updated Claude settings with PostToolUse hook"
            else
                rm -f "$temp_file"
                warn "Could not update settings.json with jq, using fallback"
                create_settings_file
            fi
        else
            # No jq, recreate file
            warn "jq not available, recreating settings.json"
            cp "$settings_file" "$settings_file.backup.$(date +%Y%m%d_%H%M%S)"
            create_settings_file
        fi
    else
        create_settings_file
    fi
}

# Create settings.json from scratch
create_settings_file() {
    local settings_file="$PROJECT_DIR/.claude/settings.json"
    local hook_path="$PROJECT_DIR/cli/log-activity.sh"
    local validation_hook="$PROJECT_DIR/.claude/hooks/validate-tiknix-php.py"

    # Check if validation hook exists
    local pre_tool_hooks=""
    if [ -f "$validation_hook" ]; then
        pre_tool_hooks=',
    "PreToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "python3 \"$CLAUDE_PROJECT_DIR\"/.claude/hooks/validate-tiknix-php.py",
            "timeout": 30
          }
        ]
      }
    ]'
    fi

    cat > "$settings_file" << EOF
{
  "hooks": {
    "PostToolUse": [
      {
        "matcher": "Edit|Write",
        "hooks": [
          {
            "type": "command",
            "command": "$hook_path",
            "timeout": 10
          }
        ]
      }
    ]$pre_tool_hooks
  }
}
EOF
    success "Created Claude settings.json with hooks"
}

# Verify baseurl is accessible
verify_baseurl() {
    local config_file="$PROJECT_DIR/conf/config.ini"

    if [ ! -f "$config_file" ]; then
        warn "Config file not found: $config_file"
        return 0
    fi

    local base_url=$(grep -E "^baseurl" "$config_file" 2>/dev/null | sed 's/.*=\s*"\?\([^"]*\)"\?/\1/' | tr -d ' ')

    if [ -z "$base_url" ]; then
        warn "No baseurl found in config"
        return 0
    fi

    # Check if baseurl uses localhost/0.0.0.0 which won't work for hooks
    if [[ "$base_url" =~ (localhost|127\.0\.0\.1|0\.0\.0\.0) ]]; then
        warn "baseurl ($base_url) uses localhost - hooks may not work in production"
        info "Consider updating to your production URL in conf/config.ini"
    else
        success "baseurl configured: $base_url"
    fi
}

# Main
main() {
    log ""
    log "${CYAN}Setting up Claude Code Hooks for Tiknix${NC}"
    log ""

    # Setup token
    setup_token || { error "Token setup failed"; exit 1; }

    # Setup Claude settings
    setup_claude_settings

    # Verify baseurl
    verify_baseurl

    log ""
    success "Hook setup complete!"
    log ""
}

main "$@"
