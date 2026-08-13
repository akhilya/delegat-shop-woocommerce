#!/bin/bash
set -euo pipefail

mkdir -p /usr/src/wordpress/wp-content/plugins/delegat-bridge
cp -a /usr/src/delegat-bridge/. /usr/src/wordpress/wp-content/plugins/delegat-bridge/

if [ -f /var/www/html/wp-includes/version.php ]; then
  mkdir -p /var/www/html/wp-content/plugins/delegat-bridge
  cp -a /usr/src/delegat-bridge/. /var/www/html/wp-content/plugins/delegat-bridge/
fi

exec docker-entrypoint.sh "$@"
