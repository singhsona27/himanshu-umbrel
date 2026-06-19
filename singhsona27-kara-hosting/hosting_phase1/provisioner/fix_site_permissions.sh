#!/usr/bin/env bash
set -euo pipefail

SITE_PATH="${1:?site path required}"

if [ ! -d "$SITE_PATH" ]; then
  echo "Error: site path not found: $SITE_PATH" >&2
  exit 1
fi

# webdevops/php-apache runs Apache/PHP as the application user.
# FileBrowser uploads can create permissions that Apache cannot read, causing 403.
chown -R 1000:1000 "$SITE_PATH"
chmod -R 755 "$SITE_PATH"
find "$SITE_PATH" -type f -exec chmod 644 {} \;

echo "{\"success\":true,\"site_path\":\"${SITE_PATH}\"}"
