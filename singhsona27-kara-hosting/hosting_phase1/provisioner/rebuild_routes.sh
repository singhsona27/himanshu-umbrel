#!/usr/bin/env bash
set -euo pipefail

TYPE="${1:?type required}"
USER_ID="${2:?user required}"
ORDER_ID="${3:?order required}"
CONTAINER="${4:?container required}"
PORT="${5:?port required}"
TEMP_DOMAIN="${6:?temporary domain required}"
CUSTOM_DOMAINS="${7:-}"

NETWORK="hosting_net"
ROOT="${PLATFORM_ROOT:-/home/umbrel/umbrel/home/Documents/hosting_phase1}"
CUSTOMER_IMAGE="${CUSTOMER_IMAGE:-karacraft/php-apache-hosting:8.3}"
SITE_VOL="${ROOT}/customer_data/customer_${USER_ID}_${ORDER_ID}/site"
MOODLE_VOL="${ROOT}/customer_data/customer_${USER_ID}_${ORDER_ID}/moodledata"
FB_VOL="${ROOT}/customer_data/customer_${USER_ID}_${ORDER_ID}/filebrowser"
LOCAL_MOODLE_VOL="/customer_data/customer_${USER_ID}_${ORDER_ID}/moodledata"
LOCAL_FB_VOL="/customer_data/customer_${USER_ID}_${ORDER_ID}/filebrowser"

host_rule="Host(\`${TEMP_DOMAIN}\`)"
IFS=',' read -ra domains <<< "$CUSTOM_DOMAINS"
for domain in "${domains[@]}"; do
  domain="$(echo "$domain" | xargs)"
  if [ -n "$domain" ]; then
    host_rule="${host_rule} || Host(\`${domain}\`)"
  fi
done

docker network inspect "$NETWORK" >/dev/null 2>&1 || docker network create "$NETWORK" >/dev/null
docker rm -f "$CONTAINER" >/dev/null 2>&1 || true

if [ "$TYPE" = "website" ]; then
  docker image inspect "$CUSTOMER_IMAGE" >/dev/null 2>&1 || docker build -t "$CUSTOMER_IMAGE" -f /provisioner/customer_php.Dockerfile /provisioner >/dev/null
  mkdir -p "$LOCAL_MOODLE_VOL"
  chown -R 1000:1000 "$LOCAL_MOODLE_VOL" >/dev/null 2>&1 || true
  chmod -R 770 "$LOCAL_MOODLE_VOL" >/dev/null 2>&1 || true
  ROUTER="kc_site_${USER_ID}_${ORDER_ID}"
  SERVICE="kc_site_${USER_ID}_${ORDER_ID}"
  docker run -d \
    --name "$CONTAINER" \
    --restart unless-stopped \
    --network "$NETWORK" \
    -p "${PORT}:80" \
    --label "traefik.enable=true" \
    --label "traefik.docker.network=${NETWORK}" \
    --label "traefik.http.routers.${ROUTER}.rule=${host_rule}" \
    --label "traefik.http.routers.${ROUTER}.entrypoints=web" \
    --label "traefik.http.services.${SERVICE}.loadbalancer.server.port=80" \
    -v "${SITE_VOL}:/var/www/html" \
    -v "${MOODLE_VOL}:/var/www/moodledata" \
    "$CUSTOMER_IMAGE" >/dev/null
elif [ "$TYPE" = "filemanager" ]; then
  mkdir -p "$LOCAL_FB_VOL"
  ROUTER="kc_files_${USER_ID}_${ORDER_ID}"
  SERVICE="kc_files_${USER_ID}_${ORDER_ID}"
  docker run -d \
    --name "$CONTAINER" \
    --restart unless-stopped \
    --network "$NETWORK" \
    -p "${PORT}:80" \
    --label "traefik.enable=true" \
    --label "traefik.docker.network=${NETWORK}" \
    --label "traefik.http.routers.${ROUTER}.rule=${host_rule}" \
    --label "traefik.http.routers.${ROUTER}.entrypoints=web" \
    --label "traefik.http.services.${SERVICE}.loadbalancer.server.port=80" \
    -v "${SITE_VOL}:/srv" \
    -v "${FB_VOL}:/database" \
    filebrowser/filebrowser:latest \
    --database /database/filebrowser.db \
    --root /srv >/dev/null
else
  echo "Unknown route type: $TYPE" >&2
  exit 1
fi

printf '{"success":true,"type":"%s","container":"%s","rule":"%s"}' "$TYPE" "$CONTAINER" "$host_rule"
