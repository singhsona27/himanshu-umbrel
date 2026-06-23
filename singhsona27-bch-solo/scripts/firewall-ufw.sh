#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
[ -f .env ] || { echo "Run scripts/init-env.sh first."; exit 1; }
. ./.env

LABEL="${DEPLOY_ID:-singhsona27-bch-solo}"
sudo ufw allow "${STRATUM_PORT}/tcp" comment "BCH solo stratum ${LABEL}"
sudo ufw allow "${BCH_P2P_PORT}/tcp" comment "Bitcoin Cash P2P ${LABEL}"
echo "Dashboard access is handled by Umbrel's app proxy. Keep it private or behind VPN."
