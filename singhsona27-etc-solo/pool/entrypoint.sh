#!/bin/sh
set -eu

envsubst < /config/config.template.json > /tmp/core-pool.json
exec /app/core-pool /tmp/core-pool.json
