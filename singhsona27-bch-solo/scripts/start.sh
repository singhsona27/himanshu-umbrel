#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
[ -f .env ] || ./scripts/init-env.sh
ADDRESS="$(grep '^BCH_MINING_ADDRESS=' .env | cut -d= -f2- || true)"
if [ -z "$ADDRESS" ] || [ "$ADDRESS" = "CHANGE_ME_BCH_LEGACY_ADDRESS" ]; then
  echo "Edit .env and set BCH_MINING_ADDRESS to a legacy-format BCH address."
  exit 64
fi
docker compose config -q
docker compose build
docker compose up -d
docker compose ps
