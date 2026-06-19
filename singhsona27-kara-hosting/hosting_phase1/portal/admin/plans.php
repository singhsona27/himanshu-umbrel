<?php require '../config.php'; require_admin(); require '_nav.php';
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=$_POST['action'] ?? '';
  if($action==='save'){
    $id=(int)($_POST['id'] ?? 0);
    $slug=strtolower(preg_replace('/[^a-z0-9_-]/','',$_POST['slug'] ?? ''));
    $vals=[
      trim($_POST['name'] ?? ''),(int)($_POST['sites'] ?? 1),(int)($_POST['storage_mb'] ?? 1024),(int)($_POST['db_mb'] ?? 512),
      trim($_POST['cpu_label'] ?? 'Shared'),trim($_POST['ram_label'] ?? '512MB'),trim($_POST['plan_type'] ?? 'Shared'),
      trim($_POST['price_label'] ?? 'Manual Quote'),isset($_POST['is_active']) ? 1 : 0,(int)($_POST['sort_order'] ?? 100)
    ];
    if($slug && $vals[0]){
      if($id){$pdo->prepare('UPDATE hosting_plans SET name=?,sites=?,storage_mb=?,db_mb=?,cpu_label=?,ram_label=?,plan_type=?,price_label=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=?')->execute([...$vals,$id]);}
      else {$pdo->prepare('INSERT INTO hosting_plans(slug,name,sites,storage_mb,db_mb,cpu_label,ram_label,plan_type,price_label,is_active,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$slug,...$vals]);}
      log_activity($pdo,'save_hosting_plan','Saved plan '.$slug);
      $msg='Plan saved.';
    } else {$msg='Slug and name are required.';}
  }
}
$rows=$pdo->query('SELECT * FROM hosting_plans ORDER BY sort_order,name')->fetchAll();
?>
<!doctype html><html><head><title>Plans</title><link rel="stylesheet" href="/style.css"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="dash"><?php admin_nav(); ?><div class="main"><h1>Package Builder</h1><?php if($msg):?><div class="alert"><?=h($msg)?></div><?php endif;?><div class="card"><h3>Create Plan</h3><form class="wide-form" method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><label>Slug</label><input name="slug" placeholder="starter-plus" required><label>Name</label><input name="name" required><label>Sites</label><input name="sites" type="number" value="1"><label>Storage MB</label><input name="storage_mb" type="number" value="1024"><label>DB MB</label><input name="db_mb" type="number" value="512"><label>CPU</label><input name="cpu_label" value="Shared"><label>RAM</label><input name="ram_label" value="512MB"><label>Type</label><input name="plan_type" value="Shared"><label>Price label</label><input name="price_label" value="Manual Quote"><label><input type="checkbox" name="is_active" checked> Active</label><label>Sort</label><input name="sort_order" type="number" value="100"><button class="btn small">Create</button></form></div><table class="table"><tr><th>Plan</th><th>Limits</th><th>Labels</th><th>Edit</th></tr><?php foreach($rows as $r):?><tr><td><?=h($r['name'])?><br><small><?=h($r['slug'])?> | <?=((int)$r['is_active']===1?'active':'hidden')?></small></td><td><?=h($r['sites'])?> sites<br><?=h(fmt_bytes($r['storage_mb']*1024*1024))?> storage<br><?=h(fmt_bytes($r['db_mb']*1024*1024))?> DB</td><td><?=h($r['cpu_label'])?> / <?=h($r['ram_label'])?><br><?=h($r['price_label'])?></td><td><form class="wide-form" method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h($r['id'])?>"><input type="hidden" name="slug" value="<?=h($r['slug'])?>"><input name="name" value="<?=h($r['name'])?>"><input name="sites" type="number" value="<?=h($r['sites'])?>"><input name="storage_mb" type="number" value="<?=h($r['storage_mb'])?>"><input name="db_mb" type="number" value="<?=h($r['db_mb'])?>"><input name="cpu_label" value="<?=h($r['cpu_label'])?>"><input name="ram_label" value="<?=h($r['ram_label'])?>"><input name="plan_type" value="<?=h($r['plan_type'])?>"><input name="price_label" value="<?=h($r['price_label'])?>"><label><input type="checkbox" name="is_active" <?php if((int)$r['is_active']===1) echo 'checked'; ?>> Active</label><input name="sort_order" type="number" value="<?=h($r['sort_order'])?>"><button class="btn small">Save</button></form></td></tr><?php endforeach;?></table></div></div></body></html>
