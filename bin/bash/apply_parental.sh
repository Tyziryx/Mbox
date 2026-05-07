#!/bin/bash
CONFIG_FILE=$1

MAC=$(jq -r '.mac' "$CONFIG_FILE")
MODE=$(jq -r '.mode' "$CONFIG_FILE")
LOCAL_DNS="${LOCAL_DNS:-192.168.100.1}"

echo "--- Nettoyage des anciennes regles pour $MAC ---"
# On supprime toutes les règles FORWARD qui concernent cette adresse MAC
iptables-save | grep -v "$MAC" | iptables-restore

echo "--- Application des horaires ---"
# On lit le tableau "blocks" généré par PHP
jq -c '.blocks[]' "$CONFIG_FILE" | while read i; do
    DAY=$(echo $i | jq -r '.day')
    START=$(echo $i | jq -r '.start')
    END=$(echo $i | jq -r '.end')
    
    iptables -I FORWARD -m mac --mac-source "$MAC" -m time --timestart "$START" --timestop "$END" --weekdays "$DAY" --kerneltz -j DROP
    echo "Bloque le $DAY de $START a $END"
done

echo "--- Application du Mode DNS ---"
if [ "$MODE" == "child" ] || [ "$MODE" == "teen" ] || [ "$MODE" == "custom" ]; then
    iptables -t nat -I PREROUTING -m mac --mac-source "$MAC" -p udp --dport 53 -j DNAT --to-destination "$LOCAL_DNS":53
    iptables -t nat -I PREROUTING -m mac --mac-source "$MAC" -p tcp --dport 53 -j DNAT --to-destination "$LOCAL_DNS":53
    echo "DNS force vers resolver local: $LOCAL_DNS"
else
    echo "Mode $MODE: pas de redirection DNS forcee"
fi

# Sauvegarde des règles
netfilter-persistent save > /dev/null 2>&1

# Regenere les zones RPZ par device (filtres par categorie) + reload BIND
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ -x "$SCRIPT_DIR/refresh_rpz.sh" ]; then
    "$SCRIPT_DIR/refresh_rpz.sh" > /dev/null 2>&1 || true
elif [ -x "$SCRIPT_DIR/sync_blacklist_rpz.sh" ]; then
    # Fallback: ancien comportement (blacklist globale unique)
    "$SCRIPT_DIR/sync_blacklist_rpz.sh" "$LOCAL_DNS" > /dev/null 2>&1 || true
fi

# Synchronise le moteur quota (compteur + deblocage eventuel)
QUOTA_RUNTIME="$SCRIPT_DIR/quota_runtime.sh"
QUOTA_GB=$(jq -r '.download_quota_gb // 0' "$CONFIG_FILE" 2>/dev/null)
if [ -x "$QUOTA_RUNTIME" ]; then
    if awk "BEGIN {exit !(${QUOTA_GB:-0} > 0)}"; then
        "$QUOTA_RUNTIME" sync "$MAC" > /dev/null 2>&1 || true
    else
        "$QUOTA_RUNTIME" block "$MAC" off > /dev/null 2>&1 || true
    fi
fi

echo "Termine avec succes."