<?php require 'config.php'; ?>
<!doctype html><html><head><title>Karacraft Hosting</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
<div class="top"><div class="brand">Karacraft Hosting</div><div class="nav"><a href="/login.php">Login</a><a class="btn" href="/signup.php">Get Started</a></div></div>
<section class="hero"><div><h1>Simple hosting for small business websites.</h1><p>Sell hosting plans, approve orders, and provision customer websites with file manager, database, and phpMyAdmin access.</p><a class="btn" href="/signup.php">Start Hosting</a></div><div class="card"><h2>Phase 1 Control Panel</h2><p class="muted">Signup, orders, admin approval, hosting details, FileBrowser and phpMyAdmin links.</p></div></section>
<section class="plans" id="plans">
<?php foreach(active_plans() as $p): $pl=plan_details($p['slug']); ?>
<div class="card plan"><h3><?=h($pl['name'])?></h3><p class="muted"><?=h($pl['type'])?> - <?=h($pl['price'])?></p><p><b><?=h($pl['sites'])?></b> Site(s)</p><p><?=h(fmt_bytes($pl['storage_mb']*1024*1024))?> Storage</p><p><?=h(fmt_bytes($pl['db_mb']*1024*1024))?> Database</p><p><?=h($pl['cpu'])?> CPU</p><p><?=h($pl['ram'])?> RAM</p><a class="btn" href="/signup.php">Choose Plan</a></div>
<?php endforeach; ?>
</section>
</body></html>
