#!/usr/bin/env bash
set -euo pipefail
USER_ID="${1:?user required}"
ORDER_ID="${2:?order required}"
DB_NAME="${3:?db required}"
ROOT="${PLATFORM_ROOT:-/home/umbrel/umbrel/home/Documents/hosting_phase1}"
BACKUP_DIR="/backups"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
SITE_DIR="/customer_data/customer_${USER_ID}_${ORDER_ID}/site"
SITE_BACKUP="${BACKUP_DIR}/customer_${USER_ID}_${ORDER_ID}_site_${STAMP}.tar.gz"
DB_BACKUP="${BACKUP_DIR}/customer_${USER_ID}_${ORDER_ID}_db_${STAMP}.sql"
tar -czf "$SITE_BACKUP" -C "$SITE_DIR" . 2>/dev/null || true
docker exec hosting-platform-db mariadb-dump -uroot -pStrongRootPassword123 "$DB_NAME" > "$DB_BACKUP"
SIZE=$(( $(stat -c%s "$SITE_BACKUP" 2>/dev/null || echo 0) + $(stat -c%s "$DB_BACKUP" 2>/dev/null || echo 0) ))
printf '{"site_backup":"%s","db_backup":"%s","size":%s}' "$SITE_BACKUP" "$DB_BACKUP" "$SIZE"
