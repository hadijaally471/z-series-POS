<?php
$page_title = 'Korosho Reports';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('korosho');
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$msg = '';
if (!empty($_SESSION['korosho_reports_flash'])) {
  $msg = $_SESSION['korosho_reports_flash'];
  unset($_SESSION['korosho_reports_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
  $action = $_POST['action'] ?? '';

  if ($action === 'void') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can void a sale.</div>';
    } else {
      $sale_id = sanitizeInt($_POST['id'] ?? 0);
      $conn->begin_transaction();
      try {
        $stmt = $conn->prepare("SELECT id FROM korosho_sales WHERE id = ? AND status='completed' FOR UPDATE");
        $stmt->bind_param('i', $sale_id);
        $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();
        if (!$sale) {
          throw new Exception('Sale not found or already voided.');
        }
        $stmt = $conn->prepare("UPDATE korosho_sales SET status='cancelled' WHERE id = ?");
        $stmt->bind_param('i', $sale_id);
        $stmt->execute();

        // Voiding puts the sold quantity back into stock, since (unlike
        // Billiards/WiFi) Korosho products are stock-tracked.
        $items = $conn->prepare("SELECT product_id, qty FROM korosho_sale_items WHERE sale_id = ?");
        $items->bind_param('i', $sale_id);
        $items->execute();
        $itemRows = $items->get_result()->fetch_all(MYSQLI_ASSOC);
        $restock = $conn->prepare("UPDATE korosho_products SET stock = stock + ? WHERE id = ?");
        foreach ($itemRows as $row) {
          $restock->bind_param('ii', $row['qty'], $row['product_id']);
          $restock->execute();
        }
        $conn->commit();
        logActivity($conn, "Korosho sale voided: #$sale_id", 'sale');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Sale voided and stock restored.</div>';
      } catch (Exception $e) {
        $conn->rollback();
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">' . htmlspecialchars($e->getMessage()) . '</div>';
      }
    }
  }

  // POST/redirect/GET: send the browser to a plain GET after processing, so a
  // page refresh replays the GET instead of resubmitting the form.
  $_SESSION['korosho_reports_flash'] = $msg;
  $redirect_qs = $_GET ? ('?' . http_build_query($_GET)) : '';
  header('Location: korosho_reports.php' . $redirect_qs);
  exit;
}

$period_type = $_GET['period_type'] ?? 'daily';
if (!in_array($period_type, ['daily', 'weekly', 'monthly'], true)) $period_type = 'daily';

$day = $_GET['day'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');

$week = $_GET['week'] ?? (date('o') . '-W' . date('W'));
if (!preg_match('/^\d{4}-W\d{2}$/', $week)) $week = date('o') . '-W' . date('W');

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

if ($period_type === 'daily') {
  $range_start = $day;
  $range_end = $day;
  $range_label = date('F j, Y', strtotime($day));
} elseif ($period_type === 'weekly') {
  sscanf($week, '%d-W%d', $wyear, $wnum);
  $dto = new DateTime();
  $dto->setISODate((int)$wyear, (int)$wnum, 1);
  $range_start = $dto->format('Y-m-d');
  $dto->modify('+6 days');
  $range_end = $dto->format('Y-m-d');
  $range_label = 'Week of ' . date('M j', strtotime($range_start)) . ' – ' . date('M j, Y', strtotime($range_end));
} else {
  $range_start = $month . '-01';
  $range_end = date('Y-m-t', strtotime($range_start));
  $range_label = date('F Y', strtotime($range_start));
}

$rep_rows_result = $conn->query("SELECT * FROM korosho_employees WHERE status='active' ORDER BY name");
$rep_rows = [];
$rep_names_by_id = [];
while ($r = $rep_rows_result->fetch_assoc()) { $rep_rows[] = $r; $rep_names_by_id[$r['id']] = $r['name']; }

$rep_filter = sanitizeInt($_GET['sales_rep_id'] ?? 0);

if ($rep_filter > 0) {
  $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as revenue FROM korosho_sales WHERE sales_rep_id=? AND status='completed' AND DATE(created_at) BETWEEN ? AND ?");
  $stmt->bind_param('iss', $rep_filter, $range_start, $range_end);
} else {
  $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as revenue FROM korosho_sales WHERE status='completed' AND DATE(created_at) BETWEEN ? AND ?");
  $stmt->bind_param('ss', $range_start, $range_end);
}
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();

if ($rep_filter > 0) {
  $stmt = $conn->prepare("SELECT COALESCE(SUM(ksi.total - ksi.buying_price * ksi.qty),0) as profit FROM korosho_sale_items ksi JOIN korosho_sales ks ON ksi.sale_id=ks.id WHERE ks.sales_rep_id=? AND ks.status='completed' AND DATE(ks.created_at) BETWEEN ? AND ?");
  $stmt->bind_param('iss', $rep_filter, $range_start, $range_end);
} else {
  $stmt = $conn->prepare("SELECT COALESCE(SUM(ksi.total - ksi.buying_price * ksi.qty),0) as profit FROM korosho_sale_items ksi JOIN korosho_sales ks ON ksi.sale_id=ks.id WHERE ks.status='completed' AND DATE(ks.created_at) BETWEEN ? AND ?");
  $stmt->bind_param('ss', $range_start, $range_end);
}
$stmt->execute();
$totals['profit'] = $stmt->get_result()->fetch_assoc()['profit'];

// Revenue chart — hourly for a single day, daily for a week/month
$chart_points = [];
if ($period_type === 'daily') {
  for ($h = 0; $h < 24; $h++) {
    if ($rep_filter > 0) {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM korosho_sales WHERE sales_rep_id=? AND DATE(created_at)=? AND HOUR(created_at)=? AND status='completed'");
      $stmt->bind_param('isi', $rep_filter, $range_start, $h);
    } else {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM korosho_sales WHERE DATE(created_at)=? AND HOUR(created_at)=? AND status='completed'");
      $stmt->bind_param('si', $range_start, $h);
    }
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc()['v'];
    $chart_points[] = ['label' => str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00', 'revenue' => $r];
  }
  $chart_title = 'Hourly Revenue — ' . $range_label;
} else {
  $d = new DateTime($range_start);
  $end = new DateTime($range_end);
  while ($d <= $end) {
    $date = $d->format('Y-m-d');
    if ($rep_filter > 0) {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM korosho_sales WHERE sales_rep_id=? AND DATE(created_at)=? AND status='completed'");
      $stmt->bind_param('is', $rep_filter, $date);
    } else {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM korosho_sales WHERE DATE(created_at)=? AND status='completed'");
      $stmt->bind_param('s', $date);
    }
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc()['v'];
    $label = $period_type === 'weekly' ? $d->format('D j') : $d->format('d');
    $chart_points[] = ['label' => $label, 'revenue' => $r];
    $d->modify('+1 day');
  }
  $chart_title = 'Daily Revenue — ' . $range_label;
}
$chart_labels   = json_encode(array_column($chart_points, 'label'));
$chart_revenues = json_encode(array_column($chart_points, 'revenue'));

// By-product breakdown for the period — Buying Cost/Profit columns are
// admin-only since they expose the product's cost/margin.
if ($rep_filter > 0) {
  $stmt = $conn->prepare("SELECT ksi.product_name, COALESCE(SUM(ksi.total),0) as revenue, COALESCE(SUM(ksi.qty),0) as qty, COALESCE(SUM(ksi.buying_price * ksi.qty),0) as cost, COALESCE(SUM(ksi.total - ksi.buying_price * ksi.qty),0) as profit FROM korosho_sale_items ksi JOIN korosho_sales ks ON ksi.sale_id=ks.id WHERE ks.sales_rep_id=? AND ks.status='completed' AND DATE(ks.created_at) BETWEEN ? AND ? GROUP BY ksi.product_name ORDER BY revenue DESC");
  $stmt->bind_param('iss', $rep_filter, $range_start, $range_end);
} else {
  $stmt = $conn->prepare("SELECT ksi.product_name, COALESCE(SUM(ksi.total),0) as revenue, COALESCE(SUM(ksi.qty),0) as qty, COALESCE(SUM(ksi.buying_price * ksi.qty),0) as cost, COALESCE(SUM(ksi.total - ksi.buying_price * ksi.qty),0) as profit FROM korosho_sale_items ksi JOIN korosho_sales ks ON ksi.sale_id=ks.id WHERE ks.status='completed' AND DATE(ks.created_at) BETWEEN ? AND ? GROUP BY ksi.product_name ORDER BY revenue DESC");
  $stmt->bind_param('ss', $range_start, $range_end);
}
$stmt->execute();
$product_breakdown_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$product_labels = json_encode(array_column($product_breakdown_rows, 'product_name'));
$product_totals = json_encode(array_column($product_breakdown_rows, 'revenue'));

// By-sales-rep breakdown — always all reps, regardless of the filter above
// (same idea as Billiards' "By Branch" table).
$stmt = $conn->prepare("SELECT re.id, re.name, COALESCE(SUM(ks.total),0) as revenue, COALESCE(SUM(ksi_sum.kg),0) as kg, COALESCE(SUM(ksi_sum.profit),0) as profit, COUNT(DISTINCT ks.id) as cnt FROM korosho_employees re LEFT JOIN korosho_sales ks ON ks.sales_rep_id=re.id AND ks.status='completed' AND DATE(ks.created_at) BETWEEN ? AND ? LEFT JOIN (SELECT sale_id, SUM(qty) as kg, SUM(total - buying_price*qty) as profit FROM korosho_sale_items GROUP BY sale_id) ksi_sum ON ksi_sum.sale_id=ks.id WHERE re.status='active' GROUP BY re.id, re.name ORDER BY re.name");
$stmt->bind_param('ss', $range_start, $range_end);
$stmt->execute();
$rep_breakdown_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Daily buying/selling ledger for the period — same shape as the original
// Date/Kg/Buying Price/Selling Price/Profit book, computed automatically
// from real sales instead of hand-entered rows.
if ($rep_filter > 0) {
  $stmt = $conn->prepare("SELECT DATE(ks.created_at) as sale_date, COALESCE(SUM(ksi.qty),0) as kg, COALESCE(SUM(ksi.buying_price * ksi.qty),0) as buying, COALESCE(SUM(ksi.total),0) as selling, COALESCE(SUM(ksi.total - ksi.buying_price * ksi.qty),0) as profit FROM korosho_sale_items ksi JOIN korosho_sales ks ON ksi.sale_id=ks.id WHERE ks.sales_rep_id=? AND ks.status='completed' AND DATE(ks.created_at) BETWEEN ? AND ? GROUP BY DATE(ks.created_at) ORDER BY sale_date DESC");
  $stmt->bind_param('iss', $rep_filter, $range_start, $range_end);
} else {
  $stmt = $conn->prepare("SELECT DATE(ks.created_at) as sale_date, COALESCE(SUM(ksi.qty),0) as kg, COALESCE(SUM(ksi.buying_price * ksi.qty),0) as buying, COALESCE(SUM(ksi.total),0) as selling, COALESCE(SUM(ksi.total - ksi.buying_price * ksi.qty),0) as profit FROM korosho_sale_items ksi JOIN korosho_sales ks ON ksi.sale_id=ks.id WHERE ks.status='completed' AND DATE(ks.created_at) BETWEEN ? AND ? GROUP BY DATE(ks.created_at) ORDER BY sale_date DESC");
  $stmt->bind_param('ss', $range_start, $range_end);
}
$stmt->execute();
$ledger_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Recent sales for the period
if ($rep_filter > 0) {
  $stmt = $conn->prepare("SELECT ks.*, u.name as cashier_name, e.name as sales_rep_name, (SELECT COUNT(*) FROM korosho_sale_items WHERE sale_id=ks.id) as item_count FROM korosho_sales ks LEFT JOIN users u ON ks.cashier_id=u.id LEFT JOIN korosho_employees e ON ks.sales_rep_id=e.id WHERE ks.sales_rep_id=? AND DATE(ks.created_at) BETWEEN ? AND ? ORDER BY ks.created_at DESC LIMIT 100");
  $stmt->bind_param('iss', $rep_filter, $range_start, $range_end);
} else {
  $stmt = $conn->prepare("SELECT ks.*, u.name as cashier_name, e.name as sales_rep_name, (SELECT COUNT(*) FROM korosho_sale_items WHERE sale_id=ks.id) as item_count FROM korosho_sales ks LEFT JOIN users u ON ks.cashier_id=u.id LEFT JOIN korosho_employees e ON ks.sales_rep_id=e.id WHERE DATE(ks.created_at) BETWEEN ? AND ? ORDER BY ks.created_at DESC LIMIT 100");
  $stmt->bind_param('ss', $range_start, $range_end);
}
$stmt->execute();
$recent_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<div class="no-print" style="margin-bottom:16px"><a href="korosho.php" class="btn btn-outline btn-sm">&larr; Korosho</a></div>

<div style="margin-bottom:16px" class="no-print">
  <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <label style="font-size:12px;color:var(--text2)">View by:</label>
    <select name="period_type" id="period-type-select" class="form-control" style="width:130px" onchange="togglePeriodInputs(this.value)">
      <option value="daily" <?=$period_type==='daily'?'selected':''?>>Daily</option>
      <option value="weekly" <?=$period_type==='weekly'?'selected':''?>>Weekly</option>
      <option value="monthly" <?=$period_type==='monthly'?'selected':''?>>Monthly</option>
    </select>
    <input type="date" name="day" id="period-input-daily" value="<?=htmlspecialchars($day)?>" class="form-control" style="width:160px;<?=$period_type!=='daily'?'display:none':''?>"/>
    <input type="week" name="week" id="period-input-weekly" value="<?=htmlspecialchars($week)?>" class="form-control" style="width:160px;<?=$period_type!=='weekly'?'display:none':''?>"/>
    <input type="month" name="month" id="period-input-monthly" value="<?=htmlspecialchars($month)?>" class="form-control" style="width:160px;<?=$period_type!=='monthly'?'display:none':''?>"/>
    <select name="sales_rep_id" class="form-control" style="width:170px">
      <option value="0">All Sales Reps</option>
      <?php foreach ($rep_rows as $r): ?>
        <option value="<?=$r['id']?>" <?=$rep_filter===(int)$r['id']?'selected':''?>><?=htmlspecialchars($r['name'])?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">View</button>
  </form>
</div>

<?=$msg?>

<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Korosho Revenue</div><div class="stat-value"><?=tzs($totals['revenue'])?></div><div class="stat-sub">Separate from Z-Series revenue</div><div class="stat-icon">🥜</div></div>
  <?php if ($is_admin): ?>
  <div class="stat-card blue"><div class="stat-label">Profit</div><div class="stat-value"><?=tzs($totals['profit'])?></div><div class="stat-sub">Selling price − buying price</div><div class="stat-icon">💹</div></div>
  <?php endif; ?>
</div>

<div class="grid-3-1">
  <div class="card"><div class="card-header"><span class="card-title"><?=htmlspecialchars($chart_title)?></span></div>
  <div class="card-body dashboard-chart-body reports-chart-body"><canvas id="koroshoChart" height="200"></canvas></div></div>
  <div class="card"><div class="card-header"><span class="card-title">By Product</span></div>
  <div class="card-body dashboard-chart-body reports-chart-body"><canvas id="productChart" height="200"></canvas></div></div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">By Product — <?=htmlspecialchars($range_label)?></span></div>
  <div class="table-wrap reports-table-wrap"><table>
    <thead><tr><th>Product</th><th>Units Sold</th><th>Revenue</th><?php if ($is_admin): ?><th>Buying Cost</th><th>Profit</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($product_breakdown_rows as $row): ?>
      <tr>
        <td class="td-bold"><?=htmlspecialchars($row['product_name'])?></td>
        <td><?=number_format($row['qty'])?> kg</td>
        <td class="text-success"><?=tzs($row['revenue'])?></td>
        <?php if ($is_admin): ?>
        <td class="text-muted"><?=tzs($row['cost'])?></td>
        <td class="<?=$row['profit']>=0?'text-success':'text-danger'?>"><?=tzs($row['profit'])?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$product_breakdown_rows): ?>
      <tr><td colspan="<?=$is_admin?5:3?>" style="text-align:center;padding:22px;color:var(--text3)">No sales recorded for this period</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">By Sales Rep — <?=htmlspecialchars($range_label)?></span></div>
  <div class="table-wrap reports-table-wrap"><table>
    <thead><tr><th>Sales Rep</th><th>Kg Sold</th><th>Sales</th><th>Revenue</th><?php if ($is_admin): ?><th>Profit</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rep_breakdown_rows as $row): ?>
      <tr>
        <td class="td-bold"><a href="?<?=http_build_query(array_merge($_GET, ['sales_rep_id' => $row['id']]))?>"><?=htmlspecialchars($row['name'])?></a></td>
        <td><?=number_format($row['kg'])?> kg</td>
        <td><?=number_format($row['cnt'])?></td>
        <td class="text-success"><?=tzs($row['revenue'])?></td>
        <?php if ($is_admin): ?>
        <td class="<?=$row['profit']>=0?'text-success':'text-danger'?>"><?=tzs($row['profit'])?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rep_breakdown_rows): ?>
      <tr><td colspan="<?=$is_admin?5:4?>" style="text-align:center;padding:22px;color:var(--text3)">No employees yet — add one in <a href="korosho_employees.php">Employees</a>.</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">Buying/Selling Ledger — <?=htmlspecialchars($range_label)?><?=$rep_filter>0 && isset($rep_names_by_id[$rep_filter])?' — '.htmlspecialchars($rep_names_by_id[$rep_filter]):''?></span></div>
  <div class="table-wrap reports-table-wrap"><table>
    <thead><tr><th>Date</th><th>Kg</th><?php if ($is_admin): ?><th>Buying Price</th><?php endif; ?><th>Selling Price</th><?php if ($is_admin): ?><th>Profit</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($ledger_rows as $row): ?>
      <tr>
        <td><?=date('M d, Y', strtotime($row['sale_date']))?></td>
        <td><?=number_format($row['kg'])?> kg</td>
        <?php if ($is_admin): ?><td class="text-muted"><?=tzs($row['buying'])?></td><?php endif; ?>
        <td class="text-success"><?=tzs($row['selling'])?></td>
        <?php if ($is_admin): ?><td class="<?=$row['profit']>=0?'text-success':'text-danger'?>"><?=tzs($row['profit'])?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ledger_rows): ?>
      <tr><td colspan="<?=$is_admin?5:3?>" style="text-align:center;padding:22px;color:var(--text3)">No sales recorded for this period</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">Recent Sales — <?=htmlspecialchars($range_label)?></span></div>
  <div class="table-wrap reports-table-wrap"><table>
    <thead><tr><th>Receipt</th><th>Customer</th><th>Sales Rep</th><th>Items</th><th>Total</th><th>Payment</th><th>Cashier</th><th>Time</th><th>Status</th><?php if($is_admin):?><th>Action</th><?php endif;?></tr></thead>
    <tbody>
    <?php $i=0; foreach ($recent_sales as $s): $i++; $voided = $s['status']==='cancelled'; ?>
      <tr <?=$voided?'style="opacity:0.55"':''?>>
        <td class="td-bold"><?=htmlspecialchars($s['receipt_number'])?></td>
        <td><?=htmlspecialchars($s['customer_name'] ?? 'Walk-in')?></td>
        <td class="text-muted"><?=htmlspecialchars($s['sales_rep_name'] ?? '—')?></td>
        <td><?=number_format($s['item_count'])?></td>
        <td class="text-success"><?=tzs($s['total'])?></td>
        <td><?=ucfirst(htmlspecialchars($s['payment_method']))?></td>
        <td class="text-muted"><?=htmlspecialchars($s['cashier_name'] ?? '—')?></td>
        <td class="text-muted"><?=date('M d, H:i', strtotime($s['created_at']))?></td>
        <td><span class="badge badge-<?=$voided?'danger':'success'?>"><?=$voided?'Voided':'Completed'?></span></td>
        <?php if ($is_admin): ?>
        <td><?php if (!$voided): ?>
          <form method="POST" style="margin:0" data-confirm="Void receipt <?=htmlspecialchars($s['receipt_number'], ENT_QUOTES)?>? This restocks the sold items and removes it from revenue totals.">
            <input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?=$s['id']?>"><?=csrfInput()?>
            <button type="submit" class="btn btn-danger btn-sm">Void</button>
          </form>
        <?php endif; ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; if ($i===0): ?>
      <tr><td colspan="<?=$is_admin?10:9?>" style="text-align:center;padding:22px;color:var(--text3)">No sales recorded for this period</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php
$extra_js = <<<JS
<script>
new Chart(document.getElementById('koroshoChart'),{type:'bar',data:{labels:$chart_labels,datasets:[{label:'Revenue',data:$chart_revenues,backgroundColor:'rgba(124,58,237,0.7)',borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6B5F8A'}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6B5F8A'}}}}});
new Chart(document.getElementById('productChart'),{type:'doughnut',data:{labels:$product_labels,datasets:[{data:$product_totals,backgroundColor:['#7C3AED','#10B981','#F59E0B','#3B82F6','#EF4444'],borderWidth:0}]},options:{responsive:true,cutout:'60%',plugins:{legend:{position:'bottom',labels:{color:'#A89DC4',padding:12,font:{size:11}}}}}});

function togglePeriodInputs(val){
  document.getElementById('period-input-daily').style.display = val==='daily' ? '' : 'none';
  document.getElementById('period-input-weekly').style.display = val==='weekly' ? '' : 'none';
  document.getElementById('period-input-monthly').style.display = val==='monthly' ? '' : 'none';
}
</script>
JS;
require_once 'includes/footer.php';
?>
