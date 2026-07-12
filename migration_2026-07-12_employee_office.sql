-- Z-Series POS — Incremental migration for live/production databases
-- Date: 2026-07-12
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
-- This script is safe to re-run if it's interrupted partway through —
-- it checks first before altering.

-- ============================================================
-- 1. employees.office — split payroll by office (Cashewnut office,
--    Home office, Pool master office). Existing employees default
--    to 'Cashewnut office' so nothing disappears from payroll runs.
-- ============================================================
SET @office_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='office'
);
SET @add_office_sql := IF(@office_exists = 0,
  "ALTER TABLE employees ADD COLUMN office ENUM('Cashewnut office','Home office','Pool master office') DEFAULT 'Cashewnut office'",
  'SELECT 1');
PREPARE stmt FROM @add_office_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Done. Verify with:
--   SHOW CREATE TABLE employees\G
-- ============================================================
