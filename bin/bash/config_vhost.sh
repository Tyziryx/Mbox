#!/bin/bash
# Config un VirtualHost Apache pour le domaine
# Usage: ./config_vhost.sh alexi.ceri.com

if [ $# -ne 1 ]; then
    echo "Usage: $0 <domain_name>"
    exit 1
fi

DOMAIN="$1"
DOMAIN_REGEX="${DOMAIN//./\\.}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PUBLIC_DIR="$PROJECT_ROOT/public"

# Désactiver tous les VirtualHosts SAUF default-ssl (pour garder HTTPS)
for site in /etc/apache2/sites-enabled/*.conf; do
    sitename=$(basename "$site")
    if [ "$sitename" != "default-ssl.conf" ] && [ -f "$site" ]; then
        sudo a2dissite "$sitename" 2>/dev/null
    fi
done

# Créer le nouveau VirtualHost HTTP
sudo tee /etc/apache2/sites-available/$DOMAIN.conf > /dev/null << EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    ServerAlias www.$DOMAIN
    DocumentRoot $PUBLIC_DIR

    RewriteEngine On
    RewriteCond %{HTTP_HOST} !^${DOMAIN_REGEX}$ [NC]
    RewriteCond %{HTTP_HOST} !^www\.${DOMAIN_REGEX}$ [NC]
    RewriteRule ^ /dns_blocked.php [L]

    <Directory $PUBLIC_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

# VHost catch-all pour afficher la page de blocage DNS sur les domaines inconnus
sudo tee /etc/apache2/sites-available/000-mbox-block.conf > /dev/null << EOF
<VirtualHost *:80>
    ServerName _default_
    ServerAlias *
    DocumentRoot $PUBLIC_DIR

    RewriteEngine On
    RewriteCond %{HTTP_HOST} !^${DOMAIN_REGEX}$ [NC]
    RewriteCond %{HTTP_HOST} !^www\.${DOMAIN_REGEX}$ [NC]
    RewriteRule ^ /dns_blocked.php [L]

    <Directory $PUBLIC_DIR>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

# Activer le VirtualHost HTTP
sudo a2ensite $DOMAIN.conf
sudo a2ensite 000-mbox-block.conf

# S'assurer que default-ssl est actif (pour HTTPS)
sudo a2ensite default-ssl.conf 2>/dev/null
sudo a2enmod rewrite 2>/dev/null

# Recharger Apache
sudo systemctl reload apache2
echo "VHost $DOMAIN OK (HTTP + HTTPS)"