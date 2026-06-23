# BCH Solo Performance Deploy

High-performance Bitcoin Cash solo-mining stack for a 4-core, 16GB server and two SHA256d miners.

It runs:

- Bitcoin Cash Node v29.0.0 from the official BCHN release.
- ckpool compiled with native CPU optimization and ZeroMQ support.
- ZMQ-first new-block notification with a 250ms RPC polling fallback.
- An authenticated, background-cached dashboard with minimal node and disk overhead.

## Miner Profile

Defaults are tuned for:

- `avalon`: Avalon Q around 90-95 TH/s, difficulty `218520`.
- `mini`: Avalon Nano 3S around 6-7 TH/s, difficulty `14568`.

Both targets keep share traffic light while providing frequent enough samples for useful hashrate reporting. Share difficulty does not change block-finding probability.

## Quick Start

```bash
unzip bch-sha256-solo-performance-deploy.zip
cd bch-sha256-solo-performance-deploy
chmod +x scripts/*.sh
./scripts/init-env.sh
nano .env
./scripts/start.sh
```

Set a legacy-format BCH payout address before starting:

```dotenv
BCH_MINING_ADDRESS=1YourLegacyBCHAddress
```

ckpool's address parser expects legacy Base58 format. The dashboard matches the payout script directly, so block accounting remains correct even when BCHN displays CashAddr.

## Miner Configuration

Avalon Q:

```text
URL: stratum+tcp://YOUR_PUBLIC_IP:3333
User: avalon
Password: x
```

Avalon Nano 3S:

```text
URL: stratum+tcp://YOUR_PUBLIC_IP:3333
User: mini
Password: x
```

The payout address is fixed by `BCH_MINING_ADDRESS`; worker usernames do not control payout.

## Ports

- `3333/tcp`: Stratum.
- `8095/tcp`: Password-protected dashboard.
- `8333/tcp`: BCH peer-to-peer.
- `8332/tcp`: RPC, internal Docker network only.
- `28332/tcp`: ZMQ, internal Docker network only.

Never publish RPC or ZMQ to the internet.

## Performance Profile

The defaults prioritize mining responsiveness:

- `BCH_DBCACHE_MB=6144`
- `BCH_PAR=4`
- `BCH_RPC_THREADS=8`
- `BCH_RPC_WORKQUEUE=128`
- `BCH_MAX_CONNECTIONS=64`
- `BCH_MAX_MEMPOOL_MB=1024`
- Wallet disabled
- Transaction index disabled
- Native `-O3 -march=native` ckpool build
- ZMQ `pubhashblock` notification
- Quiet ckpool operation with bounded Docker logs

The dashboard refreshes every five minutes. Node RPC collection, price lookup, historical payout scanning, and ckpool parsing happen in a background thread. Browser requests only return cached JSON.

Historical payout scanning is incremental and bounded by `BLOCK_SCAN_BATCH`; it cannot stall the dashboard or flood the node with thousands of RPC calls at once.

## Commands

```bash
docker compose ps
./scripts/status.sh
./scripts/odds.sh
docker compose logs -f ckpool
```

Dashboard:

```text
http://YOUR_PUBLIC_IP:8095
```

Use `DASHBOARD_USER` and `DASHBOARD_PASSWORD` from `.env`.

## Verification

`./scripts/status.sh` should show:

- `initialblockdownload: false` after synchronization.
- A `pubhashblock` entry from `getzmqnotifications`.
- Healthy `bchn`, `ckpool`, and `dashboard` containers.

The dashboard should show `ZMQ ACTIVE`, both miner usernames, fresh shares, and node height.

## Firewall

```bash
./scripts/firewall-ufw.sh
```

Keep the dashboard private or behind a VPN where possible.

## Important

Performance tuning cannot guarantee a block or eliminate every stale share. ZMQ minimizes the pool's old-job notification window; miner-to-server latency and work already inside ASIC pipelines can still produce occasional stale submissions.
