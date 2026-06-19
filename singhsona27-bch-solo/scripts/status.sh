#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
docker compose ps
echo
docker compose exec bch-node bitcoin-cli -datadir=/data \
  -rpcconnect=127.0.0.1 \
  -rpcuser="$(grep '^RPC_USER=' .env | cut -d= -f2-)" \
  -rpcpassword="$(grep '^RPC_PASSWORD=' .env | cut -d= -f2-)" \
  getblockchaininfo
