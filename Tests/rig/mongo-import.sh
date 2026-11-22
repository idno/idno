#!/usr/bin/env bash
# Runs the MongoDB import's tests against a real MongoDB server (composer test:mongo-import).
#
#   mongo-import.sh            the end-to-end test, Tests/Import (MongoImportEndToEndTest), twice:
#                              the import into an SQLite database and into a MySQL one
#
# Everything runs in Docker: a mongo:6 container and a mysql:8.0 container (no port published on the
# host: the three containers share a network of their own) and a PHP 8.4 container with the mongodb
# extension, built from Tests/rig/mongo-import.Dockerfile around a COPY of this checkout, never the
# checkout itself. The PHP container runs Tests/rig/mongo-import-container.sh: first on the test rig
# in its SQLite mode (composer test:up), then on the MySQL server set up as CI's MySQL job sets its
# database up (the container has no Docker, so the rig's MySQL mode cannot be used). Both write the
# copy's own configuration/config.ini. A run in which the end-to-end test was skipped fails. The copy leaves out .git, vendor/, var/cache, the whole configuration/
# directory, .env and SQLite database files, so no local configuration or secret is ever baked into
# the image. Whatever the outcome, the containers (with their volumes) and the network this script
# started are removed. The image (idno-mongo-import:local) is kept as a build cache; when a build
# replaces it, the previous image is removed, so no dangling image is left behind.
#
# The container has the mongodb extension and nothing else of MongoDB's: the import command needs
# the extension only (no mongodb/mongodb library is installed).
set -euo pipefail

RIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$RIG_DIR/../.." && pwd -P)"
if [ "$#" -ne 0 ]; then
    echo "Usage: $0" >&2
    exit 2
fi

IMAGE=idno-mongo-import:local
MONGO_IMAGE=mongo:6
NAME="idno-mongo-import-$$-$RANDOM"
NETWORK="$NAME"
MONGO="$NAME-mongo"
MYSQL="$NAME-mysql"
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
    docker rm -f -v "$PHP_CONTAINER" "$MONGO" "$MYSQL" >/dev/null 2>&1 || true
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

echo "Copying the checkout..."
mkdir -p "$STAGE/app" "$STAGE/out"
tar -C "$ROOT" \
    --exclude=./.git --exclude=./vendor --exclude=./var/cache --exclude=./configuration --exclude=./.env \
    --exclude='./data/*.sqlite*' --exclude=./Tests/rig/.mode --exclude=./Tests/rig/.server.pid \
    --exclude=./Tests/rig/.server.log --exclude=./node_modules --exclude=./frontend/node_modules \
    --exclude=./.superpowers --exclude=./.claude \
    -cf - . | tar -C "$STAGE/app" -xf -

echo "Building $IMAGE..."
PREVIOUS="$(docker image inspect -f '{{.Id}}' "$IMAGE" 2>/dev/null || true)"
docker build --quiet -t "$IMAGE" -f "$RIG_DIR/mongo-import.Dockerfile" "$STAGE" >/dev/null
CURRENT="$(docker image inspect -f '{{.Id}}' "$IMAGE")"
if [ -n "$PREVIOUS" ] && [ "$PREVIOUS" != "$CURRENT" ]; then
    # The image this build replaced (now untagged); its layers shared with the new one are kept.
    docker image rm "$PREVIOUS" >/dev/null 2>&1 || true
fi

# A container, waited for in the background so that a signal reaches the trap at once.
run() {
    docker run "$@" &
    wait "$!"
}

docker network create "$NETWORK" >/dev/null
docker run -d --name "$MONGO" --network "$NETWORK" --network-alias mongo \
    -e MONGO_INITDB_ROOT_USERNAME=root -e MONGO_INITDB_ROOT_PASSWORD="$MONGO_PASSWORD" "$MONGO_IMAGE" >/dev/null
echo "Waiting for MongoDB..."
for _ in $(seq 1 60); do
    if docker exec "$MONGO" mongosh --quiet -u root -p "$MONGO_PASSWORD" --eval 'db.runCommand({ping: 1}).ok' >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker exec "$MONGO" mongosh --quiet -u root -p "$MONGO_PASSWORD" --eval 'db.runCommand({ping: 1}).ok' >/dev/null

# The MySQL server of the second run: no password (as CI's service), the database CI's job names. The
# test makes its own databases beside it with the root user, which may create them.
docker run -d --name "$MYSQL" --network "$NETWORK" --network-alias mysql \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=known_unittest mysql:8.0 >/dev/null
echo "Waiting for MySQL..."
for _ in $(seq 1 90); do
    # Over TCP: while the image initialises it runs a server that listens on its socket only.
    if docker exec "$MYSQL" mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker exec "$MYSQL" mysqladmin ping -h 127.0.0.1 --silent >/dev/null

run --rm --name "$PHP_CONTAINER" --network "$NETWORK" -e IDNO_TEST_MONGO_URL="$MONGO_URL" -e MYSQL_HOST=mysql \
    "$IMAGE" bash Tests/rig/mongo-import-container.sh
