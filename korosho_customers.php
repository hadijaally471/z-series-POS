<?php
$page_title = 'Korosho Customers';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('korosho');
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$msg = '';
if (!empty($_SESSION['korosho_customers_flash'])) {
  $msg = $_SESSION['korosho_customers_flash'];
  unset($_SESSION['korosho_customers_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
  $action = $_POST['action'] ?? '';

  if ($action === 'add_customer') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can add customers.</div>';
    } else {
      $name = sanitizeString($_POST['name'] ?? '', 150);
      $location = sanitizeString($_POST['location'] ?? '', 200);
      $phone = sanitizeString($_POST['phone'] ?? '', 30);
      if ($name === '') {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Customer name is required.</div>';
      } else {
        $stmt = $conn->prepare("INSERT INTO korosho_customers (name, location, phone) VALUES (?,?,?)");
        $stmt->bind_param('sss', $name, $location, $phone);
        $stmt->execute();
        logActivity($conn, "Korosho customer added: $name", 'system');
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
      $phone = sanitizeString($_POST['phone'] ?? '', 30);
      $status = sanitizeString($_POST['status'] ?? 'active', 20);
      if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
      if ($name === '') {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Customer name is required.</div>';
      } else {
        $stmt = $conn->prepare("UPDATE korosho_customers SET name=?, location=?, phone=?, status=? WHERE id=?");
        $stmt->bind_param('ssssi', $name, $location, $phone, $status, $id);
        $stmt->execute();
        logActivity($conn, "Korosho customer updated: $name", 'system');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Customer updated!</div>';
      }
    }
  }

  $_SESSION['korosho_customers_flash'] = $msg;
  header('Location: korosho_customers.php');
  exit;
}

$customers_result = $conn->query("SELECT * FROM korosho_customers ORDER BY name");
$customers = [];
while ($c = $customers_result->fetch_assoc()) $customers[] = $c;
?>
<div class="no-print" style="margin-bottom:16px"><a href="korosho.php" class="btn btn-outline btn-sm">&larr; Korosho</a></div>

<?=$msg?>

<div class="card">
  <div class="card-header">
    <span class="card-title">Korosho Customers</span>
    <?php if ($is_admin): ?><button type="button" class="btn btn-primary btn-sm" onclick="openModal('add-customer-modal')">+ Add Customer</button><?php endif; ?>
  </div>
  <div class="table-wrap reports-table-wrap"><table>
    <thead><tr><th>Name</th><th>Location</th><th>Phone</th><th>Total Purchases</th><th>Status</th><?php if($is_admin):?><th>Action</th><?php endif;?></tr></thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
      <tr>
        <td class="td-bold"><?=htmlspecialchars($c['name'])?></td>
        <td class="text-muted"><?=htmlspecialchars($c['location'] ?? '—')?></td>
        <td class="text-muted"><?=htmlspecialchars($c['phone'] ?? '—')?></td>
        <td class="text-success"><?=tzs($c['total_purchases'])?></td>
        <td><span class="badge badge-<?=$c['status']==='active'?'success':'danger'?>"><?=ucfirst($c['status'])?></span></td>
        <?php if ($is_admin): ?>
        <td><button type="button" class="btn btn-outline btn-sm" onclick='openEditCustomer(<?=json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Edit</button></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$customers): ?>
      <tr><td colspan="<?=$is_admin?6:5?>" style="text-align:center;padding:22px;color:var(--text3)">No customers yet<?php if ($is_admin): ?> — click "+ Add Customer" to create one.<?php endif; ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php if ($is_admin): ?>
<!-- Add Customer Modal -->
<div class="modal-overlay" id="add-customer-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Customer</span><button class="modal-close" onclick="closeModal('add-customer-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="add_customer"><?=csrfInput()?><div class="modal-body">
<div class="form-group"><label class="form-label">Customer Name *</label><input name="name" class="form-control" placeholder="e.g. Amina Traders" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Location</label><input name="location" class="form-control" placeholder="e.g. Kariakoo"/></div>
<div class="form-group"><label class="form-label">Phone</label><input name="phone" class="form-control" placeholder="e.g. +255 712 000 000"/></div>
</div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-customer-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Customer</button></div></form></div></div>

<!-- Edit Customer Modal -->
<div class="modal-overlay" id="edit-customer-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Customer</span><button class="modal-close" onclick="closeModal('edit-customer-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit_customer"><input type="hidden" name="id" id="edit-customer-id"><?=csrfInput()?><div class="modal-body">
<div class="form-group"><label class="form-label">Customer Name *</label><input name="name" id="edit-customer-name" class="form-control" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Location</label><input name="location" id="edit-customer-location" class="form-control"/></div>
<div class="form-group"><label class="form-label">Phone</label><input name="phone" id="edit-customer-phone" class="form-control"/></div>
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
  document.getElementById('edit-customer-phone').value = c.phone || '';
  document.getElementById('edit-customer-status').value = c.status;
  openModal('edit-customer-modal');
}
</script>
JS;
require_once 'includes/footer.php';
?>
