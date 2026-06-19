<?php require '../config.php'; require_admin(); require '_nav.php';
$rows=$pdo->query('SELECT h.*,u.name,u.email FROM hosting_accounts h JOIN users u ON u.id=h.user_id ORDER BY h.id DESC')->fetchAll();
function hosting_action_form($id,$action,$label,$class='btn small',$confirm=''){
  $confirmAttr=$confirm ? ' onclick="return confirm(\''.h($confirm).'\')"' : '';
  echo '<form class="inline" method="post" action="/admin/action.php">'.csrf_field().'<input type="hidden" name="id" value="'.h($id).'"><input type="hidden" name="action" value="'.h($action).'"><button class="'.h($class).'"'.$confirmAttr.'>'.h($label).'</button></form>';
}
?>
<!doctype html><html><head><title>Hosting Accounts</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>Hosting Accounts</h1><?php foreach($rows as $r): $plan=plan_details($r['plan']); $siteState=container_state($r['container_name']); $fbState=container_state($r['filebrowser_container']); $sitePath=site_path_for($r); $storage=get_du_bytes($sitePath); $dbsize=db_size_bytes($pdo,$r['db_name']); $siteStats=docker_stats($r['container_name']); $storageLimit=($r['storage_limit_mb'] ?: $plan['storage_mb'])*1024*1024; $dbLimit=($r['db_limit_mb'] ?: $plan['db_mb'])*1024*1024; ?>
<div class="card account-card">
  <div class="account-head"><div><h2><?=h($r['name'])?> <small><?=h($r['email'])?></small></h2><p class="muted">Plan: <?=h($r['plan'])?> | Expires: <?=h($r['expires_at'] ?: 'Not set')?> | Last action: <?=h($r['last_action'] ?: '-')?></p></div><span class="badge <?=h($r['status'])?>"><?=h($r['status'])?></span></div>
  <div class="grid four">
    <div><strong>Website</strong><br><a href="<?=h($r['site_url'])?>" target="_blank"><?=h($r['site_domain'] ?: $r['site_url'])?></a><br><small>Status: <?=h($siteState)?> | Local: <?=h($r['local_site_url'] ?: ('http://umbrel.local:'.$r['site_port']))?></small></div>
    <div><strong>File Manager</strong><br><a href="<?=h($r['filebrowser_url'])?>" target="_blank"><?=h($r['filebrowser_domain'] ?: 'Open FileBrowser')?></a><br><small><?=h($r['filebrowser_username'])?> / <?=h($r['filebrowser_password'])?></small><br><small>Local: <?=h($r['local_filebrowser_url'] ?: ('http://umbrel.local:'.$r['filebrowser_port']))?></small></div>
    <div><strong>Database</strong><br><?=h($r['db_name'])?><br><small><?=h($r['db_user'])?> / <?=h($r['db_password'])?></small></div>
    <div><strong>Usage</strong><br>Files: <?=h(file_count($sitePath))?><br><small>CPU <?=h($siteStats['cpu'])?> | RAM <?=h($siteStats['mem'])?></small></div>
  </div>
  <div class="usage-row"><label>Storage <?=fmt_bytes($storage)?> / <?=fmt_bytes($storageLimit)?></label><div class="bar"><span style="width:<?=pct($storage,$storageLimit)?>%"></span></div></div>
  <div class="usage-row"><label>Database <?=fmt_bytes($dbsize)?> / <?=fmt_bytes($dbLimit)?></label><div class="bar"><span style="width:<?=pct($dbsize,$dbLimit)?>%"></span></div></div>
  <div class="actions">
    <?php hosting_action_form($r['id'],'restart_site','Restart Site'); ?>
    <?php hosting_action_form($r['id'],'fix_permissions','Fix Permissions'); ?>
    <?php hosting_action_form($r['id'],'restart_filebrowser','Restart File Manager'); ?>
    <?php if($r['status']==='suspended'):?><?php hosting_action_form($r['id'],'unsuspend','Unsuspend'); ?><?php else:?><?php hosting_action_form($r['id'],'suspend','Suspend','btn small warn','Suspend this hosting account?'); ?><?php endif;?>
    <?php hosting_action_form($r['id'],'reset_fb_password','Reset FileBrowser Password'); ?>
    <?php hosting_action_form($r['id'],'backup','Backup'); ?>
    <?php hosting_action_form($r['id'],'renew_30','Renew +30 Days'); ?>
    <?php hosting_action_form($r['id'],'terminate','Terminate','btn small danger','Permanent termination will delete containers, files and database. Continue?'); ?>
  </div>
</div>
<?php endforeach;?></div></div></body></html>
