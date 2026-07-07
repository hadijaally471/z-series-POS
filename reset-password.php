<?php
require_once 'config.php';
if (isset($_SESSION['user_id'])) {
    redirectTo('dashboard.php');
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;
$validToken = false;
$resetId = null;
$userId = null;

if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW()");
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    if ($reset) {
        $validToken = true;
        $resetId = (int)$reset['id'];
        $userId = (int)$reset['user_id'];
    }
}

if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();
            $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $conn->commit();
            logActivity($conn, 'Password reset completed (user id ' . $userId . ')', 'system');
            $success = true;
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Reset Password — Z-Series POS</title>
<link rel="stylesheet" href="<?= htmlspecialchars(appPath('css/style.css'), ENT_QUOTES) ?>?v=<?= @filemtime(__DIR__ . '/css/style.css') ?: time() ?>"/>
</head>
<body>
<div class="login-page">
  <div class="login-orb" style="width:500px;height:500px;background:var(--purple);top:-150px;left:-150px"></div>
  <div class="login-orb" style="width:400px;height:400px;background:#4F46E5;bottom:-150px;right:-150px"></div>

  <div class="login-card">
    <div class="login-left">
      <div class="login-logo">
        <img class="login-logo-icon" src="<?= htmlspecialchars(appPath('uploads/z series logo.png'), ENT_QUOTES) ?>" alt="Z-Series logo"/>
        <div class="login-logo-text-wrapper">
          <div class="login-logo-main">Z-SERIES</div>
          <div class="login-logo-sub">PRODUCTS MANAGEMENT</div>
        </div>
      </div>
      <div class="login-title">Choose a new password</div>
      <div class="login-subtitle">Pick something you haven't used before. This reset link only works once and expires an hour after it was requested.</div>
    </div>
    <div class="login-right">
      <div class="login-right-label">Z-Series POS</div>
      <div class="login-right-title">Reset password</div>

      <?php if ($success): ?>
        <div class="alert-danger" style="background:rgba(16,185,129,0.1);border-color:rgba(16,185,129,0.3);color:#6ee7b7">Password updated! You can now sign in with your new password.</div>
        <div style="text-align:center;margin-top:16px">
          <a href="<?= htmlspecialchars(appPath('index.php'), ENT_QUOTES) ?>" class="login-btn" style="display:block;text-decoration:none">Go to Sign In</a>
        </div>
      <?php elseif (!$validToken): ?>
        <div class="alert-danger">This reset link is invalid or has expired.</div>
        <div style="text-align:center;margin-top:16px">
          <a href="<?= htmlspecialchars(appPath('forgot-password.php'), ENT_QUOTES) ?>" style="color:var(--text2);font-size:13px;text-decoration:none">Request a new link</a>
        </div>
      <?php else: ?>
        <?php if ($error): ?>
          <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
          <?= csrfInput() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>"/>
          <div class="login-input-group">
            <label class="login-label">New Password</label>
            <input type="password" name="password" class="login-input" placeholder="At least 8 characters" required minlength="8"/>
          </div>
          <div class="login-input-group">
            <label class="login-label">Confirm New Password</label>
            <input type="password" name="confirm_password" class="login-input" placeholder="Re-enter password" required minlength="8"/>
          </div>
          <button type="submit" class="login-btn">Reset Password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
