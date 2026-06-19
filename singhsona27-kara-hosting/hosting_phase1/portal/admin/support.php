<?php require '../config.php'; require_admin(); require '_nav.php';
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $id=(int)($_POST['id'] ?? 0);
  $action=$_POST['action'] ?? '';
  if($action==='reply'){
    $message=trim($_POST['message'] ?? '');
    $status=$_POST['status'] ?? 'waiting_customer';
    if($id && $message){$pdo->prepare('INSERT INTO support_messages(ticket_id,admin_id,message) VALUES(?,?,?)')->execute([$id,current_admin_id(),$message]);$pdo->prepare('UPDATE support_tickets SET status=?, updated_at=NOW() WHERE id=?')->execute([$status,$id]);log_activity($pdo,'support_reply','Replied to ticket #'.$id);$msg='Reply added.';}
  }
  if($action==='status'){
    $status=$_POST['status'] ?? 'open';
    $pdo->prepare('UPDATE support_tickets SET status=?, updated_at=NOW() WHERE id=?')->execute([$status,$id]);
    log_activity($pdo,'support_status','Updated ticket #'.$id.' to '.$status);
    $msg='Ticket updated.';
  }
}
$rows=$pdo->query('SELECT t.*,u.name,u.email FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY FIELD(t.status,"open","waiting_admin","waiting_customer","resolved","closed"), t.id DESC')->fetchAll();
?>
<!doctype html><html><head><title>Support Queue</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>Support Queue</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><?php foreach($rows as $r): $m=$pdo->prepare('SELECT * FROM support_messages WHERE ticket_id=? ORDER BY id');$m->execute([$r['id']]); ?><div class="card"><div class="account-head"><div><h2>#<?=h($r['id'])?> <?=h($r['subject'])?></h2><p class="muted"><?=h($r['name'])?> - <?=h($r['email'])?> | <?=h($r['priority'])?></p></div><span class="badge <?=h($r['status'])?>"><?=h($r['status'])?></span></div><pre><?php foreach($m as $msgRow): ?><?=h(($msgRow['admin_id']?'Admin':'Customer').': '.$msgRow['message']."\n\n")?><?php endforeach;?></pre><form method="post" class="wide-form"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($r['id'])?>"><input type="hidden" name="action" value="reply"><label>Reply</label><textarea name="message"></textarea><label>Status</label><select name="status"><option value="waiting_customer">Waiting Customer</option><option value="waiting_admin">Waiting Admin</option><option value="resolved">Resolved</option><option value="closed">Closed</option><option value="open">Open</option></select><button class="btn small">Reply</button></form></div><?php endforeach;?></div></div></body></html>
