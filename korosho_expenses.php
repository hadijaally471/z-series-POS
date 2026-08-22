<?php
$page_title = 'Korosho Expenses';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('korosho');
$msg = '';
if (!empty($_SESSION['korosho_expenses_flash'])) {
  $msg = $_SESSION['korosho_expenses_flash'];
  unset($_SESSION['korosho_expenses_flash']);
}
$allowed = ['transport','utilities','staff','raw_materials','rent','maintenance','other'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? 'add';
    if ($action === 'add') {
        $desc = sanitizeString($_POST['description'] ?? '', 500);
        $cat = in_array($_POST['category'] ?? '', $allowed, true) ? $_POST['category'] : 'other';
        $emp_id = sanitizeInt($_POST['employee_id'] ?? 0) ?: null;
        $amount = sanitizeFloat($_POST['amount'] ?? 0); $date = sanitizeString($_POST['expense_date'] ?? '', 20);
        $stmt = $conn->prepare("INSERT INTO korosho_expenses (description,category,employee_id,amount,expense_date,recorded_by) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('ssidsi',$desc,$cat,$emp_id,$amount,$date,$_SESSION['user_id']);
        $stmt->execute();
        logActivity($conn,"Korosho expense recorded: $desc — ".number_format($amount)." TZS",'expense');
        $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Expense recorded!</div>';
    }
    if ($action === 'edit') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        $desc = sanitizeString($_POST['description'] ?? '', 500);
        $cat = in_array($_POST['category'] ?? '', $allowed, true) ? $_POST['category'] : 'other';
        $emp_id = sanitizeInt($_POST['employee_id'] ?? 0) ?: null;
        $amount = sanitizeFloat($_POST['amount'] ?? 0);
        $date = sanitizeString($_POST['expense_date'] ?? '', 20);
        if ($id > 0 && $desc !== '') {
            $stmt = $conn->prepare("UPDATE korosho_expenses SET description=?, category=?, employee_id=?, amount=?, expense_date=? WHERE id=?");
            $stmt->bind_param('ssidsi', $desc, $cat, $emp_id, $amount, $date, $id);
            $stmt->execute();
            logActivity($conn, "Korosho expense updated: $desc — ".number_format($amount)." TZS", 'expense');
            $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Expense updated!</div>';
        }
    }
    if ($action === 'delete') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT description FROM korosho_expenses WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $exp = $stmt->get_result()->fetch_assoc();
            $stmt = $conn->prepare("DELETE FROM korosho_expenses WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            logActivity($conn, "Korosho expense deleted: " . ($exp['description'] ?? 'Unknown'), 'expense');
            $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Expense deleted!</div>';
        }
    }

    $_SESSION['korosho_expenses_flash'] = $msg;
    $redirect_qs = $_GET ? ('?' . http_build_query($_GET)) : '';
    header('Location: korosho_expenses.php' . $redirect_qs);
    exit;
}
$month = date('Y-m');
$today = date('Y-m-d');
$stmt_m = $conn->prepare("SELECT COALESCE(SUM(amount),0) as v FROM korosho_expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?");
$stmt_m->bind_param('s', $month); $stmt_m->execute();
$month_total = $stmt_m->get_result()->fetch_assoc()['v'];
$stmt_t = $conn->prepare("SELECT COALESCE(SUM(amount),0) as v FROM korosho_expenses WHERE expense_date=?");
$stmt_t->bind_param('s', $today); $stmt_t->execute();
$today_total = $stmt_t->get_result()->fetch_assoc()['v'];
$cat_top = $conn->query("SELECT category, SUM(amount) as total FROM korosho_expenses GROUP BY category ORDER BY total DESC LIMIT 1")->fetch_assoc();
$expenses = $conn->query("SELECT e.*, emp.name as employee_name FROM korosho_expenses e LEFT JOIN korosho_employees emp ON e.employee_id=emp.id ORDER BY e.expense_date DESC, e.created_at DESC LIMIT 50");
$employees_list = $conn->query("SELECT id, name FROM korosho_employees WHERE status='active' ORDER BY name");
?>
<div class="no-print" style="margin-bottom:16px"><a href="korosho.php" class="btn btn-outline btn-sm">&larr; Korosho</a></div>

<div class="stats-grid">
  <div class="stat-card red"><div class="stat-label">This Month</div><div class="stat-value"><?=tzs($month_total)?></div></div>
  <div class="stat-card amber"><div class="stat-label">Today</div><div class="stat-value"><?=tzs($today_total)?></div></div>
  <div class="stat-card purple"><div class="stat-label">Top Category</div><div class="stat-value"><?=ucwords(str_replace('_',' ',$cat_top['category']??'N/A'))?></div></div>
  <div class="stat-card green"><div class="stat-label">Top Category Total</div><div class="stat-value"><?=tzs($cat_top['total']??0)?></div></div>
</div>
<?=$msg?>
<div style="margin-bottom:14px"><button class="btn btn-primary" onclick="openModal('add-expense-modal')">+ Add Expense</button></div>
<div class="card"><div class="card-header"><span class="card-title">Recent Expenses</span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Description</th><th>Category</th><th>Belongs To</th><th>Amount</th><th>Date</th><th>Actions</th></tr></thead>
<tbody><?php $i=1; while($e=$expenses->fetch_assoc()):?>
<tr><td class="text-muted"><?=$i++?></td><td><span class="td-bold"><?=htmlspecialchars($e['description'])?></span><?php if(!empty($e['notes'])):?><div style="color:var(--text3);font-size:12px;margin-top:2px"><?=htmlspecialchars($e['notes'])?></div><?php endif;?></td>
<td><span class="badge badge-purple"><?=ucwords(str_replace('_',' ',$e['category']))?></span></td>
<td class="text-muted"><?=$e['employee_name'] ? htmlspecialchars($e['employee_name']) : '—'?></td>
<td class="text-danger"><?=tzs($e['amount'])?></td><td class="text-muted"><?=date('M d, Y',strtotime($e['expense_date']))?></td>
<td style="display:flex;gap:6px;align-items:center">
  <button type="button" class="btn btn-outline btn-sm" onclick='koroshoOpenEditExpense(this)' data-id="<?=$e['id']?>" data-desc="<?=htmlspecialchars($e['description'], ENT_QUOTES)?>" data-cat="<?=htmlspecialchars($e['category'], ENT_QUOTES)?>" data-employee="<?=htmlspecialchars($e['employee_id'] ?? '', ENT_QUOTES)?>" data-employee-name="<?=htmlspecialchars($e['employee_name'] ?? '', ENT_QUOTES)?>" data-amount="<?=$e['amount']?>" data-date="<?=$e['expense_date']?>">Edit</button>
  <form method="POST" style="margin:0" data-confirm="Delete this expense?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$e['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
</td></tr>
<?php endwhile; if($expenses->num_rows===0):?><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No expenses recorded</td></tr><?php endif;?></tbody></table></div></div>

<!-- Add Expense Modal -->
<div class="modal-overlay" id="add-expense-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Expense</span><button class="modal-close" onclick="closeModal('add-expense-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Description *</label><input name="description" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Category</label><select name="category" class="form-control"><option value="transport">Transport</option><option value="utilities">Utilities</option><option value="staff">Staff/Salary</option><option value="raw_materials">Raw Materials</option><option value="rent">Rent</option><option value="maintenance">Maintenance</option><option value="other">Other</option></select></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Amount (TZS) *</label><input type="number" name="amount" class="form-control" required/></div><div class="form-group"><label class="form-label">Date *</label><input type="date" name="expense_date" class="form-control" value="<?=date('Y-m-d')?>" required/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Belongs To (optional)</label><select name="employee_id" class="form-control"><option value="">— None —</option><?php $employees_list->data_seek(0); while($emp=$employees_list->fetch_assoc()): ?><option value="<?=$emp['id']?>"><?=htmlspecialchars($emp['name'])?></option><?php endwhile; ?></select></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-expense-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Expense</button></div></form></div></div>

<!-- Edit Expense Modal -->
<div class="modal-overlay" id="edit-expense-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Expense</span><button class="modal-close" onclick="closeModal('edit-expense-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit-korosho-exp-id"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Description *</label><input name="description" id="edit-korosho-exp-desc" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Category</label><select name="category" id="edit-korosho-exp-cat" class="form-control"><option value="transport">Transport</option><option value="utilities">Utilities</option><option value="staff">Staff/Salary</option><option value="raw_materials">Raw Materials</option><option value="rent">Rent</option><option value="maintenance">Maintenance</option><option value="other">Other</option></select></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Amount (TZS) *</label><input type="number" name="amount" id="edit-korosho-exp-amount" class="form-control" required/></div><div class="form-group"><label class="form-label">Date *</label><input type="date" name="expense_date" id="edit-korosho-exp-date" class="form-control" required/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Belongs To (optional)</label><select name="employee_id" id="edit-korosho-exp-employee" class="form-control"><option value="">— None —</option><?php $employees_list->data_seek(0); while($emp=$employees_list->fetch_assoc()): ?><option value="<?=$emp['id']?>"><?=htmlspecialchars($emp['name'])?></option><?php endwhile; ?></select></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-expense-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>

<?php
$extra_js = <<<'JS'
<script>
function koroshoOpenEditExpense(btn){
  document.getElementById('edit-korosho-exp-id').value = btn.dataset.id;
  document.getElementById('edit-korosho-exp-desc').value = btn.dataset.desc;
  document.getElementById('edit-korosho-exp-cat').value = btn.dataset.cat;
  const empSelect = document.getElementById('edit-korosho-exp-employee');
  if(btn.dataset.employee && ![...empSelect.options].some(o=>o.value===btn.dataset.employee)){
    const opt = document.createElement('option');
    opt.value = btn.dataset.employee; opt.textContent = btn.dataset.employeeName || ('#'+btn.dataset.employee);
    empSelect.appendChild(opt);
  }
  empSelect.value = btn.dataset.employee;
  document.getElementById('edit-korosho-exp-amount').value = btn.dataset.amount;
  document.getElementById('edit-korosho-exp-date').value = btn.dataset.date;
  openModal('edit-expense-modal');
}
</script>
JS;
require_once 'includes/footer.php';
?>
