#!/usr/bin/env bash
# Stop the local test rig started by up.sh.
set -euo pipefail

RIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="$RIG_DIR/.server.pid"

if [ -f "$PID_FILE" ]; then
    kill "$(cat "$PID_FILE")" 2>/dev/null || true
    rm -f "$PID_FILE"
fi

docker compose -f "$RIG_DIR/docker-compose.yml" down
