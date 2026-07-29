<?php
// wifi_billing.php — standalone WiFi bundle billing ledger. Not linked to
// Billiards, Customers (the product-sales one), Products, or the main
// `sales` table — purely records which customer bought which internet
// bundle, for how much, and when it expires. No router/network integration.
$page_title = 'WiFi Billing';
require_once 'includes/header.php';
requirePrivilege('wifi_billing');
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
  $action = $_POST['action'] ?? '';

  if ($action === 'sell') {
    $customer_id = sanitizeInt($_POST['customer_id'] ?? 0);
    $description = sanitizeString($_POST['description'] ?? '', 200);
    $amount = sanitizeFloat($_POST['amount'] ?? 0);
    $duration_days = sanitizeInt($_POST['duration_days'] ?? 0);
    $method = sanitizeString($_POST['payment_method'] ?? 'cash', 20);
    $notes = sanitizeString($_POST['notes'] ?? '', 500);
    if (!in_array($method, ['cash', 'lipa', 'bank'], true)) $method = 'cash';

    if ($description === '' || $amount <= 0 || $duration_days <= 0) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Description, a positive amount, and a positive duration are required.</div>';
    } else {
      $stmt = $conn->prepare("SELECT * FROM wifi_customers WHERE id = ? AND status='active'");
      $stmt->bind_param('i', $customer_id);
      $stmt->execute();
      $customer = $stmt->get_result()->fetch_assoc();
      if (!$customer) {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Customer not found or inactive.</div>';
      } else {
        $expires_at = date('Y-m-d', strtotime("+$duration_days days"));
        $sold_by = $_SESSION['user_id'];
        $conn->begin_transaction();
        try {
          $temp_receipt = 'TMP-' . bin2hex(random_bytes(6));
          $stmt = $conn->prepare("INSERT INTO wifi_bundle_sales (receipt_number, customer_id, description, amount, duration_days, expires_at, payment_method, sold_by, notes) VALUES (?,?,?,?,?,?,?,?,?)");
          $stmt->bind_param('sisdissis', $temp_receipt, $customer_id, $description, $amount, $duration_days, $expires_at, $method, $sold_by, $notes);
          $stmt->execute();
          $sale_id = $conn->insert_id;
          $receipt_number = 'NET-' . str_pad((string)$sale_id, 6, '0', STR_PAD_LEFT);
          $stmt_up = $conn->prepare("UPDATE wifi_bundle_sales SET receipt_number = ? WHERE id = ?");
          $stmt_up->bind_param('si', $receipt_number, $sale_id);
          $stmt_up->execute();
          $conn->commit();
          logActivity($conn, "WiFi bundle sold: {$customer['name']} — $description — " . number_format($amount) . " TZS ($receipt_number)", 'sale');
          $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Sale recorded! Receipt ' . htmlspecialchars($receipt_number) . ' — ' . tzs($amount) . ', expires ' . date('M d, Y', strtotime($expires_at)) . '</div>';
        } catch (Exception $e) {
          $conn->rollback();
          $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">' . htmlspecialchars($e->getMessage()) . '</div>';
        }
      }
    }
  }

  if ($action === 'void') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can void a sale.</div>';
    } else {
      $sale_id = sanitizeInt($_POST['id'] ?? 0);
      $stmt = $conn->prepare("UPDATE wifi_bundle_sales SET status='cancelled' WHERE id = ? AND status='active'");
      $stmt->bind_param('i', $sale_id);
      $stmt->execute();
      if ($stmt->affected_rows > 0) {
        logActivity($conn, "WiFi bundle sale voided: #$sale_id", 'sale');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Sale voided.</div>';
      } else {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Sale not found or already voided.</div>';
      }
    }
  }

  if ($action === 'add_customer') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can add customers.</div>';
    } else {
      $name = sanitizeString($_POST['name'] ?? '', 150);
      $location = sanitizeString($_POST['location'] ?? '', 200);
      $phone = sanitizeString($_POST['contact_phone'] ?? '', 30);
      if ($name === '') {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Customer name is required.</div>';
      } else {
        $stmt = $conn->prepare("INSERT INTO wifi_customers (name, location, contact_phone) VALUES (?,?,?)");
        $stmt->bind_param('sss', $name, $location, $phone);
        $stmt->execute();
        logActivity($conn, "WiFi billing customer added: $name", 'system');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Customer added!</div>';
      }
    }
  }

  if ($action === 'edit_customer') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can edit customers.</div>';
    } else {
      $id = sanitizeInt($_POST['id'] ?? 0);
      $name = sanitizeString($_POST['name'] ?? '', 150);
      $location = sanitizeString($_POST['location'] ?? '', 200);
      $phone = sanitizeString($_POST['contact_phone'] ?? '', 30);
      $status = sanitizeString($_POST['status'] ?? 'active', 20);
      if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
      if ($name === '') {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Customer name is required.</div>';
      } else {
        $stmt = $conn->prepare("UPDATE wifi_customers SET name=?, location=?, contact_phone=?, status=? WHERE id=?");
        $stmt->bind_param('ssssi', $name, $location, $phone, $status, $id);
        $stmt->execute();
        logActivity($conn, "WiFi billing customer updated: $name", 'system');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Customer updated!</div>';
      }
    }
  }
}

$customers = $conn->query("SELECT * FROM wifi_customers ORDER BY name");
$customer_rows = [];
while ($c = $customers->fetch_assoc()) $customer_rows[] = $c;

$search = sanitizeString($_GET['search'] ?? '', 100);
$status_filter = sanitizeString($_GET['status'] ?? '', 20);
$customer_filter = sanitizeInt($_GET['customer_id'] ?? 0);

$where = [];
$params = [];
$types = '';
if ($search) {
  $where[] = "c.name LIKE ?";
  $params[] = '%' . $search . '%';
  $types .= 's';
}
if ($customer_filter) {
  $where[] = "s.customer_id = ?";
  $params[] = $customer_filter;
  $types .= 'i';
}
if ($status_filter === 'active')    $where[] = "s.status='active' AND s.expires_at >= CURDATE()";
if ($status_filter === 'expiring')  $where[] = "s.status='active' AND s.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
if ($status_filter === 'expired')   $where[] = "s.status='active' AND s.expires_at < CURDATE()";
if ($status_filter === 'cancelled') $where[] = "s.status='cancelled'";

$sql = "SELECT s.*, c.name as customer_name, u.name as sold_by_name FROM wifi_bundle_sales s JOIN wifi_customers c ON s.customer_id=c.id LEFT JOIN users u ON s.sold_by=u.id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY s.created_at DESC LIMIT 200";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$sales = $stmt->get_result();

$month = date('Y-m');
$stmt_rev = $conn->prepare("SELECT COALESCE(SUM(amount),0) as v FROM wifi_bundle_sales WHERE status != 'cancelled' AND DATE_FORMAT(created_at,'%Y-%m')=?");
$stmt_rev->bind_param('s', $month);
$stmt_rev->execute();
$month_revenue = $stmt_rev->get_result()->fetch_assoc()['v'];

$active_count   = $conn->query("SELECT COUNT(*) as c FROM wifi_bundle_sales WHERE status='active' AND expires_at >= CURDATE()")->fetch_assoc()['c'];
$expiring_count = $conn->query("SELECT COUNT(*) as c FROM wifi_bundle_sales WHERE status='active' AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetch_assoc()['c'];
$expired_count  = $conn->query("SELECT COUNT(*) as c FROM wifi_bundle_sales WHERE status='active' AND expires_at < CURDATE()")->fetch_assoc()['c'];
?>

<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">This Month's Revenue</div><div class="stat-value"><?= tzs($month_revenue) ?></div><div class="stat-sub">Separate from Z-Series revenue</div><div class="stat-icon">📶</div></div>
  <div class="stat-card green"><div class="stat-label">Active Bundles</div><div class="stat-value"><?= $active_count ?></div><div class="stat-icon"></div></div>
  <div class="stat-card amber"><div class="stat-label">Expiring Soon</div><div class="stat-value"><?= $expiring_count ?></div><div class="stat-icon"></div></div>
  <div class="stat-card red"><div class="stat-label">Expired</div><div class="stat-value"><?= $expired_count ?></div><div class="stat-icon"></div></div>
</div>

<?= $msg ?>

<div style="margin:14px 0;display:flex;gap:8px;flex-wrap:wrap">
  <button class="btn btn-primary" onclick="openModal('sell-modal')">+ Sell Bundle</button>
  <?php if ($is_admin): ?>
  <button class="btn btn-outline" onclick="openModal('add-customer-modal')">+ Add Customer</button>
  <?php endif; ?>
</div>

<form method="GET" style="display:contents">
<div class="filter-bar">
  <input class="filter-search" name="search" placeholder="Search customers..." value="<?= htmlspecialchars($search) ?>"/>
  <select class="filter-select" name="customer_id" onchange="this.form.submit()">
    <option value="">All Customers</option>
    <?php foreach ($customer_rows as $c): ?>
    <option value="<?= $c['id'] ?>" <?= $customer_filter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="filter-select" name="status" onchange="this.form.submit()">
    <option value="">All Sales</option>
    <option value="active" <?= $status_filter==='active'?'selected':'' ?>>Active</option>
    <option value="expiring" <?= $status_filter==='expiring'?'selected':'' ?>>Expiring Soon</option>
    <option value="expired" <?= $status_filter==='expired'?'selected':'' ?>>Expired</option>
    <option value="cancelled" <?= $status_filter==='cancelled'?'selected':'' ?>>Cancelled</option>
  </select>
  <button type="submit" class="btn btn-outline">Filter</button>
</div>
</form>

<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">Customers</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Location</th><th>Contact</th><th>Status</th><?php if($is_admin): ?><th>Action</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($customer_rows as $c): ?>
        <tr>
          <td class="td-bold"><?= htmlspecialchars($c['name']) ?></td>
          <td><?= htmlspecialchars($c['location'] ?? '') ?></td>
          <td><?= htmlspecialchars($c['contact_phone'] ?? '') ?></td>
          <td><span class="badge badge-<?= $c['status']==='active'?'success':'danger' ?>"><?= ucfirst($c['status']) ?></span></td>
          <?php if ($is_admin): ?>
          <td><button type="button" class="btn btn-outline btn-sm" onclick='openEditCustomer(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$customer_rows): ?>
        <tr><td colspan="<?= $is_admin?5:4 ?>" style="text-align:center;padding:22px;color:var(--text3)">No customers added yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">Bundle Sales</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Receipt</th><th>Customer</th><th>Bundle</th><th>Amount</th><th>Duration</th><th>Expires</th><th>Payment</th><th>Sold By</th><th>Status</th><?php if($is_admin): ?><th>Action</th><?php endif; ?></tr></thead>
      <tbody>
      <?php $i=0; while ($s = $sales->fetch_assoc()): $i++;
        $cancelled = $s['status']==='cancelled';
        $expired = !$cancelled && $s['expires_at'] < date('Y-m-d');
        $expiring = !$cancelled && !$expired && $s['expires_at'] <= date('Y-m-d', strtotime('+3 days'));
        if ($cancelled) { $badge = 'danger'; $label = 'Cancelled'; }
        elseif ($expired) { $badge = 'danger'; $label = 'Expired'; }
        elseif ($expiring) { $badge = 'warning'; $label = 'Expiring Soon'; }
        else { $badge = 'success'; $label = 'Active'; }
      ?>
        <tr <?= $cancelled?'style="opacity:0.55"':'' ?>>
          <td class="td-bold"><?= htmlspecialchars($s['receipt_number']) ?></td>
          <td><?= htmlspecialchars($s['customer_name']) ?></td>
          <td><?= htmlspecialchars($s['description']) ?></td>
          <td class="text-success"><?= tzs($s['amount']) ?></td>
          <td><?= $s['duration_days'] ?> day<?= $s['duration_days']==1?'':'s' ?></td>
          <td class="text-muted"><?= date('M d, Y', strtotime($s['expires_at'])) ?></td>
          <td><?= ucfirst(htmlspecialchars($s['payment_method'])) ?></td>
          <td class="text-muted"><?= htmlspecialchars($s['sold_by_name'] ?? '—') ?></td>
          <td><span class="badge badge-<?= $badge ?>"><?= $label ?></span></td>
          <?php if ($is_admin): ?>
          <td><?php if (!$cancelled): ?>
            <form method="POST" style="margin:0" data-confirm="Void receipt <?= htmlspecialchars($s['receipt_number'], ENT_QUOTES) ?>? This removes it from revenue totals.">
              <input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= $s['id'] ?>"><?= csrfInput() ?>
              <button type="submit" class="btn btn-danger btn-sm">Void</button>
            </form>
          <?php endif; ?></td>
          <?php endif; ?>
        </tr>
      <?php endwhile; if ($i===0): ?>
        <tr><td colspan="<?= $is_admin?10:9 ?>" style="text-align:center;padding:22px;color:var(--text3)">No bundle sales found</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Sell Bundle Modal -->
<div class="modal-overlay" id="sell-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Sell WiFi Bundle</span><button class="modal-close" onclick="closeModal('sell-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="sell"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Customer *</label>
<select name="customer_id" class="form-control" required>
<option value="">-- Select customer --</option>
<?php foreach ($customer_rows as $c): if ($c['status']!=='active') continue; ?>
<option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
<?php endforeach; ?>
</select></div>
<div class="form-group"><label class="form-label">Bundle Description *</label><input name="description" class="form-control" placeholder="e.g. 10GB / 7 Days" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Amount (TZS) *</label><input type="number" name="amount" class="form-control" min="1" required/></div>
<div class="form-group"><label class="form-label">Duration (days) *</label><input type="number" name="duration_days" class="form-control" min="1" required/></div>
</div>
<div class="form-group"><label class="form-label">Payment Method</label><select name="payment_method" class="form-control"><option value="cash">Cash</option><option value="lipa">Lipa Namba</option><option value="bank">Bank</option></select></div>
<div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Optional"></textarea></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('sell-modal')">Cancel</button><button type="submit" class="btn btn-primary">Record Sale</button></div></form></div></div>

<?php if ($is_admin): ?>
<!-- Add Customer Modal -->
<div class="modal-overlay" id="add-customer-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Customer</span><button class="modal-close" onclick="closeModal('add-customer-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="add_customer"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Customer Name *</label><input name="name" class="form-control" placeholder="e.g. Kilimo Insurance Ltd" required/></div>
<div class="form-group"><label class="form-label">Contact Phone</label><input name="contact_phone" class="form-control" placeholder="e.g. +255 712 000 000"/></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-customer-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Customer</button></div></form></div></div>

<!-- Edit Customer Modal -->
<div class="modal-overlay" id="edit-customer-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Customer</span><button class="modal-close" onclick="closeModal('edit-customer-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit_customer"><input type="hidden" name="id" id="edit-customer-id"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Customer Name *</label><input name="name" id="edit-customer-name" class="form-control" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Location</label><input name="location" id="edit-customer-location" class="form-control"/></div>
<div class="form-group"><label class="form-label">Contact Phone</label><input name="contact_phone" id="edit-customer-phone" class="form-control"/></div>
</div>
<div class="form-group"><label class="form-label">Status</label><select name="status" id="edit-customer-status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-customer-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>
<?php endif; ?>

<?php
$extra_js = <<<'JS'
<script>
function openEditCustomer(c){
  document.getElementById('edit-customer-id').value = c.id;
  document.getElementById('edit-customer-name').value = c.name;
  document.getElementById('edit-customer-location').value = c.location || '';
  document.getElementById('edit-customer-phone').value = c.contact_phone || '';
  document.getElementById('edit-customer-status').value = c.status;
  openModal('edit-customer-modal');
}
</script>
JS;
require_once 'includes/footer.php';
?>
