<?php
function customer_nav($active='dashboard'){
  $items=[
    'dashboard'=>['/dashboard.php','Dashboard'],
    'websites'=>['/websites.php','Websites'],
    'domains'=>['/domains.php','Domains'],
    'order'=>['/order.php','Order Hosting'],
    'billing'=>['/billing.php','Billing'],
    'support'=>['/support.php','Support'],
    'migration'=>['/migration.php','Migration'],
  ];
?>
<div class="side">
  <h2>Karacraft</h2>
  <?php foreach($items as $key=>$item): ?>
    <a class="<?=h($active===$key?'active-link':'')?>" href="<?=h($item[0])?>"><?=h($item[1])?></a>
  <?php endforeach; ?>
  <a href="/logout.php">Logout</a>
</div>
<?php } ?>

<?php
function website_tabs($site,$active='overview'){
  $tabs=[
    'overview'=>'Overview',
    'domains'=>'Domains',
    'files'=>'Files',
    'databases'=>'Databases',
    'backups'=>'Backups',
    'security'=>'Security',
    'performance'=>'Performance',
    'apps'=>'Apps',
    'logs'=>'Logs',
    'support'=>'Support',
  ];
?>
<div class="tabs">
  <?php foreach($tabs as $key=>$label): ?>
    <a class="<?=h($active===$key?'tab active':'tab')?>" href="/website.php?id=<?=h($site['id'])?>&tab=<?=h($key)?>"><?=h($label)?></a>
  <?php endforeach; ?>
</div>
<?php } ?>
