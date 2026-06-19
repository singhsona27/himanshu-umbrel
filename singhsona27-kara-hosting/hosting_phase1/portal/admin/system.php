<?php require '../config.php'; require_admin(); require '_nav.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $hosts=$pdo->query('SELECT * FROM hosting_accounts WHERE status<>"terminated"')->fetchAll();
  foreach($hosts as $hrow){
    $siteState=container_state($hrow['container_name']);
    $fbState=container_state($hrow['filebrowser_container']);
    $pdo->prepare('INSERT INTO hosting_checks(hosting_account_id,check_type,status,details) VALUES(?,?,?,?)')->execute([$hrow['id'],'container',($siteState==='running' && $fbState==='running')?'ok':'warning','Site: '.$siteState.'; File Manager: '.$fbState]);
    $plan=plan_details($hrow['plan']);
    $storage=get_du_bytes(site_path_for($hrow));
    $limit=($hrow['storage_limit_mb'] ?: $plan['storage_mb'])*1024*1024;
    $pct=pct($storage,$limit);
    $pdo->prepare('INSERT INTO hosting_checks(hosting_account_id,check_type,status,details) VALUES(?,?,?,?)')->execute([$hrow['id'],'quota',$pct>=90?'warning':'ok','Storage '.$pct.'% used']);
    $domain=$hrow['site_domain'] ?: parse_url($hrow['site_url'],PHP_URL_HOST);
    $status=$domain ? 'configured' : 'missing';
    $pdo->prepare('INSERT INTO hosting_checks(hosting_account_id,check_type,status,details) VALUES(?,?,?,?)')->execute([$hrow['id'],'domain',$status,$domain ?: 'No domain recorded']);
  }
  $domains=$pdo->query('SELECT d.*,h.id hosting_id FROM custom_domains d JOIN hosting_accounts h ON h.id=d.hosting_account_id WHERE h.status<>"terminated"')->fetchAll();
  foreach($domains as $drow){
    $dns=domain_dns_status($drow['domain'],$drow['dns_target'],$drow['verification_token']);
    $route=trim(run_cmd('curl -I --max-time 10 http://localhost:8088 -H '.escapeshellarg('Host: '.$drow['domain']).' | head -1',$o,$c));
    $routeStatus=str_contains($route,'200') || str_contains($route,'301') || str_contains($route,'302') ? 'connected' : 'offline';
    $pdo->prepare('UPDATE custom_domains SET cloudflare_status=?, routing_status=?, last_checked_at=NOW() WHERE id=?')->execute([$dns['status'],$routeStatus,$drow['id']]);
    $pdo->prepare('INSERT INTO hosting_checks(hosting_account_id,check_type,status,details) VALUES(?,?,?,?)')->execute([$drow['hosting_id'],'custom_domain',$routeStatus,$drow['domain'].' DNS: '.$dns['details'].'; Route: '.($route ?: 'no response')]);
  }
  log_activity($pdo,'run_health_checks','Ran hosting health checks');
  redirect('/admin/system.php');
}
$docker=trim(run_cmd('docker version --format {{.Server.Version}}',$o,$c));
$containers=trim(run_cmd('docker ps -a --filter name=customer- --format "{{.Names}}|{{.Status}}"',$o,$c));
$traefik=container_state('hosting-platform-traefik');
$disk=trim(run_cmd('df -h / | tail -1',$o,$c));
$mem=trim(run_cmd('free -h | awk \'/Mem:/ {print $3" / "$2}\'',$o,$c));
$checks=$pdo->query('SELECT c.*,h.site_domain,u.name FROM hosting_checks c JOIN hosting_accounts h ON h.id=c.hosting_account_id JOIN users u ON u.id=h.user_id ORDER BY c.id DESC LIMIT 30')->fetchAll();
?>
<!doctype html><html><head><title>System Health</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>System Health</h1><form method="post"><?=csrf_field()?><button class="btn small">Run Health Checks</button></form><div class="grid"><div class="card"><h3>Docker</h3><p><?=h($docker ?: 'Unavailable')?></p></div><div class="card"><h3>Traefik</h3><p><?=h($traefik)?></p><small>Dashboard: http://umbrel.local:8089</small></div><div class="card"><h3>Memory</h3><p><?=h($mem)?></p></div><div class="card"><h3>Disk</h3><p><small><?=h($disk)?></small></p></div></div><div class="card"><h2>Latest Hosting Checks</h2><table class="table"><tr><th>Customer</th><th>Domain</th><th>Check</th><th>Status</th><th>Details</th><th>Date</th></tr><?php foreach($checks as $ck):?><tr><td><?=h($ck['name'])?></td><td><?=h($ck['site_domain'])?></td><td><?=h($ck['check_type'])?></td><td><span class="badge <?=h($ck['status'])?>"><?=h($ck['status'])?></span></td><td><?=h($ck['details'])?></td><td><?=h($ck['checked_at'])?></td></tr><?php endforeach;?></table></div><div class="card"><h2>Customer Containers</h2><pre><?=h($containers ?: 'No customer containers found')?></pre></div></div></div></body></html>
