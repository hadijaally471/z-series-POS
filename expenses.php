<?php
// expenses.php
$page_title = 'Expenses';
require_once 'includes/header.php';
requirePrivilege('expenses');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $desc = sanitizeString($_POST['description'] ?? '', 500);
    $allowed = ['transport','utilities','staff','raw_materials','rent','maintenance','other'];
    $cat = in_array($_POST['category'] ?? '', $allowed, true) ? $_POST['category'] : 'other';
    $amount = sanitizeFloat($_POST['amount'] ?? 0); $date = sanitizeString($_POST['expense_date'] ?? '', 20);
    $stmt = $conn->prepare("INSERT INTO expenses (description,category,amount,expense_date,recorded_by) VALUES (?,?,?,?,?)");
    $stmt->bind_param('ssdsi',$desc,$cat,$amount,$date,$_SESSION['user_id']);
    $stmt->execute();
    logActivity($conn,"Expense recorded: $desc — ".number_format($amount)." TZS",'expense');
    $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">✅ Expense recorded!</div>';
}
$month = date('Y-m');
$today = date('Y-m-d');
$month_total = $conn->query("SELECT COALESCE(SUM(amount),0) as v FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')='$month'")->fetch_assoc()['v'];
$today_total = $conn->query("SELECT COALESCE(SUM(amount),0) as v FROM expenses WHERE expense_date='$today'")->fetch_assoc()['v'];
$cat_top = $conn->query("SELECT category, SUM(amount) as total FROM expenses GROUP BY category ORDER BY total DESC LIMIT 1")->fetch_assoc();
$expenses = $conn->query("SELECT * FROM expenses ORDER BY expense_date DESC, created_at DESC LIMIT 50");
$cat_icons = ['transport'=>'🚚','utilities'=>'⚡','staff'=>'👷','raw_materials'=>'🧪','rent'=>'🏢','maintenance'=>'🔧','other'=>'📌'];
?>
<div class="stats-grid">
  <div class="stat-card red"><div class="stat-label">This Month</div><div class="stat-value"><?=tzs($month_total)?></div><div class="stat-icon">💸</div></div>
  <div class="stat-card amber"><div class="stat-label">Today</div><div class="stat-value"><?=tzs($today_total)?></div><div class="stat-icon">📅</div></div>
  <div class="stat-card purple"><div class="stat-label">Top Category</div><div class="stat-value"><?=ucwords(str_replace('_',' ',$cat_top['category']??'N/A'))?></div><div class="stat-icon">📊</div></div>
  <div class="stat-card green"><div class="stat-label">Top Category Total</div><div class="stat-value"><?=tzs($cat_top['total']??0)?></div><div class="stat-icon">💰</div></div>
</div>
<?=$msg?>
<div style="margin-bottom:14px"><button class="btn btn-primary" onclick="openModal('add-expense-modal')">+ Add Expense</button></div>
<div class="card"><div class="card-header"><span class="card-title">Recent Expenses</span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Description</th><th>Category</th><th>Amount</th><th>Date</th></tr></thead>
<tbody><?php $i=1; while($e=$expenses->fetch_assoc()):?>
<tr><td class="text-muted"><?=$i++?></td><td class="td-bold"><?=($cat_icons[$e['category']]??'📌').' '.htmlspecialchars($e['description'])?></td>
<td><span class="badge badge-purple"><?=ucwords(str_replace('_',' ',$e['category']))?></span></td>
<td class="text-danger"><?=tzs($e['amount'])?></td><td class="text-muted"><?=date('M d, Y',strtotime($e['expense_date']))?></td></tr>
<?php endwhile; if($expenses->num_rows===0):?><tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text3)">No expenses recorded</td></tr><?php endif;?></tbody></table></div></div>
<div class="modal-overlay" id="add-expense-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Expense</span><button class="modal-close" onclick="closeModal('add-expense-modal')">✕</button></div>
<form method="POST"><?= csrfInput() ?><div class="modal-body">
<div class="form-row"><div class="form-group"><label class="form-label">Description *</label><input name="description" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Category</label><select name="category" class="form-control"><option value="transport">Transport</option><option value="utilities">Utilities</option><option value="staff">Staff/Salary</option><option value="raw_materials">Raw Materials</option><option value="rent">Rent</option><option value="maintenance">Maintenance</option><option value="other">Other</option></select></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Amount (TZS) *</label><input type="number" name="amount" class="form-control" required/></div><div class="form-group"><label class="form-label">Date *</label><input type="date" name="expense_date" class="form-control" value="<?=date('Y-m-d')?>" required/></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-expense-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Expense</button></div></form></div></div>
<?php require_once 'includes/footer.php'; ?>
