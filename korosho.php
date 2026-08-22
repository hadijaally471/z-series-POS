<?php
$page_title = 'Korosho';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('korosho');
?>
<div class="korosho-hub-grid">
  <a href="korosho_reports.php" class="korosho-hub-card">
    <div class="korosho-hub-icon">📈</div>
    <div class="korosho-hub-title">Report</div>
    <div class="korosho-hub-sub">Revenue, by-product breakdown, recent sales</div>
  </a>
  <a href="korosho_pos.php" class="korosho-hub-card">
    <div class="korosho-hub-icon">🛒</div>
    <div class="korosho-hub-title">POS</div>
    <div class="korosho-hub-sub">Sell Korosho products &amp; print receipts</div>
  </a>
  <a href="korosho_inventory.php" class="korosho-hub-card">
    <div class="korosho-hub-icon">📦</div>
    <div class="korosho-hub-title">Inventory</div>
    <div class="korosho-hub-sub">Add products, set prices &amp; stock</div>
  </a>
  <a href="korosho_customers.php" class="korosho-hub-card">
    <div class="korosho-hub-icon">👥</div>
    <div class="korosho-hub-title">Customers</div>
    <div class="korosho-hub-sub">Manage customers &amp; track purchases</div>
  </a>
  <a href="korosho_employees.php" class="korosho-hub-card">
    <div class="korosho-hub-icon">🧑‍🌾</div>
    <div class="korosho-hub-title">Employees</div>
    <div class="korosho-hub-sub">Manage Korosho's own Sales Reps</div>
  </a>
  <a href="korosho_debts.php" class="korosho-hub-card">
    <div class="korosho-hub-icon">💰</div>
    <div class="korosho-hub-title">Debt</div>
    <div class="korosho-hub-sub">Track and collect customer debts</div>
  </a>
</div>

<?php
$extra_js = <<<'JS'
<style>
.korosho-hub-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin-top:8px}
.korosho-hub-card{display:block;background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:32px 24px;text-align:center;text-decoration:none;transition:transform .15s,border-color .15s}
.korosho-hub-card:hover{transform:translateY(-3px);border-color:var(--purple)}
.korosho-hub-icon{font-size:36px;margin-bottom:12px}
.korosho-hub-title{font-family:'Syne',sans-serif;font-weight:700;font-size:18px;color:var(--text);margin-bottom:6px}
.korosho-hub-sub{font-size:12px;color:var(--text2)}
</style>
JS;
require_once 'includes/footer.php';
?>
