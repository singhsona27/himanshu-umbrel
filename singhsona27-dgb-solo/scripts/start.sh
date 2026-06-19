#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
[ -f .env ] || ./scripts/init-env.sh
if grep -q '^DGB_MINING_ADDRESS=CHANGE_ME_DGB_ADDRESS' .env; then
  echo "DGB_MINING_ADDRESS is still unset; the pool will wait until you save one in the dashboard."
fi
docker compose build
docker compose up -d
docker compose ps
