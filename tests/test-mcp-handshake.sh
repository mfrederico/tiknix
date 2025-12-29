#!/bin/bash
#
# Test MCP Handshake - Simulates Claude Code's MCP initialization flow
#

BASE_URL="${MCP_URL:-http://localhost:9501}"
ENDPOINT="$BASE_URL/mcp/message"
AUTH_HEADER="Authorization: Bearer tk_0e7f70a9f74c3d03791a86cd069531b8b33c96546771fe7b4aa5c0981e51ecbc"

echo "============================================"
echo "MCP Handshake Test"
echo "Endpoint: $ENDPOINT"
echo "============================================"
echo ""

# Step 1: Initialize
echo "=== Step 1: Initialize ==="
INIT_RESPONSE=$(curl -s -D /tmp/mcp_headers.txt "$ENDPOINT" -X POST \
    -H "Content-Type: application/json" \
    -H "Accept: application/json, text/event-stream" \
    -H "$AUTH_HEADER" \
    -d '{
        "jsonrpc": "2.0",
        "id": 1,
        "method": "initialize",
        "params": {
            "protocolVersion": "2024-11-05",
            "capabilities": {
                "tools": {}
            },
            "clientInfo": {
                "name": "claude-code",
                "version": "1.0.0"
            }
        }
    }' 2>&1)

echo "Response:"
echo "$INIT_RESPONSE" | head -5
echo ""

# Extract session ID from headers
SESSION_ID=$(grep -i "mcp-session-id" /tmp/mcp_headers.txt | cut -d: -f2 | tr -d ' \r\n')
echo "Session ID: $SESSION_ID"
echo ""

# Step 2: Send initialized notification
echo "=== Step 2: Initialized Notification ==="
NOTIF_RESPONSE=$(curl -s "$ENDPOINT" -X POST \
    -H "Content-Type: application/json" \
    -H "Accept: application/json, text/event-stream" \
    -H "mcp-session-id: $SESSION_ID" \
    -H "$AUTH_HEADER" \
    -d '{
        "jsonrpc": "2.0",
        "method": "notifications/initialized"
    }' 2>&1)

echo "Response:"
echo "$NOTIF_RESPONSE" | head -3
echo ""

# Step 3: List tools
echo "=== Step 3: List Tools ==="
TOOLS_RESPONSE=$(curl -s "$ENDPOINT" -X POST \
    -H "Content-Type: application/json" \
    -H "Accept: application/json, text/event-stream" \
    -H "mcp-session-id: $SESSION_ID" \
    -H "$AUTH_HEADER" \
    -d '{
        "jsonrpc": "2.0",
        "id": 2,
        "method": "tools/list"
    }' 2>&1)

echo "Response (first 500 chars):"
echo "$TOOLS_RESPONSE" | head -c 500
echo ""
echo ""

# Count tools
TOOL_COUNT=$(echo "$TOOLS_RESPONSE" | grep -o '"name":' | wc -l)
echo "Tool count: $TOOL_COUNT"
echo ""

# Step 4: Call hello tool
echo "=== Step 4: Call hello Tool ==="
CALL_RESPONSE=$(curl -s "$ENDPOINT" -X POST \
    -H "Content-Type: application/json" \
    -H "Accept: application/json, text/event-stream" \
    -H "mcp-session-id: $SESSION_ID" \
    -H "$AUTH_HEADER" \
    -d '{
        "jsonrpc": "2.0",
        "id": 3,
        "method": "tools/call",
        "params": {
            "name": "hello",
            "arguments": {
                "name": "Claude"
            }
        }
    }' 2>&1)

echo "Response:"
echo "$CALL_RESPONSE"
echo ""

# Step 5: Call get_time tool
echo "=== Step 5: Call get_time Tool ==="
TIME_RESPONSE=$(curl -s "$ENDPOINT" -X POST \
    -H "Content-Type: application/json" \
    -H "Accept: application/json, text/event-stream" \
    -H "mcp-session-id: $SESSION_ID" \
    -H "$AUTH_HEADER" \
    -d '{
        "jsonrpc": "2.0",
        "id": 4,
        "method": "tools/call",
        "params": {
            "name": "get_time",
            "arguments": {}
        }
    }' 2>&1)

echo "Response:"
echo "$TIME_RESPONSE"
echo ""

# Step 6: Call add_numbers tool
echo "=== Step 6: Call add_numbers Tool ==="
ADD_RESPONSE=$(curl -s "$ENDPOINT" -X POST \
    -H "Content-Type: application/json" \
    -H "Accept: application/json, text/event-stream" \
    -H "mcp-session-id: $SESSION_ID" \
    -H "$AUTH_HEADER" \
    -d '{
        "jsonrpc": "2.0",
        "id": 5,
        "method": "tools/call",
        "params": {
            "name": "add_numbers",
            "arguments": {
                "a": 5,
                "b": 3
            }
        }
    }' 2>&1)

echo "Response:"
echo "$ADD_RESPONSE"
echo ""

echo "============================================"
echo "Handshake Test Complete"
echo "============================================"
