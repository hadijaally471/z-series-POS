<?php
// activity.php
$page_title = 'Activity Log';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('activity');
$type_f = $_GET['type']??'';
$allowed_types = ['sale','product','customer','expense','debt','po','system'];
if (!in_array($type_f, $allowed_types, true)) {
  $type_f = '';
}
if ($type_f) {
  $stmt = $conn->prepare("SELECT * FROM activity_log WHERE type = ? ORDER BY created_at DESC LIMIT 100");
  $stmt->bind_param('s', $type_f);
  $stmt->execute();
  $logs = $stmt->get_result();
} else {
  $logs = $conn->query("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT 100");
}
$type_icons = ['sale'=>'','product'=>'','customer'=>'','expense'=>'','debt'=>'','po'=>'','system'=>''];
$type_colors = ['sale'=>'var(--success)','product'=>'var(--purple)','customer'=>'var(--info)','expense'=>'var(--danger)','debt'=>'var(--warning)','po'=>'var(--purple2)','system'=>'var(--text3)'];
?>
<form method="GET" style="display:contents"><div class="filter-bar">
  <select class="filter-select" name="type" onchange="this.form.submit()">
    <option value="">All Activities</option>
    <option value="sale" <?=$type_f==='sale'?'selected':''?>>Sales</option>
    <option value="product" <?=$type_f==='product'?'selected':''?>>Products</option>
    <option value="customer" <?=$type_f==='customer'?'selected':''?>>Customers</option>
    <option value="expense" <?=$type_f==='expense'?'selected':''?>>Expenses</option>
    <option value="debt" <?=$type_f==='debt'?'selected':''?>>Debts</option>
    <option value="po" <?=$type_f==='po'?'selected':''?>>Purchase Orders</option>
    <option value="system" <?=$type_f==='system'?'selected':''?>>System</option>
  </select>
  <a href="activity.php" class="btn btn-outline">Clear Filter</a>
</div></form>
<div class="card"><div class="card-header"><span class="card-title">System Activity Log (<?=$logs->num_rows?> recent)</span></div>
<div class="card-body" style="padding:0 20px">
<?php while($log=$logs->fetch_assoc()):
$icon = $type_icons[$log['type']]??'';
$color = $type_colors[$log['type']]??'var(--text3)';
?>
<div class="act-item">
  <div class="act-dot" style="background:<?=$color?>"></div>
  <div class="act-text">
    <span style="color:var(--text);font-weight:600"><?=htmlspecialchars($log['user_name']??'System')?></span>
    — <?=htmlspecialchars($log['action'])?>
    <?php if($log['details']):?><small style="display:block;color:var(--text3)"><?=htmlspecialchars($log['details'])?></small><?php endif;?>
  </div>
  <div class="act-time"><?=date('M d H:i',strtotime($log['created_at']))?></div>
</div>
<?php endwhile; if($logs->num_rows===0):?>
<div class="empty-state"><div class="empty-state-icon"></div><div class="empty-state-title">No activity yet</div></div>
<?php endif;?>
</div></div>
<?php require_once 'includes/footer.php'; ?>
