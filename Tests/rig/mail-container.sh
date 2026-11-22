#!/usr/bin/env bash
# Runs inside the PHP container that Tests/rig/mail.sh starts: the mail delivery test (Tests/Mail/
# MailpitDeliveryTest) on the test rig in its SQLite mode (composer test:up), as CI's sqlite job runs the
# suite. The test must RUN, not skip (Tests/rig/mail-check.php). The exit status is non-zero when the test
# failed or did not run.
set -uo pipefail

cd /app
status=0
composer test:up || exit 1
composer test -- Tests/Mail/MailpitDeliveryTest.php --log-junit /tmp/mail.xml || status=$?
composer test:down >/dev/null || true
php Tests/rig/mail-check.php /tmp/mail.xml || status=1
exit "$status"
