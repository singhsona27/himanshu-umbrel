#!/usr/bin/env bash
set -euo pipefail

BACKUP_TYPE="${1:?backup type required}"
BACKUP_FILE="${2:?backup file required}"
USER_ID="${3:?user required}"
ORDER_ID="${4:?order required}"
DB_NAME="${5:?db required}"

SITE_DIR="/customer_data/customer_${USER_ID}_${ORDER_ID}/site"

if [ ! -f "$BACKUP_FILE" ]; then
  echo "Backup file not found: $BACKUP_FILE" >&2
  exit 1
fi

case "$BACKUP_TYPE" in
  site)
    mkdir -p "$SITE_DIR"
    find "$SITE_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    tar -xzf "$BACKUP_FILE" -C "$SITE_DIR"
    chown -R 1000:1000 "$SITE_DIR" >/dev/null 2>&1 || true
    find "$SITE_DIR" -type d -exec chmod 755 {} + 2>/dev/null || true
    find "$SITE_DIR" -type f -exec chmod 644 {} + 2>/dev/null || true
    ;;
  database)
    docker exec -i hosting-platform-db mariadb -uroot -pStrongRootPassword123 "$DB_NAME" < "$BACKUP_FILE"
    ;;
  *)
    echo "Unknown backup type: $BACKUP_TYPE" >&2
    exit 1
    ;;
esac

printf '{"success":true,"backup_type":"%s","file":"%s"}' "$BACKUP_TYPE" "$BACKUP_FILE"
