#!/bin/bash
# MAJ du DNS sur l'operateur (délégation de zone)
# Usage: ./update_operator_dns.sh  alexi.ceri.com 192.168.50.10

if [ $# -ne 2 ]; then
    echo "Usage: $0 <domain_name> <box_wan_ip>"
    exit 1
fi

DOMAIN_NAME="$1"
BOX_WAN_IP="$2"
OPERATOR_IP="192.168.50.1"
OPERATOR_USER="stud"

SUBDOMAIN=$(echo "$DOMAIN_NAME" | cut -d'.' -f1)
WAN_OCTET=$(echo "$BOX_WAN_IP" | cut -d'.' -f4)
REV_ZONE_WAN="50.168.192.in-addr.arpa"
REV_FILE_WAN="/etc/bind/db.50.168.192"

ssh -o StrictHostKeyChecking=no "$OPERATOR_USER@$OPERATOR_IP" bash << ENDSSH

echo "[Opérateur] MAJ pour $DOMAIN_NAME..."

# Nettoyage des anciennes délégations
sudo sed -i '/^; Délégation/d' /etc/bind/db.ceri.com
sudo sed -i '/IN[[:space:]]*NS[[:space:]]*ns\./d' /etc/bind/db.ceri.com
sudo sed -i '/^ns\./d' /etc/bind/db.ceri.com

# Ajout de la nouvelle délégation
echo "; Délégation $DOMAIN_NAME
$SUBDOMAIN    IN      NS      ns.$SUBDOMAIN
ns.$SUBDOMAIN IN      A       $BOX_WAN_IP" | sudo tee -a /etc/bind/db.ceri.com > /dev/null

# Incrémentation du Serial
sudo sed -i '/Serial/ s/[0-9]\+/&1/' /etc/bind/db.ceri.com

# Vérification zone inverse WAN
if ! grep -q "$REV_ZONE_WAN" /etc/bind/named.conf.local; then
    echo "zone \\"$REV_ZONE_WAN\\" { type master; file \\"$REV_FILE_WAN\\"; };" | sudo tee -a /etc/bind/named.conf.local > /dev/null
fi

# Création fichier zone inverse si absent
if [ ! -f "$REV_FILE_WAN" ]; then
    sudo tee "$REV_FILE_WAN" > /dev/null << 'EOFZONE'
\$TTL 604800
@       IN      SOA     ns.ceri.com. admin.ceri.com. (
                              1         ; Serial
                         604800         ; Refresh
                          86400         ; Retry
                        2419200         ; Expire
                         604800 )       ; Negative Cache TTL
;
@       IN      NS      ns.ceri.com.
EOFZONE
fi

# Nettoyage anciens PTR et ajout du nouveau
sudo sed -i '/IN[[:space:]]*PTR[[:space:]]*ns\\./d' "$REV_FILE_WAN"
echo "$WAN_OCTET   IN      PTR     ns.$DOMAIN_NAME." | sudo tee -a "$REV_FILE_WAN" > /dev/null
sudo sed -i '/Serial/ s/[0-9]\+/&1/' "$REV_FILE_WAN"

# Redémarrage BIND9
sudo systemctl restart bind9

ENDSSH

[ $? -eq 0 ] && echo "✓ Opérateur OK" || echo "✗ Erreur SSH"