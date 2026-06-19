<?php
require 'config.php';
require_user();

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Use POST for Cloudflare actions.');
}
verify_csrf();

$uid=(int)current_user_id();
$action=$_POST['action'] ?? '';

if($action === 'save'){
    $token=trim($_POST['api_token'] ?? '');
    $zone=trim($_POST['zone_id'] ?? '');
    if(!$token) die('API token is required.');
    $encrypted=encrypt_secret($token);
    $exists=$pdo->prepare('SELECT id FROM cloudflare_accounts WHERE user_id=? ORDER BY id DESC LIMIT 1');
    $exists->execute([$uid]);
    $row=$exists->fetch();
    if($row){
        $pdo->prepare("UPDATE cloudflare_accounts SET api_token_encrypted=?, zone_id=?, status='saved', updated_at=NOW() WHERE id=?")
            ->execute([$encrypted,$zone ?: null,$row['id']]);
    } else {
        $pdo->prepare("INSERT INTO cloudflare_accounts(user_id,api_token_encrypted,zone_id,status) VALUES(?,?,?,'saved')")
            ->execute([$uid,$encrypted,$zone ?: null]);
    }
    log_activity($pdo,'cloudflare_settings_saved','Customer saved Cloudflare settings',null,$uid);
    redirect('/dashboard.php');
}

$cf=cloudflare_zone_for($pdo,$uid);
if(!$cf || empty($cf['api_token'])) die('Cloudflare API token is not configured.');

if($action === 'verify_connection'){
    $res=cf_request($cf['api_token'],'GET','/user/tokens/verify');
    $status=!empty($res['success']) ? 'connected' : 'error';
    $email=$res['result']['id'] ?? null;
    $pdo->prepare('UPDATE cloudflare_accounts SET status=?, account_email=?, last_check_at=NOW(), updated_at=NOW() WHERE id=?')
        ->execute([$status,$email,$cf['id']]);
    log_activity($pdo,'cloudflare_verify','Customer verified Cloudflare token',null,$uid);
    redirect('/dashboard.php');
}

if($action === 'sync_dns' || $action === 'repair_dns'){
    $domains=$pdo->prepare("SELECT d.*,h.user_id FROM custom_domains d JOIN hosting_accounts h ON h.id=d.hosting_account_id WHERE h.user_id=?");
    $domains->execute([$uid]);
    foreach($domains as $d){
        $cfRow=cloudflare_zone_for($pdo,$uid,$d['domain']);
        if(!$cfRow || empty($cfRow['zone_id'])) {
            $pdo->prepare("UPDATE custom_domains SET cloudflare_status='missing_zone', last_checked_at=NOW() WHERE id=?")->execute([$d['id']]);
            continue;
        }
        $existing=cf_request($cfRow['api_token'],'GET','/zones/'.$cfRow['zone_id'].'/dns_records?type=CNAME&name='.rawurlencode($d['domain']));
        $payload=['type'=>'CNAME','name'=>$d['domain'],'content'=>$d['dns_target'],'ttl'=>1,'proxied'=>true];
        if(!empty($existing['success']) && !empty($existing['result'][0]['id'])){
            $recordId=$existing['result'][0]['id'];
            $res=cf_request($cfRow['api_token'],'PUT','/zones/'.$cfRow['zone_id'].'/dns_records/'.$recordId,$payload);
        } else {
            $res=cf_request($cfRow['api_token'],'POST','/zones/'.$cfRow['zone_id'].'/dns_records',$payload);
        }
        $status=!empty($res['success']) ? 'synced' : 'error';
        $ssl=!empty($res['success']) ? 'ssl_pending' : 'ssl_error';
        $pdo->prepare('UPDATE custom_domains SET cloudflare_status=?, ssl_status=?, last_checked_at=NOW() WHERE id=?')
            ->execute([$status,$ssl,$d['id']]);
    }
    log_activity($pdo,$action === 'repair_dns' ? 'cloudflare_repair_dns' : 'cloudflare_sync_dns','Customer synced DNS records',null,$uid);
    redirect('/dashboard.php');
}

die('Invalid Cloudflare action.');
?>
