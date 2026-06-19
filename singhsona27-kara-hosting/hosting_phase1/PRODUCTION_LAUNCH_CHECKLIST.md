# Karacraft Hosting Production Launch Checklist

Use this before accepting real paying customers.

## Required Before Launch

- Replace `APP_KEY` in `.env` with a long random value. Do not reuse the example value.
- Configure SMTP in `.env` and test customer/admin password reset.
- Confirm Cloudflare Tunnel routes:
  - `hosting.karacraft.ng` -> `http://umbrel.local:8088`
  - `db.karacraft.ng` -> `http://umbrel.local:8088`
  - `*.karacraft.ng` -> `http://umbrel.local:8088`
- Confirm Cloudflare DNS records exist for:
  - `hosting`
  - `db`
  - wildcard `*`
  - any customer custom domains or CNAMEs.
- Keep Cloudflare SSL mode as Full or Full Strict when the origin supports it.
- Run `sudo ./install.sh` once after every clean deployment.
- Approve one test hosting order and confirm:
  - temporary site opens
  - file manager opens
  - phpMyAdmin opens
  - WordPress installs
  - Moodle reaches installer checks
  - OpenCart reaches installer checks
  - ZIP upload and extract shows a success message.
- Change the default admin password immediately after first login.
- Create a second admin account for emergency access.
- Disable or remove unused admin accounts.

## Customer Safety Checks

- File Manager must only show the customer website root.
- File Manager must not reveal host disk capacity to customers.
- File Manager shell/command features must remain disabled.
- Custom domains must show clear DNS instructions before verification.
- Every customer action should return a success or failure message.
- Delete/reset site actions must require confirmation and must not affect other customers.

## Operations

- Create an off-device backup schedule for `customer_data`, `backups`, `.env`, and the database.
- Test a restore before launch. A backup that has never been restored is only a hope.
- Keep an admin-only change log for patches applied directly on Umbrel.
- Do not edit production files without keeping a matching updated package.
- Rebuild customer PHP runtime after changing `provisioner/customer_php.Dockerfile`.

## Go/No-Go

Do not launch paid plans until these are true:

- New order approval completes without Cloudflare 524 timeout.
- Domain verification and repair no longer produce PHP fatal errors.
- A fresh customer can add a domain using the guided steps without support.
- A fresh customer can install WordPress, upload a ZIP, extract it, and open the site.
- Password reset email is working for both admin and customers.
- You have a written recovery process for a lost Umbrel disk or corrupted database.
