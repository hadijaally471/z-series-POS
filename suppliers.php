<?php
$page_title = 'Suppliers';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('suppliers');
$msg = '';
$allowed_types = ['farmer','tile_supplier','materials','other'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = sanitizeString($_POST['name'] ?? '', 200);
        $type = in_array($_POST['type'] ?? '', $allowed_types, true) ? $_POST['type'] : 'other';
        $phone = sanitizeString($_POST['phone'] ?? '', 40);
        $location = sanitizeString($_POST['location'] ?? '', 200);
        $products = sanitizeString($_POST['products_supplied'] ?? '', 500);
        $stmt = $conn->prepare("INSERT INTO suppliers (name,type,phone,location,products_supplied) VALUES (?,?,?,?,?)");
        $stmt->bind_param('sssss',$name,$type,$phone,$location,$products); $stmt->execute();
        logActivity($conn,"Supplier added: $name",'system');
        $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">✅ Supplier added!</div>';
    }
}
$search = sanitizeString($_GET['search']??'', 100);
$type_f = in_array($_GET['type'] ?? '', $allowed_types, true) ? $_GET['type'] : '';
$where = ["status = 'active'"];
$params = [];
$types = '';
if($search) {
  $where[] = "name LIKE ?";
  $params[] = '%' . $search . '%';
  $types .= 's';
}
if($type_f) {
  $where[] = "type = ?";
  $params[] = $type_f;
  $types .= 's';
}
$stmt = $conn->prepare("SELECT * FROM suppliers WHERE " . implode(' AND ', $where) . " ORDER BY name");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$suppliers = $stmt->get_result();
$stats = $conn->query("SELECT COUNT(*) as total, SUM(type='farmer') as farmers, SUM(type='tile_supplier') as tiles, SUM(type='materials') as mats FROM suppliers WHERE status='active'")->fetch_assoc();
?>
<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Total Suppliers</div><div class="stat-value"><?=$stats['total']?></div><div class="stat-icon">🌾</div></div>
  <div class="stat-card green"><div class="stat-label">Cashew Farmers</div><div class="stat-value"><?=$stats['farmers']?></div><div class="stat-icon">🥜</div></div>
  <div class="stat-card blue"><div class="stat-label">Tile Suppliers</div><div class="stat-value"><?=$stats['tiles']?></div><div class="stat-icon">🏗️</div></div>
  <div class="stat-card amber"><div class="stat-label">Raw Material Suppliers</div><div class="stat-value"><?=$stats['mats']?></div><div class="stat-icon">🧴</div></div>
</div>
<?=$msg?>
<form method="GET" style="display:contents"><div class="filter-bar">
  <input class="filter-search" name="search" placeholder="Search suppliers..." value="<?=htmlspecialchars($search)?>"/>
  <select class="filter-select" name="type" onchange="this.form.submit()">
    <option value="">All Types</option><option value="farmer" <?=$type_f==='farmer'?'selected':''?>>Farmers</option><option value="tile_supplier" <?=$type_f==='tile_supplier'?'selected':''?>>Tile Suppliers</option><option value="materials" <?=$type_f==='materials'?'selected':''?>>Materials</option>
  </select>
  <button type="button" class="btn btn-primary" onclick="openModal('add-supplier-modal')">+ Add Supplier</button>
</div></form>
<div class="card"><div class="card-header"><span class="card-title">All Suppliers (<?=$suppliers->num_rows?>)</span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Type</th><th>Phone</th><th>Location</th><th>Products</th><th>Total Purchased</th><th>Status</th></tr></thead>
<tbody><?php $i=1; while($s=$suppliers->fetch_assoc()):?>
<tr><td class="text-muted"><?=$i++?></td><td class="td-bold"><?=htmlspecialchars($s['name'])?></td>
<td><span class="badge badge-<?=$s['type']==='farmer'?'success':($s['type']==='tile_supplier'?'info':'warning')?>"><?=ucwords(str_replace('_',' ',$s['type']))?></span></td>
<td><?=htmlspecialchars($s['phone'])?></td><td><?=htmlspecialchars($s['location'])?></td>
<td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($s['products_supplied']??'—')?></td>
<td class="text-purple"><?=tzs($s['total_purchased'])?></td><td><span class="badge badge-success">Active</span></td></tr>
<?php endwhile; if($suppliers->num_rows===0): ?><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text3)">No suppliers found</td></tr><?php endif;?></tbody></table></div></div>
<div class="modal-overlay" id="add-supplier-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Supplier</span><button class="modal-close" onclick="closeModal('add-supplier-modal')">✕</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Name *</label><input name="name" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Type</label><select name="type" class="form-control"><option value="farmer">Farmer (Cashew)</option><option value="tile_supplier">Tile Supplier</option><option value="materials">Raw Materials</option><option value="other">Other</option></select></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Phone</label><input name="phone" class="form-control" placeholder="+255 7XX XXX XXX"/></div><div class="form-group"><label class="form-label">Location</label><input name="location" class="form-control" placeholder="Region/District"/></div></div>
<div class="form-group"><label class="form-label">Products Supplied</label><input name="products_supplied" class="form-control" placeholder="e.g. Cashew Nuts Grade A, B"/></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-supplier-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Supplier</button></div></form></div></div>
<?php require_once 'includes/footer.php'; ?>
<script>
// Auto-dismiss notification message after 2 seconds
document.addEventListener('DOMContentLoaded', function() {
  const msgDiv = document.querySelector('div[style*="color:var"]');
  if (msgDiv && (msgDiv.textContent.includes('✅') || msgDiv.textContent.includes('❌') || msgDiv.textContent.includes('🗑️'))) {
    setTimeout(() => {
      msgDiv.style.opacity = '0';
      msgDiv.style.transition = 'opacity 0.3s ease-out';
      setTimeout(() => msgDiv.remove(), 300);
    }, 2000);
  }
});
</script>
