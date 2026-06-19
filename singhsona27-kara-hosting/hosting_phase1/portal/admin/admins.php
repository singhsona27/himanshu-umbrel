<?php require '../config.php'; require_admin(); require '_nav.php';
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=$_POST['action'] ?? '';
  if($action==='create'){
    $name=trim($_POST['name'] ?? '');
    $email=strtolower(trim($_POST['email'] ?? ''));
    $pass=$_POST['password'] ?? '';
    if($name && filter_var($email,FILTER_VALIDATE_EMAIL) && strlen($pass)>=8){
      try{$pdo->prepare('INSERT INTO admins(name,email,password_hash,is_active) VALUES(?,?,?,1)')->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT)]); log_activity($pdo,'create_admin','Created admin '.$email); $msg='Admin created.';}catch(Throwable $e){$msg='Could not create admin. Email may already exist.';}
    } else {$msg='Enter valid admin details. Password must be at least 8 characters.';}
  }
  if($action==='toggle'){
    $id=(int)($_POST['id'] ?? 0);
    if($id && $id !== (int)current_admin_id()){
      $pdo->prepare('UPDATE admins SET is_active=IF(is_active=1,0,1), updated_at=NOW() WHERE id=?')->execute([$id]);
      log_activity($pdo,'toggle_admin','Enabled/disabled admin #'.$id);
      $msg='Admin status updated.';
    } else {$msg='You cannot disable your own active admin session.';}
  }
  if($action==='delete'){
    $id=(int)($_POST['id'] ?? 0);
    if($id && $id !== (int)current_admin_id()){
      $pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$id]);
      log_activity($pdo,'delete_admin','Deleted admin #'.$id);
      $msg='Admin deleted.';
    } else {$msg='You cannot delete your own active admin session.';}
  }
  if($action==='reset'){
    $id=(int)($_POST['id'] ?? 0); $pass=$_POST['password'] ?? '';
    if($id && strlen($pass)>=8){$pdo->prepare('UPDATE admins SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),$id]); log_activity($pdo,'reset_admin_password','Reset admin #'.$id.' password'); $msg='Password updated.';} else {$msg='Password must be at least 8 characters.';}
  }
  if($action==='change_own'){
    $current=$_POST['current_password'] ?? ''; $pass=$_POST['new_password'] ?? '';
    $st=$pdo->prepare('SELECT * FROM admins WHERE id=?'); $st->execute([current_admin_id()]); $me=$st->fetch();
    if($me && password_verify($current,$me['password_hash']) && strlen($pass)>=8){$pdo->prepare('UPDATE admins SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),current_admin_id()]); log_activity($pdo,'change_own_admin_password','Changed own admin password'); $msg='Your password was changed.';} else {$msg='Current password is wrong or new password is too short.';}
  }
}
$rows=$pdo->query('SELECT * FROM admins ORDER BY id DESC')->fetchAll();
?>
<!doctype html><html><head><title>Admin Users</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>Admin Users</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><div class="grid"><div class="card"><h3>Change My Password</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="change_own"><label>Current password</label><input type="password" name="current_password" required><label>New password</label><input type="password" name="new_password" minlength="8" required><button class="btn small">Update</button></form></div><div class="card"><h3>Create Admin</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create"><label>Name</label><input name="name" required><label>Email</label><input type="email" name="email" required><label>Password</label><input type="password" name="password" minlength="8" required><button class="btn small">Create</button></form></div></div><table class="table"><tr><th>Name</th><th>Email</th><th>Status</th><th>Last Login</th><th>Actions</th></tr><?php foreach($rows as $r):?><tr><td><?=h($r['name'])?></td><td><?=h($r['email'])?></td><td><span class="badge <?=((int)$r['is_active']===1?'active':'suspended')?>"><?=((int)$r['is_active']===1?'active':'disabled')?></span></td><td><?=h($r['last_login_at'] ?: '-')?></td><td><form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=h($r['id'])?>"><button class="btn small warn">Enable/Disable</button></form><form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?=h($r['id'])?>"><input class="inline-input" type="password" name="password" minlength="8" placeholder="New password"><button class="btn small">Reset Password</button></form><form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=h($r['id'])?>"><button class="btn small danger" onclick="return confirm('Delete this admin?')">Delete</button></form></td></tr><?php endforeach;?></table></div></div></body></html>
