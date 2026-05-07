#!/bin/bash
set -euo pipefail

DNS_DIR="/etc/mbox/dns"
BLACKLIST="$DNS_DIR/blacklist.txt"
KNOWN="$DNS_DIR/knowdomain.txt"
REASONS_FILE="$DNS_DIR/block_reasons.json"
RPZ_ZONE_NAME="rpz.mbox.local"
RPZ_ZONE_FILE="/etc/bind/db.rpz.mbox"
BLOCK_MODE="${RPZ_BLOCK_MODE:-nxdomain}"

LAN_IP="${1:-}"
if [ -z "$LAN_IP" ]; then
  LAN_IP=$(ip -4 addr show eth1 2>/dev/null | grep -oE 'inet [0-9]+(\.[0-9]+){3}' | awk '{print $2}' | head -1 || true)
fi
if [ -z "$LAN_IP" ]; then
  LAN_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || true)
fi
if [ -z "$LAN_IP" ]; then
  LAN_IP="127.0.0.1"
fi

if [ ! -f "$BLACKLIST" ]; then
  echo "Erreur: blacklist introuvable: $BLACKLIST"
  exit 1
fi

TMP_ZONE=$(mktemp)
TMP_BLACK=$(mktemp)
TMP_KNOWN=$(mktemp)
TMP_DOMAINS=$(mktemp)

SERIAL=$(date +%s)
cat > "$TMP_ZONE" <<EOF
\$TTL 60
@   IN  SOA localhost. root.localhost. (
        $SERIAL ; Serial
        60      ; Refresh
        60      ; Retry
        60      ; Expire
        60 )    ; Minimum
    IN  NS  localhost.
EOF

normalize_domain() {
  local d="$1"
  d=$(echo "$d" | tr '[:upper:]' '[:lower:]')
  d=$(echo "$d" | sed -E 's#^[a-z]+://##; s#/.*$##; s/:([0-9]+)$//; s/^www\.//')
  d=$(echo "$d" | tr -cd 'a-z0-9.-')
  d=$(echo "$d" | sed -E 's/\.+/./g; s/^\.+//; s/\.+$//')
  echo "$d"
}

to_root_domain() {
  local d="$1"
  IFS='.' read -r -a parts <<< "$d"
  local n=${#parts[@]}
  if [ "$n" -ge 2 ]; then
    echo "${parts[$((n-2))]}.${parts[$((n-1))]}"
  elif [ "$n" -eq 1 ]; then
    echo "${parts[0]}"
  else
    echo ""
  fi
}

extract_domains() {
  local src="$1"
  [ -f "$src" ] || return 0

  while IFS= read -r raw; do
    line=$(echo "$raw" | sed 's/#.*$//' | xargs)
    [ -z "$line" ] && continue

    norm=$(normalize_domain "$line")
    root=$(to_root_domain "$norm")
    [ -z "$root" ] && continue
    [[ "$root" != *.* ]] && continue

    echo "$root"
  done < "$src"
}

extract_domains "$BLACKLIST" | sort -u > "$TMP_BLACK"
extract_domains "$KNOWN" | sort -u > "$TMP_KNOWN"
comm -23 "$TMP_BLACK" "$TMP_KNOWN" > "$TMP_DOMAINS"

while IFS= read -r domain; do
  if [ "$BLOCK_MODE" = "redirect" ]; then
    echo "$domain    IN    A    $LAN_IP" >> "$TMP_ZONE"
    echo "*.$domain    IN    A    $LAN_IP" >> "$TMP_ZONE"
  else
    # Mode recommande pour HTTPS/HSTS: reponse NXDOMAIN via RPZ CNAME .
    echo "$domain    IN    CNAME    ." >> "$TMP_ZONE"
    echo "*.$domain    IN    CNAME    ." >> "$TMP_ZONE"
  fi
done < "$TMP_DOMAINS"

if [ -f "$RPZ_ZONE_FILE" ]; then
  sudo cp "$RPZ_ZONE_FILE" "$RPZ_ZONE_FILE.bak.$(date +%s)" >/dev/null 2>&1 || true
fi

sudo cp "$TMP_ZONE" "$RPZ_ZONE_FILE"
sudo chown bind:bind "$RPZ_ZONE_FILE"

if [ ! -f "$REASONS_FILE" ]; then
  echo '{}' | sudo tee "$REASONS_FILE" >/dev/null
fi

if command -v jq >/dev/null 2>&1; then
  TMP_JSON=$(mktemp)
  sudo cp "$REASONS_FILE" "$TMP_JSON.base" >/dev/null 2>&1 || echo '{}' > "$TMP_JSON.base"
  for domain in $(cat "$TMP_DOMAINS"); do
    jq --arg d "$domain" '
      .[$d] = (
        if (has($d) and (.[$d] | type == "object")) then
          {
            reason: (.[$d].reason // "blacklist_exact"),
            matched: (.[$d].matched // $d),
            updated_at: (.[$d].updated_at // now)
          }
        else
          {reason:"blacklist_exact", matched:$d, updated_at: now}
        end
      )
    ' "$TMP_JSON.base" > "$TMP_JSON" || cp "$TMP_JSON.base" "$TMP_JSON"
    cp "$TMP_JSON" "$TMP_JSON.base"
  done
  sudo cp "$TMP_JSON.base" "$REASONS_FILE"
  rm -f "$TMP_JSON" "$TMP_JSON.base"
fi

sudo named-checkzone "$RPZ_ZONE_NAME" "$RPZ_ZONE_FILE" >/dev/null
sudo systemctl reload bind9

COUNT=$(wc -l < "$TMP_DOMAINS" | tr -d ' ')
if [ "$BLOCK_MODE" = "redirect" ]; then
  echo "OK: RPZ synchronise depuis blacklist ($COUNT domaines), mode=redirect vers $LAN_IP"
else
  echo "OK: RPZ synchronise depuis blacklist ($COUNT domaines), mode=nxdomain"
fi

rm -f "$TMP_ZONE" "$TMP_BLACK" "$TMP_KNOWN" "$TMP_DOMAINS"
