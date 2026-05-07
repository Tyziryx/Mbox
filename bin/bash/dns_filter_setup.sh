#!/bin/bash
set -euo pipefail

DNS_DIR="/etc/mbox/dns"
BLACKLIST="$DNS_DIR/blacklist.txt"
KNOW="$DNS_DIR/knowdomain.txt"
REASONS_FILE="$DNS_DIR/block_reasons.json"
RPZ_ZONE_NAME="rpz.mbox.local"
RPZ_ZONE_FILE="/etc/bind/db.rpz.mbox"
NAMED_LOCAL="/etc/bind/named.conf.local"
NAMED_OPTIONS="/etc/bind/named.conf.options"

echo "[1/5] Création des répertoires"
sudo mkdir -p "$DNS_DIR"

echo "[2/5] Initialisation des listes"
if [ ! -f "$BLACKLIST" ]; then
  sudo tee "$BLACKLIST" > /dev/null << 'EOF'
# Domaines explicitement bloqués
# ex:
# bad-domain.example
EOF
fi

if [ ! -f "$KNOWN" ]; then
  sudo tee "$KNOWN" > /dev/null << 'EOF'
# Domaines légitimes de référence (anti-typosquatting)
google.com
facebook.com
microsoft.com
apple.com
amazon.com
github.com
EOF
fi

if [ ! -f "$REASONS_FILE" ]; then
  echo '{}' | sudo tee "$REASONS_FILE" > /dev/null
fi

echo "[3/5] Création zone RPZ"
if [ ! -f "$RPZ_ZONE_FILE" ]; then
  sudo tee "$RPZ_ZONE_FILE" > /dev/null << 'EOF'
$TTL 60
@   IN  SOA localhost. root.localhost. (
        1       ; Serial
        60      ; Refresh
        60      ; Retry
        60      ; Expire
        60 )    ; Minimum
    IN  NS  localhost.
EOF
fi
sudo chown bind:bind "$RPZ_ZONE_FILE"

echo "[4/5] Injection BIND (idempotent)"
if ! sudo grep -q "zone \"$RPZ_ZONE_NAME\"" "$NAMED_LOCAL"; then
  sudo tee -a "$NAMED_LOCAL" > /dev/null << EOF

zone "$RPZ_ZONE_NAME" {
    type master;
    file "$RPZ_ZONE_FILE";
};
EOF
fi

if ! sudo grep -q "response-policy" "$NAMED_OPTIONS"; then
  if sudo grep -q "allow-recursion" "$NAMED_OPTIONS"; then
    sudo sed -i '/allow-recursion[[:space:]]*{[^}]*};/a\    response-policy { zone "rpz.mbox.local"; };' "$NAMED_OPTIONS"
  else
    sudo sed -i '/^[[:space:]]*};[[:space:]]*$/i\    response-policy { zone "rpz.mbox.local"; };' "$NAMED_OPTIONS"
  fi

  if ! sudo named-checkconf >/dev/null 2>&1; then
    sudo sed -i '/response-policy[[:space:]]*{[[:space:]]*zone[[:space:]]*"rpz\.mbox\.local";[[:space:]]*};/d' "$NAMED_OPTIONS"
    echo "WARN: response-policy non supporté ou mal placé sur cette version BIND. Ligne retirée automatiquement."
  fi
fi

echo "[5/5] Validation et reload"
sudo named-checkconf
sudo named-checkzone "$RPZ_ZONE_NAME" "$RPZ_ZONE_FILE"
sudo systemctl reload bind9

echo "OK: setup DNS filter + RPZ terminé."
echo "Listes: $BLACKLIST | $KNOWN"
