#!/bin/bash
# Change l'IP d'une interface (eth0 ou eth1)
# Usage: ./change_ip.sh eth1 192.168.100.5 alexi.ceri.com

if [ $# -ne 3 ]; then
    echo "Usage: $0 <interface> <nouvelle_ip> <nom_domaine>"
    exit 1
fi

INTERFACE="$1"
NEW_IP="$2"
DOMAIN_NAME="$3"
INTERFACES_FILE="/etc/network/interfaces"

# On recupere l'ancienne IP dans /etc/network/interfaces
OLD_IP=$(grep -A 3 "iface $INTERFACE inet static" "$INTERFACES_FILE" | grep 'address' | awk '{print $2}')

if [ -z "$OLD_IP" ]; then
    echo "Erreur: IP $INTERFACE introuvable"
    exit 1
fi

# Remplacement de l'IP dans le fichier
sudo sed -i "/^iface $INTERFACE inet static$/,/^[[:space:]]*$/ s/^address .*/address $NEW_IP/" "$INTERFACES_FILE"

# Si c'est eth1 et qu'on a un domaine, on met a jour le DNS aussi
if [ "$INTERFACE" = "eth1" ] && [ "$DOMAIN_NAME" != "no-domain" ]; then
    ZONE_FILE="/etc/bind/db.$DOMAIN_NAME"

    # On remplace l'ancienne IP par la nouvelle dans la zone DNS
    if [ -f "$ZONE_FILE" ]; then
        sudo sed -i "s/$OLD_IP/$NEW_IP/g" "$ZONE_FILE"
        # Incrementation du serial
        sudo sed -i '/Serial/ s/[0-9]\+/&1/' "$ZONE_FILE"
        sudo systemctl restart bind9
    fi

    # MAJ de resolv.conf pour pointer vers le bon DNS
    sudo sed -i "s/^nameserver .*/nameserver $NEW_IP/" /etc/resolv.conf
fi

# On redemarre l'interface pour appliquer les changements
sudo ifdown "$INTERFACE" 2>/dev/null || true
sudo ifup "$INTERFACE"

echo "IP $INTERFACE changée : $OLD_IP → $NEW_IP"
