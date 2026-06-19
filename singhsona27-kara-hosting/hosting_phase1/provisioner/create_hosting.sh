#!/usr/bin/env bash
set -euo pipefail

USER_ID="${1:?USER_ID required}"
ORDER_ID="${2:?ORDER_ID required}"
PLAN="${3:-starter}"

NETWORK="hosting_net"
ROOT="${PLATFORM_ROOT:-/home/umbrel/umbrel/home/Documents/hosting_phase1}"
BASE_DOMAIN="${BASE_DOMAIN:-umbrel2.karacraft.ng}"
URL_SCHEME="${URL_SCHEME:-https}"
LOCAL_HOST="http://umbrel.local"
CUSTOMER_IMAGE="${CUSTOMER_IMAGE:-karacraft/php-apache-hosting:8.3}"

SITE_CONTAINER="customer-${USER_ID}-${ORDER_ID}-site"
FILE_CONTAINER="customer-${USER_ID}-${ORDER_ID}-filebrowser"
SITE_PORT=$((8300 + ORDER_ID))
FILE_PORT=$((8400 + ORDER_ID))

SITE_SLUG="site-${USER_ID}-${ORDER_ID}"
FILES_SLUG="files-${USER_ID}-${ORDER_ID}"
SITE_DOMAIN="${SITE_SLUG}.${BASE_DOMAIN}"
FILE_DOMAIN="${FILES_SLUG}.${BASE_DOMAIN}"
SITE_URL="${URL_SCHEME}://${SITE_DOMAIN}"
FILE_URL="${URL_SCHEME}://${FILE_DOMAIN}"
PHPMYADMIN_URL="${URL_SCHEME}://${DB_DOMAIN:-db.${BASE_DOMAIN}}"

SITE_ROUTER="kc_site_${USER_ID}_${ORDER_ID}"
SITE_SERVICE="kc_site_${USER_ID}_${ORDER_ID}"
FILE_ROUTER="kc_files_${USER_ID}_${ORDER_ID}"
FILE_SERVICE="kc_files_${USER_ID}_${ORDER_ID}"

DB_NAME="cust_${USER_ID}_${ORDER_ID}_db"
DB_USER="cust_${USER_ID}_${ORDER_ID}_user"
DB_PASS="$(openssl rand -hex 8)"
FB_USER="fb_${USER_ID}_${ORDER_ID}"
FB_PASS="$(openssl rand -hex 8)"

SITE_VOL="${ROOT}/customer_data/customer_${USER_ID}_${ORDER_ID}/site"
MOODLE_VOL="${ROOT}/customer_data/customer_${USER_ID}_${ORDER_ID}/moodledata"
FB_VOL="${ROOT}/customer_data/customer_${USER_ID}_${ORDER_ID}/filebrowser"
LOCAL_SITE_VOL="/customer_data/customer_${USER_ID}_${ORDER_ID}/site"
LOCAL_MOODLE_VOL="/customer_data/customer_${USER_ID}_${ORDER_ID}/moodledata"
LOCAL_FB_VOL="/customer_data/customer_${USER_ID}_${ORDER_ID}/filebrowser"

mkdir -p "$LOCAL_SITE_VOL" "$LOCAL_MOODLE_VOL" "$LOCAL_FB_VOL"

# Keep first-time Docker builds/pulls from polluting stdout before the final JSON.
docker image inspect "$CUSTOMER_IMAGE" >/dev/null 2>&1 || docker build -t "$CUSTOMER_IMAGE" -f /provisioner/customer_php.Dockerfile /provisioner >/dev/null
docker pull filebrowser/filebrowser:latest >/dev/null 2>&1 || true

if [ ! -f "$LOCAL_SITE_VOL/index.php" ] && [ ! -f "$LOCAL_SITE_VOL/index.html" ]; then
cat > "$LOCAL_SITE_VOL/index.html" <<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Karacraft Hosting</title></head>
<body>
  <h1>Karacraft Hosting</h1>
  <p>Your website is live. Upload your files with File Manager.</p>
</body>
</html>
HTML
fi

chown -R 1000:1000 "$LOCAL_SITE_VOL" >/dev/null 2>&1 || true
chown -R 1000:1000 "$LOCAL_MOODLE_VOL" >/dev/null 2>&1 || true
chmod -R 755 "$LOCAL_SITE_VOL" >/dev/null 2>&1 || true
chmod -R 770 "$LOCAL_MOODLE_VOL" >/dev/null 2>&1 || true
find "$LOCAL_SITE_VOL" -type f -exec chmod 644 {} \; >/dev/null 2>&1 || true

docker network inspect "$NETWORK" >/dev/null 2>&1 || docker network create "$NETWORK" >/dev/null

docker exec hosting-platform-db mariadb -uroot -pStrongRootPassword123 -e "
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
"

docker rm -f "$SITE_CONTAINER" >/dev/null 2>&1 || true
docker rm -f "$FILE_CONTAINER" >/dev/null 2>&1 || true

docker run -d \
  --name "$SITE_CONTAINER" \
  --restart unless-stopped \
  --network "$NETWORK" \
  -p ${SITE_PORT}:80 \
  --label "traefik.enable=true" \
  --label "traefik.docker.network=${NETWORK}" \
  --label "traefik.http.routers.${SITE_ROUTER}.rule=Host(\`${SITE_DOMAIN}\`)" \
  --label "traefik.http.routers.${SITE_ROUTER}.entrypoints=web" \
  --label "traefik.http.services.${SITE_SERVICE}.loadbalancer.server.port=80" \
  -v ${SITE_VOL}:/var/www/html \
  -v ${MOODLE_VOL}:/var/www/moodledata \
  "$CUSTOMER_IMAGE" >/dev/null

docker run -d \
  --name "$FILE_CONTAINER" \
  --restart unless-stopped \
  --network "$NETWORK" \
  -p ${FILE_PORT}:80 \
  --label "traefik.enable=true" \
  --label "traefik.docker.network=${NETWORK}" \
  --label "traefik.http.routers.${FILE_ROUTER}.rule=Host(\`${FILE_DOMAIN}\`)" \
  --label "traefik.http.routers.${FILE_ROUTER}.entrypoints=web" \
  --label "traefik.http.services.${FILE_SERVICE}.loadbalancer.server.port=80" \
  -v ${SITE_VOL}:/srv \
  -v ${FB_VOL}:/database \
  filebrowser/filebrowser:latest \
  --database /database/filebrowser.db \
  --root /srv >/dev/null

sleep 5

# FileBrowser locks /database/filebrowser.db while running. Do not use docker exec
# for user creation. Stop it, update the DB through a temporary container, then start it.
docker stop "$FILE_CONTAINER" >/dev/null

docker run --rm \
  --volumes-from "$FILE_CONTAINER" \
  filebrowser/filebrowser:latest \
  config set \
  --branding.name "Karacraft Files" \
  --branding.disableExternal \
  --branding.disableUsedPercentage \
  --disableExec \
  --commands "" \
  --database /database/filebrowser.db >/dev/null 2>&1 || true

docker run --rm \
  --volumes-from "$FILE_CONTAINER" \
  filebrowser/filebrowser:latest \
  users add "$FB_USER" "$FB_PASS" \
  --perm.create --perm.modify --perm.delete --perm.rename --perm.share --perm.download --perm.execute=false \
  --database /database/filebrowser.db >/dev/null 2>&1 || true

docker run --rm \
  --volumes-from "$FILE_CONTAINER" \
  filebrowser/filebrowser:latest \
  users update "$FB_USER" \
  --password "$FB_PASS" \
  --perm.create --perm.modify --perm.delete --perm.rename --perm.share --perm.download --perm.execute=false \
  --database /database/filebrowser.db >/dev/null 2>&1 || true

docker start "$FILE_CONTAINER" >/dev/null

cat <<JSON
{"site_url":"${SITE_URL}","filebrowser_url":"${FILE_URL}","phpmyadmin_url":"${PHPMYADMIN_URL}","local_site_url":"${LOCAL_HOST}:${SITE_PORT}","local_filebrowser_url":"${LOCAL_HOST}:${FILE_PORT}","db_name":"${DB_NAME}","db_user":"${DB_USER}","db_password":"${DB_PASS}","container_name":"${SITE_CONTAINER}","filebrowser_container":"${FILE_CONTAINER}","filebrowser_username":"${FB_USER}","filebrowser_password":"${FB_PASS}","site_port":"${SITE_PORT}","filebrowser_port":"${FILE_PORT}","site_domain":"${SITE_DOMAIN}","filebrowser_domain":"${FILE_DOMAIN}","domain_mode":"cloudflare_traefik"}
JSON
