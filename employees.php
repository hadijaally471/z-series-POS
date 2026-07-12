<?php
$page_title = 'Employees';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('employees');
$msg = '';
$offices = ['Cashewnut Sales', 'Home office', 'Pool Master', 'Z series sales', 'Driver', 'Others'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action']??'';
    if ($action === 'add') {
        $name = sanitizeString($_POST['name'] ?? '', 200);
        $role = sanitizeString($_POST['role'] ?? '', 60);
        $phone = sanitizeString($_POST['phone'] ?? '', 40);
        $salary = sanitizeFloat($_POST['salary'] ?? 0);
        $nida = sanitizeString($_POST['nida'] ?? '', 60);
        $start = sanitizeString($_POST['start_date'] ?? '', 20);
        $office = sanitizeString($_POST['office'] ?? '', 40);
        if (!in_array($office, $offices, true)) $office = $offices[0];
        $stmt=$conn->prepare("INSERT INTO employees (name,role,phone,salary,nida,start_date,office) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('sssdsss',$name,$role,$phone,$salary,$nida,$start,$office);$stmt->execute();
        logActivity($conn,"Employee added: $name",'system');
        $msg='<div id="emp-msg" style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Employee added!</div>';
    } elseif ($action === 'delete') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $employee_row = $stmt->get_result()->fetch_assoc();
        if ($employee_row) {
          try {
            $delete_stmt = $conn->prepare("DELETE FROM employees WHERE id = ?");
            $delete_stmt->bind_param('i', $id);
            $delete_stmt->execute();
            logActivity($conn, 'Employee deleted: ' . $employee_row['name'], 'system');
            $msg='<div id="emp-msg" style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Employee deleted!</div>';
          } catch (mysqli_sql_exception $e) {
            $msg='<div id="emp-msg" style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Cannot delete this employee — they have records linked to them.</div>';
          }
        }
      } elseif ($action === 'edit') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        $name = sanitizeString($_POST['name'] ?? '', 200);
        $role = sanitizeString($_POST['role'] ?? '', 60);
        $phone = sanitizeString($_POST['phone'] ?? '', 40);
        $salary = sanitizeFloat($_POST['salary'] ?? 0);
        $nida = sanitizeString($_POST['nida'] ?? '', 60);
        $start = sanitizeString($_POST['start_date'] ?? '', 20);
        $status = sanitizeString($_POST['status'] ?? 'active', 20);
        if (!in_array($status, ['active','inactive','on_leave'], true)) {
          $status = 'active';
        }
        $office = sanitizeString($_POST['office'] ?? '', 40);
        if (!in_array($office, $offices, true)) $office = $offices[0];
        $stmt = $conn->prepare("UPDATE employees SET name=?, role=?, phone=?, salary=?, nida=?, start_date=?, status=?, office=? WHERE id=?");
        $stmt->bind_param('sssdssssi', $name, $role, $phone, $salary, $nida, $start, $status, $office, $id);
        $stmt->execute();
        logActivity($conn, "Employee updated: $name", 'system');
        $msg='<div id="emp-msg" style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Employee updated!</div>';
    }
}
$stats = $conn->query("SELECT COUNT(*) as total, SUM(status='active') as active, SUM(status='on_leave') as leave_count, COALESCE(SUM(salary),0) as payroll FROM employees")->fetch_assoc();
$employee_options = [];
$option_result = $conn->query("SELECT id, name FROM employees ORDER BY name");
if ($option_result) {
  while ($option_row = $option_result->fetch_assoc()) {
    $employee_options[] = $option_row;
  }
}
?>
<div style="display:block !important; visibility:visible !important; opacity:1 !important; margin-bottom:18px; padding:18px 20px; border:1px solid rgba(124,58,237,0.25); border-radius:18px; background:linear-gradient(180deg, rgba(22,18,42,0.96), rgba(19,16,30,0.98)); box-shadow:0 14px 30px rgba(0,0,0,0.16);">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <div style="font-size:18px;font-weight:800;color:var(--text);font-family:'Syne',sans-serif;margin-bottom:4px">Employees table</div>
      <div style="font-size:12px;color:var(--text3)"><?=$stats['total']?> employees found in the database</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" onclick="openModal('add-emp-modal')">+ Add Employee</button>
      <button class="btn btn-outline" onclick="openModal('delete-emp-modal')">Delete Employee</button>
    </div>
  </div>
  <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
    <div class="stat-card purple" style="flex:1;min-width:160px;margin:0"><div class="stat-label">Total Staff</div><div class="stat-value"><?=$stats['total']?></div></div>
    <div class="stat-card green" style="flex:1;min-width:160px;margin:0"><div class="stat-label">Active</div><div class="stat-value"><?=$stats['active']?></div></div>
    <div class="stat-card amber" style="flex:1;min-width:160px;margin:0"><div class="stat-label">On Leave</div><div class="stat-value"><?=$stats['leave_count']?></div></div>
    <div class="stat-card red" style="flex:1;min-width:160px;margin:0"><div class="stat-label">Monthly Payroll</div><div class="stat-value"><?=tzs($stats['payroll'])?></div></div>
  </div>
</div>
<?php $employees = $conn->query("SELECT * FROM employees ORDER BY name");?>
<?=$msg?>
<?php if((int)($stats['total']??0)===0): ?>
  <div class="card">
    <div class="card-body">
      <div class="empty-state" style="padding:24px;text-align:left">
        <div class="empty-state-title">No employees yet</div>
        <div class="empty-state-sub" style="margin:8px 0 14px">There are currently no employee records in the database.</div>
      </div>
    </div>
  </div>
<?php endif; ?>
<div class="card"><div class="card-header"><span class="card-title">All Employees</span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Role</th><th>Office</th><th>Phone</th><th>Salary</th><th>Start Date</th><th>Status</th><th>Actions</th></tr></thead>
<tbody><?php $i=1; while($e=$employees->fetch_assoc()):?>
<tr><td class="text-muted"><?=$i++?></td><td class="td-bold"><?=htmlspecialchars($e['name'])?></td><td style="color:var(--text2);font-size:13px"><?=htmlspecialchars($e['role'])?></td><td style="color:var(--text2);font-size:13px"><?=htmlspecialchars($e['office'] ?? $offices[0])?></td><td><?=htmlspecialchars($e['phone'])?></td>
<td class="text-purple"><?=tzs($e['salary'])?></td><td class="text-muted"><?=date('M d, Y',strtotime($e['start_date']))?></td>
<td><span class="badge badge-<?=$e['status']==='active'?'success':($e['status']==='on_leave'?'warning':'danger')?>"><?=ucwords(str_replace('_',' ',$e['status']))?></span></td>
<td style="display:flex;gap:8px;align-items:center">
  <button type="button" class="btn btn-outline btn-sm" title="Edit" aria-label="Edit" onclick='openEditEmployee(this)' data-id="<?=$e['id']?>" data-name="<?=htmlspecialchars($e['name'], ENT_QUOTES)?>" data-role="<?=htmlspecialchars($e['role'], ENT_QUOTES)?>" data-office="<?=htmlspecialchars($e['office'] ?? $offices[0], ENT_QUOTES)?>" data-phone="<?=htmlspecialchars($e['phone'], ENT_QUOTES)?>" data-salary="<?=htmlspecialchars($e['salary'], ENT_QUOTES)?>" data-nida="<?=htmlspecialchars($e['nida'], ENT_QUOTES)?>" data-start="<?=htmlspecialchars($e['start_date'], ENT_QUOTES)?>" data-status="<?=htmlspecialchars($e['status'], ENT_QUOTES)?>">✏️</button>
  <form method="POST" data-confirm="Delete <?=htmlspecialchars($e['name'], ENT_QUOTES)?>?" style="margin:0">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?=$e['id']?>">
    <?= csrfInput() ?>
    <button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete">🗑️</button>
  </form>
</td>
</tr><?php endwhile;?></tbody></table></div></div>
<div class="modal-overlay" id="add-emp-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Employee</span><button class="modal-close" onclick="closeModal('add-emp-modal')"></button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Full Name *</label><input name="name" class="form-control" required/></div><div class="form-group"><label class="form-label">Role / Position *</label><select name="role" class="form-control" required><option value="">Select role</option><option value="Sales Rep">Sales Rep</option><option value="Cashier">Cashier</option><option value="Driver">Driver</option><option value="Manager">Manager</option><option value="Accountant">Accountant</option><option value="Administrator">Administrator</option><option value="Other">Other</option></select></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Phone</label><input name="phone" class="form-control" placeholder="+255 7XX XXX XXX"/></div><div class="form-group"><label class="form-label">Monthly Salary (TZS)</label><input type="number" name="salary" class="form-control"/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?=date('Y-m-d')?>"/></div><div class="form-group"><label class="form-label">NIDA Number</label><input name="nida" class="form-control" placeholder="National ID"/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Office *</label><select name="office" class="form-control" required><?php foreach($offices as $off): ?><option value="<?=htmlspecialchars($off)?>"><?=htmlspecialchars($off)?></option><?php endforeach; ?></select></div><div></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-emp-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Employee</button></div></form></div></div>
<div class="modal-overlay" id="edit-emp-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Employee</span><button class="modal-close" onclick="closeModal('edit-emp-modal')"></button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit-id"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Full Name *</label><input name="name" id="edit-name" class="form-control" required/></div><div class="form-group"><label class="form-label">Role / Position *</label><select name="role" id="edit-role" class="form-control" required><option value="">Select role</option><option value="Sales Rep">Sales Rep</option><option value="Cashier">Cashier</option><option value="Driver">Driver</option><option value="Manager">Manager</option><option value="Accountant">Accountant</option><option value="Administrator">Administrator</option><option value="Other">Other</option></select></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Phone</label><input name="phone" id="edit-phone" class="form-control"/></div><div class="form-group"><label class="form-label">Monthly Salary (TZS)</label><input type="number" name="salary" id="edit-salary" class="form-control"/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Start Date</label><input type="date" name="start_date" id="edit-start" class="form-control"/></div><div class="form-group"><label class="form-label">NIDA Number</label><input name="nida" id="edit-nida" class="form-control"/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Status</label><select name="status" id="edit-status" class="form-control"><option value="active">Active</option><option value="on_leave">On Leave</option><option value="inactive">Inactive</option></select></div><div class="form-group"><label class="form-label">Office *</label><select name="office" id="edit-office" class="form-control" required><?php foreach($offices as $off): ?><option value="<?=htmlspecialchars($off)?>"><?=htmlspecialchars($off)?></option><?php endforeach; ?></select></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-emp-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>
<div class="modal-overlay" id="delete-emp-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Delete Employee</span><button class="modal-close" onclick="closeModal('delete-emp-modal')"></button></div>
<form method="POST" data-confirm="Delete this employee permanently? This cannot be undone."><input type="hidden" name="action" value="delete"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Select Employee</label><select name="id" class="form-control" required>
  <option value="">Choose employee...</option>
  <?php foreach($employee_options as $emp): ?>
    <option value="<?=$emp['id']?>"><?=htmlspecialchars($emp['name'])?></option>
  <?php endforeach; ?>
</select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('delete-emp-modal')">Cancel</button><button type="submit" class="btn btn-danger">Delete Employee</button></div></form></div></div>
<?php require_once 'includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const msgDiv = document.getElementById('emp-msg');
  if (msgDiv) {
    setTimeout(() => {
      msgDiv.style.transition = 'opacity 0.4s ease-out';
      msgDiv.style.opacity = '0';
      setTimeout(() => msgDiv.remove(), 400);
    }, 1500);
  }
});

function seedEmployees(){
  if(!confirm('Insert sample employees? This will only run if the table is empty. Continue?')) return;
  fetch('scripts/seed_employees.php')
    .then(r=>r.text())
    .then(txt=>{
      try{ console.log(txt); }catch(e){}
      // show toast if available
      if(typeof showToast === 'function') showToast('Seeder finished','success');
      setTimeout(()=>location.reload(),600);
    }).catch(err=>{
      console.error(err);
      if(typeof showToast === 'function') showToast('Seeder failed','error');
      alert('Seed failed — check console');
    });
}

function openEditEmployee(button){
  document.getElementById('edit-id').value = button.dataset.id || '';
  document.getElementById('edit-name').value = button.dataset.name || '';
  const roleSelect = document.getElementById('edit-role');
  const roleVal = button.dataset.role || 'Cashier';
  if(roleVal && ![...roleSelect.options].some(o=>o.value===roleVal)){
    const opt = document.createElement('option');
    opt.value = roleVal; opt.textContent = roleVal;
    roleSelect.appendChild(opt);
  }
  roleSelect.value = roleVal;
  document.getElementById('edit-phone').value = button.dataset.phone || '';
  document.getElementById('edit-salary').value = button.dataset.salary || '';
  document.getElementById('edit-nida').value = button.dataset.nida || '';
  document.getElementById('edit-start').value = button.dataset.start || '';
  document.getElementById('edit-status').value = button.dataset.status || 'active';
  document.getElementById('edit-office').value = button.dataset.office || 'Cashewnut Sales';
  openModal('edit-emp-modal');
}
</script>
