#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
HASHRATE_TH="${1:-$(grep '^EXPECTED_HASHRATE_TH=' .env 2>/dev/null | cut -d= -f2-)}"
HASHRATE_TH="${HASHRATE_TH:-97}"
USER="$(grep '^RPC_USER=' .env | cut -d= -f2-)"
PASS="$(grep '^RPC_PASSWORD=' .env | cut -d= -f2-)"
DIFF="$(docker compose exec -T bch-node bitcoin-cli -datadir=/data -rpcconnect=127.0.0.1 -rpcuser="$USER" -rpcpassword="$PASS" getdifficulty)"
awk -v diff="$DIFF" -v th="$HASHRATE_TH" 'BEGIN {
  seconds = diff * 4294967296 / (th * 1000000000000);
  printf("Hashrate: %.3f TH/s\nBCH difficulty: %.8f\nExpected solo block time: %.3f days (%.3f years)\n", th, diff, seconds/86400, seconds/31557600);
  print "Solo mining is probabilistic: actual time can be much shorter or much longer.";
}'
