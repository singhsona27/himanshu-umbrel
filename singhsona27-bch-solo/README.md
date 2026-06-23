# BCH Solo Node + Mining Pool

High-performance Bitcoin Cash solo-mining stack for Umbrel and SHA256d miners such as Avalon Q and Avalon Nano 3S.

It runs:

- Bitcoin Cash Node v29.0.0 from the official BCHN release.
- ckpool compiled with native CPU optimization and ZeroMQ support.
- ZMQ-first new-block notification with a 250ms RPC polling fallback.
- An authenticated, background-cached dashboard with 30-second updates and minimal node/disk overhead.

## Miner Profile

Defaults are tuned for:

- `avalon`: Avalon class miners around 90-110 TH/s.
- `mini`: Avalon Nano 3S around 5-8 TH/s.

Both targets keep share traffic light while providing frequent enough samples for useful hashrate reporting. Share difficulty does not change block-finding probability.

## Quick Start

```bash
cd /home/umbrel/umbrel/app-data/singhsona27-bch-solo
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

Use the host IP of your Umbrel and the published stratum port:

```text
URL: stratum+tcp://YOUR_UMBREL_IP:3335
Password: x
```

ckpool expects the username to start with a valid payout address. Put the worker name after a dot so the dashboard can show each miner separately.

Avalon Q:

```text
URL: stratum+tcp://YOUR_UMBREL_IP:3335
User: BCH_MINING_ADDRESS.avalon
Password: x
```

Avalon Nano 3S:

```text
URL: stratum+tcp://YOUR_UMBREL_IP:3335
User: BCH_MINING_ADDRESS.mini
Password: x
```

The dashboard strips the `BCH_MINING_ADDRESS.` prefix and displays the workers as `avalon` and `mini` in Miner Username Stats.

## Ports

- `3335/tcp`: Stratum.
- `3102/tcp`: Umbrel app proxy/dashboard.
- `8333/tcp`: BCH peer-to-peer.
- `8332/tcp`: RPC, internal Docker network only.
- `28332/tcp`: ZMQ, internal Docker network only.

Never publish RPC or ZMQ to the internet.

## Performance Profile

The defaults prioritize mining responsiveness:

- `BCH_DBCACHE_MB=4096`
- `BCH_PAR=4`
- `BCH_RPC_THREADS=8`
- `BCH_RPC_WORKQUEUE=128`
- `BCH_MAX_CONNECTIONS=128`
- `BCH_MAX_MEMPOOL_MB=512`
- Wallet disabled
- Transaction index disabled
- Native `-O3 -march=native` ckpool build
- ZMQ `pubhashblock` notification
- Quiet ckpool operation with bounded Docker logs

The dashboard refreshes every 30 seconds. Node RPC collection, price lookup, historical payout scanning, and ckpool parsing happen in a background thread. Browser requests only return cached JSON.

Historical payout scanning is incremental and bounded by `BLOCK_SCAN_BATCH`; it cannot stall the dashboard or flood the node with thousands of RPC calls at once.

## Commands

```bash
docker compose ps
./scripts/status.sh
./scripts/odds.sh
docker compose logs -f bch-ckpool
```

Dashboard:

```text
http://YOUR_UMBREL_IP:3102
```

Use `DASHBOARD_USER` and `DASHBOARD_PASSWORD` from `.env`.

## Verification

`./scripts/status.sh` should show:

- `initialblockdownload: false` after synchronization.
- A `pubhashblock` entry from `getzmqnotifications`.
- Healthy `bch-node`, `bch-ckpool`, and `bch-dashboard` containers.

The dashboard should show `ZMQ ACTIVE`, both miner usernames, fresh shares, and node height.

## Firewall

```bash
./scripts/firewall-ufw.sh
```

Keep the dashboard private or behind a VPN where possible.

## Important

Performance tuning cannot guarantee a block or eliminate every stale share. ZMQ minimizes the pool's old-job notification window; miner-to-server latency and work already inside ASIC pipelines can still produce occasional stale submissions.
