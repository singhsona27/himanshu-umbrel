<?php require '../config.php'; require_admin(); require '_nav.php';
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=$_POST['action'] ?? '';
  if($action==='add'){
    $hid=(int)($_POST['hosting_account_id'] ?? 0);
    $domain=normalize_domain($_POST['domain'] ?? '');
    $type=($_POST['domain_type'] ?? 'website') === 'filemanager' ? 'filemanager' : 'website';
    $st=$pdo->prepare('SELECT * FROM hosting_accounts WHERE id=?'); $st->execute([$hid]); $hrow=$st->fetch();
    if($hrow && $domain){
      $target=$type==='filemanager' ? $hrow['filebrowser_domain'] : $hrow['site_domain'];
      try{$pdo->prepare('INSERT INTO custom_domains(hosting_account_id,domain,domain_type,verification_token,dns_target) VALUES(?,?,?,?,?)')->execute([$hid,$domain,$type,domain_verification_value($domain),$target]); if($type==='website' && !str_starts_with($domain,'www.')){ $www='www.'.$domain; try{$pdo->prepare('INSERT INTO custom_domains(hosting_account_id,domain,domain_type,verification_token,dns_target) VALUES(?,?,?,?,?)')->execute([$hid,$www,$type,domain_verification_value($www),$target]);}catch(Throwable $ignored){} } log_activity($pdo,'add_custom_domain','Added '.$domain,$hid,$hrow['user_id']); $msg='Domain added with DNS instructions.';}catch(Throwable $e){$msg='Domain already exists or could not be added.';}
    } else {$msg='Choose a hosting account and valid domain.';}
  }
  if($action==='verify'){
    $id=(int)($_POST['id'] ?? 0);
    $st=$pdo->prepare('SELECT * FROM custom_domains WHERE id=?'); $st->execute([$id]); $drow=$st->fetch();
    if($drow){
      $dns=domain_dns_status($drow['domain'],$drow['dns_target'],$drow['verification_token']);
      $status=$dns['status']==='connected' ? 'verified' : 'failed';
      $pdo->prepare("UPDATE custom_domains SET verification_status=?, cloudflare_status=?, ssl_status=?, last_checked_at=NOW() WHERE id=?")->execute([$status,$dns['status'],$status==='verified'?'ssl_pending':'ssl_error',$id]);
      if($status==='verified'){
        [$ok,$out]=rebuild_domain_routes($pdo,$drow['hosting_account_id'],$drow['domain_type']);
        $pdo->prepare('UPDATE custom_domains SET routing_status=? WHERE id=?')->execute([$ok ? 'connected' : 'route_error',$id]);
        $msg=$ok ? 'Domain verified and routing was rebuilt.' : 'Domain verified, but route rebuild failed: '.$out;
      } else {
        $msg='Domain check failed: '.$dns['details'];
      }
    }
    log_activity($pdo,'verify_custom_domain','Checked custom domain #'.$id);
  }
  if($action==='delete'){
    $id=(int)($_POST['id'] ?? 0);
    $st=$pdo->prepare('SELECT * FROM custom_domains WHERE id=?'); $st->execute([$id]); $drow=$st->fetch();
    $pdo->prepare('DELETE FROM custom_domains WHERE id=?')->execute([$id]);
    if($drow) rebuild_domain_routes($pdo,$drow['hosting_account_id'],$drow['domain_type']);
    log_activity($pdo,'delete_custom_domain','Deleted custom domain #'.$id);
    $msg='Domain removed.';
  }
}
$hosts=$pdo->query('SELECT h.id,h.site_domain,h.filebrowser_domain,u.name,u.email FROM hosting_accounts h JOIN users u ON u.id=h.user_id WHERE h.status<>"terminated" ORDER BY h.id DESC')->fetchAll();
$rows=$pdo->query('SELECT d.*,h.site_domain,h.filebrowser_domain,u.name,u.email FROM custom_domains d JOIN hosting_accounts h ON h.id=d.hosting_account_id JOIN users u ON u.id=h.user_id ORDER BY d.id DESC')->fetchAll();
?>
<!doctype html><html><head><title>Domains</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>Custom Domains</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><div class="card"><h3>Add Domain</h3><form method="post" class="wide-form"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>Hosting account</label><select name="hosting_account_id"><?php foreach($hosts as $hrow):?><option value="<?=h($hrow['id'])?>">#<?=h($hrow['id'])?> <?=h($hrow['name'])?> - <?=h($hrow['email'])?></option><?php endforeach;?></select><label>Domain type</label><select name="domain_type"><option value="website">Website</option><option value="filemanager">File Manager</option></select><label>Domain</label><input name="domain" placeholder="example.com" required><button class="btn small">Add Domain</button></form></div><table class="table"><tr><th>Customer</th><th>Temporary Domain</th><th>Custom Domain</th><th>Status</th><th>Cloudflare</th><th>Last Check</th><th>Actions</th></tr><?php foreach($rows as $r):?><tr><td><?=h($r['name'])?><br><small><?=h($r['email'])?></small></td><td><small><?=h($r['domain_type']==='filemanager' ? $r['filebrowser_domain'] : $r['site_domain'])?></small></td><td><?=h($r['domain'])?><br><small><?=h($r['domain_type'])?> | CNAME -> <?=h($r['dns_target'])?><br>TXT: <?=h($r['verification_token'])?></small></td><td><span class="badge <?=h($r['verification_status'])?>"><?=h($r['verification_status'])?></span><br><small>Routing: <?=h($r['routing_status'] ?: 'pending')?> | SSL: <?=h($r['ssl_status'] ?: 'pending')?></small></td><td><?=h($r['cloudflare_status'] ?: 'pending')?></td><td><?=h($r['last_checked_at'] ?: '-')?></td><td><form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?=h($r['id'])?>"><button class="btn small">Verify/Rebuild</button></form><form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=h($r['id'])?>"><button class="btn small danger" onclick="return confirm('Remove this domain?')">Delete</button></form></td></tr><?php endforeach;?></table></div></div></body></html>
