#!/usr/bin/env bash
# Start the local test rig: MySQL in Docker plus PHP's built-in web server.
set -euo pipefail

RIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$RIG_DIR/../.." && pwd)"
PID_FILE="$RIG_DIR/.server.pid"
HOST=127.0.0.1
PORT=8089

cd "$ROOT"

# Configuration first, so we fail before starting anything if the developer's config differs.
UPLOADPATH="$(php -r 'echo rtrim(sys_get_temp_dir(), "/") . "/idno-test-uploads/";')"
mkdir -p "$UPLOADPATH"
EXPECTED="$(sed "s#__UPLOADPATH__#$UPLOADPATH#" "$RIG_DIR/config.ini")"
mkdir -p configuration
if [ ! -e configuration/config.ini ]; then
    printf '%s\n' "$EXPECTED" > configuration/config.ini
    echo "Wrote configuration/config.ini for the test rig."
elif [ "$(cat configuration/config.ini)" != "$EXPECTED" ]; then
    echo "configuration/config.ini already exists and differs from Tests/rig/config.ini." >&2
    echo "Not overwriting it. Move it aside (or compare the two) and run again." >&2
    exit 1
fi

docker compose -f "$RIG_DIR/docker-compose.yml" up -d --wait

# Load the schema only into an empty database, so re-running does not wipe test data.
DB="docker compose -f $RIG_DIR/docker-compose.yml exec -T db mysql -uroot"
if [ -z "$($DB -N -B known_unittest -e 'SHOW TABLES' 2>/dev/null)" ]; then
    $DB known_unittest < warmup/schemas/mysql/mysql.sql
    echo "Loaded MySQL schema."
fi

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    echo "Web server already running (pid $(cat "$PID_FILE"))."
else
    # error_reporting mirrors a stock production php.ini (what CI's Apache uses). With
    # pedantic_mode on, any reported deprecation (e.g. dynamic properties) is thrown as an exception.
    nohup php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_STRICT' -S "$HOST:$PORT" "$RIG_DIR/router.php" > "$RIG_DIR/.server.log" 2>&1 &
    echo $! > "$PID_FILE"
    for _ in $(seq 1 30); do
        curl -s -o /dev/null "http://$HOST:$PORT/" && break
        sleep 0.5
    done
fi

echo "Test rig up: http://$HOST:$PORT/ (MySQL on 127.0.0.1:33306, database known_unittest)"
echo "Run tests with: composer test"
