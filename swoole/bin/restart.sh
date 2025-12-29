#!/bin/bash
#
# Tiknix OpenSwoole Server Restart Script
# Usage: ./swoole/bin/restart.sh
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$(dirname "$SCRIPT_DIR")")"
LOG_FILE="/tmp/tiknix-server.log"
PID_FILE="$PROJECT_DIR/log/unified-server.pid"
PORT=9501

cd "$PROJECT_DIR" || exit 1

echo "=== Tiknix Server Restart ==="
echo "Project: $PROJECT_DIR"
echo "Port: $PORT"

# Function to kill existing processes
kill_server() {
    echo "Stopping existing server..."

    # Try graceful shutdown first via PID file
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            echo "Sending SIGTERM to PID $PID..."
            kill "$PID" 2>/dev/null
            sleep 2
        fi
    fi

    # Force kill any remaining processes on the port
    PIDS=$(lsof -ti :$PORT 2>/dev/null)
    if [ -n "$PIDS" ]; then
        echo "Force killing processes on port $PORT: $PIDS"
        echo "$PIDS" | xargs kill -9 2>/dev/null
        sleep 1
    fi

    # Also kill by process name as fallback
    pkill -9 -f "unified-server.php" 2>/dev/null
    sleep 1
}

# Function to check if port is free
check_port() {
    if lsof -ti :$PORT >/dev/null 2>&1; then
        echo "ERROR: Port $PORT is still in use!"
        return 1
    fi
    echo "Port $PORT is free"
    return 0
}

# Function to start server
start_server() {
    echo "Starting server..."
    php "$SCRIPT_DIR/unified-server.php" > "$LOG_FILE" 2>&1 &

    # Wait for server to start
    sleep 3

    # Check if server started successfully
    if lsof -ti :$PORT >/dev/null 2>&1; then
        echo "Server started successfully!"
        echo "Log file: $LOG_FILE"
        tail -10 "$LOG_FILE"
        return 0
    else
        echo "ERROR: Server failed to start!"
        echo "Last log entries:"
        tail -20 "$LOG_FILE"
        return 1
    fi
}

# Function to clear caches
clear_caches() {
    echo "Clearing permission cache..."
    CACHE_FILE="$PROJECT_DIR/cache/.permission_cache_version"
    echo "$(date +%s)" > "$CACHE_FILE"
    echo "Cache version updated: $(cat "$CACHE_FILE")"
}

# Main execution
case "${1:-restart}" in
    stop)
        kill_server
        check_port
        ;;
    start)
        if ! check_port; then
            echo "Use '$0 restart' to force restart"
            exit 1
        fi
        start_server
        ;;
    restart)
        kill_server
        if ! check_port; then
            echo "Waiting for port to free..."
            sleep 2
            if ! check_port; then
                echo "Failed to free port. Aborting."
                exit 1
            fi
        fi
        clear_caches
        start_server
        ;;
    status)
        if lsof -ti :$PORT >/dev/null 2>&1; then
            echo "Server is running on port $PORT"
            echo "PIDs: $(lsof -ti :$PORT | tr '\n' ' ')"
            if [ -f "$PID_FILE" ]; then
                echo "Master PID: $(cat "$PID_FILE")"
            fi
        else
            echo "Server is not running"
        fi
        ;;
    logs)
        tail -f "$LOG_FILE"
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status|logs}"
        exit 1
        ;;
esac
