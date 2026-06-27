<?php
require_once 'config.php';
if (isset($_SESSION['user_id'])) {
    redirectTo('dashboard.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if ($user && password_verify($password, $user['password'])) {
      session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_privileges'] = $user['privileges'] ?? '';
        logActivity($conn, 'User logged in: ' . $user['name'], 'system');
        redirectTo('dashboard.php');
    } else {
        $error = 'Invalid username or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Z-Series POS — Login</title>
<link rel="stylesheet" href="<?= htmlspecialchars(appPath('css/style.css'), ENT_QUOTES) ?>"/>
</head>
<body>
<div class="login-page">
  <div class="login-orb" style="width:500px;height:500px;background:var(--purple);top:-150px;left:-150px"></div>
  <div class="login-orb" style="width:400px;height:400px;background:#4F46E5;bottom:-150px;right:-150px"></div>

  <div class="login-card">
    <div class="login-left">
      <div class="login-logo">
        <div class="login-logo-icon">Z</div>
        <div class="login-logo-text-wrapper">
          <div class="login-logo-main">Z-SERIES</div>
          <div class="login-logo-sub">PRODUCTS MANAGEMENT</div>
        </div>
      </div>
      <div class="login-title">Welcome Back</div>
      <div class="login-subtitle">Manage your tiles, soap & cashew nut business — sales, inventory, suppliers and more in one place.</div>
      <div class="login-feature">
        <div class="login-feature-icon"></div>
        <div class="login-feature-text">Point of Sale — Jumla & Rejareja pricing</div>
      </div>
      <div class="login-feature">
        <div class="login-feature-icon"></div>
        <div class="login-feature-text">Inventory & Stock Management</div>
      </div>
      <div class="login-feature">
        <div class="login-feature-icon"></div>
        <div class="login-feature-text">Supplier & Farmer Management</div>
      </div>
      <div class="login-feature">
        <div class="login-feature-icon"></div>
        <div class="login-feature-text">Reports & Business Analytics</div>
      </div>
    </div>
    <div class="login-right">
      <div class="login-right-label">Z-Series POS</div>
      <div class="login-right-title">Sign in to continue</div>
      <?php if ($error): ?>
        <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="POST">
        <?= csrfInput() ?>
        <div class="login-input-group">
          <label class="login-label">Username</label>
          <input type="text" name="username" class="login-input" placeholder="admin" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus/>
        </div>
        <div class="login-input-group">
          <label class="login-label">Password</label>
          <input type="password" name="password" class="login-input" placeholder="••••••••" required/>
        </div>
        <button type="submit" class="login-btn">Sign In →</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
