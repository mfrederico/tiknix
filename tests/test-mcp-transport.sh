#!/bin/bash
#
# Test MCP Transport Detection
# Tests that the MCP server correctly responds with SSE or JSON based on Accept header
#

BASE_URL="${MCP_URL:-http://localhost:9501}"
ENDPOINT="$BASE_URL/mcp/message"
PASSED=0
FAILED=0

echo "============================================"
echo "MCP Transport Detection Tests"
echo "Endpoint: $ENDPOINT"
echo "============================================"
echo ""

# Test helper - simplified
run_test() {
    local name="$1"
    local accept="$2"
    local expect="$3"
    local method="$4"

    printf "%-50s " "$name"

    if [ -n "$accept" ]; then
        resp=$(curl -s --max-time 5 "$ENDPOINT" -X POST \
            -H "Content-Type: application/json" \
            -H "Accept: $accept" \
            -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$method\"}" 2>&1)
    else
        resp=$(curl -s --max-time 5 "$ENDPOINT" -X POST \
            -H "Content-Type: application/json" \
            -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$method\"}" 2>&1)
    fi

    if [[ "$resp" == "$expect"* ]]; then
        echo "PASS"
        PASSED=$((PASSED + 1))
    else
        echo "FAIL"
        echo "  Expected: ${expect:0:50}..."
        echo "  Got:      ${resp:0:50}..."
        FAILED=$((FAILED + 1))
    fi
}

# Test header presence
test_header() {
    local name="$1"
    local accept="$2"
    local header="$3"

    printf "%-50s " "$name"

    if [ -n "$accept" ]; then
        headers=$(curl -s --max-time 5 -D - -o /dev/null "$ENDPOINT" -X POST \
            -H "Content-Type: application/json" \
            -H "Accept: $accept" \
            -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05"}}' 2>&1)
    else
        headers=$(curl -s --max-time 5 -D - -o /dev/null "$ENDPOINT" -X POST \
            -H "Content-Type: application/json" \
            -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05"}}' 2>&1)
    fi

    if echo "$headers" | grep -qi "$header"; then
        echo "PASS"
        PASSED=$((PASSED + 1))
    else
        echo "FAIL"
        echo "  Header '$header' not found"
        FAILED=$((FAILED + 1))
    fi
}

echo "--- Response Format Tests ---"
run_test "SSE format (no Accept header)" "" "event: message" "initialize"
run_test "SSE format (Accept: text/event-stream)" "text/event-stream" "event: message" "initialize"
run_test "JSON format (Accept: application/json)" "application/json" "{" "initialize"
run_test "JSON format (both Accept types - Claude Code)" "application/json, text/event-stream" "{" "initialize"
run_test "JSON tools/list" "application/json" "{\"result\":{\"tools\":" "tools/list"
run_test "SSE tools/list" "" "event: message" "tools/list"

echo ""
echo "--- Content-Type Header Tests ---"
test_header "SSE returns text/event-stream" "" "content-type: text/event-stream"
test_header "JSON returns application/json" "application/json" "content-type: application/json"

echo ""
echo "--- Session Header Tests ---"
test_header "mcp-session-id header present" "application/json" "mcp-session-id:"

echo ""
echo "============================================"
echo "Results: $PASSED passed, $FAILED failed"
echo "============================================"

[ $FAILED -eq 0 ]
