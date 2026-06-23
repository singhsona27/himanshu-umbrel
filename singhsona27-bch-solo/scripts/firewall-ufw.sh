#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
[ -f .env ] || { echo "Run scripts/init-env.sh first."; exit 1; }
. ./.env

sudo ufw allow "${STRATUM_PORT}/tcp" comment "BCH solo stratum ${DEPLOY_ID}"
sudo ufw allow "${BCH_P2P_PORT}/tcp" comment "Bitcoin Cash P2P ${DEPLOY_ID}"
echo "Dashboard ${DASHBOARD_PORT}/tcp is not opened automatically."
echo "Prefer VPN/private access, or deliberately run:"
echo "sudo ufw allow ${DASHBOARD_PORT}/tcp comment 'BCH dashboard ${DEPLOY_ID}'"
