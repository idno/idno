#!/usr/bin/env bash
# Stop the local test rig started by up.sh, in whichever mode it runs, and delete its data: the
# SQLite database or the MySQL container and its data volume, and this checkout's directory under
# the system temp directory (see lib.sh). Safe to run when nothing is running, and it always completes its cleanup:
# when the MySQL container cannot be stopped (Docker absent or not running) it says so and carries
# on. configuration/config.ini is left as it is. The SQLite mode never runs docker.
set -euo pipefail

RIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$RIG_DIR/lib.sh"

MODE="$(rig_mode)"

# The workers up.sh recorded, signalled whether or not the server's own process is still there: when it has died (killed, or by
# PHP's hard time limit) they are handed to init and no pid file or parent finds them, and they keep the port. Only a recorded pid
# whose command line is still this rig's server is signalled.
if [ -f "$WORKERS_FILE" ]; then
    while read -r WORKER; do
        if rig_is_server_process "$WORKER"; then
            kill "$WORKER" 2>/dev/null || true
        fi
    done < "$WORKERS_FILE"
fi

# Signal only this rig's web server: a pid file left by a server that died may name another process.
if [ -f "$PID_FILE" ]; then
    SERVER_PID="$(rig_server_pid)"
    if [ -n "$SERVER_PID" ]; then
        # The server runs several workers (PHP_CLI_SERVER_WORKERS, see up.sh), which are its children
        # and outlive it, holding the port: stop them first.
        pkill -P "$SERVER_PID" 2>/dev/null || true
        kill "$SERVER_PID" 2>/dev/null || true
    else
        echo "Tests/rig/.server.pid does not name this rig's web server (pid $(cat "$PID_FILE")); not signalling it."
    fi
    rm -f "$PID_FILE"
fi

case "$MODE" in
    mysql)
        # -v: the data directory (the image's anonymous volume) goes too, so the next start, on
        # whichever image, begins with an empty one.
        if ! command -v docker > /dev/null 2>&1 || ! docker compose -f "$RIG_DIR/docker-compose.yml" down -v; then
            echo "warning: could not stop the test rig's MySQL container (docker compose down failed); cleaning up the rest." >&2
        fi
        ;;
    sqlite)
        ;;
    *)
        # Unknown mode (no .mode file and no rig-written config): stop the MySQL container if
        # there is one, as down.sh always did, but do not fail when Docker is absent or unreachable.
        if command -v docker > /dev/null 2>&1; then
            docker compose -f "$RIG_DIR/docker-compose.yml" down -v 2>/dev/null || true
        fi
        ;;
esac

rm -rf "$STATE_DIR"
rm -f "$MODE_FILE"
echo "Test rig down."
