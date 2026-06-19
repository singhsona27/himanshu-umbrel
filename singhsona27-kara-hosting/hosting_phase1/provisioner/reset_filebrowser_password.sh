#!/usr/bin/env bash
set -euo pipefail

FILE_CONTAINER="${1:?container required}"
FB_USER="${2:?username required}"
FB_PASS="${3:?password required}"

docker pull filebrowser/filebrowser:latest >/dev/null 2>&1 || true

# FileBrowser locks /database/filebrowser.db while the server is running.
# Use a temporary container with --volumes-from to modify the DB safely.
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

echo "{\"success\":true,\"filebrowser_username\":\"${FB_USER}\",\"filebrowser_password\":\"${FB_PASS}\"}"
