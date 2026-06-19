#!/usr/bin/env bash
set -euo pipefail
ACTION="${1:?action required}"
SITE_CONTAINER="${2:-}"
FILE_CONTAINER="${3:-}"
DB_NAME="${4:-}"
DB_USER="${5:-}"
USER_ID="${6:-}"
ORDER_ID="${7:-}"
ROOT="${PLATFORM_ROOT:-/home/umbrel/umbrel/home/Documents/hosting_phase1}"

case "$ACTION" in
  suspend)
    docker stop "$SITE_CONTAINER" >/dev/null 2>&1 || true
    docker stop "$FILE_CONTAINER" >/dev/null 2>&1 || true
    ;;
  unsuspend)
    docker start "$SITE_CONTAINER" >/dev/null
    docker start "$FILE_CONTAINER" >/dev/null
    ;;
  restart_site)
    docker restart "$SITE_CONTAINER" >/dev/null
    ;;
  restart_filebrowser)
    docker restart "$FILE_CONTAINER" >/dev/null
    ;;
  terminate)
    docker rm -f "$SITE_CONTAINER" >/dev/null 2>&1 || true
    docker rm -f "$FILE_CONTAINER" >/dev/null 2>&1 || true
    docker exec hosting-platform-db mariadb -uroot -pStrongRootPassword123 -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; DROP USER IF EXISTS '${DB_USER}'@'%'; FLUSH PRIVILEGES;" >/dev/null
    rm -rf "/customer_data/customer_${USER_ID}_${ORDER_ID}"
    ;;
  *) echo "Unknown action" >&2; exit 1;;
esac
