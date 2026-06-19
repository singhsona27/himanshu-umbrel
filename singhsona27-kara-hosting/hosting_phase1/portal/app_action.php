<?php
require 'config.php';
require_user();

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Use POST.');
}
verify_csrf();

$uid=(int)current_user_id();
$id=(int)($_POST['id'] ?? 0);
$action=$_POST['action'] ?? '';

$st=$pdo->prepare('SELECT * FROM hosting_accounts WHERE id=? AND user_id=?');
$st->execute([$id,$uid]);
$site=$st->fetch();
if(!$site) die('Website not found.');

if($action === 'install_app' || $action === 'install_wordpress'){
    $app=$_POST['app'] ?? ($action === 'install_wordpress' ? 'wordpress' : '');
    $allowed=['wordpress','moodle','opencart','joomla','drupal','grav'];
    if(!in_array($app,$allowed,true)) die('Unsupported app.');
    $cmd='bash /provisioner/install_app.sh '
        .escapeshellarg($app).' '
        .escapeshellarg(site_path_for($site)).' '
        .escapeshellarg($site['db_name']).' '
        .escapeshellarg($site['db_user']).' '
        .escapeshellarg($site['db_password']).' '
        .escapeshellarg($site['site_url']).' '
        .escapeshellarg($site['container_name']);
    $out=[]; $code=0; exec($cmd.' 2>&1',$out,$code);
    if($code!==0) die('<pre>'.h(implode("\n",$out)).'</pre>');
    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')->execute([ucfirst($app).' installed',$id]);
    log_activity($pdo,'install_app','Customer installed '.$app,$id,$uid);
    flash_action(ucfirst($app).' installation completed.', display_url_for($pdo,$site,'website'));
    redirect('/website.php?id='.$id.'&tab=apps');
}

die('Invalid app action.');
?>
