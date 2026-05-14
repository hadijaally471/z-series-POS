<?php
// purchase_orders.php
$page_title = 'Purchase Orders';
require_once 'includes/header.php';
requirePrivilege('purchase_orders');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action']??'';
    if ($action === 'add') {
        $sup_id = !empty($_POST['supplier_id'])?sanitizeInt($_POST['supplier_id']):null;
        $sup_name = sanitizeString($_POST['supplier_name'] ?? '', 200);
        $items = sanitizeString($_POST['items'] ?? '', 2000);
        $total = sanitizeFloat($_POST['total_amount'] ?? 0);
        $terms = sanitizeString($_POST['payment_terms'] ?? '', 100);
        $expected = sanitizeString($_POST['expected_date'] ?? '', 20);
        $last = $conn->query("SELECT po_number FROM purchase_orders ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $num = $last ? (int)substr($last['po_number'],3)+1 : 1;
        $po_number = 'PO-'.str_pad($num,4,'0',STR_PAD_LEFT);
        $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number,supplier_id,supplier_name,items,total_amount,payment_terms,order_date,expected_date) VALUES (?,?,?,?,?,?,CURDATE(),?)");
        $stmt->bind_param('sissdss',$po_number,$sup_id,$sup_name,$items,$total,$terms,$expected);
        $stmt->execute();
        logActivity($conn,"Purchase Order created: $po_number — $sup_name",'po');
        $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">✅ Purchase Order '.$po_number.' created!</div>';
    }
    if ($action === 'receive') {
      $id = sanitizeInt($_POST['id'] ?? 0);
      $stmt = $conn->prepare("UPDATE purchase_orders SET status='received' WHERE id = ?");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      logActivity($conn,"Purchase Order received: #$id",'po');
      $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">✅ Order marked as received!</div>';
    }
}
$status_f = $_GET['status']??'';
$allowed_statuses = ['pending','received','cancelled'];
if (!in_array($status_f, $allowed_statuses, true)) {
  $status_f = '';
}
if ($status_f) {
  $stmt = $conn->prepare("SELECT * FROM purchase_orders WHERE status = ? ORDER BY created_at DESC");
  $stmt->bind_param('s', $status_f);
  $stmt->execute();
  $pos = $stmt->get_result();
} else {
  $pos = $conn->query("SELECT * FROM purchase_orders ORDER BY created_at DESC");
}
$suppliers = $conn->query("SELECT * FROM suppliers WHERE status='active' ORDER BY name");
$stats = $conn->query("SELECT SUM(status='pending') as pending, SUM(status='received') as received, SUM(status='cancelled') as cancelled, COALESCE(SUM(total_amount),0) as total_value FROM purchase_orders")->fetch_assoc();
?>
<div class="stats-grid">
  <div class="stat-card amber"><div class="stat-label">Pending Orders</div><div class="stat-value"><?=$stats['pending']?></div><div class="stat-icon">⏳</div></div>
  <div class="stat-card green"><div class="stat-label">Received</div><div class="stat-value"><?=$stats['received']?></div><div class="stat-icon">✅</div></div>
  <div class="stat-card red"><div class="stat-label">Cancelled</div><div class="stat-value"><?=$stats['cancelled']?></div><div class="stat-icon">❌</div></div>
  <div class="stat-card purple"><div class="stat-label">Total Value</div><div class="stat-value"><?=tzs($stats['total_value'])?></div><div class="stat-icon">💰</div></div>
</div>
<?=$msg?>
<form method="GET" style="display:contents"><div class="filter-bar">
  <select class="filter-select" name="status" onchange="this.form.submit()"><option value="">All Status</option><option value="pending" <?=$status_f==='pending'?'selected':''?>>Pending</option><option value="received" <?=$status_f==='received'?'selected':''?>>Received</option><option value="cancelled" <?=$status_f==='cancelled'?'selected':''?>>Cancelled</option></select>
  <button type="button" class="btn btn-primary" onclick="openModal('add-po-modal')">+ New Purchase Order</button>
</div></form>
<div class="card"><div class="card-header"><span class="card-title">Purchase Orders (<?=$pos->num_rows?>)</span></div>
<div class="table-wrap"><table><thead><tr><th>PO #</th><th>Supplier</th><th>Items</th><th>Total</th><th>Expected</th><th>Status</th><th>Actions</th></tr></thead>
<tbody><?php while($po=$pos->fetch_assoc()):?>
<tr><td class="text-purple"><?=htmlspecialchars($po['po_number'])?></td><td class="td-bold"><?=htmlspecialchars($po['supplier_name'])?></td>
<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($po['items'])?></td>
<td class="text-success"><?=tzs($po['total_amount'])?></td>
<td class="text-muted"><?=$po['expected_date']?date('M d, Y',strtotime($po['expected_date'])):'—'?></td>
<td><span class="badge badge-<?=$po['status']==='received'?'success':($po['status']==='pending'?'warning':'danger')?>"><?=ucfirst($po['status'])?></span></td>
<td>
  <?php if($po['status']==='pending'):?>
  <form method="POST" style="display:inline"><input type="hidden" name="action" value="receive"><?= csrfInput() ?><input type="hidden" name="id" value="<?=$po['id']?>">
  <button type="submit" class="btn btn-success btn-sm">✅ Received</button></form>
  <?php endif;?>
</td></tr>
<?php endwhile; if($pos->num_rows===0):?><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No purchase orders found</td></tr><?php endif;?></tbody></table></div></div>
<div class="modal-overlay" id="add-po-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">New Purchase Order</span><button class="modal-close" onclick="closeModal('add-po-modal')">✕</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-row">
<div class="form-group"><label class="form-label">Supplier *</label><select name="supplier_id" class="form-control" onchange="setSupName(this)" required><option value="">Select supplier</option><?php $suppliers->data_seek(0); while($s=$suppliers->fetch_assoc()):?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endwhile;?></select></div>
<div class="form-group"><label class="form-label">Supplier Name</label><input name="supplier_name" id="po-sup-name" class="form-control"/></div></div>
<div class="form-group"><label class="form-label">Items & Quantities *</label><textarea name="items" class="form-control" rows="3" required placeholder="e.g. Cashew Nuts Grade A — 50kg"></textarea></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Total Amount (TZS) *</label><input type="number" name="total_amount" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Expected Date</label><input type="date" name="expected_date" class="form-control" value="<?=date('Y-m-d',strtotime('+7 days'))?>"/></div></div>
<div class="form-group"><label class="form-label">Payment Terms</label><select name="payment_terms" class="form-control"><option>Cash on Delivery</option><option>50% Advance</option><option>Full Advance</option><option>Credit 30 days</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-po-modal')">Cancel</button><button type="submit" class="btn btn-primary">Create Order</button></div></form></div></div>
<?php
$extra_js = "<script>
function setSupName(sel){document.getElementById('po-sup-name').value=sel.selectedOptions[0].text;}
document.addEventListener('DOMContentLoaded', function() {
  const msgDiv = document.querySelector('div[style*=\"color:var\"]');
  if (msgDiv && (msgDiv.textContent.includes('✅') || msgDiv.textContent.includes('❌') || msgDiv.textContent.includes('🗑️'))) {
    setTimeout(() => {
      msgDiv.style.opacity = '0';
      msgDiv.style.transition = 'opacity 0.3s ease-out';
      setTimeout(() => msgDiv.remove(), 300);
    }, 2000);
  }
});
</script>";
require_once 'includes/footer.php';
?>
