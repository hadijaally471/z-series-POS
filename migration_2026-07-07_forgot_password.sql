-- Z-Series POS — Incremental migration for live/production databases
-- Adds: users.email column, password_resets table (Forgot Password feature)
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Do NOT import database.sql on top of a live database — see the other migration
-- file's header comment for why.
--
-- Safe to re-run if interrupted partway through.

SET @email_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email'
);
SET @add_email_sql := IF(@email_exists = 0,
  'ALTER TABLE users ADD COLUMN email VARCHAR(150) AFTER password',
  'SELECT 1');
PREPARE stmt FROM @add_email_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- After running this, go to Users in the app and add an email address to
-- each account that should be able to use "Forgot password" — and add the
-- SMTP_* constants to config.local.php (see config.local.example.php),
-- using a real mailbox (Hostinger email account or Gmail app password).
