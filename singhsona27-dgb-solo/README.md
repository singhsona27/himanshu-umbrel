# DGB SHA256 Solo Performance Deploy

Fresh DigiByte SHA256d solo-mining deployment package for a public 4-core / 16GB server.

## Umbrel App

The Umbrel wrapper opens the dashboard on the app page and keeps the settings
in the app root `.env` file. Use the settings form to set your DGB payout
address, tune the node, and confirm the readiness checklist before pointing
miners at the displayed Stratum URL.

It runs:

- DigiByte Core v8.26.2 built from the official `DigiByte-Core/digibyte` GitHub release.
- ckpool built locally from source as a fast SHA256d solo stratum proxy.
- Lightweight authenticated professional dashboard.
- Persistent node and pool data under `data/`.

## Reality Check

This improves node correctness, latency, persistence, and solo-pool stability. It cannot guarantee a block. DigiByte uses five mining algorithms; this package mines SHA256d work only.

For your Avalon Q 90 TH/s plus Avalon Nano 7 TH/s, set:

```dotenv
EXPECTED_HASHRATE_TH=97
```

Once running and synced:

```bash
./scripts/odds.sh
```

## Quick Start

```bash
unzip dgb-sha256-solo-performance-deploy.zip
cd dgb-sha256-solo-performance-deploy
chmod +x scripts/*.sh
./scripts/init-env.sh
nano .env
./scripts/start.sh
```

Before `./scripts/start.sh`, set:

```dotenv
DGB_MINING_ADDRESS=YOUR_DGB_PAYOUT_ADDRESS
```

## Same-Server Separate Deployment

Edit `.env` before starting the second copy:

```dotenv
COMPOSE_PROJECT_NAME=dgbsha_solo_2
DEPLOY_ID=dgbsha2
STRATUM_PORT=3365
DASHBOARD_PORT=8105
DGB_P2P_PORT=12124
```

Use a separate folder per deployment so each has its own `data/`.

## Miner Configuration

Set both Avalon miners to:

- Pool URL: `stratum+tcp://YOUR_PUBLIC_IP:3355`
- Worker/User: your DGB payout address, optionally with `.worker`, for example `dgb1qexample.avalonq`
- Password: `x`

The coinbase reward address is `DGB_MINING_ADDRESS` in `.env`.

## Ports

- `3355/tcp`: stratum for your miners.
- `8095/tcp`: dashboard with HTTP basic auth.
- `12024/tcp`: DigiByte P2P.
- `14022/tcp`: DigiByte RPC, internal Docker network only. Do not expose publicly.

## Performance Profile

Defaults are for a 4-core / 16GB server:

- `DGB_DBCACHE_MB=6144`
- `DGB_PAR=3`
- `DGB_RPC_THREADS=8`
- `DGB_RPC_WORKQUEUE=128`
- `DGB_MAX_CONNECTIONS=160`
- `START_DIFF=10000`

The node explicitly enables DigiByte DNS/fixed seeds and seednode hints from DigiByte Core:

- `seed.digibyte.org`
- `seed.digibyteservers.io`
- `seed.digibyte.io`
- `seed.digibyteblockchain.com`
- `dnsseed.esotericizm.site`
- `seed.digibytefoundation.org`
- `seed.digibyte.host`

These help the node discover peers faster during initial block sync. Disk speed and validation CPU still matter more than raw peer count once blocks are flowing.

For about 97 TH/s, `START_DIFF=10000` is a practical starting point. If shares are too rare, lower it. If the server is busy processing too many shares, raise it.

## Commands

```bash
docker compose ps
./scripts/status.sh
docker compose logs -f ckpool
```

Dashboard:

```text
http://YOUR_PUBLIC_IP:8095
```

Login is `DASHBOARD_USER` and `DASHBOARD_PASSWORD` from `.env`.

## Firewall

```bash
./scripts/firewall-ufw.sh
```

Keep dashboard private/VPN where possible. Never expose DigiByte RPC.

## Notes

ckpool uses Bitcoin-style config keys such as `btcd` and `btcaddress`, but the DGB SHA256 solo-mining guide confirms this is expected because ckpool was originally written for Bitcoin.

Seeing non-SHA256 block messages is normal on DigiByte. Your miners keep working on SHA256d jobs while other DigiByte algorithms also produce blocks.

## Sources Checked

- Official DigiByte site lists DigiByte as mineable with Sha256, Scrypt, Skein, Qubit, and Odocrypt.
- DigiByte-Core GitHub currently identifies v8.26.2 as current.
- DGB SHA256 solo-mining guide uses DigiByte Core v8.26.x plus ckpool with RPC port `14022`, P2P port `12024`, and stratum on `3333`.
