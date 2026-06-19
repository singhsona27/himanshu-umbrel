<?php
require 'config.php';
require_user();
require '_customer_nav.php';

$uid=(int)current_user_id();
$hosts=$pdo->prepare('SELECT * FROM hosting_accounts WHERE user_id=? AND status<>"terminated" ORDER BY id DESC');
$hosts->execute([$uid]);
$hostRows=$hosts->fetchAll();

$domains=$pdo->prepare('SELECT d.*,h.site_domain,h.filebrowser_domain,h.site_url,h.filebrowser_url,h.plan,h.status hosting_status FROM custom_domains d JOIN hosting_accounts h ON h.id=d.hosting_account_id WHERE h.user_id=? ORDER BY d.id DESC');
$domains->execute([$uid]);
$domainRows=$domains->fetchAll();

$cf=$pdo->prepare('SELECT * FROM cloudflare_accounts WHERE user_id=? ORDER BY id DESC LIMIT 1');
$cf->execute([$uid]);
$cfRow=$cf->fetch();
?>
<!doctype html>
<html>
<head>
  <title>Domains</title>
  <link rel="stylesheet" href="/style.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body>
<div class="dash">
<?php customer_nav('domains'); ?>
<div class="main">
  <h1>Domains</h1>
  <?=render_flash()?>

  <div class="step-list">
    <div class="step"><h3><strong>1</strong>Add Domain</h3><p class="muted">Enter your domain and choose which hosting account it should open.</p></div>
    <div class="step"><h3><strong>2</strong>Add DNS</h3><p class="muted">At your domain provider, create the CNAME record shown below.</p></div>
    <div class="step"><h3><strong>3</strong>Verify</h3><p class="muted">Karacraft checks DNS and automatically connects the secure Cloudflare Tunnel route.</p></div>
  </div>

  <div class="guide-shots">
    <div class="guide-shot"><div class="browserbar"></div><h3>Domain Provider</h3><div class="row"><strong>Type</strong><span>CNAME</span></div><div class="row"><strong>Name</strong><span>shown below</span></div><div class="row"><strong>Target</strong><span>site-...karacraft.ng</span></div></div>
    <div class="guide-shot"><div class="browserbar"></div><h3>Works With</h3><div class="row"><strong>Hostinger</strong><span>DNS Zone</span></div><div class="row"><strong>Cloudflare</strong><span>DNS Records</span></div><div class="row"><strong>Others</strong><span>CNAME DNS</span></div></div>
    <div class="guide-shot"><div class="browserbar"></div><h3>Karacraft Verify</h3><div class="row"><strong>DNS</strong><span>Checked</span></div><div class="row"><strong>Tunnel</strong><span>Auto connected</span></div><p class="muted">No admin action is required after DNS is correct.</p></div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Step 1: Add Custom Domain</h2>
      <form class="wide-form" method="post" action="/domain_action.php">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add">
        <label>Hosting account</label>
        <select name="hosting_account_id" required>
          <?php foreach($hostRows as $h): ?>
            <option value="<?=h($h['id'])?>">#<?=h($h['id'])?> <?=h($h['plan'])?> - <?=h($h['site_domain'] ?: $h['site_url'])?></option>
          <?php endforeach; ?>
        </select>
        <label>Domain</label>
        <input name="domain" placeholder="example.com or store.example.com" required>
        <label>Use for</label>
        <select name="domain_type">
          <option value="website">Website</option>
          <option value="filemanager">File Manager</option>
        </select>
        <button class="btn small">Next: Show DNS Instructions</button>
      </form>
    </div>

    <div class="card">
      <h2>Optional: Cloudflare API</h2>
      <p class="muted">If connected, future versions can create DNS records automatically. Manual DNS still works.</p>
      <form class="wide-form" method="post" action="/cloudflare_action.php">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="save">
        <label>API Token</label>
        <input name="api_token" type="password" placeholder="<?=h($cfRow ? 'Saved - enter a new token to replace' : 'Cloudflare API token')?>" <?php if(!$cfRow) echo 'required'; ?>>
        <label>Zone ID optional</label>
        <input name="zone_id" value="<?=h($cfRow['zone_id'] ?? '')?>">
        <button class="btn small">Save Cloudflare</button>
      </form>
      <small>Status: <?=h($cfRow['status'] ?? 'not connected')?> | Last check: <?=h($cfRow['last_check_at'] ?? '-')?></small>
    </div>
  </div>

  <div class="card">
    <h2>Step 2 and 3: Connect Domains</h2>
    <?php if(!$domainRows): ?>
      <p class="muted">No custom domains yet. Add one above and this section will show exact DNS records.</p>
    <?php else: ?>
      <table class="table">
        <tr><th>Domain</th><th>DNS Setup</th><th>Status</th><th>Actions</th></tr>
        <?php foreach($domainRows as $d): ?>
          <tr>
            <td>
              <strong><?=h($d['domain'])?></strong><br>
              <small><?=h($d['domain_type'])?> domain for <?=h($d['site_domain'] ?: $d['site_url'])?></small>
            </td>
            <td>
              <div class="dns-record">
                <strong>Add this at your domain provider</strong>
                <code>Type: CNAME
Name: <?=h(dns_record_name_hint($d['domain']))?>
Target: <?=h($d['dns_target'])?>
Proxy: On if your DNS provider has proxy mode
TTL: Auto</code>
              </div>
              <?php if(!is_platform_domain($d['domain'])): ?>
              <div class="dns-record">
                <strong>Karacraft automation</strong>
                <code>When you click Verify:
1. DNS is checked
2. Website routing is rebuilt
3. Cloudflare Tunnel hostname is added automatically</code>
              </div>
              <small class="muted">You only need to update DNS at your registrar or DNS provider. Karacraft handles the server-side Cloudflare Tunnel route.</small>
              <?php endif; ?>
              <div class="dns-record">
                <strong>Optional TXT verification</strong>
                <code>Type: TXT
Name: <?=h(dns_record_name_hint($d['domain']))?>
Content: <?=h($d['verification_token'])?></code>
              </div>
            </td>
            <td>
              <span class="badge <?=h($d['verification_status'])?>"><?=h($d['verification_status'])?></span><br>
              <small>Routing: <?=h($d['routing_status'] ?: 'pending')?><br>Cloudflare: <?=h($d['cloudflare_status'] ?: 'pending')?><br>SSL: <?=h($d['ssl_status'] ?: 'pending')?><br>Last check: <?=h($d['last_checked_at'] ?: '-')?></small>
            </td>
            <td>
              <form class="inline" method="post" action="/domain_action.php"><?=csrf_field()?><input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?=h($d['id'])?>"><button class="btn small">Verify</button></form>
              <form class="inline" method="post" action="/domain_action.php"><?=csrf_field()?><input type="hidden" name="action" value="repair"><input type="hidden" name="id" value="<?=h($d['id'])?>"><button class="btn small warn">Repair</button></form>
              <form class="inline" method="post" action="/domain_action.php"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=h($d['id'])?>"><button class="btn small danger" onclick="return confirm('Remove this domain?')">Remove</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</div>
</body>
</html>
