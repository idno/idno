#!/usr/bin/env bash
# Start the local test rig: a database plus PHP's built-in web server on http://127.0.0.1:8089/.
#
#   up.sh [sqlite]   SQLite (composer test:up): needs nothing but PHP. The database file and the
#                    upload directory are in a directory under the system temp directory that is
#                    specific to this checkout (see lib.sh).
#   up.sh mysql      MySQL 8 in Docker on 127.0.0.1:33306 (composer test:up:mysql).
#                    IDNO_RIG_MYSQL_IMAGE selects another image of MySQL or MariaDB (default
#                    mysql:8.0), e.g. IDNO_RIG_MYSQL_IMAGE=mariadb:10.6 composer test:up:mysql.
#                    Starting it on another image than the running one stops the running one
#                    first (down.sh, which deletes its data), so each image starts empty.
#
# Starting one mode while the other is running stops the other first (down.sh, which wipes its
# database). A configuration/config.ini the rig did not write is never replaced.
set -euo pipefail

RIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$RIG_DIR/lib.sh"
HOST=127.0.0.1
# The web server's port: 8089 (the rig's config says so). IDNO_RIG_PORT is for Tests/Kernel/RigScriptsTest only.
PORT="${IDNO_RIG_PORT:-8089}"

MODE="${1:-sqlite}"
case "$MODE" in
    sqlite|mysql) ;;
    *) echo "Usage: $0 [sqlite|mysql]" >&2; exit 2 ;;
esac

cd "$ROOT"

# Configuration first, so we fail before starting or stopping anything if the developer's config is there.
"$RIG_DIR/write-config.sh" "$MODE" --check

# Something may be running when up.sh left a mode (down.sh removes it) or a web server pid behind.
RUNNING=""
if [ -f "$MODE_FILE" ] || [ -f "$PID_FILE" ]; then
    RUNNING="$(rig_mode)"
fi
if [ -n "$RUNNING" ] && [ "$RUNNING" != "$MODE" ]; then
    echo "The test rig was last started in $RUNNING mode: stopping it first."
    "$RIG_DIR/down.sh"
elif [ "$RUNNING" = mysql ] && [ "$MODE" = mysql ] && [ "$(rig_mysql_image)" != "$MYSQL_IMAGE" ]; then
    echo "The test rig's database runs $(rig_mysql_image), not $MYSQL_IMAGE: stopping it first."
    "$RIG_DIR/down.sh"
fi

mkdir -p "$STATE_DIR/uploads"
"$RIG_DIR/write-config.sh" "$MODE"

# The mode file is written only once the database is up, so a start that fails (Docker not running)
# leaves nothing that a later start of the other mode would have to stop.
if [ "$MODE" = mysql ]; then
    # --renew-anon-volumes: a container that must be recreated (another image) gets an empty data
    # directory, never the previous image's.
    IDNO_RIG_MYSQL_IMAGE="$MYSQL_IMAGE" docker compose -f "$RIG_DIR/docker-compose.yml" up -d --wait --renew-anon-volumes
    echo "$MYSQL_IMAGE" > "$MYSQL_IMAGE_FILE"
    echo "$MODE" > "$MODE_FILE"

    # The image's command-line client: mariadb in MariaDB's images (which drop the mysql name in
    # 11), mysql in MySQL's.
    db_client() {
        IDNO_RIG_MYSQL_IMAGE="$MYSQL_IMAGE" docker compose -f "$RIG_DIR/docker-compose.yml" exec -T db \
            sh -c 'exec "$(command -v mariadb || command -v mysql)" -uroot "$@"' db-client "$@"
    }
    # Load the schema only into an empty database, so re-running does not wipe test data.
    if [ -z "$(db_client -N -B known_unittest -e 'SHOW TABLES' 2>/dev/null)" ]; then
        db_client known_unittest < warmup/schemas/mysql/mysql.sql
        echo "Loaded MySQL schema."
    fi
    DATABASE="$MYSQL_IMAGE on 127.0.0.1:33306, database known_unittest"
else
    # Loads the schema only into a database without tables, so re-running does not wipe test data.
    php -d error_reporting=22527 "$RIG_DIR/sqlite_schema.php" "$STATE_DIR/site.sqlite"
    echo "$MODE" > "$MODE_FILE"
    DATABASE="SQLite, $STATE_DIR/site.sqlite"
fi

if [ -n "$(rig_server_pid)" ]; then
    echo "Web server already running (pid $(rig_server_pid))."
else
    # error_reporting mirrors a stock production php.ini (what CI's Apache uses). With
    # pedantic_mode on, any reported deprecation (e.g. dynamic properties) is thrown as an exception.
    # APP_ENV/APP_DEBUG run the kernel in test/debug mode, so a changed service definition
    # is picked up without clearing the compiled container by hand.
    rm -f "$PID_FILE"
    if nc -z "$HOST" "$PORT" 2>/dev/null; then
        echo "Port $PORT on $HOST is already in use by another process; not starting the web server." >&2
        exit 1
    fi
    APP_ENV=test APP_DEBUG=1 nohup php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_STRICT' -S "$HOST:$PORT" "$RIG_DIR/router.php" > "$RIG_DIR/.server.log" 2>&1 &
    echo $! > "$PID_FILE"
    started=0
    for _ in $(seq 1 30); do
        kill -0 "$(cat "$PID_FILE")" 2>/dev/null || break
        if curl -s -o /dev/null "http://$HOST:$PORT/"; then
            started=1
            break
        fi
        sleep 0.5
    done
    if [ "$started" != 1 ] || ! kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
        echo "The web server failed to start on http://$HOST:$PORT/. Last lines of Tests/rig/.server.log:" >&2
        tail -n 20 "$RIG_DIR/.server.log" >&2 || true
        kill "$(cat "$PID_FILE")" 2>/dev/null || true
        rm -f "$PID_FILE"
        exit 1
    fi
fi

echo "Test rig up ($MODE): http://$HOST:$PORT/ ($DATABASE)"
echo "Run tests with: composer test"
