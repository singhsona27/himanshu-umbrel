#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."

if [ -f .env ]; then
  echo ".env already exists. Move it aside first if you want to regenerate credentials."
  exit 0
fi

cp .env.example .env
PASS="$(openssl rand -base64 36 | tr -d '\n' | sed 's/[\/&]/_/g')"
DASHPASS="$(openssl rand -base64 36 | tr -d '\n' | sed 's/[\/&]/_/g')"
sed -i "0,/CHANGE_ME_GENERATE_A_LONG_RANDOM_VALUE/s//${PASS}/" .env
sed -i "0,/CHANGE_ME_GENERATE_A_LONG_RANDOM_VALUE/s//${DASHPASS}/" .env
echo "Created .env with a random RPC password."
echo "Created dashboard login from DASHBOARD_USER / DASHBOARD_PASSWORD in .env."
echo "Edit .env before starting if this is your second deployment on the same server."
