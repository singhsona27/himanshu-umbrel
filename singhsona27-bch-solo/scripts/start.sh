#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
[ -f .env ] || ./scripts/init-env.sh
if grep -q '^BCH_MINING_ADDRESS=CHANGE_ME_BCH_LEGACY_ADDRESS' .env; then
  echo "Edit .env and set BCH_MINING_ADDRESS to a legacy-format BCH address."
  exit 64
fi
docker compose config -q
docker compose build
docker compose up -d
docker compose ps
