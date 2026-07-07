<?php
$page_title = 'Dashboard';
$topbar_buttons = '<a href="pos.php" class="btn-topbar">New Sale</a><a href="inventory.php?action=add" class="btn-topbar primary">Add Product</a>';
require_once 'includes/header.php';
requirePrivilege('dashboard');

// Stats
$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));

$today_revenue = $conn->query("SELECT COALESCE(SUM(total),0) as v FROM sales WHERE DATE(created_at)='$today' AND status='completed'")->fetch_assoc()['v'];
$today_sales   = $conn->query("SELECT COUNT(*) as v FROM sales WHERE DATE(created_at)='$today' AND status='completed'")->fetch_assoc()['v'];
$low_stock     = $conn->query("SELECT COUNT(*) as v FROM products WHERE stock <= low_stock_threshold AND status='active'")->fetch_assoc()['v'];
$total_debt    = $conn->query("SELECT COALESCE(SUM(balance),0) as v FROM debts WHERE status != 'cleared'")->fetch_assoc()['v'];
$total_customers = $conn->query("SELECT COUNT(*) as v FROM customers WHERE status = 'active'")->fetch_assoc()['v'];
$total_products  = $conn->query("SELECT COUNT(*) as v FROM products WHERE status='active'")->fetch_assoc()['v'];

// Recent sales
$recent_sales = $conn->query("SELECT s.*, u.name as cashier_name FROM sales s LEFT JOIN users u ON s.cashier_id=u.id ORDER BY s.created_at DESC LIMIT 8");

// Weekly revenue for chart
$week_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $r = $conn->query("SELECT COALESCE(SUM(total),0) as v FROM sales WHERE DATE(created_at)='$d' AND status='completed'")->fetch_assoc()['v'];
    $week_data[] = ['date' => date('D', strtotime($d)), 'revenue' => $r];
}

// Category sales
$cat_sales = $conn->query("SELECT c.name, COALESCE(SUM(si.total),0) as total FROM categories c LEFT JOIN products p ON c.id=p.category_id LEFT JOIN sale_items si ON p.id=si.product_id LEFT JOIN sales s ON si.sale_id=s.id AND s.status='completed' GROUP BY c.id,c.name");

// Low stock products
$low_products = $conn->query("SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.stock <= p.low_stock_threshold AND p.status='active' LIMIT 5");
?>

<div class="dash-hero">
  <div class="dash-hero-title">Welcome back, <?= htmlspecialchars($_SESSION['user_name']) ?>.</div>
  <div class="dash-hero-sub">A clear view of what matters today.</div>
  <div class="dash-hero-date"><?= date('l, d F Y') ?></div>
</div>

<div class="stats-grid">
  <div class="stat-card purple">
    <div class="stat-label">Today's Revenue</div>
    <div class="stat-value"><?= tzs($today_revenue) ?></div>
    <div class="stat-sub">Sales completed today</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Today's Transactions</div>
    <div class="stat-value"><?= $today_sales ?></div>
    <div class="stat-sub">Receipts issued today</div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Low Stock Items</div>
    <div class="stat-value"><?= $low_stock ?></div>
    <div class="stat-sub"><a href="inventory.php" class="stat-link warning">Review inventory</a></div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">Total Outstanding Debts</div>
    <div class="stat-value"><?= tzs($total_debt) ?></div>
    <div class="stat-sub"><a href="debts.php" class="stat-link danger">View debtors</a></div>
  </div>
</div>

<div class="grid-3-1">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Sales This Week (TZS)</span>
      <a href="reports.php" class="card-action">Full Report</a>
    </div>
    <div class="card-body dashboard-chart-body">
      <canvas id="weekChart" height="200"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Sales by Category</span></div>
    <div class="card-body dashboard-chart-body">
      <canvas id="catChart" height="200"></canvas>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Recent Transactions</span>
      <a href="receipts.php" class="card-action">View All</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Receipt</th><th>Customer</th><th>Amount</th><th>Payment</th><th>Type</th><th>Time</th></tr></thead>
        <tbody>
          <?php while($s = $recent_sales->fetch_assoc()): ?>
          <tr>
            <td class="text-purple"><?= htmlspecialchars($s['receipt_number']) ?></td>
            <td class="td-bold"><?= htmlspecialchars($s['customer_name']) ?></td>
            <td class="text-success"><?= tzs($s['total']) ?></td>
            <td><span class="badge badge-<?= $s['payment_method']==='mpesa'?'success':($s['payment_method']==='debt'?'danger':'warning') ?>"><?= ucfirst($s['payment_method']) ?></span></td>
            <td><span class="badge badge-<?= $s['price_type']==='jumla'?'info':'purple' ?>"><?= ucfirst($s['price_type']) ?></span></td>
            <td class="text-muted"><?= date('H:i', strtotime($s['created_at'])) ?></td>
          </tr>
          <?php endwhile; ?>
          <?php if ($recent_sales->num_rows === 0): ?>
          <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text3)">No sales today yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <span class="card-title">Low Stock Alert</span>
      <a href="inventory.php" class="card-action">View All</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Product</th><th>Category</th><th>Stock</th><th>Action</th></tr></thead>
        <tbody>
          <?php while($p = $low_products->fetch_assoc()): ?>
          <tr>
            <td class="td-bold"><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars($p['cat_name']) ?></td>
            <td class="<?= $p['stock']==0?'text-danger':'text-warning' ?>"><?= $p['stock'] ?> <?= $p['unit'] ?></td>
            <td><a href="purchase_orders.php?action=add&product=<?= $p['id'] ?>" class="btn btn-primary btn-sm">Restock</a></td>
          </tr>
          <?php endwhile; ?>
          <?php if ($low_products->num_rows === 0): ?>
          <tr><td colspan="4" style="text-align:center;padding:20px;color:var(--text3)">All products well stocked</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$week_labels   = json_encode(array_column($week_data, 'date'));
$week_revenues = json_encode(array_column($week_data, 'revenue'));
$cat_rows = []; while($r = $cat_sales->fetch_assoc()) $cat_rows[] = $r;
$cat_labels = json_encode(array_column($cat_rows, 'name'));
$cat_totals = json_encode(array_column($cat_rows, 'total'));
$extra_js = <<<JS
<script>
new Chart(document.getElementById('weekChart'), {
  type: 'bar',
  data: {
    labels: $week_labels,
    datasets: [{label:'Revenue',data:$week_revenues,backgroundColor:'rgba(124,58,237,0.7)',borderRadius:6}]
  },
  options: {responsive:true,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6B5F8A'}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6B5F8A',callback:v=>v>=1000?'TZS '+(v/1000)+'K':v}}}}
});
new Chart(document.getElementById('catChart'), {
  type: 'doughnut',
  data: {labels:$cat_labels, datasets:[{data:$cat_totals, backgroundColor:['#7C3AED','#10B981','#F59E0B','#3B82F6'], borderWidth:0, hoverOffset:4}]},
  options: {responsive:true, cutout:'65%', plugins:{legend:{position:'bottom',labels:{color:'#A89DC4',padding:14,font:{size:11}}}}}
});
</script>
JS;
require_once 'includes/footer.php';
?>
