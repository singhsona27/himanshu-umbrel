<?php
require 'config.php';
require_user();
if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Use POST for hosting actions.');
}
verify_csrf();

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$uid = (int)($_SESSION['user_id'] ?? 0);

if ($action === '' && !empty($_FILES['zip_file']['tmp_name'])) {
    $action = 'zip_upload_only';
}
if (in_array($action, ['upload_zip', 'zip_upload_file', 'upload_website_zip'], true)) {
    $action = 'zip_upload_only';
}
if (in_array($action, ['extract_selected_zip', 'unzip_file'], true)) {
    $action = 'extract_zip';
}

$st = $pdo->prepare('SELECT * FROM hosting_accounts WHERE id=? AND user_id=?');
$st->execute([$id, $uid]);
$row = $st->fetch();

if (!$row) {
    die('Hosting account not found');
}

function kcu_exec($cmd) {
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, $out];
}

function kcu_last_json($out) {
    foreach (array_reverse($out) as $line) {
        $line = trim($line);
        if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
            return json_decode($line, true);
        }
    }
    return null;
}

if ($row['status'] === 'terminated') {
    die('This hosting account has been terminated.');
}

if ($action === 'restart_site') {
    $sitePath = site_path_for($row);
    [$fixCode, $fixOut] = kcu_exec('bash /provisioner/fix_site_permissions.sh ' . escapeshellarg($sitePath));
    if ($fixCode !== 0) {
        die('<pre>Fix permissions failed: ' . h(implode("\n", $fixOut)) . '</pre>');
    }

    [$code, $out] = kcu_exec('docker restart ' . escapeshellarg($row['container_name']));
    if ($code !== 0) {
        die('<pre>Restart website failed: ' . h(implode("\n", $out)) . '</pre>');
    }

    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')
        ->execute(['Customer restarted website', $id]);
    log_activity($pdo, 'customer_restart_site', 'Customer restarted website and fixed permissions', $id, $row['user_id']);
    flash('success', 'Website restarted and permissions repaired.');
    redirect('/website.php?id=' . $id . '&tab=overview');
}

if ($action === 'restart_filebrowser') {
    [$code, $out] = kcu_exec('docker restart ' . escapeshellarg($row['filebrowser_container']));
    if ($code !== 0) {
        die('<pre>Restart File Manager failed: ' . h(implode("\n", $out)) . '</pre>');
    }

    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')
        ->execute(['Customer restarted File Manager', $id]);
    log_activity($pdo, 'customer_restart_filebrowser', 'Customer restarted File Manager', $id, $row['user_id']);
    flash('success', 'File Manager restarted successfully.');
    redirect('/website.php?id=' . $id . '&tab=files');
}

if ($action === 'fix_permissions') {
    $sitePath = site_path_for($row);
    [$code, $out] = kcu_exec('bash /provisioner/fix_site_permissions.sh ' . escapeshellarg($sitePath));
    if ($code !== 0) {
        die('<pre>Fix permissions failed: ' . h(implode("\n", $out)) . '</pre>');
    }

    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')
        ->execute(['Customer fixed website permissions', $id]);
    log_activity($pdo, 'customer_fix_permissions', 'Customer fixed website permissions', $id, $row['user_id']);
    flash('success', 'Website file permissions repaired successfully.');
    redirect('/website.php?id=' . $id . '&tab=files');
}

if ($action === 'reset_fb_password') {
    $newPass = bin2hex(random_bytes(8));

    $cmd = 'bash /provisioner/reset_filebrowser_password.sh '
        . escapeshellarg($row['filebrowser_container']) . ' '
        . escapeshellarg($row['filebrowser_username']) . ' '
        . escapeshellarg($newPass);

    [$code, $out] = kcu_exec($cmd);
    $res = kcu_last_json($out);

    if ($code !== 0 || empty($res['success'])) {
        die('<pre>File Manager password reset failed: ' . h(implode("\n", $out)) . '</pre>');
    }

    $pdo->prepare('UPDATE hosting_accounts SET filebrowser_password=?, last_action=? WHERE id=?')
        ->execute([$newPass, 'Customer reset File Manager password', $id]);

    log_activity($pdo, 'customer_reset_filebrowser_password', 'Customer reset File Manager password', $id, $row['user_id']);
    flash('success', 'File Manager password reset. The new password is shown in the Files tab.');
    redirect('/website.php?id=' . $id . '&tab=files');
}

if ($action === 'zip_upload') {
    if (empty($_FILES['zip_file']['tmp_name']) || ($_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        die('ZIP upload failed.');
    }
    $name = $_FILES['zip_file']['name'] ?? 'website.zip';
    if (!preg_match('/\.zip$/i', $name)) {
        die('Only ZIP files are allowed.');
    }

    $sitePath = site_path_for($row);
    $tmpZip = tempnam(sys_get_temp_dir(), 'kc_zip_');
    move_uploaded_file($_FILES['zip_file']['tmp_name'], $tmpZip);

    [$listCode, $listOut] = kcu_exec('unzip -Z1 ' . escapeshellarg($tmpZip));
    if ($listCode !== 0) {
        @unlink($tmpZip);
        die('<pre>Invalid ZIP file: ' . h(implode("\n", $listOut)) . '</pre>');
    }
    foreach ($listOut as $entry) {
        $entry = trim($entry);
        if ($entry === '' || str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry)) {
            @unlink($tmpZip);
            die('ZIP contains an unsafe path.');
        }
    }

    [$code, $out] = kcu_exec('unzip -q -o ' . escapeshellarg($tmpZip) . ' -d ' . escapeshellarg($sitePath));
    @unlink($tmpZip);
    if ($code !== 0) {
        die('<pre>ZIP extraction failed: ' . h(implode("\n", $out)) . '</pre>');
    }
    [$fixCode, $fixOut] = kcu_exec('bash /provisioner/fix_site_permissions.sh ' . escapeshellarg($sitePath));
    if ($fixCode !== 0) {
        die('<pre>ZIP extracted, but permission repair failed: ' . h(implode("\n", $fixOut)) . '</pre>');
    }
    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')->execute(['Customer uploaded and extracted ZIP', $id]);
    log_activity($pdo, 'customer_zip_extract', 'Customer uploaded and extracted ZIP', $id, $row['user_id']);
    flash_action('ZIP uploaded, extracted, permissions repaired, and website restarted.', display_url_for($pdo,$row,'website'));
    redirect('/website.php?id=' . $id . '&tab=files');
}

if ($action === 'zip_upload_only') {
    if (empty($_FILES['zip_file']['tmp_name']) || ($_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        die('ZIP upload failed.');
    }
    $name = basename($_FILES['zip_file']['name'] ?? 'website.zip');
    if (!preg_match('/\.zip$/i', $name)) die('Only ZIP files are allowed.');
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    $sitePath = site_path_for($row);
    if (!is_dir($sitePath.'/_uploads')) mkdir($sitePath.'/_uploads', 0755, true);
    if (!move_uploaded_file($_FILES['zip_file']['tmp_name'], $sitePath.'/_uploads/'.$safe)) die('Could not save ZIP file.');
    [$fixCode, $fixOut] = kcu_exec('bash /provisioner/fix_site_permissions.sh ' . escapeshellarg($sitePath));
    if ($fixCode !== 0) die('<pre>Upload saved, but permission repair failed: ' . h(implode("\n", $fixOut)) . '</pre>');
    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')->execute(['Customer uploaded ZIP', $id]);
    log_activity($pdo, 'customer_zip_upload', 'Customer uploaded ZIP '.$safe, $id, $row['user_id']);
    flash('success', 'ZIP uploaded successfully. Select it from the list and click Extract ZIP.');
    redirect('/website.php?id=' . $id . '&tab=files');
}

if ($action === 'extract_zip') {
    $zip = trim($_POST['zip_path'] ?? '');
    if ($zip === '' || str_starts_with($zip, '/') || str_contains($zip, '..') || str_contains($zip, '\\') || !preg_match('/\.zip$/i', $zip)) {
        die('Invalid ZIP selection.');
    }
    $sitePath = site_path_for($row);
    $cmd = 'bash /provisioner/extract_zip.sh '
        . escapeshellarg($sitePath) . ' '
        . escapeshellarg($zip) . ' '
        . escapeshellarg($row['container_name']);
    [$code, $out] = kcu_exec($cmd);
    if ($code !== 0) die('<pre>ZIP extraction failed: ' . h(implode("\n", $out)) . '</pre>');
    $pdo->prepare('UPDATE hosting_accounts SET last_action=? WHERE id=?')->execute(['Customer extracted ZIP and restarted website', $id]);
    log_activity($pdo, 'customer_zip_extract', 'Customer extracted ZIP '.$zip, $id, $row['user_id']);
    flash_action('ZIP extracted successfully. Permissions were repaired and the website was restarted.', display_url_for($pdo,$row,'website'));
    redirect('/website.php?id=' . $id . '&tab=files');
}

if ($action === 'backup') {
    $cmd = 'bash /provisioner/backup_hosting.sh '
        . escapeshellarg($row['user_id']) . ' '
        . escapeshellarg($row['order_id']) . ' '
        . escapeshellarg($row['db_name']);
    [$code, $out] = kcu_exec($cmd);
    if ($code !== 0) die('<pre>Backup failed: ' . h(implode("\n", $out)) . '</pre>');
    $json = kcu_last_json($out);
    if ($json) {
        $siteSize = is_file($json['site_backup'] ?? '') ? filesize($json['site_backup']) : 0;
        $dbSize = is_file($json['db_backup'] ?? '') ? filesize($json['db_backup']) : 0;
        $pdo->prepare('INSERT INTO backups(hosting_account_id,backup_type,file_path,file_size) VALUES(?,?,?,?),(?,?,?,?)')
            ->execute([$id, 'site', $json['site_backup'], $siteSize, $id, 'database', $json['db_backup'], $dbSize]);
    }
    $pdo->prepare('UPDATE hosting_accounts SET last_action=?, backup_status=? WHERE id=?')->execute(['Customer created backup','Backup created',$id]);
    log_activity($pdo, 'customer_backup', 'Customer created backup', $id, $row['user_id']);
    flash('success', 'Backup created successfully.');
    redirect('/website.php?id=' . $id . '&tab=backups');
}

if ($action === 'restore_backup') {
    $backupId=(int)($_POST['backup_id'] ?? 0);
    $bst=$pdo->prepare('SELECT * FROM backups WHERE id=? AND hosting_account_id=?');
    $bst->execute([$backupId,$id]);
    $backup=$bst->fetch();
    if(!$backup) die('Backup not found.');
    $cmd='bash /provisioner/restore_backup.sh '
        .escapeshellarg($backup['backup_type']).' '
        .escapeshellarg($backup['file_path']).' '
        .escapeshellarg($row['user_id']).' '
        .escapeshellarg($row['order_id']).' '
        .escapeshellarg($row['db_name']);
    [$code,$out]=kcu_exec($cmd);
    if($code!==0) die('<pre>Restore failed: '.h(implode("\n",$out)).'</pre>');
    if($backup['backup_type']==='site') kcu_exec('docker restart '.escapeshellarg($row['container_name']));
    $pdo->prepare('UPDATE hosting_accounts SET last_action=?, backup_status=? WHERE id=?')->execute(['Customer restored backup','Restored '.$backup['backup_type'],$id]);
    log_activity($pdo,'customer_restore_backup','Customer restored backup #'.$backupId,$id,$row['user_id']);
    flash('success', 'Backup restored successfully.');
    redirect('/website.php?id=' . $id . '&tab=backups');
}

if ($action === 'reset_website') {
    $confirm = trim($_POST['reset_confirm'] ?? '');
    if ($confirm !== 'RESET WEBSITE') {
        die('Type RESET WEBSITE to confirm this destructive reset.');
    }

    $sitePath = site_path_for($row);
    $cmd = 'bash /provisioner/reset_website.sh '
        . escapeshellarg($sitePath) . ' '
        . escapeshellarg($row['db_name']) . ' '
        . escapeshellarg($row['db_user']) . ' '
        . escapeshellarg($row['db_password']) . ' '
        . escapeshellarg($row['container_name']);

    [$code, $out] = kcu_exec($cmd);
    $res = kcu_last_json($out);

    if ($code !== 0 || empty($res['success'])) {
        die('<pre>Website reset failed: ' . h(implode("\n", $out)) . '</pre>');
    }

    $pdo->prepare('UPDATE hosting_accounts SET last_action=?, backup_status=NULL WHERE id=?')
        ->execute(['Customer reset website files and database', $id]);
    log_activity($pdo, 'customer_reset_website', 'Customer deleted website files, app data, and reset database', $id, $row['user_id']);
    flash('success', 'Website files and database were reset. You can install a fresh app now.');
    redirect('/website.php?id=' . $id . '&tab=apps');
}

die('Invalid action');
?>
