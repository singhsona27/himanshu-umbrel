#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
[ -f .env ] || { echo "Run scripts/init-env.sh first."; exit 1; }
. ./.env

sudo ufw allow "${STRATUM_PORT}/tcp" comment "BSV solo stratum ${DEPLOY_ID}"
sudo ufw allow "${BSV_P2P_PORT}/tcp" comment "BSV P2P ${DEPLOY_ID}"
echo "Dashboard ${DASHBOARD_PORT}/tcp is not opened automatically. Keep it VPN/private, or open it deliberately:"
echo "sudo ufw allow ${DASHBOARD_PORT}/tcp comment 'BSV solo dashboard ${DEPLOY_ID}'"
