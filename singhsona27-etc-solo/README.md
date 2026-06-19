# ETC Solo Node + Pool for Umbrel

This package runs a local Ethereum Classic solo-mining stack:

- CoreGeth `v1.12.21` for ETC mainnet.
- ETC Labs Core-Pool for ETChash Stratum mining.
- Redis for pool state.
- A small Umbrel dashboard that proxies the pool API.

## Required Setup

1. Install the app from the `singhsona27` community store.
2. Open the dashboard settings and set `ETC_COINBASE` to your ETC reward address.
3. Restart the app from Umbrel after saving settings.
4. Wait for CoreGeth to sync before pointing miners at it.

## Miner settings

Use your Umbrel LAN IP:

```text
URL: stratum+tcp://UMBrel-LAN-IP:8008
User: 0xYourEtcAddress.x16qe1
Pass: x
```

Use a different worker suffix for the second Jasminer, for example
`0xYourEtcAddress.x16qe2`.

## Tuning notes

`ETC_SHARE_DIFFICULTY=8250000000` is tuned for two Jasminer X16-QE units at
about 3.3 GH/s total. It targets a lightweight local share cadence without
swamping the Umbrel. Share difficulty and vardiff only change accounting and
network chatter; they do not increase the probability of finding an ETC block.
Your chance of hitting a block comes from accepted hashrate, low latency to your
own node, full sync, and good peer connectivity.

Open/forward TCP+UDP `30303` if you want better CoreGeth peer reachability.
