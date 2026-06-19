<?php
require 'config.php';
$msg='';
$resetLink='';
$mailError='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $email=strtolower(trim($_POST['email'] ?? ''));
    if(filter_var($email,FILTER_VALIDATE_EMAIL)){
        $token=create_reset_token($pdo,'user',$email);
        if($token) {
            $resetLink=app_url('/reset_password.php?token='.$token);
            if(smtp_configured() && send_reset_email($email,'user',$resetLink,$mailError)) {
                $resetLink='';
            }
        }
    }
    $msg='If the email exists, a password reset token has been created.';
}
?>
<!doctype html><html><head><title>Forgot Password</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="top"><div class="brand">Karacraft Hosting</div><div class="nav"><a href="/login.php">Login</a></div></div><div class="card form"><h1>Reset customer password</h1><?php if($msg):?><div class="alert"><?=h($msg)?><?php if($mailError):?><br><br>SMTP delivery failed: <?=h($mailError)?><?php endif;?><?php if($resetLink):?><br><br><strong>Reset link:</strong><br><a href="<?=h($resetLink)?>"><?=h($resetLink)?></a><?php endif;?></div><?php endif;?><form method="post"><?=csrf_field()?><label>Email</label><input name="email" type="email" required><button class="btn">Create Reset Link</button></form></div></body></html>
