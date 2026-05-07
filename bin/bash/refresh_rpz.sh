#!/bin/bash
# Wrapper appele par cron / hook dhcpd / apply_parental.sh
# Regenere les zones RPZ par device et reload BIND si necessaire.
set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
SCRIPT="$PROJECT_ROOT/bin/php/build_rpz_per_device.php"
LOCK="/var/lock/mbox_refresh_rpz.lock"

# Verrou pour eviter les runs concurrents (cron + hook + UI)
exec 9>"$LOCK" 2>/dev/null || exit 0
if ! flock -n 9; then
    exit 0
fi

if [ ! -f "$SCRIPT" ]; then
    echo "refresh_rpz: $SCRIPT introuvable" >&2
    exit 1
fi

/usr/bin/php "$SCRIPT"
