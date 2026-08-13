#!/bin/sh
set -eu

cd /var/www/html

SITE_URL="${SITE_URL:-http://localhost:8088}"
SITE_URL="${SITE_URL%/}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-admin}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"
WP_TITLE="${WP_TITLE:-Delegat WooCommerce shop}"
DELEGAT_API_KEY="${DELEGAT_API_KEY:-delegat-woo-sandbox-api-key-local}"

echo "Waiting for WordPress files..."
n=0
while [ ! -f wp-includes/version.php ] || [ ! -f wp-config.php ]; do
  n=$((n + 1))
  if [ "$n" -gt 90 ]; then
    echo "Timeout waiting for WordPress files"
    exit 1
  fi
  sleep 2
done

echo "Waiting for database..."
n=0
until wp db check --allow-root >/dev/null 2>&1; do
  n=$((n + 1))
  if [ "$n" -gt 90 ]; then
    echo "Timeout waiting for database"
    exit 1
  fi
  sleep 2
done

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
  echo "Installing WordPress..."
  wp core install \
    --url="${SITE_URL}" \
    --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email \
    --allow-root
fi

wp rewrite structure '/%postname%/' --hard --allow-root || true
wp rewrite flush --hard --allow-root || true

cat > /var/www/html/.htaccess <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF

if ! wp plugin is-installed woocommerce --allow-root; then
  echo "Installing WooCommerce (WordPress.org)..."
  wp plugin install woocommerce --activate --allow-root
else
  wp plugin activate woocommerce --allow-root || true
fi

wp option update woocommerce_onboarding_profile '{"skipped":true,"completed":true}' --format=json --allow-root || true
wp option update woocommerce_task_list_hidden "yes" --allow-root || true
wp option update woocommerce_coming_soon "no" --allow-root || true
wp option update woocommerce_store_address "1 Test Street" --allow-root
wp option update woocommerce_store_city "Moscow" --allow-root
wp option update woocommerce_default_country "RU" --allow-root
wp option update woocommerce_currency "RUB" --allow-root
wp option update woocommerce_allow_tracking "no" --allow-root || true
wp wc tool run install_pages --user="${WP_ADMIN_USER}" --allow-root || true

if [ ! -f /var/www/html/wp-content/plugins/delegat-bridge/delegat-bridge.php ]; then
  echo "Missing vendored plugin at wp-content/plugins/delegat-bridge"
  exit 1
fi

echo "Activating Delegat Bridge..."
wp plugin activate delegat-bridge --allow-root
wp option update delegat_bridge_api_key "${DELEGAT_API_KEY}" --allow-root

wp wc product create --name="Sandbox product" --sku=delegat-sandbox --type=simple --regular_price=100 --status=publish --user="${WP_ADMIN_USER}" --allow-root || true

chown -R 33:33 /var/www/html || true

echo "READY"
echo "Admin: ${SITE_URL}/wp-admin  (${WP_ADMIN_USER} / ${WP_ADMIN_PASSWORD})"
echo "Bridge URL: ${SITE_URL}/wp-json/delegat/v1"
echo "API key: ${DELEGAT_API_KEY}"
