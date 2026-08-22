<?php
// includes/auth.php — call requireLogin() before including header
require_once __DIR__ . '/../config.php';
requireLogin();

$current_page = basename($_SERVER['PHP_SELF'], '.php');
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_role = $_SESSION['user_role'] ?? 'admin';

// Low stock count for badge
$low_stock_count = 0;
$ls = $conn->query("SELECT COUNT(*) as c FROM products WHERE stock <= low_stock_threshold AND status='active'");
if ($ls) $low_stock_count = $ls->fetch_assoc()['c'];

// Low stock count for the private (admin-only) inventory badge
$admin_low_stock_count = 0;
if ($user_role === 'admin') {
    $als = $conn->query("SELECT COUNT(*) as c FROM admin_inventory WHERE stock <= low_stock_threshold AND status='active'");
    if ($als) $admin_low_stock_count = $als->fetch_assoc()['c'];
}

// Due/overdue task count for badge (current user's own tasks)
$due_task_count = 0;
$dt = $conn->prepare("SELECT COUNT(*) as c FROM tasks WHERE user_id = ? AND status = 'pending' AND due_date <= CURDATE()");
$dt->bind_param('i', $_SESSION['user_id']);
$dt->execute();
$due_task_count = $dt->get_result()->fetch_assoc()['c'];

// Overdue payroll count for badge — pending entries from a past period,
// or from the current period once we're in its final week (day >= 25)
$pending_payroll_count = 0;
$current_period = date('Y-m');
$is_late_in_month = (int)date('j') >= 25 ? 1 : 0;
$pp = $conn->prepare("SELECT COUNT(*) as c FROM payroll WHERE status = 'pending' AND (period < ? OR (period = ? AND ? = 1))");
$pp->bind_param('ssi', $current_period, $current_period, $is_late_in_month);
$pp->execute();
$pending_payroll_count = $pp->get_result()->fetch_assoc()['c'];

$business_name = getSetting($conn, 'business_name') ?: 'Z-Series Products';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>"/>
<meta name="app-base-url" content="<?= htmlspecialchars(appBaseUrl(), ENT_QUOTES) ?>"/>
<title><?= $page_title ?? 'Z-Series POS' ?> — <?= htmlspecialchars($business_name) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars(appPath('css/style.css'), ENT_QUOTES) ?>?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?: time() ?>"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body>
<div class="layout">
<div class="sidebar">
  <div class="sidebar-logo">
    <img class="sidebar-logo-icon" src="<?= htmlspecialchars(appPath('uploads/z series logo.png'), ENT_QUOTES) ?>" alt="Z-Series logo"/>
    <div>
      <div class="sidebar-logo-text">Z-SERIES</div>
      <div class="sidebar-logo-sub">Products POS</div>
    </div>
  </div>

  <div class="sidebar-section">Main</div>
  <?php if(hasPrivilege('dashboard')): ?>
  <a href="dashboard.php" class="nav-item <?= $current_page==='dashboard'?'active':'' ?>"><span class="nav-icon">📊</span> Dashboard</a>
  <?php endif; ?>
  <?php if(hasPrivilege('pos')): ?>
  <a href="pos.php" class="nav-item <?= $current_page==='pos'?'active':'' ?>"><span class="nav-icon">🛒</span> Point of Sale</a>
  <?php endif; ?>
  <?php if(hasPrivilege('inventory')): ?>
  <a href="inventory.php" class="nav-item <?= $current_page==='inventory'?'active':'' ?>">
    <span class="nav-icon">📦</span> Inventory
    <?php if($low_stock_count>0): ?><span class="nav-badge"><?= $low_stock_count ?></span><?php endif; ?>
  </a>
  <?php endif; ?>
  <?php if(hasPrivilege('customers')): ?>
  <a href="customers.php" class="nav-item <?= $current_page==='customers'?'active':'' ?>"><span class="nav-icon">👥</span> Customers</a>
  <?php endif; ?>
  <?php if(hasPrivilege('suppliers')): ?>
  <a href="suppliers.php" class="nav-item <?= $current_page==='suppliers'?'active':'' ?>"><span class="nav-icon">🌾</span> Suppliers</a>
  <?php endif; ?>

  <div class="sidebar-section">Finance</div>
  <?php if(hasPrivilege('expenses')): ?>
  <a href="expenses.php" class="nav-item <?= $current_page==='expenses'?'active':'' ?>"><span class="nav-icon">💸</span> Expenses</a>
  <?php endif; ?>
  <?php if(hasPrivilege('debts')): ?>
  <a href="debts.php" class="nav-item <?= $current_page==='debts'?'active':'' ?>"><span class="nav-icon">💰</span> Debts</a>
  <?php endif; ?>
  <?php if(hasPrivilege('purchase_orders')): ?>
  <a href="purchase_orders.php" class="nav-item <?= $current_page==='purchase_orders'?'active':'' ?>"><span class="nav-icon">📋</span> Purchase Orders</a>
  <?php endif; ?>
  <?php if(hasPrivilege('payroll')): ?>
  <a href="payroll.php" class="nav-item <?= $current_page==='payroll'?'active':'' ?>">
    <span class="nav-icon">🧮</span> Payroll
    <?php if($pending_payroll_count>0): ?><span class="nav-badge"><?= $pending_payroll_count ?></span><?php endif; ?>
  </a>
  <?php endif; ?>

  <div class="sidebar-section">Personal</div>
  <?php if(hasPrivilege('tasks')): ?>
  <a href="tasks.php" class="nav-item <?= $current_page==='tasks'?'active':'' ?>">
    <span class="nav-icon">✅</span> Tasks
    <?php if($due_task_count>0): ?><span class="nav-badge"><?= $due_task_count ?></span><?php endif; ?>
  </a>
  <?php endif; ?>

  <div class="sidebar-section">Uzunguni Billiards</div>
  <?php if(hasPrivilege('billiards')): ?>
  <a href="billiards.php" class="nav-item <?= $current_page==='billiards'?'active':'' ?>"><span class="nav-icon">🎱</span> Billiards Arena</a>
  <?php endif; ?>

  <div class="sidebar-section">WiFi Billing</div>
  <?php if(hasPrivilege('wifi_billing')): ?>
  <a href="wifi_billing.php" class="nav-item <?= $current_page==='wifi_billing'?'active':'' ?>"><span class="nav-icon">📶</span> WiFi Bundles</a>
  <?php endif; ?>

  <div class="sidebar-section">Korosho</div>
  <?php if(hasPrivilege('korosho')): ?>
  <a href="korosho.php" class="nav-item <?= in_array($current_page,['korosho','korosho_pos','korosho_inventory','korosho_reports','korosho_customers','korosho_employees','korosho_debts'],true)?'active':'' ?>"><span class="nav-icon">🥜</span> Korosho Sales</a>
  <?php endif; ?>

  <div class="sidebar-section">Management</div>
  <?php if(hasPrivilege('reports')): ?>
  <a href="reports.php" class="nav-item <?= $current_page==='reports'?'active':'' ?>"><span class="nav-icon">📈</span> Reports</a>
  <a href="cashier_sales.php" class="nav-item <?= $current_page==='cashier_sales'?'active':'' ?>"><span class="nav-icon">🧑‍💼</span> Sales Rep Report</a>
  <?php endif; ?>
  <?php if(hasPrivilege('receipts')): ?>
  <a href="receipts.php" class="nav-item <?= $current_page==='receipts'?'active':'' ?>"><span class="nav-icon">🧾</span> Receipts</a>
  <?php endif; ?>
  <?php if(hasPrivilege('employees')): ?>
  <a href="employees.php" class="nav-item <?= $current_page==='employees'?'active':'' ?>"><span class="nav-icon">👨‍💼</span> Employees</a>
  <?php endif; ?>
  <?php if(hasPrivilege('activity')): ?>
  <a href="activity.php" class="nav-item <?= $current_page==='activity'?'active':'' ?>"><span class="nav-icon">📝</span> Activity Log</a>
  <?php endif; ?>
  <?php if(hasPrivilege('settings')): ?>
  <a href="settings.php" class="nav-item <?= $current_page==='settings'?'active':'' ?>"><span class="nav-icon">⚙️</span> Settings</a>
  <?php endif; ?>
  <?php if(hasPrivilege('users')): ?>
  <a href="users.php" class="nav-item <?= $current_page==='users'?'active':'' ?>"><span class="nav-icon">👤</span> Users</a>
  <?php endif; ?>
  <?php if($user_role === 'admin'): ?>
  <a href="personal_expenses.php" class="nav-item <?= $current_page==='personal_expenses'?'active':'' ?>"><span class="nav-icon">🔒</span> Personal Expenses</a>
  <a href="admin_inventory.php" class="nav-item <?= $current_page==='admin_inventory'?'active':'' ?>">
    <span class="nav-icon">🔒</span> Private Inventory
    <?php if($admin_low_stock_count>0): ?><span class="nav-badge"><?= $admin_low_stock_count ?></span><?php endif; ?>
  </a>
  <a href="backup.php" class="nav-item <?= $current_page==='backup'?'active':'' ?>"><span class="nav-icon">🔒</span> Backups</a>
  <?php endif; ?>

  <div class="sidebar-bottom">
    <div class="user-card">
      <div class="user-avatar"><?= strtoupper(substr($user_name,0,1)) ?></div>
      <div>
        <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
        <div class="user-role"><?= ucfirst($user_role) ?></div>
      </div>
    </div>
    <a href="logout.php" data-confirm="Are you sure you want to sign out?" class="logout-btn" style="display:block;text-align:center;text-decoration:none">Sign Out</a>
  </div>
</div>
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>

<div class="main">
<div class="topbar">
  <div class="topbar-left">
    <button type="button" class="sidebar-toggle-btn" id="sidebar-toggle" aria-label="Toggle sidebar" aria-expanded="false">☰</button>
    <div>
      <div class="topbar-title"><?= $page_title ?? 'Dashboard' ?></div>
      <div class="topbar-sub"><?= date('l, d F Y') ?></div>
    </div>
  </div>
  <div class="topbar-right">
    <?php if(isset($topbar_buttons)) echo $topbar_buttons; ?>
    <div class="notif-wrap">
      <button id="notif-btn" class="notif-btn" aria-haspopup="true" aria-expanded="false">
        <span class="notif-icon">🔔</span>
        <span class="notif-badge" id="notif-badge" style="display:none">0</span>
      </button>
      <div class="notif-panel" id="notif-panel" aria-hidden="true">
        <div class="notif-panel-header">
          <div>Notifications</div>
          <div style="display:flex;gap:8px;align-items:center">
            <button id="notif-clear" class="btn btn-outline btn-sm">Clear</button>
          </div>
        </div>
        <div class="notif-list" id="notif-list">
          <div class="notif-empty">No notifications</div>
        </div>
        <div class="notif-panel-footer">
          <button id="notif-mark-read" class="btn btn-primary btn-sm">Mark all read</button>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="<?= htmlspecialchars($content_class ?? 'content') ?>">
