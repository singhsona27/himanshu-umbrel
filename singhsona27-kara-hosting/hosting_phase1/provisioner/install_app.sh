#!/usr/bin/env bash
set -euo pipefail

APP="${1:?app required}"
SITE_DIR="${2:?site dir required}"
DB_NAME="${3:?db name required}"
DB_USER="${4:?db user required}"
DB_PASS="${5:?db pass required}"
SITE_URL="${6:?site url required}"
CONTAINER="${7:?site container required}"

TMP="/tmp/kc_app_${APP}_$$"
mkdir -p "$TMP" "$SITE_DIR"

clear_site() {
  find "$SITE_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
}

finish_install() {
  chown -R 1000:1000 "$SITE_DIR" >/dev/null 2>&1 || true
  find "$SITE_DIR" -type d -exec chmod 755 {} + 2>/dev/null || true
  find "$SITE_DIR" -type f -exec chmod 644 {} + 2>/dev/null || true
  docker restart "$CONTAINER" >/dev/null
  rm -rf "$TMP"
  printf '{"success":true,"app":"%s","site_url":"%s"}' "$APP" "$SITE_URL"
}

case "$APP" in
  wordpress)
    clear_site
    curl -fsSL https://wordpress.org/latest.tar.gz -o "$TMP/app.tgz"
    tar -xzf "$TMP/app.tgz" -C "$TMP"
    cp -a "$TMP/wordpress/." "$SITE_DIR/"
    SALT="$(curl -fsSL https://api.wordpress.org/secret-key/1.1/salt/ || true)"
    [ -z "$SALT" ] && SALT="define('AUTH_KEY', '$(openssl rand -hex 32)');"
    cat > "$SITE_DIR/wp-config.php" <<PHP
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
if ( isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) { \$_SERVER['HTTPS'] = 'on'; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
    ;;
  moodle)
    clear_site
    curl -fsSL https://download.moodle.org/download.php/direct/stable502/moodle-latest-502.tgz -o "$TMP/app.tgz"
    tar -xzf "$TMP/app.tgz" -C "$TMP"
    cp -a "$TMP/moodle/." "$SITE_DIR/"
    mkdir -p "$(dirname "$SITE_DIR")/moodledata"
    chown -R 1000:1000 "$(dirname "$SITE_DIR")/moodledata" >/dev/null 2>&1 || true
    chmod -R 770 "$(dirname "$SITE_DIR")/moodledata" >/dev/null 2>&1 || true
    ;;
  opencart)
    clear_site
    curl -fsSL https://github.com/opencart/opencart/releases/download/4.1.0.3/opencart-4.1.0.3.zip -o "$TMP/app.zip"
    unzip -q "$TMP/app.zip" -d "$TMP"
    if [ -d "$TMP/upload" ]; then cp -a "$TMP/upload/." "$SITE_DIR/"; else cp -a "$TMP/." "$SITE_DIR/"; fi
    [ -f "$SITE_DIR/config-dist.php" ] && cp "$SITE_DIR/config-dist.php" "$SITE_DIR/config.php"
    [ -f "$SITE_DIR/admin/config-dist.php" ] && cp "$SITE_DIR/admin/config-dist.php" "$SITE_DIR/admin/config.php"
    ;;
  joomla)
    clear_site
    curl -fsSL https://downloads.joomla.org/cms/joomla6/6-1-1/Joomla_6-1-1-Stable-Full_Package.zip -o "$TMP/app.zip"
    unzip -q "$TMP/app.zip" -d "$SITE_DIR"
    ;;
  drupal)
    clear_site
    curl -fsSL https://ftp.drupal.org/files/projects/drupal-11.3.11.tar.gz -o "$TMP/app.tgz"
    tar -xzf "$TMP/app.tgz" -C "$TMP"
    cp -a "$TMP"/drupal-*/. "$SITE_DIR/"
    mkdir -p "$SITE_DIR/sites/default/files"
    cp "$SITE_DIR/sites/default/default.settings.php" "$SITE_DIR/sites/default/settings.php" 2>/dev/null || true
    ;;
  grav)
    clear_site
    curl -fsSL https://getgrav.org/download/core/grav/latest -o "$TMP/app.zip"
    unzip -q "$TMP/app.zip" -d "$TMP"
    cp -a "$TMP"/grav*/. "$SITE_DIR/"
    ;;
  *)
    echo "Unknown app: $APP" >&2
    exit 1
    ;;
esac

finish_install
