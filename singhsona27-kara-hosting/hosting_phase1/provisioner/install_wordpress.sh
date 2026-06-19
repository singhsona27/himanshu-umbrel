#!/usr/bin/env bash
set -euo pipefail

SITE_DIR="${1:?site dir required}"
DB_NAME="${2:?db name required}"
DB_USER="${3:?db user required}"
DB_PASS="${4:?db pass required}"
SITE_URL="${5:?site url required}"
CONTAINER="${6:?site container required}"

mkdir -p "$SITE_DIR"
cd "$SITE_DIR"

if [ -f wp-config.php ]; then
  echo "WordPress already appears to be installed" >&2
  exit 1
fi

curl -fsSL https://wordpress.org/latest.tar.gz -o /tmp/wordpress_latest.tar.gz
tar -xzf /tmp/wordpress_latest.tar.gz -C /tmp
cp -a /tmp/wordpress/. "$SITE_DIR/"
rm -rf /tmp/wordpress /tmp/wordpress_latest.tar.gz

SALT="$(curl -fsSL https://api.wordpress.org/secret-key/1.1/salt/ || true)"
if [ -z "$SALT" ]; then
  SALT="define('AUTH_KEY',         '$(openssl rand -hex 32)');
define('SECURE_AUTH_KEY',  '$(openssl rand -hex 32)');
define('LOGGED_IN_KEY',    '$(openssl rand -hex 32)');
define('NONCE_KEY',        '$(openssl rand -hex 32)');
define('AUTH_SALT',        '$(openssl rand -hex 32)');
define('SECURE_AUTH_SALT', '$(openssl rand -hex 32)');
define('LOGGED_IN_SALT',   '$(openssl rand -hex 32)');
define('NONCE_SALT',       '$(openssl rand -hex 32)');"
fi

cat > wp-config.php <<PHP
<?php
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', 'hosting-platform-db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

${SALT}

\$table_prefix = 'wp_';
define( 'WP_DEBUG', false );

if ( isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) {
  \$_SERVER['HTTPS'] = 'on';
}

if ( ! defined( 'ABSPATH' ) ) {
  define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHP

chown -R 1000:1000 "$SITE_DIR" >/dev/null 2>&1 || true
find "$SITE_DIR" -type d -exec chmod 755 {} + 2>/dev/null || true
find "$SITE_DIR" -type f -exec chmod 644 {} + 2>/dev/null || true
docker restart "$CONTAINER" >/dev/null

printf '{"success":true,"site_url":"%s"}' "$SITE_URL"
