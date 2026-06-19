<?php
function provision_order($pdo,$orderId,$source='approved'){
    $st = $pdo->prepare('SELECT o.*, u.email, u.name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) return ['ok'=>false,'error'=>'Order not found'];

    $exists = $pdo->prepare('SELECT id FROM hosting_accounts WHERE order_id=?');
    $exists->execute([$orderId]);
    if ($exists->fetch()) return ['ok'=>true,'hosting_id'=>null,'message'=>'Already provisioned'];

    $cmd = 'bash /provisioner/create_hosting.sh '
        . escapeshellarg($order['user_id']) . ' '
        . escapeshellarg($order['id']) . ' '
        . escapeshellarg($order['plan']);

    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) return ['ok'=>false,'error'=>"Provisioning failed:\n".implode("\n", $out)];

    $jsonLine = null;
    foreach (array_reverse($out) as $line) {
        $line = trim($line);
        if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
            $jsonLine = $line;
            break;
        }
    }
    $json = $jsonLine ? json_decode($jsonLine, true) : null;
    if (!$json) return ['ok'=>false,'error'=>"Invalid provisioning output:\n".implode("\n", $out)];

    $plan = plan_details($order['plan']);
    $ins = $pdo->prepare('INSERT INTO hosting_accounts(order_id,user_id,plan,site_url,filebrowser_url,phpmyadmin_url,site_domain,filebrowser_domain,local_site_url,local_filebrowser_url,domain_mode,db_name,db_user,db_password,container_name,filebrowser_container,filebrowser_username,filebrowser_password,site_port,filebrowser_port,storage_limit_mb,db_limit_mb,expires_at,status,last_action) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(CURDATE(), INTERVAL 30 DAY),?,?)');
    $ins->execute([
        $order['id'],$order['user_id'],$order['plan'],$json['site_url'],$json['filebrowser_url'],$json['phpmyadmin_url'],
        $json['site_domain'] ?? '',$json['filebrowser_domain'] ?? '',$json['local_site_url'] ?? '',$json['local_filebrowser_url'] ?? '',
        $json['domain_mode'] ?? 'cloudflare_traefik',$json['db_name'],$json['db_user'],$json['db_password'],$json['container_name'],
        $json['filebrowser_container'],$json['filebrowser_username'] ?? '',$json['filebrowser_password'] ?? '',
        $json['site_port'] ?? null,$json['filebrowser_port'] ?? null,$plan['storage_mb'],$plan['db_mb'],'active',
        $source === 'gift' ? 'Gifted by admin' : 'Provisioned and approved'
    ]);
    $hid = $pdo->lastInsertId();
    $pdo->prepare("UPDATE orders SET status='approved', updated_at=NOW() WHERE id=?")->execute([$orderId]);
    log_activity($pdo, $source === 'gift' ? 'gift_hosting' : 'approve_hosting', 'Provisioned hosting for order #' . $orderId, $hid, $order['user_id']);
    return ['ok'=>true,'hosting_id'=>$hid];
}
?>
