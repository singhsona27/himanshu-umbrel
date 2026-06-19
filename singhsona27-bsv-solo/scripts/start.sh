#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
[ -f .env ] || ./scripts/init-env.sh
docker compose pull
docker compose up -d
docker compose ps
