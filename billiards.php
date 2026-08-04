<?php
$page_title = 'Uzunguni Billiards';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('billiards');
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$msg = '';
if (!empty($_SESSION['billiards_flash'])) {
  $msg = $_SESSION['billiards_flash'];
  unset($_SESSION['billiards_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
  $action = $_POST['action'] ?? '';

  if ($action === 'sell') {
    $branch_id = sanitizeInt($_POST['branch_id'] ?? 0);
    $tokens    = sanitizeInt($_POST['tokens'] ?? 0);
    $method    = sanitizeString($_POST['payment_method'] ?? 'cash', 20);
    $notes     = sanitizeString($_POST['notes'] ?? '', 500);
    if (!in_array($method, ['cash', 'lipa', 'bank'], true)) $method = 'cash';

    if ($tokens <= 0) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Number of tokens must be greater than zero.</div>';
    } else {
      $stmt = $conn->prepare("SELECT * FROM billiard_branches WHERE id = ? AND status='active'");
      $stmt->bind_param('i', $branch_id);
      $stmt->execute();
      $branch = $stmt->get_result()->fetch_assoc();
      if (!$branch) {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Branch not found or inactive.</div>';
      } else {
        $rate = (float)$branch['token_rate'];
        $total = $rate * $tokens;
        $cashier_id = $_SESSION['user_id'];
        $conn->begin_transaction();
        try {
          $temp_receipt = 'TMP-' . bin2hex(random_bytes(6));
          $stmt = $conn->prepare("INSERT INTO billiard_sales (receipt_number, branch_id, tokens, rate_per_token, total, payment_method, cashier_id, notes) VALUES (?,?,?,?,?,?,?,?)");
          $stmt->bind_param('siidssis', $temp_receipt, $branch_id, $tokens, $rate, $total, $method, $cashier_id, $notes);
          $stmt->execute();
          $sale_id = $conn->insert_id;
          $receipt_number = 'BIL-' . str_pad((string)$sale_id, 6, '0', STR_PAD_LEFT);
          $stmt_up = $conn->prepare("UPDATE billiard_sales SET receipt_number = ? WHERE id = ?");
          $stmt_up->bind_param('si', $receipt_number, $sale_id);
          $stmt_up->execute();
          $conn->commit();
          logActivity($conn, "Billiards sale: {$branch['name']} — $tokens token(s) — " . number_format($total) . " TZS ($receipt_number)", 'sale');
          $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Sale recorded! Receipt ' . htmlspecialchars($receipt_number) . ' — ' . tzs($total) . '</div>';
        } catch (Exception $e) {
          $conn->rollback();
          $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">' . htmlspecialchars($e->getMessage()) . '</div>';
        }
      }
    }
  }

  if ($action === 'void') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can void a sale.</div>';
    } else {
      $sale_id = sanitizeInt($_POST['id'] ?? 0);
      $stmt = $conn->prepare("UPDATE billiard_sales SET status='cancelled' WHERE id = ? AND status='completed'");
      $stmt->bind_param('i', $sale_id);
      $stmt->execute();
      if ($stmt->affected_rows > 0) {
        logActivity($conn, "Billiards sale voided: #$sale_id", 'sale');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Sale voided.</div>';
      } else {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Sale not found or already voided.</div>';
      }
    }
  }

  if ($action === 'purge') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can permanently delete a sale.</div>';
    } else {
      $sale_id = sanitizeInt($_POST['id'] ?? 0);
      $stmt = $conn->prepare("SELECT receipt_number FROM billiard_sales WHERE id = ? AND status='cancelled'");
      $stmt->bind_param('i', $sale_id);
      $stmt->execute();
      $sale = $stmt->get_result()->fetch_assoc();
      if (!$sale) {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Sale not found, or still active — void it first before deleting.</div>';
      } else {
        $stmt = $conn->prepare("DELETE FROM billiard_sales WHERE id = ? AND status='cancelled'");
        $stmt->bind_param('i', $sale_id);
        $stmt->execute();
        logActivity($conn, "Billiards sale permanently deleted: " . $sale['receipt_number'], 'sale');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Sale permanently deleted.</div>';
      }
    }
  }

  if ($action === 'add_branch') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can add branches.</div>';
    } else {
      $name = sanitizeString($_POST['name'] ?? '', 100);
      $location = sanitizeString($_POST['location'] ?? '', 200);
      $rate = sanitizeFloat($_POST['token_rate'] ?? 500);
      if ($name === '' || $rate <= 0) {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Branch name and a positive token rate are required.</div>';
      } else {
        $stmt = $conn->prepare("INSERT INTO billiard_branches (name, location, token_rate) VALUES (?,?,?)");
        $stmt->bind_param('ssd', $name, $location, $rate);
        $stmt->execute();
        logActivity($conn, "Billiards branch added: $name", 'system');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Branch added!</div>';
      }
    }
  }

  if ($action === 'edit_branch') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can edit branches.</div>';
    } else {
      $id = sanitizeInt($_POST['id'] ?? 0);
      $name = sanitizeString($_POST['name'] ?? '', 100);
      $location = sanitizeString($_POST['location'] ?? '', 200);
      $rate = sanitizeFloat($_POST['token_rate'] ?? 500);
      $status = sanitizeString($_POST['status'] ?? 'active', 20);
      if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
      if ($name === '' || $rate <= 0) {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Branch name and a positive token rate are required.</div>';
      } else {
        $stmt = $conn->prepare("UPDATE billiard_branches SET name=?, location=?, token_rate=?, status=? WHERE id=?");
        $stmt->bind_param('ssdsi', $name, $location, $rate, $status, $id);
        $stmt->execute();
        logActivity($conn, "Billiards branch updated: $name", 'system');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Branch updated!</div>';
      }
    }
  }

  // POST/redirect/GET: send the browser to a plain GET after processing, so a
  // page refresh replays the GET instead of resubmitting the form (which was
  // duplicating token sales on refresh).
  $_SESSION['billiards_flash'] = $msg;
  $redirect_qs = $_GET ? ('?' . http_build_query($_GET)) : '';
  header('Location: billiards.php' . $redirect_qs);
  exit;
}

$branches = $conn->query("SELECT * FROM billiard_branches ORDER BY name");
$branch_rows = [];
while ($b = $branches->fetch_assoc()) $branch_rows[] = $b;

$period_type = $_GET['period_type'] ?? 'daily';
if (!in_array($period_type, ['daily', 'weekly', 'monthly'], true)) $period_type = 'daily';

$day = $_GET['day'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');

$week = $_GET['week'] ?? (date('o') . '-W' . date('W'));
if (!preg_match('/^\d{4}-W\d{2}$/', $week)) $week = date('o') . '-W' . date('W');

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

$branch_filter = sanitizeInt($_GET['branch_id'] ?? 0);

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

if ($branch_filter > 0) {
  $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as revenue, COALESCE(SUM(tokens),0) as tokens, COUNT(*) as cnt FROM billiard_sales WHERE branch_id=? AND status='completed' AND DATE(created_at) BETWEEN ? AND ?");
  $stmt->bind_param('iss', $branch_filter, $range_start, $range_end);
} else {
  $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as revenue, COALESCE(SUM(tokens),0) as tokens, COUNT(*) as cnt FROM billiard_sales WHERE status='completed' AND DATE(created_at) BETWEEN ? AND ?");
  $stmt->bind_param('ss', $range_start, $range_end);
}
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();

// Revenue chart — hourly for a single day, daily for a week/month
$chart_points = [];
if ($period_type === 'daily') {
  for ($h = 0; $h < 24; $h++) {
    if ($branch_filter > 0) {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM billiard_sales WHERE branch_id=? AND DATE(created_at)=? AND HOUR(created_at)=? AND status='completed'");
      $stmt->bind_param('isi', $branch_filter, $range_start, $h);
    } else {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM billiard_sales WHERE DATE(created_at)=? AND HOUR(created_at)=? AND status='completed'");
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
    if ($branch_filter > 0) {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM billiard_sales WHERE branch_id=? AND DATE(created_at)=? AND status='completed'");
      $stmt->bind_param('is', $branch_filter, $date);
    } else {
      $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) as v FROM billiard_sales WHERE DATE(created_at)=? AND status='completed'");
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

// Per-branch breakdown for the period (always all branches, regardless of filter)
$stmt = $conn->prepare("SELECT bb.id, bb.name, bb.token_rate, bb.status, COALESCE(SUM(bs.total),0) as revenue, COALESCE(SUM(bs.tokens),0) as tokens, COUNT(bs.id) as cnt FROM billiard_branches bb LEFT JOIN billiard_sales bs ON bs.branch_id=bb.id AND bs.status='completed' AND DATE(bs.created_at) BETWEEN ? AND ? GROUP BY bb.id, bb.name, bb.token_rate, bb.status ORDER BY bb.name");
$stmt->bind_param('ss', $range_start, $range_end);
$stmt->execute();
$branch_breakdown = $stmt->get_result();
$branch_breakdown_rows = [];
while ($row = $branch_breakdown->fetch_assoc()) $branch_breakdown_rows[] = $row;
$branch_labels = json_encode(array_column($branch_breakdown_rows, 'name'));
$branch_totals = json_encode(array_column($branch_breakdown_rows, 'revenue'));

// Recent sales for the period
if ($branch_filter > 0) {
  $stmt = $conn->prepare("SELECT bs.*, bb.name as branch_name, u.name as cashier_name FROM billiard_sales bs JOIN billiard_branches bb ON bs.branch_id=bb.id LEFT JOIN users u ON bs.cashier_id=u.id WHERE bs.branch_id=? AND DATE(bs.created_at) BETWEEN ? AND ? ORDER BY bs.created_at DESC LIMIT 100");
  $stmt->bind_param('iss', $branch_filter, $range_start, $range_end);
} else {
  $stmt = $conn->prepare("SELECT bs.*, bb.name as branch_name, u.name as cashier_name FROM billiard_sales bs JOIN billiard_branches bb ON bs.branch_id=bb.id LEFT JOIN users u ON bs.cashier_id=u.id WHERE DATE(bs.created_at) BETWEEN ? AND ? ORDER BY bs.created_at DESC LIMIT 100");
  $stmt->bind_param('ss', $range_start, $range_end);
}
$stmt->execute();
$recent_sales = $stmt->get_result();

$branch_rate_map = [];
foreach ($branch_rows as $b) $branch_rate_map[$b['id']] = (float)$b['token_rate'];
$branch_rates_json = json_encode($branch_rate_map);
?>
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
    <select name="branch_id" class="form-control" style="width:170px">
      <option value="0">All Branches</option>
      <?php foreach ($branch_rows as $b): ?>
        <option value="<?=$b['id']?>" <?=$branch_filter===(int)$b['id']?'selected':''?>><?=htmlspecialchars($b['name'])?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">View</button>
  </form>
</div>

<?=$msg?>

<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Billiards Revenue</div><div class="stat-value"><?=tzs($totals['revenue'])?></div><div class="stat-sub">Separate from Z-Series revenue</div><div class="stat-icon">🎱</div></div>
  <div class="stat-card green"><div class="stat-label">Tokens Sold</div><div class="stat-value"><?=number_format($totals['tokens'])?></div><div class="stat-icon"></div></div>
  <div class="stat-card amber"><div class="stat-label">Transactions</div><div class="stat-value"><?=number_format($totals['cnt'])?></div><div class="stat-icon"></div></div>
</div>

<div style="margin:14px 0;display:flex;gap:8px;flex-wrap:wrap">
  <button class="btn btn-primary" onclick="openModal('sell-modal')">+ Record Token Sale</button>
  <?php if ($is_admin): ?>
  <button class="btn btn-outline" onclick="openModal('add-branch-modal')">+ Add Branch</button>
  <?php endif; ?>
</div>

<div class="grid-3-1">
  <div class="card"><div class="card-header"><span class="card-title"><?=htmlspecialchars($chart_title)?></span></div>
  <div class="card-body dashboard-chart-body reports-chart-body"><canvas id="billiardsChart" height="200"></canvas></div></div>
  <div class="card"><div class="card-header"><span class="card-title">By Branch</span></div>
  <div class="card-body dashboard-chart-body reports-chart-body"><canvas id="branchChart" height="200"></canvas></div></div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">Branches — <?=htmlspecialchars($range_label)?></span></div>
  <div class="table-wrap reports-table-wrap"><table>
    <thead><tr><th>Branch</th><th>Rate/Token</th><th>Tokens Sold</th><th>Transactions</th><th>Revenue</th><th>Status</th><?php if($is_admin):?><th>Action</th><?php endif;?></tr></thead>
    <tbody>
    <?php foreach ($branch_breakdown_rows as $row): ?>
      <tr>
        <td class="td-bold"><?=htmlspecialchars($row['name'])?></td>
        <td><?=tzs($row['token_rate'])?></td>
        <td><?=number_format($row['tokens'])?></td>
        <td><?=number_format($row['cnt'])?></td>
        <td class="text-success"><?=tzs($row['revenue'])?></td>
        <td><span class="badge badge-<?=$row['status']==='active'?'success':'danger'?>"><?=ucfirst($row['status'])?></span></td>
        <?php if ($is_admin): ?>
        <td><button type="button" class="btn btn-outline btn-sm" onclick='openEditBranch(<?=json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Edit</button></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">Recent Sales — <?=htmlspecialchars($range_label)?></span></div>
  <div class="table-wrap reports-table-wrap"><table>
    <thead><tr><th>Receipt</th><th>Branch</th><th>Tokens</th><th>Rate</th><th>Total</th><th>Payment</th><th>Cashier</th><th>Time</th><th>Status</th><?php if($is_admin):?><th>Action</th><?php endif;?></tr></thead>
    <tbody>
    <?php $i=0; while ($s = $recent_sales->fetch_assoc()): $i++; $voided = $s['status']==='cancelled'; ?>
      <tr <?=$voided?'style="opacity:0.55"':''?>>
        <td class="td-bold"><?=htmlspecialchars($s['receipt_number'])?></td>
        <td><?=htmlspecialchars($s['branch_name'])?></td>
        <td><?=number_format($s['tokens'])?></td>
        <td><?=tzs($s['rate_per_token'])?></td>
        <td class="text-success"><?=tzs($s['total'])?></td>
        <td><?=ucfirst(htmlspecialchars($s['payment_method']))?></td>
        <td class="text-muted"><?=htmlspecialchars($s['cashier_name'] ?? '—')?></td>
        <td class="text-muted"><?=date('M d, H:i', strtotime($s['created_at']))?></td>
        <td><span class="badge badge-<?=$voided?'danger':'success'?>"><?=$voided?'Voided':'Completed'?></span></td>
        <?php if ($is_admin): ?>
        <td><?php if (!$voided): ?>
          <form method="POST" style="margin:0" data-confirm="Void receipt <?=htmlspecialchars($s['receipt_number'], ENT_QUOTES)?>? This removes it from revenue totals.">
            <input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?=$s['id']?>"><?=csrfInput()?>
            <button type="submit" class="btn btn-danger btn-sm">Void</button>
          </form>
        <?php else: ?>
          <form method="POST" style="margin:0" data-confirm="Permanently delete receipt <?=htmlspecialchars($s['receipt_number'], ENT_QUOTES)?>? This removes it from the database entirely and cannot be undone.">
            <input type="hidden" name="action" value="purge"><input type="hidden" name="id" value="<?=$s['id']?>"><?=csrfInput()?>
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
        <?php endif; ?></td>
        <?php endif; ?>
      </tr>
    <?php endwhile; if ($i===0): ?>
      <tr><td colspan="<?=$is_admin?10:9?>" style="text-align:center;padding:22px;color:var(--text3)">No sales recorded for this period</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- Record Sale Modal -->
<div class="modal-overlay" id="sell-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Record Token Sale</span><button class="modal-close" onclick="closeModal('sell-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="sell"><?=csrfInput()?><div class="modal-body">
<div class="form-group"><label class="form-label">Branch *</label>
<select name="branch_id" id="sell-branch" class="form-control" required onchange="updateSellTotal()">
<option value="">-- Select branch --</option>
<?php foreach ($branch_rows as $b): if ($b['status']!=='active') continue; ?>
<option value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?> (<?=tzs($b['token_rate'])?>/token)</option>
<?php endforeach; ?>
</select></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Tokens *</label><input type="number" name="tokens" id="sell-tokens" class="form-control" min="1" required oninput="updateSellTotal()"/></div>
<div class="form-group"><label class="form-label">Payment Method</label><select name="payment_method" class="form-control"><option value="cash">Cash</option><option value="lipa">Lipa Namba</option><option value="bank">Bank</option></select></div>
</div>
<div style="padding:12px;background:var(--bg3);border-radius:10px;margin-bottom:14px">Total: <strong id="sell-total-display" style="color:var(--success)">TZS 0</strong></div>
<div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Optional"></textarea></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('sell-modal')">Cancel</button><button type="submit" class="btn btn-primary">Record Sale</button></div></form></div></div>

<?php if ($is_admin): ?>
<!-- Add Branch Modal -->
<div class="modal-overlay" id="add-branch-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Branch</span><button class="modal-close" onclick="closeModal('add-branch-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="add_branch"><?=csrfInput()?><div class="modal-body">
<div class="form-group"><label class="form-label">Branch Name *</label><input name="name" class="form-control" placeholder="e.g. Uzunguni C" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Location</label><input name="location" class="form-control" placeholder="e.g. Uzunguni"/></div>
<div class="form-group"><label class="form-label">Rate per Token (TZS) *</label><input type="number" name="token_rate" class="form-control" value="500" min="1" required/></div>
</div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-branch-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Branch</button></div></form></div></div>

<!-- Edit Branch Modal -->
<div class="modal-overlay" id="edit-branch-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Branch</span><button class="modal-close" onclick="closeModal('edit-branch-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit_branch"><input type="hidden" name="id" id="edit-branch-id"><?=csrfInput()?><div class="modal-body">
<div class="form-group"><label class="form-label">Branch Name *</label><input name="name" id="edit-branch-name" class="form-control" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Location</label><input name="location" id="edit-branch-location" class="form-control"/></div>
<div class="form-group"><label class="form-label">Rate per Token (TZS) *</label><input type="number" name="token_rate" id="edit-branch-rate" class="form-control" min="1" required/></div>
</div>
<div class="form-group"><label class="form-label">Status</label><select name="status" id="edit-branch-status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-branch-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>
<?php endif; ?>

<?php
$extra_js = <<<JS
<script>
const branchRates = $branch_rates_json;
function updateSellTotal(){
  const branchId = document.getElementById('sell-branch').value;
  const tokens = parseInt(document.getElementById('sell-tokens').value, 10) || 0;
  const rate = branchRates[branchId] || 0;
  document.getElementById('sell-total-display').textContent = 'TZS ' + (rate * tokens).toLocaleString();
}
function openEditBranch(b){
  document.getElementById('edit-branch-id').value = b.id;
  document.getElementById('edit-branch-name').value = b.name;
  document.getElementById('edit-branch-location').value = b.location || '';
  document.getElementById('edit-branch-rate').value = b.token_rate;
  document.getElementById('edit-branch-status').value = b.status;
  openModal('edit-branch-modal');
}
new Chart(document.getElementById('billiardsChart'),{type:'bar',data:{labels:$chart_labels,datasets:[{label:'Revenue',data:$chart_revenues,backgroundColor:'rgba(124,58,237,0.7)',borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6B5F8A'}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6B5F8A'}}}}});
new Chart(document.getElementById('branchChart'),{type:'doughnut',data:{labels:$branch_labels,datasets:[{data:$branch_totals,backgroundColor:['#7C3AED','#10B981','#F59E0B','#3B82F6','#EF4444'],borderWidth:0}]},options:{responsive:true,cutout:'60%',plugins:{legend:{position:'bottom',labels:{color:'#A89DC4',padding:12,font:{size:11}}}}}});
function togglePeriodInputs(val){
  document.getElementById('period-input-daily').style.display = val==='daily' ? '' : 'none';
  document.getElementById('period-input-weekly').style.display = val==='weekly' ? '' : 'none';
  document.getElementById('period-input-monthly').style.display = val==='monthly' ? '' : 'none';
}
</script>
JS;
require_once 'includes/footer.php';
?>
