-- Z-Series POS — Incremental migration for live/production databases
-- Adds: rate_limits table (login lockout, forgot-password abuse prevention)
--
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Do NOT import database.sql on top of a live database — see the other migration
-- files' header comments for why.
--
-- Safe to re-run if interrupted partway through.

CREATE TABLE IF NOT EXISTS rate_limits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(50) NOT NULL,
  identifier VARCHAR(150) NOT NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action_identifier (action, identifier)
);

-- After running this, also add SMTP_* and set APP_ENV = 'production' in
-- config.local.php if not already done — the security hardening in this
-- release (forced HTTPS, hidden error details) only activates when
-- APP_ENV is exactly 'production'.
