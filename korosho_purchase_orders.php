<?php
$page_title = 'Korosho Purchase Orders';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('korosho');
$msg = '';
if (!empty($_SESSION['korosho_po_flash'])) {
  $msg = $_SESSION['korosho_po_flash'];
  unset($_SESSION['korosho_po_flash']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action']??'';
    if ($action === 'add') {
        $sup_name = sanitizeString($_POST['supplier_name'] ?? '', 200);
        $items = sanitizeString($_POST['items'] ?? '', 2000);
        $total = sanitizeFloat($_POST['total_amount'] ?? 0);
        $terms = sanitizeString($_POST['payment_terms'] ?? '', 100);
        $expected = sanitizeString($_POST['expected_date'] ?? '', 20);
        if ($sup_name === '' || $items === '') {
          $msg='<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Supplier name and items are required.</div>';
        } else {
          $temp_po = 'PO-TEMP';
          $stmt = $conn->prepare("INSERT INTO korosho_purchase_orders (po_number,supplier_name,items,total_amount,payment_terms,order_date,expected_date) VALUES (?,?,?,?,?,CURDATE(),?)");
          $stmt->bind_param('sssdss',$temp_po,$sup_name,$items,$total,$terms,$expected);
          $stmt->execute();
          $po_id = $conn->insert_id;
          $po_number = 'KPO-'.str_pad($po_id, 4, '0', STR_PAD_LEFT);
          $stmt = $conn->prepare("UPDATE korosho_purchase_orders SET po_number = ? WHERE id = ?");
          $stmt->bind_param('si', $po_number, $po_id);
          $stmt->execute();
          logActivity($conn,"Korosho Purchase Order created: $po_number — $sup_name",'po');
          $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Purchase Order '.$po_number.' created!</div>';
        }
    }
    if ($action === 'edit') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        $sup_name = sanitizeString($_POST['supplier_name'] ?? '', 200);
        $items = sanitizeString($_POST['items'] ?? '', 2000);
        $total = sanitizeFloat($_POST['total_amount'] ?? 0);
        $terms = sanitizeString($_POST['payment_terms'] ?? '', 100);
        $expected = sanitizeString($_POST['expected_date'] ?? '', 20);
        if ($id > 0 && $sup_name !== '' && $items !== '') {
            $stmt = $conn->prepare("UPDATE korosho_purchase_orders SET supplier_name=?, items=?, total_amount=?, payment_terms=?, expected_date=? WHERE id=?");
            $stmt->bind_param('ssdssi', $sup_name, $items, $total, $terms, $expected, $id);
            $stmt->execute();
            logActivity($conn,"Korosho Purchase Order updated: #$id — $sup_name",'po');
            $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Purchase order updated!</div>';
        }
    }
    if ($action === 'receive') {
      $id = sanitizeInt($_POST['id'] ?? 0);
      $stmt = $conn->prepare("UPDATE korosho_purchase_orders SET status='received' WHERE id = ?");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      logActivity($conn,"Korosho Purchase Order received: #$id",'po');
      $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Order marked as received!</div>';
    }
    if ($action === 'cancel') {
      $id = sanitizeInt($_POST['id'] ?? 0);
      $stmt = $conn->prepare("UPDATE korosho_purchase_orders SET status='cancelled' WHERE id = ? AND status='pending'");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      logActivity($conn,"Korosho Purchase Order cancelled: #$id",'po');
      $msg='<div style="color:var(--warning);padding:10px;background:rgba(245,158,11,0.1);border-radius:8px;margin-bottom:14px">Order cancelled.</div>';
    }
    if ($action === 'delete') {
      $id = sanitizeInt($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = $conn->prepare("SELECT po_number FROM korosho_purchase_orders WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $po_row = $stmt->get_result()->fetch_assoc();
        $stmt = $conn->prepare("DELETE FROM korosho_purchase_orders WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        logActivity($conn, "Korosho Purchase Order deleted: " . ($po_row['po_number'] ?? '#'.$id), 'po');
        $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Purchase order deleted!</div>';
      }
    }

    $_SESSION['korosho_po_flash'] = $msg;
    $redirect_qs = $_GET ? ('?' . http_build_query($_GET)) : '';
    header('Location: korosho_purchase_orders.php' . $redirect_qs);
    exit;
}
$status_f = $_GET['status']??'';
$allowed_statuses = ['pending','received','cancelled'];
if (!in_array($status_f, $allowed_statuses, true)) {
  $status_f = '';
}
if ($status_f) {
  $stmt = $conn->prepare("SELECT * FROM korosho_purchase_orders WHERE status = ? ORDER BY created_at DESC");
  $stmt->bind_param('s', $status_f);
  $stmt->execute();
  $pos = $stmt->get_result();
} else {
  $pos = $conn->query("SELECT * FROM korosho_purchase_orders ORDER BY created_at DESC");
}
$stats = $conn->query("SELECT SUM(status='pending') as pending, SUM(status='received') as received, SUM(status='cancelled') as cancelled, COALESCE(SUM(total_amount),0) as total_value FROM korosho_purchase_orders")->fetch_assoc();
?>
<div class="no-print" style="margin-bottom:16px"><a href="korosho.php" class="btn btn-outline btn-sm">&larr; Korosho</a></div>

<div class="stats-grid">
  <div class="stat-card amber"><div class="stat-label">Pending Orders</div><div class="stat-value"><?=$stats['pending']?></div><div class="stat-icon"></div></div>
  <div class="stat-card green"><div class="stat-label">Received</div><div class="stat-value"><?=$stats['received']?></div><div class="stat-icon"></div></div>
  <div class="stat-card red"><div class="stat-label">Cancelled</div><div class="stat-value"><?=$stats['cancelled']?></div><div class="stat-icon"></div></div>
  <div class="stat-card purple"><div class="stat-label">Total Value</div><div class="stat-value"><?=tzs($stats['total_value'])?></div><div class="stat-icon"></div></div>
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
<td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
  <?php if($po['status']==='pending'):?>
  <form method="POST" style="margin:0"><input type="hidden" name="action" value="receive"><?= csrfInput() ?><input type="hidden" name="id" value="<?=$po['id']?>">
  <button type="submit" class="btn btn-success btn-sm">Received</button></form>
  <form method="POST" style="margin:0" data-confirm="Cancel this purchase order?"><input type="hidden" name="action" value="cancel"><?= csrfInput() ?><input type="hidden" name="id" value="<?=$po['id']?>">
  <button type="submit" class="btn btn-warning btn-sm">Cancel</button></form>
  <?php endif;?>
  <button type="button" class="btn btn-outline btn-sm" title="Edit" aria-label="Edit" onclick="koroshoOpenEditPO(this)" data-id="<?=$po['id']?>" data-supplier-name="<?=htmlspecialchars($po['supplier_name'], ENT_QUOTES)?>" data-items="<?=htmlspecialchars($po['items'], ENT_QUOTES)?>" data-total="<?=htmlspecialchars($po['total_amount'], ENT_QUOTES)?>" data-terms="<?=htmlspecialchars($po['payment_terms'] ?? '', ENT_QUOTES)?>" data-expected="<?=htmlspecialchars($po['expected_date'] ?? '', ENT_QUOTES)?>">✏️</button>
  <form method="POST" style="margin:0" data-confirm="Delete purchase order <?=htmlspecialchars($po['po_number'], ENT_QUOTES)?>?"><input type="hidden" name="action" value="delete"><?= csrfInput() ?><input type="hidden" name="id" value="<?=$po['id']?>">
  <button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
</td></tr>
<?php endwhile; if($pos->num_rows===0):?><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No purchase orders found</td></tr><?php endif;?></tbody></table></div></div>
<div class="modal-overlay" id="add-po-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">New Purchase Order</span><button class="modal-close" onclick="closeModal('add-po-modal')">✕</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Supplier Name *</label><input name="supplier_name" class="form-control" placeholder="e.g. Rashidi Farms" required/></div>
<div class="form-group"><label class="form-label">Items & Quantities *</label><textarea name="items" class="form-control" rows="3" required placeholder="e.g. Cashew Nuts Grade A — 50kg"></textarea></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Total Amount (TZS) *</label><input type="number" name="total_amount" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Expected Date</label><input type="date" name="expected_date" class="form-control" value="<?=date('Y-m-d',strtotime('+7 days'))?>"/></div></div>
<div class="form-group"><label class="form-label">Payment Terms</label><select name="payment_terms" class="form-control"><option>Cash on Delivery</option><option>50% Advance</option><option>Full Advance</option><option>Credit 30 days</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-po-modal')">Cancel</button><button type="submit" class="btn btn-primary">Create Order</button></div></form></div></div>
<div class="modal-overlay" id="edit-po-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Purchase Order</span><button class="modal-close" onclick="closeModal('edit-po-modal')">✕</button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit-po-id"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Supplier Name *</label><input name="supplier_name" id="edit-po-sup-name" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Items & Quantities *</label><textarea name="items" id="edit-po-items" class="form-control" rows="3" required></textarea></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Total Amount (TZS) *</label><input type="number" name="total_amount" id="edit-po-total" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Expected Date</label><input type="date" name="expected_date" id="edit-po-expected" class="form-control"/></div></div>
<div class="form-group"><label class="form-label">Payment Terms</label><select name="payment_terms" id="edit-po-terms" class="form-control"><option>Cash on Delivery</option><option>50% Advance</option><option>Full Advance</option><option>Credit 30 days</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-po-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>
<?php
$extra_js = <<<'JS'
<script>
function koroshoOpenEditPO(btn){
  document.getElementById('edit-po-id').value = btn.dataset.id;
  document.getElementById('edit-po-sup-name').value = btn.dataset.supplierName;
  document.getElementById('edit-po-items').value = btn.dataset.items;
  document.getElementById('edit-po-total').value = btn.dataset.total;
  document.getElementById('edit-po-terms').value = btn.dataset.terms;
  document.getElementById('edit-po-expected').value = btn.dataset.expected;
  openModal('edit-po-modal');
}
</script>
JS;
require_once 'includes/footer.php';
?>
