<?php
require_once 'config.php';
require_once 'includes/mailer.php';
if (isset($_SESSION['user_id'])) {
    redirectTo('dashboard.php');
}
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $identifier = trim($_POST['identifier'] ?? '');
    $rateKey = strtolower($identifier);

    $rateLimited = $identifier !== '' && tooManyAttempts($conn, 'forgot_password', $rateKey, 3, 15);

    if ($identifier !== '' && !$rateLimited) {
        recordAttempt($conn, 'forgot_password', $rateKey);
        $stmt = $conn->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && !empty($user['email'])) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            // Compute the expiry inside MySQL (DATE_ADD(NOW(), ...)) rather than in PHP —
            // PHP's default timezone (UTC) and MySQL's server timezone can differ, and
            // comparing a PHP-computed timestamp against MySQL's NOW() elsewhere would
            // make tokens look expired immediately if the two clocks are offset.
            $ins = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR))");
            $ins->bind_param('is', $user['id'], $tokenHash);
            $ins->execute();

            $resetLink = fullUrl('reset-password.php') . '?token=' . $token;
            $subject = 'Reset your Z-Series POS password';
            $body = '<div style="font-family:sans-serif;max-width:480px;margin:0 auto">'
                  . '<h2 style="color:#7C3AED">Z-Series POS</h2>'
                  . '<p>Hi ' . htmlspecialchars($user['name']) . ',</p>'
                  . '<p>We received a request to reset your password. Click the button below to choose a new one. This link expires in 1 hour.</p>'
                  . '<p style="margin:24px 0"><a href="' . htmlspecialchars($resetLink) . '" style="background:#7C3AED;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block">Reset Password</a></p>'
                  . '<p style="color:#666;font-size:13px">If you did not request this, you can safely ignore this email — your password will not change.</p>'
                  . '</div>';

            sendMail($user['email'], $user['name'], $subject, $body);
            logActivity($conn, 'Password reset requested: ' . $user['username'], 'system');
        }
    }
    if ($rateLimited) {
        $message = 'Too many reset requests for this account. Please try again in 15 minutes.';
    } else {
        // Always show the same message whether or not the account was found —
        // avoids revealing which usernames/emails exist in the system.
        $message = 'If that account exists and has an email on file, a password reset link has been sent to it.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Forgot Password — Z-Series POS</title>
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
      <div class="login-title">Forgot your password?</div>
      <div class="login-subtitle">Enter your username or email and, if we find a matching account with an email on file, we'll send a link to reset your password.</div>
    </div>
    <div class="login-right">
      <div class="login-right-label">Z-Series POS</div>
      <div class="login-right-title">Reset password</div>
      <?php if ($message): ?>
        <div class="alert-danger" style="background:rgba(16,185,129,0.1);border-color:rgba(16,185,129,0.3);color:#6ee7b7"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <form method="POST">
        <?= csrfInput() ?>
        <div class="login-input-group">
          <label class="login-label">Username or Email</label>
          <input type="text" name="identifier" class="login-input" placeholder="admin or you@example.com" required autofocus/>
        </div>
        <button type="submit" class="login-btn">Send Reset Link</button>
      </form>
      <div style="text-align:center;margin-top:16px">
        <a href="<?= htmlspecialchars(appPath('index.php'), ENT_QUOTES) ?>" style="color:var(--text2);font-size:13px;text-decoration:none">&larr; Back to Sign In</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
