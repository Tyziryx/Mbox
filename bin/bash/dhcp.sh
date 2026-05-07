#!/bin/bash
# Script pour config le serveur DHCP
# Usage: ./dhcp.sh 192.168.100 192.168.100.11 192.168.100.30 192.168.100.1

if [ $# -ne 4 ]; then
    echo "Usage: $0 <network> <dhcp_start> <dhcp_end> <server_ip>"
    exit 1
fi

NETWORK="$1"
DHCP_START="$2"
DHCP_END="$3"
SERVER_IP="$4"
DHCP_CONF="/etc/dhcp/dhcpd.conf"

echo "Config DHCP : $DHCP_START - $DHCP_END (gateway: $SERVER_IP)"

# Création ou modification de dhcpd.conf
if [ ! -f "$DHCP_CONF" ]; then
    # Création from scratch
    sudo tee "$DHCP_CONF" > /dev/null << EOF
default-lease-time 600;
max-lease-time 7200;

subnet $NETWORK.0 netmask 255.255.255.0 {
    range $DHCP_START $DHCP_END;
    option routers $SERVER_IP;
    option domain-name-servers $SERVER_IP;
    option broadcast-address $NETWORK.255;
    option subnet-mask 255.255.255.0;
}
EOF
else
    # Modification du fichier existant
    sudo sed -i "/^subnet $NETWORK.0 netmask 255.255.255.0/,/^}/ {
        s/^[[:space:]]*range .*/    range $DHCP_START $DHCP_END;/
        s/^[[:space:]]*option routers .*/    option routers $SERVER_IP;/
        s/^[[:space:]]*option domain-name-servers .*/    option domain-name-servers $SERVER_IP;/
        s/^[[:space:]]*option broadcast-address .*/    option broadcast-address $NETWORK.255;/
    }" "$DHCP_CONF"

    # Ajout du subnet si inexistant
    if ! grep -q "^subnet $NETWORK.0 netmask 255.255.255.0" "$DHCP_CONF"; then
        sudo tee -a "$DHCP_CONF" > /dev/null << EOF

subnet $NETWORK.0 netmask 255.255.255.0 {
    range $DHCP_START $DHCP_END;
    option routers $SERVER_IP;
    option domain-name-servers $SERVER_IP;
    option broadcast-address $NETWORK.255;
    option subnet-mask 255.255.255.0;
}
EOF
    fi
fi

# Config interface d'écoute (eth1 = LAN)
echo 'INTERFACESv4="eth1"' | sudo tee /etc/default/isc-dhcp-server > /dev/null

# Redémarrage du service
sudo systemctl restart isc-dhcp-server

if systemctl is-active --quiet isc-dhcp-server; then
    echo "✓ DHCP configuré et redémarré"
else
    echo "⚠ Erreur démarrage - Vérifier: sudo systemctl status isc-dhcp-server"
    exit 1
fi