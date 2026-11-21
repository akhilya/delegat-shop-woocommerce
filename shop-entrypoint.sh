#!/bin/bash
set -euo pipefail

rm -rf /usr/src/wordpress/wp-content/plugins/delegat-bridge
mkdir -p /usr/src/wordpress/wp-content/plugins/delegat-bridge
cp -a /usr/src/delegat-bridge/. /usr/src/wordpress/wp-content/plugins/delegat-bridge/

if [ -f /var/www/html/wp-includes/version.php ]; then
  # Replace the entire plugin directory in the persistent volume on every deploy
  # so stale PHP files from older bridge versions are never left behind.
  rm -rf /var/www/html/wp-content/plugins/delegat-bridge
  mkdir -p /var/www/html/wp-content/plugins/delegat-bridge
  cp -a /usr/src/delegat-bridge/. /var/www/html/wp-content/plugins/delegat-bridge/
  chown -R www-data:www-data /var/www/html/wp-content/plugins/delegat-bridge
fi

exec docker-entrypoint.sh "$@"
