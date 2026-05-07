#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WEBROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
WATCH_SCRIPT="$WEBROOT/bin/bash/watch_domain_lists.sh"
UNIT_FILE="/etc/systemd/system/mbox-domain-watch.service"

if [ ! -f "$WATCH_SCRIPT" ]; then
  echo "Erreur: script introuvable: $WATCH_SCRIPT"
  exit 1
fi

sudo chmod +x "$WATCH_SCRIPT"

sudo tee "$UNIT_FILE" >/dev/null <<EOF
[Unit]
Description=MBox domain lists watcher (blacklist + knowdomain)
After=network-online.target bind9.service
Wants=network-online.target

[Service]
Type=simple
ExecStart=/bin/bash $WATCH_SCRIPT
Restart=always
RestartSec=2
User=root

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now mbox-domain-watch.service

echo "OK: service actif"
sudo systemctl --no-pager --full status mbox-domain-watch.service | sed -n '1,20p'
