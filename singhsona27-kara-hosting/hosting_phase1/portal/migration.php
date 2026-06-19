<?php require 'config.php'; require_user();
$uid=(int)current_user_id(); $msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $hid=(int)($_POST['hosting_account_id'] ?? 0);
  $sourceHost=trim($_POST['source_host'] ?? '');
  $sourceDomain=normalize_domain($_POST['source_domain'] ?? '');
  $notes=trim($_POST['notes'] ?? '');
  if($hid){$own=$pdo->prepare('SELECT id FROM hosting_accounts WHERE id=? AND user_id=?');$own->execute([$hid,$uid]);if(!$own->fetch()) $hid=0;}
  if($sourceHost || $sourceDomain || $notes){
    $pdo->prepare('INSERT INTO migration_requests(user_id,hosting_account_id,source_host,source_domain,notes,status) VALUES(?,?,?,?,?,?)')->execute([$uid,$hid ?: null,$sourceHost,$sourceDomain,$notes,'requested']);
    log_activity($pdo,'migration_requested','Customer requested migration', $hid ?: null, $uid);
    $msg='Migration request submitted.';
  } else {$msg='Add source host, domain, or notes.';}
}
$hosts=$pdo->prepare('SELECT id,site_domain FROM hosting_accounts WHERE user_id=? ORDER BY id DESC');$hosts->execute([$uid]);
$rows=$pdo->prepare('SELECT * FROM migration_requests WHERE user_id=? ORDER BY id DESC');$rows->execute([$uid]);
?>
<!doctype html><html><head><title>Migration</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><div class="side"><h2>Karacraft</h2><a href="/dashboard.php">Dashboard</a><a href="/domains.php">Domains</a><a href="/billing.php">Billing</a><a href="/support.php">Support</a><a href="/migration.php">Migration</a><a href="/logout.php">Logout</a></div><div class="main"><h1>Migration Request</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><div class="card"><form method="post" class="wide-form"><?=csrf_field()?><label>Destination hosting</label><select name="hosting_account_id"><option value="">Not sure yet</option><?php foreach($hosts as $hrow):?><option value="<?=h($hrow['id'])?>">#<?=h($hrow['id'])?> <?=h($hrow['site_domain'])?></option><?php endforeach;?></select><label>Current host</label><input name="source_host" placeholder="Old hosting provider"><label>Source domain</label><input name="source_domain" placeholder="example.com"><label>Notes</label><textarea name="notes" placeholder="Mention cPanel, WordPress, database, email, or special requirements"></textarea><button class="btn small">Request Migration</button></form></div><table class="table"><tr><th>Source</th><th>Status</th><th>Date</th></tr><?php foreach($rows as $r):?><tr><td><?=h($r['source_domain'] ?: $r['source_host'])?><br><small><?=h($r['notes'])?></small></td><td><span class="badge <?=h($r['status'])?>"><?=h($r['status'])?></span></td><td><?=h($r['created_at'])?></td></tr><?php endforeach;?></table></div></div></body></html>
