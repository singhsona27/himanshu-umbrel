# Karashare

## Umbrel App

The Umbrel wrapper opens the local site through the app page. Use the built-in
installer once to generate the runtime config and writable storage, then the
homepage link in Umbrel will take you straight into file sharing.

Karashare is a self-contained PHP + browser WebRTC file sharing site inspired by private peer-to-peer transfer tools, with a distinct high-tech homepage and extra transfer features.

## Features

- Browser-to-browser file transfer using WebRTC data channels
- Temporary PHP signaling endpoint
- No account flow
- Invite code and share link
- Scannable QR invite
- Optional access phrase
- Multiple file selection
- Optional secure note
- Live status and progress UI
- Install page for shared hosting

## Deployment

1. Upload the contents of the package to your hosting directory, for example `public_html` or the folder mapped to `hosting.karacraft.ng`.
2. Visit `https://hosting.karacraft.ng/install.php`.
3. Confirm the detected URL and click **Install now**.
4. Open the homepage and test a send/receive flow in two browser windows.
5. Delete `install.php` after installation.

## Hosting Notes

Karashare requires PHP 7.4+ and writable permissions for `storage/` and `storage/sessions/`. WebRTC works best over HTTPS, so SSL must be enabled on the domain.

The server stores only temporary signaling messages. Files are not uploaded to the server.

## Mobile Data and Strict Networks

WebRTC can connect directly on many networks using STUN. Some mobile networks, office firewalls, and carrier-grade NAT setups require a TURN relay. After installation, edit `config.php` and add your TURN server entry to `KARASHARE_ICE_SERVERS` for the most reliable Windows-to-mobile transfers.
