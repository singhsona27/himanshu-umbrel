<?php require 'config.php'; require_user();
$uid=(int)current_user_id(); $msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $subject=trim($_POST['subject'] ?? '');
  $message=trim($_POST['message'] ?? '');
  $hid=(int)($_POST['hosting_account_id'] ?? 0);
  $priority=in_array($_POST['priority'] ?? 'normal',['low','normal','high','urgent'],true) ? $_POST['priority'] : 'normal';
  if($subject && $message){
    if($hid){$own=$pdo->prepare('SELECT id FROM hosting_accounts WHERE id=? AND user_id=?');$own->execute([$hid,$uid]);if(!$own->fetch()) $hid=0;}
    $pdo->prepare('INSERT INTO support_tickets(user_id,hosting_account_id,subject,priority,status,updated_at) VALUES(?,?,?,?,?,NOW())')->execute([$uid,$hid ?: null,$subject,$priority,'open']);
    $tid=$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO support_messages(ticket_id,user_id,message) VALUES(?,?,?)')->execute([$tid,$uid,$message]);
    log_activity($pdo,'support_ticket_created','Ticket #'.$tid,null,$uid);
    $msg='Support ticket opened.';
  } else {$msg='Subject and message are required.';}
}
$hosts=$pdo->prepare('SELECT id,site_domain FROM hosting_accounts WHERE user_id=? ORDER BY id DESC');$hosts->execute([$uid]);
$tickets=$pdo->prepare('SELECT * FROM support_tickets WHERE user_id=? ORDER BY id DESC');$tickets->execute([$uid]);
?>
<!doctype html><html><head><title>Support</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><div class="side"><h2>Karacraft</h2><a href="/dashboard.php">Dashboard</a><a href="/domains.php">Domains</a><a href="/billing.php">Billing</a><a href="/support.php">Support</a><a href="/migration.php">Migration</a><a href="/logout.php">Logout</a></div><div class="main"><h1>Support</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><div class="card"><h3>Open Ticket</h3><form method="post" class="wide-form"><?=csrf_field()?><label>Hosting account</label><select name="hosting_account_id"><option value="">General account issue</option><?php foreach($hosts as $hrow):?><option value="<?=h($hrow['id'])?>">#<?=h($hrow['id'])?> <?=h($hrow['site_domain'])?></option><?php endforeach;?></select><label>Priority</label><select name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select><label>Subject</label><input name="subject" required><label>Message</label><textarea name="message" required></textarea><button class="btn small">Submit Ticket</button></form></div><table class="table"><tr><th>ID</th><th>Subject</th><th>Priority</th><th>Status</th><th>Updated</th></tr><?php foreach($tickets as $t):?><tr><td>#<?=h($t['id'])?></td><td><?=h($t['subject'])?></td><td><?=h($t['priority'])?></td><td><span class="badge <?=h($t['status'])?>"><?=h($t['status'])?></span></td><td><?=h($t['updated_at'] ?: $t['created_at'])?></td></tr><?php endforeach;?></table></div></div></body></html>
