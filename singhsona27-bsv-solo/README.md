# BSV Solo Performance Deploy

Fresh BSV solo-mining deployment package for a public 4-core / 16GB server.

## Umbrel App

The Umbrel wrapper opens the dashboard on the app page and keeps the settings
in the app root `.env` file. Use the settings form to set the BSV payout
address, tune the node, and confirm the readiness checklist before pointing
miners at the displayed Stratum URL.

It runs:

- Bitcoin SV Node, using the BSVN Docker image referenced by the BSVN download page.
- BSV solo ckpool, exposed on your selected stratum port.
- Lightweight dashboard, exposed on your selected dashboard port.
- Persistent chain and pool data under `data/`.

## Important Reality Check

This package improves uptime, latency, persistence, and local-node correctness. It cannot guarantee a block. With an Avalon Q at 90 TH/s plus an Avalon Nano at 7 TH/s, you have about 97 TH/s. Your expected block time depends on live BSV difficulty:

```bash
./scripts/odds.sh
```

Solo mining is memoryless. Every hash is independent.

## Quick Start

```bash
unzip bsv-solo-performance-deploy.zip
cd bsv-solo-performance-deploy
chmod +x scripts/*.sh
./scripts/init-env.sh
nano .env
./scripts/start.sh
```

On Windows before uploading to your Linux server:

```powershell
.\scripts\init-env.ps1
notepad .env
```

## Same-Server Second Deployment

Edit `.env` before starting the second copy:

```dotenv
COMPOSE_PROJECT_NAME=bsvsolo_perf_2
DEPLOY_ID=bsvsolo2
STRATUM_PORT=3337
DASHBOARD_PORT=8089
BSV_P2P_PORT=8335
```

Use a separate folder for each deployment so each one has its own `data/`.

## Miner Configuration

Set both Avalon miners to:

- Pool URL: `stratum+tcp://YOUR_PUBLIC_IP:3336`
- Username: your BSV payout address plus a worker suffix, for example `bitcoin:q....avalonq`
- Password: `x`

If your miner or ckpool rejects a `bitcoin:` cashaddr address, try the same BSV address in legacy format. Do not use a BTC address.

## Public Server Firewall

Open only what is required:

```bash
./scripts/firewall-ufw.sh
```

Recommended:

- Public: stratum port, for example `3336/tcp`.
- Public: BSV P2P port, for example `8334/tcp`, optional but good for node connectivity.
- Private/VPN only: dashboard port.
- Never expose BSV RPC port `8332` to the public internet.

## Performance Profile

Defaults in `.env` are tuned for a 4-core / 16GB server:

- `BSVN_DBCACHE_MB=6144`
- `BSVN_PAR=3`
- `BSVN_RPC_THREADS=8`
- `BSVN_RPC_WORKQUEUE=128`
- `BSVN_MAX_CONNECTIONS=96`
- `BSVN_MAX_MEMPOOL_MB=1024`

For this hashrate, the server is not hashing. Its job is fast block templates, low rejection rate, stable networking, and full-node correctness.

## Health Checks

```bash
docker compose ps
./scripts/status.sh
docker compose logs -f ckpool
```

Dashboard:

```text
http://YOUR_PUBLIC_IP:8086
```

Dashboard access is protected by Umbrel's app proxy; there is no extra dashboard password.

## Data and Backups

Persistent data lives in:

- `data/bsvn`: BSV chainstate and node data.
- `data/ckpool`: ckpool logs.

Stop the stack before copying chain data:

```bash
docker compose down
tar -czf bsvsolo-backup.tgz .env data/ckpool
```

The BSV chain can be resynced, but the `.env` and pool logs are worth keeping.

## Sources Checked

- BSVN download page documents Docker use with `zquestz/bitcoin-sv`.
- The upstream ckpool examples use a `btcd` RPC section, `btcsig`, and `serverurl`.
- Public solo ckpool docs confirm miners use stratum plus payout address as username.
