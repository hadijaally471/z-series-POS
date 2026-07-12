-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-07-12
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
-- Follows migration_2026-07-12_employee_office.sql — run that one first if you haven't.
--
-- Adds a "Guard" office to the existing office list (Cashewnut Sales,
-- Home office, Pool Master, Z series sales, Driver, Others). Pure
-- addition — no employee is currently assigned "Guard", so there's
-- nothing to relabel, just widen the enum.
--
-- BEFORE RUNNING: take a backup (Hostinger hPanel > Databases > Backups)
-- so this is trivially reversible if anything looks wrong afterwards.
--
-- Safe to re-run — it checks first before altering.

SET @office_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='office'
);

SET @has_guard := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='office'
    AND COLUMN_TYPE LIKE '%Guard%'
);

SET @add_guard_sql := IF(@office_exists = 0,
  -- office column doesn't exist yet at all (shouldn't normally happen if the
  -- prior migration already ran, but covers a fresh/partial install too)
  "ALTER TABLE employees ADD COLUMN office ENUM('Cashewnut Sales','Home office','Pool Master','Z series sales','Driver','Others','Guard') DEFAULT 'Cashewnut Sales'",
  IF(@has_guard = 0,
    "ALTER TABLE employees MODIFY COLUMN office ENUM('Cashewnut Sales','Home office','Pool Master','Z series sales','Driver','Others','Guard') DEFAULT 'Cashewnut Sales'",
    'SELECT 1'));
PREPARE stmt FROM @add_guard_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Done. Verify with:
--   SHOW CREATE TABLE employees\G
-- ============================================================
