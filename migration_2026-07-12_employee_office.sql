-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-07-12 (updated same day — office list renamed/expanded)
-- Run this ONCE against your Hostinger database (phpMyAdmin > SQL tab, or via SSH mysql client).
--
-- Do NOT import database.sql on top of a live database — it starts with
-- DROP TABLE statements and will destroy all existing customers, sales,
-- debts, employees, etc. This file only ALTERs what's needed.
--
-- BEFORE RUNNING: take a backup (Hostinger hPanel > Databases > Backups,
-- or `mysqldump` via SSH) so this is trivially reversible if anything
-- looks wrong afterwards.
--
-- This script is safe to re-run if it's interrupted partway through, and
-- safe to run even if an earlier version of this file (with the old
-- 'Cashewnut office'/'Pool master office' labels) already ran — it
-- widens the enum to a superset first, relabels any employees still on
-- the old values, then narrows the enum to the final list. So no one's
-- office assignment is ever silently truncated or lost.

-- ============================================================
-- 1. employees.office — split payroll by office: Cashewnut Sales,
--    Home office, Pool Master, Z series sales, Driver, Others.
-- ============================================================
SET @office_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='office'
);

-- Step 1: if the column already exists, widen the enum to a superset of
-- old + new labels first — MySQL rejects UPDATE-ing a row to a value
-- that isn't yet in the enum's allowed list, so old data must stay valid
-- while we relabel it below.
SET @widen_office_sql := IF(@office_exists > 0,
  "ALTER TABLE employees MODIFY COLUMN office ENUM('Cashewnut Sales','Home office','Pool Master','Z series sales','Driver','Others','Cashewnut office','Pool master office') DEFAULT 'Cashewnut Sales'",
  'SELECT 1');
PREPARE stmt FROM @widen_office_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: relabel any employees still on the old enum values.
SET @rename_cashewnut_sql := IF(@office_exists > 0,
  "UPDATE employees SET office='Cashewnut Sales' WHERE office='Cashewnut office'",
  'SELECT 1');
PREPARE stmt FROM @rename_cashewnut_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @rename_pool_sql := IF(@office_exists > 0,
  "UPDATE employees SET office='Pool Master' WHERE office='Pool master office'",
  'SELECT 1');
PREPARE stmt FROM @rename_pool_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 3: add the column fresh (first run) or narrow the enum down to
-- the final list (re-run) — by now no row holds an old-only label.
SET @finalize_office_sql := IF(@office_exists = 0,
  "ALTER TABLE employees ADD COLUMN office ENUM('Cashewnut Sales','Home office','Pool Master','Z series sales','Driver','Others') DEFAULT 'Cashewnut Sales'",
  "ALTER TABLE employees MODIFY COLUMN office ENUM('Cashewnut Sales','Home office','Pool Master','Z series sales','Driver','Others') DEFAULT 'Cashewnut Sales'");
PREPARE stmt FROM @finalize_office_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Done. Verify with:
--   SHOW CREATE TABLE employees\G
--   SELECT id, name, office FROM employees;
-- ============================================================
