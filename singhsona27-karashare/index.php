<?php
$isInstalled = file_exists(__DIR__ . '/config.php');
$baseUrl = '';
$iceServers = [['urls' => 'stun:stun.l.google.com:19302'], ['urls' => 'stun:global.stun.twilio.com:3478']];
if ($isInstalled) {
    require __DIR__ . '/config.php';
    $baseUrl = defined('KARASHARE_BASE_URL') ? KARASHARE_BASE_URL : '';
    if (defined('KARASHARE_ICE_SERVERS') && is_array(KARASHARE_ICE_SERVERS)) {
        $iceServers = KARASHARE_ICE_SERVERS;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Karashare - Private P2P file transfer without upload limits</title>
  <meta name="description" content="Karashare sends files and private notes directly between browsers with encrypted peer-to-peer transfer, invite codes, and no cloud storage.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body data-installed="<?php echo $isInstalled ? 'true' : 'false'; ?>" data-base-url="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES); ?>">
  <header class="site-header">
    <a class="brand" href="#top" aria-label="Karashare home">
      <span class="brand-mark">K</span>
      <span>
        <strong>Karashare</strong>
        <small>direct transfer grid</small>
      </span>
    </a>
    <nav aria-label="Main navigation">
      <a href="#transfer">Transfer</a>
      <a href="#security">Security</a>
      <a href="#advanced">Advanced</a>
      <a href="#faq">FAQ</a>
    </nav>
    <a class="header-cta" href="#transfer">Launch transfer</a>
  </header>

  <main id="top">
    <section class="hero" id="transfer">
      <div class="hero-visual" aria-hidden="true">
        <span class="beam beam-one"></span>
        <span class="beam beam-two"></span>
        <span class="node node-one"></span>
        <span class="node node-two"></span>
        <span class="node node-three"></span>
      </div>

      <div class="transfer-console" aria-label="Karashare transfer console">
        <?php if (!$isInstalled): ?>
          <div class="install-warning">
            Run <a href="install.php">install.php</a> after upload to activate signaling.
          </div>
        <?php endif; ?>

        <div class="mode-tabs" role="tablist" aria-label="Transfer mode">
          <button class="mode-tab active" type="button" data-mode="send">Send</button>
          <button class="mode-tab" type="button" data-mode="receive">Receive</button>
        </div>

        <section class="panel active" data-panel="send">
          <label class="drop-zone" for="fileInput">
            <input id="fileInput" type="file" multiple>
            <span class="drop-icon">+</span>
            <strong>Drop files here</strong>
            <small>Nothing is uploaded to Karashare. Your browser streams directly to the receiver.</small>
          </label>

          <textarea id="noteInput" rows="3" placeholder="Optional secure note to send with the transfer"></textarea>

          <div class="controls-grid">
            <label>
              <span>Access phrase</span>
              <input id="passwordInput" type="password" placeholder="Optional password">
            </label>
            <label>
              <span>Transfer label</span>
              <input id="labelInput" type="text" placeholder="e.g. Lagos campaign media">
            </label>
          </div>

          <button class="primary-action" id="startShare" type="button">Create secure link</button>

          <div class="share-card hidden" id="shareCard">
            <div>
              <small>Invite code</small>
              <strong id="shareCode">------</strong>
            </div>
            <button id="copyLink" type="button">Copy link</button>
            <div id="qrCode" class="qr-code" aria-label="Share QR code"></div>
            <p id="shareLink"></p>
          </div>
        </section>

        <section class="panel" data-panel="receive">
          <div class="receive-box">
            <label>
              <span>Paste invite code</span>
              <input id="receiveCode" type="text" inputmode="latin" autocomplete="off" placeholder="KARA-">
            </label>
            <label>
              <span>Access phrase</span>
              <input id="receivePassword" type="password" placeholder="If required">
            </label>
            <button class="primary-action" id="joinShare" type="button">Join encrypted transfer</button>
          </div>
        </section>

        <div class="status-stream" id="statusStream" aria-live="polite">
          <span class="pulse"></span>
          <span id="statusText">Ready to create a private route.</span>
        </div>

        <div class="file-list" id="fileList"></div>
      </div>

      <div class="hero-copy">
        <p class="eyebrow">Zero-cloud transfer for serious files</p>
        <h1>Share huge files directly from device to device.</h1>
        <p class="lead">Karashare combines browser-to-browser WebRTC transfer, encrypted channels, invite codes, optional password gates, secure notes, and multi-file uploads in one fast private workspace.</p>
        <div class="hero-actions">
          <a class="solid-link" href="#transfer">Start sharing</a>
          <a class="ghost-link" href="#security">See privacy model</a>
        </div>
        <div class="signal-stats" aria-label="Karashare highlights">
          <span>No file size cap</span>
          <span>P2P WebRTC</span>
          <span>No accounts</span>
          <span>Encrypted route</span>
        </div>
      </div>
    </section>

    <section class="trust-band" aria-label="Karashare promise">
      <div>
        <strong>Files stay on your device</strong>
        <span>Karashare uses the server only to introduce browsers. Payloads travel through the peer channel.</span>
      </div>
      <div>
        <strong>Built for hosting.karacraft.ng</strong>
        <span>Upload, run the installer, and the PHP signal endpoint prepares the transfer workspace.</span>
      </div>
      <div>
        <strong>Designed for speed</strong>
        <span>Chunked transfer, live progress, and receiver-triggered downloads keep the experience direct.</span>
      </div>
    </section>

    <section class="section-grid" id="security">
      <div class="section-copy">
        <p class="eyebrow">Security architecture</p>
        <h2>Private by design, impressive by default.</h2>
        <p>Traditional sharing uploads your data into storage first. Karashare instead creates a temporary handshake, opens an encrypted browser channel, and streams the data while both people remain connected.</p>
      </div>
      <div class="feature-stack">
        <article>
          <span>01</span>
          <h3>Ephemeral sessions</h3>
          <p>Invite codes expire automatically, and the included cleanup task removes stale signaling data.</p>
        </article>
        <article>
          <span>02</span>
          <h3>Optional access phrase</h3>
          <p>Protect a transfer so only someone with the code and phrase can begin the route.</p>
        </article>
        <article>
          <span>03</span>
          <h3>Encrypted WebRTC transport</h3>
          <p>Browser-native DTLS/SRTP protects the peer connection while the server never receives file payloads.</p>
        </article>
      </div>
    </section>

    <section class="advanced" id="advanced">
      <p class="eyebrow">Advanced capabilities</p>
      <h2>More than a clone. Karashare feels like a transfer command center.</h2>
      <div class="capability-grid">
        <article><h3>Multi-file sharing</h3><p>Select several files at once and keep each file name intact during delivery.</p></article>
        <article><h3>Secure notes</h3><p>Send passwords, context, instructions, or captions with the same encrypted session.</p></article>
        <article><h3>Scannable QR invite</h3><p>Let mobile receivers scan a real QR code or use the copyable invite link and code.</p></article>
        <article><h3>Live route telemetry</h3><p>Connection state, transfer progress, and receiver readiness appear in real time.</p></article>
        <article><h3>No-login flow</h3><p>Visitors can send or receive instantly without creating an account.</p></article>
        <article><h3>Install check</h3><p>The packaged installer verifies folders, config, and PHP readiness after upload.</p></article>
      </div>
    </section>

    <section class="faq" id="faq">
      <div>
        <p class="eyebrow">FAQ</p>
        <h2>Direct answers for first-time users.</h2>
      </div>
      <details open>
        <summary>Does Karashare store my files?</summary>
        <p>No. The PHP server stores only temporary signaling messages. File data travels through the browser peer connection.</p>
      </details>
      <details>
        <summary>Is there a file size limit?</summary>
        <p>Karashare does not impose a software limit. Real-world limits depend on browser memory, device storage, network quality, and hosting policies.</p>
      </details>
      <details>
        <summary>What happens if I close the browser?</summary>
        <p>The transfer stops because your device is the source. Open the page again and create a new invite code.</p>
      </details>
    </section>
  </main>

  <footer>
    <strong>Karashare</strong>
    <span>Private file movement for Karacraft.</span>
  </footer>

  <script>
    window.KARASHARE_ICE_SERVERS = <?php echo json_encode($iceServers); ?>;
  </script>
  <script src="assets/vendor/qrcode.min.js"></script>
  <script src="assets/app.js?v=20260617-3"></script>
</body>
</html>
