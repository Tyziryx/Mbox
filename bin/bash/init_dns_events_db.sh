#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
SQL_FILE="${1:-$PROJECT_ROOT/bin/sql/create_dns_events_table.sql}"

if [ ! -f "$SQL_FILE" ]; then
  echo "Erreur: fichier SQL introuvable: $SQL_FILE"
  exit 1
fi

echo "[1/2] Creation/verif table dns_events dans db_box"
sudo mysql < "$SQL_FILE"

echo "[2/2] Verification rapide"
sudo mysql -D db_box -e "SELECT event_type, COUNT(*) AS c FROM dns_events GROUP BY event_type;"

echo "OK: table dns_events prete"
