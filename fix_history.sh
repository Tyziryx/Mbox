#!/usr/bin/env bash
set -euo pipefail

# fix_history.sh
# Repares and verifies blocked-history data sources for parental UI.
# Run this on the Linux VM after DNS/BIND changes.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"

API_FILE="$PROJECT_ROOT/public/api_domain_lists.php"
DNS_DIR="/etc/mbox/dns"
REASONS_FILE="$DNS_DIR/block_reasons.json"
VISITS_FILE="$DNS_DIR/blocked_visits.jsonl"

LOG_DIR="/var/log/named"
RPZ_LOG="$LOG_DIR/rpz.log"
QUERY_LOG="$LOG_DIR/query.log"

log()  { printf '[INFO] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*" >&2; }

WEB_USER="${WEB_USER:-www-data}"
if ! id "$WEB_USER" >/dev/null 2>&1; then
  WEB_USER="$(ps -eo user,comm | awk '($2 ~ /apache2|nginx|php-fpm/) && $1 != "root" {print $1; exit}')"
fi
[ -z "${WEB_USER:-}" ] && WEB_USER="www-data"
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || echo "$WEB_USER")"

log "Using web user: $WEB_USER"

log "Ensure DNS data files"
sudo mkdir -p "$DNS_DIR"
[ -f "$REASONS_FILE" ] || echo '{}' | sudo tee "$REASONS_FILE" >/dev/null
[ -f "$VISITS_FILE" ] || sudo touch "$VISITS_FILE"

if getent passwd "$WEB_USER" >/dev/null 2>&1; then
  sudo chown "$WEB_USER:$WEB_GROUP" "$REASONS_FILE" "$VISITS_FILE" || true
fi
sudo chmod 664 "$REASONS_FILE" "$VISITS_FILE" || true

log "Ensure BIND log files"
sudo mkdir -p "$LOG_DIR"
sudo touch "$RPZ_LOG" "$QUERY_LOG"
sudo chown bind:bind "$RPZ_LOG" "$QUERY_LOG" || true
sudo chmod 644 "$RPZ_LOG" "$QUERY_LOG" || true
sudo chmod 755 "$LOG_DIR" || true

if command -v rndc >/dev/null 2>&1; then
  log "Enable query logging at runtime"
  sudo rndc querylog on >/dev/null 2>&1 || warn "rndc querylog on failed"
fi

if command -v systemctl >/dev/null 2>&1; then
  log "Reload bind9"
  sudo systemctl reload bind9 >/dev/null 2>&1 || warn "bind9 reload failed"
fi

if [ -f "$API_FILE" ] && command -v php >/dev/null 2>&1; then
  log "Quick API check for blocked history"
  php -r '$_GET=["action"=>"recent"]; include $argv[1];' "$API_FILE" >/tmp/mbox_recent_history.json 2>/dev/null || true
  if [ -s /tmp/mbox_recent_history.json ]; then
    log "API recent output saved to /tmp/mbox_recent_history.json"
  else
    warn "API recent returned empty output"
  fi
else
  warn "Skip API check (php or api_domain_lists.php missing)"
fi

if command -v curl >/dev/null 2>&1; then
  log "HTTP endpoint check"
  for url in \
    "http://127.0.0.1/api_domain_lists.php?action=recent" \
    "http://127.0.0.1/public/api_domain_lists.php?action=recent"
  do
    body="$(curl -s --max-time 5 "$url" || true)"
    if echo "$body" | grep -q '"success"'; then
      log "OK endpoint: $url"
      break
    fi
  done
fi

log "Done. You can now refresh Historique des blocages in UI."
