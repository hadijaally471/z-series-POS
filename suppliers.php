<?php
$page_title = 'Suppliers';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('suppliers');
$msg = '';
$allowed_types = ['chemicals','z_series_pkg','master_23_pkg','korosho'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = sanitizeString($_POST['name'] ?? '', 200);
        $type = in_array($_POST['type'] ?? '', $allowed_types, true) ? $_POST['type'] : 'chemicals';
        $phone = sanitizeString($_POST['phone'] ?? '', 40);
        $location = sanitizeString($_POST['location'] ?? '', 200);
        $products = sanitizeString($_POST['products_supplied'] ?? '', 500);
        $stmt = $conn->prepare("INSERT INTO suppliers (name,type,phone,location,products_supplied) VALUES (?,?,?,?,?)");
        $stmt->bind_param('sssss',$name,$type,$phone,$location,$products); $stmt->execute();
        logActivity($conn,"Supplier added: $name",'system');
        $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Supplier added!</div>';
    }
    if ($action === 'edit') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        $name = sanitizeString($_POST['name'] ?? '', 200);
        $type = in_array($_POST['type'] ?? '', $allowed_types, true) ? $_POST['type'] : 'chemicals';
        $phone = sanitizeString($_POST['phone'] ?? '', 40);
        $location = sanitizeString($_POST['location'] ?? '', 200);
        $products = sanitizeString($_POST['products_supplied'] ?? '', 500);
        if ($id > 0 && $name !== '') {
            $stmt = $conn->prepare("UPDATE suppliers SET name=?, type=?, phone=?, location=?, products_supplied=? WHERE id=?");
            $stmt->bind_param('sssssi', $name, $type, $phone, $location, $products, $id);
            $stmt->execute();
            logActivity($conn, "Supplier updated: $name", 'system');
            $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Supplier updated!</div>';
        }
    }
    if ($action === 'delete') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT name FROM suppliers WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $sup = $stmt->get_result()->fetch_assoc();
            try {
                $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                logActivity($conn, "Supplier deleted: " . ($sup['name'] ?? 'Unknown'), 'system');
                $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Supplier deleted!</div>';
            } catch (mysqli_sql_exception $e) {
                $msg='<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Cannot delete this supplier — they have products or purchase orders linked to them.</div>';
            }
        }
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
$stats = $conn->query("SELECT COUNT(*) as total, SUM(type='chemicals') as chemicals, SUM(type='z_series_pkg') as z_series, SUM(type='master_23_pkg') as master_23, SUM(type='korosho') as korosho FROM suppliers WHERE status='active'")->fetch_assoc();
?>
<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Total Suppliers</div><div class="stat-value"><?=$stats['total']?></div><div class="stat-icon"></div></div>
  <div class="stat-card green"><div class="stat-label">Chemicals</div><div class="stat-value"><?=$stats['chemicals']?></div><div class="stat-icon"></div></div>
  <div class="stat-card blue"><div class="stat-label">Z Series PKG</div><div class="stat-value"><?=$stats['z_series']?></div><div class="stat-icon"></div></div>
  <div class="stat-card amber"><div class="stat-label">Master 23 PKG</div><div class="stat-value"><?=$stats['master_23']?></div><div class="stat-icon"></div></div>
  <div class="stat-card red"><div class="stat-label">Korosho</div><div class="stat-value"><?=$stats['korosho']?></div><div class="stat-icon"></div></div>
</div>
<?=$msg?>
<form method="GET" style="display:contents"><div class="filter-bar">
  <input class="filter-search" name="search" placeholder="Search suppliers..." value="<?=htmlspecialchars($search)?>"/>
  <select class="filter-select" name="type" onchange="this.form.submit()">
    <option value="">All Types</option><option value="chemicals" <?=$type_f==='chemicals'?'selected':''?>>Chemicals</option><option value="z_series_pkg" <?=$type_f==='z_series_pkg'?'selected':''?>>Z Series PKG</option><option value="master_23_pkg" <?=$type_f==='master_23_pkg'?'selected':''?>>Master 23 PKG</option><option value="korosho" <?=$type_f==='korosho'?'selected':''?>>Korosho</option>
  </select>
  <button type="button" class="btn btn-primary" onclick="openModal('add-supplier-modal')">+ Add Supplier</button>
</div></form>
<div class="card"><div class="card-header"><span class="card-title">All Suppliers (<?=$suppliers->num_rows?>)</span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Type</th><th>Phone</th><th>Location</th><th>Products</th><th>Total Purchased</th><th>Actions</th></tr></thead>
<tbody><?php $i=1; while($s=$suppliers->fetch_assoc()):?>
<tr><td class="text-muted"><?=$i++?></td><td class="td-bold"><?=htmlspecialchars($s['name'])?></td>
<td><span class="badge badge-<?=$s['type']==='chemicals'?'success':($s['type']==='z_series_pkg'?'info':($s['type']==='master_23_pkg'?'warning':'purple'))?>"><?=ucwords(str_replace('_',' ',$s['type']))?></span></td>
<td><?=htmlspecialchars($s['phone'])?></td><td><?=htmlspecialchars($s['location'])?></td>
<td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($s['products_supplied']??'—')?></td>
<td class="text-purple"><?=tzs($s['total_purchased'])?></td>
<td style="display:flex;gap:6px;align-items:center">
  <button type="button" class="btn btn-outline btn-sm" title="Edit" aria-label="Edit" onclick='openEditSupplier(this)' data-id="<?=$s['id']?>" data-name="<?=htmlspecialchars($s['name'], ENT_QUOTES)?>" data-type="<?=htmlspecialchars($s['type'], ENT_QUOTES)?>" data-phone="<?=htmlspecialchars($s['phone'], ENT_QUOTES)?>" data-location="<?=htmlspecialchars($s['location'], ENT_QUOTES)?>" data-products="<?=htmlspecialchars($s['products_supplied'] ?? '', ENT_QUOTES)?>">✏️</button>
  <form method="POST" style="margin:0" data-confirm="Delete <?=htmlspecialchars($s['name'], ENT_QUOTES)?>? This cannot be undone."><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$s['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete">🗑️</button></form>
</td></tr>
<?php endwhile; if($suppliers->num_rows===0): ?><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text3)">No suppliers found</td></tr><?php endif;?></tbody></table></div></div>

<!-- Add Supplier Modal -->
<div class="modal-overlay" id="add-supplier-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Supplier</span><button class="modal-close" onclick="closeModal('add-supplier-modal')"></button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Name *</label><input name="name" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Type</label><select name="type" class="form-control"><option value="chemicals">Chemicals</option><option value="z_series_pkg">Z Series PKG</option><option value="master_23_pkg">Master 23 PKG</option><option value="korosho">Korosho</option></select></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Phone</label><input name="phone" class="form-control" placeholder="+255 7XX XXX XXX"/></div><div class="form-group"><label class="form-label">Location</label><input name="location" class="form-control" placeholder="Region/District"/></div></div>
<div class="form-group"><label class="form-label">Products Supplied</label><input name="products_supplied" class="form-control" placeholder="e.g. Cashew Nuts Grade A, B"/></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-supplier-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Supplier</button></div></form></div></div>

<!-- Edit Supplier Modal -->
<div class="modal-overlay" id="edit-supplier-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Supplier</span><button class="modal-close" onclick="closeModal('edit-supplier-modal')"></button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit-sup-id"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Name *</label><input name="name" id="edit-sup-name" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Type</label><select name="type" id="edit-sup-type" class="form-control"><option value="chemicals">Chemicals</option><option value="z_series_pkg">Z Series PKG</option><option value="master_23_pkg">Master 23 PKG</option><option value="korosho">Korosho</option></select></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Phone</label><input name="phone" id="edit-sup-phone" class="form-control"/></div><div class="form-group"><label class="form-label">Location</label><input name="location" id="edit-sup-location" class="form-control"/></div></div>
<div class="form-group"><label class="form-label">Products Supplied</label><input name="products_supplied" id="edit-sup-products" class="form-control"/></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-supplier-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>

<?php require_once 'includes/footer.php'; ?>
<script>
function openEditSupplier(btn){
  document.getElementById('edit-sup-id').value = btn.dataset.id;
  document.getElementById('edit-sup-name').value = btn.dataset.name;
  document.getElementById('edit-sup-type').value = btn.dataset.type;
  document.getElementById('edit-sup-phone').value = btn.dataset.phone;
  document.getElementById('edit-sup-location').value = btn.dataset.location;
  document.getElementById('edit-sup-products').value = btn.dataset.products;
  openModal('edit-supplier-modal');
}
document.addEventListener('DOMContentLoaded', function() {
  const msgDiv = document.querySelector('div[style*="color:var"]');
  if (msgDiv) {
    setTimeout(() => {
      msgDiv.style.opacity = '0';
      msgDiv.style.transition = 'opacity 0.3s ease-out';
      setTimeout(() => msgDiv.remove(), 300);
    }, 2000);
  }
});
</script>
