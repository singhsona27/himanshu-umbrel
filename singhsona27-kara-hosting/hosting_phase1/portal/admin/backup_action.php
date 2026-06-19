<?php
require '../config.php';
require_admin();
if($_SERVER['REQUEST_METHOD'] !== 'POST') die('Use POST for backup actions.');
verify_csrf();
$id=(int)($_POST['id'] ?? 0);
$action=$_POST['action'] ?? '';
$st=$pdo->prepare('SELECT b.*,h.user_id,h.order_id,h.db_name,h.container_name FROM backups b JOIN hosting_accounts h ON h.id=b.hosting_account_id WHERE b.id=?');
$st->execute([$id]);
$row=$st->fetch();
if(!$row) die('Backup not found');
if($action==='restore'){
  $cmd='bash /provisioner/restore_backup.sh '
    .escapeshellarg($row['backup_type']).' '
    .escapeshellarg($row['file_path']).' '
    .escapeshellarg($row['user_id']).' '
    .escapeshellarg($row['order_id']).' '
    .escapeshellarg($row['db_name']);
  $out=[]; $code=0; exec($cmd.' 2>&1',$out,$code);
  if($code!==0) die('<pre>'.h(implode("\n",$out)).'</pre>');
  if($row['backup_type']==='site') exec('docker restart '.escapeshellarg($row['container_name']).' 2>&1');
  $pdo->prepare('UPDATE hosting_accounts SET last_action=?, backup_status=? WHERE id=?')->execute(['Backup restored','Last restore: '.$row['backup_type'],$row['hosting_account_id']]);
  log_activity($pdo,'restore_backup','Restored '.$row['backup_type'].' backup #'.$id,$row['hosting_account_id'],$row['user_id']);
  redirect('/admin/backups.php');
}
die('Invalid backup action');
?>
