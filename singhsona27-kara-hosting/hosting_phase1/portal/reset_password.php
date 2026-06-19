<?php
require 'config.php';
$token=$_GET['token'] ?? ($_POST['token'] ?? '');
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $pass=$_POST['password'] ?? '';
    if(strlen($pass)<8){
        $msg='Password must be at least 8 characters.';
    } else {
        $row=consume_reset_token($pdo,'user',$token);
        if($row){
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),$row['account_id']]);
            log_activity($pdo,'user_password_reset','Customer password reset',null,$row['account_id']);
            redirect('/login.php');
        }
        $msg='Reset token is invalid or expired.';
    }
}
?>
<!doctype html><html><head><title>Reset Password</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="top"><div class="brand">Karacraft Hosting</div></div><div class="card form"><h1>Set new password</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><form method="post"><?=csrf_field()?><input type="hidden" name="token" value="<?=h($token)?>"><label>New password</label><input name="password" type="password" minlength="8" required><button class="btn">Update Password</button></form></div></body></html>
