# Karacraft Hosting V5, Cloudflare Tunnel + Traefik Build

## Umbrel App

The Umbrel wrapper opens the portal on the app page and keeps the useful local
links in one place. The stack runs from the bundled `hosting_phase1`
directory, so the app page can point you to the portal, phpMyAdmin, and
Traefik without extra hunting.

This version upgrades the V3.1 Hostinger-style hosting platform with V5 security, recovery, admin, domain, and customer upload workflows.

## What is included in V5

- Traefik reverse proxy included in Docker Compose.
- Cloudflare Tunnel can route a wildcard hostname once, instead of one tunnel entry per customer.
- Customer website domains are generated automatically:
  - `site-USERID-ORDERID.umbrel2.karacraft.ng`
- Customer FileBrowser domains are generated automatically:
  - `files-USERID-ORDERID.umbrel2.karacraft.ng`
- Customer ZIP upload and extract with automatic permission repair.
- Customer storage/database usage shows plan quota, not full server capacity.
- FileBrowser is Karacraft branded and hides the used-disk percentage graph.
- FileBrowser customer users are not provisioned as FileBrowser admins.
- Customer and admin forgot-password flows with optional SMTP email delivery.
- Admin user management: create admins, enable/disable, reset passwords, change own password.
- Manual provisioning and gift hosting without payment/order approval.
- Custom domain records with CNAME/TXT verification instructions.
- Customers can add/remove their own custom domains from the dashboard.
- A dedicated customer Domains page appears in the logged-in sidebar.
- Customers can verify/repair custom domains and see DNS, routing, SSL, and Cloudflare status.
- Customer Cloudflare API tokens are encrypted at rest and can be used to sync/repair DNS.
- ZIP uploads can be selected, extracted, permission-fixed, and restarted from the dashboard.
- Customers can create and restore backups for their own hosting account.
- Activity logs for customer, admin, and security-sensitive actions.
- Installer and Compose are `PLATFORM_ROOT` aware for easier Umbrel deployment from any folder.
- Package builder for editable hosting plans.
- Billing records and invoice status tracking.
- Customer support tickets with admin replies.
- Migration request intake and admin migration workflow.
- Backup restore for site and database backup records.
- System health checks for customer containers, storage pressure, and domain configuration.

## Required Cloudflare Tunnel route

In Cloudflare Zero Trust tunnel public hostnames, add one wildcard route:

```text
Hostname: *.umbrel2.karacraft.ng
Service:  http://localhost:8088
```

This single route sends all subdomains to Traefik. Traefik then routes each hostname to the correct Docker container using labels.

Optional direct routes, also handled by the wildcard:

```text
hosting.umbrel2.karacraft.ng -> http://localhost:8088
db.umbrel2.karacraft.ng      -> http://localhost:8088
```

## Fresh Umbrel installation

After extracting the ZIP via Umbrel Files, run:

```bash
cd /home/umbrel/umbrel/home/Documents
sudo chown -R umbrel:umbrel hosting_phase1
sudo chmod -R u+rwX hosting_phase1

cd hosting_phase1
sudo ./install.sh
```

The installer writes the current folder into `.env` as `PLATFORM_ROOT`, so the Docker bind mounts point at the actual V5 folder.

## Change domain defaults

Edit `.env` before install if needed:

```bash
BASE_DOMAIN=umbrel2.karacraft.ng
URL_SCHEME=https
PORTAL_DOMAIN=hosting.umbrel2.karacraft.ng
DB_DOMAIN=db.umbrel2.karacraft.ng
```

## Optional SMTP password recovery

Edit `.env` before install or restart the portal after changing it:

```bash
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=no-reply@example.com
SMTP_PASS=your-app-password
SMTP_FROM=no-reply@example.com
SMTP_FROM_NAME=Karacraft Hosting
```

When SMTP is configured, customer and admin reset links are emailed. If SMTP is not configured or delivery fails, the reset page still shows the link on-screen for local Umbrel recovery.

## Access URLs

Local access:

```text
Portal:      http://umbrel.local:8200
phpMyAdmin:  http://umbrel.local:8201
Traefik:     http://umbrel.local:8089
```

Cloudflare access after wildcard route:

```text
Portal:      https://hosting.umbrel2.karacraft.ng
phpMyAdmin:  https://db.umbrel2.karacraft.ng
Site:        https://site-1-1.umbrel2.karacraft.ng
FileManager: https://files-1-1.umbrel2.karacraft.ng
```

## Important notes

- Cloudflare handles HTTPS at the edge. Traefik receives HTTP from cloudflared on port 8088.
- No customer ports need to be manually added to Cloudflare Tunnel.
- Local ports remain available for troubleshooting.
- If uploaded website files show 403, use Admin > Hosting > Fix Permissions.

## Admin login

```text
Email: admin@hosting.local
Password: use the saved admin password from the existing baseline
```

## V3 Permission Fixer

FileBrowser uploads can create files that Apache cannot read, resulting in `403 Forbidden` even when `index.html` exists. V3 includes two protections:

1. `provisioner/fix_site_permissions.sh`, used by the admin/user restart and fix-permissions actions.
2. `hosting-platform-permission-fixer`, an Alpine sidecar that normalizes ownership and permissions inside `customer_data` every 60 seconds.

Canonical permissions:

```bash
chown -R 1000:1000 customer_data
find customer_data -type d -exec chmod 755 {} +
find customer_data -type f -exec chmod 644 {} +
```

After replacing files in FileBrowser, wait up to 60 seconds or click **Fix Permissions / Restart Site**.

## V5 Customer Self-Service Controls

Customer dashboard now includes self-service actions for each active hosting account:

- Restart Website
- Restart File Manager
- Fix Website Permissions
- Reset File Manager Password
- ZIP Upload + Extract

The Restart Website action runs `provisioner/fix_site_permissions.sh` before restarting the site container, which prevents Apache 403 errors after a customer uploads or replaces `index.html` through FileBrowser.

Customer actions are restricted to the authenticated customer's own hosting account by `portal/user_action.php`.

## V5 Admin Additions

- Admin Users: change own password, create admins, enable/disable admins, reset admin passwords.
- Manual Provision: gift hosting or provision hosting for a customer without payment.
- Domains: add custom website/file manager domains and provide DNS verification instructions.
- Customer Dashboard: customers can submit their own website or file manager custom domains.
- Plans: create and edit hosting packages shown on the public/order pages.
- Billing: review invoices and mark them paid or void.
- Support: reply to customer tickets and update ticket status.
- Migrations: track customer site migration requests.
- Backups: restore site or database backups into the selected hosting account.
- System Health: run account checks for containers, quota, and domain configuration.
- Hosting Accounts: restart, fix permissions, reset FileBrowser password, backup, renew, suspend, unsuspend, terminate.
