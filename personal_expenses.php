<?php
// personal_expenses.php — private, admin-only draws. Never linked to
// `expenses`, `sales`, or `billiard_sales` — has no effect on any
// revenue/profit figure shown elsewhere in the app.
$page_title = 'Personal Expenses';
require_once 'includes/header.php';
requirePrivilege('personal_expenses');
if (($_SESSION['user_role'] ?? '') !== 'admin') {
  http_response_code(403);
  die('<div style="padding: 40px; text-align: center; font-family: sans-serif; color: #dc3545;"><h2>Access Denied</h2><p>Personal Expenses is restricted to admin accounts only.</p><a href="dashboard.php" style="color: #0d6efd; text-decoration: none;">Back to Dashboard</a></div>');
}

$msg = '';
$allowed_sources = ['uzunguni', 'cashewnuts', 'z_series'];
$source_labels = ['uzunguni' => 'Uzunguni Billiards', 'cashewnuts' => 'Cashew Nuts', 'z_series' => 'Z Series (Tiles/Soap)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
  $action = $_POST['action'] ?? 'add';

  if ($action === 'add') {
    $desc = sanitizeString($_POST['description'] ?? '', 500);
    $source = in_array($_POST['source'] ?? '', $allowed_sources, true) ? $_POST['source'] : 'z_series';
    $amount = sanitizeFloat($_POST['amount'] ?? 0);
    $date = sanitizeString($_POST['expense_date'] ?? '', 20);
    $notes = sanitizeString($_POST['notes'] ?? '', 1000);
    if ($desc === '' || $amount <= 0) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Description and a positive amount are required.</div>';
    } else {
      $stmt = $conn->prepare("INSERT INTO personal_expenses (description, source, amount, expense_date, notes, recorded_by) VALUES (?,?,?,?,?,?)");
      $stmt->bind_param('ssdssi', $desc, $source, $amount, $date, $notes, $_SESSION['user_id']);
      $stmt->execute();
      logActivity($conn, "Personal expense recorded: $desc — " . number_format($amount) . " TZS (from " . $source_labels[$source] . ")", 'system');
      $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Personal expense recorded!</div>';
    }
  }

  if ($action === 'edit') {
    $id = sanitizeInt($_POST['id'] ?? 0);
    $desc = sanitizeString($_POST['description'] ?? '', 500);
    $source = in_array($_POST['source'] ?? '', $allowed_sources, true) ? $_POST['source'] : 'z_series';
    $amount = sanitizeFloat($_POST['amount'] ?? 0);
    $date = sanitizeString($_POST['expense_date'] ?? '', 20);
    $notes = sanitizeString($_POST['notes'] ?? '', 1000);
    if ($id > 0 && $desc !== '' && $amount > 0) {
      $stmt = $conn->prepare("UPDATE personal_expenses SET description=?, source=?, amount=?, expense_date=?, notes=? WHERE id=?");
      $stmt->bind_param('ssdssi', $desc, $source, $amount, $date, $notes, $id);
      $stmt->execute();
      logActivity($conn, "Personal expense updated: $desc — " . number_format($amount) . " TZS", 'system');
      $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Personal expense updated!</div>';
    }
  }

  if ($action === 'delete') {
    $id = sanitizeInt($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $conn->prepare("SELECT description FROM personal_expenses WHERE id = ?");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $pe = $stmt->get_result()->fetch_assoc();
      $stmt = $conn->prepare("DELETE FROM personal_expenses WHERE id = ?");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      logActivity($conn, "Personal expense deleted: " . ($pe['description'] ?? 'Unknown'), 'system');
      $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Personal expense deleted!</div>';
    }
  }
}

$month = date('Y-m');
$today = date('Y-m-d');

$stmt_m = $conn->prepare("SELECT COALESCE(SUM(amount),0) as v FROM personal_expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?");
$stmt_m->bind_param('s', $month);
$stmt_m->execute();
$month_total = $stmt_m->get_result()->fetch_assoc()['v'];

$stmt_t = $conn->prepare("SELECT COALESCE(SUM(amount),0) as v FROM personal_expenses WHERE expense_date=?");
$stmt_t->bind_param('s', $today);
$stmt_t->execute();
$today_total = $stmt_t->get_result()->fetch_assoc()['v'];

$all_time_total = $conn->query("SELECT COALESCE(SUM(amount),0) as v FROM personal_expenses")->fetch_assoc()['v'];

$stmt_src = $conn->prepare("SELECT source, COALESCE(SUM(amount),0) as total FROM personal_expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=? GROUP BY source");
$stmt_src->bind_param('s', $month);
$stmt_src->execute();
$source_totals = ['uzunguni' => 0, 'cashewnuts' => 0, 'z_series' => 0];
$src_result = $stmt_src->get_result();
while ($row = $src_result->fetch_assoc()) $source_totals[$row['source']] = $row['total'];

$personal_expenses = $conn->query("SELECT pe.*, u.name as recorded_by_name FROM personal_expenses pe LEFT JOIN users u ON pe.recorded_by=u.id ORDER BY pe.expense_date DESC, pe.created_at DESC LIMIT 100");

$source_badge = ['uzunguni' => 'amber', 'cashewnuts' => 'success', 'z_series' => 'purple'];
?>
<div class="stats-grid-3">
  <div class="stat-card red"><div class="stat-label">This Month</div><div class="stat-value"><?=tzs($month_total)?></div></div>
  <div class="stat-card amber"><div class="stat-label">Today</div><div class="stat-value"><?=tzs($today_total)?></div></div>
  <div class="stat-card purple"><div class="stat-label">All-Time Total</div><div class="stat-value"><?=tzs($all_time_total)?></div></div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">This Month — By Source</span></div>
  <div class="table-wrap"><table><thead><tr><th>Source</th><th>Drawn This Month</th></tr></thead>
  <tbody>
  <?php foreach ($allowed_sources as $s): ?>
    <tr><td><span class="badge badge-<?=$source_badge[$s]?>"><?=$source_labels[$s]?></span></td><td class="text-danger"><?=tzs($source_totals[$s])?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<?=$msg?>
<div style="margin:14px 0"><button class="btn btn-primary" onclick="openModal('add-pe-modal')">+ Record Personal Expense</button></div>

<div class="card"><div class="card-header"><span class="card-title">Personal Expenses</span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Description</th><th>Source</th><th>Amount</th><th>Date</th><th>Recorded By</th><th>Actions</th></tr></thead>
<tbody><?php $i=1; while($e=$personal_expenses->fetch_assoc()):?>
<tr><td class="text-muted"><?=$i++?></td>
<td><span class="td-bold"><?=htmlspecialchars($e['description'])?></span><?php if(!empty($e['notes'])):?><div style="color:var(--text3);font-size:12px;margin-top:2px"><?=htmlspecialchars($e['notes'])?></div><?php endif;?></td>
<td><span class="badge badge-<?=$source_badge[$e['source']]?>"><?=$source_labels[$e['source']]?></span></td>
<td class="text-danger"><?=tzs($e['amount'])?></td>
<td class="text-muted"><?=date('M d, Y',strtotime($e['expense_date']))?></td>
<td class="text-muted"><?=$e['recorded_by_name']?htmlspecialchars($e['recorded_by_name']):'—'?></td>
<td style="display:flex;gap:6px;align-items:center">
  <button type="button" class="btn btn-outline btn-sm" onclick='openEditPe(this)' data-id="<?=$e['id']?>" data-desc="<?=htmlspecialchars($e['description'], ENT_QUOTES)?>" data-source="<?=$e['source']?>" data-amount="<?=$e['amount']?>" data-date="<?=$e['expense_date']?>" data-notes="<?=htmlspecialchars($e['notes'] ?? '', ENT_QUOTES)?>">Edit</button>
  <form method="POST" style="margin:0" data-confirm="Delete this personal expense?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$e['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
</td></tr>
<?php endwhile; if($personal_expenses->num_rows===0):?><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No personal expenses recorded</td></tr><?php endif;?></tbody></table></div></div>

<!-- Add Personal Expense Modal -->
<div class="modal-overlay" id="add-pe-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Record Personal Expense</span><button class="modal-close" onclick="closeModal('add-pe-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Description *</label><input name="description" class="form-control" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Amount (TZS) *</label><input type="number" name="amount" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Date *</label><input type="date" name="expense_date" class="form-control" value="<?=date('Y-m-d')?>" required/></div>
</div>
<div class="form-group"><label class="form-label">Money Used From *</label>
<select name="source" class="form-control" required>
<?php foreach ($allowed_sources as $s): ?><option value="<?=$s?>"><?=$source_labels[$s]?></option><?php endforeach; ?>
</select></div>
<div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Optional"></textarea></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-pe-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div></form></div></div>

<!-- Edit Personal Expense Modal -->
<div class="modal-overlay" id="edit-pe-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Personal Expense</span><button class="modal-close" onclick="closeModal('edit-pe-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit-pe-id"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Description *</label><input name="description" id="edit-pe-desc" class="form-control" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Amount (TZS) *</label><input type="number" name="amount" id="edit-pe-amount" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Date *</label><input type="date" name="expense_date" id="edit-pe-date" class="form-control" required/></div>
</div>
<div class="form-group"><label class="form-label">Money Used From *</label>
<select name="source" id="edit-pe-source" class="form-control" required>
<?php foreach ($allowed_sources as $s): ?><option value="<?=$s?>"><?=$source_labels[$s]?></option><?php endforeach; ?>
</select></div>
<div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="edit-pe-notes" class="form-control" rows="2"></textarea></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-pe-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>

<?php require_once 'includes/footer.php'; ?>
<script>
function openEditPe(btn){
  document.getElementById('edit-pe-id').value = btn.dataset.id;
  document.getElementById('edit-pe-desc').value = btn.dataset.desc;
  document.getElementById('edit-pe-source').value = btn.dataset.source;
  document.getElementById('edit-pe-amount').value = btn.dataset.amount;
  document.getElementById('edit-pe-date').value = btn.dataset.date;
  document.getElementById('edit-pe-notes').value = btn.dataset.notes;
  openModal('edit-pe-modal');
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
