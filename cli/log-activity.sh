#!/bin/bash
#
# Claude Code Hook - Log file activity to Tiknix task
#
# This hook is called by Claude Code after Edit/Write operations.
# It reads the tool input from stdin (JSON) and logs to the active task.
#
# Hook Input Format (JSON via stdin):
# {
#   "session_id": "...",
#   "hook_event_name": "PostToolUse",
#   "tool_name": "Write",
#   "tool_input": { "file_path": "/path/to/file" },
#   "tool_response": { "success": true },
#   ...
# }
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Read hook input from stdin (JSON with tool info)
HOOK_INPUT=$(cat)

# Parse JSON - prefer jq if available, fallback to grep
if command -v jq &> /dev/null; then
    TOOL_NAME=$(echo "$HOOK_INPUT" | jq -r '.tool_name // empty' 2>/dev/null)
    FILE_PATH=$(echo "$HOOK_INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
    SUCCESS=$(echo "$HOOK_INPUT" | jq -r '.tool_response.success // empty' 2>/dev/null)
else
    # Fallback grep parsing for systems without jq
    TOOL_NAME=$(echo "$HOOK_INPUT" | grep -o '"tool_name"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed 's/.*"\([^"]*\)"$/\1/')
    FILE_PATH=$(echo "$HOOK_INPUT" | grep -o '"file_path"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed 's/.*"\([^"]*\)"$/\1/')
    SUCCESS="true"  # Assume success if we can't parse it
fi

# Only proceed if we have a file path
if [ -z "$FILE_PATH" ]; then
    exit 0
fi

# Get current task ID from environment (set by ClaudeRunner)
TASK_ID="${TIKNIX_TASK_ID:-}"

# If no task ID from environment, try to get from the work directory name
if [ -z "$TASK_ID" ]; then
    WORK_DIR=$(pwd)
    if [[ "$WORK_DIR" =~ tiknix-.*-task-([0-9]+) ]]; then
        TASK_ID="${BASH_REMATCH[1]}"
    fi
fi

# Only log if we have a task ID
if [ -z "$TASK_ID" ]; then
    exit 0
fi

# Get API token
TOKEN_FILE="$PROJECT_DIR/.mcp_token"
if [ ! -f "$TOKEN_FILE" ]; then
    exit 0
fi

API_TOKEN=$(cat "$TOKEN_FILE" 2>/dev/null | tr -d '\n')
if [ -z "$API_TOKEN" ]; then
    exit 0
fi

# Get MCP URL - check .mcp_url file first (written by serve.sh), fallback to localhost:8080
# Using localhost avoids nginx proxy issues with Authorization headers
MCP_URL_FILE="$PROJECT_DIR/.mcp_url"
if [ -f "$MCP_URL_FILE" ]; then
    BASE_URL=$(cat "$MCP_URL_FILE" | tr -d '\n\r')
else
    BASE_URL="http://localhost:8080"
fi

# Make the file path relative to project for cleaner logging
RELATIVE_PATH="${FILE_PATH#$PROJECT_DIR/}"

# Determine message based on success
if [ "$SUCCESS" = "false" ]; then
    LOG_MESSAGE="${TOOL_NAME:-Edit} (failed): ${RELATIVE_PATH}"
    LOG_LEVEL="warning"
else
    LOG_MESSAGE="${TOOL_NAME:-Edit}: ${RELATIVE_PATH}"
    LOG_LEVEL="info"
fi

# Log the activity via MCP
curl -s -X POST "${BASE_URL}/mcp/message" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $API_TOKEN" \
    -d "{
        \"jsonrpc\": \"2.0\",
        \"id\": \"hook-$(date +%s%N)\",
        \"method\": \"tools/call\",
        \"params\": {
            \"name\": \"add_task_log\",
            \"arguments\": {
                \"task_id\": $TASK_ID,
                \"level\": \"$LOG_LEVEL\",
                \"type\": \"file_change\",
                \"message\": \"$LOG_MESSAGE\"
            }
        }
    }" > /dev/null 2>&1

# Always exit 0 so we don't block Claude
exit 0
