#!/bin/bash
#
# Tiknix Development Server
# Run: ./serve.sh [OPTIONS]
#
# Options:
#   --port=PORT   Set server port (default: 8000)
#   --host=HOST   Set server host (default: localhost)
#   --open        Open browser after starting
#

HOST="localhost"
PORT="8000"
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
            echo "  --port=PORT   Server port (default: 8000)"
            echo "  --host=HOST   Server host (default: localhost)"
            echo "  --open        Open browser after starting"
            exit 0
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo ""
echo "  Tiknix Development Server"
echo "  ========================="
echo ""
echo "  URL: http://${HOST}:${PORT}"
echo ""
echo "  Press Ctrl+C to stop"
echo ""

# Open browser if requested
if [ "$OPEN_BROWSER" = true ]; then
    sleep 1 && (xdg-open "http://${HOST}:${PORT}" 2>/dev/null || open "http://${HOST}:${PORT}" 2>/dev/null) &
fi

php -S "${HOST}:${PORT}" -t "${SCRIPT_DIR}" "${SCRIPT_DIR}/server.php"
