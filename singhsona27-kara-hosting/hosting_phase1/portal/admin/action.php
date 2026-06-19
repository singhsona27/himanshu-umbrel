<?php
require '../config.php';
require_admin();
if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Use POST for admin hosting actions.');
}
verify_csrf();

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

$st = $pdo->prepare('SELECT * FROM hosting_accounts WHERE id=?');
$st->execute([$id]);
$row = $st->fetch();

if (!$row) {
    die('Hosting account not found');
}

function kc_exec($cmd) {
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, $out];
}

function kc_last_json($out) {
    foreach (array_reverse($out) as $line) {
        $line = trim($line);
        if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
            return json_decode($line, true);
        }
    }
    return null;
}

if ($action === 'reset_fb_password') {
    $newPass = bin2hex(random_bytes(8));

    $cmd = 'bash /provisioner/reset_filebrowser_password.sh '
        . escapeshellarg($row['filebrowser_container']) . ' '
        . escapeshellarg($row['filebrowser_username']) . ' '
        . escapeshellarg($newPass);

    [$code, $out] = kc_exec($cmd);
    $res = kc_last_json($out);

    if ($code !== 0 || empty($res['success'])) {
        die('<pre>FileBrowser password reset failed: ' . h(implode("\n", $out)) . '</pre>');
    }

    $pdo->prepare("UPDATE hosting_accounts SET filebrowser_password=?, last_action=? WHERE id=?")
        ->execute([$newPass, 'FileBrowser password reset', $id]);

    log_activity($pdo, 'reset_filebrowser_password', 'FileBrowser password reset', $id, $row['user_id']);
    redirect('/admin/hosting.php');
}


if ($action === 'fix_permissions') {
    $sitePath = site_path_for($row);
    [$code, $out] = kc_exec('bash /provisioner/fix_site_permissions.sh ' . escapeshellarg($sitePath));
    if ($code !== 0) {
        die('<pre>Fix permissions failed: ' . h(implode("\n", $out)) . '</pre>');
    }
    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')->execute(['Website permissions fixed', $id]);
    log_activity($pdo, 'fix_site_permissions', 'Website permissions fixed', $id, $row['user_id']);
    redirect('/admin/hosting.php');
}

if ($action === 'backup') {
    $cmd = 'bash /provisioner/backup_hosting.sh '
        . escapeshellarg($row['user_id']) . ' '
        . escapeshellarg($row['order_id']) . ' '
        . escapeshellarg($row['db_name']);

    [$code, $out] = kc_exec($cmd);
    if ($code !== 0) {
        die('<pre>Backup failed: ' . h(implode("\n", $out)) . '</pre>');
    }

    $json = kc_last_json($out);
    if ($json) {
        $siteSize = is_file($json['site_backup'] ?? '') ? filesize($json['site_backup']) : 0;
        $dbSize = is_file($json['db_backup'] ?? '') ? filesize($json['db_backup']) : 0;
        $pdo->prepare('INSERT INTO backups(hosting_account_id,backup_type,file_path,file_size) VALUES(?,?,?,?),(?,?,?,?)')
            ->execute([$id, 'site', $json['site_backup'], $siteSize, $id, 'database', $json['db_backup'], $dbSize]);
    }

    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')->execute(['Backup created', $id]);
    log_activity($pdo, 'backup_hosting', 'Backup created', $id, $row['user_id']);
    redirect('/admin/backups.php');
}

if ($action === 'renew_30' || $action === 'renew') {
    $pdo->prepare("UPDATE hosting_accounts SET expires_at=DATE_ADD(COALESCE(expires_at,CURDATE()), INTERVAL 30 DAY), status=IF(status='expired','active',status), last_action='Renewed for 30 days' WHERE id=?")
        ->execute([$id]);
    log_activity($pdo, 'renew_hosting', 'Renewed for 30 days', $id, $row['user_id']);
    redirect('/admin/hosting.php');
}


if ($action === 'restart_site') {
    $sitePath = site_path_for($row);
    kc_exec('bash /provisioner/fix_site_permissions.sh ' . escapeshellarg($sitePath));
    [$code, $out] = kc_exec('docker restart ' . escapeshellarg($row['container_name']));
    if ($code !== 0) {
        die('<pre>Restart site failed: ' . h(implode("\n", $out)) . '</pre>');
    }
    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')->execute(['Website restarted', $id]);
    log_activity($pdo, 'restart_site', 'Website container restarted', $id, $row['user_id']);
    redirect('/admin/hosting.php');
}

if ($action === 'restart_filebrowser') {
    [$code, $out] = kc_exec('docker restart ' . escapeshellarg($row['filebrowser_container']));
    if ($code !== 0) {
        die('<pre>Restart FileBrowser failed: ' . h(implode("\n", $out)) . '</pre>');
    }
    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')->execute(['FileBrowser restarted', $id]);
    log_activity($pdo, 'restart_filebrowser', 'FileBrowser container restarted', $id, $row['user_id']);
    redirect('/admin/hosting.php');
}

$allowed = ['suspend', 'unsuspend', 'terminate'];
if (!in_array($action, $allowed, true)) {
    die('Invalid action');
}

$scriptAction = $action;
$newStatus = null;
if ($action === 'suspend') {
    $newStatus = 'suspended';
}
if ($action === 'unsuspend') {
    $scriptAction = 'unsuspend';
    $newStatus = 'active';
}
if ($action === 'terminate') {
    $newStatus = 'terminated';
}

$cmd = 'bash /provisioner/hosting_action.sh '
    . escapeshellarg($scriptAction) . ' '
    . escapeshellarg($row['container_name']) . ' '
    . escapeshellarg($row['filebrowser_container']) . ' '
    . escapeshellarg($row['db_name']) . ' '
    . escapeshellarg($row['db_user']) . ' '
    . escapeshellarg($row['user_id']) . ' '
    . escapeshellarg($row['order_id']);

[$code, $out] = kc_exec($cmd);
if ($code !== 0) {
    die('<pre>Action failed: ' . h(implode("\n", $out)) . '</pre>');
}

$last = str_replace('_', ' ', $action);
if ($newStatus) {
    if ($action === 'suspend') {
        $pdo->prepare('UPDATE hosting_accounts SET status=?, suspended_at=NOW(), last_action=? WHERE id=?')
            ->execute([$newStatus, $last, $id]);
    } elseif ($action === 'terminate') {
        $pdo->prepare('UPDATE hosting_accounts SET status=?, terminated_at=NOW(), last_action=? WHERE id=?')
            ->execute([$newStatus, $last, $id]);
    } else {
        $pdo->prepare('UPDATE hosting_accounts SET status=?, last_action=? WHERE id=?')
            ->execute([$newStatus, $last, $id]);
    }
} else {
    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')->execute([$last, $id]);
}

log_activity($pdo, $action, $last, $id, $row['user_id']);
redirect('/admin/hosting.php');
?>
