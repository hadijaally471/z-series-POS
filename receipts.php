<?php
// receipts.php
$page_title = 'Receipts';
$content_class = 'content premium-content receipt-page';
require_once 'includes/header.php';
requirePrivilege('receipts');
$search = sanitizeString($_GET['search'] ?? '', 100);
$date_f = sanitizeString($_GET['date'] ?? '', 20);
$where = ["1=1"];
$params = [];
$types = '';
if($search) {
    $term = '%' . $search . '%';
    $where[] = "(s.receipt_number LIKE ? OR s.customer_name LIKE ?)";
    $params[] = $term;
    $params[] = $term;
    $types .= 'ss';
}
if($date_f) {
    $date_value = date('Y-m-d', strtotime($date_f));
    $where[] = "DATE(s.created_at) = ?";
    $params[] = $date_value;
    $types .= 's';
}
$stmt = $conn->prepare("SELECT s.* FROM sales s WHERE " . implode(' AND ', $where) . " ORDER BY s.created_at DESC LIMIT 50");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$sales = $stmt->get_result();
$selected_sale = null;
$selected_items = null;
if(isset($_GET['view'])){
    $sid = sanitizeInt($_GET['view']);
    $stmt = $conn->prepare("SELECT * FROM sales WHERE id = ?");
    $stmt->bind_param('i', $sid);
    $stmt->execute();
    $selected_sale = $stmt->get_result()->fetch_assoc();
    $stmt = $conn->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $stmt->bind_param('i', $sid);
    $stmt->execute();
    $selected_items = $stmt->get_result();
}
$business_name = getSetting($conn,'business_name');
$business_phone = getSetting($conn,'business_phone');
$business_address = getSetting($conn,'business_address');
$receipt_phone = '+255 755 059 387';
$receipt_footer = getSetting($conn,'receipt_footer');
?>
<form method="GET" style="display:contents"><div class="filter-bar">
  <input class="filter-search" name="search" placeholder="Search by receipt # or customer..." value="<?=htmlspecialchars($search)?>"/>
  <input type="date" name="date" class="filter-select" value="<?=htmlspecialchars($date_f)?>"/>
  <button type="submit" class="btn btn-outline">Filter</button>
</div></form>
<div class="grid-2">
<div class="card"><div class="card-header"><span class="card-title">Recent Receipts (<?=$sales->num_rows?>)</span></div>
<div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Customer</th><th>Total</th><th>Payment</th><th>Type</th><th>Date</th></tr></thead>
<tbody><?php while($s=$sales->fetch_assoc()):?>
<tr class="clickable" onclick="window.location='?view=<?=$s['id']?><?=$search?"&search=".urlencode($search):''?>'" style="<?=isset($_GET['view'])&&$_GET['view']==$s['id']?'background:var(--purple-light)':''?>">
<td class="text-purple"><?=htmlspecialchars($s['receipt_number'])?></td>
<td class="td-bold"><?=htmlspecialchars($s['customer_name'])?></td>
<td class="text-success"><?=tzs($s['total'])?></td>
<td><span class="badge badge-<?=$s['payment_method']==='mpesa'?'success':($s['payment_method']==='debt'?'danger':'warning')?>"><?=ucfirst($s['payment_method'])?></span></td>
<td><span class="badge badge-<?=$s['price_type']==='jumla'?'info':'purple'?>"><?=ucfirst($s['price_type'])?></span></td>
<td class="text-muted"><?=date('M d H:i',strtotime($s['created_at']))?></td>
</tr><?php endwhile;?></tbody></table></div></div>

<div class="card"><div class="card-header"><span class="card-title">Receipt Preview</span><?php if($selected_sale):?><button class="btn btn-primary btn-sm" onclick="printReceipt('#print-receipt')">🖨️ Print</button><?php endif;?></div>
<div class="card-body receipt-preview-body">
<?php if($selected_sale): ?>
<div class="receipt-box" id="print-receipt">
  <div class="receipt-header"><div class="receipt-company"><?=htmlspecialchars($business_name)?></div><div class="receipt-sub"><?=htmlspecialchars($business_address)?></div><div class="receipt-sub"><?=htmlspecialchars($receipt_phone)?></div></div>
  <div class="receipt-row"><span>Receipt:</span><span><?=htmlspecialchars($selected_sale['receipt_number'])?></span></div>
  <div class="receipt-row"><span>Date:</span><span><?=date('d/m/Y H:i',strtotime($selected_sale['created_at']))?></span></div>
  <div class="receipt-row"><span>Customer:</span><span><?=htmlspecialchars($selected_sale['customer_name'])?></span></div>
  <div class="receipt-row"><span>Payment:</span><span><?=strtoupper($selected_sale['payment_method'])?></span></div>
  <div class="receipt-row"><span>Type:</span><span><?=strtoupper($selected_sale['price_type'])?></span></div>
  <hr class="receipt-divider"/>
  <?php while($item=$selected_items->fetch_assoc()):?>
  <div class="receipt-row"><span><?=htmlspecialchars($item['product_name'])?> x<?=$item['qty']?></span><span><?=tzs($item['total'])?></span></div>
  <?php endwhile;?>
  <hr class="receipt-divider"/>
  <div class="receipt-row"><span>Subtotal:</span><span><?=tzs($selected_sale['subtotal'])?></span></div>
  <div class="receipt-row"><span>Discount:</span><span><?=tzs($selected_sale['discount'])?></span></div>
  <div class="receipt-row receipt-total"><span>TOTAL:</span><span><?=tzs($selected_sale['total'])?></span></div>
  <div class="receipt-footer"><div><?=htmlspecialchars($receipt_footer)?></div><div><?=htmlspecialchars($business_name)?> © <?=date('Y')?></div></div>
</div>
<?php else: ?>
<div class="empty-state"><div class="empty-state-icon">🧾</div><div class="empty-state-title">Select a receipt</div><div class="empty-state-sub">Click "View" on any receipt to preview</div></div>
<?php endif;?>
</div></div></div>
<?php require_once 'includes/footer.php'; ?>
