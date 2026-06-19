<?php require '../config.php'; require_admin(); require '_nav.php';
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $id=(int)($_POST['id'] ?? 0);
  $status=$_POST['status'] ?? 'requested';
  if(in_array($status,['requested','in_progress','completed','cancelled'],true)){
    $pdo->prepare('UPDATE migration_requests SET status=?, updated_at=NOW() WHERE id=?')->execute([$status,$id]);
    log_activity($pdo,'migration_status','Updated migration #'.$id.' to '.$status);
    $msg='Migration status updated.';
  }
}
$rows=$pdo->query('SELECT m.*,u.name,u.email,h.site_domain FROM migration_requests m JOIN users u ON u.id=m.user_id LEFT JOIN hosting_accounts h ON h.id=m.hosting_account_id ORDER BY m.id DESC')->fetchAll();
?>
<!doctype html><html><head><title>Migrations</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>Migration Requests</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><table class="table"><tr><th>Customer</th><th>Source</th><th>Destination</th><th>Notes</th><th>Status</th><th>Action</th></tr><?php foreach($rows as $r):?><tr><td><?=h($r['name'])?><br><small><?=h($r['email'])?></small></td><td><?=h($r['source_host'])?><br><small><?=h($r['source_domain'])?></small></td><td><?=h($r['site_domain'] ?: '-')?></td><td><?=h($r['notes'])?></td><td><span class="badge <?=h($r['status'])?>"><?=h($r['status'])?></span></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($r['id'])?>"><select name="status"><option value="requested">Requested</option><option value="in_progress">In Progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select><button class="btn small">Update</button></form></td></tr><?php endforeach;?></table></div></div></body></html>
