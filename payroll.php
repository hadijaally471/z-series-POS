<?php
// payroll.php
$page_title = 'Payroll';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('payroll');
$msg = '';

$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}
$prev_period = date('Y-m', strtotime($period . '-01 -1 month'));
$next_period = date('Y-m', strtotime($period . '-01 +1 month'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $gen_period = $_POST['period'] ?? '';
        if (preg_match('/^\d{4}-\d{2}$/', $gen_period)) {
            $employees = $conn->query("SELECT id, salary FROM employees WHERE status IN ('active','on_leave') AND role != 'Administrator'");
            $created = 0;
            while ($emp = $employees->fetch_assoc()) {
                $check = $conn->prepare("SELECT id FROM payroll WHERE employee_id=? AND period=?");
                $check->bind_param('is', $emp['id'], $gen_period);
                $check->execute();
                if ($check->get_result()->fetch_assoc()) continue;
                $base = (float)$emp['salary'];
                $stmt = $conn->prepare("INSERT INTO payroll (employee_id, period, base_salary, net_pay, created_by) VALUES (?,?,?,?,?)");
                $stmt->bind_param('isddi', $emp['id'], $gen_period, $base, $base, $_SESSION['user_id']);
                $stmt->execute();
                $created++;
            }
            logActivity($conn, "Payroll generated for $gen_period ($created employee(s))", 'payroll');
            $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Payroll generated for ' . $created . ' employee(s)!</div>';
        }
    }

    if ($action === 'edit') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        $bonus = sanitizeFloat($_POST['bonus'] ?? 0);
        $deductions = sanitizeFloat($_POST['deductions'] ?? 0);
        $notes = sanitizeString($_POST['notes'] ?? '', 500);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT base_salary, status FROM payroll WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && $row['status'] === 'pending') {
                $net = (float)$row['base_salary'] + $bonus - $deductions;
                $stmt = $conn->prepare("UPDATE payroll SET bonus=?, deductions=?, net_pay=?, notes=? WHERE id=?");
                $stmt->bind_param('dddsi', $bonus, $deductions, $net, $notes, $id);
                $stmt->execute();
                $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Payroll entry updated!</div>';
            } else {
                $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Cannot edit — this payroll entry is already paid.</div>';
            }
        }
    }

    if ($action === 'mark_paid') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT p.*, e.name as employee_name FROM payroll p JOIN employees e ON p.employee_id=e.id WHERE p.id=? FOR UPDATE");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if (!$row || $row['status'] === 'paid') {
                    throw new Exception('Payroll entry not found or already paid.');
                }
                $desc = "Salary payment — " . $row['employee_name'] . " (" . $row['period'] . ")";
                $stmt = $conn->prepare("INSERT INTO expenses (description, category, employee_id, amount, expense_date, recorded_by) VALUES (?,?,?,?,CURDATE(),?)");
                $cat = 'staff';
                $stmt->bind_param('ssidi', $desc, $cat, $row['employee_id'], $row['net_pay'], $_SESSION['user_id']);
                $stmt->execute();
                $expense_id = $conn->insert_id;

                $stmt = $conn->prepare("UPDATE payroll SET status='paid', paid_date=CURDATE(), expense_id=? WHERE id=?");
                $stmt->bind_param('ii', $expense_id, $id);
                $stmt->execute();

                $conn->commit();
                logActivity($conn, "Payroll paid: " . $row['employee_name'] . " — " . number_format($row['net_pay']) . " TZS (" . $row['period'] . ")", 'payroll');
                $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Marked as paid!</div>';
            } catch (Exception $e) {
                $conn->rollback();
                $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }

    if ($action === 'revert') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT * FROM payroll WHERE id=? FOR UPDATE");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if (!$row || $row['status'] !== 'paid') {
                    throw new Exception('Payroll entry not found or not marked paid.');
                }
                $old_expense_id = $row['expense_id'];
                $stmt = $conn->prepare("UPDATE payroll SET status='pending', paid_date=NULL, expense_id=NULL WHERE id=?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                if ($old_expense_id) {
                    $stmt = $conn->prepare("DELETE FROM expenses WHERE id=?");
                    $stmt->bind_param('i', $old_expense_id);
                    $stmt->execute();
                }
                $conn->commit();
                logActivity($conn, "Payroll payment reverted (id $id)", 'payroll');
                $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Reverted to pending.</div>';
            } catch (Exception $e) {
                $conn->rollback();
                $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }

    if ($action === 'delete') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT status FROM payroll WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && $row['status'] === 'pending') {
                $stmt = $conn->prepare("DELETE FROM payroll WHERE id=?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Payroll entry deleted!</div>';
            } else {
                $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Cannot delete a paid entry — revert to pending first.</div>';
            }
        }
    }
}

$stmt = $conn->prepare("SELECT p.*, e.name as employee_name, e.role as employee_role FROM payroll p JOIN employees e ON p.employee_id=e.id WHERE p.period=? ORDER BY e.name");
$stmt->bind_param('s', $period);
$stmt->execute();
$payroll_list = $stmt->get_result();

$stmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(net_pay),0) as total, COALESCE(SUM(net_pay*(status='paid')),0) as paid, COALESCE(SUM(net_pay*(status='pending')),0) as pending FROM payroll WHERE period=?");
$stmt->bind_param('s', $period);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM employees e WHERE e.status IN ('active','on_leave') AND e.role != 'Administrator' AND NOT EXISTS (SELECT 1 FROM payroll p WHERE p.employee_id=e.id AND p.period=?)");
$stmt->bind_param('s', $period);
$stmt->execute();
$not_generated_count = $stmt->get_result()->fetch_assoc()['c'];
?>
<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Total Payroll</div><div class="stat-value"><?=tzs($stats['total'])?></div><div class="stat-icon"></div></div>
  <div class="stat-card green"><div class="stat-label">Paid</div><div class="stat-value"><?=tzs($stats['paid'])?></div><div class="stat-icon"></div></div>
  <div class="stat-card amber"><div class="stat-label">Pending</div><div class="stat-value"><?=tzs($stats['pending'])?></div><div class="stat-icon"></div></div>
  <div class="stat-card blue"><div class="stat-label">Employees in Run</div><div class="stat-value"><?=$stats['cnt']?></div><div class="stat-icon"></div></div>
</div>
<?=$msg?>

<div class="card" style="margin-bottom:16px">
  <div class="cal-header">
    <div class="cal-title"><?=date('F Y', strtotime($period . '-01'))?></div>
    <div class="cal-nav">
      <a class="btn btn-outline btn-sm" href="?period=<?=$prev_period?>">&larr; Prev</a>
      <a class="btn btn-outline btn-sm" href="?period=<?=date('Y-m')?>">This Month</a>
      <a class="btn btn-outline btn-sm" href="?period=<?=$next_period?>">Next &rarr;</a>
      <?php if ($not_generated_count > 0): ?>
      <form method="POST" style="margin:0"><input type="hidden" name="action" value="generate"><input type="hidden" name="period" value="<?=$period?>"><?=csrfInput()?>
        <button type="submit" class="btn btn-primary btn-sm">Generate Payroll (<?=$not_generated_count?> employee(s) missing)</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card"><div class="card-header"><span class="card-title">Payroll — <?=date('F Y', strtotime($period . '-01'))?></span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Employee</th><th>Role</th><th>Base Salary</th><th>Bonus</th><th>Deductions</th><th>Net Pay</th><th>Status</th><th>Paid Date</th><th>Actions</th></tr></thead>
<tbody><?php $i=1; while($p=$payroll_list->fetch_assoc()):?>
<tr>
  <td class="text-muted"><?=$i++?></td>
  <td class="td-bold"><?=htmlspecialchars($p['employee_name'])?></td>
  <td style="color:var(--text2);font-size:13px"><?=htmlspecialchars($p['employee_role'])?></td>
  <td class="text-muted"><?=tzs($p['base_salary'])?></td>
  <td class="text-success"><?=tzs($p['bonus'])?></td>
  <td class="text-danger"><?=tzs($p['deductions'])?></td>
  <td class="text-purple"><?=tzs($p['net_pay'])?></td>
  <td><span class="badge badge-<?=$p['status']==='paid'?'success':'warning'?>"><?=ucfirst($p['status'])?></span></td>
  <td class="text-muted"><?=$p['paid_date']?date('M d, Y',strtotime($p['paid_date'])):'—'?></td>
  <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
    <?php if ($p['status']==='pending'): ?>
    <button type="button" class="btn btn-outline btn-sm" onclick='openEditPayroll(this)' data-id="<?=$p['id']?>" data-bonus="<?=$p['bonus']?>" data-deductions="<?=$p['deductions']?>" data-notes="<?=htmlspecialchars($p['notes'] ?? '', ENT_QUOTES)?>">Edit</button>
    <form method="POST" style="margin:0" data-confirm="Mark <?=htmlspecialchars($p['employee_name'], ENT_QUOTES)?>'s salary as paid? This will record an expense of <?=tzs($p['net_pay'])?>."><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?=$p['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-success btn-sm">Mark Paid</button></form>
    <form method="POST" style="margin:0" data-confirm="Delete this payroll entry?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$p['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
    <?php else: ?>
    <form method="POST" style="margin:0" data-confirm="Revert this payment to pending? This will remove the linked expense record."><input type="hidden" name="action" value="revert"><input type="hidden" name="id" value="<?=$p['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-outline btn-sm">Revert to Pending</button></form>
    <?php endif; ?>
  </td>
</tr>
<?php endwhile; if($payroll_list->num_rows===0):?><tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text3)">No payroll generated for this month yet</td></tr><?php endif;?></tbody></table></div></div>

<!-- Edit Payroll Modal -->
<div class="modal-overlay" id="edit-payroll-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Adjust Payroll Entry</span><button class="modal-close" onclick="closeModal('edit-payroll-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit-payroll-id"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Bonus (TZS)</label><input type="number" name="bonus" id="edit-payroll-bonus" class="form-control" value="0"/></div><div class="form-group"><label class="form-label">Deductions (TZS)</label><input type="number" name="deductions" id="edit-payroll-deductions" class="form-control" value="0"/></div></div>
<div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="edit-payroll-notes" class="form-control" rows="2" placeholder="e.g. loan repayment, overtime bonus..."></textarea></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-payroll-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>

<?php require_once 'includes/footer.php'; ?>
<script>
function openEditPayroll(btn){
  document.getElementById('edit-payroll-id').value = btn.dataset.id;
  document.getElementById('edit-payroll-bonus').value = btn.dataset.bonus;
  document.getElementById('edit-payroll-deductions').value = btn.dataset.deductions;
  document.getElementById('edit-payroll-notes').value = btn.dataset.notes;
  openModal('edit-payroll-modal');
}
document.addEventListener('DOMContentLoaded', function() {
  const msgDiv = document.querySelector('div[style*="color:var"]');
  if (msgDiv && (msgDiv.textContent.trim().length > 0)) {
    setTimeout(() => {
      msgDiv.style.opacity = '0';
      msgDiv.style.transition = 'opacity 0.3s ease-out';
      setTimeout(() => msgDiv.remove(), 300);
    }, 2000);
  }
});
</script>
