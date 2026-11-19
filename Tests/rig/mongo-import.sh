#!/usr/bin/env bash
# Runs the MongoDB import's tests against a real MongoDB server (composer test:mongo-import).
#
#   mongo-import.sh            the end-to-end test, Tests/Import (MongoImportEndToEndTest)
#   mongo-import.sh capture    recaptures Tests/Import/fixtures/mongo-site.ndjson and expected.php
#                              from the old MongoDB backend (Tests/Import/fixtures/capture.php)
#
# Everything runs in Docker: a mongo:6 container (published on 127.0.0.1:27018) and a PHP 8.4
# container with the mongodb extension, built from Tests/rig/mongo-import.Dockerfile around a COPY of
# this checkout (without .git, vendor/, var/cache, configuration/config.ini and SQLite database
# files), never the checkout itself: the test rig started inside the container (composer test:up, on
# SQLite) writes the copy's configuration/config.ini, not this checkout's. Both containers share a
# network of their own. Whatever the outcome, the containers (with their volumes) and the network
# this script started are removed; the image (idno-mongo-import:local) is kept as a build cache.
#
# The container also installs the mongodb/mongodb library into its copy (never into this checkout's
# composer.json): the capture uses the old backend, which needs it, and until the old backend is
# removed a process configured for MongoDB (the import command's subprocess in the test) boots it.
# Once the old backend is gone, the library step below can go too; the import command itself needs
# the extension only.
set -euo pipefail

RIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$RIG_DIR/../.." && pwd -P)"
MODE="${1:-test}"
case "$MODE" in
    test|capture) ;;
    *) echo "Usage: $0 [test|capture]" >&2; exit 2 ;;
esac

IMAGE=idno-mongo-import:local
MONGO_IMAGE=mongo:6
MONGO_PORT=27018
NAME="idno-mongo-import-$$-$RANDOM"
NETWORK="$NAME"
MONGO="$NAME-mongo"
PHP_CONTAINER="$NAME-php"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/idno-mongo-import.XXXXXX")"
# The MongoDB server requires a password (a throwaway one), so that the tests can check that the
# import never prints it.
MONGO_PASSWORD="import-$RANDOM$RANDOM$RANDOM"
MONGO_URL="mongodb://root:$MONGO_PASSWORD@mongo:27017/?authSource=admin"

# Removes what this run started: its two containers (with their anonymous volumes) and its network,
# all named after this run, so nothing else is touched. It runs on every exit, an interrupted one
# included (the containers run in the background, below, so that a signal is handled at once).
cleanup() {
    local status=$?
    docker rm -f -v "$PHP_CONTAINER" "$MONGO" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    if [ -n "$(docker ps -aq --filter "name=^$NAME-")" ] || [ -n "$(docker network ls -q --filter "name=^$NETWORK\$")" ]; then
        echo "Warning: could not remove everything named $NAME (docker ps -a, docker network ls)." >&2
    fi
    rm -rf "$STAGE"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

if ! docker info >/dev/null 2>&1; then
    echo "Docker is not running: the MongoDB import's tests need it." >&2
    exit 1
fi
if (exec 3<>"/dev/tcp/127.0.0.1/$MONGO_PORT") 2>/dev/null; then
    echo "Something already listens on 127.0.0.1:$MONGO_PORT: stop it, or it is a MongoDB this script did not start." >&2
    exit 1
fi

echo "Copying the checkout..."
mkdir -p "$STAGE/app" "$STAGE/out"
tar -C "$ROOT" \
    --exclude=./.git --exclude=./vendor --exclude=./var/cache --exclude=./configuration/config.ini \
    --exclude='./data/*.sqlite*' --exclude=./Tests/rig/.mode --exclude=./Tests/rig/.server.pid \
    --exclude=./Tests/rig/.server.log --exclude=./node_modules --exclude=./Themes/Twenty26/node_modules \
    --exclude=./.superpowers --exclude=./.claude \
    -cf - . | tar -C "$STAGE/app" -xf -

echo "Building $IMAGE..."
docker build --quiet -t "$IMAGE" -f "$RIG_DIR/mongo-import.Dockerfile" "$STAGE" >/dev/null

# A container, waited for in the background so that a signal reaches the trap at once.
run() {
    docker run "$@" &
    wait "$!"
}

docker network create "$NETWORK" >/dev/null
docker run -d --name "$MONGO" --network "$NETWORK" --network-alias mongo -p "127.0.0.1:$MONGO_PORT:27017" \
    -e MONGO_INITDB_ROOT_USERNAME=root -e MONGO_INITDB_ROOT_PASSWORD="$MONGO_PASSWORD" "$MONGO_IMAGE" >/dev/null
echo "Waiting for MongoDB..."
for _ in $(seq 1 60); do
    if docker exec "$MONGO" mongosh --quiet -u root -p "$MONGO_PASSWORD" --eval 'db.runCommand({ping: 1}).ok' >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker exec "$MONGO" mongosh --quiet -u root -p "$MONGO_PASSWORD" --eval 'db.runCommand({ping: 1}).ok' >/dev/null

# The library for the old backend, in the container's copy only (see above).
LIBRARY='composer require mongodb/mongodb --no-interaction --no-progress --quiet -W'

if [ "$MODE" = capture ]; then
    run --rm --name "$PHP_CONTAINER" --network "$NETWORK" -v "$STAGE/out:/out" "$IMAGE" bash -euc "
        $LIBRARY
        mkdir -p configuration
        cp Tests/build/config_default.ini configuration/config.ini
        php -d error_reporting=22527 Tests/Import/fixtures/capture.php '$MONGO_URL' /out
    "
    cp "$STAGE/out/mongo-site.ndjson" "$STAGE/out/expected.php" "$ROOT/Tests/Import/fixtures/"
    echo "Captured Tests/Import/fixtures/mongo-site.ndjson and expected.php."
    exit 0
fi

run --rm --name "$PHP_CONTAINER" --network "$NETWORK" -e IDNO_TEST_MONGO_URL="$MONGO_URL" "$IMAGE" bash -euc "
    $LIBRARY
    composer test:up
    status=0
    composer test -- Tests/Import || status=\$?
    composer test:down >/dev/null || true
    exit \$status
"
