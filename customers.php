<?php
// customers.php
$page_title = 'Customers';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('customers');
$msg = '';
if (!empty($_SESSION['customers_flash'])) {
  $msg = $_SESSION['customers_flash'];
  unset($_SESSION['customers_flash']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = sanitizeString($_POST['name'] ?? '', 200);
        $phone = sanitizeString($_POST['phone'] ?? '', 40);
        $category = in_array($_POST['category'] ?? '', ['wholesale', 'retail'], true) ? $_POST['category'] : null;
        $location = sanitizeString($_POST['location'] ?? '', 200);
        $stmt = $conn->prepare("INSERT INTO customers (name,phone,category,location) VALUES (?,?,?,?)");
        $stmt->bind_param('ssss',$name,$phone,$category,$location); $stmt->execute();
        logActivity($conn,"Customer added: $name",'customer');
        $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Customer added!</div>';
    }
    if ($action === 'edit') {
        $cid = sanitizeInt($_POST['customer_id'] ?? 0);
        $name = sanitizeString($_POST['name'] ?? '', 200);
        $phone = sanitizeString($_POST['phone'] ?? '', 40);
        $category = in_array($_POST['category'] ?? '', ['wholesale', 'retail'], true) ? $_POST['category'] : null;
        $location = sanitizeString($_POST['location'] ?? '', 200);
        if ($cid > 0 && $name !== '') {
            $stmt = $conn->prepare("UPDATE customers SET name=?, phone=?, category=?, location=? WHERE id=?");
            $stmt->bind_param('ssssi', $name, $phone, $category, $location, $cid);
            $stmt->execute();
            logActivity($conn, "Customer updated: $name", 'customer');
            $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Customer updated!</div>';
        }
    }
    if ($action === 'delete') {
        $cid = sanitizeInt($_POST['customer_id'] ?? 0);
        if ($cid > 0) {
            $stmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
            $stmt->bind_param('i', $cid);
            $stmt->execute();
            $c = $stmt->get_result()->fetch_assoc();
            try {
                $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
                $stmt->bind_param('i', $cid);
                $stmt->execute();
                logActivity($conn,"Customer deleted: " . ($c['name'] ?? 'Unknown'),'customer');
                $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Customer deleted!</div>';
            } catch (mysqli_sql_exception $e) {
                $stmt = $conn->prepare("UPDATE customers SET status='inactive' WHERE id = ?");
                $stmt->bind_param('i', $cid);
                $stmt->execute();
                logActivity($conn,"Customer archived (has sales/debts on record): " . ($c['name'] ?? 'Unknown'),'customer');
                $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">This customer has sales or debts on record, so they were archived instead of deleted (hidden from this list, history preserved).</div>';
            }
        }
    }

    $_SESSION['customers_flash'] = $msg;
    $redirect_qs = $_GET ? ('?' . http_build_query($_GET)) : '';
    header('Location: customers.php' . $redirect_qs);
    exit;
}
$search = sanitizeString($_GET['search'] ?? '', 100);
$cat_f = in_array($_GET['category'] ?? '', ['wholesale', 'retail'], true) ? $_GET['category'] : '';
$city_f = sanitizeString($_GET['city'] ?? '', 100);
$phone_f = sanitizeString($_GET['phone'] ?? '', 40);
$where = ["status = 'active'"];
$params = [];
$types = '';
if($search) {
  $where[] = "(name LIKE ? OR phone LIKE ?)";
  $params[] = '%' . $search . '%';
  $params[] = '%' . $search . '%';
  $types .= 'ss';
}
if($cat_f) {
  $where[] = "category = ?";
  $params[] = $cat_f;
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
$stmt = $conn->prepare("SELECT c.* FROM customers c WHERE " . implode(' AND ', $where) . " ORDER BY c.name");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$customers = $stmt->get_result();
$stats = $conn->query("SELECT COUNT(*) as total, SUM(outstanding_debt>0) as with_debt, SUM(outstanding_debt) as total_debt FROM customers WHERE status = 'active'")->fetch_assoc();
$client_categories = ['wholesale' => 'Wholesale', 'retail' => 'Retail'];
$cat_counts_raw = $conn->query("SELECT category, COUNT(*) as cnt FROM customers WHERE status = 'active' AND category IS NOT NULL GROUP BY category")->fetch_all(MYSQLI_ASSOC);
$cat_counts_map = [];
foreach ($cat_counts_raw as $row) $cat_counts_map[$row['category']] = $row['cnt'];
$cat_counts = [];
foreach ($client_categories as $key => $label) {
  $cat_counts[] = ['id' => $key, 'name' => $label, 'cnt' => $cat_counts_map[$key] ?? 0];
}

// Get unique cities for filter dropdown
$cities = $conn->query("SELECT DISTINCT location FROM customers WHERE location IS NOT NULL AND location!='' ORDER BY location")->fetch_all(MYSQLI_ASSOC);
$cities_list = ['Arusha', 'Burundi', 'Dar es Salaam', 'Dodoma', 'Geita', 'Iringa', 'Kagera', 'Katavi', 'Kenya', 'Kigoma', 'Kilimanjaro', 'Lindi', 'Manyara', 'Mara', 'Mbeya', 'Morogoro', 'Moshi', 'Mtwara', 'Mwanza', 'Njombe', 'Pemba North', 'Pemba South', 'Pwani', 'Rukwa', 'Ruvuma', 'Rwanda', 'Shinyanga', 'Simiyu', 'Singida', 'Songwe', 'Tabora', 'Tanga', 'Zanzibar North', 'Zanzibar South', 'Zanzibar Urban/West'];
$stat_colors = ['blue','green','amber','purple'];
?>
<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Total Customers</div><div class="stat-value"><?=$stats['total']?></div><div class="stat-icon"></div></div>
  <?php foreach ($cat_counts as $i => $cc): ?>
  <div class="stat-card <?=$stat_colors[$i % count($stat_colors)]?>"><div class="stat-label"><?=htmlspecialchars($cc['name'])?> Customers</div><div class="stat-value"><?=$cc['cnt']?></div><div class="stat-icon"></div></div>
  <?php endforeach; ?>
  <div class="stat-card red"><div class="stat-label">Total Debt</div><div class="stat-value"><?=tzs($stats['total_debt'])?></div><div class="stat-icon"></div></div>
</div>
<?=$msg?>
<form method="GET" style="display:contents">
<div class="filter-bar">
  <input class="filter-search" name="search" placeholder="Search by name or phone..." value="<?=htmlspecialchars($search)?>"/>
  <select class="filter-select" name="category" onchange="this.form.submit()">
    <option value="">All Categories</option>
    <?php foreach($client_categories as $key => $label): ?>
    <option value="<?=$key?>" <?=$cat_f===$key?'selected':''?>><?=htmlspecialchars($label)?></option>
    <?php endforeach; ?>
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
<div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Category</th><th>Location</th><th>Total Purchases</th><th>Action</th></tr></thead>
<tbody><?php $i=1; while($c=$customers->fetch_assoc()):?>
<tr><td class="text-muted"><?=$i++?></td><td class="td-bold"><?=htmlspecialchars($c['name'])?></td><td><?=htmlspecialchars($c['phone'])?></td>
<td><?php if($c['category']): ?><span class="badge badge-<?=$c['category']==='retail'?'info':'purple'?>"><?=htmlspecialchars($client_categories[$c['category']])?></span><?php else: ?>—<?php endif; ?></td>
<td><?=htmlspecialchars($c['location']??'—')?></td><td class="text-success"><?=tzs($c['total_purchases'])?></td>
<td style="display:flex;gap:6px;align-items:center">
  <button type="button" class="btn btn-outline btn-sm" title="Edit" aria-label="Edit" onclick='openEditCustomer(this)' data-id="<?=$c['id']?>" data-name="<?=htmlspecialchars($c['name'], ENT_QUOTES)?>" data-phone="<?=htmlspecialchars($c['phone'], ENT_QUOTES)?>" data-category="<?=htmlspecialchars($c['category'] ?? '', ENT_QUOTES)?>" data-location="<?=htmlspecialchars($c['location'] ?? '', ENT_QUOTES)?>">✏️</button>
  <form method="POST" style="margin:0" data-confirm="Delete <?=htmlspecialchars($c['name'], ENT_QUOTES)?>? This cannot be undone."><input type="hidden" name="action" value="delete"><input type="hidden" name="customer_id" value="<?=$c['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-sm btn-danger" title="Delete" aria-label="Delete" style="padding:4px 8px;font-size:11px">🗑️</button></form>
</td></tr>
<?php endwhile; if($customers->num_rows===0): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No customers found</td></tr><?php endif;?></tbody></table></div></div>
<div class="modal-overlay" id="add-customer-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Customer</span><button class="modal-close" onclick="closeModal('add-customer-modal')"></button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Full Name *</label><input name="name" class="form-control" required/></div><div class="form-group"><label class="form-label">Phone</label><input name="phone" class="form-control" placeholder="+255 7XX XXX XXX"/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Client Category</label><select name="category" class="form-control"><option value="">Select category</option><?php foreach($client_categories as $key => $label): ?><option value="<?=$key?>"><?=htmlspecialchars($label)?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">City</label><select name="location" class="form-control" required><option value="">Select a city...</option><?php foreach($cities_list as $c): ?><option value="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?></option><?php endforeach; ?></select></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-customer-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Customer</button></div></form></div></div>
<div class="modal-overlay" id="edit-customer-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Customer</span><button class="modal-close" onclick="closeModal('edit-customer-modal')"></button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="customer_id" id="edit-cust-id"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Full Name *</label><input name="name" id="edit-cust-name" class="form-control" required/></div><div class="form-group"><label class="form-label">Phone</label><input name="phone" id="edit-cust-phone" class="form-control"/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Client Category</label><select name="category" id="edit-cust-category" class="form-control"><option value="">Select category</option><?php foreach($client_categories as $key => $label): ?><option value="<?=$key?>"><?=htmlspecialchars($label)?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">City</label><select name="location" id="edit-cust-location" class="form-control" required><option value="">Select a city...</option><?php foreach($cities_list as $c): ?><option value="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?></option><?php endforeach; ?></select></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-customer-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>
<?php require_once 'includes/footer.php'; ?>
<script>
function openEditCustomer(btn){
  document.getElementById('edit-cust-id').value = btn.dataset.id;
  document.getElementById('edit-cust-name').value = btn.dataset.name;
  document.getElementById('edit-cust-phone').value = btn.dataset.phone;
  document.getElementById('edit-cust-category').value = btn.dataset.category;
  document.getElementById('edit-cust-location').value = btn.dataset.location;
  openModal('edit-customer-modal');
}
// Auto-dismiss notification message after 2 seconds
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
