#!/bin/sh
set -e

SETTINGS="/var/www/html/web/sites/default/settings.php"

# Ensure sites/default is writable so we can write settings.php
chmod 755 /var/www/html/web/sites/default 2>/dev/null || true

# Generate settings.php from environment variables at container startup.
# This is done at runtime (not build time) so credentials are never baked into the image.
cat > "$SETTINGS" <<PHPEOF
<?php
\$databases['default']['default'] = [
  'database'  => '${MYSQL_DATABASE}',
  'username'  => '${MYSQL_USER}',
  'password'  => '${MYSQL_PASSWORD}',
  'host'      => '${MYSQL_HOST}',
  'port'      => '3306',
  'driver'    => 'mysql',
  'prefix'    => '',
  'collation' => 'utf8mb4_general_ci',
];
\$settings['hash_salt'] = '${DRUPAL_HASH_SALT}';
\$settings['trusted_host_patterns'] = [
  '^groups\\.performantlabs\\.com$',
  '^localhost$',
];
\$settings['file_private_path'] = '/var/www/html/private';
\$settings['config_sync_directory'] = '/var/www/html/config/sync';
\$settings['reverse_proxy'] = TRUE;
\$settings['reverse_proxy_addresses'] = ['127.0.0.1'];
PHPEOF

echo "Generated settings.php from environment variables"

# Start PHP-FPM in the background
php-fpm -D

# Start nginx in the foreground (keeps the container alive)
exec nginx -g 'daemon off;'
