#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

DNS_DIR="/etc/mbox/dns"
BLACKLIST="$DNS_DIR/blacklist.txt"
KNOWN="$DNS_DIR/knowdomain.txt"
REFRESH_SCRIPT="$PROJECT_ROOT/bin/bash/refresh_rpz.sh"
SYNC_GLOBAL_SCRIPT="$PROJECT_ROOT/bin/bash/sync_blacklist_rpz.sh"
NORM_SCRIPT="$PROJECT_ROOT/bin/python/normalize_domain_lists.py"
SUMMARY_JSON="$DNS_DIR/domain_lists_summary.json"
INTERVAL="${WATCH_INTERVAL:-3}"

ensure_files() {
  sudo mkdir -p "$DNS_DIR"
  [ -f "$BLACKLIST" ] || sudo touch "$BLACKLIST"
  [ -f "$KNOWN" ] || sudo touch "$KNOWN"
}

run_sync() {
  echo "[watch] changement detecte -> normalisation + refresh RPZ + sync global"

  if command -v python3 >/dev/null 2>&1 && [ -f "$NORM_SCRIPT" ]; then
    python3 "$NORM_SCRIPT" --blacklist "$BLACKLIST" --known "$KNOWN" --summary "$SUMMARY_JSON" >/dev/null 2>&1 || true
  fi

  if [ -x "$REFRESH_SCRIPT" ]; then
    "$REFRESH_SCRIPT" >/dev/null 2>&1 || true
  fi

  if [ -x "$SYNC_GLOBAL_SCRIPT" ]; then
    "$SYNC_GLOBAL_SCRIPT" >/dev/null 2>&1 || true
  fi
}

hash_files() {
  sha256sum "$BLACKLIST" "$KNOWN" 2>/dev/null | sha256sum | awk '{print $1}'
}

ensure_files
LAST_HASH="$(hash_files || echo '')"

# Premiere sync au demarrage
run_sync

if command -v inotifywait >/dev/null 2>&1; then
  echo "[watch] mode inotify actif"
  while true; do
    inotifywait -q -e close_write,move,create "$BLACKLIST" "$KNOWN" >/dev/null 2>&1 || true
    run_sync
  done
else
  echo "[watch] inotifywait absent, fallback polling ${INTERVAL}s"
  while true; do
    sleep "$INTERVAL"
    CUR_HASH="$(hash_files || echo '')"
    if [ "$CUR_HASH" != "$LAST_HASH" ]; then
      LAST_HASH="$CUR_HASH"
      run_sync
    fi
  done
fi
