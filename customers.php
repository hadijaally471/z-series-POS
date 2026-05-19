<?php
// customers.php
$page_title = 'Customers';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('customers');
$msg = '';
$allowed_types = ['rejareja','jumla'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = sanitizeString($_POST['name'] ?? '', 200);
        $phone = sanitizeString($_POST['phone'] ?? '', 40);
        $type = in_array($_POST['type'] ?? '', $allowed_types, true) ? $_POST['type'] : 'rejareja';
        $location = sanitizeString($_POST['location'] ?? '', 200);
        $stmt = $conn->prepare("INSERT INTO customers (name,phone,type,location) VALUES (?,?,?,?)");
        $stmt->bind_param('ssss',$name,$phone,$type,$location); $stmt->execute();
        logActivity($conn,"Customer added: $name",'customer');
        $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">✅ Customer added!</div>';
    }
    if ($action === 'delete') {
        $cid = sanitizeInt($_POST['customer_id'] ?? 0);
        if ($cid > 0) {
            $stmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
            $stmt->bind_param('i', $cid);
            $stmt->execute();
            $c = $stmt->get_result()->fetch_assoc();
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->bind_param('i', $cid);
            $stmt->execute();
            logActivity($conn,"Customer deleted: " . ($c['name'] ?? 'Unknown'),'customer');
            $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">🗑️ Customer deleted!</div>';
        }
    }
}
$search = sanitizeString($_GET['search'] ?? '', 100);
$type_f = in_array($_GET['type'] ?? '', $allowed_types, true) ? $_GET['type'] : '';
$city_f = sanitizeString($_GET['city'] ?? '', 100);
$phone_f = sanitizeString($_GET['phone'] ?? '', 40);
$where = ["1=1"];
$params = [];
$types = '';
if($search) {
  $where[] = "(name LIKE ? OR phone LIKE ?)";
  $params[] = '%' . $search . '%';
  $params[] = '%' . $search . '%';
  $types .= 'ss';
}
if($type_f) {
  $where[] = "type = ?";
  $params[] = $type_f;
  $types .= 's';
}
if($city_f) {
  $where[] = "location = ?";
  $params[] = $city_f;
  $types .= 's';
}
if($phone_f) {
  $where[] = "phone LIKE ?";
  $params[] = '%' . $phone_f . '%';
  $types .= 's';
}
$stmt = $conn->prepare("SELECT * FROM customers WHERE " . implode(' AND ', $where) . " ORDER BY name");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$customers = $stmt->get_result();
$stats = $conn->query("SELECT COUNT(*) as total, SUM(type='rejareja') as rej, SUM(type='jumla') as jum, SUM(outstanding_debt>0) as with_debt, SUM(outstanding_debt) as total_debt FROM customers")->fetch_assoc();

// Get unique cities for filter dropdown
$cities = $conn->query("SELECT DISTINCT location FROM customers WHERE location IS NOT NULL AND location!='' ORDER BY location")->fetch_all(MYSQLI_ASSOC);
$cities_list = ['Arusha', 'Dar es Salaam', 'Dodoma', 'Kilimanjaro', 'Mbeya', 'Moshi', 'Mwanza', 'Iringa', 'Morogoro', 'Tanga', 'Kigoma', 'Singida', 'Tabora'];
?>
<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Total Customers</div><div class="stat-value"><?=$stats['total']?></div><div class="stat-icon">👥</div></div>
  <div class="stat-card blue"><div class="stat-label">Jumla Customers</div><div class="stat-value"><?=$stats['jum']?></div><div class="stat-icon">🏪</div></div>
  <div class="stat-card green"><div class="stat-label">Rejareja Customers</div><div class="stat-value"><?=$stats['rej']?></div><div class="stat-icon">🛍️</div></div>
  <div class="stat-card red"><div class="stat-label">Total Debt</div><div class="stat-value"><?=tzs($stats['total_debt'])?></div><div class="stat-icon">💰</div></div>
</div>
<?=$msg?>
<form method="GET" style="display:contents">
<div class="filter-bar">
  <input class="filter-search" name="search" placeholder="Search by name or phone..." value="<?=htmlspecialchars($search)?>"/>
  <select class="filter-select" name="type" onchange="this.form.submit()">
    <option value="">All Types</option><option value="rejareja" <?=$type_f==='rejareja'?'selected':''?>>Rejareja</option><option value="jumla" <?=$type_f==='jumla'?'selected':''?>>Jumla</option>
  </select>
  <select class="filter-select" name="city" onchange="this.form.submit()">
    <option value="">All Cities</option>
    <?php foreach($cities_list as $c): ?>
    <option value="<?=htmlspecialchars($c)?>" <?=$city_f===$c?'selected':''?>><?=htmlspecialchars($c)?></option>
    <?php endforeach; ?>
  </select>
  <input class="filter-search" style="flex:0.8" name="phone" placeholder="Filter by phone..." value="<?=htmlspecialchars($phone_f)?>" onchange="this.form.submit()"/>
  <button type="submit" class="btn btn-outline">Filter</button>
  <button type="button" class="btn btn-primary" onclick="openModal('add-customer-modal')">+ Add Customer</button>
</div></form>
<div class="card"><div class="card-header"><span class="card-title">All Customers (<?=$customers->num_rows?>)</span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Type</th><th>Location</th><th>Total Purchases</th><th>Action</th></tr></thead>
<tbody><?php $i=1; while($c=$customers->fetch_assoc()):?>
<tr><td class="text-muted"><?=$i++?></td><td class="td-bold"><?=htmlspecialchars($c['name'])?></td><td><?=htmlspecialchars($c['phone'])?></td>
<td><span class="badge badge-<?=$c['type']==='jumla'?'info':'purple'?>"><?=ucfirst($c['type'])?></span></td>
<td><?=htmlspecialchars($c['location']??'—')?></td><td class="text-success"><?=tzs($c['total_purchases'])?></td>
<td><form method="POST" style="display:inline" onsubmit="return confirm('Delete customer: <?=htmlspecialchars(str_replace("'", "\\'", $c['name']))?>\nThis action cannot be undone.');"><input type="hidden" name="action" value="delete"><input type="hidden" name="customer_id" value="<?=$c['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-sm btn-danger" style="padding:4px 8px;font-size:11px">🗑️ Delete</button></form></td></tr>
<?php endwhile; if($customers->num_rows===0): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No customers found</td></tr><?php endif;?></tbody></table></div></div>
<div class="modal-overlay" id="add-customer-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Customer</span><button class="modal-close" onclick="closeModal('add-customer-modal')">✕</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Full Name *</label><input name="name" class="form-control" required/></div><div class="form-group"><label class="form-label">Phone</label><input name="phone" class="form-control" placeholder="+255 7XX XXX XXX"/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Customer Type</label><select name="type" class="form-control"><option value="rejareja">Rejareja (Retail)</option><option value="jumla">Jumla (Wholesale)</option></select></div><div class="form-group"><label class="form-label">City</label><select name="location" class="form-control" required><option value="">Select a city...</option><?php foreach($cities_list as $c): ?><option value="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?></option><?php endforeach; ?></select></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-customer-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Customer</button></div></form></div></div>
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
