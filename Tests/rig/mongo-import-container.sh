#!/usr/bin/env bash
# Runs inside the PHP container that Tests/rig/mongo-import.sh starts: the MongoDB import's tests
# (Tests/Import) twice, against the same MongoDB server, once for each kind of target database.
#
#   1. SQLite: the test rig in its SQLite mode (composer test:up), as CI's sqlite job does.
#   2. MySQL: a mysql:8.0 server on the container's network (its name is $MYSQL_HOST), set up as CI's
#      MySQL job does: Tests/build/config_mysql.ini as configuration/config.ini, the schema from
#      php bin/console idno:upgrade, the site served by `php -S localhost:80` with
#      Tests/build/router.php, PHPUnit run bare (with PHP deprecations excluded, as the rig does). The
#      test's target is then a private database of that server (PrivateDatabase::mysql()), made with
#      the server's root user, which may create databases.
#
# Each run must RUN the end-to-end test (Tests/rig/mongo-import-check.php): a run in which it skipped
# fails. The exit status is non-zero when either run failed.
set -uo pipefail

: "${MYSQL_HOST:?the name of the MySQL server}"
cd /app

overall=0
server_pid=''
stop_server() {
    if [ -n "$server_pid" ]; then
        kill "$server_pid" >/dev/null 2>&1 || true
        wait "$server_pid" >/dev/null 2>&1 || true
        server_pid=''
    fi
}
trap stop_server EXIT

echo
echo "=== MongoDB import, SQLite target ==="
if composer test:up; then
    status=0
    composer test -- Tests/Import --log-junit /tmp/import-sqlite.xml || status=$?
    composer test:down >/dev/null || true
    php Tests/rig/mongo-import-check.php /tmp/import-sqlite.xml SQLite || status=1
    [ "$status" -eq 0 ] || overall=1
else
    overall=1
fi

echo
echo "=== MongoDB import, MySQL target ==="
cp Tests/build/config_mysql.ini configuration/config.ini
sed -i "s/dbuser = 'travis'/dbuser = 'root'/" configuration/config.ini
sed -i "s/dbhost = 'localhost'/dbhost = '$MYSQL_HOST'/" configuration/config.ini
status=0
APP_ENV=test APP_DEBUG=1 php -d error_reporting=22527 bin/console idno:upgrade || status=1
if [ "$status" -eq 0 ]; then
    # PHP's built-in server on localhost:80 (the tests expect the site there; the container runs as root).
    APP_ENV=test APP_DEBUG=1 PHP_CLI_SERVER_WORKERS=8 php -d error_reporting=22527 -S localhost:80 -t /app /app/Tests/build/router.php >/tmp/php-server.log 2>&1 &
    server_pid=$!
    up=0
    for _ in $(seq 1 30); do
        if php -r 'exit(@fsockopen("localhost", 80) ? 0 : 1);'; then
            up=1
            break
        fi
        sleep 1
    done
    if [ "$up" -eq 1 ]; then
        php -d error_reporting=22527 vendor/bin/phpunit Tests/Import --log-junit /tmp/import-mysql.xml || status=$?
        php Tests/rig/mongo-import-check.php /tmp/import-mysql.xml MySQL || status=1
    else
        echo "The web server did not start:" >&2
        cat /tmp/php-server.log >&2
        status=1
    fi
    if [ "$status" -ne 0 ]; then
        tail -n 100 /tmp/php-server.log >&2 || true
    fi
    stop_server
fi
[ "$status" -eq 0 ] || overall=1

exit "$overall"
