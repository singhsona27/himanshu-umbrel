<?php
session_start();
$dbHost = getenv('DB_HOST') ?: 'hosting-platform-db';
$dbName = getenv('DB_NAME') ?: 'hosting_platform';
$dbUser = getenv('DB_USER') ?: 'platform_user';
$dbPass = getenv('DB_PASS') ?: 'StrongPlatformPassword123';
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect($url){ header("Location: $url"); exit; }
function require_user(){ if(empty($_SESSION['user_id'])) redirect('/login.php'); }
function require_admin(){ if(empty($_SESSION['admin_id'])) redirect('/admin/login.php'); }
function current_admin_id(){ return $_SESSION['admin_id'] ?? null; }
function current_user_id(){ return $_SESSION['user_id'] ?? null; }
function request_scheme(){
    if(!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) return $_SERVER['HTTP_X_FORWARDED_PROTO'];
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}
function base_host(){ return request_scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'umbrel.local:8200'); }
function base_domain(){ return getenv('BASE_DOMAIN') ?: 'umbrel2.karacraft.ng'; }
function url_scheme(){ return getenv('URL_SCHEME') ?: 'https'; }
function platform_root(){ return getenv('PLATFORM_ROOT') ?: '/home/umbrel/umbrel/home/Documents/hosting_phase1'; }
function portal_domain(){ return getenv('PORTAL_DOMAIN') ?: 'hosting.'.base_domain(); }
function is_platform_domain($domain){
    $domain = strtolower(rtrim((string)$domain, '.'));
    $base = strtolower(rtrim(base_domain(), '.'));
    return $domain === $base || str_ends_with($domain, '.'.$base);
}
function likely_cloudflare_zone($domain){
    $labels = explode('.', strtolower(rtrim((string)$domain, '.')));
    $count = count($labels);
    if ($count <= 2) return implode('.', $labels);
    $secondLevelCc = ['ac','co','com','edu','gov','net','org','sch'];
    if ($count >= 3 && strlen($labels[$count-1]) === 2 && in_array($labels[$count-2], $secondLevelCc, true)) {
        return implode('.', array_slice($labels, -3));
    }
    return implode('.', array_slice($labels, -2));
}
function dns_record_name_hint($domain){
    $domain = strtolower(rtrim((string)$domain, '.'));
    if (is_platform_domain($domain)) {
        $base = strtolower(rtrim(base_domain(), '.'));
        return $domain === $base ? '@' : preg_replace('/\.'.preg_quote($base,'/').'$/', '', $domain);
    }
    $zone = likely_cloudflare_zone($domain);
    return $domain === $zone ? '@' : preg_replace('/\.'.preg_quote($zone,'/').'$/', '', $domain);
}
function public_domain_status($domain){
    $domain = strtolower(rtrim((string)$domain, '.'));
    foreach (['https://','http://'] as $scheme) {
        $headers = @get_headers($scheme.$domain.'/', true);
        if (!$headers || empty($headers[0])) continue;
        $line = is_array($headers[0]) ? end($headers[0]) : $headers[0];
        if (preg_match('/\s(\d{3})\s/', (string)$line, $m)) {
            $code = (int)$m[1];
            if (in_array($code, [200,301,302,303,307,308], true)) {
                return ['status'=>'reachable','code'=>$code];
            }
            if ($code === 404) {
                return ['status'=>'tunnel_missing','code'=>$code];
            }
            return ['status'=>'http_'.$code,'code'=>$code];
        }
    }
    return ['status'=>'unreachable','code'=>0];
}
function csrf_token(){ if(empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function csrf_field(){ return '<input type="hidden" name="csrf_token" value="'.h(csrf_token()).'">'; }
function verify_csrf(){
    $posted = $_POST['csrf_token'] ?? '';
    if(empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $posted)) {
        http_response_code(419);
        die('Security check failed. Please go back and try again.');
    }
}
function flash($type,$message){
    $_SESSION['flash_messages'][]=['type'=>$type,'message'=>$message];
}
function flash_action($message,$url,$label='Open Website'){
    $_SESSION['flash_messages'][]=['type'=>'success','message'=>$message,'action_url'=>$url,'action_label'=>$label];
}
function render_flash(){
    $items=$_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    $html='';
    foreach($items as $item){
        $type=preg_replace('/[^a-z_]/','',(string)($item['type'] ?? 'info'));
        $html.='<div class="alert flash '.h($type).'">'.h($item['message'] ?? '').'</div>';
        if(!empty($item['action_url'])){
            $msg=json_encode((string)($item['message'] ?? 'Action completed')."\n\nOpen the website now?");
            $url=json_encode((string)$item['action_url']);
            $html.="<script>setTimeout(function(){if(confirm($msg)){window.open($url,'_blank');}},250);</script>";
        }
    }
    return $html;
}
function post_action($url,$label,$class='btn small',$confirm=''){
    $confirmAttr = $confirm ? ' onclick="return confirm(\''.h($confirm).'\')"' : '';
    return '<form class="inline" method="post" action="'.h($url).'">'.csrf_field().'<button class="'.h($class).'"'.$confirmAttr.'>'.h($label).'</button></form>';
}
function app_url($path){ return rtrim(base_host(),'/').'/'.ltrim($path,'/'); }
function plan_details($plan){
    global $pdo;
    try {
        $st=$pdo->prepare('SELECT * FROM hosting_plans WHERE slug=? AND is_active=1 LIMIT 1');
        $st->execute([$plan]);
        $row=$st->fetch();
        if($row){
            return [
                'name'=>$row['name'],'sites'=>(int)$row['sites'],'storage_mb'=>(int)$row['storage_mb'],
                'db_mb'=>(int)$row['db_mb'],'cpu'=>$row['cpu_label'],'ram'=>$row['ram_label'],
                'type'=>$row['plan_type'],'price'=>$row['price_label']
            ];
        }
    } catch(Throwable $e) {}
    $plans = [
        'starter' => ['name'=>'Starter','sites'=>1,'storage_mb'=>1024,'db_mb'=>512,'cpu'=>'Shared','ram'=>'512MB','type'=>'Shared','price'=>'Manual Quote'],
        'business' => ['name'=>'Business','sites'=>3,'storage_mb'=>5120,'db_mb'=>1024,'cpu'=>'Shared','ram'=>'1GB','type'=>'Shared','price'=>'Manual Quote'],
        'pro' => ['name'=>'Pro','sites'=>5,'storage_mb'=>10240,'db_mb'=>2048,'cpu'=>'Shared','ram'=>'2GB','type'=>'Shared','price'=>'Manual Quote'],
    ];
    return $plans[$plan] ?? $plans['starter'];
}
function active_plans(){
    global $pdo;
    try{
        $rows=$pdo->query('SELECT * FROM hosting_plans WHERE is_active=1 ORDER BY sort_order,name')->fetchAll();
        if($rows) return $rows;
    } catch(Throwable $e){}
    return [
        ['slug'=>'starter','name'=>'Starter','sites'=>1,'storage_mb'=>1024,'db_mb'=>512,'cpu_label'=>'Shared','ram_label'=>'512MB','plan_type'=>'Shared','price_label'=>'Manual Quote'],
        ['slug'=>'business','name'=>'Business','sites'=>3,'storage_mb'=>5120,'db_mb'=>1024,'cpu_label'=>'Shared','ram_label'=>'1GB','plan_type'=>'Shared','price_label'=>'Manual Quote'],
        ['slug'=>'pro','name'=>'Pro','sites'=>5,'storage_mb'=>10240,'db_mb'=>2048,'cpu_label'=>'Shared','ram_label'=>'2GB','plan_type'=>'Shared','price_label'=>'Manual Quote'],
    ];
}
function run_cmd($cmd, &$out=null, &$code=null){
    $out=[]; $code=0; exec($cmd.' 2>&1', $out, $code); return implode("\n", $out);
}
function docker_exists($name){
    $cmd='docker inspect '.escapeshellarg($name).' >/dev/null 2>&1';
    exec($cmd, $o, $c); return $c===0;
}
function container_state($name){
    if(!$name) return 'unknown';
    $cmd='docker inspect -f {{.State.Status}} '.escapeshellarg($name);
    $out=[]; $code=0; exec($cmd.' 2>/dev/null', $out, $code);
    return $code===0 && isset($out[0]) ? trim($out[0]) : 'not_found';
}
function container_started_at($name){
    $cmd='docker inspect -f {{.State.StartedAt}} '.escapeshellarg($name);
    $out=[]; $code=0; exec($cmd.' 2>/dev/null', $out, $code);
    return $code===0 && isset($out[0]) ? trim($out[0]) : '';
}
function parse_bytes($s){
    $s=trim((string)$s); if($s==='') return 0;
    if(preg_match('/([0-9.]+)\s*([KMGT]?i?B|B)?/i',$s,$m)){
        $n=(float)$m[1]; $u=strtolower($m[2] ?? 'b');
        $map=['b'=>1,'kb'=>1000,'kib'=>1024,'mb'=>1000000,'mib'=>1048576,'gb'=>1000000000,'gib'=>1073741824,'tb'=>1000000000000,'tib'=>1099511627776];
        return (int)round($n*($map[$u] ?? 1));
    }
    return 0;
}
function fmt_bytes($bytes){
    $bytes=(float)$bytes; $units=['B','KB','MB','GB','TB']; $i=0;
    while($bytes>=1024 && $i<count($units)-1){$bytes/=1024;$i++;}
    return ($i===0 ? number_format($bytes,0) : number_format($bytes,2)).' '.$units[$i];
}
function site_path_for($row){ return '/customer_data/customer_'.$row['user_id'].'_'.$row['order_id'].'/site'; }
function customer_root_for($row){ return '/customer_data/customer_'.$row['user_id'].'_'.$row['order_id']; }
function primary_domain_for($pdo,$hostingId,$type='website'){
    try{
        $st=$pdo->prepare("SELECT domain FROM custom_domains WHERE hosting_account_id=? AND domain_type=? AND verification_status='verified' ORDER BY CASE WHEN domain LIKE 'www.%' THEN 1 ELSE 0 END, id DESC LIMIT 1");
        $st->execute([$hostingId,$type]);
        $row=$st->fetch();
        return $row['domain'] ?? '';
    } catch(Throwable $e){ return ''; }
}
function display_domain_for($pdo,$hosting,$type='website'){
    $custom=primary_domain_for($pdo,(int)$hosting['id'],$type);
    if($custom) return $custom;
    return $type==='filemanager' ? ($hosting['filebrowser_domain'] ?: parse_url($hosting['filebrowser_url'],PHP_URL_HOST)) : ($hosting['site_domain'] ?: parse_url($hosting['site_url'],PHP_URL_HOST));
}
function display_url_for($pdo,$hosting,$type='website'){
    $domain=display_domain_for($pdo,$hosting,$type);
    return $domain ? url_scheme().'://'.$domain : ($type==='filemanager' ? $hosting['filebrowser_url'] : $hosting['site_url']);
}
function get_du_bytes($path){
    $cmd='du -sb '.escapeshellarg($path).' 2>/dev/null | cut -f1';
    $out=[]; $code=0; exec($cmd, $out, $code);
    return $code===0 && isset($out[0]) ? (int)$out[0] : 0;
}
function file_count($path){
    $cmd='find '.escapeshellarg($path).' -type f 2>/dev/null | wc -l';
    $out=[]; $code=0; exec($cmd, $out, $code);
    return $code===0 && isset($out[0]) ? (int)trim($out[0]) : 0;
}
function db_size_bytes($pdo, $db){
    try{
        $st=$pdo->prepare("SELECT COALESCE(SUM(data_length+index_length),0) s FROM information_schema.TABLES WHERE table_schema=?");
        $st->execute([$db]); return (int)$st->fetch()['s'];
    } catch(Throwable $e){ return 0; }
}
function docker_stats($container){
    $res=['cpu'=>'0%','mem'=>'0 B / 0 B','memperc'=>'0%','net'=>'0 B / 0 B','block'=>'0 B / 0 B'];
    if(!$container || container_state($container)!=='running') return $res;
    $fmt='{{.CPUPerc}}|{{.MemUsage}}|{{.MemPerc}}|{{.NetIO}}|{{.BlockIO}}';
    $cmd='docker stats --no-stream --format '.escapeshellarg($fmt).' '.escapeshellarg($container);
    $out=[]; $code=0; exec($cmd.' 2>/dev/null', $out, $code);
    if($code===0 && isset($out[0])){
        $p=explode('|',$out[0]);
        $res=['cpu'=>$p[0]??'0%','mem'=>$p[1]??'0 B / 0 B','memperc'=>$p[2]??'0%','net'=>$p[3]??'0 B / 0 B','block'=>$p[4]??'0 B / 0 B'];
    }
    return $res;
}
function pct($used,$limit){ if(!$limit) return 0; return min(100, round(($used/$limit)*100,1)); }
function log_activity($pdo,$action,$details='',$hosting_id=null,$user_id=null){
    try{
        $st=$pdo->prepare('INSERT INTO activity_logs(admin_id,user_id,hosting_account_id,action,details) VALUES(?,?,?,?,?)');
        $st->execute([current_admin_id(),$user_id,$hosting_id,$action,$details]);
    } catch(Throwable $e){}
}
function create_reset_token($pdo,$accountType,$email){
    $table = $accountType === 'admin' ? 'admins' : 'users';
    $st=$pdo->prepare("SELECT id,email FROM $table WHERE email=?");
    $st->execute([$email]);
    $row=$st->fetch();
    if(!$row) return null;
    $token=bin2hex(random_bytes(32));
    $hash=hash('sha256',$token);
    $pdo->prepare('INSERT INTO password_reset_tokens(account_type,account_id,token_hash,expires_at,created_ip) VALUES(?,?,?,?,?)')
        ->execute([$accountType,$row['id'],$hash,date('Y-m-d H:i:s', time()+3600),$_SERVER['REMOTE_ADDR'] ?? null]);
    return $token;
}
function consume_reset_token($pdo,$accountType,$token){
    $hash=hash('sha256',$token);
    $st=$pdo->prepare('SELECT * FROM password_reset_tokens WHERE account_type=? AND token_hash=? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $st->execute([$accountType,$hash]);
    $row=$st->fetch();
    if(!$row) return null;
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?')->execute([$row['id']]);
    return $row;
}
function normalize_domain($domain){
    $domain=strtolower(trim((string)$domain));
    $domain=preg_replace('#^https?://#','',$domain);
    $domain=trim(explode('/',$domain)[0]);
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/',$domain) ? $domain : '';
}
function domain_verification_value($domain){
    return 'karacraft-verify='.substr(hash('sha256',$domain.'|'.base_domain()),0,32);
}
function app_secret(){
    $secret=getenv('APP_KEY') ?: getenv('DB_PASS') ?: 'karacraft-local-dev-key-change-me';
    return hash('sha256',$secret,true);
}
function encrypt_secret($plain){
    if($plain==='') return '';
    $iv=random_bytes(12);
    $tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',app_secret(),OPENSSL_RAW_DATA,$iv,$tag);
    return base64_encode($iv.$tag.$cipher);
}
function decrypt_secret($encoded){
    if(!$encoded) return '';
    $raw=base64_decode($encoded,true);
    if($raw===false || strlen($raw)<29) return '';
    $iv=substr($raw,0,12);
    $tag=substr($raw,12,16);
    $cipher=substr($raw,28);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',app_secret(),OPENSSL_RAW_DATA,$iv,$tag);
    return $plain===false ? '' : $plain;
}
function domain_dns_status($domain,$target,$token=''){
    $res=['status'=>'pending','details'=>'DNS not checked'];
    $records=@dns_get_record($domain,DNS_A + DNS_AAAA + DNS_CNAME + DNS_TXT);
    if(!$records){ return ['status'=>'dns_error','details'=>'No DNS records found']; }
    foreach($records as $r){
        if(($r['type'] ?? '')==='CNAME' && strtolower(rtrim($r['target'] ?? '', '.'))===strtolower(rtrim($target,'.'))){
            return ['status'=>'connected','details'=>'CNAME points to '.$target];
        }
        if(($r['type'] ?? '')==='TXT' && $token && str_contains($r['txt'] ?? '', $token)){
            return ['status'=>'connected','details'=>'TXT verification found'];
        }
    }
    foreach($records as $r){
        if(in_array($r['type'] ?? '', ['A','AAAA'], true)){
            return ['status'=>'connected','details'=>'DNS resolves; likely proxied through Cloudflare'];
        }
    }
    return ['status'=>'dns_error','details'=>'DNS exists but does not point to '.$target];
}
function rebuild_domain_routes($pdo,$hostingId,$type='website'){
    $st=$pdo->prepare('SELECT * FROM hosting_accounts WHERE id=?');
    $st->execute([$hostingId]);
    $hrow=$st->fetch();
    if(!$hrow) return [false,'Hosting account not found'];
    $dst=$pdo->prepare("SELECT domain FROM custom_domains WHERE hosting_account_id=? AND domain_type=? AND verification_status='verified' ORDER BY id");
    $dst->execute([$hostingId,$type]);
    $domains=[];
    foreach($dst as $d){ $domains[]=$d['domain']; }
    $temp=$type==='filemanager' ? $hrow['filebrowser_domain'] : $hrow['site_domain'];
    $container=$type==='filemanager' ? $hrow['filebrowser_container'] : $hrow['container_name'];
    $port=$type==='filemanager' ? $hrow['filebrowser_port'] : $hrow['site_port'];
    $cmd='bash /provisioner/rebuild_routes.sh '
        .escapeshellarg($type).' '
        .escapeshellarg($hrow['user_id']).' '
        .escapeshellarg($hrow['order_id']).' '
        .escapeshellarg($container).' '
        .escapeshellarg($port).' '
        .escapeshellarg($temp).' '
        .escapeshellarg(implode(',',$domains));
    $out=[]; $code=0; exec($cmd.' 2>&1',$out,$code);
    return [$code===0, implode("\n",$out)];
}
function cf_request($token,$method,$path,$payload=null){
    $headers=["Authorization: Bearer $token","Content-Type: application/json"];
    $opts=['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>25]];
    if($payload!==null){ $opts['http']['content']=json_encode($payload); }
    $body=@file_get_contents('https://api.cloudflare.com/client/v4'.$path,false,stream_context_create($opts));
    $json=$body ? json_decode($body,true) : null;
    return $json ?: ['success'=>false,'errors'=>[['message'=>'Cloudflare request failed']]];
}
function provider_cloudflare_ready(){
    return (bool)(getenv('CLOUDFLARE_ACCOUNT_ID') && getenv('CLOUDFLARE_TUNNEL_ID') && getenv('CLOUDFLARE_API_TOKEN'));
}
function provider_tunnel_service(){
    return getenv('CLOUDFLARE_TUNNEL_SERVICE') ?: 'http://umbrel.local:8088';
}
function provider_tunnel_config_path(){
    return '/accounts/'.rawurlencode(getenv('CLOUDFLARE_ACCOUNT_ID')).'/cfd_tunnel/'.rawurlencode(getenv('CLOUDFLARE_TUNNEL_ID')).'/configurations';
}
function provider_tunnel_ensure_hostname($hostname, &$detail=null){
    $detail = null;
    $hostname = normalize_domain($hostname);
    if (!$hostname) { $detail='Invalid hostname'; return false; }
    if (!provider_cloudflare_ready()) {
        $detail='Provider Cloudflare automation is not configured. Set CLOUDFLARE_ACCOUNT_ID, CLOUDFLARE_TUNNEL_ID, CLOUDFLARE_API_TOKEN, and CLOUDFLARE_TUNNEL_SERVICE in .env.';
        return false;
    }
    $token = getenv('CLOUDFLARE_API_TOKEN');
    $path = provider_tunnel_config_path();
    $current = cf_request($token, 'GET', $path);
    if (empty($current['success'])) {
        $detail='Could not read Cloudflare Tunnel configuration: '.json_encode($current['errors'] ?? $current);
        return false;
    }
    $config = $current['result']['config'] ?? $current['result'] ?? [];
    if (!is_array($config)) $config = [];
    $ingress = $config['ingress'] ?? [];
    if (!is_array($ingress)) $ingress = [];

    foreach ($ingress as $rule) {
        if (isset($rule['hostname']) && strtolower($rule['hostname']) === strtolower($hostname)) {
            $detail='Tunnel hostname already exists.';
            return true;
        }
    }

    $catchAll = ['service'=>'http_status:404'];
    $kept = [];
    foreach ($ingress as $rule) {
        if (!isset($rule['hostname']) && isset($rule['service']) && str_starts_with((string)$rule['service'], 'http_status:')) {
            $catchAll = $rule;
            continue;
        }
        if (isset($rule['originRequest']) && is_array($rule['originRequest']) && !$rule['originRequest']) {
            $rule['originRequest'] = (object)[];
        }
        $kept[] = $rule;
    }
    $kept[] = ['hostname'=>$hostname, 'originRequest'=>(object)[], 'service'=>provider_tunnel_service()];
    if (isset($catchAll['originRequest']) && is_array($catchAll['originRequest']) && !$catchAll['originRequest']) {
        $catchAll['originRequest'] = (object)[];
    }
    $kept[] = $catchAll;
    $config['ingress'] = $kept;

    $updated = cf_request($token, 'PUT', $path, ['config'=>$config]);
    if (empty($updated['success'])) {
        $detail='Could not update Cloudflare Tunnel configuration: '.json_encode($updated['errors'] ?? $updated);
        return false;
    }
    $detail='Tunnel hostname added.';
    return true;
}
function cloudflare_zone_for($pdo,$uid,$domain=''){
    $st=$pdo->prepare('SELECT * FROM cloudflare_accounts WHERE user_id=? ORDER BY id DESC LIMIT 1');
    $st->execute([$uid]);
    $row=$st->fetch();
    if(!$row) return null;
    $row['api_token']=decrypt_secret($row['api_token_encrypted']);
    if(empty($row['zone_id']) && $domain){
        $parts=explode('.',normalize_domain($domain));
        $zone=count($parts)>=2 ? $parts[count($parts)-2].'.'.$parts[count($parts)-1] : $domain;
        $res=cf_request($row['api_token'],'GET','/zones?name='.rawurlencode($zone));
        if(!empty($res['success']) && !empty($res['result'][0]['id'])){
            $row['zone_id']=$res['result'][0]['id'];
        }
    }
    return $row;
}
function smtp_configured(){
    return (bool)(getenv('SMTP_HOST') && getenv('SMTP_USER') && getenv('SMTP_PASS'));
}
function smtp_send($to,$subject,$body,&$error=null){
    $error=null;
    $host=getenv('SMTP_HOST') ?: '';
    $port=(int)(getenv('SMTP_PORT') ?: 587);
    $user=getenv('SMTP_USER') ?: '';
    $pass=getenv('SMTP_PASS') ?: '';
    $from=getenv('SMTP_FROM') ?: $user;
    $fromName=getenv('SMTP_FROM_NAME') ?: 'Karacraft Hosting';
    $secure=strtolower(getenv('SMTP_SECURE') ?: 'tls');
    if(!$host || !$user || !$pass || !$from){ $error='SMTP is not configured.'; return false; }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if(!$fp){ $error="SMTP connection failed: $errstr"; return false; }
    stream_set_timeout($fp, 20);
    $read=function() use ($fp){ $data=''; while(($line=fgets($fp,515))!==false){ $data.=$line; if(isset($line[3]) && $line[3]===' ') break; } return $data; };
    $send=function($cmd) use ($fp,$read){ fwrite($fp,$cmd."\r\n"); return $read(); };
    $expect=function($resp,$codes) use (&$error){ foreach((array)$codes as $code){ if(str_starts_with($resp,(string)$code)) return true; } $error='SMTP error: '.trim($resp); return false; };

    if(!$expect($read(),220)){ fclose($fp); return false; }
    if(!$expect($send('EHLO '.($_SERVER['SERVER_NAME'] ?? 'karacraft.local')),250)){ fclose($fp); return false; }
    if($secure === 'tls'){
        if(!$expect($send('STARTTLS'),220)){ fclose($fp); return false; }
        if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)){ $error='SMTP TLS negotiation failed.'; fclose($fp); return false; }
        if(!$expect($send('EHLO '.($_SERVER['SERVER_NAME'] ?? 'karacraft.local')),250)){ fclose($fp); return false; }
    }
    if(!$expect($send('AUTH LOGIN'),334)){ fclose($fp); return false; }
    if(!$expect($send(base64_encode($user)),334)){ fclose($fp); return false; }
    if(!$expect($send(base64_encode($pass)),235)){ fclose($fp); return false; }
    if(!$expect($send('MAIL FROM:<'.$from.'>'),250)){ fclose($fp); return false; }
    if(!$expect($send('RCPT TO:<'.$to.'>'),[250,251])){ fclose($fp); return false; }
    if(!$expect($send('DATA'),354)){ fclose($fp); return false; }

    $headers=[
        'From: '.sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $from),
        'To: <'.$to.'>',
        'Subject: '.$subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $message=implode("\r\n",$headers)."\r\n\r\n".$body;
    $message=preg_replace('/^\./m','..',$message);
    fwrite($fp,$message."\r\n.\r\n");
    if(!$expect($read(),250)){ fclose($fp); return false; }
    $send('QUIT');
    fclose($fp);
    return true;
}
function send_reset_email($to,$accountType,$link,&$error=null){
    $label=$accountType === 'admin' ? 'admin' : 'customer';
    $subject='Karacraft Hosting password reset';
    $body="Hello,\n\nUse this link to reset your Karacraft Hosting {$label} password:\n{$link}\n\nThis link expires in 1 hour. If you did not request it, ignore this email.\n\nKaracraft Hosting";
    return smtp_send($to,$subject,$body,$error);
}
function ensure_schema($pdo){
    $alters=[
        "ALTER TABLE admins ADD COLUMN is_active TINYINT(1) DEFAULT 1",
        "ALTER TABLE admins ADD COLUMN last_login_at TIMESTAMP NULL",
        "ALTER TABLE admins ADD COLUMN updated_at TIMESTAMP NULL",
        "ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN site_domain VARCHAR(255) NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN filebrowser_domain VARCHAR(255) NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN local_site_url VARCHAR(255) NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN local_filebrowser_url VARCHAR(255) NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN domain_mode VARCHAR(50) DEFAULT 'port'",
        "ALTER TABLE hosting_accounts ADD COLUMN filebrowser_username VARCHAR(150) NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN filebrowser_password VARCHAR(255) NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN site_port INT NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN filebrowser_port INT NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN storage_limit_mb INT DEFAULT 1024",
        "ALTER TABLE hosting_accounts ADD COLUMN db_limit_mb INT DEFAULT 512",
        "ALTER TABLE hosting_accounts ADD COLUMN expires_at DATE NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN suspended_at TIMESTAMP NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN terminated_at TIMESTAMP NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN last_action VARCHAR(255) NULL",
        "ALTER TABLE hosting_accounts ADD COLUMN backup_status VARCHAR(80) NULL",
        "ALTER TABLE custom_domains ADD COLUMN routing_status VARCHAR(80) NULL",
        "ALTER TABLE custom_domains ADD COLUMN cloudflare_status VARCHAR(80) NULL",
        "ALTER TABLE custom_domains ADD COLUMN ssl_status VARCHAR(80) NULL",
        "ALTER TABLE custom_domains ADD COLUMN dns_target VARCHAR(255) NULL",
        "ALTER TABLE custom_domains ADD COLUMN last_checked_at TIMESTAMP NULL",
    ];
    foreach($alters as $sql){ try{$pdo->exec($sql);}catch(Throwable $e){} }
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NULL, user_id INT NULL, hosting_account_id INT NULL, action VARCHAR(120) NOT NULL, details TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS backups (id INT AUTO_INCREMENT PRIMARY KEY, hosting_account_id INT NOT NULL, backup_type VARCHAR(50) NOT NULL, file_path VARCHAR(255) NOT NULL, file_size BIGINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (id INT AUTO_INCREMENT PRIMARY KEY, account_type ENUM('user','admin') NOT NULL, account_id INT NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_ip VARCHAR(64) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX token_lookup(account_type,token_hash), INDEX account_lookup(account_type,account_id))");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS custom_domains (id INT AUTO_INCREMENT PRIMARY KEY, hosting_account_id INT NOT NULL, domain VARCHAR(255) NOT NULL UNIQUE, domain_type ENUM('website','filemanager') DEFAULT 'website', verification_token VARCHAR(255) NOT NULL, verification_status ENUM('pending','verified','failed') DEFAULT 'pending', cloudflare_status VARCHAR(80) NULL, ssl_status VARCHAR(80) NULL, routing_status VARCHAR(80) NULL, dns_target VARCHAR(255) NULL, last_checked_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (hosting_account_id) REFERENCES hosting_accounts(id) ON DELETE CASCADE)");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS hosting_plans (id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, sites INT DEFAULT 1, storage_mb INT DEFAULT 1024, db_mb INT DEFAULT 512, cpu_label VARCHAR(80) DEFAULT 'Shared', ram_label VARCHAR(80) DEFAULT '512MB', plan_type VARCHAR(80) DEFAULT 'Shared', price_label VARCHAR(80) DEFAULT 'Manual Quote', is_active TINYINT(1) DEFAULT 1, sort_order INT DEFAULT 100, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL)");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, hosting_account_id INT NULL, subject VARCHAR(180) NOT NULL, priority ENUM('low','normal','high','urgent') DEFAULT 'normal', status ENUM('open','waiting_customer','waiting_admin','resolved','closed') DEFAULT 'open', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, admin_id INT NULL, user_id INT NULL, message TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE)");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS invoices (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, order_id INT NULL, hosting_account_id INT NULL, invoice_number VARCHAR(60) NOT NULL UNIQUE, description VARCHAR(255) NOT NULL, amount DECIMAL(12,2) DEFAULT 0, currency VARCHAR(10) DEFAULT 'NGN', status ENUM('draft','unpaid','paid','void') DEFAULT 'unpaid', due_at DATE NULL, paid_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS hosting_checks (id INT AUTO_INCREMENT PRIMARY KEY, hosting_account_id INT NOT NULL, check_type VARCHAR(80) NOT NULL, status VARCHAR(80) NOT NULL, details TEXT NULL, checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (hosting_account_id) REFERENCES hosting_accounts(id) ON DELETE CASCADE)");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS migration_requests (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, hosting_account_id INT NULL, source_host VARCHAR(180) NULL, source_domain VARCHAR(180) NULL, notes TEXT NULL, status ENUM('requested','in_progress','completed','cancelled') DEFAULT 'requested', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS cloudflare_accounts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, api_token_encrypted TEXT NOT NULL, zone_id VARCHAR(120) NULL, account_email VARCHAR(180) NULL, status VARCHAR(80) DEFAULT 'pending', last_check_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");}catch(Throwable $e){}
    try{$pdo->exec("INSERT INTO hosting_plans(slug,name,sites,storage_mb,db_mb,cpu_label,ram_label,plan_type,price_label,sort_order) SELECT 'starter','Starter',1,1024,512,'Shared','512MB','Shared','Manual Quote',10 WHERE NOT EXISTS (SELECT 1 FROM hosting_plans WHERE slug='starter')");}catch(Throwable $e){}
    try{$pdo->exec("INSERT INTO hosting_plans(slug,name,sites,storage_mb,db_mb,cpu_label,ram_label,plan_type,price_label,sort_order) SELECT 'business','Business',3,5120,1024,'Shared','1GB','Shared','Manual Quote',20 WHERE NOT EXISTS (SELECT 1 FROM hosting_plans WHERE slug='business')");}catch(Throwable $e){}
    try{$pdo->exec("INSERT INTO hosting_plans(slug,name,sites,storage_mb,db_mb,cpu_label,ram_label,plan_type,price_label,sort_order) SELECT 'pro','Pro',5,10240,2048,'Shared','2GB','Shared','Manual Quote',30 WHERE NOT EXISTS (SELECT 1 FROM hosting_plans WHERE slug='pro')");}catch(Throwable $e){}
    try{$pdo->exec("UPDATE admins SET is_active=1 WHERE is_active IS NULL");}catch(Throwable $e){}
}
ensure_schema($pdo);
?>
