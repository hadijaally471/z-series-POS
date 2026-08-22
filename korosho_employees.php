<?php
$page_title = 'Korosho Employees';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('korosho');
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$msg = '';
if (!empty($_SESSION['korosho_employees_flash'])) {
  $msg = $_SESSION['korosho_employees_flash'];
  unset($_SESSION['korosho_employees_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
  $action = $_POST['action'] ?? '';

  if ($action === 'add_employee') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can add employees.</div>';
    } else {
      $name = sanitizeString($_POST['name'] ?? '', 150);
      $phone = sanitizeString($_POST['phone'] ?? '', 30);
      if ($name === '') {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Name is required.</div>';
      } else {
        $stmt = $conn->prepare("INSERT INTO korosho_employees (name, phone) VALUES (?,?)");
        $stmt->bind_param('ss', $name, $phone);
        $stmt->execute();
        logActivity($conn, "Korosho employee added: $name", 'system');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Employee added!</div>';
      }
    }
  }

  if ($action === 'edit_employee') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can edit employees.</div>';
    } else {
      $id = sanitizeInt($_POST['id'] ?? 0);
      $name = sanitizeString($_POST['name'] ?? '', 150);
      $phone = sanitizeString($_POST['phone'] ?? '', 30);
      $status = sanitizeString($_POST['status'] ?? 'active', 20);
      if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
      if ($name === '') {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Name is required.</div>';
      } else {
        $stmt = $conn->prepare("UPDATE korosho_employees SET name=?, phone=?, status=? WHERE id=?");
        $stmt->bind_param('sssi', $name, $phone, $status, $id);
        $stmt->execute();
        logActivity($conn, "Korosho employee updated: $name", 'system');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Employee updated!</div>';
      }
    }
  }

  $_SESSION['korosho_employees_flash'] = $msg;
  header('Location: korosho_employees.php');
  exit;
}

$employees_result = $conn->query("SELECT * FROM korosho_employees ORDER BY name");
$employees = [];
while ($e = $employees_result->fetch_assoc()) $employees[] = $e;
?>
<div class="no-print" style="margin-bottom:16px"><a href="korosho.php" class="btn btn-outline btn-sm">&larr; Korosho</a></div>

<?=$msg?>

<div class="card">
  <div class="card-header">
    <span class="card-title">Korosho Employees</span>
    <?php if ($is_admin): ?><button type="button" class="btn btn-primary btn-sm" onclick="openModal('add-employee-modal')">+ Add Employee</button><?php endif; ?>
  </div>
  <div class="table-wrap reports-table-wrap"><table>
    <thead><tr><th>Name</th><th>Phone</th><th>Status</th><?php if($is_admin):?><th>Action</th><?php endif;?></tr></thead>
    <tbody>
    <?php foreach ($employees as $e): ?>
      <tr>
        <td class="td-bold"><?=htmlspecialchars($e['name'])?></td>
        <td class="text-muted"><?=htmlspecialchars($e['phone'] ?? '—')?></td>
        <td><span class="badge badge-<?=$e['status']==='active'?'success':'danger'?>"><?=ucfirst($e['status'])?></span></td>
        <?php if ($is_admin): ?>
        <td><button type="button" class="btn btn-outline btn-sm" onclick='openEditEmployee(<?=json_encode($e, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Edit</button></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$employees): ?>
      <tr><td colspan="<?=$is_admin?4:3?>" style="text-align:center;padding:22px;color:var(--text3)">No employees yet<?php if ($is_admin): ?> — click "+ Add Employee" to create one.<?php endif; ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="no-print" style="margin-top:16px;font-size:12px;color:var(--text2)">Active employees added here appear in the Sales Rep dropdown on the Korosho POS page.</div>

<?php if ($is_admin): ?>
<!-- Add Employee Modal -->
<div class="modal-overlay" id="add-employee-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Employee</span><button class="modal-close" onclick="closeModal('add-employee-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="add_employee"><?=csrfInput()?><div class="modal-body">
<div class="form-group"><label class="form-label">Name *</label><input name="name" class="form-control" placeholder="e.g. George" required/></div>
<div class="form-group"><label class="form-label">Phone</label><input name="phone" class="form-control" placeholder="e.g. +255 712 000 000"/></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-employee-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Employee</button></div></form></div></div>

<!-- Edit Employee Modal -->
<div class="modal-overlay" id="edit-employee-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Employee</span><button class="modal-close" onclick="closeModal('edit-employee-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit_employee"><input type="hidden" name="id" id="edit-employee-id"><?=csrfInput()?><div class="modal-body">
<div class="form-group"><label class="form-label">Name *</label><input name="name" id="edit-employee-name" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Phone</label><input name="phone" id="edit-employee-phone" class="form-control"/></div>
<div class="form-group"><label class="form-label">Status</label><select name="status" id="edit-employee-status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-employee-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>

<?php
$extra_js = <<<'JS'
<script>
function openEditEmployee(e){
  document.getElementById('edit-employee-id').value = e.id;
  document.getElementById('edit-employee-name').value = e.name;
  document.getElementById('edit-employee-phone').value = e.phone || '';
  document.getElementById('edit-employee-status').value = e.status;
  openModal('edit-employee-modal');
}
</script>
JS;
require_once 'includes/footer.php';
?>
<?php else: ?>
<?php require_once 'includes/footer.php'; ?>
<?php endif; ?>
