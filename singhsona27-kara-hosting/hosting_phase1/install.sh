#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"
mkdir -p customer_data backups
chmod +x provisioner/*.sh

if [ ! -f .env ]; then
  cp .env.example .env
fi

if ! grep -q '^PLATFORM_ROOT=' .env; then
  echo "PLATFORM_ROOT=$SCRIPT_DIR" >> .env
else
  sed -i "s#^PLATFORM_ROOT=.*#PLATFORM_ROOT=$SCRIPT_DIR#" .env
fi

if grep -q '^APP_KEY=change-this-long-random-secret-before-production' .env; then
  sed -i "s#^APP_KEY=.*#APP_KEY=$(openssl rand -hex 32)#" .env
elif ! grep -q '^APP_KEY=' .env; then
  echo "APP_KEY=$(openssl rand -hex 32)" >> .env
fi

if ! docker ps >/dev/null 2>&1; then
  echo "Docker is not accessible for this user/session. Run this installer with sudo:" >&2
  echo "sudo ./install.sh" >&2
  exit 1
fi

echo "Pre-pulling runtime images silently..."
docker pull traefik:v3.3 >/dev/null 2>&1 || true
docker pull filebrowser/filebrowser:latest >/dev/null 2>&1 || true
docker pull mariadb:11 >/dev/null 2>&1 || true
docker pull phpmyadmin:latest >/dev/null 2>&1 || true
docker pull alpine:3.20 >/dev/null 2>&1 || true
echo "Building customer PHP runtime image..."
docker build -t karacraft/php-apache-hosting:8.3 -f provisioner/customer_php.Dockerfile provisioner >/dev/null

echo "Building and starting Karacraft Hosting V5..."
docker compose up -d --build

echo "Waiting for MariaDB..."
for i in {1..60}; do
  if docker exec hosting-platform-db mariadb -uplatform_user -pStrongPlatformPassword123 hosting_platform -e "SELECT 1" >/dev/null 2>&1; then
    break
  fi
  sleep 2
  if [ "$i" = "60" ]; then echo "Database did not become ready" >&2; exit 1; fi
done

echo "Installing database schema..."
docker exec -i hosting-platform-db mariadb -uplatform_user -pStrongPlatformPassword123 hosting_platform < portal/install.sql

BASE_DOMAIN=$(grep -E '^BASE_DOMAIN=' .env | cut -d= -f2- || true)
BASE_DOMAIN=${BASE_DOMAIN:-umbrel2.karacraft.ng}

echo "Karacraft Hosting V5 is ready."
echo "Local portal: http://umbrel.local:8200"
echo "Traefik HTTP entrypoint for Cloudflare Tunnel: http://localhost:8088"
echo "Traefik local dashboard: http://umbrel.local:8089"
echo "phpMyAdmin local: http://umbrel.local:8201"
echo "Recommended Cloudflare Tunnel public hostname: *.${BASE_DOMAIN} -> http://localhost:8088"
echo "Portal through tunnel: https://hosting.${BASE_DOMAIN}"
echo "DB through tunnel: https://db.${BASE_DOMAIN}"
echo "Admin: admin@hosting.local"
echo "Use the existing saved admin password from your baseline."
