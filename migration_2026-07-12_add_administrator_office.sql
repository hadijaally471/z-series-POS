-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-07-12
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Follows migration_2026-07-12_add_accountant_manager_office.sql — run that one first if you haven't.
--
-- Adds "Administrator" to the existing office list (Manager, Accountant,
-- Cashewnut Sales, Z series sales, Pool Master, Home office, Driver,
-- Guard, Others). Pure addition — no employee is currently assigned
-- "Administrator" as an office, so there's nothing to relabel, just
-- widen the enum.
--
-- Note: this is separate from employees.role — payroll generation
-- already skips employees whose ROLE is 'Administrator' (see payroll.php);
-- this migration only affects the OFFICE column and does not change
-- that behavior.
--
-- BEFORE RUNNING: take a backup (Hostinger hPanel > Databases > Backups)
-- so this is trivially reversible if anything looks wrong afterwards.
--
-- Safe to re-run — it checks first before altering.

SET @office_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='office'
);

SET @has_admin := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='office'
    AND COLUMN_TYPE LIKE '%Administrator%'
);

SET @add_sql := IF(@office_exists = 0,
  -- office column doesn't exist yet at all (shouldn't normally happen if the
  -- prior migrations already ran, but covers a fresh/partial install too)
  "ALTER TABLE employees ADD COLUMN office ENUM('Manager','Accountant','Cashewnut Sales','Z series sales','Pool Master','Home office','Driver','Guard','Administrator','Others') DEFAULT 'Cashewnut Sales'",
  IF(@has_admin = 0,
    "ALTER TABLE employees MODIFY COLUMN office ENUM('Manager','Accountant','Cashewnut Sales','Z series sales','Pool Master','Home office','Driver','Guard','Administrator','Others') DEFAULT 'Cashewnut Sales'",
    'SELECT 1'));
PREPARE stmt FROM @add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Done. Verify with:
--   SHOW CREATE TABLE employees\G
-- ============================================================
