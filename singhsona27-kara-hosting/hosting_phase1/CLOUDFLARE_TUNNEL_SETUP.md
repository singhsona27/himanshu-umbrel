# Cloudflare Tunnel Setup for Karacraft Hosting V5

## Goal

Only one wildcard public hostname should be needed for all customer websites and file managers.

## Add public hostname in Cloudflare Tunnel

Go to:

```text
Cloudflare Zero Trust > Networks > Tunnels > Your Umbrel Tunnel > Public Hostnames
```

Add:

```text
Subdomain: *
Domain:    umbrel2.karacraft.ng
Service:   http://localhost:8088
```

Save.

## How routing works

Cloudflare sends all matching subdomains to:

```text
http://localhost:8088
```

Traefik listens there, reads Docker labels, and routes:

```text
site-1-1.umbrel2.karacraft.ng  -> customer-1-1-site
files-1-1.umbrel2.karacraft.ng -> customer-1-1-filebrowser
hosting.umbrel2.karacraft.ng   -> hosting-platform-portal
db.umbrel2.karacraft.ng        -> hosting-platform-phpmyadmin
```

## Test after install

```bash
sudo docker ps | grep hosting-platform-traefik
sudo docker logs hosting-platform-traefik --tail 50
```

Open:

```text
https://hosting.umbrel2.karacraft.ng
```

Then create and approve a test order. The generated site and file manager domains should work automatically.
