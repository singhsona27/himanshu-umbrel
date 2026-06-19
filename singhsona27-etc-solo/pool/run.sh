set -eu

if [ -f /config/app.env ]; then
  . /config/app.env
fi

render_config() {
  sed \
    -e "s|\${ETC_POOL_THREADS}|${ETC_POOL_THREADS:-4}|g" \
    -e "s|\${ETC_HTTP_MINING_PORT}|${ETC_HTTP_MINING_PORT:-8888}|g" \
    -e "s|\${ETC_BLOCK_REFRESH}|${ETC_BLOCK_REFRESH:-120ms}|g" \
    -e "s|\${ETC_STATE_REFRESH}|${ETC_STATE_REFRESH:-2s}|g" \
    -e "s|\${ETC_SHARE_DIFFICULTY}|${ETC_SHARE_DIFFICULTY:-8250000000}|g" \
    -e "s|\${ETC_STRATUM_PORT}|${ETC_STRATUM_PORT:-8008}|g" \
    /config/config.template.json > /build/core-pool.json
}

if [ ! -x /build/core-pool ] && [ -x /app/core-pool ]; then
  cp /app/core-pool /build/core-pool
  chmod +x /build/core-pool
fi

if [ ! -x /build/core-pool ]; then
  rm -rf /build/src
  git clone --depth 1 https://github.com/etclabscore/core-pool.git /build/src
  cd /build/src
  go mod download
  CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /build/core-pool .
fi

render_config
exec /build/core-pool /build/core-pool.json
