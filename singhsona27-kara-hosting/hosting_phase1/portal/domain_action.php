<?php
require 'config.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Use POST for domain actions.');
}
verify_csrf();

function domain_action_return_url($hostingId = 0) {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($hostingId && strpos($ref, '/website.php') !== false) {
        return '/website.php?id=' . (int)$hostingId . '&tab=domains';
    }
    return '/domains.php';
}

function domain_action_rebuild_routes($pdo, $hostingId, $type) {
    $st = $pdo->prepare('SELECT * FROM hosting_accounts WHERE id=?');
    $st->execute([$hostingId]);
    $h = $st->fetch();
    if (!$h) return [false, 'Hosting account not found'];

    $dst = $pdo->prepare("SELECT domain FROM custom_domains WHERE hosting_account_id=? AND domain_type=? AND verification_status='verified' ORDER BY id");
    $dst->execute([$hostingId, $type]);
    $domains = [];
    foreach ($dst as $d) $domains[] = $d['domain'];

    $temp = $type === 'filemanager' ? $h['filebrowser_domain'] : $h['site_domain'];
    $container = $type === 'filemanager' ? $h['filebrowser_container'] : $h['container_name'];
    $port = $type === 'filemanager' ? $h['filebrowser_port'] : $h['site_port'];

    $cmd = 'bash /provisioner/rebuild_routes.sh '
        . escapeshellarg($type) . ' '
        . escapeshellarg($h['user_id']) . ' '
        . escapeshellarg($h['order_id']) . ' '
        . escapeshellarg($container) . ' '
        . escapeshellarg($port) . ' '
        . escapeshellarg($temp) . ' '
        . escapeshellarg(implode(',', $domains));

    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code === 0, implode("\n", $out)];
}

try {
    $uid = (int) current_user_id();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add') {
        $hid = (int) ($_POST['hosting_account_id'] ?? 0);
        $domain = normalize_domain($_POST['domain'] ?? '');
        $type = ($_POST['domain_type'] ?? 'website') === 'filemanager' ? 'filemanager' : 'website';

        $st = $pdo->prepare('SELECT * FROM hosting_accounts WHERE id=? AND user_id=? AND status<>"terminated"');
        $st->execute([$hid, $uid]);
        $host = $st->fetch();
        if (!$host) die('Hosting account not found.');
        if (!$domain) die('Enter a valid domain name.');

        $target = $type === 'filemanager'
            ? ($host['filebrowser_domain'] ?: parse_url($host['filebrowser_url'], PHP_URL_HOST))
            : ($host['site_domain'] ?: parse_url($host['site_url'], PHP_URL_HOST));
        if (!$target) die('Temporary target domain is missing.');

        $pdo->prepare('INSERT INTO custom_domains(hosting_account_id,domain,domain_type,verification_token,dns_target) VALUES(?,?,?,?,?)')
            ->execute([$hid, $domain, $type, domain_verification_value($domain), $target]);

        if ($type === 'website' && strpos($domain, 'www.') !== 0 && $domain === likely_cloudflare_zone($domain)) {
            $www = 'www.' . $domain;
            try {
                $pdo->prepare('INSERT INTO custom_domains(hosting_account_id,domain,domain_type,verification_token,dns_target) VALUES(?,?,?,?,?)')
                    ->execute([$hid, $www, $type, domain_verification_value($www), $target]);
            } catch (Throwable $ignored) {}
        }

        log_activity($pdo, 'customer_add_custom_domain', 'Customer added ' . $domain, $hid, $uid);
        flash('success', 'Domain added. Follow Step 2 to add the CNAME record in Cloudflare, then click Verify.');
        redirect(domain_action_return_url($hid));
    }

    if ($action === 'delete' || $action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT d.*,h.user_id FROM custom_domains d JOIN hosting_accounts h ON h.id=d.hosting_account_id WHERE d.id=? AND h.user_id=?');
        $st->execute([$id, $uid]);
        $domain = $st->fetch();
        if (!$domain) die('Domain not found.');

        $pdo->prepare('DELETE FROM custom_domains WHERE id=?')->execute([$id]);
        domain_action_rebuild_routes($pdo, $domain['hosting_account_id'], $domain['domain_type']);
        log_activity($pdo, 'customer_delete_custom_domain', 'Customer removed ' . $domain['domain'], $domain['hosting_account_id'], $uid);
        flash('success', 'Domain removed and routing was refreshed.');
        redirect(domain_action_return_url($domain['hosting_account_id']));
    }

    if (in_array($action, ['verify', 'repair', 'repair_domain', 'dns_repair'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT d.*,h.user_id FROM custom_domains d JOIN hosting_accounts h ON h.id=d.hosting_account_id WHERE d.id=? AND h.user_id=?');
        $st->execute([$id, $uid]);
        $domain = $st->fetch();
        if (!$domain) die('Domain not found.');

        $dns = domain_dns_status($domain['domain'], $domain['dns_target'], $domain['verification_token']);
        if ($dns['status'] === 'connected' || $action !== 'verify') {
            $pdo->prepare("UPDATE custom_domains SET verification_status='verified', cloudflare_status=COALESCE(cloudflare_status,'manual_dns_ready'), ssl_status=COALESCE(ssl_status,'ssl_pending'), last_checked_at=NOW() WHERE id=?")
                ->execute([$id]);
            [$ok, $out] = domain_action_rebuild_routes($pdo, $domain['hosting_account_id'], $domain['domain_type']);
            $pdo->prepare('UPDATE custom_domains SET routing_status=? WHERE id=?')->execute([$ok ? 'connected' : 'route_error', $id]);
            if (!$ok) die('<pre>Domain route rebuild failed: ' . h($out) . '</pre>');
            log_activity($pdo, $action === 'verify' ? 'customer_verify_domain' : 'customer_repair_domain', $domain['domain'], $domain['hosting_account_id'], $uid);
            if (!is_platform_domain($domain['domain'])) {
                $tunnelDetail = '';
                $tunnelOk = provider_tunnel_ensure_hostname($domain['domain'], $tunnelDetail);
                $public = $tunnelOk ? public_domain_status($domain['domain']) : ['status'=>'automation_required','code'=>0];
                if (!$tunnelOk) {
                    $pdo->prepare("UPDATE custom_domains SET cloudflare_status='automation_required', ssl_status='pending' WHERE id=?")->execute([$id]);
                    flash('error', 'DNS and Karacraft routing are ready, but automatic Cloudflare Tunnel onboarding could not complete. '.$tunnelDetail);
                } elseif ($public['status'] === 'tunnel_missing') {
                    $pdo->prepare("UPDATE custom_domains SET cloudflare_status='tunnel_propagating', ssl_status='pending' WHERE id=?")->execute([$id]);
                    flash('success', 'Cloudflare Tunnel hostname was added. Cloudflare may take a short time to propagate; try opening the domain in 1-2 minutes.');
                } else {
                    $pdo->prepare("UPDATE custom_domains SET cloudflare_status=?, ssl_status=IF(?='reachable','active',ssl_status) WHERE id=?")->execute([$public['status'], $public['status'], $id]);
                    flash('success', ($action === 'verify' ? 'Domain DNS verified and connected automatically.' : 'Domain route repaired automatically.'));
                }
            } else {
                flash('success', $action === 'verify' ? 'Domain verified and connected.' : 'Domain route repaired successfully.');
            }
        } else {
            $pdo->prepare("UPDATE custom_domains SET verification_status='failed', cloudflare_status=?, last_checked_at=NOW() WHERE id=?")
                ->execute([$dns['status'], $id]);
            log_activity($pdo, 'customer_domain_check_failed', $domain['domain'] . ': ' . $dns['details'], $domain['hosting_account_id'], $uid);
            flash('error', 'Domain is not ready yet: ' . $dns['details']);
        }

        redirect(domain_action_return_url($domain['hosting_account_id']));
    }

    die('Invalid domain action: ' . h($action));
} catch (Throwable $e) {
    http_response_code(500);
    die('<pre>Domain action failed: ' . h($e->getMessage()) . '</pre>');
}
?>
