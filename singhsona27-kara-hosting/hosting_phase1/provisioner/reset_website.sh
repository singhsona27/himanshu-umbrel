#!/usr/bin/env bash
set -euo pipefail

SITE_DIR="${1:?site dir required}"
DB_NAME="${2:?db name required}"
DB_USER="${3:?db user required}"
DB_PASS="${4:?db pass required}"
CONTAINER="${5:?site container required}"

case "$SITE_DIR" in
  /customer_data/customer_*_*/site) ;;
  *)
    echo "Unsafe site path: $SITE_DIR" >&2
    exit 1
    ;;
esac

CUSTOMER_ROOT="$(dirname "$SITE_DIR")"
mkdir -p "$SITE_DIR"

find "$SITE_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
rm -rf "$CUSTOMER_ROOT/moodledata" "$CUSTOMER_ROOT/appdata" "$CUSTOMER_ROOT/tmp"

docker exec hosting-platform-db mariadb -uroot -pStrongRootPassword123 -e "
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
"

cat > "$SITE_DIR/index.html" <<'HTML'
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Karacraft Hosting</title></head>
<body>
  <h1>Fresh website ready</h1>
  <p>Your website files and database have been reset. Install an app or upload new files.</p>
</body>
</html>
HTML

chown -R 1000:1000 "$SITE_DIR" >/dev/null 2>&1 || true
find "$SITE_DIR" -type d -exec chmod 755 {} + 2>/dev/null || true
find "$SITE_DIR" -type f -exec chmod 644 {} + 2>/dev/null || true
docker restart "$CONTAINER" >/dev/null

printf '{"success":true,"site_dir":"%s","database":"%s"}' "$SITE_DIR" "$DB_NAME"
