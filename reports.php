<?php
$page_title = 'Reports';
$content_class = 'content premium-content reports-page';
require_once 'includes/header.php';
requirePrivilege('reports');
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$month_start = $month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));

$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='completed'");
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$revenue = $stmt->get_result()->fetch_assoc()['v'];

$stmt = $conn->prepare("SELECT COUNT(*) as v FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='completed'");
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_assoc()['v'];

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) as v FROM expenses WHERE expense_date BETWEEN ? AND ?");
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_assoc()['v'];
$profit      = $revenue - $expenses;

// Daily revenue for chart
$daily = [];
$d = new DateTime($month_start);
$end = new DateTime($month_end);
while ($d <= $end) {
    $date = $d->format('Y-m-d');
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM sales WHERE DATE(created_at)=? AND status='completed'");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc()['v'];
    $daily[] = ['date' => $d->format('d'), 'revenue' => $r];
    $d->modify('+1 day');
}

// Category revenue
$stmt = $conn->prepare("SELECT c.name, COALESCE(SUM(si.total),0) as total FROM categories c LEFT JOIN products p ON c.id=p.category_id LEFT JOIN sale_items si ON p.id=si.product_id LEFT JOIN sales s ON si.sale_id=s.id AND s.status='completed' AND DATE(s.created_at) BETWEEN ? AND ? GROUP BY c.id, c.name");
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$cat_rev = $stmt->get_result();

// Payment method breakdown
$stmt = $conn->prepare("SELECT payment_method, COUNT(*) as cnt, SUM(total) as total FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='completed' GROUP BY payment_method");
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$pay_data = $stmt->get_result();

// Top products
$stmt = $conn->prepare("SELECT si.product_name, SUM(si.qty) as total_qty, SUM(si.total) as total_revenue FROM sale_items si JOIN sales s ON si.sale_id=s.id WHERE s.status='completed' AND DATE(s.created_at) BETWEEN ? AND ? GROUP BY si.product_name ORDER BY total_revenue DESC LIMIT 10");
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$top_products = $stmt->get_result();

$daily_labels   = json_encode(array_column($daily, 'date'));
$daily_revenues = json_encode(array_column($daily, 'revenue'));

$cat_rows = []; while($r=$cat_rev->fetch_assoc()) $cat_rows[]=$r;
$cat_labels  = json_encode(array_column($cat_rows,'name'));
$cat_totals  = json_encode(array_column($cat_rows,'total'));

$pay_rows = []; while($r=$pay_data->fetch_assoc()) $pay_rows[]=$r;
$pay_labels = json_encode(array_column($pay_rows,'payment_method'));
$pay_totals = json_encode(array_column($pay_rows,'total'));
?>
<div style="margin-bottom:16px">
  <form method="GET" style="display:flex;gap:10px;align-items:center">
    <label style="font-size:12px;color:var(--text2)">Month:</label>
    <input type="month" name="month" value="<?=htmlspecialchars($month)?>" class="form-control" style="width:180px"/>
    <button type="submit" class="btn btn-primary">View Report</button>
  </form>
</div>
<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Total Revenue</div><div class="stat-value"><?=tzs($revenue)?></div><div class="stat-icon">💰</div></div>
  <div class="stat-card green"><div class="stat-label">Transactions</div><div class="stat-value"><?=$transactions?></div><div class="stat-icon">🛒</div></div>
  <div class="stat-card red"><div class="stat-label">Total Expenses</div><div class="stat-value"><?=tzs($expenses)?></div><div class="stat-icon">💸</div></div>
  <div class="stat-card <?=$profit>=0?'green':'red'?>"><div class="stat-label">Net Profit</div><div class="stat-value"><?=tzs(abs($profit))?></div><div class="stat-sub"><?=$profit<0?'(Loss)':'Profit'?></div><div class="stat-icon">📈</div></div>
</div>
<div class="grid-3-1">
  <div class="card"><div class="card-header"><span class="card-title">Daily Revenue — <?=date('F Y',strtotime($month_start))?></span></div>
  <div class="card-body dashboard-chart-body reports-chart-body"><canvas id="dailyChart" height="200"></canvas></div></div>
  <div class="card"><div class="card-header"><span class="card-title">By Category</span></div>
  <div class="card-body dashboard-chart-body reports-chart-body"><canvas id="catChart" height="200"></canvas></div></div>
</div>
<div class="grid-2">
  <div class="card"><div class="card-header"><span class="card-title">Payment Methods</span></div>
  <div class="card-body dashboard-chart-body reports-chart-body"><canvas id="payChart" height="200"></canvas></div></div>
  <div class="card"><div class="card-header"><span class="card-title">Top Selling Products</span></div>
  <div class="table-wrap reports-table-wrap"><table><thead><tr><th>#</th><th>Product</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
  <tbody><?php $i=1; while($p=$top_products->fetch_assoc()):?><tr><td class="text-muted"><?=$i++?></td><td class="td-bold"><?=htmlspecialchars($p['product_name'])?></td><td><?=number_format($p['total_qty'])?></td><td class="text-success"><?=tzs($p['total_revenue'])?></td></tr><?php endwhile;?></tbody></table></div></div>
</div>
<?php
$extra_js = <<<JS
<script>
new Chart(document.getElementById('dailyChart'),{type:'bar',data:{labels:$daily_labels,datasets:[{label:'Revenue',data:$daily_revenues,backgroundColor:'rgba(124,58,237,0.7)',borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6B5F8A'}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6B5F8A'}}}}});
new Chart(document.getElementById('catChart'),{type:'doughnut',data:{labels:$cat_labels,datasets:[{data:$cat_totals,backgroundColor:['#7C3AED','#10B981','#F59E0B','#3B82F6'],borderWidth:0}]},options:{responsive:true,cutout:'60%',plugins:{legend:{position:'bottom',labels:{color:'#A89DC4',padding:12,font:{size:11}}}}}});
new Chart(document.getElementById('payChart'),{type:'doughnut',data:{labels:$pay_labels,datasets:[{data:$pay_totals,backgroundColor:['#F59E0B','#10B981','#EF4444','#3B82F6'],borderWidth:0}]},options:{responsive:true,cutout:'60%',plugins:{legend:{position:'bottom',labels:{color:'#A89DC4',padding:12,font:{size:11}}}}}});
</script>
JS;
require_once 'includes/footer.php';
?>
