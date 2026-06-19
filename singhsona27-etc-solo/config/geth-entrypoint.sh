set -eu

if [ -f /config/app.env ]; then
  . /config/app.env
fi

if [ -z "${ETC_COINBASE:-}" ]; then
  echo "Set ETC_COINBASE in the app settings before mining."
  sleep 3600
  exit 1
fi

exec geth \
  --classic \
  --syncmode=snap \
  --cache="${GETH_CACHE_MB:-4096}" \
  --maxpeers="${GETH_MAX_PEERS:-100}" \
  --http \
  --http.addr=0.0.0.0 \
  --http.port=8545 \
  --http.api=eth,net,web3 \
  --http.vhosts='*' \
  --http.corsdomain='*' \
  --mine \
  --miner.threads=0 \
  --miner.etherbase="${ETC_COINBASE}"
