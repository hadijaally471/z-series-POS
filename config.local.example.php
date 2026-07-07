<?php
// Copy this file to config.local.php for your environment and fill in the correct MySQL details.
// Keep config.local.php out of version control and never use the local XAMPP root account on hosting.

define('APP_ENV', 'production');
define('DB_HOST', 'localhost');
define('DB_USER', 'u123456789_zseries_user');
define('DB_PASS', 'replace_with_hostinger_database_password');
define('DB_NAME', 'u123456789_zseries_pos');

// Required for the "Forgot password" email feature.
// Use a real mailbox — either a Hostinger email account (hPanel > Emails)
// or a Gmail address with an app password (myaccount.google.com/apppasswords).
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls'); // 'tls' for port 587 (STARTTLS), 'ssl' for port 465
define('SMTP_USER', 'noreply@yourdomain.com');
define('SMTP_PASS', 'replace_with_mailbox_password');
define('SMTP_FROM', 'noreply@yourdomain.com');
define('SMTP_FROM_NAME', 'Z-Series POS');
