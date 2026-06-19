<?php require '../config.php'; require_admin(); require '_nav.php';
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=$_POST['action'] ?? '';
  $id=(int)($_POST['id'] ?? 0);
  if($action==='paid'){$pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$id]); log_activity($pdo,'invoice_paid','Marked invoice #'.$id.' paid'); $msg='Invoice marked paid.';}
  if($action==='void'){$pdo->prepare("UPDATE invoices SET status='void' WHERE id=?")->execute([$id]); log_activity($pdo,'invoice_void','Voided invoice #'.$id); $msg='Invoice voided.';}
}
$rows=$pdo->query('SELECT i.*,u.name,u.email,o.plan FROM invoices i JOIN users u ON u.id=i.user_id LEFT JOIN orders o ON o.id=i.order_id ORDER BY i.id DESC')->fetchAll();
?>
<!doctype html><html><head><title>Billing</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>Billing</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><table class="table"><tr><th>Invoice</th><th>Customer</th><th>Description</th><th>Amount</th><th>Status</th><th>Actions</th></tr><?php foreach($rows as $r):?><tr><td><?=h($r['invoice_number'])?><br><small><?=h($r['created_at'])?></small></td><td><?=h($r['name'])?><br><small><?=h($r['email'])?></small></td><td><?=h($r['description'])?><br><small><?=h($r['plan'] ?: '')?></small></td><td><?=h($r['currency'])?> <?=h(number_format((float)$r['amount'],2))?></td><td><span class="badge <?=h($r['status'])?>"><?=h($r['status'])?></span></td><td><form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($r['id'])?>"><input type="hidden" name="action" value="paid"><button class="btn small">Mark Paid</button></form><form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($r['id'])?>"><input type="hidden" name="action" value="void"><button class="btn small warn">Void</button></form></td></tr><?php endforeach;?></table></div></div></body></html>
