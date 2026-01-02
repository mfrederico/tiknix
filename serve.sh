#!/bin/bash
#
# Tiknix Development Server
# Run: ./serve.sh [OPTIONS]
#
# Options:
#   --port=PORT   Set server port (default: 8080)
#   --host=HOST   Set server host (default: localhost)
#   --open        Open browser after starting
#

HOST="localhost"
PORT="8080"
OPEN_BROWSER=false

for arg in "$@"; do
    case $arg in
        --port=*)
            PORT="${arg#*=}"
            ;;
        --host=*)
            HOST="${arg#*=}"
            ;;
        --open)
            OPEN_BROWSER=true
            ;;
        -h|--help)
            echo "Tiknix Development Server"
            echo ""
            echo "Usage: ./serve.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --port=PORT   Server port (default: 8080)"
            echo "  --host=HOST   Server host (default: localhost)"
            echo "  --open        Open browser after starting"
            exit 0
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Function to update MCP configuration when port changes
update_mcp_config() {
    local new_url="http://${HOST}:${PORT}/mcp/message"
    local claude_settings="$HOME/.claude/settings.json"

    # Update config.ini baseurl first
    local config_file="$SCRIPT_DIR/conf/config.ini"
    if [ -f "$config_file" ]; then
        local new_baseurl="http://${HOST}:${PORT}"
        sed -i.tmp "s|^baseurl[[:space:]]*=.*|baseurl = \"$new_baseurl\"|" "$config_file" 2>/dev/null
        rm -f "$config_file.tmp"
    fi

    # Check if claude CLI is available
    if ! command -v claude &>/dev/null; then
        return 0
    fi

    # Check if tiknix MCP server exists
    if ! claude mcp get tiknix &>/dev/null 2>&1; then
        return 0
    fi

    # Get current URL from claude mcp get
    local current_url=$(claude mcp get tiknix 2>/dev/null | grep -o 'http[s]*://[^"]*' | head -1)

    if [ "$current_url" != "$new_url" ] && [ -n "$current_url" ]; then
        echo "  Updating MCP URL: $new_url"

        # Get the API token - first try local .mcp_token file, then settings.json
        local api_token=""
        local token_file="$SCRIPT_DIR/.mcp_token"
        if [ -f "$token_file" ]; then
            api_token=$(cat "$token_file" 2>/dev/null | tr -d '\n')
        elif [ -f "$claude_settings" ]; then
            # Fallback: extract from settings.json
            api_token=$(grep -o 'Bearer tk_[^"]*' "$claude_settings" 2>/dev/null | head -1 | sed 's/Bearer //')
        fi

        if [ -n "$api_token" ]; then
            # Store the update command to show after server starts
            MCP_UPDATE_CMD="claude mcp remove tiknix && claude mcp add --transport http tiknix \"$new_url\" --header \"Authorization: Bearer $api_token\""
        else
            MCP_UPDATE_CMD="claude mcp remove tiknix && claude mcp add --transport http tiknix \"$new_url\" --header \"Authorization: Bearer YOUR_TOKEN\""
        fi
    fi
}

# Ensure hooks are configured
if [ -x "$SCRIPT_DIR/cli/setup-hooks.sh" ]; then
    "$SCRIPT_DIR/cli/setup-hooks.sh" --quiet
fi

# Write current MCP URL for hooks to use
echo "http://${HOST}:${PORT}" > "$SCRIPT_DIR/.mcp_url"

# Update MCP config before starting server
update_mcp_config

echo ""
echo "  Tiknix Development Server"
echo "  ========================="
echo ""
echo "  URL: http://${HOST}:${PORT}"
echo "  MCP: http://${HOST}:${PORT}/mcp/message"
echo ""

# Show MCP update command if port changed
if [ -n "$MCP_UPDATE_CMD" ]; then
    echo "  ⚠ MCP URL changed! Run this in another terminal:"
    echo ""
    echo "  $MCP_UPDATE_CMD"
    echo ""
fi

echo "  Press Ctrl+C to stop"
echo ""

# Open browser if requested
if [ "$OPEN_BROWSER" = true ]; then
    sleep 1 && (xdg-open "http://${HOST}:${PORT}" 2>/dev/null || open "http://${HOST}:${PORT}" 2>/dev/null) &
fi

php -S "${HOST}:${PORT}" -t "${SCRIPT_DIR}" "${SCRIPT_DIR}/server.php"
