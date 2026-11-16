#!/usr/bin/env bash
# Write configuration/config.ini for the local test rig, in the given mode (sqlite or mysql), from
# Tests/rig/config.sqlite.ini or Tests/rig/config.ini.
#
#   write-config.sh <sqlite|mysql>          write it (or leave it, if it is already that)
#   write-config.sh <sqlite|mysql> --check  only say whether it could be written
#
# A configuration/config.ini that the rig did not write (one whose first line is not the rig's
# marker, see lib.sh) is never replaced: this exits 1 and says so.
set -euo pipefail

RIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$RIG_DIR/lib.sh"

MODE="${1:-}"
CHECK="${2:-}"
case "$MODE" in
    sqlite) TEMPLATE="$RIG_DIR/config.sqlite.ini" ;;
    mysql) TEMPLATE="$RIG_DIR/config.ini" ;;
    *) echo "Usage: $0 <sqlite|mysql> [--check]" >&2; exit 2 ;;
esac

# Anything at that path that is not a regular file starting with the marker (a developer's config, an
# empty file, a symlink, even a dangling one) is refused.
if { [ -e "$CONFIG" ] || [ -L "$CONFIG" ]; } && ! rig_config_is_ours; then
    echo "configuration/config.ini exists and was not written by the test rig (it is not a regular file" >&2
    echo "whose first line is the rig's marker), so the rig will not replace it. Move it aside and run again." >&2
    exit 1
fi
if [ "$CHECK" = "--check" ]; then
    exit 0
fi

EXPECTED="$(sed -e "s#__UPLOADPATH__#$STATE_DIR/uploads/#" -e "s#__DBPATH__#$STATE_DIR/site.sqlite#" "$TEMPLATE")"
mkdir -p "$ROOT/configuration"
if [ ! -e "$CONFIG" ] && [ ! -L "$CONFIG" ]; then
    printf '%s\n' "$EXPECTED" > "$CONFIG"
    echo "Wrote configuration/config.ini for the test rig ($MODE)."
elif [ "$(cat "$CONFIG")" != "$EXPECTED" ]; then
    printf '%s\n' "$EXPECTED" > "$CONFIG"
    echo "Replaced the test rig's configuration/config.ini ($MODE)."
fi
