<?php
require '../config.php';
require_admin();
require 'provision_helper.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST') die('Use POST to approve orders.');
verify_csrf();
$id = (int)($_POST['id'] ?? 0);
$res = provision_order($pdo,$id,'approved');
if(empty($res['ok'])) die('<pre>'.h($res['error'] ?? 'Provisioning failed').'</pre>');
redirect('/admin/orders.php');
?>
