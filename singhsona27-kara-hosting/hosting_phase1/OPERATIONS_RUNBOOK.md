# Karacraft Hosting Operations Runbook

## Daily Checks

Run on Umbrel:

```bash
cd /home/umbrel/umbrel/home/Documents/hosting_phase1
sudo docker compose ps
sudo docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
sudo docker logs --tail=80 hosting-platform-portal
sudo docker logs --tail=80 hosting-platform-traefik
```

Open:

- `https://hosting.karacraft.ng`
- `https://db.karacraft.ng`
- one temporary customer domain such as `site-1-1.karacraft.ng`
- one custom customer domain if available.

## Clean Redeploy

Upload the zip to Umbrel Documents, then run:

```bash
cd /home/umbrel/umbrel/home/Documents
sudo rm -rf hosting_phase1 /tmp/kc_final
mkdir -p /tmp/kc_final
python3 - <<'PY'
import zipfile
from pathlib import Path
zip_path = Path("/home/umbrel/umbrel/home/Documents/karacraft_hosting_enterprise_20260618.zip")
with zipfile.ZipFile(zip_path) as z:
    z.extractall("/tmp/kc_final")
PY
sudo mv /tmp/kc_final/hosting_phase1 ./hosting_phase1
cd hosting_phase1
cp .env.example .env
nano .env
chmod +x install.sh provisioner/*.sh
sudo ./install.sh
```

## Cloudflare Tunnel Rules

Recommended public hostnames:

```text
hosting.karacraft.ng  -> http://umbrel.local:8088
db.karacraft.ng       -> http://umbrel.local:8088
*.karacraft.ng        -> http://umbrel.local:8088
```

If `karashare.karacraft.ng` or another custom subdomain shows `DNS_PROBE_FINISHED_NXDOMAIN`, add a Cloudflare DNS CNAME:

```text
Name: karashare
Target: site-1-1.karacraft.ng
Proxy: On
```

## When Approval Times Out

Cloudflare 524 means the browser waited too long for the origin. The package now builds the customer PHP image during install so approval should be fast. If it still happens:

```bash
cd /home/umbrel/umbrel/home/Documents/hosting_phase1
sudo docker build -t karacraft/php-apache-hosting:8.3 -f provisioner/customer_php.Dockerfile provisioner
sudo docker logs --tail=120 hosting-platform-portal
```

Refresh the admin orders page. If the order already provisioned, do not approve it again.

## Emergency Restart

```bash
cd /home/umbrel/umbrel/home/Documents/hosting_phase1
sudo docker compose restart
```

Restart one customer website:

```bash
sudo docker restart customer-USERID-ORDERID-site
```

Restart one customer File Manager:

```bash
sudo docker restart customer-USERID-ORDERID-filebrowser
```

## Backup Minimum

Back up these before upgrades:

```text
/home/umbrel/umbrel/home/Documents/hosting_phase1/.env
/home/umbrel/umbrel/home/Documents/hosting_phase1/customer_data
/home/umbrel/umbrel/home/Documents/hosting_phase1/backups
hosting_platform database dump
```

Database dump:

```bash
cd /home/umbrel/umbrel/home/Documents/hosting_phase1
sudo docker exec hosting-platform-db mariadb-dump -uroot -pStrongRootPassword123 hosting_platform > backups/platform_$(date +%F_%H%M).sql
```
