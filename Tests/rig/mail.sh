#!/usr/bin/env bash
# Runs the mail delivery test against real mail servers (composer test:mail).
#
#   mail.sh            Tests/Mail/MailpitDeliveryTest: the four mails of the site delivered to Mailpit over
#                      a plain connection, STARTTLS on 587 and implicit TLS on 465, each with a login, and
#                      read back through Mailpit's API
#
# Everything runs in Docker: three axllent/mailpit containers (one SMTP listener each: Mailpit has one) and
# a PHP 8.4 container, built from Tests/rig/mail.Dockerfile around a COPY of this checkout, never the
# checkout itself. No port is published on the host: the containers share a network of their own. The
# certificate authority and the servers' certificate are made in a throwaway PHP container (with
# Tests\Fixtures\Smtp\TestPki) and copied into the Mailpit containers, which never leave the network. The
# PHP container runs Tests/rig/mail-container.sh on the test rig in its SQLite mode. A run in which the
# test was skipped fails. The copy leaves out .git, vendor/, var/cache, the whole configuration/ directory,
# .env and SQLite database files, so no local configuration or secret is baked into the image. Whatever
# the outcome, the containers (with their volumes) and the network this script started are removed. The
# image (idno-mail:local) is kept as a build cache; when a build replaces it, the previous image is removed.
set -euo pipefail

RIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$RIG_DIR/../.." && pwd -P)"
if [ "$#" -ne 0 ]; then
    echo "Usage: $0" >&2
    exit 2
fi

IMAGE=idno-mail:local
MAILPIT_IMAGE=axllent/mailpit:v1.27.10
NAME="idno-mail-$$-$RANDOM"
NETWORK="$NAME"
PHP_CONTAINER="$NAME-php"
SERVERS=(plain starttls tls)
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/idno-mail.XXXXXX")"

# Removes what this run started: its containers (with their anonymous volumes) and its network, all named
# after this run, so nothing else is touched. It runs on every exit, an interrupted one included.
cleanup() {
    local status=$?
    local names=("$PHP_CONTAINER" "$NAME-certs")
    for kind in "${SERVERS[@]}"; do names+=("$NAME-$kind"); done
    docker rm -f -v "${names[@]}" >/dev/null 2>&1 || true
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
    echo "Docker is not running: the mail delivery test needs it." >&2
    exit 1
fi

echo "Copying the checkout..."
mkdir -p "$STAGE/app"
tar -C "$ROOT" \
    --exclude=./.git --exclude=./vendor --exclude=./var/cache --exclude=./configuration --exclude=./.env \
    --exclude='./data/*.sqlite*' --exclude=./Tests/rig/.mode --exclude=./Tests/rig/.server.pid \
    --exclude=./Tests/rig/.server.log --exclude=./node_modules --exclude=./frontend/node_modules \
    -cf - . | tar -C "$STAGE/app" -xf -

echo "Building $IMAGE..."
PREVIOUS="$(docker image inspect -f '{{.Id}}' "$IMAGE" 2>/dev/null || true)"
docker build --quiet -t "$IMAGE" -f "$RIG_DIR/mail.Dockerfile" "$STAGE" >/dev/null
CURRENT="$(docker image inspect -f '{{.Id}}' "$IMAGE")"
if [ -n "$PREVIOUS" ] && [ "$PREVIOUS" != "$CURRENT" ]; then
    docker image rm "$PREVIOUS" >/dev/null 2>&1 || true
fi

docker network create "$NETWORK" >/dev/null

# The certificate authority, and a certificate for the servers' network names, made by the test suite's own
# generator in a throwaway container; the files are copied out (no volume, no bind mount).
echo "Making the test certificates..."
docker create --name "$NAME-certs" "$IMAGE" php -r '
    require "/app/vendor/autoload.php";
    $pki = Tests\Fixtures\Smtp\TestPki::create(["mailpit-starttls", "mailpit-tls"]);
    mkdir("/certs");
    copy($pki->caFile(), "/certs/ca.pem");
    $pem = file_get_contents($pki->serverFile("valid-for-localhost"));
    preg_match("/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s", $pem, $cert);
    preg_match("/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s", $pem, $key);
    file_put_contents("/certs/cert.pem", $cert[0] . "\n");
    file_put_contents("/certs/key.pem", $key[0] . "\n");
' >/dev/null
docker start -a "$NAME-certs" >/dev/null
mkdir "$STAGE/certs"
docker cp "$NAME-certs:/certs/." "$STAGE/certs"
chmod 644 "$STAGE/certs"/*.pem

# One Mailpit per kind of connection. Logins are accepted (any), over a plain connection too, where the
# test sends one. Each listens on the port its kind has on a real server.
start_mailpit() {
    local kind="$1"
    shift
    docker create --name "$NAME-$kind" --network "$NETWORK" --network-alias "mailpit-$kind" "$MAILPIT_IMAGE" "$@" >/dev/null
    docker cp "$STAGE/certs" "$NAME-$kind:/certs"
    docker start "$NAME-$kind" >/dev/null
}
start_mailpit plain --smtp 0.0.0.0:1025 --smtp-auth-accept-any --smtp-auth-allow-insecure
start_mailpit starttls --smtp 0.0.0.0:587 --smtp-auth-accept-any --smtp-tls-cert /certs/cert.pem --smtp-tls-key /certs/key.pem --smtp-require-starttls
start_mailpit tls --smtp 0.0.0.0:465 --smtp-auth-accept-any --smtp-tls-cert /certs/cert.pem --smtp-tls-key /certs/key.pem --smtp-require-tls

echo "Waiting for Mailpit..."
for kind in "${SERVERS[@]}"; do
    ready=0
    for _ in $(seq 1 60); do
        if docker exec "$NAME-$kind" /mailpit readyz >/dev/null 2>&1; then
            ready=1
            break
        fi
        sleep 1
    done
    if [ "$ready" -ne 1 ]; then
        echo "Mailpit ($kind) did not start:" >&2
        docker logs "$NAME-$kind" >&2 || true
        exit 1
    fi
done

docker create --name "$PHP_CONTAINER" --network "$NETWORK" \
    -e IDNO_TEST_MAILPIT_SMTP="plain=mailpit-plain:1025,starttls=mailpit-starttls:587,tls=mailpit-tls:465" \
    -e IDNO_TEST_MAILPIT_API="plain=http://mailpit-plain:8025,starttls=http://mailpit-starttls:8025,tls=http://mailpit-tls:8025" \
    -e IDNO_TEST_MAILPIT_CA=/certs/ca.pem \
    "$IMAGE" bash Tests/rig/mail-container.sh >/dev/null
docker cp "$STAGE/certs" "$PHP_CONTAINER:/certs"
docker start -a "$PHP_CONTAINER" &
wait "$!"
