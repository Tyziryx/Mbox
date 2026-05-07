#!/bin/bash
# Config DNS avec BIND9 - cree les zones directe et inverse
# Usage: ./config_dns.sh alexi.ceri.com 192.168.100.1

if [ $# -ne 2 ]; then
    echo "Usage: $0 <domain_name> <server_lan_ip>"
    exit 1
fi

DOMAIN="$1"
IP="$2"
IP_OCTET=$(echo "$IP" | cut -d'.' -f4)
REV_ZONE_FILE="/etc/bind/db.100.168.192"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Config DNS pour $DOMAIN ($IP)"

# Nettoyage des anciennes zones
sudo find /etc/bind/ -name 'db.*.ceri.com' -type f -delete 2>/dev/null
sudo rm -f "$REV_ZONE_FILE" 2>/dev/null

# Config named.conf.local
sudo tee /etc/bind/named.conf.local > /dev/null << EOF
zone "$DOMAIN" {
    type master;
    file "/etc/bind/db.$DOMAIN";
};
zone "100.168.192.in-addr.arpa" {
    type master;
    file "$REV_ZONE_FILE";
};
zone "rpz.mbox.local" {
    type master;
    file "/etc/bind/db.rpz.mbox";
};
EOF
sudo chown bind:bind /etc/bind/named.conf.local

# Zone directe (nom → IP)
sudo tee "/etc/bind/db.$DOMAIN" > /dev/null << EOF
\$TTL    604800
@       IN      SOA     ns.$DOMAIN. admin.$DOMAIN. (
                              1         ; Serial
                         604800         ; Refresh
                          86400         ; Retry
                        2419200         ; Expire
                         604800 )       ; Negative Cache TTL
;
@       IN      NS      ns.$DOMAIN.
@       IN      A       $IP
ns      IN      A       $IP
www     IN      A       $IP
EOF
sudo chown bind:bind "/etc/bind/db.$DOMAIN"

# Zone inverse (permet de retrouver le nom depuis l'IP)
sudo tee "$REV_ZONE_FILE" > /dev/null << EOF
\$TTL    604800
@       IN      SOA     ns.$DOMAIN. admin.$DOMAIN. (
                              1         ; Serial
                         604800         ; Refresh
                          86400         ; Retry
                        2419200         ; Expire
                         604800 )       ; Negative Cache TTL
;
@       IN      NS      ns.$DOMAIN.
$IP_OCTET   IN      PTR     $DOMAIN.
EOF
sudo chown bind:bind "$REV_ZONE_FILE"

# Options BIND9 - on forward vers l'operateur si on connait pas
sudo tee /etc/bind/named.conf.options > /dev/null << 'EOFOPT'
options {
    directory "/var/cache/bind";
    forwarders { 192.168.50.1; };
    dnssec-validation no;
    listen-on { any; };
    listen-on-v6 { none; };
    allow-query { any; };
    allow-recursion { any; };
    response-policy { zone "rpz.mbox.local"; };
};
EOFOPT
sudo chown bind:bind /etc/bind/named.conf.options

# Config du VirtualHost Apache pour le domaine
sudo "$SCRIPT_DIR/config_vhost.sh" "$DOMAIN"

# Verifications de syntaxe avant de lancer
sudo named-checkconf || exit 1
sudo named-checkzone "$DOMAIN" "/etc/bind/db.$DOMAIN" || exit 1
sudo named-checkzone "100.168.192.in-addr.arpa" "$REV_ZONE_FILE" || exit 1

# On redemarre les services
sudo systemctl restart bind9
sudo systemctl reload apache2

# Mise a jour chez l'operateur (delegation de zone)
WAN_IP=$(ip -4 addr show eth0 | grep -oP '(?<=inet\s)\d+(\.\d+){3}')
if [ -n "$WAN_IP" ]; then
    sudo "$SCRIPT_DIR/update_operator_dns.sh" "$DOMAIN" "$WAN_IP"
fi

# Config Postfix pour recevoir les mails du domaine
POSTFIX_CONF="/etc/postfix/main.cf"
OLD_DEST=$(sudo grep "^mydestination" "$POSTFIX_CONF")
if ! echo "$OLD_DEST" | grep -q "$DOMAIN"; then
    sudo sed -i "s/^mydestination = \(.*\)$/mydestination = \1, $DOMAIN/" "$POSTFIX_CONF"
    sudo postfix reload
    echo "✓ Postfix: mails @$DOMAIN activés"
fi

echo "✓ DNS configuré pour $DOMAIN"