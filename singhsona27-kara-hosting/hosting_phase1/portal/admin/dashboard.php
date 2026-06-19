<?php require '../config.php'; require_admin(); require '_nav.php';
$pending=$pdo->query("SELECT COUNT(*) c FROM orders WHERE status='pending'")->fetch()['c'];
$users=$pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
$active=$pdo->query("SELECT COUNT(*) c FROM hosting_accounts WHERE status='active'")->fetch()['c'];
$suspended=$pdo->query("SELECT COUNT(*) c FROM hosting_accounts WHERE status='suspended'")->fetch()['c'];
$expiring=$pdo->query("SELECT COUNT(*) c FROM hosting_accounts WHERE expires_at IS NOT NULL AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch()['c'];
$tickets=$pdo->query("SELECT COUNT(*) c FROM support_tickets WHERE status NOT IN ('resolved','closed')")->fetch()['c'];
$unpaid=$pdo->query("SELECT COUNT(*) c FROM invoices WHERE status='unpaid'")->fetch()['c'];
$migrations=$pdo->query("SELECT COUNT(*) c FROM migration_requests WHERE status IN ('requested','in_progress')")->fetch()['c'];
$logs=$pdo->query('SELECT * FROM activity_logs ORDER BY id DESC LIMIT 8')->fetchAll();
?>
<!doctype html><html><head><title>Admin Dashboard</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>Admin Dashboard</h1><div class="grid"><div class="card stat"><span>Pending Orders</span><h1><?=h($pending)?></h1></div><div class="card stat"><span>Customers</span><h1><?=h($users)?></h1></div><div class="card stat"><span>Active Hosting</span><h1><?=h($active)?></h1></div><div class="card stat"><span>Suspended</span><h1><?=h($suspended)?></h1></div><div class="card stat"><span>Expiring 7 Days</span><h1><?=h($expiring)?></h1></div><div class="card stat"><span>Open Tickets</span><h1><?=h($tickets)?></h1></div><div class="card stat"><span>Unpaid Invoices</span><h1><?=h($unpaid)?></h1></div><div class="card stat"><span>Migrations</span><h1><?=h($migrations)?></h1></div></div><div class="card"><h2>Recent Activity</h2><table class="table"><tr><th>Action</th><th>Details</th><th>Date</th></tr><?php foreach($logs as $l):?><tr><td><?=h($l['action'])?></td><td><?=h($l['details'])?></td><td><?=h($l['created_at'])?></td></tr><?php endforeach;?></table></div></div></div></body></html>
