<?php require '../config.php'; require_admin(); require '_nav.php'; require 'provision_helper.php';
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $userId=(int)($_POST['user_id'] ?? 0);
  $plan=$_POST['plan'] ?? 'starter';
  $note=trim($_POST['note'] ?? 'Manual admin provision');
  if($userId){
    $pdo->prepare("INSERT INTO orders(user_id,plan,payment_reference,status,admin_note) VALUES(?,?,?,?,?)")->execute([$userId,$plan,'ADMIN-GIFT','pending',$note]);
    $orderId=$pdo->lastInsertId();
    $res=provision_order($pdo,$orderId,'gift');
    $msg=empty($res['ok']) ? ($res['error'] ?? 'Provisioning failed') : 'Hosting provisioned for customer.';
  } else {$msg='Select a customer.';}
}
$users=$pdo->query('SELECT id,name,email FROM users ORDER BY name')->fetchAll();
?>
<!doctype html><html><head><title>Manual Provision</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>Manual Provision / Gift Hosting</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><div class="card form"><form method="post"><?=csrf_field()?><label>Customer</label><select name="user_id" required><?php foreach($users as $u):?><option value="<?=h($u['id'])?>"><?=h($u['name'])?> - <?=h($u['email'])?></option><?php endforeach;?></select><label>Plan</label><select name="plan"><option value="starter">Starter</option><option value="business">Business</option><option value="pro">Pro</option></select><label>Admin note</label><textarea name="note">Manual admin provision</textarea><button class="btn">Provision Hosting</button></form></div></div></div></body></html>
