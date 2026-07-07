<?php
$page_title = 'Sales Rep Report';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('reports');

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$month_start = $month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));
$rep_filter  = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : 0;

// All employees who have sales (for filter dropdown)
$all_reps_res = $conn->query("SELECT DISTINCT e.id, e.name FROM employees e JOIN sales s ON s.sales_rep_id=e.id ORDER BY e.name");
$all_reps = $all_reps_res ? $all_reps_res->fetch_all(MYSQLI_ASSOC) : [];

// Sales rep performance summary for the month
$stmt = $conn->prepare("
    SELECT e.id, e.name, e.role,
           COUNT(s.id) as tx_count,
           COALESCE(SUM(s.total), 0) as total_revenue,
           COALESCE(AVG(s.total), 0) as avg_sale
    FROM employees e
    JOIN sales s ON s.sales_rep_id = e.id
    WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY e.id, e.name, e.role
    ORDER BY total_revenue DESC
");
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$rep_summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total_tx  = array_sum(array_column($rep_summary, 'tx_count'));
$total_rev = array_sum(array_column($rep_summary, 'total_revenue'));

// Detailed sales filtered by rep if selected
$params = [$month_start, $month_end];
$types  = 'ss';
$rep_sql = '';
if ($rep_filter > 0) {
    $rep_sql = ' AND s.sales_rep_id = ?';
    $params[] = $rep_filter;
    $types .= 'i';
}

$stmt = $conn->prepare("
    SELECT s.id, s.receipt_number, s.customer_name, s.price_type,
           s.total, s.discount, s.payment_method,
           DATE_FORMAT(s.created_at, '%d %b %Y %H:%i') as sale_date,
           e.name as rep_name,
           u.name as cashier_name
    FROM sales s
    LEFT JOIN employees e ON s.sales_rep_id = e.id
    LEFT JOIN users u ON s.cashier_id = u.id
    WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
    AND s.sales_rep_id IS NOT NULL
    $rep_sql
    ORDER BY s.created_at DESC
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$sales_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all sale items for displayed sales in one query
$items_by_sale = [];
if (!empty($sales_rows)) {
    $ids = array_column($sales_rows, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st2 = $conn->prepare("SELECT sale_id, product_name, qty, unit_price, total FROM sale_items WHERE sale_id IN ($ph) ORDER BY id");
    $st2->bind_param(str_repeat('i', count($ids)), ...$ids);
    $st2->execute();
    $ir = $st2->get_result();
    while ($item = $ir->fetch_assoc()) {
        $items_by_sale[$item['sale_id']][] = $item;
    }
}

// Selected rep name for heading
$selected_rep_name = '';
if ($rep_filter > 0) {
    foreach ($rep_summary as $r) {
        if ($r['id'] === $rep_filter) { $selected_rep_name = $r['name']; break; }
    }
    if (!$selected_rep_name) {
        foreach ($all_reps as $r) {
            if ($r['id'] === $rep_filter) { $selected_rep_name = $r['name']; break; }
        }
    }
}

$pay_colors = [
    'cash' => '#10B981',
    'lipa' => '#3B82F6',
    'bank' => '#8B5CF6',
    'debt' => '#F59E0B',
];
?>

<!-- Filters -->
<div style="margin-bottom:16px" class="no-print">
  <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <label style="font-size:12px;color:var(--text2)">Month:</label>
    <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" class="form-control" style="width:180px"/>
    <label style="font-size:12px;color:var(--text2)">Sales Rep:</label>
    <select name="rep_id" class="form-control" style="width:220px">
      <option value="0">All Sales Reps</option>
      <?php foreach ($all_reps as $r): ?>
        <option value="<?= $r['id'] ?>" <?= $rep_filter === $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">View</button>
    <button type="button" class="btn btn-outline" onclick="window.print()">Print / PDF</button>
  </form>
</div>

<!-- Summary stats -->
<div class="stats-grid" style="margin-bottom:16px">
  <div class="stat-card purple">
    <div class="stat-label">Active Sales Reps</div>
    <div class="stat-value"><?= count($rep_summary) ?></div>
    <div class="stat-icon"></div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Total Transactions</div>
    <div class="stat-value"><?= number_format($total_tx) ?></div>
    <div class="stat-icon"></div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Total Revenue</div>
    <div class="stat-value"><?= tzs($total_rev) ?></div>
    <div class="stat-icon"></div>
  </div>
</div>

<!-- Sales Rep Leaderboard -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header">
    <span class="card-title">Sales Rep Performance — <?= date('F Y', strtotime($month_start)) ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Sales Rep</th>
          <th>Role / Position</th>
          <th>Transactions</th>
          <th>Total Revenue</th>
          <th>Avg / Sale</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rep_summary)): ?>
          <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No sales rep sales recorded this month. Make sure to select a Sales Rep when recording a sale in POS.</td></tr>
        <?php else: ?>
          <?php foreach ($rep_summary as $i => $r): ?>
            <tr <?= $rep_filter === $r['id'] ? 'style="background:rgba(124,58,237,0.08)"' : '' ?>>
              <td class="text-muted"><?= $i + 1 ?></td>
              <td class="td-bold">
                <div style="display:flex;align-items:center;gap:10px">
                  <div style="width:34px;height:34px;border-radius:50%;background:var(--purple);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0">
                    <?= strtoupper(substr($r['name'], 0, 1)) ?>
                  </div>
                  <?= htmlspecialchars($r['name']) ?>
                </div>
              </td>
              <td style="color:var(--text2);font-size:12px"><?= htmlspecialchars($r['role']) ?></td>
              <td><?= number_format($r['tx_count']) ?></td>
              <td class="text-success td-bold"><?= tzs($r['total_revenue']) ?></td>
              <td class="text-muted"><?= tzs($r['avg_sale']) ?></td>
              <td>
                <?php if ($rep_filter === $r['id']): ?>
                  <a href="?month=<?= htmlspecialchars($month) ?>" class="btn btn-sm btn-primary">Showing</a>
                <?php else: ?>
                  <a href="?month=<?= htmlspecialchars($month) ?>&rep_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline">View Sales</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Detailed Sales Table -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;gap:10px">
    <span class="card-title">
      <?php if ($rep_filter > 0 && $selected_rep_name): ?>
        Sales by <?= htmlspecialchars($selected_rep_name) ?> — <?= date('F Y', strtotime($month_start)) ?>
      <?php else: ?>
        All Sales Rep Transactions — <?= date('F Y', strtotime($month_start)) ?>
      <?php endif; ?>
      <span style="font-weight:400;font-size:12px;color:var(--text3);margin-left:6px">(<?= count($sales_rows) ?> transactions)</span>
    </span>
    <?php if ($rep_filter > 0): ?>
      <a href="?month=<?= htmlspecialchars($month) ?>" class="btn btn-sm btn-outline" style="margin-left:auto">Show All Reps</a>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date & Time</th>
          <th>Receipt</th>
          <th>Sales Rep</th>
          <th>Customer</th>
          <th>Products Sold</th>
          <th>Payment</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sales_rows)): ?>
          <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text3)">No transactions found for this period</td></tr>
        <?php else: ?>
          <?php foreach ($sales_rows as $sale):
            $items = $items_by_sale[$sale['id']] ?? [];
            $pay_color = $pay_colors[$sale['payment_method']] ?? '#6B7280';
          ?>
            <tr>
              <td style="white-space:nowrap;font-size:12px;color:var(--text2)"><?= $sale['sale_date'] ?></td>
              <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($sale['receipt_number']) ?></td>
              <td class="td-bold">
                <div style="display:flex;align-items:center;gap:8px">
                  <div style="width:28px;height:28px;border-radius:50%;background:var(--purple);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:11px;flex-shrink:0">
                    <?= strtoupper(substr($sale['rep_name'] ?? '?', 0, 1)) ?>
                  </div>
                  <?= htmlspecialchars($sale['rep_name'] ?? '—') ?>
                </div>
              </td>
              <td><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in') ?></td>
              <td style="font-size:12px;color:var(--text2);max-width:260px">
                <?php if (empty($items)): ?>
                  <span style="color:var(--text3)">—</span>
                <?php else: ?>
                  <?php foreach ($items as $item): ?>
                    <div style="margin-bottom:2px">
                      <span style="color:var(--text1)"><?= htmlspecialchars($item['product_name']) ?></span>
                      <span style="color:var(--text3)"> × <?= $item['qty'] ?></span>
                      <span style="color:var(--text2);font-size:11px"> @ <?= tzs($item['unit_price']) ?></span>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td>
                <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;background:<?= $pay_color ?>22;color:<?= $pay_color ?>">
                  <?= ucfirst($sale['payment_method']) ?>
                </span>
              </td>
              <td class="text-success td-bold" style="white-space:nowrap"><?= tzs($sale['total']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
