#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
docker compose ps
echo
USER="$(grep '^RPC_USER=' .env | cut -d= -f2-)"
PASS="$(grep '^RPC_PASSWORD=' .env | cut -d= -f2-)"
docker compose exec dgb-digibyte digibyte-cli -datadir=/data \
  -rpcconnect=127.0.0.1 \
  -rpcuser="$USER" \
  -rpcpassword="$PASS" \
  getblockchaininfo
